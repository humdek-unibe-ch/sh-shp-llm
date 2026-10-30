<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Shared bootstrap of the plugin's CLI workers (llm_async_worker.php,
 * llm_memory_worker.php).
 *
 * A worker runs in its own PHP process, outside any web request: there is no
 * router, login or session. llm_worker_boot_services() therefore boots the
 * SelfHelp core the way server/cronjobs/ScheduledJobsQueue.php does and
 * returns the Services container in cron mode (no login, no ACL).
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

/**
 * Read the job arguments the parent process wrote to a temp JSON file.
 *
 * The file is deleted right away (it can carry user text). Ends the worker
 * with exit status 1 and a message on STDERR when the file is missing or is
 * not a JSON object.
 *
 * @param array $argv Worker command line ($argv[1] = args file)
 * @param string $worker Script name for the usage line
 * @param string $label Prefix of the error messages, e.g. 'LLM Async Worker'
 * @return array The decoded job arguments
 */
function llm_worker_read_args(array $argv, $worker, $label)
{
    // The uopz extension blocks exit() by default; allow it for this CLI worker
    // (before the first exit() below, or a usage error would run on)
    if (function_exists('uopz_allow_exit')) {
        uopz_allow_exit(true);
    }
    ini_set('log_errors', '1');
    ini_set('display_errors', '0');

    if (!isset($argv[1]) || empty($argv[1])) {
        fwrite(STDERR, "Usage: php $worker <args_file>\n");
        exit(1);
    }

    $args_file = $argv[1];
    if (!file_exists($args_file)) {
        fwrite(STDERR, "$label: Args file not found: $args_file\n");
        exit(1);
    }

    $args = json_decode(file_get_contents($args_file), true);
    @unlink($args_file);
    if (!$args || !is_array($args)) {
        fwrite(STDERR, "$label: Failed to parse args file\n");
        exit(1);
    }

    return $args;
}

/**
 * Boot the SelfHelp core and create the Services container for a worker.
 *
 * @param string $user_agent Fake HTTP_USER_AGENT, names the worker in logs
 * @param string $http_host Host the job was queued for (links, mail sender)
 * @param int|string|null $id_user User the worker acts for (transaction log)
 * @return Services Services in cron mode (no login, no ACL)
 * @throws Exception When the project root is unknown or the services cannot be created
 */
function llm_worker_boot_services($user_agent, $http_host, $id_user)
{
    // this file:    server/plugins/sh-shp-llm/server/service/llm_worker_bootstrap.php
    // project root: ../../../../../
    $project_root = realpath(__DIR__ . '/../../../../../');
    if (!$project_root) {
        throw new Exception('Cannot determine project root');
    }

    // The minimal $_SERVER values the core globals expect
    $_SERVER['DOCUMENT_ROOT'] = $project_root;
    $_SERVER['HTTP_HOST'] = $http_host;
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_NAME'] = $http_host;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = $user_agent;

    // Session user for transaction logging
    $_SESSION = [];
    if ($id_user !== null) {
        $_SESSION['id_user'] = $id_user;
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

    // Plugin hook classes and globals (same loop as ScheduledJobsQueue.php)
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

    return new Services(false);
}
