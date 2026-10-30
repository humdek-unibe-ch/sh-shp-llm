<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmSafetyConfigService (plugin v1.6.0, global danger
 * detection): a section value replaces the global LLM settings value, an empty
 * section value uses it, and the built-in defaults cover an empty or unreadable
 * settings page.
 *
 * No bootstrap, no database: the real service runs in a throw-away tree where a
 * small stand-in replaces BaseLlmService; the global settings come from a
 * subclass that overrides loadGlobalFields().
 *
 * Run: php tests/LlmSafetyConfigServiceTest.php
 */

require_once __DIR__ . '/../server/service/globals.php';

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_safety_config_test_' . getmypid();
$service_dir = $root . '/server/plugins/sh-shp-llm/server/service';

function safety_sandbox_write($path, $contents)
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function safety_sandbox_remove($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? safety_sandbox_remove($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(function () use ($root) {
    safety_sandbox_remove($root);
});

$real_file = __DIR__ . '/../server/service/LlmSafetyConfigService.php';
if (!is_file($real_file)) {
    echo "FAIL: server/service/LlmSafetyConfigService.php does not exist\n";
    exit(1);
}
safety_sandbox_write($service_dir . '/LlmSafetyConfigService.php', file_get_contents($real_file));
safety_sandbox_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
<?php
class BaseLlmService
{
    protected $services;
    protected $db;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = null;
    }

    protected function logError($message, $exception = null)
    {
    }
}
PHP
);

require_once $service_dir . '/LlmSafetyConfigService.php';

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class SafetyFakeModel
{
    public $keywords = '';
    public $emails = array();
    public $message = '';

    public function getDangerKeywords()
    {
        return $this->keywords;
    }

    public function getDangerNotificationEmails()
    {
        return $this->emails;
    }

    public function getDangerBlockedMessage()
    {
        return $this->message;
    }
}

class TestSafetyConfig extends LlmSafetyConfigService
{
    public $global = array();

    protected function loadGlobalFields()
    {
        return $this->global;
    }
}

function config_for($model, array $global)
{
    $config = new TestSafetyConfig(null, $model);
    $config->global = $global;
    return $config;
}

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

/* =========================================================================
 * Keywords
 * ========================================================================= */

$model = new SafetyFakeModel();
$model->keywords = 'foo, bar';
check('style_keywords_override_global',
    config_for($model, array('danger_keywords' => 'suicide'))->getKeywords() === array('foo', 'bar'));

$model = new SafetyFakeModel();
$model->keywords = ' , ,';
check('separator_only_style_uses_global',
    config_for($model, array('danger_keywords' => 'suicide'))->getKeywords() === array('suicide'));

$model = new SafetyFakeModel();
check('global_keywords_lowercased_utf8',
    config_for($model, array('danger_keywords' => 'Selbstmord, TÖTEN, selbstmord'))->getKeywords() === array('selbstmord', 'töten'));

$model = new SafetyFakeModel();
check('missing_global_uses_constant',
    in_array('kill myself', config_for($model, array())->getKeywords(), true));

/* =========================================================================
 * Notification emails
 * ========================================================================= */

$model = new SafetyFakeModel();
$model->emails = array('a@x.ch');
check('style_emails_override_global',
    config_for($model, array('danger_notification_emails' => "b@x.ch;c@x.ch"))->getNotificationEmails() === array('a@x.ch'));

$model = new SafetyFakeModel();
check('empty_style_emails_use_global',
    config_for($model, array('danger_notification_emails' => "b@x.ch; \nc@x.ch\n"))->getNotificationEmails() === array('b@x.ch', 'c@x.ch'));

$model = new SafetyFakeModel();
check('no_emails_anywhere_is_empty',
    config_for($model, array())->getNotificationEmails() === array());

/* =========================================================================
 * Blocked message
 * ========================================================================= */

$model = new SafetyFakeModel();
$model->message = 'Section text';
check('blocked_message_style_wins',
    config_for($model, array('danger_blocked_message' => 'Global text'))->getBlockedMessage() === 'Section text');

$model = new SafetyFakeModel();
$model->message = "  \n";
check('blocked_message_global_used',
    config_for($model, array('danger_blocked_message' => 'Global text'))->getBlockedMessage() === 'Global text');

$model = new SafetyFakeModel();
check('blocked_message_all_empty_uses_constant',
    config_for($model, array('danger_blocked_message' => ''))->getBlockedMessage() === LLM_DEFAULT_DANGER_BLOCKED_MESSAGE);

/* =========================================================================
 * A model without the getters (e.g. a prompt-lab runtime) uses the global values
 * ========================================================================= */

check('model_without_getters_uses_global',
    config_for(new stdClass(), array('danger_keywords' => 'suicide'))->getKeywords() === array('suicide'));

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
