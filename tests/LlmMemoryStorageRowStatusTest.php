<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone regression test for LlmMemoryStorageService (plugin v1.6.0):
 * the memory rows it writes through UserInput::save_data() are always live.
 *
 * save_data() reads the key `trigger_type` as the status of the row it
 * writes. A memory update for a deleted form entry once passed its event
 * trigger ("deleted") there, which marked the user's current memory and its
 * history row as deleted - the memory looked empty from then on.
 *
 * The real service runs with stand-ins for its base class and config.
 *
 * Run: php tests/LlmMemoryStorageRowStatusTest.php
 */

foreach (array('actionTriggerTypes_finished' => 'finished', 'LLM_MEMORY_STATUS_APPLIED' => 'applied') as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_memory_storage_status_test_' . getmypid();
$service_dir = $root . '/server/service';
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

register_shutdown_function(function () use ($root) {
    sandbox_remove($root);
});

sandbox_write($service_dir . '/LlmMemoryStorageService.php', file_get_contents($real_server . '/service/LlmMemoryStorageService.php'));
sandbox_write($service_dir . '/base/BaseLlmService.php', "<?php\nabstract class BaseLlmService { protected \$services; public function __construct(\$services) { \$this->services = \$services; } }\n");
sandbox_write($service_dir . '/LlmMemoryConfigService.php', "<?php\nclass LlmMemoryConfigService { public function __construct(\$services = null) {} }\n");
require_once $service_dir . '/LlmMemoryStorageService.php';

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    echo ($condition ? 'ok:   ' : 'FAIL: ') . $label . "\n";
    if (!$condition) {
        $failed++;
    }
}

$service = new LlmMemoryStorageService(null, new LlmMemoryConfigService());
$memory = array('memory_text' => 'text', 'memory_object' => array('a' => 1), 'change_summary' => 'x');

foreach (array('deleted', 'started', 'manual', 'manual_rebuild', '', 'finished') as $event_trigger) {
    $metadata = array('rule_id' => 5, 'source_type' => 'form_action_submit', 'trigger_type' => $event_trigger);

    $current = new ReflectionMethod($service, 'buildCurrentMemoryFields');
    $current->setAccessible(true);
    $fields = $current->invoke($service, 3, 'forest', $memory, $metadata);
    check("current memory row stays live for event \"$event_trigger\"", $fields['trigger_type'] === 'finished');

    $history = new ReflectionMethod($service, 'buildHistoryFields');
    $history->setAccessible(true);
    $fields = $history->invoke($service, 3, 'forest', $memory, $metadata, '{}');
    check("history row stays live for event \"$event_trigger\"", $fields['trigger_type'] === 'finished');
}

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
