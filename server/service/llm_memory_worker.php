<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * CLI worker for async LLM memory updates.
 *
 * Spawned by LlmMemoryTriggerService when a rule uses llm_summarize mode.
 * Reads job arguments from a temp JSON file, bootstraps SelfHelp services,
 * executes the memory update, and persists results.
 *
 * Usage: php llm_memory_worker.php <path_to_args_json_file>
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

if (!isset($argv[1]) || empty($argv[1])) {
    fwrite(STDERR, "Usage: php llm_memory_worker.php <args_file>\n");
    exit(1);
}

$args_file = $argv[1];

if (!file_exists($args_file)) {
    fwrite(STDERR, "LLM Memory Worker: Args file not found: $args_file\n");
    exit(1);
}

$args_json = file_get_contents($args_file);
$args = json_decode($args_json, true);

if (!$args || !is_array($args)) {
    fwrite(STDERR, "LLM Memory Worker: Failed to parse args file\n");
    @unlink($args_file);
    exit(1);
}

@unlink($args_file);

if (function_exists('uopz_allow_exit')) {
    uopz_allow_exit(true);
}

ini_set('log_errors', '1');
ini_set('display_errors', '0');

function llm_memory_worker_log_file()
{
    return realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'llm_memory_worker.log';
}

function llm_memory_worker_log($message, $context = array())
{
    $line = '[' . date('Y-m-d H:i:s') . '] LLM Memory Worker: ' . $message;
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $line .= ' | ' . $encoded;
        }
    }

    @file_put_contents(llm_memory_worker_log_file(), $line . PHP_EOL, FILE_APPEND);
}

function llm_memory_worker_debug($message)
{
    if (defined('DEBUG') && DEBUG) {
        trigger_error('LLM Memory Worker: ' . $message, E_USER_WARNING);
    }
}

llm_memory_worker_log('worker bootstrapping', array(
    'args_file' => $args_file,
    'raw_args_keys' => array_keys($args),
));

$project_root = realpath(__DIR__ . '/../../../../../');
if (!$project_root) {
    fwrite(STDERR, "LLM Memory Worker: Cannot determine project root\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $project_root;
$_SERVER['HTTP_HOST'] = $args['http_host'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'LLM-Memory-Worker/1.0';

$_SESSION = [];
if (isset($args['user_id'])) {
    $_SESSION['id_user'] = $args['user_id'];
}

require_once $project_root . "/server/service/globals.php";
require_once $project_root . "/server/service/PageDb.php";
require_once $project_root . "/server/service/jobs/Mailer.php";
require_once $project_root . "/server/service/Transaction.php";
require_once $project_root . "/server/service/Router.php";
require_once $project_root . "/server/service/UserInput.php";
require_once $project_root . "/server/service/conditions/Condition.php";
require_once $project_root . "/server/service/JobScheduler.php";
require_once $project_root . "/server/service/Services.php";

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

try {
    $services = new Services(false);
} catch (Exception $e) {
    llm_memory_worker_log('services initialization failed', array('error' => $e->getMessage()));
    fwrite(STDERR, "LLM Memory Worker: Failed to initialize services: " . $e->getMessage() . "\n");
    exit(1);
}

llm_memory_worker_log('services initialized', array(
    'http_host' => $_SERVER['HTTP_HOST'] ?? '',
    'session_user' => $_SESSION['id_user'] ?? null,
));

$rule_id = (int)($args['rule_id'] ?? 0);

llm_memory_worker_debug(
    "started, rule_id=" . $rule_id
    . ", user=" . ($args['user_id'] ?? 'null')
);

try {
    require_once __DIR__ . "/LlmMemoryConfigService.php";
    require_once __DIR__ . "/LlmMemoryUpdateService.php";

    $config_service = new LlmMemoryConfigService($services);
    $update_service = new LlmMemoryUpdateService($services, $config_service);

    if ($rule_id <= 0) {
        llm_memory_worker_log('missing rule_id in args', $args);
        fwrite(STDERR, "LLM Memory Worker: No rule_id in args\n");
        exit(1);
    }

    llm_memory_worker_log('resolving rule', array(
        'rule_id' => $rule_id,
        'user_id' => $args['user_id'] ?? null,
    ));

    $rule = $config_service->getRuleById($rule_id);
    if (!$rule) {
        llm_memory_worker_log('rule not found', array('rule_id' => $rule_id));
        $transaction = $services->get_transaction();
        $transaction->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_MEMORY,
            null, null, null, false,
            "LLM Memory Worker: Rule not found; id=" . $rule_id
        );
        fwrite(STDERR, "LLM Memory Worker: Rule not found: id=$rule_id\n");
        exit(1);
    }

    $normalized_payload = [
        'source_type'            => $args['source_type'] ?? '',
        'source_ref'             => $args['source_ref'] ?? '',
        'trigger_type'           => $args['trigger_type'] ?? '',
        'user_id'                => $args['user_id'],
        'event_at'               => $args['event_at'] ?? date('Y-m-d H:i:s'),
        'fields'                 => $args['fields'] ?? [],
        'readable_text'          => $args['readable_text'] ?? '',
        'memory_key_override'    => $args['memory_key_override'] ?? '',
        'force_storage_mode'     => $args['force_storage_mode'] ?? '',
        'user_language'          => $args['user_language'] ?? '',
        'user_language_locale'   => $args['user_language_locale'] ?? '',
    ];

    llm_memory_worker_log('normalized payload prepared', array(
        'rule_id' => (int)($rule['id'] ?? 0),
        'source_type' => (string)($normalized_payload['source_type'] ?? ''),
        'trigger_type' => (string)($normalized_payload['trigger_type'] ?? ''),
        'user_id' => (int)($normalized_payload['user_id'] ?? 0),
        'memory_key_override' => (string)($normalized_payload['memory_key_override'] ?? ''),
        'field_keys' => array_keys((array)($normalized_payload['fields'] ?? array())),
        'source_ref' => $normalized_payload['source_ref'] ?? '',
    ));

    llm_memory_worker_log('starting executeLlmSummarization', array(
        'rule_id' => (int)($rule['id'] ?? 0),
        'user_id' => (int)($normalized_payload['user_id'] ?? 0),
    ));

    $success = $update_service->executeLlmSummarization($rule, $normalized_payload);

    llm_memory_worker_log('executeLlmSummarization finished', array(
        'rule_id' => (int)($rule['id'] ?? 0),
        'user_id' => (int)($normalized_payload['user_id'] ?? 0),
        'success' => (bool)$success,
    ));

    llm_memory_worker_debug(
        "Rule #" . $rule_id . " "
        . ($success ? "completed successfully" : "failed")
        . " for user " . $args['user_id']
    );

    exit($success ? 0 : 1);

} catch (Exception $e) {
    llm_memory_worker_log('fatal exception', array(
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ));
    error_log("LLM Memory Worker: Fatal error: " . $e->getMessage());
    fwrite(STDERR, "LLM Memory Worker: " . $e->getMessage() . "\n");
    exit(1);
}
