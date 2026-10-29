<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the commit-ordering of memory updates (plugin
 * v1.6.0): "memory can be updated even when form approval fails".
 *
 * LlmMemoryTriggerService::enqueueMemoryUpdate is the single place every
 * trigger (form hook, SurveyJS hook, form-action task, chat, login,
 * profile) goes through before an update runs inline or a detached worker
 * is started. Inside a transaction opened with LlmTransaction::begin() the
 * update must wait for the commit and be dropped by a rollback; with no
 * transaction open it must run at once, exactly as before; inside a
 * transaction the plugin did not open (whose commit it cannot observe) it
 * is dropped and reported, never started early.
 *
 * No bootstrap, no database, no process is spawned: the real service and
 * the real LlmTransaction run in a throw-away tree where small stand-ins
 * replace the base class, the rule/config/update services and the
 * database. The sandbox has no llm_memory_worker.php, so the async branch
 * takes its (observable) "worker script missing, using sync fallback"
 * path instead of starting a process.
 *
 * Run: php tests/LlmMemoryTriggerServiceTest.php
 */

foreach (array(
    'transactionTypes_insert' => 'insert',
    'TRANSACTION_BY_LLM_MEMORY' => 'by_llm_memory',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

$error_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_memory_trigger_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_memory_trigger_test_' . getmypid();
$plugin_dir = $root . '/server/plugins/sh-shp-llm';
$service_dir = $plugin_dir . '/server/service';
$real_server = str_replace('\\', '/', dirname(__DIR__)) . '/server';
$worker_log = $plugin_dir . '/llm_memory_worker.log';

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

register_shutdown_function(function () use ($root, $error_log) {
    sandbox_remove($root);
    @unlink($error_log);
});

sandbox_write($service_dir . '/LlmMemoryTriggerService.php', file_get_contents($real_server . '/service/LlmMemoryTriggerService.php'));
sandbox_write($service_dir . '/LlmTransaction.php', file_get_contents($real_server . '/service/LlmTransaction.php'));
sandbox_write($service_dir . '/LlmErrorReporter.php', file_get_contents($real_server . '/service/LlmErrorReporter.php'));

sandbox_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
<?php
abstract class BaseLlmService
{
    protected $services;
    protected $db;
    protected $cache;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cache = $this->db->get_cache();
    }

    public static function resolvePhpCliBinary() { return PHP_BINARY; }
}
PHP
);

sandbox_write($service_dir . '/LlmMemoryConfigService.php', <<<'PHP'
<?php
class LlmMemoryConfigService
{
    public function __construct($services = null) {}
    public function isMemoryEnabled() { return $GLOBALS['mem']['enabled']; }
    public function getRuleTargetMemoryKeys($rule) { return $GLOBALS['mem']['keys']; }
    public function findMatchingRules($source_type, $criteria) { return array_values($GLOBALS['mem']['rules']); }
    public function getRuleById($id)
    {
        return isset($GLOBALS['mem']['rules'][(int)$id]) ? $GLOBALS['mem']['rules'][(int)$id] : null;
    }
}
PHP
);

sandbox_write($service_dir . '/LlmMemoryRuleService.php', <<<'PHP'
<?php
class LlmMemoryRuleService
{
    public function __construct($services = null) {}
    public function getRuleById($id)
    {
        return isset($GLOBALS['mem']['rules'][(int)$id]) ? $GLOBALS['mem']['rules'][(int)$id] : null;
    }
}
PHP
);

sandbox_write($service_dir . '/LlmMemoryUpdateService.php', <<<'PHP'
<?php
class LlmMemoryUpdateService
{
    public function __construct($services = null, $config = null) {}
    public function executeLlmSummarization($rule, $normalized)
    {
        if ($GLOBALS['mem']['fail_rule'] === (int)$rule['id']) {
            throw new RuntimeException('summarization failed for rule ' . (int)$rule['id']);
        }
        $GLOBALS['updates'][] = array(
            'rule' => (int)$rule['id'],
            'key' => isset($normalized['memory_key_override']) ? $normalized['memory_key_override'] : '',
            'user' => (int)$normalized['user_id'],
            'in_tx' => $GLOBALS['db']->dbh->in_transaction,
        );
        return true;
    }
}
PHP
);

/* =========================================================================
 * Fakes
 * ========================================================================= */

class MtFakeDbh
{
    public $in_transaction = false;
    public function inTransaction() { return $this->in_transaction; }
    public function rollBack() { $this->in_transaction = false; return true; }
}

class MtFakeCache
{
    public $calls = array();
    public function clear_cache($type = null, $id = null) { $this->calls[] = func_get_args(); return true; }
}

class MtFakeDb
{
    public $dbh;
    public $cache;
    public $wipes = 0;

    public function __construct()
    {
        $this->dbh = new MtFakeDbh();
        $this->cache = new MtFakeCache();
    }

    public function get_dbh() { return $this->dbh; }
    public function get_cache() { return $this->cache; }
    public function begin_transaction() { $this->dbh->in_transaction = true; }
    public function commit() { $this->dbh->in_transaction = false; }
    public function rollback() { $this->wipes++; }
}

class MtFakeTransaction
{
    public $logs = array();
    public function add_transaction() { $this->logs[] = func_get_args(); }
}

class MtFakeServices
{
    private $db;
    private $transaction;
    public function __construct($db) { $this->db = $db; $this->transaction = new MtFakeTransaction(); }
    public function get_db() { return $this->db; }
    public function get_transaction() { return $this->transaction; }
}

require_once $service_dir . '/LlmMemoryTriggerService.php';

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

function worker_log()
{
    clearstatcache();
    return is_file($GLOBALS['worker_log']) ? (string)file_get_contents($GLOBALS['worker_log']) : '';
}

function php_log()
{
    clearstatcache();
    return is_file($GLOBALS['error_log']) ? (string)file_get_contents($GLOBALS['error_log']) : '';
}

/** After-commit tasks still waiting in the open transactions (the class keeps no accessor for it). */
function queued_tasks()
{
    $n = 0;
    foreach ((new ReflectionProperty('LlmTransaction', 'frames'))->getValue() as $frame) {
        $n += count($frame['tasks']);
    }
    return $n;
}

/** Fresh world: one rule (id 1) with one memory key, memory on, nothing recorded. */
function fresh_world(array $rule_ids = array(1), array $keys = array('mem_key'))
{
    LlmTransaction::reportUnflushed(); // forget any transaction a previous case left open
    $GLOBALS['updates'] = array();
    $GLOBALS['mem'] = array('enabled' => true, 'keys' => $keys, 'rules' => array(), 'fail_rule' => 0);
    foreach ($rule_ids as $id) {
        $GLOBALS['mem']['rules'][$id] = array(
            'id' => $id, 'enabled' => true, 'label' => 'rule ' . $id, 'trigger_types' => array(),
        );
    }
    $GLOBALS['db'] = new MtFakeDb();
    @unlink($GLOBALS['worker_log']);
    @file_put_contents($GLOBALS['error_log'], '');
    return new LlmMemoryTriggerService(new MtFakeServices($GLOBALS['db']));
}

function payload()
{
    return array(
        'source_type' => 'form', 'source_ref' => 'tbl', 'trigger_type' => 'finished',
        'user_id' => 5, 'event_at' => '2026-01-01 00:00:00',
        'fields' => array('title' => 'x'), 'readable_text' => 'Title: x',
    );
}

$_SESSION = array('id_user' => 5);

/* =========================================================================
 * 1. No transaction scope: behavior is unchanged (runs at once)
 * ========================================================================= */

$svc = fresh_world();
$out = $svc->dispatchForRuleIds(array(1), payload(), false);
check('no scope, sync: the update runs inside the call', count($GLOBALS['updates']) === 1);
check('no scope, sync: the dispatched rule ids are returned', $out === array(1));

$svc = fresh_world();
$out = $svc->dispatchForRuleIds(array(1), payload(), true);
check('no scope, async: the worker branch ran at once (sync fallback in the sandbox)',
    count($GLOBALS['updates']) === 1
    && strpos(worker_log(), 'spawnAsyncWorker worker script missing, using sync fallback') !== false);
check('no scope, async: the fallback carries the rule and memory key',
    $GLOBALS['updates'][0]['rule'] === 1 && $GLOBALS['updates'][0]['key'] === 'mem_key'
    && $GLOBALS['updates'][0]['user'] === 5);

$svc = fresh_world();
$out = $svc->dispatchMemoryUpdate(payload(), false);
check('no scope, rule matching route (chat/login): runs at once',
    count($GLOBALS['updates']) === 1 && $out === array(1));

/* =========================================================================
 * 2. Inside an approval transaction: wait for the commit
 * ========================================================================= */

// sync
$svc = fresh_world();
LlmTransaction::begin($GLOBALS['db']);
$out = $svc->dispatchForRuleIds(array(1), payload(), false);
check('scope, sync: nothing ran inside the transaction', $GLOBALS['updates'] === array());
check('scope, sync: the update is queued', queued_tasks() === 1);
check('scope, sync: the dispatch result still reports the rule', $out === array(1));
check('scope, sync: the worker log shows what was prepared ...',
    strpos(worker_log(), 'enqueueMemoryUpdate prepared') !== false);
check('scope, sync: ... but not an execution yet', strpos(worker_log(), 'executing sync') === false);
LlmTransaction::commit($GLOBALS['db']);
check('scope, sync: after the commit the update runs once', count($GLOBALS['updates']) === 1);
check('scope, sync: ... and not inside a transaction', $GLOBALS['updates'][0]['in_tx'] === false);
check('scope, sync: ... with the payload snapshotted at dispatch time',
    $GLOBALS['updates'][0]['key'] === 'mem_key' && $GLOBALS['updates'][0]['user'] === 5);

// async (the detached-worker branch)
$svc = fresh_world();
LlmTransaction::begin($GLOBALS['db']);
$svc->dispatchForRuleIds(array(1), payload(), true);
check('scope, async: no worker is started inside the transaction',
    $GLOBALS['updates'] === array() && strpos(worker_log(), 'spawnAsyncWorker') === false);
LlmTransaction::commit($GLOBALS['db']);
check('scope, async: after the commit the worker branch runs',
    strpos(worker_log(), 'spawnAsyncWorker worker script missing') !== false
    && count($GLOBALS['updates']) === 1);

// the rule matching route used by chat/login/profile triggers
$svc = fresh_world();
LlmTransaction::begin($GLOBALS['db']);
$svc->dispatchMemoryUpdate(payload(), true);
check('scope, rule matching route: deferred as well', $GLOBALS['updates'] === array() && queued_tasks() === 1);
LlmTransaction::commit($GLOBALS['db']);
check('scope, rule matching route: runs after the commit', count($GLOBALS['updates']) === 1);

/* =========================================================================
 * 3. The regression: approval fails after the hooks queued the update
 * ========================================================================= */

foreach (array('sync' => false, 'async' => true) as $mode => $async) {
    $svc = fresh_world();
    LlmTransaction::begin($GLOBALS['db']);
    $svc->dispatchForRuleIds(array(1), payload(), $async);
    LlmTransaction::rollback($GLOBALS['db']); // completeApproval failed / commit failed
    LlmTransaction::commit($GLOBALS['db']);   // a stray commit must not resurrect it
    check("rollback, $mode: the memory update never runs", $GLOBALS['updates'] === array());
    check("rollback, $mode: no worker is ever started",
        strpos(worker_log(), 'spawnAsyncWorker') === false && strpos(worker_log(), 'executing sync') === false);
    check("rollback, $mode: nothing left queued", queued_tasks() === 0);
    check("rollback, $mode: the drop is reported",
        strpos(php_log(), 'after-commit task(s) dropped: the transaction was rolled back') !== false);
    check("rollback, $mode: the global cache wipe was not used", $GLOBALS['db']->wipes === 0 && $GLOBALS['db']->cache->calls === array());
}

/* =========================================================================
 * 4. Several rules and memory keys, skipped rules, failing updates
 * ========================================================================= */

$svc = fresh_world(array(1, 2), array('key_a', 'key_b'));
LlmTransaction::begin($GLOBALS['db']);
$out = $svc->dispatchForRuleIds(array(1, 2), payload(), false);
check('multi: two rules x two keys are all queued', queued_tasks() === 4 && $out === array(1, 2));
check('multi: none ran yet', $GLOBALS['updates'] === array());
LlmTransaction::commit($GLOBALS['db']);
$order = array_map(function ($u) { return $u['rule'] . ':' . $u['key']; }, $GLOBALS['updates']);
check('multi: after the commit all run in dispatch order', $order === array('1:key_a', '1:key_b', '2:key_a', '2:key_b'));

$svc = fresh_world(array(1, 2));
$GLOBALS['mem']['rules'][2]['enabled'] = false;
LlmTransaction::begin($GLOBALS['db']);
$out = $svc->dispatchForRuleIds(array(1, 2, 99), payload(), false);
check('skip: disabled and missing rules are not queued', $out === array(1) && queued_tasks() === 1);
LlmTransaction::commit($GLOBALS['db']);
check('skip: only the enabled rule ran', count($GLOBALS['updates']) === 1 && $GLOBALS['updates'][0]['rule'] === 1);

$svc = fresh_world();
$GLOBALS['mem']['enabled'] = false;
LlmTransaction::begin($GLOBALS['db']);
$out = $svc->dispatchForRuleIds(array(1), payload(), false);
check('skip: memory switched off queues nothing', $out === array() && queued_tasks() === 0);
LlmTransaction::rollback($GLOBALS['db']);

$svc = fresh_world(array(1, 2));
$GLOBALS['mem']['fail_rule'] = 1;
LlmTransaction::begin($GLOBALS['db']);
$svc->dispatchForRuleIds(array(1, 2), payload(), false);
$threw = false;
try {
    LlmTransaction::commit($GLOBALS['db']);
} catch (\Throwable $t) {
    $threw = true;
}
check('failure: a failing update never breaks the already committed request', !$threw);
check('failure: the other rule still ran', count($GLOBALS['updates']) === 1 && $GLOBALS['updates'][0]['rule'] === 2);
check('failure: the failure is logged',
    strpos(php_log(), 'After-commit task failed: RuntimeException: summarization failed for rule 1') !== false);

/* =========================================================================
 * 5. A transaction the plugin did not open: never started early
 * ========================================================================= */

foreach (array('sync' => false, 'async' => true) as $mode => $async) {
    $svc = fresh_world();
    $GLOBALS['db']->begin_transaction(); // core code / another plugin: not LlmTransaction::begin()
    $out = $svc->dispatchForRuleIds(array(1), payload(), $async);
    check("foreign transaction, $mode: the update does not run inside it", $GLOBALS['updates'] === array());
    check("foreign transaction, $mode: no worker is started", strpos(worker_log(), 'spawnAsyncWorker') === false);
    check("foreign transaction, $mode: it is not queued for later either", queued_tasks() === 0);
    check("foreign transaction, $mode: the drop is reported, with the reason",
        strpos(php_log(), 'after-commit task(s) dropped: it was queued inside a transaction that was not opened with LlmTransaction::begin()') !== false);
    check("foreign transaction, $mode: the dispatch itself does not fail", $out === array(1));
    $GLOBALS['db']->commit();
    check("foreign transaction, $mode: ...and committing it later does not resurrect it", $GLOBALS['updates'] === array());
}

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
