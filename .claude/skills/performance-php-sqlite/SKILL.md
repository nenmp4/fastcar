---
name: performance-php-sqlite
description: "Use this skill whenever the user asks to investigate, diagnose, or fix slowness/performance issues ('sistema lento', 'melhora desempenho', 'tá lento', 'demora carregar', 'trava', '504/timeout') in a PHP puro + SQLite project following the José/Jean stack pattern (includes/db.php::getDB() singleton, no framework, no ORM) — the same pattern used by JurídicoSaaS and Fastcar CRM. Covers SQLite PRAGMA tuning for reads, finding and adding missing indexes on hot-path queries, batching high-volume writes to avoid lock contention, and the mandatory discipline of adding a regression guard to tests/smoke.php for every fix. Not for frontend/JS rendering slowness or mobile layout (see the ux-mobile-php skill for that) and not for a different database engine (MySQL/Postgres tuning uses different levers)."
---

# Performance em PHP + SQLite — padrão José/Jean

Runbook pra diagnosticar e corrigir lentidão neste tipo de stack (PHP puro,
SQLite via `includes/db.php::getDB()`, sem framework/ORM, telas admin que
rodam query direto inline) — Fastcar CRM e qualquer projeto-irmão futuro
com a mesma arquitetura. Validado no Fastcar em 29/09/2026 ("vamos melhora
desempenho velocidade do sistema" → "AGORA TÁ RAPIDASH").

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

1. **Qual tela o usuário reportou como lenta?** Se não disse, pergunte —
   "lento" sem contexto pode ser 1 tela específica ou o sistema inteiro.
2. Abrir o(s) arquivo(s) dessa tela e ler toda query `SELECT`/`UPDATE`
   inline (`admin/*.php` deste padrão não usa query builder, é SQL cru —
   fácil de ler direto). Prestar atenção especial em:
   - Telas que carregam em **toda visita** de um perfil comum (dashboard,
     `admin/index.php`, `admin/financeiro.php`) — o maior volume de
     requests do sistema, onde 1 índice faltando dói mais.
   - `WHERE`/`JOIN` em coluna que não é a chave primária, especialmente
     `responsavel_id`, `etapa`, `status`, `created_at`/`data_vencimento`
     (colunas de dono/estado/data são as mais filtradas neste padrão de
     CRM).
   - Funções chamadas em TODA carga de página, não só sob demanda — ex.
     `finRecalcularAtrasados()` (Fastcar,
     `includes/financeiro.php`) roda um `UPDATE ... WHERE status=?
     AND data_vencimento < ?` em toda visita a 3 telas financeiras
     diferentes; sem índice em `(status, data_vencimento)` isso é um full
     table scan repetido a cada carregamento, não só uma vez.
3. Sem `sqlite3` CLI disponível (comum neste tipo de sandbox — confirmar
   com `which sqlite3` antes), usar PHP direto pra rodar
   `EXPLAIN QUERY PLAN`:
   ```php
   $db = new PDO('sqlite:' . $caminhoDoBanco);
   foreach ($db->query("EXPLAIN QUERY PLAN SELECT ...") as $r) print_r($r);
   ```
   `SCAN <tabela>` na saída = sem índice usado, full table scan.
   `SEARCH <tabela> USING INDEX <nome>` = já está usando um índice.

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

## Passo 3 — índices nas colunas quentes

Padrão de índice composto pra "minha carteira" (a query mais repetida em
qualquer CRM deste tipo — filtro por dono + status/etapa):

```sql
CREATE INDEX IF NOT EXISTS idx_<tabela>_responsavel ON <tabela>(responsavel_id, etapa);
```

A ORDEM das colunas no índice composto importa — a mais seletiva/sempre
presente no `WHERE` primeiro (aqui, `responsavel_id`), a de filtro
adicional depois (`etapa`). Um índice em `(responsavel_id, etapa)` também
serve sozinho pra uma query que só filtra `responsavel_id` (prefixo do
índice), mas não o contrário.

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
   # criar um banco novo a partir desse schema antigo, seedar 1 linha
   # rodar install/migrar.php (com DB_PATH apontando pra esse banco de teste,
   #   via um arquivo prepend.php com define('DB_PATH', ...) — NUNCA `php -r`
   #   inline, auto_prepend_file é silenciosamente ignorado nesse modo)
   # confirmar: índice(s) aplicado(s), dado semeado preservado
   # rodar a migração DE NOVO — confirmar idempotência (2ª rodada não falha/duplica)
   ```
3. `php tests/smoke.php` no banco de dev real, depois de rodar
   `php install/migrar.php` nele — **não pular esse passo**: editar
   schema.sql/migrar.php sem aplicar no banco de dev faz o guard do passo
   5 acusar falha real (o guard checando o banco ao vivo é o ponto — ver
   exemplo abaixo).
4. Opcional mas recomendado — sanity-check que o guard novo REALMENTE
   pega regressão: comentar/remover temporariamente o PRAGMA ou o nome do
   índice, rodar `php tests/smoke.php`, confirmar que falha com a
   mensagem certa, desfazer, confirmar `git diff --stat` limpo e o smoke
   voltando a passar. Sem isso, um guard mal escrito (regex errado,
   `str_contains` no arquivo errado) passa despercebido até o dia em que
   a regressão de verdade acontecer e ninguém percebe.

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
   `install/schema.sql` E `install/migrar.php`, mesma lista de nomes.
3. **Índice existe de verdade no banco ao vivo** — só roda se o banco
   local existir (mesmo guard condicional que a seção "3. Schema" já usa
   pra colunas), consulta `sqlite_master WHERE type='index'`. Esse é o
   guard que pega o caso real "editei o SQL mas esqueci de rodar
   `migrar.php`" — aconteceu no próprio desenvolvimento deste skill: o
   guard acusou os 5 índices ausentes no banco de dev local porque a
   migração só tinha rodado contra um banco de teste isolado, nunca no
   banco de dev de verdade.

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

Não precisa de guard novo em `tests/smoke.php` pra esse tipo de fix —
não há um "padrão de string" simples pra detectar "loop de escrita sem
transação" sem falso positivo alto; a defesa aqui é o `try/catch`
genérico do handler global de exceção (`set_exception_handler()` em
`includes/db.php`, se o projeto já tiver isso) + revisão manual de
código em qualquer função nova que processe lista externa em lote.

## Checklist final

- [ ] Query lenta identificada com `EXPLAIN QUERY PLAN` (ou leitura
      direta do código), não um índice "por via das dúvidas"
- [ ] PRAGMAs de leitura presentes e documentados com o porquê de cada um
- [ ] Índice(s) novo(s) em `install/schema.sql` E `install/migrar.php`,
      nomeado(s) de forma que descreva a query que resolve
- [ ] Migração testada contra banco simulando produção ANTES da mudança
      (idempotente, dado preservado) — não só o banco de dev já migrado
- [ ] `php install/migrar.php` rodado no banco de dev real antes do
      smoke final (senão o guard 3 acusa falha real, não falso positivo)
- [ ] 3 guards adicionados/atualizados em `tests/smoke.php` (PRAGMA,
      nomes nos arquivos-fonte, índice no banco ao vivo)
- [ ] Sanity-check manual de que o guard novo falha quando deveria
- [ ] `php tests/smoke.php` 100% verde antes de commitar
