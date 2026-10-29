<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the LLM admin console (ModuleLlmAdminConsoleController,
 * ModuleLlmAdminConsoleModel and LlmAdminService), plugin v1.6.0:
 *
 *  - every action asks for its own ACL mode on the page: select to list and
 *    read, update to block / unblock, delete to delete;
 *  - block, unblock and delete are POST only and need the session CSRF token;
 *  - the sent context and request payload of a message are only returned to
 *    users with update access;
 *  - the audit entry names the acting admin, not the conversation owner;
 *  - page / per_page are clamped before they reach LIMIT / OFFSET.
 *
 * No bootstrap, no database: the real controller, model, admin service,
 * CSRF service and logging trait are copied into a throw-away tree that
 * mirrors their relative require_once targets, where small stand-ins replace
 * the core base classes, the JSON trait (which exits), LlmService and the
 * database.
 *
 * Run: php tests/ModuleLlmAdminConsoleTest.php
 */

foreach (array(
    'TRANSACTION_BY_LLM_PLUGIN' => 'by_llm_plugin',
    'transactionTypes_update' => 'update',
    'transactionTypes_delete' => 'delete',
    'LLM_DEFAULT_MESSAGE_LIMIT' => 100,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_admin_console_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_admin_console_test_' . getmypid();
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
    'component/moduleLlmAdminConsole/ModuleLlmAdminConsoleController.php',
    'component/moduleLlmAdminConsole/ModuleLlmAdminConsoleModel.php',
    'service/LlmCsrfService.php',
    'service/LlmErrorReporter.php',
    'service/LlmAdminService.php',
    'service/exception/LlmException.php',
    'service/base/LlmLoggingTrait.php',
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

    public function __construct($services, $params = array(), $id_page = null)
    {
        $this->services = $services;
        $this->db = $services->get_db();
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

// LlmService: only what LlmAdminService leans on (db, services, audit logging)
sandbox_write($plugin_server . '/service/LlmService.php', <<<'PHP'
<?php
require_once __DIR__ . '/base/LlmLoggingTrait.php';

class LlmService
{
    use LlmLoggingTrait;

    protected $services;
    protected $db;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }
}
PHP
);

/* =========================================================================
 * Stand-ins for the services container
 * ========================================================================= */

class FakeAcl
{
    /** @var string[] ACL modes the user does not hold */
    public $deny = array();
    /** @var string[] modes asked for, in order */
    public $asked = array();

    public function has_access($id, $id_page, $mode, $is_group = false)
    {
        $this->asked[] = $mode;
        return !in_array($mode, $this->deny, true);
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
    public $conversations = array();
    public $page_fields = array();
    /** @var array[] [sql, params] of every read */
    public $queries = array();
    /** @var array[] [table, set, where] of every write */
    public $updates = array();
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

    public function query_db_first($sql, $params = array())
    {
        $this->queries[] = array($sql, $params);
        $this->maybeFail($sql);
        if (strpos($sql, 'CALL get_page_fields') === 0) {
            return $this->page_fields;
        }
        if (strpos($sql, 'SELECT COUNT(DISTINCT lc.id)') === 0) {
            return array('total' => 0);
        }
        if (strpos($sql, 'FROM llmConversations WHERE id = ?') !== false
            || strpos($sql, 'WHERE lc.id = ?') !== false) {
            $id = (int)$params[0];
            return isset($this->conversations[$id]) ? $this->conversations[$id] : false;
        }
        return false;
    }

    public function query_db($sql, $params = array())
    {
        $this->queries[] = array($sql, $params);
        $this->maybeFail($sql);
        return array();
    }

    public function update_by_ids($table, $set, $where)
    {
        $this->updates[] = array($table, $set, $where);
        return true;
    }

    /** SQL of the last read that contains the needle. */
    public function lastSql($needle)
    {
        for ($i = count($this->queries) - 1; $i >= 0; $i--) {
            if (strpos($this->queries[$i][0], $needle) !== false) {
                return $this->queries[$i][0];
            }
        }
        return null;
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
        $this->db->conversations = array(
            5 => array('id' => 5, 'id_users' => 41, 'blocked' => 0, 'title' => 'Open conversation'),
            6 => array('id' => 6, 'id_users' => 42, 'blocked' => 1, 'title' => 'Blocked conversation'),
        );
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

require_once $plugin_server . '/component/moduleLlmAdminConsole/ModuleLlmAdminConsoleController.php';
require_once $plugin_server . '/component/moduleLlmAdminConsole/ModuleLlmAdminConsoleModel.php';

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

function newServices()
{
    return new FakeServices();
}

/**
 * Run one request through the real controller.
 *
 * GET requests carry the action and parameters in the query string, POST
 * requests (what the React console sends) in the form body.
 *
 * @param string $token How the CSRF token arrives: 'post' (form field), 'header',
 *  'wrong' (form field with a bad value) or 'none'
 * @param string|null $session_token Token held by the session (null = the session never got one)
 * @param int|null $user Session user (null = not logged in)
 * @return array|null [status, data] of the JSON response, null when the controller stayed silent
 */
function request(FakeServices $services, $action, $method, array $params = array(), $token = 'post', $session_token = 'session-token', $user = 7)
{
    $_SESSION = array();
    if ($user !== null) {
        $_SESSION['id_user'] = $user;
    }
    if ($session_token !== null) {
        $_SESSION['csrf_token'] = $session_token;
    }
    $_GET = array();
    $_POST = array();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $_SERVER['REQUEST_METHOD'] = $method;

    if ($method === 'POST') {
        $_POST = array_merge(array('action' => $action), $params);
        if ($token === 'post') {
            $_POST['csrf_token'] = 'session-token';
        } elseif ($token === 'wrong') {
            $_POST['csrf_token'] = 'not-the-token';
        } elseif ($token === 'header') {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = 'session-token';
        }
    } else {
        $_GET = array_merge(array('action' => $action), $params);
        if ($token === 'post') {
            // A token in the query string must never make a GET change anything
            $_GET['csrf_token'] = 'session-token';
        }
    }

    try {
        new ModuleLlmAdminConsoleController(new ModuleLlmAdminConsoleModel($services, array(), 55));
    } catch (TestJsonSent $sent) {
        return array($sent->status, $sent->data);
    }
    return null;
}

/* =========================================================================
 * Dispatch
 * ========================================================================= */

$s = newServices();
check('a request without an action sends nothing and asks no ACL question', request($s, null, 'GET') === null && $s->acl->asked === array());

list($status, $data) = request($s, 'drop_everything', 'GET');
check('unknown action: 400', $status === 400 && $data['error'] === 'Unknown action' && $s->acl->asked === array());
list($status) = request($s, array('admin_filters'), 'GET');
check('an action that is not a string: 400', $status === 400);

// --- not logged in ---------------------------------------------------------------
$s = newServices();
list($status, $data) = request($s, 'admin_conversations', 'GET', array(), 'none', 'session-token', null);
check('no session user: 403 and the ACL is not even asked', $status === 403 && $s->acl->asked === array());

/* =========================================================================
 * Every action asks for its own ACL mode
 * ========================================================================= */

$expected_mode = array(
    'admin_filters' => array('GET', 'select', array()),
    'admin_conversations' => array('GET', 'select', array()),
    'admin_messages' => array('GET', 'select', array('conversation_id' => 5)),
    'admin_block_conversation' => array('POST', 'update', array('conversation_id' => 5)),
    'admin_unblock_conversation' => array('POST', 'update', array('conversation_id' => 6)),
    'admin_delete_conversation' => array('POST', 'delete', array('conversation_id' => 5)),
);
foreach ($expected_mode as $action => $spec) {
    list($method, $mode, $params) = $spec;

    $s = newServices();
    list($status) = request($s, $action, $method, $params);
    check("$action works with the $mode ACL", $status === 200 && $s->acl->asked[0] === $mode);

    // Hold every mode but the one this action needs
    $s = newServices();
    $s->acl->deny = array($mode);
    list($status, $data) = request($s, $action, $method, $params);
    check("$action is refused without the $mode ACL (403)", $status === 403 && $data['error'] === 'Access denied'
        && $s->db->updates === array() && $s->transaction->calls === array());
}

// --- select only: read yes, change no -------------------------------------------------
$s = newServices();
$s->acl->deny = array('insert', 'update', 'delete');
list($status) = request($s, 'admin_conversations', 'GET');
check('a select-only user can list conversations', $status === 200);
list($status) = request($s, 'admin_block_conversation', 'POST', array('conversation_id' => 5));
check('a select-only user cannot block', $status === 403);
list($status) = request($s, 'admin_unblock_conversation', 'POST', array('conversation_id' => 6));
check('a select-only user cannot unblock', $status === 403);
list($status) = request($s, 'admin_delete_conversation', 'POST', array('conversation_id' => 5));
check('a select-only user cannot delete', $status === 403);
check('nothing was written or audited for the select-only user', $s->db->updates === array() && $s->transaction->calls === array());

// --- update without delete ---------------------------------------------------------------
$s = newServices();
$s->acl->deny = array('delete');
list($status) = request($s, 'admin_block_conversation', 'POST', array('conversation_id' => 5));
check('a moderator with update (no delete) can block', $status === 200);
list($status) = request($s, 'admin_delete_conversation', 'POST', array('conversation_id' => 5));
check('a moderator with update (no delete) cannot delete', $status === 403);

/* =========================================================================
 * POST only, with the session CSRF token
 * ========================================================================= */

foreach (array(
    'admin_block_conversation' => 5,
    'admin_unblock_conversation' => 6,
    'admin_delete_conversation' => 5,
) as $action => $conversation) {
    $params = array('conversation_id' => $conversation);

    $s = newServices();
    list($status) = request($s, $action, 'GET', $params);
    check("$action refuses GET, even with a token in the URL (405)", $status === 405 && $s->db->updates === array());

    $s = newServices();
    list($status, $data) = request($s, $action, 'POST', $params, 'none');
    check("$action refuses a POST without token (403)", $status === 403 && $data['error'] === 'Invalid CSRF token' && $s->db->updates === array());

    $s = newServices();
    list($status) = request($s, $action, 'POST', $params, 'wrong');
    check("$action refuses a wrong token (403)", $status === 403 && $s->db->updates === array());

    $s = newServices();
    list($status) = request($s, $action, 'POST', $params, 'post', null);
    check("$action refuses a session that never got a token (403, never skipped)", $status === 403 && $s->db->updates === array());

    $s = newServices();
    list($status) = request($s, $action, 'POST', $params, 'header');
    check("$action accepts the token in the X-CSRF-Token header", $status === 200 && $s->db->updates !== array());

    $s = newServices();
    list($status) = request($s, $action, 'POST', $params, 'post');
    check("$action accepts the token in the form field", $status === 200 && $s->db->updates !== array());
}

// Reading does not need a token (and works for GET)
$s = newServices();
list($status) = request($s, 'admin_conversations', 'GET', array(), 'none');
check('listing conversations needs no CSRF token', $status === 200);

/* =========================================================================
 * What the moderation actions write, and who is audited
 * ========================================================================= */

$s = newServices();
list($status) = request($s, 'admin_block_conversation', 'POST', array('conversation_id' => 5, 'reason' => 'Off topic'), 'post', 'session-token', 7);
$set = $s->db->updates[0][1];
check('block sets the flag, the reason and the acting admin', $status === 200 && $s->db->updates[0][0] === 'llmConversations'
    && $set['blocked'] === 1 && $set['blocked_reason'] === 'Off topic' && (int)$set['blocked_by'] === 7);
$call = $s->transaction->calls[0];
check('block is audited under the admin (7), not the owner (41)', count($s->transaction->calls) === 1
    && $call[0] === transactionTypes_update && (int)$call[2] === 7 && $call[3] === 'llmConversations' && (int)$call[4] === 5);
check('the audit text names the owner and the reason', strpos($call[6], 'owner user ID: 41') !== false && strpos($call[6], 'Off topic') !== false);

$s = newServices();
list($status) = request($s, 'admin_block_conversation', 'POST', array('conversation_id' => 5), 'post', 'session-token', 7);
check('block without a reason uses the default reason', $status === 200
    && $s->db->updates[0][1]['blocked_reason'] === 'Manually blocked by administrator');

$s = newServices();
list($status) = request($s, 'admin_unblock_conversation', 'POST', array('conversation_id' => 6), 'post', 'session-token', 9);
$set = $s->db->updates[0][1];
check('unblock clears the block fields', $status === 200 && $set['blocked'] === 0 && $set['blocked_reason'] === null && $set['blocked_by'] === null);
$call = $s->transaction->calls[0];
check('unblock is audited under the admin (9), not the owner (42)', (int)$call[2] === 9 && strpos($call[6], 'owner user ID: 42') !== false);

$s = newServices();
list($status) = request($s, 'admin_delete_conversation', 'POST', array('conversation_id' => 5), 'post', 'session-token', 7);
check('delete soft-deletes the conversation and its messages', $status === 200 && count($s->db->updates) === 2
    && $s->db->updates[0][0] === 'llmConversations' && $s->db->updates[0][1] === array('deleted' => 1)
    && $s->db->updates[1][0] === 'llmMessages' && $s->db->updates[1][1] === array('deleted' => 1));
$call = $s->transaction->calls[0];
check('delete is audited under the admin (7), not the owner (41)', $call[0] === transactionTypes_delete && (int)$call[2] === 7
    && strpos($call[6], 'owner user ID: 41') !== false);

// --- things that cannot be done -----------------------------------------------------------
$s = newServices();
list($status, $data) = request($s, 'admin_block_conversation', 'POST', array('conversation_id' => 6));
check('blocking a blocked conversation fails without a write or audit entry', $status !== 200 && isset($data['error'])
    && $s->db->updates === array() && $s->transaction->calls === array());
list($status, $data) = request($s, 'admin_unblock_conversation', 'POST', array('conversation_id' => 5));
check('unblocking an open conversation fails without a write or audit entry', $status !== 200 && isset($data['error'])
    && $s->db->updates === array() && $s->transaction->calls === array());
list($status, $data) = request($s, 'admin_delete_conversation', 'POST', array('conversation_id' => 999));
check('deleting an unknown conversation fails without a write or audit entry', $status !== 200 && isset($data['error'])
    && $s->db->updates === array() && $s->transaction->calls === array());
list($status) = request($s, 'admin_block_conversation', 'POST', array());
check('block without a conversation id: 400', $status === 400);
list($status) = request($s, 'admin_unblock_conversation', 'POST', array());
check('unblock without a conversation id: 400', $status === 400);
list($status) = request($s, 'admin_delete_conversation', 'POST', array());
check('delete without a conversation id: 400', $status === 400);

/* =========================================================================
 * Sent context and request payload are for moderators only
 * ========================================================================= */

$s = newServices();
$s->acl->deny = array('update', 'delete');
list($status, $data) = request($s, 'admin_messages', 'GET', array('conversation_id' => 5));
$sql = $s->db->lastSql('FROM llmMessages m');
check('a select-only user reads the messages', $status === 200 && $sql !== null);
check('a select-only user gets neither sent_context nor request_payload', strpos($sql, 'sent_context') === false
    && strpos($sql, 'request_payload') === false);

$s = newServices();
list($status) = request($s, 'admin_messages', 'GET', array('conversation_id' => 5));
$sql = $s->db->lastSql('FROM llmMessages m');
check('a user with update access also gets sent_context and request_payload', $status === 200
    && strpos($sql, 'm.sent_context') !== false && strpos($sql, 'm.request_payload') !== false);

$s = newServices();
list($status) = request($s, 'admin_messages', 'GET', array('conversation_id' => 999));
check('messages of an unknown conversation: 404', $status === 404);
list($status) = request($s, 'admin_messages', 'GET');
check('messages without a conversation id: 400', $status === 400);

/* =========================================================================
 * Filters and paging
 * ========================================================================= */

$s = newServices();
list($status, $data) = request($s, 'admin_filters', 'GET');
check('filters answer with users, sections, scripts and sources', $status === 200
    && array_keys($data['filters']) === array('users', 'sections', 'scripts', 'sources'));

$s = newServices();
list($status, $data) = request($s, 'admin_conversations', 'GET', array('page' => '-3', 'per_page' => '100000'));
check('a negative page and a huge per_page are clamped (page 1, 100 rows)', $status === 200
    && $data['page'] === 1 && $data['per_page'] === 100
    && strpos($s->db->lastSql('FROM llmConversations lc'), 'LIMIT 100 OFFSET 0') !== false);

$s = newServices();
list($status, $data) = request($s, 'admin_conversations', 'GET', array('page' => '5', 'per_page' => '0'));
check('per_page 0 falls back to 50 rows', $data['per_page'] === 50
    && strpos($s->db->lastSql('FROM llmConversations lc'), 'LIMIT 50 OFFSET 200') !== false);

$s = newServices();
list($status, $data) = request($s, 'admin_conversations', 'GET', array('page' => 'abc'));
check('a non numeric page becomes page 1', $data['page'] === 1);

// The service clamps on its own as well (other callers than the controller)
$service = new LlmAdminService($s);
$result = $service->getAdminConversations(array(), 0, -1);
check('the service clamps page 0 / per_page -1 to 1 / 1', $result['page'] === 1 && $result['per_page'] === 1
    && strpos($s->db->lastSql('FROM llmConversations lc'), 'LIMIT 1 OFFSET 0') !== false);
$result = $service->getAdminConversations(array(), 999999999, 10);
check('the service caps the page so OFFSET stays bounded', $result['page'] === 100000
    && strpos($s->db->lastSql('FROM llmConversations lc'), 'OFFSET 999990') !== false);

$s = newServices();
list($status) = request($s, 'admin_conversations', 'GET', array('source_type' => 'bad value!'));
check('a malformed source_type filter: 400', $status === 400);
list($status) = request($s, 'admin_conversations', 'GET', array('record_id' => '1 OR 1=1'));
check('a malformed record_id filter: 400', $status === 400);
list($status) = request($s, 'admin_conversations', 'GET', array('source_type' => 'llm_form', 'record_id' => 'draft'));
check('valid source_type and record_id filters are accepted', $status === 200);

/* =========================================================================
 * The interview state never reaches the console
 *
 * llmConversations.state holds the participant's interview draft. A user with
 * read-only access to the console must not receive it, so both reads select an
 * explicit column list instead of lc.*.
 * ========================================================================= */

$console_columns = array(
    'lc.id', 'lc.id_users', 'lc.id_sections', 'lc.id_llm_scripts', 'lc.title', 'lc.model',
    'lc.temperature', 'lc.max_tokens', 'lc.deleted', 'lc.blocked', 'lc.blocked_reason',
    'lc.blocked_at', 'lc.blocked_by', 'lc.id_dataRows', 'lc.completed_at',
    'lc.created_at', 'lc.updated_at',
);

$s = newServices();
request($s, 'admin_conversations', 'GET');
$list_sql = (string)$s->db->lastSql('LIMIT ');
check('the conversation list query is captured', $list_sql !== '');
check('the conversation list never selects lc.*', strpos($list_sql, 'lc.*') === false);
check('the conversation list does not select the interview state', preg_match('/\bstate\b/i', $list_sql) !== 1);
$missing = array();
foreach ($console_columns as $column) {
    if (strpos($list_sql, $column . ',') === false && strpos($list_sql, $column . ' ') === false) {
        $missing[] = $column;
    }
}
check('the conversation list still selects every column the console shows', empty($missing));

$s = newServices();
$s->db->conversations[5] = array('id' => 5, 'id_users' => 3, 'title' => 'T', 'blocked' => 0);
request($s, 'admin_messages', 'GET', array('conversation_id' => 5));
$detail_sql = (string)$s->db->lastSql('WHERE lc.id = ?');
check('the conversation detail query is captured', $detail_sql !== '');
check('the conversation detail never selects lc.*', strpos($detail_sql, 'lc.*') === false);
check('the conversation detail does not select the interview state', preg_match('/\bstate\b/i', $detail_sql) !== 1);
$missing = array();
foreach ($console_columns as $column) {
    if (strpos($detail_sql, $column . ',') === false && strpos($detail_sql, $column . ' ') === false) {
        $missing[] = $column;
    }
}
check('the conversation detail still selects every column the console shows', empty($missing));

check('the shared column list is the single source of both reads',
    defined('LlmAdminService::ADMIN_CONVERSATION_COLUMNS')
    && strpos($list_sql, LlmAdminService::ADMIN_CONVERSATION_COLUMNS) !== false
    && strpos($detail_sql, LlmAdminService::ADMIN_CONVERSATION_COLUMNS) !== false);
check('a read-only (select-only) user gets the same restricted columns', (function () use ($console_columns) {
    $s = newServices();
    $s->acl->deny = array('update', 'delete');
    $s->db->conversations[5] = array('id' => 5, 'id_users' => 3);
    list($status) = request($s, 'admin_messages', 'GET', array('conversation_id' => 5));
    $sql = (string)$s->db->lastSql('WHERE lc.id = ?');
    return $status === 200 && $sql !== '' && strpos($sql, 'lc.*') === false && preg_match('/\bstate\b/i', $sql) !== 1;
})());

/* =========================================================================
 * Failures: deliberate messages pass, unexpected ones are logged and hidden
 *
 * A moderator is told "Conversation is already blocked". A database error
 * (which can quote row values) or any other unexpected failure is answered
 * with a generic message; the details go to the server log only.
 * ========================================================================= */

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_admin_console_test.log';

$s = newServices();
list($status, $data) = request($s, 'admin_block_conversation', 'POST', array('conversation_id' => 6));
check('blocking a blocked conversation: 409 with its own message', $status === 409 && $data['error'] === 'Conversation is already blocked');
list($status, $data) = request($s, 'admin_unblock_conversation', 'POST', array('conversation_id' => 5));
check('unblocking an open conversation: 409 with its own message', $status === 409 && $data['error'] === 'Conversation is not blocked');
foreach (array('admin_block_conversation', 'admin_unblock_conversation', 'admin_delete_conversation') as $action) {
    list($status, $data) = request($s, $action, 'POST', array('conversation_id' => 999));
    check("$action on an unknown conversation: 404 with its own message", $status === 404 && $data['error'] === 'Conversation not found');
}
list($status, $data) = request($s, 'admin_messages', 'GET', array('conversation_id' => 999));
check('reading an unknown conversation: 404', $status === 404 && $data['error'] === 'Conversation not found');

$secret = 'SECRET-ROW-VALUE-4711';
$failing = array(
    'admin_filters' => array('GET', array(), 'Admin console filters', null),
    'admin_conversations' => array('GET', array(), 'Admin console conversations', null),
    'admin_messages' => array('GET', array('conversation_id' => 5), 'Admin console messages', 5),
    'admin_block_conversation' => array('POST', array('conversation_id' => 5), 'Admin console block', 5),
    'admin_unblock_conversation' => array('POST', array('conversation_id' => 6), 'Admin console unblock', 6),
    'admin_delete_conversation' => array('POST', array('conversation_id' => 5), 'Admin console delete', 5),
);
foreach ($failing as $action => $spec) {
    list($method, $params, $context, $conversation) = $spec;
    $failures = array(
        'driver' => new PDOException("SQLSTATE[23000]: Duplicate entry '$secret' for key 'answers'"),
        'plain' => new RuntimeException("cannot open D:\\secret\\config.php ($secret)"),
        // An LlmException is only a message for the moderator while its code is 4xx; with a 5xx code it is a failure
        'internal' => new LlmException("cannot reach D:\\secret\\config.php ($secret)", 500),
        'provider' => new LlmException("upstream said $secret", 502),
        'codeless' => new LlmException("no code at all ($secret)", 0),
    );
    foreach ($failures as $kind => $failure) {
        $s = newServices();
        $s->db->fail_on = 'llmConversations';
        $s->db->fail_with = $failure;
        file_put_contents($log_file, '');
        list($status, $data) = request($s, $action, $method, $params);
        $logged = (string)file_get_contents($log_file);
        $json = json_encode($data);

        check("$action, $kind failure: 500 with the generic message", $status === 500 && $data['error'] === LlmErrorReporter::GENERIC_MESSAGE);
        check("$action, $kind failure: nothing of the exception reaches the client",
            strpos($json, $secret) === false && stripos($json, 'config.php') === false && stripos($json, 'sqlstate') === false);
        check("$action, $kind failure: logged with its context" . ($conversation === null ? '' : ' and conversation'),
            strpos($logged, "$context failed") !== false && strpos($logged, get_class($failure)) !== false
            && ($conversation === null || strpos($logged, "(conversation $conversation)") !== false));
        check("$action, $kind failure: " . ($kind === 'driver' ? 'the driver message is not logged (it can quote row values)' : 'the message is logged'),
            $kind === 'driver' ? strpos($logged, $secret) === false : strpos($logged, $secret) !== false);
        check("$action, $kind failure: nothing was written", $s->db->updates === array() && $s->transaction->calls === array());
    }
}

// The edges of "4xx": 400 and 499 are messages for the moderator, 399 and 500 are failures
foreach (array(400 => true, 499 => true, 399 => false, 500 => false) as $code => $is_message) {
    $s = newServices();
    $s->db->fail_on = 'llmConversations';
    $s->db->fail_with = new LlmException("message for code $code", $code);
    list($status, $data) = request($s, 'admin_conversations', 'GET');
    check("an LlmException with code $code is " . ($is_message ? 'passed on with its own status' : 'answered with the generic 500'),
        $is_message
            ? ($status === $code && $data['error'] === "message for code $code")
            : ($status === 500 && $data['error'] === LlmErrorReporter::GENERIC_MESSAGE));
}

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
