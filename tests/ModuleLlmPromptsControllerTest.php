<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the controller of the Prompts hub (plugin v1.10.0).
 *
 * Covers the read actions (`list`, `get`, `get_version`), the write actions
 * (`save`, `restore`) and the dispatch. What is checked here is what the
 * controller itself owns:
 *
 *  - every action is gated by the ACL of the HUB page (select for reads,
 *    update for writes), needs a session user and answers 403 'Access
 *    denied' before any service is even created; the page is the hub's own
 *    page keyword;
 *  - a write is POST only (405 otherwise) and carries the session CSRF token
 *    (POST field or X-CSRF-Token header), else 403;
 *  - an unknown action is answered with 400 JSON, a request without an action
 *    sends nothing (that is the normal page view);
 *  - request parameters are plain strings only (array values are ignored,
 *    unknown parameters never reach the service), version ids must be
 *    digits;
 *  - InvalidArgumentException from a service is a 400 with its message
 *    (malformed key), "not found" is a 404, a write conflict is a 409 with
 *    what is live now, a refused write is a 403 with its reason, every other
 *    failure is a 500 with the generic text while the details go to the log
 *    through LlmErrorReporter (no exception text, no driver text, no request
 *    data in the answer).
 *
 * No bootstrap, no database: the real controller, the CSRF service, the
 * error reporter, the hub exceptions and globals.php are copied into a
 * throw-away tree that mirrors the controller's relative require_once
 * targets; small stand-ins replace the core BaseController, the JSON trait
 * (the real one exits), the model, the catalog service and the save service
 * (covered by LlmPromptCatalogServiceTest and LlmPromptHubSaveServiceTest).
 *
 * Run: php tests/ModuleLlmPromptsControllerTest.php
 */

set_exception_handler(function ($e) {
    fwrite(STDOUT, 'FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
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

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_module_prompts_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_module_prompts_test_' . getmypid();
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
    @unlink($log_file);
});

// Real files, byte for byte
foreach (array(
    'component/moduleLlmPrompts/ModuleLlmPromptsController.php',
    'service/LlmCsrfService.php',
    'service/LlmErrorReporter.php',
    'service/globals.php',
    'service/LlmModelCapabilities.php',
    'service/exception/LlmPromptHubExceptions.php',
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
// An Error, not an Exception: the controller's `catch (Exception $e)` blocks
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

sandbox_write($plugin_server . '/component/moduleLlmPrompts/ModuleLlmPromptsModel.php', <<<'PHP'
<?php
class ModuleLlmPromptsModel
{
}
PHP
);

// The catalog: records every call, answers what the test configured (or throws it)
sandbox_write($plugin_server . '/service/LlmPromptCatalogService.php', <<<'PHP'
<?php
class LlmPromptCatalogService
{
    public function __construct($services)
    {
        $GLOBALS['T']['constructed']++;
    }

    public function listPrompts($filters, $user_id)
    {
        return $this->answer('listPrompts', array($filters, $user_id));
    }

    public function getPrompt($key, $user_id)
    {
        return $this->answer('getPrompt', array($key, $user_id));
    }

    public function getVersion($key, $version_id, $user_id)
    {
        return $this->answer('getVersion', array($key, $version_id, $user_id));
    }

    private function answer($method, $args)
    {
        $GLOBALS['T']['calls'][] = array($method, $args);
        $result = isset($GLOBALS['T']['answers'][$method]) ? $GLOBALS['T']['answers'][$method] : null;
        if ($result instanceof Throwable) {
            throw $result;
        }
        return $result;
    }
}
PHP
);

// The save service: records every call, answers what the test configured (or throws it).
// It loads the real exception classes, like the real service file does.
sandbox_write($plugin_server . '/service/LlmPromptHubSaveService.php', <<<'PHP'
<?php
require_once __DIR__ . '/exception/LlmPromptHubExceptions.php';

class LlmPromptHubSaveService
{
    public function __construct($services)
    {
        $GLOBALS['T']['constructed_save']++;
    }

    public function save($key, $content, $change_note, $expected_active_version_id, $user_id, $expected_content_hash = null)
    {
        return $this->answer('save', func_get_args());
    }

    public function restore($key, $version_id, $expected_active_version_id, $user_id, $expected_content_hash = null)
    {
        return $this->answer('restore', func_get_args());
    }

    public function saveAll(array $items, $user_id)
    {
        return $this->answer('save_all', func_get_args());
    }

    private function answer($method, $args)
    {
        $GLOBALS['T']['calls'][] = array($method, $args);
        $result = isset($GLOBALS['T']['answers'][$method]) ? $GLOBALS['T']['answers'][$method] : null;
        if ($result instanceof Throwable) {
            throw $result;
        }
        return $result;
    }
}
PHP
);

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class FakeAcl
{
    public $allowed = array('select' => true, 'insert' => true, 'update' => true, 'delete' => true);
    public $asked = array();

    public function has_access_select($user, $page) { return $this->ask('select', $user, $page); }
    public function has_access_insert($user, $page) { return $this->ask('insert', $user, $page); }
    public function has_access_update($user, $page) { return $this->ask('update', $user, $page); }
    public function has_access_delete($user, $page) { return $this->ask('delete', $user, $page); }

    private function ask($mode, $user, $page)
    {
        $this->asked[] = array($mode, $user, $page);
        return $this->allowed[$mode];
    }
}

class FakeDb
{
    public function fetch_page_id_by_keyword($keyword)
    {
        // Only the hub's own page exists: asking for any other keyword is a bug
        return ($keyword === 'moduleLlmPrompts' && $GLOBALS['T']['page_id'] !== null) ? $GLOBALS['T']['page_id'] : false;
    }
}

class FakeServices
{
    public $acl;
    public $db;

    public function __construct()
    {
        $this->acl = new FakeAcl();
        $this->db = new FakeDb();
    }

    public function get_acl() { return $this->acl; }
    public function get_db() { return $this->db; }
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
require_once $plugin_server . '/component/moduleLlmPrompts/ModuleLlmPromptsController.php';

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
 * Run the real controller for one request.
 *
 * @param string $method GET | POST
 * @param string|null $action null = no action parameter
 * @param array $params further request parameters (raw values, arrays allowed)
 * @param array $opts denied (ACL modes the user lacks), answers (service method => result|Throwable),
 *  session (replaces the session), page_id (null = the hub page does not exist),
 *  csrf (what the request sends as token: none | header | post | wrong | wrong_post)
 * @return array {sent, status, data, calls, constructed, constructed_save, asked, log}
 */
function run_hub($method, $action, array $params = array(), array $opts = array())
{
    global $log_file;
    file_put_contents($log_file, '');
    $GLOBALS['T'] = array(
        'calls' => array(),
        'constructed' => 0,
        'constructed_save' => 0,
        'answers' => isset($opts['answers']) ? $opts['answers'] : array(),
        'page_id' => array_key_exists('page_id', $opts) ? $opts['page_id'] : 17,
    );
    $csrf = isset($opts['csrf']) ? $opts['csrf'] : 'none';
    $fields = $params;
    if ($action !== null) {
        $fields['action'] = $action;
    }
    if ($csrf === 'post') {
        $fields['csrf_token'] = 'session-token';
    } elseif ($csrf === 'wrong_post') {
        $fields['csrf_token'] = 'not-the-token';
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $method === 'GET' ? $fields : array();
    $_POST = $method === 'POST' ? $fields : array();
    $_SESSION = array_key_exists('session', $opts) ? $opts['session'] : array('id_user' => 7, 'csrf_token' => 'session-token');
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
    if ($csrf === 'header') {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'session-token';
    } elseif ($csrf === 'wrong') {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'not-the-token';
    }

    $services = new FakeServices();
    foreach (isset($opts['denied']) ? $opts['denied'] : array() as $mode) {
        $services->acl->allowed[$mode] = false;
    }

    $sent = false;
    $status = null;
    $data = null;
    try {
        new ModuleLlmPromptsController(new FakeModel($services));
    } catch (TestJsonSent $e) {
        $sent = true;
        $status = $e->status;
        $data = $e->data;
    }

    return $GLOBALS['last_run'] = array(
        'sent' => $sent,
        'status' => $status,
        'data' => $data,
        'calls' => $GLOBALS['T']['calls'],
        'constructed' => $GLOBALS['T']['constructed'],
        'constructed_save' => $GLOBALS['T']['constructed_save'],
        'asked' => $services->acl->asked,
        'log' => (string)file_get_contents($log_file),
    );
}

$GENERIC = LlmErrorReporter::GENERIC_MESSAGE;
$reads = array(
    'list' => array(),
    'get' => array('key' => 'llm_script:5:script:1'),
    'get_version' => array('key' => 'llm_script:5:script:1', 'version_id' => '90'),
);

/* =========================================================================
 * Dispatch
 * ========================================================================= */

$r = run_hub('GET', 'nope');
check('an unknown action is a 400 with an error', $r['status'] === 400 && $r['data'] === array('error' => 'Unknown action'));
check('an unknown action never reaches the service', $r['constructed'] === 0 && $r['calls'] === array());

$r = run_hub('GET', null);
check('a request without an action sends nothing (it is the page view)', $r['sent'] === false && $r['constructed'] === 0);

foreach (array('delete', 'purge', 'clear_cache', 'save_many', 'SAVE') as $unknown) {
    $r = run_hub('POST', $unknown, array('key' => 'llm_script:5:script:1'), array('csrf' => 'header'));
    check("an action that does not exist ($unknown) is unknown, also as POST with a valid token",
        $r['status'] === 400 && $r['data'] === array('error' => 'Unknown action') && $r['constructed'] === 0 && $r['constructed_save'] === 0 && $r['calls'] === array());
}

/* =========================================================================
 * ACL of the hub page
 * ========================================================================= */

foreach ($reads as $action => $params) {
    $r = run_hub('GET', $action, $params, array('denied' => array('select')));
    check("$action: no select on the hub page is a 403", $r['status'] === 403 && $r['data'] === array('error' => 'Access denied'));
    check("$action: the service is not even created without select", $r['constructed'] === 0 && $r['calls'] === array());

    $r = run_hub('GET', $action, $params, array('denied' => array('select'), 'answers' => array(
        'listPrompts' => array('rows' => array()), 'getPrompt' => array('key' => 'x'), 'getVersion' => array('template_raw' => 'x'),
    )));
    check("$action: the answer of a denied request carries no data", array_keys($r['data']) === array('error'));

    $r = run_hub('GET', $action, $params, array('session' => array()));
    check("$action: no session user is a 403", $r['status'] === 403 && $r['constructed'] === 0);

    $r = run_hub('GET', $action, $params, array('session' => array('id_user' => null)));
    check("$action: a null session user is a 403", $r['status'] === 403 && $r['constructed'] === 0);

    $r = run_hub('GET', $action, $params, array('page_id' => null));
    check("$action: a missing hub page is a 403", $r['status'] === 403 && $r['constructed'] === 0);

    $r = run_hub('GET', $action, $params, array('denied' => array('update', 'insert', 'delete'), 'answers' => array(
        'listPrompts' => array('rows' => array()), 'getPrompt' => array('key' => 'x'), 'getVersion' => array('template_raw' => 'x'),
    )));
    check("$action: select alone is enough to read", $r['status'] === 200);
    check("$action: the ACL was asked for select on the hub page for the session user", $r['asked'] === array(array('select', 7, 17)));
}

$r = run_hub('GET', 'list', array(), array('denied' => array('select'), 'answers' => array('listPrompts' => array('rows' => array()))));
check('a user with every other mode but no select is still refused', $r['status'] === 403);
$all_but_select = run_hub('GET', 'list', array(), array('denied' => array('select')));
check('the refusal does not depend on the other modes', $all_but_select['status'] === 403);

/* =========================================================================
 * list
 * ========================================================================= */

$list_answer = array('rows' => array(array('key' => 'k')), 'total' => 1, 'facets' => array());
$r = run_hub('GET', 'list', array(
    'q' => 'needle', 'sourceType' => 'system', 'plugin' => 'LLM', 'style' => 'llmChat', 'pageId' => '3',
    'languageId' => '2', 'includeOrphaned' => '1', 'limit' => '5', 'offset' => '10',
), array('answers' => array('listPrompts' => $list_answer)));
check('list answers the service result as JSON 200', $r['status'] === 200 && $r['data'] === $list_answer);
check('list passes the filters as plain strings and the session user', $r['calls'] === array(array('listPrompts', array(array(
    'q' => 'needle', 'sourceType' => 'system', 'plugin' => 'LLM', 'style' => 'llmChat', 'pageId' => '3',
    'languageId' => '2', 'includeOrphaned' => '1', 'limit' => '5', 'offset' => '10',
), 7))));

$r = run_hub('GET', 'list', array(), array('answers' => array('listPrompts' => $list_answer)));
check('list without parameters passes no filters', $r['calls'] === array(array('listPrompts', array(array(), 7))));

$r = run_hub('GET', 'list', array(
    'q' => array('needle'), 'sourceType' => array('a' => 'system'), 'limit' => array('5'), 'plugin' => 'LLM',
    'evil' => 'x', 'user_id' => '1', 'id_user' => '1', 'key' => 'k',
), array('answers' => array('listPrompts' => $list_answer)));
check('list ignores array values and unknown parameters', $r['calls'] === array(array('listPrompts', array(array('plugin' => 'LLM'), 7))));

$r = run_hub('GET', 'list', array('sourceType' => 'bogus'), array('answers' => array('listPrompts' => new InvalidArgumentException('Unknown source type'))));
check('list: a rejected filter is a 400 with the message', $r['status'] === 400 && $r['data'] === array('error' => 'Unknown source type'));

$r = run_hub('GET', 'list', array(), array('answers' => array('listPrompts' => new RuntimeException('SELECT secret FROM somewhere failed: participant said hello'))));
check('list: any other failure is a 500 with the generic text', $r['status'] === 500 && $r['data'] === array('error' => $GENERIC));
check('list: the response carries no exception text', strpos(json_encode($r['data']), 'secret') === false && strpos(json_encode($r['data']), 'participant') === false);
check('list: the failure is logged through the reporter with its context', strpos($r['log'], '[LLM] Listing prompts failed: RuntimeException:') !== false);

$r = run_hub('GET', 'list', array(), array('answers' => array('listPrompts' => new PDOException("SQLSTATE[22001]: Data too long for value 'my private answer'", 22001))));
check('list: a database failure answers the generic text', $r['status'] === 500 && $r['data'] === array('error' => $GENERIC));
check('list: a database failure logs the code, not the driver text (it can quote answers)',
    strpos($r['log'], 'Listing prompts failed') !== false && strpos($r['log'], 'private answer') === false && strpos($r['log'], 'database error') !== false);

/* =========================================================================
 * get
 * ========================================================================= */

$prompt = array('key' => 'llm_script:5:script:1', 'content' => 'Summarize.', 'versions' => array());
$r = run_hub('GET', 'get', array('key' => 'llm_script:5:script:1'), array('answers' => array('getPrompt' => $prompt)));
check('get answers the prompt as JSON 200', $r['status'] === 200 && $r['data'] === $prompt);
check('get passes the key and the session user', $r['calls'] === array(array('getPrompt', array('llm_script:5:script:1', 7))));

$r = run_hub('GET', 'get', array('key' => array('llm_script:5:script:1')), array('answers' => array('getPrompt' => new InvalidArgumentException('Malformed prompt key'))));
check('get: an array as key reads as an empty key', $r['calls'] === array(array('getPrompt', array('', 7))));
check('get: a malformed key is a 400 with the message', $r['status'] === 400 && $r['data'] === array('error' => 'Malformed prompt key'));

$r = run_hub('GET', 'get', array(), array('answers' => array('getPrompt' => new InvalidArgumentException('Malformed prompt key'))));
check('get: a missing key reads as an empty key', $r['calls'] === array(array('getPrompt', array('', 7))) && $r['status'] === 400);

$r = run_hub('GET', 'get', array('key' => 'llm_script:5:script:1'), array('answers' => array('getPrompt' => null)));
check('get: a prompt that does not exist (or the user may not see) is a 404', $r['status'] === 404 && $r['data'] === array('error' => 'Prompt not found'));

$r = run_hub('GET', 'get', array('key' => 'llm_script:5:script:1'), array('answers' => array('getPrompt' => new RuntimeException('disk full while reading content'))));
check('get: any other failure is a 500 with the generic text, logged with its context',
    $r['status'] === 500 && $r['data'] === array('error' => $GENERIC) && strpos($r['log'], '[LLM] Loading a prompt failed:') !== false
    && strpos(json_encode($r['data']), 'disk') === false);

/* =========================================================================
 * get_version
 * ========================================================================= */

$version = array('version_no' => 3, 'template_raw' => 'Old text', 'created_at' => '2026-11-01 10:00:00', 'change_note' => null);
$r = run_hub('GET', 'get_version', array('key' => 'llm_script:5:script:1', 'version_id' => '90'), array('answers' => array('getVersion' => $version)));
check('get_version answers the version as JSON 200', $r['status'] === 200 && $r['data'] === $version);
check('get_version passes key, version id as int and the session user', $r['calls'] === array(array('getVersion', array('llm_script:5:script:1', 90, 7))));

// Pairs, not array keys: PHP turns the key '-1' into an integer, and the test must send strings
foreach (array(
    array('', 'empty'), array('12abc', 'trailing letters'), array('-1', 'negative'), array('1.5', 'decimal'),
    array(' 12', 'leading space'), array('12 ', 'trailing space'), array('0x1A', 'hex'), array('1e3', 'exponent'),
    array("12\n", 'trailing newline'), array('+12', 'plus sign'),
) as $pair) {
    list($bad, $label) = $pair;
    check("test input '$label' is a string", is_string($bad));
    $r = run_hub('GET', 'get_version', array('key' => 'llm_script:5:script:1', 'version_id' => $bad), array('answers' => array('getVersion' => null)));
    check("get_version: a version id that is not digits ($label) reads as 0", $r['calls'] === array(array('getVersion', array('llm_script:5:script:1', 0, 7))) && $r['status'] === 404);
}
$r = run_hub('GET', 'get_version', array('key' => 'llm_script:5:script:1', 'version_id' => array('90')), array('answers' => array('getVersion' => null)));
check('get_version: an array as version id reads as 0', $r['calls'] === array(array('getVersion', array('llm_script:5:script:1', 0, 7))));
$r = run_hub('GET', 'get_version', array('key' => 'llm_script:5:script:1'), array('answers' => array('getVersion' => null)));
check('get_version: a missing version id reads as 0', $r['calls'] === array(array('getVersion', array('llm_script:5:script:1', 0, 7))));

$r = run_hub('GET', 'get_version', array('key' => 'llm_script:5:script:1', 'version_id' => '90'), array('answers' => array('getVersion' => null)));
check('get_version: a version that is not of this prompt is a 404', $r['status'] === 404 && $r['data'] === array('error' => 'Version not found'));

$r = run_hub('GET', 'get_version', array('key' => 'x', 'version_id' => '90'), array('answers' => array('getVersion' => new InvalidArgumentException('Malformed prompt key'))));
check('get_version: a malformed key is a 400 with the message', $r['status'] === 400 && $r['data'] === array('error' => 'Malformed prompt key'));

$r = run_hub('GET', 'get_version', array('key' => 'llm_script:5:script:1', 'version_id' => '90'), array('answers' => array('getVersion' => new RuntimeException('connection lost'))));
check('get_version: any other failure is a 500 with the generic text, logged with its context',
    $r['status'] === 500 && $r['data'] === array('error' => $GENERIC) && strpos($r['log'], '[LLM] Loading a prompt version failed:') !== false
    && strpos(json_encode($r['data']), 'connection') === false);

/* =========================================================================
 * Write actions: ACL (update) of the hub page, POST only, CSRF token
 * ========================================================================= */

$KEY = 'style_field:100:conversation_context:2';
$HASH = str_repeat('ab', 32);
$writes = array(
    'save' => array('key' => $KEY, 'content' => 'New text', 'expected_active_version_id' => '91'),
    'restore' => array('key' => $KEY, 'version_id' => '90', 'expected_active_version_id' => '91'),
    'save_all' => array('items' => json_encode(array(array('key' => $KEY, 'content' => 'New text', 'expected_active_version_id' => '91')))),
);
$write_answer = array('ok' => true, 'changed' => true, 'row' => array('key' => $KEY, 'activeVersionNo' => 8));

foreach ($writes as $action => $params) {
    $r = run_hub('GET', $action, $params, array('csrf' => 'header', 'answers' => array($action => $write_answer)));
    check("$action: a GET is a 405 and reaches no service", $r['status'] === 405 && $r['data'] === array('error' => 'Method not allowed') && $r['constructed_save'] === 0 && $r['calls'] === array());
    $r = run_hub('GET', $action, $params + array('csrf_token' => 'session-token'), array('answers' => array($action => $write_answer)));
    check("$action: a GET with the token in the URL is still a 405", $r['status'] === 405 && $r['constructed_save'] === 0);

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'denied' => array('update'), 'answers' => array($action => $write_answer)));
    check("$action: select without update on the hub page is a 403 (also with a valid token)",
        $r['status'] === 403 && $r['data'] === array('error' => 'Access denied') && $r['constructed_save'] === 0 && $r['calls'] === array());

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'session' => array('csrf_token' => 'session-token'), 'answers' => array($action => $write_answer)));
    check("$action: no session user is a 403 'Access denied'", $r['status'] === 403 && $r['data'] === array('error' => 'Access denied') && $r['constructed_save'] === 0);

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'page_id' => null, 'answers' => array($action => $write_answer)));
    check("$action: a missing hub page is a 403 'Access denied'", $r['status'] === 403 && $r['data'] === array('error' => 'Access denied') && $r['constructed_save'] === 0);

    $r = run_hub('POST', $action, $params, array('csrf' => 'none', 'answers' => array($action => $write_answer)));
    check("$action: a POST without a CSRF token is a 403", $r['status'] === 403 && strpos($r['data']['error'], 'CSRF') !== false && $r['constructed_save'] === 0 && $r['calls'] === array());
    $r = run_hub('POST', $action, $params, array('csrf' => 'wrong', 'answers' => array($action => $write_answer)));
    check("$action: a wrong token in the header is a 403", $r['status'] === 403 && strpos($r['data']['error'], 'CSRF') !== false && $r['constructed_save'] === 0);
    $r = run_hub('POST', $action, $params, array('csrf' => 'wrong_post', 'answers' => array($action => $write_answer)));
    check("$action: a wrong token in the POST field is a 403", $r['status'] === 403 && $r['constructed_save'] === 0);
    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'session' => array('id_user' => 7), 'answers' => array($action => $write_answer)));
    check("$action: a session without a token never matches (not even an empty one)", $r['status'] === 403 && $r['constructed_save'] === 0);
    $r = run_hub('POST', $action, array('csrf_token' => '') + $params, array('csrf' => 'none', 'session' => array('id_user' => 7, 'csrf_token' => ''), 'answers' => array($action => $write_answer)));
    check("$action: an empty token against an empty session token is refused", $r['status'] === 403 && $r['constructed_save'] === 0);

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'denied' => array('select', 'insert', 'delete'), 'answers' => array($action => $write_answer)));
    check("$action: update alone on the hub page is enough, the token in the header works", $r['status'] === 200 && $r['data'] === $write_answer);
    check("$action: the ACL was asked for update (only) on the hub page for the session user", $r['asked'] === array(array('update', 7, 17)));
    $r = run_hub('POST', $action, $params, array('csrf' => 'post', 'answers' => array($action => $write_answer)));
    check("$action: the token in the POST field works too", $r['status'] === 200 && $r['data'] === $write_answer);
}

/* =========================================================================
 * save
 * ========================================================================= */

$full = array(
    'key' => $KEY, 'content' => "Line 1\nLine 2", 'change_note' => 'Friendlier', 'expected_active_version_id' => '91', 'expected_content_hash' => $HASH,
);
$r = run_hub('POST', 'save', $full + array('evil' => 'x', 'user_id' => '1', 'id_user' => '1', 'expected_user' => '1'), array('csrf' => 'header', 'answers' => array('save' => $write_answer)));
check('save answers the service result as JSON 200', $r['status'] === 200 && $r['data'] === $write_answer);
check('save passes the strings as sent, the version id as int and the session user (nothing else)',
    $r['calls'] === array(array('save', array($KEY, "Line 1\nLine 2", 'Friendlier', 91, 7, $HASH))));

$r = run_hub('POST', 'save', array('key' => $KEY, 'content' => 'x', 'expected_active_version_id' => '0'), array('csrf' => 'header', 'answers' => array('save' => $write_answer)));
check('save: optional fields that are missing reach the service as null, "0" is a version id (none yet)',
    $r['calls'] === array(array('save', array($KEY, 'x', null, 0, 7, null))));

$r = run_hub('POST', 'save', array('key' => $KEY, 'content' => '', 'change_note' => '', 'expected_active_version_id' => '91', 'expected_content_hash' => ''), array('csrf' => 'header', 'answers' => array('save' => $write_answer)));
check('save: an empty text stays an empty text, an empty note stays empty (the service decides)',
    $r['calls'] === array(array('save', array($KEY, '', '', 91, 7, ''))));

$r = run_hub('POST', 'save', array('key' => $KEY, 'expected_active_version_id' => '91'), array('csrf' => 'header', 'answers' => array('save' => new InvalidArgumentException('The prompt text is required'))));
check('save: a missing text reaches the service as null, which answers 400', $r['calls'] === array(array('save', array($KEY, null, null, 91, 7, null))) && $r['status'] === 400);

$r = run_hub('POST', 'save', array(
    'key' => array($KEY), 'content' => array('x'), 'change_note' => array('n'), 'expected_active_version_id' => array('91'), 'expected_content_hash' => array($HASH),
), array('csrf' => 'header', 'answers' => array('save' => new InvalidArgumentException('Malformed prompt key'))));
check('save: array values read as missing (key: empty, text/note/hash: null, version id: -1)',
    $r['calls'] === array(array('save', array('', null, null, -1, 7, null))) && $r['status'] === 400 && $r['data'] === array('error' => 'Malformed prompt key'));

foreach (array(
    array('', 'empty'), array('12abc', 'trailing letters'), array('-1', 'negative'), array('1.5', 'decimal'),
    array(' 91', 'leading space'), array('91 ', 'trailing space'), array('0x5B', 'hex'), array('9e1', 'exponent'),
    array("91\n", 'trailing newline'), array('+91', 'plus sign'),
) as $pair) {
    list($bad, $label) = $pair;
    check("test input '$label' is a string", is_string($bad));
    $r = run_hub('POST', 'save', array('key' => $KEY, 'content' => 'x', 'expected_active_version_id' => $bad), array('csrf' => 'header', 'answers' => array('save' => new InvalidArgumentException('expected_active_version_id is required'))));
    check("save: an expected version id that is not digits ($label) reads as -1", $r['calls'] === array(array('save', array($KEY, 'x', null, -1, 7, null))) && $r['status'] === 400);
}
$r = run_hub('POST', 'save', array('key' => $KEY, 'content' => 'x', 'expected_active_version_id' => '0091'), array('csrf' => 'header', 'answers' => array('save' => $write_answer)));
check('save: leading zeros are still digits', $r['calls'] === array(array('save', array($KEY, 'x', null, 91, 7, null))));

$big = str_repeat('x', 300000);
$r = run_hub('POST', 'save', array('key' => $KEY, 'content' => $big, 'expected_active_version_id' => '91'), array('csrf' => 'header', 'answers' => array('save' => $write_answer)));
check('save: a long text reaches the service unchanged (no cut in the controller)', $r['calls'][0][1][1] === $big);

// Status mapping
$current = array('version_id' => 92, 'version_no' => 8, 'content' => 'CMS text', 'content_hash' => $HASH, 'updated_at' => '2026-11-23 12:00:00', 'updated_by' => 'Jane Admin');
foreach (array('save', 'restore') as $action) {
    $params = $writes[$action];
    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'answers' => array($action => new LlmPromptHubConflictException('This prompt was changed by someone else', $current))));
    check("$action: a conflict is a 409 with what is live now",
        $r['status'] === 409 && $r['data'] === array('error' => 'conflict', 'message' => 'This prompt was changed by someone else', 'current' => $current));

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'answers' => array($action => new LlmPromptHubForbiddenException('You may not change this prompt', 'no_access'))));
    check("$action: a refused write is a 403 with the reason", $r['status'] === 403 && $r['data'] === array('error' => 'You may not change this prompt', 'reason' => 'no_access'));

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'answers' => array($action => new LlmPromptHubNotFoundException())));
    check("$action: a prompt that does not exist (or the user may not see) is a 404", $r['status'] === 404 && $r['data'] === array('error' => 'Prompt not found'));

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'answers' => array($action => new InvalidArgumentException('This prompt is read-only'))));
    check("$action: bad input is a 400 with the message", $r['status'] === 400 && $r['data'] === array('error' => 'This prompt is read-only'));

    $r = run_hub('POST', $action, $params + array('content' => 'SECRET-PROMPT-TEXT'), array('csrf' => 'header', 'answers' => array($action => new RuntimeException('registry down: connection reset'))));
    $label = $action === 'save' ? 'Saving a prompt' : 'Restoring a prompt version';
    check("$action: any other failure is a 500 with the generic text", $r['status'] === 500 && $r['data'] === array('error' => $GENERIC));
    check("$action: the failure is logged through the reporter with its context, without the request data",
        strpos($r['log'], "[LLM] $label failed: RuntimeException: registry down: connection reset") !== false
        && strpos($r['log'], 'SECRET-PROMPT-TEXT') === false && strpos(json_encode($r['data']), 'registry') === false);

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'answers' => array($action => new PDOException("SQLSTATE[22001]: Data too long for value 'my private answer'", 22001))));
    check("$action: a database failure answers the generic text and logs the code, not the driver text",
        $r['status'] === 500 && $r['data'] === array('error' => $GENERIC) && strpos($r['log'], "$label failed") !== false
        && strpos($r['log'], 'private answer') === false && strpos($r['log'], 'database error') !== false);

    $r = run_hub('POST', $action, $params, array('csrf' => 'header', 'answers' => array($action => new TypeError('Argument #1 must be of type array, null given'))));
    check("$action: an Error (not an Exception) is a generic 500 too", $r['status'] === 500 && $r['data'] === array('error' => $GENERIC) && strpos(json_encode($r['data']), 'Argument') === false);
}

/* =========================================================================
 * restore
 * ========================================================================= */

$r = run_hub('POST', 'restore', array('key' => $KEY, 'version_id' => '90', 'expected_active_version_id' => '91', 'expected_content_hash' => $HASH) + array('content' => 'ignored', 'evil' => 'x'),
    array('csrf' => 'header', 'answers' => array('restore' => $write_answer)));
check('restore answers the service result as JSON 200', $r['status'] === 200 && $r['data'] === $write_answer);
check('restore passes the key, both ids as int, the session user and the hash (nothing else)',
    $r['calls'] === array(array('restore', array($KEY, 90, 91, 7, $HASH))));

$r = run_hub('POST', 'restore', array('key' => $KEY, 'version_id' => '90', 'expected_active_version_id' => '91'), array('csrf' => 'header', 'answers' => array('restore' => $write_answer)));
check('restore: a missing hash reaches the service as null', $r['calls'] === array(array('restore', array($KEY, 90, 91, 7, null))));

foreach (array('version_id', 'expected_active_version_id') as $field) {
    foreach (array(array('', 'empty'), array('12abc', 'letters'), array('-1', 'negative'), array(' 1', 'space')) as $pair) {
        list($bad, $label) = $pair;
        $params = array('key' => $KEY, 'version_id' => '90', 'expected_active_version_id' => '91');
        $params[$field] = $bad;
        $r = run_hub('POST', 'restore', $params, array('csrf' => 'header', 'answers' => array('restore' => new InvalidArgumentException('A version is required'))));
        check("restore: $field that is not digits ($label) reads as -1",
            $r['calls'] === array(array('restore', array($KEY, $field === 'version_id' ? -1 : 90, $field === 'version_id' ? 91 : -1, 7, null))) && $r['status'] === 400);
    }
}
$r = run_hub('POST', 'restore', array('key' => $KEY, 'version_id' => array('90'), 'expected_active_version_id' => array('91')), array('csrf' => 'header', 'answers' => array('restore' => new InvalidArgumentException('A version is required'))));
check('restore: array ids read as -1', $r['calls'] === array(array('restore', array($KEY, -1, -1, 7, null))));

/* =========================================================================
 * The read actions stay GET-and-select only
 * ========================================================================= */

$r = run_hub('POST', 'get', array('key' => $KEY), array('csrf' => 'header', 'answers' => array('getPrompt' => array('key' => 'x'))));
check('a read action sent as POST is not read from the POST body (the key is empty)', $r['calls'] === array(array('getPrompt', array('', 7))));
$r = run_hub('GET', 'list', array(), array('denied' => array('update', 'insert', 'delete'), 'answers' => array('listPrompts' => $list_answer)));
check('reading never needs update', $r['status'] === 200 && $r['asked'] === array(array('select', 7, 17)));

/* =========================================================================
 * save_all
 * ========================================================================= */

$KEY_EN = 'style_field:100:conversation_context:3';
$all_answer = array('ok' => true, 'changed' => true, 'rows' => array(array('key' => $KEY), array('key' => $KEY_EN)));
$items = array(
    array('key' => $KEY, 'content' => "Zeile 1\nZeile 2", 'change_note' => 'beide', 'expected_active_version_id' => '91', 'expected_content_hash' => $HASH, 'user_id' => '1'),
    array('key' => $KEY_EN, 'content' => 'Line', 'expected_active_version_id' => '0'),
);
$r = run_hub('POST', 'save_all', array('items' => json_encode($items)), array('csrf' => 'header', 'answers' => array('save_all' => $all_answer)));
check('save_all answers the service result as JSON 200', $r['status'] === 200 && $r['data'] === $all_answer);
check('save_all hands the service plain items (ids as int, unknown fields dropped) and the session user',
    $r['calls'] === array(array('save_all', array(array(
        array('key' => $KEY, 'content' => "Zeile 1\nZeile 2", 'change_note' => 'beide', 'expected_active_version_id' => 91, 'expected_content_hash' => $HASH),
        array('key' => $KEY_EN, 'content' => 'Line', 'expected_active_version_id' => 0),
    ), 7))));

$r = run_hub('POST', 'save_all', array('items' => json_encode(array(array('key' => $KEY, 'content' => array('x'), 'expected_active_version_id' => 91)))), array('csrf' => 'header', 'answers' => array('save_all' => $all_answer)));
check('save_all: a value that is not a string is left out, a numeric version id reads as -1',
    $r['calls'] === array(array('save_all', array(array(array('key' => $KEY, 'expected_active_version_id' => -1)), 7))));

foreach (array('missing' => null, 'not JSON' => '{nope', 'an object' => '{"a":1}', 'a list of strings' => '["x"]') as $label => $value) {
    $params = $value === null ? array() : array('items' => $value);
    $r = run_hub('POST', 'save_all', $params, array('csrf' => 'header', 'answers' => array('save_all' => $all_answer)));
    check("save_all: items $label is a 400 that reaches no service", $r['status'] === 400 && $r['calls'] === array());
}

$conflict = new LlmPromptHubConflictException('This prompt was changed by someone else', array('version_id' => 95, 'key' => $KEY_EN));
$r = run_hub('POST', 'save_all', array('items' => json_encode($items)), array('csrf' => 'header', 'answers' => array('save_all' => $conflict)));
check('save_all: a conflict is a 409 that names the language', $r['status'] === 409 && $r['data']['error'] === 'conflict' && $r['data']['current']['key'] === $KEY_EN);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
