<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * CLI worker for async LLM script execution.
 *
 * Spawned by LlmHooks::execute_llm_task() when a script has async=1.
 * Reads job arguments from a temp JSON file, bootstraps SelfHelp services
 * (llm_worker_bootstrap.php), executes the LLM script, saves results, and
 * triggers refresh events.
 *
 * Usage: php llm_async_worker.php <path_to_args_json_file>
 */

require_once __DIR__ . '/llm_worker_bootstrap.php';

$args = llm_worker_read_args($argv, 'llm_async_worker.php', 'LLM Async Worker');

/**
 * Write async worker debug messages as warnings to the normal PHP error log.
 *
 * Only active when DEBUG is enabled by the application bootstrap.
 */
function llm_async_worker_debug_warning($message)
{
    if (defined('DEBUG') && DEBUG) {
        trigger_error('LLM Async Worker: ' . $message, E_USER_WARNING);
    }
}

try {
    $services = llm_worker_boot_services(
        'LLM-Async-Worker/1.0',
        $args['http_host'] ?? 'localhost',
        $args['id_users'] ?? null
    );
} catch (Exception $e) {
    fwrite(STDERR, "LLM Async Worker: Failed to initialize services: " . $e->getMessage() . "\n");
    exit(1);
}

llm_async_worker_debug_warning(
    "started, script_id=" . ($args['script_id'] ?? 'null')
    . ", user=" . ($args['id_users'] ?? 'null')
);

// Execute the LLM script
try {
    require_once __DIR__ . "/LlmScriptService.php";
    $scriptService = new LlmScriptService($services);

    $script_id = $args['script_id'];
    $script_info = $scriptService->fetch_script($script_id);

    if (!$script_info) {
        $transaction = $services->get_transaction();
        $transaction->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_SCRIPT,
            null, null, null, false,
            "LLM Async Worker: Script not found; id=" . $script_id
        );
        fwrite(STDERR, "LLM Async Worker: Script not found: $script_id\n");
        exit(1);
    }

    $form_values = $args['form_values'] ?? [];
    $data_config = $script_info['data_config']
        ? json_decode($script_info['data_config'], true)
        : null;

    $result = $scriptService->execute_llm_script(
        $script_info['script'],
        $data_config,
        $form_values,
        $args['id_users'],
        $script_info['model'],
        $script_info['temperature'] !== null ? floatval($script_info['temperature']) : null,
        $script_info['max_tokens'] !== null ? intval($script_info['max_tokens']) : null,
        $script_info['id'],
        $script_info['name']
    );

    $save_success = $scriptService->save_llm_results(
        $result,
        $args['id_users'],
        $args['id_scheduledJobs'],
        $script_info['generated_id'],
        isset($args['linked_record_id']) && is_numeric($args['linked_record_id'])
            ? (int)$args['linked_record_id']
            : null
    );

    if ($save_success && $script_info['refresh_sections']) {
        $section_ids = json_decode($script_info['refresh_sections'], true);
        if (is_array($section_ids) && !empty($section_ids)) {
            $scriptService->insert_refresh_event(
                $args['id_users'],
                $section_ids,
                'llm_script_completed',
                json_encode(['generated_id' => $script_info['generated_id']])
            );
        }
    }

    llm_async_worker_debug_warning(
        "Script '" . $script_info['name'] . "' "
        . ($save_success ? "completed successfully" : "failed to save results")
        . " for user " . $args['id_users']
    );

    exit($save_success ? 0 : 1);

} catch (Exception $e) {
    error_log("LLM Async Worker: Fatal error: " . $e->getMessage());
    fwrite(STDERR, "LLM Async Worker: " . $e->getMessage() . "\n");
    exit(1);
}
