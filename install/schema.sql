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
    canal_origem TEXT DEFAULT '',      -- facebook_ads, google_ads, whatsapp_direto, etc
    campanha_origem TEXT DEFAULT '',
    anuncio_origem TEXT DEFAULT '',
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

    -- Bloco 3 — Qualificação IA (dados do veículo/financiamento)
    -- Marca separada do modelo pra dar pra validar contra a lista oficial
    -- da FIPE (includes/fipe.php::fipeValidarMarca()) sem heurística de
    -- "primeira palavra do texto" — nunca 100% confiável.
    veiculo_marca TEXT DEFAULT '',
    veiculo_modelo TEXT DEFAULT '',
    veiculo_ano TEXT DEFAULT '',
    banco_financiamento TEXT DEFAULT '',
    valor_parcela REAL,
    parcelas_restantes INTEGER,
    parcelas_atraso INTEGER DEFAULT 0,
    valor_pretendido REAL,             -- quanto o cliente quer pelo veículo
    resumo_ia TEXT DEFAULT '',         -- resumo da conversa gerado pela IA pro consultor

    -- Bloco 6 — Negociação (Jean/closer)
    valor_ofertado REAL,
    valor_contraproposta REAL,
    condicoes_negociacao TEXT DEFAULT '',
    aprovado_por INTEGER REFERENCES usuarios(id),
    motivo_perda TEXT DEFAULT '',

    -- Bloco 7 — Presencial/fechamento
    reuniao_agendada_em DATETIME,
    avaliacao_veiculo TEXT DEFAULT '',
    documentos_ok INTEGER DEFAULT 0,   -- checklist obrigatório antes de liberar "fechado"
    contrato_assinado INTEGER DEFAULT 0,

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
    enviado_por_ia INTEGER DEFAULT 0,   -- 1 = resposta automática da IA, 0 = humano
    zapi_message_id TEXT DEFAULT '',    -- messageId do Z-API — dedup de webhook reenviado
    -- NULL = veio pela instância principal (funil oficial). Preenchido = veio
    -- pela instância Z-API própria de um consultor/closer (zapi_instancias_consultores)
    -- — o telefone do cliente é o mesmo, então a conversa entra no mesmo
    -- histórico automaticamente; isso só marca QUEM falou por qual canal,
    -- pra dar pra ver o que cada consultor conversa com o cliente e medir
    -- volume por pessoa.
    usuario_id INTEGER REFERENCES usuarios(id),
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_wpp_telefone ON whatsapp_mensagens(telefone, id DESC);
CREATE INDEX IF NOT EXISTS idx_wpp_usuario ON whatsapp_mensagens(usuario_id, created_at);
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
    updated_at DATETIME DEFAULT (datetime('now','localtime'))
);

-- Pasta fechada (bloco 8) — documentos e comprovantes vinculados à
-- oportunidade. "Compra concluída exige checklist" — cada tipo obrigatório
-- deve ter uma linha aqui antes de liberar etapa='fechado' na aplicação.
CREATE TABLE IF NOT EXISTS oportunidade_documentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
    tipo TEXT NOT NULL,                 -- contrato, comprovante_pagamento, laudo_avaliacao, etc
    arquivo_url TEXT DEFAULT '',
    obrigatorio INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
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
    concluido_em DATETIME,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT UNIQUE,
    whatsapp TEXT DEFAULT '',
    senha_hash TEXT NOT NULL,
    perfil TEXT DEFAULT 'consultor' CHECK (perfil IN ('super_admin','closer','consultor')),
    bloqueado INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);
