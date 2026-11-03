<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for request traces (plugin v1.7.0): LlmRequestTraceService
 * and the way LlmService logs an assistant reply with its trace.
 *
 *  - a reply is logged with a trace that rebuilds the sent request (verified);
 *  - while llm_trace_store_blobs is on (default off), sent_context / request_payload are
 *    stored too; off, only when the trace could not be verified;
 *  - shared text is stored once for all users, history rows are referenced
 *    (and locked), not copied;
 *  - tracing never fails the call: a broken trace falls back to the blobs;
 *  - params never hold the messages or a top-level system prompt.
 *
 * No bootstrap, no MySQL: the real LlmService, formatter and trace classes
 * run in a throw-away tree with a stand-in BaseLlmService; the database is an
 * in-memory SQLite PDO behind a BaseDb-shaped stand-in, so a rebuild reads
 * real rows.
 *
 * Run: php tests/LlmRequestTraceServiceTest.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "skipped: pdo_sqlite is not available\n";
    exit(0);
}

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

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_request_trace_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_request_trace_test_' . getmypid();
$service_dir = $root . '/server/plugins/sh-shp-llm/server/service';
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

foreach (array(
    'service/LlmService.php',
    'service/LlmErrorReporter.php',
    'service/LlmTransaction.php',
    'service/globals.php',
    'service/LlmModelCapabilities.php',
    'service/LlmApiFormatterService.php',
    'service/LlmFileUtility.php',
    'service/validation/LlmValidator.php',
    'service/exception/LlmException.php',
    'service/exception/LlmValidationException.php',
    'service/exception/LlmRateLimitException.php',
    'service/exception/LlmApiException.php',
    'service/trace/LlmRequestTraceService.php',
    'service/trace/LlmTraceBuilder.php',
    'service/trace/LlmTraceCanonicalizer.php',
    'service/trace/LlmTraceHints.php',
    'service/trace/LlmPromptVersionLinker.php',
    'service/LlmAdminService.php',
    'service/trace/LlmTraceVerifier.php',
) as $file) {
    sandbox_write($root . '/server/plugins/sh-shp-llm/server/' . $file, file_get_contents($real_server . '/' . $file));
}

sandbox_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
<?php
class BaseLlmService
{
    protected $services;
    protected $db;
    protected $cacheManager;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cacheManager = $services->cache;
    }

    protected function getLlmConfig()
    {
        return $this->services->config;
    }

    protected function jsonEncode($data, $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    {
        // Like BaseLlmService::jsonEncode(): null becomes the JSON text 'null'
        return json_encode($data, $flags);
    }

    protected function sanitizePayloadForStorage($payload)
    {
        return $payload;
    }

    protected function logTransaction($operation, $table, $record_id, $user_id, $details = '')
    {
    }

    protected function logError($message, $exception = null)
    {
    }

    protected function logWarning($message, $context = array())
    {
    }

    protected function logDebug($message, $context = array())
    {
    }
}
PHP
);
// Real providers: the admin view rebuilds the provider's wire format from the trace
foreach (array('LlmProviderInterface', 'BaseProvider', 'OpenAIProvider', 'AnthropicProvider', 'GpuStackProvider', 'BfhProvider', 'LlmProviderRegistry') as $provider) {
    sandbox_write($service_dir . '/provider/' . $provider . '.php', file_get_contents($real_server . '/service/provider/' . $provider . '.php'));
}

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

/** ExtendedPdo's nesting: the outermost level is a transaction, inner levels are savepoints. */
class TraceFakeDbh
{
    private $pdo;
    private $depth = 0;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function beginTransaction()
    {
        $this->depth === 0 ? $this->pdo->beginTransaction() : $this->pdo->exec("SAVEPOINT LEVEL{$this->depth}");
        $this->depth++;
        return true;
    }

    public function commit()
    {
        $this->depth--;
        $this->depth === 0 ? $this->pdo->commit() : $this->pdo->exec("RELEASE SAVEPOINT LEVEL{$this->depth}");
        return true;
    }

    public function rollBack()
    {
        $this->depth--;
        $this->depth === 0 ? $this->pdo->rollBack() : $this->pdo->exec("ROLLBACK TO SAVEPOINT LEVEL{$this->depth}");
        return true;
    }

    public function inTransaction()
    {
        return $this->depth > 0;
    }
}

/** BaseDb surface on an in-memory SQLite database. */
class TraceFakeDb
{
    public $pdo;
    public $dbh;
    /** @var string|null throw on the first statement containing this text */
    public $fail_on = null;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE plugins (name TEXT, version TEXT)");
        $this->pdo->exec("INSERT INTO plugins VALUES ('llm', 'v1.7.0')");
        $this->pdo->exec("CREATE TABLE llmConversations (id INTEGER PRIMARY KEY AUTOINCREMENT, id_users INTEGER, updated_at TEXT)");
        $this->pdo->exec("CREATE TABLE llmMessages (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llmConversations INTEGER, role TEXT,
            content TEXT, attachments TEXT, model TEXT, tokens_used INTEGER, prompt_tokens INTEGER, completion_tokens INTEGER,
            raw_response TEXT, sent_context TEXT, reasoning TEXT, is_validated INTEGER, request_payload TEXT,
            trace_locked INTEGER NOT NULL DEFAULT 0, timestamp TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->pdo->exec("CREATE TABLE llmRequestTraces (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llmMessages INTEGER UNIQUE,
            format_version INTEGER, plugin_version TEXT, provider TEXT, params_json TEXT, recipe_json TEXT,
            request_hash TEXT, verified INTEGER NOT NULL DEFAULT 0)");
        $this->pdo->exec("CREATE TABLE llmTraceChunks (id INTEGER PRIMARY KEY AUTOINCREMENT, owner INTEGER NOT NULL DEFAULT 0,
            id_users INTEGER, sha256 TEXT, content TEXT, UNIQUE(owner, sha256))");
        $this->pdo->exec("CREATE TABLE llmTracePromptVersions (id_llmRequestTraces INTEGER, id_llm_prompt_versions INTEGER,
            PRIMARY KEY (id_llmRequestTraces, id_llm_prompt_versions))");
        $this->pdo->exec("CREATE TABLE lookups (id INTEGER PRIMARY KEY, type_code TEXT, lookup_code TEXT)");
        $this->pdo->exec("INSERT INTO lookups VALUES (41, 'llm_prompt_owner_types', 'system')");
        $this->pdo->exec("CREATE TABLE llm_prompt_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llm_prompt_owner_types INTEGER,
            owner_id INTEGER, prompt_slot TEXT, title TEXT, UNIQUE(id_llm_prompt_owner_types, owner_id, prompt_slot))");
        $this->pdo->exec("CREATE TABLE llm_prompt_locales (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llm_prompt_entries INTEGER,
            id_languages INTEGER, active_version_id INTEGER, active_version_no INTEGER NOT NULL DEFAULT 0)");
        $this->pdo->exec("CREATE TABLE llm_prompt_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llm_prompt_locales INTEGER,
            version_no INTEGER, template_raw TEXT, template_hash TEXT, change_note TEXT, based_on_version_id INTEGER)");
        $this->dbh = new TraceFakeDbh($this->pdo);
    }

    public function begin_transaction()
    {
        $this->dbh->beginTransaction();
    }

    public function commit()
    {
        $this->dbh->commit();
    }

    public function get_dbh()
    {
        return $this->dbh;
    }

    private function run($sql, $params)
    {
        if ($this->fail_on !== null && strpos($sql, $this->fail_on) !== false) {
            $this->fail_on = null;
            throw new PDOException('lost connection');
        }
        $stmt = $this->pdo->prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql));
        $stmt->execute($params);
        return $stmt;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $this->run('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')',
            array_combine(array_map(function ($c) { return ':' . $c; }, $cols), array_values($data)));
        return (int)$this->pdo->lastInsertId();
    }

    public function query_db($sql, $params = array())
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function query_db_first($sql, $params = array())
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? false : $row;
    }

    public function execute_update_db($sql, $params = array())
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function update_by_ids($table, $set, $where)
    {
        $params = array();
        $sets = array();
        foreach ($set as $col => $value) {
            $sets[] = "$col = :s_$col";
            $params[":s_$col"] = $value;
        }
        $conds = array();
        foreach ($where as $col => $value) {
            $conds[] = "$col = :w_$col";
            $params[":w_$col"] = $value;
        }
        // Like BaseDb::update_by_ids: the number of changed rows
        return $this->run("UPDATE $table SET " . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conds), $params)->rowCount();
    }

    public function count($table, $where = '1=1')
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();
    }

    public function row($table, $id)
    {
        return $this->pdo->query("SELECT * FROM $table WHERE id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
    }
}

class TraceFakeCache
{
    public function clearConversationMessageCache($conversation_id)
    {
    }
}

class TraceFakeServices
{
    public $db;
    public $cache;
    public $config = array('llm_base_url' => 'https://api.example.org/v1');

    public function __construct()
    {
        $this->db = new TraceFakeDb();
        $this->cache = new TraceFakeCache();
    }

    public function get_db()
    {
        return $this->db;
    }
}

require_once $service_dir . '/globals.php';
require_once $service_dir . '/LlmService.php';
require_once $service_dir . '/trace/LlmRequestTraceService.php';
require_once $service_dir . '/LlmAdminService.php';

/** Exposes the protected logging step of callLlmApi(). */
class TraceTestLlmService extends LlmService
{
    public function logWithTrace($log_options, $content, $sent_context, $normalized, $messages, $provider_id, $payload)
    {
        return $this->logAssistantWithTrace($log_options, $content, 'test-model', $sent_context, $normalized, true,
            $messages, $provider_id, $payload, $this->getLlmConfig());
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
    fwrite(STDOUT, ($condition ? "ok:   " : "FAIL: ") . $label . "\n");
    if (!$condition) {
        $failed++;
    }
}

$USER = 7;
$SCHEMA = "Answer as JSON.\n{\n  \"type\": \"object\"\n}";

/**
 * One chat turn: the user message is stored, the history is sent with the
 * shared schema block, the reply is logged with its trace.
 *
 * @return array{0:int, 1:array, 2:array} message id, sent messages, payload
 */
function turn($service, $services, $conversation_id, $user_text, $provider = 'openai')
{
    $service->addMessage($conversation_id, 'user', $user_text);
    $rows = $services->db->query_db('SELECT id, role, content, attachments FROM llmMessages WHERE id_llmConversations = :c
        AND is_validated = 1 ORDER BY id', array(':c' => $conversation_id));
    LlmTraceHints::share($GLOBALS['SCHEMA']);
    $messages = array(array('role' => 'system', 'content' => $GLOBALS['SCHEMA'] . "\n\nName: Ana"));
    foreach ($rows as $row) {
        $messages[] = array('role' => $row['role'], 'content' => $row['content']);
    }
    // What the provider receives (callLlmApi adapts the messages the same way)
    $payload = (new LlmAdminService($services))->toWirePayload($provider,
        array('model' => 'test-model', 'temperature' => 0.7, 'max_tokens' => 100, 'stream' => false), $messages);
    $normalized = array('content' => 'Reply to ' . $user_text, 'usage' => array(), 'raw_response' => array('id' => 'r'), 'request_payload' => $payload);
    $id = $service->logWithTrace(array('conversation_id' => $conversation_id, 'trace_rows' => $rows),
        'Reply to ' . $user_text, array(array('role' => 'system', 'content' => 'ctx')), $normalized, $messages, $provider, $payload);
    return array($id, $messages, $payload);
}

function rebuild($services, $message_id)
{
    require_once $GLOBALS['service_dir'] . '/LlmApiFormatterService.php';
    $formatter = new LlmApiFormatterService($services, 'test-model');
    $all = (new LlmRequestTraceService($services->db))->rebuildMany(array($message_id), function ($row) use ($formatter) {
        return $formatter->formatStoredMessage($row, false);
    });
    return $all[$message_id] ?? null;
}

/* =========================================================================
 * Dual write (setting on)
 * ========================================================================= */
$services = new TraceFakeServices();
$service = new TraceTestLlmService($services);
$services->config['llm_trace_store_blobs'] = '1';
$services->db->pdo->exec("INSERT INTO llmConversations (id, id_users) VALUES (1, $USER)");

list($m1, $sent1, $payload1) = turn($service, $services, 1, 'Hello');
$row = $services->db->row('llmMessages', $m1);
$trace = $services->db->pdo->query("SELECT * FROM llmRequestTraces WHERE id_llmMessages = $m1")->fetch(PDO::FETCH_ASSOC);
check('dual_write_keeps_blobs_and_trace: blobs stored', $row['sent_context'] !== null && $row['request_payload'] !== null);
check('dual_write_keeps_blobs_and_trace: a verified trace is stored', $trace && (int)$trace['verified'] === 1
    && $trace['provider'] === 'openai' && $trace['plugin_version'] === 'v1.7.0' && (int)$trace['format_version'] === LLM_TRACE_FORMAT_VERSION);
$rebuilt = rebuild($services, $m1);
check('the rebuild equals the sent messages and verifies', $rebuilt && $rebuilt['messages'] === $sent1 && $rebuilt['verified'] === true);
check('params_without_messages', $rebuilt && !isset($rebuilt['params']['messages']) && $rebuilt['params']['temperature'] === 0.7);

/* =========================================================================
 * Trace only
 * ========================================================================= */
$services->config['llm_trace_store_blobs'] = '0';
// What a crash right before the trace insert would leave: the reply row as it is then
$services->db->pdo->exec("CREATE TABLE seen_at_trace (id_llmMessages INTEGER, has_blobs INTEGER)");
$services->db->pdo->exec("CREATE TRIGGER blobs_before_trace BEFORE INSERT ON llmRequestTraces BEGIN
    INSERT INTO seen_at_trace SELECT id, request_payload IS NOT NULL AND sent_context IS NOT NULL FROM llmMessages WHERE id = NEW.id_llmMessages; END");
list($m2, $sent2) = turn($service, $services, 1, 'How are you?');
$services->db->pdo->exec("DROP TRIGGER blobs_before_trace");
check('crash-safe order: the reply holds its blobs until its trace is written and verified',
    (int)$services->db->pdo->query("SELECT has_blobs FROM seen_at_trace WHERE id_llmMessages = $m2")->fetchColumn() === 1);
$row = $services->db->row('llmMessages', $m2);
check('trace_only_writes_null_blobs', $row['sent_context'] === null && $row['request_payload'] === null
    && strpos((string)$row['raw_response'], 'messages') === false);
$rebuilt = rebuild($services, $m2);
check('trace only: the rebuild still equals the sent messages', $rebuilt && $rebuilt['messages'] === $sent2 && $rebuilt['verified']);

$recipe = json_decode($services->db->pdo->query("SELECT recipe_json FROM llmRequestTraces WHERE id_llmMessages = $m2")->fetchColumn(), true);
$refs = array_values(array_filter(array_map(function ($e) { return $e['m'] ?? null; }, $recipe)));
check('history rows are referenced, not copied', count($refs) === 3);
check('refs_get_trace_locked', $services->db->count('llmMessages', 'trace_locked = 1 AND id IN (' . implode(',', $refs) . ')') === 3
    && (int)$services->db->row('llmMessages', $m2)['trace_locked'] === 0);
check('the shared schema block is stored once for both turns', $services->db->count('llmTraceChunks', 'owner = 0') === 1);
check('the participant text next to it is a per-user chunk', $services->db->count('llmTraceChunks', "owner = $USER AND id_users = $USER AND content LIKE '%Ana%'") === 1);

// The sent schema asset is linked to its (first-use) system prompt version
LlmPromptVersionLinker::noteAsset('core.response.schema.instruction', $SCHEMA);
LlmPromptVersionLinker::noteAsset('core.floating_mode.system', 'Not sent this time.');
list($m5) = turn($service, $services, 1, 'Fifth');
$links = $services->db->query_db("SELECT v.template_raw FROM llmTracePromptVersions l
    JOIN llmRequestTraces t ON t.id = l.id_llmRequestTraces JOIN llm_prompt_versions v ON v.id = l.id_llm_prompt_versions
    WHERE t.id_llmMessages = :m", array(':m' => $m5));
check('the trace links the prompt versions that were sent', array_column($links, 'template_raw') === array($SCHEMA));

/* =========================================================================
 * Fallbacks: never fail the call, never lose the request
 * ========================================================================= */
$services->db->fail_on = 'INSERT INTO llmRequestTraces';
list($m3) = turn($service, $services, 1, 'Third');
$row = $services->db->row('llmMessages', $m3);
check('record_exception_falls_back_and_request_succeeds', $m3 > 0 && $row['sent_context'] !== null && $row['request_payload'] !== null);
check('...and no trace is left for it', $services->db->count('llmRequestTraces', "id_llmMessages = $m3") === 0);
// The trace insert fails after the chunks were written: its savepoint takes them back
$services->db->fail_on = 'INSERT INTO llmRequestTraces';
$half = array(array('role' => 'system', 'content' => 'Half written context for Ana'));
$m_half = $service->logWithTrace(array('conversation_id' => 1), 'Reply', $half,
    array('content' => 'Reply', 'usage' => array(), 'raw_response' => array('id' => 'r'), 'request_payload' => array('messages' => $half)),
    $half, 'openai', array('model' => 'test-model', 'messages' => $half));
check('a failed trace leaves no partial chunks (its savepoint is rolled back)',
    $services->db->count('llmTraceChunks', "content LIKE 'Half written%'") === 0);
check('...while the reply is kept with its blobs', $m_half > 0 && $services->db->row('llmMessages', $m_half)['sent_context'] !== null);

// message_and_trace_in_one_transaction: inside a caller's transaction the two are
// written together, and the caller's rollback leaves neither
$before_messages = $services->db->count('llmMessages');
$before_traces = $services->db->count('llmRequestTraces');
LlmTransaction::begin($services->db);
list($m_outer) = turn($service, $services, 1, 'Inside a caller transaction');
check('message_and_trace_in_one_transaction: both written inside the caller transaction',
    $services->db->count('llmRequestTraces', "id_llmMessages = $m_outer") === 1);
LlmTransaction::rollback($services->db);
check('message_and_trace_in_one_transaction: the caller rollback leaves neither',
    $services->db->count('llmMessages') === $before_messages && $services->db->count('llmRequestTraces') === $before_traces);
check('...and it is logged as a trace fallback', strpos((string)@file_get_contents($log_file), 'trace_fallback') !== false);

// The stored history changes between sending and the self-check: the rebuild
// no longer matches what was sent (the case the self-check exists for)
$services->db->pdo->exec("CREATE TRIGGER tamper AFTER INSERT ON llmRequestTraces BEGIN
    UPDATE llmMessages SET content = content || ' (edited)' WHERE content = 'Fourth'; END");
list($m4) = turn($service, $services, 1, 'Fourth');
$services->db->pdo->exec("DROP TRIGGER tamper");
$row = $services->db->row('llmMessages', $m4);
$trace = $services->db->pdo->query("SELECT verified FROM llmRequestTraces WHERE id_llmMessages = $m4")->fetchColumn();
check('self_check_mismatch_falls_back_to_blobs', $row['sent_context'] !== null && $row['request_payload'] !== null && (string)$trace === '0');

$services->db->pdo->exec("INSERT INTO llmConversations (id, id_users) VALUES (2, 0)");
$result = (new LlmRequestTraceService($services->db))->record(999, 2, 'openai', array(), array(), array(), function ($r) { return null; });
check('a conversation without owner is not traced', $result['verified'] === false && $result['fallback'] !== null);

/* =========================================================================
 * Verifier (server/scripts/llm_verify_traces.php)
 * ========================================================================= */
// m1 dual write (verified, equals its blob), m2 + m5 trace only (verified),
// m3 no trace (the trace insert failed), m4 history edited after sending
require_once $service_dir . '/trace/LlmTraceVerifier.php';
$verifier_formatter = new LlmApiFormatterService($services, 'test-model');
$format = function ($row) use ($verifier_formatter) {
    return $verifier_formatter->formatStoredMessage($row, false);
};
$to_wire = array(new LlmAdminService($services), 'toWirePayload');
// A canned assistant row (auto-start greeting): no model call, so no raw_response and no trace
$canned = $service->addMessage(1, 'assistant', 'Welcome! How can I help?', null, 'test-model', null, null, array(array('role' => 'system', 'content' => 'ctx')));
$report = (new LlmTraceVerifier($services->db, $format, $to_wire))->run(array());
check('the verifier does not count canned assistant rows (auto-start) as untraced', !in_array((int)$canned, $report['untraced'], true));
check('the verifier rebuilds every trace', $report['checked'] === 4 && $report['verified'] === 3);
check('the verifier reports the edited history as a hash mismatch', $report['hash_mismatch'] === array($m4));
check('the verifier compares dual-write blobs with the rebuild', $report['blob_mismatch'] === array($m4));
check('the verifier reports traces that failed their write-time self-check', $report['write_unverified'] === array($m4));
check('the verifier reports assistant replies without a trace', $report['untraced'] === array($m3, $m_half));
check('the verifier is clean only without any finding', LlmTraceVerifier::isClean($report) === false
    && LlmTraceVerifier::isClean(array('checked' => 1, 'verified' => 1, 'hash_mismatch' => array(), 'blob_mismatch' => array(),
        'write_unverified' => array(), 'untraced' => array())) === true);
$one = (new LlmTraceVerifier($services->db, $format, $to_wire))->run(array('conversation' => 2));
check('the verifier can be limited to one conversation', $one['checked'] === 0 && $one['untraced'] === array());

/* =========================================================================
 * Readers: the admin console gets blobs or the rebuild
 * ========================================================================= */
$rebuilt = rebuild($services, $m2);
check('the rebuild separates the context from referenced history', $rebuilt
    && array_column($rebuilt['context'], 'content') === array($SCHEMA . "\n\nName: Ana"));

require_once $service_dir . '/LlmAdminService.php';
$admin = new LlmAdminService($services);
$by_id = array_column($admin->getAdminMessages(1, 100, true), null, 'id');
check('admin_uses_blob_when_present', $by_id[$m1]['trace_status'] === 'blob'
    && $by_id[$m1]['sent_context'] === $services->db->row('llmMessages', $m1)['sent_context'] && $by_id[$m1]['trace_verified'] === true);
check('a row with blobs shows its write-time self-check without a rebuild (m4 failed it)',
    $by_id[$m4]['trace_status'] === 'blob' && $by_id[$m4]['trace_verified'] === false);
check('a row with blobs and no trace says so', $by_id[$m3]['trace_status'] === 'blob' && $by_id[$m3]['trace_verified'] === null);
$payload_view = json_decode((string)$by_id[$m2]['request_payload'], true);
check('admin_rebuilds_when_blob_null', $by_id[$m2]['trace_status'] === 'trace' && $by_id[$m2]['trace_verified'] === true
    && json_decode((string)$by_id[$m2]['sent_context'], true) === $rebuilt['context']
    && $payload_view['messages'] === LlmModelCapabilities::convertMessagesForModel($sent2, 'test-model') && $payload_view['temperature'] === 0.7);
$user_row = array_values(array_filter($by_id, function ($r) { return $r['role'] === 'user'; }))[0];
check('a message without trace and blobs says so', $user_row['trace_status'] === 'none' && $user_row['trace_verified'] === null);
// Postman: the rebuilt payload is the provider's wire format, like a stored one
list($m6) = turn($service, $services, 1, 'Anthropic turn', 'anthropic');
$by_id = array_column($admin->getAdminMessages(1, 100, true), null, 'id');
$wire = json_decode((string)$by_id[$m6]['request_payload'], true);
$roles = array_column($wire['messages'] ?? array(), 'role');
check('rebuilt Anthropic payload lifts the system prompt to the top level (as sent)',
    $by_id[$m6]['trace_status'] === 'trace' && ($wire['system'] ?? null) === $SCHEMA . "\n\nName: Ana"
    && !in_array('system', $roles, true) && $wire['model'] === 'test-model' && $wire['temperature'] === 0.7);
check('rebuilt payload keeps the order of a written request (model, system, messages)',
    array_slice(array_keys($wire), 0, 3) === array('model', 'system', 'messages'));

$plain = array_column($admin->getAdminMessages(1, 100, false), null, 'id');
check('without debug access nothing is rebuilt or added', !isset($plain[$m2]['sent_context']) && !isset($plain[$m2]['trace_status']));

/* =========================================================================
 * Immutability of referenced rows
 * ========================================================================= */
$services->db->pdo->exec("INSERT INTO llmConversations (id, id_users) VALUES (3, $USER)");
$fresh = $service->addMessage(3, 'assistant', 'Raw coach JSON');
$service->updateMessage($fresh, array('content' => 'Coach text', 'is_validated' => 1));
check('coach_text_rewrite_before_reference_allowed', $services->db->row('llmMessages', $fresh)['content'] === 'Coach text');

$locked = $refs[0];
$before = $services->db->row('llmMessages', $locked);
$refused = null;
try {
    $service->updateMessage($locked, array('content' => 'Rewritten history'));
} catch (LlmException $e) {
    $refused = $e;
}
check('rewrite_after_reference_rejected (409)', $refused !== null && $refused->getCode() === 409
    && $services->db->row('llmMessages', $locked)['content'] === $before['content']);
$refused = null;
try {
    $service->updateMessage($locked, array('attachments' => '[]'));
} catch (LlmException $e) {
    $refused = $e;
}
check('attachments of a referenced row are immutable too', $refused !== null);
$service->updateMessage($locked, array('is_validated' => 0));
check('other columns of a referenced row can still change', (int)$services->db->row('llmMessages', $locked)['is_validated'] === 0);

/* =========================================================================
 * Deletion: everything personal goes with the user
 * ========================================================================= */
$sql = file_get_contents($real_server . '/db/v1.7.0.sql');
check('user_cleanup_leaves_no_user_chunks: per-user chunks cascade with the user',
    preg_match('/`fk_llmTraceChunks_user` FOREIGN KEY \(`id_users`\) REFERENCES `users` \(`id`\) ON DELETE CASCADE/', $sql) === 1);
check('traces cascade with their message (messages cascade with conversation and user)',
    preg_match('/`fk_llmRequestTraces_message` FOREIGN KEY \(`id_llmMessages`\) REFERENCES `llmMessages` \(`id`\) ON DELETE CASCADE/', $sql) === 1);
check('prompt versions linked by a trace are never deleted with it',
    preg_match('/`fk_llmTracePromptVersions_version` FOREIGN KEY \(`id_llm_prompt_versions`\) REFERENCES `llm_prompt_versions` \(`id`\) ON DELETE RESTRICT/', $sql) === 1);

/* =========================================================================
 * Params
 * ========================================================================= */
check('anthropic_params_have_no_system', LlmRequestTraceService::paramsOf(array('model' => 'c', 'system' => 'secret prompt', 'messages' => array(), 'max_tokens' => 5))
    === array('model' => 'c', 'max_tokens' => 5));
check('the setting is off unless it is exactly 1', !LlmRequestTraceService::storeBlobsEnabled(array())
    && LlmRequestTraceService::storeBlobsEnabled(array('llm_trace_store_blobs' => '1'))
    && !LlmRequestTraceService::storeBlobsEnabled(array('llm_trace_store_blobs' => '0'))
    && !LlmRequestTraceService::storeBlobsEnabled(array('llm_trace_store_blobs' => '')));

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
