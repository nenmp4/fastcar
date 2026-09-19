-- Schema Fastcar CRM — funil de compra de veículo financiado
-- Baseado no fluxo definido por Jean Susej (8 blocos) em 12/09/2026.
-- Convenção: tabela config no padrão chave/valor do JurídicoSaaS (sem
-- updated_at) — TTL de cache, se precisar, usa o padrão "timestamp|json".

CREATE TABLE IF NOT EXISTS config (
    chave TEXT PRIMARY KEY,
    valor TEXT
);

-- Um cadastro por telefone (regra do Jean) — a oportunidade é o cliente;
-- o veículo mora em `veiculos`, um cliente pode ter mais de um veículo em
-- negociação ao mesmo tempo (também regra do Jean).
CREATE TABLE IF NOT EXISTS clientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT DEFAULT '',
    telefone TEXT NOT NULL UNIQUE,
    cidade TEXT DEFAULT '',
    estado TEXT DEFAULT '',
    cpf TEXT DEFAULT '',
    endereco TEXT DEFAULT '',
    -- E-mail do cliente — coletado na 1ª etapa do wizard público de
    -- documentos (public/documentos.php, junto com CNH/qualificação civil),
    -- pedido explícito do José/Jean 14/09/2026 ("faltou esse dado"). Usado
    -- também como canal alternativo de notificação da ZapSign na hora de
    -- assinar o contrato (includes/zapsign.php), além do telefone.
    email TEXT DEFAULT '',
    -- Qualificação civil — exigida pelo contrato-mestre de compra
    -- (includes/contratos.php) pra identificar o VENDEDOR no instrumento.
    -- Coletado no mesmo formulário público de documentos (public/documentos.php).
    rg TEXT DEFAULT '',
    cnh TEXT DEFAULT '',
    nacionalidade TEXT DEFAULT '',
    estado_civil TEXT DEFAULT '',
    profissao TEXT DEFAULT '',
    canal_origem TEXT DEFAULT '',      -- facebook_ads, google_ads, whatsapp_direto, etc
    campanha_origem TEXT DEFAULT '',
    anuncio_origem TEXT DEFAULT '',
    -- Pasta do cliente no Google Drive (includes/google_drive.php), criada sob
    -- demanda dentro da pasta raiz "Fastcar" — mesmo padrão do JurídicoSaaS.
    drive_folder_id TEXT DEFAULT NULL,
    -- Foto de perfil do WhatsApp (16/09/2026, "puxa foto do zap e nome") —
    -- cacheada via zapiBuscarContato() (includes/whatsapp_config.php) na
    -- criação do cliente, pra não bater na Z-API toda hora. URL pode
    -- expirar/mudar com o tempo (é a foto atual do WhatsApp da pessoa, não
    -- um arquivo nosso) — sem garantia de validade eterna, só um cache.
    foto_perfil_url TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_clientes_telefone ON clientes(telefone);

-- Cada linha é 1 veículo/oportunidade em negociação — é o que percorre o
-- funil (bloco 1 a 8), não o cliente. Campo de cada bloco fica aqui,
-- nullable (pendência) até ser preenchido — "IA não inventa informação".
CREATE TABLE IF NOT EXISTS oportunidades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id INTEGER NOT NULL REFERENCES clientes(id),

    -- Etapa atual do funil (bloco 1-8) — nomes curtos, sem acento, pra
    -- facilitar filtro/index; label bonito fica só na camada de exibição
    etapa TEXT NOT NULL DEFAULT 'whatsapp'
        CHECK (etapa IN (
            'whatsapp',            -- 2. entrou pelo WhatsApp, ainda sem qualificação
            'qualificacao_ia',     -- 3. IA conversando
            'crm_preenchido',      -- 4. dados organizados, aguardando consultor
            'atendimento',         -- 5. consultor em contato
            'negociacao',          -- 6. Jean/closer negociando
            'presencial',          -- 7. reunião marcada / em andamento
            'fechado',             -- 8. pasta fechada, compra concluída
            'sem_perfil',          -- IA descartou — sem perfil de compra
            'perdido'              -- perdido em qualquer etapa (motivo em oportunidades.motivo_perda)
        )),

    responsavel_id INTEGER REFERENCES usuarios(id),   -- quem é dono da oportunidade agora
    proxima_acao TEXT DEFAULT '',                     -- texto livre: "ligar às 15h", etc
    proxima_acao_em DATETIME,                         -- pra alerta de atraso

    -- Token do formulário público de upload de documentos (includes/documentos.php)
    -- — gerado sob demanda (lazy) na 1ª vez que o consultor manda o link;
    -- é a "senha" do link, cliente nunca faz login de verdade.
    documentos_token TEXT,
    -- Quando o cliente concluiu a revisão final do wizard de documentos
    -- (confirmou os 3 documentos + dados extraídos) — NULL enquanto ainda
    -- não terminou. Zerado de novo se ele voltar e editar algo depois de já
    -- ter confirmado (força o consultor a olhar de novo antes do contrato).
    documentos_confirmados_em DATETIME,

    -- Bloco 3 — Qualificação IA (dados do veículo/financiamento)
    -- Marca separada do modelo pra dar pra validar contra a lista oficial
    -- da FIPE (includes/fipe.php::fipeValidarMarca()) sem heurística de
    -- "primeira palavra do texto" — nunca 100% confiável.
    veiculo_marca TEXT DEFAULT '',
    veiculo_modelo TEXT DEFAULT '',
    veiculo_ano TEXT DEFAULT '',
    veiculo_placa TEXT DEFAULT '',
    veiculo_renavam TEXT DEFAULT '',
    veiculo_chassi TEXT DEFAULT '',
    banco_financiamento TEXT DEFAULT '',
    valor_parcela REAL,
    parcelas_restantes INTEGER,
    parcelas_atraso INTEGER DEFAULT 0,
    valor_pretendido REAL,             -- quanto o cliente quer pelo veículo
    resumo_ia TEXT DEFAULT '',         -- resumo da conversa gerado pela IA pro consultor
    urgencia TEXT DEFAULT '',          -- percepção da IA: precisa vender rápido ou pode esperar (texto livre, nunca inventado)
    temperatura_lead TEXT DEFAULT '' CHECK (temperatura_lead IN ('', 'frio', 'morno', 'quente')),
    aceita_ligacao_consultor INTEGER,  -- NULL = ainda não perguntado; 1/0 = cliente topou/recusou receber ligação

    -- Dados do financiamento pro contrato-mestre de compra
    -- (includes/contratos.php) — nunca preenchidos automaticamente, o
    -- closer confirma com o cliente antes de gerar o contrato.
    valor_fipe_referencia REAL,           -- valor FIPE na data da negociação (% pago ao vendedor é limitado a 25% disso)
    contrato_financiamento_numero TEXT DEFAULT '',
    saldo_financiamento_atual REAL,

    -- Bloco 6 — Negociação (Jean/closer)
    valor_ofertado REAL,
    valor_contraproposta REAL,
    condicoes_negociacao TEXT DEFAULT '',
    aprovado_por INTEGER REFERENCES usuarios(id),
    motivo_perda TEXT DEFAULT '',
    terceiro_quitacao TEXT DEFAULT '', -- quem a FASTCAR indica pra quitar o financiamento (Quadro-Resumo do contrato)
    seguro_texto TEXT DEFAULT '',      -- condição de seguro/proteção durante a posse da FASTCAR (Quadro-Resumo do contrato)
    encargos_texto TEXT DEFAULT '',    -- responsável por IPVA/licenciamento/multas após a entrega (Quadro-Resumo do contrato)
    prazo_quitacao_meses INTEGER,      -- prazo negociado pra quitar o financiamento (normal: 12-18, nunca > 24 meses — cláusulas 1.3/4.1/5ª/18.3 do contrato-mestre de compra); sem DEFAULT de propósito, sempre confirmado com o cliente por oportunidade, nunca fixo em 24
    -- Flag manual (19/09/2026, "ter botão veiculo quitado") de que o
    -- financiamento do banco que a FASTCAR assumiu na compra já foi
    -- quitado de verdade — separado de etapa='fechado' (só marca o
    -- NEGÓCIO de compra concluído) e de oportunidade_pendencias_pos_venda
    -- (genérico pra qualquer pendência pós-venda); este flag é específico,
    -- pensado só pra alimentar/desligar o alerta de "perto de negociar
    -- financiamento" em admin/veiculos.php.
    financiamento_quitado INTEGER NOT NULL DEFAULT 0,
    financiamento_quitado_em DATETIME,

    -- Bloco 7 — Presencial/fechamento
    reuniao_agendada_em DATETIME,
    data_entrega_posse DATE,           -- quando o veículo/posse física passa pra FASTCAR (Quadro-Resumo do contrato)
    avaliacao_veiculo TEXT DEFAULT '',
    documentos_ok INTEGER DEFAULT 0,   -- checklist obrigatório antes de liberar "fechado"
    contrato_assinado INTEGER DEFAULT 0,
    contrato_gerado_em DATETIME,       -- última vez que o contrato foi gerado (includes/contratos.php)
    -- Testemunhas do contrato-mestre de compra (assinatura, includes/contratos_pdf.php)
    -- — não são obrigatórias pra gerar o contrato: quando não preenchidas,
    -- o PDF sai com a linha em branco pra assinatura física na hora do
    -- presencial, igual já era antes dessas colunas existirem.
    testemunha1_nome TEXT DEFAULT '',
    testemunha1_cpf TEXT DEFAULT '',
    testemunha2_nome TEXT DEFAULT '',
    testemunha2_cpf TEXT DEFAULT '',

    -- Bloco 8 — Pasta fechada
    valor_final REAL,
    data_compra DATE,
    fechado_por INTEGER REFERENCES usuarios(id),

    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_oportunidades_cliente ON oportunidades(cliente_id);
CREATE INDEX IF NOT EXISTS idx_oportunidades_etapa ON oportunidades(etapa);
CREATE INDEX IF NOT EXISTS idx_oportunidades_proxima_acao ON oportunidades(proxima_acao_em);
CREATE UNIQUE INDEX IF NOT EXISTS idx_oportunidades_documentos_token
    ON oportunidades(documentos_token) WHERE documentos_token IS NOT NULL;

-- "Mudanças de etapa ficam no histórico, com data e responsável" — regra
-- explícita do Jean. Nunca fazer UPDATE direto em oportunidades.etapa sem
-- também inserir aqui (ver includes/oportunidades.php::mudarEtapa()).
CREATE TABLE IF NOT EXISTS oportunidade_historico (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
    etapa_anterior TEXT DEFAULT '',
    etapa_nova TEXT NOT NULL,
    responsavel_id INTEGER REFERENCES usuarios(id),
    observacao TEXT DEFAULT '',
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_historico_oportunidade ON oportunidade_historico(oportunidade_id);

-- Inbox unificado do WhatsApp — mesmo padrão do JurídicoSaaS
-- (whatsapp_mensagens). "Salvar desde o primeiro contato": toda mensagem
-- 'in' entra aqui, mesmo antes de existir cliente/oportunidade formal.
CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telefone TEXT NOT NULL,
    cliente_id INTEGER REFERENCES clientes(id),
    direcao TEXT NOT NULL CHECK (direcao IN ('in','out')),
    mensagem TEXT NOT NULL,
    tipo TEXT DEFAULT 'text',
    arquivo_url TEXT DEFAULT '',
    -- Cópia da mídia original (áudio/imagem/vídeo) salva no Drive
    -- (preferido) ou em arquivo_url (fallback local) — mesmo par
    -- drive_file_id/arquivo_url de oportunidade_documentos/contratos.
    -- Antes disso (15/09/2026, achado real: "mídia não estou visualizado")
    -- só o TEXTO gerado pelo Gemini (transcrição/descrição) ficava salvo —
    -- a mídia em si nunca era persistida, só baixada de passagem pra
    -- alimentar o Gemini e descartada; o consultor via a descrição mas
    -- nunca a foto/áudio/vídeo de verdade.
    drive_file_id TEXT DEFAULT '',
    enviado_por_ia INTEGER DEFAULT 0,   -- 1 = resposta automática da IA, 0 = humano
    zapi_message_id TEXT DEFAULT '',    -- messageId do Z-API — dedup de webhook reenviado
    -- Decisão de 15/09/2026 (José/Jean): 1 instância Z-API só, WhatsApp Box
    -- (admin/whatsapp_inbox.php) é o jeito de todo mundo atender pelo mesmo
    -- número — usuario_id aqui passou a marcar QUEM enviou pela caixa
    -- (não mais "qual instância própria"; zapi_instancias_consultores ficou
    -- sem uso novo, ver CLAUDE.md), alimentando admin/produtividade.php.
    usuario_id INTEGER REFERENCES usuarios(id),
    -- "Não lida" pro WhatsApp Box (só é relevante pra direcao='in' — mensagem
    -- do cliente ainda não vista por ninguém da equipe; marcada 1 quando
    -- alguém abre a conversa, includes/whatsapp_inbox.php::marcarConversaLida()).
    lida INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_wpp_telefone ON whatsapp_mensagens(telefone, id DESC);
CREATE INDEX IF NOT EXISTS idx_wpp_usuario ON whatsapp_mensagens(usuario_id, created_at);
-- Índice parcial: contagem de "não lidas" (WhatsApp Box) só olha direcao='in'
-- AND lida=0 — índice pequeno, só cresce com mensagem de verdade não vista.
CREATE INDEX IF NOT EXISTS idx_wpp_nao_lidas ON whatsapp_mensagens(telefone) WHERE direcao = 'in' AND lida = 0;
-- Índice parcial: só exige unicidade quando zapi_message_id foi informado.
-- Mensagens digitadas manualmente no CRM (sem messageId) não competem entre si.
CREATE UNIQUE INDEX IF NOT EXISTS idx_wpp_zapi_message_id
    ON whatsapp_mensagens(zapi_message_id) WHERE zapi_message_id != '';

-- Instância Z-API própria de cada consultor/closer — canal PARALELO ao
-- funil oficial (que roda todo na instância principal, config.zapi_*):
-- serve pra capturar o que o consultor conversa com o cliente por fora,
-- pra dar visibilidade (compliance) e medir volume/produtividade por
-- pessoa. 1 instância por usuário — normalizar o dado do cliente/telefone
-- não muda, é o mesmo pipeline de sempre (processarMensagemZapi()).
CREATE TABLE IF NOT EXISTS zapi_instancias_consultores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL UNIQUE REFERENCES usuarios(id),
    instance_id TEXT NOT NULL UNIQUE,
    token TEXT NOT NULL,
    client_token TEXT DEFAULT '',
    ativo INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);

-- "IA deve pausar as respostas automáticas" quando o consultor assume —
-- esse flag por telefone é o kill-switch, checado no webhook antes de
-- responder automaticamente. Mesmo padrão do bot_pausado_global do
-- JurídicoSaaS, só que por conversa em vez de global.
CREATE TABLE IF NOT EXISTS whatsapp_sessoes (
    telefone TEXT PRIMARY KEY,
    etapa_bot TEXT DEFAULT 'inicio',
    ia_pausada INTEGER DEFAULT 0,
    extras TEXT DEFAULT '{}',           -- JSON — estado efêmero da conversa
    -- Turnos seguidos de qualificação sem NENHUM dado novo extraído — reseta
    -- a cada turno que avança algo; ao bater o limite (includes/ia_qualificacao.php),
    -- passa pro consultor humano em vez de ficar girando a conversa à toa
    -- (lead "só de bate-papo" ou que esfriou por desconfiar que é bot).
    turnos_sem_avanco INTEGER DEFAULT 0,
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);

-- Documentos vinculados à oportunidade — tanto os que o CLIENTE sobe
-- sozinho no formulário público (CNH, comprovante de endereço, contrato de
-- financiamento do banco — includes/documentos.php) quanto os da pasta
-- fechada (bloco 8: contrato da Fastcar, comprovante de pagamento, laudo
-- de avaliação), que o consultor/Jean anexa depois da reunião presencial.
-- "Compra concluída exige checklist" — cada tipo obrigatório precisa ter
-- arquivo_url preenchido antes de liberar etapa='fechado' na aplicação.
CREATE TABLE IF NOT EXISTS oportunidade_documentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
    tipo TEXT NOT NULL,                 -- cnh, comprovante_endereco, contrato_financiamento, contrato_compra, comprovante_pagamento, laudo_avaliacao, etc
    arquivo_url TEXT DEFAULT '',        -- caminho relativo em storage/uploads/ — só usado quando NÃO subiu pro Drive (fallback)
    drive_file_id TEXT DEFAULT '',      -- preenchido quando o arquivo foi pro Google Drive (includes/google_drive.php) — mesmo padrão do JurídicoSaaS
    obrigatorio INTEGER DEFAULT 1,
    enviado_pelo_cliente INTEGER DEFAULT 0, -- 1 = veio do formulário público, 0 = staff anexou
    -- Wizard de documentos (public/documentos.php, 13/09/2026): pro cliente,
    -- upload de cnh/comprovante_endereco/contrato_financiamento dispara
    -- extração por IA (includes/extracao_documentos.php) que pré-preenche
    -- clientes/oportunidades — dados_confirmados=1 só depois que o cliente
    -- revisa/corrige e clica "avançar" pra essa etapa específica. Decide qual
    -- etapa do wizard mostrar (arquivo ausente → upload; arquivo presente e
    -- não confirmado → revisão; todos confirmados → resumo final).
    dados_confirmados INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime')),
    UNIQUE(oportunidade_id, tipo)       -- upsert por tipo — reenvio substitui, nunca duplica linha
);
CREATE INDEX IF NOT EXISTS idx_docs_oportunidade ON oportunidade_documentos(oportunidade_id);

-- Controle operacional pós-fechamento (ex: quitação de financiamento) —
-- "se ainda houver obrigações futuras, continuam vinculadas à mesma pasta",
-- separado do funil comercial que já foi encerrado em oportunidades.etapa.
CREATE TABLE IF NOT EXISTS oportunidade_pendencias_pos_venda (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
    descricao TEXT NOT NULL,            -- ex: "Quitação do financiamento junto ao banco X"
    prazo_estimado DATE,
    status TEXT DEFAULT 'pendente' CHECK (status IN ('pendente','concluido')),
    -- Responsável por resolver (regra #5 do CLAUDE.md — mesmo espírito de
    -- "toda oportunidade aberta precisa de responsável", aplicado aqui pra
    -- pendência não ficar largada sem dono depois da pasta já fechada).
    responsavel_id INTEGER REFERENCES usuarios(id),
    concluido_em DATETIME,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_pendencias_oportunidade ON oportunidade_pendencias_pos_venda(oportunidade_id);

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT UNIQUE,
    whatsapp TEXT DEFAULT '',
    senha_hash TEXT NOT NULL,
    -- Perfis 'consultor' e 'closer' foram mesclados em 13/09/2026 (pedido
    -- do José): na prática é a mesma pessoa que atende (bloco 5) E negocia/
    -- fecha (bloco 6), não faz sentido dois perfis. 'closer' fica aceito
    -- aqui só por segurança histórica (nunca mais escrito pela aplicação —
    -- install/migrar.php converte qualquer linha antiga pra 'consultor');
    -- recriar a CHECK sem ele exigiria reconstruir a tabela toda no SQLite,
    -- sem ganho real.
    -- 'supervisor' adicionado em 15/09/2026 (pedido José/Jean: "preciso ter
    -- perfil de supervisão que vai acompanhar tudo que consultores está
    -- fazendo") — mesma VISÃO do super_admin (todas as oportunidades,
    -- WhatsApp Box inteiro, produtividade, qualidade da IA), mas só
    -- ACOMPANHA: nunca muda etapa, envia mensagem ou edita cadastro, e não
    -- vê Configurações/Frota/Vendas/Usuários/Saúde (ver
    -- includes/security.php::perfilVeTudo()/requireVisaoGeral()).
    -- 'vendedor' adicionado em 17/09/2026 (pedido José/Jean: módulo de
    -- vendas ganhando funil de entrada de lead pelo WhatsApp próprio,
    -- "igual de compra") — equipe dedicada à revenda de veículos da frota,
    -- só vê/atua no módulo de vendas (admin/vendas.php, admin/venda.php,
    -- admin/vendas_inbox.php), nunca no funil de compra — ver
    -- includes/security.php::podeAcessarVendas().
    -- 'financeiro' adicionado em 17/09/2026 (pedido José/Jean: "criar
    -- perfil gestão financeira") — módulo financeiro próprio
    -- (admin/financeiro*.php), lançamentos/categorias/fornecedores/
    -- colaboradores + integração Asaas — ver includes/security.php::
    -- podeAcessarFinanceiro().
    perfil TEXT DEFAULT 'consultor' CHECK (perfil IN ('super_admin','closer','consultor','supervisor','vendedor','financeiro')),
    bloqueado INTEGER DEFAULT 0,

    -- Fila de distribuição automática de leads (decisão do Jean,
    -- includes/fila_leads.php): toggle manual que o próprio consultor liga/
    -- desliga no admin ao começar/terminar o expediente.
    disponivel INTEGER DEFAULT 0,
    -- Marca o(s) usuário(s) de plantão de fim de expediente — só entram na
    -- distribuição quando NINGUÉM normal está com disponivel=1, nunca
    -- competem pelo rodízio normal mesmo que também estejam "disponível".
    plantao_fim_expediente INTEGER DEFAULT 0,
    -- Round-robin: quem recebeu lead há mais tempo (ou nunca recebeu, NULL
    -- primeiro) é o próximo da fila — evita sempre sobrecarregar o mesmo.
    -- ultimo_lead_recebido_em é só informativo (mostrado no admin); a ordem
    -- de verdade usa posicao_fila (contador monotônico) — timestamp sozinho
    -- empata quando 2 leads chegam no mesmo segundo (granularidade do SQLite),
    -- e o contador nunca empata depois da 1ª atribuição de cada pessoa.
    ultimo_lead_recebido_em DATETIME,
    posicao_fila INTEGER DEFAULT 0,

    created_at DATETIME DEFAULT (datetime('now','localtime'))
);

-- Contratos gerados e enviados pra assinatura eletrônica (Assinafy) — mesmo
-- padrão do JurídicoSaaS (includes/assinafy.php). 1:N com oportunidades
-- porque pode gerar de novo (reenvio, correção) — histórico fica todo aqui.
CREATE TABLE IF NOT EXISTS contratos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
    -- Preenchido só quando tipo='venda' — desambigua QUAL negociação de
    -- revenda gerou esse contrato (um mesmo veículo/oportunidade pode ter
    -- mais de uma linha em `vendas` ao longo do tempo, se uma negociação
    -- cair e outro comprador aparecer depois). NULL pra contrato de compra.
    venda_id INTEGER REFERENCES vendas(id),
    tipo TEXT NOT NULL DEFAULT 'compra' CHECK (tipo IN ('compra', 'venda')),
    nome TEXT DEFAULT '',              -- nome do documento (ex: "Contrato de Compra - João Silva")
    campos_json TEXT DEFAULT '{}',     -- snapshot dos dados usados no merge, pra auditoria
    -- ZapSign substituiu a Assinafy em 13/09/2026 (includes/zapsign.php).
    -- assinafy_assignment_id não tem equivalente na ZapSign (cria
    -- documento+signatário numa chamada só) — mantido só por histórico de
    -- contrato antigo, nunca mais escrito.
    zapsign_doc_token TEXT DEFAULT '',
    zapsign_signer_token TEXT DEFAULT '',
    assinafy_assignment_id TEXT DEFAULT '',
    sign_url TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'gerado'
        CHECK (status IN ('gerado', 'enviado', 'visualizado', 'assinado', 'recusado', 'erro')),
    motivo_recusa TEXT DEFAULT '',
    -- Quando o status virou 'assinado' de verdade (não confundir com
    -- updated_at, que muda em qualquer sincronização) — pedido pra mostrar
    -- "que dia ele assina contrato" no detalhe do cliente.
    assinado_em DATETIME,
    -- PDF do contrato — Drive é preferido, arquivo_url é fallback local
    -- (storage/uploads/, mesmo padrão de oportunidade_documentos); começa a
    -- existir já na geração (ainda sem assinar) e é sobrescrito pela versão
    -- assinada quando ela chega — sempre é "a versão mais atual", pra dar
    -- pra visualizar em admin/ver_contrato.php a qualquer momento.
    drive_file_id TEXT DEFAULT '',
    arquivo_url TEXT DEFAULT '',
    pdf_assinado_url TEXT DEFAULT '',  -- não usado (nunca escrito) — mantido só por compat com bancos já criados
    created_by INTEGER REFERENCES usuarios(id),
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_contratos_oportunidade ON contratos(oportunidade_id);
CREATE INDEX IF NOT EXISTS idx_contratos_zapsign_doc ON contratos(zapsign_doc_token);

-- Módulo de VENDAS (14/09/2026) — Fastcar revende um veículo já comprado
-- (frota = oportunidades com etapa='fechado'). Cada linha aqui é UMA
-- negociação de revenda pra um veículo específico; pode ter mais de uma ao
-- longo do tempo se uma cair (etapa='cancelada') e outro comprador
-- aparecer depois — por isso 1:N com oportunidades, nunca 1:1. Dados do
-- veículo em si (marca/modelo/placa/renavam/chassi/banco_financiamento/
-- saldo_financiamento_atual) NÃO são duplicados aqui — vêm sempre de
-- oportunidades (mesma fonte de verdade); só o que é específico da REVENDA
-- (comprador, condições da venda) mora aqui. Segue o mesmo padrão de
-- disciplina de etapa do funil de compra (mudarEtapaVenda(), nunca UPDATE
-- direto — includes/vendas.php).
CREATE TABLE IF NOT EXISTS vendas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Nullable desde 17/09/2026 (lead por WhatsApp, ver includes/vendas.php
    -- ::criarOuAbrirVendaLead()) — um comprador pode entrar em contato ANTES
    -- de saber qual veículo específico da frota quer; só vira NOT NULL de
    -- fato quando o vendedor confirma o match. Negociação criada manualmente
    -- (botão "Vender" em admin/veiculos.php, fluxo original) continua
    -- nascendo com oportunidade_id já preenchido, etapa='negociacao' direto.
    oportunidade_id INTEGER REFERENCES oportunidades(id),

    -- 'whatsapp'/'qualificacao_ia'/'sem_perfil' (17/09/2026) — espelham as
    -- etapas de entrada do funil de COMPRA (includes/oportunidades.php),
    -- só usadas quando origem='whatsapp': lead ainda conversando com a IA,
    -- antes de virar negociação de verdade com veículo confirmado.
    -- Negociação criada manualmente nunca passa por elas, vai direto pra
    -- 'negociacao'.
    etapa TEXT NOT NULL DEFAULT 'negociacao'
        CHECK (etapa IN ('whatsapp', 'qualificacao_ia', 'negociacao', 'contrato_enviado', 'vendido', 'cancelada', 'sem_perfil')),
    -- 'manual' (padrão, fluxo original — vendedor/admin abre a partir de um
    -- veículo já na frota) ou 'whatsapp' (lead entrou sozinho pela instância
    -- Z-API dedicada de vendas, qualificado por IA antes de virar negociação).
    origem TEXT NOT NULL DEFAULT 'manual' CHECK (origem IN ('manual', 'whatsapp')),
    -- Atribuição de clique em anúncio (19/09/2026, "sistema registrar
    -- campanhas de vendas também") — mesmo mecanismo de
    -- oportunidades.canal_origem/campanha_origem/anuncio_origem
    -- (extrairOrigemAnuncio(), chatbot-whatsapp/includes/mensagens.php,
    -- já genérica), só que só é preenchido quando origem='whatsapp'; lead
    -- criado manualmente (botão "Vender" na frota) nunca tem clique de
    -- anúncio nenhum por trás, fica vazio mesmo.
    canal_origem TEXT DEFAULT '',
    campanha_origem TEXT DEFAULT '',
    anuncio_origem TEXT DEFAULT '',
    responsavel_id INTEGER REFERENCES usuarios(id),
    proxima_acao TEXT DEFAULT '',
    proxima_acao_em DATETIME,
    motivo_cancelamento TEXT DEFAULT '',
    -- Motivo de 'sem_perfil' (mesmo espírito de oportunidades.motivo_perda).
    motivo_perda TEXT DEFAULT '',
    -- Resumo gerado pela IA ao concluir a qualificação (bloco equivalente ao
    -- resumo_ia de oportunidades) — o vendedor que assumir vê isso, não só
    -- o card vazio (mesma regra #4 do CLAUDE.md aplicada aqui).
    resumo_ia TEXT DEFAULT '',
    -- O que o comprador disse que procura, em texto livre, ANTES de
    -- confirmar/vincular um veículo específico da frota (oportunidade_id) —
    -- nunca inventado, só o que a IA extraiu da conversa.
    veiculo_interesse_texto TEXT DEFAULT '',
    -- Uso pretendido do veículo — "passeio"/"aplicativo"/"utilitario" (sem
    -- CHECK de propósito: extraído por IA, texto livre em vez de enum
    -- travado, pra nunca bloquear um valor que não bata 100% com as 3
    -- opções sugeridas) — 17/09/2026, pedido José/Jean: "tipo de carro
    -- para passeio o aplicativo utilitário".
    tipo_uso_veiculo TEXT DEFAULT '',
    forma_pagamento_pretendida TEXT DEFAULT '',
    -- Orçamento do comprador (17/09/2026, "qual a entrada valor da entrada
    -- que você tem, valor da parcela em seu orçamento") — nunca inventado,
    -- só o que a pessoa disse; nullable de propósito (regra #3, não é
    -- "0" quando não informado, é "não sabemos ainda").
    valor_entrada_disponivel REAL,
    valor_parcela_orcamento REAL,
    urgencia TEXT DEFAULT '',
    -- Leitura viva da IA sobre a prontidão de compra do lead (19/09/2026,
    -- "crm tem tá preechido igual na compra lead quente frio e mornos") —
    -- mesmo conceito de oportunidades.temperatura_lead, mas critério
    -- adaptado ao COMPRADOR: sinal principal é ter orçamento definido
    -- (entrada/parcela) + veículo específico já identificado + urgência
    -- real pra decidir = "quente"; ainda pesquisando, sem orçamento nem
    -- veículo confirmado, sem pressa = "frio"; "morno" no meio.
    temperatura_lead TEXT DEFAULT '' CHECK (temperatura_lead IN ('', 'frio', 'morno', 'quente')),
    -- Último oportunidade_id (frota) pra quem a IA já mandou foto/vídeo
    -- do catálogo NESTA conversa (17/09/2026, "ela precisa enviar fotos
    -- do veículos - vídeo") — evita mandar a mesma mídia de novo a cada
    -- turno enquanto o comprador ainda não confirmou o vínculo de verdade
    -- (vincularVeiculoVenda(), sempre humano). NULL = nunca mandou nada
    -- ainda nesta conversa.
    midia_sugerida_enviada_para INTEGER REFERENCES oportunidades(id),

    -- Qualificação civil do COMPRADOR — colunas próprias aqui, não
    -- `clientes`: comprador de revenda é um contato diferente do vendedor
    -- original que trouxe o veículo pra Fastcar, e o mesmo telefone
    -- poderia em tese aparecer nos dois papéis em momentos diferentes —
    -- clientes.telefone é UNIQUE pro funil de COMPRA, não serviria aqui.
    comprador_nome TEXT DEFAULT '',
    comprador_nacionalidade TEXT DEFAULT 'brasileiro(a)',
    comprador_estado_civil TEXT DEFAULT '',
    comprador_profissao TEXT DEFAULT '',
    comprador_rg TEXT DEFAULT '',
    comprador_cpf TEXT DEFAULT '',
    comprador_cnh TEXT DEFAULT '',
    comprador_endereco TEXT DEFAULT '',
    comprador_telefone TEXT DEFAULT '',
    comprador_email TEXT DEFAULT '',
    -- Token do wizard público de documentos do COMPRADOR (19/09/2026,
    -- "espelhar compra - subir os documentos... ter link igual de compra"),
    -- mesmo mecanismo de oportunidades.documentos_token — link mandado via
    -- WhatsApp, sem login (public/documentos_venda.php).
    documentos_token TEXT,
    -- Mesmo mecanismo de oportunidades.documentos_confirmados_em — marca
    -- que o comprador já revisou o resumo final e confirmou; editar um
    -- documento depois disso zera de novo, forçando revisão (mesmo rito
    -- do wizard de compra).
    documentos_confirmados_em DATETIME,

    -- Condições da venda — Quadro-Resumo do contrato-mestre de venda
    -- (includes/contratos_pdf.php::gerarPdfContratoVenda(), transcrito de
    -- 01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx)
    km_entrega INTEGER,
    preco_venda REAL,                  -- "Preço ajustado entre FASTCAR e COMPRADOR"
    valor_pago_contratacao REAL,       -- valor pago pelo comprador na contratação
    forma_pagamento TEXT DEFAULT '',
    saldo_preco_devido REAL,           -- NULL/0 = "inexistente" no Quadro-Resumo
    prazo_quitacao_meses INTEGER DEFAULT 24,  -- nunca > 24 (mesmo limite da cláusula 3.1 do modelo)
    data_limite_quitacao DATE,
    prestacao_contas_texto TEXT DEFAULT '',
    seguro_texto TEXT DEFAULT '',
    ipva_responsavel_texto TEXT DEFAULT '',
    multas_texto TEXT DEFAULT 'COMPRADOR, na extensão legal aplicável',
    rastreador_texto TEXT DEFAULT '',
    prazo_transferencia_dias INTEGER,
    penalidade_atraso_texto TEXT DEFAULT '',

    data_venda DATE,                   -- quando etapa vira 'vendido'

    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_vendas_oportunidade ON vendas(oportunidade_id);
CREATE INDEX IF NOT EXISTS idx_vendas_etapa ON vendas(etapa);
CREATE INDEX IF NOT EXISTS idx_vendas_proxima_acao ON vendas(proxima_acao_em);
-- Só 1 negociação ATIVA por veículo por vez (negociacao/contrato_enviado)
-- — trava também no banco (índice único parcial), não só na aplicação;
-- uma negociação cancelada libera o veículo pra uma nova tentativa.
CREATE UNIQUE INDEX IF NOT EXISTS idx_vendas_ativa_por_veiculo
    ON vendas(oportunidade_id) WHERE etapa IN ('negociacao', 'contrato_enviado');

-- Catálogo de fotos/vídeos de um veículo da frota, pra revenda (17/09/2026,
-- pedido José/Jean: "ela precisa enviar fotos do veículos - vídeo") — vive
-- ligado à OPORTUNIDADE (o veículo em si), não a uma negociação de venda
-- específica: uma vez cadastrado, fica disponível pra qualquer tentativa de
-- venda futura desse mesmo veículo (negociação cancelada + reaberta com
-- outro comprador reaproveita o mesmo catálogo, sem reupload). Mesmo padrão
-- Drive-preferido/local-fallback de oportunidade_documentos/whatsapp_mensagens
-- (includes/vendas.php::salvarMidiaRevenda()) — arquivo entra na MESMA pasta
-- do Drive do cliente original (que já tem os documentos de compra desse
-- veículo), sem precisar de pasta nova.
CREATE TABLE IF NOT EXISTS veiculo_midias_revenda (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
    tipo TEXT NOT NULL CHECK (tipo IN ('foto', 'video')),
    mime TEXT NOT NULL DEFAULT '',
    drive_file_id TEXT DEFAULT '',
    arquivo_url TEXT DEFAULT '',
    legenda TEXT DEFAULT '',
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_veiculo_midias_oportunidade ON veiculo_midias_revenda(oportunidade_id);

-- Mesma disciplina de histórico do funil de compra (regra #6) — nunca
-- UPDATE direto em vendas.etapa, sempre por mudarEtapaVenda()
-- (includes/vendas.php), que grava aqui junto.
CREATE TABLE IF NOT EXISTS venda_historico (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    venda_id INTEGER NOT NULL REFERENCES vendas(id),
    etapa_anterior TEXT DEFAULT '',
    etapa_nova TEXT NOT NULL,
    responsavel_id INTEGER REFERENCES usuarios(id),
    observacao TEXT DEFAULT '',
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_venda_historico_venda ON venda_historico(venda_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_vendas_documentos_token
    ON vendas(documentos_token) WHERE documentos_token IS NOT NULL;

-- Documentos que o COMPRADOR sobe sozinho no wizard público, espelhando
-- oportunidade_documentos do lado da compra (19/09/2026, "espelhar compra -
-- subir os documentos preencher tudo ter link igual de compra... analisar
-- contrato antes enviar"), mas tabela PRÓPRIA — nunca reaproveita
-- oportunidade_documentos, que é sobre o VENDEDOR original (dono anterior
-- do carro), um contato diferente do comprador da revenda. Escopo de
-- documento bem mais enxuto que o de compra: só CNH/RG + comprovante de
-- endereço (comprador de revenda não tem financiamento ativo nem CRLV pra
-- entregar — os outros 2 tipos que o wizard de compra pede não fazem
-- sentido aqui, decisão confirmada com o usuário).
CREATE TABLE IF NOT EXISTS venda_documentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    venda_id INTEGER NOT NULL REFERENCES vendas(id),
    tipo TEXT NOT NULL,                 -- cnh, comprovante_endereco
    arquivo_url TEXT DEFAULT '',        -- caminho relativo em storage/uploads/ — só quando NÃO subiu pro Drive (fallback)
    drive_file_id TEXT DEFAULT '',      -- preenchido quando o arquivo foi pro Google Drive
    obrigatorio INTEGER DEFAULT 1,
    enviado_pelo_cliente INTEGER DEFAULT 0, -- 1 = veio do wizard público, 0 = vendedor anexou manualmente
    dados_confirmados INTEGER DEFAULT 0,    -- mesmo mecanismo do wizard de compra: decide qual etapa mostrar
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime')),
    UNIQUE(venda_id, tipo)
);
CREATE INDEX IF NOT EXISTS idx_venda_documentos_venda ON venda_documentos(venda_id);

-- ── Módulo financeiro (17/09/2026, pedido José/Jean: "tem modulo
-- financeiro no iab boutique - precisamos copia de la para colocar aqui") —
-- portado do repo irmão JurídicoSaaS (includes/financeiro_dre.php,
-- admin/financeiro-*.php), mas adaptado: nunca copy-paste direto (modelo de
-- dado diferente demais — lá é honorário de processo jurídico, aqui é
-- compra/revenda de veículo financiado), mesmo espírito já documentado no
-- CLAUDE.md pra WhatsApp Box/etc. Diferenças da versão original:
-- (1) fin_lancamentos ganha oportunidade_id/venda_id — liga um lançamento
--     ao negócio de COMPRA ou de VENDA (revenda) que o originou, sem
--     duplicar dado nenhum dos dois módulos;
-- (2) cliente_nome_manual (TEXT livre) cobre "relacionamento de clientes
--     pode ser manual" (pedido explícito) — nem todo lançamento tem um
--     cliente_id de verdade pra linkar (ex: comprador de revenda não vira
--     linha em `clientes`, só existe em vendas.comprador_*; e cobrança
--     importada do Asaas pode não ter NENHUM match ainda) — o nome fica
--     sempre visível mesmo sem link nenhum, e o vínculo com cliente_id/
--     venda_id/oportunidade_id é sempre uma ação humana explícita (nunca a
--     IA/sistema decide/chuta o match sozinho, mesma regra #3 do projeto);
-- (3) anexo vira drive_file_id/arquivo_url (não uma URL pública do Drive
--     tornada pública com makePublic()) — mesmo padrão Drive-preferido/
--     local-fallback + servido por proxy (admin/ver_anexo_financeiro.php)
--     já usado em toda parte do Fastcar, nunca link público direto;
-- (4) parcela_numero/parcela_total + origem ('manual'/'asaas'/
--     'parcelamento_venda') — Fastcar vende veículo da frota financiado
--     (entrada + parcelas) pro comprador, o JurídicoSaaS original não tinha
--     esse conceito (lançamento de lá é sempre avulso, 1 vencimento só);
-- (5) asaas_payment_id/asaas_customer_id — pedido junto no mesmo dia
--     ("vamos integrar api do assas pra puxar tudo de lá"): cobrança já
--     em uso no Asaas pra cobrar cliente de venda parcelada, importada e
--     sincronizada (includes/asaas.php) — dedup por asaas_payment_id.
CREATE TABLE IF NOT EXISTS fin_categorias (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    tipo TEXT NOT NULL DEFAULT 'despesa' CHECK (tipo IN ('receita','despesa')),
    natureza_sugerida TEXT DEFAULT '',
    icone TEXT DEFAULT '💰',
    grupo_dre TEXT DEFAULT '',
    ativo INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS fin_fornecedores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    cnpj_cpf TEXT DEFAULT '',
    contato TEXT DEFAULT '',
    observacoes TEXT DEFAULT '',
    status TEXT DEFAULT 'ativo' CHECK (status IN ('ativo','inativo')),
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS fin_colaboradores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    cargo TEXT DEFAULT '',
    tipo_vinculo TEXT DEFAULT 'clt',
    salario_base REAL DEFAULT NULL,
    -- 'mensal'/'quinzenal' — 17/09/2026, "Os consultores eles ganha o fixo
    -- cada 15 dias mais comissão". Comissão em si NUNCA é gerada daqui —
    -- é sempre lançamento manual avulso (confirmado com o usuário:
    -- "comissão é lançado manual"), este campo é só o fixo/salário_base.
    -- Ainda não existe cron gerando o lançamento quinzenal sozinho — o
    -- usuário confirmou que isso fica pra configurar depois
    -- ("pagamento vai rodar no cron cada 15 dias podemos configurar
    -- depois isso"); por enquanto é só um dado do colaborador, usado pra
    -- saber a periodicidade na hora de lançar manualmente.
    periodicidade_pagamento TEXT DEFAULT 'mensal',
    usuario_id INTEGER DEFAULT NULL REFERENCES usuarios(id),
    status TEXT DEFAULT 'ativo' CHECK (status IN ('ativo','inativo')),
    data_admissao TEXT DEFAULT NULL,
    observacoes TEXT DEFAULT '',
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS fin_lancamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL DEFAULT 'despesa' CHECK (tipo IN ('receita','despesa')),
    categoria_id INTEGER DEFAULT NULL REFERENCES fin_categorias(id),
    descricao TEXT NOT NULL,
    valor REAL NOT NULL DEFAULT 0,
    natureza TEXT DEFAULT '', -- 'fixa'/'variavel', só despesa
    data_vencimento TEXT DEFAULT NULL,
    data_pagamento TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','pago','atrasado','cancelado')),
    -- Vínculo com cliente: sempre um dos 2, nunca obrigatório — ver nota
    -- (2) acima ("relacionamento de clientes pode ser manual").
    cliente_id INTEGER DEFAULT NULL REFERENCES clientes(id),
    cliente_nome_manual TEXT DEFAULT '',
    -- Vínculo com o negócio que originou o lançamento — nunca os dois ao
    -- mesmo tempo na prática (um lançamento é de COMPRA ou de VENDA), mas
    -- nada no schema força isso, decisão de quem lança.
    oportunidade_id INTEGER DEFAULT NULL REFERENCES oportunidades(id),
    venda_id INTEGER DEFAULT NULL REFERENCES vendas(id),
    -- Preenchidos só quando faz parte de um plano de parcelamento (entrada
    -- conta como parcela_numero=0) — ver nota (4) acima.
    parcela_numero INTEGER DEFAULT NULL,
    parcela_total INTEGER DEFAULT NULL,
    funcionario_id INTEGER DEFAULT NULL REFERENCES fin_colaboradores(id),
    fornecedor_id INTEGER DEFAULT NULL REFERENCES fin_fornecedores(id),
    forma_pagamento TEXT DEFAULT '',
    recorrente INTEGER DEFAULT 0,
    recorrencia_intervalo TEXT DEFAULT '', -- 'mensal'/'quinzenal'/'anual'
    recorrencia_origem_id INTEGER DEFAULT NULL,
    drive_file_id TEXT DEFAULT '',
    arquivo_url TEXT DEFAULT '',
    observacoes TEXT DEFAULT '',
    -- 'manual' (digitado na tela) / 'parcelamento_venda' (gerado ao fechar
    -- uma venda parcelada, sem Asaas) / 'asaas' (importado/criado via API
    -- Asaas) / 'fechamento_compra' (despesa automática ao fechar uma
    -- compra, 19/09/2026) / 'recorrencia_fixa' (despesa fixa gerada
    -- automaticamente pro mês seguinte por cron/lancamentos_fixos.php,
    -- 19/09/2026, "todas despesas fixas pode lançar todo mês automático")
    -- — ver nota (5) acima.
    origem TEXT NOT NULL DEFAULT 'manual' CHECK (origem IN ('manual','parcelamento_venda','asaas','fechamento_compra','recorrencia_fixa')),
    asaas_payment_id TEXT DEFAULT NULL,
    asaas_customer_id TEXT DEFAULT NULL,
    created_by INTEGER DEFAULT NULL REFERENCES usuarios(id),
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_venda ON fin_lancamentos(venda_id);
CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_oportunidade ON fin_lancamentos(oportunidade_id);
CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_status ON fin_lancamentos(status);
CREATE UNIQUE INDEX IF NOT EXISTS idx_fin_lancamentos_asaas_payment ON fin_lancamentos(asaas_payment_id) WHERE asaas_payment_id IS NOT NULL;

-- Cache/mapeamento dos clientes já cadastrados no Asaas (17/09/2026,
-- "puxar tudo de lá") — existe independente de fin_lancamentos porque um
-- cliente pode estar cadastrado no Asaas sem nenhuma cobrança ainda (ou já
-- ter sido importado, mas a cobrança em si falhar de importar). cliente_id/
-- venda_id ficam NULL até alguém da equipe linkar manualmente (nunca
-- automático — nome/CPF batendo por acaso não é garantia suficiente).
CREATE TABLE IF NOT EXISTS fin_asaas_clientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asaas_id TEXT NOT NULL UNIQUE,
    nome TEXT DEFAULT '',
    cpf_cnpj TEXT DEFAULT '',
    email TEXT DEFAULT '',
    telefone TEXT DEFAULT '',
    cliente_id INTEGER DEFAULT NULL REFERENCES clientes(id),
    venda_id INTEGER DEFAULT NULL REFERENCES vendas(id),
    created_at DATETIME DEFAULT (datetime('now','localtime')),
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);
