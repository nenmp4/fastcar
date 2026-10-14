---
name: performance-php-sqlite
description: >
  Manter a performance de um sistema PHP puro + SQLite sem framework
  (padrão `includes/db.php::getDB()`, sem Composer/ORM, `admin/*.php`
  com SQL inline) — tanto diagnosticar/corrigir lentidão já relatada
  quanto, de forma PROATIVA, checar toda feature nova que adiciona uma
  tela/query antes de considerar pronta. Use sempre que o usuário pedir
  "melhora a velocidade"/"performance"/"tá lento"/"otimiza o sistema",
  E TAMBÉM, sem precisar que o usuário peça, sempre que você mesmo
  terminar de implementar uma tela nova ou uma query nova que roda em
  toda carga de página (dashboard, listagem paginada, polling) ou numa
  tabela que já tem mais de uma centena de linhas — antes de marcar a
  feature como concluída.
---

# Performance em PHP + SQLite — padrão José/Jean

Runbook pra manter a performance deste tipo de stack (PHP puro, SQLite
via `includes/db.php::getDB()`, sem framework/ORM, telas admin que rodam
query direto inline) — Fastcar CRM e qualquer projeto-irmão futuro com a
mesma arquitetura. Validado no Fastcar em 29/09/2026 ("vamos melhora
desempenho velocidade do sistema" → "AGORA TÁ RAPIDASH") e reforçado em
14/10/2026 ("melhore velocidade dos sistema", achado real: índice
faltando numa fila paginada sem filtro) e 14/10/2026 de novo
("precisamos manter esse padrão... quando fazemos atualizações novas" —
este skill passou a cobrir também o checklist PROATIVO, não só o
diagnóstico reativo).

## ⚠️ Lição operacional: skill global não sobrevive a reset de container

Achado real: uma versão anterior deste skill vivia só em
`~/.claude/skills/performance-php-sqlite/` (global, fora do repo) — e
desapareceu quando a sessão/container foi recriado, apesar do CLAUDE.md
do projeto documentar que ele existia. **Por isso este arquivo vive
dentro do próprio repo** (`.claude/skills/performance-php-sqlite/SKILL.md`,
versionado no git, sobrevive a qualquer reset de container) — é a cópia
de verdade. Uma cópia em `~/.claude/skills/` (fora do repo) pode ser
conveniente pra reuso imediato na mesma sessão ou em projeto-irmão, mas
nunca é a fonte confiável — sempre que notar que a cópia global sumiu
(skill não aparece na lista de skills disponíveis apesar do CLAUDE.md
dizer que existe), recopiar deste arquivo do repo, nunca reescrever do
zero.

## Quando rodar isto — 2 gatilhos, não só 1

1. **Reativo** — usuário pede "melhora performance"/"tá lento"/"otimiza
   o sistema". Segue os passos 1-5 abaixo.
2. **Proativo, sem o usuário precisar pedir** — você mesmo, terminando
   qualquer feature nova que:
   - cria uma tela/endpoint que roda em **toda carga de página comum**
     (dashboard, qualquer `admin/index.php`-like, qualquer coisa com
     polling de JS tipo WhatsApp Box);
   - adiciona `WHERE`/`JOIN`/`ORDER BY` numa tabela que já tem (ou vai
     crescer pra ter) mais de ~100 linhas reais — oportunidade/lead,
     venda, lançamento financeiro, mensagem, vistoria, notificação;
   - adiciona uma função chamada de dentro de um loop (busca de contato,
     cálculo de score, resincronização em lote).

   Nesses casos, **antes de considerar a feature pronta** (junto do
   `php -l` + `tests/smoke.php` que já são obrigatórios de sempre, nunca
   em vez deles): rodar o passo 1 (achar a query real) e o passo 1b
   (`EXPLAIN QUERY PLAN`) na query nova — se vier `SCAN` numa tabela que
   não é pequena/estática, resolver ANTES do commit, não depois que
   alguém reportar lentidão. Isso é барато de fazer na hora (a query já
   está na tela, só rodar o EXPLAIN) e caro de descobrir depois (reportado
   como "sistema lento" semanas depois, sem ninguém lembrar qual feature
   introduziu).

Duas categorias de lentidão bem diferentes, **nunca confundir**:

1. **Leitura lenta** (tela demora a carregar) — quase sempre falta de
   índice numa coluna filtrada com frequência, ou PRAGMA de conexão
   subotimizado. Resolve com os passos 1-3 abaixo.
2. **Escrita travando** ("database is locked", 504, timeout) — contenção
   de concorrência: muitas escritas individuais disputando o lock de
   escrita do SQLite ao mesmo tempo (webhook + cron + admin gravando
   junto). Resolve agrupando em transação, não com índice — ver seção
   "Escrita em lote" mais abaixo. Sintoma parecido ("sistema lento"), causa
   e correção completamente diferentes — sempre confirmar qual dos dois é
   antes de aplicar a correção errada.

## Passo 1 — achar as queries quentes, nunca adivinhar

Nunca adicionar índice "porque parece que ajuda" — cada índice tem custo
de escrita (todo `INSERT`/`UPDATE` na tabela recalcula todos os índices
dela). Achar a query real primeiro:

1. **Qual tela o usuário reportou como lenta?** (gatilho reativo) ou
   **qual tela/função você acabou de escrever?** (gatilho proativo). Se
   reativo e o usuário não disse qual tela, pergunte — "lento" sem
   contexto pode ser 1 tela específica ou o sistema inteiro.
2. Abrir o(s) arquivo(s) e ler toda query `SELECT`/`UPDATE`/`DELETE`
   inline (`admin/*.php`/`includes/*.php` deste padrão não usa query
   builder, é SQL cru — fácil de ler direto). Prestar atenção especial
   em:
   - Telas que carregam em **toda visita** de um perfil comum (dashboard,
     `admin/index.php`, `admin/financeiro.php`, qualquer WhatsApp Box
     com polling) — o maior volume de requests do sistema, onde 1 índice
     faltando dói mais.
   - `WHERE`/`JOIN`/`ORDER BY` em coluna que não é a chave primária,
     especialmente `responsavel_id`, `etapa`/`status`,
     `created_at`/`data_vencimento`/`concluida_em` (colunas de dono/
     estado/data são as mais filtradas neste padrão de CRM) — e **fila
     paginada sem o filtro mais comum aplicado** (ex: a aba "ver tudo"
     de um super_admin, sem o `WHERE dono=?` que o usuário comum sempre
     tem — fácil de testar só com o caso filtrado e esquecer o caso sem
     filtro, que costuma ser o mais caro).
   - Funções chamadas em TODA carga de página, não só sob demanda — ex.
     `finRecalcularAtrasados()` (Fastcar, `includes/financeiro.php`)
     roda um `UPDATE ... WHERE status=? AND data_vencimento < ?` em toda
     visita a 3 telas financeiras diferentes; sem índice em
     `(status, data_vencimento)` isso é um full table scan repetido a
     cada carregamento, não só uma vez.
   - Subquery correlacionada (`WHERE x.id = (SELECT MAX(id) FROM t WHERE
     t.col = x.col)`) — se a coluna de correlação (`col`) não tem
     índice, cada linha do resultado externo reexecuta um scan completo
     da subquery. Reescrever pro padrão `JOIN (SELECT col, MAX(id) FROM
     t GROUP BY col) agg ON agg.col = x.col` costuma resolver sozinho.
3. Sem `sqlite3` CLI disponível (comum neste tipo de sandbox — confirmar
   com `which sqlite3` antes), usar PHP direto pra rodar
   `EXPLAIN QUERY PLAN`:
   ```php
   $db = new PDO('sqlite:' . $caminhoDoBanco);
   foreach ($db->query("EXPLAIN QUERY PLAN SELECT ...") as $r) print_r($r);
   ```
   `SCAN <tabela>` na saída = sem índice usado, full table scan.
   `SEARCH <tabela> USING INDEX <nome>` = já está usando um índice.
   `USE TEMP B-TREE FOR ORDER BY` = sort em memória/disco — às vezes
   inevitável (`ORDER BY CASE ...`), às vezes some sozinho se o índice
   novo já cobrir a ordem também (índice composto `(filtro, ordem)`).

   **Nunca testar só o caso "feliz"** (o mais filtrado/com menos linha).
   Testar também o caso sem filtro extra (ex: `super_admin`/`supervisor`
   vendo tudo, sem o `WHERE dono=?` que o perfil comum sempre tem) — foi
   exatamente esse caso que escondia o `SCAN` achado em
   `listarAvaliacoesConcluidas()` (14/10/2026): o caso filtrado por
   avaliador já usava índice, só o caso "ver fila inteira" (super_admin)
   fazia scan completo.

## Passo 1b — medir de verdade antes de decidir, com volume e cardinalidade realistas

Banco de dev geralmente está quase vazio — `EXPLAIN QUERY PLAN` sozinho
mostra a ESTRUTURA do plano (SCAN vs SEARCH), mas pra confirmar que um
índice composto vale a pena e não tem efeito colateral negativo num caso
diferente, seedar volume realista num banco de TESTE isolado (nunca o
banco de dev real — ver aviso no topo sobre `auto_prepend_file`) antes
de aplicar:

```bash
# banco de teste isolado, nunca o banco de dev real
echo "<?php define('DB_PATH', __DIR__ . '/teste.db');" > prepend.php
php -d auto_prepend_file=prepend.php seed.php   # SEMPRE arquivo, nunca -r
```

Ao testar um índice composto pra "filtro + ordenação" que serve tanto um
caso FILTRADO (poucas linhas) quanto um caso SEM FILTRO (todas as
linhas), simular **cardinalidade desbalanceada de propósito** — não só
o caso onde o filtro bate nas linhas mais recentes (caso fácil/sorte),
mas também espalhado aleatoriamente no tempo (pior caso real):

```php
// pior caso: só ~0.5% das linhas batem no filtro, espalhadas no tempo
// inteiro, não concentradas nas mais recentes
$avId = (mt_rand(0, 399) === 0) ? $alvo : $outro;
```

Comparar timing ANTES/DEPOIS do índice nos dois cenários (filtrado e
sem filtro) com `microtime(true)` em volta de várias chamadas repetidas
— só então decidir se o índice compensa. Documentar o número real
(ex: "37x mais rápido, 66ms→1,8ms pra 20 chamadas") no comentário do
`CREATE INDEX`, não um "deveria ajudar" vago.

## Passo 2 — PRAGMAs de conexão (leitura)

`includes/db.php::getDB()` já define `busy_timeout`/`journal_mode=WAL`
(pra concorrência, não pra velocidade de leitura — não mexer nisso aqui).
3 PRAGMAs adicionais, sempre juntos, sempre logo depois desses dois —
**nunca persistem no arquivo do banco** (diferente de `journal_mode`),
então têm que ser reaplicados em toda conexão, dentro do próprio
`getDB()`:

```php
$db->exec('PRAGMA synchronous=NORMAL');   // padrão recomendado COM journal_mode=WAL —
                                           // ainda dá durabilidade contra crash, só reduz
                                           // fsync a cada commit (o default sem WAL é FULL,
                                           // que sincroniza no disco toda escrita)
$db->exec('PRAGMA cache_size=-20000');    // negativo = KB; 20MB de cache de página em vez
                                           // do padrão (~2MB) — evita reler do disco dentro
                                           // da mesma request
$db->exec('PRAGMA temp_store=MEMORY');    // tira ORDER BY / b-tree temporário (ex: query
                                           // principal de um funil ordenando por CASE
                                           // temperatura_lead...) de um arquivo temp em
                                           // disco pra RAM
```

Zero mudança de comportamento visível — só velocidade. Rodar `php
tests/smoke.php` depois (o guard `db-sem-pragma-performance`, se esse
projeto já rodou este skill antes, confirma que os 3 continuam
presentes).

`cache_size`/`temp_store` aqui são o cache de PÁGINA do SQLite (memória
que o próprio banco usa pra não reler do disco) — coisa diferente de
cache de APLICAÇÃO (resultado de chamada de API externa, OPcache de
opcode PHP). Se a lentidão é "bater numa API externa toda hora" ou "PHP
recompilando os mesmos arquivos em toda request", isso não se resolve
com índice/PRAGMA — ver skill `cache-php-sqlite`, se existir.

## Passo 3 — índices nas colunas quentes

Padrão de índice composto pra "minha carteira" (a query mais repetida em
qualquer CRM deste tipo — filtro por dono + status/etapa):

```sql
CREATE INDEX IF NOT EXISTS idx_<tabela>_responsavel ON <tabela>(responsavel_id, etapa);
```

Padrão de índice composto pra "filtro + ordenação" (fila paginada, aba
"concluídas"/"histórico" que ordena por data e opcionalmente filtra por
dono/status):

```sql
CREATE INDEX IF NOT EXISTS idx_<tabela>_status_data ON <tabela>(status, data_coluna);
```

A ORDEM das colunas no índice composto importa — a mais seletiva/sempre
presente no `WHERE` primeiro, a de ordenação por último (pra o índice
já sair na ordem certa e nunca precisar de `TEMP B-TREE FOR ORDER BY`).

**Sempre em 2 lugares, nunca só 1:**
- `install/schema.sql` (`CREATE TABLE`/índices — instalação nova already
  correta desde o primeiro boot)
- `install/migrar.php` (`CREATE INDEX IF NOT EXISTS ...` — idempotente por
  natureza, não precisa de `colunaExiste()`/checagem prévia como uma
  coluna nova precisaria; ainda assim SEMPRE rodar
  `php install/migrar.php` depois de editar pra aplicar no banco de
  dev/produção — editar só o SQL não muda o banco já existente)

Esquecer um dos dois lugares faz instalação nova e banco de produção já
rodando divergirem silenciosamente — exatamente o que o guard do passo 5
existe pra pegar.

## Passo 4 — testar antes de considerar pronto

Mesma disciplina rigorosa do resto deste tipo de projeto (ver
`CLAUDE.md` do repo, se existir) — nunca "só rodei e não deu erro":

1. `php -l` nos arquivos editados.
2. **Migração testada contra um banco simulando produção ANTES da
   mudança**, não só o banco de dev já atualizado:
   ```bash
   git show HEAD:install/schema.sql > /tmp/schema_antigo.sql   # ou o commit anterior à mudança
   # criar um banco novo a partir desse schema antigo (via PHP, não sqlite3
   # CLI se ela não existir: new PDO('sqlite:...')->exec(file_get_contents(...)))
   # seedar 1 linha, confirmar que o índice/coluna novo NÃO existe ainda
   # rodar install/migrar.php (com DB_PATH apontando pra esse banco de teste,
   #   via um arquivo prepend.php com define('DB_PATH', ...) — NUNCA `php -r`
   #   inline, auto_prepend_file é SILENCIOSAMENTE IGNORADO nesse modo —
   #   confirmar isso é real com `php -d auto_prepend_file=X -r
   #   'var_dump(defined("DB_PATH"));'` se tiver dúvida, antes de confiar)
   # confirmar: índice(s) aplicado(s), dado semeado preservado
   # rodar a migração DE NOVO — confirmar idempotência (2ª rodada não falha/duplica)
   ```
3. `php tests/smoke.php` no banco de dev real, depois de rodar
   `php install/migrar.php` nele (sem prepend — o banco de dev real usa
   o `DB_PATH` padrão do próprio `includes/db.php`) — **não pular esse
   passo**: editar schema.sql/migrar.php sem aplicar no banco de dev faz
   o guard do passo 5 acusar falha real (o guard checando o banco ao
   vivo é o ponto — ver exemplo abaixo).
4. Opcional mas recomendado — sanity-check que o guard novo REALMENTE
   pega regressão: comentar/remover temporariamente o PRAGMA ou o nome do
   índice, rodar `php tests/smoke.php`, confirmar que falha com a
   mensagem certa, desfazer, confirmar `git diff --stat` limpo e o smoke
   voltando a passar. Sem isso, um guard mal escrito (regex errado,
   `str_contains` no arquivo errado) passa despercebido até o dia em que
   a regressão de verdade acontece e ninguém percebe.

### ⚠️ Risco real de contaminar o banco de dev — reconfirmado 14/10/2026

`-r 'código inline'` combinado com `-d auto_prepend_file=X` **ignora o
prepend silenciosamente** — `DB_PATH` nunca é definido, e
`includes/db.php` cai no fallback padrão (`database/fastcar.db`, o
banco de DEV REAL deste projeto, não o de teste). Qualquer `INSERT`/
`CREATE INDEX`/`ANALYZE` rodado assim contamina o banco de dev de
verdade, mesmo com toda a intenção de estar isolado em `teste.db`.
Confirmado de novo nesta sessão: uma rodada de benchmark escreveu
~40.000 linhas fake em `veiculo_avaliacoes` do banco de dev real antes
do erro ser percebido pelo próprio `ls -la` mostrando o arquivo
modificado na hora errada.

**Regra sem exceção**: todo script de teste/seed/benchmark que lê ou
escreve banco vai pra um ARQUIVO (`seed.php`, `check.php`, nunca `php -r
'...'`), rodado com `-d auto_prepend_file=prepend.php arquivo.php`. Se
precisar confirmar rapidinho que o prepend está valendo antes de
confiar num script maior:
```bash
php -d auto_prepend_file=prepend.php -r 'var_dump(defined("DB_PATH") ? DB_PATH : "NAO DEFINIDO");'
# ^ -r SEMPRE mostra "NAO DEFINIDO" aqui, mesmo com -d auto_prepend_file — é
# o próprio -r que ignora o prepend, não um bug do prepend.php. Prova rápida
# de que -r nunca deve ser usado pra nada que toca o banco.
```
Se descobrir que contaminou o banco de dev por engano: confirmar que é
só artefato local (gitignorado, `database/*.db` — nunca produção, que só
existe na VPS) e resetar (`rm database/fastcar.db*` + `php
install/migrar.php` recria limpo), nunca tentar "limpar" linha por linha.

## Passo 5 — SEMPRE adicionar guard em tests/smoke.php

Regra central deste tipo de projeto (documentada no topo do próprio
`tests/smoke.php`): "achou um bug de padrão? corrige em TODO o repo e
adiciona um guard aqui — nunca só no ponto onde apareceu". Performance
não é exceção — sem guard, um refactor futuro remove o PRAGMA/índice sem
ninguém perceber até o sistema voltar a ficar lento, sem nenhum sinal de
erro. 3 guards nesse padrão, todos no mesmo `tests/smoke.php`:

1. **PRAGMA presente** — `str_contains()` simples em `includes/db.php`
   verificando os PRAGMAs de leitura esperados.
2. **Índice nomeado presente nos 2 arquivos-fonte** —
   `install/schema.sql` E `install/migrar.php`, mesma lista de nomes
   (um array `$indicesEsperados` com 1 linha de comentário por índice
   explicando qual query ele resolve — útil pra quem ler o smoke.php
   entender o "porquê" sem precisar ir atrás do commit original).
3. **Índice existe de verdade no banco ao vivo** — só roda se o banco
   local existir (mesmo guard condicional que a seção "3. Schema" já usa
   pra colunas), consulta `sqlite_master WHERE type='index'`. Esse é o
   guard que pega o caso real "editei o SQL mas esqueci de rodar
   `migrar.php`" — já aconteceu mais de uma vez no desenvolvimento deste
   skill.

Ver a seção "4. Performance (velocidade)" de `tests/smoke.php` (se este
projeto já rodou este skill) pro código de referência — copiar o padrão
pra um projeto-irmão que ainda não tenha essa seção.

## Escrita em lote — contenção, não velocidade de leitura

Sintoma diferente do resto deste skill: "database is locked"
(`SQLSTATE[HY000] General error: 5`), 504/timeout, geralmente batendo
num horário fixo (cron de 30min, por exemplo). Causa: uma função que
processa uma lista (ex: importar N cobranças de uma API externa) fazendo
**uma escrita — INSERT/UPDATE — por item, cada uma auto-commitando
sozinha**, disputando o lock de escrita do SQLite N vezes separadas em
vez de 1, ao mesmo tempo que webhook/outro cron também escrevem.

Fix: agrupar o lote inteiro numa única transação:

```php
$db->beginTransaction();
try {
    foreach ($itens as $item) {
        // ... inserts/updates individuais ...
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    return ['ok' => false, 'erro' => $e->getMessage()];
}
```

Reduz de N disputas de lock pra 1 — bem mais rápido e bem menos chance
de bater em contenção real. Sempre com `try/catch` + `rollBack()` em
volta — sem isso, uma `PDOException` de lock no meio do loop propaga sem
ser pega e mata o script inteiro, perdendo o processamento dos itens
restantes também (não só o que falhou). Idempotência do lote (dedup por
alguma chave externa, tipo `asaas_payment_id`) é o que torna seguro
rodar de novo do zero depois de uma falha — confirmar que esse dedup já
existe antes de assumir que basta agrupar em transação.

Também vale pra escrita disparada em TODA carga de página condicionada
só a TTL de cache (ex: um badge de status com cache de 60s que faz
`setConfig()` quando expira) — se essa escrita não tem `try/catch`
próprio, uma contenção na hora errada derruba a PÁGINA INTEIRA (não só
o badge), via o handler global de exceção. Envolver em `try/catch` que
devolve o resultado já computado em memória mesmo se a escrita de cache
falhar — nunca deixar uma falha de "só guardar em cache pra próxima vez"
propagar como se fosse um erro real.

Não precisa de guard novo em `tests/smoke.php` pra esse tipo de fix —
não há um "padrão de string" simples pra detectar "loop de escrita sem
transação" sem falso positivo alto; a defesa aqui é o `try/catch`
genérico do handler global de exceção (`set_exception_handler()` em
`includes/db.php`, se o projeto já tiver isso) + revisão manual de
código em qualquer função nova que processe lista externa em lote, ou
qualquer escrita condicionada só por TTL de cache chamada de um include
compartilhado por muitas páginas.

## Checklist final

- [ ] Query lenta identificada com `EXPLAIN QUERY PLAN` (ou leitura
      direta do código), não um índice "por via das dúvidas"
- [ ] Testado o caso "sem filtro extra" (super_admin/supervisor vendo
      tudo), não só o caso mais filtrado/com menos linha
- [ ] Índice testado contra volume + cardinalidade realistas (pior caso
      espalhado, não só o melhor caso por coincidência), com número real
      medido (não "deveria ajudar")
- [ ] PRAGMAs de leitura presentes e documentados com o porquê de cada um
- [ ] Índice(s) novo(s) em `install/schema.sql` E `install/migrar.php`,
      nomeado(s) de forma que descreva a query que resolve
- [ ] Migração testada contra banco simulando produção ANTES da mudança
      (idempotente, dado preservado) — não só o banco de dev já migrado,
      e sempre via script em ARQUIVO (nunca `php -r`) pra não contaminar
      o banco de dev real
- [ ] `php install/migrar.php` rodado no banco de dev real antes do
      smoke final (senão o guard 3 acusa falha real, não falso positivo)
- [ ] 3 guards adicionados/atualizados em `tests/smoke.php` (PRAGMA,
      nomes nos arquivos-fonte, índice no banco ao vivo)
- [ ] Sanity-check manual de que o guard novo falha quando deveria
- [ ] `php tests/smoke.php` 100% verde antes de commitar
- [ ] Se isto rodou como parte do checklist PROATIVO (feature nova, não
      pedido explícito de performance): 1 bullet datado no CLAUDE.md do
      projeto registrando o achado (se houver) ou "nenhum achado, só
      checado" — mesma disciplina de todo o resto do projeto
