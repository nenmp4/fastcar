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
`zapi_instancias_consultores` (⚠️ arquitetura de instância própria por
consultor RETIRADA em 15/09/2026 — ver bullet "WhatsApp Box" abaixo; tabela
segue no schema sem uso novo, não removida sem ganho real),
`contratos` (contrato gerado + rastreio de assinatura eletrônica via ZapSign),
`vendas`, `venda_historico` (módulo de vendas, ver bullet próprio).

> ⚠️ Igual ao JurídicoSaaS: `config` é só `chave TEXT PRIMARY KEY, valor TEXT`,
> sem `updated_at`. Cache com TTL usa o padrão `"timestamp|json"` no valor.

---

## Módulos implementados (código pronto e testado contra fakes locais)

- **Webhook WhatsApp + funil** — `chatbot-whatsapp/webhook/whatsapp.php` +
  `chatbot-whatsapp/includes/mensagens.php` (lógica compartilhada com o
  simulador de CLI `chatbot-whatsapp/simulate.php`, útil pra testar o bot
  sem precisar de credencial Z-API real)
  **PHP Fatal Error não tratado numa contenção real de escrita do SQLite
  derrubava o webhook** (18/09/2026, achado real de produção via
  `admin/saude.php` — "Erros recentes" mostrando
  `PDOException: SQLSTATE[HY000]: General error: 5 database is locked` —
  seguido do próprio usuário relatando "voltou whatsapp" / "liberam do
  bloque de ontem", sugerindo o bot ficou fora do ar por um tempo até se
  recuperar sozinho). Investigação: `PRAGMA busy_timeout=5000` +
  `journal_mode=WAL` estão corretos em TODA conexão do projeto (via
  `includes/db.php::getDB()`, forçado pelo guard `sqlite-sem-busy-timeout`
  do smoke — auditado de novo, nenhuma conexão avulsa sem essa proteção),
  e as transações de escrita (`mudarEtapa()`, `mudarEtapaVenda()`, fila de
  leads/vendas, financeiro) nunca fazem chamada de rede *dentro* do
  `beginTransaction()`/`commit()` — não era o mesmo bug de sempre. O erro
  real é contenção de escrita que durou MAIS que os 5s de espera (rajada
  de mensagens concorrentes, por exemplo) — só que a chamada mais
  concorrida do sistema, `processarMensagemZapi()`/
  `processarMensagemVendasZapi()` (`chatbot-whatsapp/webhook/whatsapp.php`,
  dispara a cada mensagem recebida — inclui `registrarMensagem()`, que é a
  própria gravação de dedup em `whatsapp_mensagens`), nunca teve NENHUM
  try/catch ao redor, ao contrário de toda outra escrita crítica do
  projeto — uma exceção aí virava PHP Fatal Error cru, quebrando o
  contrato "sempre responde 200 rápido" documentado no topo do próprio
  arquivo (Z-API reentrega se não receber 200 — um fatal error sem
  resposta válida não cumpre isso). Corrigido envolvendo a chamada num
  try/catch — loga no mesmo `storage/logs/whatsapp_webhook_*.log` de
  sempre e responde HTTP 500 (nunca `"ok":true`) — deixa o Z-API
  reentregar depois, comportamento que o próprio arquivo já documentava
  como esperado pra falha real, só sem o crash cru. ⚠️ Hipótese não
  confirmável sem acesso aos logs/métricas da VPS, mas plausível pro "fora
  do ar por mais tempo" relatado: numa rajada de mensagens concorrentes,
  cada worker do PHP-FPM ficava preso até 5s (o busy_timeout) esperando a
  mesma trava antes de crashar — o suficiente pra esgotar o pool de
  workers numa VPS pequena (2 vCPU/4GB, pendência #1), não só derrubar 1
  mensagem. Testado contra um lock real (não simulado): 2ª conexão
  segurando `BEGIN IMMEDIATE` por 10s enquanto o webhook tentava escrever
  — a request esperou ~5s (busy_timeout) e bateu no
  `SQLSTATE[HY000] General error 5` de verdade; ANTES da correção isso
  derrubava o script (Fatal Error, sem resposta válida pro Z-API); DEPOIS,
  HTTP 500 limpo (`{"ok":false,"erro":"erro_interno"}`), log gravado com a
  mensagem exata, e **nenhuma** entrada nova em `php_errors.log` (o card
  de Saúde não veria mais esse caso como Fatal Error) — caminho normal
  (sem contenção) continua idêntico, mensagem salva certa, resposta 200.
- **Página pública institucional (`index.php`, raiz do domínio)** —
  17/09/2026, "Faz pagina publica fastcar solutions para verificação no
  google" → confirmado com o usuário: é pra dar suporte à verificação do
  **Google Business Profile** (ficha da empresa no Google Maps/Busca, que
  cruza nome/endereço/telefone cadastrados com um site real). Até então o
  domínio só tinha `sistema.fastcar.solutions` (CRM, atrás de login) e o
  wizard de documentos (`public/documentos.php`, público mas com
  `X-Robots-Tag: noindex` + token — nunca pensado pra ser achado/indexado);
  não existia nenhuma página pensada pra aparecer no Google. `index.php`
  novo na raiz do repo (raiz do domínio) — home simples e honesta (o que a
  Fastcar faz, como funciona em 4 passos, contato) reaproveitando a mesma
  identidade visual do wizard (`public/documentos.php` — navy `#151722` +
  azul `#2f6fed`, logo com fallback de texto) e os MESMOS dados já usados
  no rodapé do contrato/wizard (`includes/contratos_pdf.php`) — nome
  "FASTCAR SOLUTIONS", CNPJ `66.934.500/0001-09`, endereço de Barueri/SP —
  nunca inventados. Inclui JSON-LD `schema.org/LocalBusiness` (nome,
  endereço, telefone, CNPJ como `taxID`, logo) — sinal estruturado extra
  que ajuda o Google a entender que é a mesma empresa do Business Profile,
  além do texto visível. Botão principal "💬 Falar no WhatsApp" aponta pro
  `wa.me` do número já em uso como canal oficial de entrada do funil
  (instância Z-API "FastCar | JEAN", `11 9 5834-7764` — o mesmo número já
  ativo no bot, não um contato novo pra manter). De propósito **nunca**
  chama `require db.php`/nenhuma config — é a única página do projeto
  pensada pra sempre ficar no ar mesmo que o banco ou alguma integração
  externa esteja com problema (diferente do resto do CRM, que depende do
  banco pra praticamente tudo). `robots.txt` (bloqueava TUDO desde sempre,
  `Disallow: /` — correto pro CRM inteiro, que nunca deveria ser indexado)
  ganhou 2 exceções mínimas (`Allow: /$` só pra raiz exata, `Allow:
  /public/assets/` pro Google conseguir buscar a logo referenciada na
  página) — o resto do site continua 100% bloqueado de indexação, nunca
  regrediu nisso. Testado: lint PHP limpo, servido localmente confere
  título/meta certos, link do WhatsApp monta a URL `wa.me` certa com texto
  pré-preenchido, e o JSON-LD decodifica válido com `name`/`taxID` batendo.
  **✅ No ar em produção, 17/09/2026** — os 3 passos manuais (DNS do domínio
  apex `fastcar.solutions` apontando pra VPS, `server_name` do nginx
  expandido pra incluir `fastcar.solutions www.fastcar.solutions` no mesmo
  arquivo/app de `sistema.fastcar.solutions`, certbot expandindo o
  certificado com `certbot --nginx --expand -d sistema.fastcar.solutions -d
  fastcar.solutions -d www.fastcar.solutions`) foram feitos direto na VPS
  (fora deste ambiente, sem SSH daqui) — `curl -I https://fastcar.solutions/`
  confirmado 200 pelo usuário. **2 tropeços reais no meio do processo**,
  registrados porque são o tipo de erro fácil de repetir: (1) a instrução
  de editar o `server_name` foi colada direto no terminal em vez de dentro
  do arquivo de config — `server_name` não é comando de shell, virou
  "command not found"; corrigido orientando um `sed -i` direto no arquivo
  em vez de pedir edição manual num editor; (2) o primeiro `certbot`
  (sem `--expand`) fez a pergunta interativa `(E)xpand/(C)ancel:`, mas como
  os comandos seguintes (nginx reload, curl) já tinham sido colados
  junto no mesmo bloco, foram interpretados como resposta ao prompt e o
  certbot cancelou sozinho — corrigido usando `certbot --nginx --expand`
  (evita o prompt) e orientando rodar cada comando separado, um de cada
  vez. Ainda falta cadastrar/reverificar o Business Profile no Painel do
  Google Meu Negócio apontando pra essa URL — passo fora do código, do
  lado do Google.
  **Redesign visual** (mesmo dia, "poderia ficar mais bonita essa pagina")
  — 1ª versão era funcional mas básica (cards simples sem hierarquia).
  Reforçado só com CSS (nenhum JS novo): hero com brilho radial atrás do
  logo, tira de confiança (🔒 Negociação segura / 📄 Contrato formalizado /
  ⚡ Resposta rápida), grade de veículos virou cards com ícone em destaque
  e hover, "Como funciona" virou uma timeline conectada por linha vertical
  em vez de lista numerada simples, cards de contato com badge de ícone, e
  um botão flutuante de WhatsApp sempre visível ao rolar a página (mesmo
  `wa.me` do botão principal). Testado via Playwright com screenshot full
  page em desktop (1280px) e mobile (390px) antes de considerar pronto —
  conferido visualmente que nada quebra/sobrepõe nos dois tamanhos.
  **SEO + preview de link pro WhatsApp/redes sociais** ("coloca seo para
  google meta dados whasApp") — a 1ª versão já tinha OG básico
  (title/description/url/image) e JSON-LD; reforçado com o que faltava
  pro link renderizar bem quando alguém cola a URL numa conversa de
  WhatsApp: `og:image` trocado de `public/assets/logo.png` (retangular,
  fundo transparente — corta estranho no card de preview) pro
  `admin/assets/img/icon-512.png` já gerado por `includes/marca.php`
  (512×512, quadrado, fundo sólido da marca — formato que WhatsApp/
  Facebook esperam), com `og:image:width/height/type` explícitos;
  `twitter:card=summary_large_image` + `twitter:title/description/image`
  (mesmo conteúdo do OG, cobre Twitter/X e qualquer unfurler que prefira
  esse padrão); `og:site_name`; `meta robots=index,follow` explícito (não
  só implícito por ausência de `noindex`); `theme-color` (cor da barra do
  navegador em mobile). Como o ícone novo mora em `/admin/assets/img/`
  (bloqueado pra crawler pelo `robots.txt`, correto pro resto do `/admin/`
  — correto, ver confirmação do usuário logo abaixo),
  `robots.txt` ganhou 1 exceção a mais, bem específica (`Allow:
  /admin/assets/img/icon-512.png`), só esse arquivo — não abre a pasta
  inteira, e o resto de `/admin/` continua 100% bloqueado. Testado:
  servido localmente, todas as tags OG/Twitter/robots conferidas no HTML
  renderizado com os valores certos (URL absoluta do ícone, `512`/`512`/
  `image/png`, `index, follow`).
  **Confirmado com o usuário, mesmo dia ("sistema não poder ser indexado
  blz")**: o CRM inteiro (`sistema.fastcar.solutions`, `/admin/`, `/api/`,
  `/chatbot-whatsapp/`, wizard de documentos) continua 100% fora de
  indexação — nunca mudou, e não é regressão nenhuma dessas mudanças de
  SEO: o `robots.txt` só abriu exceção pra a raiz exata (`Allow: /$`) e 2
  arquivos de imagem estáticos referenciados na home
  (`public/assets/`, o ícone 512px), nunca uma pasta inteira nem qualquer
  rota do CRM; e o CRM em si sempre mandou `X-Robots-Tag: noindex` por
  cima disso (dupla proteção, camada de aplicação + `robots.txt`, mesmo
  padrão de sempre).
  **FAQ** (mesmo dia, "coloca uma faq como funciona e principais dúvidas")
  — 7 perguntas reais de quem tá pensando em vender (assume financiamento?
  precisa estar com parcela em dia? quais documentos? como é definido o
  valor? quanto tempo demora? tem contrato? só compra carro?), respostas
  honestas e sem prometer número/prazo específico (mesmo espírito da regra
  #3 do projeto — nunca inventar/chutar o que só o consultor pode
  confirmar caso a caso). Accordion via `<details>/<summary>` nativos do
  HTML — zero JS novo, mesmo espírito leve do resto da página; `summary`
  perde o marcador padrão do navegador e ganha um `+` que gira 45° (vira
  `×`) quando aberto, só CSS (`.faq-item[open] summary::after`). Ganhou
  também `schema.org/FAQPage` (JSON-LD, novo `<script>` separado do
  `LocalBusiness` já existente) com as mesmas 7 perguntas/respostas
  palavra por palavra — texto duplicado de propósito entre o HTML visível
  e o JSON-LD, comentário no código avisando pra manter os dois em sync se
  alguma resposta mudar. Testado: os 2 blocos JSON-LD decodificam válidos
  (`LocalBusiness` e `FAQPage`, 7 perguntas), e via Playwright confirmado
  que o accordion abre/fecha de verdade ao clicar (2º item aberto,
  screenshot conferido visualmente).
  **Blog SEO com 10 artigos** (mesmo dia, "cria blog seo com alguns
  artigos sobre esse assunto usando pesquisa mais no google" / "uns 10
  artigos bons de puxar cliques pro site") — antes de escrever qualquer
  artigo, rodei uma pesquisa real (WebSearch, fontes: bancos, Serasa,
  Jusbrasil, Conjur, portais de trânsito) sobre os 10 temas, porque é
  conteúdo financeiro/jurídico-adjacente (alienação fiduciária, busca e
  apreensão, negativação SPC/Serasa) — regra #3 do projeto ("nunca
  inventar/chutar") aplicada aqui também, não só a dado de cliente. A
  pesquisa achou pontos de divergência real entre "o que a lei permite
  tecnicamente" e "o que os bancos fazem na prática" (ex: busca e
  apreensão juridicamente cabe com 1 parcela em atraso, mas na prática de
  mercado os bancos só acionam depois de 2-3, ~60-90 dias) — os artigos
  deixam essa distinção explícita em vez de simplificar/arredondar.
  Arquitetura nova, **sem nenhuma dependência de VPS pra funcionar**
  (diferente da página pública em si, que precisou de DNS/nginx/certbot):
  `includes/blog.php` (array `BLOG_ARTIGOS` com metadados — slug/título/
  resumo/tempo de leitura — + `blogAbrirPagina()`/`blogRodape()`
  compartilhadas) e um arquivo PHP por artigo em `blog/{slug}.php`
  (URL limpa, sem query string, sem precisar de rewrite no nginx — cada
  arquivo já É a URL final, mesmo padrão de sempre do projeto de nunca
  adicionar roteamento quando um arquivo por rota já resolve). CSS
  extraído pra `public/assets/blog.css` compartilhado entre os 11
  arquivos (índice + 10 artigos) — único CSS externo do projeto até agora
  (index.php e o wizard público mantêm `<style>` inline de propósito, são
  páginas únicas; o blog tem muitos arquivos repetindo o mesmo layout, aí
  compensa extrair). Cada artigo: meta tags OG/Twitter/canonical
  individuais + JSON-LD `schema.org/Article`, CTA pro WhatsApp (2x: meio
  e fim do texto, mesmo `wa.me` de sempre) e bloco "Continue lendo" com
  links pros outros 4 artigos (nunca fica em beco sem saída de navegação).
  Todo artigo termina com aviso "conteúdo informativo, não é
  aconselhamento jurídico ou financeiro individual" — a própria pesquisa
  sinalizou pontos de tensão jurisprudencial não totalmente pacíficos
  (ex: até que ponto certas regras do CDC convivem com o rito específico
  da ação de busca e apreensão), então o texto evita afirmação categórica
  demais nesses pontos específicos, mantendo o tom em "geralmente"/"na
  prática" onde a própria pesquisa achou divergência. Os 10 temas:
  posso vender carro financiado, atraso e busca e apreensão, transferência
  de financiamento, negativação SPC/Serasa, documentos necessários,
  como consultar saldo devedor, moto/caminhão/caminhonete financiados,
  Tabela FIPE na prática, direitos do consumidor (CDC), e riscos do
  "contrato de gaveta" (esse último é o mais forte pro ângulo de negócio —
  vender sem autorização do banco pode configurar estelionato, achado
  real da pesquisa que reforça institucionalmente por que vender pela
  Fastcar é mais seguro que "vender por fora"). Homepage (`index.php`)
  ganhou seção nova "Aprenda mais" com 3 artigos em destaque + link "Ver
  todos os artigos" pro índice do blog — link interno nos dois sentidos
  (blog linka pra home no cabeçalho, home linka pro blog), bom pra SEO e
  pra não deixar o blog "órfão" sem nenhum caminho de chegada dentro do
  próprio site. `robots.txt` ganhou `Allow: /blog/` (blog É pensado pra
  ser indexado, diferente do resto do site — nenhuma mudança na regra de
  bloquear o CRM). Testado: lint PHP limpo nos 12 arquivos novos
  (`includes/blog.php` + índice + 10 artigos), todas as URLs retornando
  200 via `curl`, todo link interno entre artigos (`/blog/{slug}.php`)
  conferido um por um resolvendo 200 (nenhum link quebrado), os 10 JSON-LD
  `Article` decodificando válidos, e via Playwright screenshot do índice
  do blog, de um artigo completo e da seção nova da home — visual
  consistente com o resto do site (mesma paleta navy/azul, mesmo botão
  flutuante de WhatsApp).
- **1 instância Z-API só + WhatsApp Box** (`includes/whatsapp_inbox.php` +
  `admin/whatsapp_inbox.php`, 15/09/2026, decisão do José/Jean: "decidimos
  manter só uma instância — e os números dos usuários somente para
  notificação de novo lead — adicionar um whatsapp box igual do
  juridicoSaas"). Substitui a arquitetura anterior de 1 instância Z-API por
  consultor (`zapi_instancias_consultores`, retirada — ver nota na tabela
  acima): agora todo mundo atende pela **mesma** instância principal,
  direto de uma caixa de entrada estilo WhatsApp Web dentro do CRM —
  sidebar com as conversas (nome, última mensagem, badge de não lidas,
  indicador de IA pausada, ordenadas pela mais recente) + thread da
  conversa selecionada + caixa de texto pra responder, com polling (sem
  WebSocket, mesma filosofia shared-hosting-friendly do sino de
  notificação). **Quem vê o quê** (pergunta direta de acompanhamento do
  José/Jean, mesmo dia: "inbox vai mostrar todos ou leads do usuário que
  iniciou atendimento?"): super_admin vê a caixa inteira; consultor só vê
  conversa de cliente onde ele é `responsavel_id` em pelo menos 1
  oportunidade (`listarConversasWhatsapp()`) — mesmo padrão "Minhas/Todas"
  já usado em `admin/index.php` pro funil de compra, aplicado aqui também.
  A restrição não é só cosmética na sidebar: `usuarioPodeVerConversaWhatsapp()`
  trava também acesso direto por `?telefone=` na URL e qualquer POST
  (enviar mensagem, pausar/reativar IA) mesmo com CSRF válido — sem essa
  checagem em separado, a sidebar filtrada sozinha não impedia um
  consultor de simplesmente digitar o telefone de outro cliente na URL ou
  forjar o campo `telefone` do formulário. Testado explicitamente: 2
  consultores each vendo só o próprio cliente na lista, tentativa de
  acesso direto e de POST forjado pro cliente do outro bloqueadas nos 2
  casos (banco confirmado sem nenhuma alteração), super_admin vendo os
  dois normalmente. Inspirado no
  `admin/whatsapp-inbox.php` do JurídicoSaaS (repo irmão, lido no momento
  de implementar), mas **enxuto** pro modelo de dados do Fastcar — de
  propósito **sem** os recursos específicos de escritório de advocacia que
  o original tem (templates jurídicos, stickers, encaminhar conversa,
  spin_score, cache de foto de perfil, envio de documento/imagem pela
  caixa): ficam como possível próxima iteração se a equipe sentir falta,
  não implementados agora pra evitar over-engineering sem pedido real.
  Mandar mensagem pela caixa **pausa a IA automaticamente**
  (`pausarIA()`, regra #4) — diferente do `fromMe` que o webhook já
  registrava sem pausar (podia ser eco do próprio bot enviando, não dava
  pra saber); aqui a ação É de um humano assumindo a conversa, pausar é
  sempre certo. `usuarios.whatsapp` deixou de ser canal de atendimento
  (não existe mais instância própria pra configurar em
  `admin/usuarios.php`, seção retirada) — serve só pro número **pessoal**
  do consultor receber notificação de lead novo
  (`notificarConsultorLeadQualificado()`, isso não mudou).
  `whatsapp_mensagens.usuario_id`, que antes marcava "por qual instância
  de consultor a mensagem passou", passou a marcar "quem mandou pela
  caixa" — `admin/produtividade.php` não precisou mudar a query
  (`zapiContarMensagensPorConsultor()`), só a fonte do dado ficou mais
  direta (textos escritos por humano de verdade, não mais decorrência de
  ter ou não uma instância própria configurada).
  **Mensagem assinada com o nome do consultor** (15/09/2026, pedido
  José/Jean: "as mensagens do inbox tem que ser assinado pelo consultor se
  ele entrar na conversa") — `enviarMensagemManualWhatsapp()`
  (`includes/whatsapp_inbox.php`) agora manda pro Z-API o texto prefixado
  com `*{nome do consultor}:*\n` (negrito no padrão do próprio WhatsApp),
  assim o cliente sabe que uma pessoa de verdade assumiu, não só a IA.
  Assinatura só entra no que vai pro WHATSAPP de verdade — o texto salvo/
  exibido na thread do CRM (`registrarMensagem()`) continua sem prefixo,
  porque a bolha já mostra "👤 {nome}" separado (duplicaria a informação
  dentro do próprio CRM). Testado com servidor Z-API fake capturando o
  payload exato: mensagem que chega no cliente vem com
  `"*Jose Consultor:*\nBoa tarde!..."`, o que fica salvo no banco/CRM
  continua limpo (`"Boa tarde!..."`).
  **Sidebar não atualizava sozinha sem conversa aberta** (mesmo dia, achado
  real: "verifica demora de atualizar as mensagens do ibox") — o polling
  que refresca a lista de conversas (não-lidas, ordem, última mensagem)
  só rodava DENTRO do `if (telefone)`, ou seja, só se atualizava sozinho
  com uma conversa já aberta; sentado na caixa sem nada selecionado — o
  estado mais comum esperando lead novo chegar — a lista nunca mudava
  sozinha, só recarregando a página na mão. Movido pra fora do `if`, roda
  sempre. De quebra, intervalos apertados a pedido ("deixa bem fluido
  inbox"): mensagens da conversa aberta 4s→2s, lista lateral 15s→5s.
  Testado com Playwright: inseriu mensagem nova direto no banco (simulando
  o webhook) com a página aberta e NENHUMA conversa selecionada, sem
  recarregar — sidebar saiu de "Nenhuma conversa ainda" pra mostrar a
  conversa nova sozinha, dentro da janela do polling.
  **"Nova conversa" pra número que ainda não escreveu** (mesmo dia, achado
  testando de verdade em produção — José: "não está igual do juridico
  sass campo de enviar mensagem"): a sidebar só listava telefone que já
  tinha pelo menos 1 mensagem salva, sem jeito de iniciar contato
  proativo com um número novo. Campo "+ Iniciar conversa" (GET simples,
  `?telefone=X`) resolve — a tela já sabia lidar com telefone "vazio"
  (0 mensagens, formulário de envio funcionando normal), só faltava a
  entrada. Corrigido de quebra, no mesmo commit: `$telefoneAtivo` passou
  a rodar `normalizarTelefone()` (não só tirar os não-dígitos) — sem
  isso, digitar um número sem o DDI 55 abria a conversa "certa" (as
  mensagens aparecem, `buscarMensagensConversa()`/`buscarMensagensNovasConversa()`
  já normalizavam por dentro) mas a linha ficava **duplicada** na
  sidebar até recarregar a página, porque o telefone da URL não batia
  crú com o telefone normalizado salvo em `whatsapp_mensagens`.
  **Bug real de produção achado no mesmo teste**: primeira mensagem de
  cliente de verdade não chegou no bot — log do webhook
  (`storage/logs/whatsapp_webhook_*.log`) apontou
  "client-token inválido no header, ignorando webhook" — o valor colado
  em Configurações → Z-API não batia byte a byte com o que a Z-API manda
  de verdade (a comparação usa `hash_equals()`, exata, qualquer espaço/
  caractere a mais quebra). Resolvido recopiando o Client-Token com mais
  cuidado direto do menu Segurança da Z-API — não é bug de código, mas
  fica registrado porque é o tipo de coisa que vai acontecer nulo com
  qualquer credencial colada manualmente.
  **Incidente real de produção — flood de mensagens duplicadas** (15/09/2026,
  José: "mandei mensagem bot não respondeu" → depois "tá mandando várias
  mensagens"/"disparando sem parar"): dois bugs distintos, achados em
  sequência.
  (1) O client-token colado certinho ainda assim rejeitava 100% dos
  webhooks reais com "client-token inválido no header" — a suposição
  original (copiada do padrão do JurídicoSaaS, nunca confirmada contra uma
  instância real) era que a Z-API devolveria o Client-Token no header de
  todo webhook recebido, igual ela exige de volta nas chamadas que NÓS
  fazemos pra API dela. Não é isso: Client-Token autentica as NOSSAS
  chamadas pra Z-API, não algo que ela manda de volta quando ELA chama
  nosso webhook — confirmado com `curl` direto na URL do webhook (200 OK,
  infra ok) e comparando contra a instância Z-API já funcionando do
  JurídicoSaaS. Corrigido removendo a checagem inteira de
  `chatbot-whatsapp/webhook/whatsapp.php` — validação de origem do webhook
  continua só por `instanceId` + dedup de `messageId`.
  (2) Depois de preencher os 6 campos de webhook da Z-API (Ao enviar/
  Presença do chat/Ao desconectar/Receber status da mensagem/Ao receber/Ao
  conectar) todos com a mesma URL — tentativa de imitar a configuração do
  JurídicoSaaS — eventos sem mensagem de verdade (presença, status,
  conexão) passaram a cair na mesma rota de processamento de mensagem,
  `tipoMidia()` classificava como `desconhecido` e o código tratava como
  "mídia sem suporte", respondendo automaticamente
  "Recebi por aqui! 😊 Consegue me contar em texto ou áudio?" a CADA evento
  — sem nenhum dedup nesse caminho específico, gerou 129+ respostas
  duplicadas pra 1 número real e várias conversas de "número" falso
  (`164059295019141` etc — na verdade IDs de evento, não telefone).
  Diagnosticado direto no banco (`whatsapp_mensagens` do número afetado:
  12+ `zapi_message_id` genuinamente distintos, todos `tipo=desconhecido`,
  chegando a cada 20-90s — descarta reentrega do mesmo evento, confirma
  "muitos eventos distintos não-mensagem"). Mitigação imediata:
  `pausarIA()` direto por PHP CLI no número afetado (VPS sem `sqlite3`
  instalado). Correção definitiva, em 2 frentes: guard em
  `chatbot-whatsapp/includes/mensagens.php::processarMensagemZapi()` que
  ignora silenciosamente (`ignored=not_a_message`, nem grava mensagem)
  qualquer payload onde `tipoMidia()==='desconhecido'` logo no início da
  função — sem isso, evento de presença/status nunca deveria ter chegado
  nem a esse ponto, mas se chegar de novo (config mudar sem querer) fica
  protegido pelo código, não só pela configuração; e limpar os 5 campos
  de webhook extras na Z-API, deixando só "Ao receber" preenchido — só
  esse evento carrega mensagem de verdade. Cuidado que rendeu um susto à
  parte: no meio da correção, o usuário chegou a abrir a tela de
  configuração de webhook da instância ERRADA (a do JurídicoSaaS, outro
  cliente) antes de ser redirecionado pra instância certa da Fastcar —
  nenhuma alteração chegou a ser salva lá, mas reforça checar sempre qual
  instância está aberta antes de mexer em config de webhook.
  **Excluir conversa** (mesmo dia, consequência direta do incidente acima —
  "Coloca uma função de excluir conversa", pra limpar a bagunça de
  conversas de teste/lixo criadas pelo flood): botão "🗑️ Excluir conversa"
  no cabeçalho da thread, restrito a `super_admin` (mesma trava de
  `usuarioPodeVerConversaWhatsapp`), com confirmação em JS
  (`confirm()`) antes de submeter — ação sem volta, sem soft-delete.
  `excluirConversaWhatsapp()` (`includes/whatsapp_inbox.php`) apaga só
  `whatsapp_mensagens` e `whatsapp_sessoes` daquele telefone — nunca mexe
  em `clientes`/`oportunidades`: apagar a CONVERSA (thread de mensagens)
  não é o mesmo que apagar o lead/negócio, se a conversa era de um cliente
  real o cadastro e o histórico do funil continuam intactos, só a thread
  some. Testado em banco isolado: mensagens e sessão zeradas, cadastro do
  cliente preservado.
  **`install/limpar_leads_invalidos.php` (novo, 16/09/2026)** — "Excluir
  conversa" apaga só a THREAD (`whatsapp_mensagens`/`whatsapp_sessoes`),
  de propósito nunca mexe em `clientes`/`oportunidades`; mas o flood
  também criou `clientes`/`oportunidades` **falsas** de verdade (telefone
  = ID de evento da Z-API, nunca um número real) que nunca foram limpas —
  achado real quando o teto de leads por consultor (ver bullet "Fila de
  leads" abaixo) expôs uma consultora com 42 leads acumulados, a maioria
  sobra desse incidente antigo. Confirmado com o usuário ("isso
  oportunidade falsas temos limpar") antes de apagar qualquer coisa. CLI
  (`php install/limpar_leads_invalidos.php`, dry-run por padrão — precisa
  de `--confirmar` explícito pra apagar de verdade): identifica pelo
  formato do telefone (`normalizarTelefone()` sempre produz 12 ou 13
  dígitos pra número real — qualquer coisa fora disso, tipo
  `164059295019141` com 15 dígitos, é garantidamente o ID do evento, nunca
  um contato de WhatsApp de verdade), e **nunca** apaga automaticamente
  cliente/oportunidade que já chegou em `atendimento` ou além (contato
  humano de verdade) mesmo que o telefone bata no critério — fica de fora,
  listado à parte pra revisão manual, rede de segurança extra pro caso
  (não deveria acontecer) de um telefone inválido ter avançado no funil.
  Apaga em cascata (histórico, documentos, pendências pós-venda,
  contratos, vendas, oportunidades, mensagens, sessão, cliente) dentro de
  transação por cliente, log em
  `storage/logs/limpeza_leads_AAAA-MM-DD_HHMMSS.log`. Testado em banco
  isolado reproduzindo o incidente (5 clientes reais + 1 já em
  `atendimento` + 37 falsos com telefone de 15 dígitos + 1 caso extremo
  falso-mas-em-`negociacao` simulando a rede de segurança): dry-run lista
  certo os 37 falsos e separa o extremo pra revisão manual;
  `--confirmar` apaga exatamente os 37, preserva os 5 reais + o já-
  atendido + o extremo intactos (conferido linha a linha depois), log
  gravado com cada cliente apagado.
  **Mensagem duplicada ao enviar** (15/09/2026, José: "mando olá ele mostra
  que mandou duas vezes"): condição de corrida entre o polling de 4s e a
  resposta do próprio envio. O JS desenhava a bolha otimista (texto que
  acabou de mandar) assim que o `fetch` de envio respondia, e SEPARADAMENTE
  o polling periódico desenhava toda mensagem nova que aparecesse — se o
  polling já estava em trânsito com um `after_id` desatualizado (capturado
  ANTES do envio terminar) e a resposta dele chegasse DEPOIS da mensagem já
  ter sido salva no banco, ele desenhava a mesma mensagem, e a resposta
  otimista do envio desenhava de novo em cima — 2 bolhas pra 1 envio só,
  ambas com "👤 José", porque as duas vinham da mesma ação. Corrigido
  marcando cada bolha desenhada com `data-id` (o id real da linha em
  `whatsapp_mensagens`) e checando (`jaRenderizada()`) se já existe uma
  bolha com aquele id antes de desenhar de novo — tanto no polling quanto
  na resposta otimista do envio, dos dois lados da corrida. Testado com
  Playwright simulando a corrida de propósito (interceptando a requisição
  do polling pra atrasá-la até depois da resposta do envio chegar): antes
  da correção geraria 2 bolhas, depois da correção sempre exatamente 1.
  **Conversa não mostrava tudo** (mesmo dia, achado direto: "inbox não
  está mostrando conversa inteira") — `buscarMensagensConversa()` sempre
  carregava só as últimas 50 mensagens ao abrir a conversa, sem nenhum
  jeito de ver o que veio antes numa conversa mais longa (silenciosamente,
  sem nem avisar que tinha mais coisa) — mesma classe do bug do `LIMIT 100`
  sem paginação já corrigido em `admin/clientes.php`. Botão
  "⬆️ Carregar mensagens anteriores" no topo da thread busca a página
  anterior via `buscarMensagensAntesId()` (`includes/whatsapp_inbox.php`,
  `WHERE id < ? ORDER BY id DESC LIMIT 50`) e insere no topo preservando a
  posição de rolagem (`scrollTop += crescimento do scrollHeight`, senão a
  tela "pula" toda vez que clica); vira "Início da conversa" (desabilitado)
  quando a resposta vem vazia — não tem mais nada antes daquilo. Testado
  com Playwright ponta a ponta numa conversa de 70 mensagens: carga inicial
  mostra as últimas 50 (msg 21-70), botão carrega as 20 restantes (msg
  1-20) sem sobreposição/buraco (união das duas páginas = 70 únicas),
  posição de rolagem preservada exatamente (Δscroll = Δaltura do
  conteúdo), e 2º clique corretamente mostra "Início da conversa" sem
  tentar buscar de novo.
  **Mídia recebida não dava pra visualizar** (mesmo dia, achado direto:
  "mídia não estou visualizado") — antes disso, áudio/imagem/vídeo do
  cliente só viravam a DESCRIÇÃO em texto do Gemini
  (`chatbot-whatsapp/includes/mensagens.php`); os bytes originais eram
  baixados só de passagem pra alimentar o Gemini e descartados em seguida
  — o consultor lia "🎥 Moto Honda CG 160..." mas nunca via a foto/vídeo
  de verdade. `baixarMidiaZapi()` (novo) separa o download de
  `descreverMidiaComGemini()` (renomeada de `processarMidiaComGemini()`)
  pra reaproveitar os MESMOS bytes nos dois: descrever via Gemini E salvar
  de verdade via `salvarMidiaWhatsappRecebida()`, que reaproveita
  `includes/documentos.php::salvarArquivoGeradoComoDocumento()` sem
  nenhuma mudança nela (Drive preferido, `storage/uploads/whatsapp/`
  fallback — mesmo padrão já usado pro wizard de documentos e pros
  contratos). Coluna nova `whatsapp_mensagens.drive_file_id` (par de
  `arquivo_url`, que já existia sem uso nesta tabela) grava a referência
  assim que a oportunidade é criada/reaberta (precisa do `cliente_id` pra
  saber em qual pasta do Drive salvar — por isso o salvamento roda DEPOIS
  de `criarOuAbrirOportunidade()`, não junto do download). Salva
  **independente** de o Gemini conseguir descrever ou não (sem chave
  configurada, por exemplo) — as duas coisas (descrição em texto, cópia
  visível) são caminhos paralelos, uma não bloqueia a outra.
  `admin/ver_midia_whatsapp.php` (novo, mesmo padrão de
  `admin/ver_documento.php` via `servirArquivoDriveOuLocal()`) serve o
  arquivo só pra quem pode ver aquela CONVERSA
  (`usuarioPodeVerConversaWhatsapp()` — sem essa checagem em separado, um
  consultor podia só trocar o `?id=` na URL e ver mídia de cliente de
  outro consultor). Thread do WhatsApp Box (PHP no carregamento inicial E
  JS no polling/paginação) renderiza `<img>`/`<audio controls>`/
  `<video controls>` apontando pra essa rota — nunca a URL do Drive/local
  direto. `tipo` sozinho não bastava pra saber que tipo de player mostrar:
  mídia descrita com sucesso vira `tipo='text'` de propósito (entra no
  histórico da IA como mensagem normal), só o prefixo emoji (🎤/📷/🎥)
  denuncia a mídia nesse caso — `tipoMidiaMensagemWhatsapp()`
  (`includes/whatsapp_inbox.php`, espelhada em JS) checa `tipo` OU o
  prefixo, cobrindo os dois caminhos (descrita e não descrita). Testado
  ponta a ponta em banco isolado: imagem processada com sucesso salva
  local (Drive não configurado no teste) e some corretamente detectada
  pelo helper; mesma imagem sem chave Gemini configurada ainda salva a
  mídia (só sem descrição, `tipo='image'` puro); servido via HTTP real
  com 3 usuários (super_admin + 2 consultores) — consultor responsável
  pela oportunidade recebe 200 com os bytes certos, consultor SEM
  responsabilidade recebe 403 com mensagem clara, super_admin recebe 200
  sempre — confirma que a trava por conversa funciona igual já funcionava
  pra pausar IA/enviar mensagem.
  **Prompt de descrição ajustado pra identificar tipo de veículo
  explicitamente** (mesmo dia, "ela identificar moto também") — antes
  dizia só "o veículo" (genérico o bastante pra cobrir moto em teoria, mas
  sem reforçar isso); agora pede pra identificar carro/moto/caminhonete/
  van/caminhão etc explicitamente antes de descrever, e reforça "se for
  moto, identifique como moto explicitamente (não trate como carro)" — a
  Fastcar compra os dois. Testado com foto simulada: Gemini respondeu
  "Moto Honda CG 160 Titan preta, em bom estado" corretamente.
  **403 pra ver mídia, mesmo a conversa aparecendo na caixa** (16/09/2026,
  "no ibox não consigo visualizar as fotos") — investigado passo a passo
  antes de mexer em código: confirmado que a mídia estava sendo salva
  direitinho (arquivo_url apontando certo pro fallback local, já que
  `drive_file_id` fica sempre vazio pra mídia de WhatsApp — nunca chegou a
  configurar Drive pra esse fluxo especificamente), que o arquivo existe
  de verdade no disco com dono/permissão certos (`www-data`, 644, mesmo
  usuário que o PHP-FPM/nginx rodam), e que o endpoint responde 302 (não
  403) sem sessão nenhuma — descarta Cloudflare/firewall bloqueando esse
  padrão de URL. Só com o **texto exato** do erro em mãos ("Essa conversa
  não é de um cliente sob sua responsabilidade") ficou claro: causa raiz é
  que `admin/ver_midia_whatsapp.php` checava
  `$_SESSION['admin_perfil'] === 'super_admin'` direto, em vez de
  `perfilVeTudo()` (super_admin OU supervisor) como o resto de
  `admin/whatsapp_inbox.php` já usa desde 15/09/2026 — a listagem de
  conversas já liberava certo pro supervisor (por isso a conversa
  aparecia normal na caixa), mas o endpoint que SERVE a mídia em si tinha
  ficado pra trás com a checagem antiga, batendo 403 só nesse ponto
  específico. Corrigido trocando pra `perfilVeTudo()`, mesmo padrão do
  resto do arquivo.
  **Nome e foto de perfil do WhatsApp** (16/09/2026, "puxa foto do zap e
  nome") — recurso que a 1ª versão do WhatsApp Box tinha deixado de
  propósito de fora (ver nota no topo desta seção: "cache de foto de
  perfil... fica como possível próxima iteração se a equipe sentir
  falta"), agora pedido de verdade. `zapiBuscarContato()`
  (`includes/whatsapp_config.php`, novo) — `GET /instances/{id}/token/
  {token}/contacts/{phone}`, endpoint Z-API **nunca confirmado contra
  instância real** (mesma ressalva de todo endpoint Z-API que não seja
  envio de mensagem — ver "a validar em produção"): tenta os nomes de
  campo mais prováveis pro nome (`name`/`short`/`vname`/`notify`) e pra
  foto (`imgUrl`/`profileImage`/`photo`/`profilePicture`), loga o corpo
  cru em `storage/logs/whatsapp_contato_debug.log` se nenhum bater (mesmo
  padrão de `logDiagnosticoMidiaZapi()`) em vez de ficar adivinhando às
  cegas depois. `clientes.foto_perfil_url` (coluna nova) — `NULL` =
  "nunca tentou buscar", `''` = "já tentou, não achou nada" (nunca tenta
  de novo a cada mensagem nova do mesmo cliente), URL real quando achou.
  `atualizarNomeFotoWhatsapp()` chamada de dentro de
  `criarOuAbrirOportunidade()` (`includes/oportunidades.php`): cliente
  NOVO sempre tenta buscar; cliente já existente só tenta de novo se
  `foto_perfil_url` ainda for `NULL`. Nome é fill-if-empty (nunca
  sobrescreve o que já tinha, mesma regra do resto do projeto), foto
  sempre atualiza quando achada. Best-effort, nunca lança — nunca pode
  travar/atrasar o webhook. Avatar circular (com fallback de iniciais se
  a imagem falhar ao carregar — é a URL da CDN do WhatsApp da pessoa, não
  um arquivo nosso, pode expirar/mudar) na sidebar do WhatsApp Box (PHP
  no carregamento inicial + JS espelhado no polling) e no cabeçalho da
  conversa aberta. Botão "🔄 Atualizar nome/foto do WhatsApp" em
  `admin/cliente_detalhe.php` cobre backfill manual de clientes
  cadastrados antes dessa função existir (a busca automática só roda na
  criação/1ª tentativa). Testado em banco isolado contra servidor Z-API
  fake local: busca com sucesso retorna nome+foto certos; busca sem
  nenhum campo reconhecido retorna `null` e grava o diagnóstico; cliente
  novo criado já sai com nome+foto preenchidos; cliente existente com
  nome digitado à mão preserva o nome mas ainda assim busca a foto
  (estava `NULL`); cliente com `foto_perfil_url=''` (já tentou antes)
  corretamente NÃO tenta de novo numa mensagem nova (confirmado que o
  valor não muda mesmo o fake server sempre retornando sucesso pra esse
  telefone — provaria um bug no guard se mudasse).
  **Endpoint corrigido pro formato REAL, copiado do JurídicoSaaS** (mesmo
  dia, "vai no inbox do iab tem jeito certo lá") — a 1ª versão de
  `zapiBuscarContato()` usava só `GET /contacts/{phone}` com nomes de
  campo adivinhados, nunca confirmados. Lido direto do código de produção
  do repo irmão (`nenmp4/iabadvocaciaboutique`, `api/clientes.php` ação
  `foto_wpp` — já validado com Z-API real há tempo lá): formato certo é
  **2 chamadas em paralelo** (`curl_multi`) — `GET /profile-picture?phone={phone}`
  pra foto (resposta array `[{"link":...}]` ou objeto `{"link":...}`,
  fallback `value`/`url`) e `GET /contacts/{phone}` pro nome
  (`{"notify":"Nome","short":"N","imgUrl":"..."}` — `notify` é o campo
  certo do nome de exibição; `imgUrl` serve de FALLBACK pra foto só
  quando `/profile-picture` não trouxe nada). Testado contra servidor
  fake local modelado exatamente nesse formato: sucesso retorna a foto de
  `/profile-picture` (não o fallback, quando as duas existem); telefone
  sem foto no 1º endpoint cai certo pro fallback `imgUrl` do 2º; nenhum
  campo reconhecido nos dois retorna `null` e grava diagnóstico.
  **Foto clicável** (mesmo dia, "pode deixar foto clicavel iggual ai") —
  lightbox copiado do mesmo padrão do JurídicoSaaS: clique em qualquer
  avatar com foto (sidebar ou cabeçalho da conversa) abre ela ampliada
  com o nome, fecha clicando fora ou com Esc. Sidebar usa
  `preventDefault`+`stopPropagation` no clique da foto especificamente,
  pra não disparar a navegação do link `<a>` que abre a conversa — só a
  foto abre o lightbox, o resto do card continua navegando normal.
  **Nome errado ("online"/"disponível") e foto sempre quebrada** (16/09/2026,
  achados reais: screenshot mostrando 2 clientes de verdade com "nome"
  igual a "online" e "disponível" na caixa — texto de status/presença do
  WhatsApp, não nome de pessoa — e "as fotos vem bugada"/"ícone quebrado/
  não carrega"). Dois bugs distintos, achados em sequência.
  (1) **Nome**: `senderName`/`chatName` do payload do webhook e os campos
  de `zapiBuscarContato()` (`notify`/`pushName`/`name`/`short`) eram usados
  direto sem checagem — pra pelo menos 2 contatos reais, esse campo trouxe
  texto de status ("online", "Disponível" — literalmente o About padrão em
  português do WhatsApp), não o nome de exibição de verdade. Nova
  `nomeWhatsappPareceValido()` (`includes/whatsapp_config.php`, blocklist
  pragmática — comparação EXATA, nunca por substring, pra nunca recusar um
  nome real que só CONTENHA uma dessas palavras) filtra isso em 3 pontos:
  na captura do webhook (`chatbot-whatsapp/includes/mensagens.php`, trata
  como "sem nome vindo" em vez de salvar o lixo), na escolha de campo
  dentro de `zapiBuscarContato()` (pula pro próximo campo da lista em vez
  de aceitar o 1º não-vazio) e no `foreach` de nomes candidatos. Nome já
  salvo como lixo (bug antigo, 2 clientes reais em produção) se
  autocorrige sozinho na próxima chamada de `atualizarNomeFotoWhatsapp()`
  — automática (mensagem nova desse cliente) ou manual (botão "🔄 Atualizar
  nome/foto do WhatsApp" em `admin/cliente_detalhe.php`) — porque as duas
  passaram a tratar nome-que-é-lixo-de-status como "vazio" pra fim de
  fill-if-empty, não só nome genuinamente vazio.
  (2) **Foto sempre quebrada**: causa raiz bem diferente do que parecia —
  `clientes.foto_perfil_url` guardava a URL da CDN do WhatsApp/Z-API
  PERMANENTEMENTE (buscada 1x, reaproveitada pra sempre no `<img src>`),
  mas essa URL é temporária/assinada e expira — por isso quebrava depois
  de um tempo, não na hora que foi salva. Corrigido seguindo o pedido
  direto do usuário ("só olhar padrão iab que está funcionando"): lido o
  código real do WhatsApp Box do JurídicoSaaS (`admin/whatsapp-inbox.php`,
  função `_aplicarFoto()`/`carregarFotos()`) — lá a foto NUNCA é cacheada
  em banco pro lado do lead/cliente, é buscada AO VIVO via AJAX a cada
  carregamento de tela, em lotes (5 por vez, delay progressivo) pra não
  floodar a Z-API, e só troca o placeholder pelo `<img>` DEPOIS de
  confirmar que carregou (`onload`), nunca antes — nunca mostra ícone de
  imagem quebrada, só continua no círculo de iniciais até a foto de
  verdade estar pronta. Portado o mesmo padrão: nova rota
  `?ajax=foto&telefone=X` em `admin/whatsapp_inbox.php` chama
  `zapiBuscarContato()` fresco a cada request (mesma checagem de permissão
  `usuarioPodeVerConversaWhatsapp()` das outras rotas AJAX do arquivo —
  sem isso um POST/GET forjado podia sondar foto de telefone fora da
  responsabilidade do consultor); todo avatar (sidebar inicial, sidebar via
  polling, cabeçalho da conversa) renderiza só o placeholder de iniciais
  primeiro (`data-av-phone` no lugar de `<img src>` direto), e
  `carregarFotos()` no JS busca e troca em segundo plano — cap de 30
  avatares na sidebar (pode ter até 100 conversas) pro mesmo cuidado do
  JurídicoSaaS de não floodar 1 chamada de Z-API por avatar visível.
  **Bug real introduzido e pego no próprio teste**: 1ª versão do
  `_aplicarFoto()` setava `img.loading = 'lazy'` num `<img>` criado via JS
  mas ainda FORA do DOM (só entra na árvore dentro do próprio `onload`) —
  lazy-loading nativo nunca dispara carregamento pra um elemento que não
  está na árvore (não tem como o navegador saber que está "perto da
  viewport"), travando a foto pra sempre em círculo vicioso; a referência
  do JurídicoSaaS não usa esse atributo (o lazy/batching de verdade já é
  manual, via `setTimeout`) — removido daqui também. **2º bug real achado
  no mesmo teste, sem relação com a mudança de foto**: o bloco
  `<div id="foto-lightbox">` (usado pelo recurso de foto clicável acima)
  estava posicionado no HTML DEPOIS do `<script>` que já tentava
  `document.getElementById('foto-lightbox').addEventListener(...)` — como
  o script roda assim que o parser chega nele, o elemento ainda não
  existia na hora, lançando `TypeError` e derrubando silenciosamente o
  resto do IIFE que vinha depois (o atalho de tecla Esc pra fechar o
  lightbox nunca chegava a ser registrado). Corrigido movendo a div pra
  ANTES do `<script>`. Testado ponta a ponta com Playwright contra banco +
  servidor Z-API fake isolados (imagem real servida localmente, não a CDN
  de verdade): nome corrigido sozinho de "online" pra "João Pereira" após
  simular uma mensagem nova; foto troca do placeholder pro `<img>` real
  sem nunca passar por ícone quebrado (`naturalWidth` sempre > 0 quando
  vira `<img>`); cliente sem foto configurada continua mostrando só o
  círculo de iniciais, nunca um `<img>` quebrado; clique na foto carregada
  abre o lightbox e Esc fecha, sem nenhum erro de JS no console — os 2
  bugs (loading=lazy e ordem do lightbox) só apareceram DEPOIS de testar
  com Playwright de verdade, não no lint/smoke.
  **Foto pisca (aparece e some) em produção** (mesmo dia, achado real logo
  depois do deploy da correção acima — "as fotos aparece e some carrega
  desaparece"): 3º bug real, esse sim só visível com o polling de verdade
  rodando por mais de alguns segundos (o teste Playwright anterior não
  tinha esperado um ciclo inteiro de 5s pra flagar). Causa: `carregarFotos()`
  buscava a foto ao vivo certinho (foto correção acima), mas
  `atualizarListaConversas()` (polling de 5s que já existia, refresca a
  sidebar) reconstrói a lista **inteira do zero** a cada rodada — sempre
  volta pro placeholder de iniciais, mesmo pro telefone cuja foto já tinha
  carregado segundos antes — e aí `carregarFotos()` rodava de novo, buscava
  de novo, trocava de novo: o ciclo completo (placeholder→foto→placeholder→
  foto...) se repetia a cada 5s pra sempre, visível como a foto "piscando".
  Corrigido com `fotoCache` (novo, `{}` em memória só desta página aberta,
  nunca persistido — mesmo espírito do cache HTTP do navegador, não do
  banco) que guarda `telefone→URL` assim que uma foto carrega com sucesso;
  `atualizarListaConversas()` passou a checar esse cache ANTES de decidir o
  que renderizar — telefone já em cache renderiza o `<img>` **direto**, sem
  passar pelo placeholder de novo (a URL já está no cache HTTP do navegador
  também, carrega na hora); `carregarFotos()` só processa placeholder ainda
  sem foto (`div.wpp-avatar-placeholder[data-av-phone]`, não mais qualquer
  `[data-av-phone]`), pra não reprocessar um `<img>` que já está certo.
  Testado com Playwright observando 12s de polling (2+ ciclos completos)
  via `MutationObserver` na sidebar: 0 reaparições do placeholder depois da
  1ª carga da foto, avatar continua `<img>` do início ao fim, sem erro de
  JS — antes da correção esse mesmo teste flagaria o placeholder voltando
  a cada rodada do polling.
  **Envio de áudio** (17/09/2026, "permita enviar audio no inbox para o
  cliente") — botão 🎤 novo ao lado do campo de texto abre o seletor de
  arquivo (`accept="audio/*"`), lê como base64 no navegador e manda via
  AJAX, mesmo padrão do envio de texto. Nova
  `enviarAudioManualWhatsapp()` (`includes/whatsapp_inbox.php`) +
  `zapiEnviarAudio()` (`includes/whatsapp_config.php`, `POST /send-audio`
  — formato confirmado via busca na documentação oficial Z-API,
  docs.z-api.io/message/send-message-audio, campo `audio` aceita URL
  pública OU data URI base64: manda como data URI, sem precisar hospedar
  arquivo público antes — mesma ressalva de "a validar em produção" de
  todo endpoint Z-API que não seja envio de texto puro). Envia PRIMEIRO
  pro Z-API, só grava no histórico + salva a cópia reproduzível
  (reaproveitando `salvarMidiaWhatsappRecebida()` — mesma função do lado
  de RECEBER mídia, ela só grava bytes numa linha já existente, não
  importa a direção) se o envio deu certo de verdade — mesma ordem/
  disciplina do envio de texto. Tamanho travado em `WHATSAPP_MIDIA_MAX_BYTES`
  (20MB), mesmo limite já usado pro download de mídia recebida. PAUSA A
  IA automaticamente (regra #4), igual ao envio de texto. Nunca desenha
  bolha otimista pro áudio (diferente do texto) — deixa o polling de 2s
  já existente trazer a mensagem nova como qualquer outra, evitando
  duplicar a lógica de player só pra 1 renderização e reproduzir o mesmo
  bug de duplicação já corrigido uma vez no envio de texto. Áudio não tem
  legenda no WhatsApp (diferente de imagem), então não dá pra "assinar"
  com o nome do consultor dentro do próprio áudio que o cliente recebe —
  a bolha no CRM continua mostrando "👤 {nome}" normalmente. Testado em
  banco isolado, ponta a ponta via Playwright + servidor Z-API fake:
  botão aparece no formulário; selecionar um arquivo de áudio dispara o
  envio e a bolha com player `<audio>` aparece na thread depois do
  polling, apontando pra `/admin/ver_midia_whatsapp.php`; mensagem
  gravada com `tipo='audio'`/`direcao='out'`/`usuario_id` certo;
  `drive_file_id` ou `arquivo_url` preenchido (cópia salva); IA pausada
  depois do envio; payload capturado pelo Z-API fake confere `phone`
  normalizado + `audio` como data URI base64 no formato certo.
  **Trocado pra gravação direto do microfone** (17/09/2026, achado real:
  "Mandar audio está com bug ainda está abrindo pasta no pc") — a 1ª
  versão do botão 🎤 abria o seletor de arquivo do navegador
  (`<input type=file accept="audio/*">`), pra ESCOLHER um áudio já salvo no
  PC; o consultor esperava gravar a voz na hora, tipo WhatsApp de verdade —
  confirmado com o usuário (2 perguntas diretas) antes de trocar. Reescrito
  com `MediaRecorder` (API nativa do navegador, sem lib externa): clique no
  🎤 pede permissão do microfone e começa a gravar (botão vira ⏹️, aparece
  um botão ✕ "cancelar" e um timer "🔴 Gravando... 0:0X"), clique de novo
  no mesmo botão para e envia — padrão clique/clique escolhido de propósito
  em vez de segurar/soltar (mais robusto num mouse: soltar sem querer fora
  do botão perderia a gravação). Auto-para em 10min
  (`AUDIO_MAX_SEGUNDOS`) como rede de segurança, nunca grava pra sempre se
  esquecerem a aba aberta. Formato de gravação: tenta
  `audio/ogg;codecs=opus` primeiro (o que o WhatsApp usa de verdade pra
  nota de voz), cai pra `audio/webm;codecs=opus`/`audio/webm`/`audio/mp4`
  conforme o que o navegador suportar (`MediaRecorder.isTypeSupported()`)
  — **novo item pra validar em produção**: qual formato cada navegador
  real escolhe e se o Z-API/WhatsApp renderiza a bolha de voz igual em
  todos, nunca testado contra API real (só contra fake local). Sem
  `MediaRecorder`/`getUserMedia` no navegador ou permissão de microfone
  negada, mostra alerta explicando em vez de travar silencioso. Reaproveita
  o MESMO endpoint AJAX (`enviar_audio`) e a mesma
  `enviarAudioManualWhatsapp()` de antes — só troca a ORIGEM do base64 (do
  `FileReader` num arquivo escolhido pro `FileReader` num `Blob` gravado),
  nenhuma mudança no backend. **Bug real achado no próprio teste
  Playwright, antes de qualquer commit**: a 1ª versão do botão "cancelar"
  só esvaziava o array de pedaços de áudio (`pedacos = []`) antes de
  chamar `gravador.stop()`, mas `MediaRecorder.stop()` dispara um último
  evento `dataavailable` (com o pedaço final pendente) ANTES do evento
  `stop` — esse pedaço final entrava de novo no array bem a tempo do
  listener de `stop` ver `pedacos.length > 0` e mandar mesmo assim,
  cancelamento não cancelava nada de verdade. Corrigido com uma flag
  `cancelando` explícita, checada no listener de `stop` (`if (cancelando
  || pedacos.length === 0)`), sem depender de esvaziar o array. Testado
  ponta a ponta com Playwright usando um microfone FAKE do Chromium
  (`--use-fake-device-for-media-stream --use-fake-ui-for-media-stream`,
  concede a permissão automaticamente sem diálogo real) contra banco +
  servidor Z-API fake isolados: clique inicia a gravação (botão vira ⏹️,
  timer aparece), clique de novo para e envia — payload capturado pelo
  Z-API fake confere `audio` como data URI `audio/webm;codecs=opus`
  (formato que o Chromium headless escolheu) com ~29KB de áudio real de
  ~2s, mensagem gravada no banco com `tipo=audio`/`direcao=out`; botão
  "cancelar" durante a gravação NÃO dispara nenhuma chamada pro Z-API
  (conferido comparando o log do fake server antes/depois — vazio nos
  dois), volta pro estado inicial (🎤, sem timer) — esse teste especificamente
  foi o que pegou o bug do `cancelando` acima, a 1ª versão passava no teste
  de "gravar e enviar" mas falhava nesse.
- **Fila de leads / plantão** — `includes/fila_leads.php`: round-robin entre
  consultores `disponivel=1` via contador monotônico `usuarios.posicao_fila`
  (não timestamp — SQLite só tem granularidade de 1s, ver bug real na seção
  de bugs corrigidos); se ninguém estiver disponível, cai no plantão
  (`usuarios.plantao_fim_expediente`).
  **Teto de 5 leads ativas por consultor + redistribuição manual**
  (16/09/2026, achado real em produção — José/Jean: "tinha vários lead na
  fila, usuário Dayane logou veio tudo para ela, usuário Anderson logou e
  Rafael ficaram sem lead, temos que redistribuir, melhora isso máximo 5
  lead por usuario"). Causa raiz: o rodízio só olha quem está disponível NO
  MOMENTO que cada lead chega — se só um consultor estava online quando
  vários leads entraram em sequência, todos iam pra ele; quando os outros
  ficaram disponíveis depois, não existia mecanismo pra "compensar" o
  desequilíbrio já feito, já que não tinha lead novo chegando naquele
  momento. Confirmado com o usuário via 2 perguntas diretas antes de
  implementar: redistribuição manual mexe só nos leads que o consultor
  sobrecarregado AINDA NÃO tocou (não tira nada que ele já está
  trabalhando); lead acima do teto no rodízio automático fica sem
  responsável, na fila — nunca força atribuição em alguém já no limite.
  Correção em 2 frentes: (1) `FILA_LEADS_MAX_ATIVAS` (5) +
  `contarOportunidadesAtivas()` — `proximoDaFila()` no caminho do rodízio
  normal (`disponivelOnly`) passou a buscar todos os candidatos ordenados
  por `posicao_fila` (não mais só o primeiro via `LIMIT 1`) e pular quem já
  está no teto, retornando `null` (mesmo comportamento de "ninguém
  disponível") se todo mundo disponível estiver no teto — o caminho de
  plantão de fim de expediente **nunca** respeita o teto, sempre recebe,
  senão um lead ficaria largado fora do horário só porque o plantonista já
  está sobrecarregado. (2) `redistribuirFilaLeads()` (novo) corrige o
  desequilíbrio **já existente** — o teto sozinho só evita que aconteça de
  novo daqui pra frente: move só oportunidades ainda em
  `etapa='crm_preenchido'` (IA terminou de qualificar, bloco 5 — consultor
  ainda não começou a atender) de quem está acima do teto pra quem está
  disponível e abaixo, em rodízio que reordena pelo mais vazio a cada
  movimentação (espalha em vez de empilhar só no primeiro receptor); cada
  movimentação grava em `oportunidade_historico` (mesmo
  `etapa_anterior`/`etapa_nova='crm_preenchido'`, só o `responsavel_id`
  muda) com observação explicando o motivo — nunca silencioso. UI em
  `admin/configuracoes.php`: card da fila ganhou coluna "Leads ativas" por
  consultor (aviso visual quando acima do teto) e botão "🔄 Redistribuir
  fila agora" (super_admin), mostrando resumo de quantas oportunidades
  foram movidas e de quem pra quem. Testado em banco isolado reproduzindo
  o cenário exato reportado (1 consultor com 8 oportunidades ativas em
  `crm_preenchido`, 2 disponíveis com 0): `proximoDaFila()` corretamente
  pula o consultor no teto pra um lead novo; `redistribuirFilaLeads()` move
  exatamente as 3 excedentes (8→5), espalhando entre os 2 receptores (não
  empilha só no primeiro), com os 3 registros de histórico certos; rodar de
  novo já balanceado não move nada; consultor offline (`disponivel=0`)
  corretamente nunca recebe redistribuição mesmo abaixo do teto.
  **Redistribuição só olhava `crm_preenchido`, deixando passar a maioria
  batido** (mesmo dia, achado real usando de verdade: "apertei distribuir
  dayane está com 42 leads temos da um jeito"). Causa: `atribuirResponsavelAutomatico()`
  roda já na **entrada** do lead (bloco 2, `etapa='whatsapp'`, antes da IA
  nem qualificar — `criarOuAbrirOportunidade()`), não só quando chega em
  `crm_preenchido`; um consultor sobrecarregado podia estar empilhado em
  **qualquer uma das 3 etapas** anteriores a `atendimento` (`whatsapp` —
  IA ainda conversando —, `qualificacao_ia`, `crm_preenchido` — já
  qualificado esperando o consultor começar), e a 1ª versão da
  redistribuição só olhava a última, ignorando as outras duas. Corrigido:
  `FILA_LEADS_ETAPAS_NAO_TOCADAS` (novo) = `['whatsapp', 'qualificacao_ia',
  'crm_preenchido']` — `redistribuirFilaLeads()` busca candidatas nas 3
  etapas (`IN`, não mais igualdade simples) e grava no histórico a etapa
  real de cada oportunidade movida (antes vinha fixo `crm_preenchido`,
  quebraria o registro pra uma movida de `whatsapp`/`qualificacao_ia`).
  Continua nunca mexendo em `atendimento` em diante — ali já é contato
  humano de verdade. Testado em banco isolado reproduzindo o volume exato
  reportado (42 oportunidades da Dayane — 20 em `whatsapp` + 15 em
  `qualificacao_ia` + 7 em `crm_preenchido` —, 2 consultores disponíveis
  com 0 cada): as 3 etapas corretamente viram candidatas, 10 movidas no
  total (Anderson e Rafael preenchidos até o teto de 5 cada — capacidade
  máxima do sistema com só 3 consultores), soma final ainda bate 42 (nada
  perdido/duplicado), oportunidade movida manualmente pra `atendimento`
  confirmadamente nunca é tocada mesmo rodando a redistribuição de novo.
  **Achado importante pro usuário**: com só 2-3 consultores disponíveis e
  teto de 5, a capacidade TOTAL do sistema é 10-15 leads ativas — 42 leads
  reais não cabem nesse teto só redistribuindo; ou aumenta quem está
  disponível, ou vale checar se boa parte desses 42 não é sobra do
  incidente de flood de mensagens duplicadas (15/09/2026, ver bullet do
  WhatsApp Box acima) que nunca foi limpa de verdade.
  **`install/limpar_leads_invalidos.php` limpou 5 candidatos, mas todos em
  `perdido`** — rodado em produção pra confirmar a suspeita do bullet
  anterior: só 5 clientes bateram o critério de telefone inválido (não 42),
  e os 5 já estavam marcados `perdido` (provável tentativa manual de tirar
  da tela antes de existir um jeito de apagar de verdade) — ficaram fora
  da limpeza automática porque a rede de segurança original tratava
  QUALQUER etapa "além" (inclusive `perdido`/`sem_perfil`) como "já tocado,
  não apagar". Ajustado pra separar estado **terminal sem valor** de
  **trabalho ativo/negócio real**: `perdido`/`sem_perfil` passaram a ser
  seguros pra apagar automaticamente (nenhum dinheiro/contrato associado),
  só `atendimento`/`negociacao`/`presencial`/`fechado` continuam
  protegidos. Relatório do script passou a mostrar `etapas` e
  `motivo_perda` de cada candidato seguro, mais contexto antes de
  confirmar. Testado em banco isolado: candidato em `perdido` agora entra
  na lista de seguros, mesmo cenário em `fechado` continua protegido.
  **Print do funil principal revelou o problema de verdade: dezenas de
  leads reais, telefone válido, "Responsável: —"** (mesmo dia, usuário:
  "esses leads tem ir para tela dos consultores") — não eram leads falsos
  (o critério de telefone inválido não pegava esses), eram leads
  GENUÍNOS que nunca ganharam responsável porque `atribuirResponsavelAutomatico()`
  só roda 1 vez, na entrada do lead — se ninguém estava disponível NEM em
  plantão naquele instante exato, a oportunidade fica com
  `responsavel_id NULL` pra sempre, já que nada revisita depois. Esse é o
  mecanismo real por trás do sintoma original relatado ("Anderson logou e
  Rafael ficaram sem lead" — os leads que chegaram sem ninguém disponível
  nunca foram redistribuídos quando alguém finalmente logou, só os novos
  que chegaram DEPOIS do login iam pro rodízio). Corrigido com uma
  **fase 2** em `redistribuirFilaLeads()`: depois de rebalancear quem está
  acima do teto (fase 1), varre oportunidades com `responsavel_id IS NULL`
  nas mesmas `FILA_LEADS_ETAPAS_NAO_TOCADAS`, mais antigas primeiro (quem
  espera há mais tempo tem prioridade), e atribui pelo mesmo rodízio/teto
  — reaproveita a mesma lista de receptores já atualizada pela fase 1,
  então 1 clique resolve as duas coisas numa passada só. Nunca adota órfã
  que por algum motivo já esteja em `atendimento` ou além (não deveria
  existir, mesma rede de segurança de sempre). Testado em banco isolado:
  Dayane com 7 (acima do teto) + 20 órfãs em `qualificacao_ia` (criadas em
  horários diferentes) + Anderson disponível vazio + Rafael **offline** +
  1 órfã-em-`atendimento` (edge case de segurança) — rebalanceou 2 de
  Dayane pra Anderson (7→5), adotou 3 órfãs (as mais antigas, confirmado
  "Cliente Orfa 1" entre elas) até Anderson bater o teto de 5, Rafael
  offline corretamente nunca recebeu nada, 17 órfãs restantes corretamente
  sem responsável (capacidade do sistema esgotada com só 1 disponível),
  órfã em `atendimento` seguiu intocada.
  **Teto virou configurável, não fixo em 5** (mesmo dia, mesmo motivo da
  fase 2 acima — usuário viu o funil real com 33 oportunidades ativas pra
  só 3 consultores, teto de 5 = 15 vagas no máximo, redistribuir não
  resolvia sozinho; "como podemos fazer teto [mudar de] 5" / "redistribuir
  por igual sempre"). `filaLeadsMaxAtivas()` (substituiu a constante
  `FILA_LEADS_MAX_ATIVAS`) lê de `config.fila_leads_max_ativas`, cai pro
  padrão `5` se nunca configurado ou valor inválido — campo numérico +
  botão "Salvar teto" no card da fila em Configurações, sem precisar de
  deploy pra ajustar quando o volume acumulado exigir um teto maior
  temporariamente. **Bug real achado testando essa mudança**: `getConfig()`
  (`includes/db.php`) tem cache estático em memória, mas `setConfig()`
  nunca invalidava esse cache — se `getConfig()` já tivesse rodado ANTES
  de um `setConfig()` na MESMA request, leituras seguintes nessa mesma
  request continuavam vendo o valor antigo (só corrigia sozinho na
  próxima request, já que o `static` do PHP-FPM reseta entre requests).
  Bug latente no projeto INTEIRO (qualquer `setConfig()` seguido de
  `getConfig()` na mesma execução, não só a fila de leads) — nunca tinha
  sido pego porque a maioria das telas salva e só lê de novo numa página
  recarregada depois (request nova, cache novo); só apareceu de verdade
  no teste isolado do teto, chamando `setConfig()` e `filaLeadsMaxAtivas()`
  (que usa `getConfig()`) na mesma execução do PHP. Corrigido com
  `configCache()` (novo, retorna array por referência) compartilhada entre
  `getConfig()`/`setConfig()` — agora as duas mexem no mesmo cache, nunca
  mais dessincroniza. Testado: leitura logo depois de um `setConfig()` na
  mesma execução sempre bate com o valor recém-salvo; `tests/smoke.php`
  inteiro (86 arquivos) continua limpo depois da mudança num arquivo tão
  central. Distribuição igualitária confirmada com o volume real (33
  oportunidades órfãs + 3 consultores + teto=11, 3×11=33 cabe exato):
  `redistribuirFilaLeads()` atribuiu as 33 igualmente, 11 pra cada, 0
  órfãs restantes.
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
  IA sobre a conversa até ali, reavaliada a cada turno; de propósito NÃO
  conta como "avanço real" pro contador de estagnação, senão o contador
  nunca dispararia). **Critério de temperatura ajustado em 15/09/2026**
  (pedido do José/Jean, feedback direto vendo o relatório de
  `admin/qualidade_ia.php`): antes o critério era só tom/engajamento da
  conversa; agora o sinal PRINCIPAL é a situação financeira do
  financiamento — muitas parcelas em atraso e sem outra opção pra resolver
  = "quente" (dor financeira real, urgência de vender); parcelas em dia,
  financiamento tranquilo = "frio" (Fastcar ainda compra, só que sem a
  mesma pressa); "morno" no meio, incluindo o caso de quem já pagou boa
  parte do financiamento (saldo baixo, poucas parcelas restantes) mesmo
  sem atraso — já qualifica o lead, mas não com a urgência de quem está
  atrasado. Tom/engajamento virou sinal secundário, só desempata dentro da
  mesma faixa (não escala sozinho pra "quente" com parcela em dia).
  **"Poucas parcelas restantes" virou "frio", não mais "morno"**
  (16/09/2026, achado real vendo a oportunidade #51 no admin — Chevrolet
  Spin LT 2016, parcela R$1.600, só 4 parcelas restantes, tinha saído
  "🔥 Quente"; José/Rafael: "esse tipo lead aqui é lead frio, poucas
  parcelas para pagar"). O critério de 15/09/2026 (ver acima) tinha
  colocado "já pagou boa parte do financiamento — poucas parcelas
  restantes, saldo baixo — mesmo sem atraso" no bucket "morno"; corrigido
  pra "frio" — racional de negócio: quem está perto de quitar sozinho tem
  **menos** motivo pra vender agora pra Fastcar assumir uma dívida
  pequena, não mais urgência. "morno" ficou só com "1-2 parcelas
  atrasadas" ou "financiamento tranquilo mas já decidida a vender por
  outro motivo real (ex: trocar de carro)". Mudança só de prompt
  (`IA_EXTRACAO_PROMPT`, `includes/ia_qualificacao.php`) — não dá pra
  testar contra servidor fake local (não simula julgamento de IA);
  validação real só na próxima conversa nova. Não corrige retroativamente
  oportunidades já classificadas (`temperatura_lead` só reavalia quando a
  IA processa um turno novo) — a #51 específica precisaria de correção
  manual direta no banco pra já sair corrigida na tela.
  **Badge de temperatura** (🔥/🌤️/❄️, mesmo dia — "coloca selo no lead")
  visível na tabela do funil (`admin/index.php`) e no cabeçalho do detalhe
  da oportunidade (`admin/oportunidade.php`).
  **Veículo já quitado agora desqualifica** (mesmo dia, achado real vendo a
  oportunidade #13 no admin — "esse tipo desqualificado porém o setor de
  vendas pode trabalhar sobre lead"): o foco da Fastcar é comprar veículo
  AINDA financiado (assumir a dívida); antes disso, um cliente confirmando
  "já está quitado" era tratado como lead válido normal (`qualificacao_completa`
  aceitava "banco+parcela OU confirmação de quitado" como caminho pra
  completar) e podia até sair "🔥 Quente" (achado literal: PCX 2024 quitada,
  R$15.000 pretendido, marcada Quente). Confirmado com o usuário (2
  perguntas diretas): agora `sem_perfil=true` assim que o cliente confirma
  quitação, com `motivo_sem_perfil` explicando "fora do foco de compra
  financiada, possível oportunidade pro setor de vendas" — a oportunidade
  encerra em `sem_perfil` normalmente (regra existente, `marcarPerdida()`),
  o motivo fica visível pro consultor no detalhe (`admin/oportunidade.php`,
  "Oportunidade encerrada — Sem perfil de compra: [motivo]") — decisão
  explícita de NÃO criar nenhum roteamento/fila nova pro "setor de vendas"
  agora, só deixar registrado pra quem olhar decidir manualmente. IA
  instruída a nunca dizer "não compramos" pro cliente, só agradecer e
  encerrar a qualificação com educação (mesmo tom do caso "não quero
  vender"). Testado ponta a ponta com servidor Gemini fake simulando a
  extração: `etapa` vira `sem_perfil`, `motivo_perda` grava o texto certo,
  `oportunidade_historico` registra a transição — sem regressão na
  qualificação normal (veículo financiado continua completando normal).
  **IA recusando lead de moto em produção** (16/09/2026, achado real pelo
  Jean vendo uma conversa de verdade — cliente "Igor" disse "meu veículo é
  uma moto" e a IA respondeu "a gente trabalha especificamente com a
  compra de **carros** financiados mesmo", encerrando a qualificação sem
  nem perguntar o nome dele direito; Jean: "precisa falar pra ia que
  compramos moto tbm não apenas carros. Compramos moto carro caminhão
  jetski"). Causa: o prompt inteiro (`IA_QUALIFICACAO_PROMPT_SISTEMA`)
  usa "carro" como exemplo recorrente em quase toda instrução/frase de
  exemplo, mas nunca dizia explicitamente que a Fastcar compra outros
  tipos de veículo — a IA concluiu sozinha (errado, alucinação induzida
  pelo próprio prompt) uma restrição que não deveria existir. Corrigido
  com 2 reforços explícitos: no parágrafo de abertura (deixa claro que
  "carro" é só o exemplo mais comum, nunca uma restrição) e uma regra
  nova em "REGRAS QUE NÃO PODEM SER QUEBRADAS" nomeando os tipos
  (carro/moto/caminhão/caminhonete/van/jet ski) e proibindo
  explicitamente qualquer frase tipo "trabalha especificamente com
  carros". Mudança só de prompt (texto pra LLM), não dá pra testar contra
  servidor fake local como o resto do projeto (fake server não simula
  julgamento de IA) — validação real só acontece com a próxima conversa
  de verdade no WhatsApp; pedir confirmação pro Jean/José assim que
  surgir outro lead de moto/caminhão/jet ski.
  **Bug real achado durante esse teste**: mensagem de texto puro (não
  mídia) disparava `PHP Warning: Undefined variable $bytesMidia` — a
  variável usada pra salvar mídia (bullet acima, mesmo dia) só era
  declarada DENTRO do bloco `if ($texto === null)`, mas era lida mais
  adiante incondicionalmente; corrigido declarando `$bytesMidia`/`$mime`
  antes do branch, pra toda mensagem (texto ou mídia) passar por ali sem
  warning. `whatsapp_sessoes.turnos_sem_avanco`
  incrementa a cada turno que não extraiu nenhum dado novo de verdade e
  reseta quando extrai; ao chegar em `IA_LIMITE_TURNOS_SEM_AVANCO` (5)
  turnos seguidos sem avanço, escala automaticamente pro consultor humano
  (`mudarEtapa()` pra `crm_preenchido`, resumo marcado com o motivo) em vez
  de deixar a IA girando à toa com quem só quer bater papo ou desconfiou
  que é bot. `resumo_ia` sai em formato checklist (✅ confirmado / ⚠️ falta
  confirmar) pro consultor entender rápido o que já foi coberto. Áudio,
  imagem **e vídeo** recebidos no WhatsApp entram no fluxo normalmente:
  áudio é transcrito, imagem é descrita e vídeo é descrito (cena + fala,
  focando no veículo quando aparece) via Gemini multimodal
  (`geminiCallComMidia()`, `inlineData` base64) e o texto resultante alimenta
  a qualificação como se fosse mensagem digitada (salvo em
  `whatsapp_mensagens` com `tipo='text'` e prefixo 🎤/📷/🎥, pra não precisar
  tocar em mais nada que já filtra por `tipo='text'`). Vídeo entrou em
  15/09/2026 (pedido José/Jean: "receber mídias áudio, imagem e vídeo, se
  cliente mandar, agente olhar") reaproveitando o mesmo mecanismo — só com
  `WHATSAPP_MIDIA_MAX_BYTES` (20MB) cortando o download cedo via
  `CURLOPT_RANGE`, já que a API do Gemini só aceita `inlineData` inline até
  por volta desse tamanho (acima disso precisaria da Files API, não
  implementada) e vídeo de WhatsApp passa fácil desse limite; arquivo
  grande demais cai no mesmo caminho de "não deu pra processar" abaixo,
  nunca trava nem estoura memória do processo do webhook. Testado com
  servidor fake local: vídeo normal processado e registrado como
  `tipo='text'` com prefixo 🎥, vídeo maior que o limite corretamente
  rejeitado (volta vazio, sem chamar o Gemini com o arquivo inteiro).
  Figurinha, documento, localização, contato — ou áudio/imagem/vídeo que
  falhou o processamento — seguem recebendo uma resposta de reconhecimento
  simples (`chatbot-whatsapp/includes/mensagens.php`) em vez de deixar o
  lead sem resposta nenhuma. **Debounce de mensagens picotadas** (13/09/2026,
  auditoria de "como fica na prática" pedida pelo José/Jean): sem isso, um
  cliente que manda "Oi" / "quero vender meu carro" / "é um Onix 2019" como
  3 mensagens separadas em poucos segundos recebia 3 respostas picotadas da
  IA, uma pra cada, com jeito nítido de robô mal escutando. Agora, antes de
  chamar a IA, `aguardarSilencioOuAbortar()` espera
  `WHATSAPP_DEBOUNCE_SEGUNDOS` (4s) de silêncio; se chegar mensagem mais
  nova desse telefone nesse meio tempo (outro worker do PHP-FPM já
  processando em paralelo), essa chamada aborta silenciosamente — a
  chamada da mensagem mais nova é quem responde, já lendo o histórico
  completo (inclui as anteriores). `0` desliga o debounce, usado pelo
  simulador de CLI (`chatbot-whatsapp/simulate.php` — não faz sentido
  esperar segundos numa conversa digitada linha a linha ao vivo). Validado
  diretamente (2 processos reais concorrentes disputando o mesmo telefone,
  com `sleep()` de verdade) confirmando abortar quando chega mensagem nova
  e prosseguir quando não chega; a prova de ponta a ponta via HTTP fica
  pendente de um servidor com concorrência real de verdade (PHP-FPM da
  VPS) — o servidor embutido do PHP usado em dev processa uma request por
  vez por padrão, o que mascara exatamente essa corrida.
  **Prompt revisado** (mesma auditoria): adicionada orientação explícita
  pra "quanto vocês pagam?" (quase sempre a 1ª pergunta do lead — antes só
  tinha "nunca prometa valor", sem indicar como desviar sem soar evasivo) e
  pra desconfiança em passar dado financeiro por WhatsApp (explicar em 1
  frase por que a Fastcar precisa saber do banco/parcela, sem insistir se a
  pessoa não quiser). **Notifica o consultor quando termina** (mesma
  auditoria, achado real: `mudarEtapa()` pra `crm_preenchido` — tanto
  qualificação completa quanto escalação por estagnação — mudava a etapa
  em silêncio, sem avisar ninguém; o único aviso que existia
  (`notificarNovoLeadWhatsapp()`, bloco 2) dispara na ENTRADA, antes da IA
  nem perguntar o nome, pra uma lista genérica — não quando a qualificação
  termina de verdade). `notificarConsultorLeadQualificado()`
  (`includes/oportunidades.php`) manda o resumo_ia pro WhatsApp PESSOAL do
  consultor responsável (`usuarios.whatsapp`) assim que a etapa vira
  `crm_preenchido`; sem responsável definido (ex: ninguém disponível na
  fila quando o lead entrou) ou sem WhatsApp cadastrado pra ele, cai no
  aviso genérico de `notificacao_leads_whatsapp` como fallback — nunca
  passa batido. Testado ponta a ponta via simulador: consultor certo
  (escolhido pelo rodízio da fila) recebe a notificação com o resumo assim
  que a IA conclui a qualificação, e o fallback dispara pra lista genérica
  quando não tem responsável.
  **Telefone do consultor mandado pro cliente** (15/09/2026, pedido
  José/Jean: "cliente aceitou que consultor ligar, encaminhar notificação
  ao consultor e enviar telefone dele pro cliente") —
  `enviarTelefoneConsultorAoCliente()` (`includes/oportunidades.php`)
  dispara logo depois de `notificarConsultorLeadQualificado()`, só na
  qualificação COMPLETA (nunca na escalação por estagnação — nesse caso
  `aceita_ligacao_consultor` pode nem ter sido respondido ainda) e só
  quando `aceita_ligacao_consultor` é `1` de verdade (checagem estrita —
  recusou ou não respondeu não dispara nada). Manda uma mensagem pro
  CLIENTE com o nome do consultor + `usuarios.whatsapp` dele, registrada
  em `whatsapp_mensagens` como qualquer outra mensagem automática (mesmo
  padrão do reengajamento do `cron/followup.php`) — assim o cliente não
  fica só esperando a ligação, pode chamar direto se quiser. Sem
  responsável definido ou sem WhatsApp cadastrado pra ele, não manda nada
  (nunca manda mensagem quebrada/sem número nenhum). Testado ponta a ponta
  com servidor Gemini+Z-API fake: aceitando a ligação, o cliente recebe as
  2 mensagens (resposta da IA + telefone do consultor) na ordem certa,
  registradas no histórico; recusando, só a resposta da IA e o aviso
  interno pro consultor saem — telefone nunca é mandado.
  **Reconhece reclamação pós-venda e escala direto, sem qualificar como
  venda nova** (16/09/2026, "ensinar ia pegar casos") — achado real:
  cliente reclamando de financiamento não quitado de um carro JÁ vendido
  pra Fastcar (recebendo notificação extrajudicial) tinha a mensagem
  processada como qualificação de venda NOVA do zero, a IA perguntando
  marca/modelo/banco como se fosse lead comum. Novo campo
  `reclamacao_pos_venda` (+`motivo_reclamacao_pos_venda`) no
  `IA_EXTRACAO_PROMPT`, checado **antes** de `sem_perfil` de propósito —
  categoria bem diferente: cliente JÁ CONVERTIDO com pendência real
  (às vezes urgência jurídica), não lead desqualificado; nunca marca
  como perdido/`sem_perfil`, que esconderia um problema real em vez de
  resolver. Quando detectado, `iaProcessarTurno()` escala direto pro
  consultor (`mudarEtapa` pra `crm_preenchido`, `resumo_ia` com o relato,
  `notificarConsultorLeadQualificado()` com aviso de urgência) sem
  tentar qualificar como venda nova. "REGRAS QUE NÃO PODEM SER
  QUEBRADAS" ganhou instrução explícita: nunca perguntar marca/modelo/
  banco/parcela nesse cenário, só reconhecer o problema e tranquilizar
  que a equipe vai olhar. Vincular a pendência à pasta ORIGINAL fechada
  (`admin/pendencias_pos_venda.php`) continua sendo passo manual do
  consultor — a IA só identifica e escala rápido, não sabe sozinha qual
  é a pasta antiga certa. Mudança de prompt (julgamento de IA) não
  testável contra servidor fake — validação real só na próxima conversa
  desse tipo.
- **Atribuição de origem de anúncio** — `extrairOrigemAnuncio()` (Meta Ads
  "Clique para WhatsApp", campo `referral` do 1º contato) +
  `admin/origem_leads.php` (analytics de canal/campanha/anúncio)
- **Busca de FIPE pela placa (PlacaFIPE)** — 15/09/2026, José/Jean pediram
  "vamos colocar em produção" depois de eu explicar a limitação da
  integração FIPE existente (BrasilAPI v1, só validava a marca digitada,
  sem busca de valor — `valor_fipe_referencia` era 100% digitado à mão).
  **Retrabalho no mesmo dia — provedor errado na 1ª tentativa**: a 1ª
  implementação (`fipeV2*`, Parallelum FIPE v2, cascata marca→modelo→ano,
  header `X-Subscription-Token`) foi construída a partir de conhecimento
  geral de documentação, **nunca confirmada contra doc real** — assim que
  o José mandou o link real do provedor contratado
  (`https://doc.placafipe.com.br/api/`), ficou claro que é uma API
  **completamente diferente**: **PlacaFIPE** (`api.placafipe.com.br`),
  busca primária **por placa** (não por cascata marca/modelo/ano), POST
  com token dentro do **corpo JSON** (`{"token": "..."}`), nunca em
  header — diferente de toda outra integração do projeto (Z-API, Gemini
  etc, que usam header/querystring). Meu sandbox bloqueia acesso direto a
  `doc.placafipe.com.br` (`WebFetch` e `curl` retornaram erro de rede
  ambos) — José colou o conteúdo da documentação direto no chat
  (`getplaca`, `getplacafipe` com JSON de resposta real, e o fluxo
  completo de cascata `ConsultarTabelaDeReferencia→ConsultarMarcas→
  ConsultarModelos→ConsultarAnoModelo→ConsultarValorComTodosParametros`
  com formato de REQUEST confirmado mas **sem nenhum exemplo de RESPONSE**
  pros 4 passos intermediários). Reescrita completa jogando fora a
  implementação Parallelum inteira; **escopo deliberadamente reduzido**
  pra evitar repetir o mesmo erro: implementado **só** `getplacafipe`
  (busca por placa, request E response confirmados de verdade pela doc) —
  a cascata marca/modelo/ano (`ConsultarMarcas` etc) **não foi
  implementada**, por falta de confirmação do formato de resposta; fica
  pendente até a doc trazer exemplo de resposta real ou alguém confirmar
  contra a API. `placafipeConsultarPorPlaca()` (`includes/fipe.php`) POSTa
  placa+token, cacheia 24h por placa em `config` (mesmo padrão
  `timestamp|json` de sempre — motivo é custo: o plano da PlacaFIPE cobra
  por requisição, "a cada requisição diminui uma requisição restante" na
  doc, então recarregar a tela da oportunidade repetidas vezes não pode
  queimar cota de novo). Widget em `admin/oportunidade.php` (campo de
  placa, pré-preenchido com `veiculo_placa` da oportunidade, só aparece
  com `config.placafipe_token` preenchido) mostra **todos os candidatos**
  retornados (`fipe[]`, cada um com `correspondencia`%, já que uma mesma
  placa pode bater com mais de um FIPE — motor/versão diferente) e exige
  clique explícito em "Usar este valor" num candidato específico pra
  preencher `valor_fipe_referencia` — nunca aplica o 1º automaticamente
  (mesma regra de "IA/sistema nunca decide informação incerta sozinho" já
  usada no resto do projeto), e nunca submete o formulário sozinho.
  `admin/fipe_ajax.php` (ação única `buscar_placa`) serve o JSON.
  Configurações → 🚗 FIPE — busca por placa (PlacaFIPE): campo de token
  (`config.placafipe_token`, substituiu `fipe_v2_token` — migração em
  `install/migrar.php` copia o valor antigo se existir), badge de status,
  e teste de conexão que **exige uma placa real digitada** (consome 1
  requisição de verdade do plano, mesmo espírito do `testar_zapi`/
  `testar_email` — nunca tentei adivinhar um endpoint `getquotas` de custo
  zero sem confirmar o formato dele também). Testado: 4 cenários isolados
  de `placafipeConsultarPorPlaca()` (sem token, placa mal formatada, placa
  não encontrada `codigo=0`, sucesso `codigo=1`) + Playwright ponta a
  ponta contra servidor PlacaFIPE fake local modelado no JSON real
  confirmado — placa pré-preenchida da oportunidade, busca retorna 2
  candidatos, clique no **2º** candidato (não o 1º) preenche
  `valor_fipe_referencia` com o valor específico daquele candidato
  (21798, não o do 1º), confirmando que a tela não aplica o resultado
  "óbvio" sozinha. `fipeValidarMarca()`/BrasilAPI v1 continuam intocados,
  funcionando igual sem nenhum token configurado.
  **Marca/modelo/ano preenchidos automaticamente pela busca** (16/09/2026,
  José vendo o card "Dados do veículo" — "nesse campo da placa poderia
  puxar todos dados pela api né preencher"): a busca por placa já trazia
  `informacoes_veiculo` (marca/modelo/ano/cor/uf) — só não estava
  aproveitado pra nada além do texto "Veículo encontrado: ...". Agora, ao
  buscar, os campos `veiculo_marca`/`veiculo_modelo`/`veiculo_ano` do card
  "Dados do veículo" (formulário diferente do widget de busca — inputs com
  `id` novo pra JS conseguir achar) são preenchidos **fill-if-empty**
  (nunca sobrescreve o que o consultor já tinha digitado ali) — diferente
  do valor FIPE (`fipe[]`, múltiplos candidatos, sempre exige escolha
  humana explícita), `informacoes_veiculo` vem como 1 resposta só da
  placa, então preenche direto sem precisar de clique extra; o consultor
  ainda vê o campo populado e pode corrigir antes de "Salvar dados do
  veículo". Testado com Playwright contra servidor PlacaFIPE fake local:
  oportunidade com marca/modelo/ano vazios recebe os 3 campos preenchidos
  certos após a busca; oportunidade com esses campos JÁ preenchidos
  mantém os valores originais intocados mesmo depois da mesma busca.
- **Módulo cliente** — `admin/clientes.php` (lista/busca) +
  `admin/cliente_detalhe.php` (dados cadastrais + histórico de todas as
  oportunidades daquele telefone, incluindo veículo/placa e **data real de
  assinatura do contrato** — `contratos.assinado_em`, coluna nova de
  13/09/2026, pedido explícito "qual veículo comprado, que dia ele assina
  contrato"; diferente de `updated_at`, que muda em toda sincronização —
  `assinado_em` só grava na 1ª vez que o status vira `assinado` de
  verdade, em `zapsignSincronizarContrato()`) — badge "🏆 Cliente
  convertido" quando o cliente já teve pelo menos um veículo com
  `etapa='fechado'`.
  **Nome editável direto em `admin/oportunidade.php`** (16/09/2026) — antes
  só dava pra editar em `admin/cliente_detalhe.php`, precisando navegar pra
  outra tela; achado real vendo nomes capturados errado do WhatsApp (`.`,
  `$`, etc — ver bullet "puxa foto do zap e nome"). Campo + botão "Salvar
  nome" logo abaixo do cabeçalho `#ID — Nome`, mesmo guard de supervisor
  (só acompanha, não edita) das outras ações da página.
- **Pendências pós-venda** (`includes/pendencias_pos_venda.php` +
  `admin/pendencias_pos_venda.php`, 16/09/2026) — `oportunidade_pendencias_pos_venda`
  existia no schema desde o início (regra #8: "'Compra concluída' ≠ fim de
  tudo... pendência futura continua vinculada à mesma pasta") mas nunca
  teve tela/fluxo nenhum. Achado real em produção: cliente (Bárbara)
  reclamando que o financiamento de um Duster **já vendido** pra Fastcar
  não foi quitado nem transferido, recebendo notificação extrajudicial em
  nome dela — o sistema tratava isso como **lead novo** (oportunidade
  rodando qualificação por IA do zero, pedindo dados de veículo pra quem
  já é cliente reclamando de venda antiga) em vez de vincular à pasta
  ORIGINAL já fechada. `criarPendenciaPosVenda()`/
  `concluirPendenciaPosVenda()`/`reabrirPendenciaPosVenda()`/
  `listarPendenciasDaOportunidade()`/`listarPendenciasPosVendaAbertas()`
  (painel geral, filtro por responsável mesmo padrão "Minhas/Todas" do
  funil de compra). `oportunidade_pendencias_pos_venda.responsavel_id`
  (coluna nova) — mesmo espírito da regra #5 (toda oportunidade aberta
  precisa de responsável), aplicado aqui pra pendência não ficar largada
  sem dono depois da pasta já fechada. Card "📋 Pendências pós-venda" em
  `admin/oportunidade.php`, só visível/editável quando `etapa='fechado'`
  (não faz sentido essa pendência numa negociação ainda em aberto no
  funil normal) — lista existentes com status/prazo estimado/responsável,
  formulário pra registrar nova, botões marcar concluída/reabrir.
  `admin/pendencias_pos_venda.php` (novo, nav "📋 Pendências") — painel
  geral com todas as pendências abertas, atrasadas destacadas (prazo
  estimado já passado), link direto pro cliente/oportunidade. Testado em
  banco isolado: pendência criada numa oportunidade fechada aparece certa;
  painel geral mostra e marca atrasada quando o prazo passou; filtro por
  responsável funciona (Rafael não vê pendência do Anderson, Anderson vê a
  própria); concluir marca `status`/`concluido_em` certos e some do painel
  de abertas; reabrir volta a aparecer. **IA ensinada a reconhecer o caso
  e escalar direto** (mesmo dia, "ensinar ia pegar casos") — ver bullet
  `reclamacao_pos_venda` na seção "Qualificação por IA" abaixo. Vincular a
  pendência à pasta ORIGINAL fechada continua sendo passo manual do
  consultor — a IA só identifica e escala rápido, não sabe sozinha qual é
  a pasta antiga certa.
- **E-mail do cliente** (`clientes.email`, 14/09/2026, pedido direto do
  José/Jean — "faltou esse dado"): campo que faltava na **1ª etapa** do
  wizard de documentos (`public/documentos.php`, junto com CPF/RG/
  nacionalidade/estado civil/profissão — `atualizarDadosPessoaisCliente()`),
  editável também em `admin/cliente_detalhe.php` (pro consultor completar
  em cadastro antigo) e exibido em `admin/oportunidade.php` (com aviso +
  link pra completar quando falta). Motivo de negócio, mesmo dia: "vamos
  enviar no email dele o contrato" — `includes/zapsign.php::zapsignCriarDocumentoEAssinatura()`
  passou a aceitar e-mail opcional e inclui no `signer` (`email`) só quando
  presente e válido (`filter_var(...,FILTER_VALIDATE_EMAIL)`); a ZapSign
  manda o link de assinatura por e-mail além do telefone/WhatsApp quando
  tem esse dado. Nunca bloqueia: cliente sem e-mail (cadastro antigo, ou
  etapa 1 do wizard ainda não confirmada) segue mandando o contrato só por
  telefone — `gerarEEnviarContratoCompra()` só avisa (mesmo mecanismo do
  aviso de limite de 25% da FIPE), nunca impede a geração/envio. Bug real
  achado no teste visual: o CSS do wizard só estilizava `input[type=text]`
  — o novo campo (`type="email"`, validação nativa do navegador) ficava bem
  mais estreito que os outros até o seletor ser corrigido pra cobrir os
  dois tipos.
- **Frota (veículos comprados)** — `admin/veiculos.php` (novo, 13/09/2026,
  pedido "no vendido ter dashboard todos carros" — nome de arquivo é
  `veiculos.php` porque hoje só existe o lado de COMPRA; "vendido" aqui
  significa "vendido *pro* Fastcar", não o módulo de revenda): lista toda
  oportunidade com `etapa='fechado'`, busca por **placa, chassi**,
  marca/modelo ou nome do vendedor, mostra valor pago, data da compra e
  **meses com a Fastcar** (calculado a partir de `data_compra`). Restrito
  ao super_admin, mesma trava de `admin/produtividade.php`. Busca por
  placa/chassi (não só id da oportunidade) é de propósito: era a chave que
  ia deixar dar pra relacionar o mesmo veículo físico com uma futura
  revenda sem precisar remodelar nada — e foi exatamente isso que aconteceu
  quando o módulo de vendas saiu do papel (15/09/2026, ver bullet próprio
  abaixo): coluna "Venda" nova aqui mostra disponível/em negociação/vendido
  de cada veículo, com botão "Vender" direto na linha.
- **Módulo de vendas (revenda de veículo da frota)** — `includes/vendas.php` +
  `admin/vendas.php` (pipeline) + `admin/venda.php` (negociação individual),
  15/09/2026, pedido direto do José/Jean ("você colocar galera para fazer o
  fluxo e fechar pontas soltas") seguindo o combinado antes ("modulos de
  vendas seguir mesmo padrão de compra ter a telas de negociação"). Mesmo
  padrão do funil de compra — `mudarEtapaVenda()` central, nunca `UPDATE`
  direto em `vendas.etapa`, histórico em `venda_historico` — mas **funil
  mais simples** (`negociacao` → `contrato_enviado` → `vendido`, ou
  `cancelada` a qualquer momento): sem qualificação por IA nem entrada pelo
  WhatsApp do lado do comprador, a negociação é sempre **criada manualmente**
  a partir de um veículo da frota (`admin/veiculos.php`, botão "Vender" —
  restrito a super_admin, mesma trava da tela de frota), pois o comprador de
  uma revenda normalmente aparece por outro canal (indicação, anúncio,
  presencial), não por lead qualificado como no funil de compra.
  ⚠️ **Escopo desta 1ª versão é uma decisão assumida, não confirmada
  palavra por palavra com o Jean** — sinalizar se ele quiser outro desenho
  (ex: WhatsApp bot também pro lado do comprador, ou fluxo de aprovação
  antes de gerar contrato).
  `vendas` (nova tabela) guarda só o que é específico da REVENDA — dados do
  comprador (nome/CPF/RG/etc, colunas próprias, **não** `clientes`: o
  comprador é um contato diferente do vendedor original, e
  `clientes.telefone` é `UNIQUE` pro funil de compra, não serviria aqui —
  o mesmo telefone poderia em tese aparecer nos dois papéis em momentos
  diferentes) e condições da venda (preço, forma de pagamento, prazo de
  quitação até 24 meses, etc, Quadro-Resumo do contrato). Dados do veículo
  em si (marca/modelo/placa/renavam/chassi/financiamento em aberto) **nunca
  duplicados** — sempre lidos de `oportunidades` (mesma fonte de verdade da
  compra), via `oportunidade_id`. Só 1 negociação **ativa**
  (`negociacao`/`contrato_enviado`) por veículo por vez — travado em dobro:
  `criarVenda()` checa antes (mensagem legível pro admin) E um índice único
  parcial no schema (`idx_vendas_ativa_por_veiculo`) barra a corrida de 2
  requests simultâneas; cancelar uma negociação (motivo obrigatório, mesmo
  espírito de "marcar perdida" no funil de compra) libera o veículo pra
  uma nova tentativa com outro comprador.
  **Contrato de venda** — `includes/contratos_pdf.php::gerarPdfContratoVenda()`
  + `clausulasContratoVenda()` (20 cláusulas, transcritas do modelo real
  `01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx`, mesma correção de
  endereço/foro pra Barueri/SP já aplicada no contrato de compra) — reaproveita
  todo o encanamento visual do contrato de compra (`_pdfCabecalho()` ganhou
  parâmetro de subtítulo, `_pdfLinhaResumo()`/`_pdfContarLinhas()` idênticos).
  `includes/contratos.php::gerarEEnviarContratoVenda()` reaproveita
  `zapsignCriarDocumentoEAssinatura()` (já genérica) e
  `salvarArquivoGeradoComoDocumento()` sem mudar nada nelas — âncora do
  Drive continua sendo o cliente ORIGINAL (vendedor que trouxe o veículo),
  já que não existe cadastro em `clientes` pro comprador da revenda: pasta
  do veículo/processo fica com os documentos de compra E venda juntos.
  `contratos.venda_id` (coluna nova) desambigua qual negociação gerou o
  contrato quando `tipo='venda'` (um mesmo veículo pode ter mais de uma
  linha em `vendas` ao longo do tempo, se uma cair e outro comprador
  aparecer depois) — `admin/ver_contrato.php` já era genérico o bastante
  pra servir os dois tipos sem nenhuma mudança. `zapsignSincronizarContrato()`
  (webhook + polling, compartilhado com compra) passou a ramificar por
  `contratos.tipo`: pro lado de venda, salva a cópia assinada com nome de
  arquivo próprio (`contrato_venda_assinado_{venda_id}.pdf`) e, assim que
  detecta a assinatura, marca a negociação como `vendido` sozinho (só se
  ainda estava `contrato_enviado` — nunca força de volta se alguém já
  cancelou manualmente nesse meio-tempo) — venda não tem checklist de
  fechamento (regra #7 é só do funil de compra), então a assinatura em si
  já é o "fechamento" da negociação. Testado em banco isolado, ponta a
  ponta via HTTP + servidor ZapSign fake: botão "Vender" cria a negociação
  e redireciona pro detalhe; 2ª tentativa de vender o mesmo veículo barrada
  com mensagem clara (e confirmado que o índice único do banco também
  bloqueia numa inserção direta, sem passar pela aplicação); preenchimento
  de comprador+condições salva certo; geração do contrato monta os campos
  certos (conferido campo a campo), bate no formato exato de signer
  esperado pela ZapSign (nome/telefone/e-mail) e move a etapa pra
  `contrato_enviado` sozinho; PDF gerado conferido decodificando os content
  streams (Quadro-Resumo com os 21 campos certos, as 20 cláusulas completas,
  foro Barueri/SP, rodapé com endereço real); simulação de assinatura via
  `zapsignSincronizarContrato()` baixa a cópia assinada, marca `vendido` +
  `data_venda`, e confirmado que os campos do lado de COMPRA daquele mesmo
  veículo (`oportunidades.contrato_assinado`,
  `oportunidade_documentos.contrato_compra`) continuam intocados — as duas
  bases de dados não se cruzam; cancelamento de negociação libera o veículo
  pra uma 3ª tentativa (confirmado abrindo uma nova negociação depois de
  cancelar a anterior); conferido visualmente via screenshot (Playwright)
  em `admin/vendas.php`, `admin/venda.php` e `admin/veiculos.php`.
  **2ª etapa do módulo — funil de entrada pelo WhatsApp, "igual de compra"**
  (17/09/2026, pedido José/Jean: "perfil vendedor, ibox do vendedor igual
  compras, dasbord de vendas, vamos usar qualificação do lead para vendas,
  vamos adcionar instancia só para vendas") — a decisão assumida acima
  ("sem qualificação por IA nem entrada pelo WhatsApp do lado do
  comprador") foi revertida por pedido explícito; a negociação MANUAL a
  partir da frota (`admin/veiculos.php`, botão "Vender") continua existindo
  como caminho alternativo, as duas origens (`vendas.origem`,
  `'manual'`/`'whatsapp'`) convivem no mesmo pipeline.
  **Perfil `vendedor`** (novo, `usuarios.perfil`, migração de CHECK igual à
  já feita pro `'supervisor'`) — só acessa o módulo de vendas, nunca o
  funil de compra (times/dados separados, mesma lógica de "consultor nunca
  mexe em vendas" no sentido inverso). Trava CENTRAL em
  `admin/_bootstrap.php` (não em cada arquivo de compra um por um): se
  `admin_perfil==='vendedor'` e a página atual não está na lista branca
  (`vendas.php`/`venda.php`/`vendas_inbox.php`/`logout.php`), redireciona
  pra `admin/vendas.php` — `admin/login.php` também manda vendedor direto
  pra lá em vez de `admin/index.php`. `includes/security.php::podeAcessarVendas()`
  (super_admin/supervisor/vendedor — nunca consultor) trava o acesso às 3
  telas de vendas; o link "💰 Vendas" que já existia sem guard nenhum no
  topbar de `admin/index.php` (falha pré-existente — qualquer consultor já
  conseguia acessar `admin/vendas.php`/`venda.php` livremente antes disso)
  ficou condicionado a `podeAcessarVendas()`.
  **Instância Z-API DEDICADA de vendas** (`config.zapi_instancia_vendas_id`/
  `_token`/`_client_token`, card novo "🛒 Instância Z-API — Vendas" em
  Configurações, com teste de conexão próprio) — `zapiEnviarTexto()`
  (`includes/whatsapp_config.php`) ganhou parâmetro opcional
  `$instanciaOverride` (`[instance_id, token, client_token]`); omitido
  (padrão) continua usando a instância principal de compra, sem quebrar
  nenhum dos ~40 call sites existentes — `zapiCredenciaisVendas()` (novo)
  monta o array pros call sites do lado de vendas.
  `zapiIdentificarInstancia()` (`includes/zapi_instancias.php`) ganhou o
  tipo `'vendas'`, checado antes de cair pro banco de instâncias de
  consultor. Webhook único (`chatbot-whatsapp/webhook/whatsapp.php`,
  mesma URL de sempre) roteia pelo `instanceId` do payload: `tipo==='vendas'`
  chama `processarMensagemVendasZapi()` (novo
  `chatbot-whatsapp/includes/mensagens_vendas.php`) em vez de
  `processarMensagemZapi()` — arquivo PRÓPRIO de propósito (mesmo
  raciocínio já documentado no CLAUDE.md pro WhatsApp Box: "portar o
  conceito, não o arquivo direto... modelo de dado e regras de negócio
  diferentes demais pra copy-paste direto"), mexer na função de compra já
  validada em produção pra ramificar em cima de uma tabela diferente seria
  risco desnecessário. Reaproveita de `mensagens.php` tudo que já era
  genérico o bastante (dedup de `messageId`, extração de texto/mídia,
  debounce, pausar/retomar IA) — nenhuma dessas depende de
  `oportunidades`/`clientes`, só de telefone/`whatsapp_mensagens`/
  `whatsapp_sessoes` (tabelas já compartilhadas pelos dois funis).
  **Schema:** `vendas.oportunidade_id` virou NULLABLE (migração reconstrói
  a tabela, SQLite não tem `ALTER COLUMN`) — um lead pode chegar pelo
  WhatsApp antes de saber qual veículo específico da frota quer; `etapa`
  ganhou `'whatsapp'`/`'qualificacao_ia'`/`'sem_perfil'`, espelhando as
  etapas de entrada do funil de compra (negociação manual nunca passa por
  elas, nasce direto em `'negociacao'`); colunas novas `origem`,
  `resumo_ia`, `veiculo_interesse_texto`, `forma_pagamento_pretendida`,
  `urgencia`, `motivo_perda`. Identidade do comprador continua só nas
  colunas `vendas.comprador_*` (decisão original do módulo, nunca virou
  tabela `clientes`-like separada — evita a grande reforma de schema que
  isso exigiria, dado que o CLAUDE.md já documentava explicitamente por
  que comprador de revenda não usa `clientes`).
  **Qualificação por IA do comprador** (`includes/ia_qualificacao_vendas.php`,
  novo) — mesmo padrão Gemini+fallback OpenAI de `ia_qualificacao.php`
  (compra), prompt/extração PRÓPRIOS (conversa é sobre COMPRAR, não
  vender). Diferença central, respondendo ao pedido explícito ("essa
  qualificação pode usar informações do veículo já está na compra"):
  `listarFrotaDisponivelParaVenda()` (`includes/vendas.php`, oportunidades
  `etapa='fechado'` sem negociação de venda ativa) monta um resumo da
  frota REAL disponível, embutido dinamicamente tanto no prompt de
  conversa quanto no de extração — a IA só pode falar de veículo que
  EXISTE de verdade agora (regra #3, "IA nunca inventa"), nunca promete
  nem descreve um veículo fora dessa lista; sem nada disponível, diz isso
  explicitamente em vez de inventar. O vínculo final entre o lead e uma
  `oportunidade_id` específica é SEMPRE confirmado por um humano
  (`vincularVeiculoVenda()`, card "🔗 Vincular veículo da frota" em
  `admin/venda.php`, só aparece enquanto `oportunidade_id` é `NULL`) — a
  IA nunca decide isso sozinha, mesmo espírito de "sistema nunca aplica o
  candidato óbvio sozinho" já usado no widget de FIPE por placa. Gerar
  contrato / avançar pra `contrato_enviado`/`vendido` fica bloqueado (na
  tela E no servidor, nunca só confiando em esconder o botão) até o
  veículo estar vinculado. `criarOuAbrirVendaLead()` (`includes/vendas.php`)
  espelha `criarOuAbrirOportunidade()` — cria/reaproveita o lead por
  telefone, abre em `'whatsapp'`, atribui vendedor já na entrada (regra #2
  aplicada aqui também: salva desde o 1º contato).
  **Round-robin próprio** (`includes/fila_vendas.php`, novo — mesmo
  raciocínio de arquivo separado do WhatsApp Box: nunca parametrizar o
  `fila_leads.php` de compra, já bem testado, pra acomodar um 2º
  perfil/tabela) — `perfil='vendedor'`, conta `vendas` ativas (nunca
  `vendido`/`cancelada`/`sem_perfil`), teto configurável
  (`config.fila_vendas_max_ativas`, padrão 5, mesmo padrão da fila de
  compra). Atribui na ENTRADA do lead, não só quando a IA termina de
  qualificar — mesma decisão já validada no funil de compra.
  **WhatsApp Box do vendedor** (`admin/vendas_inbox.php` +
  `includes/vendas_inbox.php`, novos) — mesmo conceito do inbox de compra
  (sidebar+thread+polling+pausar IA automaticamente ao responder manual),
  mas as conversas vêm de `vendas.comprador_telefone` (sem `clientes`,
  `listarConversasVendas()` faz `JOIN vendas` em vez de `JOIN clientes`) —
  arquivo próprio, não um parâmetro a mais no inbox de compra (mesmo
  raciocínio de sempre). Escopo **intencionalmente mais enxuto** nesta 1ª
  versão, evitar over-engineering sem pedido real (mesmo espírito já
  documentado pro inbox de compra): sem busca ao vivo de foto de perfil do
  WhatsApp (avatar sempre em iniciais) e sem envio de áudio — ficam como
  possível próxima iteração se a equipe sentir falta. Envio manual assina
  com o nome do vendedor e sempre manda pela instância DEDICADA de vendas
  (`zapiCredenciaisVendas()`), nunca pela principal.
  **Dashboard do vendedor** (`includes/dashboard.php::dashboardVendedor()`/
  `dashboardVendasGeral()`, novos) — `admin/vendas.php` virou também o
  dashboard (mesmo espírito de `admin/index.php` ser dashboard+funil pro
  lado de compra): cards de ativas/atrasadas/recebidas na semana/
  disponibilidade/vendidas no mês (contagem+valor)/taxa de conversão,
  filtro "Minhas" (vendedor, por `responsavel_id`) vs "Todas"
  (super_admin/supervisor) — mesmo padrão exato de `admin/index.php`.
  `admin/usuarios.php` ganhou a opção "Vendedor" no seletor de perfil
  (criação e edição).
  Testado ponta a ponta: **função** — driver isolado simulando 5 turnos de
  webhook real (payload → `processarMensagemVendasZapi()`) contra
  servidor Gemini+Z-API fake local, confirmando lead criado com
  `origem='whatsapp'`, atribuído ao vendedor certo pelo rodízio,
  qualificação avançando turno a turno até `etapa='negociacao'` com
  `resumo_ia` preenchido, e a resposta da IA saindo pela instância de
  VENDAS (`inst-vendas/token/tok-vendas`) — nunca vazando a credencial da
  instância de compra, conferido no log do fake server. **UI** via
  Playwright (servidor PHP real + fake Gemini/Z-API): login de vendedor
  cai em `admin/vendas.php` (nunca `admin/index.php`, redirecionado se
  tentar); pipeline mostra o lead qualificado; abre a venda, vê os cards
  "Lead qualificado por IA" e "Vincular veículo da frota", vincula um
  veículo real da frota (select lista os 2 disponíveis), card some depois
  do match; WhatsApp Vendas lista a conversa e envia mensagem manual (bolha
  aparece na thread); super_admin vê o card novo de Configurações
  ("credenciais preenchidas") e o seletor "Vendedor" em Usuários; consultor
  não vê mais o link "💰 Vendas" na nav e recebe 403 tentando acessar
  `admin/vendas.php` direto pela URL. Migração testada idempotente
  (rodada 2x sem efeito colateral) preservando uma negociação MANUAL já
  existente no banco (fluxo antigo intocado). **Bug real achado no próprio
  teste Playwright, antes de qualquer commit**: `admin/venda.php`/
  `admin/vendas.php` chamavam `e($v['veiculo_ano'])` direto — com o `JOIN`
  virando `LEFT JOIN` (oportunidade pode ser `NULL` agora), isso é
  `TypeError` em PHP 8.1+ (`e(string $str)` não aceita `null`), quebrando
  a página inteira com 500 assim que um lead sem veículo vinculado era
  aberto; corrigido com `(string)($v['veiculo_ano'] ?? '')` nos 3 pontos
  afetados (`admin/venda.php` × 2, `admin/vendas.php` × 1).
  **3ª etapa do módulo — catálogo de foto/vídeo + IA manda mídia sozinha +
  prompt de negociação** (mesmo dia, pergunta de acompanhamento José/Jean:
  "como vamos treinar ela para negociação - ela precisa enviar fotos do
  veículos - vídeo fazer que abraço pro vendedor so ligar fechar") — 3
  pedaços:
  (1) **Catálogo de mídia por veículo** (`veiculo_midias_revenda`, nova
  tabela — `oportunidade_id`, `tipo` foto/vídeo, `mime`, `drive_file_id`/
  `arquivo_url`, `legenda`) — fica ligado ao VEÍCULO (`oportunidades.id`),
  não a uma negociação específica: cadastra 1 vez, disponível pra qualquer
  tentativa de venda futura do mesmo carro (negociação cancelada + reaberta
  com outro comprador reaproveita sem reupload). ⚠️ **Decisão assumida, não
  confirmada com o Jean**: fotos são upload NOVO/deliberado do vendedor
  (card "📸 Fotos e vídeos pra revenda" em `admin/venda.php`, só aparece
  quando `oportunidade_id` já está vinculado), nunca reaproveitadas
  automaticamente da foto que o vendedor ORIGINAL mandou durante a
  qualificação de COMPRA (`whatsapp_mensagens`) — regra #3 aplicada aqui
  também: carro pode ter sido lavado/reformado/rodado km desde a compra, a
  foto antiga podia não refletir mais a realidade; sinalizar se o Jean
  preferir também aproveitar as fotos antigas como ponto de partida.
  `salvarMidiaRevenda()`/`listarMidiasRevenda()`/`excluirMidiaRevenda()`
  (`includes/vendas.php`) reaproveitam o MESMO destino Drive/local já
  usado pros documentos de compra daquele veículo
  (`salvarArquivoGeradoComoDocumento()`, subpasta "revenda" no fallback) —
  sem pasta nova no Drive. Teto de tamanho próprio, maior que o de
  documento comum: 10MB foto / 50MB vídeo (`VEICULO_MIDIA_MAX_BYTES_FOTO`/
  `_VIDEO`). `admin/ver_midia_revenda.php` (novo) serve o arquivo, travado
  por `requireAcessoVendas()` (não por responsável — a mídia é do veículo,
  compartilhada entre qualquer vendedor).
  (2) **IA manda a mídia sozinha pro comprador** — `zapiEnviarVideo()`
  (novo, `includes/whatsapp_config.php`, mesmo formato de
  `zapiEnviarImagem()` — `POST /send-video`, campo `video`, nunca
  confirmado contra instância real ainda) + `zapiEnviarImagem()` ganhou o
  mesmo parâmetro `$instanciaOverride` que `zapiEnviarTexto()` já tinha.
  Extração da IA (`includes/ia_qualificacao_vendas.php`) ganhou
  `oportunidade_id_sugerida` — só preenchido quando a IA tem certeza real
  de QUAL veículo específico da frota bate com o interesse do comprador
  (nunca "chuta" entre vários candidatos); pra isso funcionar sem a IA
  "inventar" um id, o prompt de EXTRAÇÃO (só esse, nunca o de conversa)
  recebe a frota com `[ID x]` na frente de cada item
  (`iaVendasMontarResumoFrotaComId()`) — a IA nunca fala esse número pro
  comprador, é só referência interna. Quando `oportunidade_id_sugerida`
  aparece e ainda não foi mandado nada pra esse veículo NESTA conversa
  (`vendas.midia_sugerida_enviada_para`, nova coluna — evita floodar a
  cada turno), `enviarMidiaCatalogoParaComprador()` manda até 3 fotos +
  o 1º vídeo do catálogo (se tiver), como data URI base64 (lê os bytes de
  verdade via `lerConteudoArquivoDocumento()`, nunca precisa de URL
  pública pra pasta do Drive/uploads) pela instância DEDICADA de vendas.
  Envio SEM mídia cadastrada pro veículo simplesmente não manda nada
  (nunca inventa/promete foto que não existe) — sem quebrar o turno. Mandar
  a foto **nunca** vincula `vendas.oportunidade_id` sozinho — isso continua
  100% humano (`vincularVeiculoVenda()`), mandar a foto é só ilustrar a
  conversa. Mensagem curta (ex: "[📷 3 foto(s) 🎥 vídeo do Chevrolet Onix
  2019 enviado(s)]") fica registrada no histórico pro vendedor ver que já
  saiu, mesmo sem preview inline no WhatsApp Box de vendas ainda (escopo
  cortado por tempo, mesmo espírito de "evitar over-engineering" — o
  vendedor vê as fotos de verdade no card do catálogo em `admin/venda.php`).
  (3) **Prompt reforçado com técnica de negociação** — pedido explícito
  ("treinar ela para negociação"): depois de identificar o veículo certo,
  a IA agora OFERECE ativamente mandar foto/vídeo em vez de esperar ser
  pedida; responde objeção de preço citando o valor de referência + os
  diferenciais reais (procedência, documentação regularizada) em vez de só
  desviar pro vendedor; e puxa o fechamento assim que sente interesse real
  (confirma que o vendedor vai ligar pra fechar detalhes/agendar
  visita/test drive). Regra nova explícita: NUNCA inventar urgência/
  escassez falsa ("só temos até amanhã", "outra pessoa também quer") —
  pressão inventada quebra confiança e contraria a regra #3 (nunca
  inventar), mesmo sendo uma "técnica de venda" comum — só pode mencionar
  isso se for informação real. `iaGerarResumoVenda()` (geração do resumo
  pro vendedor) ganhou uma linha de fechamento: "🔥 PRONTO PRA FECHAR"
  quando o comprador já confirmou interesse num veículo específico, viu
  foto/vídeo e tem forma de pagamento definida, vs "🌱 PRECISA DE MAIS
  CONVERSA" — dá pro vendedor saber de cara, sem ler tudo, se é só ligar e
  fechar ou se ainda precisa nutrir o lead.
  Testado ponta a ponta: **função** — driver isolado fazendo upload real
  de uma foto (JPEG gerado via GD) pro catálogo via `salvarMidiaRevenda()`,
  depois simulando 5 turnos de conversa onde a IA identifica o veículo
  certo (`[ID x]` extraído do prompt real, batendo com o id verdadeiro no
  banco) e manda a foto — confirmado no log do fake Z-API que
  `send-image` foi chamado exatamente 1 vez (nunca reenviou nos turnos
  seguintes) pela instância de vendas (nunca vazando credencial de
  compra), `midia_sugerida_enviada_para` gravado certo, mensagem
  descritiva no histórico. **UI** via Playwright: card de catálogo só
  aparece depois do veículo vinculado; upload de PNG real funciona,
  aparece na galeria com legenda; thumbnail serve via
  `admin/ver_midia_revenda.php` com `Content-Type` certo (200); remover
  mídia funciona e volta pro estado "vazio". `tests/smoke.php` e migração
  (idempotente, rodada contra banco já migrado da etapa anterior)
  continuam limpos.
  **4ª etapa — parâmetros de orçamento e uso pretendido na qualificação do
  comprador** (17/09/2026, "qual a entrada valor da entrada que você tem,
  valor da parcela em seu orçamentos, tipo de carro para passeio o
  aplicativo utilitário, vendemos carro na promissória, nunca revelar
  dados como financiamento do veiculo") — `includes/ia_qualificacao_vendas.php`:
  (1) o prompt de conversa passou a perguntar, com naturalidade e sem
  insistir se a pessoa não quiser dizer, quanto ela TEM de entrada e
  quanto CABE no orçamento dela de parcela mensal, além de classificar o
  uso pretendido do veículo em exatamente 3 categorias — passeio (uso
  pessoal), aplicativo (Uber/99/entrega) ou utilitário (carga/trabalho);
  (2) **promissória** entrou como 4ª forma de pagamento que a Fastcar
  oferece, mencionada ao lado de à vista/financiado/entrada+parcelas —
  tanto no prompt de conversa quanto na descrição do campo
  `forma_pagamento_pretendida` no prompt de extração; (3) regra nova e
  explícita em "REGRAS QUE NÃO PODEM SER QUEBRADAS": a IA **nunca**
  revela nem comenta dado de FINANCIAMENTO do veículo (banco, valor de
  parcela, saldo devedor, se estava financiado quando a Fastcar comprou)
  — isso é informação interna do negócio com o vendedor ORIGINAL, nunca
  do comprador de agora; se perguntarem ("esse carro tinha
  financiamento?", "de onde veio?"), a IA desvia pra procedência/
  documentação regularizada sem confirmar nem negar detalhe nenhum da
  origem (defesa em profundidade — `listarFrotaDisponivelParaVenda()`,
  `includes/vendas.php`, já nunca expôs esses campos pro prompt, mas a
  regra cobre o caso de a pessoa perguntar diretamente). 3 colunas novas
  em `vendas` (`tipo_uso_veiculo`, `valor_entrada_disponivel`,
  `valor_parcela_orcamento`, todas nullable/sem valor chutado — regra #3)
  extraídas e aplicadas em `iaAplicarDadosExtraidosVenda()` com a mesma
  disciplina **fill-if-empty** do resto do projeto — rodar a extração de
  novo nunca sobrescreve um valor de entrada/parcela/uso já capturado
  antes, mesmo que a IA "leia" algo diferente num turno seguinte.
  `iaGerarResumoVenda()` (resumo pro vendedor que assume) passou a cobrir
  os campos novos no checklist. `admin/venda.php` — card "🤖 Lead
  qualificado por IA" ganhou "Uso pretendido" (label bonito por categoria)
  e "Orçamento" (entrada + parcela formatados em R$, ou "não informada"
  quando falta). Mudança de prompt (julgamento de IA) não é testável
  contra servidor fake pro TEXTO da conversa em si — validação real só na
  próxima conversa de verdade — mas a extração/aplicação/resumo/UI SÃO
  testáveis e foram: função isolada (extração dos 3 campos novos certa
  contra Gemini fake — `tipo_uso_veiculo`/`valor_entrada_disponivel`/
  `valor_parcela_orcamento`; fill-if-empty confirmado não sobrescrevendo
  valor já extraído mesmo com dado novo diferente chegando; resumo
  menciona entrada/parcela; prompt de sistema contém a regra de nunca
  revelar financiamento e menciona promissória) + Playwright (card do
  vendedor mostra "Uso pretendido: Aplicativo (Uber/99/entrega)",
  "entrada R$ 8.000,00", "parcela até R$ 950,50" formatados certos) +
  migração testada idempotente preservando uma venda já existente sem as
  colunas novas (rodada 2x sem efeito colateral).
- **Paginação nas listagens do admin** — `includes/paginacao.php`
  (13/09/2026, pergunta direta "quantas negociações ficar na tela, já
  pensou nisso?"; resposta honesta foi não, e achou de quebra um bug real:
  `admin/clientes.php` tinha `LIMIT 100` **sem paginação nenhuma** — cliente
  além do 100º, por `created_at DESC`, simplesmente sumia da tela sem
  aviso): `paginaAtual()`/`paginacaoOffset()`/`renderPaginacao()`
  compartilhados, 25 registros por página (`ITENS_POR_PAGINA_PADRAO`),
  preserva os outros parâmetros da URL (busca/etapa/q) nos links de
  anterior/próxima. Aplicado em `admin/index.php`, `admin/clientes.php` e
  `admin/veiculos.php` — cada um com uma query `COUNT(*)` própria pro total
  real, e os cards de estatística que resumem o conjunto inteiro (total
  pago, veículos na frota) vindo de uma query `SUM`/`COUNT` separada, nunca
  de `array_sum()`/`count()` em cima do array já limitado a 25 linhas da
  página atual (mesma classe de bug do `LIMIT 100`, corrigida antes de virar
  problema de verdade).
- **Módulo de formulário/documentos** — `public/documentos.php` (link com
  token, sem login): desde 13/09/2026 é um **wizard passo a passo** (CNH →
  comprovante de endereço → contrato de financiamento → resumo final), não
  mais 1 formulário só. Motivo: o formulário antigo deixava o cliente
  digitar nome/CPF/endereço à mão sem ninguém checar, indo reto pro
  contrato se o consultor não abrisse a CNH pra conferir. Agora, a cada
  upload, `includes/extracao_documentos.php` manda o documento pro Gemini
  multimodal (`geminiCallComMidia()`, mesma função do WhatsApp — `inlineData`
  lê `image/*` **e `application/pdf` nativamente, sem OCR/biblioteca de
  PDF**, mesmo mecanismo já validado em produção no JurídicoSaaS via
  `api/financeiro-ler-comprovante.php`) e pré-preenche os campos; o cliente
  só revisa/corrige e confirma **um documento por vez** antes de avançar
  (`oportunidade_documentos.dados_confirmados`) — a etapa do wizard é
  sempre derivada do banco (nunca sessão/cookie), então o cliente pode
  fechar a aba e voltar pelo link dias depois, de outro aparelho, de onde
  parou. Preenchimento é **fill-if-empty** (nunca sobrescreve dado que já
  existia — nem o que o próprio cliente confirmou numa etapa anterior do
  wizard, nem o que a qualificação por IA do bloco 3 já capturou via
  WhatsApp, tratado como fonte confiável), mas quando o documento
  **contradiz** o que já estava cadastrado (ex: contrato de financiamento
  de um veículo diferente do que o WhatsApp já tinha capturado, comprovante
  com nome de outra pessoa), `compararDivergenciasDocumento()` aponta a
  diferença na hora pro cliente E grava em `oportunidade_historico` — nunca
  bloqueia (decisão de negócio é sempre do consultor, regra #3), só garante
  que não passa batido se o cliente só clicar "avançar" sem prestar
  atenção. `?revisar=tipo` deixa voltar numa etapa já confirmada mesmo
  depois do resumo final; corrigir algo depois de já ter confirmado tudo
  zera `oportunidades.documentos_confirmados_em` de novo, forçando o
  consultor a olhar uma 2ª vez antes de gerar o contrato. Anexo manual do
  consultor (`admin/oportunidade.php`, ex: cliente mandou foto pelo
  WhatsApp) roda a mesma extração. Sem chave Gemini configurada, a
  extração simplesmente não roda (mesma limitação do áudio/imagem do
  WhatsApp — só Gemini é multimodal aqui) e o cliente preenche manualmente,
  nunca trava o wizard. Arquivos vão pro **Google Drive**
  (`includes/google_drive.php`, service account, mesmo padrão do
  JurídicoSaaS: pasta raiz "Fastcar" → subpasta por cliente → arquivos),
  com `storage/uploads/` local como fallback só se o Drive não estiver
  configurado ou uma chamada falhar; `includes/documentos.php::lerConteudoArquivoDocumento()`
  lê os bytes de qualquer um dos dois destinos, compartilhado entre servir
  pro navegador (`servirArquivoDriveOuLocal()`) e mandar pro Gemini.
  **Visual com a marca da Fastcar** (13/09/2026, primeira versão real do
  wizard testada pelo José — "bem feio" no CSS genérico anterior): cabeçalho
  fixo azul-marinho (`#0a1229`→`#101d40`) com o wordmark "Fast**Car**" +
  "Soluções Financeiras", cartões brancos com sombra, botão em gradiente
  azul — paleta tirada da logo real recebida (ainda falta o arquivo de
  verdade, ver seção de módulos acima; `<img src="/public/assets/logo.png">`
  já está no lugar certo pra aparecer sozinho assim que o arquivo for
  colocado lá, com fallback pro texto estilizado enquanto não existir). A
  faixa escura fica isolada só no cabeçalho (elemento próprio, full-bleed) —
  nunca embaixo de texto de conteúdo: bug real corrigido no meio do teste
  (um gradiente de corte fixo em pixel no `body` deixava o texto "Etapa X de
  Y" ilegível — texto escuro sobre fundo escuro — dependendo de quanto
  conteúdo caía dentro da faixa). Conferido visualmente via screenshot
  (Playwright/Chromium) na etapa 1 e no resumo final antes de considerar
  pronto, não só "compilou sem erro".
  **4ª etapa — CRLV** (16/09/2026, "falta o documento do carro crlv"):
  o próprio contrato-mestre de compra (cláusula 2.2, transcrita do modelo
  real em `includes/contratos_pdf.php`) já lista "CRLV-e" entre os
  documentos que o vendedor precisa entregar, junto do contrato de
  financiamento — faltava só o passo de coleta no wizard. `ORDEM_ETAPAS`
  ganhou `'crlv'` como 4º passo, **depois** do contrato de financiamento de
  propósito: os dois documentam o mesmo veículo (placa/renavam/chassi/
  marca/modelo/ano), então a etapa de CRLV já chega com esses campos
  pré-preenchidos (fill-if-empty, mesmo mecanismo de sempre) da etapa
  anterior — o cliente só confere, não digita de novo. Extração por IA
  (`includes/extracao_documentos.php`) reaproveita os mesmos 6 campos de
  veículo já usados pelo contrato de financiamento (`EXTRACAO_DOCUMENTO_CAMPOS['crlv']`),
  nenhuma mudança precisou em `aplicarDadosExtraidosDocumento()`/
  `compararDivergenciasDocumento()` (já genéricas por campo, não por tipo
  de documento). `TIPOS_DOCUMENTOS_CLIENTE` (`includes/documentos.php`)
  ganhou a entrada — automaticamente aparece também no card de anexo
  manual do consultor em `admin/oportunidade.php` (itera a constante, sem
  precisar de mudança nenhuma lá) e entra no checklist de fechamento
  (regra #7, `checklistFechamentoCompleto()`, já genérico por linha da
  tabela). **Self-heal automático pra quem já tinha terminado o wizard
  antes dessa mudança**: a etapa atual do wizard é sempre derivada do
  banco a cada carregamento de página (nunca de sessão), então um cliente
  que já tinha confirmado tudo e recebido a tela de "Tudo certo!" volta a
  cair automaticamente na etapa de CRLV na próxima vez que abrir o mesmo
  link — sem precisar de nenhuma migração/script pra "retroagir" pedido
  de CRLV nas oportunidades já em andamento. Testado ponta a ponta: banco
  isolado com uma oportunidade simulando exatamente esse caso (CNH/
  comprovante/contrato já confirmados ANTES do CRLV existir, veículo já
  com placa/marca/modelo cadastrados) — abrir o link corretamente mostra
  "Etapa 4 de 4 — CRLV"; upload avança pra revisão já com marca/placa
  pré-preenchidos do contrato de financiamento; confirmar chega no resumo
  final com o link "CRLV" na lista de revisão; linha `oportunidade_documentos`
  gravada com `obrigatorio=1`/`dados_confirmados=1`, entrando certo no
  checklist de fechamento junto dos outros documentos obrigatórios.
  **Rótulo do passo "CNH" deixa explícito que RG também serve**
  (17/09/2026, "wirzad pede cnh/Rg pois tem gente não tem cnh") — o
  rótulo dizia só "CNH (frente e verso, ou documento com foto)", CNH em
  destaque primeiro e o RG só implícito num detalhe pequeno, dando a
  impressão de que CNH era obrigatória pra quem não tem. Os dados já
  eram coletados certos por baixo (RG é campo separado na tela de
  revisão, e a CNH já era opcional lá — "Nº da CNH (se tiver)") — só o
  rótulo do PASSO DE UPLOAD não deixava isso óbvio de cara. Trocado pra
  "CNH ou RG (documento de identidade com foto, frente e verso)" nos 2
  lugares onde o rótulo é definido (`public/documentos.php` e
  `includes/documentos.php::TIPOS_DOCUMENTOS_CLIENTE` — duplicado de
  propósito desde a implementação original, atualizados juntos pra não
  dessincronizar entre o wizard do cliente e a tela do consultor), e os
  links "Voltar na CNH" da tela de resumo/conclusão viraram "CNH/RG". A
  IA de extração (`includes/extracao_documentos.php`) já lia "CNH ou
  documento de identidade com foto" desde sempre — nenhuma mudança
  precisou lá. Testado servindo a página real em banco isolado: "Etapa 1
  de 4 — envie: CNH ou RG (documento de identidade com foto, frente e
  verso)" aparece certo no HTML renderizado.
  **Boleto rejeitado como comprovante de endereço** (18/09/2026, achado
  real do usuário: "quero colocar um comprovante de residência como
  boleto e nao consigo") — a autochecagem de tipo do documento (fix de
  17/09/2026, "IA identifica o TIPO do documento antes de extrair") usa
  uma descrição do que é esperado em cada slot pra IA se autocorrigir; a
  descrição de `comprovante_endereco`
  (`EXTRACAO_DOCUMENTO_ESPERADO_DESCRICAO`, `includes/extracao_documentos.php`)
  só citava "conta de luz, água, telefone, internet" como exemplo — um
  boleto (de condomínio, cartão, financiamento etc., muito comum como
  comprovante de residência no Brasil) não batia com nenhum desses
  exemplos explícitos, então a IA podia concluir (errado) que não era o
  documento certo e disparar o aviso forte de tipo divergente; o arquivo
  já era salvo mesmo assim (o bloqueio nunca foi no upload em si), mas na
  prática o cliente via um alerta vermelho dizendo que o arquivo estava
  errado e ficava sem saber que podia simplesmente digitar o endereço e
  seguir. Corrigido generalizando a descrição e o prompt de extração pra
  deixar explícito que qualquer conta, fatura OU BOLETO conta como
  comprovante de endereço válido, desde que mostre um endereço — o
  critério real nunca foi "precisa ser uma dessas 4 contas específicas".
  Rótulos visíveis ao cliente (`includes/documentos.php::TIPOS_DOCUMENTOS_CLIENTE`,
  `public/documentos.php`) também ficaram mais explícitos ("conta ou
  boleto de luz, água, internet, condomínio etc."), mesmo espírito do fix
  de rótulo CNH/RG de 17/09/2026. Mudança de prompt (julgamento de IA)
  não é 100% testável contra servidor fake pra confirmar que a IA vai
  aceitar um boleto real de verdade — validação final só na próxima
  conversa/upload real —, mas testado: função isolada confirmando que o
  texto novo menciona "BOLETO" explicitamente, e o pipeline completo
  (`extrairDadosDocumentoComIA()` → parsing do JSON → `_documento_correto`)
  rodando normal simulando a IA reconhecendo um boleto como comprovante de
  endereço válido e extraindo o endereço certo.
  **Comprovante de pagamento e laudo de avaliação viraram opcionais**
  (17/09/2026, "vamos deixar opcional o laudo e comprovante de pagamento
  opcional para fechar pasta"), motivo de negócio explicado no mesmo dia
  ("como ficou obrigatório pagamento as vezes pix outro pix nen todo
  veiculo laudo") — forma de pagamento varia (PIX de contas diferentes,
  sem padrão fixo pra anexar comprovante) e nem todo veículo passa por
  avaliação formal com laudo, então travar o fechamento (regra #7) por
  esses 2 documentos específicos não reflete como a operação funciona de
  verdade. Nova `TIPOS_DOCUMENTOS_FECHAMENTO_OPCIONAIS`
  (`includes/documentos.php`) — `garantirLinhasDocumentosObrigatorios()`
  passou a gravar `comprovante_pagamento`/`laudo_avaliacao` com
  `obrigatorio=0`; os outros 4 tipos (CNH/comprovante de endereço/
  contrato de financiamento/CRLV do cliente + contrato de compra da
  própria pasta) continuam obrigatórios como sempre. `admin/oportunidade.php`
  mostra "(opcional)" no rótulo e um badge neutro "— opcional, não
  enviado" em vez do alarme vermelho "⏳ pendente" pra esses 2. Migração
  em `install/migrar.php` corrige linhas de `oportunidade_documentos` JÁ
  CRIADAS antes dessa mudança — `garantirLinhasDocumentosObrigatorios()`
  só faz `INSERT OR IGNORE`, nunca atualiza linha existente, então sem a
  migração uma oportunidade em andamento continuaria travada com o
  `obrigatorio=1` antigo pra sempre. Testado em banco isolado: oportunidade
  nova já nasce com os 2 tipos em `obrigatorio=0` (resto continua 1);
  checklist fecha completo preenchendo só os 4 obrigatórios de verdade,
  sem laudo/comprovante; cenário simulando oportunidade "antiga" (linhas
  já existentes com `obrigatorio=1`, como estava antes dessa mudança) fica
  bloqueada ANTES da migração e libera certo DEPOIS, rodando a migração
  2x sem efeito colateral (idempotente).
  **"Confirmar em nome do cliente" (17/09/2026, achado real de operação —
  José: "tem cliente tem dificuldade de preencher o wirzad - proprio
  consultor sobe os documentos ja da aceite... maioria das vezes consultor
  sobe a documentação")**: na prática, boa parte dos clientes não consegue/
  não usa o wizard — o consultor recebe a foto do documento no WhatsApp
  pessoal dele e anexa direto pela tela (`admin/oportunidade.php`, ação
  `upload_documento_staff`, já existia). Só que `dados_confirmados` (o
  campo que tira o alarme "📝 enviado, aguardando cliente confirmar dados")
  só era gravado pelo PRÓPRIO wizard (`public/documentos.php::confirmar_etapa`),
  então um documento anexado pelo consultor ficava preso NESSE alarme
  pra sempre — mesmo já revisado de verdade — porque o cliente nunca ia
  abrir o link. Confirmado ANTES de implementar que isso não bloqueava o
  fechamento de verdade (`checklistFechamentoCompleto()`,
  `includes/oportunidades.php`, só olha se o arquivo existe, nunca
  `dados_confirmados`), só deixava a tela com um aviso permanente e
  enganoso. Nova ação `confirmar_documento_staff` (mesmo arquivo) — botão
  "✅ Confirmar em nome do cliente" ao lado do alarme, só quando o
  documento já tem arquivo e ainda não foi confirmado; grava
  `dados_confirmados=1` pro tipo escolhido, e se essa era a ÚLTIMA
  pendência entre os 4 tipos do cliente, também grava
  `oportunidades.documentos_confirmados_em` (mesmo sinal "tudo revisado"
  que o wizard grava ao finalizar) — mesma disciplina de "não marcar
  concluído cedo demais" do resto do checklist. Nunca reescreve
  marca/modelo/banco/etc sozinho — esses campos já são editáveis direto
  pelos cards "Dados do veículo"/"Financiamento" desta mesma tela (o
  consultor corrige lá, essa ação só marca "já revisei, tá certo").
  Banner "✅ Cliente confirmou os dados..." reescrito pra "✅ Dados e
  documentos confirmados... (pelo cliente via wizard, ou pela equipe em
  nome dele quando ele não conseguiu usar o link)" — honestidade sobre
  quem confirmou de fato, já que agora pode ser qualquer um dos dois.
  Mesma trava de supervisor (só acompanha) do resto da tela. Testado em
  banco isolado + Playwright: documento anexado pelo consultor (`enviado_pelo_cliente=0`)
  mostra o botão novo; clicar confirma e o alarme some; banner de "tudo
  confirmado" só aparece depois que os 4 tipos do cliente estão
  confirmados (testado a query isoladamente com os outros 3 já
  confirmados — 0 pendentes, dispararia a gravação de
  `documentos_confirmados_em`).
  **IA identifica o TIPO do documento antes de extrair, pra nunca
  contaminar o cadastro com dado de outro tipo de documento** (mesmo dia,
  achado real de operação — José: "então o consultor subiu documento do
  carro no lugar da cnh kk se acredita permita substutir documento
  bloquear indetificar documento"). Antes disso, a extração
  (`includes/extracao_documentos.php`) confiava cegamente que o arquivo no
  slot "CNH" era mesmo uma CNH — um CRLV (documento do veículo, que também
  tem "nome do proprietário") anexado por engano nesse slot podia fazer a
  IA "achar" um nome de verdade (o do dono ANTERIOR do carro) e preenchê-lo
  no cadastro do cliente via fill-if-empty, sem ninguém perceber, porque o
  campo estava vazio antes. Os 4 prompts (`extracaoDocumentoPrompt()`)
  ganharam uma AUTOCHECAGEM: antes de extrair qualquer campo, a IA
  responde `"parece_ser_esse_documento"` (bool) — false quando o arquivo
  claramente não é o tipo esperado pro slot — e `"tipo_real_se_diferente"`
  (o que ela acha que é de verdade, ex: "CRLV (documento do veículo)").
  `extrairDadosDocumentoComIA()` devolve isso como `_documento_correto`/
  `_tipo_real_se_diferente` (prefixo `_` de propósito — nunca colide com
  nome de campo real, `aplicarDadosExtraidosDocumento()` ignora
  automaticamente qualquer chave que não reconhece). **"Bloquear"**: nos 2
  pontos onde a extração roda (`public/documentos.php`, wizard do cliente,
  E `admin/oportunidade.php`, anexo manual do consultor) — quando
  `_documento_correto` é false, `aplicarDadosExtraidosDocumento()` NUNCA é
  chamada (nenhum campo é gravado, nem os que por acaso vieram
  preenchidos) — testado justamente o pior caso, a IA "achando" um nome
  mesmo marcando o documento como errado, confirmando que mesmo assim nada
  é aplicado. **"Identificar"**: aviso forte (classe `alerta-erro`, mais
  chamativo que o `alerta-info` de divergência de dado comum) explica o
  que aconteceu — "Esse arquivo não parece ser CNH ou RG... parece ser
  CRLV (documento do veículo)" — no wizard e, do lado do consultor, no
  lugar da mensagem de sucesso muda de sempre. Fica registrado em
  `oportunidade_historico` também (mesmo padrão de divergência de dado já
  existente). **"Permitir substituir"**: o arquivo (mesmo errado) já É
  salvo normalmente — reenviar o mesmo tipo sempre sobrescreve (já existia,
  `ON CONFLICT DO UPDATE` em `salvarUploadDocumento()`); o wizard ganhou um
  `<details>` novo "Enviou o arquivo errado? Envie outro no lugar" bem na
  tela de revisão, com upload direto, sem precisar voltar etapa — antes
  disso não tinha nenhum jeito de trocar o arquivo a partir dessa tela, só
  editar os campos de texto. Nunca falso-bloqueia por falta de sinal — IA
  que não respondeu o campo de autochecagem (falha de parse, por exemplo)
  conta como "correto" (`_documento_correto` default true), documento
  sem chave Gemini configurada segue sem checagem nenhuma, igual sempre foi.
  Testado ponta a ponta: função (`extrairDadosDocumentoComIA()` isolada
  contra Gemini fake, confirmando `_documento_correto=false` +
  `_tipo_real_se_diferente` certos pro caso de mismatch, e o caminho normal
  intacto pro caso de documento certo) + Playwright nos 2 pontos de upload
  (wizard: aviso aparece, campo `nome` fica vazio mesmo a IA "achando" um
  nome, botão de substituir aparece; admin: aviso aparece no lugar do
  "Documento anexado." de sempre).
- **Módulo de contrato (só COMPRA)** — `includes/contratos.php` +
  `includes/contratos_pdf.php` (PDF via FPDF puro, sem LibreOffice/Composer —
  shared hosting não teria isso — transcrito do modelo real
  `01_Contrato_Mestre_FASTCAR_Compra_Quitacao_Futura.docx`).
  **Endereço da sede corrigido em 13/09/2026** (o modelo original trazia um
  endereço genérico de Santana de Parnaíba/SP): sede real é **Av.
  Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo
  Alpha Square Offices, Alphaville Conde II, Barueri/SP, CEP 06473-073**
  (confirmado pelo José) — corrigido nos 4 lugares do PDF que citavam a
  cidade/endereço antigos: cláusula de qualificação das partes (abertura),
  cidade da linha de assinatura, foro de eleição (cláusula 29.2) e um
  rodapé novo (nome/CNPJ/endereço em letra pequena) logo após o bloco de
  testemunhas — pedido explícito ("coloca no rodapé do contrato"). O mesmo
  endereço também aparece no rodapé do **wizard de documentos**
  (`public/documentos.php`, wizard e tela de link inválido) — pedido
  separado ("coloca no rodapé endereço nos links que enviar para
  cliente"), reforça pro cliente que o link não é golpe (mesma
  preocupação já coberta no prompt de qualificação da IA sobre
  desconfiança com dado financeiro por WhatsApp).
  **Link do wizard enviado como imagem+legenda, não texto puro** (mesmo
  dia, "link enviado para cliente no whatsap vai como templade com imagem
  e texto" / "puxa logo da fastcar bem caprichado nesse templade"):
  `includes/whatsapp_config.php::zapiEnviarImagem()` (novo, `POST
  /send-image` — `image` URL pública + `caption`, formato confirmado via
  busca na documentação oficial Z-API) manda a logo (`public/assets/logo.png`)
  como capa da mensagem com o link na legenda, sempre que já tiver logo
  configurada (`marcaLogoConfigurada()`); sem logo ainda, cai no texto puro
  de sempre (`zapiEnviarTexto()`) — nunca trava o envio do link por causa
  disso. Testado contra servidor Z-API fake local confirmando o payload
  exato (`phone`/`image`/`caption`) batendo com o formato documentado. O
  rodapé com endereço (wizard e tela de link inválido) ganhou acabamento
  na cor da marca (azul, não cinza neutro) a pedido ("personaliza rodapé
  com cores fica legal") — borda superior em gradiente azul no wizard,
  "FASTCAR SOLUTIONS" em azul vivo no fundo escuro da tela de link
  inválido.
  **Bug real achado no mockup do Quadro-Resumo** ("tem bug no quadro
  resumo da operação tabela curta"): `_pdfLinhaResumo()` usava `Cell()` de
  altura fixa (6mm, sem quebra de linha) pras duas colunas — 3 dos 17
  campos (`Exploração econômica pela FASTCAR`, `Transferência final`,
  `Seguro/proteção durante posse FASTCAR`) têm valor mais longo que os
  85mm da célula, e o texto simplesmente vazava pra fora da borda em vez
  de quebrar linha — visualmente parecia a célula "curta demais" pro
  conteúdo (não é caso raro: são textos de negociação digitados livremente
  pelo consultor, `includes/oportunidades.php`, tendem a crescer).
  Corrigido: `_pdfLinhaResumo()` agora calcula ANTES de desenhar quantas
  linhas rótulo/valor vão precisar quebrando por palavra
  (`_pdfContarLinhas()`, mesma lógica de quebra que `MultiCell()` usa por
  baixo dos panos) na largura útil real, usa a maior contagem pra decidir
  a altura da linha, e desenha as duas células com `MultiCell()` em vez de
  `Cell()` — nunca mais estoura, cresce sozinho quando o texto precisa.
  Cuidado técnico: a medição usa o texto já convertido pra ISO-8859-1
  (`_pdfTexto()`), nunca o UTF-8 original — a tabela de largura de
  caractere do FPDF é por byte na fonte core, medir UTF-8 cru contaria
  acento como 2 "caracteres" e dava conta de quebra errada. Testado com
  PDF gerado de ponta a ponta: as 3 linhas que antes estouravam (91mm/
  104mm/90mm calculados via `GetStringWidth()`, > 85mm da célula) agora
  quebram corretamente em 2 linhas.
  **Logo real no cabeçalho do PDF** ("ficou bom só faltou logo topo pra
  fechar"): `_pdfCabecalho()` coloca `public/assets/logo.png` (a mesma
  gerada por `includes/marca.php` no upload em Configurações) no canto
  esquerdo da faixa navy, altura fixa 16mm com largura calculada
  automaticamente pelo FPDF preservando proporção; cor da faixa também
  ajustada pra bater com a marca (`#151722`, era um navy genérico antes).
  Sem logo ainda enviada — ou arquivo corrompido/formato que o FPDF não lê
  — o cabeçalho segue só com o texto "FASTCAR SOLUTIONS", igual sempre
  foi, nunca trava a geração do contrato por causa de imagem. Testado com
  logo de teste real (PNG com transparência) confirmando que entra sem
  erro, e os casos "sem logo" e "logo corrompida" confirmados gerando o
  PDF normalmente do mesmo jeito.
  Assinatura eletrônica via **ZapSign** (`includes/zapsign.php`, webhook
  `api/zapsign_webhook.php` + fallback de polling `cron/zapsign_sync.php` —
  substituiu a Assinafy em 13/09/2026, pedido do José/Jean).
  Fluxo confirmado com o Jean: cliente preenche dados pelo link do
  formulário → consultor confere → **consultor preenche os campos
  financeiros/de negociação só na hora de fechar o negócio** (bloco 6, mesma
  pessoa que atendeu desde a mesclagem consultor/closer, ver pendência #4) →
  dispara o contrato. Contrato de **VENDA** (Fastcar revende o carro) —
  implementado em 15/09/2026, ver bullet próprio "Módulo de vendas" acima.
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
  **Gerar só pra visualizar, sem enviar pra assinatura** (17/09/2026,
  "gerar contrato manual só para visualizar antes de enviar para
  cliente... conferir antes os dados"): até então o único botão ("Gerar
  contrato e enviar pra assinatura") já disparava a ZapSign na hora —
  sem nenhum jeito de conferir os dados mesclados (nome, veículo,
  valores, cláusulas) antes do cliente já ter recebido o link de
  assinatura de verdade. `gerarContratoCompraPreview()` (novo,
  `includes/contratos.php`) gera o PDF e salva a cópia (mesmo destino
  Drive/local de sempre) igual à função de envio, mas **nunca chama a
  ZapSign nem manda e-mail** — grava em `contratos` com `status='gerado'`
  (o schema já previa esse status desde o início, `CHECK (status IN
  ('gerado', 'enviado', ...))`, e a UI já sabia desenhar o badge "📄
  gerado" — só faltava um jeito de chegar nele). Botão novo "👁️ Gerar
  contrato (só visualizar)" ao lado do botão de envio em
  `admin/oportunidade.php`, mesma validação de campos obrigatórios da
  cláusula 27.2. Gerar de novo (ex: depois de corrigir um dado) cria uma
  **nova linha**, nunca sobrescreve a anterior — mesmo espírito de nunca
  perder histórico do resto do projeto; o botão de envio continua
  totalmente independente, gera sua própria cópia final na hora de
  mandar de verdade. Testado em banco isolado: preview funciona mesmo
  sem nenhuma credencial ZapSign configurada (prova que o caminho não
  depende dela pra nada), `zapsign_doc_token`/`zapsign_signer_token`/
  `sign_url` ficam vazios (nunca chamou a ZapSign), `status='gerado'`
  (nunca `'enviado'`), gerar 2x cria 2 linhas distintas, e campo
  obrigatório faltando bloqueia igual ao fluxo de envio.
  **Saldo do financiamento calculado automático** (17/09/2026, "pode
  calcular saldo do financiamento automático ao preencher o valor da
  parcela"): ao digitar `valor_parcela`/`parcelas_restantes` no card
  "Dados do veículo", `saldo_financiamento_atual` (card "Financiamento e
  contrato de compra", campo que alimenta o Quadro-Resumo) se preenche
  sozinho com o produto dos dois — estimativa simples, nunca exata (não
  desconta juros/amortização), por isso sempre editável: digitar algo
  diferente direto no campo de saldo faz o cálculo automático parar de
  mexer nele dali em diante (mesma regra de "sistema nunca sobrescreve o
  que já foi confirmado por humano" do resto da tela), mostrando/escondendo
  uma dica "🧮 calculado automaticamente... edite se for diferente"
  conforme o caso. Detecção de edição manual usa o evento nativo `input`
  do navegador — só dispara em digitação de verdade, nunca em reassignment
  via JS `.value=` — sem precisar de nenhuma flag extra além disso; uma
  oportunidade que já chega com saldo salvo nunca é sobrescrita, nem no
  carregamento nem editando a parcela depois. **Bug real achado no próprio
  teste Playwright, antes de qualquer commit**: a calculadora nova tinha
  sido inserida no fim do `<script>` existente sem perceber que ele inteiro
  está dentro de `<?php if (getConfig('placafipe_token')): ?>` (o mesmo
  bloco que só desenha o widget de busca de FIPE por placa) — sem token do
  PlacaFIPE configurado, o `<script>` inteiro simplesmente não era incluído
  na página e o cálculo de saldo não rodava, apesar de não ter nada a ver
  com FIPE (exatamente o ambiente de teste isolado, sem token nenhum
  configurado, que expôs o problema). Corrigido movendo a calculadora pra
  um `<script>` próprio, sempre renderizado, fora do `if`. Testado em banco
  isolado com Playwright: oportunidade sem saldo prévio (parcela=800,
  parcelas=10) calcula e mostra 8000.00 com a dica visível; editar o campo
  de saldo manualmente (12345.67) esconde a dica e uma nova mudança na
  parcela (999) não recalcula mais, mantendo o valor digitado; oportunidade
  já com saldo salvo (9999.99) carrega esse valor certo e permanece
  intocado mesmo editando a parcela depois.
  **Generalizada pros 3 sentidos + roda no carregamento da página**
  (mesmo dia, achado real em produção: print mostrando parcela=872,67 e
  parcelas=42 já salvos no card "Dados do veículo", mas saldo vazio no
  card "Financiamento" — "fazer calculo deu erro"; e pedido de
  acompanhamento "ao digitar valor da parcela calcular parcelas restante
  enteu preencher campo parcela restantes"). Causa raiz do "cálculo não
  aconteceu": a 1ª versão só calculava quando o usuário digitava DIRETO
  nos campos (evento `input`); no fluxo real do consultor — preencher
  parcela+parcelas no card "Dados do veículo", clicar "Salvar dados do
  veículo" (form separado do card "Financiamento") — a página recarrega e
  os dois campos vêm PRÉ-PREENCHIDOS do servidor, sem disparar `input`
  nenhum, então o saldo nunca calculava sozinho, exatamente o vazio visto
  no print. Reescrita numa regra só, sem precisar de flag de "editado
  manualmente" separada: sempre que exatamente 1 dos 3 campos
  (`valor_parcela`/`parcelas_restantes`/`saldo_financiamento_atual`)
  estiver vazio e os outros 2 tiverem valor válido, calcula e preenche o
  vazio — parcela×parcelas=saldo, saldo÷parcela=parcelas restantes, OU
  saldo÷parcelas=parcela; com os 3 já preenchidos não sobra "vazio" pra
  calcular, então um saldo customizado nunca é sobrescrito (mesmo
  comportamento de antes, agora decorrência natural da regra em vez de
  caso especial). Chamada 1x já no carregamento da página, além de a cada
  digitação — cobre o caso real que faltava. Dica "🧮 calculado
  automaticamente" replicada nos 3 campos (não só no saldo). Testado em
  banco isolado com Playwright: oportunidade com parcela+parcelas já
  salvos calcula o saldo sozinho já no carregamento, sem digitar nada
  (872,67×42=36652,14); oportunidade com parcela+saldo já salvos calcula
  parcelas restantes sozinha no carregamento (5000÷500=10); oportunidade
  com os 3 já preenchidos e saldo customizado (9999,99, não bate com o
  produto) nunca é sobrescrita mesmo editando a parcela depois; fluxo do
  zero (digitar parcela, depois parcelas restantes) continua calculando o
  saldo igual antes.
  **Erro 500 relatado ao salvar, não reproduzido**: no mesmo print, o
  usuário relatou erro 500 ao clicar "Salvar dados do contrato". Reproduzi
  em banco isolado com os MESMOS valores exatos do print (incluindo
  `encargos_texto` com vírgula, `saldo_financiamento_atual` vazio) contra
  PHP com `display_errors`/`error_reporting` no máximo — salvou normal
  (200, sucesso, valores conferidos no banco), nenhum erro PHP registrado.
  Não achei nenhum caminho de código nesse handler que explique um fatal
  error com esses dados — hipótese mais provável é a request ter batido
  bem na janela do auto-deploy (~1min entre `git pull` trocar os arquivos
  e `smoke.php` confirmar), coincidindo com os pushes desse mesmo dia; sem
  acesso a `storage/logs/`/log do PHP-FPM da VPS pra confirmar de verdade.
  Se acontecer de novo, pegar o texto exato do erro (ou os logs na VPS)
  antes de assumir causa — a reprodução isolada não encontrou nada.
  **Prazo pra quitação do financiamento deixou de ser fixo em 24 meses**
  (17/09/2026, pedido urgente: "prazo máximo para quitação do
  financiamento é de 12 a 18 meses podendo prolongar para 24 meses temos
  alterar urgente no contrato"). Achado revisando `contratos_pdf.php`: o
  Quadro-Resumo e as cláusulas 1.3/4.1/5ª (título e 5.1)/10.1/18.3 tinham
  "24 meses" **hardcoded** direto no texto — sem nenhum campo pra negociar
  por oportunidade, o normal da operação (12-18 meses) nunca tinha como
  ser refletido no contrato de verdade, só o teto absoluto sempre
  aparecia como se fosse o prazo real. Nova coluna
  `oportunidades.prazo_quitacao_meses` (nullable, sem `DEFAULT` de
  propósito — regra #3, nunca um número chutado) + campo "Prazo pra
  quitar o financiamento (meses)" no card "Financiamento e contrato de
  compra" (`admin/oportunidade.php`), hint "normal: 12 a 18", `max="24"`
  no input; salvar trava no servidor com `min(24, max(1, ...))` — nunca
  só confia no `max` do HTML, mesmo padrão já usado no prazo do contrato
  de venda (`admin/venda.php`). `clausulasContratoCompra()`
  (`includes/contratos_pdf.php`) ganhou parâmetro `$prazoMeses`,
  interpolado nas 5 cláusulas + Quadro-Resumo em vez do `24` fixo,
  sempre mantendo "nunca superior a 24 meses" como teto absoluto no texto
  (mesmo padrão do contrato de venda, que já era parametrizado desde a
  1ª versão — conferido que a cláusula 3.1 de lá já referenciava "o prazo
  indicado no Quadro-Resumo" corretamente, sem o mesmo bug, não precisou
  de mudança). Campo virou **obrigatório** pra gerar o contrato
  (`verificarCamposObrigatoriosContrato()`) — nunca deixa cair num 24
  chutado só porque ficou vazio; `montarCamposContratoCompra()` retorna
  `null` explicitamente quando não preenchido, pra essa validação
  funcionar de verdade. Testado em banco isolado: coluna nova existe no
  schema; oportunidade sem prazo preenchido bloqueia a geração do
  contrato com a mensagem certa; oportunidade com prazo=15 gera o PDF
  normalmente; PDF gerado decodificado (`gzuncompress` dos content
  streams do FPDF) confirmando "15 meses" no Quadro-Resumo E nas 5
  cláusulas, nenhuma sobrando com "24" fixo; teste HTTP direto
  confirmando que enviar 99 no campo salva 24 no banco (trava do servidor
  funcionando, não só o `max` do HTML).
  **Texto exato da frase ajustado** (mesmo dia, pedido direto: "a frase
  tem aparecer no contrato... Fica ajustado, entretanto, que o prazo
  supracitado será de até 18 (dezoito) meses, podendo ser
  excepcionalmente prorrogado por até 24 (vinte e quatro) meses") — a
  linha do Quadro-Resumo "Prazo pra quitação do financiamento" e a
  Cláusula 5ª (5.1) passaram a usar exatamente essa frase, com o valor de
  `prazo_quitacao_meses` por extenso. Nova `_extensoMeses()`
  (`includes/contratos_pdf.php`, tabela fechada 1-24 — o prazo nunca passa
  de 24, regra já travada) gera "18 (dezoito)" etc a partir do número
  salvo. Testado gerando o PDF com prazo=18 e decodificando o conteúdo:
  texto batendo exatamente nos 2 lugares (Quadro-Resumo e cláusula 5.1).
- **Identidade visual (logo/favicon/ícones PWA)** — `includes/marca.php`
  (13/09/2026, pedido do José/Jean depois de ver o wizard "bem feio" e
  pedir "coloca em Configurações pra subir logo, favicon e ícone PWA" em
  vez de mandar o arquivo por fora pra um dev trocar na mão a cada deploy).
  1 upload (`admin/configuracoes.php` → card "🎨 Identidade visual", PNG/
  JPG/WEBP) gera, via **GD puro** (extensão padrão do PHP, sem Imagick —
  mesmo espírito de "sem dependência exótica" do resto do projeto):
  `public/assets/logo.png` (proporção original preservada, fundo
  transparente, só reduz se passar de 200px de altura — é o que aparece no
  cabeçalho do wizard) e `admin/assets/img/icon-192.png`/`icon-512.png` +
  `favicon.png`/`public/assets/favicon.png` (quadrados, a imagem inteira
  encaixada sem cortar, sobra preenchida com o azul-marinho da marca
  `#0a1229` — nunca fundo transparente nesses, fica ilegível dependendo do
  tema de quem instalar o PWA). Testado em banco isolado com uma imagem
  retangular de teste (600×300, com transparência): saída proporcional
  correta (400×200, mesma proporção 2:1), os 3 quadrados com a elipse de
  teste centralizada e redimensionada sem distorcer, e a transparência do
  `logo.png` confirmada pixel a pixel (canto transparente, centro opaco).
- **Configurações de super admin** — `admin/configuracoes.php`: Z-API
  principal, IA (Gemini + OpenAI fallback), Google Drive, ZapSign,
  testemunhas do contrato (fixas — ver abaixo), fila de leads/plantão,
  instâncias dos consultores. **Testemunhas do contrato de compra são
  sempre as mesmas 2 pessoas do lado da Fastcar** (pedido do José/Jean,
  13/09/2026) — cadastradas 1x aqui (`testemunha1_nome/cpf`,
  `testemunha2_nome/cpf` em `config`) em vez de digitadas de novo em cada
  oportunidade; `includes/contratos.php::montarCamposContratoCompra()` lê
  direto da config na hora de gerar o PDF. As colunas
  `oportunidades.testemunha1_*/testemunha2_*` ficaram sem uso (não
  removidas do schema — sem ganho real em reconstruir a tabela no SQLite
  só por isso, nenhum contrato real chegou a usar).
  **Upload da credencial do Google direto pela tela** (15/09/2026, pedido
  José/Jean enquanto configurava a service account de verdade em
  produção): até então a credencial (`config/google_drive_credentials.json`,
  usada tanto pro Drive quanto pro e-mail transacional) só dava pra colocar
  manualmente por FTP/SSH — decisão original de segurança, revertida por
  pedido explícito, não por eu ter sugerido de volta.
  `processarUploadCredencialGoogle()` (`includes/google_drive.php`) valida
  antes de salvar (JSON decodificável, `type==='service_account'`,
  `client_email`/`private_key` presentes — nunca aceita qualquer arquivo
  só porque tem extensão `.json`), grava em `config/google_drive_credentials.json`
  com `chmod 600` (defesa extra além do `config/.htaccess` que já bloqueia
  acesso via navegador) e nunca ecoa o conteúdo de volta pra tela, só o
  `client_email` como confirmação. Card no admin/configuracoes.php deixou
  explícito que é a MESMA credencial usada pelo card de E-mail logo abaixo
  (mesmo arquivo, dois usos). Segue restrito ao super_admin, mesma trava
  de toda a tela. Testado em banco isolado: sem arquivo, JSON com
  estrutura errada e conteúdo não-JSON são todos rejeitados com mensagem
  clara sem escrever nada em disco; upload válido salva com permissão 600
  e é reconhecido na hora por `GoogleDrive::hasCredentials()`/
  `getCredentialEmail()`, sem precisar reiniciar nada.
  **Bug real de produção — documentos anexados, mas nenhuma pasta criada no
  Drive** (17/09/2026, achado direto: "ja anexamos documentos de cliente
  não criou pasta"). Causa raiz: `GoogleDrive::authenticate()`
  (`includes/google_drive.php`) montava o JWT sem claim `sub` — ou seja, a
  Drive API sempre rodava autenticada como a PRÓPRIA service account, nunca
  impersonando um usuário real do Workspace. Service account "pura" não tem
  cota de armazenamento própria no Drive (política do Google, não bug
  daqui) — toda escrita (`createFolder()`, `uploadFile()`) falha com
  `"Service Accounts do not have storage quota. Leverage shared drives, or
  use OAuth delegation instead."`, e como o código só verificava o HTTP
  status e caía no fallback local (`storage/uploads/`) sem propagar o erro
  pra tela nenhuma, o upload "funcionava" do ponto de vista do consultor
  (documento salvo, sem mensagem de erro) mas nunca criava nada visível no
  Drive — exatamente o sintoma relatado. **Por que o "Testar conexão" de
  Configurações não pegou isso antes**: `testarConexao()` só faz uma
  LEITURA (`GET /about?fields=user`), que não precisa de cota nenhuma —
  passa mesmo com a credencial "pura", sem nunca exercitar o caminho que
  quebra (escrita). Corrigido reaproveitando exatamente o mesmo mecanismo
  que o e-mail transacional (`includes/mail.php::mailAutenticar()`) já usa
  com sucesso: `authenticate()` agora inclui `'sub' => getConfig('email_from')`
  no JWT quando esse e-mail estiver configurado — passa a autenticar
  IMPERSONANDO a caixa `contato@fastcar.solutions` (mesma delegação em todo
  o domínio já autorizada no Workspace Admin pros escopos `drive` E
  `gmail.send` juntos, ver "Gmail API" abaixo — não precisou de nenhuma
  config nova nem autorização adicional no Google Cloud/Workspace). Sem
  `email_from` configurado, comportamento não regride (roda sem `sub`,
  igual antes — só não funciona pra escrita, mesma limitação de sempre).
  De quebra, `createFolder()` passou a gravar `lastError` na falha (só
  `uploadFile()`/`authenticate()` faziam isso antes), pra facilitar
  diagnóstico se aparecer outro caso parecido. Testado contra servidor
  OAuth+Drive fake local simulando exatamente essa política do Google
  (rejeita `createFolder()` com 403 quando o token não carrega `sub`,
  aceita quando carrega): sem `email_from` configurado, JWT sai sem `sub`
  e `createFolder()` falha com a mensagem real do Google capturada em
  `lastError`; com `email_from` configurado, JWT sai com `sub` batendo
  exatamente o e-mail configurado e `createFolder()` sucede — confirma que
  o fix ataca a causa raiz real, não só mascara o sintoma. ⚠️ Não valida
  ainda que a pasta "Fastcar" aparece de fato visível na conta
  `contato@fastcar.solutions` em produção — precisa confirmar depois do
  deploy, anexando um documento de teste e checando o Drive dessa caixa.
- **E-mails transacionais** (`includes/email_templates.php`, 16/09/2026,
  "cria todos os templates" depois do 1º envio real de e-mail funcionar em
  produção) — moldura visual compartilhada (`emailLayout()`/`emailBotao()`)
  com a mesma identidade do wizard/PDF do contrato: faixa navy `#151722`
  com a logo (texto "FastCar" estilizado como fallback se ainda não tiver
  logo enviada), botão em azul sólido `#2f6fed` (gradiente evitado de
  propósito — Outlook desktop não renderiza `linear-gradient`, cor sólida é
  o padrão seguro pra e-mail HTML), rodapé com o endereço real da sede
  (mesma preocupação de "isso não é golpe?" já coberta no wizard). Todo
  estilo inline, nunca `<style>` em bloco — clientes de e-mail removem CSS
  não-inline. `appBaseUrl()` (novo, mesmo arquivo) resolve o link absoluto
  com fallback pro domínio de produção quando chamado fora de um request
  HTTP (`mudarEtapa()` pode em tese rodar fora de admin). 3 e-mails
  plugados, todos best-effort (nunca podem travar o fluxo principal) e só
  disparam se o cliente já tiver e-mail cadastrado:
  1. **Link do wizard de documentos** — `admin/oportunidade.php`, ação
     `enviar_link_documentos`: cópia por e-mail além do WhatsApp de
     sempre, canais independentes (um falhar não afeta o outro).
  2. **Contrato enviado pra assinatura** —
     `includes/contratos.php::gerarEEnviarContratoCompra()`: aviso
     complementar com a cara da Fastcar, nunca compete com o link de
     assinatura de verdade que a própria ZapSign manda por conta própria.
  3. **Compra concluída** — plugado direto em `mudarEtapa()`
     (`includes/oportunidades.php`), fora da transação de banco de
     propósito (nunca queremos um `rollBack()` numa transação já
     commitada só porque o e-mail deu problema) — dispara pra QUALQUER
     rota que feche uma oportunidade, não só uma tela específica.
  Testado ponta a ponta com servidor Gmail fake local: e-mail de compra
  concluída capturado e decodificado (headers From/To/Subject em Base64/
  Content-Type corretos, dados do cliente/veículo/valor batendo), cliente
  sem e-mail cadastrado corretamente não gera nenhuma chamada; renderização
  visual conferida via screenshot (Playwright) dos 2 templates (com e sem
  botão) — layout, cores e botão saindo exatamente como esperado.
- **PWA (instalável como app)** — `admin/manifest.json` + `admin/sw.js`
  (service worker mínimo, sem cache agressivo — dados do CRM são sempre
  dinâmicos), mesmo padrão do JurídicoSaaS. Como o admin da Fastcar (ao
  contrário do JurídicoSaaS) não tem um `layout.php` compartilhado — cada
  página tem seu próprio `<head>`/`<body>` — as tags entram via 2 partials
  (`admin/_pwa_head.php`, `admin/_pwa_register.php`) incluídos em toda
  página cheia; guard `admin-pagina-sem-pwa` no `tests/smoke.php` garante
  que uma página nova nunca esqueça de incluir os dois (mesma classe de bug
  do head_scripts nas landing pages do JurídicoSaaS). Ícones em
  `admin/assets/img/icon-192.png`/`icon-512.png` ainda são **placeholder**
  ("FC" em fundo escuro) até alguém enviar a logo de verdade pela tela de
  Configurações (ver `includes/marca.php` abaixo) — a logo real da Fastcar
  (fundo azul-marinho `#0a1229`, wordmark "Fast**Car**" branco+azul,
  "Soluções Financeiras" como subtítulo) chegou colada direto na conversa
  em 13/09/2026, sem dar pra salvar os pixels exatos; falta reenviar como
  arquivo de verdade.
- **Smoke test** — `tests/smoke.php` (rodar antes de todo commit: `php
  tests/smoke.php`) + `version.json` (changelog semver) — mesmo padrão do
  JurídicoSaaS (LINT + GUARDS de regressão + SCHEMA), guards codificando os
  bugs reais já corrigidos aqui (ver seção de bugs corrigidos abaixo)
- **E-mail** — `includes/mail.php`, via **Gmail API** (Google Workspace).
  **15/09/2026 — 2ª reviravolta no mesmo dia**: de manhã tinha ficado
  decidido manter Brevo (Workspace não é feito pra envio automático em
  volume); à tarde o José/Jean pediram pra trocar mesmo assim ("vamos
  trocar brevo pelo api do google worpace") — decisão de negócio deles,
  não uma questão técnica que eu tenha levantado de novo. Reaproveita a
  MESMA credencial de service account já usada pro Google Drive
  (`config/google_drive_credentials.json`, `includes/google_drive.php`) —
  mesmo `client_email`/`private_key`, só troca o escopo do JWT pra
  `gmail.send` e ganha um claim `sub` (a caixa do Workspace que a service
  account passa a impersonar — `config.email_from`, **`contato@fastcar.solutions`**).
  **Pré-requisito manual, fora do código**: delegação em todo o domínio
  autorizada no Google Workspace Admin Console (admin.google.com →
  Segurança → Controles de API → Delegação em todo o domínio) pro Client
  ID dessa service account, com o escopo `https://www.googleapis.com/auth/gmail.send`
  adicionado (junto do `drive` que o Drive já usa) — sem isso o Google
  rejeita o JWT com `unauthorized_client`, mesmo com credencial válida (a
  assinatura RS256 é genuína, só falta a autorização). Testado em banco
  isolado contra servidor OAuth+Gmail fake local: JWT monta certo (`sub`/
  `scope`/`aud`), MIME da mensagem (From/To/Subject codificado em Base64
  UTF-8/Content-Type HTML) confere byte a byte, e os dois caminhos de
  falha graciosa (sem credencial, sem `email_from` configurado) devolvem
  mensagem de erro clara em vez de estourar exceção — mesmo contrato
  `bool|array` que a Brevo já tinha, nenhum dos 4 call sites de
  `enviarEmail()` precisou mudar. Tela de Configurações → E-mail perdeu o
  campo de API key (não existe mais chave própria de e-mail — usa a
  credencial do Drive) e ganhou o mesmo badge de status
  "✅ credencial encontrada" que o card do Drive já usava.
- **Backup** — `includes/backup.php` (compartilhado entre `cron/` e
  `admin/backup.php`, nunca duplicado): banco copiado várias vezes ao dia,
  ZIP completo (banco + uploads locais + credencial do Drive) 1x/dia, envio
  automático pra pasta "Backups" dedicada no Drive com rotação — botão
  manual pros 3 em `admin/backup.php` (super_admin), download via proxy com
  defesa de path traversal em `admin/baixar_backup.php`
- **Deploy automático** — `api/webhook_deploy.php` (valida assinatura do
  GitHub, agenda `storage/.deploy`) + `install/setup_crontab.sh` (linha de
  crontab que só checa o marcador e chama `install/aplicar_deploy.sh`) —
  mesmo padrão decouplado do JurídicoSaaS (nunca roda git disparado direto
  pela request HTTP, só agenda). `install/aplicar_deploy.sh` (novo,
  13/09/2026) concentra a lógica de aplicar de verdade, nessa ordem: `git
  pull` → `install/migrar.php` (idempotente, roda sempre mesmo sem coluna
  nova — sem isso uma migração chegando por auto-deploy atualizava o código
  mas nunca o banco, e a 1ª tela que tocasse na coluna nova quebrava com
  "no such column") → **`tests/smoke.php`** (novo nesse mesmo dia — rodar
  o smoke DEPOIS do deploy, não só antes de cada commit local, garante que
  um deploy que quebra alguma coisa nunca fica em produção sem ninguém
  perceber). Se o smoke falhar, manda alerta por WhatsApp pros números já
  configurados em `notificacao_leads_whatsapp` (mesmo usado pra lead novo)
  em vez de só logar silencioso — e sempre remove `storage/.deploy` no
  final, falhando ou não (retry automático não resolveria um bug de
  código, só alertar de verdade ajuda). Log de cada rodada em
  `storage/logs/deploy_AAAA-MM.log`.
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
  **Esqueceu a senha** (15/09/2026, achado real: não existia jeito de
  redefinir senha de quem já existe fora do próprio painel — só
  `create_admin.php`, que é create-only e recusa e-mail já cadastrado):
  `install/resetar_senha.php email@fastcar.com novaSenha` (CLI, mesmo
  espírito do `create_admin.php` — sem tela web, precisa de SSH na VPS)
  reaproveita `redefinirSenhaUsuario()` (já existia, usada internamente
  por `admin/usuarios.php` pro super_admin trocar senha de consultor) pra
  resolver o caso "sou o único super_admin e esqueci a minha". Testado em
  banco isolado: senha antiga para de bater depois do reset, nova bate,
  e-mail inexistente é rejeitado com mensagem clara.
- **Dashboard por perfil** — `admin/index.php` mostra cards diferentes pra
  cada perfil (`includes/dashboard.php`): consultor vê a própria carteira
  de atendimento (ativas/atrasadas/recebidas na semana/status da fila) E o
  pipeline de negociação (em negociação/presencial, fechadas do mês, taxa
  de conversão) juntos — mesma pessoa cuida dos blocos 5 e 6 desde a
  mesclagem; super_admin vê visão geral da empresa + funil em barra por
  etapa. Tabela principal e nav de etapas também filtram por
  `responsavel_id` pra consultor.
  **Filtro de busca** (17/09/2026, "adicionar filtro de busca no dashboard
  dos consultores"): até então o dashboard não tinha campo de busca
  nenhum, só a nav por etapa — sem jeito de achar uma oportunidade
  específica sem navegar etapa por etapa. Campo `?q=` novo, mesmo padrão
  já usado em `admin/clientes.php`/`admin/veiculos.php`: busca por
  nome/telefone do cliente OU marca/modelo/placa do veículo. Aplicado nas
  3 queries que já existiam (listagem paginada, contagem total, contagem
  por etapa pra nav) — os contadores da nav também refletem a busca, então
  clicar numa etapa com busca ativa continua mostrando só o que bate com
  os dois filtros juntos, nunca reseta a busca sozinho. Formulário
  preserva a etapa atual (campo hidden) pra buscar dentro da aba
  selecionada; link "Limpar" só aparece com busca ativa. Como o dashboard
  é o mesmo arquivo compartilhado entre os 3 perfis (consultor vê só a
  própria carteira via `responsavel_id`, super_admin/supervisor vêem
  tudo), o filtro funciona igual pros dois — testado nos dois. Testado em
  banco isolado com Playwright: busca por modelo de veículo retorna só a
  oportunidade certa; busca por telefone funciona; busca sem match mostra
  a mensagem certa (diferente da de "etapa vazia"); link de etapa na nav
  preserva `?q=`; combinar busca + etapa filtra os dois juntos
  corretamente; botão "Limpar" remove só o `q` da URL; super_admin também
  usa o mesmo filtro normalmente.
  **"Fechadas este mês"/"Valor fechado este mês" sempre em 0** (17/09/2026,
  achado real: "no dasbord consultor fechamos cliente mais não mostra
  tipo negocio fechado esse mes" / "bianaca já fechou") — causa raiz:
  `oportunidades.valor_final`/`data_compra`/`fechado_por` (colunas do
  bloco 8 "Pasta fechada") existiam no schema desde o início, mas
  `mudarEtapa()` (`includes/oportunidades.php`) **nunca as preenchia** ao
  fechar uma oportunidade — só atualizava `etapa`/`updated_at`.
  `includes/dashboard.php` (`dashboardConsultor()`/`dashboardSuperAdmin()`)
  sempre filtram esses cards por essas 3 colunas (`data_compra >= início
  do mês`), então mesmo com `etapa='fechado'` de verdade o card sempre
  dava 0 — mesma classe de bug em `admin/veiculos.php` (frota), que lê
  `valor_final` pra "valor pago" e sempre mostrava "—"/R$0,00 mesmo com
  veículo genuinamente comprado. Corrigido: `mudarEtapa()` agora preenche
  as 3 colunas numa UPDATE só ao transicionar pra `fechado` —
  `valor_final` assume `valor_ofertado` (bloco 6, única "proposta final"
  que o sistema já rastreia, sem campo próprio de valor final na tela),
  `data_compra` vira a data de hoje, `fechado_por` vira quem executou o
  fechamento. Migração em `install/migrar.php` faz o **backfill** de
  oportunidades que JÁ estavam fechadas antes desse fix (ex: a cliente
  Bianca) — sem isso, o código corrigido só valeria pra fechamentos
  novos, deixando fechamentos reais já feitos escondidos dos cards/frota
  pra sempre; `data_compra`/`fechado_por` vêm do histórico
  (`oportunidade_historico`, data+responsável de quando a etapa virou
  `fechado` de verdade), com fallback pro `responsavel_id` atual se não
  achar linha de histórico — idempotente. Testado em banco isolado:
  fechamento novo via `mudarEtapa()` grava as 3 colunas certas e já
  aparece nos dois dashboards na hora; oportunidade simulando o cenário
  "antiga" (fechada antes do fix, colunas NULL) fica de fora dos cards
  ANTES da migração e some corretamente DEPOIS; oportunidade fechada há
  45 dias (fora do mês atual) corretamente NUNCA conta em "este mês",
  mesmo depois da migração; migração idempotente rodando 2x.
  **Nenhum jeito de localizar cliente que chegou até a pasta fechada**
  (18/09/2026, achado real do usuário: "em dasbord clientes que conluiio
  toda etapa ate pasta como consultor pode localizar nçao tem essa
  opção") — a nav de etapas, a busca (`?q=`) e a tabela inteira do
  dashboard sempre foram hard-limitadas a `ETAPAS_ATIVAS` (as 6 etapas do
  funil em andamento, `whatsapp`→`presencial`) — a única tela que já
  listava `etapa='fechado'` é a Frota (`admin/veiculos.php`), restrita ao
  `super_admin`, então consultor (e supervisor, mesmo gap) não tinha
  NENHUM caminho pra achar um cliente já fechado a partir do dashboard,
  nem pela busca. Adicionado item "✅ Fechadas (N)" na nav de
  `admin/index.php` — monta seu próprio escopo de etapa pro `WHERE`
  (`['fechado']` em vez de `ETAPAS_ATIVAS`) quando selecionado, com
  contador próprio e busca funcionando igual dentro dele. Pro consultor,
  filtra por `fechado_por` (não `responsavel_id`) — mesmo campo que o
  card "Fechadas este mês" do próprio dashboard já usa
  (`dashboardConsultor()`, ver bullet acima), a pessoa que efetivamente
  fechou o negócio, consistente com o resto da tela; pra
  super_admin/supervisor, mostra a empresa inteira (sem filtro de dono),
  igual ao resto do dashboard. Bug lateral corrigido no caminho: a query
  de contagem da nav das etapas ATIVAS reaproveitava a mesma variável do
  filtro atual pro `IN(...)`, que passou a variar de tamanho (1 elemento
  quando "Fechadas" está selecionado) — quebraria o número de binds;
  corrigido com uma variável própria, sempre do tamanho de
  `ETAPAS_ATIVAS`, independente do filtro ativo no momento. Testado em
  banco isolado com Playwright, 2 consultores + super_admin: nav do
  consultor A mostra "Fechadas (2)" (só as 2 que ele mesmo fechou, não
  conta a fechada pelo consultor B); clicar em Fechadas lista as 2
  certas, nunca a do outro consultor nem um cliente ainda em etapa ativa;
  busca por nome funciona dentro da aba Fechadas; aba padrão
  (Minhas/ativas) continua mostrando só oportunidade ativa, sem a fechada
  aparecer lá (sem regressão); super_admin vê "Fechadas (3)" (empresa
  inteira) e as 3 linhas certas ao clicar.
  **Coluna "Recebido em" (data/hora de entrada do lead)** (18/09/2026,
  pedido direto: "da para registrar datas dos leads ?" seguido de "ficar
  melhor organizados") — o dado (`created_at`) já era gravado desde
  sempre em `clientes`/`oportunidades` (inclusive já usado internamente
  pra ordenar as duas listagens), só nunca tinha sido exposto na tela —
  o consultor não tinha como ver, só de olhar a tabela, há quanto tempo
  um lead está no funil. Coluna nova em `admin/index.php` (logo depois de
  "Cliente") e em `admin/clientes.php` (logo depois de "Nome"), formato
  `d/m/Y H:i`, mesmo padrão já usado em `admin/cliente_detalhe.php`/
  `admin/oportunidade.php` pro histórico. Na aba "✅ Fechadas" (bullet
  acima) a coluna também mostra a data de fechamento (`data_compra`) numa
  linha extra abaixo, quando existir — as duas datas juntas (quando
  entrou, quando fechou) fazem mais sentido ali do que só uma. Testado em
  banco isolado com Playwright: dashboard mostra a data/hora certa de uma
  oportunidade semeada; listagem de clientes mostra a data/hora certa de
  um cliente semeado.
  **Lead "quente" destacado e priorizado + cards de estatística clicáveis**
  (18/09/2026, 2 pedidos diretos: "classifica os ledas quentes bem
  destacados prioriza em com os primeiros" e "coloca clicavil os cads tipo
  leads de hoje clicar em cima abri os leads"). (1) Temperatura: a query
  principal de `admin/index.php` passou a ordenar primeiro por
  `temperatura_lead` (`CASE ... WHEN 'quente' THEN 0 WHEN 'morno' THEN 1
  WHEN 'frio' THEN 2 ELSE 3 END`), só depois por atraso/atualização — antes
  a ordem era só por próxima ação/atualização, então um lead quente podia
  ficar enterrado no meio da lista atrás de vários frios mais recentes.
  Linha da tabela ganhou classe `.linha-quente` (borda esquerda laranja +
  fundo laranja claro — cores novas `--laranja`/`--laranja-bg` em
  `admin/assets/style.css`, deliberadamente distintas do vermelho de
  `.linha-atrasada` pra um lead que é as duas coisas ao mesmo tempo — atrasado
  E quente — mostrar os dois sinais sem colidir) e badge "🔥 Quente" ao
  lado do nome (morno/frio ganharam badge neutro ❄️/🌤️ só pra contexto,
  sem o destaque forte reservado ao quente). (2) Cards clicáveis: mecanismo
  novo `?filtro=` em `admin/index.php` (`hoje`/`semana`/`atrasadas`/
  `negociacao`/`fechado_mes`), cada valor mapeando pra um escopo de etapa +
  filtro SQL próprio (ex: `fechado_mes` = etapa `fechado` + `data_compra`
  dentro do mês corrente; `atrasadas` = etapas ativas + `proxima_acao_em`
  no passado) — sempre mutuamente exclusivo com a nav por etapa normal
  (`?etapa=`) e com a aba "✅ Fechadas" (bullet acima). Os cards de
  estatística que já mostravam esses números (Atrasadas, Recebidas nos
  últimos 7 dias, Leads novos hoje/semana, Em negociação/presencial,
  Fechadas este mês/Valor fechado este mês) viraram `<a class="stat-card">`
  em vez de `<div>` puro — clicar abre a mesma tabela do dashboard já
  filtrada, com banner "🔎 Mostrando: [rótulo]" e link "Limpar filtro" pra
  voltar à visão padrão; cards sem contrapartida de lista específica (Taxa
  de conversão, toggle Disponível/Offline) continuam `<div>` normais.
  `.stat-card` no CSS ganhou `display:block;color:inherit;text-decoration:none`
  pra renderizar idêntico como link ou div. Busca (`?q=`) continua
  combinando com o filtro especial ativo (filtro tem prioridade sobre
  `?etapa=`; buscar dentro de um filtro mantém os dois juntos); "Limpar"
  preserva o outro parâmetro quando presente. **Bug pego e corrigido antes
  do commit**: ao converter o card "Valor fechado este mês" pra `<a>`, a
  tag de fechamento tinha ficado como `</div>` (mismatch) — achado relendo
  o arquivo antes de qualquer teste, corrigido. Testado em banco isolado
  com Playwright (2 scripts): ordenação com 3 oportunidades em
  temperaturas diferentes confirma quente sempre primeiro mesmo sendo a
  mais antiga das três, borda/badge aparecendo certo nas 3 classes; cada
  um dos 5 valores de `?filtro=` abre a lista certa (contagem batendo com
  o que o card mostrava), banner de contexto com o rótulo certo, "Limpar
  filtro" volta à visão padrão; clicar num card navega pra URL com o
  `?filtro=` certo; combinação filtro+busca funciona; nav por etapa/aba
  Fechadas continuam funcionando sem filtro ativo (sem regressão).
- **Rebrand visual do admin** (13/09/2026, José achou o visual anterior
  "pobre" comparado ao JurídicoSaaS) — `admin/assets/style.css` trocou o
  roxo/indigo genérico pela paleta real da marca (`--azul: #2f6fed`,
  `--navy: #0a1229`, mesma da logo/wizard), botões com gradiente, cards com
  raio maior e sombra mais suave. Topbar de toda página ganhou o logo
  (`admin/assets/img/icon-192.png`) + wordmark "Fast**Car** CRM" estilizado
  (mesmo padrão do cabeçalho do wizard); login ganhou fundo diagonal
  azul-marinho. **Decisão explícita: manteve a topbar atual** (não migrou
  pra sidebar fixa como o JurídicoSaaS) — mudança de estrutura em ~15
  páginas sem `layout.php` compartilhado foi considerada risco/trabalho
  desproporcional pro pedido, oferecida e recusada. **Bug real achado e
  corrigido no meio do rebrand:** o sino de notificação
  (`admin/_notify.php`, `position:fixed;top:14px;right:20px`, z-index alto)
  ficava por cima do link "Sair" — que sempre cai no canto direito da
  topbar (`<span>` anterior usa `margin-left:auto`) — em qualquer página
  cujo conteúdo da topbar coubesse numa linha só; o clique era capturado
  pelo sino, "Sair" não funcionava (achado em produção: "sair não
  funciona"). Corrigido reservando `padding-right: 76px` na `.topbar`,
  espaço suficiente pro sino nunca mais sobrepor conteúdo real. Conferido
  via screenshot (Playwright) no login, dashboard e Configurações — a
  mesma página onde o bug foi visto — antes de considerar pronto.
  **Cache do navegador mascarava o próprio deploy** (achado logo em seguida:
  José subiu a logo real pela tela nova, mas o topbar apareceu enorme e com
  a cor roxa antiga) — o `<link rel="stylesheet">` nunca teve parâmetro de
  versão, então o navegador segue servindo o CSS antigo já em cache mesmo
  depois de um deploy que muda o arquivo. Corrigido com
  `?v=<?= filemtime(...) ?>` em todo `<link>` de `admin/assets/style.css`
  (todas as ~12 páginas) — muda sozinho a cada vez que o CSS for editado,
  nunca mais precisa de Ctrl+Shift+R depois de um deploy. Cor da marca
  ajustada pra `#151722` (tom pedido pelo José, ligeiramente diferente do
  `#0a1229` inicial) em `--navy` (style.css), `MARCA_COR_FUNDO`
  (`includes/marca.php`) e no cabeçalho do wizard (`public/documentos.php`).
  Logo do topbar/login aumentada (`.topbar-logo` 28px→40px, `.login-logo`
  36px→44px) e com sombra sutil, a pedido explícito ("aumenta mais logo" /
  "deixa mais caprichado").
  **Palavra "CRM" removida do topbar/login** (18/09/2026, "no topo do site
  fastcar é junto" seguido de "pode tirar palavra crm") — todas as ~25
  páginas do admin mostravam "Fast**Car** CRM" (`<span class="crm-tag">CRM</span>`,
  opacidade reduzida) ao lado do ícone no cabeçalho/tela de login; removido
  esse span nas ~25 páginas (mesmo trecho duplicado de propósito em cada
  arquivo, sem `layout.php` compartilhado, mesma decisão de sempre) — fica
  só "Fast**Car**" ao lado do ícone. Regra CSS `.crm-tag` (sem nenhuma
  referência depois disso) removida de `admin/assets/style.css`. Testado:
  `php -l` limpo em todos os arquivos editados, `tests/smoke.php` sem
  avisos.
- **Saúde do sistema** — `admin/saude.php`, mesmo padrão do JurídicoSaaS
  (checks agrupados ok/warn/error/info, banner de resumo), remapeado pros
  subsistemas reais do Fastcar: banco, servidor, Z-API (status real da
  instância), IA (conectividade + custo de tokens do dia/mês), Google
  Drive (autenticação JWT real), ZapSign, Gmail (delegação de domínio),
  backup, crons (frescor de log), fila de leads (alerta se ninguém
  disponível), erros recentes.
  Restrito ao super_admin.
  **Custo estimado em reais** (16/09/2026, "coloca valor estimado de gasto
  em reais lá no saúde api") — "Tokens hoje"/"Tokens no mês" ganharam
  `≈ R$ X,XX (estimado, modelo principal)` no detalhe. `geminiCustoEstimadoBrl()`
  (`includes/gemini.php`, novo) usa a tabela de preço oficial do Google AI
  pro modelo padrão (`gemini-3.5-flash-lite`: US$0,30/1M tokens de entrada,
  US$2,50/1M de saída — confirmado via busca, não chutado) convertida em
  reais por `cotacaoUsdBrl()` (nova, mesmo arquivo) — cotação USD→BRL
  buscada AO VIVO (AwesomeAPI, gratuita, sem chave), cache de 6h em
  `config.cotacao_usd_brl` (mesmo padrão `"timestamp|json"` de sempre, ex:
  PlacaFIPE), cai num fallback fixo (R$5,30) se a busca falhar — nunca
  trava a tela de Saúde por causa de uma cotação indisponível.
  **Estimativa assumida, não exata**: `geminiRegistrarTokens()` acumula
  tokens de entrada/saída num total só por dia, sem registrar qual modelo
  serviu cada chamada — então o cálculo assume que praticamente tudo usa o
  modelo principal (lite); nos dias raros em que o fallback interno pro
  `gemini-3.6-flash` (mais caro) entra em ação por causa de falha do lite,
  o custo real fica um pouco ACIMA do estimado aqui. Não cobre custo do
  OpenAI (fallback secundário) — esse não tem contagem de tokens
  implementada ainda, nenhum dado real pra estimar em cima. Testado em
  banco isolado contra servidor de cotação fake local: busca ao vivo e
  cacheia certo, cache expirado (>6h) busca de novo em vez de ficar preso
  no valor velho, cálculo de custo bate a conta esperada (1M entrada + 1M
  saída × cotação = valor exato), volume zero dá custo zero, e API de
  cotação fora do ar cai no fallback fixo sem quebrar nada.
  **"Modelo Gemini" mostrando modelo já aposentado** (mesmo dia, achado
  real vendo a própria tela em produção logo depois do deploy do custo em
  reais — print mostrando "gemini-2.5-flash-lite", um dos modelos
  aposentados pra chave nova, ver pendência #3): as chamadas de verdade já
  usam o modelo certo (`geminiModeloValido()` remapeia NA HORA de cada
  chamada em `geminiCall()`/`geminiCallChat()`/`geminiCallComMidia()`),
  mas isso nunca reescreve o valor salvo em `config.gemini_model` — só
  remapeia em memória, a cada chamada — então tanto `admin/saude.php`
  quanto o campo de texto em `admin/configuracoes.php` mostravam o valor
  CRU salvo (o nome antigo, aposentado, que nem responde mais), mesmo o
  sistema funcionando certo por baixo. Corrigido nos 2 lugares que liam o
  valor cru pra exibir: `geminiModeloValido(getConfig('gemini_model') ?: '')`
  em vez de `getConfig('gemini_model') ?: 'gemini-3.5-flash-lite'` — mostra
  o modelo EFETIVO, nunca o nome aposentado; salvar a tela de Configurações
  sem mexer nesse campo já corrige o valor salvo sozinho (o form manda de
  volta o valor já remapeado). Testado: `geminiModeloValido()` remapeia
  corretamente os 2 modelos aposentados (`gemini-2.5-flash-lite`,
  `gemini-2.5-flash`), string vazia e o próprio modelo padrão já válido,
  batendo exatamente o cenário real visto em produção.
  **"Erros recentes" sempre em "ℹ️ Info — error_log do PHP não configurado/
  legível"** (17/09/2026, achado direto por screenshot do card de Saúde) —
  o check (`admin/saude.php`) lê `ini_get('error_log')` e só consegue ler o
  arquivo se a VPS/pool do PHP-FPM já vier com essa diretiva configurada de
  fora — nunca foi, então o check sempre caía no caminho "info" (nem chega
  a ler nada, não é bug de lógica do check em si). Corrigido na raiz, não
  no check: `includes/db.php` (carregado por praticamente toda entrada do
  sistema — admin, webhook do WhatsApp, crons, wizard público) passou a
  chamar `ini_set('log_errors', '1')` +
  `ini_set('error_log', storage/logs/php_errors.log)` logo no topo, mesmo
  padrão `storage/logs/*.log` já usado em todo canto do projeto (debug de
  mídia/contato do WhatsApp, deploy, limpeza de leads). Como `error_log` só
  pode ser travado via `php_admin_value` no pool do PHP-FPM (escopo
  `PHP_INI_SYSTEM`), esse `ini_set()` nunca conflita com uma config real da
  VPS: se o hosting já trava o valor, a chamada simplesmente não tem efeito
  e o check continua mostrando o error_log de verdade da VPS; sem trava
  nenhuma (o caso daqui, confirmado pelo "não configurado" do screenshot),
  garante um log que o próprio app sempre controla e consegue reler.
  Testado em banco isolado: logo após `require db.php`,
  `ini_get('error_log')` aponta pro arquivo em `storage/logs/`,
  `trigger_error()` de teste grava nele de verdade; escrita manual de uma
  linha `Fatal error`/`Uncaught Error` simulando um erro real é
  corretamente filtrada pela mesma lógica de `admin/saude.php` (contração
  por `str_contains`), confirmando que o check passaria a mostrar
  "⚠️ N encontrado(s)" em vez de ficar preso no "ℹ️ Info" pra sempre.
- **Qualidade da IA** — `admin/qualidade_ia.php` + `includes/qualidade_ia.php`
  (13/09/2026, pedido do José/Jean — "conforme vai atendendo vai ficando
  afiado"): cruza o que a IA decidiu na qualificação com o resultado real
  depois — funil de resultado (ainda em qualificação / sem perfil / escalou
  por estagnação / qualificação completa), `temperatura_lead` × taxa de
  fechamento real (fechadas/perdidas/ativas por frio-morno-quente), e pra
  onde foram as oportunidades que a IA escalou por estagnação (o consultor
  reverteu ou esfriou mesmo). **Não é fine-tuning nem re-treino automático**
  — o modelo (Gemini/GPT) continua o mesmo; o ganho vem de olhar esse
  relatório periodicamente e ajustar o texto do
  `IA_QUALIFICACAO_PROMPT_SISTEMA`/`IA_EXTRACAO_PROMPT`
  (`includes/ia_qualificacao.php`) com padrão real em vez de achismo — fica
  "afiado" guiado por humano, não sozinho. Restrito ao super_admin.
- **Módulo financeiro** — `includes/financeiro.php` + `admin/financeiro*.php`
  (17/09/2026, "Lá no iab boutique tem modulo financeiro - precisamos copia
  de la para colocar aqui criar perfil gestão financeira subir os
  lançamentos", desbloqueando o item que estava explicitamente adiado na
  "Segunda etapa" abaixo). Portado do repo irmão JurídicoSaaS
  (`admin/financeiro-*.php` de lá), mas **nunca copy-paste direto** — mesmo
  espírito já documentado pro WhatsApp Box ("portar o conceito, não o
  arquivo... modelo de dado e regra de negócio diferentes demais"):
  `fin_categorias`/`fin_fornecedores`/`fin_colaboradores`/`fin_lancamentos`
  mantêm a estrutura base do original, mas `fin_lancamentos` ganhou
  `oportunidade_id`/`venda_id` (liga o lançamento ao negócio de COMPRA ou de
  VENDA/revenda que originou, sem duplicar dado dos dois módulos) e
  `cliente_nome_manual` (texto livre, respondendo ao pedido de
  acompanhamento "relacionamento de clientes pode ser manual" — comprador
  de revenda não vira linha em `clientes`, e cobrança importada do Asaas
  pode chegar sem nenhum match ainda; vínculo com `cliente_id`/`venda_id`/
  `oportunidade_id` é **sempre** ação humana explícita, nunca a IA/sistema
  decide sozinho — regra #3, mesmo espírito do widget de FIPE por placa que
  nunca aplica o candidato "óbvio" sozinho). CRUD simples inline nas
  próprias telas admin (mesmo padrão do original), lógica não-trivial
  (cálculo de status, anexo, geração de plano de parcelamento) centralizada
  em `includes/financeiro.php`. Anexo de comprovante vira `drive_file_id`/
  `arquivo_url` (não uma URL pública do Drive tornada pública com
  `makePublic()` como o original) — mesmo padrão Drive-preferido/
  local-fallback + servido via proxy (`admin/ver_anexo_financeiro.php`) já
  usado em todo o resto do Fastcar. **Escopo cortado de propósito**: sem
  "ler comprovante com IA" (existia no original via Gemini multimodal, não
  foi pedido aqui — evitar over-engineering sem pedido real, mesmo espírito
  já documentado pro inbox de vendas).
  **Perfil `financeiro`** novo (`usuarios.perfil`, mesma técnica de
  reconstrução de CHECK das migrações `supervisor`/`vendedor`) —
  `includes/security.php::podeAcessarFinanceiro()` restringe a
  super_admin+financeiro (dado financeiro é mais sensível que vendas; o
  pedido não incluiu supervisor acompanhando esse módulo dessa vez, decisão
  assumida — sinalizar se a equipe quiser supervisor vendo depois). Siloed
  em `admin/_bootstrap.php` (mesma técnica allowlist central do perfil
  `vendedor`) — nunca acessa funil de compra/vendas/WhatsApp Box, redireciona
  pra `admin/financeiro.php`; login também manda perfil `financeiro` direto
  pra lá (`paginaInicialPorPerfil()`, `admin/login.php`, generalizada pra
  cobrir os 2 perfis siloed — vendedor e financeiro — numa função só).
  **Plano de parcelamento de venda** ("fastcar vende veicualo parcelado
  entrada mais parcelamentos", pedido de acompanhamento no mesmo dia) —
  card novo "💳 Financeiro — plano de parcelamento" em `admin/venda.php`,
  só aparece com veículo já vinculado à negociação.
  `finGerarPlanoParcelamentoVenda()` (`includes/financeiro.php`) gera a
  entrada (`parcela_numero=0`, só se > 0) + N parcelas mensais
  (vencimentos calculados sempre a partir da 1ª parcela, nunca composto
  sobre a anterior, pra não desalinhar o dia do mês) — **local**, só
  registrado no financeiro do CRM, sem cobrar de verdade. Nunca gera 2x pra
  mesma venda (`finContarLancamentosVenda()` checado antes, recusa
  explícita se já existir).
  **Integração Asaas** (`includes/asaas.php`, pedido no mesmo dia: "vamos
  integrar api do assas pra puxar tudo de lá" — já em uso de verdade pra
  cobrar cliente de venda parcelada) — construída a partir da documentação
  pública da API v3 (docs.asaas.com), **nunca confirmada contra uma
  conta/credencial real** (mesma ressalva de todo provedor novo do
  projeto — ver seção "A validar assim que subir em produção" abaixo).
  `asaasImportarClientes()`/`asaasImportarCobrancas()` importam (dedup por
  `asaas_payment_id`) o que já existe no Asaas pra `fin_asaas_clientes`/
  `fin_lancamentos` (`origem='asaas'`); `api/asaas_webhook.php` (evento
  `payment.*`, header `asaas-access-token` opcional contra
  `config.asaas_webhook_token`) + `cron/asaas_sync.php` (polling de
  fallback, mesmo padrão do `cron/zapsign_sync.php`) mantêm sincronizado
  depois — webhook só ATUALIZA cobrança já importada antes, nunca cria uma
  nova a partir do payload sozinho (evita confiar cegamente no que o
  webhook manda, mesma disciplina do `zapsign_webhook.php`).
  `asaasGerarCobrancaParceladaVenda()` cria a cobrança de verdade (POST
  `/payments` com `installmentCount`, busca as demais parcelas do grupo via
  `GET /payments?installment={id}`) quando o comprador já tem cliente Asaas
  vinculado — o card de parcelamento em `admin/venda.php` escolhe
  automaticamente entre Asaas (cobrança real, checkbox marcado por padrão
  quando configurado) e local (só registro) conforme o Asaas estiver
  configurado ou não, nunca bloqueia a operação por falta de credencial.
  `admin/financeiro-asaas.php` (novo) — botões de importar clientes/
  cobranças + tabela de clientes Asaas com vínculo manual a
  `cliente_id`/`venda_id` do CRM (nunca automático, mesmo com nome batendo
  óbvio) — vincular propaga o `cliente_id`/`venda_id` pra todos os
  lançamentos já importados daquele cliente Asaas, sem precisar linkar
  cobrança por cobrança. Card novo em `admin/configuracoes.php` (API key,
  ambiente sandbox/produção, URL do webhook pra colar no painel Asaas,
  teste de conexão).
  Testado: migração rodada contra um banco simulando produção **antes**
  dessa mudança (schema antigo restaurado via `git show HEAD`, confirmando
  que a CHECK/tabelas realmente não existiam ainda), aplica tudo de uma vez,
  idempotente numa 2ª rodada, usuário/dados existentes preservados; função
  isolada (`finGerarPlanoParcelamentoVenda` com entrada+3 parcelas mensais
  corretas e nunca duplicando; `asaasImportarClientes`/`Cobrancas` com dedup
  e mapeamento de status contra servidor Asaas fake local simulando o
  formato documentado, `asaasCriarClienteSeNecessario` +
  `asaasGerarCobrancaParceladaVenda` criando as 3 parcelas reais, webhook
  atualizando só cobrança já importada e ignorando uma desconhecida sem
  criar nada); Playwright ponta a ponta (perfil `financeiro` cai direto em
  `financeiro.php` no login e é bloqueado de qualquer tela de compra/vendas
  mesmo digitando a URL direto; lançamento manual com `cliente_nome_manual`
  aparece certo na listagem; parcelamento local gerado numa venda com
  veículo vinculado mostra entrada+parcelas na tabela e não permite gerar
  de novo).
  **Fornecedores — busca de CNPJ na Receita** (mesmo dia, "no modulo
  financeiro em foncedores coloca api do cnpj para puxa da receita") —
  `includes/cnpj.php` (novo) consulta a BrasilAPI
  (`https://brasilapi.com.br/api/cnpj/v1/{cnpj}`, mesmo provedor já usado
  pro FIPE, sem token/chave, dados oficiais espelhados da Receita), cache
  24h por CNPJ em `config` (mesmo padrão `timestamp|json` de sempre).
  `admin/fornecedor_cnpj_ajax.php` (novo, GET, mesmo padrão de
  `admin/fipe_ajax.php`) serve o JSON — precisou entrar no allowlist
  central do perfil `financeiro` em `admin/_bootstrap.php` (a mesma
  trava que manda esse perfil direto pra `financeiro.php` em qualquer
  página fora da lista branca também pegava esse endpoint novo por
  engano, achado no próprio teste Playwright antes do commit). Botão
  "🔎 Buscar na Receita" ao lado do campo CNPJ/CPF em
  `admin/financeiro-fornecedores.php` preenche nome (nome fantasia, ou
  razão social se não tiver)/contato (telefone+email)/observações
  (razão social+situação cadastral+endereço) **fill-if-empty** —
  diferente do widget de FIPE por placa (vários candidatos, exige
  escolha humana), 1 CNPJ é chave única, só 1 resultado possível, então
  preenche direto, mesmo espírito da busca de marca/modelo/ano por
  placa. CNPJ não encontrado ou API fora do ar mostra aviso, nunca
  quebra o formulário. **Bug real achado no próprio teste Playwright,
  antes de qualquer commit**: a implementação inicial deixou pra trás o
  campo "Nome" ORIGINAL do formulário (sem `id`, ainda `required`) ao
  adicionar o campo novo com `id` — 2 inputs `name="nome"` na mesma
  tela, o antigo sempre vazio bloqueava a submissão silenciosamente via
  validação nativa do HTML5 (nenhum erro visível, só o formulário nunca
  submetia) — corrigido removendo o campo duplicado antigo. Testado:
  função isolada (CNPJ pontuado normaliza certo, todos os campos batendo
  contra servidor BrasilAPI fake local, CNPJ não encontrado retorna
  `null`, cache gravado em `config`) + Playwright ponta a ponta (busca
  preenche os 3 campos certos, fill-if-empty confirmado não sobrescrevendo
  nome editado à mão, fornecedor salvo aparece na listagem, CNPJ inválido
  mostra aviso sem travar).
  **Sem diagnóstico nenhum quando falhava em produção** (mesmo dia, achado
  real do usuário: "cnpj não tá buscando api") — causa mais provável:
  `cnpjConsultar()` chamava curl SEM header `User-Agent` — confirmado
  isoladamente que a extensão cURL do PHP (diferente do CLI `curl`) não
  manda User-Agent nenhum por padrão, e a BrasilAPI roda atrás de CDN
  (Cloudflare/Vercel), exatamente o tipo de provedor que costuma bloquear
  request sem esse header. Corrigido com `CURLOPT_USERAGENT` +
  `CURLOPT_FOLLOWLOCATION` (defesa a mais contra redirect silencioso). Mas
  o problema maior era estrutural: a função só retornava `null` em
  QUALQUER falha, sem log nem mensagem específica — só o aviso genérico
  de sempre. `cnpjUltimoErro()`/`cnpjSetUltimoErro()` (mesmo espírito de
  `GoogleDrive::lastError`) diferenciam CNPJ mal formatado, não encontrado
  (404), erro HTTP do provedor (com o código), formato de resposta
  inesperado, e falha de conexão (com o erro real do curl) —
  `admin/fornecedor_cnpj_ajax.php` mostra essa mensagem específica na tela
  agora. `cnpjLogDiagnostico()` grava o corpo cru em
  `storage/logs/cnpj_debug.log` nos casos que não são "CNPJ simplesmente
  não existe" — mesmo padrão de `whatsapp_contato_debug.log`. ⚠️ Este
  sandbox de dev não consegue alcançar `brasilapi.com.br` de jeito nenhum
  (mesma limitação de acesso externo já documentada no CLAUDE.md) — não
  dá pra confirmar se o User-Agent era A causa raiz de verdade, só que era
  uma lacuna real e a causa mais plausível pro sintoma; se persistir
  depois deste deploy, `storage/logs/cnpj_debug.log` + a mensagem
  específica na tela devem apontar o motivo real. Testado: função isolada
  contra servidor fake local simulando exatamente esse cenário (rejeita
  403 sem User-Agent, aceita com) + os 5 caminhos de erro (formato
  inválido, 404, HTTP 500 com log gravado, formato inesperado com log
  gravado, falha de conexão real com erro do curl capturado) + Playwright
  ponta a ponta confirmando mensagem específica na tela por tipo de erro
  e que o caminho de sucesso continua funcionando com o header novo.
  **Cadastro explícito de fornecedor sem CNPJ (estrangeiro)** (mesmo dia,
  "cadastrar fornecedores que naó tem cnpj no brasil tipo antropic e
  utros") — CNPJ/CPF já era opcional no servidor (só `nome` é obrigatório
  pra salvar em `fin_fornecedores`), mas nada na tela deixava isso óbvio:
  o campo vinha logo no topo, com placeholder em formato brasileiro e o
  botão "Buscar na Receita" do lado, dando a impressão de obrigatório.
  Checkbox novo "🌎 Fornecedor estrangeiro / sem CNPJ no Brasil (ex:
  Anthropic e outros)" — marcar esconde o bloco inteiro do CNPJ (campo +
  botão de busca) e limpa qualquer valor digitado antes, pra nunca
  submeter resto de digitação por engano; editar um fornecedor já salvo
  sem CNPJ (`cnpj_cpf` vazio) já abre com o checkbox marcado e o campo
  escondido sozinho; desmarcar reabre o campo pra quem quiser voltar e
  preencher um CNPJ depois. Nenhuma mudança de schema — só clareza de UI
  em cima de uma regra que já existia. Listagem ganhou o rótulo "🌎
  estrangeiro/sem CNPJ" (em vez de célula em branco) na coluna CNPJ/CPF.
  Testado com Playwright ponta a ponta: campo visível por padrão num
  cadastro novo; marcar o checkbox esconde e limpa o campo; cadastrar a
  Anthropic sem CNPJ funciona e aparece certa na listagem com o rótulo
  novo; reabrir pra editar já vem com o checkbox marcado e o campo
  escondido; desmarcar reabre o campo.
  **Colaboradores — puxar direto de um usuário do sistema** (mesmo dia,
  "nos colaboradores permita puxar do sistema adicionar manuall") — card
  novo "🔗 Adicionar a partir de um usuário do sistema" em
  `admin/financeiro-colaboradores.php`, com um `<select>` (via
  `listarUsuarios()`) de quem já tem login no CRM (consultor/vendedor/
  supervisor/financeiro/admin) e ainda não foi vinculado como colaborador
  — reaproveita `fin_colaboradores.usuario_id`, que já existia no schema
  desde a 1ª versão do módulo (portada do JurídicoSaaS) mas nunca tinha
  ganhado UI pra usar. Selecionar e confirmar cria o colaborador com nome
  puxado do usuário e um cargo padrão sugerido a partir do perfil (ex:
  consultor → "Consultor de compra") — cargo/vínculo/salário continuam
  100% editáveis depois, é só um ponto de partida, nunca decide sozinho o
  resto. Nunca deixa vincular o mesmo usuário 2x. O card de cadastro
  manual (título ajustado pra "Novo colaborador (manual)") continua
  exatamente como antes, pra quem não tem login nenhum no sistema
  (motorista terceirizado, prestador externo, etc). Tabela ganhou coluna
  "Origem" (🔗 usuário do sistema / ✍️ manual). Testado: função isolada
  (puxar do sistema cria com nome/cargo/vínculo certos e `usuario_id`
  vinculado; bloqueia duplicar o mesmo usuário; lista de disponíveis
  exclui quem já foi vinculado e mantém quem não foi; cadastro manual
  continua sem `usuario_id`) + Playwright ponta a ponta (select mostra
  usuário real com cargo sugerido certo, vincular funciona e aparece na
  tabela com a origem certa, usuário some do select depois de vinculado,
  cadastro manual continua funcionando do lado do card antigo).
  **Periodicidade de pagamento — mensal/quinzenal** (mesmo dia, "Os
  consultores eles ganha o fixo cada 15 dias mais comição", seguido de 2
  respostas diretas de acompanhamento que fecharam o escopo: "comissão é
  lançado manual" e "pagamento vai rodar no cron cada 15 dias podemos
  configurar depois isso") — coluna nova
  `fin_colaboradores.periodicidade_pagamento` (`mensal`/`quinzenal`,
  default `mensal`). Puxar um usuário do sistema com `perfil='consultor'`
  (ação `adicionar_do_sistema`, bullet acima) já nasce com `quinzenal`
  sugerido — regra de negócio confirmada só pra esse perfil, qualquer
  outro perfil ou o cadastro manual continuam `mensal` por padrão, sempre
  editável no formulário ("Periodicidade do fixo", ao lado de
  salário/vínculo); tabela ganhou coluna "Fixo". `fin_lancamentos.recorrencia_intervalo`
  ganhou o valor `'quinzenal'` (antes só aceitava `mensal`/`anual`, e nem
  tinha `<select>` nenhum no formulário — a recorrência era só um
  checkbox que sempre gravava `'mensal'` por trás, achado ao mexer nesse
  ponto) — `admin/financeiro-lancamentos.php` ganhou o select que
  faltava, com as 3 opções. **Escopo deliberadamente cortado, confirmado
  pelas 2 respostas do usuário**: comissão nunca é calculada/gerada
  automaticamente — continua sendo sempre um lançamento manual avulso em
  Lançamentos, como qualquer outro (texto de apoio adicionado no form de
  colaborador linkando pra lá); e a geração automática do lançamento do
  fixo a cada 15 dias via cron **não foi implementada** — confirmado
  explicitamente que fica pra configurar depois, então por enquanto
  `periodicidade_pagamento` é só um dado informativo do colaborador, sem
  nenhum cron novo rodando em cima dele. ⚠️ **Fica como pendência real**
  (ver seção "Pendências" mais abaixo): criar o cron que gera o
  lançamento do fixo quinzenal sozinho — precisa de mais uma rodada de
  confirmação antes de codar (ex: gerar automaticamente já como
  `status='pago'` ou como pendente pro financeiro confirmar o pagamento
  de verdade?). Testado: migração rodada contra um banco simulando
  produção ANTES dessa mudança (schema restaurado via `git show HEAD~1`,
  confirmando que a coluna realmente não existia), aplica a coluna nova
  preservando um colaborador já cadastrado (fica `mensal` por default,
  nunca perdido), idempotente numa 2ª rodada; função isolada (consultor
  puxado do sistema nasce `quinzenal`, outro perfil nasce `mensal`,
  cadastro manual grava `quinzenal` quando escolhido, lançamento
  recorrente aceita `recorrencia_intervalo='quinzenal'`, e um lançamento
  de comissão simulado confirmadamente não é recorrente — sempre avulso);
  Playwright ponta a ponta (puxar consultor do sistema mostra "Fixo:
  Quinzenal" na tabela, formulário de edição já abre com quinzenal
  selecionado, trocar pra mensal e salvar reflete na tabela, cadastro
  manual com quinzenal escolhido funciona, select de recorrência em
  Lançamentos oferece as 3 opções).
  **Financeiro ganhou acesso (só consulta) ao cadastro de clientes**
  (18/09/2026, pedido direto: "financeiro tem ter acesso ao clientes") —
  até então o perfil era 100% siloed no módulo financeiro, sem jeito de
  conferir o cadastro de um cliente ligado a uma cobrança do Asaas (ex:
  confirmar telefone/endereço de quem está com conta atrasada, achado
  numa sessão de acompanhamento em cima do card "Contas atrasadas" recém
  clicável). `clientes.php`/`cliente_detalhe.php` liberados no allowlist
  central do perfil (`admin/_bootstrap.php`) + botão novo "🙋 Clientes" na
  barra de ações de `admin/financeiro.php`. Nunca EDITA cliente por aqui —
  mesma trava de só-acompanha já usada pro perfil `supervisor` em
  `admin/cliente_detalhe.php` (guard de POST extendido de `supervisor` pra
  `[supervisor, financeiro]`, 403 com mensagem explicando que o perfil só
  consulta); resto do módulo de compra/vendas/WhatsApp continua fora de
  alcance, sem mudança. Testado em banco isolado com Playwright: login
  como financeiro cai em `financeiro.php`, botão novo abre a listagem,
  abrir um cliente mostra os dados certos, POST forjado tentando trocar o
  nome do cliente é rejeitado com 403 (banco confirmado sem alteração), e
  o resto do silo continua intacto (`admin/veiculos.php` direto ainda
  redireciona pra `financeiro.php`).
  **Dashboard financeiro (`admin/financeiro.php`) ganhou 2 pontos
  clicáveis** (18/09/2026, achados numa sessão de acompanhamento em cima
  do print da tela): (1) card "⏰ Contas atrasadas" — mostrava só o número
  (35), sem nenhum jeito de ver quem são; virou `<a>` apontando pra
  `/admin/financeiro-lancamentos.php?status=atrasado&todos_periodos=1`
  (mesmo padrão dos cards de KPI do dashboard principal, `admin/index.php`)
  — o `todos_periodos=1` é necessário porque a contagem do card
  (`WHERE status='atrasado'`, sem filtro de data) soma atrasados de
  QUALQUER mês, mas a listagem por padrão só mostra o mês corrente; sem
  esse parâmetro o clique abriria uma lista menor que o número mostrado.
  (2) Descrição de cada linha em "⏰ Contas a vencer nos próximos 7 dias"
  — pedido direto "da pra clicar" — virou link pra
  `/admin/financeiro-lancamentos.php?action=edit&id=X`, abrindo o
  lançamento certo já pré-preenchido pra edição, mesmo padrão de
  link-em-célula já usado no resto do projeto (ex: "Abrir →" em
  `admin/clientes.php`) em vez de um padrão novo de linha inteira
  clicável via JS. Os outros 3 cards (Receitas/Despesas/Saldo do mês)
  continuam `<div>` normais — já têm o detalhamento completo logo abaixo
  na tabela, diferente do card de atrasadas, que não tinha nenhuma lista
  correspondente na própria tela. Testado em banco isolado com Playwright:
  3 lançamentos atrasados de mês anterior (fora do mês corrente) + 1
  pendente do mês atual semeados — card mostra a contagem certa, clique
  navega pro href certo, lista retornada mostra exatamente os atrasados
  (nenhum a mais/menos, o pendente corretamente de fora); clicar na
  descrição de um lançamento em "Contas a vencer" navega pra URL de edição
  certa e o formulário já abre com a descrição certa preenchida.
  **Formulário "Novo/Editar lançamento" virou modal** (18/09/2026, pedido
  direto: "lyout poderia novo lançamentos ser modal fica mais bonitos") —
  antes ficava sempre visível, fixo no topo de `admin/financeiro-lancamentos.php`,
  ocupando espaço mesmo sem ninguém lançando nada; virou `<dialog>` nativo
  do navegador (sem lib nova) — fechado por padrão, abre sozinho via
  `?novo=1` (link "➕ Novo lançamento" novo, canto superior direito) ou
  `?action=edit&id=X` (mesmo ✏️ de sempre na tabela), com botão × e
  "Cancelar" pra fechar sem navegar. Nenhuma mudança no fluxo de salvar/
  POST, só a apresentação. CSS novo `dialog.modal-lancamento` em
  `admin/assets/style.css` — genérico, de propósito reutilizável em
  qualquer outro formulário do admin que quiser virar modal depois (mesmo
  espírito de componente compartilhado do resto do CSS). Testado em banco
  isolado com Playwright: modal fechado no carregamento normal; "Novo
  lançamento" abre vazio, preencher+enviar cria o lançamento (linha nova
  na tabela, banner de sucesso); "Cancelar" fecha sem submeter; ✏️ numa
  linha existente abre já pré-preenchido com os dados certos.
  **Categoria automática pra cobrança importada do Asaas** (18/09/2026,
  pedido direto: "isso que puxamos do assas são receitas de pacerla de
  veiculos temos organizar como podemos fazer", confirmado que os 35
  registros do print eram parcela de venda de veículo com descrição
  digitada no próprio Asaas) — Configurações → Asaas ganhou um select
  "Categoria padrão pra cobrança importada" (`config.asaas_categoria_padrao_id`,
  populado com as categorias `tipo='receita'` já cadastradas);
  `asaasImportarCobrancas()` grava esse valor em `categoria_id` só no
  INSERT de cobrança NOVA — fill-if-empty, o UPDATE de cobrança já
  existente nunca mexe em `categoria_id`, então uma categoria trocada à
  mão pelo financeiro depois nunca é sobrescrita numa resincronização
  seguinte. `install/migrar.php` faz o mesmo fill-if-empty no nível da
  config: se ainda não foi escolhida à mão, aponta sozinha pra "Venda de
  veículo — parcela" (💳, já seedada desde a 1ª versão do módulo
  financeiro) — nunca sobrescreve se o super_admin já tiver escolhido
  outra. **`install/asaas_categorizar_importados.php`** (novo, CLI,
  dry-run por padrão, `--confirmar` pra aplicar) resolve o backfill das
  cobranças importadas ANTES dessa mudança existir (usuário confirmou
  "importamos ontem" — sem esse script ficariam com `categoria_id` NULL
  pra sempre, já que a importação só categoriza linha NOVA) — só toca
  `origem='asaas' AND categoria_id IS NULL`, mesma disciplina de nunca
  sobrescrever categoria já escolhida. Testado em banco isolado: migração
  seeda a categoria padrão + aponta a config sozinha; script de backfill
  em dry-run lista certo as cobranças sem categoria, com `--confirmar`
  aplica e rodando de novo confirma "nada a fazer" (idempotente);
  reimportação simulada com 1 cobrança nova + 1 já existente recategorizada
  manualmente pra outra categoria ANTES da resincronização confirma que a
  nova recebe a categoria padrão sozinha e a recategorizada à mão nunca é
  sobrescrita.
  **Lista de atrasados separada por tempo de atraso (1/2/3+ meses)**
  (18/09/2026, mesmo pedido da instância dedicada de cobrança abaixo:
  "em atrasados da para colocar separados atraso 3 meses - atraso 2 meses -
  atraso de 1 mes pois vamos fazer gestão desses clientes que não paga") —
  `admin/financeiro-lancamentos.php`, com `?status=atrasado`, calcula os
  dias de atraso inline via SQLite (`julianday('now','localtime') -
  julianday(data_vencimento)`, sem coluna nova — sempre correto no
  momento da consulta, nunca dessincroniza) e ganhou o parâmetro
  `atraso=1|2|3` (1-30/31-60/61+ dias): 3 badges clicáveis mostrando a
  contagem de cada faixa (query própria, sem filtro de tipo/período,
  sempre reflete o total real por faixa independente do mês selecionado
  no filtro principal), e a coluna Status da tabela passou a mostrar os
  dias de atraso de cada lançamento junto do badge de status. Contexto
  direto de priorização de cobrança — cliente com 3+ meses de atraso é
  caso mais urgente que um com 1 mês. Testado em banco isolado com
  Playwright: 4 lançamentos semeados em -10/-40/-90/-100 dias — badges
  mostram "1 mês — 1", "2 meses — 1", "3+ meses — 2" certos, clicar em
  cada badge filtra exatamente as linhas certas.
  **Instância Z-API dedicada do financeiro + WhatsApp Box de cobrança**
  (18/09/2026, mesmo pedido acima, seguido de "adcione o inbox das
  mensagens resumo pegou ideia") — mesmo padrão já validado em produção
  pro módulo de vendas (instância dedicada + roteamento por `instanceId`
  + WhatsApp Box próprio), portado como arquivo novo, nunca ramificando
  em cima de compra/vendas já validados (mesmo raciocínio documentado
  neste arquivo pro WhatsApp Box original e pra instância de vendas).
  `zapiCredenciaisFinanceiro()` (`includes/whatsapp_config.php`) +
  `zapiIdentificarInstancia()` (`includes/zapi_instancias.php`) ganharam
  o tipo `financeiro`; o webhook único
  (`chatbot-whatsapp/webhook/whatsapp.php`) roteia pra
  `processarMensagemFinanceiroZapi()` (novo
  `chatbot-whatsapp/includes/mensagens_financeiro.php`) — bem mais
  simples que compra/vendas de propósito: cobrança é gestão ATIVA de
  dívida de cliente já convertido, não lead, então sem qualificação por
  IA, sem criar oportunidade/venda nenhuma — só registra a mensagem no
  histórico compartilhado (`whatsapp_mensagens`) pra aparecer na caixa;
  mesmo guard anti-flood das outras 2 instâncias (`tipoMidia()==='desconhecido'`
  ignorado silenciosamente, ver incidente de flood de 15/09/2026).
  `includes/financeiro_inbox.php` + `admin/financeiro_inbox.php` (novo,
  mesmo sidebar+thread+polling dos outros inboxes) resolvem "a conversa"
  de um jeito novo — `fin_lancamentos` não tem telefone próprio, então
  `_finInboxFonteSql()` une os 3 caminhos possíveis pelos quais um
  lançamento pode estar ligado a um contato de verdade: `cliente_id` →
  `clientes.telefone` (funil de compra), `venda_id` →
  `vendas.comprador_telefone` (revenda), `asaas_customer_id` →
  `fin_asaas_clientes.telefone` (importado do Asaas — o caminho mais
  comum na prática agora, cobrança de parcela de venda de veículo);
  `cliente_nome_manual` sozinho (sem nenhum dos 3 vínculos) nunca aparece
  na caixa, não tem telefone resolvível. Sidebar mostra o total em atraso
  de cada contato direto na lista (contexto de cobrança já visível sem
  abrir a conversa) — sem "responsável"/round-robin, perfil `financeiro`
  já é um time pequeno e restrito (super_admin + financeiro), todo mundo
  com acesso vê a caixa inteira, mesmo espírito de super_admin/supervisor
  nos outros inboxes. **Decisão de segurança assumida, não pedida
  explicitamente** (avisar a equipe se não era essa a intenção): esta 1ª
  versão NUNCA dispara mensagem sozinha — toda mensagem sai só quando um
  humano do financeiro digita e aperta enviar
  (`enviarMensagemManualFinanceiro()` nunca chamada por cron/automação
  nenhuma), dado o histórico real deste projeto de bloqueio de número por
  mensagem repetida em rajada (ver bullets "Incidente real de produção —
  flood de mensagens duplicadas" e o fix de `flock()` em
  `cron/followup.php`) — cobrança automatizada em massa é exatamente o
  tipo de padrão que mais arrisca isso. Escopo cortado de propósito nesta
  1ª versão (mesmo espírito já documentado pro inbox de vendas): sem foto
  de perfil ao vivo, sem envio de áudio, e mídia recebida (ex: comprovante
  de pagamento) fica só com o rótulo do tipo, sem descrição por IA nem
  cópia salva ainda — ficam como possível próxima iteração se a equipe
  sentir falta. Mensagem manual assina com `*{nome do usuário}:*\n` (mesmo
  padrão de compra/vendas), sempre pela instância dedicada
  (`zapiCredenciaisFinanceiro()`), nunca pela principal. Card novo "💳
  Instância Z-API — Financeiro" em `admin/configuracoes.php` (mesmo padrão
  salvar+testar conexão da instância de vendas) + botão "💬 WhatsApp
  Cobrança" em `admin/financeiro.php`. Testado ponta a ponta: função
  isolada (mensagem processada certa, dedup de `messageId`, `fromMe`
  registrado sem processar, grupo ignorado, evento não-mensagem tipo
  presença/status ignorado, roteamento por `instanceId` confirmado,
  `ia_pausada` sempre presente no retorno — sem esse campo o webhook
  compartilhado gera PHP warning ao acessá-lo incondicionalmente mais
  adiante, mesmo contrato que compra/vendas já seguem) + webhook HTTP real
  via `php -S` confirmando fim a fim que uma requisição POST simulando o
  payload da Z-API grava a mensagem certa no banco + Playwright contra
  servidor Z-API fake local (sidebar mostra a conversa com o total em
  atraso certo; enviar mensagem manual chega no fake Z-API assinada com o
  nome do usuário pela instância/token dedicados do financeiro — nunca
  vazando credencial de compra/vendas —, e a cópia salva no CRM fica sem
  a assinatura; telefone sem nenhum vínculo financeiro não mostra
  conversa nem aceita POST forjado, ambos bloqueados por
  `usuarioPodeVerConversaFinanceiro()`; "+ Iniciar conversa" abre normal
  um telefone financeiro válido ainda sem mensagens; perfil consultor
  bloqueado com 403 tentando acessar a caixa direto pela URL) +
  Configurações (salvar credenciais persiste ao recarregar, teste de
  conexão chega no fake Z-API e reporta sucesso). ⚠️ A instância Z-API de
  verdade ainda precisa ser criada (número próprio pro financeiro) e as
  credenciais coladas em Configurações — mesmo "a validar em produção" de
  toda integração nova deste projeto.
- **`admin/usuarios.php` permite criar/promover outro `super_admin`**
  (17/09/2026, "coloca no usuarios para adicionar mais super admin") —
  **reverte** a decisão original ("NUNCA cria/promove pra super_admin por
  aqui, só o CLI `install/create_admin.php`, decisão de segurança de
  propósito"), por pedido explícito, não por eu ter sugerido de volta. Só
  quem já é super_admin acessa esta tela (`requireSuperAdmin()`), então
  criar/promover outro continua restrito a quem já tem esse nível de
  acesso — nunca um consultor/supervisor se auto-promovendo. Editar um
  usuário que **já** é super_admin continua com o perfil travado nesta
  tela (só criar/promover foi liberado, nunca rebaixar um existente por
  aqui) — `install/create_admin.php` via CLI segue sendo o único jeito de
  recuperar acesso se todos os super_admin ficarem bloqueados por engano.
  Aviso visual (⚠️) quando "Super admin" está selecionado no formulário.
  Testado via Playwright: opção aparece no seletor, criar um 2º super_admin
  funciona e aparece na lista com o label certo.
- **Cadastro manual de veículo na frota + upload direto de fotos/vídeos**
  (17/09/2026, "vamos implementar subir manual o veiculos fotos videos
  para ia vender qualificar") — cobre veículo que a Fastcar já tem
  fisicamente mas nunca passou pelo funil de compra pelo WhatsApp (deal
  fechado fora do CRM, frota legada). Card "➕ Adicionar veículo
  manualmente" em `admin/veiculos.php` — confirmado com o usuário (2
  perguntas diretas): continua pedindo nome+telefone do vendedor/origem
  (nunca um veículo "solto" sem cliente por trás, mesma disciplina de 1
  cadastro por telefone do resto do sistema), e o cadastro fica na própria
  tela de Frota já existente (não uma tela nova separada).
  `criarVeiculoManualFrota()` (`includes/oportunidades.php`) cria/
  reaproveita o cliente por telefone (nunca chama
  `atualizarNomeFotoWhatsapp()` — não faz sentido bater na Z-API atrás de
  nome/foto de alguém que nem mandou mensagem) e insere a oportunidade
  **direto** em `etapa='fechado'` — de propósito **não** passa por
  `mudarEtapa()` pra essa transição: `mudarEtapa()` trava fechamento sem
  `checklistFechamentoCompleto()` (regra #7), que é sobre o checklist de
  documentos do funil normal de compra; um veículo que entra assim nunca
  passou por esse funil, não tem porquê exigir os mesmos documentos. Ainda
  assim grava `oportunidade_historico` manualmente na hora, pra manter a
  mesma disciplina de auditoria (regra #6) mesmo pulando `mudarEtapa()`.
  Redireciona direto pra `admin/veiculo_midias.php` (novo) — tela dedicada
  de fotos/vídeos que **não depende de nenhuma negociação de venda
  existir**: antes disso, o único jeito de chegar no catálogo
  `veiculo_midias_revenda` (já existia pro módulo de vendas) era abrir
  `admin/venda.php` de uma negociação já iniciada, só pra poder subir 1
  foto de um carro que ainda nem tinha comprador. A tela nova reaproveita
  exatamente as mesmas funções (`salvarMidiaRevenda()`/
  `listarMidiasRevenda()`/`excluirMidiaRevenda()`, `includes/vendas.php`)
  e o mesmo destino Drive/local já usados pelo módulo de vendas — é o
  MESMO catálogo que a IA de vendas usa pra mandar mídia sozinha pro
  comprador (`enviarMidiaCatalogoParaComprador()`,
  `includes/ia_qualificacao_vendas.php`), então uma foto subida por aqui
  já fica disponível pra IA usar, sem nenhuma mudança extra. Coluna nova
  "Fotos/vídeos" na tabela de Frota mostra o contador + link direto pra
  cada veículo, comprado pelo funil normal ou cadastrado manualmente —
  mesma trava de `admin/veiculos.php` (super_admin). Testado: função
  isolada (telefone inválido rejeitado; cadastro básico grava
  `etapa='fechado'`/`valor_final`/`data_compra`/`fechado_por` certos e o
  histórico registrado; 2º veículo do mesmo telefone reaproveita o
  cliente mas cria uma oportunidade NOVA, regra do Jean de "1 cliente pode
  ter mais de 1 veículo"; sem marca/modelo é rejeitado; veículo manual
  aparece em `listarFrotaDisponivelParaVenda()` — a mesma função que a IA
  de vendas usa pra saber o que está disponível pra oferecer; upload de
  foto funciona no veículo recém-criado) + Playwright ponta a ponta
  (cadastro pela tela redireciona pra fotos/vídeos com o veículo certo;
  upload direto funciona sem nenhuma negociação de venda existir; volta
  pra Frota e o contador de fotos aparece certo na listagem).
  **Preencher lendo o CRLV com IA** (mesmo dia, pedido de acompanhamento:
  "vamos subir carro pelo documento veiculo para ir mais rapido") — botão
  "📄 Ler CRLV com IA" no mesmo card, ao lado do upload de arquivo.
  `admin/veiculo_crlv_ajax.php` (novo, mesma trava super_admin) **nunca**
  duplica o prompt de extração — reaproveita exatamente
  `extrairDadosDocumentoComIA('crlv', ...)` (`includes/extracao_documentos.php`,
  já usada no wizard de documentos do cliente), então ganha de graça a
  mesma autochecagem de tipo (nunca aplica dado de um documento que não
  parece ser CRLV — ex: CNH enviada por engano no lugar). O endpoint só
  LÊ e devolve os 6 campos do veículo — nunca salva o arquivo como
  documento oficial nem grava nada no banco, quem decide se os dados
  batem é o super_admin cadastrando. JS preenche
  marca/modelo/ano/placa/chassi/renavam em **fill-if-empty** — editar um
  campo manualmente e clicar "Ler CRLV" de novo nunca sobrescreve o que
  já foi corrigido à mão, mesma regra já usada na calculadora de saldo e
  no widget de FIPE por placa. Testado: função isolada (sem chave Gemini
  configurada, extração retorna vazio, endpoint cai no aviso de
  configurar a chave) + Playwright ponta a ponta contra servidor Gemini
  fake local (upload preenche os 6 campos certos; editar 1 campo na mão e
  ler de novo preserva o editado mas continua preenchendo os outros;
  cadastro final com os dados da IA funciona e o veículo aparece certo na
  Frota).
  **Seletor de consultor responsável pela compra, em vez de digitar/sempre
  creditar quem tá logado** (17/09/2026, "em local do fomulario de
  cadastro do veiculo mais facil selecionar contultor qur comprou do que
  digitar") — antes, `criarVeiculoManualFrota()` sempre gravava
  `fechado_por` (e nunca preenchia `responsavel_id`) com o usuário da
  sessão que estava fazendo o cadastro, mesmo quando na prática é comum
  um admin lançar no sistema um negócio que outro consultor negociou de
  verdade. `criarVeiculoManualFrota()` ganhou parâmetro opcional
  `$responsavelId` — quando informado, `fechado_por` E `responsavel_id`
  da oportunidade (e o `responsavel_id` do registro em
  `oportunidade_historico`) passam a ser o consultor selecionado; sem
  seleção, cai no comportamento de antes (fallback pro próprio usuário
  logado), preservando compatibilidade com qualquer outro caller.
  `admin/veiculos.php` ganhou `<select name="responsavel_id">` no card,
  populado via `listarUsuarios()` filtrado a `consultor`/`super_admin`
  (nunca `supervisor`/`vendedor`/`financeiro` — esses não fecham negócio
  de compra), com "— Eu mesmo (Nome logado) —" como opção padrão em vez
  de forçar escolha. Importa de verdade porque `fechado_por` é o campo
  que `includes/dashboard.php` usa pra contar "fechadas este mês"/taxa de
  conversão de cada consultor (ver bug corrigido no mesmo bullet do
  dashboard, seção "Dashboard por perfil") — sem essa mudança, um veículo
  lançado por um admin em nome de outro consultor nunca aparecia no
  dashboard/produtividade de quem realmente fechou. Testado: função
  isolada (sem seleção, `fechado_por`/`responsavel_id` caem no criador;
  com um consultor selecionado, os dois campos E o histórico gravam o
  consultor escolhido, não quem submeteu o formulário; `dashboardConsultor()`
  do consultor selecionado conta o fechamento certo) + Playwright ponta a
  ponta (dropdown lista os consultores reais cadastrados; selecionar um
  consultor diferente do usuário logado, cadastrar, e confirmar no banco
  que `fechado_por`/`responsavel_id` gravaram o consultor selecionado, não
  quem estava logado).

## Segunda etapa (combinado com o Jean/José — não iniciar sem pedido novo)

Itens explicitamente adiados durante a conversa, pra não se perderem:

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
- **WhatsApp Box** (`admin/whatsapp_inbox.php`, 15/09/2026) — inspirado no
  `admin/whatsapp-inbox.php` do JurídicoSaaS (repo irmão), só que **enxuto**
  pro modelo de dados e escala do Fastcar: sidebar + thread + polling
  reaproveitados do conceito, mas sem os recursos específicos de escritório
  de advocacia (templates jurídicos, stickers, spin_score, encaminhar
  conversa, cache de foto de perfil) — ver bullet completo na seção de
  módulos. Se outro projeto for criado a partir daqui, portar o conceito
  de novo (sidebar+thread+polling+pausar IA), não o arquivo do JurídicoSaaS
  direto — os dois têm modelo de dado (leads/clientes separados lá,
  oportunidades aqui) e regras de negócio (jurídico x compra de veículo)
  diferentes demais pra copy-paste direto
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
| `cron/followup.php` | a cada 30 min | Três papéis: (1) alerta pro responsável quando `oportunidades.proxima_acao_em` está no passado e a etapa ainda está ativa — dedup de 4h por oportunidade via `config.alerta_atraso_{id}`, só marca como enviado se `zapiEnviarTexto()` retornar sucesso; (2) **lead "quente" parado** (15/09/2026, "fazer followup de lead quente... se não agir rápido") — `temperatura_lead='quente'` ainda em `crm_preenchido` (acabou de cair pro consultor, ainda não avançou) há mais de `IA_QUENTE_MINUTOS_LIMITE` (20min) — cobre o buraco que o alerta (1) sozinho deixava: lead recém-qualificado geralmente ainda não tem `proxima_acao_em` marcada, então nunca cairia lá mesmo sendo o caso mais urgente; dedup de 1h via `config.alerta_quente_{id}` (mais apertado que o de atraso, urgência real de financiamento atrasado); (3) reengajamento de lead esfriando: oportunidade ainda em `whatsapp`/`qualificacao_ia`, sem responsável assumido, cuja última mensagem `in` foi há 30-120 min sem resposta nossa depois — mesma janela do `followup_leads.php` do JurídicoSaaS, dedup de 24h por telefone via `config.reeng_sent_{telefone}` (não é permanente — um mesmo telefone pode esfriar de novo numa oportunidade futura, ex: 2º veículo meses depois — bug real corrigido); mensagem de reengajamento fica registrada em `whatsapp_mensagens` (`out`, `enviado_por_ia=1`) igual qualquer outra mensagem ao cliente, pro consultor que assumir depois ver a pergunta que gerou a resposta. Testado (2): 4 cenários simulados — quente parado 30min (dispara), quente parado só 5min (não dispara, dentro do limite), morno parado 30min (não dispara, só quente), quente já avançado pra `negociacao` (não dispara, consultor já agiu) — só o 1º caso alertou, e rodando o cron de novo imediatamente o dedup de 1h bloqueou reenvio. |
| `cron/zapsign_sync.php` | a cada 30 min | Polling de status dos contratos ainda `enviado`/`visualizado` (fallback caso o webhook da ZapSign não chegue) — frequência menor que o antigo `assinafy_sync.php` (que era a cada 1 min): assinatura eletrônica não é tão sensível a atraso de minutos quanto lead esfriando |
| `cron/asaas_sync.php` | a cada 30 min | **18/09/2026, achado real: "tenho que sicornizar assas manual as cobranças de parcela dos carros"** — o script já existia no código desde 17/09/2026, mas nunca tinha sido cadastrado em `install/setup_crontab.sh` (arquivo que a própria cabeça do script declara como "fonte de verdade dos horários", mas ficou desatualizado — `resumo_produtividade.php`, linha abaixo, tinha o mesmo problema, também corrigido agora), então nunca rodou sozinho na VPS; e mesmo rodando, só resincronizava STATUS de cobrança já importada, nunca trazia cobrança NOVA criada direto no painel do Asaas — só o clique manual em "Importar cobranças" (`admin/financeiro-asaas.php`) fazia isso. Corrigido em 2 frentes: (1) `cron/asaas_sync.php` passou a chamar `asaasImportarCobrancas()` (mesma função do botão manual, dedup por `asaas_payment_id`, importa novas E atualiza status de todas numa passada) antes de `asaasSincronizarPendentes()` (mantido, mais barato pro caso comum de só status mudando); (2) linha nova em `install/setup_crontab.sh`, junto com a linha de `resumo_produtividade.php` que também estava faltando lá. Testado em banco isolado contra servidor Asaas fake local: 1 cobrança nova (`pay_novo123`, `PENDING`) + 1 já existente (`pay_existente456`, `pendente` no banco) — rodar o cron importa a nova (`status='pendente'`) e atualiza a existente pro status real vindo da API (`RECEIVED`→`pago`, `data_pagamento` preenchida), rodando de novo mostra "0 nova(s)" (dedup funcionando, não duplica). |
| `cron/resumo_produtividade.php` | 1x/dia, 19h30 | Resumo diário de produtividade pro WhatsApp pessoal de quem tem `perfil=supervisor` (15/09/2026, pedido José/Jean: "envia notificação de produção para números de notificação, supervisores acompanhar a produtividade"). Reaproveita exatamente `dashboardSuperAdmin()` (`includes/dashboard.php`, mesmas métricas de visão geral da empresa já usadas no dashboard — ativas/atrasadas/novas hoje/novas na semana/fechadas no mês/taxa de conversão), sem duplicar query nenhuma. Confirmado com o usuário (3 perguntas diretas): frequência = resumo diário automático (não sob demanda); destinatários = telefone (`usuarios.whatsapp`) de quem já tem `perfil=supervisor` cadastrado (não um campo novo de config com números avulsos); conteúdo = visão geral da empresa (não quebrado por consultor). Dedup por dia via `config.resumo_prod_enviado_{data}` — só marca como enviado se pelo menos 1 supervisor recebeu de verdade (`zapiEnviarTexto()` retornou sucesso), senão tenta de novo na próxima rodada do cron em vez de desistir o dia inteiro por causa de uma falha temporária da Z-API. Sem nenhum supervisor com `whatsapp` cadastrado, não manda nada (nunca quebra o cron). Testado ponta a ponta com banco isolado + servidor Z-API fake: 1 supervisor com WhatsApp recebe o resumo certo (métricas batendo com os dados semeados), 1 supervisor sem WhatsApp corretamente ignorado, rodando o cron de novo no mesmo dia o dedup bloqueia reenvio, e cenário sem nenhum supervisor cadastrado não dispara chamada nenhuma pra Z-API. |
| `cron/backup_db.php` | 4x/dia (2h/8h/13h/18h) | Cópia rápida só do `.db`, mantém os últimos 7 dias — recuperação rápida de um "oops" recente |
| `cron/backup.php` | 1x/dia (3h) | ZIP completo (`.db` + `storage/uploads/` + credencial do Drive), mantém os últimos 5 dias — código não entra, já está no git |
| `cron/backup_drive.php` | 1x/dia (4h, depois do `backup.php`) | Sobe o ZIP mais recente pra pasta "Backups" dedicada no Drive, dedup por data, mantém os últimos 5 lá também |
| *(linha de deploy)* | a cada 1 min | Não é um script `cron/*.php` — é uma linha direta no crontab (`install/setup_crontab.sh`) que chama `install/aplicar_deploy.sh` (`git pull` → `migrar.php` → `smoke.php`, com alerta por WhatsApp se o smoke falhar) quando `api/webhook_deploy.php` agenda `storage/.deploy` |

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
>
> **Bug real achado investigando bloqueio do número no WhatsApp**
> (17/09/2026, usuário perguntou "possíveis causas" do bloqueio; apontado
> como hipótese e confirmado): `cron/followup.php` não tinha NENHUMA trava
> contra rodadas sobrepostas — se uma execução demorasse mais que o
> intervalo de 30min (Z-API lenta, volume alto de oportunidades pendentes)
> e a próxima já começasse, as duas liam o dedup (`getConfig`/`setConfig`
> por oportunidade/telefone) ainda vazio — checagem e gravação em passos
> separados, nunca atômica — e as duas mandavam a MESMA mensagem pro MESMO
> contato. É exatamente o padrão (mensagem duplicada em rajada) que mais
> costuma disparar bloqueio automático de número em APIs não-oficiais como
> a Z-API. Reproduzido isolado com 2 execuções CONCORRENTES DE VERDADE
> (não simulado): as duas mandaram o reengajamento pro mesmo telefone com
> 0.0002s de diferença uma da outra. Corrigido com `flock()` — trava
> exclusiva não-bloqueante em `storage/followup.lock` logo no início do
> script; se já tem outra rodada em andamento, a nova aborta na hora
> (loga e sai, não fica esperando). `flock()` em vez de um arquivo-
> marcador tipo `storage/.deploy` (padrão já usado no webhook de deploy)
> de propósito: a trava libera sozinha se o processo morrer no meio —
> nunca fica "presa" exigindo remoção manual, diferente de um marcador
> que precisa ser apagado explicitamente. Testado em 2 cenários: 2
> processos reais disputando o lock ao mesmo tempo (sem trava, os dois
> mandavam; com a trava, só 1 manda e o outro aborta antes de qualquer
> chamada à Z-API); e trava sendo segurada manualmente por 3s enquanto o
> cron real tenta rodar — aborta na hora com a mensagem certa no log,
> zero chamada à Z-API nesse cenário. Vale considerar isso como possível
> causa raiz de um bloqueio de número já visto em produção, junto com o
> incidente de flood documentado no bullet "1 instância Z-API só +
> WhatsApp Box" acima (mesma classe de problema — mensagem repetida em
> rajada —, causa diferente).
>
> **Varredura nos outros pontos que mandam WhatsApp automático** (mesmo
> dia, "verifica" → "sim", depois do fix acima): usuário pediu pra
> confirmar se a mesma falha estrutural existia em outro lugar antes do
> número voltar. Levantados TODOS os call sites de `zapiEnviarTexto()`/
> `zapiEnviarImagem()`/`zapiEnviarAudio()` do projeto (`grep -rn` em
> `*.php`) e classificados em 2 grupos: (1) **automáticos, sem ação
> humana** — cron/followup.php (já corrigido acima) e
> `cron/resumo_produtividade.php` (mesmo padrão exato: dedup por dia via
> `getConfig`/`setConfig` em passos separados, comentário original já
> cogitava "retry manual, crontab duplicado" como cenário real) — mesmo
> `flock()` em `storage/resumo_produtividade.lock` aplicado ali também,
> por consistência/defesa em profundidade, mesmo o risco sendo bem menor
> (roda 1x/dia, não a cada 30min); testado com 2 processos reais
> concorrentes disputando o lock — confirmado que só 1 manda o resumo e o
> outro aborta antes de qualquer chamada à Z-API. `cron/zapsign_sync.php`
> não manda WhatsApp nenhum (só sincroniza status de contrato), fora do
> escopo. (2) **Disparados de dentro do fluxo do webhook** (resposta da
> IA em `includes/ia_qualificacao.php`, notificações em
> `includes/oportunidades.php` — `notificarConsultorLeadQualificado()`,
> `enviarTelefoneConsultorAoCliente()`) — já protegidos estruturalmente
> pelo debounce (`aguardarSilencioOuAbortar()`,
> `chatbot-whatsapp/includes/mensagens.php`) que já existe antes de
> qualquer um desses disparar: só o worker da mensagem mais recente de um
> telefone segue adiante, os outros abortam cedo — validado com
> concorrência real (2 processos com `sleep()`) na auditoria de
> 13/09/2026 (ver bullet "Qualificação por IA"), nenhuma mudança
> necessária. (3) **Ações de humano clicando um botão** (`admin/oportunidade.php`
> enviando o link do wizard, `admin/whatsapp_inbox.php` enviando mensagem/
> áudio manual, teste de conexão em `admin/configuracoes.php`) —
> categoria de risco diferente (double-click acidental, não rodada de
> cron sobreposta automática) e fora do escopo dessa varredura específica
> — nenhum indício de que essa classe de duplicação tenha causado o
> bloqueio visto.

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
   webhook do GitHub + `api/webhook_deploy.php`, e-mail via Gmail API,
   backup). **Domínio definido e VPS provisionada em 15/09/2026:
   `fastcar.solutions`** (SSL via certbot, não mais Origin Certificate
   manual — ver a skill `setup-vps` pro racional completo da troca),
   `sistema.fastcar.solutions` já respondendo com HTTPS válido e acesso
   direto por IP bloqueado. **Webhook de deploy automático via GitHub
   confirmado funcionando em produção, 16/09/2026** — cadastrado, `git
   push` na `main` dispara `api/webhook_deploy.php`, agenda
   `storage/.deploy`, e o crontab aplica sozinho em até 1 minuto (GitHub
   "Recent Deliveries" mostrando 100% de entregas verdes ao longo do dia
   inteiro). Achado real no mesmo dia: a linha AO VIVO do crontab tinha
   ficado presa numa versão antiga — só `git pull` puro, sem chamar
   `install/aplicar_deploy.sh` — então o marcador era criado e removido
   certinho, mas **nenhuma migração nem smoke test rodava sozinho**
   nenhuma vez, sem nenhum sinal de erro pra desconfiar (só não tinha
   quebrado ainda porque nenhuma migração de schema tinha sido empurrada
   nesse intervalo). Corrigido pra `[ -f storage/.deploy ] && bash
   install/aplicar_deploy.sh` — mesma lição registrada na skill
   `setup-vps` (gotcha #3) pra nunca assumir que a crontab ao vivo bate
   com o que o script pretende, só porque o script existe no repo.
2. ~~**WhatsApp**~~ — ✅ decidido: **Z-API**, mesmo provedor do JurídicoSaaS.
   Instância própria da Fastcar já criada em 10/07/2026 (**"FastCar | JEAN"**,
   número 11 9 5834-7764, plano pago, `Conectado`/Multi Device) — falta só
   configurar em Configurações → Z-API (`zapi_instance_id`/`zapi_token`,
   colhidos da tela "Dados da instância web" da Z-API em 15/09/2026;
   `zapi_client_token` é campo separado, fica em Segurança → não em Dados da
   instância, ainda não coletado) e trocar o webhook "Ao receber" da
   instância (estava apontando pra um n8n de outro projeto, achado
   conferindo a tela — `https://.../webhook/yaqar-incoming-jonas`) pra
   `https://fastcar.solutions/chatbot-whatsapp/webhook/whatsapp.php`.
   Webhook (`chatbot-whatsapp/webhook/whatsapp.php`) segue o mesmo padrão do
   JurídicoSaaS: dedup de `messageId`, checar `fromMe`/grupo antes de
   processar, salvar mensagem sempre (mesmo em pausa de IA)
3. ~~**IA de qualificação**~~ — ✅ decidido: **Gemini** (`gemini-3.5-flash-lite`,
   o mais barato disponível pra chave nova) como provedor principal, com
   fallback automático pro **OpenAI GPT** (`gpt-4o-mini`, também o nível
   mais barato) quando o Gemini falha ou não está configurado.
   **15/09/2026 — modelos da família 2.5 aposentados pra chave nova:**
   primeiro teste real contra a API (Configurações → IA → testar conexão,
   já com domínio/instância reais no ar) voltou "models/gemini-2.5-flash is
   no longer available to new users [...] use models/gemini-3.6-flash" — a
   chave Gemini da Fastcar é nova, então a família 2.5 inteira (incluindo o
   `gemini-2.5-flash-lite` até então configurado como padrão) simplesmente
   não responde mais. Trocado pro padrão **`gemini-3.5-flash-lite`**
   (mais barato da geração 3.x — não existe "gemini-3.6-flash-lite", só o
   `gemini-3.6-flash` "cheio" nessa geração, que entra como fallback
   automático se o lite falhar, mesmo espírito de antes). `gemini-1.5-*`,
   `gemini-2.0-*` e agora também `gemini-2.5-flash`/`-lite` entraram na
   lista de `geminiModeloValido()` (`includes/gemini.php`) — qualquer
   `config.gemini_model` salvo com um modelo aposentado (dessa vez ou de
   uma aposentadoria futura) é remapeado pro padrão atual sozinho, sem
   precisar mexer no banco na mão. Mesmo padrão de fallback duplo
   Gemini→GPT do JurídicoSaaS (`includes/gemini.php`, `includes/openai.php`,
   `includes/ia_qualificacao.php`). O **prompt** de qualificação
   (`IA_QUALIFICACAO_PROMPT_SISTEMA`) e o de extração estruturada
   (`IA_EXTRACAO_PROMPT`) existem mas seguem marcados como rascunho — o que
   perguntar, em que ordem e quando desistir/marcar "sem perfil de compra"
   ainda precisa de revisão do Jean antes de rodar com lead de verdade.
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
   **Perfil `supervisor` adicionado em 15/09/2026** (pedido José/Jean:
   "preciso ter perfil de supervisão que vai acompanhar tudo que
   consultores está fazendo") — mesma VISÃO do `super_admin` (todas as
   oportunidades no funil, WhatsApp Box inteiro sem filtro de
   `responsavel_id`, Produtividade, Origem dos leads, Qualidade da IA),
   mas só ACOMPANHA: nunca muda etapa, edita oportunidade/cliente, envia
   mensagem, pausa IA ou exclui conversa — e não vê
   Configurações/Frota/Usuários/Backup/Saúde (essas seguem exclusivas do
   `super_admin`). Escopo confirmado direto com o usuário (2 perguntas:
   "só acompanhar ou também agir?" → só acompanhar; "vê as telas restritas
   hoje ao super_admin?" → não, só funil/WhatsApp). Implementado via
   `includes/security.php::perfilVeTudo()` (`super_admin` OU `supervisor`
   — controla o que a pessoa VÊ) separado de `requireSuperAdmin()`/checagem
   direta de `admin_perfil==='super_admin'` (controla quem pode AGIR ou ver
   telas administrativas) — `requireVisaoGeral()` novo trava
   Produtividade/Qualidade da IA/Origem dos leads pros dois perfis, nunca
   consultor. Toda rota que muda estado (`admin/oportunidade.php`,
   `admin/cliente_detalhe.php`, `admin/whatsapp_inbox.php`) ganhou um guard
   explícito bloqueando POST de `supervisor` no servidor — nunca confia só
   em esconder o formulário na tela, porque isso não impede um POST
   forjado. `admin/usuarios.php` voltou a ter seletor de perfil
   (Consultor/Supervisor) na criação/edição — desde a mesclagem
   consultor/closer só existia 1 opção; segue nunca oferecendo
   `super_admin` por essa tela. `usuarios.perfil` precisou reconstruir a
   tabela pra CHECK aceitar o valor novo (SQLite não tem ALTER TABLE pra
   isso) — `install/migrar.php` faz isso de forma idempotente (só
   reconstrói se a CHECK ainda não tiver `'supervisor'`, checando o SQL da
   própria tabela em `sqlite_master` antes). Testado ponta a ponta via
   HTTP com 3 contas reais (super_admin/consultor/supervisor): supervisor
   vê dashboard geral + Produtividade + Qualidade da IA (200), bloqueado
   de Configurações/Usuários/Veículos (403), vê o WhatsApp Box inteiro
   mesmo sem ser responsável por nenhuma oportunidade, POST forjado de
   enviar mensagem com CSRF válido roubado de outra página é rejeitado
   pelo guard (não pelo CSRF), POST forjado de editar oportunidade também
   bloqueado (403, banco confirmado sem alteração); migração testada
   simulando um banco com o schema ANTIGO (rejeitava `supervisor` antes,
   aceitava depois, dados de usuários existentes preservados, idempotente
   numa 2ª rodada).
5. **Anúncio/tráfego (bloco 1)** — combinado em 12/09/2026: anúncio "Clique
   para WhatsApp" do Meta — a WhatsApp Cloud API manda um `referral`
   (headline, source_id) na 1ª mensagem, capturado automaticamente em
   `extrairOrigemAnuncio()`. Sem link/UTM manual. Fica em aberto até validar
   contra uma instância Z-API real e um clique de anúncio de teste (ver
   seção de validação em produção abaixo) — formato exato ainda não confirmado.
6. ~~**Módulo de contrato**~~ — ✅ decidido e implementado, **COMPRA e VENDA**:
   modelo real de compra recebido do Jean (`01_Contrato_Mestre_FASTCAR_Compra_Quitacao_Futura.docx`,
   30 cláusulas + Quadro-Resumo), transcrito pra geração via FPDF puro
   (`includes/contratos_pdf.php`, sem LibreOffice/Composer — shared hosting
   não teria isso) e enviado pra assinatura eletrônica via ZapSign
   (`includes/contratos.php`, `includes/zapsign.php` — trocou de Assinafy
   pra ZapSign em 13/09/2026, ver "O que reaproveitar do JurídicoSaaS"
   acima). Contrato de **VENDA** (`01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx`,
   20 cláusulas) implementado em 15/09/2026 junto com o módulo de vendas —
   ver bullet próprio "Módulo de vendas" na seção de módulos acima.
7. **Leads do Supabase (Leandro Soragi)** — aguardando CSV ou acesso ao
   painel pra importar a base existente; sem isso, script de importação
   fica só desenhado, sem rodar de verdade.
8. **Cron do pagamento fixo quinzenal do consultor** — 17/09/2026,
   confirmado com o usuário que fica pra depois ("pagamento vai rodar no
   cron cada 15 dias podemos configurar depois isso"). O dado já existe
   (`fin_colaboradores.periodicidade_pagamento`, ver bullet "Módulo
   financeiro" → "Periodicidade de pagamento"), mas nenhum cron gera o
   lançamento sozinho ainda — hoje é 100% manual em
   `admin/financeiro-lancamentos.php`, igual comissão (que o usuário já
   confirmou que **sempre** continua manual, nunca entra nesse cron).
   Antes de codar, falta confirmar: o cron cria o lançamento já como
   `status='pago'` (assume que o pagamento sempre acontece automático) ou
   como pendente pro financeiro confirmar/anexar comprovante depois? O
   ciclo de 15 dias começa em que data de referência por colaborador (dia
   de admissão? dia fixo do mês, tipo 1 e 15?)? Gera só pra quem está
   `periodicidade_pagamento='quinzenal'` E `status='ativo'`, óbvio, mas
   vale confirmar se PJ/autônomo entra nesse fluxo ou só CLT.

### A validar assim que subir em produção (internet livre + credenciais reais)

Este ambiente de dev bloqueia acesso externo (só libera alguns hosts tipo
GitHub/npm), então o que segue foi construído seguindo documentação e
testado com servidor fake local — nunca contra o serviço real:

- **API Asaas** (`includes/asaas.php`, `api/asaas_webhook.php`,
  `cron/asaas_sync.php`, 17/09/2026) — construída a partir da documentação
  pública da API v3 (docs.asaas.com), nunca confirmada contra uma
  conta/credencial real até 18/09/2026. **✅ 1ª importação real confirmada
  em produção, 18/09/2026** ("puxamos os dados do assas", print de
  `admin/financeiro.php` mostrando "Cobrança Asaas #pay_1ftjb3k0lmzjoxsx"
  já listada em "Contas a vencer nos próximos 7 dias", R$ 2.200,00,
  vencimento 18/09) — confirma que **ponto (1) abaixo** (header
  `access_token`) está certo: a chave real autenticou e `GET /payments`
  (via `admin/financeiro-asaas.php`, botão "Importar cobranças") trouxe
  cobranças de verdade pra `fin_lancamentos` (`origem='asaas'`), aparecendo
  certo no card "Contas a vencer" do dashboard financeiro. Formato do ID
  (`pay_...`) bate com o assumido em `asaas_payment_id`. **Ainda não
  confirmados** pelo mesmo print: campos de parcela
  (`installmentNumber`/`installmentCount`) — a cobrança do print não é
  visivelmente parcelada —, o header do webhook (`asaas-access-token`,
  nada indica que o webhook já foi cadastrado/disparado, só a importação
  manual via botão), e o fluxo de `POST /payments` com `installmentCount>1`
  (`asaasGerarCobrancaParceladaVenda()`, cobrança nova gerada por aqui, não
  importada de lá). Pontos específicos a confirmar quando a chave
  chegar: (1) nome exato do header de autenticação (`access_token` —
  documentado, mas nunca testado contra o serviço real); (2) nomes de campo
  no payload de `/payments` — `installmentNumber`/`installmentCount`
  (número/total da parcela) e `billingType`/`status` nunca confirmados
  contra uma resposta real, só contra servidor fake local modelado na doc;
  (3) header `asaas-access-token` do webhook (nome documentado como padrão
  de autenticação de webhook do Asaas, nunca visto num envio real); (4)
  fluxo de `POST /payments` com `installmentCount>1` — a doc descreve que a
  resposta traz a 1ª parcela com um id de grupo no campo `installment`, e
  que `GET /payments?installment={id}` lista as demais; nunca confirmado
  contra a API de verdade, só simulado no fake server local
  (`asaasGerarCobrancaParceladaVenda()`, `includes/asaas.php`). Cadastro do
  webhook em si precisa ser feito manualmente no painel Asaas → Integrações
  → Webhooks, apontando pra `https://fastcar.solutions/api/asaas_webhook.php`
  (mesmo padrão de toda outra integração do projeto — nunca auto-registra
  webhook via código).
- **Instância Z-API dedicada de vendas** (17/09/2026,
  `includes/zapi_instancias.php`/`whatsapp_config.php`/`mensagens_vendas.php`)
  — reaproveita o MESMO mecanismo de webhook/envio já confirmado em
  produção pro lado de compra (mesmo `zapiEnviarTexto()`, só com
  credenciais/`instanceId` diferentes), então o formato de payload em si
  não é um risco novo. O que ainda falta, fora do código: criar a
  instância de verdade na Z-API (número próprio pro módulo de vendas),
  colar as credenciais em Configurações → 🛒 Instância Z-API — Vendas, e
  apontar o webhook "Ao receber" dessa instância pra MESMA URL do webhook
  de compra (`.../chatbot-whatsapp/webhook/whatsapp.php` — o roteamento
  por `instanceId` já sabe diferenciar as duas). Validar contra uma
  mensagem real assim que a instância existir.
- **Instância Z-API dedicada do financeiro** (18/09/2026,
  `includes/zapi_instancias.php`/`whatsapp_config.php`/`mensagens_financeiro.php`)
  — mesma situação da instância de vendas acima: reaproveita o MESMO
  mecanismo de webhook/envio já confirmado em produção (mesmo
  `zapiEnviarTexto()`, só com credenciais/`instanceId` diferentes), então
  o formato de payload em si não é um risco novo, só testado contra
  servidor Z-API fake local até aqui. Falta, fora do código: criar a
  instância de verdade na Z-API (número próprio pro financeiro), colar as
  credenciais em Configurações → 💳 Instância Z-API — Financeiro, e
  apontar o webhook "Ao receber" dessa instância pra MESMA URL do webhook
  de compra/vendas (o roteamento por `instanceId` já sabe diferenciar as
  3). Validar contra uma mensagem real assim que a instância existir.
- **`zapiEnviarVideo()` (envio de vídeo)** — 17/09/2026,
  `includes/whatsapp_config.php`, usado pela IA de vendas pra mandar vídeo
  do catálogo de um veículo. Construído copiando o mesmo formato já usado
  em `zapiEnviarImagem()` (`POST /send-video`, campo `video` + `caption`),
  nunca confirmado contra instância real — mesma ressalva de todo endpoint
  Z-API que não seja envio de texto. Validar assim que a instância de
  vendas existir de verdade: se o campo/formato bate, e se o Z-API aceita
  vídeo como data URI base64 (usado pra evitar precisar de URL pública pra
  pasta do Drive) do mesmo jeito que já confirmamos funcionar pra áudio.
- ~~**Formato do payload do webhook Z-API**~~ — ✅ **confirmado em produção,
  15/09/2026**: `messageId`, `phone`, `fromMe`, `isGroup`, `text.message`,
  `instanceId` batem com o padrão herdado do JurídicoSaaS, mensagem real
  do WhatsApp chegou, passou pela validação de instância + dedup, e o bot
  respondeu de ponta a ponta. **Uma suposição estava ERRADA** e foi corrigida
  no caminho: o webhook validava um header `Client-Token` no recebimento,
  copiado do padrão do JurídicoSaaS sem nunca ter sido testado contra a
  Z-API real — na prática ela não manda esse header de volta (Client-Token
  é só pras chamadas que NÓS fazemos pra API dela, não o contrário).
  Rejeitava 100% das mensagens recebidas ("client-token inválido no
  header") até ser removido — ver `chatbot-whatsapp/webhook/whatsapp.php`.
  Ainda não confirmado especificamente: payload de áudio/imagem (ver item
  abaixo) e o campo `referral` de clique em anúncio (pendência #5).
- **URL de download de áudio/imagem no payload Z-API** —
  `extrairUrlMidia()` (`chatbot-whatsapp/includes/mensagens.php`) tenta os
  nomes de campo mais prováveis (`audioUrl`/`imageUrl`, `url`, `mediaUrl`,
  `link`) dentro do bloco `audio`/`image` do payload, mas o nome exato nunca
  foi confirmado contra uma instância real — só testado com servidor fake
  local simulando essas variações. **Confirmado que isso é problema real em
  produção, 15/09/2026** ("inbox ainda não está aparecendo as imagens" —
  mídia recebida não estava sendo salva, quase certo que nenhum dos nomes
  chutados bate com o campo de verdade). `logDiagnosticoMidiaZapi()` (novo)
  grava em `storage/logs/whatsapp_midia_debug.log` o bloco cru da mídia
  sempre que `extrairUrlMidia()` não acha o campo OU o download falha
  (com http/erro do curl) — próxima vez que um cliente mandar foto/áudio
  de verdade, esse log revela o nome real do campo pra travar
  `extrairUrlMidia()` nele em vez de continuar tentando adivinhar. Remover
  esse log depois que o formato for confirmado e corrigido de vez.
- **Campo `referral` do clique em anúncio Meta Ads** — `extrairOrigemAnuncio()`
  aceita tanto `referral` solto quanto `message.referral`, mas o nome/formato
  exato dos campos (`source_id`, `headline`, `ctwa_clid`) só dá pra confirmar
  com um clique de anúncio de teste passando pela Z-API real.
- **API de marcas da FIPE (BrasilAPI, v1)** — `includes/fipe.php` só foi
  testado contra um servidor fake local simulando `/marcas/v1/carros`;
  validar o formato de resposta real assim que rodar com internet livre.
- **API PlacaFIPE (busca por placa)** — `includes/fipe.php`
  (`placafipeConsultarPorPlaca()`), `admin/fipe_ajax.php`, widget em
  `admin/oportunidade.php`. Construída a partir de documentação real
  colada pelo José direto no chat (`doc.placafipe.com.br` bloqueado no
  meu sandbox), incluindo um exemplo de resposta JSON real de
  `getplacafipe` — testado antes só contra servidor fake local modelado
  nesse exemplo. **✅ 1ª chamada real confirmada em produção, 15/09/2026**
  (Configurações → FIPE → "Testar conexão" com placa real): API respondeu
  `codigo=1` com `msg="total de 2 modelo(s) encontrado(s)"` — confirma que
  token no corpo do POST + endpoint `getplacafipe` batem exatamente com a
  doc, e que essa conta tem cota/acesso funcionando de verdade. Ainda não
  verificado na prática (o botão de teste só mostra o `msg`, não abre os
  campos individuais): se os nomes de campo dentro de cada item de
  `fipe[]` (`marca`/`modelo`/`ano_modelo`/`combustivel`/`codigo_fipe`/
  `mes_referencia`/`correspondencia`/`valor`/`unidade_valor`) batem
  exatamente com o que `admin/fipe_ajax.php` espera — só confirma isso de
  verdade usando o widget em `admin/oportunidade.php` com uma placa real e
  conferindo se os candidatos aparecem com marca/modelo/valor certos (não
  em branco/undefined); também falta confirmar o comportamento em placa
  não encontrada (`codigo=0`, mensagem exata) e se o plano contratado
  realmente cobra por requisição do jeito que a doc descreve (confirma se
  o cache de 24h por placa é suficiente ou se compensa aumentar).
  **Fluxo de cascata marca→modelo→ano→valor (`ConsultarMarcas` etc) não
  foi implementado** — a doc tem formato de REQUEST confirmado pros 5
  endpoints mas nenhum exemplo de RESPONSE pros 4 passos intermediários;
  se algum dia for pedido, pedir pro José um exemplo de resposta real de
  cada endpoint antes de implementar (mesmo erro já cometido 1x nesta
  sessão com o provedor errado, Parallelum, que foi jogado fora depois de
  confirmado que o provedor real contratado é outro).
- **Envio real de mensagem (`zapiEnviarTexto`)** — só testado o caminho de
  falha graciosa (sem credencial/rede); nunca um envio de verdade.
- **API de contato Z-API (`zapiBuscarContato()`)** — `GET .../profile-picture?phone=`
  + `GET .../contacts/{phone}` (2 chamadas em paralelo), formato confirmado
  copiando o código já validado em produção no JurídicoSaaS, não mais os
  nomes de campo chutados da 1ª versão (ver bullet completo no WhatsApp
  Box, seção de módulos). **2 achados reais em produção, 16/09/2026,
  depois desse endpoint já estar rodando**: campo de nome às vezes traz
  texto de status/presença do WhatsApp ("online", "Disponível") em vez do
  nome de verdade — filtrado agora por `nomeWhatsappPareceValido()`; e a
  foto NUNCA deveria ter sido cacheada permanentemente em
  `clientes.foto_perfil_url` (a URL da CDN expira) — corrigido buscando a
  foto ao vivo via AJAX a cada carregamento de tela (mesmo padrão do
  JurídicoSaaS), `clientes.foto_perfil_url` ficou sem uso pra renderização
  (só histórico/fallback). `storage/logs/whatsapp_contato_debug.log`
  continua gravando o corpo cru sempre que NENHUM campo bate (nome e foto
  vazios) — útil se a Z-API mudar o formato de resposta de novo no futuro.
- **API Gemini** — ✅ 1ª chamada real feita em 15/09/2026 (teste de conexão
  em Configurações → IA, já com chave de verdade): confirmou que
  `gemini-2.5-flash`/`-lite` estavam aposentados pra chave nova (ver
  pendência #3 acima, já corrigido pro padrão `gemini-3.5-flash-lite`) —
  falta repetir o teste de conexão já com o modelo novo pra confirmar que
  esse sim responde. Formato de resposta/fallback Gemini→GPT (o lado OpenAI
  ainda não teve nenhuma chamada real) seguem só testados contra servidor
  fake local simulando os dois formatos.
- **Extração de documentos por IA** (`includes/extracao_documentos.php`,
  wizard `public/documentos.php`) — mesma limitação acima: leitura de
  CNH/comprovante de endereço/contrato de financiamento (foto ou PDF) via
  Gemini multimodal só testada contra servidor fake local simulando o JSON
  de resposta esperado por tipo de documento; nunca contra uma foto real de
  documento brasileiro. Validar assim que possível: qualidade da leitura
  em foto tirada de celular (ângulo, reflexo, iluminação ruim), CNH modelo
  antigo x novo, e se o Gemini realmente lê PDF de contrato de
  financiamento escaneado (não só PDF nativo/texto).
- **Vídeo recebido no WhatsApp** (`chatbot-whatsapp/includes/mensagens.php`,
  15/09/2026) — mesma limitação de áudio/imagem: `extrairUrlMidia()`
  pro bloco `video` do payload Z-API (campo `videoUrl` como aposta
  principal) nunca foi confirmado contra uma instância real, só contra
  servidor fake local. Validar assim que possível: nome exato do campo de
  URL no payload real, se o Gemini processa bem vídeo curto de celular
  (tremido, vertical, áudio ambiente ruim) descrevendo o veículo
  corretamente, e se `WHATSAPP_MIDIA_MAX_BYTES` (20MB) não está cortando
  vídeo legítimo de WhatsApp cedo demais (WhatsApp já comprime bastante,
  mas nunca testado com um vídeo real pra confirmar o tamanho típico).
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
- **API Google Drive** (`includes/google_drive.php`) — ✅ **credencial real
  provisionada e autenticação confirmada em produção, 15-16/09/2026**.
  Service account criada pelo José direto no Google Cloud Console
  (`fastcar-crm@gen-lang-client-0936186149...`, no MESMO projeto onde a
  chave Gemini já existia — não precisou de projeto novo, a criação de
  projeto novo esbarrou numa restrição de Organização do Cloud puxada do
  domínio Workspace, sem permissão `resourcemanager.projects.create`, e o
  jeito mais rápido foi reaproveitar o projeto que o AI Studio já tinha
  criado sozinho pra chave Gemini). `GoogleDrive::testarConexao()` (novo,
  `includes/google_drive.php`) autentica de verdade E confirma que a Drive
  API responde (`GET /about?fields=user`, leitura simples que não depende
  de nenhuma pasta já existir) — botão "Testar conexão" no card do Drive
  em `admin/configuracoes.php`, mesmo padrão dos outros provedores.
  `authenticate()` passou a preencher `lastError` com o erro real do
  Google na falha (`error_description`/`error`), antes só devolvia
  `false` sem dizer o motivo. Testado com servidor OAuth+Drive fake local
  (token válido → 200, sem credencial → false) antes de ir pra produção.
- **Upload da credencial pela própria tela** (15/09/2026, pedido direto —
  reverte a decisão original de "só por FTP/SSH, é chave sensível demais
  pra ter upload"): `processarUploadCredencialGoogle()`
  (`includes/google_drive.php`) valida estrutura (`type==='service_account'`,
  `client_email`/`private_key` presentes — nunca aceita qualquer `.json`
  só pela extensão) antes de salvar em
  `config/google_drive_credentials.json` com `chmod 600`, nunca ecoa o
  conteúdo de volta pra tela. Mesma credencial serve pro Drive E pro
  e-mail transacional (ver abaixo). Testado em banco isolado: sem
  arquivo/estrutura errada/não-JSON todos rejeitados sem escrever nada em
  disco; upload válido salva com permissão 600 e é reconhecido na hora.
- **Gmail API** (`includes/mail.php`) — ✅ **1º envio real confirmado em
  produção, 16/09/2026**, depois de resolver 2 pendências reais no
  caminho: (1) delegação em todo o domínio nunca tinha sido cadastrada de
  verdade no Workspace Admin (`admin.google.com → Segurança → Delegação em
  todo o domínio` — lista vinha vazia), cadastrada com o Client ID da
  service account (mesmo número que o "ID exclusivo" da tela de detalhes
  da conta em `console.cloud.google.com`) + os 2 escopos
  (`.../auth/drive,.../auth/gmail.send`, sem espaço depois da vírgula —
  erro comum); (2) a Gmail API em si não estava ativada no projeto do
  Google Cloud (erro claro do próprio Google apontando o link exato pra
  ativar) — ativada em **APIs e serviços → Biblioteca**, junto com a
  confirmação de que a Drive API também já estava ativa. Depois dos 2
  ajustes, `mailAutenticar()` conseguiu token impersonando
  `contato@fastcar.solutions` e o teste de conexão em Configurações →
  E-mail passou. **Ainda não confirmado**: se o e-mail realmente chega na
  caixa de entrada do destinatário sem cair em spam (só o envio via API
  foi validado, não a entrega final).
- **Webhook do GitHub** (`api/webhook_deploy.php`) — header
  `X-Hub-Signature-256` e formato do payload (`ref`, `pusher.name`,
  `commits`, `head_commit.message`) testados só com payload sintético
  local, nunca contra um push de verdade do GitHub.
- **PDF do contrato de compra** — conteúdo e estrutura verificados
  decodificando os content streams internos do PDF gerado (sem
  `pdftoppm`/LibreOffice funcionando neste sandbox pra renderizar
  visualmente); vale abrir o PDF de verdade num leitor real assim que
  possível pra conferir layout/quebra de página.
