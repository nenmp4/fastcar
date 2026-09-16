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
    concluido_em DATETIME,
    created_at DATETIME DEFAULT (datetime('now','localtime'))
);

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
    perfil TEXT DEFAULT 'consultor' CHECK (perfil IN ('super_admin','closer','consultor','supervisor')),
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
    oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),

    etapa TEXT NOT NULL DEFAULT 'negociacao'
        CHECK (etapa IN ('negociacao', 'contrato_enviado', 'vendido', 'cancelada')),
    responsavel_id INTEGER REFERENCES usuarios(id),
    proxima_acao TEXT DEFAULT '',
    proxima_acao_em DATETIME,
    motivo_cancelamento TEXT DEFAULT '',

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
