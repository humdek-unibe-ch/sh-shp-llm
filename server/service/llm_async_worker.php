<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * CLI worker for async LLM script execution.
 *
 * Spawned by LlmHooks::execute_llm_task() when a script has async=1.
 * Reads job arguments from a temp JSON file, bootstraps SelfHelp services,
 * executes the LLM script, saves results, and triggers refresh events.
 *
 * Usage: php llm_async_worker.php <path_to_args_json_file>
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

if (!isset($argv[1]) || empty($argv[1])) {
    fwrite(STDERR, "Usage: php llm_async_worker.php <args_file>\n");
    exit(1);
}

$args_file = $argv[1];

if (!file_exists($args_file)) {
    fwrite(STDERR, "LLM Async Worker: Args file not found: $args_file\n");
    exit(1);
}

$args_json = file_get_contents($args_file);
$args = json_decode($args_json, true);

if (!$args || !is_array($args)) {
    fwrite(STDERR, "LLM Async Worker: Failed to parse args file\n");
    @unlink($args_file);
    exit(1);
}

@unlink($args_file);

// uopz extension blocks exit() by default; allow it for this CLI worker
if (function_exists('uopz_allow_exit')) {
    uopz_allow_exit(true);
}

ini_set('log_errors', '1');
ini_set('display_errors', '0');

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

// Determine the SelfHelp project root relative to this file:
// this file:    server/plugins/sh-shp-llm/server/service/llm_async_worker.php
// project root: ../../../../../
$project_root = realpath(__DIR__ . '/../../../../../');
if (!$project_root) {
    fwrite(STDERR, "LLM Async Worker: Cannot determine project root\n");
    exit(1);
}

// Fake the minimal $_SERVER variables that globals.php expects
$_SERVER['DOCUMENT_ROOT'] = $project_root;
$_SERVER['HTTP_HOST'] = $args['http_host'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'LLM-Async-Worker/1.0';

// Set session user for transaction logging
$_SESSION = [];
if (isset($args['id_users'])) {
    $_SESSION['id_user'] = $args['id_users'];
}

// Bootstrap SelfHelp core (same approach as ScheduledJobsQueue.php)
require_once $project_root . "/server/service/globals.php";
require_once $project_root . "/server/service/PageDb.php";
require_once $project_root . "/server/service/jobs/Mailer.php";
require_once $project_root . "/server/service/Transaction.php";
require_once $project_root . "/server/service/Router.php";
require_once $project_root . "/server/service/UserInput.php";
require_once $project_root . "/server/service/conditions/Condition.php";
require_once $project_root . "/server/service/JobScheduler.php";
require_once $project_root . "/server/service/Services.php";

// Load plugin hooks and globals (same as ScheduledJobsQueue.php)
$plugins_folder = realpath($project_root . '/server/plugins/');
ob_start();
if ($plugins_folder !== false && is_dir($plugins_folder)) {
    if ($handle = opendir($plugins_folder)) {
        while (false !== ($dir = readdir($handle))) {
            $plugin_dir = $plugins_folder . DIRECTORY_SEPARATOR . $dir;
            if (is_dir($plugin_dir) && $dir !== '.' && $dir !== '..') {
                $component_dir = $plugin_dir . '/server/component/';
                if (is_dir($component_dir)) {
                    if ($component_handle = opendir($component_dir)) {
                        while (false !== ($file = readdir($component_handle))) {
                            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                                $class_name = pathinfo($file, PATHINFO_FILENAME);
                                if (substr($class_name, -5) === 'Hooks') {
                                    require_once $component_dir . $file;
                                    $globals_file = $plugin_dir . '/server/service/globals.php';
                                    if (file_exists($globals_file)) {
                                        require_once $globals_file;
                                    }
                                }
                            }
                        }
                        closedir($component_handle);
                    }
                }
            }
        }
        closedir($handle);
    }
}
ob_end_clean();

// Create services in cron/CLI mode (no Login, no ACL)
try {
    $services = new Services(false);
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
        $script_info['max_tokens'] !== null ? intval($script_info['max_tokens']) : null
    );

    $save_success = $scriptService->save_llm_results(
        $result,
        $args['id_users'],
        $args['id_scheduledJobs'],
        $script_info['generated_id']
    );

    $scriptService->log_script_execution(
        $script_info['id'],
        $script_info['name'],
        $result,
        $script_info['model'],
        $script_info['temperature'] !== null ? floatval($script_info['temperature']) : null,
        $script_info['max_tokens'] !== null ? intval($script_info['max_tokens']) : null
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
