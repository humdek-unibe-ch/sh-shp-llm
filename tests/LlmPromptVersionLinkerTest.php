<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmPromptVersionLinker (plugin v1.7.0, request traces).
 *
 * A trace links the prompt versions that were in effect:
 *  - a system asset resolves to its `system` registry entry by text hash and
 *    is created on first use (a changed asset file = a new version);
 *  - a section prompt resolves to the Prompt Lab version (owner type
 *    style_field) of its raw field text; resolve only, nothing is created;
 *  - long Global Values are shared trace text (not linked);
 *  - only templates that were actually sent are linked;
 *  - linking never breaks a trace (it is informational).
 *
 * No bootstrap, no MySQL: an in-memory SQLite database with the registry
 * tables behind a BaseDb-shaped stand-in.
 *
 * Run: php tests/LlmPromptVersionLinkerTest.php
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "skipped: pdo_sqlite is not available\n";
    exit(0);
}

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_prompt_linker_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);
register_shutdown_function(function () use ($log_file) {
    if (is_file($log_file)) {
        unlink($log_file);
    }
});

foreach (array('LLM_PROMPT_OWNER_SYSTEM' => 'system', 'LLM_PROMPT_OWNER_STYLE_FIELD' => 'style_field',
    'SH_GLOBAL_VALUES' => 'sh_global_values', 'PF_GLOBAL_VALUES' => 'global_values', 'LLM_TRACE_SHARED_GLOBAL_VALUE_MIN_LENGTH' => 200) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once dirname(__DIR__) . '/server/service/trace/LlmPromptVersionLinker.php';

class LinkerFakeDb
{
    public $pdo;
    public $fail = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (array(
            "CREATE TABLE lookups (id INTEGER PRIMARY KEY, type_code TEXT, lookup_code TEXT)",
            "INSERT INTO lookups VALUES (40, 'llm_prompt_owner_types', 'style_field'), (41, 'llm_prompt_owner_types', 'system')",
            "CREATE TABLE fields (id INTEGER PRIMARY KEY, name TEXT)",
            "INSERT INTO fields VALUES (5, 'conversation_context'), (7, 'global_values')",
            "CREATE TABLE pages (id INTEGER PRIMARY KEY, keyword TEXT)",
            "INSERT INTO pages VALUES (3, 'sh_global_values')",
            "CREATE TABLE pages_fields_translation (id_pages INTEGER, id_fields INTEGER, id_languages INTEGER, content TEXT)",
            "CREATE TABLE sections_fields_translation (id_sections INTEGER, id_fields INTEGER, id_languages INTEGER, id_genders INTEGER, content TEXT, meta TEXT)",
            "CREATE TABLE llm_prompt_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llm_prompt_owner_types INTEGER, owner_id INTEGER,
                prompt_slot TEXT, title TEXT, archived_at TEXT, UNIQUE(id_llm_prompt_owner_types, owner_id, prompt_slot))",
            "CREATE TABLE llm_prompt_locales (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llm_prompt_entries INTEGER, id_languages INTEGER,
                active_version_id INTEGER, active_version_no INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE llm_prompt_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, id_llm_prompt_locales INTEGER, version_no INTEGER,
                template_raw TEXT, template_hash TEXT, change_note TEXT, based_on_version_id INTEGER, UNIQUE(id_llm_prompt_locales, version_no))",
        ) as $sql) {
            $this->pdo->exec($sql);
        }
    }

    private function run($sql, $params)
    {
        if ($this->fail) {
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

    public function count($table)
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    /** The value escaping of core PageDb::replace_calced_values() for string values. */
    public function replace_calced_values($text, $values)
    {
        foreach ($values as $name => $value) {
            $value = str_replace("\t", '\t', preg_replace('/\r|\n/', '\n', trim($value)));
            $text = preg_replace('#\{\{' . preg_quote($name, '#') . '\}\}#s', addslashes($value), $text);
        }
        return $text;
    }
}

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

/** The llm_prompt_versions ids of resolveAll(). */
function prompt_ids($db, array $messages)
{
    return LlmPromptVersionLinker::resolveAll($db, $messages);
}

/** A Prompt Lab version of a section field, made active; returns its id. */
function add_field_version($db, $section_id, $slot, $language, $version_no, $text)
{
    $db->pdo->exec("INSERT OR IGNORE INTO llm_prompt_entries (id_llm_prompt_owner_types, owner_id, prompt_slot) VALUES (40, $section_id, '$slot')");
    $entry = (int)$db->pdo->query("SELECT id FROM llm_prompt_entries WHERE id_llm_prompt_owner_types = 40 AND owner_id = $section_id AND prompt_slot = '$slot'")->fetchColumn();
    $locale = (int)$db->pdo->query("SELECT id FROM llm_prompt_locales WHERE id_llm_prompt_entries = $entry AND id_languages = $language")->fetchColumn();
    if (!$locale) {
        $locale = $db->insert('llm_prompt_locales', array('id_llm_prompt_entries' => $entry, 'id_languages' => $language));
    }
    $id = $db->insert('llm_prompt_versions', array('id_llm_prompt_locales' => $locale, 'version_no' => $version_no,
        'template_raw' => $text, 'template_hash' => hash('sha256', $text)));
    $db->pdo->exec("UPDATE llm_prompt_locales SET active_version_id = $id, active_version_no = $version_no WHERE id = $locale");
    return $id;
}

$db = new LinkerFakeDb();
$form_mode = "You are in form mode. Always answer with a form.";
$retry = "Your reply was not valid JSON: {{error_list}}. Answer again.";
$sent = array(
    array('role' => 'system', 'content' => $form_mode),
    array('role' => 'user', 'content' => 'Your reply was not valid JSON: missing text_blocks. Answer again.'),
);

/* ------------------------------------------------------------------------- */
LlmPromptVersionLinker::reset();
LlmPromptVersionLinker::noteAsset('core.form_mode.system', $form_mode);
LlmPromptVersionLinker::noteAsset('core.response.retry_prompt', $retry);
LlmPromptVersionLinker::noteAsset('core.floating_mode.system', 'Floating mode text that was loaded but not sent.');
$ids = prompt_ids($db, $sent);
check('first use creates a system entry, locale and version per sent asset', count($ids) === 2
    && $db->count('llm_prompt_entries') === 2 && $db->count('llm_prompt_versions') === 2);
check('a template with placeholders is linked by its literal text', $db->query_db_first(
    "SELECT COUNT(*) AS n FROM llm_prompt_entries WHERE prompt_slot = 'core.response.retry_prompt' AND id_llm_prompt_owner_types = 41")['n'] == 1);
check('only templates that were sent are linked', $db->query_db_first(
    "SELECT COUNT(*) AS n FROM llm_prompt_entries WHERE prompt_slot = 'core.floating_mode.system'")['n'] == 0);
check('the version is active and holds the text with its hash', $db->query_db_first(
    "SELECT COUNT(*) AS n FROM llm_prompt_versions v JOIN llm_prompt_locales l ON l.active_version_id = v.id
     WHERE v.template_raw = :t AND v.template_hash = :h AND v.version_no = 1", array(':t' => $form_mode, ':h' => hash('sha256', $form_mode)))['n'] == 1);
check('notes are consumed by resolveAll', prompt_ids($db, $sent) === array());

LlmPromptVersionLinker::noteAsset('core.form_mode.system', $form_mode);
check('same_template_resolves_existing_version', prompt_ids($db, $sent) === array($ids[0])
    && $db->count('llm_prompt_versions') === 2);

$changed = "You are in form mode. Always answer with a form, never with prose.";
LlmPromptVersionLinker::noteAsset('core.form_mode.system', $changed);
$new = prompt_ids($db, array(array('role' => 'system', 'content' => $changed)));
$row = $db->query_db_first('SELECT version_no, based_on_version_id FROM llm_prompt_versions WHERE id = :id', array(':id' => $new[0] ?? 0));
check('changed_template_creates_new_version', count($new) === 1 && $new[0] !== $ids[0]
    && (int)$row['version_no'] === 2 && (int)$row['based_on_version_id'] === $ids[0]);

/* ------------------------------------------------------------------------- */
// Section prompt: linked to the Prompt Lab version (style_field) of its raw text
$raw = "You coach {{name}} through the exercise.";
$db->pdo->exec("INSERT INTO sections_fields_translation (id_sections, id_fields, id_languages, id_genders, content) VALUES (12, 5, 1, 1, " . $db->pdo->quote("Default: $raw") . ")");
$db->pdo->exec("INSERT INTO sections_fields_translation (id_sections, id_fields, id_languages, id_genders, content) VALUES (12, 5, 2, 1, " . $db->pdo->quote($raw) . ")");
$default_version = add_field_version($db, 12, 'conversation_context', 1, 1, "Default: $raw");
$first = add_field_version($db, 12, 'conversation_context', 2, 1, $raw);
add_field_version($db, 12, 'conversation_context', 2, 2, 'An edit that was saved and later restored away.');
$restored = add_field_version($db, 12, 'conversation_context', 2, 3, $raw);
add_field_version($db, 13, 'conversation_context', 2, 1, $raw);
$prompt_versions_before = $db->count('llm_prompt_versions');
$coached = array(array('role' => 'system', 'content' => 'You coach Ana through the exercise.'));

LlmPromptVersionLinker::noteSectionField(12, 'conversation_context', 2);
check('a section prompt links the active Prompt Lab version of its raw text (its own section only)',
    prompt_ids($db, $coached) === array($restored));
$db->pdo->exec("UPDATE llm_prompt_locales SET active_version_id = $first WHERE active_version_id = $restored");
LlmPromptVersionLinker::noteSectionField(12, 'conversation_context', 2);
check('the active version wins over a newer one with the same text', prompt_ids($db, $coached) === array($first));
LlmPromptVersionLinker::noteSectionField(12, 'conversation_context', 3);
check('a language without its own text falls back to the default language row and links its version',
    prompt_ids($db, $coached) === array($default_version));

// Content written by SQL and never saved in the CMS has no version: no link, nothing created
$db->pdo->exec("UPDATE sections_fields_translation SET content = 'Imported prompt for {{name}}: be brief.' WHERE id_sections = 12 AND id_languages = 2");
LlmPromptVersionLinker::noteSectionField(12, 'conversation_context', 2);
check('a field text without a Prompt Lab version is not linked and no version is created',
    prompt_ids($db, array(array('role' => 'system', 'content' => 'Imported prompt for Ana: be brief.'))) === array()
    && $db->count('llm_prompt_versions') === $prompt_versions_before);

/* ------------------------------------------------------------------------- */
// Global Values: long values are shared trace text (stored once), not linked
$module = "# Module 1\n\n" . str_repeat('Module text about resilience. ', 20) . "It's Lea's story.";
$db->pdo->exec("INSERT INTO pages_fields_translation VALUES (3, 7, 2, " . $db->pdo->quote(json_encode(array(
    'woop_module_1_title' => 'Resilience', 'woop_module_1_context' => $module))) . ")");
$interpolated = $db->replace_calced_values('{{woop_module_1_context}}', array('woop_module_1_context' => $module));
LlmTraceHints::reset();
LlmPromptVersionLinker::shareGlobalValues($db);
check('a long Global Value is declared shared in its interpolated (escaped) form, a short one is not',
    LlmTraceHints::sharedTexts() === array($interpolated) && strpos($interpolated, "\n") === false);
LlmTraceHints::reset();

/* ------------------------------------------------------------------------- */
// A database the process cache has not seen, so the lookup really fails
LlmPromptVersionLinker::noteAsset('core.form_mode.system', $form_mode);
$broken = new LinkerFakeDb();
$broken->fail = true;
$error = null;
try {
    $result = prompt_ids($broken, $sent);
} catch (Throwable $e) {
    $error = $e;
}
check('linker_failure_does_not_break_trace', $error === null && $result === array());

// Known versions come from the process cache: no statement at all
LlmPromptVersionLinker::noteAsset('core.form_mode.system', $form_mode);
$db->fail = true;
$cached = prompt_ids($db, $sent);
$db->fail = false;
check('a known system version is resolved from the process cache', count($cached) === 1);

// A concurrent request took the next version_no first: the linker reads again and moves on
$changed = $form_mode . ' (changed)';
$locale = (int)$db->pdo->query("SELECT l.id FROM llm_prompt_locales l JOIN llm_prompt_entries e ON e.id = l.id_llm_prompt_entries
    WHERE e.prompt_slot = 'core.form_mode.system'")->fetchColumn();
$taken = (int)$db->pdo->query("SELECT MAX(version_no) + 1 FROM llm_prompt_versions WHERE id_llm_prompt_locales = $locale")->fetchColumn();
$db->pdo->exec("CREATE TRIGGER other_request BEFORE INSERT ON llm_prompt_versions WHEN NEW.template_hash = '" . hash('sha256', $changed) . "'
    AND NOT EXISTS (SELECT 1 FROM llm_prompt_versions WHERE id_llm_prompt_locales = $locale AND version_no = $taken)
    BEGIN INSERT INTO llm_prompt_versions (id_llm_prompt_locales, version_no, template_raw, template_hash) VALUES ($locale, $taken, 'other', 'other'); END");
LlmPromptVersionLinker::noteAsset('core.form_mode.system', $changed);
$raced = prompt_ids($db, array(array('role' => 'system', 'content' => $changed)));
$db->pdo->exec("DROP TRIGGER other_request");
$row = $db->pdo->query("SELECT version_no FROM llm_prompt_versions WHERE template_hash = '" . hash('sha256', $changed) . "'")->fetch(PDO::FETCH_ASSOC);
check('version_no race: the changed asset still gets its own version (next number)', count($raced) === 1 && $row && (int)$row['version_no'] === $taken + 1);

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
