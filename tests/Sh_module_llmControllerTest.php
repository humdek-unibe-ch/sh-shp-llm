<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the LLM settings console (Sh_module_llmController +
 * Sh_module_llmModel), plugin v1.6.0:
 *
 *  - get_config never returns a stored API key (has_key / last four only);
 *  - save_config / protect_keys are POST only, need the session CSRF token and
 *    the update ACL;
 *  - every posted setting is validated first; one invalid value rejects the
 *    whole request (422) and nothing is stored;
 *  - "keep existing" keys survive a save that only carries the browser list;
 *  - the audit entry names the changed settings, never values or keys.
 *
 * No bootstrap, no database: the real controller, model and services are
 * copied into a throw-away tree that mirrors their relative require_once
 * targets, where small stand-ins replace the core base classes, the JSON
 * trait (which exits), LlmService and the database.
 *
 * Run: php tests/Sh_module_llmControllerTest.php
 */

foreach (array(
    'PAGE_LLM_CONFIG' => 'sh_module_llm',
    'TRANSACTION_BY_LLM_PLUGIN' => 'by_llm_plugin',
    'transactionTypes_update' => 'update',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_settings_controller_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_settings_controller_test_' . getmypid();
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
    'component/sh_module_llm/Sh_module_llmController.php',
    'component/sh_module_llm/Sh_module_llmModel.php',
    'service/LlmCsrfService.php',
    'service/LlmErrorReporter.php',
    'service/LlmSecretService.php',
    'service/LlmSettingsValidator.php',
    'service/LlmApiKeysConfigService.php',
    'service/LlmModelCapabilities.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

// Core base classes
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

sandbox_write($root . '/server/component/BaseModel.php', <<<'PHP'
<?php
class BaseModel
{
    protected $services;
    protected $db;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }

    public function get_services()
    {
        return $this->services;
    }
}
PHP
);

// JSON trait: the real one exits, this one throws what it would have sent
sandbox_write($plugin_server . '/component/LlmJsonResponseTrait.php', <<<'PHP'
<?php
// An Error, not an Exception: the controller's own catch (Exception) blocks must not swallow it
// (the real trait exits).
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

sandbox_write($plugin_server . '/service/LlmService.php', <<<'PHP'
<?php
class LlmService
{
    public function __construct($services)
    {
    }

    public function clearAvailableModelsCache()
    {
        $GLOBALS['T']['cache_cleared']++;
    }

    public function getAvailableModels()
    {
        if (!empty($GLOBALS['T']['models_fail'])) {
            throw $GLOBALS['T']['models_fail'];
        }
        return array();
    }
}
PHP
);

/* =========================================================================
 * Stand-ins for the services container
 * ========================================================================= */

class FakeAcl
{
    public $deny = array();

    public function has_access_select($user, $page)
    {
        return !in_array('select', $this->deny, true);
    }

    public function has_access_update($user, $page)
    {
        return !in_array('update', $this->deny, true);
    }
}

class FakeTransaction
{
    public $calls = array();

    public function add_transaction()
    {
        $this->calls[] = func_get_args();
        return true;
    }
}

class FakeDb
{
    /** @var array page field name => content (what get_page_fields returns) */
    public $fields = array();
    public $writes = 0;
    /** @var string|null Every read whose SQL contains this fails ... */
    public $fail_on = null;
    /** @var Throwable|null ... with this */
    public $fail_with = null;

    private function maybeFail($sql)
    {
        if ($this->fail_with !== null && $this->fail_on !== null && strpos($sql, $this->fail_on) !== false) {
            throw $this->fail_with;
        }
    }
    private $ids = array(
        'llm_api_keys' => 101, 'llm_default_model' => 102, 'llm_temperature' => 103,
        'llm_max_tokens' => 104, 'llm_reasoning_effort' => 105, 'llm_timeout' => 106,
        'llm_memory_enabled' => 107, 'llm_memory_storage_mode' => 108,
    );

    public function fetch_page_id_by_keyword($keyword)
    {
        return 55;
    }

    public function query_db_first($sql, $params = array())
    {
        $this->maybeFail($sql);
        if (strpos($sql, 'CALL get_page_fields') === 0) {
            return $this->fields;
        }
        if (strpos($sql, 'SELECT id FROM fields WHERE name') === 0) {
            return isset($this->ids[$params[0]]) ? array('id' => $this->ids[$params[0]]) : false;
        }
        if (strpos($sql, 'SELECT id_pages FROM pages_fields_translation') === 0) {
            $name = array_search($params[1], $this->ids);
            return array_key_exists($name, $this->fields) ? array('id_pages' => 55) : false;
        }
        if (strpos($sql, 'SELECT id_pages FROM pages_fields ') === 0) {
            return array('id_pages' => 55);
        }
        return false;
    }

    public function query_db($sql, $params = array())
    {
        return array();
    }

    public function execute_update_db($sql, $params = array())
    {
        if (strpos($sql, 'UPDATE pages_fields_translation SET content') === 0) {
            $this->fields[array_search($params[2], $this->ids)] = $params[0];
            $this->writes++;
        } elseif (strpos($sql, 'INSERT INTO pages_fields_translation') === 0) {
            $this->fields[array_search($params[1], $this->ids)] = $params[3];
            $this->writes++;
        }
        return true;
    }
}

class FakeServices
{
    public $acl;
    public $db;
    public $transaction;

    public function __construct()
    {
        $this->acl = new FakeAcl();
        $this->db = new FakeDb();
        $this->transaction = new FakeTransaction();
    }

    public function get_acl()
    {
        return $this->acl;
    }

    public function get_db()
    {
        return $this->db;
    }

    public function get_transaction()
    {
        return $this->transaction;
    }
}

/** php://input for the controller (CLI has none). */
class MockPhpInput
{
    public static $content = '';
    /** Set by PHP on user stream wrappers. */
    public $context;
    private $pos = 0;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->pos = 0;
        return true;
    }

    public function stream_read($count)
    {
        $chunk = substr(self::$content, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof()
    {
        return $this->pos >= strlen(self::$content);
    }

    public function stream_stat()
    {
        return array();
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'MockPhpInput');

require_once $plugin_server . '/component/sh_module_llm/Sh_module_llmController.php';
require_once $plugin_server . '/component/sh_module_llm/Sh_module_llmModel.php';

/* =========================================================================
 * Test helpers
 * ========================================================================= */

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

function masterOn()
{
    putenv('LLM_SECRET_KEY=0123456789abcdef0123456789abcdef');
}

function masterOff()
{
    putenv('LLM_SECRET_KEY');
}

/**
 * Run one request through the real controller.
 *
 * @return array|null [status, data] of the JSON response
 */
function request(FakeServices $services, $action, $method, $body = null, $token = 'session-token')
{
    $_SESSION = array('id_user' => 7, 'csrf_token' => 'session-token');
    $_GET = array('action' => $action);
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    if ($token !== null) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    }
    MockPhpInput::$content = $body === null ? '' : json_encode($body);

    try {
        new Sh_module_llmController(new Sh_module_llmModel($services));
    } catch (TestJsonSent $sent) {
        return array($sent->status, $sent->data);
    }
    return null;
}

function newServices(array $fields)
{
    $GLOBALS['T'] = array('cache_cleared' => 0);
    $services = new FakeServices();
    $services->db->fields = $fields;
    return $services;
}

/* =========================================================================
 * Scenarios
 * ========================================================================= */
$KEY = 'sk-test-0123456789abcdef';
$LEGACY = 'sk-legacy-0123456789';

masterOn();
require_once $plugin_server . '/service/LlmApiKeysConfigService.php';
$errors = array();
$stored = LlmApiKeysConfigService::prepareForSave(json_encode(array(
    array('name' => 'Main', 'base_url' => 'https://main.example.com/v1', 'api_key' => $KEY),
)), '', $errors);
$storedWithLegacy = json_encode(array_merge(json_decode($stored, true), array(
    array('id' => 'aaaaaaaaaaaaaaaa', 'name' => 'Old', 'base_url' => 'https://old.example.com/v1', 'api_key' => $LEGACY),
)));
$baseFields = array(
    'llm_api_keys' => $stored,
    'llm_default_model' => 'gpt-oss-120b',
    'llm_temperature' => '1',
    'llm_max_tokens' => '2048',
    'llm_timeout' => '30',
);

// --- get_config -----------------------------------------------------------
$s = newServices(array_merge($baseFields, array('llm_api_keys' => $storedWithLegacy)));
list($status, $data) = request($s, 'get_config', 'GET');
$json = json_encode($data);
check('get_config answers 200', $status === 200);
check('get_config never contains a key', strpos($json, $KEY) === false && strpos($json, $LEGACY) === false);
check('get_config never contains the ciphertext', strpos($json, 'enc:v1:') === false);
$field = $data['settings']['api']['fields'][0];
$servers = json_decode($field['value'], true);
check('get_config lists the servers with has_key and last four', count($servers) === 2
    && $servers[0]['has_key'] === true && $servers[0]['key_hint'] === "\u{2026}cdef"
    && $servers[1]['key_source'] === 'plain' && $servers[1]['key_hint'] === "\u{2026}6789");
check('get_config reports the encryption status', $field['meta']['encryption_available'] === true && $field['meta']['plain_keys'] === 1);
check('get_config keeps ACL flags', $data['acl'] === array('select' => true, 'update' => true));
$s->acl->deny = array('select');
list($status) = request($s, 'get_config', 'GET');
check('get_config needs the select ACL', $status === 403);

// --- save_config: request checks ---------------------------------------------
$s = newServices($baseFields);
$body = array('fields' => array('llm_temperature' => '0.5'));
list($status) = request($s, 'save_config', 'GET', $body);
check('save_config refuses GET (405)', $status === 405 && $s->db->writes === 0);
list($status) = request($s, 'save_config', 'POST', $body, null);
check('save_config refuses a request without CSRF token (403)', $status === 403 && $s->db->writes === 0);
list($status) = request($s, 'save_config', 'POST', $body, 'wrong');
check('save_config refuses a wrong CSRF token (403)', $status === 403 && $s->db->writes === 0);
$s->acl->deny = array('update');
list($status) = request($s, 'save_config', 'POST', $body);
check('save_config needs the update ACL (403)', $status === 403 && $s->db->writes === 0);
$s->acl->deny = array();
list($status, $data) = request($s, 'save_config', 'POST', array());
check('save_config without fields answers 400', $status === 400);
list($status) = request($s, 'nonsense', 'POST', $body);
check('an unknown action answers 400', $status === 400);

// --- save_config: validation -----------------------------------------------------
$s = newServices($baseFields);
list($status, $data) = request($s, 'save_config', 'POST', array('fields' => array(
    'llm_temperature' => '5',
    'llm_max_tokens' => '0',
    'llm_timeout' => 'soon',
    'llm_default_model' => 'gpt-5',
)));
check('invalid numbers answer 422 with a message per field', $status === 422
    && isset($data['errors']['llm_temperature'], $data['errors']['llm_max_tokens'], $data['errors']['llm_timeout'])
    && !isset($data['errors']['llm_default_model']));
check('one invalid field stores nothing, not even the valid ones', $s->db->writes === 0
    && $s->db->fields['llm_default_model'] === 'gpt-oss-120b' && $s->db->fields['llm_temperature'] === '1');
check('a rejected save is not audited', count($s->transaction->calls) === 0);

list($status, $data) = request($s, 'save_config', 'POST', array('fields' => array(
    'llm_api_keys' => json_encode(array(array('name' => 'Plain', 'base_url' => 'http://insecure.example.com/v1', 'api_key' => 'sk-x-0123456789'))),
)));
check('an http base URL is rejected', $status === 422 && isset($data['errors']['llm_api_keys']) && $s->db->fields['llm_api_keys'] === $stored);

// --- save_config: success ------------------------------------------------------------
$s = newServices($baseFields);
$browserList = json_decode($data = json_encode(LlmApiKeysConfigService::toClient($stored)), true);
$browserList[0]['name'] = 'Main renamed';
$browserList[] = array('name' => 'Second', 'base_url' => 'https://second.example.com/v1', 'api_key' => 'sk-second-0123456789');
list($status, $data) = request($s, 'save_config', 'POST', array('fields' => array(
    'llm_api_keys' => json_encode($browserList),
    'llm_temperature' => '0.8',
    'llm_max_tokens' => '4096',
    'evil_field' => 'x',
)));
check('a valid save answers 200', $status === 200 && $data['success'] === true);
check('only whitelisted settings are saved', $data['saved'] === array('llm_api_keys', 'llm_temperature', 'llm_max_tokens') && !isset($s->db->fields['evil_field']));
check('validated values are stored normalized', $s->db->fields['llm_temperature'] === '0.8' && $s->db->fields['llm_max_tokens'] === '4096');
$savedServers = json_decode($s->db->fields['llm_api_keys'], true);
check('keep existing: the first key is untouched', count($savedServers) === 2
    && $savedServers[0]['api_key'] === json_decode($stored, true)[0]['api_key'] && $savedServers[0]['name'] === 'Main renamed');
check('the new key is stored encrypted', strpos($savedServers[1]['api_key'], 'enc:v1:') === 0
    && strpos($s->db->fields['llm_api_keys'], 'sk-second-0123456789') === false);
check('saving API keys clears the models cache', $GLOBALS['T']['cache_cleared'] === 1);
check('the save is audited once, naming settings only', count($s->transaction->calls) === 1
    && strpos(json_encode($s->transaction->calls), 'LLM settings updated: llm_api_keys, llm_temperature, llm_max_tokens') !== false
    && strpos(json_encode($s->transaction->calls), $KEY) === false
    && $s->transaction->calls[0][2] === 7 && $s->transaction->calls[0][0] === 'update');

// --- protect_keys ---------------------------------------------------------------------
$s = newServices(array_merge($baseFields, array('llm_api_keys' => $storedWithLegacy)));
list($status) = request($s, 'protect_keys', 'GET');
check('protect_keys refuses GET (405)', $status === 405);
list($status) = request($s, 'protect_keys', 'POST', array(), null);
check('protect_keys refuses a missing CSRF token (403)', $status === 403);
masterOff();
list($status, $data) = request($s, 'protect_keys', 'POST', array());
check('protect_keys explains a missing master secret (409)', $status === 409 && strpos($data['error'], 'LLM_SECRET_KEY') !== false
    && strpos($s->db->fields['llm_api_keys'], $LEGACY) !== false);
masterOn();
list($status, $data) = request($s, 'protect_keys', 'POST', array());
check('protect_keys encrypts the plain keys', $status === 200 && $data['converted'] === 1
    && strpos($s->db->fields['llm_api_keys'], $LEGACY) === false && LlmApiKeysConfigService::countPlainKeys($s->db->fields['llm_api_keys']) === 0);
check('protect_keys keeps every key usable', LlmSecretService::resolve(json_decode($s->db->fields['llm_api_keys'], true)[1]['api_key']) === $LEGACY
    && LlmSecretService::resolve(json_decode($s->db->fields['llm_api_keys'], true)[0]['api_key']) === $KEY);
check('protect_keys is audited without keys', count($s->transaction->calls) === 1 && strpos(json_encode($s->transaction->calls), $LEGACY) === false);
list($status, $data) = request($s, 'protect_keys', 'POST', array());
check('protect_keys again converts nothing', $status === 200 && $data['converted'] === 0 && count($s->transaction->calls) === 1);
$s->acl->deny = array('update');
list($status) = request($s, 'protect_keys', 'POST', array());
check('protect_keys needs the update ACL (403)', $status === 403);

/* =========================================================================
 * Failures: logged, never shown
 *
 * An unexpected failure (a database error can quote stored values, any other
 * exception can name paths) is answered with a generic message; the details
 * go to the server log only.
 * ========================================================================= */

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_settings_controller_test.log';
$secret = 'SECRET-SETTING-9913';
$scenarios = array(
    'get_config' => array('GET', null, 'CALL get_page_fields', 'Settings load'),
    'save_config' => array('POST', array('fields' => array('llm_temperature' => '0.5')), 'SELECT id FROM fields WHERE name', 'Settings save'),
    'models' => array('GET', null, null, 'Settings model list'),
);
foreach ($scenarios as $action => $spec) {
    list($method, $body, $needle, $context) = $spec;
    $failures = array(
        'driver' => new PDOException("SQLSTATE[HY000]: General error: 1366 Incorrect value '$secret'"),
        'plain' => new RuntimeException("cannot read D:\\private\\keys.php ($secret)"),
    );
    foreach ($failures as $kind => $failure) {
        $s = newServices($baseFields);
        if ($needle !== null) {
            $s->db->fail_on = $needle;
            $s->db->fail_with = $failure;
        } else {
            $GLOBALS['T']['models_fail'] = $failure;
        }
        file_put_contents($log_file, '');
        list($status, $data) = request($s, $action, $method, $body);
        $logged = (string)file_get_contents($log_file);
        $json = json_encode($data);

        check("$action, $kind failure: 500 with the generic message", $status === 500 && $data['error'] === LlmErrorReporter::GENERIC_MESSAGE);
        check("$action, $kind failure: nothing of the exception reaches the client",
            strpos($json, $secret) === false && stripos($json, 'keys.php') === false && stripos($json, 'sqlstate') === false);
        check("$action, $kind failure: logged with its context",
            strpos($logged, "$context failed") !== false && strpos($logged, get_class($failure)) !== false);
        check("$action, $kind failure: " . ($kind === 'driver' ? 'the driver message is not logged (it can quote stored values)' : 'the message is logged'),
            $kind === 'driver' ? strpos($logged, $secret) === false : strpos($logged, $secret) !== false);
    }
}
$GLOBALS['T']['models_fail'] = null;

masterOff();
echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
