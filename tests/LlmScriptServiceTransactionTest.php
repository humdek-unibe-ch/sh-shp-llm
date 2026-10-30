<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the transactions of LlmScriptService (plugin v1.6.0):
 * insert_new_script() and update_script().
 *
 *  - success commits (dataTables row + script row together);
 *  - any failure inside the transaction - an Exception or an Error - answers
 *    false, leaves nothing half written and no transaction open;
 *  - the rollback goes through the PDO handle and clears only the sections
 *    cache the transaction filled; core's BaseDb::rollback() would wipe the
 *    whole application cache (apcu_clear_cache()).
 *
 * No bootstrap, no MySQL: the real LlmScriptService and the real LlmTransaction
 * are copied into a throw-away tree that mirrors their require_once targets;
 * the database is an in-memory SQLite PDO behind a BaseDb-shaped stand-in.
 *
 * Run: php tests/LlmScriptServiceTransactionTest.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "skipped: pdo_sqlite is not available\n";
    exit(0);
}

foreach (array(
    'LLM_TABLE_SCRIPTS' => 'llm_scripts',
    'transactionTypes_insert' => 'insert',
    'transactionTypes_update' => 'update',
    'transactionBy_by_user' => 'by_user',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_script_txn_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_script_txn_test_' . getmypid();
$service_dir = $root . '/server/service';
$real_service = str_replace('\\', '/', dirname(__DIR__)) . '/server/service';

function sandbox_write($path, $contents)
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function sandbox_remove($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? sandbox_remove($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(function () use ($root, $log_file) {
    sandbox_remove($root);
    if (is_file($log_file)) {
        unlink($log_file);
    }
});

// Real files, byte for byte
foreach (array('LlmScriptService.php', 'LlmTransaction.php', 'LlmErrorReporter.php') as $file) {
    sandbox_write($service_dir . '/' . $file, file_get_contents($real_service . '/' . $file));
}

// Stand-ins
sandbox_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
<?php
abstract class BaseLlmService
{
    protected $services;
    protected $db;
    protected $cache;
    protected $cacheManager;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }
}
PHP
);
sandbox_write($service_dir . '/LlmService.php', "<?php\nclass LlmService { public function __construct(\$services) {} }\n");
sandbox_write($service_dir . '/LlmScriptImageService.php', "<?php\nclass LlmScriptImageService {}\n");
sandbox_write($service_dir . '/LlmPromptRegistryService.php', <<<'PHP'
<?php
class LlmPromptRegistryService
{
    /** @var \Throwable|null thrown by syncScriptSave() */
    public static $fail = null;

    public function __construct($services) {}

    public function syncScriptSave($script, $note, $meta)
    {
        $GLOBALS['events'][] = 'sync_script_save';
        if (self::$fail !== null) {
            throw self::$fail;
        }
    }
}
PHP
);

require_once $service_dir . '/LlmScriptService.php';

/* =========================================================================
 * Stand-ins for what the service talks to
 * ========================================================================= */

/** Everything that touches the database or the cache, in order. */
$GLOBALS['events'] = array();

class ScriptPdo extends PDO
{
    public function rollBack(): bool
    {
        $GLOBALS['events'][] = 'rollback';
        return parent::rollBack();
    }
}

class ScriptFakeCache
{
    const CACHE_TYPE_SECTIONS = 'CACHE_SECTIONS';

    public function clear_cache($type = null, $id = null)
    {
        $GLOBALS['events'][] = 'clear_cache:' . ($type === null ? 'NULL' : $type);
    }
}

/** The BaseDb/PageDb surface LlmScriptService uses. */
class ScriptFakeDb
{
    public $pdo;
    private $cache;

    public function __construct()
    {
        $this->pdo = new ScriptPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->cache = new ScriptFakeCache();
    }

    public function begin_transaction()
    {
        $GLOBALS['events'][] = 'begin';
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        $GLOBALS['events'][] = 'commit';
        return $this->pdo->commit();
    }

    /** Core's BaseDb::rollback(): roll back AND wipe the whole application cache. */
    public function rollback()
    {
        $GLOBALS['events'][] = 'WIPE';
        $this->pdo->rollBack();
        $this->cache->clear_cache();
    }

    public function get_dbh()
    {
        return $this->pdo;
    }

    public function get_cache()
    {
        return $this->cache;
    }

    public function clear_cache($type = null, $id = null)
    {
        $this->cache->clear_cache($type, $id);
    }

    public function insert($table, $entries, $update_entries = array())
    {
        $cols = implode(', ', array_keys($entries));
        $marks = implode(', ', array_map(function ($c) { return ':' . $c; }, array_keys($entries)));
        $stmt = $this->pdo->prepare("INSERT INTO $table ($cols) VALUES ($marks)");
        $stmt->execute($entries);
        return (int)$this->pdo->lastInsertId();
    }

    public function update_by_ids($table, $update, $where)
    {
        $set = implode(', ', array_map(function ($c) { return "$c = :s_$c"; }, array_keys($update)));
        $cond = implode(' AND ', array_map(function ($c) { return "$c = :w_$c"; }, array_keys($where)));
        $params = array();
        foreach ($update as $c => $v) {
            $params[':s_' . $c] = $v;
        }
        foreach ($where as $c => $v) {
            $params[':w_' . $c] = $v;
        }
        $stmt = $this->pdo->prepare("UPDATE $table SET $set WHERE $cond");
        return $stmt->execute($params);
    }

    public function query_db_first($sql, $params = array())
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

class ScriptFakeTransactionService
{
    /** @var \Throwable|null thrown by add_transaction() */
    public $fail = null;

    public function add_transaction($type, $by, $user, $table, $record)
    {
        $GLOBALS['events'][] = 'add_transaction:' . $type;
        if ($this->fail !== null) {
            throw $this->fail;
        }
    }
}

class ScriptFakeServices
{
    public $db;
    public $transaction;

    public function __construct($db, $transaction)
    {
        $this->db = $db;
        $this->transaction = $transaction;
    }

    public function get_db()
    {
        return $this->db;
    }

    public function get_user_input()
    {
        return new stdClass();
    }

    public function get_transaction()
    {
        return $this->transaction;
    }
}

/* =========================================================================
 * Helpers
 * ========================================================================= */

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    if ($condition) {
        echo "ok:   $label\n";
    } else {
        echo "FAIL: $label\n";
        $failed++;
    }
}

function reset_tables($db)
{
    foreach (array('dataTables', 'llm_scripts') as $table) {
        $db->pdo->exec("DROP TABLE IF EXISTS $table");
    }
    $db->pdo->exec('CREATE TABLE dataTables (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, displayName TEXT)');
    $db->pdo->exec('CREATE TABLE llm_scripts (id INTEGER PRIMARY KEY AUTOINCREMENT, generated_id TEXT, name TEXT, script TEXT,'
        . ' test_variables TEXT, async INTEGER, data_config TEXT, model TEXT, temperature REAL, max_tokens INTEGER,'
        . ' refresh_sections TEXT, combine_images INTEGER DEFAULT 0)');
    $GLOBALS['events'] = array();
    LlmPromptRegistryService::$fail = null;
}

function count_rows($db, $table)
{
    return (int)$db->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}

function no_global_clear()
{
    foreach ($GLOBALS['events'] as $event) {
        if ($event === 'WIPE' || $event === 'clear_cache:NULL') {
            return false;
        }
    }
    return true;
}

function count_event($event)
{
    return count(array_keys($GLOBALS['events'], $event, true));
}

function log_text()
{
    clearstatcache();
    return is_file($GLOBALS['log_file']) ? file_get_contents($GLOBALS['log_file']) : '';
}

function update_args($service, $sid, $name)
{
    return $service->update_script($sid, $name, 'Prompt {{x}}', '{}', false, '[]');
}

$GLOBALS['log_file'] = $log_file;
$_SESSION['id_user'] = 1;
$db = new ScriptFakeDb();
$transaction = new ScriptFakeTransactionService();
$service = new LlmScriptService(new ScriptFakeServices($db, $transaction));

/* =========================================================================
 * insert_new_script()
 * ========================================================================= */
reset_tables($db);
$sid = $service->insert_new_script();
check('insert: success returns the new script id', is_int($sid) && $sid > 0);
check('insert: the dataTables row and the script row are committed', count_rows($db, 'dataTables') === 1 && count_rows($db, 'llm_scripts') === 1);
check('insert: one begin and one commit, no rollback',
    $GLOBALS['events'] === array('begin', 'clear_cache:CACHE_SECTIONS', 'add_transaction:insert', 'commit'));
check('insert: no transaction is left open', !$db->pdo->inTransaction());

$failures = array(
    'an Exception' => new RuntimeException('audit log down'),
    'an Error' => new TypeError('boom'),
);
foreach ($failures as $label => $throwable) {
    reset_tables($db);
    $transaction->fail = $throwable;
    file_put_contents($log_file, '');
    $res = $service->insert_new_script();
    $transaction->fail = null;
    check("insert: $label inside the transaction answers false", $res === false);
    check("insert: $label - nothing half written (no dataTables row, no script row)",
        count_rows($db, 'dataTables') === 0 && count_rows($db, 'llm_scripts') === 0);
    check("insert: $label - rolled back through the PDO handle, never BaseDb::rollback",
        $GLOBALS['events'] === array('begin', 'clear_cache:CACHE_SECTIONS', 'add_transaction:insert', 'rollback', 'clear_cache:CACHE_SECTIONS'));
    check("insert: $label - the application cache is not wiped", no_global_clear());
    check("insert: $label - the sections cache is cleared once more after the rollback", count_event('clear_cache:CACHE_SECTIONS') === 2);
    check("insert: $label - no transaction is left open", !$db->pdo->inTransaction());
}

/* =========================================================================
 * update_script()
 * ========================================================================= */
reset_tables($db);
$sid = $service->insert_new_script();
$original = $db->query_db_first('SELECT * FROM llm_scripts WHERE id = :id', array(':id' => $sid));
$db->pdo->exec("UPDATE dataTables SET displayName = 'Original title'");
$GLOBALS['events'] = array();

$res = update_args($service, $sid, 'Renamed');
$row = $db->query_db_first('SELECT * FROM llm_scripts WHERE id = :id', array(':id' => $sid));
check('update: success returns the script id', $res === $sid);
check('update: the script and the dataTables title are both updated',
    $row['name'] === 'Renamed' && $row['script'] === 'Prompt {{x}}'
    && $db->pdo->query('SELECT displayName FROM dataTables')->fetchColumn() === 'Renamed');
check('update: one begin and one commit, no rollback',
    $GLOBALS['events'] === array('begin', 'clear_cache:CACHE_SECTIONS', 'sync_script_save', 'add_transaction:update', 'commit'));
check('update: no transaction is left open', !$db->pdo->inTransaction());

$db->pdo->exec("UPDATE dataTables SET displayName = 'Original title'");
$db->pdo->exec("UPDATE llm_scripts SET name = 'Original name', script = NULL");
foreach (array(
    'an Error in the prompt registry sync' => array('registry', new TypeError('registry boom')),
    'an Exception in the transaction log' => array('transaction', new RuntimeException('audit log down')),
) as $label => $failure) {
    $GLOBALS['events'] = array();
    file_put_contents($log_file, '');
    if ($failure[0] === 'registry') {
        LlmPromptRegistryService::$fail = $failure[1];
    } else {
        $transaction->fail = $failure[1];
    }
    $res = update_args($service, $sid, 'Renamed again');
    LlmPromptRegistryService::$fail = null;
    $transaction->fail = null;
    $row = $db->query_db_first('SELECT * FROM llm_scripts WHERE id = :id', array(':id' => $sid));
    check("update: $label answers false", $res === false);
    check("update: $label - the script row is back to what it was", $row['name'] === 'Original name' && $row['script'] === null);
    check("update: $label - the dataTables title is back to what it was",
        $db->pdo->query('SELECT displayName FROM dataTables')->fetchColumn() === 'Original title');
    check("update: $label - rolled back through the PDO handle, never BaseDb::rollback",
        in_array('rollback', $GLOBALS['events'], true) && !in_array('WIPE', $GLOBALS['events'], true));
    check("update: $label - the application cache is not wiped", no_global_clear());
    check("update: $label - the sections cache is cleared after the rollback (last event)",
        end($GLOBALS['events']) === 'clear_cache:CACHE_SECTIONS' && $GLOBALS['events'][count($GLOBALS['events']) - 2] === 'rollback');
    check("update: $label - no transaction is left open", !$db->pdo->inTransaction());
}

/* =========================================================================
 * Still usable after the failures
 * ========================================================================= */
$GLOBALS['events'] = array();
$res = update_args($service, $sid, 'Final');
check('after the failures an update still succeeds', $res === $sid && count_event('commit') === 1 && !$db->pdo->inTransaction());
$res = $service->insert_new_script();
check('after the failures an insert still succeeds', is_int($res) && $res > $sid);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
