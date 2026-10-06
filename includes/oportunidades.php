<?php
/**
 * Camada central de manipulação de oportunidades — funil de 8 blocos.
 * Regra #6 do CLAUDE.md: NUNCA fazer UPDATE direto em oportunidades.etapa.
 * Toda mudança de etapa passa por mudarEtapa(), que grava o histórico junto.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/fila_leads.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/financeiro.php';

const ETAPAS_VALIDAS = [
    'whatsapp', 'qualificacao_ia', 'crm_preenchido', 'atendimento',
    'negociacao', 'presencial', 'fechado', 'sem_perfil', 'perdido',
];

const ETAPAS_ATIVAS = [
    'whatsapp', 'qualificacao_ia', 'crm_preenchido', 'atendimento',
    'negociacao', 'presencial',
];

function etapaLabel(string $etapa): string {
    $labels = [
        'whatsapp'        => '💬 WhatsApp',
        'qualificacao_ia' => '🤖 Qualificação IA',
        'crm_preenchido'  => '📋 CRM preenchido',
        'atendimento'     => '📞 Atendimento',
        'negociacao'      => '🤝 Negociação',
        // 22/09/2026, "precisamos fazer a avaliação antes de comprar...
        // cliente trouxe a moto precisamos mandar etapa para avaliação" —
        // reaproveita a mesma etapa "presencial" que já existia (bloco 7 do
        // funil já cobre "agenda reunião, avalia veículo, confirma
        // condições, executa compra"), só com rótulo mais explícito —
        // confirmado com o usuário que é o MESMO momento, não uma etapa
        // nova (evita mexer em ETAPAS_ATIVAS/dashboard/fila de leads).
        'presencial'      => '🔍 Em avaliação / Presencial',
        'fechado'         => '✅ Pasta fechada',
        'sem_perfil'      => '⚪ Sem perfil de compra',
        'perdido'         => '❌ Perdido',
    ];
    return $labels[$etapa] ?? ucfirst($etapa);
}

/**
 * Classe de badge (cores já existentes em admin/assets/style.css) pro
 * "status" visual de cada etapa — 21/09/2026, "mostra status da etapa":
 * verde pra fechado (sucesso), vermelho pra perdido, amarelo pra sem
 * perfil (neutro/atenção, não é bem uma "perda"), azul pras etapas ainda
 * ativas do funil (em andamento).
 */
function etapaBadgeClasse(string $etapa): string {
    return match ($etapa) {
        'fechado'    => 'badge-ok',
        'perdido'    => 'badge-atraso',
        'sem_perfil' => 'badge-aviso',
        default      => 'badge-info',
    };
}

/**
 * 28/09/2026, "separa eses leads" — o filtro de tipo de veículo
 * (oportunidades.tipo_veiculo) já existia, mas nenhum lead antigo tinha
 * sido classificado ainda (campo manual, nunca inferido — regra #3), então
 * filtrar por carro/moto/etc mostrava 0 resultados. Botão rápido de
 * classificar em lote no dashboard (admin/index.php) chama isto — SÓ
 * grava tipo_veiculo, nunca mexe em marca/modelo/placa/etc (diferente da
 * ação `atualizar_veiculo` de admin/oportunidade.php, que sobrescreve o
 * formulário inteiro — reaproveitar aquela pra um clique rápido apagaria
 * os outros campos do veículo).
 */
function classificarTipoVeiculo(int $oportunidadeId, string $tipo): void {
    $tiposValidos = ['carro', 'moto', 'caminhao', 'outro'];
    if (!in_array($tipo, $tiposValidos, true)) {
        throw new InvalidArgumentException('Tipo de veículo inválido.');
    }
    getDB()->prepare("
        UPDATE oportunidades SET tipo_veiculo = ?, updated_at = datetime('now','localtime') WHERE id = ?
    ")->execute([$tipo, $oportunidadeId]);
}

/**
 * 28/09/2026, "sem tipo não identifica quem carro moto e outros sera tem
 * aguma api que ver modelo de carro e moto" — não existe API confiável
 * genérica pra isso; a mais próxima que o projeto já tem (PlacaFIPE) só
 * funciona por PLACA, que a maioria dos leads ainda não tem capturada
 * nesse ponto do funil. Heurística LOCAL por palavra-chave de marca/
 * modelo — NUNCA decide sozinha (regra #3): só sugere o botão mais
 * provável na tela (admin/index.php) pra virar 1 clique de confirmação em
 * vez de precisar pensar em cada um dos leads sem tipo; sem palavra
 * reconhecida, retorna null (fica 100% manual, nunca força um chute).
 * Lista deliberadamente NÃO-exaustiva, cobre os modelos/marcas mais
 * comuns do mercado financiado brasileiro — ajustar se aparecer padrão
 * novo recorrente nos leads reais.
 */
function sugerirTipoVeiculo(?string $marca, ?string $modelo): ?string {
    $marca = trim((string)$marca);
    $modelo = trim((string)$modelo);
    $texto = mb_strtoupper($marca . ' ' . $modelo);
    if (trim($texto) === '') {
        return null;
    }

    // Marcas que só vendem moto no Brasil — sinal forte sozinho, sem
    // precisar olhar o modelo (diferente de Honda/Suzuki, que vendem os
    // dois — pra essas só o modelo decide, ver listas abaixo).
    $marcasMoto = ['YAMAHA', 'KAWASAKI', 'DUCATI', 'TRIUMPH', 'HARLEY', 'DAFRA', 'SHINERAY', 'HAOJUE', 'KASINSKI', 'TRAXX', 'ROYAL ENFIELD'];
    // Marcas que só vendem caminhão/van de carga no Brasil.
    $marcasCaminhao = ['SCANIA', 'IVECO', 'DAF ', 'MAN '];

    $modelosMoto = [
        'CG', 'TITAN', 'FAN', 'BIZ', 'POP', 'BROS', 'XRE', 'CROSSER', 'TWISTER',
        'CB', 'CBR', 'HORNET', 'FALCON', 'ADV', 'SH150', 'SH300', 'ELITE', 'LEAD',
        'NMAX', 'FAZER', 'FACTOR', 'LANDER', 'TENERE', 'XTZ', 'YBR', 'MT-', 'FZ',
        'R15', 'R3', 'BURGMAN', 'INTRUDER', 'GSX', 'GIXXER', 'BANDIT', 'KATANA',
        'NINJA', 'VERSYS', 'COMET', 'METEOR', 'PULSAR', 'PCX', 'TRICITY',
    ];
    $modelosCaminhao = ['SPRINTER', 'DAILY', ' HR ', 'BONGO', 'ACCELO', 'ATEGO', 'ACTROS', 'CONSTELLATION', 'CARGO', 'WORKER', 'DELIVERY', 'AXOR', 'VUC'];
    // Só usado como sinal POSITIVO de "carro" — nunca o fallback padrão de
    // tudo que não bateu moto/caminhão (regra #3: sem sinal nenhum, fica
    // sem sugestão, nunca chuta).
    $modelosCarro = [
        'ONIX', 'COROLLA', 'CIVIC', 'GOL', 'FIESTA', ' KA ', 'COMPASS', 'HB20',
        'UNO', 'PALIO', 'VOYAGE', 'STRADA', 'SAVEIRO', 'HILUX', 'RANGER', 'S10',
        'TORO', 'RENEGADE', 'DUSTER', 'KICKS', 'CRETA', 'TUCSON', 'SPORTAGE',
        'CR-V', 'CRV', 'HR-V', 'HRV', 'FIT', 'CITY', 'VERSA', 'SENTRA', 'MARCH',
        'LOGAN', 'SANDERO', 'ARGO', 'CRONOS', 'MOBI', ' UP ', 'POLO', 'VIRTUS',
        'JETTA', 'T-CROSS', 'NIVUS', 'TIGUAN', 'AMAROK', 'SPIN', 'PRISMA',
        'COBALT', 'TRACKER', 'EQUINOX', 'CAPTIVA', 'MERIVA', 'CORSA', 'CELTA',
        'ASTRA', 'VECTRA', 'ECOSPORT', 'EDGE', 'FUSION', 'FOCUS', 'MONDEO',
        'AIRCROSS', 'PICASSO', 'PARTNER', ' C3', ' C4', '208', '2008', '3008',
        '408', '308', 'FLUENCE', 'CAPTUR', 'KWID', 'MEGANE', 'CLIO', 'ACCORD',
        'YARIS', 'ETIOS', 'SW4', 'X1', 'X3', 'SERIE 3', 'A3', 'A4', 'Q3', 'Q5',
        'GOLF', 'PASSAT',
    ];

    foreach ($marcasMoto as $m) { if (str_contains($texto, $m)) return 'moto'; }
    foreach ($marcasCaminhao as $m) { if (str_contains($texto, $m)) return 'caminhao'; }
    foreach ($modelosMoto as $m) { if (str_contains($texto, $m)) return 'moto'; }
    foreach ($modelosCaminhao as $m) { if (str_contains($texto, $m)) return 'caminhao'; }
    foreach ($modelosCarro as $m) { if (str_contains($texto, $m)) return 'carro'; }

    // Modelo sozinho é só um número de cilindrada típica de moto brasileira
    // (CG160/Fan160/Titan160/NMax160 etc, capturado pela qualificação por
    // IA sem marca nenhuma junto — caso real visto em produção, "160
    // 2024"). Sinal mais fraco que os de cima, mas nunca usado como nome
    // de carro no Brasil, então ainda vale sugerir.
    if (preg_match('/^(100|110|125|150|160|190|200|250|300)$/', mb_strtoupper($modelo))) {
        return 'moto';
    }

    return null;
}

/**
 * Cria (ou reaproveita) o cliente por telefone e já abre a oportunidade na
 * etapa 'whatsapp' — regra #2: "salvar desde o primeiro contato", mesmo
 * antes de qualquer qualificação.
 */
/**
 * @param array $origem Atribuição de anúncio (bloco 1) — canal_origem,
 *   campanha_origem, anuncio_origem. Só é gravada na CRIAÇÃO do cliente
 *   (first-touch); se o telefone já existe, a origem original é mantida
 *   — inclusive se essa pessoa clicar num anúncio diferente meses depois
 *   pra negociar um 2º veículo (limitação conhecida: origem vive em
 *   `clientes`, não em `oportunidades`, então não temos atribuição por
 *   negócio pra quem repete contato — só first-touch por telefone).
 */
function criarOuAbrirOportunidade(string $telefone, string $nome = '', array $origem = []): array {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone inválido: {$telefone}");
    }

    $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
    $stmt->execute([$telNorm]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        $db->prepare("
            INSERT INTO clientes (nome, telefone, canal_origem, campanha_origem, anuncio_origem)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            clean($nome),
            $telNorm,
            clean((string)($origem['canal_origem'] ?? '')),
            clean((string)($origem['campanha_origem'] ?? '')),
            clean((string)($origem['anuncio_origem'] ?? '')),
        ]);
        $clienteId = (int)$db->lastInsertId();
        atualizarNomeFotoWhatsapp($clienteId, $telNorm);
    } else {
        $clienteId = (int)$cliente['id'];
        // Preenche nome se ainda estava vazio (ex: cliente criado só com
        // telefone via webhook, nome veio depois via qualificação)
        if (empty($cliente['nome']) && $nome) {
            $db->prepare("UPDATE clientes SET nome = ? WHERE id = ?")->execute([clean($nome), $clienteId]);
        }
        // foto_perfil_url NULL = nunca tentou buscar ainda; '' = já tentou e
        // não achou nada (não fica tentando de novo a cada mensagem nova
        // desse mesmo cliente, só 1x por cliente).
        $stmtFoto = $db->prepare("SELECT foto_perfil_url FROM clientes WHERE id = ?");
        $stmtFoto->execute([$clienteId]);
        if ($stmtFoto->fetchColumn() === null) {
            atualizarNomeFotoWhatsapp($clienteId, $telNorm);
        }
    }

    // Já existe oportunidade ativa aberta pra esse cliente? Reaproveita —
    // não cria uma segunda enquanto a primeira ainda está em andamento.
    // (Regra do Jean permite MAIS de um veículo por cliente, mas isso é
    // decisão explícita — ex: 2º carro depois do 1º já fechado/perdido —
    // não abertura automática de duplicata pela mesma mensagem de entrada.)
    $etapasAtivasPlaceholder = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
    $stmtOp = $db->prepare("
        SELECT id FROM oportunidades
        WHERE cliente_id = ? AND etapa IN ({$etapasAtivasPlaceholder})
        ORDER BY id DESC LIMIT 1
    ");
    $stmtOp->execute([$clienteId, ...ETAPAS_ATIVAS]);
    $existente = $stmtOp->fetch();

    if ($existente) {
        return ['cliente_id' => $clienteId, 'oportunidade_id' => (int)$existente['id'], 'nova' => false];
    }

    $db->prepare("INSERT INTO oportunidades (cliente_id, etapa) VALUES (?, 'whatsapp')")
       ->execute([$clienteId]);
    $opId = (int)$db->lastInsertId();

    // Distribuição automática de leads (decisão do Jean): já na entrada
    // (bloco 2), não só quando a IA termina de qualificar — quem estiver
    // disponível no rodízio pega o lead; se ninguém, cai no plantão de fim
    // de expediente; se nem isso, fica sem responsável (igual antes de
    // existir essa fila).
    $responsavelId = atribuirResponsavelAutomatico();
    if ($responsavelId !== null) {
        $db->prepare("UPDATE oportunidades SET responsavel_id = ? WHERE id = ?")->execute([$responsavelId, $opId]);
    }

    mudarEtapa($opId, 'whatsapp', $responsavelId, 'Oportunidade criada — entrada pelo WhatsApp'
        . ($responsavelId ? ' (atribuída automaticamente)' : ''));

    notificarNovoLeadWhatsapp($opId, $nome ?: '(sem nome)', $telNorm);

    return ['cliente_id' => $clienteId, 'oportunidade_id' => $opId, 'nova' => true, 'responsavel_id' => $responsavelId];
}

/**
 * Cadastra um veículo direto na frota (etapa='fechado'), SEM passar pelo
 * funil de compra pelo WhatsApp — 17/09/2026, pedido José/Jean: "vamos
 * implementar subir manual o veiculos fotos videos para ia vender
 * qualificar". Cobre veículo que a Fastcar já tem fisicamente (deal feito
 * fora do CRM, veículo antigo, etc) e precisa entrar na frota pra poder
 * ser revendido (módulo de vendas lê a frota via `etapa='fechado'`,
 * `includes/vendas.php::listarFrotaDisponivelParaVenda()`) e ter fotos/
 * vídeos pra IA de vendas usar (`veiculo_midias_revenda`).
 *
 * Cria (ou reaproveita por telefone) um cliente pro vendedor/origem —
 * decisão confirmada: mesmo cadastro manual precisa de um vendedor de
 * verdade por trás, mantém a pasta do veículo consistente com o resto do
 * sistema (regra #1: 1 cadastro por telefone). NUNCA chama
 * `atualizarNomeFotoWhatsapp()` (isso é pra contato real de WhatsApp, não
 * faz sentido bater na Z-API atrás de nome/foto de alguém que nem mandou
 * mensagem nenhuma).
 *
 * Insere a oportunidade JÁ em `etapa='fechado'` diretamente (não usa
 * `mudarEtapa()` pra essa transição de propósito — `mudarEtapa()` trava
 * fechamento sem `checklistFechamentoCompleto()`, regra #7, que é sobre o
 * checklist de documentos do funil normal de compra; um veículo que entra
 * assim nunca passou por esse funil, não tem porquê exigir os mesmos
 * documentos). Ainda assim grava `oportunidade_historico` manualmente,
 * pra manter a mesma disciplina de auditoria (regra #6) mesmo pulando
 * `mudarEtapa()`.
 */

/**
 * Gera um telefone PLACEHOLDER único pra `criarVeiculoManualFrota()`
 * quando não existe vendedor de verdade pra registrar — 28/09/2026,
 * "tem campos que não tem necessidade... carro recuperado pela fastcar",
 * confirmado "pode ser opcional": um veículo que a Fastcar RECUPEROU
 * (retomada, devolução sem contato ativo, etc) pode não ter ninguém pra
 * cadastrar como vendedor/telefone, mas `clientes.telefone` é `UNIQUE` e
 * `criarVeiculoManualFrota()` sempre exige um valor válido — inventar um
 * número que PARECE real seria dado falso (regra #3); em vez disso usa o
 * DDD `00` (nunca existe de verdade no Brasil, reconhecidamente
 * placeholder pra quem olhar o cadastro depois) + 9 dígitos aleatórios,
 * com retry contra a UNIQUE pra nunca colidir com um placeholder anterior.
 */
function gerarTelefonePlaceholderVeiculoRecuperado(): string {
    $db = getDB();
    for ($tentativa = 0; $tentativa < 5; $tentativa++) {
        $candidato = '00' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT 1 FROM clientes WHERE telefone = ?');
        $stmt->execute([normalizarTelefone($candidato)]);
        if (!$stmt->fetch()) return $candidato;
    }
    throw new RuntimeException('Não consegui gerar um telefone placeholder único — tente de novo.');
}

/**
 * Checa se um telefone já tem oportunidade ATIVA aberta — usado por
 * criarVeiculoManualFrota() (via admin/veiculos.php) pra AVISAR antes de
 * criar um 2º registro pro mesmo telefone (achado real, 28/09/2026: José
 * Bonifácio já tinha oportunidade #56 travada em qualificacao_ia desde o
 * WhatsApp quando um veículo foi cadastrado manualmente na Frota pro mesmo
 * telefone — #125 — criando 2 "ativas" pro mesmo cliente sem ninguém saber
 * da outra; confirmado depois que era o MESMO carro duplicado por engano).
 * Nunca bloqueia sozinho (regra #1 permite +1 veículo de verdade por
 * cliente) — só avisa, decisão de prosseguir é sempre humana.
 */
function buscarOportunidadeAtivaPorTelefone(string $telefone): ?array {
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm) return null;
    $db = getDB();
    $etapasAtivasPlaceholder = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
    $stmt = $db->prepare("
        SELECT o.id, o.etapa, o.veiculo_marca, o.veiculo_modelo, c.nome
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE c.telefone = ? AND o.etapa IN ({$etapasAtivasPlaceholder})
        ORDER BY o.id DESC LIMIT 1
    ");
    $stmt->execute([$telNorm, ...ETAPAS_ATIVAS]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Acha outra oportunidade JÁ na frota (etapa='fechado') com a MESMA placa —
 * 06/10/2026, "temos problema de subir mesmo carro... carro veio da
 * oportunidade" / "como evitar duplicar veiculo". Achado real: mesma
 * classe de bug já documentada em excluirVeiculoFrota() (23/09/2026, 2
 * cadastros duplicados de "Bianca", mesma placa/chassi, vindos da
 * importação do CRM antigo) — criarVeiculoManualFrota() nunca checava
 * placa/chassi, só telefone do vendedor, então cadastrar o MESMO carro
 * manualmente de novo (ex: já veio de uma oportunidade/venda existente e
 * alguém recadastra sem saber) criava uma 2ª linha em `oportunidades`
 * sem nenhum aviso. Mesma normalização já usada em
 * listarVistoriasPorPlaca() (maiúsculo, sem hífen/espaço) — placa curta
 * demais (<6, formato brasileiro mínimo) nunca dispara query, nunca
 * falso positivo. Nunca bloqueia (regra #3 — decisão sempre humana, pode
 * ser carro legitimamente parecido ou erro de digitação na placa já
 * cadastrada), só avisa — quem chama decide pedir confirmação explícita
 * antes de prosseguir, mesmo padrão de buscarOportunidadeAtivaPorTelefone()
 * acima.
 *
 * $excluirOportunidadeId (06/10/2026, achado real — oportunidade #272
 * ativa com a MESMA placa de um veículo já fechado/revendido como
 * Venda #31) — além dos 3 pontos que CRIAM veículo manualmente, o card
 * "Dados do veículo" de admin/oportunidade.php (ação `atualizar_veiculo`)
 * também precisava checar: aqui a oportunidade já EXISTE (criada pelo
 * telefone do lead, não pela placa), e o consultor/IA só está preenchendo
 * a placa depois — sem excluir a própria oportunidade da busca, salvar a
 * placa de um carro já fechado nela mesma (ex: resalvando sem mudar nada)
 * geraria um falso positivo de "duplicado" contra si mesma.
 */
function buscarVeiculoAtivoPorPlaca(string $placa, ?int $excluirOportunidadeId = null): ?array {
    $placaNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $placa));
    if ($placaNorm === '' || strlen($placaNorm) < 6) return null;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.id, o.etapa, o.veiculo_marca, o.veiculo_modelo, c.nome
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE UPPER(REPLACE(REPLACE(o.veiculo_placa, '-', ''), ' ', '')) = ? AND o.etapa = 'fechado'
              AND o.id != ?
        ORDER BY o.id DESC LIMIT 1
    ");
    $stmt->execute([$placaNorm, $excluirOportunidadeId ?? -1]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Cria uma oportunidade MANUALMENTE, sem passar pelo WhatsApp — 05/10/2026,
 * "opçao adcionar uma opotunidade manual". Cobre o lead que chegou por
 * ligação, indicação ou presencial — o consultor/admin já tem o contato (e,
 * às vezes, já o veículo) de verdade e quer registrar o negócio direto no
 * funil, sem fingir que passou por entrada no WhatsApp ou qualificação por
 * IA que nunca aconteceu.
 *
 * Entra direto em `etapa='crm_preenchido'` (bloco 4, já "concluído" — os
 * dados já vieram prontos de um humano, não tem IA pra rodar), pronta pra
 * "Atendimento do consultor" (bloco 5). Mesma disciplina de
 * `criarOuAbrirOportunidade()` — grava via `mudarEtapa()` na própria etapa
 * de entrada só pra deixar o registro em `oportunidade_historico` (regra
 * #6), nunca um `INSERT` silencioso sem rastro.
 *
 * SEMPRE cria uma oportunidade NOVA, nunca reaproveita uma ativa já
 * existente do mesmo telefone — diferente de `criarOuAbrirOportunidade()`
 * (que reaproveita porque é a MESMA conversa de WhatsApp continuando), aqui
 * é uma ação humana explícita de registrar um negócio; regra #1 permite +1
 * veículo por cliente — quem chama (`admin/index.php`) já avisa antes
 * (nunca bloqueia) se o telefone já tiver oportunidade ativa, mesmo padrão
 * de `buscarOportunidadeAtivaPorTelefone()`/`criarVeiculoManualFrota()`.
 *
 * Nunca chama `atualizarNomeFotoWhatsapp()` pro cliente novo — mesma
 * decisão já tomada em `criarVeiculoManualFrota()`: não faz sentido bater
 * na Z-API atrás de nome/foto de alguém que ainda não mandou mensagem
 * nenhuma por esse canal.
 */
function criarOportunidadeManual(
    string $nome,
    string $telefone,
    int $criadoPor,
    ?int $responsavelId = null,
    string $veiculoMarca = '',
    string $veiculoModelo = '',
    string $veiculoAno = '',
    string $veiculoPlaca = '',
    ?float $valorPretendido = null,
    string $observacao = ''
): array {
    if (trim($nome) === '') {
        throw new InvalidArgumentException('Informe o nome do cliente.');
    }
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone inválido: {$telefone}");
    }
    $responsavelId = $responsavelId ?: $criadoPor;

    $db = getDB();
    $stmt = $db->prepare('SELECT id, nome FROM clientes WHERE telefone = ?');
    $stmt->execute([$telNorm]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        $db->prepare('INSERT INTO clientes (nome, telefone) VALUES (?, ?)')
           ->execute([clean($nome), $telNorm]);
        $clienteId = (int)$db->lastInsertId();
    } else {
        $clienteId = (int)$cliente['id'];
        if (empty($cliente['nome']) && $nome) {
            $db->prepare('UPDATE clientes SET nome = ? WHERE id = ?')->execute([clean($nome), $clienteId]);
        }
    }

    $db->prepare('
        INSERT INTO oportunidades
            (cliente_id, etapa, veiculo_marca, veiculo_modelo, veiculo_ano, veiculo_placa,
             valor_pretendido, observacao_manual, responsavel_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $clienteId, 'crm_preenchido',
        clean($veiculoMarca), clean($veiculoModelo), clean($veiculoAno), clean($veiculoPlaca),
        $valorPretendido,
        clean($observacao),
        $responsavelId,
    ]);
    $opId = (int)$db->lastInsertId();

    mudarEtapa($opId, 'crm_preenchido', $responsavelId, 'Oportunidade criada manualmente pelo consultor/admin'
        . ($observacao !== '' ? (' — ' . $observacao) : ''));

    return ['cliente_id' => $clienteId, 'oportunidade_id' => $opId];
}

function criarVeiculoManualFrota(
    string $vendedorNome,
    string $vendedorTelefone,
    string $marca,
    string $modelo,
    string $ano,
    string $placa,
    string $chassi,
    string $renavam,
    ?float $valorPago,
    int $criadoPor,
    ?int $responsavelId = null
): array {
    $db = getDB();
    $telNorm = normalizarTelefone($vendedorTelefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone do vendedor inválido: {$vendedorTelefone}");
    }
    if (!$marca && !$modelo) {
        throw new InvalidArgumentException('Informe ao menos marca ou modelo do veículo.');
    }
    $responsavelId = $responsavelId ?: $criadoPor;

    $stmt = $db->prepare('SELECT id, nome FROM clientes WHERE telefone = ?');
    $stmt->execute([$telNorm]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        $db->prepare('INSERT INTO clientes (nome, telefone) VALUES (?, ?)')
           ->execute([clean($vendedorNome), $telNorm]);
        $clienteId = (int)$db->lastInsertId();
    } else {
        $clienteId = (int)$cliente['id'];
        if (empty($cliente['nome']) && $vendedorNome) {
            $db->prepare('UPDATE clientes SET nome = ? WHERE id = ?')->execute([clean($vendedorNome), $clienteId]);
        }
    }

    $db->prepare('
        INSERT INTO oportunidades
            (cliente_id, etapa, veiculo_marca, veiculo_modelo, veiculo_ano, veiculo_placa, veiculo_chassi, veiculo_renavam, valor_final, data_compra, fechado_por, responsavel_id)
        VALUES (?, \'fechado\', ?, ?, ?, ?, ?, ?, ?, date(\'now\',\'localtime\'), ?, ?)
    ')->execute([$clienteId, clean($marca), clean($modelo), clean($ano), clean($placa), clean($chassi), clean($renavam), $valorPago, $responsavelId, $responsavelId]);
    $opId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, '', 'fechado', ?, 'Veículo cadastrado manualmente direto na frota — sem passar pelo funil de compra')
    ")->execute([$opId, $responsavelId]);

    return ['cliente_id' => $clienteId, 'oportunidade_id' => $opId];
}

/**
 * Exclui um veículo da Frota (uma linha de `oportunidades` com
 * etapa='fechado') — 23/09/2026, "permita super admin excuir veiculo veio
 * duas bianca que veio do outro sistema": achado real (screenshot de
 * admin/veiculos.php) mostrando 2 cadastros duplicados pra mesma
 * "Bianca Pereira Da Silva" (mesma placa/chassi, telefones diferentes),
 * provável duplicata da importação do CRM antigo (install/importar_crm_antigo.php)
 * — um deles com negociação de venda ativa (não pode sumir), o outro sem
 * nenhum negócio de revenda ainda (candidato real a exclusão).
 *
 * Restrito ao super_admin (checado em admin/veiculos.php). Nunca apaga
 * `clientes` (mesma disciplina de excluirConversaWhatsapp()/
 * excluirAvaliacao() — o cadastro do cliente é dado independente do
 * veículo). Bloqueia (nunca força) quando existe risco real de perder
 * negócio/dinheiro/documento já confirmado:
 *  - venda ativa ou já concluída (etapa != 'cancelada') pro mesmo veículo
 *  - qualquer fin_lancamentos JÁ PAGO ligado à oportunidade ou a qualquer
 *    venda dela (mesmo uma venda cancelada — regra de "devolução de
 *    veículo" do financeiro nunca mexe em lançamento já pago)
 *  - contrato de compra ou venda já ASSINADO (documento legal de verdade)
 *  - vistoria (veiculo_avaliacoes) com termo de entrega já gerado/enviado/
 *    assinado (mesmo guard de excluirAvaliacao())
 */
function excluirVeiculoFrota(int $oportunidadeId): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    if (!$stmt->fetch()) {
        return ['ok' => false, 'erro' => 'Veículo não encontrado.'];
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE oportunidade_id = ? AND etapa != 'cancelada'");
    $stmt->execute([$oportunidadeId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erro' => 'Esse veículo tem uma negociação de venda ativa ou já concluída — não pode ser excluído.'];
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM fin_lancamentos
        WHERE status = 'pago' AND (oportunidade_id = ? OR venda_id IN (SELECT id FROM vendas WHERE oportunidade_id = ?))
    ");
    $stmt->execute([$oportunidadeId, $oportunidadeId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erro' => 'Esse veículo tem lançamento financeiro já pago vinculado — não pode ser excluído.'];
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM contratos WHERE oportunidade_id = ? AND status = 'assinado'");
    $stmt->execute([$oportunidadeId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erro' => 'Esse veículo tem contrato assinado (compra ou venda) — não pode ser excluído.'];
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM veiculo_avaliacoes WHERE oportunidade_id = ? AND termo_status != ''");
    $stmt->execute([$oportunidadeId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erro' => 'Esse veículo tem termo de vistoria já gerado/enviado — não pode ser excluído.'];
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE fin_asaas_clientes SET venda_id = NULL WHERE venda_id IN (SELECT id FROM vendas WHERE oportunidade_id = ?)")
           ->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM venda_historico WHERE venda_id IN (SELECT id FROM vendas WHERE oportunidade_id = ?)")
           ->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM venda_documentos WHERE venda_id IN (SELECT id FROM vendas WHERE oportunidade_id = ?)")
           ->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM fin_lancamentos WHERE venda_id IN (SELECT id FROM vendas WHERE oportunidade_id = ?)")
           ->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM vendas WHERE oportunidade_id = ?")->execute([$oportunidadeId]);

        $db->prepare("DELETE FROM veiculo_avaliacao_itens WHERE avaliacao_id IN (SELECT id FROM veiculo_avaliacoes WHERE oportunidade_id = ?)")
           ->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM veiculo_avaliacao_fotos WHERE avaliacao_id IN (SELECT id FROM veiculo_avaliacoes WHERE oportunidade_id = ?)")
           ->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM veiculo_avaliacoes WHERE oportunidade_id = ?")->execute([$oportunidadeId]);

        $db->prepare("DELETE FROM veiculo_midias_revenda WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM zapcar_consultas WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM fin_lancamentos WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM contratos WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM oportunidade_documentos WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM oportunidade_pendencias_pos_venda WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM oportunidade_historico WHERE oportunidade_id = ?")->execute([$oportunidadeId]);
        $db->prepare("DELETE FROM oportunidades WHERE id = ?")->execute([$oportunidadeId]);

        $db->commit();
        return ['ok' => true, 'erro' => null];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}

/**
 * Busca nome/foto de perfil do WhatsApp (zapiBuscarContato()) e preenche
 * no cliente — fill-if-empty pro nome (nunca sobrescreve o que já tinha,
 * mesma regra do resto do projeto), sempre grava foto_perfil_url mesmo
 * que vazio ('' = "já tentei, não achou nada", NULL = "nunca tentei" —
 * evita tentar de novo em toda mensagem nova desse cliente). Best-effort,
 * nunca lança — chamado de dentro do webhook, não pode atrasar/quebrar o
 * fluxo de mensagem por causa disso.
 */
function atualizarNomeFotoWhatsapp(int $clienteId, string $telefone): void {
    try {
        $contato = zapiBuscarContato($telefone);
        $db = getDB();
        if (!$contato) {
            $db->prepare("UPDATE clientes SET foto_perfil_url = '' WHERE id = ? AND foto_perfil_url IS NULL")
               ->execute([$clienteId]);
            return;
        }
        // fill-if-empty pro nome — mas um nome já salvo que na verdade é
        // texto de status/presença do WhatsApp (achado real, 16/09/2026:
        // "online"/"disponível" salvos como nome) conta como "vazio" pra
        // esse fim, autocorrigindo sozinho assim que uma mensagem nova desse
        // cliente passar por aqui de novo.
        $stmtAtual = $db->prepare("SELECT nome FROM clientes WHERE id = ?");
        $stmtAtual->execute([$clienteId]);
        $nomeAtual = (string)$stmtAtual->fetchColumn();
        $podeAtualizarNome = $contato['nome'] !== '' && ($nomeAtual === '' || !nomeWhatsappPareceValido($nomeAtual));
        $novoNome = $podeAtualizarNome ? clean($contato['nome']) : null;
        $db->prepare("
            UPDATE clientes
            SET foto_perfil_url = ?,
                nome = COALESCE(?, nome)
            WHERE id = ?
        ")->execute([$contato['foto_url'], $novoNome, $clienteId]);
    } catch (Throwable $e) {
        // melhor esforço — nunca pode travar a criação/atualização do lead.
    }
}

/**
 * Avisa por WhatsApp os números cadastrados em Configurações quando um
 * lead novo entra (bloco 2) — igual ao sino de notificação sonora no
 * admin (admin/_notify.php), mas alcança quem não está de olho no painel
 * na hora. `notificacao_leads_whatsapp` em config: números separados por
 * vírgula, mesmo padrão de lista que o JurídicoSaaS usa pra números
 * bloqueados. Nunca lança exceção nem bloqueia a criação do lead — aviso
 * é sempre melhor esforço (mesmo espírito de enviarEmail()/
 * geminiRegistrarTokens()).
 */
function notificarNovoLeadWhatsapp(int $oportunidadeId, string $nomeCliente, string $telefoneCliente): void {
    try {
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/oportunidade.php?id={$oportunidadeId}" : "oportunidade #{$oportunidadeId}";
        $msg = "🚗 Novo lead no Fastcar CRM!\nCliente: {$nomeCliente}\nTelefone: {$telefoneCliente}\n{$link}";

        foreach ($numeros as $numero) {
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode derrubar a criação do lead
    }
}

/**
 * Avisa o CONSULTOR RESPONSÁVEL, por WhatsApp, quando a IA termina de
 * qualificar um lead (bloco 3→4) — seja qualificação completa normal ou
 * escalada por estagnação (includes/ia_qualificacao.php::iaProcessarTurno()).
 * Sem isso (bug real achado em 13/09/2026 auditando "e depois, tem processo
 * pro consultor ligar?"): a oportunidade só mudava de etapa pra
 * `crm_preenchido` silenciosamente — nenhum aviso saía, o consultor só
 * descobria que tinha lead pronto se checasse o painel por conta própria.
 * `notificarNovoLeadWhatsapp()` (acima) não resolve isso: ela dispara na
 * ENTRADA (bloco 2, antes da IA perguntar até o nome) pra uma lista
 * genérica de números — aqui é dirigido, pro WhatsApp pessoal
 * (`usuarios.whatsapp`) de quem é responsável por essa oportunidade
 * específica, com o resumo pronto pra já saber o que perguntar na ligação.
 * Sem responsável definido (ex: ninguém disponível na fila quando o lead
 * entrou) ou sem `usuarios.whatsapp` cadastrado, cai no aviso genérico de
 * `notificacao_leads_whatsapp` como fallback — nunca deixa passar batido.
 * Nunca lança, nunca bloqueia o fluxo principal (mesmo espírito de
 * notificarNovoLeadWhatsapp()).
 */
function notificarConsultorLeadQualificado(int $oportunidadeId, string $motivo = 'Qualificação concluída'): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT o.resumo_ia, o.responsavel_id, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
                   u.whatsapp AS consultor_whatsapp
            FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            LEFT JOIN usuarios u ON u.id = o.responsavel_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/oportunidade.php?id={$oportunidadeId}" : "oportunidade #{$oportunidadeId}";
        $msg = "📋 Lead pronto pra ligar! ({$motivo})\n"
             . "Cliente: {$op['cliente_nome']}\nTelefone: {$op['cliente_telefone']}\n\n"
             . ($op['resumo_ia'] ? "{$op['resumo_ia']}\n\n" : '')
             . $link;

        if (!empty($op['consultor_whatsapp'])) {
            zapiEnviarTextoInterno($op['consultor_whatsapp'], $msg);
            return;
        }

        // Sem responsável ou sem WhatsApp cadastrado pra ele — fallback pra
        // não deixar o lead qualificado sem NENHUM aviso saindo.
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $lista))) as $numero) {
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode travar o fluxo da qualificação
    }
}

/** Horário configurável pra disparar o relatório de fim de turno (padrão 19:30). */
function leadsCrmPreenchidoHorarioNotificar(): string {
    $v = getConfig('leads_crm_preenchido_notificar_hora');
    return ($v && preg_match('/^\d{2}:\d{2}$/', $v)) ? $v : '19:30';
}

/**
 * Fim de turno (29/09/2026, "ao terminar turno 7:30 enviar todos leads
 * crm preenchido que chegarem para numero de notificação") — lista os
 * leads que ENTRARAM no funil hoje (`created_at`) e ainda estão parados
 * em `etapa='crm_preenchido'` (já qualificados pela IA, bloco 4, esperando
 * o consultor assumir de verdade) e manda pro(s) número(s) de notificação
 * genérica (`config.notificacao_leads_whatsapp`, mesma lista já usada em
 * `notificarNovoLeadWhatsapp()`/como fallback de
 * `notificarConsultorLeadQualificado()`) — nunca a instância dedicada de
 * ninguém, é aviso interno, mesmo espírito das outras notificações desta
 * seção — usa `zapiEnviarTextoInterno()` (sempre Z-API principal, nunca o
 * toggle/Meta oficial), não `zapiEnviarTexto()`: 30/09/2026, achado real
 * ("contrato assinado mas sem notificação, e-mail chegou certinho") — um
 * número pessoal de staff nunca escreveu pro WhatsApp oficial da empresa
 * como cliente, então a Meta rejeita como mensagem fora da janela de 24h
 * (código 131047) se essas notificações caírem no toggle global. Não
 * precisa de canal de origem (não é resposta a cliente nenhum), mas
 * também não pode depender do provider ativo pro canal de clientes.
 *
 * Sempre manda algo (mesmo "0 leads parados"), pra confirmar que o cron
 * está vivo — mesmo espírito do resumo diário de produtividade. Nunca
 * lança, nunca bloqueia nada — quem chama (`cron/leads_crm_preenchido_fim_turno.php`)
 * decide o dedup-por-dia olhando o retorno.
 */
function notificarLeadsCrmPreenchidoFimTurno(): array {
    try {
        $hoje = date('Y-m-d');
        $db = getDB();
        $stmt = $db->prepare("
            SELECT o.id, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
                   o.veiculo_marca, o.veiculo_modelo, u.nome AS responsavel_nome
            FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            LEFT JOIN usuarios u ON u.id = o.responsavel_id
            WHERE o.etapa = 'crm_preenchido' AND date(o.created_at) = ?
            ORDER BY o.created_at
        ");
        $stmt->execute([$hoje]);
        $leads = $stmt->fetchAll();

        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) {
            return ['ok' => false, 'motivo' => 'nenhum número de notificação configurado', 'total' => count($leads)];
        }

        $baseUrl = getConfig('app_base_url') ?: '';
        if (!$leads) {
            $msg = "📋 Fim de turno — nenhum lead qualificado hoje ficou parado em \"CRM preenchido\". ✅";
        } else {
            $linhas = [];
            foreach ($leads as $l) {
                $veiculo = trim(($l['veiculo_marca'] ?? '') . ' ' . ($l['veiculo_modelo'] ?? ''));
                $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/oportunidade.php?id={$l['id']}" : "#{$l['id']}";
                $resp = $l['responsavel_nome'] ? $l['responsavel_nome'] : 'sem responsável';
                $linhas[] = "• {$l['cliente_nome']} ({$l['cliente_telefone']})" . ($veiculo !== '' ? " — {$veiculo}" : '')
                          . " — {$resp}\n  {$link}";
            }
            $msg = "📋 Fim de turno — " . count($leads) . ' lead(s) qualificado(s) hoje ainda em "CRM preenchido":' . "\n\n"
                 . implode("\n\n", $linhas);
        }

        $enviouAlgum = false;
        foreach ($numeros as $numero) {
            if (zapiEnviarTextoInterno($numero, $msg)) $enviouAlgum = true;
        }
        return ['ok' => $enviouAlgum, 'total' => count($leads)];
    } catch (Throwable $e) {
        return ['ok' => false, 'motivo' => 'erro interno', 'total' => 0];
    }
}

/**
 * Manda o telefone do consultor responsável direto pro CLIENTE via
 * WhatsApp — regra de negócio de 15/09/2026 (José/Jean: "cliente aceitou
 * que consultor ligar, encaminhar notificação ao consultor E enviar
 * telefone dele pro cliente"): o cliente não precisa ficar só esperando
 * a ligação, já pode chamar direto se quiser. Só dispara quando
 * `aceita_ligacao_consultor` é TRUE de verdade (nunca se recusou ou ainda
 * não respondeu — checagem estrita `=== 1`, não "truthy") E o consultor
 * responsável tem WhatsApp cadastrado; sem os dois, nunca manda mensagem
 * quebrada/sem número nenhum pro cliente. Mensagem fica registrada em
 * `whatsapp_mensagens` como qualquer outra mandada ao cliente, pro
 * consultor que assumir depois ver o que já foi dito.
 *
 * $canalOrigem ('zapi'|'oficial'|null, 29/09/2026) — mesmo canal que o
 * cliente usou nesse turno (ver zapiEnviarTextoPeloCanal()), pra nunca
 * tentar responder pela Meta um cliente que só fala com o número Z-API
 * antigo (ou vice-versa).
 */
function enviarTelefoneConsultorAoCliente(int $oportunidadeId, ?string $canalOrigem = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT c.telefone AS cliente_telefone, o.aceita_ligacao_consultor,
                   u.nome AS consultor_nome, u.whatsapp AS consultor_whatsapp
            FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            LEFT JOIN usuarios u ON u.id = o.responsavel_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op) return;
        if ((int)$op['aceita_ligacao_consultor'] !== 1) return;
        if (empty($op['consultor_whatsapp']) || empty($op['consultor_nome'])) return;

        $msg = "Perfeito! O(a) {$op['consultor_nome']}, da nossa equipe, vai te ligar em breve. "
             . "Se quiser chamar antes, o WhatsApp dele(a) é: {$op['consultor_whatsapp']}";
        if (!zapiEnviarTextoPeloCanal($op['cliente_telefone'], $msg, $canalOrigem)) return;

        // 22/09/2026, "está aparecendo mesma oportunidade para outros
        // consultores" — a partir daqui o cliente já sabe o nome/WhatsApp
        // DESTE consultor; travar a oportunidade contra reatribuição
        // automática silenciosa da fila (equalizarFilaLeads()/
        // redistribuirFilaLeads()/marcarConsultorFaltou()), que senão
        // trocava o responsavel_id sem o cliente nunca ficar sabendo.
        $db->prepare("UPDATE oportunidades SET consultor_tel_enviado_em = datetime('now','localtime') WHERE id = ?")
           ->execute([$oportunidadeId]);

        $telNorm = normalizarTelefone($op['cliente_telefone']);
        $stmtC = $db->prepare("SELECT id FROM clientes WHERE telefone = ?");
        $stmtC->execute([$telNorm]);
        $clienteId = $stmtC->fetchColumn();
        $db->prepare("
            INSERT INTO whatsapp_mensagens (telefone, cliente_id, direcao, mensagem, tipo, enviado_por_ia, created_at)
            VALUES (?, ?, 'out', ?, 'text', 1, datetime('now','localtime'))
        ")->execute([$telNorm, $clienteId !== false ? (int)$clienteId : null, $msg]);
    } catch (Throwable $e) {
        // melhor esforço — nunca pode travar a conclusão da qualificação.
    }
}

/**
 * ÚNICO ponto do sistema que deve alterar oportunidades.etapa.
 * Grava o histórico (data + responsável) junto, sempre.
 */
function mudarEtapa(int $oportunidadeId, string $etapaNova, ?int $responsavelId = null, string $observacao = ''): bool {
    if (!in_array($etapaNova, ETAPAS_VALIDAS, true)) {
        throw new InvalidArgumentException("Etapa inválida: {$etapaNova}");
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT etapa, valor_ofertado FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    $op = $stmt->fetch();
    if (!$op) {
        throw new RuntimeException("Oportunidade #{$oportunidadeId} não existe.");
    }
    $atual = $op['etapa'];

    // "Compra concluída exige checklist" (regra #7) — trava aqui, não só
    // na tela, pra nenhuma rota conseguir pular o checklist.
    if ($etapaNova === 'fechado' && !checklistFechamentoCompleto($oportunidadeId)) {
        throw new RuntimeException(
            "Oportunidade #{$oportunidadeId} não pode ser fechada: documentos obrigatórios pendentes."
        );
    }

    $db->beginTransaction();
    try {
        // 17/09/2026, achado real: "fechamos cliente mais não mostra tipo
        // negocio fechado esse mes" — valor_final/data_compra/fechado_por
        // (colunas do bloco 8, "Pasta fechada") existiam no schema desde o
        // início mas NUNCA eram preenchidas em lugar nenhum do código —
        // dashboardConsultor()/dashboardSuperAdmin() (includes/dashboard.php)
        // sempre filtram por essas 3 colunas pra "fechadas/valor fechado
        // este mês" e pra taxa de conversão do consultor (fechado_por),
        // então mesmo com a oportunidade genuinamente em etapa='fechado'
        // esses cards sempre davam 0 — a mesma classe de bug em
        // admin/veiculos.php, que lê valor_final pra mostrar "valor pago"
        // da frota (sempre "—"/R$0,00 antes desse fix, mesmo com veículos
        // de verdade comprados). valor_final assume o valor_ofertado (bloco
        // 6, a única "proposta final" que o sistema já rastreia) no
        // momento exato do fechamento — sem campo próprio de "valor final"
        // separado na tela, é o valor real que foi pago.
        if ($etapaNova === 'fechado') {
            $db->prepare("
                UPDATE oportunidades
                SET etapa = ?, valor_final = ?, data_compra = date('now','localtime'),
                    fechado_por = ?, updated_at = datetime('now','localtime')
                WHERE id = ?
            ")->execute([$etapaNova, $op['valor_ofertado'], $responsavelId, $oportunidadeId]);
        } else {
            $db->prepare("UPDATE oportunidades SET etapa = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$etapaNova, $oportunidadeId]);
        }

        $db->prepare("
            INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$oportunidadeId, $atual, $etapaNova, $responsavelId, clean($observacao)]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Fora da transação de propósito: nunca queremos um rollBack() numa
    // transação já commitada só porque o envio de e-mail/lançamento
    // financeiro deu problema.
    if ($etapaNova === 'fechado') {
        enviarEmailCompraConcluida($oportunidadeId);
        // 19/09/2026, pedido direto: conciliar negociação com financeiro —
        // "quando compra veiculo sai do caixa" — ver finRegistrarDespesaCompraFechada().
        finRegistrarDespesaCompraFechada($oportunidadeId, (float)$op['valor_ofertado'], $responsavelId);
        // 23/09/2026, pedido direto: comissão automática do consultor por
        // faixa de % da FIPE — ver finRegistrarComissaoCompraFechada().
        finRegistrarComissaoCompraFechada($oportunidadeId, (float)$op['valor_ofertado'], $responsavelId);
    }

    return true;
}

/**
 * E-mail de confirmação pro cliente quando a compra é concluída (bloco 8)
 * — 16/09/2026, pedido José/Jean ("cria todos os templates" dos e-mails
 * transacionais propostos). Best-effort, igual todo outro aviso automático
 * daqui: nunca pode travar o fechamento da oportunidade por causa disso.
 * Sem e-mail cadastrado, não manda nada (nunca quebra por falta de dado).
 */
function enviarEmailCompraConcluida(int $oportunidadeId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT o.veiculo_marca, o.veiculo_modelo, o.valor_final, c.nome AS cliente_nome, c.email AS cliente_email
            FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op || empty($op['cliente_email'])) return;

        $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? '')) ?: 'seu veículo';
        $valor = $op['valor_final'] ? 'R$ ' . number_format((float)$op['valor_final'], 2, ',', '.') : null;

        $corpo = "<p>Olá, " . htmlspecialchars($op['cliente_nome'] ?: '', ENT_QUOTES) . "!</p>"
            . "<p>A compra do seu <strong>{$veiculo}</strong> foi concluída com sucesso pela Fastcar. 🎉</p>"
            . ($valor ? "<p>Valor final: <strong>{$valor}</strong></p>" : '')
            . "<p>Toda a documentação e o contrato assinado ficam guardados com a gente. Qualquer dúvida sobre o processo, "
            . "é só chamar por aqui ou pelo WhatsApp.</p>"
            . "<p>Obrigado pela confiança!</p>";

        enviarEmail($op['cliente_email'], 'Compra concluída — Fastcar', emailLayout($corpo), $op['cliente_nome'] ?: '');
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o fechamento da oportunidade.
    }
}

/**
 * Checklist do bloco 8 — regra #7: só libera 'fechado' se todo documento
 * marcado como obrigatório pra essa oportunidade estiver presente.
 */
function checklistFechamentoCompleto(int $oportunidadeId): bool {
    $db = getDB();
    // ⚠️ Bug real #1 (achado em teste): contar só "pendentes" (obrigatorio=1
    // com arquivo vazio) dá 0 tanto faz se está tudo preenchido quanto se
    // NENHUM documento foi cadastrado ainda — falso-positivo de COUNT em
    // query vazia. Por isso exige explicitamente total>0: sem nenhum
    // documento obrigatório cadastrado, o checklist NUNCA está completo.
    //
    // ⚠️ Bug real #2 (achado em teste E2E do funil completo, depois que o
    // Google Drive foi integrado): um documento "presente" pode estar em
    // arquivo_url (fallback local) OU em drive_file_id (Drive, preferido) —
    // checar só arquivo_url fazia o checklist NUNCA fechar depois que o
    // Drive foi configurado, mesmo com os 6 documentos enviados de verdade.
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN (arquivo_url IS NULL OR arquivo_url = '') AND (drive_file_id IS NULL OR drive_file_id = '') THEN 1 ELSE 0 END) as pendentes
        FROM oportunidade_documentos
        WHERE oportunidade_id = ? AND obrigatorio = 1
    ");
    $stmt->execute([$oportunidadeId]);
    $r = $stmt->fetch();
    return (int)$r['total'] > 0 && (int)$r['pendentes'] === 0;
}

/**
 * Marca perda em qualquer etapa — sempre exige motivo (regra do Jean:
 * "Sem perfil de compra" → registra motivo e encerra; "Perdido" também).
 */
function marcarPerdida(int $oportunidadeId, string $motivo, ?int $responsavelId = null, bool $semPerfil = false): bool {
    if (!$motivo) {
        throw new InvalidArgumentException('Motivo de perda é obrigatório.');
    }
    $db = getDB();
    $db->prepare("UPDATE oportunidades SET motivo_perda = ? WHERE id = ?")->execute([clean($motivo), $oportunidadeId]);
    return mudarEtapa($oportunidadeId, $semPerfil ? 'sem_perfil' : 'perdido', $responsavelId, $motivo);
}

/**
 * Seleção em massa (29/09/2026, pedido direto: "permitir que selecione em
 * massa os leads pra marcar... desistiu... negocio [pode] retornar") — marca
 * várias oportunidades como perdidas numa passada só, mesmo motivo pra
 * todas. Nunca "some" nada do sistema — igual marcarPerdida() de sempre, só
 * encerra o funil; reabrir depois continua sendo ação manual normal, o
 * negócio pode voltar. Reaproveita marcarPerdida()/mudarEtapa() pra cada id,
 * nunca UPDATE em lote direto (regra #6) — cada oportunidade grava seu
 * próprio histórico.
 *
 * Cada id é tratado independente (try/catch por item, nunca deixa 1 id
 * ruim derrubar o lote inteiro): id inexistente, fora da carteira do
 * consultor (quando $restringirDono é passado) ou já fora de ETAPAS_ATIVAS
 * (já fechado/perdido/sem_perfil — nunca reprocessa, evitaria duplicar
 * histórico à toa) contam como "ignorado", nunca erro.
 *
 * @param int[] $ids
 * @return array{total:int, sucesso:int, ignorados:int}
 */
function marcarPerdidaEmMassa(array $ids, string $motivo, ?int $responsavelId = null, bool $semPerfil = false, ?int $restringirDono = null): array {
    if (trim($motivo) === '') {
        throw new InvalidArgumentException('Motivo de perda é obrigatório.');
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $db = getDB();
    $sucesso = 0;
    $ignorados = 0;
    foreach ($ids as $oportunidadeId) {
        if ($oportunidadeId <= 0) {
            $ignorados++;
            continue;
        }
        $stmt = $db->prepare('SELECT etapa, responsavel_id FROM oportunidades WHERE id = ?');
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op || !in_array($op['etapa'], ETAPAS_ATIVAS, true)) {
            $ignorados++;
            continue;
        }
        if ($restringirDono !== null && (int)$op['responsavel_id'] !== $restringirDono) {
            $ignorados++;
            continue;
        }
        try {
            marcarPerdida($oportunidadeId, $motivo, $responsavelId, $semPerfil);
            $sucesso++;
        } catch (Throwable $e) {
            $ignorados++;
        }
    }
    return ['total' => count($ids), 'sucesso' => $sucesso, 'ignorados' => $ignorados];
}

/**
 * 29/09/2026, "coloca fitro por super admin - puxar leads dos consultores"
 * — super_admin/supervisor filtram a tabela por um consultor específico
 * (admin/index.php, ?consultor=id) e podem PUXAR (reatribuir) os leads
 * filtrados em massa pra outro consultor ou pra si mesmo. Diferente do
 * atualizar_proxima_acao de admin/oportunidade.php (UPDATE simples, sem
 * histórico, porque trocar responsável sozinho não é transição de etapa),
 * aqui SEMPRE grava oportunidade_historico — é uma ação administrativa
 * mais consequente, movendo potencialmente muitos leads de uma vez, mesma
 * disciplina de marcarConsultorFaltou()/equalizarFilaLeads() (fila_leads.php)
 * que já registram o motivo de toda reatribuição automática.
 *
 * Cada id é tratado independente (nunca 1 id ruim derruba o lote): id
 * inexistente, já fora de ETAPAS_ATIVAS (fechado/perdido/sem_perfil — nunca
 * reprocessa) ou já com esse responsável conta como "ignorado", nunca erro.
 *
 * @param int[] $ids
 * @return array{total:int, sucesso:int, ignorados:int, destino_nome:string}
 */
function reatribuirEmMassa(array $ids, int $novoResponsavelId, int $executadoPor): array {
    $db = getDB();
    $stmtDestino = $db->prepare("SELECT nome FROM usuarios WHERE id = ? AND perfil IN ('consultor', 'super_admin') AND bloqueado = 0");
    $stmtDestino->execute([$novoResponsavelId]);
    $destino = $stmtDestino->fetch();
    if (!$destino) {
        throw new InvalidArgumentException('Consultor de destino inválido.');
    }

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $sucesso = 0;
    $ignorados = 0;

    foreach ($ids as $oportunidadeId) {
        if ($oportunidadeId <= 0) {
            $ignorados++;
            continue;
        }
        $stmt = $db->prepare('SELECT etapa, responsavel_id FROM oportunidades WHERE id = ?');
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op || !in_array($op['etapa'], ETAPAS_ATIVAS, true)) {
            $ignorados++;
            continue;
        }
        if ((int)$op['responsavel_id'] === $novoResponsavelId) {
            $ignorados++;
            continue;
        }

        try {
            $origemNome = null;
            if ($op['responsavel_id']) {
                $stmtOrigem = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
                $stmtOrigem->execute([(int)$op['responsavel_id']]);
                $origemNome = $stmtOrigem->fetchColumn() ?: null;
            }
            $db->beginTransaction();
            $db->prepare("UPDATE oportunidades SET responsavel_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$novoResponsavelId, $oportunidadeId]);
            $db->prepare("
                INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao, responsavel_id)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $oportunidadeId,
                $op['etapa'],
                $op['etapa'],
                'Reatribuído manualmente pelo super admin' . ($origemNome ? " (de {$origemNome}" : ' (sem responsável anterior') . " pra {$destino['nome']})",
                $executadoPor,
            ]);
            $db->commit();
            $sucesso++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $ignorados++;
        }
    }

    return ['total' => count($ids), 'sucesso' => $sucesso, 'ignorados' => $ignorados, 'destino_nome' => $destino['nome']];
}

/**
 * Anotação livre editável direto na linha da tabela do funil
 * (admin/index.php, 29/09/2026, "campo de observação manual para digitar
 * consultor") — nunca é mudança de etapa (sem mudarEtapa()/histórico, mesmo
 * espírito de "atualizar_proxima_acao" em admin/oportunidade.php, que já é
 * UPDATE direto porque não é transição de estado). Quando a oportunidade já
 * está encerrada com motivo (`perdido`/`sem_perfil`), a tela reaproveita
 * `motivo_perda` em vez desse campo — confirmado com o usuário, "são a
 * mesma coisa" — então essa função só é chamada enquanto a oportunidade
 * ainda está ativa.
 */
function atualizarObservacaoManual(int $oportunidadeId, string $observacao): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE oportunidades SET observacao_manual = ?, updated_at = datetime('now','localtime') WHERE id = ?");
    $stmt->execute([clean($observacao), $oportunidadeId]);
    return $stmt->rowCount() > 0;
}

/**
 * "7 dias de silêncio" (21/09/2026, pedido direto depois de ver ~25 leads
 * sem nome que receberam reengajamento — cron/followup.php, bloco 3 — e
 * nunca responderam nada, ficando presos pra sempre em 'whatsapp'/
 * 'qualificacao_ia'. Diferente de `whatsapp_sessoes.turnos_sem_avanco`
 * (só incrementa quando o CLIENTE responde mas sem dar dado novo —
 * `iaProcessarTurno()` só roda quando chega mensagem nova dele), um
 * cliente que nunca responde nada nunca dispara reprocessamento nenhum,
 * então nada tirava esse lead do funil sozinho até hoje.
 *
 * Marca como 'perdido' — nunca 'sem_perfil' (o cliente pode ter perfil de
 * compra genuíno, só sumiu, não foi desqualificado) — qualquer
 * oportunidade ainda em 'whatsapp'/'qualificacao_ia' cuja última mensagem
 * DO CLIENTE (nunca conta mensagem nossa, nem reengajamento automático)
 * passou de LEADS_DIAS_SILENCIO_ENCERRAR dias; sem nenhuma mensagem 'in'
 * registrada (não deveria acontecer — toda oportunidade nasce de uma
 * mensagem recebida —, mas cobre o caso), usa `created_at` da própria
 * oportunidade como referência.
 *
 * Se o cliente voltar a escrever meses depois, `criarOuAbrirOportunidade()`
 * abre uma oportunidade NOVA pra ele — só reaproveita oportunidade em
 * ETAPAS_ATIVAS, e 'perdido' não está nessa lista — então nada fica
 * perdido de verdade, só sai da fila de pendências do consultor.
 */
const LEADS_DIAS_SILENCIO_ENCERRAR = 7;

function encerrarLeadsSemResposta(): array {
    $db = getDB();
    $limite = date('Y-m-d H:i:s', strtotime('-' . LEADS_DIAS_SILENCIO_ENCERRAR . ' days'));

    $candidatos = $db->query("
        SELECT o.id, o.created_at, c.telefone,
               (SELECT MAX(m.created_at) FROM whatsapp_mensagens m
                WHERE m.telefone = c.telefone AND m.direcao = 'in') AS ultima_msg_in
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.etapa IN ('whatsapp', 'qualificacao_ia')
    ")->fetchAll();

    $encerrados = [];
    foreach ($candidatos as $op) {
        $referencia = $op['ultima_msg_in'] ?: $op['created_at'];
        if ($referencia >= $limite) continue; // ainda dentro do prazo

        $dias = (int)floor((time() - strtotime($referencia)) / 86400);
        marcarPerdida(
            (int)$op['id'],
            "Cliente não respondeu por {$dias} dias — encerrado automaticamente por silêncio.",
            null,
            false
        );
        $encerrados[] = (int)$op['id'];
    }

    return ['encerrados' => count($encerrados), 'ids' => $encerrados, 'total_candidatos' => count($candidatos)];
}
