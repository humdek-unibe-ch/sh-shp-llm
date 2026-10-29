<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the CSRF gate of the memory and the script module
 * controllers (plugin v1.6.0).
 *
 * Both modules change data (memory rules and entries, memory settings, LLM
 * scripts) and one of them calls the model (script test run), and both hand
 * the page a session token in their config. Every such action is therefore
 * answered
 *
 *  - 403 'Access denied' when the ACL mode is missing (checked first, so
 *    the answer does not depend on the token);
 *  - 405 when it is not a POST;
 *  - 403 'Invalid CSRF token' without the session token (POST field or the
 *    X-CSRF-Token header the JSON config save uses). The check is strict:
 *    browser headers such as Sec-Fetch-Site: same-origin do not replace it;
 *
 * while plain reads stay open to everyone with `select` access.
 *
 * No bootstrap, no database: the real controllers, the CSRF service and
 * globals.php are copied into a throw-away tree that mirrors the
 * controllers' relative require_once targets, where small stand-ins replace
 * the core BaseController, the JSON trait (which exits), the models and the
 * services.
 *
 * Run: php tests/ModuleLlmCsrfTest.php
 */

set_exception_handler(function ($e) {
    fwrite(STDOUT, 'FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});

foreach (array(
    'GUEST_USER_ID' => 1,
    'transactionTypes_insert' => 'insert',
    'transactionTypes_update' => 'update',
    'transactionTypes_delete' => 'delete',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_module_csrf_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_module_csrf_test_' . getmypid();
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

register_shutdown_function(function () use ($root) {
    sandbox_remove($root);
});

// Real files, byte for byte
foreach (array(
    'component/moduleLlmMemory/ModuleLlmMemoryController.php',
    'component/moduleLlmScript/ModuleLlmScriptController.php',
    'service/LlmCsrfService.php',
    'service/globals.php',
    'service/LlmModelCapabilities.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

sandbox_write($root . '/server/component/BaseController.php', <<<'PHP'
<?php
class BaseController
{
    protected $model;

    public function __construct($model)
    {
        $this->model = $model;
    }
}
PHP
);

// JSON trait: the real one exits, this one throws what it would have sent.
// An Error, not an Exception: the controllers' `catch (Exception $e)` blocks
// sit around calls that never return in production.
sandbox_write($plugin_server . '/component/LlmJsonResponseTrait.php', <<<'PHP'
<?php
class TestJsonSent extends Error
{
    public $data;
    public $status;

    public function __construct($data, $status)
    {
        parent::__construct('json sent');
        $this->data = $data;
        $this->status = $status;
    }
}

trait LlmJsonResponseTrait
{
    protected function sendJsonResponse($data, $status_code = 200)
    {
        throw new TestJsonSent($data, $status_code);
    }
}
PHP
);

// Models and services: whatever a handler calls is recorded and answered with an empty array
foreach (array(
    'component/moduleLlmMemory/ModuleLlmMemoryModel.php' => 'ModuleLlmMemoryModel',
    'component/sh_module_llm/Sh_module_llmModel.php' => 'Sh_module_llmModel',
    'service/LlmMemoryAdminService.php' => 'LlmMemoryAdminService',
    'service/LlmMemoryConfigService.php' => 'LlmMemoryConfigService',
    'service/LlmMemoryRuleService.php' => 'LlmMemoryRuleService',
    'service/LlmScriptService.php' => 'LlmScriptService',
    'service/LlmService.php' => 'LlmService',
) as $file => $class) {
    sandbox_write($plugin_server . '/' . $file, <<<PHP
<?php
class $class
{
    public function __construct()
    {
    }

    public function __call(\$name, \$args)
    {
        \$GLOBALS['T']['events'][] = '$class::' . \$name;
        return array();
    }

    public static function __callStatic(\$name, \$args)
    {
        \$GLOBALS['T']['events'][] = '$class::' . \$name;
        return array();
    }
}
PHP
    );
}

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class FakeAcl
{
    public $allowed = array('select' => true, 'insert' => true, 'update' => true, 'delete' => true);

    public function has_access_select($user, $page) { return $this->allowed['select']; }
    public function has_access_insert($user, $page) { return $this->allowed['insert']; }
    public function has_access_update($user, $page) { return $this->allowed['update']; }
    public function has_access_delete($user, $page) { return $this->allowed['delete']; }
}

class FakeDb
{
    public function fetch_page_id_by_keyword($keyword)
    {
        return 17;
    }

    public function query_db($sql, $params = array())
    {
        return array();
    }
}

class FakeServices
{
    public $acl;

    public function __construct()
    {
        $this->acl = new FakeAcl();
    }

    public function get_acl() { return $this->acl; }
    public function get_db() { return new FakeDb(); }
}

class FakeModel
{
    public $services;

    public function __construct($services)
    {
        $this->services = $services;
    }

    public function get_services() { return $this->services; }
}

require_once $plugin_server . '/service/globals.php';
require_once $plugin_server . '/component/moduleLlmMemory/ModuleLlmMemoryController.php';
require_once $plugin_server . '/component/moduleLlmScript/ModuleLlmScriptController.php';

/* =========================================================================
 * Harness
 * ========================================================================= */

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    if ($condition) {
        fwrite(STDOUT, "ok:   $label\n");
    } else {
        fwrite(STDOUT, "FAIL: $label\n");
        if (getenv('LLM_TEST_DEBUG')) {
            fwrite(STDOUT, '      last request: ' . json_encode($GLOBALS['last_run']) . "\n");
        }
        $failed++;
    }
}

/**
 * Run a real controller for one request.
 *
 * @param string $class ModuleLlmMemoryController | ModuleLlmScriptController
 * @param string $method GET | POST
 * @param string $action
 * @param array $opts denied (ACL modes the user lacks), token (POST field),
 *  header_token (X-CSRF-Token), session_token, site (Sec-Fetch-Site)
 * @return array {gate, status, data}: gate = csrf | method | acl | passed
 */
function run_module($class, $method, $action, array $opts = array())
{
    $GLOBALS['T'] = array('events' => array());
    $fields = array('action' => $action);
    if (isset($opts['token'])) {
        $fields['csrf_token'] = $opts['token'];
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $method === 'GET' ? $fields : array();
    $_POST = $method === 'POST' ? $fields : array();
    $_SESSION = array(
        'id_user' => 7,
        'csrf_token' => isset($opts['session_token']) ? $opts['session_token'] : 'session-token',
    );
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
    if (isset($opts['header_token'])) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $opts['header_token'];
    }
    if (isset($opts['site'])) {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $opts['site'];
        $_SERVER['HTTP_ORIGIN'] = 'https://site.example';
    }
    $services = new FakeServices();
    foreach (isset($opts['denied']) ? $opts['denied'] : array() as $mode) {
        $services->acl->allowed[$mode] = false;
    }
    try {
        new $class(new FakeModel($services));
    } catch (TestJsonSent $e) {
        $error = is_array($e->data) && isset($e->data['error']) ? (string)$e->data['error'] : '';
        if ($e->status === 405) {
            $gate = 'method';
        } elseif ($e->status === 403 && strpos($error, 'Invalid CSRF token') === 0) {
            $gate = 'csrf';
        } elseif ($e->status === 403 && strpos($error, 'Access denied') === 0) {
            $gate = 'acl';
        } else {
            $gate = 'passed';
        }
        return $GLOBALS['last_run'] = array('gate' => $gate, 'status' => $e->status, 'data' => $e->data, 'events' => $GLOBALS['T']['events']);
    } catch (Throwable $t) {
        // The handler itself failed on the empty stand-in answers: it was reached
        return $GLOBALS['last_run'] = array('gate' => 'passed', 'status' => null, 'data' => null, 'events' => $GLOBALS['T']['events'], 'crash' => get_class($t));
    }
    return $GLOBALS['last_run'] = array('gate' => 'passed', 'status' => null, 'data' => null, 'events' => $GLOBALS['T']['events']);
}

$modules = array(
    'memory' => array(
        'class' => 'ModuleLlmMemoryController',
        // action => ACL mode
        'mutations' => array(
            'rule_create' => 'insert',
            'rule_update' => 'update',
            'rule_delete' => 'delete',
            'rule_duplicate' => 'insert',
            'memory_config_save' => 'update',
            'memory_key_delete' => 'delete',
            'memory_rerun_rule' => 'update',
            'memory_rebuild' => 'update',
            'memory_edit' => 'update',
            'memory_delete' => 'delete',
        ),
        'reads' => array('overview', 'rules_list', 'rules_bootstrap', 'rule_get', 'sources', 'memory_config_get',
            'memory_keys', 'memory_activity', 'memory_users', 'memory_user_detail', 'memory_user_history'),
    ),
    'scripts' => array(
        'class' => 'ModuleLlmScriptController',
        'mutations' => array(
            'create' => 'insert',
            'update' => 'update',
            'delete' => 'delete',
            'test' => 'update',
        ),
        'reads' => array('list', 'get', 'config', 'models', 'sections'),
    ),
);

foreach ($modules as $module => $def) {
    $class = $def['class'];

    foreach ($def['mutations'] as $action => $mode) {
        $r = run_module($class, 'POST', $action);
        check("$module/$action: a POST without a token is refused (403 Invalid CSRF token)", $r['gate'] === 'csrf'
            && $r['status'] === 403 && $r['events'] === array());

        $r = run_module($class, 'POST', $action, array('token' => 'wrong'));
        check("$module/$action: a wrong token is refused", $r['gate'] === 'csrf');

        $r = run_module($class, 'POST', $action, array('token' => ''));
        check("$module/$action: an empty token is refused", $r['gate'] === 'csrf');

        $r = run_module($class, 'POST', $action, array('token' => 'session-token', 'session_token' => ''));
        check("$module/$action: a session without a token never matches", $r['gate'] === 'csrf');

        $r = run_module($class, 'POST', $action, array('site' => 'same-origin'));
        check("$module/$action: a same-origin browser request still needs the token (strict)", $r['gate'] === 'csrf');

        $r = run_module($class, 'POST', $action, array('header_token' => 'wrong'));
        check("$module/$action: a wrong token header is refused", $r['gate'] === 'csrf');

        $r = run_module($class, 'POST', $action, array('token' => 'session-token'));
        check("$module/$action: the session token in the POST field is accepted", $r['gate'] === 'passed');

        $r = run_module($class, 'POST', $action, array('header_token' => 'session-token'));
        check("$module/$action: the session token in the X-CSRF-Token header is accepted", $r['gate'] === 'passed');

        $r = run_module($class, 'GET', $action, array('header_token' => 'session-token', 'token' => 'session-token'));
        check("$module/$action: a GET is refused (405) even with the token", $r['gate'] === 'method' && $r['events'] === array());

        $r = run_module($class, 'POST', $action, array('denied' => array($mode), 'token' => 'session-token'));
        check("$module/$action: without the '$mode' ACL mode it is 403 Access denied", $r['gate'] === 'acl');

        $r = run_module($class, 'POST', $action, array('denied' => array($mode)));
        check("$module/$action: the ACL is checked before the token (no token, no '$mode' -> Access denied)", $r['gate'] === 'acl');

        $r = run_module($class, 'POST', $action, array('denied' => array_values(array_diff(array('select', 'insert', 'update', 'delete'), array($mode))), 'token' => 'session-token'));
        check("$module/$action: only the '$mode' mode counts", $r['gate'] === 'passed');
    }

    foreach ($def['reads'] as $action) {
        $r = run_module($class, 'GET', $action);
        check("$module/$action: a read needs no token", $r['gate'] === 'passed');
    }

    $r = run_module($class, 'POST', 'something_unknown', array('token' => 'session-token'));
    check("$module: an unknown action is a plain 400", $r['status'] === 400);
}

// The memory reads keep their own ACL
$r = run_module('ModuleLlmMemoryController', 'GET', 'rules_list', array('denied' => array('select')));
check('memory/rules_list: without select it is 403 Access denied', $r['gate'] === 'acl');
$r = run_module('ModuleLlmScriptController', 'GET', 'list', array('denied' => array('select')));
check('scripts/list: without select it is 403 Access denied', $r['gate'] === 'acl');

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
