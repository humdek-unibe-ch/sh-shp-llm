<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for the Prompt Lab AJAX endpoint (AjaxLlmPromptLab), plugin v1.6.0:
 * the server decides who may touch which prompt, dataset and run, not the browser.
 *
 *  - a section is authorized by the pages that really show it (nested sections and
 *    navigation children included); a page_id the browser sends that does not show the
 *    section is refused, however much access the user has on that page;
 *  - every version, dataset, run, run case and test case id in a request has to belong to
 *    the owner the request was authorized for (datasets without a scope are shared);
 *  - dataset listing is scoped by the descriptor, never by the request;
 *  - deleting a case needs the dataset it is deleted from;
 *  - imports need an existing owner;
 *  - database and runtime failures reach the browser as a generic message.
 *
 * No bootstrap and no web server: the real endpoint, access service and CSRF service
 * are copied into a throw-away tree that mirrors their relative require_once targets.
 * The other Llm services are recording stand-ins (a denied request must reach none of
 * them); the database is in-memory SQLite, so the access service's real SQL runs.
 *
 * Run: php tests/AjaxLlmPromptLabTest.php
 */

$real_server = str_replace('\\', '/', dirname(__DIR__)) . '/server';

// The owner types and page keywords are the plugin's own constants
$globals_source = file_get_contents($real_server . '/service/globals.php');
foreach (array(
    'LLM_PROMPT_OWNER_STYLE_FIELD',
    'LLM_PROMPT_OWNER_SCRIPT',
    'LLM_PROMPT_OWNER_MEMORY_RULE',
    'LLM_SCRIPTS_PAGE_KEYWORD',
    'LLM_MEMORY_PAGE_KEYWORD',
) as $name) {
    if (!preg_match("/define\\('" . $name . "',\\s*'([^']*)'\\)/", $globals_source, $m)) {
        fwrite(STDERR, "constant $name not found in globals.php\n");
        exit(1);
    }
    define($name, $m[1]);
}

$error_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_prompt_lab_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log);

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

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_prompt_lab_test_' . getmypid();
$plugin_server = $root . '/server/plugins/sh-shp-llm/server';

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

register_shutdown_function(function () use ($root, $error_log) {
    sandbox_remove($root);
    if (is_file($error_log)) {
        unlink($error_log);
    }
});

// Real files, byte for byte
foreach (array(
    'ajax/AjaxLlmPromptLab.php',
    'service/LlmPromptLabAccessService.php',
    'service/LlmCsrfService.php',
    'service/LlmErrorReporter.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

// Core base class: what BaseAjax hands to its children
sandbox_write($root . '/server/ajax/BaseAjax.php', <<<'PHP'
<?php
abstract class BaseAjax
{
    protected $db;
    protected $acl;

    public function __construct($services)
    {
        $this->db = $services->get_db();
        $this->acl = $services->get_acl();
    }
}
PHP
);

// Recording stand-ins for the services behind the endpoint. Every call is logged in
// ServiceCalls::$log, so a test can tell "refused up front" from "reached the service".
foreach (array(
    'LlmPromptRegistryService',
    'LlmPromptPlaygroundService',
    'LlmPromptBuilderService',
    'LlmPromptRuntimeValueService',
    'LlmDatasetService',
    'LlmEvaluationService',
) as $class) {
    sandbox_write($plugin_server . '/service/' . $class . '.php', "<?php\nclass $class extends StandInService {}\n");
}

class ServiceCalls
{
    /** @var array[] [Class::method, args] */
    public static $log = array();
    /** @var Throwable|null Thrown by the next service call */
    public static $throw = null;

    public static function names()
    {
        return array_map(function ($entry) {
            return $entry[0];
        }, self::$log);
    }
}

class StandInService
{
    protected $db;

    public function __construct($services)
    {
        $this->db = $services->get_db();
    }

    public function __call($method, $args)
    {
        ServiceCalls::$log[] = array(static::class . '::' . $method, $args);
        if (ServiceCalls::$throw !== null) {
            $e = ServiceCalls::$throw;
            ServiceCalls::$throw = null;
            throw $e;
        }
        switch ($method) {
            case 'getVersion':
                return array('id' => (int)$args[0], 'content' => 'prompt text of version ' . (int)$args[0]);
            case 'getDataset':
                return $this->db->query_db_first('SELECT * FROM llm_eval_datasets WHERE id = :id', array(':id' => (int)$args[0]));
            case 'listDatasets':
                // Same equality semantics as the real service
                $filters = $args[0];
                $where = array('1=1');
                $params = array();
                if (!empty($filters['owner_type_scope'])) {
                    $where[] = 'owner_type_scope = :t';
                    $params[':t'] = (string)$filters['owner_type_scope'];
                }
                if (!empty($filters['owner_id_scope'])) {
                    $where[] = 'owner_id_scope = :i';
                    $params[':i'] = (int)$filters['owner_id_scope'];
                }
                return $this->db->query_db('SELECT id, owner_type_scope, owner_id_scope FROM llm_eval_datasets WHERE ' . implode(' AND ', $where) . ' ORDER BY id', $params);
            case 'getEvalRun':
                return array('id' => (int)$args[0], 'status' => 'completed');
            case 'listDatasetCases':
            case 'listEvalRunCases':
            case 'listCaseEvaluationHistory':
            case 'getImportCandidates':
                return array();
            case 'deleteDatasetCase':
            case 'deleteEvalRun':
            case 'deleteEvalRunCase':
            case 'deleteDataset':
                return true;
            default:
                return array('ok' => true);
        }
    }
}

/* =========================================================================
 * Stand-ins for the services container, ACL and database
 * ========================================================================= */

class SqliteDb
{
    public $pdo;
    public $page_ids = array('moduleLlmScript' => 900, 'moduleLlmMemory' => 901);

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (array(
            'CREATE TABLE pages_sections (id_pages INTEGER, id_sections INTEGER)',
            'CREATE TABLE sections_navigation (parent INTEGER, child INTEGER, id_pages INTEGER)',
            'CREATE TABLE sections_hierarchy (parent INTEGER, child INTEGER)',
            'CREATE TABLE lookups (id INTEGER PRIMARY KEY, type_code TEXT, lookup_code TEXT)',
            'CREATE TABLE llm_prompt_entries (id INTEGER PRIMARY KEY, id_llm_prompt_owner_types INTEGER, owner_id INTEGER, prompt_slot TEXT)',
            'CREATE TABLE llm_prompt_locales (id INTEGER PRIMARY KEY, id_llm_prompt_entries INTEGER)',
            'CREATE TABLE llm_prompt_versions (id INTEGER PRIMARY KEY, id_llm_prompt_locales INTEGER)',
            'CREATE TABLE llm_eval_datasets (id INTEGER PRIMARY KEY, owner_type_scope TEXT, owner_id_scope INTEGER)',
            'CREATE TABLE llm_eval_runs (id INTEGER PRIMARY KEY, id_llm_eval_datasets INTEGER)',
            'CREATE TABLE llm_eval_run_cases (id INTEGER PRIMARY KEY, id_llm_eval_runs INTEGER, id_llm_eval_cases INTEGER)',
            'CREATE TABLE llm_eval_dataset_case_links (id INTEGER PRIMARY KEY, id_llm_eval_datasets INTEGER, id_llm_eval_cases INTEGER)',
        ) as $ddl) {
            $this->pdo->exec($ddl);
        }
    }

    public function query_db($sql, $params = array())
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function query_db_first($sql, $params = array())
    {
        $rows = $this->query_db($sql, $params);
        return (is_array($rows) && !empty($rows)) ? $rows[0] : false;
    }

    public function fetch_page_id_by_keyword($keyword)
    {
        return isset($this->page_ids[$keyword]) ? $this->page_ids[$keyword] : 0;
    }

    public function seed($table, array $rows)
    {
        foreach ($rows as $row) {
            $stmt = $this->pdo->prepare("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
            $stmt->execute(array_values($row));
        }
    }
}

class FakeAcl
{
    /** @var array [user id][page id] => modes held */
    public $grants = array();

    private function held($user, $page, $mode)
    {
        return isset($this->grants[(int)$user][(int)$page]) && in_array($mode, $this->grants[(int)$user][(int)$page], true);
    }

    public function has_access_select($id, $id_page, $is_group = false)
    {
        return $this->held($id, $id_page, 'select');
    }

    public function has_access_update($id, $id_page, $is_group = false)
    {
        return $this->held($id, $id_page, 'update');
    }

    public function has_access_insert($id, $id_page, $is_group = false)
    {
        return $this->held($id, $id_page, 'insert');
    }

    public function has_access_delete($id, $id_page, $is_group = false)
    {
        return $this->held($id, $id_page, 'delete');
    }
}

class FakeServices
{
    public $db;
    public $acl;

    public function __construct($db, $acl)
    {
        $this->db = $db;
        $this->acl = $acl;
    }

    public function get_db()
    {
        return $this->db;
    }

    public function get_acl()
    {
        return $this->acl;
    }
}

/* =========================================================================
 * Fixtures
 *
 * Page 10 (A): the editor (user 7) may read and write.   Section 100, below it 101 > 102,
 *                and 103 as a child of a navigation section.
 * Page 20 (B): the editor has no access.                 Section 200, below it 201.
 * Page 30 (C): the editor may only read.                 Section 300.
 * Section 400 is shown nowhere; section 500 is shown on A and B.
 * Page 900 / 901: the scripts and memory modules (user 8 administers both).
 * ========================================================================= */

$db = new SqliteDb();
$acl = new FakeAcl();
$acl->grants = array(
    7 => array(10 => array('select', 'update'), 30 => array('select')),
    8 => array(900 => array('select', 'update'), 901 => array('select', 'update')),
);

$db->seed('pages_sections', array(
    array('id_pages' => 10, 'id_sections' => 100),
    array('id_pages' => 20, 'id_sections' => 200),
    array('id_pages' => 30, 'id_sections' => 300),
    array('id_pages' => 10, 'id_sections' => 500),
    array('id_pages' => 20, 'id_sections' => 500),
    // 510: the lowest page id (5) is one the editor cannot read, page 10 is
    array('id_pages' => 5, 'id_sections' => 510),
    array('id_pages' => 10, 'id_sections' => 510),
));
$db->seed('sections_hierarchy', array(
    array('parent' => 100, 'child' => 101),
    array('parent' => 101, 'child' => 102),
    array('parent' => 200, 'child' => 201),
));
$db->seed('sections_navigation', array(array('parent' => 110, 'child' => 103, 'id_pages' => 10)));

$db->seed('lookups', array(
    array('id' => 1, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'style_field'),
    array('id' => 2, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_script'),
    array('id' => 3, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_memory_rule'),
));
// versions 1..6 belong to: section 100, section 200, script 5, memory rule 9, section 102,
// and script 100: a script and a section can share a number, only the type tells them apart.
// Entry, locale and version ids differ on purpose so a wrong join cannot look right.
foreach (array(array(1, 1, 100), array(2, 1, 200), array(3, 2, 5), array(4, 3, 9), array(5, 1, 102), array(6, 2, 100)) as $row) {
    list($n, $type, $owner) = $row;
    $db->seed('llm_prompt_entries', array(array('id' => 30 + $n, 'id_llm_prompt_owner_types' => $type, 'owner_id' => $owner, 'prompt_slot' => 'main')));
    $db->seed('llm_prompt_locales', array(array('id' => 40 + $n, 'id_llm_prompt_entries' => 30 + $n)));
    $db->seed('llm_prompt_versions', array(array('id' => $n, 'id_llm_prompt_locales' => 40 + $n)));
}

// datasets: 1 = section 100, 2 = section 200, 3 = shared, 4 = script 5, 5 = section 102,
// 6 = script 100 (same number as section 100), 7 = every section (type scope, no id)
$db->seed('llm_eval_datasets', array(
    array('id' => 1, 'owner_type_scope' => 'style_field', 'owner_id_scope' => 100),
    array('id' => 2, 'owner_type_scope' => 'style_field', 'owner_id_scope' => 200),
    array('id' => 3, 'owner_type_scope' => null, 'owner_id_scope' => null),
    array('id' => 4, 'owner_type_scope' => 'llm_script', 'owner_id_scope' => 5),
    array('id' => 5, 'owner_type_scope' => 'style_field', 'owner_id_scope' => 102),
    array('id' => 6, 'owner_type_scope' => 'llm_script', 'owner_id_scope' => 100),
    array('id' => 7, 'owner_type_scope' => 'style_field', 'owner_id_scope' => null),
));
// runs 11 / 12 and their cases 21 / 22 belong to datasets 1 / 2
$db->seed('llm_eval_runs', array(
    array('id' => 11, 'id_llm_eval_datasets' => 1),
    array('id' => 12, 'id_llm_eval_datasets' => 2),
));
$db->seed('llm_eval_run_cases', array(
    array('id' => 21, 'id_llm_eval_runs' => 11, 'id_llm_eval_cases' => 7),
    array('id' => 22, 'id_llm_eval_runs' => 12, 'id_llm_eval_cases' => 8),
));
// case 7: section 100's dataset and the shared one; case 8: section 200's; case 9: nobody's
$db->seed('llm_eval_dataset_case_links', array(
    array('id' => 1, 'id_llm_eval_datasets' => 1, 'id_llm_eval_cases' => 7),
    array('id' => 2, 'id_llm_eval_datasets' => 3, 'id_llm_eval_cases' => 7),
    array('id' => 3, 'id_llm_eval_datasets' => 2, 'id_llm_eval_cases' => 8),
));

// Load the endpoint from the sandbox: the stand-in BaseAjax and services are picked up through its require_once lines
require_once $plugin_server . '/ajax/AjaxLlmPromptLab.php';
$ajax = new AjaxLlmPromptLab(new FakeServices($db, $acl));

/**
 * One request as a logged-in user with a valid CSRF token.
 *
 * @return array The endpoint's response
 */
function call(AjaxLlmPromptLab $ajax, array $post, $user = 7)
{
    $_SESSION = array('id_user' => $user, 'csrf_token' => 'tok');
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    ServiceCalls::$log = array();
    ServiceCalls::$throw = null;
    $post += array('csrf_token' => 'tok');
    return $ajax->dispatch($post);
}

function section($id, $page = null)
{
    $descriptor = array('owner_type' => 'style_field', 'owner_id' => $id, 'prompt_slot' => 'main');
    if ($page !== null) {
        $descriptor['page_id'] = $page;
    }
    return $descriptor;
}

function error_of($result)
{
    return (is_array($result) && isset($result['error'])) ? $result['error'] : null;
}

function reached_service()
{
    return count(ServiceCalls::$log) > 0;
}

/* =========================================================================
 * Descriptor: which pages decide
 * ========================================================================= */

$result = call($ajax, array('action' => 'list_datasets') + section(100, 10));
check('the editor reads datasets of a section on their own page', error_of($result) === null && reached_service());

$result = call($ajax, array('action' => 'list_datasets') + section(200, 10));
check('SPOOF: a foreign section with the editor\'s own page_id is refused', error_of($result) === 'Access denied');
check('SPOOF: the refused request reached no service', !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => 2) + section(200, 10));
check('SPOOF: the foreign prompt cannot be read with the editor\'s own page_id', error_of($result) === 'Access denied' && !reached_service());

$result = call($ajax, array('action' => 'list_datasets', 'ownerId' => 200, 'ownerType' => 'style_field', 'pageId' => 10));
check('SPOOF: the camelCase spelling of the descriptor is checked the same way', error_of($result) === 'Access denied' && !reached_service());

$result = call($ajax, array('action' => 'list_datasets') + section(200));
check('a foreign section without any page_id is refused (its pages are looked up)', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets') + section(200, 20));
check('the foreign section\'s true page does not help without access there', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets') + section(102, 10));
check('a section nested below the editor\'s page is allowed', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets') + section(103));
check('a navigation child on the editor\'s page is allowed without a page_id', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets') + section(500));
check('a section shown on two pages is allowed through the page the editor can use', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets') + section(500, 20));
check('naming the page without access on a shared section is refused', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets') + section(500, 30));
check('SPOOF: a page that does not show the section is refused although the editor reads it', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets') + section(400));
check('a section no page shows has no page context', error_of($result) === 'Missing page context');

$result = call($ajax, array('action' => 'list_datasets') + section(400, 10));
check('SPOOF: an orphan section cannot borrow the editor\'s page', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets') + section(0, 10));
check('SPOOF: owner id 0 cannot borrow the editor\'s page', error_of($result) !== null && !reached_service());

$result = call($ajax, array('action' => 'list_datasets') + section(300));
check('a read-only page allows reading', error_of($result) === null);

$result = call($ajax, array('action' => 'create_dataset', 'name' => 'x') + section(300));
check('a read-only page refuses a write action', error_of($result) === 'Access denied' && !reached_service());

$result = call($ajax, array('action' => 'create_dataset', 'name' => 'x') + section(100, 10));
check('the editor may write on their own page', error_of($result) === null);

$result = call($ajax, array('action' => 'create_dataset', 'name' => 'x', 'csrf_token' => 'wrong') + section(100, 10));
check('a write action still needs the CSRF token', error_of($result) === 'Invalid CSRF token' && !reached_service());

// Every action that changes data needs the token: in the body or as the X-CSRF-Token header
$write_actions = array(
    'playground_run', 'builder_run', 'create_dataset', 'update_dataset', 'delete_dataset',
    'add_case_from_playground_run', 'add_cases_from_source', 'parse_cases_from_text', 'import_parsed_cases',
    'move_dataset_cases', 'delete_dataset_case', 'update_dataset_case', 'run_dataset_eval', 'delete_eval_run',
    'delete_eval_run_case', 'delete_eval_runs_bulk', 'link_eval_run_baseline', 'save_human_score',
);
foreach ($write_actions as $write_action) {
    $result = call($ajax, array('action' => $write_action, 'csrf_token' => 'wrong') + section(100, 10));
    check("$write_action: a wrong token is refused before any service is reached", error_of($result) === 'Invalid CSRF token' && !reached_service());

    $_SESSION = array('id_user' => 7, 'csrf_token' => 'tok');
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    ServiceCalls::$log = array();
    $result = $ajax->dispatch(array('action' => $write_action) + section(100, 10));
    check("$write_action: a request without any token is refused", error_of($result) === 'Invalid CSRF token' && !reached_service());

    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
    $result = $ajax->dispatch(array('action' => $write_action) + section(100, 10));
    check("$write_action: the X-CSRF-Token header is accepted (the request goes on to its own checks)", error_of($result) !== 'Invalid CSRF token');
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
}
$_SESSION = array('id_user' => 7, 'csrf_token' => 'tok');
$result = $ajax->dispatch(array('action' => 'create_dataset', 'name' => 'x', 'token' => 'tok') + section(100, 10));
check('the body field "token" is not a CSRF field (only csrf_token / X-CSRF-Token)', error_of($result) === 'Invalid CSRF token');
$result = $ajax->dispatch(array('action' => 'create_dataset', 'name' => 'x', 'csrf_token' => 'tok') + section(100, 10));
check('the body field "csrf_token" is accepted', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets', 'owner_type' => 'something_else', 'owner_id' => 100, 'page_id' => 10));
check('an owner type that is not a section is not authorized by a page', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets', 'owner_id' => 100, 'page_id' => 10));
check('a descriptor without an owner type is not authorized by a page', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets', 'owner_type' => 'llm_script', 'owner_id' => 5));
check('the editor has no scripts module access', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets', 'owner_type' => 'llm_memory_rule', 'owner_id' => 9));
check('the editor has no memory module access', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets', 'owner_type' => 'llm_script', 'owner_id' => 5), 8);
check('a scripts administrator reads script datasets', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets', 'owner_type' => 'llm_memory_rule', 'owner_id' => 9), 8);
check('a memory administrator reads memory rule datasets', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets', 'prompt_slot' => 'script', 'owner_id' => 5), 8);
check('the legacy script descriptor (slot only) keeps working for the scripts module', error_of($result) === null);

$result = call($ajax, array('action' => 'list_datasets') + section(100, 10), 9);
check('a user with no grants gets nothing', error_of($result) === 'Access denied');

$result = call($ajax, array('action' => 'list_datasets') + section(100, 10), 0);
check('no user in the session gets nothing', error_of($result) === 'Access denied');

/* =========================================================================
 * Ids in the request must belong to the authorized owner
 * ========================================================================= */

$own = section(100, 10);

$result = call($ajax, array('action' => 'get_version', 'version_id' => 1) + $own);
check('own prompt version is returned', error_of($result) === null && $result['content'] === 'prompt text of version 1');

$result = call($ajax, array('action' => 'get_version', 'version_id' => 2) + $own);
check('a version of another page\'s prompt is refused', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => 3) + $own);
check('a version of a script prompt is refused', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => 5) + $own);
check('a version of a nested section\'s prompt is refused (another owner)', error_of($result) === 'Prompt version is outside current descriptor scope');

$result = call($ajax, array('action' => 'get_version', 'version_id' => 999) + $own);
check('an unknown version is not found', error_of($result) === 'Prompt version not found' && !reached_service());

$result = call($ajax, array('action' => 'playground_run', 'target_version_id' => 2, 'draft_prompt' => 'x') + $own);
check('a foreign target version cannot be run', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'builder_run', 'version_id' => 2) + $own);
check('a foreign version cannot be used by the builder', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => 3, 'owner_type' => 'llm_script', 'owner_id' => 5), 8);
check('a script administrator reads their own script version', error_of($result) === null);

$result = call($ajax, array('action' => 'get_version', 'version_id' => 1, 'owner_type' => 'llm_script', 'owner_id' => 5), 8);
check('a script descriptor cannot read a page prompt version', error_of($result) === 'Prompt version is outside current descriptor scope');

$result = call($ajax, array('action' => 'get_version', 'version_id' => 3, 'owner_type' => 'llm_script', 'owner_id' => 6), 8);
check('a script descriptor cannot read another script\'s version', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => 6) + $own);
check('a script version is refused for the section with the same number', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => 6, 'owner_type' => 'llm_script', 'owner_id' => 100), 8);
check('the script with that number reads it', error_of($result) === null && $result['id'] === 6);

$result = call($ajax, array('action' => 'get_version', 'version_id' => 1, 'owner_type' => 'llm_script', 'owner_id' => 100), 8);
check('the script with the section\'s number cannot read the section\'s version', error_of($result) === 'Prompt version is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 6) + $own);
check('a script dataset is refused for the section with the same number', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 6, 'owner_type' => 'llm_script', 'owner_id' => 100), 8);
check('the script with that number reads its dataset', error_of($result) === null && $result['dataset']['id'] == 6);

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 1, 'owner_type' => 'llm_script', 'owner_id' => 100), 8);
check('the script with the section\'s number cannot read the section\'s dataset', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 7) + $own);
check('a dataset scoped to every section is readable by a section', error_of($result) === null && $result['dataset']['id'] == 7);

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 7, 'owner_type' => 'llm_script', 'owner_id' => 5), 8);
check('a dataset scoped to every section is not readable by a script', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 4, 'owner_type' => 'llm_script', 'owner_id' => 5), 8);
check('a script reads its own scoped dataset', error_of($result) === null && $result['dataset']['id'] == 4);

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 4, 'owner_type' => 'llm_script', 'owner_id' => 6), 8);
check('another script cannot read a scoped dataset', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 4, 'owner_type' => 'llm_script', 'owner_id' => 0), 8);
check('SPOOF: owner id 0 does not unlock a scoped dataset', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 1, 'owner_type' => 'llm_script', 'owner_id' => 5), 8);
check('a script descriptor cannot read a page\'s dataset', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

// datasets
$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 1) + $own);
check('own dataset is returned', error_of($result) === null && $result['dataset']['id'] == 1);

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 3) + $own);
check('a shared (unscoped) dataset is returned', error_of($result) === null && $result['dataset']['id'] == 3);

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 2) + $own);
check('another page\'s dataset is refused', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 4) + $own);
check('a script dataset is refused for a section', error_of($result) === 'Dataset is outside current descriptor scope');

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 5) + $own);
check('a dataset of another section (even a nested one) is refused', error_of($result) === 'Dataset is outside current descriptor scope');

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 99) + $own);
check('an unknown dataset is not found', error_of($result) === 'Dataset not found' && !reached_service());

foreach (array('list_dataset_cases', 'list_compatible_datasets') as $action) {
    $result = call($ajax, array('action' => $action, 'dataset_id' => 2) + $own);
    check("$action refuses another page's dataset", error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());
}
foreach (array('update_dataset', 'delete_dataset') as $action) {
    $result = call($ajax, array('action' => $action, 'dataset_id' => 2, 'name' => 'x') + $own);
    check("$action refuses another page's dataset", error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());
}
$result = call($ajax, array('action' => 'delete_dataset', 'dataset_id' => 1) + $own);
check('the editor may delete their own dataset', error_of($result) === null && ServiceCalls::names() === array('LlmDatasetService::deleteDataset'));

$result = call($ajax, array('action' => 'add_cases_from_source', 'dataset_id' => 2, 'source_type' => 'conversation', 'source_ids_json' => '[1]') + $own);
check('importing into another page\'s dataset is refused', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'move_dataset_cases', 'source_dataset_id' => 1, 'target_dataset_id' => 2, 'case_ids_json' => '[7]') + $own);
check('moving cases into another page\'s dataset is refused', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'move_dataset_cases', 'source_dataset_id' => 2, 'target_dataset_id' => 1, 'case_ids_json' => '[8]') + $own);
check('moving cases out of another page\'s dataset is refused', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'move_dataset_cases', 'source_dataset_id' => 1, 'target_dataset_id' => 3, 'case_ids_json' => '[7]') + $own);
check('moving cases to a shared dataset is allowed', error_of($result) === null && reached_service());

// evaluation runs
$result = call($ajax, array('action' => 'get_eval_run', 'run_id' => 11) + $own);
check('own evaluation run is returned', error_of($result) === null && $result['id'] == 11);

$result = call($ajax, array('action' => 'get_eval_run', 'run_id' => 12) + $own);
check('another page\'s evaluation run is refused', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'get_eval_run', 'run_id' => 99) + $own);
check('an unknown evaluation run is not found', error_of($result) === 'Evaluation run not found' && !reached_service());

$result = call($ajax, array('action' => 'list_eval_run_cases', 'run_id' => 12) + $own);
check('the cases of another page\'s run are refused', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'delete_eval_run', 'run_id' => 12, 'dataset_id' => 1) + $own);
check('SPOOF: a foreign run cannot ride along with the editor\'s own dataset_id', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'delete_eval_run', 'run_id' => 11, 'dataset_id' => 1) + $own);
check('the editor may delete their own run', error_of($result) === null && reached_service());

$result = call($ajax, array('action' => 'delete_eval_run_case', 'run_case_id' => 22, 'dataset_id' => 1) + $own);
check('SPOOF: a foreign run case cannot ride along with the editor\'s own dataset_id', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'delete_eval_run_case', 'run_case_id' => 21, 'dataset_id' => 1) + $own);
check('the editor may delete their own run case', error_of($result) === null && reached_service());

$result = call($ajax, array('action' => 'delete_eval_run_case', 'run_case_id' => 99, 'dataset_id' => 1) + $own);
check('an unknown run case is not found', error_of($result) === 'Evaluation case not found' && !reached_service());

$result = call($ajax, array('action' => 'save_human_score', 'id_llm_eval_run_cases' => 22, 'id_llm_eval_definitions' => 1) + $own);
check('a score cannot be written to another page\'s run case', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'save_human_score', 'id_llm_eval_run_cases' => 21, 'id_llm_eval_definitions' => 1) + $own);
check('a score can be written to the editor\'s own run case', error_of($result) === null && reached_service());

$result = call($ajax, array('action' => 'link_eval_run_baseline', 'run_id' => 11, 'baseline_run_id' => 12) + $own);
check('a foreign run cannot be linked as the baseline', error_of($result) === 'Dataset is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'link_eval_run_baseline', 'run_id' => 11, 'baseline_run_id' => 11) + $own);
check('own runs can be linked as baseline', error_of($result) === null && reached_service());

// test cases
$result = call($ajax, array('action' => 'list_case_evaluation_history', 'case_id' => 7) + $own);
check('history of a case in the editor\'s dataset is returned', error_of($result) === null && reached_service());

$result = call($ajax, array('action' => 'list_case_evaluation_history', 'case_id' => 8) + $own);
check('history of a case that only another page\'s dataset holds is refused', error_of($result) === 'Dataset case is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'list_case_evaluation_history', 'case_id' => 9) + $own);
check('an orphan case is not found', error_of($result) === 'Dataset case not found' && !reached_service());

$result = call($ajax, array('action' => 'update_dataset_case', 'dataset_id' => 1, 'dataset_case_id' => 8, 'title' => 'x') + $own);
check('SPOOF: a foreign case cannot be edited through the editor\'s own dataset_id', error_of($result) === 'Dataset case is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'update_dataset_case', 'dataset_id' => 1, 'dataset_case_id' => 7, 'title' => 'x') + $own);
check('the editor may edit their own case', error_of($result) === null && reached_service());

$result = call($ajax, array('action' => 'delete_dataset_case', 'dataset_id' => 1, 'dataset_case_id' => 8) + $own);
check('SPOOF: a foreign case cannot be deleted through the editor\'s own dataset_id', error_of($result) === 'Dataset case is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'delete_dataset_case', 'dataset_case_id' => 7) + $own);
check('deleting a case needs the dataset it is deleted from (else every linked dataset would lose it)', error_of($result) === 'Missing dataset_id' && !reached_service());

$result = call($ajax, array('action' => 'delete_dataset_case', 'dataset_case_id' => 8) + $own);
check('a foreign case without a dataset_id is refused as foreign', error_of($result) === 'Dataset case is outside current descriptor scope' && !reached_service());

$result = call($ajax, array('action' => 'delete_dataset_case', 'dataset_id' => 0, 'dataset_case_id' => 7) + $own);
check('dataset_id 0 does not mean "every dataset"', error_of($result) === 'Missing dataset_id' && !reached_service());

$result = call($ajax, array('action' => 'delete_dataset_case', 'dataset_id' => 1, 'dataset_case_id' => 7) + $own);
check('the editor may delete their own case from their own dataset',
    error_of($result) === null
    && ServiceCalls::$log[0][0] === 'LlmDatasetService::deleteDatasetCase'
    && ServiceCalls::$log[0][1] === array(7, 1));

$result = call($ajax, array('action' => 'delete_dataset_case', 'dataset_id' => 3, 'dataset_case_id' => 7) + $own);
check('a case may be removed from a shared dataset it is linked to', error_of($result) === null && reached_service());

// ids may be strings, arrays or junk: never a way around the checks
$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => '2abc') + $own);
check('a numeric prefix is read the way the handler reads it (dataset 2: refused)', error_of($result) === 'Dataset is outside current descriptor scope');

// (int) of a non-empty array is 1: such a value must not slip past the check and be read as dataset 1 by the handler
$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => array(2)) + $own);
check('SPOOF: an array where an id belongs is refused, not read as id 1', error_of($result) === 'Invalid dataset_id' && !reached_service());

$result = call($ajax, array('action' => 'get_version', 'version_id' => array('x')) + $own);
check('SPOOF: an array version id is refused', error_of($result) === 'Invalid version_id' && !reached_service());

$result = call($ajax, array('action' => 'get_eval_run', 'run_id' => array(2)) + $own);
check('SPOOF: an array run id is refused', error_of($result) === 'Invalid run_id' && !reached_service());

$result = call($ajax, array('action' => 'list_case_evaluation_history', 'case_id' => array(8)) + $own);
check('SPOOF: an array case id is refused', error_of($result) === 'Invalid case_id' && !reached_service());

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 1.0) + $own);
check('a float id is read as its integer part, like the handler', error_of($result) === null);

/* =========================================================================
 * Listing is scoped by the descriptor
 * ========================================================================= */

$result = call($ajax, array('action' => 'list_datasets') + $own);
check('listing returns the owner\'s datasets only', error_of($result) === null && array_map('intval', array_column($result, 'id')) === array(1));
check('listing used the descriptor\'s scope', ServiceCalls::$log[0][1][0]['owner_type_scope'] === 'style_field' && ServiceCalls::$log[0][1][0]['owner_id_scope'] === 100);

$result = call($ajax, array('action' => 'list_datasets', 'owner_type_scope' => 'style_field', 'owner_id_scope' => 200) + $own);
check('SPOOF: a scope in the request does not widen the listing', array_map('intval', array_column($result, 'id')) === array(1));

$result = call($ajax, array('action' => 'list_datasets', 'owner_type_scope' => '', 'owner_id_scope' => '') + $own);
check('SPOOF: an empty scope in the request does not list everything', array_map('intval', array_column($result, 'id')) === array(1));

$result = call($ajax, array('action' => 'list_datasets', 'search' => 'abc', 'execution_profile' => 'text_only') + $own);
check('search and execution profile still reach the service',
    ServiceCalls::$log[0][1][0]['search'] === 'abc' && ServiceCalls::$log[0][1][0]['execution_profile'] === 'text_only');

$result = call($ajax, array('action' => 'list_datasets') + section(102, 10));
check('a nested section lists only its own datasets', array_map('intval', array_column($result, 'id')) === array(5));

$result = call($ajax, array('action' => 'list_datasets', 'owner_type' => 'llm_script', 'owner_id' => 0), 8);
check('an owner that does not exist yet has no datasets (and no query)', $result === array() && !reached_service());

/* =========================================================================
 * Imports need an owner
 * ========================================================================= */

$result = call($ajax, array('action' => 'get_import_candidates', 'source_type' => 'playground_run', 'owner_type' => 'llm_script', 'owner_id' => 0), 8);
check('no import candidates without an owner (they would match every owner)', $result === array() && !reached_service());

$result = call($ajax, array('action' => 'get_import_candidates', 'source_type' => 'playground_run') + $own);
$context = ServiceCalls::$log[0][1][2];
check('import candidates are asked for the editor\'s own section',
    error_of($result) === null && $context['descriptor']['owner_id'] === 100 && $context['descriptor']['owner_type'] === 'style_field');
check('...with no page in the context: records belong to their owner, the service does not widen to a page',
    !array_key_exists('allowed_page_id', $context));

$result = call($ajax, array('action' => 'get_import_candidates', 'source_type' => 'playground_run') + section(500));
check('a section shown on two pages is asked for by its own id, whichever page is read', error_of($result) === null
    && ServiceCalls::$log[0][1][2]['descriptor']['owner_id'] === 500);

$result = call($ajax, array('action' => 'get_import_candidates', 'source_type' => 'playground_run') + section(200, 10));
check('SPOOF: import candidates of a foreign section are refused', error_of($result) === 'Access denied' && !reached_service());

$result = call($ajax, array('action' => 'get_import_candidates', 'source_type' => 'conversation_message', 'prompt_slot' => 'script', 'owner_id' => 5), 8);
check('the legacy script descriptor (slot only) is asked for as a script owner',
    error_of($result) === null && ServiceCalls::$log[0][1][2]['descriptor']['owner_type'] === 'llm_script');

$result = call($ajax, array('action' => 'add_cases_from_source', 'dataset_id' => 3, 'source_type' => 'conversation', 'source_ids_json' => '[1]', 'owner_type' => 'llm_script', 'owner_id' => 0), 8);
check('importing without an owner is refused', error_of($result) === 'Missing owner_id' && !reached_service());

$result = call($ajax, array('action' => 'add_cases_from_source', 'dataset_id' => 1, 'source_type' => 'conversation', 'source_ids_json' => '[1]') + $own);
$context = ServiceCalls::$log[0][1][3];
check('importing into the editor\'s dataset names the editor\'s own section, and no page',
    error_of($result) === null && $context['descriptor']['owner_id'] === 100 && !array_key_exists('allowed_page_id', $context));
check('the ids to import reach the service as sent (it scopes them)', ServiceCalls::$log[0][1][2] === array(1));

$result = call($ajax, array('action' => 'add_cases_from_source', 'dataset_id' => 3, 'source_type' => 'conversation_message', 'source_ids_json' => '[1]', 'prompt_slot' => 'script', 'owner_id' => 5), 8);
check('importing as the legacy script descriptor (slot only) names a script owner',
    error_of($result) === null && ServiceCalls::$log[0][1][3]['descriptor']['owner_type'] === 'llm_script');

/* =========================================================================
 * Errors: deliberate messages pass, internals do not
 * ========================================================================= */

$result = call($ajax, array('action' => 'no_such_action') + $own);
check('an unknown action names itself', error_of($result) === 'Unknown prompt lab action: no_such_action');

$result = call($ajax, array('action' => 'get_dataset', 'dataset_id' => 99) + $own);
check('a deliberate message reaches the browser', error_of($result) === 'Dataset not found');

file_put_contents($error_log, '');
ServiceCalls::$throw = null;
$_SESSION = array('id_user' => 7, 'csrf_token' => 'tok');
ServiceCalls::$log = array();
$secret_sql = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'selfhelp.llm_eval_cases' doesn't exist";
ServiceCalls::$throw = new PDOException($secret_sql);
$result = $ajax->dispatch(array('action' => 'list_dataset_cases', 'dataset_id' => 1, 'csrf_token' => 'tok') + $own);
check('a database error reaches the browser as a generic message', error_of($result) === 'Request failed');
check('the generic message carries no SQL or table name', strpos(json_encode($result), 'llm_eval_cases') === false && strpos(json_encode($result), 'SQLSTATE') === false);
$logged = (string)file_get_contents($error_log);
check('the failure is still logged for the operator (action and error class)',
    strpos($logged, '[LLM] Prompt Lab action list_dataset_cases failed: PDOException: database error') !== false);
check('...through the reporter, which keeps the SQL text (table names, row values) out of the log',
    strpos($logged, 'llm_eval_cases') === false);

ServiceCalls::$log = array();
ServiceCalls::$throw = new TypeError('Argument #1 ($x) must be of type array, null given in /var/www/secret/path.php');
$result = $ajax->dispatch(array('action' => 'list_dataset_cases', 'dataset_id' => 1, 'csrf_token' => 'tok') + $own);
check('a PHP Error (not an Exception) is contained and generic', error_of($result) === 'Request failed' && strpos(json_encode($result), 'secret') === false);

ServiceCalls::$log = array();
ServiceCalls::$throw = new Exception('Dataset is locked');
$result = $ajax->dispatch(array('action' => 'list_dataset_cases', 'dataset_id' => 1, 'csrf_token' => 'tok') + $own);
check('a deliberate service message still reaches the browser', error_of($result) === 'Dataset is locked');

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
