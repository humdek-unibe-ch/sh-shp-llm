<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmHooks::onCleanUserDataLlmCleanup (plugin v1.6.0), the
 * hook that removes the LLM-owned rows of a user after core's
 * UserModel::clean_user_data:
 *
 *  - the original method (and the hooks before it) runs first, a refusal of it
 *    (false, e.g. the ACL check) or a missing user id stops the LLM cleanup;
 *  - success deletes the rows of that user only, in one committed transaction;
 *  - a failed delete rolls the transaction back through the PDO handle: no
 *    half cleanup survives and the application cache is NOT wiped (core's
 *    BaseDb::rollback() ends in apcu_clear_cache());
 *  - an Error (not only an Exception) cannot leave the transaction open.
 *
 * No bootstrap, no MySQL: the real hook class, the real core BaseHooks and the
 * real LlmTransaction are copied into a throw-away tree that mirrors their
 * relative require_once targets; the database is an in-memory SQLite PDO behind
 * a BaseDb-shaped stand-in, so "rolled back" means the rows are really back.
 *
 * Run: php tests/LlmHooksCleanupTest.php
 */

$core_hooks = dirname(__DIR__, 3) . '/component/BaseHooks.php';
if (!is_file($core_hooks)) {
    echo "skipped: the SelfHelp core (BaseHooks.php) is not next to this plugin\n";
    exit(0);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "skipped: pdo_sqlite is not available\n";
    exit(0);
}

if (!defined('LLM_UPLOAD_FOLDER')) {
    define('LLM_UPLOAD_FOLDER', 'upload');
}

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_hooks_cleanup_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_hooks_cleanup_test_' . getmypid();
$plugin_server = $root . '/server/plugins/sh-shp-llm/server';
$real_server = str_replace('\\', '/', dirname(__DIR__)) . '/server';

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
sandbox_write($root . '/server/component/BaseHooks.php', file_get_contents($core_hooks));
foreach (array(
    'component/LlmHooks.php',
    'service/LlmCsrfService.php',
    'service/LlmTransaction.php',
    'service/LlmErrorReporter.php',
    'service/LlmFileUtility.php',
    'service/LlmSecretService.php',
    'service/LlmSettingsValidator.php',
    'service/LlmApiKeysConfigService.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

// Stand-ins
sandbox_write($root . '/server/component/BaseModel.php', <<<'PHP'
<?php
abstract class BaseModel
{
    protected $services;
    protected $db;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }
}
PHP
);
sandbox_write($root . '/server/component/style/BaseStyleComponent.php', "<?php\nclass BaseStyleComponent {}\n");
foreach (array(
    'LlmService', 'LlmScriptService', 'LlmPromptRegistryService', 'LlmPromptExecutionProfileService',
    'LlmMemoryConfigService', 'LlmMemoryTriggerService', 'LlmMemoryRuleService', 'LlmMemorySubmissionContextService',
) as $service) {
    sandbox_write($plugin_server . '/service/' . $service . '.php', "<?php\n");
}

require_once $plugin_server . '/component/LlmHooks.php';

/* =========================================================================
 * Stand-ins for what the hook talks to
 * ========================================================================= */

/** Everything that touches the database or the cache, in order. */
$GLOBALS['events'] = array();

/** The PDO handle behind BaseDb::get_dbh(): real SQLite, rollbacks are logged. */
class CleanupPdo extends PDO
{
    public function rollBack(): bool
    {
        $GLOBALS['events'][] = 'rollback';
        return parent::rollBack();
    }
}

class CleanupFakeCache
{
    public function clear_cache($type = null, $id = null)
    {
        $GLOBALS['events'][] = 'clear_cache:' . ($type === null ? 'NULL' : $type);
    }
}

/** The BaseDb surface the hook (and LlmTransaction) uses. */
class CleanupFakeDb
{
    public $pdo;
    /** @var array table => Throwable thrown by remove_by_fk() before the DELETE runs */
    public $throw_on = array();
    private $cache;

    public function __construct()
    {
        $this->pdo = new CleanupPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->cache = new CleanupFakeCache();
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

    /** Same contract as BaseDb::query_db(): rows as associative arrays (reads are not events). */
    public function query_db($sql, $params = array())
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Same contract as BaseDb::remove_by_fk(): true/false, SQL errors never escape. */
    public function remove_by_fk($table, $fk, $id)
    {
        $GLOBALS['events'][] = 'delete:' . $table;
        if (isset($this->throw_on[$table])) {
            throw $this->throw_on[$table];
        }
        try {
            $stmt = $this->pdo->prepare("DELETE FROM $table WHERE $fk = :fk");
            return $stmt->execute(array(':fk' => $id));
        } catch (PDOException $e) {
            return false;
        }
    }
}

class CleanupFakeServices
{
    public $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function get_db()
    {
        return $this->db;
    }
}

/** The hooked UserModel: what the core method answers, recorded. */
class FakeUserModel
{
    public $result = true;
    public $calls = array();

    public function clean_user_data($uid)
    {
        $GLOBALS['events'][] = 'core_clean';
        $this->calls[] = $uid;
        return $this->result;
    }
}

/** The hooked Login: core user delete, recorded (its rows cascade in the real DB). */
class FakeLogin
{
    public $result = true;
    public $calls = array();

    public function delete_user($uid, $email, $transaction_by = 'by_system')
    {
        $GLOBALS['events'][] = 'core_delete_user';
        $this->calls[] = array($uid, $email);
        // The cascade removes the rows that list the uploads
        if ($this->result) {
            $GLOBALS['db']->pdo->exec("DELETE FROM llmMessages WHERE id_llmConversations = 1");
        }
        return $this->result;
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

/** Fresh tables: two rows of user 7, one row of user 8 in each. */
function seed($db)
{
    $db->throw_on = array();
    foreach (array('refresh_events', 'llmConversations', 'llmTraceChunks') as $table) {
        $db->pdo->exec("DROP TRIGGER IF EXISTS block_$table");
        $db->pdo->exec("DROP TABLE IF EXISTS $table");
        $db->pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY AUTOINCREMENT, id_users INTEGER)");
        foreach (array(7, 7, 8) as $uid) {
            $db->pdo->exec("INSERT INTO $table (id_users) VALUES ($uid)");
        }
    }
    // Messages with uploads: conversation 1 belongs to user 7, conversation 3 to user 8
    $db->pdo->exec("DROP TABLE IF EXISTS llmMessages");
    $db->pdo->exec("CREATE TABLE llmMessages (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llmConversations INTEGER, attachments TEXT)");
    $db->pdo->exec("INSERT INTO llmMessages (id_llmConversations, attachments) VALUES "
        . "(1, '[{\"path\":\"upload/7/a.png\",\"original_name\":\"a.png\"}]'), "
        . "(1, '{\"type\":\"form_submission\",\"values\":{\"path\":\"upload/8/b.png\"}}'), "
        . "(1, NULL), "
        . "(3, '[{\"path\":\"upload/8/b.png\",\"original_name\":\"b.png\"}]')");
    foreach (array('upload/7/a.png', 'upload/7/a.png.llm.jpg', 'upload/8/b.png') as $file) {
        sandbox_write($GLOBALS['plugin_root'] . '/' . $file, 'IMG');
    }
    $GLOBALS['events'] = array();
    $GLOBALS['log_file'] && file_put_contents($GLOBALS['log_file'], '');
}

function uploaded($file)
{
    clearstatcache();
    return is_file($GLOBALS['plugin_root'] . '/' . $file);
}

/** DELETEs on this table fail at SQL level (BaseDb::remove_by_fk then answers false). */
function block_deletes($db, $table)
{
    $db->pdo->exec("CREATE TRIGGER block_$table BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT, 'locked'); END");
}

function rows($db, $table, $uid)
{
    $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM $table WHERE id_users = :u");
    $stmt->execute(array(':u' => $uid));
    return (int)$stmt->fetchColumn();
}

function hookArgs($model, $uid)
{
    return array('uid' => $uid, 'hookedClassInstance' => $model, 'methodName' => 'clean_user_data');
}

function logText()
{
    clearstatcache();
    return is_file($GLOBALS['log_file']) ? file_get_contents($GLOBALS['log_file']) : '';
}

/** Transaction frames (with their queued after-commit tasks) LlmTransaction still holds. */
function openFrames()
{
    return count((new ReflectionProperty('LlmTransaction', 'frames'))->getValue());
}

function noCacheClears()
{
    foreach ($GLOBALS['events'] as $event) {
        if (strpos($event, 'clear_cache') === 0 || $event === 'WIPE') {
            return false;
        }
    }
    return true;
}

$GLOBALS['log_file'] = $log_file;
$GLOBALS['plugin_root'] = dirname($plugin_server);
$db = new CleanupFakeDb();
$hooks = new LlmHooks(new CleanupFakeServices($db));

/* =========================================================================
 * Success
 * ========================================================================= */
seed($db);
$core = new FakeUserModel();
$res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 7));
check('success: true is returned', $res === true);
check('success: the original method ran first, once, with the user id', $GLOBALS['events'][0] === 'core_clean' && $core->calls === array(7));
check('success: all tables are cleaned inside one begin/commit',
    $GLOBALS['events'] === array('core_clean', 'begin', 'delete:refresh_events', 'delete:llmConversations', 'delete:llmTraceChunks', 'commit'));
check('success: the rows of the user are gone', rows($db, 'refresh_events', 7) === 0 && rows($db, 'llmConversations', 7) === 0);
check('success: the per-user trace chunks of the user are gone', rows($db, 'llmTraceChunks', 7) === 0);
check('success: the rows of another user are untouched', rows($db, 'refresh_events', 8) === 1 && rows($db, 'llmConversations', 8) === 1
    && rows($db, 'llmTraceChunks', 8) === 1);
check('success: no transaction is left open', !$db->pdo->inTransaction());
check('success: no cache is cleared and nothing is logged', noCacheClears() && logText() === '');
check('success: no transaction frame (and no after-commit queue) is left behind', openFrames() === 0);
check('success: the uploads of the user and their LLM images are deleted', !uploaded('upload/7/a.png') && !uploaded('upload/7/a.png.llm.jpg'));
check('success: the upload of another user is kept (also when named in form values)', uploaded('upload/8/b.png'));

/* =========================================================================
 * Nothing to do
 * ========================================================================= */
seed($db);
$core = new FakeUserModel();
$core->result = false;
$res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 7));
check('core refused (false): false comes back untouched', $res === false);
check('core refused: no transaction, no delete', $GLOBALS['events'] === array('core_clean'));
check('core refused: every row is still there', rows($db, 'refresh_events', 7) === 2 && rows($db, 'llmConversations', 7) === 2);

foreach (array(0 => 'user id 0', -3 => 'a negative user id') as $uid => $label) {
    seed($db);
    $core = new FakeUserModel();
    $res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, $uid));
    check("$label: the original result is returned and nothing else happens", $res === true && $GLOBALS['events'] === array('core_clean'));
}

/* =========================================================================
 * A delete that reports failure
 * ========================================================================= */
seed($db);
block_deletes($db, 'refresh_events');
$core = new FakeUserModel();
$res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 7));
check('first delete fails: false is returned', $res === false);
check('first delete fails: rolled back through the PDO handle, never BaseDb::rollback',
    $GLOBALS['events'] === array('core_clean', 'begin', 'delete:refresh_events', 'delete:llmConversations', 'delete:llmTraceChunks', 'rollback'));
check('first delete fails: the deletes that did run are undone (nothing half cleaned)', rows($db, 'refresh_events', 7) === 2 && rows($db, 'llmConversations', 7) === 2
    && rows($db, 'llmTraceChunks', 7) === 2);
check('first delete fails: no transaction is left open', !$db->pdo->inTransaction());
check('first delete fails: the application cache is not wiped', noCacheClears());
check('first delete fails: the failure is logged with the user id', strpos(logText(), 'LLM cleanup after clean_user_data failed for user 7') !== false);

seed($db);
block_deletes($db, 'llmConversations');
$core = new FakeUserModel();
$res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 7));
check('second delete fails: false is returned', $res === false);
check('second delete fails: the already deleted first table is restored', rows($db, 'refresh_events', 7) === 2 && rows($db, 'llmConversations', 7) === 2);
check('second delete fails: rolled back through the handle, no wipe, no open transaction',
    in_array('rollback', $GLOBALS['events'], true) && noCacheClears() && !$db->pdo->inTransaction());
check('second delete fails: the rows of the other user are untouched too', rows($db, 'refresh_events', 8) === 1 && rows($db, 'llmConversations', 8) === 1);
check('second delete fails: no upload is deleted (the rows are back)', uploaded('upload/7/a.png') && uploaded('upload/7/a.png.llm.jpg'));

/* =========================================================================
 * A delete that throws
 * ========================================================================= */
$throwables = array(
    'an Exception' => new RuntimeException('db gone'),
    'a PDOException' => new PDOException('lost connection'),
    'an Error' => new TypeError('boom'),
);
foreach ($throwables as $label => $throwable) {
    seed($db);
    $db->throw_on['llmConversations'] = $throwable;
    $core = new FakeUserModel();
    try {
        $res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 7));
        $escaped = false;
    } catch (\Throwable $t) {
        $res = null;
        $escaped = true;
    }
    check("$label in the second delete: nothing escapes the hook, false is returned", !$escaped && $res === false);
    check("$label in the second delete: the first delete is rolled back", rows($db, 'refresh_events', 7) === 2 && rows($db, 'llmConversations', 7) === 2);
    check("$label in the second delete: rollback via the handle, no wipe, transaction closed",
        $GLOBALS['events'] === array('core_clean', 'begin', 'delete:refresh_events', 'delete:llmConversations', 'rollback')
        && noCacheClears() && !$db->pdo->inTransaction());
    // LlmErrorReporter: a database error keeps only its SQLSTATE code in the log
    // (its text can quote row values), everything else its message.
    $detail = $throwable instanceof PDOException
        ? 'database error ' . $throwable->getCode()
        : $throwable->getMessage();
    check("$label in the second delete: logged with the user id and the reason",
        strpos(logText(), '[LLM] Cleanup after clean_user_data for user 7 failed: ' . get_class($throwable) . ': ' . $detail) !== false);
    if ($throwable instanceof PDOException) {
        check("$label in the second delete: ...without the driver text", strpos(logText(), $throwable->getMessage()) === false);
    }
}

seed($db);
$db->throw_on['refresh_events'] = new TypeError('first boom');
$core = new FakeUserModel();
$res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 7));
check('an Error in the first delete: false, one rollback, transaction closed',
    $res === false && $GLOBALS['events'] === array('core_clean', 'begin', 'delete:refresh_events', 'rollback') && !$db->pdo->inTransaction());

/* =========================================================================
 * The hook stays usable after failures
 * ========================================================================= */
seed($db);
$core = new FakeUserModel();
$res = $hooks->onCleanUserDataLlmCleanup(hookArgs($core, 8));
check('after the failures a cleanup still succeeds', $res === true && rows($db, 'refresh_events', 8) === 0 && rows($db, 'llmConversations', 8) === 0);
check('no transaction frame is left behind by any of the failures', openFrames() === 0);

/* =========================================================================
 * Core user delete (Login::delete_user): the uploads go with the user
 * ========================================================================= */
function deleteArgs($login, $uid)
{
    return array('uid' => $uid, 'email' => 'a@example.org', 'hookedClassInstance' => $login, 'methodName' => 'delete_user');
}

seed($db);
$login = new FakeLogin();
$res = $hooks->onDeleteUserLlmFiles(deleteArgs($login, 7));
check('delete user: the core delete ran once and its result is returned', $res === true && $login->calls === array(array(7, 'a@example.org')));
check('delete user: the uploads of the user and their LLM images are deleted', !uploaded('upload/7/a.png') && !uploaded('upload/7/a.png.llm.jpg'));
check('delete user: the upload of another user is kept', uploaded('upload/8/b.png'));

seed($db);
$login = new FakeLogin();
$login->result = false;
$res = $hooks->onDeleteUserLlmFiles(deleteArgs($login, 7));
check('delete user refused: false is returned and the files are kept', $res === false && uploaded('upload/7/a.png') && uploaded('upload/7/a.png.llm.jpg'));

seed($db);
$login = new FakeLogin();
$login->result = true;
// The upload lookup joins the conversations: without them it fails
$db->pdo->exec("DROP TABLE llmConversations");
$res = $hooks->onDeleteUserLlmFiles(deleteArgs($login, 7));
check('delete user: a failing upload lookup never blocks the delete (and is logged)', $res === true && count($login->calls) === 1
    && strpos(logText(), 'deleted user') !== false);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
