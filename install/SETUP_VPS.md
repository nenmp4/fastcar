# Setup da VPS — Fastcar CRM

Guia pra deixar a VPS (HostGator NVMe 4, São Paulo, Ubuntu sem painel) rodando
o CRM do zero: nginx + PHP-FPM + SQLite, Cloudflare na frente (SSL + proteção
contra robô), crontab, deploy automático via GitHub, e-mail (Brevo) e backup.

Passos marcados com **⏳ precisa do domínio** ficam pra quando o domínio da
Fastcar existir — o resto pode ser feito agora, sem ele.

---

## 1. Acesso inicial

```bash
ssh root@SEU_IP_AQUI
```

Recomendado: trocar a senha root na primeira vez (`passwd`) e, se possível,
configurar login por chave SSH em vez de senha (mais seguro, evita
força-bruta de robô varrendo a internet — mesmo motivo do `robots.txt`
bloqueando indexação, só que na camada de SSH).

## 2. Atualizar sistema e instalar pacotes

```bash
apt update && apt upgrade -y

# nginx + PHP-FPM (ajustar a versão do PHP se o repositório do Ubuntu
# oferecer uma mais nova — confirmar com `apt-cache policy php-fpm`)
apt install -y nginx php-fpm php-sqlite3 php-curl php-mbstring php-xml \
  php-gd php-zip git curl unzip

systemctl enable --now nginx php*-fpm
```

Confirmar a versão instalada e o nome do socket do PHP-FPM (muda o nome do
arquivo `.sock` conforme a versão — vai precisar no passo 4):

```bash
php -v
ls /run/php/
```

## 3. Clonar o repositório

```bash
mkdir -p /var/www
cd /var/www
git clone https://github.com/nenmp4/fastcar.git
cd fastcar
git checkout main   # ou a branch que estiver em produção no momento
```

## 4. Permissões

O usuário do nginx/PHP-FPM (`www-data` no Ubuntu) precisa escrever em
`database/`, `storage/` e `config/` (o SQLite e os uploads locais moram
aqui; `config/google_drive_credentials.json` é dropado manualmente, ver
abaixo):

```bash
chown -R www-data:www-data /var/www/fastcar
chmod -R 755 /var/www/fastcar
chmod -R 775 /var/www/fastcar/database /var/www/fastcar/storage /var/www/fastcar/config
```

Se a service account do Google Drive já existir, sobe o arquivo agora
(nunca por upload web, sempre por aqui):

```bash
scp google_drive_credentials.json root@SEU_IP_AQUI:/var/www/fastcar/config/
chown www-data:www-data /var/www/fastcar/config/google_drive_credentials.json
chmod 640 /var/www/fastcar/config/google_drive_credentials.json
```

## 5. nginx — server block

Cria `/etc/nginx/sites-available/fastcar`:

```nginx
server {
    listen 80;
    server_name SEU_DOMINIO_AQUI;   # ⏳ trocar pelo domínio quando existir; até lá pode usar o IP ou _
    root /var/www/fastcar;
    index index.php;

    # Nunca serve os arquivos de dado/config diretamente — só via PHP
    location ~ ^/(database|storage|config)/ { deny all; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # ajustar pro socket real (ver passo 2)
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # robots.txt e .htaccess já existem no repo — .htaccess não faz nada no
    # nginx (é regra do Apache), o bloqueio de indexação aqui é 100% pelo
    # header X-Robots-Tag que o próprio PHP já manda (ver admin/_bootstrap.php,
    # admin/login.php, public/documentos.php) + o robots.txt servido como
    # arquivo estático normal.
}
```

```bash
ln -s /etc/nginx/sites-available/fastcar /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

Testa sem domínio ainda, só pelo IP: `http://SEU_IP_AQUI/admin/login.php`
deve carregar a tela de login.

## 6. Cloudflare — DNS, SSL e proteção contra robô ⏳ precisa do domínio

Quando o domínio existir:

1. Adiciona o domínio na Cloudflare, aponta os nameservers pra lá.
2. Cria um registro **A** apontando pro IP da VPS, com o **proxy laranja
   ligado** (Cloudflare na frente, esconde o IP real e já filtra bastante
   robô/scanner antes de chegar no servidor).
3. **SSL/TLS → Overview**: modo **Full (strict)**.
4. **SSL/TLS → Origin Server**: gera um **Origin Certificate** (válido 15
   anos, grátis) — cola o certificado e a chave privada em
   `/etc/nginx/ssl/fastcar.pem` e `/etc/nginx/ssl/fastcar.key` na VPS.
   Não usar Let's Encrypt/certbot aqui: com o proxy da Cloudflare ligado, o
   desafio HTTP-01 do certbot fica mais chato de validar, e o Origin
   Certificate já é o jeito recomendado pela própria Cloudflare pra esse
   cenário.
5. Atualiza o server block do nginx pra ouvir 443 com esse certificado e
   redirecionar 80→443:
   ```nginx
   server {
       listen 80;
       server_name SEU_DOMINIO_AQUI;
       return 301 https://$host$request_uri;
   }
   server {
       listen 443 ssl;
       server_name SEU_DOMINIO_AQUI;
       ssl_certificate     /etc/nginx/ssl/fastcar.pem;
       ssl_certificate_key /etc/nginx/ssl/fastcar.key;
       # ... resto igual ao passo 5
   }
   ```
6. **SSL/TLS → Edge Certificates**: liga "Always Use HTTPS".
7. **Security → Bots**: liga o "Bot Fight Mode" (ou "Super Bot Fight Mode"
   se o plano tiver) — reforça o que o `robots.txt`/`X-Robots-Tag` já fazem
   na aplicação, mas na borda, antes até de gastar recurso da VPS. Sistema
   tem dado pessoal/financeiro de cliente, não pode aparecer indexado nem
   varrido por robô (ver CLAUDE.md).
8. **Firewall da VPS — só aceitar 80/443 vindo da Cloudflare** (impede
   alguém de bater direto no IP da VPS pulando a Cloudflare e o Bot Fight
   Mode):
   ```bash
   apt install -y ufw
   ufw allow 22/tcp
   for ip in $(curl -s https://www.cloudflare.com/ips-v4); do ufw allow from $ip to any port 80,443 proto tcp; done
   for ip in $(curl -s https://www.cloudflare.com/ips-v6); do ufw allow from $ip to any port 80,443 proto tcp; done
   ufw default deny incoming
   ufw enable
   ```
   ⚠️ Rodar isso ANTES de fechar a sessão SSH atual e confirmar que a porta
   22 continua acessível — `ufw enable` mal configurado pode trancar o
   próprio acesso.

## 7. Crontab

```bash
cd /var/www/fastcar
bash install/setup_crontab.sh
crontab -l   # conferir que os 6 jobs entraram
```

Isso já cadastra: `followup.php` (a cada 30 min), `zapsign_sync.php` (a
cada 30 min), `backup_db.php` (4x/dia), `backup.php` e `backup_drive.php`
(1x/dia) e o "puxador" do deploy automático (ver próximo passo). Detalhe de
cada um: ver tabela "Cron Jobs" no `CLAUDE.md`.

## 8. Deploy automático via GitHub ⏳ webhook precisa do domínio (URL pública)

1. Gera uma chave aleatória forte pra usar de secret:
   ```bash
   openssl rand -hex 32
   ```
2. Cola essa chave em **Configurações → Deploy → Secret do webhook** no
   admin do CRM.
3. No GitHub: `Settings → Webhooks → Add webhook`
   - Payload URL: `https://SEU_DOMINIO_AQUI/api/webhook_deploy.php`
   - Content type: `application/json`
   - Secret: a mesma chave do passo 1
   - Events: só `push`
4. A partir daí, todo push na branch `main` dispara: o webhook valida a
   assinatura e agenda um marcador (`storage/.deploy`); o cron (já instalado
   no passo 7) detecta o marcador no próximo minuto, roda `git pull` **e em
   seguida `php install/migrar.php`** — sempre os dois juntos, pra uma
   migração de banco nunca ficar pra trás do código sem ninguém perceber
   (`migrar.php` é idempotente, rodar sem coluna nova nenhuma não faz nada).

Até o domínio existir, deploy continua manual: `cd /var/www/fastcar && git
pull origin main && php install/migrar.php`.

## 9. E-mail (Brevo)

Não depende de domínio pra funcionar (pode usar um e-mail de remetente
provisório e trocar depois), mas idealmente o "de" é do domínio da Fastcar
quando ele existir (melhora entrega/reputação).

1. Cria conta na [Brevo](https://www.brevo.com) (tem plano grátis com limite
   diário, suficiente pro volume da Fastcar).
2. Gera uma API key em **SMTP & API → API Keys**.
3. Cola em **Configurações → E-mail (Brevo)** no admin: chave, e-mail
   remetente, nome do remetente.
4. Usa o botão "Enviar e-mail de teste" na mesma tela pra confirmar.

## 10. Criar o primeiro usuário (super_admin)

```bash
cd /var/www/fastcar
php install/create_admin.php "Jean Susej" jean@fastcar.com.br "senha-forte-aqui" super_admin
```

## 11. Checklist final

- [ ] `https://SEU_DOMINIO/admin/login.php` carrega e loga (⏳ até lá, testar
      pelo IP em `http`)
- [ ] `php tests/smoke.php` rodado direto na VPS sem falha
- [ ] Z-API configurado em Configurações (instância principal)
- [ ] Gemini/OpenAI configurados em Configurações
- [ ] Google Drive: `google_drive_credentials.json` no lugar, status
      "configurado" em Configurações
- [ ] ZapSign configurado em Configurações
- [ ] E-mail (Brevo) configurado e teste enviado com sucesso
- [ ] `crontab -l` mostra os 6 jobs do Fastcar
- [ ] Backup manual disparado uma vez em `/admin/backup.php` só pra
      confirmar que os botões funcionam de verdade contra o Drive real
- [ ] ⏳ Cloudflare: proxy ligado, SSL Full (strict), Origin Certificate
      instalado, Bot Fight Mode ligado
- [ ] ⏳ ufw restringindo 80/443 só a IPs da Cloudflare
- [ ] ⏳ Webhook de deploy configurado no GitHub

A partir daqui — com internet livre e credenciais reais — dá pra validar de
verdade os itens que ficaram marcados como "só testado contra fake local" na
seção "A validar assim que subir em produção" do `CLAUDE.md`.
