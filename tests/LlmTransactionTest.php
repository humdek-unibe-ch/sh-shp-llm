<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmTransaction (plugin v1.6.0):
 *
 *  - rollback() rolls the PDO handle back and never performs the typeless
 *    clear_cache() (= apcu_clear_cache(), the global wipe BaseDb::rollback()
 *    ends with); it clears only the cache types the caller names and never
 *    throws;
 *  - begin()/commit()/rollback() are the plugin's one transaction API and
 *    the after-commit deferral comes with it, nothing to opt into: tasks
 *    queue inside a transaction, run in order at the outermost commit(),
 *    are dropped by the rollback() of the transaction they were queued in,
 *    and run at once when no transaction is open;
 *  - transactions nest like the core's savepoints: the work queued in an
 *    inner transaction waits for the outermost commit;
 *  - a transaction opened outside this class cannot be observed, so work
 *    queued inside it is dropped and reported, never started early;
 *  - the shutdown guard drops (and reports) tasks of a request that ended
 *    inside an open transaction;
 *  - the production class has no accessor that only the tests use.
 *
 * No bootstrap, no MySQL: fakes for the database handle and the cache, a
 * real in-memory SQLite PDO for the transaction semantics, and one child
 * process for the shutdown guard.
 *
 * Run: php tests/LlmTransactionTest.php
 */

require_once dirname(__DIR__) . '/server/service/LlmTransaction.php';

$error_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_transaction_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log);
register_shutdown_function(function () use ($error_log) {
    @unlink($error_log);
});

/* =========================================================================
 * Fakes
 * ========================================================================= */

class TxFakeDbh
{
    public $in_transaction = true;
    /** Open transaction levels, like ExtendedPdo::$_transactionDepth */
    public $depth = 0;
    public $rollbacks = 0;
    public $throw_on_rollback = false;

    public function inTransaction() { return $this->in_transaction; }

    public function rollBack()
    {
        if ($this->throw_on_rollback) {
            throw new PDOException('connection lost');
        }
        $this->rollbacks++;
        $this->depth = max(0, $this->depth - 1);
        $this->in_transaction = $this->depth > 0;
        return true;
    }
}

class TxFakeCache
{
    /** @var array one entry per clear_cache() call: the argument list it received */
    public $calls = array();
    public $throw = false;

    public function clear_cache($type = null, $id = null)
    {
        $this->calls[] = func_get_args();
        if ($this->throw) {
            throw new RuntimeException('cache down');
        }
        return true;
    }
}

class TxFakeDb
{
    public $dbh;
    public $cache;
    public $wipes = 0;
    public $commits = 0;
    public $throw_on_dbh = false;
    public $throw_on_begin = false;
    public $throw_on_commit = false;

    public function __construct()
    {
        $this->dbh = new TxFakeDbh();
        $this->cache = new TxFakeCache();
    }

    public function get_dbh()
    {
        if ($this->throw_on_dbh) {
            throw new RuntimeException('no handle');
        }
        return $this->dbh;
    }

    public function get_cache() { return $this->cache; }

    /** BaseDb::begin_transaction(): a savepoint when a transaction is open. */
    public function begin_transaction()
    {
        if ($this->throw_on_begin) {
            throw new PDOException('cannot start');
        }
        $this->dbh->depth++;
        $this->dbh->in_transaction = true;
    }

    /** BaseDb::commit(): only the outermost level really commits. */
    public function commit()
    {
        if ($this->throw_on_commit) {
            throw new PDOException('commit failed');
        }
        $this->commits++;
        $this->dbh->depth = max(0, $this->dbh->depth - 1);
        $this->dbh->in_transaction = $this->dbh->depth > 0;
    }

    /** BaseDb::rollback(): never reached by LlmTransaction. */
    public function rollback() { $this->wipes++; }
}

/** A connection with no transaction open. */
function idle_db()
{
    $db = new TxFakeDb();
    $db->dbh->in_transaction = false;
    return $db;
}

/* =========================================================================
 * Harness
 * ========================================================================= */

$GLOBALS['__checks'] = 0;
$GLOBALS['__fails'] = array();

function check($label, $cond)
{
    $GLOBALS['__checks']++;
    if (!$cond) {
        $GLOBALS['__fails'][] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

function log_text()
{
    clearstatcache();
    return is_file($GLOBALS['error_log']) ? (string)file_get_contents($GLOBALS['error_log']) : '';
}

function reset_log()
{
    @file_put_contents($GLOBALS['error_log'], '');
}

/** The open transaction frames of the class (it has no accessor for them). */
function frames_state()
{
    $property = new ReflectionProperty('LlmTransaction', 'frames');
    return $property->getValue();
}

function open_frames()
{
    return count(frames_state());
}

function queued_tasks()
{
    $n = 0;
    foreach (frames_state() as $frame) {
        $n += count($frame['tasks']);
    }
    return $n;
}

/** Leave no transaction or queue behind between cases. */
function reset_state()
{
    LlmTransaction::reportUnflushed();
    reset_log();
}

/* =========================================================================
 * 1. rollback(): PDO handle only, never the global wipe
 * ========================================================================= */

$db = new TxFakeDb();
$ok = LlmTransaction::rollback($db);
check('rollback: reports success', $ok === true);
check('rollback: the PDO handle was rolled back once', $db->dbh->rollbacks === 1);
check('rollback: BaseDb::rollback() (global wipe) is never called', $db->wipes === 0);
check('rollback: without cache types the cache is not touched at all', $db->cache->calls === array());

$db = new TxFakeDb();
$db->dbh->in_transaction = false;
$ok = LlmTransaction::rollback($db);
check('rollback: no open transaction -> nothing to undo, handle untouched',
    $ok === true && $db->dbh->rollbacks === 0);

$db = new TxFakeDb();
LlmTransaction::rollback($db, array('USER_INPUT'));
check('rollback: a named cache type is cleared exactly by type',
    $db->cache->calls === array(array('USER_INPUT')));

$db = new TxFakeDb();
LlmTransaction::rollback($db, array('USER_INPUT', 'CACHE_SECTIONS'));
check('rollback: several cache types are cleared one by one, each with its type',
    $db->cache->calls === array(array('USER_INPUT'), array('CACHE_SECTIONS')));

$db = new TxFakeDb();
LlmTransaction::rollback($db, array('', null, 0, false, array('x'), 'USER_INPUT'));
check('rollback: empty / non-string types are skipped, never a typeless clear_cache()',
    $db->cache->calls === array(array('USER_INPUT')));
$typeless = array_filter($db->cache->calls, function ($c) { return empty($c) || $c[0] === null; });
check('rollback: no clear_cache() call without a type', count($typeless) === 0);

$db = new TxFakeDb();
$db->dbh->in_transaction = false;
LlmTransaction::rollback($db, array('USER_INPUT'));
check('rollback: the named cache is cleared even when no transaction was open',
    $db->cache->calls === array(array('USER_INPUT')));

reset_state();
$db = new TxFakeDb();
$db->dbh->throw_on_rollback = true;
$threw = false;
try {
    $ok = LlmTransaction::rollback($db, array('USER_INPUT'));
} catch (\Throwable $t) {
    $threw = true;
}
check('rollback: a failing PDO rollback never throws', !$threw);
check('rollback: a failing PDO rollback is reported as false', $ok === false);
check('rollback: a failing PDO rollback is logged (through the reporter)',
    strpos(log_text(), '[LLM] Transaction rollback failed: PDOException: database error') !== false);
check('rollback: ...without the driver text, which can quote row values',
    strpos(log_text(), 'connection lost') === false);
check('rollback: the named cache is still cleared after a failed rollback',
    $db->cache->calls === array(array('USER_INPUT')));

reset_state();
$db = new TxFakeDb();
$db->throw_on_dbh = true;
$threw = false;
try {
    $ok = LlmTransaction::rollback($db);
} catch (\Throwable $t) {
    $threw = true;
}
check('rollback: a missing handle never throws and reports false', !$threw && $ok === false);

reset_state();
$db = new TxFakeDb();
$db->cache->throw = true;
$threw = false;
try {
    $ok = LlmTransaction::rollback($db, array('USER_INPUT', 'CACHE_SECTIONS'));
} catch (\Throwable $t) {
    $threw = true;
}
check('rollback: a failing cache clear never throws', !$threw);
check('rollback: a failing cache clear does not undo the rollback result', $ok === true && $db->dbh->rollbacks === 1);
check('rollback: a failing cache clear does not stop the next type', count($db->cache->calls) === 2);
check('rollback: a failing cache clear is logged', strpos(log_text(), 'Cache clear (USER_INPUT) after rollback failed') !== false);

/* =========================================================================
 * 2. begin() / commit() / rollback() against a real PDO (SQLite)
 * ========================================================================= */

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

    /** BaseDb on top of a real PDO */
    $real_db = new class($pdo) {
        public $dbh;
        public $cache;
        public function __construct($pdo) { $this->dbh = $pdo; $this->cache = new TxFakeCache(); }
        public function get_dbh() { return $this->dbh; }
        public function get_cache() { return $this->cache; }
        public function begin_transaction() { $this->dbh->beginTransaction(); }
        public function commit() { $this->dbh->commit(); }
    };

    reset_state();
    LlmTransaction::begin($real_db);
    $pdo->exec("INSERT INTO t (v) VALUES ('uncommitted')");
    check('real PDO: begin() opens a transaction', $pdo->inTransaction() === true && open_frames() === 1);
    LlmTransaction::rollback($real_db, array('USER_INPUT'));
    check('real PDO: the uncommitted row is gone after rollback',
        (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 0);
    check('real PDO: no transaction left open, no frame left', $pdo->inTransaction() === false && open_frames() === 0);
    check('real PDO: the cache was cleared by type only', $real_db->cache->calls === array(array('USER_INPUT')));

    LlmTransaction::begin($real_db);
    $pdo->exec("INSERT INTO t (v) VALUES ('committed')");
    $ran = array();
    LlmTransaction::afterCommit($real_db, function () use (&$ran, $pdo) {
        // What a memory worker with its own connection would see: the committed row
        $ran[] = (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() . ' row(s), in a transaction: '
            . ($pdo->inTransaction() ? 'yes' : 'no');
    });
    check('real PDO: the after-commit task has not run before commit()', $ran === array());
    $count = LlmTransaction::commit($real_db);
    check('real PDO: commit() makes the write durable and then runs the task',
        $count === 1 && $ran === array('1 row(s), in a transaction: no'));

    $threw = false;
    try {
        $ok = LlmTransaction::rollback($real_db);
    } catch (\Throwable $t) {
        $threw = true;
    }
    check('real PDO: a rollback after commit() is a harmless no-op (a late catch block)',
        !$threw && $ok === true
        && (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 1);

    // Nested: SAVEPOINT semantics with the real PDO (PDO itself has no nesting, so the
    // wrapper maps the inner level to a savepoint the way ExtendedPdo does).
    $nested_db = new class($pdo) {
        public $dbh;
        public $cache;
        private $depth = 0;
        public function __construct($pdo) { $this->dbh = $pdo; $this->cache = new TxFakeCache(); }
        public function get_dbh()
        {
            $self = $this;
            return new class($self, $this->dbh) {
                private $db;
                private $pdo;
                public function __construct($db, $pdo) { $this->db = $db; $this->pdo = $pdo; }
                public function inTransaction() { return $this->pdo->inTransaction(); }
                public function rollBack() { $this->db->rollbackLevel(); }
            };
        }
        public function get_cache() { return $this->cache; }
        public function begin_transaction()
        {
            if ($this->depth === 0) {
                $this->dbh->beginTransaction();
            } else {
                $this->dbh->exec('SAVEPOINT LEVEL' . $this->depth);
            }
            $this->depth++;
        }
        public function commit()
        {
            $this->depth--;
            if ($this->depth === 0) {
                $this->dbh->commit();
            } else {
                $this->dbh->exec('RELEASE SAVEPOINT LEVEL' . $this->depth);
            }
        }
        public function rollbackLevel()
        {
            $this->depth--;
            if ($this->depth === 0) {
                $this->dbh->rollBack();
            } else {
                $this->dbh->exec('ROLLBACK TO SAVEPOINT LEVEL' . $this->depth);
            }
        }
    };

    $pdo->exec('DELETE FROM t');
    reset_state();
    $ran = array();
    LlmTransaction::begin($nested_db);
    $pdo->exec("INSERT INTO t (v) VALUES ('outer')");
    LlmTransaction::afterCommit($nested_db, function () use (&$ran) { $ran[] = 'outer-task'; });
    LlmTransaction::begin($nested_db);
    $pdo->exec("INSERT INTO t (v) VALUES ('inner-undone')");
    LlmTransaction::afterCommit($nested_db, function () use (&$ran) { $ran[] = 'inner-undone-task'; });
    LlmTransaction::rollback($nested_db);
    $values = $pdo->query('SELECT v FROM t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check('real PDO nested: the inner rollback undoes only the inner write', $values === array('outer'));
    check('real PDO nested: ...and drops only the inner task', queued_tasks() === 1 && $ran === array());
    LlmTransaction::begin($nested_db);
    $pdo->exec("INSERT INTO t (v) VALUES ('inner-kept')");
    LlmTransaction::afterCommit($nested_db, function () use (&$ran) { $ran[] = 'inner-kept-task'; });
    LlmTransaction::commit($nested_db);
    check('real PDO nested: an inner commit runs nothing yet', $ran === array() && queued_tasks() === 2);
    $count = LlmTransaction::commit($nested_db);
    $values = $pdo->query('SELECT v FROM t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check('real PDO nested: the outermost commit keeps both surviving writes', $values === array('outer', 'inner-kept'));
    check('real PDO nested: ...and runs the surviving tasks in order',
        $count === 2 && $ran === array('outer-task', 'inner-kept-task'));
} else {
    echo "skip: pdo_sqlite not available - real PDO checks skipped\n";
}

/* =========================================================================
 * 3. begin() / commit(): after-commit work needs no opt-in
 * ========================================================================= */

reset_state();
$db = idle_db();
$ran = array();
$result = LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'immediate'; });
check('defer: with no transaction open the task runs at once', $ran === array('immediate') && $result === true);
check('defer: with no transaction open nothing is queued', queued_tasks() === 0 && open_frames() === 0);

$threw = false;
try {
    LlmTransaction::afterCommit($db, function () { throw new RuntimeException('caller sees this'); });
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'caller sees this';
}
check('defer: with no transaction open an exception reaches the caller exactly as before', $threw);

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
check('begin: the database transaction was started and a frame opened',
    $db->dbh->in_transaction === true && open_frames() === 1);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'a'; });
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'b'; });
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'c'; });
check('defer: inside a transaction nothing runs yet', $ran === array());
check('defer: inside a transaction the tasks are queued', queued_tasks() === 3);
$count = LlmTransaction::commit($db);
check('commit: the database commit happened', $db->commits === 1 && $db->dbh->in_transaction === false);
check('commit: runs every queued task in order', $ran === array('a', 'b', 'c'));
check('commit: reports how many tasks ran', $count === 3);
check('commit: the queue and the frame are gone afterwards', queued_tasks() === 0 && open_frames() === 0);

$ran = array();
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'after-commit'; });
check('commit: the transaction is over (later tasks run at once)', $ran === array('after-commit'));

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'first'; });
LlmTransaction::afterCommit($db, function () { throw new LogicException('task two exploded'); });
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'third'; });
// Whatever a memory task throws - an Exception of any family or a PHP Error - the commit is already durable.
LlmTransaction::afterCommit($db, function () { throw new RuntimeException('task four exploded'); });
LlmTransaction::afterCommit($db, function () { throw new TypeError('task five exploded'); });
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'sixth'; });
$threw = false;
try {
    $count = LlmTransaction::commit($db);
} catch (\Throwable $t) {
    $threw = true;
}
check('defer: a failing task never throws out of commit() (the commit is durable)', !$threw);
check('defer: failing tasks (Exception and Error alike) do not stop the others', $ran === array('first', 'third', 'sixth'));
check('defer: failing tasks still count as run', $count === 6);
check('defer: a failing task is logged with its class and message',
    strpos(log_text(), 'After-commit task failed: LogicException: task two exploded') !== false);
check('defer: a RuntimeException is logged too',
    strpos(log_text(), 'After-commit task failed: RuntimeException: task four exploded') !== false);
check('defer: a PHP Error (TypeError) is logged and contained',
    strpos(log_text(), 'After-commit task failed: TypeError: task five exploded') !== false);

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran, $db) {
    $ran[] = 'outer';
    // A task that triggers more work runs it right away: the transaction is already over.
    LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'nested'; });
});
LlmTransaction::commit($db);
check('defer: work queued by a running task runs immediately, not into a dead queue',
    $ran === array('outer', 'nested') && queued_tasks() === 0 && open_frames() === 0);

/* ---- nesting ----------------------------------------------------------- */

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'a'; });
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'b'; });
check('nesting: an inner transaction has its own frame', open_frames() === 2);
$count = LlmTransaction::commit($db);
check('nesting: an inner commit runs nothing', $count === 0 && $ran === array());
check('nesting: ...its tasks wait with the outer transaction', open_frames() === 1 && queued_tasks() === 2);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'c'; });
$count = LlmTransaction::commit($db);
check('nesting: the outermost commit runs everything, in the order it was queued',
    $count === 3 && $ran === array('a', 'b', 'c') && open_frames() === 0);

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'a'; });
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'b'; });
LlmTransaction::rollback($db);
check('nesting: an inner rollback drops only the inner tasks', queued_tasks() === 1 && open_frames() === 1);
check('nesting: ...and says so', substr_count(log_text(), '1 after-commit task(s) dropped: the transaction was rolled back') === 1);
LlmTransaction::commit($db);
check('nesting: the outer transaction goes on and releases its own task', $ran === array('a'));

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'inner'; });
LlmTransaction::commit($db);
LlmTransaction::rollback($db);
check('nesting: an outer rollback drops what the committed inner transaction had queued',
    $ran === array() && queued_tasks() === 0 && open_frames() === 0);

/* ---- rollback drops the queue ------------------------------------------ */

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'memory'; });
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'memory2'; });
LlmTransaction::rollback($db);
check('discard: rollback drops the queued tasks', queued_tasks() === 0 && open_frames() === 0);
check('discard: nothing was run', $ran === array());
check('discard: the drop is reported once, with the count',
    substr_count(log_text(), '2 after-commit task(s) dropped: the transaction was rolled back') === 1);

$ran = array();
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'after-rollback'; });
check('discard: the transaction is over after a rollback (later tasks run at once)', $ran === array('after-rollback'));

reset_state();
$db = idle_db();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () {});
$db->dbh->in_transaction = false;
LlmTransaction::rollback($db);
check('discard: a rollback with no open database transaction still drops the queue',
    queued_tasks() === 0 && open_frames() === 0);

reset_state();
$db = idle_db();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () {});
$db->dbh->throw_on_rollback = true;
LlmTransaction::rollback($db);
check('discard: a failed rollback still drops the queue (fail closed)', queued_tasks() === 0 && open_frames() === 0);

/* ---- failures of begin() and commit() ----------------------------------- */

reset_state();
$db = idle_db();
$db->throw_on_begin = true;
$threw = false;
try {
    LlmTransaction::begin($db);
} catch (PDOException $e) {
    $threw = true;
}
check('begin: a database that refuses to start throws', $threw);
check('begin: ...and no frame is left behind', open_frames() === 0);

reset_state();
$db = idle_db();
$ran = array();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'never'; });
$db->throw_on_commit = true;
$threw = false;
try {
    LlmTransaction::commit($db);
} catch (PDOException $e) {
    $threw = true;
}
check('commit: a failing commit throws to the caller', $threw);
check('commit: ...nothing was run (the data is not durable)', $ran === array() && queued_tasks() === 1);
$db->throw_on_commit = false;
LlmTransaction::rollback($db);
check('commit: the caller\'s rollback drops what the failed commit left queued',
    $ran === array() && queued_tasks() === 0 && open_frames() === 0);

reset_state();
$db = idle_db();
$db->begin_transaction();
$count = LlmTransaction::commit($db);
check('commit: with no frame it is a plain database commit', $count === 0 && $db->commits === 1);

/* ---- a transaction the plugin did not open ------------------------------ */

reset_state();
$db = idle_db();
$db->begin_transaction(); // core code, another plugin: not opened through LlmTransaction
$ran = array();
$result = LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'too-early'; });
check('foreign transaction: a task queued inside it is not run early', $ran === array());
check('foreign transaction: ...and is reported as dropped, not silently lost',
    $result === false
    && strpos(log_text(), '1 after-commit task(s) dropped: it was queued inside a transaction that was not opened with LlmTransaction::begin()') !== false);
check('foreign transaction: ...and not queued for later', queued_tasks() === 0);

reset_state();
$db = idle_db();
$db->begin_transaction(); // the foreign outer transaction
$ran = array();
LlmTransaction::begin($db); // a savepoint inside it
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'too-early'; });
$count = LlmTransaction::commit($db); // releases the savepoint only
check('foreign transaction: the plugin\'s commit inside it only released a savepoint, nothing ran', $count === 0 && $ran === array());
check('foreign transaction: ...the dropped task is reported',
    strpos(log_text(), '1 after-commit task(s) dropped: a transaction that was not opened with LlmTransaction::begin() is still open') !== false);
check('foreign transaction: ...and the frame is gone', open_frames() === 0 && queued_tasks() === 0);

reset_state();
$db = idle_db();
$db->begin_transaction();
LlmTransaction::begin($db);
$count = LlmTransaction::commit($db);
check('foreign transaction: a plugin transaction without tasks inside it is no problem and logs nothing',
    $count === 0 && log_text() === '');

/* =========================================================================
 * 4. Shutdown guard
 * ========================================================================= */

reset_state();
$db = idle_db();
LlmTransaction::begin($db);
LlmTransaction::afterCommit($db, function () {});
LlmTransaction::afterCommit($db, function () {});
LlmTransaction::reportUnflushed();
check('guard: tasks of a request that ended inside a transaction are dropped', queued_tasks() === 0 && open_frames() === 0);
check('guard: ... and reported, not silently lost',
    strpos(log_text(), '2 after-commit task(s) were never released') !== false);
$ran = array();
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'late'; });
$db->dbh->in_transaction = false;
LlmTransaction::afterCommit($db, function () use (&$ran) { $ran[] = 'late-idle'; });
check('guard: the frames are closed afterwards (a late task from another shutdown function is not queued for ever)',
    $ran === array('late-idle') && queued_tasks() === 0);

reset_state();
$db = idle_db();
LlmTransaction::begin($db);
LlmTransaction::reportUnflushed();
check('guard: an unbalanced begin() without tasks is reported too',
    strpos(log_text(), 'The request ended inside 1 open transaction(s)') !== false
    && strpos(log_text(), 'never released') === false);

reset_state();
LlmTransaction::reportUnflushed();
check('guard: nothing open -> nothing reported', log_text() === '');

// The real thing: a request that exits inside an open transaction never runs the task.
$child_log = $error_log . '.child';
$marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_transaction_child_marker_' . getmypid();
@unlink($child_log);
@unlink($marker);
// A script file (not `php -r`): inline code does not survive Windows argument quoting.
$child_script = $error_log . '.child.php';
file_put_contents($child_script, '<?php' . "\n"
    . 'require ' . var_export(dirname(__DIR__) . '/server/service/LlmTransaction.php', true) . ";\n"
    . "ini_set('log_errors', '1');\n"
    . 'ini_set(\'error_log\', ' . var_export($child_log, true) . ");\n"
    . "class ChildDbh { public function inTransaction() { return true; } }\n"
    . "class ChildDb {\n"
    . "    public function begin_transaction() {}\n"
    . "    public function commit() {}\n"
    . "    public function get_dbh() { return new ChildDbh(); }\n"
    . "}\n"
    . "\$db = new ChildDb();\n"
    . "LlmTransaction::begin(\$db);\n"
    . 'LlmTransaction::afterCommit($db, function () { file_put_contents(' . var_export($marker, true) . ", 'RAN'); });\n"
    . "exit(0);\n");
exec('"' . PHP_BINARY . '" "' . $child_script . '" 2>&1', $child_out, $child_code);
@unlink($child_script);
check('guard (child process): exit inside a transaction never runs the queued task', !is_file($marker));
$child_text = is_file($child_log) ? (string)file_get_contents($child_log) : '';
check('guard (child process): the shutdown guard logged the drop',
    strpos($child_text, '1 after-commit task(s) were never released') !== false);
@unlink($child_log);
@unlink($marker);

/* =========================================================================
 * 5. No test seam on the production class
 * ========================================================================= */

check('api: the old opt-in functions are gone',
    !method_exists('LlmTransaction', 'beginDeferral')
    && !method_exists('LlmTransaction', 'runDeferred')
    && !method_exists('LlmTransaction', 'discardDeferred'));
check('api: no accessor that only the tests use (pendingCount)', !method_exists('LlmTransaction', 'pendingCount'));
$public = array();
foreach ((new ReflectionClass('LlmTransaction'))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    $public[] = $method->getName();
}
sort($public);
check('api: the public surface is begin / commit / rollback / afterCommit (+ the shutdown callback)',
    $public === array('afterCommit', 'begin', 'commit', 'reportUnflushed', 'rollback'));

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
