---
name: evolution-whatsapp
description: Instalar, configurar e integrar a Evolution API (WhatsApp self-hosted, Baileys) num sistema PHP puro + SQLite, deixando o WhatsApp Box (inbox do CRM) funcionando igual ao Fastcar — envio de texto/foto/vídeo/documento/áudio gravado, recebimento de mídia, foto/nome do contato, badge de conexão, webhook multi-instância (principal/vendas/financeiro), dedup, debounce e IA pausando quando um humano responde. Use sempre que o usuário pedir pra "instalar/configurar Evolution", "trocar Z-API por Evolution", "conectar WhatsApp no CRM/inbox", "igual o Fastcar", ou quando aparecer erro de envio/recebimento com Evolution ("instance does not exist", "instance requires property text", "Owned media must be a url, base64...", foto aparecendo como [image], badge vermelho com o celular conectado).
---

# Evolution API + WhatsApp Box (padrão Fastcar)

Referência viva: repositório `nenmp4/fastcar` — `includes/whatsapp_evolution.php`,
`chatbot-whatsapp/webhook/whatsapp_evolution.php`, `includes/whatsapp_config.php`
(dispatch), `includes/whatsapp_inbox.php` + `admin/whatsapp_inbox.php` (caixa),
`admin/configuracoes.php` (cards de configuração), `admin/_zapi_status.php` (badge).
Quando estiver num projeto-irmão, **leia esses arquivos antes de escrever do zero** —
cada detalhe abaixo foi corrigido contra a instância real, não contra documentação.

> ⚠️ Risco estrutural: Evolution usa Baileys (WhatsApp Web/multi-device,
> NÃO oficial). Self-hosted dá IP/infra próprios, mas não elimina risco de
> banimento. Nunca prometa "não vai ser banido". Boas práticas que reduzem
> risco: número aquecido com uso humano antes de parear, nunca disparo em
> massa/mensagem idêntica repetida, automação ativa só com opt-in e em
> horário comercial, responder "SAIR" na hora.

---

## 1. Instalação (servidor)

Duas formas:

**A) Addon pronto da hospedagem** (o Fastcar usou o "Evolution API Whats" da
HostGator, v2.3.7). Já vem com Manager web + Postgres. Só anote: URL base
(`https://IP-ou-host:8080`), **API key global** e acesse o Manager.

**B) Docker na própria VPS** (Ubuntu). Esqueleto — confirme nome/versão da
imagem e variáveis no README da versão escolhida antes de subir (a imagem
mudou de dono entre versões: `atendai/evolution-api` → `evoapicloud/evolution-api`;
**fixe uma tag exata**, nunca `latest`):

```yaml
# docker-compose.yml
services:
  evolution:
    image: evoapicloud/evolution-api:v2.3.7   # fixe a versão
    restart: always
    ports: ["127.0.0.1:8080:8080"]            # só local; nginx expõe com SSL
    env_file: .env
    volumes: [evolution_instances:/evolution/instances]
    depends_on: [postgres, redis]
  postgres:
    image: postgres:15
    restart: always
    environment: { POSTGRES_USER: evo, POSTGRES_PASSWORD: TROQUE, POSTGRES_DB: evolution }
    volumes: [pgdata:/var/lib/postgresql/data]
  redis:
    image: redis:7
    restart: always
volumes: { evolution_instances: {}, pgdata: {} }
```

`.env` mínimo (nomes de variável da v2 — conferir no README da tag):
```
SERVER_URL=https://evolution.seudominio.com
AUTHENTICATION_API_KEY=<gere com: openssl rand -hex 32>
DATABASE_PROVIDER=postgresql
DATABASE_CONNECTION_URI=postgresql://evo:TROQUE@postgres:5432/evolution
CACHE_REDIS_ENABLED=true
CACHE_REDIS_URI=redis://redis:6379/6
```

**SSL**: prefira um subdomínio com certificado válido (nginx reverse proxy
→ `127.0.0.1:8080` + `certbot --nginx -d evolution.seudominio.com`).
- ⚠️ Gotcha real: se o domínio-pai já usa **HSTS** (ex: `sistema.dominio`
  com HTTPS), o navegador recusa certificado autoassinado em QUALQUER
  subdomínio dele — ou usa certificado válido, ou acessa por IP.
- Sem certificado válido (IP cru + autoassinado): o PHP cURL rejeita por
  padrão. O Fastcar tem opt-in explícito `config.evolution_ignorar_ssl='1'`
  (checkbox em Configurações, desligado por padrão, só pra própria VPS).

Firewall: a porta 8080 nunca aberta pro mundo se houver nginx na frente.

---

## 2. Instância (número de WhatsApp)

1. No Manager (ou API): criar instância, integração **WHATSAPP-BAILEYS**,
   parear pelo **QR code** no celular (WhatsApp → Aparelhos conectados).
   - API (v2, conferir): `POST /instance/create` `{"instanceName":"...","qrcode":true,"integration":"WHATSAPP-BAILEYS"}`;
     QR: `GET /instance/connect/{instance}`.
2. **O identificador usado nas rotas é o campo `name` da instância** —
   NUNCA o hash/token mostrado em destaque no Manager nem o UUID da URL
   (os dois dão `"The \"X\" instance does not exist"`). Na dúvida,
   `GET /instance/fetchInstances` com a API key global lista o `name` real.
   Copie byte a byte (espaço/typo inclusos — no Fastcar é "Fastacar Solutions").
3. Uma instância por canal se precisar separar (Fastcar: principal/compra,
   vendas, financeiro) — mesma VPS/URL base, números diferentes.

## 3. Webhook (receber mensagens)

Configure **uma URL só** (todas as instâncias podem usar a mesma):
`https://SEU_SISTEMA/chatbot-whatsapp/webhook/whatsapp_evolution.php`

- Evento: **`MESSAGES_UPSERT`** (só esse é necessário pro inbox).
- **"Webhook By Events" DESLIGADO** (senão a Evolution anexa `/messages-upsert`
  na URL e cai em 404).
- **"Webhook Base64" desligado** — a mídia é baixada sob demanda (seção 5).
- API (v2, conferir): `POST /webhook/set/{instance}`
  `{"webhook":{"enabled":true,"url":"...","byEvents":false,"base64":false,"events":["MESSAGES_UPSERT"]}}`.
- Lição Z-API: **não valide origem por header/campo não confirmado** (o
  Fastcar rejeitou 100% das mensagens por um Client-Token que o provedor
  nunca mandava). Valide pelo **nome da `instance`** no envelope
  `{event, instance, data}` — instância desconhecida → 401.

---

## 4. Configuração no sistema (tabela `config`, chave/valor)

| chave | uso |
|---|---|
| `evolution_base_url` | URL base (sem barra final) — compartilhada pelas instâncias |
| `evolution_instance_name` / `evolution_api_key` | instância principal |
| `evolution_vendas_instance_name` / `_api_key` | instância de vendas |
| `evolution_financeiro_instance_name` / `_api_key` | instância do financeiro |
| `evolution_ignorar_ssl` | `'1'` só pra VPS própria sem certificado válido |
| `whatsapp_provider_principal` | `'zapi'`/`'oficial'`/`'evolution'` — canal ativo (radio em Configurações, nunca troca sozinho) |
| `whatsapp_zapi_meta_desativado` | kill switch: nasce `true`, bloqueia Z-API/Meta e força Evolution |

Tela de Configurações: um card por instância com **Salvar**, **Testar
conexão** (`GET /instance/connectionState/{instance}` → `state:"open"`) e
**"🔎 Listar instâncias (diagnóstico)"** (`GET /instance/fetchInstances`),
mais a URL do webhook pronta pra copiar com o aviso de "By Events desligado".

Header de toda chamada: `apikey: <API key>` + `Content-Type: application/json`.

---

## 5. Formato REAL da API (v2.3.7 — confirmado em produção)

Telefone sempre normalizado com DDI (`55` + DDD + número, 12-13 dígitos).

**Texto** — corpo **FLAT** (a doc pública mostrava aninhado; está errado):
```
POST /message/sendText/{instance}
{"number":"5511999999999","text":"olá"}
```
Erro se aninhar: `instance requires property "text"`.

**Imagem/vídeo/documento** — **JSON**, nunca multipart:
```
POST /message/sendMedia/{instance}
{"number":"...","mediatype":"image|video|document","media":"<URL pública OU base64 CRU>",
 "mimetype":"image/jpeg","fileName":"foto.jpg","caption":"legenda opcional"}
```
**Áudio (nota de voz)**:
```
POST /message/sendWhatsAppAudio/{instance}
{"number":"...","audio":"<URL pública OU base64 CRU>"}
```
- "base64 CRU" = sem o prefixo `data:mime;base64,`.
- ⚠️ Bug real que travou 100% do áudio gravado: o navegador grava
  `audio/webm;codecs=opus` / `audio/ogg;codecs=opus`. O regex que separa o
  data URI precisa aceitar parâmetros no mime:
  `#^data:([^,]*?);base64,(.+)$#s` (classe `[^,]*`), e o mime enviado é só a
  parte antes do 1º `;`. Com classe restrita (`[a-zA-Z0-9/+.\-]+`) a string
  inteira vai crua e a Evolution responde 400
  `"Owned media must be a url, base64, or valid file with buffer"`.

**Status da conexão**: `GET /instance/connectionState/{instance}` →
`{"instance":{"state":"open|close|connecting"}}`.

**Foto/nome do contato** (pedir fresco, nunca cachear URL da CDN — expira):
`POST /chat/fetchProfilePictureUrl/{instance}` `{"number":"..."}` →
`profilePictureUrl`; `POST /chat/findContacts/{instance}`
`{"where":{"id":"<numero>@s.whatsapp.net"}}` → `pushName`/`profilePicUrl`.
Filtre nome que é status ("online", "Disponível") — comparação exata.

**Baixar mídia RECEBIDA** (o webhook não traz URL — Baileys exige decriptar;
a Evolution decripta por você):
```
POST /chat/getBase64FromMediaMessage/{instance}
{"message":{"key":{"id":"<messageId do webhook>"}},"convertToMp4":false}
→ {"base64":"...","mimetype":"..."}
```
Use as credenciais da MESMA instância que recebeu. Sem isso, a mídia aparece
como `[image]`/`[audio]` cru no inbox. Mídia antiga pode já ter expirado no
servidor — falhe gracioso.

**Diagnóstico de erro**: sempre guarde o corpo da resposta de erro
(`evolutionUltimoErro()`) e mostre na tela junto da mensagem genérica — foi
isso que revelou o formato flat do sendText. Num 3xx, mostre também o
`CURLINFO_REDIRECT_URL` (URL base com http/https/porta errada).

---

## 6. Webhook → mesmo processador de sempre

Payload (`MESSAGES_UPSERT`, aceitar também `messages.upsert`):
```
{"event":"MESSAGES_UPSERT","instance":"<name>","data":{
  "key":{"remoteJid":"5511...@s.whatsapp.net","fromMe":false,"id":"<msgid>"},
  "pushName":"Fulano",
  "message":{"conversation":"oi"} | {"extendedTextMessage":{"text":"..."}}
          | {"imageMessage":{"caption","mimetype"}} | audioMessage | videoMessage
          | {"documentMessage":{"mimetype","fileName"}} }}
```
Padrão do Fastcar: um **adaptador** (`evolutionAdaptarPayloadParaZapi()`)
converte isso pro formato interno único (`messageId`, `phone`, `fromMe`,
`isGroup`, `senderName`, `text.message` / `image` / `audio` / `video` /
`document`) e o MESMO processador que já atendia Z-API/Meta roda —
nenhuma regra de negócio duplicada por provedor.

Roteamento por instância (`evolutionIdentificarCanal($instance)`):
`principal` → `processarMensagemZapi()`, `vendas` →
`processarMensagemVendasZapi()`, `financeiro` →
`processarMensagemFinanceiroZapi()`; passe `canal:'evolution'` adiante pra
resposta do mesmo turno sair pelo mesmo canal e a mídia ser baixada pelo
endpoint da seção 5.

Ordem obrigatória dentro do processador:
1. Ignorar evento sem mensagem real (presença/status/ack) **antes** de
   gravar qualquer coisa — evento desconhecido nunca gera resposta
   automática (incidente real de flood).
2. Ignorar grupo; `fromMe` só registra, nunca processa.
3. **Dedup por `messageId`** (`jaProcessado()`) — o provedor reentrega.
4. Salvar a mensagem SEMPRE (mesmo com IA pausada) — salva desde o 1º contato.
5. Opt-out ("SAIR"/"PARAR", comparação exata) e pedido de exclusão LGPD
   antes da IA.
6. Debounce (~4s de silêncio) antes da IA — cliente que manda 3 mensagens
   picotadas recebe 1 resposta só.
7. IA só se a sessão não estiver pausada (`whatsapp_sessoes.ia_pausada`).
8. try/catch ao redor de tudo; falha real → HTTP 500 (deixa reentregar),
   nunca fatal error cru.

---

## 7. Envio: dispatcher único

Todo o sistema chama UMA função por tipo (`zapiEnviarTexto/Imagem/Video/
Audio/Documento()` no Fastcar, nome histórico) que decide o provedor:
- canal principal → Evolution se `whatsapp_provider_principal='evolution'`
  ou kill switch ligado;
- vendas/financeiro → instância Evolution dedicada sempre que configurada;
- nunca fallback "instância → instância" do mesmo provedor numa falha
  (piora shadowban); fallback só entre provedores diferentes, se existir.

Notificação interna pra WhatsApp pessoal da equipe tem função própria
(`zapiEnviarTextoInterno()`) — nunca o mesmo caminho de mensagem ao cliente.

---

## 8. WhatsApp Box (inbox) — o que tem que existir

- **Sidebar** com conversas (nome, última mensagem, não lidas, IA pausada),
  polling a cada ~5s **sempre** (não só com conversa aberta); "+ Iniciar
  conversa" com `normalizarTelefone()`.
- Query da última mensagem por telefone com `JOIN (SELECT telefone,
  MAX(id) ...GROUP BY telefone)`, nunca subquery correlacionada (lento).
- **Thread** com polling ~2s (`after_id`), "carregar anteriores"
  (`before_id`, preservando scroll), bolha marcada com `data-id` pra nunca
  duplicar (corrida entre polling e resposta do envio).
- **Enviar** texto assinado `*{Nome do atendente}:*\n` (só no que vai pro
  WhatsApp; o salvo no CRM fica limpo) e **pausar a IA** ao responder.
- **Anexo** (📎 imagem/PDF/planilha) e **áudio gravado** (🎤 `MediaRecorder`,
  clique/clique, flag `cancelando` — o `stop()` dispara um último
  `dataavailable`), base64 via AJAX, limite 20MB checado no cliente E no
  servidor; envia primeiro, só grava + salva cópia se o envio deu certo.
- **Mídia recebida**: salva a cópia (Drive/local) e serve por endpoint
  autenticado por conversa (`ver_midia_whatsapp.php`), cache em disco por
  id + `Cache-Control: private, immutable` (nunca mudam).
- **Foto de perfil** ao vivo via `?ajax=foto` em lotes, troca o placeholder
  só no `onload` (nunca ícone quebrado), cache em memória por telefone pra
  não piscar no polling; sem `loading="lazy"` em `<img>` fora do DOM.
- Permissão: consultor só vê conversa de cliente sob sua responsabilidade
  — checada também em todo GET/POST/AJAX, não só na sidebar.
- Endpoints de polling/mídia chamam `liberarSessao()`
  (`session_write_close()`) logo após autenticar — senão o polling trava
  todas as outras requests do mesmo navegador.
- **Badge de conexão** no topo: `connectionState` com cache de 60s em
  `config`, a escrita do cache **dentro de try/catch** (escrita de cache em
  toda página + SQLite = "database is locked" derrubando a tela inteira).

---

## 9. Checklist de validação

1. Configurações → Testar conexão → "state: open".
2. Mandar "oi" de outro celular → aparece no inbox em ≤5s, lead criado.
3. Responder pela caixa → chega assinado, IA pausada.
4. Cliente manda foto/áudio/documento → abre de verdade no inbox (não `[image]`).
5. Gravar áudio de 5s → chega como nota de voz.
6. Anexar foto e PDF → chegam com nome/legenda.
7. Reenviar o mesmo webhook (mesmo `messageId`) → nada duplicado.
8. Desconectar o celular → badge muda em ≤60s.
9. Testar contra servidor fake local antes (php -S simulando os endpoints
   da seção 5), banco isolado, e só então a instância real.
10. `php tests/smoke.php` limpo; nunca `php -r` pra teste que escreve no banco.

## 10. Erros já vistos → causa

| sintoma | causa |
|---|---|
| `The "X" instance does not exist` | usou hash/UUID em vez do `name` |
| `instance requires property "text"` | sendText aninhado em vez de flat |
| `Owned media must be a url, base64, or valid file with buffer` | data URI com `;codecs=opus` não separado — prefixo foi junto |
| foto/áudio do cliente vira `[image]` no inbox | não chamou `getBase64FromMediaMessage` (ou instância errada) |
| cURL SSL error | certificado autoassinado — usar certificado válido ou opt-in de ignorar SSL |
| 404 no webhook com sufixo `/messages-upsert` | "Webhook By Events" ligado |
| webhook 401 no log | nome da instância no sistema ≠ `instance` do payload |
| badge vermelho mas envia normal / tela "Erro interno" | escrita do cache de status sem try/catch sob contenção do SQLite |
| áudio demora | medir: log de timing por trecho (provedor × Drive); token do Drive cacheado |
