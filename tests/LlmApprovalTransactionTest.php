<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the interview approval transaction (plugin v1.6.0):
 *
 *   "Memory processing before commit - memory can be updated even when
 *    form approval fails" and "avoid global cache clearing".
 *
 * The real LlmFormLogListController::handleApprove / after_successful_write
 * run against fakes for the database handle, the cache, the interview
 * service and the core write path. Memory updates are queued the way the
 * save hooks queue them (LlmTransaction::afterCommit, see
 * LlmMemoryTriggerServiceTest for the enforcement point itself), so each
 * scenario reports in which order the database and the memory update saw
 * the approval:
 *
 *  - success: the memory update runs after the commit, never before;
 *  - every failure path (prepare rejected, core write throws, validation
 *    fails, nothing answered, completeApproval fails or throws, the commit
 *    itself fails): the transaction is rolled back, the memory update never
 *    runs, and only the user-input cache type is cleared - never the whole
 *    cache (BaseDb::rollback()).
 *
 * The real sendJsonResponse() ends the request with exit, and the
 * controller wraps everything in catch (Throwable), so each scenario runs
 * in its own child process: this file re-runs itself with --scenario=NAME
 * and the parent compares the recorded event sequence.
 *
 * Run: php tests/LlmApprovalTransactionTest.php
 */

/* =========================================================================
 * Scenarios (shared by the parent and the children)
 * ========================================================================= */

function approval_scenarios()
{
    $success_events = array('begin', 'prepare', 'core_write', 'complete', 'commit', 'memory_update');
    $rolled_back_after_write = array('begin', 'prepare', 'core_write', 'rollback', 'clear_cache:USER_INPUT');
    $rolled_back_after_complete = array('begin', 'prepare', 'core_write', 'complete', 'rollback', 'clear_cache:USER_INPUT');
    return array(
        'success' => array(
            'in' => array('prep' => 'ok', 'execute' => 'ok', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => $success_events, 'status' => 200, 'success' => true,
        ),
        'success_memory_task_fails' => array(
            'in' => array('prep' => 'ok', 'execute' => 'ok', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'throws'),
            'events' => $success_events, 'status' => 200, 'success' => true,
            'log' => 'After-commit task failed: RuntimeException: memory boom',
        ),
        'already_completed' => array(
            'in' => array('prep' => 'already', 'execute' => 'ok', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => array('begin', 'prepare', 'commit'), 'status' => 200, 'success' => true,
            'idempotent' => true,
        ),
        'prepare_rejected' => array(
            'in' => array('prep' => 'not_ok', 'execute' => 'ok', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => array('begin', 'prepare', 'rollback'), 'status' => 409, 'success' => false,
            'error_type' => 'conflict',
        ),
        // Reaches the controller's outer catch (nothing inside the inner write try).
        'prepare_throws' => array(
            'in' => array('prep' => 'throws', 'execute' => 'ok', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => array('begin', 'prepare', 'rollback', 'clear_cache:USER_INPUT'),
            'status' => 500, 'success' => false,
            'error_type' => 'approval_failed', 'message' => 'The approval could not be completed',
        ),
        'core_write_throws' => array(
            'in' => array('prep' => 'ok', 'execute' => 'throw', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => $rolled_back_after_write, 'status' => 500, 'success' => false,
            'error_type' => 'approval_failed', 'message' => 'The approval write failed',
        ),
        'validation_fails' => array(
            'in' => array('prep' => 'ok', 'execute' => 'validation', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => $rolled_back_after_write, 'status' => 422, 'success' => false,
            'error_type' => 'validation',
        ),
        'nothing_answered' => array(
            'in' => array('prep' => 'ok', 'execute' => 'silent', 'complete' => 'ok', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => $rolled_back_after_write, 'status' => 500, 'success' => false,
            'error_type' => 'approval_failed', 'message' => 'The approval could not be completed',
        ),
        'complete_fails' => array(
            'in' => array('prep' => 'ok', 'execute' => 'ok', 'complete' => 'fail', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => $rolled_back_after_complete, 'status' => 500, 'success' => false,
            'error_type' => 'conflict',
        ),
        'complete_throws' => array(
            'in' => array('prep' => 'ok', 'execute' => 'ok', 'complete' => 'throw', 'commit' => 'ok', 'memory' => 'ok'),
            'events' => $rolled_back_after_complete, 'status' => 500, 'success' => false,
            'error_type' => 'approval_failed',
        ),
        'commit_fails' => array(
            'in' => array('prep' => 'ok', 'execute' => 'ok', 'complete' => 'ok', 'commit' => 'throws', 'memory' => 'ok'),
            'events' => array('begin', 'prepare', 'core_write', 'complete', 'commit_failed', 'rollback', 'clear_cache:USER_INPUT'),
            'status' => 500, 'success' => false, 'error_type' => 'approval_failed',
        ),
    );
}

/* =========================================================================
 * Child: one scenario against the real controller
 * ========================================================================= */

$child_scenario = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--scenario=') === 0) {
        $child_scenario = substr($arg, strlen('--scenario='));
    }
}

if ($child_scenario !== null) {
    define('LLM_PATH', dirname(__DIR__) . '/server');
    foreach (array(
        'ENTRY_RECORD_ID' => 'record_id',
        'SELECTED_RECORD_ID' => 'selected_record_id',
        'DELETE_RECORD_ID' => 'delete_record_id',
        'QUICK_ACTION_ID' => 'quick_action_id',
    ) as $const => $value) {
        define($const, $value);
    }

    $scenarios = approval_scenarios();
    $SCN = $scenarios[$child_scenario]['in'];
    $EVENTS = array();
    $child_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_approval_child_' . getmypid() . '.log';
    ini_set('log_errors', '1');
    ini_set('error_log', $child_log);

    function ev($name)
    {
        $GLOBALS['EVENTS'][] = $name;
    }

    // The core files end with stray newlines after their closing tags.
    ob_start();
    require_once LLM_PATH . '/component/style/llmFormLogList/LlmFormLogListController.php';
    ob_end_clean();

    /* The shutdown function runs after the real exit of sendJsonResponse(). */
    ob_start();
    register_shutdown_function(function () use ($child_log) {
        $body = ob_get_level() > 0 ? ob_get_clean() : '';
        $log = is_file($child_log) ? (string)file_get_contents($child_log) : '';
        @unlink($child_log);
        // The class keeps no accessor for what the tests look at: read the
        // open transaction frames it holds (the shutdown guard of the class
        // itself runs after this function).
        $frames = (new ReflectionProperty('LlmTransaction', 'frames'))->getValue();
        $pending = 0;
        foreach ($frames as $frame) {
            $pending += count($frame['tasks']);
        }
        $scope_closed = count($frames) === 0;
        echo "\n@@RESULT@@" . json_encode(array(
            'events' => $GLOBALS['EVENTS'],
            'status' => http_response_code(),
            'body' => $body,
            'pending' => $pending,
            'scope_closed' => $scope_closed,
            'log' => $log,
        ));
    });

    class ApprovalFakeDbh
    {
        public $depth = 0;
        public function inTransaction() { return $this->depth > 0; }
        public function rollBack() { ev('rollback'); $this->depth--; return true; }
    }

    class ApprovalFakeCache
    {
        const CACHE_TYPE_USER_INPUT = 'USER_INPUT';
        public function clear_cache($type = null, $id = null)
        {
            ev('clear_cache:' . ($type === null ? 'NULL' : $type));
            return true;
        }
    }

    class ApprovalFakeDb
    {
        public $dbh;
        public $cache;

        public function __construct()
        {
            $this->dbh = new ApprovalFakeDbh();
            $this->cache = new ApprovalFakeCache();
        }

        public function begin_transaction() { ev('begin'); $this->dbh->depth++; }

        public function commit()
        {
            if ($GLOBALS['SCN']['commit'] === 'throws') {
                ev('commit_failed');
                throw new PDOException('commit failed');
            }
            ev('commit');
            $this->dbh->depth--;
        }

        /** BaseDb::rollback(): PDO rollback + the typeless clear_cache() = global wipe. */
        public function rollback() { ev('WIPE'); $this->dbh->depth--; }

        public function get_dbh() { return $this->dbh; }
        public function get_cache() { return $this->cache; }
    }

    class ApprovalFakeRouter
    {
        public function log_user_activity() {}
    }

    class ApprovalFakeServices
    {
        public $db;
        public function __construct($db) { $this->db = $db; }
        public function get_db() { return $this->db; }
        public function get_router() { return new ApprovalFakeRouter(); }
    }

    class ApprovalFakeModel
    {
        private $services;
        public function __construct($services) { $this->services = $services; }
        public function get_services() { return $this->services; }
        public function get_section_id() { return 42; }
        public function get_db_field($key, $default = '') { return $default; }
        public function setInterviewApprovalMode($on) {}
        public function get_approved_record_id() { return 77; }
    }

    class ApprovalFakeInterviewService
    {
        public function prepareApproval($model, $record_id, $conversation_id, $revision, $token, $patch, $native_patch)
        {
            ev('prepare');
            $mode = $GLOBALS['SCN']['prep'];
            if ($mode === 'throws') {
                throw new RuntimeException('prepareApproval exploded');
            }
            if ($mode === 'not_ok') {
                return array('ok' => false, 'error' => array('type' => 'conflict', 'message' => 'Stale revision'));
            }
            if ($mode === 'already') {
                return array(
                    'ok' => true, 'already_completed' => true,
                    'receipt' => array('record_id' => 77), 'state' => array('phase' => 'approved'),
                );
            }
            return array(
                'ok' => true,
                'post' => array('__form_name' => 'interview_form'),
                'conversation' => array('id' => 9),
                'request_token' => $token,
            );
        }

        public function completeApproval($model, array $prep, $record_id_result)
        {
            ev('complete');
            $mode = $GLOBALS['SCN']['complete'];
            if ($mode === 'throw') {
                throw new RuntimeException('completeApproval exploded');
            }
            if ($mode === 'fail') {
                return array('ok' => false, 'error' => array('type' => 'conflict', 'message' => 'Concurrent modification'));
            }
            return array('ok' => true, 'approval' => array('record_id' => 77), 'state' => array('phase' => 'approved'));
        }
    }

    /**
     * The real controller without its constructor routing (ACL/CSRF are
     * covered elsewhere); execute() is the inherited core write path.
     */
    class ApprovalSpyController extends LlmFormLogListController
    {
        public function boot($model, $service)
        {
            $this->model = $model;
            $prop = new ReflectionProperty('LlmFormLogListController', 'interview_service');
            $prop->setAccessible(true);
            $prop->setValue($this, $service);
        }

        public function approve($model)
        {
            $method = new ReflectionMethod('LlmFormLogListController', 'handleApprove');
            $method->setAccessible(true);
            $method->invoke($this, $model, null);
        }

        public function execute()
        {
            ev('core_write');
            // What the save hooks do (LlmHooks::onFormSavedMemoryRules ->
            // LlmMemoryTriggerService::enqueueMemoryUpdate): the memory
            // update goes through LlmTransaction::afterCommit.
            LlmTransaction::afterCommit($this->model->get_services()->get_db(), function () {
                ev('memory_update');
                if ($GLOBALS['SCN']['memory'] === 'throws') {
                    throw new RuntimeException('memory boom');
                }
            });
            switch ($GLOBALS['SCN']['execute']) {
                case 'throw':
                    throw new RuntimeException('core write exploded');
                case 'validation':
                    $this->fail = true;
                    $this->success = false;
                    $this->error_msgs = array('title' => 'is required');
                    return;
                case 'silent':
                    // A write that reports success but never answers.
                    $this->fail = false;
                    $this->success = true;
                    return;
                default:
                    // Core: record_id > 0 -> success -> after_successful_write().
                    $this->fail = false;
                    $this->success = true;
                    $this->after_successful_write();
            }
        }
    }

    $_POST = array('conversation_id' => '9', 'revision' => '3', 'request_token' => 'tok-1');
    $_GET = array();
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $db = new ApprovalFakeDb();
    $model = new ApprovalFakeModel(new ApprovalFakeServices($db));
    $controller = (new ReflectionClass('ApprovalSpyController'))->newInstanceWithoutConstructor();
    $controller->boot($model, new ApprovalFakeInterviewService());
    $controller->approve($model);
    // Reached only when the controller failed to answer at all.
    ev('NO_RESPONSE');
    exit(0);
}

/* =========================================================================
 * Parent
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

function run_scenario($name)
{
    $cmd = '"' . PHP_BINARY . '" "' . __FILE__ . '" --scenario=' . $name . ' 2>&1';
    exec($cmd, $lines, $code);
    $text = implode("\n", $lines);
    $pos = strrpos($text, '@@RESULT@@');
    if ($pos === false) {
        return array('error' => "no result from child (exit $code): " . $text);
    }
    $result = json_decode(substr($text, $pos + strlen('@@RESULT@@')), true);
    if (!is_array($result)) {
        return array('error' => 'unreadable child result: ' . $text);
    }
    $result['json'] = json_decode((string)$result['body'], true);
    return $result;
}

foreach (approval_scenarios() as $name => $expect) {
    $r = run_scenario($name);
    if (isset($r['error'])) {
        check("$name: child ran ($r[error])", false);
        continue;
    }
    $got = implode(' > ', $r['events']);
    $want = implode(' > ', $expect['events']);
    check("$name: event order is [$want]" . ($got === $want ? '' : " (got [$got])"), $got === $want);
    check("$name: HTTP status " . $expect['status'], (int)$r['status'] === $expect['status']);
    check("$name: the response says " . ($expect['success'] ? 'success' : 'failure'),
        is_array($r['json']) && !empty($r['json']['success']) === $expect['success']);
    check("$name: the global cache wipe (BaseDb::rollback) is never used", !in_array('WIPE', $r['events'], true));
    check("$name: clear_cache() is never called without a type", !in_array('clear_cache:NULL', $r['events'], true));
    check("$name: no after-commit task is left queued", (int)$r['pending'] === 0);
    check("$name: no transaction frame is left open", !empty($r['scope_closed']));
    check("$name: the controller always answers", !in_array('NO_RESPONSE', $r['events'], true));

    $memory_ran = in_array('memory_update', $r['events'], true);
    if ($expect['success'] && !isset($expect['idempotent'])) {
        $commit_at = array_search('commit', $r['events'], true);
        $memory_at = array_search('memory_update', $r['events'], true);
        check("$name: the memory update ran, and only after the commit",
            $memory_ran && $commit_at !== false && $memory_at > $commit_at);
    } else {
        check("$name: the memory update never ran", !$memory_ran);
    }
    if (isset($expect['error_type'])) {
        check("$name: error type " . $expect['error_type'],
            isset($r['json']['error']['type']) && $r['json']['error']['type'] === $expect['error_type']);
    }
    if (isset($expect['message'])) {
        check("$name: error message '" . $expect['message'] . "'",
            isset($r['json']['error']['message']) && $r['json']['error']['message'] === $expect['message']);
    }
    if (isset($expect['idempotent'])) {
        check("$name: the replay is reported as idempotent", !empty($r['json']['idempotent']));
    }
    if (isset($expect['log'])) {
        check("$name: the failing after-commit task is logged", strpos($r['log'], $expect['log']) !== false);
    }
}

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
