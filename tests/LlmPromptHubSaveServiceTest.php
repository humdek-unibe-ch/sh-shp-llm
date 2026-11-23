<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for LlmPromptHubSaveService (plugin v1.10.0), the write side of the prompts hub.
 *
 * What runs for real: the service itself, LlmPromptCatalogService (ACL, editable, content hash,
 * versions), LlmTransaction and the SQL of both, against in-memory SQLite tables shaped like the
 * MySQL ones. The SQLite handle behaves like the core's ExtendedPdo for transactions (savepoints
 * for nested ones, real rollbacks), so "nothing was written" means the rows are really back.
 * Stand-ins: the registry (it writes the same tables: entry, locale, version), the execution
 * profile, the script service (it nests its own LlmTransaction and answers false on failure, like
 * LlmScriptService::update_script), the memory rule service and the field history. The MySQL
 * dialect (FOR UPDATE, the real registry) is covered by the browser runs on a real instance.
 *
 * Run: php tests/LlmPromptHubSaveServiceTest.php
 */

require_once __DIR__ . '/../server/service/globals.php';

// Core constants the plugin code reads
foreach (array('UPDATE' => 'update', 'RELATION_SECTION_FIELD' => 'section_field') as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

// LlmTransaction logs dropped after-commit tasks here
$error_log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_hub_save_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log_file);
register_shutdown_function(function () use ($error_log_file) {
    @unlink($error_log_file);
});

require_once __DIR__ . '/../server/service/LlmPromptHubSaveService.php';

// A warning is a failure here: a failed lookup (false) must not leak into a foreach or an array access
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$_SESSION = array('id_user' => 7);

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
 * Database stand-ins
 * ========================================================================= */

/** What the core's ExtendedPdo does for transactions: the first level is real, deeper ones are savepoints */
class FakeDbh
{
    public $depth = 0;
    private $pdo;
    private $db;

    public function __construct($pdo, $db)
    {
        $this->pdo = $pdo;
        $this->db = $db;
    }

    public function beginTransaction()
    {
        if ($this->depth === 0) {
            $this->pdo->exec('BEGIN');
            $this->db->events[] = 'BEGIN';
            $this->db->log[] = '-- BEGIN';
        } else {
            $this->pdo->exec('SAVEPOINT LEVEL' . $this->depth);
            $this->db->events[] = 'SAVEPOINT';
        }
        $this->depth++;
        return true;
    }

    public function commit()
    {
        if ($this->db->fail_commit) {
            throw new PDOException('SQLSTATE[40001]: commit failed near participant answer');
        }
        $this->depth--;
        if ($this->depth === 0) {
            $this->pdo->exec('COMMIT');
            $this->db->events[] = 'COMMIT';
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT LEVEL' . $this->depth);
            $this->db->events[] = 'RELEASE';
        }
        return true;
    }

    public function rollBack()
    {
        if ($this->depth === 0) {
            throw new PDOException('Rollback error: There is no transaction started');
        }
        $this->depth--;
        if ($this->depth === 0) {
            $this->pdo->exec('ROLLBACK');
            $this->db->events[] = 'ROLLBACK';
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT LEVEL' . $this->depth);
            $this->db->events[] = 'ROLLBACK TO';
        }
        return true;
    }

    public function inTransaction()
    {
        return $this->depth > 0;
    }
}

class FakeCache
{
    const CACHE_TYPE_SECTIONS = 'CACHE_SECTIONS';

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function clear_cache($type = null, $id = null)
    {
        $this->db->events[] = 'CLEAR ' . ($type === null ? '(all)' : $type);
    }
}

/**
 * Core query helpers in miniature (rows as associative arrays, "first row or false", false instead
 * of an exception), plus the transaction methods and the upsert of insert().
 */
class SaveDb
{
    const SCHEMA = array(
        'CREATE TABLE pages (id INTEGER PRIMARY KEY, keyword TEXT)',
        'CREATE TABLE pages_sections (id_pages INTEGER, id_sections INTEGER)',
        'CREATE TABLE sections_navigation (parent INTEGER, child INTEGER, id_pages INTEGER)',
        'CREATE TABLE sections_hierarchy (parent INTEGER, child INTEGER)',
        'CREATE TABLE sections (id INTEGER PRIMARY KEY, name TEXT, id_styles INTEGER)',
        'CREATE TABLE styles (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE fieldType (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE fields (id INTEGER PRIMARY KEY, name TEXT, id_type INTEGER, display INTEGER DEFAULT 1)',
        'CREATE TABLE styles_fields (id_styles INTEGER, id_fields INTEGER)',
        'CREATE TABLE languages (id INTEGER PRIMARY KEY, locale TEXT)',
        'CREATE TABLE sections_fields_translation (id_sections INTEGER, id_fields INTEGER, id_languages INTEGER, id_genders INTEGER, content TEXT, meta TEXT)',
        'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE lookups (id INTEGER PRIMARY KEY, type_code TEXT, lookup_code TEXT)',
        'CREATE TABLE llm_prompt_entries (id INTEGER PRIMARY KEY, id_llm_prompt_owner_types INTEGER, owner_id INTEGER, prompt_slot TEXT, title TEXT)',
        'CREATE TABLE llm_prompt_locales (id INTEGER PRIMARY KEY, id_llm_prompt_entries INTEGER, id_languages INTEGER, active_version_id INTEGER, active_version_no INTEGER DEFAULT 0)',
        'CREATE TABLE llm_prompt_versions (id INTEGER PRIMARY KEY, id_llm_prompt_locales INTEGER, version_no INTEGER, template_raw TEXT, change_note TEXT, id_users_created INTEGER, created_at TEXT)',
        'CREATE TABLE llm_scripts (id INTEGER PRIMARY KEY, name TEXT, script TEXT, test_variables TEXT, async INTEGER, data_config TEXT, model TEXT, temperature TEXT, max_tokens TEXT, refresh_sections TEXT, combine_images INTEGER, updated_at TEXT)',
        'CREATE TABLE llm_memory_rules (id INTEGER PRIMARY KEY, label TEXT, enabled INTEGER, updated_at TEXT)',
        'CREATE TABLE plugins (id INTEGER PRIMARY KEY, name TEXT, version TEXT)',
        'CREATE TABLE pages_fields_translation (id_pages INTEGER, id_fields INTEGER, id_languages INTEGER, content TEXT)',
    );

    public $pdo;
    public $dbh;
    public $cache;
    /** @var string[] Transaction and cache events, in order */
    public $events = array();
    /** @var string[] Every statement sent */
    public $log = array();
    /** @var callable|null Runs right before a locking read executes: "someone commits while we wait" */
    public $on_lock = null;
    /** @var string ok | fail (answers false) | swallow (answers fine, stores nothing) */
    public $insert_mode = 'ok';
    /** @var bool */
    public $fail_commit = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (self::SCHEMA as $ddl) {
            $this->pdo->exec($ddl);
        }
        $this->dbh = new FakeDbh($this->pdo, $this);
        $this->cache = new FakeCache($this);
    }

    public function query_db($sql, $params = array())
    {
        $this->log[] = $sql;
        if (preg_match('/\sFOR UPDATE\s*$/i', $sql)) {
            $this->events[] = 'LOCK';
            if ($this->on_lock !== null) {
                $callback = $this->on_lock;
                $this->on_lock = null;
                $callback($this);
            }
            $sql = preg_replace('/\sFOR UPDATE\s*$/i', '', $sql);
        }
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
        $row = $this->query_db_first('SELECT id FROM pages WHERE keyword = :keyword', array(':keyword' => $keyword));
        return $row ? (int)$row['id'] : false;
    }

    /** Core insert($table, $insert, $update): an upsert on the primary key of sections_fields_translation */
    public function insert($table, $insert, $update = array())
    {
        $this->log[] = 'INSERT ' . $table;
        if ($this->insert_mode === 'fail') {
            return false;
        }
        if ($this->insert_mode === 'swallow') {
            return 0;
        }
        $keys = array('id_sections', 'id_fields', 'id_languages', 'id_genders');
        $where = implode(' AND ', array_map(function ($k) {
            return "$k = :k_$k";
        }, $keys));
        $params = array();
        foreach ($keys as $k) {
            $params[":k_$k"] = $insert[$k];
        }
        $sets = array();
        $set_params = array();
        foreach ($update as $column => $value) {
            $sets[] = "$column = :s_$column";
            $set_params[":s_$column"] = $value;
        }
        $stmt = $this->pdo->prepare("UPDATE $table SET " . implode(', ', $sets) . " WHERE $where");
        $stmt->execute(array_merge($params, $set_params));
        if ($stmt->rowCount() === 0) {
            $columns = implode(', ', array_keys($insert));
            $marks = implode(', ', array_fill(0, count($insert), '?'));
            $stmt = $this->pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)");
            $stmt->execute(array_values($insert));
        }
        return 1;
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

    public function get_cache()
    {
        return $this->cache;
    }

    public function clear_cache($type = null, $id = null)
    {
        $this->cache->clear_cache($type, $id);
    }

    /** Test helper: one value */
    public function value($sql, $params = array())
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** Test helper: one row */
    public function row($sql, $params = array())
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

class FakeAcl
{
    public $select;
    public $update;

    public function __construct(array $select, array $update)
    {
        $this->select = $select;
        $this->update = $update;
    }

    public function has_access_select($id, $id_page, $is_group = false)
    {
        return in_array((int)$id_page, $this->select, true);
    }

    public function has_access_update($id, $id_page, $is_group = false)
    {
        return in_array((int)$id_page, $this->update, true);
    }
}

class FakeRouter
{
    public function get_link_url($key, $params = array())
    {
        return '/admin/' . $key;
    }

    public function generate($name, $params = array())
    {
        return '/cms/' . $name;
    }
}

class FakeUserInput
{
    public function is_new_ui_enabled()
    {
        return false;
    }
}

/** The CMS field history: records what the hub hands it */
class FakeFieldVersions
{
    public $versioned = true;
    public $fail = false;
    public $calls = array();

    public function is_versioned($id_fields)
    {
        return $this->versioned;
    }

    public function snapshot($relation, $owner_id, $id_fields, $id_languages, $content, $meta = null, $restored_from = null, $id_users = false)
    {
        $this->calls[] = array($relation, $owner_id, $id_fields, $id_languages, $content, $meta);
        return $this->fail ? false : 1;
    }
}

class FakeServices
{
    public $db;
    public $acl;
    public $field_versions;
    private $router;
    private $user_input;

    public function __construct($db, $acl)
    {
        $this->db = $db;
        $this->acl = $acl;
        $this->field_versions = new FakeFieldVersions();
        $this->router = new FakeRouter();
        $this->user_input = new FakeUserInput();
    }

    public function get_db() { return $this->db; }
    public function get_acl() { return $this->acl; }
    public function get_router() { return $this->router; }
    public function get_user_input() { return $this->user_input; }
    public function get_field_versions() { return $this->field_versions; }
}

class StubAssetLoader
{
    public function load($key)
    {
        return 'Stub text of ' . $key;
    }
}

/** The real catalog, except that it can stop finding a prompt from the n-th read on */
class VanishingCatalog extends LlmPromptCatalogService
{
    public $calls = 0;
    /** @var int 0 = never; 1 = from the first getPrompt() on; 2 = from the second (the read under the lock) on */
    public $null_from = 0;

    public function getPrompt($key, $user_id)
    {
        $this->calls++;
        if ($this->null_from > 0 && $this->calls >= $this->null_from) {
            return null;
        }
        return parent::getPrompt($key, $user_id);
    }
}

/* =========================================================================
 * Service stand-ins
 * ========================================================================= */

/** The prompt registry: writes entry, locale and version rows like the real one */
class StubRegistry
{
    public $calls = array();
    /** @var string ok | throw_before | throw_after (the version row is written, then it fails) */
    public $mode = 'ok';
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function syncFieldSave($descriptor, $field_id, $content, $meta_json = null, $runtime_values = array())
    {
        $this->calls[] = array(
            'descriptor' => $descriptor, 'field_id' => $field_id, 'content' => $content,
            'meta_json' => $meta_json, 'runtime_values' => $runtime_values,
        );
        if ($this->mode === 'throw_before') {
            throw new RuntimeException('registry down: the participant said hello');
        }
        $sync = $this->snapshot($descriptor, $content, $meta_json);
        if ($this->mode === 'throw_after') {
            throw new RuntimeException('registry died after writing the version');
        }

        $meta = json_decode((string)$meta_json, true);
        $meta = is_array($meta) ? $meta : array();
        $prompt = isset($meta['prompt']) && is_array($meta['prompt']) ? $meta['prompt'] : array();
        unset($prompt['pendingChangeNote']);
        $prompt['entryId'] = (int)$sync['entry']['id'];
        $prompt['localeId'] = (int)$sync['locale']['id'];
        $prompt['activeVersionId'] = (int)$sync['locale']['active_version_id'];
        $prompt['activeVersionNo'] = (int)$sync['locale']['active_version_no'];
        $meta['prompt'] = $prompt;

        return array('sync' => $sync, 'meta_json' => json_encode($meta));
    }

    /** What syncPromptSave() does: a new version unless the text is the active one */
    public function snapshot(array $descriptor, $content, $meta_json)
    {
        $db = $this->db;
        $type_id = $db->value(
            "SELECT id FROM lookups WHERE type_code = 'llm_prompt_owner_types' AND lookup_code = ?",
            array($descriptor['owner_type'])
        );
        $entry = $db->row(
            'SELECT * FROM llm_prompt_entries WHERE id_llm_prompt_owner_types = ? AND owner_id = ? AND prompt_slot = ?',
            array($type_id, $descriptor['owner_id'], $descriptor['prompt_slot'])
        );
        if (!$entry) {
            $db->pdo->prepare('INSERT INTO llm_prompt_entries (id_llm_prompt_owner_types, owner_id, prompt_slot, title) VALUES (?, ?, ?, ?)')
                ->execute(array($type_id, $descriptor['owner_id'], $descriptor['prompt_slot'], $descriptor['title']));
            $entry = $db->row('SELECT * FROM llm_prompt_entries WHERE id = ?', array($db->pdo->lastInsertId()));
        }
        $locale = $db->row(
            'SELECT * FROM llm_prompt_locales WHERE id_llm_prompt_entries = ? AND id_languages = ?',
            array($entry['id'], $descriptor['id_languages'])
        );
        if (!$locale) {
            $db->pdo->prepare('INSERT INTO llm_prompt_locales (id_llm_prompt_entries, id_languages, active_version_id, active_version_no) VALUES (?, ?, NULL, 0)')
                ->execute(array($entry['id'], $descriptor['id_languages']));
            $locale = $db->row('SELECT * FROM llm_prompt_locales WHERE id = ?', array($db->pdo->lastInsertId()));
        }

        $active = $locale['active_version_id']
            ? $db->row('SELECT * FROM llm_prompt_versions WHERE id = ?', array($locale['active_version_id']))
            : null;
        if ($active && $active['template_raw'] === $content) {
            return array('entry' => $entry, 'locale' => $locale, 'version' => $active, 'created' => false);
        }

        $meta = json_decode((string)$meta_json, true);
        $note = is_array($meta) && isset($meta['prompt']['pendingChangeNote']) ? $meta['prompt']['pendingChangeNote'] : null;
        $next = (int)$locale['active_version_no'] + 1;
        $db->pdo->prepare('INSERT INTO llm_prompt_versions (id_llm_prompt_locales, version_no, template_raw, change_note, id_users_created, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(array($locale['id'], $next, $content, $note, isset($_SESSION['id_user']) ? $_SESSION['id_user'] : null, '2026-11-23 12:00:00'));
        $version_id = $db->pdo->lastInsertId();
        $db->pdo->prepare('UPDATE llm_prompt_locales SET active_version_id = ?, active_version_no = ? WHERE id = ?')
            ->execute(array($version_id, $next, $locale['id']));

        return array(
            'entry' => $entry,
            'locale' => $db->row('SELECT * FROM llm_prompt_locales WHERE id = ?', array($locale['id'])),
            'version' => $db->row('SELECT * FROM llm_prompt_versions WHERE id = ?', array($version_id)),
            'created' => true,
        );
    }
}

/** The registry, except that it fails from its n-th save on: the languages of one prompt are saved one after the other */
class FlakyRegistry extends StubRegistry
{
    /** @var int 0 = never */
    public $fail_from = 0;
    private $seen = 0;

    public function syncFieldSave($descriptor, $field_id, $content, $meta_json = null, $runtime_values = array())
    {
        $this->seen++;
        if ($this->fail_from > 0 && $this->seen >= $this->fail_from) {
            throw new RuntimeException('registry down: the participant said hello');
        }
        return parent::syncFieldSave($descriptor, $field_id, $content, $meta_json, $runtime_values);
    }
}

class StubProfile
{
    public $calls = array();

    public function resolveExecutionProfile($descriptor)
    {
        $this->calls[] = array('resolveExecutionProfile', $descriptor);
        return array('profile' => 'chat');
    }

    public function getCompanionFieldNames($profile)
    {
        $this->calls[] = array('getCompanionFieldNames', $profile);
        return array('llm_model', 'llm_temperature');
    }

    public function getStyleFieldValues($section_id, $language_id, $field_names)
    {
        $this->calls[] = array('getStyleFieldValues', $section_id, $language_id, $field_names);
        return array('llm_model' => 'gpt-x', 'llm_temperature' => '0.5');
    }
}

/** LlmScriptService: fetch_script() and update_script(), which nests its own transaction and answers false on failure */
class StubScripts
{
    public $calls = array();
    /** @var string ok | false (answers false at once) | false_after_write (writes inside its nested transaction, rolls it back, answers false) */
    public $mode = 'ok';
    private $db;
    private $registry;

    public function __construct($db, $registry)
    {
        $this->db = $db;
        $this->registry = $registry;
    }

    public function fetch_script($sid)
    {
        return $this->db->row('SELECT * FROM llm_scripts WHERE id = ?', array($sid));
    }

    public function update_script($sid, $name, $script, $test_variables, $async, $data_config, $model = null, $temperature = null, $max_tokens = null, $refresh_sections = null, $prompt_change_note = null, $prompt_meta_json = null, $combine_images = null)
    {
        $this->calls[] = func_get_args();
        if ($this->mode === 'false') {
            return false;
        }
        try {
            LlmTransaction::begin($this->db);
            $this->db->pdo->prepare('UPDATE llm_scripts SET name = ?, script = ?, test_variables = ?, async = ?, data_config = ?, model = ?, temperature = ?, max_tokens = ?, refresh_sections = ?, combine_images = ? WHERE id = ?')
                ->execute(array($name, $script, $test_variables, (int)$async, $data_config, $model, $temperature, $max_tokens, $refresh_sections, $combine_images, $sid));
            $note = $prompt_change_note !== null && $prompt_change_note !== ''
                ? json_encode(array('prompt' => array('pendingChangeNote' => $prompt_change_note))) : null;
            $this->registry->snapshot(
                array('owner_type' => 'llm_script', 'owner_id' => (int)$sid, 'prompt_slot' => 'script', 'id_languages' => 1, 'title' => $name),
                $script,
                $note
            );
            if ($this->mode === 'false_after_write') {
                throw new RuntimeException('script service failed after writing');
            }
            LlmTransaction::commit($this->db);
            return $sid;
        } catch (Throwable $e) {
            LlmTransaction::rollback($this->db, array(FakeCache::CACHE_TYPE_SECTIONS));
            return false;
        }
    }
}

/** LlmMemoryRuleService: the rule row and the prompt half of the rule save */
class StubRules
{
    public $calls = array();
    private $db;
    private $registry;

    public function __construct($db, $registry)
    {
        $this->db = $db;
        $this->registry = $registry;
    }

    public function getRuleById($rule_id)
    {
        $row = $this->db->row('SELECT * FROM llm_memory_rules WHERE id = ?', array($rule_id));
        return $row ?: null;
    }

    public function syncPromptForRule($rule, $prompt_template, $prompt_meta_json = null, $prompt_change_note = null)
    {
        $this->calls[] = array($rule, $prompt_template, $prompt_meta_json, $prompt_change_note);
        $note = $prompt_change_note !== null && $prompt_change_note !== ''
            ? json_encode(array('prompt' => array('pendingChangeNote' => $prompt_change_note))) : null;
        return $this->registry->snapshot(
            array('owner_type' => 'llm_memory_rule', 'owner_id' => (int)$rule['id'], 'prompt_slot' => 'memory_rule', 'id_languages' => 1, 'title' => $rule['label']),
            (string)$prompt_template,
            $note
        );
    }
}

/* =========================================================================
 * The site
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

/**
 * Pages 10 (coaching) and 12 (secret) hold the sections; 1 is the hub, 2 the scripts page, 3 the
 * memory page. Section 100 has a DE prompt at v7, an EN prompt at v2 and a second prompt field;
 * section 101 has a DE prompt the registry does not know (the lazy case); 102 is on page 12;
 * 104 is on no page.
 */
function seedSite($pdo)
{
    seed($pdo, 'pages', array(
        array('id' => 1, 'keyword' => 'moduleLlmPrompts'),
        array('id' => 2, 'keyword' => 'moduleLlmScript'),
        array('id' => 3, 'keyword' => 'moduleLlmMemory'),
        array('id' => 10, 'keyword' => 'coaching'),
        array('id' => 12, 'keyword' => 'secret'),
    ));
    seed($pdo, 'pages_sections', array(
        array('id_pages' => 10, 'id_sections' => 100),
        array('id_pages' => 10, 'id_sections' => 101),
        array('id_pages' => 12, 'id_sections' => 102),
    ));
    seed($pdo, 'styles', array(array('id' => 1, 'name' => 'llmChat')));
    seed($pdo, 'sections', array(
        array('id' => 100, 'name' => 'Coach chat', 'id_styles' => 1),
        array('id' => 101, 'name' => 'Lazy chat', 'id_styles' => 1),
        array('id' => 102, 'name' => 'Secret chat', 'id_styles' => 1),
        array('id' => 104, 'name' => 'Detached', 'id_styles' => 1),
    ));
    seed($pdo, 'fieldType', array(array('id' => 1, 'name' => 'llm_prompt'), array('id' => 2, 'name' => 'markdown')));
    seed($pdo, 'fields', array(
        array('id' => 1, 'name' => 'conversation_context', 'id_type' => 1),
        array('id' => 2, 'name' => 'therapy_summary_context', 'id_type' => 1),
        array('id' => 3, 'name' => 'title', 'id_type' => 2),
    ));
    seed($pdo, 'languages', array(
        array('id' => 1, 'locale' => 'all'), array('id' => 2, 'locale' => 'de-CH'), array('id' => 3, 'locale' => 'en-GB'),
    ));
    // The llmChat style has both prompt fields: the CMS offers every site language for them
    seed($pdo, 'styles_fields', array(array('id_styles' => 1, 'id_fields' => 1), array('id_styles' => 1, 'id_fields' => 2)));
    seed($pdo, 'sections_fields_translation', array(
        array('id_sections' => 100, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'Du bist ein Coach.',
            'meta' => '{"prompt":{"entryId":1,"localeId":1,"activeVersionId":91,"activeVersionNo":7,"pendingChangeNote":"stale"},"keep":"me"}'),
        array('id_sections' => 100, 'id_fields' => 1, 'id_languages' => 3, 'id_genders' => 1, 'content' => 'You are a coach.', 'meta' => null),
        array('id_sections' => 100, 'id_fields' => 2, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'Summary prompt', 'meta' => null),
        array('id_sections' => 100, 'id_fields' => 3, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'Titel', 'meta' => null),
        array('id_sections' => 101, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'Lazy prompt', 'meta' => null),
        array('id_sections' => 102, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'secret prompt', 'meta' => null),
        array('id_sections' => 104, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'orphan prompt', 'meta' => null),
    ));
    seed($pdo, 'users', array(
        array('id' => 1, 'name' => 'Jane Admin'), array('id' => 2, 'name' => 'Bob'), array('id' => 7, 'name' => 'Tester'),
    ));
    seed($pdo, 'lookups', array(
        array('id' => 1, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'style_field'),
        array('id' => 2, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_script'),
        array('id' => 3, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_memory_rule'),
    ));
    seed($pdo, 'llm_prompt_entries', array(
        array('id' => 1, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 100, 'prompt_slot' => 'conversation_context', 'title' => 'Conversation context'),
        array('id' => 2, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 100, 'prompt_slot' => 'therapy_summary_context', 'title' => null),
        array('id' => 4, 'id_llm_prompt_owner_types' => 2, 'owner_id' => 5, 'prompt_slot' => 'script', 'title' => 'Summarizer'),
        array('id' => 6, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 7, 'prompt_slot' => 'memory_rule', 'title' => 'Goals'),
    ));
    seed($pdo, 'llm_prompt_locales', array(
        array('id' => 1, 'id_llm_prompt_entries' => 1, 'id_languages' => 2, 'active_version_id' => 91, 'active_version_no' => 7),
        array('id' => 5, 'id_llm_prompt_entries' => 1, 'id_languages' => 3, 'active_version_id' => 103, 'active_version_no' => 2),
        array('id' => 2, 'id_llm_prompt_entries' => 2, 'id_languages' => 2, 'active_version_id' => 100, 'active_version_no' => 1),
        array('id' => 4, 'id_llm_prompt_entries' => 4, 'id_languages' => 1, 'active_version_id' => 94, 'active_version_no' => 1),
        array('id' => 6, 'id_llm_prompt_entries' => 6, 'id_languages' => 1, 'active_version_id' => 96, 'active_version_no' => 3),
    ));
    seed($pdo, 'llm_prompt_versions', array(
        array('id' => 90, 'id_llm_prompt_locales' => 1, 'version_no' => 6, 'template_raw' => 'Alter Prompt', 'change_note' => 'first', 'id_users_created' => 2, 'created_at' => '2026-11-01 10:00:00'),
        array('id' => 91, 'id_llm_prompt_locales' => 1, 'version_no' => 7, 'template_raw' => 'Du bist ein Coach.', 'change_note' => 'Friendlier', 'id_users_created' => 1, 'created_at' => '2026-11-20 14:03:00'),
        array('id' => 102, 'id_llm_prompt_locales' => 5, 'version_no' => 1, 'template_raw' => 'English first', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-02 10:00:00'),
        array('id' => 103, 'id_llm_prompt_locales' => 5, 'version_no' => 2, 'template_raw' => 'You are a coach.', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-03 10:00:00'),
        array('id' => 100, 'id_llm_prompt_locales' => 2, 'version_no' => 1, 'template_raw' => 'Summary prompt', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-04 10:00:00'),
        array('id' => 94, 'id_llm_prompt_locales' => 4, 'version_no' => 1, 'template_raw' => 'Summarize the chat.', 'change_note' => 'init', 'id_users_created' => 2, 'created_at' => '2026-11-02 09:00:00'),
        array('id' => 96, 'id_llm_prompt_locales' => 6, 'version_no' => 3, 'template_raw' => 'Remember the goals', 'change_note' => 'goals', 'id_users_created' => 1, 'created_at' => '2026-11-05 12:00:00'),
    ));
    seed($pdo, 'llm_scripts', array(
        array('id' => 5, 'name' => 'Summarizer', 'script' => 'Summarize the chat.', 'test_variables' => '{"a":1}', 'async' => 0,
            'data_config' => '{"x":2}', 'model' => 'gpt-x', 'temperature' => '0.7', 'max_tokens' => '1000',
            'refresh_sections' => '[1,2]', 'combine_images' => 1, 'updated_at' => '2026-11-03 10:00:00'),
        array('id' => 6, 'name' => 'Lazy script', 'script' => 'script without registry entry', 'test_variables' => null, 'async' => 1,
            'data_config' => null, 'model' => null, 'temperature' => null, 'max_tokens' => null,
            'refresh_sections' => null, 'combine_images' => 0, 'updated_at' => '2026-11-04 10:00:00'),
    ));
    seed($pdo, 'llm_memory_rules', array(
        array('id' => 7, 'label' => 'Goals', 'enabled' => 1, 'updated_at' => '2026-11-05 12:00:00'),
        array('id' => 8, 'label' => 'Lazy rule', 'enabled' => 1, 'updated_at' => '2026-11-06 12:00:00'),
    ));
}

const KEY_DE = 'style_field:100:conversation_context:2';
const KEY_EN = 'style_field:100:conversation_context:3';
const KEY_LAZY = 'style_field:101:conversation_context:2';
const KEY_SUMMARY = 'style_field:100:therapy_summary_context:2';
const KEY_SECRET = 'style_field:102:conversation_context:2';
const KEY_ORPHAN = 'style_field:104:conversation_context:2';
const KEY_SCRIPT = 'llm_script:5:script:1';
const KEY_SCRIPT_LAZY = 'llm_script:6:script:1';
const KEY_RULE = 'llm_memory_rule:7:memory_rule:1';
const KEY_RULE_LAZY = 'llm_memory_rule:8:memory_rule:1';

/**
 * A fresh site with every stand-in.
 *
 * @param array $opts select / update (page ids the user may read / change)
 * @return array
 */
function site(array $opts = array())
{
    $db = new SaveDb();
    seedSite($db->pdo);
    $acl = new FakeAcl(
        isset($opts['select']) ? $opts['select'] : array(1, 2, 3, 10),
        isset($opts['update']) ? $opts['update'] : array(1, 2, 3, 10)
    );
    $services = new FakeServices($db, $acl);
    $registry = new StubRegistry($db);
    $profile = new StubProfile();
    $scripts = new StubScripts($db, $registry);
    $rules = new StubRules($db, $registry);
    $catalog = new LlmPromptCatalogService($services, null, new StubAssetLoader());
    $service = new LlmPromptHubSaveService($services, $catalog, $registry, $profile, $scripts, $rules);

    return array(
        'db' => $db, 'acl' => $acl, 'services' => $services, 'registry' => $registry, 'profile' => $profile,
        'scripts' => $scripts, 'rules' => $rules, 'catalog' => $catalog, 'service' => $service,
    );
}

/** Run a callable; the exception it throws, or null */
function thrown($callable)
{
    try {
        $callable();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

function is_thrown($callable, $class)
{
    $e = thrown($callable);
    return $e !== null && $e instanceof $class;
}

/** No transaction left open, neither in the handle nor in LlmTransaction's frame list */
function balanced($site)
{
    $frames = (new ReflectionProperty('LlmTransaction', 'frames'))->getValue();
    return $site['db']->dbh->depth === 0 && empty($frames);
}

function hash_of($text)
{
    return hash('sha256', $text);
}

function sft($site, $section, $field, $language)
{
    return $site['db']->row(
        'SELECT content, meta FROM sections_fields_translation WHERE id_sections = ? AND id_fields = ? AND id_languages = ? AND id_genders = 1',
        array($section, $field, $language)
    );
}

function version_count($site)
{
    return (int)$site['db']->value('SELECT COUNT(*) FROM llm_prompt_versions');
}

function begins($site)
{
    return count(array_keys($site['db']->events, 'BEGIN', true));
}

/**
 * What the transaction sent from its BEGIN up to and including its last locking read.
 * Under REPEATABLE READ the first plain read of a transaction pins its snapshot, so a plain read
 * in this range would hide a save that committed while a lock was waited for. One SQLite
 * connection cannot show the stale read itself; the order of the statements is what the real
 * database depends on.
 */
function sent_until_last_lock($site)
{
    $log = $site['db']->log;
    $begin = array_search('-- BEGIN', $log, true);
    $last_lock = false;
    foreach ($log as $i => $sql) {
        if (preg_match('/FOR UPDATE\s*$/', $sql) === 1) {
            $last_lock = $i;
        }
    }
    return ($begin === false || $last_lock === false) ? array() : array_slice($log, $begin + 1, $last_lock - $begin);
}

/** @param string[] $statements */
function all_locking_reads(array $statements)
{
    return count(array_filter($statements, function ($sql) {
        return preg_match('/FOR UPDATE\s*$/', $sql) !== 1;
    })) === 0;
}

$USER = 7;

/* =========================================================================
 * style_field: the save mirrors the CMS save
 * ========================================================================= */

$s = site();
$de_hash = hash_of('Du bist ein Coach.');
$r = $s['service']->save(KEY_DE, "Du bist ein sehr guter Coach.\r\nZweite Zeile\rDritte", 'Friendlier tone', 91, $USER, $de_hash);

check('a saved style field answers ok with the fresh row', $r['ok'] === true && $r['row']['key'] === KEY_DE);
check('the save reports a change', $r['changed'] === true);
check('the new text is the live text, line endings stored as \n',
    $r['row']['content'] === "Du bist ein sehr guter Coach.\nZweite Zeile\nDritte"
    && sft($s, 100, 1, 2)['content'] === "Du bist ein sehr guter Coach.\nZweite Zeile\nDritte");
check('the fresh row is the next version: v8, a new version id',
    $r['row']['activeVersionNo'] === 8 && (int)$r['row']['activeVersionId'] !== 91 && $r['row']['contentHash'] === hash_of($r['row']['content']));
check('the version carries the change note and the session user',
    $s['db']->row('SELECT change_note, id_users_created FROM llm_prompt_versions WHERE version_no = 8 AND id_llm_prompt_locales = 1') === array('change_note' => 'Friendlier tone', 'id_users_created' => 7));
check('the old versions are untouched', version_count($s) === 8 && $s['db']->value('SELECT template_raw FROM llm_prompt_versions WHERE id = 91') === 'Du bist ein Coach.');

$call = $s['registry']->calls[0];
check('the registry gets the descriptor the CMS hook builds',
    $call['descriptor'] === array(
        'owner_type' => 'style_field', 'owner_id' => 100, 'prompt_slot' => 'conversation_context',
        'id_languages' => 2, 'title' => 'Conversation context',
    ) && $call['field_id'] === 1);
check('the registry gets the text with \n line endings', $call['content'] === "Du bist ein sehr guter Coach.\nZweite Zeile\nDritte");
check('the runtime values come from the execution profile of the descriptor, without posted overrides',
    $call['runtime_values'] === array('llm_model' => 'gpt-x', 'llm_temperature' => '0.5')
    && $s['profile']->calls[0][0] === 'resolveExecutionProfile' && $s['profile']->calls[0][1] === $call['descriptor']
    && $s['profile']->calls[2] === array('getStyleFieldValues', 100, 2, array('llm_model', 'llm_temperature')));
$sent_meta = json_decode($call['meta_json'], true);
check('the stored field meta goes to the registry with the change note added (and a stale note replaced)',
    $sent_meta['prompt']['pendingChangeNote'] === 'Friendlier tone' && $sent_meta['keep'] === 'me' && $sent_meta['prompt']['entryId'] === 1);

$stored = sft($s, 100, 1, 2);
$stored_meta = json_decode($stored['meta'], true);
check('sections_fields_translation gets the registry meta: link to the new version, no pending note, other keys kept',
    $stored_meta['prompt']['activeVersionNo'] === 8 && $stored_meta['prompt']['activeVersionId'] === (int)$r['row']['activeVersionId']
    && !isset($stored_meta['prompt']['pendingChangeNote']) && $stored_meta['keep'] === 'me');
check('the CMS field history gets the same text and meta',
    count($s['services']->field_versions->calls) === 1
    && $s['services']->field_versions->calls[0] === array('section_field', 100, 1, 2, "Du bist ein sehr guter Coach.\nZweite Zeile\nDritte", $stored['meta']));
check('the English text of the same section is untouched (own version stream)',
    sft($s, 100, 1, 3)['content'] === 'You are a coach.' && $s['db']->value('SELECT active_version_no FROM llm_prompt_locales WHERE id = 5') === 2);
check('the gender and the other prompt field of the section are untouched',
    sft($s, 100, 2, 2)['content'] === 'Summary prompt' && sft($s, 100, 3, 2)['content'] === 'Titel');

$locks = array_keys($s['db']->events, 'LOCK', true);
$events = $s['db']->events;
check('the save is one transaction: begin, lock, commit', $events[0] === 'BEGIN' && $events[1] === 'LOCK' && in_array('COMMIT', $events, true) && begins($s) === 1 && balanced($s));
check('the section cache is cleared exactly once, after the commit',
    array_keys($events, 'CLEAR CACHE_SECTIONS', true) === array(array_search('COMMIT', $events, true) + 1));

// First save of a prompt the registry does not know yet
$s = site();
$r = $s['service']->save(KEY_LAZY, 'New lazy text', null, 0, $USER);
check('the lazy row (no registry entry) is saved as v1 with expected id 0', $r['ok'] === true && $r['row']['activeVersionNo'] === 1 && $r['row']['content'] === 'New lazy text');
check('the registry now has entry, locale and version for it, without a note',
    (int)$s['db']->value("SELECT COUNT(*) FROM llm_prompt_entries WHERE owner_id = 101 AND prompt_slot = 'conversation_context'") === 1
    && $s['db']->value('SELECT change_note FROM llm_prompt_versions WHERE template_raw = ?', array('New lazy text')) === null
    && !isset(json_decode($s['registry']->calls[0]['meta_json'], true)['prompt']['pendingChangeNote']));
$lazy_meta = json_decode(sft($s, 101, 1, 2)['meta'], true);
check('the field now links to its first version', $lazy_meta['prompt']['activeVersionNo'] === 1 && $lazy_meta['prompt']['entryId'] > 0);

$s = site();
$s['service']->save(KEY_LAZY, 'New lazy text', "  \t ", 0, $USER);
check('a note of only whitespace is stored as no note', !isset(json_decode($s['registry']->calls[0]['meta_json'], true)['prompt']['pendingChangeNote']));

// Saving the active text again: no new version
$s = site();
$r = $s['service']->save(KEY_DE, 'Du bist ein Coach.', 'same', 91, $USER);
check('the same text again is "no changes": no new version, changed false', $r['ok'] === true && $r['changed'] === false && $r['row']['activeVersionNo'] === 7 && version_count($s) === 7);

// Empty text is a valid prompt
$s = site();
$r = $s['service']->save(KEY_DE, '', null, 91, $USER);
check('an empty text can be saved (the CMS allows it)', $r['row']['content'] === '' && $r['row']['activeVersionNo'] === 8 && sft($s, 100, 1, 2)['content'] === '');

/* =========================================================================
 * Optimistic lock: version id and text hash
 * ========================================================================= */

$s = site();
$e = thrown(function () use ($s, $USER) {
    $s['service']->save(KEY_DE, 'My edit', 'x', 90, $USER);
});
check('a stale expected version id is a conflict', $e instanceof LlmPromptHubConflictException);
$cur = $e->getCurrent();
check('the conflict carries what is live now',
    $cur['version_id'] === 91 && $cur['version_no'] === 7 && $cur['content'] === 'Du bist ein Coach.'
    && $cur['content_hash'] === hash_of('Du bist ein Coach.') && $cur['updated_by'] === 'Jane Admin' && $cur['updated_at'] === '2026-11-20 14:03:00');
check('a conflict writes nothing: text, meta, versions, field history',
    sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && version_count($s) === 7 && $s['registry']->calls === array()
    && $s['services']->field_versions->calls === array());
check('a conflict leaves no transaction open, commits nothing and does not cool the cache',
    balanced($s) && $s['db']->events === array('BEGIN', 'LOCK', 'ROLLBACK'));

$s = site();
check('"there was none" (0) against a prompt that has a version is a conflict',
    is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 0, $USER); }, 'LlmPromptHubConflictException'));
check('a version id for a prompt that has none is a conflict',
    is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_LAZY, 'x', null, 91, $USER); }, 'LlmPromptHubConflictException'));
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_LAZY, 'x', null, 5, $USER); });
check('the conflict of a prompt without a version names no version', $e->getCurrent()['version_id'] === null && $e->getCurrent()['version_no'] === null);

// Same version id, but the text at the source is not the one the editor loaded
$s = site();
$s['db']->pdo->exec("UPDATE sections_fields_translation SET content = 'Edited behind the registry' WHERE id_sections = 100 AND id_fields = 1 AND id_languages = 2");
$e = thrown(function () use ($s, $USER, $de_hash) {
    $s['service']->save(KEY_DE, 'My edit', null, 91, $USER, $de_hash);
});
check('the same version but another stored text (hash mismatch) is a conflict',
    $e instanceof LlmPromptHubConflictException && $e->getCurrent()['content'] === 'Edited behind the registry'
    && $e->getCurrent()['content_hash'] === hash_of('Edited behind the registry'));
check('the hash conflict writes nothing', sft($s, 100, 1, 2)['content'] === 'Edited behind the registry' && version_count($s) === 7 && balanced($s));
$r = $s['service']->save(KEY_DE, 'My edit', null, 91, $USER, hash_of('Edited behind the registry'));
check('the hash of the live text lets the save through', $r['ok'] === true && $r['row']['content'] === 'My edit');

$s = site();
$r = $s['service']->save(KEY_DE, 'Upper case hash', null, 91, $USER, strtoupper($de_hash));
check('the hash is compared case-insensitively', $r['ok'] === true);
$s = site();
$r = $s['service']->save(KEY_DE, 'No hash given', null, 91, $USER);
check('without a hash only the version id is checked', $r['ok'] === true);
$s = site();
$r = $s['service']->save(KEY_DE, 'Empty hash', null, 91, $USER, '');
check('an empty hash means "not given"', $r['ok'] === true);

// The race: another save commits while ours waits for the lock
$s = site();
$s['db']->on_lock = function ($db) {
    $db->pdo->exec("INSERT INTO llm_prompt_versions (id, id_llm_prompt_locales, version_no, template_raw, change_note, id_users_created, created_at) VALUES (92, 1, 8, 'CMS text', 'cms', 2, '2026-11-23 11:59:00')");
    $db->pdo->exec('UPDATE llm_prompt_locales SET active_version_id = 92, active_version_no = 8 WHERE id = 1');
    $db->pdo->exec("UPDATE sections_fields_translation SET content = 'CMS text' WHERE id_sections = 100 AND id_fields = 1 AND id_languages = 2");
};
$e = thrown(function () use ($s, $USER, $de_hash) {
    $s['service']->save(KEY_DE, 'My edit', null, 91, $USER, $de_hash);
});
check('a save that lands while ours waits for the lock is found by the check under the lock',
    $e instanceof LlmPromptHubConflictException && $e->getCurrent()['version_id'] === 92 && $e->getCurrent()['content'] === 'CMS text');
// (One connection: what the callback "committed" is rolled back with our transaction. What matters is
// what the service saw under the lock, and that it wrote and committed nothing.)
check('the waiting save wrote and committed nothing over it',
    $s['registry']->calls === array() && !in_array('COMMIT', $s['db']->events, true) && balanced($s));
$begin_index = array_search('-- BEGIN', $s['db']->log, true);
check('the first statement of the transaction is the locking read of the stored text',
    $begin_index !== false && preg_match('/FOR UPDATE\s*$/', $s['db']->log[$begin_index + 1]) === 1
    && strpos($s['db']->log[$begin_index + 1], 'FROM sections_fields_translation') !== false);
check('nothing before the transaction locks', count(array_filter(array_slice($s['db']->log, 0, $begin_index), function ($sql) {
    return preg_match('/FOR UPDATE\s*$/', $sql) === 1;
})) === 0);

/* =========================================================================
 * restore
 * ========================================================================= */

$s = site();
$r = $s['service']->restore(KEY_DE, 90, 91, $USER, $de_hash);
check('restoring an old version saves its text as a new version', $r['ok'] === true && $r['changed'] === true
    && $r['row']['content'] === 'Alter Prompt' && $r['row']['activeVersionNo'] === 8);
check('the restored version is noted "Restored from v6"', $s['db']->value('SELECT change_note FROM llm_prompt_versions WHERE version_no = 8 AND id_llm_prompt_locales = 1') === 'Restored from v6');
check('history is not rewritten: v6 and v7 are still there', $s['db']->value('SELECT template_raw FROM llm_prompt_versions WHERE id = 90') === 'Alter Prompt'
    && $s['db']->value('SELECT template_raw FROM llm_prompt_versions WHERE id = 91') === 'Du bist ein Coach.');
check('the restore reaches the CMS text and the field history', sft($s, 100, 1, 2)['content'] === 'Alter Prompt' && count($s['services']->field_versions->calls) === 1);

$s = site();
$e = thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, 94, 91, $USER); });
check('a version of another owner (a script) is refused', $e instanceof LlmPromptHubForbiddenException && $e->getReason() === 'wrong_version');
$e = thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, 100, 91, $USER); });
check('a version of another prompt field of the same section is refused', $e instanceof LlmPromptHubForbiddenException && $e->getReason() === 'wrong_version');
$e = thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, 102, 91, $USER); });
check('a version of the same prompt in another language is refused', $e instanceof LlmPromptHubForbiddenException && $e->getReason() === 'wrong_version');
check('a version that does not exist is refused', is_thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, 999, 91, $USER); }, 'LlmPromptHubForbiddenException'));
check('a refused restore writes nothing and opens no transaction',
    version_count($s) === 7 && sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && begins($s) === 0 && $s['registry']->calls === array());
check('restoring needs a version id', is_thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, 0, 91, $USER); }, 'InvalidArgumentException')
    && is_thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, -3, 91, $USER); }, 'InvalidArgumentException')
    && is_thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, '90', 91, $USER); }, 'InvalidArgumentException'));
check('a restore against a changed prompt is a conflict',
    is_thrown(function () use ($s, $USER) { $s['service']->restore(KEY_DE, 90, 90, $USER); }, 'LlmPromptHubConflictException') && version_count($s) === 7);
check('a restore of a prompt the user may not see is not found',
    is_thrown(function () use ($s, $USER) { $s['service']->restore(KEY_SECRET, 90, 0, $USER); }, 'LlmPromptHubNotFoundException'));
check('a restore of a read-only prompt is refused as bad input',
    is_thrown(function () use ($s, $USER) { $s['service']->restore('system:0:some_prompt:0', 1, 0, $USER); }, 'InvalidArgumentException'));

$s = site();
$s['service']->restore(KEY_EN, 102, 103, $USER);
check('restoring in one language does not touch the other', $s['db']->value('SELECT active_version_no FROM llm_prompt_locales WHERE id = 1') === 7
    && sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && sft($s, 100, 1, 3)['content'] === 'English first');

/* =========================================================================
 * What cannot be saved, and who may not
 * ========================================================================= */

$s = site();
foreach (array('system:0:some_prompt:0', 'agentic_persona:0:persona_1:0') as $read_only) {
    check("$read_only is read-only", is_thrown(function () use ($s, $USER, $read_only) { $s['service']->save($read_only, 'x', null, 0, $USER); }, 'InvalidArgumentException'));
}
foreach (array('', 'nonsense', 'style_field:100:conversation_context', 'style_field:0:conversation_context:2', 'llm_script:5:script:2', "style_field:100:conversation_context:2\n", 'unknown:1:x:1') as $bad_key) {
    check('a malformed key is a bad request: ' . json_encode($bad_key), is_thrown(function () use ($s, $USER, $bad_key) { $s['service']->save($bad_key, 'x', null, 0, $USER); }, 'InvalidArgumentException'));
}
check('a read-only or malformed key touches neither database nor registry', begins($s) === 0 && $s['db']->log === array() && $s['registry']->calls === array());

$s = site(array('update' => array(1, 2, 3)));
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('no update access on the page that holds the section is forbidden (no_access)', $e instanceof LlmPromptHubForbiddenException && $e->getReason() === 'no_access');
check('a forbidden save locks and writes nothing', begins($s) === 0 && version_count($s) === 7 && sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && $s['registry']->calls === array());

$s = site(array('update' => array(2, 3, 10)));
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('select-only access to the hub page is forbidden too (the hub is gated first)', $e instanceof LlmPromptHubForbiddenException);

$s = site(array('select' => array(1, 2, 3, 10), 'update' => array(1, 2, 3, 10)));
$first = $s['service']->save(KEY_DE, 'x', null, 91, $USER);
check('an owner page the user can open and change: saved', $first['ok'] === true);
$s['acl']->update = array(1, 2, 3);
// A new request: the catalog remembers the ACL answers it got for the length of one request
$next_request = new LlmPromptHubSaveService($s['services'], new LlmPromptCatalogService($s['services'], null, new StubAssetLoader()), $s['registry'], $s['profile'], $s['scripts'], $s['rules']);
check('update access lost after the first save: the next save is forbidden',
    is_thrown(function () use ($next_request, $USER, $first) { $next_request->save(KEY_DE, 'y', null, (int)$first['row']['activeVersionId'], $USER); }, 'LlmPromptHubForbiddenException'));

$s = site();
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_SECRET, 'x', null, 0, $USER); });
check('a prompt on a page the user cannot open is "not found"', $e instanceof LlmPromptHubNotFoundException);
$e2 = thrown(function () use ($s, $USER) { $s['service']->save('style_field:555:conversation_context:2', 'x', null, 0, $USER); });
check('it answers exactly like a prompt that does not exist', $e2 instanceof LlmPromptHubNotFoundException && $e2->getMessage() === $e->getMessage());
check('neither of them locks anything', begins($s) === 0 && version_count($s) === 7);
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_ORPHAN, 'x', null, 0, $USER); });
check('an orphaned prompt (its section is on no page) is forbidden: orphaned', $e instanceof LlmPromptHubForbiddenException && $e->getReason() === 'orphaned');

$s = site(array('select' => array(1, 2, 3, 10, 12), 'update' => array(1, 2, 3, 10)));
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_SECRET, 'x', null, 0, $USER); });
check('a prompt the user may see but not change is forbidden', $e instanceof LlmPromptHubForbiddenException && $e->getReason() === 'no_access');

/* =========================================================================
 * Input checks happen before anything is touched
 * ========================================================================= */

$s = site();
$bad_inputs = array(
    'a non-string text' => function () use ($s, $USER) { $s['service']->save(KEY_DE, null, null, 91, $USER); },
    'an array as text' => function () use ($s, $USER) { $s['service']->save(KEY_DE, array('x'), null, 91, $USER); },
    'a text over the limit' => function () use ($s, $USER) { $s['service']->save(KEY_DE, str_repeat('x', LlmPromptHubSaveService::MAX_CONTENT_BYTES + 1), null, 91, $USER); },
    'invalid UTF-8 in the text' => function () use ($s, $USER) { $s['service']->save(KEY_DE, "bad \xC3\x28 bytes", null, 91, $USER); },
    'a note over 255 characters' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', str_repeat('n', 256), 91, $USER); },
    'a non-string note' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', 12, 91, $USER); },
    'invalid UTF-8 in the note' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', "bad \xC3\x28", 91, $USER); },
    'a negative expected version' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, -1, $USER); },
    'a missing expected version' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, null, $USER); },
    'a decimal expected version' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, '1.5', $USER); },
    'a short content hash' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER, 'abc'); },
    'a content hash with non-hex characters' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER, str_repeat('z', 64)); },
    'an array as content hash' => function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER, array('x')); },
);
foreach ($bad_inputs as $label => $call) {
    check("$label is a bad request", is_thrown($call, 'InvalidArgumentException'));
}
check('a bad request touches nothing', begins($s) === 0 && $s['db']->log === array() && $s['registry']->calls === array());

$s = site();
check('255 characters of note are fine, counted as characters not bytes',
    $s['service']->save(KEY_DE, 'x', str_repeat("\u{00E4}", 255), 91, $USER)['ok'] === true
    && mb_strlen($s['db']->value('SELECT change_note FROM llm_prompt_versions WHERE version_no = 8 AND id_llm_prompt_locales = 1')) === 255);
$s = site();
check('a text exactly at the limit is fine', $s['service']->save(KEY_DE, str_repeat('x', LlmPromptHubSaveService::MAX_CONTENT_BYTES), null, 91, $USER)['ok'] === true);
$s = site();
$r = $s['service']->save(KEY_DE, '  inner   whitespace  kept ', '  trimmed note  ', '91', $USER);
check('inner and outer whitespace of the text is kept, the note is trimmed, a numeric string works as expected version',
    $r['row']['content'] === '  inner   whitespace  kept ' && $s['db']->value('SELECT change_note FROM llm_prompt_versions WHERE version_no = 8 AND id_llm_prompt_locales = 1') === 'trimmed note');

/* =========================================================================
 * Atomicity: a failure anywhere rolls the whole save back
 * ========================================================================= */

$s = site();
$s['registry']->mode = 'throw_before';
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('a failing registry fails the save with its own exception', $e instanceof RuntimeException && strpos($e->getMessage(), 'registry down') === 0);
check('nothing was written and the transaction is closed', sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && version_count($s) === 7 && balanced($s)
    && in_array('ROLLBACK', $s['db']->events, true) && !in_array('COMMIT', $s['db']->events, true));
check('the rollback clears the section cache by type, never the whole cache', in_array('CLEAR CACHE_SECTIONS', $s['db']->events, true) && !in_array('CLEAR (all)', $s['db']->events, true));

$s = site();
$s['registry']->mode = 'throw_after';
thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('a version written before the failure is rolled back with the rest', version_count($s) === 7
    && (int)$s['db']->value('SELECT active_version_no FROM llm_prompt_locales WHERE id = 1') === 7 && sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && balanced($s));

$s = site();
$s['db']->insert_mode = 'fail';
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('a text that cannot be stored fails the save (by the write result, not only by the check afterwards) and rolls the registry version back',
    $e instanceof RuntimeException && strpos($e->getMessage(), 'could not be stored') !== false
    && version_count($s) === 7 && sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && balanced($s));

$s = site();
$s['db']->insert_mode = 'swallow';
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('a write that answers fine but stores another text is caught by the check afterwards',
    $e instanceof RuntimeException && strpos($e->getMessage(), 'could not be verified') !== false && version_count($s) === 7 && balanced($s));

$s = site();
$s['services']->field_versions->fail = true;
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('a failing CMS field history fails the save and rolls everything back',
    $e instanceof RuntimeException && strpos($e->getMessage(), 'field history could not be written') !== false
    && version_count($s) === 7 && sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && balanced($s));

$s = site();
$s['services']->field_versions->versioned = false;
$r = $s['service']->save(KEY_DE, 'x', null, 91, $USER);
check('a field without history is saved without a snapshot', $r['ok'] === true && $s['services']->field_versions->calls === array());

$s = site();
$s['db']->fail_commit = true;
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
check('a commit that fails fails the save', $e instanceof PDOException);
$s['db']->fail_commit = false;
check('after a failed commit the rows are back and the cache task did not run',
    sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && version_count($s) === 7 && balanced($s)
    && array_keys($s['db']->events, 'CLEAR CACHE_SECTIONS', true) === array(count($s['db']->events) - 1)
    && !in_array('COMMIT', $s['db']->events, true));
check('the dropped after-commit task is reported by LlmTransaction', strpos((string)file_get_contents($error_log_file), 'after-commit task(s) dropped: the transaction was rolled back') !== false);

$s = site();
$s['db']->on_lock = function ($db) {
    $db->pdo->exec('DELETE FROM sections_fields_translation WHERE id_sections = 100 AND id_fields = 1 AND id_languages = 2');
};
// A missing text row is an empty CMS language, so the text the editor loaded is gone: a conflict
check('a prompt text that disappears before the lock is a conflict, nothing written',
    is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER, hash_of('Du bist ein Coach.')); }, 'LlmPromptHubConflictException')
    && version_count($s) === 7 && balanced($s));
check('and that refusal does not cool the cache either', $s['db']->events === array('BEGIN', 'LOCK', 'ROLLBACK'));

// The catalog stops finding the prompt between the first check and the lock (the check under the lock is its own read)
$s = site();
$vanishing = new VanishingCatalog($s['services'], null, new StubAssetLoader());
$vanishing->null_from = 2;
$vanishing_service = new LlmPromptHubSaveService($s['services'], $vanishing, $s['registry'], $s['profile'], $s['scripts'], $s['rules']);
$e = thrown(function () use ($vanishing_service, $USER) { $vanishing_service->save(KEY_DE, 'x', null, 91, $USER); });
check('a prompt the catalog no longer finds under the lock is "not found", nothing written',
    $e instanceof LlmPromptHubNotFoundException && $vanishing->calls === 2
    && $s['registry']->calls === array() && $s['db']->events === array('BEGIN', 'LOCK', 'ROLLBACK') && balanced($s));
$s = site();
$vanishing = new VanishingCatalog($s['services'], null, new StubAssetLoader());
$vanishing->null_from = 1;
$vanishing_service = new LlmPromptHubSaveService($s['services'], $vanishing, $s['registry'], $s['profile'], $s['scripts'], $s['rules']);
$e = thrown(function () use ($vanishing_service, $USER) { $vanishing_service->save(KEY_DE, 'x', null, 91, $USER); });
check('a prompt the catalog does not find at all is "not found" before any transaction starts',
    $e instanceof LlmPromptHubNotFoundException && $vanishing->calls === 1 && $s['db']->events === array() && begins($s) === 0);

// A save works after a failed one (no state left behind)
$s = site();
$s['registry']->mode = 'throw_before';
thrown(function () use ($s, $USER) { $s['service']->save(KEY_DE, 'x', null, 91, $USER); });
$s['registry']->mode = 'ok';
check('a save after a failed save works', $s['service']->save(KEY_DE, 'x', null, 91, $USER)['ok'] === true && balanced($s));

/* =========================================================================
 * llm_script: update_script(), every other column as it is
 * ========================================================================= */

$s = site();
$script_hash = hash_of('Summarize the chat.');
$r = $s['service']->save(KEY_SCRIPT, "Summarize it.\r\nBriefly.", 'shorter', 94, $USER, $script_hash);
check('a script text is saved as a new version', $r['ok'] === true && $r['changed'] === true && $r['row']['content'] === "Summarize it.\nBriefly." && $r['row']['activeVersionNo'] === 2);
check('update_script gets the row\'s own values for every other column, the note, no meta, the collage flag',
    $s['scripts']->calls === array(array(
        5, 'Summarizer', "Summarize it.\nBriefly.", '{"a":1}', 0, '{"x":2}', 'gpt-x', '0.7', '1000', '[1,2]', 'shorter', null, 1,
    )));
$row = $s['db']->row('SELECT * FROM llm_scripts WHERE id = 5');
check('the script row keeps name, variables, async, data config, model, temperature, tokens, sections and collage flag',
    $row['name'] === 'Summarizer' && $row['test_variables'] === '{"a":1}' && (int)$row['async'] === 0 && $row['data_config'] === '{"x":2}'
    && $row['model'] === 'gpt-x' && $row['temperature'] === '0.7' && $row['max_tokens'] === '1000' && $row['refresh_sections'] === '[1,2]' && (int)$row['combine_images'] === 1);
check('the version carries the note', $s['db']->value('SELECT change_note FROM llm_prompt_versions WHERE id_llm_prompt_locales = 4 AND version_no = 2') === 'shorter');
check('the other script is untouched', $s['db']->value('SELECT script FROM llm_scripts WHERE id = 6') === 'script without registry entry');
check('the script service clears its own caches: the hub queues none for a script', !in_array('CLEAR CACHE_SECTIONS', $s['db']->events, true) && balanced($s));
check('the registry of the field path is not used for a script', $s['registry']->calls === [] && $s['profile']->calls === []);

$s = site();
$r = $s['service']->save(KEY_SCRIPT_LAZY, 'First script text', null, 0, $USER);
check('a script without a registry entry gets v1 on its first save', $r['row']['activeVersionNo'] === 1 && $r['row']['content'] === 'First script text');
check('a script saved without a note passes null as the note', $s['scripts']->calls[0][10] === null);

$s = site();
check('a stale version id on a script is a conflict, update_script is not called',
    is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_SCRIPT, 'x', null, 0, $USER); }, 'LlmPromptHubConflictException') && $s['scripts']->calls === [] && begins($s) === 1 && balanced($s));
$s['db']->pdo->exec("UPDATE llm_scripts SET script = 'Changed on the scripts page' WHERE id = 5");
check('a script text changed behind the registry is a conflict by hash',
    is_thrown(function () use ($s, $USER, $script_hash) { $s['service']->save(KEY_SCRIPT, 'x', null, 94, $USER, $script_hash); }, 'LlmPromptHubConflictException'));

$s = site();
$s['scripts']->mode = 'false';
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_SCRIPT, 'x', null, 94, $USER); });
check('update_script answering false is an error, never "saved"', $e instanceof RuntimeException && strpos($e->getMessage(), 'could not be saved') !== false);
check('nothing of the failed script save is left', $s['db']->value('SELECT script FROM llm_scripts WHERE id = 5') === 'Summarize the chat.' && version_count($s) === 7 && balanced($s));

$s = site();
$s['scripts']->mode = 'false_after_write';
$e = thrown(function () use ($s, $USER) { $s['service']->save(KEY_SCRIPT, 'x', null, 94, $USER); });
check('update_script failing after its writes (its own nested rollback) is an error too', $e instanceof RuntimeException && strpos($e->getMessage(), 'could not be saved') !== false);
check('the whole transaction is rolled back, the nested one and the hub\'s',
    $s['db']->value('SELECT script FROM llm_scripts WHERE id = 5') === 'Summarize the chat.' && version_count($s) === 7 && balanced($s)
    && $s['db']->events === array('BEGIN', 'LOCK', 'SAVEPOINT', 'ROLLBACK TO', 'CLEAR CACHE_SECTIONS', 'ROLLBACK', 'CLEAR CACHE_SECTIONS'));

$s = site(array('update' => array(1, 3, 10)));
check('no update access on the scripts page: forbidden', is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_SCRIPT, 'x', null, 94, $USER); }, 'LlmPromptHubForbiddenException') && $s['scripts']->calls === []);
$s = site(array('select' => array(1, 3, 10), 'update' => array(1, 3, 10)));
check('no access to the scripts page: not found', is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_SCRIPT, 'x', null, 94, $USER); }, 'LlmPromptHubNotFoundException'));

$s = site();
$s['db']->on_lock = function ($db) {
    $db->pdo->exec('DELETE FROM llm_scripts WHERE id = 5');
};
check('a script deleted before the lock is "not found"', is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_SCRIPT, 'x', null, 94, $USER); }, 'LlmPromptHubNotFoundException') && balanced($s));

$s = site();
$r = $s['service']->restore(KEY_SCRIPT, 94, 94, $USER);
check('restoring the active version of a script changes nothing', $r['changed'] === false && version_count($s) === 7);

/* =========================================================================
 * llm_memory_rule: the prompt half of the rule save
 * ========================================================================= */

$s = site();
$rule_before = $s['db']->row('SELECT * FROM llm_memory_rules WHERE id = 7');
$r = $s['service']->save(KEY_RULE, "Remember goals.\r\nAlso moods.", 'moods', 96, $USER, hash_of('Remember the goals'));
check('a memory rule instruction is saved as a new version', $r['ok'] === true && $r['row']['content'] === "Remember goals.\nAlso moods." && $r['row']['activeVersionNo'] === 4);
check('the rule service gets the rule, the text, no meta and the note',
    count($s['rules']->calls) === 1 && $s['rules']->calls[0][0]['id'] == 7 && $s['rules']->calls[0][1] === "Remember goals.\nAlso moods."
    && $s['rules']->calls[0][2] === null && $s['rules']->calls[0][3] === 'moods');
check('the rule row itself is not rewritten', $s['db']->row('SELECT * FROM llm_memory_rules WHERE id = 7') === $rule_before);
check('the version carries the note', $s['db']->value('SELECT change_note FROM llm_prompt_versions WHERE id_llm_prompt_locales = 6 AND version_no = 4') === 'moods');

$s = site();
$r = $s['service']->save(KEY_RULE_LAZY, 'First rule text', null, 0, $USER);
check('a memory rule without a prompt gets v1 on its first save', $r['row']['activeVersionNo'] === 1 && $r['row']['content'] === 'First rule text');
$s = site();
check('a stale version id on a memory rule is a conflict', is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_RULE, 'x', null, 1, $USER); }, 'LlmPromptHubConflictException') && $s['rules']->calls === [] && balanced($s));
check('a memory rule text changed in the active version is a conflict by hash',
    is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_RULE, 'x', null, 96, $USER, hash_of('something else')); }, 'LlmPromptHubConflictException'));
$s = site(array('update' => array(1, 2, 10)));
check('no update access on the memory page: forbidden', is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_RULE, 'x', null, 96, $USER); }, 'LlmPromptHubForbiddenException') && $s['rules']->calls === []);
$s = site();
$s['db']->on_lock = function ($db) {
    $db->pdo->exec('DELETE FROM llm_memory_rules WHERE id = 7');
};
check('a rule deleted before the lock is "not found"', is_thrown(function () use ($s, $USER) { $s['service']->save(KEY_RULE, 'x', null, 96, $USER); }, 'LlmPromptHubNotFoundException') && balanced($s));

/* =========================================================================
 * Languages: every CMS language of a prompt field can be opened and saved, also one without text yet
 * ========================================================================= */

$s = site();
$new_key = 'style_field:100:therapy_summary_context:3';
$fresh = $s['catalog']->getPrompt($new_key, $USER);
check('a CMS language without text opens as an empty, editable prompt',
    is_array($fresh) && $fresh['content'] === '' && $fresh['editable'] === true && $fresh['activeVersionId'] === null);
check('the prompt lists every CMS language of its field, with keys',
    is_array($fresh) && array_map(function ($l) { return $l['id']; }, $fresh['languages']) === array(2, 3)
    && $fresh['languages'][1]['code'] === 'en-GB' && $fresh['languages'][1]['key'] === $new_key);
check('language 1 is not offered for a translatable field', $s['catalog']->getPrompt('style_field:100:therapy_summary_context:1', $USER) === null);
check('a language that does not exist is not found', $s['catalog']->getPrompt('style_field:100:therapy_summary_context:9', $USER) === null);
check('a field the style does not have is not found', $s['catalog']->getPrompt('style_field:100:title:3', $USER) === null);
$r = $s['service']->save($new_key, 'Summary in English', null, 0, $USER, hash_of(''));
check('saving the empty language creates its text', $r['ok'] === true && $r['changed'] === true
    && sft($s, 100, 2, 3)['content'] === 'Summary in English' && $r['row']['activeVersionNo'] === 1 && balanced($s));

/* =========================================================================
 * saveAll: the languages of one field, all or none
 * ========================================================================= */

$s = site();
$en = $s['catalog']->getPrompt(KEY_EN, $USER);
$both = array(
    array('key' => KEY_EN, 'content' => 'You are a great coach.', 'change_note' => 'both',
        'expected_active_version_id' => (int)$en['activeVersionId'], 'expected_content_hash' => $en['contentHash']),
    array('key' => KEY_DE, 'content' => 'Du bist ein toller Coach.', 'change_note' => 'beide',
        'expected_active_version_id' => 91, 'expected_content_hash' => hash_of('Du bist ein Coach.')),
);
$r = $s['service']->saveAll($both, $USER);
check('saveAll saves every language', $r['ok'] === true && $r['changed'] === true
    && sft($s, 100, 1, 2)['content'] === 'Du bist ein toller Coach.' && sft($s, 100, 1, 3)['content'] === 'You are a great coach.');
check('saveAll answers the fresh rows in the order of the request',
    count($r['rows']) === 2 && $r['rows'][0]['key'] === KEY_EN && $r['rows'][1]['key'] === KEY_DE
    && $r['rows'][1]['activeVersionNo'] === 8 && $r['rows'][0]['content'] === 'You are a great coach.');
check('saveAll leaves no transaction open', balanced($s));

$s = site();
$stale = $both;
$stale[0]['expected_content_hash'] = hash_of('an older english text');
$e = thrown(function () use ($s, $stale, $USER) { $s['service']->saveAll($stale, $USER); });
check('a conflict in one language is a conflict that names it',
    $e instanceof LlmPromptHubConflictException && $e->getCurrent()['key'] === KEY_EN);
check('and nothing is written, not even the language without a conflict',
    sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && sft($s, 100, 1, 3)['content'] === 'You are a coach.'
    && version_count($s) === 7 && balanced($s));

$s = site();
$late = $both;
$late[1]['expected_active_version_id'] = 1;
check('a conflict in the language saved last also rolls back the one saved first',
    is_thrown(function () use ($s, $late, $USER) { $s['service']->saveAll($late, $USER); }, 'LlmPromptHubConflictException')
    && sft($s, 100, 1, 3)['content'] === 'You are a coach.' && version_count($s) === 7 && balanced($s));

// Lock first: a plain read between the BEGIN and the last lock would pin the snapshot (see sent_until_last_lock)
$s = site();
$s['service']->saveAll($both, $USER);
$sent = sent_until_last_lock($s);
check('saveAll locks every language before it reads anything', count($sent) === 2 && all_locking_reads($sent));
$events = $s['db']->events;
check('saveAll clears the section cache once, after the commit',
    array_keys($events, 'CLEAR CACHE_SECTIONS', true) === array(array_search('COMMIT', $events, true) + 1));

$s = site();
$s['service']->saveAll(array($both[1]), $USER);
$sent = sent_until_last_lock($s);
check('saveAll of one language: the first statement of the transaction is the locking read of its text',
    count($sent) === 1 && all_locking_reads($sent) && strpos($sent[0], 'FROM sections_fields_translation') !== false);

$s = site();
check('saveAll that finds a conflict opens one transaction and leaves it rolled back, the cache untouched',
    is_thrown(function () use ($s, $stale, $USER) { $s['service']->saveAll($stale, $USER); }, 'LlmPromptHubConflictException')
    && begins($s) === 1 && balanced($s) && !in_array('COMMIT', $s['db']->events, true)
    && !in_array('CLEAR CACHE_SECTIONS', $s['db']->events, true));

// A write that fails in the language saved last takes the one saved first with it
$s = site();
$flaky = new FlakyRegistry($s['db']);
$flaky->fail_from = 2;
$flaky_service = new LlmPromptHubSaveService($s['services'], $s['catalog'], $flaky, $s['profile'], $s['scripts'], $s['rules']);
$e = thrown(function () use ($flaky_service, $both, $USER) { $flaky_service->saveAll($both, $USER); });
check('a registry that fails on the second language fails saveAll with its own exception',
    $e instanceof RuntimeException && strpos($e->getMessage(), 'registry down') === 0);
check('and the language saved first is rolled back with it, the section cache cooled by type',
    sft($s, 100, 1, 2)['content'] === 'Du bist ein Coach.' && sft($s, 100, 1, 3)['content'] === 'You are a coach.'
    && version_count($s) === 7 && balanced($s) && !in_array('COMMIT', $s['db']->events, true)
    && in_array('CLEAR CACHE_SECTIONS', $s['db']->events, true) && !in_array('CLEAR (all)', $s['db']->events, true));

$s = site();
check('saveAll without items is refused', is_thrown(function () use ($s, $USER) { $s['service']->saveAll(array(), $USER); }, 'InvalidArgumentException'));
check('saveAll with the languages of two fields is refused', is_thrown(function () use ($s, $USER) {
    $s['service']->saveAll(array(array('key' => KEY_DE), array('key' => KEY_SUMMARY)), $USER);
}, 'InvalidArgumentException') && version_count($s) === 7);
check('saveAll with a language twice is refused', is_thrown(function () use ($s, $USER) {
    $s['service']->saveAll(array(array('key' => KEY_DE), array('key' => KEY_DE)), $USER);
}, 'InvalidArgumentException'));
check('saveAll of a read-only source is refused', is_thrown(function () use ($s, $USER) {
    $s['service']->saveAll(array(array('key' => 'system:0:core.memory.system:0')), $USER);
}, 'InvalidArgumentException'));
check('saveAll with too many items is refused', is_thrown(function () use ($s, $USER) {
    $s['service']->saveAll(array_fill(0, 21, array('key' => KEY_DE)), $USER);
}, 'InvalidArgumentException'));

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
