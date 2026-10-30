<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for danger detection in the Prompt Lab (plugin v1.6.0, global
 * danger detection):
 *
 *  - the execution profile has no `enable_danger_detection` switch any more;
 *  - a playground run reports the danger keywords found in the participant text
 *    (last user message and variable values) without any side effect: no email,
 *    no audit log, no blocked conversation.
 *
 * No bootstrap, no database: the real playground and profile services run in a
 * throw-away tree where every other file they require is a small stand-in.
 *
 * Run: php tests/LlmPlaygroundSafetyTest.php
 */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_playground_safety_test_' . getmypid();
$service_dir = $root . '/server/plugins/sh-shp-llm/server/service';
$real_service = str_replace('\\', '/', dirname(__DIR__)) . '/server/service';

function pg_write($path, $contents)
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function pg_remove($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? pg_remove($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(function () use ($root) {
    pg_remove($root);
});

pg_write($service_dir . '/LlmPromptPlaygroundService.php', file_get_contents($real_service . '/LlmPromptPlaygroundService.php'));
pg_write($service_dir . '/LlmPromptExecutionProfileService.php', file_get_contents($real_service . '/LlmPromptExecutionProfileService.php'));

pg_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
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
}
PHP
);

// Every other required file is an empty stand-in with the class it must provide
$stubs = array(
    'LlmService.php' => 'class LlmService { public function __construct($s) {} }',
    'LlmContextService.php' => 'class LlmContextService {}',
    'LlmFloatingModeService.php' => 'class LlmFloatingModeService {}',
    'LlmStrictConversationService.php' => 'class LlmStrictConversationService {}',
    'LlmFormModeService.php' => 'class LlmFormModeService {}',
    'LlmResponseService.php' => 'class LlmResponseService {}',
    'LlmPromptResponseRenderService.php' => 'class LlmPromptResponseRenderService {}',
    'LlmPromptRegistryService.php' => 'class LlmPromptRegistryService { public function __construct($s) {} }',
    'LlmScriptService.php' => 'class LlmScriptService { public function __construct($s) {} }',
    'LlmFormInterviewService.php' => 'class LlmFormInterviewService {}',
    'prompt/LlmPromptAssetLoader.php' => 'class LlmPromptAssetLoader { public function load($k) { return ""; } }',
    // Detection stand-in: records every call so a side effect would show up
    'LlmDangerDetectionService.php' => <<<'PHP'
class LlmDangerDetectionService
{
    public $model;

    public function __construct($services, $model)
    {
        $this->model = $model;
        $GLOBALS['T']['danger_models'][] = $model;
    }

    public function detectKeywords($message)
    {
        $GLOBALS['T']['scanned'][] = $message;
        $keywords = array_filter(array_map('trim', explode(',', strtolower((string)$this->model->getDangerKeywords()))));
        $found = array();
        foreach ($keywords as $keyword) {
            if (strpos(strtolower($message), $keyword) !== false) {
                $found[] = $keyword;
            }
        }
        return $found;
    }

    public function handleDetection()
    {
        $GLOBALS['T']['side_effects'][] = 'handleDetection';
    }

    public function checkMessage()
    {
        $GLOBALS['T']['side_effects'][] = 'checkMessage';
    }

    public function getCriticalSafetyContext()
    {
        return '[CRITICAL SAFETY INSTRUCTION] ' . $this->model->getDangerKeywords();
    }

    public function sendNotifications()
    {
        $GLOBALS['T']['side_effects'][] = 'sendNotifications';
    }
}
PHP
);
foreach ($stubs as $file => $code) {
    pg_write($service_dir . '/' . $file, "<?php\n" . $code . "\n");
}
pg_write($root . '/server/plugins/sh-shp-llm/server/component/style/llmFormLogList/LlmFormInterviewPreviewModel.php',
    "<?php\nclass LlmFormInterviewPreviewModel {}\n");

$GLOBALS['T'] = array('scanned' => array(), 'side_effects' => array(), 'danger_models' => array());

require_once $service_dir . '/LlmPromptPlaygroundService.php';

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
 * Execution profile: no enable switch
 * ========================================================================= */

$profile = new LlmPromptExecutionProfileService(null);
check('execution_profile_has_no_enable_flag: not a chat companion field',
    !in_array('enable_danger_detection', $profile->getCoreCompanionFieldNames('chat_runtime'), true)
    && in_array('danger_keywords', $profile->getCoreCompanionFieldNames('chat_runtime'), true));
$snapshot = $profile->buildConfigSnapshot('chat_runtime', array('enable_danger_detection' => '1', 'danger_keywords' => 'x'));
check('execution_profile_has_no_enable_flag: not in the run snapshot',
    !array_key_exists('enable_danger_detection', $snapshot) && $snapshot['danger_keywords'] === 'x');

/* =========================================================================
 * Playground: keyword hits are reported, nothing else happens
 * ========================================================================= */

$playground = new LlmPromptPlaygroundService(null);

$GLOBALS['T'] = array('scanned' => array(), 'side_effects' => array(), 'danger_models' => array());
$found = $playground->detectDangerKeywords(
    array('danger_keywords' => 'kill myself, overdose'),
    array('note' => 'fine'),
    array(
        array('role' => 'user', 'content' => 'an old message about an overdose'),
        array('role' => 'assistant', 'content' => 'reply'),
        array('role' => 'user', 'content' => 'I want to kill myself'),
    )
);
check('playground_reports_detected_keywords_without_side_effects: the last user message is scanned',
    $found === array('kill myself'));
check('playground_reports_detected_keywords_without_side_effects: no email, audit log or block',
    $GLOBALS['T']['side_effects'] === array());

$GLOBALS['T'] = array('scanned' => array(), 'side_effects' => array(), 'danger_models' => array());
$found = $playground->detectDangerKeywords(array('danger_keywords' => 'kill myself'), array('feeling' => 'I will kill myself'), array());
check('playground: variable values (form / interview runs) are scanned', $found === array('kill myself'));

$GLOBALS['T'] = array('scanned' => array(), 'side_effects' => array(), 'danger_models' => array());
check('playground: a harmless run reports nothing',
    $playground->detectDangerKeywords(array('danger_keywords' => 'kill myself'), array('a' => 'hello'), array()) === array());

/* =========================================================================
 * Form runtime: the safety block is in the system prompt, as in live forms
 * ========================================================================= */

$prompt = $playground->withFormSafetyContext('Reflect on the answers.', array('danger_keywords' => 'kill myself'));
check('form runtime system prompt carries the critical safety block (as live)',
    $prompt === "Reflect on the answers.\n\n[CRITICAL SAFETY INSTRUCTION] kill myself");

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
