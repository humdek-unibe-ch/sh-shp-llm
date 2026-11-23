<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for LlmPromptCatalogService (plugin v1.10.0), the read side of the prompts hub.
 *
 * The catalog lists every LLM prompt of the site: `llm_prompt` CMS fields, scripts, memory rules,
 * the built-in system prompts and agentic chat personas. Its statements run against in-memory
 * SQLite tables shaped like the MySQL ones (only the columns the service reads): the real SQL is
 * executed, not pattern-matched. Host pages come from the real LlmPromptLabAccessService. The MySQL
 * dialect itself is covered by the migration and by browser runs against a real database.
 *
 * Key regression: most sections have prompt content and NO registry entry (entries are created on
 * the first save), so the list has to start from sections_fields_translation.
 *
 * Run: php tests/LlmPromptCatalogServiceTest.php
 */

require_once __DIR__ . '/../server/service/globals.php';

// Core constant used by LlmCmsLinkHelper (server/service/globals.php of the core)
if (!defined('UPDATE')) {
    define('UPDATE', 'update');
}

// LlmErrorReporter::log() writes here (a missing system prompt file is logged, not fatal)
$error_log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_catalog_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log_file);

require_once __DIR__ . '/../server/service/LlmPromptCatalogService.php';

// A warning is a failure here: a failed lookup (false) must not leak into a foreach or an array access
set_error_handler(function ($severity, $message, $file, $line) {
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

/**
 * Core query helpers in miniature: rows as associative arrays, "first row or false",
 * and false (not an exception) when a statement fails.
 */
class SqliteDb
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
        'CREATE TABLE llm_scripts (id INTEGER PRIMARY KEY, name TEXT, script TEXT, updated_at TEXT)',
        'CREATE TABLE llm_memory_rules (id INTEGER PRIMARY KEY, label TEXT, enabled INTEGER, updated_at TEXT)',
        'CREATE TABLE plugins (id INTEGER PRIMARY KEY, name TEXT, version TEXT)',
        'CREATE TABLE pages_fields_translation (id_pages INTEGER, id_fields INTEGER, id_languages INTEGER, content TEXT)',
    );

    public $pdo;
    /** @var string Statements containing this text fail (like a PDOException in the core helper) */
    public $fail_on = '';
    /** @var string[] Every statement sent */
    public $log = array();
    /** @var int Bytes of prompt text (a `content` column) the statements answered */
    public $content_bytes = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (self::SCHEMA as $ddl) {
            $this->pdo->exec($ddl);
        }
    }

    public function query_db($sql, $params = array())
    {
        $this->log[] = $sql;
        if ($this->fail_on !== '' && strpos($sql, $this->fail_on) !== false) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['content'])) {
                    $this->content_bytes += strlen($row['content']);
                }
            }
            return $rows;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function query_db_first($sql, $params = array())
    {
        $rows = $this->query_db($sql, $params);
        return (is_array($rows) && !empty($rows)) ? $rows[0] : false;
    }

    /** Core PageDb: the id, or false for an unknown page */
    public function fetch_page_id_by_keyword($keyword)
    {
        $row = $this->query_db_first('SELECT id FROM pages WHERE keyword = :keyword', array(':keyword' => $keyword));
        return $row ? (int)$row['id'] : false;
    }
}

class FakeAcl
{
    public $select = array();
    public $update = array();

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
    /** @var string[] Route keys without a route */
    public $unrouted = array();
    public $generate_throws = false;

    public function get_link_url($key, $params = array())
    {
        return in_array($key, $this->unrouted, true) ? '' : '/admin/' . $key;
    }

    public function generate($name, $params = array())
    {
        if ($this->generate_throws) {
            throw new Exception('no route');
        }
        return sprintf(
            '/cms/%s?pid=%d&sid=%d&ssid=%s',
            $name,
            $params['pid'],
            $params['sid'],
            $params['ssid'] === null ? 'none' : $params['ssid']
        ) . (isset($params['mode']) ? '&mode=' . $params['mode'] . '&type=' . $params['type'] : '');
    }
}

class FakeUserInput
{
    public $new_ui = false;

    public function is_new_ui_enabled()
    {
        return $this->new_ui;
    }
}

class FakeServices
{
    public $db;
    public $acl;
    public $router;
    public $user_input;

    public function __construct($db, $acl)
    {
        $this->db = $db;
        $this->acl = $acl;
        $this->router = new FakeRouter();
        $this->user_input = new FakeUserInput();
    }

    public function get_db()
    {
        return $this->db;
    }

    public function get_acl()
    {
        return $this->acl;
    }

    public function get_router()
    {
        return $this->router;
    }

    public function get_user_input()
    {
        return $this->user_input;
    }
}

/** System prompt files: fixed text for every registered key, optionally missing for some */
class StubAssetLoader
{
    public $missing = array();

    public function load($key)
    {
        if (in_array($key, $this->missing, true)) {
            throw new RuntimeException('Prompt asset file not found for key ' . $key);
        }
        return 'Stub text of ' . $key . ' with secret sauce';
    }
}

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
 * A site: pages, a section tree, prompts in every source, and a registry that knows only some of them.
 */
function buildDb()
{
    $db = new SqliteDb();
    $pdo = $db->pdo;

    seed($pdo, 'pages', array(
        array('id' => 1, 'keyword' => 'moduleLlmPrompts'),
        array('id' => 2, 'keyword' => 'moduleLlmScript'),
        array('id' => 3, 'keyword' => 'moduleLlmMemory'),
        array('id' => 10, 'keyword' => 'coaching'),
        array('id' => 11, 'keyword' => 'week2'),
        array('id' => 12, 'keyword' => 'secret'),
        array('id' => 13, 'keyword' => 'sh_module_llm_agentic_chat'),
    ));

    // Page 10 shows 100, 103, 105, 106; page 11 shows 110 (parent of 101) and 106; page 12 shows 102.
    // Section 104 is on no page.
    seed($pdo, 'pages_sections', array(
        array('id_pages' => 10, 'id_sections' => 100),
        array('id_pages' => 10, 'id_sections' => 103),
        array('id_pages' => 10, 'id_sections' => 105),
        array('id_pages' => 10, 'id_sections' => 106),
        array('id_pages' => 11, 'id_sections' => 110),
        array('id_pages' => 11, 'id_sections' => 106),
        array('id_pages' => 12, 'id_sections' => 102),
    ));
    seed($pdo, 'sections_hierarchy', array(
        array('parent' => 110, 'child' => 101),
        // A cycle with no page: the walk up must end
        array('parent' => 300, 'child' => 301),
        array('parent' => 301, 'child' => 300),
    ));
    seed($pdo, 'styles', array(
        array('id' => 1, 'name' => 'llmChat'),
        array('id' => 2, 'name' => 'agenticChat'),
        array('id' => 3, 'name' => 'customStyle'),
    ));
    seed($pdo, 'sections', array(
        array('id' => 100, 'name' => 'Coach chat', 'id_styles' => 1),
        array('id' => 101, 'name' => 'Weekly', 'id_styles' => 1),
        array('id' => 102, 'name' => 'Secret chat', 'id_styles' => 1),
        array('id' => 103, 'name' => 'Agent', 'id_styles' => 2),
        array('id' => 104, 'name' => 'Detached', 'id_styles' => 1),
        array('id' => 105, 'name' => 'Custom', 'id_styles' => 3),
        array('id' => 106, 'name' => 'Shared', 'id_styles' => 1),
        array('id' => 110, 'name' => 'Week container', 'id_styles' => 1),
        array('id' => 300, 'name' => 'Cycle A', 'id_styles' => 1),
        array('id' => 301, 'name' => 'Cycle B', 'id_styles' => 1),
    ));
    seed($pdo, 'fieldType', array(
        array('id' => 1, 'name' => 'llm_prompt'),
        array('id' => 2, 'name' => 'markdown'),
    ));
    seed($pdo, 'fields', array(
        array('id' => 1, 'name' => 'conversation_context', 'id_type' => 1),
        array('id' => 2, 'name' => 'therapy_summary_context', 'id_type' => 1),
        array('id' => 3, 'name' => 'title', 'id_type' => 2),
        array('id' => 4, 'name' => 'agentic_chat_personas', 'id_type' => 1),
    ));
    seed($pdo, 'languages', array(
        array('id' => 1, 'locale' => 'all'),
        array('id' => 2, 'locale' => 'de-CH'),
        array('id' => 3, 'locale' => 'en-GB'),
    ));

    $coach_de = "Du bist ein Coach. Antworte freundlich.\nDu duzt den Nutzer.\nDenke an die Ziele.";
    seed($pdo, 'sections_fields_translation', array(
        // a: has a registry entry (v7)
        array('id_sections' => 100, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => $coach_de, 'meta' => '{"prompt":{"pendingChangeNote":"x"}}'),
        // b: never versioned (the lazy case); % and _ in the text
        array('id_sections' => 100, 'id_fields' => 1, 'id_languages' => 3, 'id_genders' => 1, 'content' => 'You are a COACH 100% of the time_ and kind.', 'meta' => null),
        // c: language id 1 ("all languages")
        array('id_sections' => 100, 'id_fields' => 1, 'id_languages' => 1, 'id_genders' => 1, 'content' => 'All languages prompt', 'meta' => null),
        // d: gender 2 is ignored
        array('id_sections' => 100, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 2, 'content' => 'gender two row must be ignored', 'meta' => null),
        // e: not an llm_prompt field
        array('id_sections' => 100, 'id_fields' => 3, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'Title is not a prompt', 'meta' => null),
        // f: deeper in the tree (page 11 shows its parent 110)
        array('id_sections' => 101, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => "Wöchentlicher Prompt mit äöü und Emoji \u{1F600}", 'meta' => null),
        // g: only on page 12
        array('id_sections' => 102, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'geheimer secret prompt', 'meta' => null),
        // h: another style, another plugin
        array('id_sections' => 103, 'id_fields' => 2, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'agentic summary prompt', 'meta' => null),
        // i: section on no page
        array('id_sections' => 104, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'detached prompt without page', 'meta' => null),
        // j: unknown style; "timeX" must not match a search for "time_"
        array('id_sections' => 105, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'timeX and 100 percent of custom style', 'meta' => null),
        // k: empty, but the registry knows it
        array('id_sections' => 100, 'id_fields' => 2, 'id_languages' => 2, 'id_genders' => 1, 'content' => '', 'meta' => null),
        // l: empty and unknown to the registry: hidden
        array('id_sections' => 101, 'id_fields' => 2, 'id_languages' => 2, 'id_genders' => 1, 'content' => '', 'meta' => null),
        // m: section on two pages
        array('id_sections' => 106, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1, 'content' => 'Shared prompt on two pages', 'meta' => null),
    ));

    seed($pdo, 'users', array(
        array('id' => 1, 'name' => 'Jane Admin'),
        array('id' => 2, 'name' => 'Bob'),
    ));
    seed($pdo, 'lookups', array(
        array('id' => 1, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'style_field'),
        array('id' => 2, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_script'),
        array('id' => 3, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_memory_rule'),
        // Another lookup type with the same code: must never be read as an owner type
        array('id' => 4, 'type_code' => 'something_else', 'lookup_code' => 'style_field'),
    ));
    seed($pdo, 'llm_prompt_entries', array(
        array('id' => 1, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 100, 'prompt_slot' => 'conversation_context', 'title' => 'Conversation context'),
        array('id' => 2, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 100, 'prompt_slot' => 'therapy_summary_context', 'title' => null),
        array('id' => 3, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 999, 'prompt_slot' => 'conversation_context', 'title' => 'Ghost'),
        array('id' => 4, 'id_llm_prompt_owner_types' => 2, 'owner_id' => 5, 'prompt_slot' => 'script', 'title' => 'Summarizer'),
        array('id' => 5, 'id_llm_prompt_owner_types' => 2, 'owner_id' => 6, 'prompt_slot' => 'script', 'title' => 'Old script'),
        array('id' => 6, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 7, 'prompt_slot' => 'memory_rule', 'title' => 'Goals'),
        array('id' => 7, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 9, 'prompt_slot' => 'memory_rule', 'title' => 'Dead rule'),
        array('id' => 8, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 10, 'prompt_slot' => 'memory_rule', 'title' => null),
        array('id' => 9, 'id_llm_prompt_owner_types' => 4, 'owner_id' => 100, 'prompt_slot' => 'conversation_context', 'title' => 'Decoy'),
        array('id' => 11, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 13, 'prompt_slot' => 'memory_rule', 'title' => null),
    ));
    seed($pdo, 'llm_prompt_locales', array(
        array('id' => 1, 'id_llm_prompt_entries' => 1, 'id_languages' => 2, 'active_version_id' => 91, 'active_version_no' => 7),
        array('id' => 2, 'id_llm_prompt_entries' => 2, 'id_languages' => 2, 'active_version_id' => null, 'active_version_no' => 0),
        array('id' => 3, 'id_llm_prompt_entries' => 3, 'id_languages' => 2, 'active_version_id' => 93, 'active_version_no' => 2),
        array('id' => 4, 'id_llm_prompt_entries' => 4, 'id_languages' => 1, 'active_version_id' => 94, 'active_version_no' => 1),
        array('id' => 5, 'id_llm_prompt_entries' => 5, 'id_languages' => 1, 'active_version_id' => 95, 'active_version_no' => 4),
        // Rule 7: a stream from before rules were stored under language 1
        array('id' => 6, 'id_llm_prompt_entries' => 6, 'id_languages' => null, 'active_version_id' => 96, 'active_version_no' => 3),
        array('id' => 7, 'id_llm_prompt_entries' => 7, 'id_languages' => 1, 'active_version_id' => 97, 'active_version_no' => 1),
        // Rule 10: two streams under CMS languages, none under 1: the one with more versions is shown
        array('id' => 8, 'id_llm_prompt_entries' => 8, 'id_languages' => 2, 'active_version_id' => 98, 'active_version_no' => 5),
        array('id' => 9, 'id_llm_prompt_entries' => 8, 'id_languages' => 3, 'active_version_id' => 99, 'active_version_no' => 2),
        // Decoy entry of another owner type, in language 3 of the section field that has no real stream
        array('id' => 10, 'id_llm_prompt_entries' => 9, 'id_languages' => 3, 'active_version_id' => 89, 'active_version_no' => 99),
        // Rule 13: has the neutral stream (language 1) and a longer one under language 2: the neutral one wins
        array('id' => 11, 'id_llm_prompt_entries' => 11, 'id_languages' => 1, 'active_version_id' => 120, 'active_version_no' => 1),
        array('id' => 12, 'id_llm_prompt_entries' => 11, 'id_languages' => 2, 'active_version_id' => 121, 'active_version_no' => 9),
    ));
    seed($pdo, 'llm_prompt_versions', array(
        array('id' => 90, 'id_llm_prompt_locales' => 1, 'version_no' => 6, 'template_raw' => 'Alter Prompt', 'change_note' => 'first', 'id_users_created' => 2, 'created_at' => '2026-11-01 10:00:00'),
        array('id' => 91, 'id_llm_prompt_locales' => 1, 'version_no' => 7, 'template_raw' => $coach_de, 'change_note' => 'Friendlier', 'id_users_created' => 1, 'created_at' => '2026-11-20 14:03:00'),
        array('id' => 93, 'id_llm_prompt_locales' => 3, 'version_no' => 2, 'template_raw' => 'ghost prompt text', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-10-01 08:00:00'),
        array('id' => 94, 'id_llm_prompt_locales' => 4, 'version_no' => 1, 'template_raw' => 'Summarize the chat. 50% done', 'change_note' => 'init', 'id_users_created' => 2, 'created_at' => '2026-11-02 09:00:00'),
        array('id' => 95, 'id_llm_prompt_locales' => 5, 'version_no' => 4, 'template_raw' => 'old script text', 'change_note' => null, 'id_users_created' => 2, 'created_at' => '2026-09-01 09:00:00'),
        array('id' => 96, 'id_llm_prompt_locales' => 6, 'version_no' => 3, 'template_raw' => 'Remember the participant goals', 'change_note' => 'goals', 'id_users_created' => 1, 'created_at' => '2026-11-05 12:00:00'),
        array('id' => 97, 'id_llm_prompt_locales' => 7, 'version_no' => 1, 'template_raw' => 'dead rule text', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-08-01 12:00:00'),
        array('id' => 98, 'id_llm_prompt_locales' => 8, 'version_no' => 5, 'template_raw' => 'Multi rule richest stream', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-06 12:00:00'),
        array('id' => 99, 'id_llm_prompt_locales' => 9, 'version_no' => 2, 'template_raw' => 'Multi rule weaker stream', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-07 12:00:00'),
        array('id' => 89, 'id_llm_prompt_locales' => 10, 'version_no' => 99, 'template_raw' => 'decoy text', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-08 12:00:00'),
        array('id' => 120, 'id_llm_prompt_locales' => 11, 'version_no' => 1, 'template_raw' => 'Neutral stream wins', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-09 12:00:00'),
        array('id' => 121, 'id_llm_prompt_locales' => 12, 'version_no' => 9, 'template_raw' => 'Language two stream', 'change_note' => null, 'id_users_created' => 1, 'created_at' => '2026-11-10 12:00:00'),
    ));

    $needle_script = "needle at line one\n" . str_repeat("filler line without it\n", 20)
        . "second NEEDLE at line 22\n" . str_repeat("filler line without it\n", 20)
        . "third needle line 43\n" . str_repeat("filler line without it\n", 20)
        . 'fourth needle line 64';
    seed($pdo, 'llm_scripts', array(
        array('id' => 5, 'name' => 'Summarizer', 'script' => 'Summarize the chat. 50% done', 'updated_at' => '2026-11-03 10:00:00'),
        array('id' => 11, 'name' => 'No entry script', 'script' => 'script without registry entry', 'updated_at' => '2026-11-04 10:00:00'),
        array('id' => 12, 'name' => 'Needle script', 'script' => $needle_script, 'updated_at' => '2026-11-04 11:00:00'),
        array('id' => 14, 'name' => 'Big script', 'script' => str_repeat('x', 60000) . 'tail', 'updated_at' => '2026-11-04 12:00:00'),
        array('id' => 15, 'name' => 'Bad bytes', 'script' => "bad \xC3\x28 bytes", 'updated_at' => '2026-11-04 13:00:00'),
    ));
    seed($pdo, 'llm_memory_rules', array(
        array('id' => 7, 'label' => 'Goals', 'enabled' => 1, 'updated_at' => '2026-11-05 12:00:00'),
        array('id' => 8, 'label' => 'Mood', 'enabled' => 0, 'updated_at' => '2026-11-05 13:00:00'),
        array('id' => 10, 'label' => 'Multi', 'enabled' => 1, 'updated_at' => '2026-11-06 12:00:00'),
        array('id' => 13, 'label' => 'Neutral wins', 'enabled' => 1, 'updated_at' => '2026-11-09 12:00:00'),
    ));

    return $db;
}

function allAccess()
{
    return new FakeAcl(array(1, 2, 3, 10, 11, 12, 13), array(1, 2, 3, 10, 11, 12, 13));
}

/**
 * @return LlmPromptCatalogService
 */
function catalog($db, $acl = null, $loader = null)
{
    return new LlmPromptCatalogService(new FakeServices($db, $acl ?: allAccess()), null, $loader ?: new StubAssetLoader());
}

function byKey(array $result)
{
    $rows = array();
    foreach ($result['rows'] as $row) {
        $rows[$row['key']] = $row;
    }
    return $rows;
}

function keysOf(array $result)
{
    $keys = array_map(function ($row) {
        return $row['key'];
    }, $result['rows']);
    sort($keys);
    return $keys;
}

function throws($callable, $class = 'InvalidArgumentException')
{
    try {
        $callable();
    } catch (Exception $e) {
        return $e instanceof $class;
    }
    return false;
}

$system_count = count(LlmPromptAssetRegistry::getMap());
$USER = 7;

$db = buildDb();
$service = catalog($db);

// --- The key regression: prompts without a registry entry -----------------------------------------

$all = $service->listPrompts(array(), $USER);
$rows = byKey($all);

check('a style field prompt with NO registry entry is listed (lazy case)', isset($rows['style_field:100:conversation_context:3']));
$lazy = $rows['style_field:100:conversation_context:3'];
check('the lazy row has no version, no editor and no date',
    $lazy['activeVersionNo'] === null && $lazy['activeVersionId'] === null && $lazy['updatedBy'] === null && $lazy['updatedAt'] === null);
check('the decoy entry of another lookup type is not read as its registry data', $lazy['activeVersionNo'] === null);
check('the lazy row is editable for a user with full access', $lazy['editable'] === true && $lazy['readOnlyReason'] === null);
$contract = array(
    'key', 'sourceType', 'plugin', 'title', 'ownerLabel', 'pageLabel', 'languageId', 'languageCode', 'preview',
    'contentLength', 'activeVersionNo', 'activeVersionId', 'updatedAt', 'updatedBy', 'editable', 'readOnlyReason',
    'orphaned', 'goToUrl', 'matches', 'ownerId', 'promptSlot', 'styleName', 'assetPath', 'hostPages',
);
$row_keys = array_keys($lazy);
sort($row_keys);
sort($contract);
check('a row carries exactly the contract fields (the list contract plus owner, slot, style, asset path, pages)', $row_keys === $contract);
check('the title is the humanized field name', $lazy['title'] === 'Conversation context');
check('the owner label names section, id and style', $lazy['ownerLabel'] === 'Coach chat (section 100, style llmChat)');
check('the page label is the keyword of the first host page', $lazy['pageLabel'] === 'coaching');
check('the content length counts characters', $lazy['contentLength'] === mb_strlen('You are a COACH 100% of the time_ and kind.'));
check('list rows carry a preview, never the full content', !array_key_exists('content', $lazy) && $lazy['preview'] === 'You are a COACH 100% of the time_ and kind.');

// --- One row per language, gender and field type ----------------------------------------------------

check('one row per language: de-CH, en-GB and "all" are three rows',
    isset($rows['style_field:100:conversation_context:2'], $rows['style_field:100:conversation_context:3'], $rows['style_field:100:conversation_context:1']));
check('rows carry language id and code',
    $rows['style_field:100:conversation_context:2']['languageId'] === 2 && $rows['style_field:100:conversation_context:2']['languageCode'] === 'de-CH'
    && $rows['style_field:100:conversation_context:3']['languageCode'] === 'en-GB' && $rows['style_field:100:conversation_context:1']['languageId'] === 1);
check('gender 2 rows are ignored (one row for the language, with the gender 1 text)',
    count(array_filter($all['rows'], function ($r) {
        return $r['key'] === 'style_field:100:conversation_context:2';
    })) === 1 && strpos($rows['style_field:100:conversation_context:2']['preview'], 'Du bist ein Coach') === 0);
check('fields that are not llm_prompt are ignored', count(array_filter($all['rows'], function ($r) {
    return $r['promptSlot'] === 'title';
})) === 0);
check('an empty field is hidden when the registry does not know it', !isset($rows['style_field:101:therapy_summary_context:2']));
check('an empty field is listed when the registry knows it', isset($rows['style_field:100:therapy_summary_context:2'])
    && $rows['style_field:100:therapy_summary_context:2']['contentLength'] === 0
    && $rows['style_field:100:therapy_summary_context:2']['activeVersionNo'] === null);

// --- Registry data joined in -------------------------------------------------------------------------

$joined = $rows['style_field:100:conversation_context:2'];
check('the registry joins the active version number and id', $joined['activeVersionNo'] === 7 && $joined['activeVersionId'] === 91);
check('the registry joins the last editor and date of the active version',
    $joined['updatedBy'] === 'Jane Admin' && $joined['updatedAt'] === '2026-11-20 14:03:00');
check('the plugin of a style comes from one map', $joined['plugin'] === 'LLM' && $rows['style_field:103:therapy_summary_context:2']['plugin'] === 'Agentic chat');
check('an unknown style is its own plugin label', $rows['style_field:105:conversation_context:2']['plugin'] === 'customStyle'
    && $rows['style_field:105:conversation_context:2']['styleName'] === 'customStyle');

// --- Search ---------------------------------------------------------------------------------------------

$hits = $service->listPrompts(array('q' => 'coach'), $USER);
check('search matches the content case-insensitively',
    keysOf($hits) === array('style_field:100:conversation_context:2', 'style_field:100:conversation_context:3'));
check('search is case-insensitive in the other direction', keysOf($service->listPrompts(array('q' => 'COACH'), $USER)) === keysOf($hits));
$hit_rows = byKey($hits);
check('a hit carries a snippet with its line number',
    count($hit_rows['style_field:100:conversation_context:3']['matches']) === 1
    && $hit_rows['style_field:100:conversation_context:3']['matches'][0]['line'] === 1
    && stripos($hit_rows['style_field:100:conversation_context:3']['matches'][0]['text'], 'coach') !== false);
check('rows without a search have no matches', $lazy['matches'] === array());

$needle = byKey($service->listPrompts(array('q' => 'needle'), $USER));
check('search finds scripts by their text', isset($needle['llm_script:12:script:1']) && count($needle) === 1);
$snips = $needle['llm_script:12:script:1']['matches'];
check('a row shows at most three snippets, at the right lines', count($snips) === 3
    && array_map(function ($s) {
        return $s['line'];
    }, $snips) === array(1, 22, 43));
check('a snippet is the hit with limited text around it', stripos($snips[1]['text'], 'needle') !== false
    && mb_strlen($snips[1]['text']) <= 2 * LlmPromptCatalogService::SNIPPET_RADIUS + mb_strlen('needle') + 2);

check('search escapes % (only the literal one matches)',
    keysOf($service->listPrompts(array('q' => '100%'), $USER)) === array('style_field:100:conversation_context:3'));
check('search escapes _ (timeX does not match time_)',
    keysOf($service->listPrompts(array('q' => 'time_'), $USER)) === array('style_field:100:conversation_context:3'));
check('a lone % finds only literal percent signs',
    keysOf($service->listPrompts(array('q' => '%'), $USER)) === array('llm_script:5:script:1', 'style_field:100:conversation_context:3'));
check('search handles umlauts', keysOf($service->listPrompts(array('q' => 'äöü'), $USER)) === array('style_field:101:conversation_context:2'));
check('search handles emoji', keysOf($service->listPrompts(array('q' => "\u{1F600}"), $USER)) === array('style_field:101:conversation_context:2'));

$db->log = array();
$unique = 'UniqueNeedle123';
$injected = $service->listPrompts(array('q' => "' OR '1'='1"), $USER);
$service->listPrompts(array('q' => $unique), $USER);
$service->listPrompts(array('style' => "x' OR '1'='1", 'plugin' => $unique), $USER);
check('a quote in the search text is data, not SQL', $injected['total'] === 0);
check('values are bound: no statement contains the search text',
    count(array_filter($db->log, function ($sql) use ($unique) {
        return strpos($sql, $unique) !== false || strpos($sql, "' OR '1'='1") !== false;
    })) === 0);
check('the catalog only reads', count(array_filter($db->log, function ($sql) {
    return !preg_match('/^\s*(SELECT|WITH)\b/i', $sql);
})) === 0);
check('a search text over 200 characters is cut, not refused', $service->listPrompts(array('q' => str_repeat('a', 250)), $USER)['total'] === 0);

// --- Filters ---------------------------------------------------------------------------------------------

$scripts = $service->listPrompts(array('sourceType' => 'llm_script'), $USER);
check('the source type filter keeps only that source', $scripts['total'] === 5
    && count(array_filter($scripts['rows'], function ($r) {
        return $r['sourceType'] === 'llm_script';
    })) === 5);
check('the style filter keeps only that style', keysOf($service->listPrompts(array('style' => 'agenticChat'), $USER)) === array('style_field:103:therapy_summary_context:2'));
check('the language filter keeps only that language', keysOf($service->listPrompts(array('languageId' => '3'), $USER)) === array('style_field:100:conversation_context:3'));
check('the page filter keeps the prompts shown on that page',
    keysOf($service->listPrompts(array('pageId' => '11'), $USER)) === array('style_field:101:conversation_context:2', 'style_field:106:conversation_context:2'));
check('the plugin filter keeps only that plugin', keysOf($service->listPrompts(array('plugin' => 'customStyle'), $USER)) === array('style_field:105:conversation_context:2'));
check('an unknown source type is refused', throws(function () use ($service, $USER) {
    $service->listPrompts(array('sourceType' => 'bogus'), $USER);
}));
check('a filter that is no number is refused', throws(function () use ($service, $USER) {
    $service->listPrompts(array('pageId' => 'abc'), $USER);
}) && throws(function () use ($service, $USER) {
    $service->listPrompts(array('limit' => '-1'), $USER);
}));
check('an array as a filter value is refused', throws(function () use ($service, $USER) {
    $service->listPrompts(array('style' => array('x')), $USER);
}));
$paged = $service->listPrompts(array('limit' => '2', 'offset' => '1'), $USER);
check('limit and offset cut the list, total stays', count($paged['rows']) === 2 && $paged['total'] === $all['total']
    && $paged['rows'][0]['key'] === $all['rows'][1]['key']);
check('limit is at least 1', count($service->listPrompts(array('limit' => '0'), $USER)['rows']) === 1);

$big = buildDb();
$big_rows = array();
for ($i = 1; $i <= 600; $i++) {
    $big_rows[] = array('id' => 1000 + $i, 'name' => 'Bulk ' . $i, 'script' => 'bulk', 'updated_at' => null);
}
seed($big->pdo, 'llm_scripts', $big_rows);
$capped = catalog($big)->listPrompts(array('limit' => '9999'), $USER);
check('limit is capped at 500', count($capped['rows']) === 500 && $capped['total'] > 600);
$default = catalog($big)->listPrompts(array(), $USER);
check('the default limit is 200', count($default['rows']) === 200);

// --- The list reads the text of the rows it shows, not of every prompt -----------------------------------------------

// What listing a page of 5 costs on the plain site: the text of its few small prompts
$plain = buildDb();
catalog($plain)->listPrompts(array('limit' => '5'), $USER);
$plain_bytes = $plain->content_bytes;

// 40 more sections on page 10, each with a prompt of about 19 KB, and one that is only blanks
$heavy = buildDb();
$heavy_text = 'Intro of the heavy prompt. ' . str_repeat('lorem ipsum ', 1600) . 'NEEDLE at the end';
$heavy_sections = array();
$heavy_hosts = array();
$heavy_texts = array();
for ($i = 1; $i <= 41; $i++) {
    $heavy_sections[] = array('id' => 500 + $i, 'name' => 'Heavy ' . $i, 'id_styles' => 1);
    $heavy_hosts[] = array('id_pages' => 10, 'id_sections' => 500 + $i);
    $heavy_texts[] = array('id_sections' => 500 + $i, 'id_fields' => 1, 'id_languages' => 2, 'id_genders' => 1,
        'content' => $i === 41 ? '   ' : $heavy_text, 'meta' => null);
}
seed($heavy->pdo, 'sections', $heavy_sections);
seed($heavy->pdo, 'pages_sections', $heavy_hosts);
seed($heavy->pdo, 'sections_fields_translation', $heavy_texts);

$heavy_list = catalog($heavy)->listPrompts(array('limit' => '5'), $USER);
check('a page of 5 rows reads the text of 5 rows, however many prompts the site has',
    count($heavy_list['rows']) === 5 && $heavy_list['total'] > 40
    && $heavy->content_bytes - $plain_bytes < 5 * strlen($heavy_text) + 5000);

$heavy->content_bytes = 0;
$found = catalog($heavy)->listPrompts(array('q' => 'needle', 'limit' => '3', 'offset' => '2'), $USER);
check('a search reads the text of the rows on the page it answers, not of every hit',
    count($found['rows']) === 3 && $heavy->content_bytes < 3 * strlen($heavy_text) + 5000);
$shown = array_values(array_filter($found['rows'], function ($row) {
    return $row['sourceType'] === 'style_field';
}));
check('the rows on a later page carry their length, preview and snippets from the text',
    count($shown) === 3 && $shown[0]['contentLength'] === mb_strlen($heavy_text)
    && strpos($shown[0]['preview'], 'Intro of the heavy prompt. lorem ipsum') === 0 && mb_strlen($shown[0]['preview']) === 200
    && count($shown[0]['matches']) === 1 && $shown[0]['matches'][0]['line'] === 1
    && strpos($shown[0]['matches'][0]['text'], 'NEEDLE at the end') !== false);

$heavy_rows = byKey(catalog($heavy)->listPrompts(array('limit' => '500'), $USER));
check('a prompt of only blanks is listed (it has text, however little), with its length',
    isset($heavy_rows['style_field:541:conversation_context:2']) && $heavy_rows['style_field:541:conversation_context:2']['contentLength'] === 3
    && $heavy_rows['style_field:541:conversation_context:2']['preview'] === '');
check('the host page of such a section is still known',
    $heavy_rows['style_field:501:conversation_context:2']['hostPages'] === array(array('id' => 10, 'label' => 'coaching')));

// The pages that show the sections come from a few statements, not three per section
$heavy->log = array();
catalog($heavy)->listPrompts(array('limit' => '500'), $USER);
$host_statements = count(array_filter($heavy->log, function ($sql) {
    return preg_match('/^SELECT .* FROM (pages_sections|sections_navigation|sections_hierarchy) WHERE (id_sections|child) IN/s', trim($sql)) === 1;
}));
check('the host pages of 50 sections cost a handful of statements, not three per section', $host_statements > 0 && $host_statements <= 15);

// --- Orphans ------------------------------------------------------------------------------------------------

check('orphans are hidden by default', !isset($rows['style_field:104:conversation_context:2'])
    && !isset($rows['style_field:999:conversation_context:2']) && !isset($rows['llm_script:6:script:1']) && !isset($rows['llm_memory_rule:9:memory_rule:1']));
$with = byKey($service->listPrompts(array('includeOrphaned' => '1'), $USER));
check('a section on no page is an orphan when asked for', isset($with['style_field:104:conversation_context:2'])
    && $with['style_field:104:conversation_context:2']['orphaned'] === true);
$orphan = $with['style_field:104:conversation_context:2'];
check('an orphan is never editable and has no link', $orphan['editable'] === false && $orphan['readOnlyReason'] === 'orphaned'
    && $orphan['goToUrl'] === null && $orphan['hostPages'] === array());
check('registry entries of a deleted section are orphans', isset($with['style_field:999:conversation_context:2'])
    && $with['style_field:999:conversation_context:2']['ownerLabel'] === 'Deleted section #999'
    && $with['style_field:999:conversation_context:2']['preview'] === 'ghost prompt text'
    && $with['style_field:999:conversation_context:2']['activeVersionNo'] === 2);
check('registry entries of a deleted script are orphans', isset($with['llm_script:6:script:1'])
    && $with['llm_script:6:script:1']['title'] === 'Old script' && $with['llm_script:6:script:1']['ownerLabel'] === 'Deleted script #6');
check('registry entries of a deleted memory rule are orphans', isset($with['llm_memory_rule:9:memory_rule:1'])
    && $with['llm_memory_rule:9:memory_rule:1']['ownerLabel'] === 'Deleted memory rule #9'
    && $with['llm_memory_rule:9:memory_rule:1']['preview'] === 'dead rule text');
check('no orphan is editable', count(array_filter($with, function ($r) {
    return $r['orphaned'] && ($r['editable'] || $r['readOnlyReason'] !== 'orphaned' || $r['goToUrl'] !== null);
})) === 0);
check('exactly four orphans exist', count(array_filter($with, function ($r) {
    return $r['orphaned'];
})) === 4);
check('search also finds orphans when they are shown', keysOf($service->listPrompts(array('q' => 'ghost', 'includeOrphaned' => 'true'), $USER)) === array('style_field:999:conversation_context:2'));
// The registry knows the language id only: the locale code has to come from the languages table
check('an orphan found in the registry carries the locale code of its language', $with['style_field:999:conversation_context:2']['languageId'] === 2
    && $with['style_field:999:conversation_context:2']['languageCode'] === 'de-CH');
$ghost = $service->listPrompts(array('q' => 'ghost', 'includeOrphaned' => 'true'), $USER);
check('the language facet of such an orphan shows the code, not the id', $ghost['facets']['languages'] === array(array('id' => 2, 'code' => 'de-CH')));

// --- Access ------------------------------------------------------------------------------------------------------

check('a full-access user sees every live style field prompt', count(array_filter($all['rows'], function ($r) {
    return $r['sourceType'] === 'style_field';
})) === 9);

$only_coaching = catalog($db, new FakeAcl(array(1, 10), array()));
$limited = byKey($only_coaching->listPrompts(array(), $USER));
check('rows of sections the user cannot select are not listed (page 11 and 12 hosts)',
    !isset($limited['style_field:101:conversation_context:2']) && !isset($limited['style_field:102:conversation_context:2']));
check('a section on a page the user may select is listed', isset($limited['style_field:100:conversation_context:2'])
    && isset($limited['style_field:106:conversation_context:2']));
check('a section on two pages is listed when one of them is selectable, with only that page',
    $limited['style_field:106:conversation_context:2']['hostPages'] === array(array('id' => 10, 'label' => 'coaching')));
check('select without update is listed read only', $limited['style_field:100:conversation_context:2']['editable'] === false
    && $limited['style_field:100:conversation_context:2']['readOnlyReason'] === 'no_access');
check('without access to the scripts and memory pages no script or rule is listed', count(array_filter($limited, function ($r) {
    return $r['sourceType'] === 'llm_script' || $r['sourceType'] === 'llm_memory_rule';
})) === 0);
check('system prompts need the hub page only', count(array_filter($limited, function ($r) {
    return $r['sourceType'] === 'system';
})) === $system_count);

$no_hub_update = byKey(catalog($db, new FakeAcl(array(1, 2, 3, 10, 11, 12), array(2, 3, 10, 11, 12)))->listPrompts(array(), $USER));
check('update on the section page is not enough without update on the hub page', count(array_filter($no_hub_update, function ($r) {
    return $r['editable'];
})) === 0);

$page10 = byKey(catalog($db, new FakeAcl(array(1, 2, 3, 10, 11, 12), array(1, 10)))->listPrompts(array(), $USER));
check('update on one host page makes the row editable', $page10['style_field:100:conversation_context:2']['editable'] === true
    && $page10['style_field:106:conversation_context:2']['editable'] === true);
check('update on another page does not', $page10['style_field:101:conversation_context:2']['editable'] === false
    && $page10['style_field:101:conversation_context:2']['readOnlyReason'] === 'no_access');
check('update on the hub and section page does not make scripts or rules editable', $page10['llm_script:5:script:1']['editable'] === false
    && $page10['llm_memory_rule:7:memory_rule:1']['editable'] === false);

$script_admin = byKey(catalog($db, new FakeAcl(array(1, 2, 3), array(1, 2)))->listPrompts(array(), $USER));
check('scripts are editable with update on the scripts page, rules are not without the memory page',
    $script_admin['llm_script:5:script:1']['editable'] === true && $script_admin['llm_memory_rule:7:memory_rule:1']['editable'] === false);

$no_hub = catalog($db, new FakeAcl(array(10, 11, 12, 2, 3), array(10, 11, 12, 2, 3)))->listPrompts(array(), $USER);
check('without select on the hub page nothing is listed', $no_hub['rows'] === array() && $no_hub['total'] === 0);
check('without select on the hub page a prompt cannot be read either',
    catalog($db, new FakeAcl(array(10), array()))->getPrompt('style_field:100:conversation_context:2', $USER) === null);

check('a section the user may not select cannot be read by key', catalog($db, new FakeAcl(array(1, 10), array()))->getPrompt('style_field:102:conversation_context:2', $USER) === null);
check('a section the user may select can be read by key', $only_coaching->getPrompt('style_field:100:conversation_context:2', $USER) !== null);

// --- System prompts --------------------------------------------------------------------------------------------------

$system = array_filter($all['rows'], function ($r) {
    return $r['sourceType'] === 'system';
});
check('every registered system prompt is listed', count($system) === $system_count);
check('system prompts are read only, without versions or link', count(array_filter($system, function ($r) {
    return $r['editable'] === false && $r['readOnlyReason'] === 'system_prompt' && $r['goToUrl'] === null
        && $r['activeVersionNo'] === null && $r['orphaned'] === false && $r['languageId'] === null;
})) === $system_count);
check('a system prompt shows its asset path', $rows['system:0:core.memory.system:0']['assetPath'] === 'assets/prompts/core/memory/system.md'
    && $rows['system:0:core.memory.system:0']['ownerLabel'] === 'assets/prompts/core/memory/system.md');

$real = catalog($db, null, new LlmPromptAssetLoader());
$memory_system = (new LlmPromptAssetLoader())->load('core.memory.system');
$probe = mb_substr($memory_system, 40, 30);
$system_hits = byKey($real->listPrompts(array('q' => $probe), $USER));
check('search finds a word in a real system prompt, with a snippet', isset($system_hits['system:0:core.memory.system:0'])
    && count($system_hits['system:0:core.memory.system:0']['matches']) >= 1);
$system_row = $real->getPrompt('system:0:core.memory.system:0', $USER);
check('the detail of a system prompt is the file content, without versions', $system_row['content'] === $memory_system
    && $system_row['versions'] === array() && $system_row['editable'] === false);

$loader = new StubAssetLoader();
$loader->missing = array('core.memory.system');
file_put_contents($error_log_file, '');
$partial = catalog($db, null, $loader)->listPrompts(array('sourceType' => 'system'), $USER);
check('a missing system prompt file is skipped, the rest is listed', $partial['total'] === $system_count - 1);
$logged = (string)file_get_contents($error_log_file);
check('the skipped file is logged without prompt text', strpos($logged, 'Loading a system prompt') !== false
    && strpos($logged, 'secret sauce') === false);

// --- Personas -----------------------------------------------------------------------------------------------------------

$db->log = array();
catalog($db)->listPrompts(array(), $USER);
check('without the agentic plugin no persona is listed and its tables are not queried',
    count(array_filter($all['rows'], function ($r) {
        return $r['sourceType'] === 'agentic_persona';
    })) === 0 && count(array_filter($db->log, function ($sql) {
        return strpos($sql, 'pages_fields_translation') !== false;
    })) === 0);

seed($db->pdo, 'plugins', array(array('id' => 1, 'name' => 'llm_agentic_chat', 'version' => 'v1.1.0')));
$personas_json = json_encode(array(
    array('name' => 'Calm Coach', 'description' => 'Speak calmly and slowly', 'enabled' => true),
    array('name' => 'Bad', 'description' => ''),
    'junk',
    array('key' => 'calm_coach', 'name' => 'Calm Coach Two', 'description' => 'same key as the first', 'enabled' => false),
    array('name' => '   ', 'description' => 'no name, no persona'),
));
seed($db->pdo, 'pages_fields_translation', array(array('id_pages' => 13, 'id_fields' => 4, 'id_languages' => 1, 'content' => $personas_json)));
$with_personas = byKey(catalog($db)->listPrompts(array('sourceType' => 'agentic_persona'), $USER));
check('personas are listed once the plugin is installed', array_keys($with_personas) === array(
    'agentic_persona:0:bad:0', 'agentic_persona:0:calm_coach:0', 'agentic_persona:0:calm_coach_2:0',
));
$persona = $with_personas['agentic_persona:0:calm_coach:0'];
check('a persona is link only and links to the settings page', $persona['editable'] === false && $persona['readOnlyReason'] === 'persona_link_only'
    && $persona['goToUrl'] === '/admin/sh_module_llm_agentic_chat' && $persona['title'] === 'Calm Coach' && $persona['plugin'] === 'Agentic chat'
    && $persona['languageId'] === null && $persona['preview'] === 'Speak calmly and slowly');
check('a persona with the same key gets a number and shows it is disabled',
    $with_personas['agentic_persona:0:calm_coach_2:0']['ownerLabel'] === 'Agentic chat persona (disabled)');
check('search finds a persona by its description', keysOf(catalog($db)->listPrompts(array('q' => 'calmly'), $USER)) === array('agentic_persona:0:calm_coach:0'));
check('a persona can be read by key', catalog($db)->getPrompt('agentic_persona:0:calm_coach:0', $USER)['content'] === 'Speak calmly and slowly');
check('personas need select access on the settings page', count(catalog($db, new FakeAcl(array(1, 2, 3, 10), array()))->listPrompts(array('sourceType' => 'agentic_persona'), $USER)['rows']) === 0);

$db->pdo->exec("UPDATE pages_fields_translation SET content = 'not json{'");
check('invalid persona JSON gives no rows and no warning', catalog($db)->listPrompts(array('sourceType' => 'agentic_persona'), $USER)['total'] === 0);
$db->pdo->exec("UPDATE pages_fields_translation SET content = '\"just a string\"'");
check('persona JSON that is not a list gives no rows', catalog($db)->listPrompts(array('sourceType' => 'agentic_persona'), $USER)['total'] === 0);
$db->pdo->exec('DELETE FROM pages_fields_translation');
check('no persona setting gives no rows', catalog($db)->listPrompts(array('sourceType' => 'agentic_persona'), $USER)['total'] === 0);
$db->pdo->exec('DELETE FROM plugins');

// --- Scripts, memory rules and their registry streams --------------------------------------------------------------------

check('a script without a registry entry is listed', isset($rows['llm_script:11:script:1']) && $rows['llm_script:11:script:1']['activeVersionNo'] === null
    && $rows['llm_script:11:script:1']['updatedAt'] === '2026-11-04 10:00:00');
check('a script with an entry shows its version', $rows['llm_script:5:script:1']['activeVersionNo'] === 1 && $rows['llm_script:5:script:1']['updatedBy'] === 'Bob'
    && $rows['llm_script:5:script:1']['updatedAt'] === '2026-11-02 09:00:00' && $rows['llm_script:5:script:1']['title'] === 'Summarizer');
check('a memory rule without an entry is listed, empty and editable', isset($rows['llm_memory_rule:8:memory_rule:1'])
    && $rows['llm_memory_rule:8:memory_rule:1']['contentLength'] === 0 && $rows['llm_memory_rule:8:memory_rule:1']['editable'] === true
    && $rows['llm_memory_rule:8:memory_rule:1']['ownerLabel'] === 'Memory rule #8 (disabled)');
check('the instructions of a memory rule come from the registry', $rows['llm_memory_rule:10:memory_rule:1']['preview'] === 'Multi rule richest stream'
    && $rows['llm_memory_rule:10:memory_rule:1']['title'] === 'Multi');
check('a stream from before language 1 is still shown (the one without language)', $rows['llm_memory_rule:7:memory_rule:1']['activeVersionNo'] === 3
    && $rows['llm_memory_rule:7:memory_rule:1']['preview'] === 'Remember the participant goals');
check('without a neutral stream the one with the most versions is shown', $rows['llm_memory_rule:10:memory_rule:1']['activeVersionNo'] === 5);
check('the neutral stream wins over a longer one', $rows['llm_memory_rule:13:memory_rule:1']['activeVersionNo'] === 1
    && $rows['llm_memory_rule:13:memory_rule:1']['preview'] === 'Neutral stream wins');
check('search finds the instructions of a memory rule', keysOf($service->listPrompts(array('q' => 'participant goals'), $USER)) === array('llm_memory_rule:7:memory_rule:1'));
check('search finds a memory rule by its label', keysOf($service->listPrompts(array('q' => 'mood'), $USER)) === array('llm_memory_rule:8:memory_rule:1'));

// --- Go-to links -----------------------------------------------------------------------------------------------------------

check('a section on a page links to the CMS section edit URL', $rows['style_field:100:conversation_context:2']['goToUrl'] === '/cms/cmsSelect?pid=10&sid=100&ssid=none');
check('a section deeper in the tree links via the section attached to the page', $rows['style_field:101:conversation_context:2']['goToUrl'] === '/cms/cmsSelect?pid=11&sid=110&ssid=101');
check('a section on two pages links to the first page', $rows['style_field:106:conversation_context:2']['goToUrl'] === '/cms/cmsSelect?pid=10&sid=106&ssid=none');
$only_week2 = byKey(catalog($db, new FakeAcl(array(1, 11), array()))->listPrompts(array(), $USER));
check('the link uses the first page the user may select', $only_week2['style_field:106:conversation_context:2']['goToUrl'] === '/cms/cmsSelect?pid=11&sid=106&ssid=none');
check('a script links to the scripts page with its id', $rows['llm_script:5:script:1']['goToUrl'] === '/admin/moduleLlmScript?sid=5');
check('a memory rule links to the rules tab with its id', $rows['llm_memory_rule:7:memory_rule:1']['goToUrl'] === '/admin/moduleLlmMemory?tab=rules&rule=7');

$ui_services = new FakeServices($db, allAccess());
$ui_services->user_input->new_ui = true;
$ui_rows = byKey((new LlmPromptCatalogService($ui_services, null, new StubAssetLoader()))->listPrompts(array(), $USER));
check('the new CMS UI gets its own route', $ui_rows['style_field:100:conversation_context:2']['goToUrl']
    === '/cms/cmsUpdate?pid=10&sid=100&ssid=none&mode=update&type=prop');

$broken_services = new FakeServices($db, allAccess());
$broken_services->router->generate_throws = true;
$broken_services->router->unrouted = array('moduleLlmScript');
$no_route = byKey((new LlmPromptCatalogService($broken_services, null, new StubAssetLoader()))->listPrompts(array(), $USER));
check('a route that cannot be generated gives no link', $no_route['style_field:100:conversation_context:2']['goToUrl'] === null
    && $no_route['llm_script:5:script:1']['goToUrl'] === null);

check('the root section of a page is found for a nested section', LlmCmsLinkHelper::rootSectionOnPage($db, 101, 11) === 110);
check('the root section of a page is the section itself when it is attached', LlmCmsLinkHelper::rootSectionOnPage($db, 100, 10) === 100);
check('a section the page does not show has no root there', LlmCmsLinkHelper::rootSectionOnPage($db, 100, 11) === null);
check('a cycle in the hierarchy ends the walk', LlmCmsLinkHelper::rootSectionOnPage($db, 300, 10) === null);

// --- Long and broken content ---------------------------------------------------------------------------------------------------

check('a long prompt lists only a preview', $rows['llm_script:14:script:1']['contentLength'] === 60004
    && mb_strlen($rows['llm_script:14:script:1']['preview']) === 200 && !array_key_exists('content', $rows['llm_script:14:script:1']));
check('the whole list stays valid JSON when content has invalid UTF-8', json_encode($all) !== false);
$bad = $service->getPrompt('llm_script:15:script:1', $USER);
check('a prompt with invalid UTF-8 is cleaned and still valid JSON', json_encode($bad) !== false && strpos($bad['content'], 'bytes') !== false);

$markup_db = buildDb();
seed($markup_db->pdo, 'llm_scripts', array(
    array('id' => 30, 'name' => 'Markup', 'script' => "# Heading\n> quoted\n- item one\n**bold** `code` <b>tag</b> a < b and 5 > 3\n\n\n  spaced   out", 'updated_at' => null),
));
$markup = byKey(catalog($markup_db)->listPrompts(array('sourceType' => 'llm_script'), $USER))['llm_script:30:script:1'];
check('a preview is plain text: markers and tags removed, whitespace collapsed, "a < b" kept',
    $markup['preview'] === 'Heading quoted item one bold code tag a < b and 5 > 3 spaced out');

// --- Order and facets -------------------------------------------------------------------------------------------------------------

$order = array_map(function ($r) {
    return $r['key'];
}, $all['rows']);
$source_order = array_map(function ($r) {
    return array_search($r['sourceType'], LlmPromptCatalogService::sourceTypes(), true);
}, $all['rows']);
$sorted_sources = $source_order;
sort($sorted_sources);
check('rows are sorted by source type first', $source_order === $sorted_sources);
$coaching_conv = array_values(array_filter($order, function ($key) {
    return in_array($key, array(
        'style_field:100:conversation_context:1', 'style_field:100:conversation_context:2', 'style_field:105:conversation_context:2',
        'style_field:106:conversation_context:2', 'style_field:100:conversation_context:3',
    ), true);
}));
check('rows on the same page with the same title sort by language, then key', $coaching_conv === array(
    'style_field:100:conversation_context:1', 'style_field:100:conversation_context:2', 'style_field:105:conversation_context:2',
    'style_field:106:conversation_context:2', 'style_field:100:conversation_context:3',
));
check('style rows sort by page label', array_search('style_field:100:conversation_context:2', $order, true) < array_search('style_field:102:conversation_context:2', $order, true)
    && array_search('style_field:102:conversation_context:2', $order, true) < array_search('style_field:101:conversation_context:2', $order, true));
$script_order = array_values(array_filter($order, function ($key) {
    return strpos($key, 'llm_script:') === 0;
}));
check('scripts sort by title, ignoring case', $script_order === array(
    'llm_script:15:script:1', 'llm_script:14:script:1', 'llm_script:12:script:1', 'llm_script:11:script:1', 'llm_script:5:script:1',
));
check('the order is the same on every request', array_map(function ($r) {
    return $r['key'];
}, $service->listPrompts(array(), $USER)['rows']) === $order);

$facets = $all['facets'];
check('facets list the source types in list order', $facets['sourceTypes'] === array('style_field', 'llm_script', 'llm_memory_rule', 'system'));
check('facets list plugins, styles, languages and pages', $facets['plugins'] === array('Agentic chat', 'customStyle', 'LLM')
    && $facets['styles'] === array('agenticChat', 'customStyle', 'llmChat')
    && $facets['languages'] === array(array('id' => 1, 'code' => 'all'), array('id' => 2, 'code' => 'de-CH'), array('id' => 3, 'code' => 'en-GB'))
    && $facets['pages'] === array(array('id' => 10, 'label' => 'coaching'), array('id' => 12, 'label' => 'secret'), array('id' => 11, 'label' => 'week2')));
check('facets do not shrink with the other filters', $service->listPrompts(array('sourceType' => 'llm_script', 'style' => 'agenticChat', 'languageId' => '3'), $USER)['facets'] === $facets);
check('facets follow the search text', $service->listPrompts(array('q' => 'coach'), $USER)['facets']['sourceTypes'] === array('style_field'));
check('facets only offer pages the user may select', catalog($db, new FakeAcl(array(1, 10), array()))->listPrompts(array(), $USER)['facets']['pages'] === array(array('id' => 10, 'label' => 'coaching')));

// --- One prompt -------------------------------------------------------------------------------------------------------------------------

$detail = $service->getPrompt('style_field:100:conversation_context:2', $USER);
check('the detail has the full content and a hash of it', $detail['content'] === "Du bist ein Coach. Antworte freundlich.\nDu duzt den Nutzer.\nDenke an die Ziele."
    && $detail['contentHash'] === hash('sha256', $detail['content']));
check('the detail lists the versions, newest first, without text', $detail['versions'] === array(
    array('id' => 91, 'no' => 7, 'created_at' => '2026-11-20 14:03:00', 'author' => 'Jane Admin', 'change_note' => 'Friendlier'),
    array('id' => 90, 'no' => 6, 'created_at' => '2026-11-01 10:00:00', 'author' => 'Bob', 'change_note' => 'first'),
));
check('the detail of a style field carries its meta', $detail['meta'] === array('prompt' => array('pendingChangeNote' => 'x')));
check('the detail has the same row fields as the list', $detail['activeVersionNo'] === 7 && $detail['editable'] === true && $detail['title'] === 'Conversation context');
$lazy_detail = $service->getPrompt('style_field:100:conversation_context:3', $USER);
check('a prompt with no registry entry has no versions', $lazy_detail['versions'] === array() && $lazy_detail['activeVersionId'] === null && $lazy_detail['meta'] === null);
check('a key with leading zeros finds the same prompt, under its canonical key',
    $service->getPrompt('style_field:0100:conversation_context:002', $USER)['key'] === 'style_field:100:conversation_context:2');
check('an unknown prompt is null', $service->getPrompt('style_field:100:conversation_context:9', $USER) === null
    && $service->getPrompt('llm_script:999:script:1', $USER) === null && $service->getPrompt('system:0:core.nope:0', $USER) === null
    && $service->getPrompt('agentic_persona:0:nobody:0', $USER) === null);
check('a prompt of a deleted section can be read', $service->getPrompt('style_field:999:conversation_context:2', $USER)['content'] === 'ghost prompt text');
check('a script detail has its versions', $service->getPrompt('llm_script:5:script:1', $USER)['versions'][0]['id'] === 94);
check('a memory rule on a stream without language has its versions', $service->getPrompt('llm_memory_rule:7:memory_rule:1', $USER)['versions'][0]['id'] === 96);
check('only the stream of the prompt is listed (not other languages of the entry)', count($service->getPrompt('llm_memory_rule:10:memory_rule:1', $USER)['versions']) === 1);
check('a detail that is not editable says why', $only_coaching->getPrompt('style_field:100:conversation_context:2', $USER)['readOnlyReason'] === 'no_access');
check('a malformed key is refused', throws(function () use ($service, $USER) {
    $service->getPrompt('nonsense', $USER);
}));

// --- One version -------------------------------------------------------------------------------------------------------------------------

$version = $service->getVersion('style_field:100:conversation_context:2', 90, $USER);
check('a version of the prompt is returned with its text', $version === array(
    'version_no' => 6, 'template_raw' => 'Alter Prompt', 'created_at' => '2026-11-01 10:00:00', 'change_note' => 'first',
));
check('a version of another prompt is refused', $service->getVersion('style_field:100:conversation_context:2', 93, $USER) === null
    && $service->getVersion('style_field:100:conversation_context:2', 94, $USER) === null);
check('a version of another entry type with the same owner is refused', $service->getVersion('style_field:100:conversation_context:3', 89, $USER) === null);
check('an unknown version, a bad id and a prompt without versions give null', $service->getVersion('style_field:100:conversation_context:2', 12345, $USER) === null
    && $service->getVersion('style_field:100:conversation_context:2', 0, $USER) === null
    && $service->getVersion('style_field:100:conversation_context:3', 90, $USER) === null
    && $service->getVersion('system:0:core.memory.system:0', 90, $USER) === null);
check('a version of a prompt the user cannot see is refused', $only_coaching->getVersion('style_field:102:conversation_context:2', 90, $USER) === null);
check('a malformed key is refused for versions too', throws(function () use ($service, $USER) {
    $service->getVersion('', 1, $USER);
}));

// --- Keys ------------------------------------------------------------------------------------------------------------------------------------

$good = array(
    'style_field:123:conversation_context:2' => array('style_field', 123, 'conversation_context', 2),
    'llm_script:5:script:1' => array('llm_script', 5, 'script', 1),
    'llm_memory_rule:7:memory_rule:1' => array('llm_memory_rule', 7, 'memory_rule', 1),
    'system:0:core.response_schema.system_instructions:0' => array('system', 0, 'core.response_schema.system_instructions', 0),
    'agentic_persona:0:calm-coach_2:0' => array('agentic_persona', 0, 'calm-coach_2', 0),
);
$parsed_ok = true;
foreach ($good as $key => $expected) {
    $parsed = LlmPromptCatalogService::parseKey($key);
    $parsed_ok = $parsed_ok && array($parsed['sourceType'], $parsed['ownerId'], $parsed['slot'], $parsed['languageId']) === $expected
        && LlmPromptCatalogService::buildKey($parsed['sourceType'], $parsed['ownerId'], $parsed['slot'], $parsed['languageId']) === $key;
}
check('valid keys parse and build back to the same key', $parsed_ok);

$bad_keys = array(
    '', 'x', 'style_field', 'style_field:1:a', 'style_field:1:a:2:3', 'bogus:1:a:1', 'style_field:0:a:1', 'style_field:1:a:0',
    'style_field:1:a b:1', 'style_field:1:a.b:1', 'style_field:1:' . str_repeat('a', 65) . ':1', 'style_field:-1:a:1', 'style_field:a:a:1',
    'llm_script:5:script:2', 'llm_script:5:other:1', 'llm_script:0:script:1', 'llm_memory_rule:7:script:1', 'llm_memory_rule:7:memory_rule:0',
    'system:1:core.x:0', 'system:0:core.x:1', 'agentic_persona:5:x:0', "style_field:1:a:1\n", ' style_field:1:a:1', 'style_field:1:a:1 ',
    'style_field:99999999999:a:1', str_repeat('a', 400), 'system:0::0', 'system:0:a/b:0', 'system:0:../x:0',
);
$all_refused = true;
foreach ($bad_keys as $key) {
    $all_refused = $all_refused && throws(function () use ($key) {
        LlmPromptCatalogService::parseKey($key);
    });
}
check('malformed keys and unknown source types are refused', $all_refused);
check('keys that are not strings are refused', throws(function () {
    LlmPromptCatalogService::parseKey(null);
}) && throws(function () {
    LlmPromptCatalogService::parseKey(array('style_field:1:a:1'));
}) && throws(function () {
    LlmPromptCatalogService::parseKey(12);
}));

// --- Failure ----------------------------------------------------------------------------------------------------------------------------------

$failing = buildDb();
$failing->fail_on = 'sections_fields_translation';
check('a failed statement is an error, not an empty list', throws(function () use ($failing, $USER) {
    catalog($failing)->listPrompts(array(), $USER);
}, 'RuntimeException'));
$failing_registry = buildDb();
$failing_registry->fail_on = 'llm_prompt_entries';
check('a failed registry statement is an error too', throws(function () use ($failing_registry, $USER) {
    catalog($failing_registry)->listPrompts(array(), $USER);
}, 'RuntimeException'));

@unlink($error_log_file);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
