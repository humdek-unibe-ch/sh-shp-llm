<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for the import scope of LlmDatasetIngestionService
 * (plugin v1.6.0): "a record can be imported only if the candidate list
 * would show it".
 *
 * The browser names the records to import in a JSON list (source_ids_json)
 * that the endpoint's request-scope check cannot see, so the service has to
 * tie every record to the descriptor's owner itself, in listing and in
 * importing, with one rule. Its statements run against in-memory SQLite
 * tables shaped like the MySQL ones (only the columns the service reads):
 * the real SQL is executed, not pattern-matched.
 *
 * What this pins down:
 *  - importable == listable for every owner and source type, over the whole
 *    id space (an id outside the list cannot be imported by naming it);
 *  - the cross-owner reads the old lenient matcher allowed: a message of a
 *    legacy / script / memory / other-section conversation, a run of another
 *    owner type that shares the owner id, runs of other prompt slots,
 *    builder and evaluation runs, a script that is not the owner's own;
 *  - fail closed: no owner id, an unknown owner type, an unknown memory rule,
 *    a failing lookup or query give nothing, never everything.
 *
 * The service runs in a throw-away tree: the real service files, with a
 * stand-in for the memory rule service (the only collaborator it loads by
 * path) and for the dataset service (case persistence).
 *
 * Run: php tests/LlmDatasetIngestionScopeTest.php
 */

set_error_handler(function ($severity, $message, $file, $line) {
    // A warning is a failure: a failed query (false) must not leak into a foreach or an array access
    throw new ErrorException($message, 0, $severity, $file, $line);
});

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

$error_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_ingestion_scope_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log);

/* =========================================================================
 * Constants: the real values, read from globals.php (which is not loaded: it
 * pulls in per-installation files)
 * ========================================================================= */

$real_server = str_replace('\\', '/', dirname(__DIR__)) . '/server';
$globals = (string)file_get_contents($real_server . '/service/globals.php');
foreach (array(
    'LLM_PROMPT_OWNER_STYLE_FIELD', 'LLM_PROMPT_OWNER_SCRIPT', 'LLM_PROMPT_OWNER_MEMORY_RULE',
    'LLM_PROMPT_RUN_MODE_PLAYGROUND', 'LLM_PROMPT_RUN_MODE_COMPARE',
    'LLM_PROMPT_RUN_MODE_BUILDER', 'LLM_PROMPT_RUN_MODE_DATASET_EVAL',
) as $name) {
    if (!preg_match("/define\\('" . $name . "',\\s*'([^']+)'\\)/", $globals, $m)) {
        echo "FAIL: $name is not defined in globals.php\n";
        exit(1);
    }
    define($name, $m[1]);
}

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_ingestion_scope_test_' . getmypid();
$service_dir = $root . '/server/service';

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
    @unlink($error_log);
});

// The service under test: the real file, or the one named on the command line
// (used to run this test against an older version of the service)
$service_file = isset($argv[1]) ? $argv[1] : $real_server . '/service/LlmDatasetIngestionService.php';
sandbox_write($service_dir . '/LlmDatasetIngestionService.php', file_get_contents($service_file));
foreach (array(
    'service/base/BaseLlmService.php',
    'service/base/LlmLoggingTrait.php',
    'service/cache/LlmCacheManager.php',
    'service/LlmSecretService.php',
    'service/LlmErrorReporter.php',
) as $file) {
    sandbox_write($root . '/server/' . $file, file_get_contents($real_server . '/' . $file));
}

// The one collaborator the service loads by path: rule 9 has the memory key "alpha", rule 10 has
// no keys, rule 12 cannot be read, every other id is unknown
sandbox_write($service_dir . '/LlmMemoryRuleService.php', <<<'PHP'
<?php
class LlmMemoryRuleService
{
    public function __construct($services = null) {}
    public function getRuleById($id)
    {
        $id = (int)$id;
        if ($id === 12) {
            throw new Exception('rule store is down');
        }
        $rules = array(
            9 => array('id' => 9, 'memory_keys' => array('alpha')),
            10 => array('id' => 10, 'memory_keys' => array()),
        );
        return isset($rules[$id]) ? $rules[$id] : null;
    }
}
PHP
);

/* =========================================================================
 * Stand-ins: core database helpers, services container, dataset service
 * ========================================================================= */

/** Core query helpers in miniature; false (not an exception) when a statement fails. */
class SqliteDb
{
    public $pdo;
    /** @var bool Behave like the core helper after a PDOException */
    public $fail = false;
    /** @var int Statements run */
    public $queries = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (array(
            'CREATE TABLE lookups (id INTEGER PRIMARY KEY, type_code TEXT, lookup_code TEXT)',
            'CREATE TABLE llm_prompt_entries (id INTEGER PRIMARY KEY, id_llm_prompt_owner_types INTEGER, owner_id INTEGER, prompt_slot TEXT)',
            'CREATE TABLE llm_prompt_playground_runs (id INTEGER PRIMARY KEY, created_at TEXT, id_llm_prompt_entries INTEGER,
                id_llmConversations INTEGER, id_llmMessages_request INTEGER, id_llmMessages_response INTEGER,
                id_lookups_run_mode INTEGER, variables_json TEXT, config_snapshot_json TEXT)',
            'CREATE TABLE llmConversations (id INTEGER PRIMARY KEY, id_users INTEGER, id_sections INTEGER, id_llm_scripts INTEGER, title TEXT)',
            'CREATE TABLE llmMessages (id INTEGER PRIMARY KEY, id_llmConversations INTEGER, id_dataRows INTEGER,
                timestamp TEXT, role TEXT, content TEXT, sent_context TEXT)',
            'CREATE TABLE llm_scripts (id INTEGER PRIMARY KEY, name TEXT, model TEXT, updated_at TEXT, data_config TEXT,
                test_variables TEXT, temperature REAL, max_tokens INTEGER)',
        ) as $ddl) {
            $this->pdo->exec($ddl);
        }
    }

    public function query_db($sql, $params = array())
    {
        $this->queries++;
        if ($this->fail) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            echo '      (statement failed: ' . $e->getMessage() . ")\n";
            return false;
        }
    }

    public function query_db_first($sql, $params = array())
    {
        $rows = $this->query_db($sql, $params);
        return (is_array($rows) && !empty($rows)) ? $rows[0] : false;
    }

    public function get_lookup_id_by_code($type, $code)
    {
        $this->queries++;
        $stmt = $this->pdo->prepare('SELECT id FROM lookups WHERE type_code = :t AND lookup_code = :c LIMIT 1');
        $stmt->execute(array(':t' => $type, ':c' => $code));
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function get_cache()
    {
        return new stdClass();
    }
}

class FakeServices
{
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function get_db() { return $this->db; }
}

/**
 * Case persistence and the pure helpers the service calls, copied from LlmDatasetService
 * (which would need the whole plugin around it).
 */
class FakeDatasetService
{
    public $cases = array();

    public function createCase($dataset_id, $payload)
    {
        $case = array('id' => count($this->cases) + 1, 'dataset_id' => (int)$dataset_id) + $payload;
        $this->cases[] = $case;
        return $case;
    }

    public function toCaseType($execution_profile)
    {
        if ($execution_profile === 'chat_runtime') return 'chat_case';
        if ($execution_profile === 'form_runtime') return 'form_case';
        if ($execution_profile === 'script_runtime') return 'script_case';
        return 'text_only_case';
    }

    public function buildOwnerDescriptor($descriptor)
    {
        return array('owner_type' => (string)($descriptor['owner_type'] ?? ''), 'owner_id' => (int)($descriptor['owner_id'] ?? 0), 'prompt_slot' => (string)($descriptor['prompt_slot'] ?? ''), 'id_languages' => isset($descriptor['id_languages']) ? (int)$descriptor['id_languages'] : null);
    }

    public function decodeJsonColumn($value, $fallback)
    {
        if (!is_string($value) || trim($value) === '') return $fallback;
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : $fallback;
    }

    public function parseFieldLines($text)
    {
        $parsed = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, ':') === false) continue;
            list($raw_key, $raw_value) = explode(':', $line, 2);
            $key = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$raw_key))), '_');
            $value = trim((string)$raw_value);
            if ($key !== '' && $value !== '') $parsed[$key] = $value;
        }
        return $parsed;
    }

    public function normalizeMessages($messages)
    {
        $normalized = array();
        foreach ((array)$messages as $message) {
            if (!is_array($message)) continue;
            $role = (string)($message['role'] ?? '');
            $content = trim((string)($message['content'] ?? ''));
            if (!in_array($role, array('system', 'user', 'assistant'), true) || $content === '') continue;
            $normalized[] = array('role' => $role, 'content' => $content);
        }
        return $normalized;
    }

    public function extractLastUserMessage($messages)
    {
        foreach (array_reverse($this->normalizeMessages($messages)) as $message) {
            if (($message['role'] ?? '') === 'user') return (string)($message['content'] ?? '');
        }
        return '';
    }
}

require_once $service_dir . '/LlmDatasetIngestionService.php';

/* =========================================================================
 * Data
 * ========================================================================= */

function seed($pdo, $table, array $rows)
{
    foreach ($rows as $row) {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)");
        $stmt->execute(array_values($row));
    }
}

$db = new SqliteDb();
$pdo = $db->pdo;

seed($pdo, 'lookups', array(
    array('id' => 1, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => LLM_PROMPT_OWNER_STYLE_FIELD),
    array('id' => 2, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => LLM_PROMPT_OWNER_SCRIPT),
    array('id' => 3, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => LLM_PROMPT_OWNER_MEMORY_RULE),
    array('id' => 11, 'type_code' => 'llm_prompt_run_modes', 'lookup_code' => LLM_PROMPT_RUN_MODE_PLAYGROUND),
    array('id' => 12, 'type_code' => 'llm_prompt_run_modes', 'lookup_code' => LLM_PROMPT_RUN_MODE_BUILDER),
    array('id' => 13, 'type_code' => 'llm_prompt_run_modes', 'lookup_code' => LLM_PROMPT_RUN_MODE_COMPARE),
    array('id' => 14, 'type_code' => 'llm_prompt_run_modes', 'lookup_code' => LLM_PROMPT_RUN_MODE_DATASET_EVAL),
));

// Prompt entries: who a stored prompt belongs to
seed($pdo, 'llm_prompt_entries', array(
    array('id' => 1, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 100, 'prompt_slot' => 'main'),   // section 100
    array('id' => 2, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 100, 'prompt_slot' => 'other'),  // section 100, another slot
    array('id' => 3, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 200, 'prompt_slot' => 'main'),   // section 200
    array('id' => 4, 'id_llm_prompt_owner_types' => 2, 'owner_id' => 7, 'prompt_slot' => 'script'),   // script 7
    array('id' => 5, 'id_llm_prompt_owner_types' => 2, 'owner_id' => 100, 'prompt_slot' => 'script'), // script 100: same number as section 100
    array('id' => 6, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 9, 'prompt_slot' => 'memory'),   // memory rule 9
    // Rows no consistent database holds (an owner id of 0): they prove that "no owner id" lists and imports nothing
    // by the guard, not by luck
    array('id' => 7, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 0, 'prompt_slot' => 'main'),
));

// Playground runs (entry, mode): 1 playground, 2 builder, 8 compare, 9 dataset evaluation, 10 has no entry, 11 belongs to owner 0
foreach (array(
    1 => array(1, 11), 2 => array(1, 12), 3 => array(2, 11), 4 => array(3, 11), 5 => array(4, 11),
    6 => array(5, 11), 7 => array(6, 11), 8 => array(1, 13), 9 => array(1, 14), 10 => array(null, 11),
    11 => array(7, 11),
) as $id => $run) {
    seed($pdo, 'llm_prompt_playground_runs', array(array(
        'id' => $id, 'created_at' => sprintf('2026-01-%02d 10:00:00', $id), 'id_llm_prompt_entries' => $run[0],
        'id_llmConversations' => 20, 'id_llmMessages_request' => $id === 1 ? 20 : null,
        'id_llmMessages_response' => $id === 1 ? 21 : null, 'id_lookups_run_mode' => $run[1],
        'variables_json' => '{"topic":"sleep"}', 'config_snapshot_json' => '{"model":"m"}',
    )));
}

// Conversations: 1 section 100, 2 section 200, 3 script 7, 4 script 8, 5 neither (legacy), 20 a playground run
// 6 / 7 memory updates (alpha, beta), 8 a title that LIKE '__memory_update__%' would take for a memory update
seed($pdo, 'llmConversations', array(
    array('id' => 1, 'id_users' => 1, 'id_sections' => 100, 'id_llm_scripts' => null, 'title' => 'Chat'),
    array('id' => 2, 'id_users' => 2, 'id_sections' => 200, 'id_llm_scripts' => null, 'title' => 'Chat'),
    array('id' => 3, 'id_users' => 3, 'id_sections' => null, 'id_llm_scripts' => 7, 'title' => 'Script run'),
    array('id' => 4, 'id_users' => 3, 'id_sections' => null, 'id_llm_scripts' => 8, 'title' => 'Script run'),
    array('id' => 5, 'id_users' => 4, 'id_sections' => null, 'id_llm_scripts' => null, 'title' => 'Old chat'),
    array('id' => 6, 'id_users' => 5, 'id_sections' => null, 'id_llm_scripts' => null, 'title' => '__memory_update__alpha'),
    array('id' => 7, 'id_users' => 5, 'id_sections' => null, 'id_llm_scripts' => null, 'title' => '__memory_update__beta'),
    array('id' => 8, 'id_users' => 6, 'id_sections' => null, 'id_llm_scripts' => null, 'title' => 'Xxmemory update!!x'),
    array('id' => 9, 'id_users' => 8, 'id_sections' => 0, 'id_llm_scripts' => null, 'title' => 'Owner id 0 (section)'),
    array('id' => 10, 'id_users' => 8, 'id_sections' => null, 'id_llm_scripts' => 0, 'title' => 'Owner id 0 (script)'),
    array('id' => 20, 'id_users' => 7, 'id_sections' => null, 'id_llm_scripts' => null, 'title' => 'Playground'),
));

$memory_context = json_encode(array(
    array('role' => 'system', 'content' => 'You maintain a memory.'),
    array('role' => 'user', 'content' => "## Scope\nMemory key: `alpha`\nRule: Sleep\n\n## Current Memory\nSlept badly.\n\n## Submitted Data\n```json\n{\"mood\":\"low\"}\n```\n\n## Instructions\nSummarize.\n\n## Reminder\nBe brief."),
));
$messages = array(
    // id, conversation, role, content, data row, sent_context
    array(1, 1, 'user', 'Hello bot', null, null),
    array(2, 1, 'assistant', 'Hi', null, null),
    array(3, 1, 'user', "Name: Ann\nAge: 30", null, null),
    array(4, 1, 'assistant', 'Thanks', null, null),
    array(14, 1, 'user', 'free text of a form', 55, null),
    array(5, 2, 'user', 'A question for section two', null, null),
    array(6, 2, 'assistant', 'An answer for section two', null, null),
    array(15, 2, 'user', 'Mood: low', null, null),
    array(7, 3, 'user', '{"topic":"sleep"}', null, null),
    array(8, 3, 'assistant', 'A script answer', null, null),
    array(9, 4, 'user', 'Input of the other script', null, null),
    array(10, 5, 'user', 'A participant wrote this long ago', null, null),
    array(11, 6, 'assistant', 'Memory of alpha', null, $memory_context),
    array(12, 7, 'assistant', 'Memory of beta', null, $memory_context),
    array(13, 8, 'assistant', 'A look-alike title', null, $memory_context),
    array(16, 1, 'assistant', 'Summary: you slept badly', null, null),
    array(17, 6, 'user', 'A user line inside a memory conversation', null, null),
    array(18, 9, 'user', 'A message of owner id 0 (section)', null, null),
    array(19, 10, 'user', 'A message of owner id 0 (script)', null, null),
    array(20, 20, 'user', 'Hello from the playground', null, null),
    array(21, 20, 'assistant', 'A reply in the playground', null, null),
);
foreach ($messages as $m) {
    seed($pdo, 'llmMessages', array(array(
        'id' => $m[0], 'id_llmConversations' => $m[1], 'id_dataRows' => $m[4],
        'timestamp' => sprintf('2026-02-%02d 10:00:00', $m[0]), 'role' => $m[2], 'content' => $m[3], 'sent_context' => $m[5],
    )));
}

seed($pdo, 'llm_scripts', array(
    array('id' => 0, 'name' => 'Script zero', 'model' => 'm', 'updated_at' => '2026-03-04', 'data_config' => '[]', 'test_variables' => '{}', 'temperature' => 1, 'max_tokens' => 100),
    array('id' => 7, 'name' => 'Script seven', 'model' => 'm', 'updated_at' => '2026-03-01', 'data_config' => '[]', 'test_variables' => '{"a":"b"}', 'temperature' => 1, 'max_tokens' => 100),
    array('id' => 8, 'name' => 'Script eight', 'model' => 'm', 'updated_at' => '2026-03-02', 'data_config' => '[]', 'test_variables' => '{}', 'temperature' => 1, 'max_tokens' => 100),
    array('id' => 100, 'name' => 'Script hundred', 'model' => 'm', 'updated_at' => '2026-03-03', 'data_config' => '[]', 'test_variables' => '{}', 'temperature' => 1, 'max_tokens' => 100),
));

/* =========================================================================
 * Helpers
 * ========================================================================= */

$dataset_service = new FakeDatasetService();
$service = new LlmDatasetIngestionService(new FakeServices($db), $dataset_service);

function descriptor($type, $id, $slot = '')
{
    return array('owner_type' => $type, 'owner_id' => $id, 'prompt_slot' => $slot);
}

/** Ids of the candidates the list shows. */
function listed($type, array $descriptor, $limit = 100)
{
    global $service;
    $ids = array_map('intval', array_column($service->getImportCandidates($type, $limit, array('descriptor' => $descriptor, 'allow_script_source' => true)), 'id'));
    sort($ids);
    return $ids;
}

/** Ids among 1..120 (and one that does not exist) that an import of that single id creates a case for. */
function importable($type, array $descriptor)
{
    global $service;
    $ids = array();
    foreach (array_merge(range(1, 120), array(999)) as $id) {
        try {
            $created = $service->addCasesFromSource(1, $type, array($id), array(
                'descriptor' => $descriptor, 'execution_profile' => 'chat_runtime', 'runtime_overrides' => array(), 'allow_script_source' => true,
            ));
            if (count($created) === 1) {
                $ids[] = $id;
            }
        } catch (Exception $e) {
            // "No cases were imported ..."
        }
    }
    return $ids;
}

/* =========================================================================
 * The truth table: what each owner may list, and exactly that may be imported
 * ========================================================================= */

$sources = array('playground_run', 'form_submission', 'conversation_message', 'script_run');
$owners = array(
    'section 100'               => array(descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100),
        array(1, 3, 8), array(3, 14), array(1, 3, 14), array()),
    'section 100, slot "main"'  => array(descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100, 'main'),
        array(1, 8), array(3, 14), array(1, 3, 14), array()),
    'section 200'               => array(descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 200, 'main'),
        array(4), array(15), array(5, 15), array()),
    'script 7'                  => array(descriptor(LLM_PROMPT_OWNER_SCRIPT, 7, 'script'),
        array(5), array(), array(7), array(7)),
    'script 100'                => array(descriptor(LLM_PROMPT_OWNER_SCRIPT, 100, 'script'),
        array(6), array(), array(), array(100)),
    'memory rule 9 (key alpha)' => array(descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 9),
        array(7), array(), array(11), array()),
    'memory rule 10 (no keys)'  => array(descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 10),
        array(), array(), array(11, 12), array()),
    'unknown memory rule 11'    => array(descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 11),
        array(), array(), array(), array()),
);

foreach ($owners as $label => $row) {
    $descriptor = $row[0];
    foreach ($sources as $i => $type) {
        $expected = $row[$i + 1];
        $shown = listed($type, $descriptor);
        $imported = importable($type, $descriptor);
        check("$label, $type: the list shows " . json_encode($expected), $shown === $expected);
        check("$label, $type: exactly the listed records can be imported (importable == listable)", $imported === $shown);
    }
}

/* =========================================================================
 * Fail closed
 * ========================================================================= */

foreach (array(
    'no owner id' => descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 0, 'main'),
    'no owner id (script)' => descriptor(LLM_PROMPT_OWNER_SCRIPT, 0, 'script'),
    'no owner id (memory rule)' => descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 0),
    'a negative owner id' => descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, -5, 'main'),
    'no owner type' => descriptor('', 100, 'main'),
    'an unknown owner type' => descriptor('made_up_owner', 100, 'main'),
    'an owner type in another letter case' => descriptor('Style_Field ', 100, 'main'),
) as $label => $descriptor) {
    foreach ($sources as $type) {
        check("$label, $type: nothing is listed and nothing can be imported",
            listed($type, $descriptor) === array() && importable($type, $descriptor) === array());
    }
}

// A page in the context does not widen anything: records are tied to their owner, never to a page
$widened = $service->getImportCandidates('conversation_message', 100, array(
    'descriptor' => descriptor('', 100), 'allowed_page_id' => 10, 'allow_script_source' => true,
));
check('an allowed_page_id in the context does not bring a page-wide fallback back', $widened === array());
$created = array();
try {
    $created = $service->addCasesFromSource(1, 'conversation_message', array(1, 5, 10), array(
        'descriptor' => descriptor('', 100), 'allowed_page_id' => 10,
    ));
} catch (Exception $e) {
    // expected: nothing imported
}
check('...nor does it for an import', $created === array());

// The memory lookup that throws gives nothing, and says why in the log
file_put_contents($error_log, '');
check('a memory rule that cannot be read lists nothing', listed('conversation_message', descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 12)) === array());
check('...and imports nothing', importable('conversation_message', descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 12)) === array());
check('...and the failure is logged with its context',
    strpos((string)file_get_contents($error_log), '[LLM] Memory rule lookup for dataset import failed: Exception: rule store is down') !== false);

// A failing database gives nothing: no warning (the error handler would throw), no case
$db->fail = true;
foreach (array(
    array('playground_run', descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100)),
    array('form_submission', descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100)),
    array('conversation_message', descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100)),
    array('conversation_message', descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 10)),
    array('script_run', descriptor(LLM_PROMPT_OWNER_SCRIPT, 7)),
) as $pair) {
    list($type, $descriptor) = $pair;
    $none = null;
    $threw = false;
    try {
        $none = $service->getImportCandidates($type, 10, array('descriptor' => $descriptor, 'allow_script_source' => true));
        $imported = $service->addCasesFromSource(1, $type, array(1, 3, 7, 11), array('descriptor' => $descriptor, 'allow_script_source' => true));
    } catch (Exception $e) {
        $threw = true; // "No cases were imported": the import's own refusal
    }
    check("a failing database, $type: no candidates, no case", $none === array() && $threw);
}
$db->fail = false;

/* =========================================================================
 * The cross-owner reads the old lenient matcher allowed, one by one
 * ========================================================================= */

function can_import($type, array $descriptor, $id)
{
    return in_array($id, importable($type, $descriptor), true);
}

$section = descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100, 'main');
$script = descriptor(LLM_PROMPT_OWNER_SCRIPT, 7, 'script');
$memory = descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 9);

check('a message of a legacy conversation (no section, no script) is refused for a section', !can_import('conversation_message', $section, 10));
check('...for a script', !can_import('conversation_message', $script, 10));
check('...for a memory rule', !can_import('conversation_message', $memory, 10));
check('a message of a script conversation is refused for a section', !can_import('conversation_message', $section, 7));
check('a message of another section is refused for a section', !can_import('conversation_message', $section, 5));
check('...and for a script, whose own conversations are the only ones it may read', !can_import('conversation_message', $script, 5));
check('a message of another script is refused for a script', !can_import('conversation_message', $script, 9));
check('a message of a chat is refused for a memory rule', !can_import('conversation_message', $memory, 1));
check('a memory update of another rule is refused', !can_import('conversation_message', $memory, 12));
check('a conversation whose title only looks like a memory update (LIKE wildcard) is refused',
    !can_import('conversation_message', descriptor(LLM_PROMPT_OWNER_MEMORY_RULE, 10), 13));
check('an assistant message is refused where only user messages are sources', !can_import('conversation_message', $section, 2));
check('...also as a form submission, even when it looks like form data', !can_import('form_submission', $section, 16));
check('a user message is refused where only assistant messages are sources (memory)', !can_import('conversation_message', $memory, 20));
check('...also inside a memory conversation', !can_import('conversation_message', $memory, 17));
check('a message without form data is refused as a form submission', !can_import('form_submission', $section, 1));
check('a form submission of another section is refused', !can_import('form_submission', $section, 15));
check('...also when the message of a script conversation looks like form data', !can_import('form_submission', $section, 7));
check('a script owner has no form submissions', !can_import('form_submission', $script, 3) && importable('form_submission', $script) === array());
check('a run of a script that shares the section\'s number is refused (the owner type counts)', !can_import('playground_run', $section, 6));
check('...and the other way round', !can_import('playground_run', descriptor(LLM_PROMPT_OWNER_SCRIPT, 100, 'script'), 1));
check('a run of another prompt slot is refused', !can_import('playground_run', $section, 3));
check('a prompt-improvement (builder) run is refused', !can_import('playground_run', $section, 2));
check('a dataset-evaluation run is refused', !can_import('playground_run', $section, 9));
check('a run without a prompt entry is refused', !can_import('playground_run', descriptor(LLM_PROMPT_OWNER_STYLE_FIELD, 100), 10));
check('a compare run is a source', can_import('playground_run', $section, 8));
check('a script that is not the owner\'s own is refused', !can_import('script_run', $script, 8));
check('...and so is the script whose number equals the section\'s', !can_import('script_run', $section, 100));
check('a section has no script fixtures at all', importable('script_run', $section) === array());
check('a memory rule has no script fixtures at all', importable('script_run', $memory) === array());

/* =========================================================================
 * What an accepted import carries, and how a list of ids is read
 * ========================================================================= */

$dataset_service->cases = array();
$created = $service->addCasesFromSource(3, 'conversation_message', array(3), array(
    'descriptor' => $section, 'execution_profile' => 'chat_runtime', 'runtime_overrides' => array(),
));
$case = $dataset_service->cases[0];
check('an imported conversation message becomes a case of the dataset', count($created) === 1 && $case['dataset_id'] === 3 && $case['source_type'] === 'conversation_message');
check('...with the message window of its own conversation only',
    array_column($case['input_payload']['message_history'], 'content') === array('Hello bot', 'Hi', "Name: Ann\nAge: 30"));
check('...and the reply that followed it', $case['expected_output'] === array('assistant_text' => 'Thanks'));
check('...tied to the message and conversation it came from',
    $case['source_ref']['id_llmMessages_request'] === 3 && $case['source_ref']['id_llmConversations'] === 1);

$dataset_service->cases = array();
$service->addCasesFromSource(3, 'conversation_message', array(11), array(
    'descriptor' => $memory, 'execution_profile' => 'memory_runtime', 'runtime_overrides' => array(),
));
check('an imported memory update replays its stored prompt', $dataset_service->cases[0]['input_payload']['execution_profile'] === 'memory_runtime'
    && $dataset_service->cases[0]['expected_output'] === array('assistant_text' => 'Memory of alpha')
    && $dataset_service->cases[0]['input_payload']['memory_context']['memory_key'] === 'alpha');

$dataset_service->cases = array();
$created = $service->addCasesFromSource(3, 'conversation_message', array(array('x'), 'abc', null, 0, -1, '3'), array(
    'descriptor' => $section, 'execution_profile' => 'chat_runtime', 'runtime_overrides' => array(),
));
check('in a list of ids only plain positive numbers count (an array is not "1", text is not an id)',
    count($created) === 1 && $dataset_service->cases[0]['source_ref']['id_llmMessages_request'] === 3);

$threw = null;
try {
    $service->addCasesFromSource(3, 'conversation_message', array(array('x'), array('y')), array('descriptor' => $section));
} catch (Exception $e) {
    $threw = $e->getMessage();
}
check('a list of nothing but arrays attempts nothing, so it is not an error', $threw === null);

$threw = null;
try {
    $service->addCasesFromSource(3, 'conversation_message', array(10, 7, 5), array('descriptor' => $section));
} catch (Exception $e) {
    $threw = $e->getMessage();
}
check('a list of records outside the owner is refused as a whole, naming the source',
    $threw !== null && strpos($threw, 'No cases were imported for source "conversation_message"') === 0);

$dataset_service->cases = array();
$created = $service->addCasesFromSource(3, 'conversation_message', array(10, 3, 7, 5), array(
    'descriptor' => $section, 'execution_profile' => 'chat_runtime',
));
check('records outside the owner are skipped, the rest of the list is still imported',
    count($created) === 1 && $dataset_service->cases[0]['source_ref']['id_llmMessages_request'] === 3);

$threw = null;
try {
    $service->addCasesFromSource(3, 'made_up_source', array(1), array('descriptor' => $section));
} catch (Exception $e) {
    $threw = $e->getMessage();
}
check('an unknown source type is refused', $threw === 'Unsupported dataset import source type');

$threw = null;
try {
    $service->getImportCandidates('made_up_source', 10, array('descriptor' => $section));
} catch (Exception $e) {
    $threw = $e->getMessage();
}
check('...in the list too', $threw === 'Unsupported source type for import candidates');

check('script fixtures stay behind the script-module switch',
    $service->getImportCandidates('script_run', 10, array('descriptor' => $script)) === array()
    && listed('script_run', $script) === array(7));
$dataset_service->cases = array();
$created = array();
try {
    $created = $service->addCasesFromSource(3, 'script_run', array(7), array('descriptor' => $script));
} catch (Exception $e) {
    // expected
}
check('...for importing as well', $created === array());

$dataset_service->cases = array();
$created = $service->addCasesFromSource(3, 'script_run', array(7), array('descriptor' => $script, 'allow_script_source' => true));
check('a script owner imports its own script as a fixture', count($created) === 1
    && $dataset_service->cases[0]['source_ref']['id_llm_scripts'] === 7
    && $dataset_service->cases[0]['expected_output'] === array('assistant_text' => 'A script answer'));

// The limit shapes the list, not the scope
check('the list is capped by the limit', count(listed('conversation_message', $section, 2)) === 2);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
