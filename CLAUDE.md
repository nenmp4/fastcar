# Fastcar CRM — Contexto do Projeto para Claude Code

## Visão Geral

CRM white-label pra Fastcar — compra de veículos financiados (proprietário vende
o carro ainda em financiamento, Fastcar avalia e compra). Stack decidida:
**reaproveitar o padrão do JurídicoSaaS** (repo irmão `nenmp4/iabadvocaciaboutique`)
— PHP puro (ea-php84) + SQLite + cPanel/Value Host, mesma arquitetura de
`includes/db.php`, `includes/security.php`, bot WhatsApp e CRM.

- **Repositório:** https://github.com/nenmp4/fastcar
- **Estado:** projeto novo, sem deploy ainda — repo estava vazio até 12/09/2026
- **Responsável de negócio:** Jean Susej (define fluxo, negociação e fechamento — bloco 6/7 do funil)
- **Dev:** José (mesmo dev do JurídicoSaaS)

---

## Funil (8 blocos — definido pelo Jean em 12/09/2026)

```
1. Anúncio/tráfego pago
        ↓
2. Entrada pelo WhatsApp ────────────────┐
        ↓                                 │ (sem resposta / ainda não decidiu)
3. Qualificação com IA ──→ Sem perfil    │
        ↓  (com perfil)      → registra motivo, encerra
4. CRM automático (preenchimento)         │
        ↓                                 │
5. Atendimento do consultor ←─────────────┤
        ↓                                 │
6. Negociação — Jean/Closer ←─────────────┘
        ↓
7. Reunião presencial / fechamento
        ↓
8. Pasta fechada — compra concluída
```

| Bloco | O que acontece | O que o sistema registra |
|-------|----------------|---------------------------|
| 1. Anúncio/tráfego | Atrai proprietário interessado em vender; anúncio direciona pro WhatsApp | Canal, campanha e anúncio de origem (`clientes.canal_origem/campanha_origem/anuncio_origem`) |
| 2. WhatsApp | Recebe contato, inicia conversa automaticamente | Nome, telefone, data de entrada, histórico (`whatsapp_mensagens`) — **salva desde o 1º contato**, mesmo sem qualificação completa |
| 3. Qualificação IA | IA identifica veículo e entende financiamento/intenção | Modelo, ano, banco, valor parcela, parcelas restantes, atraso, cidade/estado, valor pretendido (`oportunidades.*`) — **campo não informado fica NULL (pendência), IA nunca inventa** |
| 4. CRM automático | Cria/atualiza cadastro, preenche dados coletados, encaminha pra consultor | Dados cliente+veículo, resumo da IA, pendências, responsável, etapa (`oportunidades`) |
| 5. Consultor | Liga/manda mensagem humanizada, confirma dados | Tentativas de contato, observações, fotos, resultado, próxima ação |
| 6. Jean/Closer | Avalia oportunidade, define proposta, negocia | Valor ofertado, contraproposta, condições, aprovação, motivo de perda (`oportunidades.valor_ofertado` etc) |
| 7. Presencial/fechamento | Agenda reunião, avalia veículo, confirma condições, executa compra | Agendamento, avaliação, documentos, contrato, transferência, pagamento |
| 8. Pasta fechada | Consolida negócio, marca compra concluída | Pasta digital: documentos, contrato, comprovantes, valor final, data, responsável — **só libera com checklist completo** |

---

## Regras de negócio (não negociar sem confirmar com o Jean)

1. **1 cadastro por telefone** — `clientes.telefone` é `UNIQUE`. Um mesmo cliente
   pode ter **mais de um veículo** em negociação → 1:N pra `oportunidades`, não 1:1.
2. **Salvar desde o primeiro contato** — o CRM registra o lead já na entrada pelo
   WhatsApp (etapa `whatsapp`), mesmo que o preenchimento completo só aconteça
   no bloco 4. Nunca esperar a qualificação terminar pra criar o registro,
   senão conversa abandonada não fica salva em lugar nenhum.
3. **IA sem inventar informação** — dado não informado fica `NULL`/pendente no
   schema, nunca um valor chutado. Valores e condições de compra **sempre**
   dependem de aprovação humana (bloco 6, `oportunidades.aprovado_por`).
4. **Passagem pro consultor pausa a IA** — `whatsapp_sessoes.ia_pausada=1`
   quando um humano assume a conversa; a IA não pode responder por cima.
   Consultor recebe histórico completo + resumo gerado pela IA
   (`oportunidades.resumo_ia`), não só o card vazio.
5. **Toda oportunidade aberta precisa de responsável e próxima ação** — com
   alerta de atraso (`oportunidades.proxima_acao_em` no passado = atrasado).
6. **Mudança de etapa sempre grava histórico** — nunca fazer
   `UPDATE oportunidades SET etapa=...` direto; usar uma função central
   (`mudarEtapa()`, a criar em `includes/oportunidades.php`) que também
   insere em `oportunidade_historico` com data + responsável.
7. **"Compra concluída" exige checklist** — a aplicação só permite
   `etapa='fechado'` quando todos os `oportunidade_documentos.obrigatorio=1`
   daquela oportunidade estiverem preenchidos. Não é trava de banco (SQLite
   não tem esse tipo de constraint condicional prática), é regra de aplicação
   — validar em toda rota que tenta fechar a oportunidade, não só na tela
   principal.
8. **"Compra concluída" ≠ fim de tudo** — encerra o funil comercial
   (`oportunidades.etapa`), mas pendência futura (ex: quitação de
   financiamento junto ao banco) continua vinculada à mesma pasta via
   `oportunidade_pendencias_pos_venda`, num controle operacional separado
   do funil de vendas.

---

## Schema do banco

Ver `install/schema.sql` — criado automaticamente por `includes/db.php::getDB()`
na primeira conexão (banco não existe ainda → roda o schema inteiro).

Tabelas: `clientes`, `oportunidades`, `oportunidade_historico`,
`whatsapp_mensagens`, `whatsapp_sessoes`, `oportunidade_documentos`,
`oportunidade_pendencias_pos_venda`, `usuarios`, `config`,
`zapi_instancias_consultores` (instância Z-API própria de cada consultor/closer,
só pra monitorar produtividade — ver seção de arquitetura Z-API abaixo),
`contratos` (contrato gerado + rastreio de assinatura eletrônica via Assinafy).

> ⚠️ Igual ao JurídicoSaaS: `config` é só `chave TEXT PRIMARY KEY, valor TEXT`,
> sem `updated_at`. Cache com TTL usa o padrão `"timestamp|json"` no valor.

---

## Módulos implementados (código pronto e testado contra fakes locais)

- **Webhook WhatsApp + funil** — `chatbot-whatsapp/webhook/whatsapp.php` +
  `chatbot-whatsapp/includes/mensagens.php` (lógica compartilhada com o
  simulador de CLI `chatbot-whatsapp/simulate.php`, útil pra testar o bot
  sem precisar de credencial Z-API real)
- **Arquitetura multi-instância Z-API** — a instância **principal** cuida só
  dos blocos 2-4 (entrada, qualificação IA, followup automático); a partir do
  bloco 5 (atendimento), toda comunicação com aquele cliente passa a ser pela
  instância própria do consultor/closer responsável (`zapi_instancias_consultores`),
  nunca mais pelo número principal naquele negócio. As instâncias dos
  consultores servem só pra **monitorar produtividade** (`admin/produtividade.php`),
  não pra rodar o bot
- **Fila de leads / plantão** — `includes/fila_leads.php`: round-robin entre
  consultores `disponivel=1` via contador monotônico `usuarios.posicao_fila`
  (não timestamp — SQLite só tem granularidade de 1s, ver bug real na seção
  de bugs corrigidos); se ninguém estiver disponível, cai no plantão
  (`usuarios.plantao_fim_expediente`)
- **Qualificação por IA** — `includes/ia_qualificacao.php` +
  `includes/gemini.php` + `includes/openai.php`: Gemini como principal, GPT
  como fallback (ver pendência #3)
- **Atribuição de origem de anúncio** — `extrairOrigemAnuncio()` (Meta Ads
  "Clique para WhatsApp", campo `referral` do 1º contato) +
  `admin/origem_leads.php` (analytics de canal/campanha/anúncio)
- **Módulo cliente** — `admin/clientes.php` (lista/busca) +
  `admin/cliente_detalhe.php` (dados cadastrais + histórico de todas as
  oportunidades daquele telefone) — badge "🏆 Cliente convertido" quando o
  cliente já teve pelo menos um veículo com `etapa='fechado'`
- **Módulo de formulário/documentos** — `public/documentos.php` (link com
  token, sem login, cliente sobe CNH/comprovante de endereço/contrato de
  financiamento + completa dados pessoais); arquivos vão pro **Google Drive**
  (`includes/google_drive.php`, service account, mesmo padrão do
  JurídicoSaaS: pasta raiz "Fastcar" → subpasta por cliente → arquivos),
  com `storage/uploads/` local como fallback só se o Drive não estiver
  configurado ou uma chamada falhar
- **Módulo de contrato (só COMPRA)** — `includes/contratos.php` +
  `includes/contratos_pdf.php` (PDF via FPDF puro, sem LibreOffice/Composer —
  shared hosting não teria isso — transcrito do modelo real
  `01_Contrato_Mestre_FASTCAR_Compra_Quitacao_Futura.docx`) + assinatura
  eletrônica via **Assinafy** (`includes/assinafy.php`, webhook
  `api/assinafy_webhook.php` + fallback de polling `cron/assinafy_sync.php`).
  Fluxo confirmado com o Jean: cliente preenche dados pelo link do
  formulário → consultor confere → **closer preenche os campos
  financeiros/de negociação só na hora de fechar o negócio** (bloco 6) →
  dispara o contrato. Contrato de **VENDA** (Fastcar revende o carro) fica
  pro módulo de vendas, fora de escopo agora (ver "Segunda etapa" abaixo)
- **Configurações de super admin** — `admin/configuracoes.php`: Z-API
  principal, IA (Gemini + OpenAI fallback), Google Drive, Assinafy, fila de
  leads/plantão, instâncias dos consultores
- **PWA (instalável como app)** — `admin/manifest.json` + `admin/sw.js`
  (service worker mínimo, sem cache agressivo — dados do CRM são sempre
  dinâmicos), mesmo padrão do JurídicoSaaS. Como o admin da Fastcar (ao
  contrário do JurídicoSaaS) não tem um `layout.php` compartilhado — cada
  página tem seu próprio `<head>`/`<body>` — as tags entram via 2 partials
  (`admin/_pwa_head.php`, `admin/_pwa_register.php`) incluídos em toda
  página cheia; guard `admin-pagina-sem-pwa` no `tests/smoke.php` garante
  que uma página nova nunca esqueça de incluir os dois (mesma classe de bug
  do head_scripts nas landing pages do JurídicoSaaS). Ícones em
  `admin/assets/img/icon-192.png`/`icon-512.png` são **placeholder** (gerado
  localmente, "FC" em fundo escuro) até a logo real da Fastcar ser enviada.
- **Smoke test** — `tests/smoke.php` (rodar antes de todo commit: `php
  tests/smoke.php`) + `version.json` (changelog semver) — mesmo padrão do
  JurídicoSaaS (LINT + GUARDS de regressão + SCHEMA), guards codificando os
  bugs reais já corrigidos aqui (ver seção de bugs corrigidos abaixo)

## Segunda etapa (combinado com o Jean/José — não iniciar sem pedido novo)

Itens explicitamente adiados durante a conversa, pra não se perderem:

- **Módulo de vendas** (Fastcar revende o veículo pro próximo comprador) —
  inclui o contrato-modelo de VENDA (`01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx`,
  já lido/estruturado, mas nada implementado)
- **Módulo financeiro** — relatórios financeiros, reaproveitando o módulo
  financeiro do JurídicoSaaS
- **Módulo de saúde do sistema** — dashboard de monitoramento/logs,
  reaproveitando o módulo de saúde do JurídicoSaaS
- **2FA no login do admin** — reaproveitando o padrão do JurídicoSaaS
- **Verificação de documentos por IA** (OCR/conferência automática do que o
  cliente subiu contra o que foi digitado) — depende de decidir o provedor
  de IA (✅ já decidido, Gemini+GPT) mas o **fluxo de verificação em si**
  ainda não foi desenhado nem pedido de volta

## O que reaproveitar do JurídicoSaaS (padrões já testados em produção)

- `includes/db.php` — `PRAGMA busy_timeout=5000` obrigatório em toda conexão
  SQLite avulsa (webhook do WhatsApp é o processo mais concorrente, mesma
  lição aprendida lá depois de uma saga real de "database is locked")
- `includes/security.php` — CSRF session-persistent, `startSecureSession()`
  com `SameSite=Lax` (não `Strict`, quebra PWA em modo standalone)
- Normalização de telefone com DDI 55 (`normalizarTelefone()`) — mesma
  função, mesmo motivo: telefone é chave de identificação
- **Z-API confirmado como provedor de WhatsApp** (mesmo do JurídicoSaaS,
  instância própria da Fastcar) — padrão de webhook: dedup de `messageId`
  (evita processar 2x o mesmo webhook), salvar mensagem antes de processar,
  checar `fromMe`/grupo antes de rodar qualquer lógica de bot
- **Gemini + fallback OpenAI** (`includes/gemini.php`, `includes/openai.php`)
  — mesmo padrão de fallback duplo do JurídicoSaaS: tenta Gemini primeiro,
  só cai pro GPT se o Gemini falhar/não estiver configurado
- **Assinafy** (`includes/assinafy.php`) e **Google Drive**
  (`includes/google_drive.php`, JWT RS256 via service account) — portados
  quase 1:1 do JurídicoSaaS, só adaptando pra `getConfig()`/`setConfig()`
  em vez de SQL cru inline
- **PWA** (`admin/manifest.json`, `admin/sw.js`) — mesmo `SameSite=Lax` (não
  `Strict`) em `startSecureSession()` continua valendo pelo mesmo motivo do
  JurídicoSaaS: `Strict` quebra a sessão no modo standalone instalado
- Guard `sqlite-sem-busy-timeout` do `tests/smoke.php` do JurídicoSaaS —
  **já recriado aqui** em `tests/smoke.php` (ver seção de módulos acima),
  junto com outros guards específicos dos bugs reais encontrados nesta sessão

## O que NÃO reaproveitar (é outro negócio, cuidado pra não copiar sem revisar)

- Módulos jurídicos (AASP, DataJud, EAD, Financeiro, ferramentas de diagnóstico)
  — não fazem sentido pra compra de veículo, não portar por padrão
- `MODULOS` (constante de áreas jurídicas) — Fastcar não tem "módulos", tem
  etapas de funil; não confundir os dois conceitos na hora de desenhar o admin

---

## Cron Jobs

| Cron | Horário sugerido | Função |
|------|-------------------|--------|
| `cron/followup.php` | a cada 30 min | Dois papéis: (1) alerta pro responsável quando `oportunidades.proxima_acao_em` está no passado e a etapa ainda está ativa — dedup de 4h por oportunidade via `config.alerta_atraso_{id}`, só marca como enviado se `zapiEnviarTexto()` retornar sucesso; (2) reengajamento de lead esfriando: oportunidade ainda em `whatsapp`/`qualificacao_ia`, sem responsável assumido, cuja última mensagem `in` foi há 30-120 min sem resposta nossa depois — mesma janela do `followup_leads.php` do JurídicoSaaS, dedup via `config.reeng_sent_{telefone}` |

> Testado localmente com banco de teste isolado: identificou corretamente 1
> oportunidade atrasada + 1 esfriando, e o dedup de 4h bloqueou reenvio do
> alerta na 2ª execução imediata (o reengajamento seguiu tentando porque a
> tentativa anterior falhou por falta de credencial Z-API real — o guard só
> marca "enviado" em caso de sucesso, de propósito).
>
> Ainda falta cadastrar no crontab real quando a hospedagem for definida
> (pendência #1 abaixo) — por enquanto só existe o script, sem agendamento.

## Pendências (aguardando definição antes de codar mais)

1. **Hospedagem/deploy** — **em andamento (12/09/2026):** decidido ir de VPS
   própria em vez do padrão cPanel+webhook do JurídicoSaaS, pra ter acesso
   root de verdade e liberdade de configuração (a VPS anterior estava
   travada em acesso root/sudo). Cotação em andamento na Hostinger — indicado
   plano **KVM 2** (2 vCPU, 8GB RAM, 100GB NVMe): a stack é leve (PHP+SQLite,
   sem processamento pesado local — as chamadas de IA/Drive/Assinafy são
   todas HTTP saindo pra fora) e o volume é baixo (ver pendência #3), então
   o KVM 1 já daria conta, mas o KVM 2 dá margem de segurança em cima da
   contenção que o SQLite já tem por natureza (mesmo motivo do
   `PRAGMA busy_timeout` obrigatório) e espaço pra crescer sem trocar de
   plano de novo. Ainda falta: confirmar a compra, escolher a distro
   (sugestão Ubuntu LTS) e montar o stack do zero (nginx/PHP-FPM, extensão
   sqlite3, certbot, crontab real, deploy via git) — sem isso não dá pra
   configurar `webhook_deploy.php`/crontab de verdade.
2. ~~**WhatsApp**~~ — ✅ decidido: **Z-API**, mesmo provedor do JurídicoSaaS.
   Instância própria da Fastcar (não reaproveita a do escritório de
   advocacia) — precisa criar instância nova no painel Z-API e configurar
   `zapi_instance_id`/`zapi_token`/`zapi_client_token` no `config` deste
   projeto quando a instância existir. Webhook vai seguir o mesmo padrão de
   `chatbot-whatsapp/webhook/whatsapp.php` do JurídicoSaaS: dedup de
   `messageId`, checar `fromMe`/grupo antes de processar, salvar mensagem
   sempre (mesmo em pausa de IA)
3. ~~**IA de qualificação**~~ — ✅ decidido: **Gemini** (`gemini-2.5-flash-lite`,
   o mais barato da família 2.5) como provedor principal, com fallback
   automático pro **OpenAI GPT** (`gpt-4o-mini`, também o nível mais barato)
   quando o Gemini falha ou não está configurado. Modelo padrão trocado de
   `gemini-2.5-flash` pra `gemini-2.5-flash-lite` depois que o volume real
   de leads foi confirmado (12/09/2026): média de 16-30 leads/dia, pico de
   ~50/dia — volume baixo o bastante que o custo por chamada praticamente
   não muda de um modelo pro outro, mas o Jean pediu pra já sair configurado
   no nível mais barato por padrão; `gemini-2.5-flash` (mais caro e mais
   capaz) continua disponível como fallback automático interno se o lite
   falhar de verdade (`includes/gemini.php`). Mesmo padrão de fallback duplo
   Gemini→GPT do JurídicoSaaS (`includes/gemini.php`, `includes/openai.php`,
   `includes/ia_qualificacao.php`). Código
   implementado e testado contra servidor fake local (nunca contra as APIs
   reais, ver seção de validação em produção abaixo). O **prompt** de
   qualificação (`IA_QUALIFICACAO_PROMPT_SISTEMA`) e o de extração
   estruturada (`IA_EXTRACAO_PROMPT`) existem mas seguem marcados como
   rascunho — o que perguntar, em que ordem e quando desistir/marcar "sem
   perfil de compra" ainda precisa de revisão do Jean antes de rodar com
   lead de verdade.
4. **Login/perfis do admin** — combinado em 12/09/2026: `super_admin`
   (Jean), `closer` (negocia/aprova valor, bloco 6), `consultor` (atendimento,
   bloco 5, não define valor) — schema e `requireSuperAdmin()` já refletem
   isso, mas fica em aberto até validar com o time em produção.
5. **Anúncio/tráfego (bloco 1)** — combinado em 12/09/2026: anúncio "Clique
   para WhatsApp" do Meta — a WhatsApp Cloud API manda um `referral`
   (headline, source_id) na 1ª mensagem, capturado automaticamente em
   `extrairOrigemAnuncio()`. Sem link/UTM manual. Fica em aberto até validar
   contra uma instância Z-API real e um clique de anúncio de teste (ver
   seção de validação em produção abaixo) — formato exato ainda não confirmado.
6. ~~**Módulo de contrato**~~ — ✅ decidido e implementado (só **COMPRA**):
   modelo real recebido do Jean (`01_Contrato_Mestre_FASTCAR_Compra_Quitacao_Futura.docx`,
   30 cláusulas + Quadro-Resumo), transcrito pra geração via FPDF puro
   (`includes/contratos_pdf.php`, sem LibreOffice/Composer — shared hosting
   não teria isso) e enviado pra assinatura eletrônica via Assinafy
   (`includes/contratos.php`, `includes/assinafy.php`). Contrato de
   **VENDA** (`01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx`, já
   recebido e lido, mas nada implementado) fica pro módulo de vendas —
   segunda etapa, ver seção própria acima.
7. **Leads do Supabase (Leandro Soragi)** — aguardando CSV ou acesso ao
   painel pra importar a base existente; sem isso, script de importação
   fica só desenhado, sem rodar de verdade.

### A validar assim que subir em produção (internet livre + credenciais reais)

Este ambiente de dev bloqueia acesso externo (só libera alguns hosts tipo
GitHub/npm), então o que segue foi construído seguindo documentação e
testado com servidor fake local — nunca contra o serviço real:

- **Formato do payload do webhook Z-API** — `messageId`, `phone`, `fromMe`,
  `isGroup`, `text.message`, `instanceId` — construído pelo padrão do
  JurídicoSaaS, nunca confirmado contra uma instância Z-API de verdade.
- **Campo `referral` do clique em anúncio Meta Ads** — `extrairOrigemAnuncio()`
  aceita tanto `referral` solto quanto `message.referral`, mas o nome/formato
  exato dos campos (`source_id`, `headline`, `ctwa_clid`) só dá pra confirmar
  com um clique de anúncio de teste passando pela Z-API real.
- **API de marcas da FIPE (BrasilAPI)** — `includes/fipe.php` só foi testado
  contra um servidor fake local simulando `/marcas/v1/carros`; validar o
  formato de resposta real assim que rodar com internet livre.
- **Envio real de mensagem (`zapiEnviarTexto`)** — só testado o caminho de
  falha graciosa (sem credencial/rede); nunca um envio de verdade.
- **API Gemini e OpenAI** (`includes/gemini.php`, `includes/openai.php`) —
  chamadas, formato de resposta e o fallback Gemini→GPT só testados contra
  servidor fake local simulando os dois formatos de resposta; nunca uma
  chamada real com chave de API de verdade. Validar também se o modelo
  configurado (`gemini-2.5-flash`/`gpt-4o-mini`) ainda existe/responde bem
  quando a instância for configurada de verdade.
- **API Assinafy** (`includes/assinafy.php`, `includes/contratos.php`) —
  upload de PDF, criação de signatário/assignment, webhook
  (`api/assinafy_webhook.php`) e polling de status (`cron/assinafy_sync.php`)
  só testados contra servidor fake local; nunca uma assinatura real de
  ponta a ponta. Confirmar o formato exato do payload do webhook contra uma
  conta Assinafy de verdade.
- **API Google Drive** (`includes/google_drive.php`) — autenticação via JWT
  RS256 de service account testada com par de chaves RSA real gerado
  localmente (a assinatura em si é genuína), mas a troca por token OAuth e
  as chamadas de criar pasta/subir arquivo só foram validadas contra
  servidor fake local; nunca contra a API do Google de verdade. Precisa de
  `config/google_drive_credentials.json` (nunca commitar) com uma service
  account real da Fastcar antes de validar.
- **PDF do contrato de compra** — conteúdo e estrutura verificados
  decodificando os content streams internos do PDF gerado (sem
  `pdftoppm`/LibreOffice funcionando neste sandbox pra renderizar
  visualmente); vale abrir o PDF de verdade num leitor real assim que
  possível pra conferir layout/quebra de página.
