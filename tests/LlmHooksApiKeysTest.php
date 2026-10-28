<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the CMS side of the API keys (LlmHooks, plugin v1.6.0):
 *
 *  - the CMS field editor of `llm_api_keys` is rendered from the browser list
 *    (no key, no ciphertext), also through the PHP template;
 *  - saving the CMS page merges that key-less list with the stored one before
 *    the core saves it: keys are kept, new keys encrypted, an invalid list
 *    stores nothing, other fields are untouched.
 *
 * No bootstrap, no database: the real hook class, the real core BaseHooks and
 * the real key services are copied into a throw-away tree that mirrors their
 * relative require_once targets, where small stand-ins replace BaseModel,
 * BaseStyleComponent and the other plugin services LlmHooks loads.
 *
 * Run: php tests/LlmHooksApiKeysTest.php
 */

$core_hooks = dirname(__DIR__, 3) . '/component/BaseHooks.php';
if (!is_file($core_hooks)) {
    echo "skipped: the SelfHelp core (BaseHooks.php) is not next to this plugin\n";
    exit(0);
}

foreach (array(
    'RELATION_PAGE_FIELD' => 'page_field',
    'RELATION_SECTION_FIELD' => 'section_field',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_hooks_api_keys_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_hooks_api_keys_test_' . getmypid();
$plugin = $root . '/server/plugins/sh-shp-llm';
$plugin_server = $plugin . '/server';
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
    if (is_file($log_file)) {
        unlink($log_file);
    }
});

// Real files, byte for byte
sandbox_write($root . '/server/component/BaseHooks.php', file_get_contents($core_hooks));
foreach (array(
    'component/LlmHooks.php',
    'component/tpl_api_keys_manager.php',
    'service/LlmCsrfService.php',
    'service/LlmSecretService.php',
    'service/LlmSettingsValidator.php',
    'service/LlmApiKeysConfigService.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

// Stand-ins
sandbox_write($root . '/server/component/BaseModel.php', <<<'PHP'
<?php
abstract class BaseModel
{
    protected $services;
    protected $db;
    protected $transaction;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }
}
PHP
);

sandbox_write($root . '/server/component/style/BaseStyleComponent.php', <<<'PHP'
<?php
class BaseStyleComponent
{
    public static $created = array();

    public function __construct($name, $params = array())
    {
        self::$created[] = array($name, $params);
    }
}
PHP
);

foreach (array(
    'LlmService', 'LlmScriptService', 'LlmPromptRegistryService', 'LlmPromptExecutionProfileService',
    'LlmMemoryConfigService', 'LlmMemoryTriggerService', 'LlmMemoryRuleService', 'LlmMemorySubmissionContextService',
) as $service) {
    sandbox_write($plugin_server . '/service/' . $service . '.php', "<?php\n");
}

require_once $plugin_server . '/component/LlmHooks.php';

/* =========================================================================
 * Stand-ins for what the hooks talk to
 * ========================================================================= */

class FakeHookDb
{
    /** @var string stored content of the llm_api_keys page field */
    public $stored = '';
    public $fieldNames = array(101 => 'llm_api_keys', 102 => 'llm_temperature', 103 => 'section_field');
    public $queries = array();

    public function query_db_first($sql, $params = array())
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'SELECT name FROM fields WHERE id') === 0) {
            return isset($this->fieldNames[$params[':id']]) ? array('name' => $this->fieldNames[$params[':id']]) : false;
        }
        if (strpos($sql, 'SELECT content FROM pages_fields_translation') === 0) {
            return $params[':field'] === 101 && $params[':page'] === 55 && $params[':lang'] === 1
                ? array('content' => $this->stored) : false;
        }
        if (strpos($sql, 'SELECT f.name, ft.name AS type') === 0) {
            $id = $params[':id'];
            return isset($this->fieldNames[$id])
                ? array('name' => $this->fieldNames[$id], 'type' => $id === 101 ? 'json-llm-api-keys' : 'text') : false;
        }
        return false;
    }
}

class FakeHookServices
{
    public $db;

    public function __construct()
    {
        $this->db = new FakeHookDb();
    }

    public function get_db()
    {
        return $this->db;
    }
}

/** The hooked CmsModel: records what the core method finally received. */
class FakeCmsModel
{
    public $calls = array();

    public function get_active_page_id()
    {
        return 55;
    }

    public function update_db($id, $id_language, $id_gender, $content, $relation, $meta = null)
    {
        $this->calls[] = array('id' => $id, 'content' => $content, 'relation' => $relation);
        return true;
    }
}

class FakeFieldView
{
    public $children = array();

    public function set_children($children)
    {
        $this->children = $children;
    }
}

class FakeFieldComponent
{
    public $view;

    public function __construct()
    {
        $this->view = new FakeFieldView();
    }

    public function get_view()
    {
        return $this->view;
    }
}

/** The hooked CmsView. */
class FakeCmsView
{
    public $component;

    public function create_field_form_item($field)
    {
        $this->component = new FakeFieldComponent();
        return $this->component;
    }
}

/* =========================================================================
 * Helpers
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

function saveArgs($cms, $field_id, $relation, $content)
{
    return array(
        'id' => $field_id,
        'id_language' => 1,
        'id_gender' => 1,
        'content' => $content,
        'relation' => $relation,
        'meta' => null,
        'hookedClassInstance' => $cms,
        'methodName' => 'update_db',
    );
}

putenv('LLM_SECRET_KEY=0123456789abcdef0123456789abcdef');
$KEY = 'sk-test-0123456789abcdef';

$errors = array();
$stored = LlmApiKeysConfigService::prepareForSave(json_encode(array(
    array('name' => 'Main', 'base_url' => 'https://main.example.com/v1', 'api_key' => $KEY),
    array('name' => 'Local', 'base_url' => 'http://localhost:11434/v1'),
)), '', $errors);
$storedEntries = json_decode($stored, true);

$services = new FakeHookServices();
$services->db->stored = $stored;
$hooks = new LlmHooks($services);

/* =========================================================================
 * Rendering the CMS field
 * ========================================================================= */
$field = array(
    'name' => 'llm_api_keys', 'content' => $stored, 'id_language' => 1, 'id_gender' => 1,
    'id' => 101, 'type' => 'json-llm-api-keys', 'relation' => 'page_field',
);
$view = new FakeCmsView();
$component = $hooks->outputFieldLlmApiKeysEdit(array('field' => $field, 'hookedClassInstance' => $view, 'methodName' => 'create_field_form_item'));
$template = end(BaseStyleComponent::$created);
check('the field is rendered through the template component', $template[0] === 'template' && $component === $view->component);
$jsonValue = $template[1]['items']['jsonValue'];
check('the editor JSON has no key and no ciphertext', strpos($jsonValue, $KEY) === false && strpos($jsonValue, 'enc:v1:') === false && strpos($jsonValue, 'api_key') === false);
$editorList = json_decode($jsonValue, true);
check('the editor JSON carries ids and key info', count($editorList) === 2 && $editorList[0]['id'] === $storedEntries[0]['id']
    && $editorList[0]['has_key'] === true && $editorList[0]['key_hint'] === "\u{2026}cdef" && $editorList[1]['has_key'] === false);

$fields = $template[1]['items'];
ob_start();
include $plugin_server . '/component/tpl_api_keys_manager.php';
$html = ob_get_clean();
check('the PHP template shows the hint, never the key', strpos($html, 'Key: ' . "\u{2026}cdef") !== false && strpos($html, 'No key') !== false
    && strpos($html, $KEY) === false && strpos($html, 'enc:v1:') === false);
check('the template escapes the JSON and carries no api_key property', strpos($html, '&quot;base_url&quot;') !== false && strpos($html, '&quot;api_key&quot;') === false);

/* =========================================================================
 * Saving the CMS page
 * ========================================================================= */
// 1. the editor posts the list it was given (unchanged) -> keys are kept
$cms = new FakeCmsModel();
$res = $hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 101, RELATION_PAGE_FIELD, $jsonValue));
$saved = json_decode($cms->calls[0]['content'], true);
check('an unchanged save keeps the stored key (never overwritten with a blank)', $res === true && count($cms->calls) === 1
    && $saved[0]['api_key'] === $storedEntries[0]['api_key'] && $saved[0]['id'] === $storedEntries[0]['id']);
check('an unchanged save keeps the key-less server key-less', $saved[1]['api_key'] === '');

// 2. a newly typed key is encrypted before it reaches the core save
$list = $editorList;
$list[] = array('name' => 'Extra', 'base_url' => 'https://extra.example.com/v1', 'api_key' => 'sk-extra-0123456789');
$cms = new FakeCmsModel();
$hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 101, RELATION_PAGE_FIELD, json_encode($list)));
$saved = json_decode($cms->calls[0]['content'], true);
check('a typed key reaches the core save encrypted', strpos($saved[2]['api_key'], 'enc:v1:') === 0
    && strpos($cms->calls[0]['content'], 'sk-extra-0123456789') === false
    && LlmSecretService::resolve($saved[2]['api_key']) === 'sk-extra-0123456789');

// 3. an invalid list stores nothing
$list = $editorList;
$list[0]['base_url'] = 'http://insecure.example.com/v1';
$cms = new FakeCmsModel();
$res = $hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 101, RELATION_PAGE_FIELD, json_encode($list)));
check('an invalid list is refused (false) and the core save never runs', $res === false && count($cms->calls) === 0);
$list = $editorList;
$list[0]['base_url'] = 'https://evil.example.net/v1';
$cms = new FakeCmsModel();
$res = $hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 101, RELATION_PAGE_FIELD, json_encode($list)));
check('redirecting a stored key to another host is refused', $res === false && count($cms->calls) === 0);
clearstatcache();
$log = is_file($log_file) ? file_get_contents($log_file) : '';
check('the refusal is logged without any key', strpos($log, 'The API keys were not saved') !== false && strpos($log, $KEY) === false);

// 4. other fields / relations are untouched
$services->db->queries = array();
$cms = new FakeCmsModel();
$res = $hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 102, RELATION_PAGE_FIELD, '0.7'));
check('another page field passes through unchanged', $res === true && $cms->calls[0]['content'] === '0.7');
$cms = new FakeCmsModel();
$services->db->queries = array();
$res = $hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 101, RELATION_SECTION_FIELD, 'not a key list'));
check('a section field with the same id is not treated as the key list', $res === true && $cms->calls[0]['content'] === 'not a key list');
check('section fields cost no extra query before the save', count($services->db->queries) <= 1);

// 5. stale form: the stored list changed since the editor was rendered
$services->db->stored = '[]';
$cms = new FakeCmsModel();
$res = $hooks->syncPromptVersionOnCmsSave(saveArgs($cms, 101, RELATION_PAGE_FIELD, $jsonValue));
check('a stale editor cannot blank keys that no longer exist (refused)', $res === false && count($cms->calls) === 0);

echo "\n$total checks, $failed failed\n";
putenv('LLM_SECRET_KEY');
exit($failed === 0 ? 0 : 1);
