<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmHooks::onFormDeletedMemoryRules (plugin v1.6.0):
 * after a form entry is deleted, the memory rules picked on the form that
 * also run on "deleted" are scheduled with the entry's last values; every
 * other rule, a failed delete, a guest and an empty selection do nothing,
 * and the delete itself is never changed.
 *
 * The real LlmHooks and core BaseHooks run in a throw-away tree; the memory
 * services are small stand-ins that record what the hook asks of them.
 *
 * Run: php tests/LlmHooksMemoryDeleteTest.php
 */

$core_hooks = dirname(__DIR__, 3) . '/component/BaseHooks.php';
if (!is_file($core_hooks)) {
    echo "skipped: the SelfHelp core (BaseHooks.php) is not next to this plugin\n";
    exit(0);
}

foreach (array(
    'GUEST_USER_ID' => 1,
    'LLM_MEMORY_FIELD_RULE_IDS' => 'llm_memory_rule_ids',
    'ENTRY_RECORD_ID' => 'record_id',
    'actionTriggerTypes_finished' => 'finished',
    'actionTriggerTypes_deleted' => 'deleted',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_hooks_delete_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_hooks_delete_test_' . getmypid();
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

sandbox_write($root . '/server/component/BaseHooks.php', file_get_contents($core_hooks));
foreach (array(
    'component/LlmHooks.php',
    'service/LlmCsrfService.php',
    'service/LlmTransaction.php',
    'service/LlmErrorReporter.php',
    'service/LlmSecretService.php',
    'service/LlmSettingsValidator.php',
    'service/LlmApiKeysConfigService.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

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
foreach (array('LlmService', 'LlmScriptService', 'LlmPromptRegistryService', 'LlmPromptExecutionProfileService') as $service) {
    sandbox_write($plugin_server . '/service/' . $service . '.php', "<?php\n");
}

sandbox_write($plugin_server . '/service/LlmMemoryConfigService.php', <<<'PHP'
<?php
class LlmMemoryConfigService
{
    public function __construct($services = null) {}
    public function isMemoryEnabled() { return true; }
}
PHP
);
sandbox_write($plugin_server . '/service/LlmMemoryRuleService.php', <<<'PHP'
<?php
class LlmMemoryRuleService
{
    public function __construct($services = null) {}
    public function normalizeRuleIds($raw)
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$raw))));
    }
    public function getRuleById($id)
    {
        return $GLOBALS['rules'][(int)$id] ?? null;
    }
}
PHP
);
sandbox_write($plugin_server . '/service/LlmMemoryTriggerService.php', <<<'PHP'
<?php
class LlmMemoryTriggerService
{
    public function __construct($services = null) {}
    public function appendWorkerLogPublic($message, $context = array()) {}
    public function normalizeFormActionPayload($data, $user_id)
    {
        return array(
            'user_id' => $user_id,
            'trigger_type' => $data['trigger_type'],
            'record_id' => $data['record_id'],
            'fields' => $data['form_fields'],
        );
    }
    public function scheduleRuleJob($rule_ids, $normalized, $async, $description)
    {
        $GLOBALS['events'][] = 'schedule';
        $GLOBALS['jobs'][] = array('rules' => $rule_ids, 'payload' => $normalized, 'description' => $description);
        return 1;
    }
}
PHP
);
sandbox_write($plugin_server . '/service/LlmMemorySubmissionContextService.php', <<<'PHP'
<?php
class LlmMemorySubmissionContextService
{
    public function buildFormContext($model, array $values, $record_id)
    {
        return array(
            'table_name' => '0000000822',
            'form_name' => 'woopList',
            'fields' => $values,
            'readable_text' => '{"source":"form"}',
        );
    }
}
PHP
);

require_once $plugin_server . '/component/LlmHooks.php';

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class DeleteFakeServices
{
    public function get_db() { return null; }
}

/** The hooked form model: its stored entry and what the core delete answers. */
class FakeFormModel
{
    public $rule_ids = '5';
    public $result = true;
    public $entry = array(
        'record_id' => 42, 'entry_date' => '2026-10-29 10:00:00', 'id_users' => 3,
        'user_name' => 'tester', 'user_code' => 'c', 'id_actionTriggerTypes' => 2, 'triggerType' => 'finished',
        '_history_log' => '[]', 'wish' => 'listen more', 'plan' => 'breathe',
    );
    public $throw_on_read = false;

    public function get_db_field($name, $default = '')
    {
        if ($name === 'llm_memory_rule_ids') {
            return $this->rule_ids;
        }
        return $name === 'id' ? 822 : $default;
    }

    public function get_form_entry_record($record_id, $own_entries_only)
    {
        $GLOBALS['events'][] = 'read';
        if ($this->throw_on_read) {
            throw new RuntimeException('read failed');
        }
        return $this->entry;
    }

    public function delete_user_input($record_id)
    {
        $GLOBALS['events'][] = 'core_delete';
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

function reset_world(array $rule_triggers)
{
    $GLOBALS['events'] = array();
    $GLOBALS['jobs'] = array();
    $GLOBALS['rules'] = array();
    foreach ($rule_triggers as $id => $triggers) {
        $GLOBALS['rules'][$id] = array('id' => $id, 'trigger_types' => $triggers);
    }
    $_SESSION = array('id_user' => 3);
}

function deleteArgs($model, $record_id = 42)
{
    return array('record_id' => $record_id, 'hookedClassInstance' => $model, 'methodName' => 'delete_user_input');
}

$hooks = new LlmHooks(new DeleteFakeServices());

/* =========================================================================
 * Cases
 * ========================================================================= */

// a rule that also runs on delete
reset_world(array(5 => array('finished', 'deleted')));
$model = new FakeFormModel();
$res = $hooks->onFormDeletedMemoryRules(deleteArgs($model));
check('delete: the core result is returned unchanged', $res === true);
check('delete: the entry is read before it is deleted, the job queued after', $GLOBALS['events'] === array('read', 'core_delete', 'schedule'));
$job = $GLOBALS['jobs'][0] ?? array();
check('delete: one job for the rule', ($job['rules'] ?? null) === array(5));
check('delete: the job says the entry was deleted', ($job['payload']['trigger_type'] ?? '') === 'deleted'
    && ($job['payload']['record_id'] ?? 0) === 42
    && strpos($job['description'] ?? '', 'entry deleted') !== false);
check('delete: it carries the last answers without the bookkeeping columns',
    ($job['payload']['fields'] ?? null) === array('wish' => 'listen more', 'plan' => 'breathe'));

// only the rules that opted in run
reset_world(array(5 => array('finished', 'deleted'), 6 => array('finished')));
$model = new FakeFormModel();
$model->rule_ids = '5,6';
$hooks->onFormDeletedMemoryRules(deleteArgs($model));
check('opt-in: a rule without "deleted" is left out', ($GLOBALS['jobs'][0]['rules'] ?? null) === array(5));

reset_world(array(6 => array('finished')));
$model = new FakeFormModel();
$model->rule_ids = '6';
$res = $hooks->onFormDeletedMemoryRules(deleteArgs($model));
check('opt-in: no rule runs on delete - nothing is read or queued', $GLOBALS['events'] === array('core_delete') && $res === true);

// nothing selected, guest, failed delete
reset_world(array(5 => array('deleted')));
$model = new FakeFormModel();
$model->rule_ids = '';
$hooks->onFormDeletedMemoryRules(deleteArgs($model));
check('empty selection: only the delete runs', $GLOBALS['events'] === array('core_delete'));

reset_world(array(5 => array('deleted')));
$_SESSION = array('id_user' => GUEST_USER_ID);
$hooks->onFormDeletedMemoryRules(deleteArgs(new FakeFormModel()));
check('guest: only the delete runs', $GLOBALS['events'] === array('core_delete'));

reset_world(array(5 => array('deleted')));
$model = new FakeFormModel();
$model->result = false;
$res = $hooks->onFormDeletedMemoryRules(deleteArgs($model));
check('failed delete: no job, the failure is returned', $res === false && $GLOBALS['jobs'] === array());

// a failure while reading the entry never blocks the delete
reset_world(array(5 => array('deleted')));
$model = new FakeFormModel();
$model->throw_on_read = true;
$res = $hooks->onFormDeletedMemoryRules(deleteArgs($model));
check('read failure: the delete still runs and succeeds', $res === true && in_array('core_delete', $GLOBALS['events'], true));
check('read failure: no job without the entry', $GLOBALS['jobs'] === array());
clearstatcache();
check('read failure: it is logged', strpos((string)@file_get_contents($log_file), 'Memory form delete trigger') !== false);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
