---
name: setup-vps
description: "Use this skill whenever the user asks to provision, set up, configure, or deploy to a fresh/virgin VPS for a PHP + SQLite project following the José/Jean stack pattern (nginx + PHP-FPM + SQLite, Cloudflare in front, cron-driven automation and deploy) — the same pattern used by JurídicoSaaS and Fastcar CRM. Triggers include: 'VPS virgem', 'montar servidor', 'configurar VPS nova', 'provisionar servidor', 'subir esse projeto numa VPS nova', or requests to replicate this deployment pattern on a sibling project. Not for shared/cPanel hosting (different pattern) and not for cloud PaaS (Heroku/Vercel/Render) deploys."
---

# Setup de VPS — padrão José/Jean (PHP + SQLite + nginx + Cloudflare)

Runbook padronizado pra deixar uma VPS Ubuntu virgem rodando um projeto
PHP puro + SQLite deste padrão (JurídicoSaaS, Fastcar CRM, e qualquer
projeto-irmão futuro que reaproveite a mesma arquitetura). Segue a ordem
de dependência real — cada camada só faz sentido depois da anterior.

## Antes de começar — colete isso do usuário

Se qualquer um destes não estiver claro na conversa, pergunte antes de
rodar comando:

1. **IP e acesso SSH** da VPS (`ssh root@IP` — ou usuário não-root + sudo)
2. **URL do repositório git** do projeto
3. **Domínio** (pode não existir ainda — tudo bem, ver notas ⏳ abaixo)
4. **Caminho de deploy** (padrão: `/var/www/<nome-do-projeto>`)
5. Se o projeto **já tem seu próprio `install/SETUP_VPS.md`** ou script
   `install/setup_crontab.sh`/`install/create_admin.php` — se tiver, ELE é
   a fonte de verdade pros detalhes específicos daquele projeto (nomes de
   cron, campos do primeiro usuário); este skill é o procedimento geral,
   não substitui o guia do projeto quando ele existe e diverge.

**Sem acesso SSH direto nesta sessão:** gere o bloco de comandos exato pra
cada etapa e peça pro usuário rodar no terminal dele, um bloco por vez,
confirmando o resultado antes de passar pro próximo — é o modo como este
runbook foi executado e validado na prática (nunca supor que um passo deu
certo sem ver o output real).

## Ordem de dependência

```
sistema base → nginx+PHP-FPM → código (git) → permissões
  → (testa por IP, sem domínio) → domínio+Cloudflare+firewall
  → automação (cron) → credenciais externas → primeiro usuário
```

### 1. Acesso inicial e sistema base

```bash
ssh root@SEU_IP
passwd                      # trocar a senha root na 1ª vez
apt update && apt upgrade -y
```

VPS nova recebe varredura de bot procurando senha padrão desde o primeiro
minuto — trocar a senha (e, se der, configurar chave SSH em vez de senha)
não é opcional.

### 2. nginx + PHP-FPM + extensões

```bash
apt install -y nginx php-fpm php-sqlite3 php-curl php-mbstring php-xml \
  php-gd php-zip git curl unzip
systemctl enable --now nginx php*-fpm
php -v
ls /run/php/          # nome do socket .sock — precisa no passo 5
```

nginx recebe a requisição HTTP mas não interpreta PHP sozinho — repassa
pro PHP-FPM via socket unix. As extensões (`sqlite3`, `curl`, ...) cobrem
o que esse tipo de projeto sempre usa: banco SQLite, chamadas HTTP pra
IA/WhatsApp/Drive/assinatura eletrônica.

⚠️ **Confira a versão do PHP contra o que o código foi escrito pra
suportar.** Se o dev testou num PHP mais novo que o da VPS (comum —
sandbox de dev costuma vir com PHP mais recente que o repositório padrão
do Ubuntu), sintaxe PHP 8.2+ (ex: `true`/`false` como tipo de retorno
standalone) passa despercebida no lint local e só quebra de verdade na
VPS. Depois de clonar (passo 3), rodar `php tests/smoke.php` cedo — é
exatamente pra pegar isso antes de acontecer em produção.

### 3. Clonar o repositório

```bash
mkdir -p /var/www
cd /var/www
git clone SEU_REPO_GIT.git NOME_DO_PROJETO
cd NOME_DO_PROJETO
git checkout main   # ou a branch de produção
```

Confirma que a pasta de banco/uploads existe de verdade no clone (`ls
database/ storage/uploads/`) — diretório vazio não entra em `git`
sozinho; se o projeto não tiver um `.gitkeep` nessas pastas, criar um
agora é mais barato que descobrir isso só quando `new PDO('sqlite:...)`
falhar por a pasta não existir.

### 4. Permissões

```bash
chown -R www-data:www-data /var/www/NOME_DO_PROJETO
chmod -R 755 /var/www/NOME_DO_PROJETO
chmod -R 775 /var/www/NOME_DO_PROJETO/database /var/www/NOME_DO_PROJETO/storage /var/www/NOME_DO_PROJETO/config
```

`www-data` (usuário do nginx/PHP-FPM) precisa **escrever** em
`database/`, `storage/` e `config/` (banco SQLite, uploads locais,
credenciais tipo `google_drive_credentials.json`) — o resto do código só
precisa ser lido.

**Armadilhas reais já batidas nesse exato passo (Fastcar, 09/2026):**

- **`chown`/`chmod` como root, depois `git` como root de novo** → erro
  "dubious ownership". Corrige com:
  ```bash
  git config --global --add safe.directory /var/www/NOME_DO_PROJETO
  ```
- **`chmod -R 755` na árvore inteira** muda o bit de execução de arquivo
  que não devia ter, e o `git status` passa a mostrar TUDO como
  "modified" (só por causa do fileMode, não mudou conteúdo nenhum) —
  trava até um simples `git checkout`. Corrige com:
  ```bash
  git config core.fileMode false
  ```
- Se a service account do Drive já existir, sobe agora (nunca por
  upload web):
  ```bash
  scp google_drive_credentials.json root@SEU_IP:/var/www/NOME_DO_PROJETO/config/
  chown www-data:www-data /var/www/NOME_DO_PROJETO/config/google_drive_credentials.json
  chmod 640 /var/www/NOME_DO_PROJETO/config/google_drive_credentials.json
  ```

### 5. nginx — server block

`/etc/nginx/sites-available/NOME_DO_PROJETO`:

```nginx
server {
    listen 80;
    server_name SEU_DOMINIO_OU_IP;
    root /var/www/NOME_DO_PROJETO;
    index index.php;

    # Nunca serve dado/config direto — só via PHP com auth
    location ~ ^/(database|storage|config)/ { deny all; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/SOCKET_REAL.sock;   # ver saída de `ls /run/php/` no passo 2
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/NOME_DO_PROJETO /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

Testa sem domínio ainda: `http://SEU_IP/admin/login.php` (ou equivalente
do projeto) deve carregar a tela de login. **Não avança pra Cloudflare
antes disso funcionar por IP puro** — mais fácil debugar nginx/PHP-FPM
isolado do que com mais uma camada por cima.

### 6. Sincronizar código — antes de seguir

Depois do clone, se o repositório já vinha sendo trabalhado (branch
antiga, deploy anterior), confirma que a VPS está na ponta certa:

```bash
git fetch origin main
git checkout main
git pull origin main   # local main pode estar atrás mesmo já "checked out"
php tests/smoke.php    # roda ANTES de seguir — pega incompatibilidade de PHP cedo
```

### 7. Cloudflare — DNS, SSL e proteção contra robô ⏳ precisa de domínio

Quando o domínio existir (senão, pula pro passo 9 e volta aqui depois):

1. Adiciona o domínio na Cloudflare, aponta nameservers.
2. Registro **A** → IP da VPS, **proxy laranja ligado**.
3. **SSL/TLS → Overview**: modo **Full (strict)**.
4. **SSL/TLS → Origin Server**: gera **Origin Certificate** (válido 15
   anos) — cola em `/etc/nginx/ssl/NOME.pem` e `.key` na VPS. Não usar
   certbot/Let's Encrypt aqui — com o proxy da Cloudflare ligado o
   desafio HTTP-01 fica mais chato, e Origin Certificate é o caminho que
   a própria Cloudflare recomenda pra esse cenário.
5. Atualiza o server block pra ouvir 443 com esse certificado e
   redirecionar 80→443 (dois blocos `server`, um só de redirect).
6. **SSL/TLS → Edge Certificates**: liga "Always Use HTTPS".
7. **Security → Bots**: liga "Bot Fight Mode" — reforça na borda o que
   `robots.txt`/`X-Robots-Tag` já fazem na aplicação, antes de gastar
   recurso da VPS. Essencial se o sistema guarda dado pessoal/financeiro.
8. **Firewall da VPS — só aceita 80/443 vindo de IP da Cloudflare**:
   ```bash
   apt install -y ufw
   ufw allow 22/tcp
   for ip in $(curl -s https://www.cloudflare.com/ips-v4); do ufw allow from $ip to any port 80,443 proto tcp; done
   for ip in $(curl -s https://www.cloudflare.com/ips-v6); do ufw allow from $ip to any port 80,443 proto tcp; done
   ufw default deny incoming
   ufw enable
   ```
   ⚠️ **Nunca rodar `ufw enable` sem confirmar que a porta 22 está liberada
   ANTES** — regra mal configurada tranca o próprio acesso SSH, e aí só
   resolve pelo console da hospedagem (fora do SSH).

### 8. Crontab — automação

```bash
cd /var/www/NOME_DO_PROJETO
bash install/setup_crontab.sh   # se o projeto tiver esse script
crontab -l                      # confere que entrou
```

Se o projeto não tiver `setup_crontab.sh` próprio, cadastra manualmente
os jobs recorrentes documentados no `CLAUDE.md`/README dele — followup de
lead, sincronização de assinatura eletrônica, backup do banco várias
vezes ao dia. **O `git pull` do deploy automático nunca roda disparado
direto pela requisição HTTP do webhook** — o webhook só agenda um
marcador (`storage/.deploy`), o cron que detecta e aplica no minuto
seguinte. Decouplar assim evita puxar código no meio de uma request real.

⚠️ Gotcha real (Fastcar, 13/09/2026): a linha de deploy do crontab tem que
rodar `git pull` **e o script de migração de banco do projeto em seguida
(ex: `php install/migrar.php`), sempre os dois juntos**. Só `git pull`
sozinho atualiza o código mas nunca o schema — a 1ª tela que tocar numa
coluna nova quebra com "no such column" até alguém entrar via SSH e rodar
a migração na mão. Um script de migração bem feito é idempotente (rodar
sem nada novo pra migrar não faz nada), então incluir ele em TODA rodada
de deploy automático — não só quando "sei que teve migração dessa vez" —
é seguro e evita esse gap ficar esquecido.

### 9. Deploy automático via GitHub ⏳ webhook precisa de domínio/URL pública

```bash
openssl rand -hex 32   # secret do webhook
```

Cola esse secret em Configurações do próprio admin do projeto (se
existir essa tela) e cadastra em `Settings → Webhooks → Add webhook` no
GitHub: URL = `https://SEU_DOMINIO/api/webhook_deploy.php` (ou
equivalente do projeto), content type `application/json`, secret igual,
evento só `push`. Até o domínio existir, deploy continua manual: `git
pull origin main` na VPS.

### 10. Credenciais externas (e-mail, IA, WhatsApp, Drive, assinatura)

Não dependem de domínio pra funcionar (exceto o "de" do e-mail, que
melhora reputação/entrega quando é do domínio final). Cada serviço:
cria conta → gera chave/token → cola na tela de Configurações do projeto
→ testa (a maioria desses admins já tem um botão "testar conexão" —
usar sempre, nunca confiar que colou certo sem ver o teste passar).

### 11. Primeiro usuário

```bash
cd /var/www/NOME_DO_PROJETO
php install/create_admin.php "Nome" email@dominio.com "senha-forte" super_admin
```

Sempre via CLI, nunca por tela do admin (decisão de segurança de
propósito nesse padrão — evita qualquer um com acesso ao painel criar
outro super_admin sozinho).

## Checklist final

- [ ] `http(s)://.../admin/login.php` carrega e loga
- [ ] `php tests/smoke.php` roda sem falha, direto na VPS
- [ ] Todas as credenciais externas configuradas e testadas
- [ ] `crontab -l` mostra os jobs esperados
- [ ] Backup manual disparado uma vez (se o projeto tiver essa tela) pra
      confirmar que funciona contra o serviço real, não só localmente
- [ ] ⏳ Cloudflare: proxy ligado, SSL Full (strict), Origin Certificate,
      Bot Fight Mode
- [ ] ⏳ `ufw` restringindo 80/443 só a IPs da Cloudflare
- [ ] ⏳ Webhook de deploy configurado no GitHub

## Depois de rodar este skill

Se o projeto tem uma seção tipo "A validar assim que subir em produção"
no `CLAUDE.md` dele (funções testadas só contra servidor fake local
durante o desenvolvimento, nunca contra a API real) — esse é o momento
de ir validando item por item, com internet livre e credencial de
verdade, e atualizar essa seção conforme cada coisa for confirmada.
