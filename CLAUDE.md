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
6. Negociação — Jean/Consultor ←─────────────┘
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
| 6. Jean/Consultor | Avalia oportunidade, define proposta, negocia | Valor ofertado, contraproposta, condições, aprovação, motivo de perda (`oportunidades.valor_ofertado` etc) |
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
`zapi_instancias_consultores` (instância Z-API própria de cada consultor,
só pra monitorar produtividade — ver seção de arquitetura Z-API abaixo),
`contratos` (contrato gerado + rastreio de assinatura eletrônica via ZapSign).

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
  instância própria do consultor responsável (`zapi_instancias_consultores`),
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
  como fallback (ver pendência #3). Conversa livre, sem menu/opção numerada,
  tom natural de WhatsApp (varia a forma de escrever, reage ao que a pessoa
  disse) — saudação de abertura já pergunta o nome, avisa em 1 frase curta
  que os dados são usados só pra avaliar a proposta (LGPD), pede foto do
  veículo perto do fim sem insistir, e só marca `qualificacao_completa` depois
  de recapitular o que entendeu e o cliente confirmar se aceita a ligação do
  consultor (`oportunidades.aceita_ligacao_consultor`, NULL até ser
  perguntado — 0/recusou é resposta válida, não "vazio"). Além dos campos
  originais, coleta `urgencia` (texto livre — precisa vender rápido ou pode
  esperar) e `temperatura_lead` (`frio`/`morno`/`quente` — leitura da própria
  IA sobre o engajamento da conversa até ali, reavaliada a cada turno; de
  propósito NÃO conta como "avanço real" pro contador de estagnação, senão
  o contador nunca dispararia). `whatsapp_sessoes.turnos_sem_avanco`
  incrementa a cada turno que não extraiu nenhum dado novo de verdade e
  reseta quando extrai; ao chegar em `IA_LIMITE_TURNOS_SEM_AVANCO` (5)
  turnos seguidos sem avanço, escala automaticamente pro consultor humano
  (`mudarEtapa()` pra `crm_preenchido`, resumo marcado com o motivo) em vez
  de deixar a IA girando à toa com quem só quer bater papo ou desconfiou
  que é bot. `resumo_ia` sai em formato checklist (✅ confirmado / ⚠️ falta
  confirmar) pro consultor entender rápido o que já foi coberto. Áudio e
  imagem recebidos no WhatsApp entram no fluxo normalmente: áudio é
  transcrito e imagem é descrita via Gemini multimodal
  (`geminiCallComMidia()`, `inlineData` base64) e o texto resultante alimenta
  a qualificação como se fosse mensagem digitada (salvo em
  `whatsapp_mensagens` com `tipo='text'` e prefixo 🎤/📷, pra não precisar
  tocar em mais nada que já filtra por `tipo='text'`); vídeo, figurinha,
  documento, localização, contato — ou áudio/imagem que falhou o
  processamento — recebem uma resposta de reconhecimento simples
  (`chatbot-whatsapp/includes/mensagens.php`) em vez de deixar o lead sem
  resposta nenhuma.
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
  eletrônica via **ZapSign** (`includes/zapsign.php`, webhook
  `api/zapsign_webhook.php` + fallback de polling `cron/zapsign_sync.php` —
  substituiu a Assinafy em 13/09/2026, pedido do José/Jean).
  Fluxo confirmado com o Jean: cliente preenche dados pelo link do
  formulário → consultor confere → **consultor preenche os campos
  financeiros/de negociação só na hora de fechar o negócio** (bloco 6, mesma
  pessoa que atendeu desde a mesclagem consultor/closer, ver pendência #4) →
  dispara o contrato. Contrato de **VENDA** (Fastcar revende o carro) fica
  pro módulo de vendas, fora de escopo agora (ver "Segunda etapa" abaixo).
  **Visualização do PDF no próprio sistema** (`admin/ver_contrato.php`):
  desde 13/09/2026, o PDF fica salvo (Drive preferido, `storage/uploads/`
  como fallback — mesmo padrão de `includes/documentos.php`) já na geração,
  antes mesmo de assinado — `contratos.drive_file_id`/`arquivo_url` guardam
  sempre a versão mais atual (a assinada sobrescreve a rascunho quando
  chega via `zapsignSincronizarContrato()`). Isso é **separado** de
  `oportunidade_documentos.contrato_compra` (o que conta pro checklist da
  regra #7) — esse só é gravado quando o status vira `assinado` de
  propósito, senão o checklist de fechamento passaria com um contrato só
  gerado, ainda sem assinatura. Lógica de servir o arquivo (Drive ou local,
  com defesa contra path traversal) compartilhada entre
  `admin/ver_documento.php` e `admin/ver_contrato.php` via
  `includes/documentos.php::servirArquivoDriveOuLocal()`.
  `zapsignSincronizarContrato()` tenta de novo baixar/guardar a cópia
  assinada em toda sincronização enquanto não conseguir (nunca desiste pra
  sempre por causa de 1 falha de download/rede) — bug real achado testando
  a troca de provedor: sem esse retry, um contrato já `assinado` sem cópia
  salva (download falhou 1x) ficava pra sempre sem nenhuma versão
  visualizável, porque o status já bater impedia qualquer tentativa nova.
- **Configurações de super admin** — `admin/configuracoes.php`: Z-API
  principal, IA (Gemini + OpenAI fallback), Google Drive, ZapSign, fila de
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
- **E-mail** — `includes/mail.php`, via API da Brevo (não SMTP puro — mesmo
  motivo do JurídicoSaaS: porta bloqueada em VPS nova + reputação/SPF/DKIM),
  configurável em Configurações → E-mail (Brevo), com teste de envio
- **Backup** — `includes/backup.php` (compartilhado entre `cron/` e
  `admin/backup.php`, nunca duplicado): banco copiado várias vezes ao dia,
  ZIP completo (banco + uploads locais + credencial do Drive) 1x/dia, envio
  automático pra pasta "Backups" dedicada no Drive com rotação — botão
  manual pros 3 em `admin/backup.php` (super_admin), download via proxy com
  defesa de path traversal em `admin/baixar_backup.php`
- **Deploy automático** — `api/webhook_deploy.php` (valida assinatura do
  GitHub, agenda `storage/.deploy`) + `install/setup_crontab.sh` (linha de
  crontab que aplica o `git pull` quando o marcador existe) — mesmo padrão
  decouplado do JurídicoSaaS (nunca roda git disparado direto pela request
  HTTP, só agenda)
- **Setup da VPS** — `install/SETUP_VPS.md`: guia completo (nginx+PHP-FPM,
  Cloudflare com SSL Full-strict + Origin Certificate + Bot Fight Mode,
  firewall restrito a IPs da Cloudflare, crontab, deploy, e-mail, backup) —
  ver pendência #1
- **Gestão de usuários** — `admin/usuarios.php`: lista/cria/edita
  consultor, restrito ao super_admin. Nunca cria nem promove ninguém pra
  `super_admin` por essa tela (só o CLI `install/create_admin.php`,
  decisão de segurança de propósito) e um super_admin não consegue
  bloquear a própria conta por aqui. Sem seletor de perfil na tela — desde
  a mesclagem consultor/closer (pendência #4) só existe um perfil pra criar.
- **Dashboard por perfil** — `admin/index.php` mostra cards diferentes pra
  cada perfil (`includes/dashboard.php`): consultor vê a própria carteira
  de atendimento (ativas/atrasadas/recebidas na semana/status da fila) E o
  pipeline de negociação (em negociação/presencial, fechadas do mês, taxa
  de conversão) juntos — mesma pessoa cuida dos blocos 5 e 6 desde a
  mesclagem; super_admin vê visão geral da empresa + funil em barra por
  etapa. Tabela principal e nav de etapas também filtram por
  `responsavel_id` pra consultor.
- **Saúde do sistema** — `admin/saude.php`, mesmo padrão do JurídicoSaaS
  (checks agrupados ok/warn/error/info, banner de resumo), remapeado pros
  subsistemas reais do Fastcar: banco, servidor, Z-API (status real da
  instância), IA (conectividade + custo de tokens do dia/mês), Google
  Drive (autenticação JWT real), ZapSign, Brevo, backup, crons (frescor
  de log), fila de leads (alerta se ninguém disponível), erros recentes.
  Restrito ao super_admin.

## Segunda etapa (combinado com o Jean/José — não iniciar sem pedido novo)

Itens explicitamente adiados durante a conversa, pra não se perderem:

- **Módulo de vendas** (Fastcar revende o veículo pro próximo comprador) —
  inclui o contrato-modelo de VENDA (`01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx`,
  já lido/estruturado, mas nada implementado)
- **Módulo financeiro** — relatórios financeiros, reaproveitando o módulo
  financeiro do JurídicoSaaS
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
- **Google Drive** (`includes/google_drive.php`, JWT RS256 via service
  account) — portado quase 1:1 do JurídicoSaaS, só adaptando pra
  `getConfig()`/`setConfig()` em vez de SQL cru inline. Assinatura
  eletrônica **diverge** do JurídicoSaaS desde 13/09/2026: era Assinafy
  (mesmo provedor de lá), agora é **ZapSign** (`includes/zapsign.php`) —
  troca de provedor específica da Fastcar, não reaproveitar Assinafy se
  outro projeto for criado a partir daqui
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
| `cron/followup.php` | a cada 30 min | Dois papéis: (1) alerta pro responsável quando `oportunidades.proxima_acao_em` está no passado e a etapa ainda está ativa — dedup de 4h por oportunidade via `config.alerta_atraso_{id}`, só marca como enviado se `zapiEnviarTexto()` retornar sucesso; (2) reengajamento de lead esfriando: oportunidade ainda em `whatsapp`/`qualificacao_ia`, sem responsável assumido, cuja última mensagem `in` foi há 30-120 min sem resposta nossa depois — mesma janela do `followup_leads.php` do JurídicoSaaS, dedup de 24h por telefone via `config.reeng_sent_{telefone}` (não é permanente — um mesmo telefone pode esfriar de novo numa oportunidade futura, ex: 2º veículo meses depois — bug real corrigido); mensagem de reengajamento fica registrada em `whatsapp_mensagens` (`out`, `enviado_por_ia=1`) igual qualquer outra mensagem ao cliente, pro consultor que assumir depois ver a pergunta que gerou a resposta |
| `cron/zapsign_sync.php` | a cada 30 min | Polling de status dos contratos ainda `enviado`/`visualizado` (fallback caso o webhook da ZapSign não chegue) — frequência menor que o antigo `assinafy_sync.php` (que era a cada 1 min): assinatura eletrônica não é tão sensível a atraso de minutos quanto lead esfriando |
| `cron/backup_db.php` | 4x/dia (2h/8h/13h/18h) | Cópia rápida só do `.db`, mantém os últimos 7 dias — recuperação rápida de um "oops" recente |
| `cron/backup.php` | 1x/dia (3h) | ZIP completo (`.db` + `storage/uploads/` + credencial do Drive), mantém os últimos 5 dias — código não entra, já está no git |
| `cron/backup_drive.php` | 1x/dia (4h, depois do `backup.php`) | Sobe o ZIP mais recente pra pasta "Backups" dedicada no Drive, dedup por data, mantém os últimos 5 lá também |
| *(linha de deploy)* | a cada 1 min | Não é um script `cron/*.php` — é uma linha direta no crontab (`install/setup_crontab.sh`) que aplica `git pull` quando `api/webhook_deploy.php` agenda `storage/.deploy` |

> Testado localmente com banco de teste isolado e servidor Z-API fake (pra
> validar o caminho de SUCESSO de envio também, não só o de falha
> graciosa): identificou corretamente 1 oportunidade atrasada + 1 esfriando
> e mandou as duas mensagens; rodando de novo na sequência, nenhum dos dois
> guards reenviou (dedup de 4h e de 24h intactos); simulando os dois guards
> expirados (5h e 25h atrás) numa 3ª rodada, com uma NOVA mensagem do
> cliente pra simular um esfriamento genuíno de novo, os dois voltaram a
> disparar — confirma que corrigiu o bug do guard de reengajamento antigo,
> que era permanente (nunca expirava) em vez de ter TTL.
>
> Ainda falta cadastrar no crontab real quando a hospedagem for definida
> (pendência #1 abaixo) — por enquanto só existe o script, sem agendamento.

## Pendências (aguardando definição antes de codar mais)

1. **Hospedagem/deploy** — **em andamento (12/09/2026):** decidido ir de VPS
   própria em vez do padrão cPanel+webhook do JurídicoSaaS, pra ter acesso
   root de verdade e liberdade de configuração (a VPS anterior estava
   travada em acesso root/sudo). Fechado com a **HostGator** (não Hostinger —
   cotação inicial não tinha datacenter no Brasil nem nos EUA na lista de
   localização; a HostGator tem "Servidores cloud no Brasil" em todos os
   planos): **VPS NVMe 4** (2 vCPU, 4GB RAM, 100GB NVMe), localização **São
   Paulo**, sistema operacional **Ubuntu sem painel de controle** (não
   instalar o cPanel que a HostGator oferece como opcional — o motivo de
   sair da hospedagem anterior era justamente ter root de verdade). Guia
   completo de setup em `install/SETUP_VPS.md` (nginx+PHP-FPM, Cloudflare
   com SSL Full-strict + Origin Certificate + Bot Fight Mode, firewall
   restrito a IPs da Cloudflare, `install/setup_crontab.sh`, deploy via
   webhook do GitHub + `api/webhook_deploy.php`, e-mail via Brevo, backup).
   **Ainda falta:** finalizar a compra e provisionar a VPS de verdade, e o
   **domínio da Fastcar ainda não existe** — os passos que dependem dele
   (Cloudflare/SSL, webhook de deploy com URL pública) ficam marcados com
   ⏳ no guia até lá; o resto (nginx, crontab, e-mail, backup) não depende
   de domínio e já pode ser feito assim que a VPS existir.
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
4. ~~**Login/perfis do admin**~~ — ✅ decidido: `super_admin` (Jean) +
   `consultor` (atende E negocia/fecha, blocos 5-6). Combinado em
   12/09/2026 como `super_admin`/`closer`/`consultor` separados, mas o
   José confirmou em 13/09/2026 que na prática é a mesma pessoa que atende
   e negocia — perfis `consultor` e `closer` **mesclados**: dashboard único
   (`includes/dashboard.php::dashboardConsultor()`, mostra carteira de
   atendimento E pipeline de negociação/resultado do mês), fila de leads
   (`includes/fila_leads.php`) e instâncias Z-API por pessoa
   (`includes/zapi_instancias.php`) já tratavam os dois perfis de forma
   idêntica antes disso (só o dashboard e a tela de usuários distinguiam).
   `install/migrar.php` converte qualquer usuário `closer` existente pra
   `consultor`; `'closer'` continua um valor tecnicamente aceito na CHECK
   de `usuarios.perfil` (recriar a tabela sem ele no SQLite exigiria
   reconstruir a tabela toda, sem ganho real) mas a aplicação nunca mais
   escreve nem oferece esse valor — `admin/usuarios.php` não tem mais
   seletor de perfil, todo usuário novo criado por lá é `consultor`.
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
   não teria isso) e enviado pra assinatura eletrônica via ZapSign
   (`includes/contratos.php`, `includes/zapsign.php` — trocou de Assinafy
   pra ZapSign em 13/09/2026, ver "O que reaproveitar do JurídicoSaaS"
   acima). Contrato de
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
- **URL de download de áudio/imagem no payload Z-API** —
  `extrairUrlMidia()` (`chatbot-whatsapp/includes/mensagens.php`) tenta os
  nomes de campo mais prováveis (`audioUrl`/`imageUrl`, `url`, `mediaUrl`,
  `link`) dentro do bloco `audio`/`image` do payload, mas o nome exato nunca
  foi confirmado contra uma instância real — só testado com servidor fake
  local simulando essas variações.
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
- **API ZapSign** (`includes/zapsign.php`, `includes/contratos.php`) —
  substituiu a Assinafy em 13/09/2026. Construído a partir da documentação
  oficial (docs.zapsign.com.br, consultada via busca — o ambiente de dev
  bloqueia fetch direto do domínio da doc), nunca contra a API real: criação
  de documento+signatário (`POST /docs/`, 1 chamada só — diferente da
  Assinafy, que precisava de 3), consulta de status
  (`GET /docs/{token}/`), download do PDF assinado, webhook
  (`api/zapsign_webhook.php`) e polling de status (`cron/zapsign_sync.php`)
  só testados contra servidor fake local simulando os formatos documentados.
  Confirmar contra uma conta ZapSign de verdade antes do primeiro contrato
  real: formato exato da resposta de `POST /docs/` (campos `token`,
  `signers[].token`/`sign_url`), do campo `signed_file` (URL temporária,
  ~60min) e do payload do webhook (`token`, `event_type`, `status`). Cadastro
  do webhook em si (URL desta app) precisa ser feito manualmente no painel
  ZapSign ou via `POST /user/company/webhook/header/` — não implementado
  automaticamente, mesmo padrão que a Assinafy já tinha (nunca teve
  auto-registro de webhook via código aqui).
- **API Google Drive** (`includes/google_drive.php`) — autenticação via JWT
  RS256 de service account testada com par de chaves RSA real gerado
  localmente (a assinatura em si é genuína), mas a troca por token OAuth e
  as chamadas de criar pasta/subir arquivo só foram validadas contra
  servidor fake local; nunca contra a API do Google de verdade. Precisa de
  `config/google_drive_credentials.json` (nunca commitar) com uma service
  account real da Fastcar antes de validar.
- **API Brevo** (`includes/mail.php`) — só testada contra servidor fake
  local simulando o formato de resposta; nunca um envio real. Validar
  também se o plano grátis da Brevo dá conta do volume (baixo, mas nunca
  testado de verdade) e se o domínio de e-mail precisa de SPF/DKIM
  configurado no DNS pra não cair em spam.
- **Webhook do GitHub** (`api/webhook_deploy.php`) — header
  `X-Hub-Signature-256` e formato do payload (`ref`, `pusher.name`,
  `commits`, `head_commit.message`) testados só com payload sintético
  local, nunca contra um push de verdade do GitHub.
- **PDF do contrato de compra** — conteúdo e estrutura verificados
  decodificando os content streams internos do PDF gerado (sem
  `pdftoppm`/LibreOffice funcionando neste sandbox pra renderizar
  visualmente); vale abrir o PDF de verdade num leitor real assim que
  possível pra conferir layout/quebra de página.
