<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the llmMemoryEditor style (plugin v1.10.0): the logged-in
 * participant edits, deletes and adds the items of their own memory, without an
 * LLM call (doc/structured-memory-design.md, section 5).
 *
 *  - edit_item / delete_item / add_item change only the item they name; every
 *    other item stays as it was. memory_text is rendered from the items, the
 *    current row is updated in place and one history row is appended
 *    (source_type user_edit, rule_id 0, the item's source becomes "user").
 *  - The request carries no user id: it always acts on the session user's
 *    memory. It names a memory key, which must be one of the keys of the
 *    section (field memory_keys); several keys are shown and saved one by one.
 *    A guest, a missing or wrong CSRF token, a section id that is not the
 *    section's own, a key the section does not show and an action the CMS
 *    checkbox does not allow change nothing.
 *  - A key without sections is one flat list; memory_labels translates the
 *    headings of keys and sections.
 *  - A client that shows an older memory version is refused ("memory changed")
 *    and gets the current memory to show; a free-text memory is read-only until
 *    the worker has converted it.
 *  - Current row and history row are saved in one transaction: a failing write
 *    leaves nothing behind and is logged.
 *  - The view escapes the texts, hides what the CMS does not allow, shows
 *    items of sections the key no longer defines, and its answers carry the
 *    state (version, token) the next request needs.
 *
 * The real controller, model, view and templates run, with the real
 * LlmMemoryStorageService, LlmMemoryConfigService, LlmMemoryItems, LlmCsrfService,
 * LlmTransaction and the core StyleModel / StyleView / PageDb. Only I/O is
 * replaced: the database below the storage and the key registry rows
 * (tests/support/LlmMemoryTestStore.php), the constructor of the core model (it reads
 * the section from MySQL), the module config, and the JSON response (it ends the request). The LLM provider is
 * trapped: the test fails when anything reaches it.
 *
 * Run: php tests/LlmMemoryEditorControllerTest.php
 */

$server = dirname(__DIR__) . '/server';
require_once __DIR__ . '/support/LlmMemoryTestStore.php';
ob_start(); // some files end with stray newlines after their closing tags
require_once dirname(__DIR__, 4) . '/server/service/ParsedownExtension.php';
require_once $server . '/component/style/llmMemoryEditor/LlmMemoryEditorController.php';
require_once $server . '/service/LlmService.php';
ob_end_clean();

if (!defined('GUEST_USER_ID')) {
    define('GUEST_USER_ID', 1);
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

$log_file = tempnam(sys_get_temp_dir(), 'llm_mem_editor_');
ini_set('log_errors', '1');
ini_set('error_log', $log_file);
register_shutdown_function(function () use ($log_file) {
    @unlink($log_file);
});
function logged()
{
    global $log_file;
    clearstatcache();
    return (string)file_get_contents($log_file);
}
function clear_log()
{
    global $log_file;
    file_put_contents($log_file, '');
}

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

/** What replaces the JSON response: it ends the request, so the test gets the answer instead. */
class EditorTestResponse extends Exception
{
    public $data;
    public $status;

    public function __construct($data, $status)
    {
        parent::__construct('response');
        $this->data = $data;
        $this->status = $status;
    }
}

class TestEditorController extends LlmMemoryEditorController
{
    protected function sendJsonResponse($data, $status_code = 200)
    {
        throw new EditorTestResponse($data, $status_code);
    }
}

/**
 * The real model without the constructor of StyleModel (which reads the section and its
 * fields from MySQL): the fields are given, the module config is the stand-in.
 */
class TestEditorModel extends LlmMemoryEditorModel
{
    private $test_config;

    public function __construct($services, $section_id, array $fields, $config)
    {
        $this->services = $services;
        $this->children = array();
        $this->section_id = $section_id;
        $this->style_name = 'llmMemoryEditor';
        $this->db_fields = array('id' => array('content' => $section_id, 'type' => 'internal'));
        foreach ($fields as $name => $content) {
            $this->db_fields[$name] = is_array($content) ? $content : array('content' => $content, 'default' => '');
        }
        $this->test_config = $config;
    }

    protected function configService()
    {
        return $this->test_config;
    }
}

// The LLM provider is the one boundary the editor must never reach.
$llm_calls = 0;
$llm_trap = false;
if (function_exists('uopz_set_return')) {
    uopz_set_return('LlmService', 'callLlmApi', function () use (&$llm_calls) {
        $llm_calls++;
        return false;
    }, true);
    $llm_trap = true;
} else {
    echo "skip: the LLM provider trap needs the uopz extension (the services trap below still applies)\n";
}

/* =========================================================================
 * Helpers
 * ========================================================================= */

const SECTION_ID = 41;
const OLD_VERSION = '20260101000000';

/**
 * The stores, the config and the storage service the editor works on.
 *
 * @param array|string $keys The key registry (see MemoryTestConfig): '' = global and profile with Person / Context / Goals
 */
function world($keys = '', $storage_mode = 'both')
{
    $store = new MemoryTestStore();
    $store->addTable('llm_memory');
    $store->addTable('llm_memory_history');
    $db = new MemoryTestDb($store);
    $services = new MemoryTestServices($db, new MemoryTestUserInput($store));
    $config = new MemoryTestConfig($services, $keys, $storage_mode);
    return (object)array(
        'store' => $store, 'db' => $db, 'services' => $services, 'config' => $config,
        'storage' => new LlmMemoryStorageService($services, $config), 'mode' => $storage_mode,
    );
}

function item($id, $section, $text, $source = 'llm')
{
    return array('id' => $id, 'section' => $section, 'text' => $text, 'source' => $source, 'updated_at' => '2026-01-01 00:00:00');
}

function forest_items()
{
    return array(
        item('m_aaaaaa', 'person', 'Forest is 14 years old'),
        item('m_bbbbbb', 'person', 'Lives in Bern'),
        item('m_cccccc', 'context', 'Plays in a football club'),
        item('m_dddddd', 'goals', 'Wants to become a football player'),
    );
}

/**
 * An items memory, as the worker writes it. The saved version is a past one, so a later save
 * of the editor (version = now) is always a different one.
 */
function seed_items($w, array $items, $user_id = 5, $key = 'global', $mode = 'record')
{
    $sections = $w->config->getKeySections($key);
    $w->storage->saveMemoryUpdate($user_id, $key, array(
        'memory_text' => LlmMemoryItems::renderText($items, $sections),
        'memory_object' => LlmMemoryItems::toMemoryObject($items),
        'flat_fields' => array(),
        'change_summary' => 'Seed',
    ), array(
        'rule_id' => 5, 'source_type' => 'form_action_submit', 'source_ref' => 'seed', 'trigger_type' => 'finished',
        'payload_json' => array(), 'event_at' => '2026-01-01 00:00:00', 'dedupe_key' => 'seed',
    ), $mode);
    foreach ($w->store->rows as $id => $row) {
        if (isset($row['cells']['memory_version'])) {
            $w->store->rows[$id]['cells']['memory_version'] = OLD_VERSION;
        }
    }
}

/** A memory of the free-text kind, as the worker wrote it before items existed. */
function seed_legacy($w, $text = "### Person\n- Forest is 14 years old", $user_id = 5)
{
    $w->storage->saveMemoryUpdate($user_id, 'global', array(
        'memory_text' => $text,
        'memory_object' => '{"person":{"name":"Forest"}}',
        'flat_fields' => array(),
        'change_summary' => 'Earlier update',
    ), array(
        'rule_id' => 5, 'source_type' => 'form_action_submit', 'source_ref' => 'old', 'trigger_type' => 'finished',
        'payload_json' => array(), 'event_at' => '2026-01-01 00:00:00', 'dedupe_key' => 'old',
    ), 'record');
}

function login($user_id = 5)
{
    $_SESSION = array(
        'id_user' => $user_id,
        'user_language_locale' => 'en-GB',
        'active_section_id' => null,
    );
    $_POST = array();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
}

function model($w, array $fields = array(), $section_id = SECTION_ID)
{
    return new TestEditorModel($w->services, $section_id, $fields + array('memory_keys' => 'global'), $w->config);
}

/** The body of the section as the page shows it; the client state (version, token) is in it. */
function body($w, array $fields = array(), $message = '')
{
    $model = model($w, $fields);
    return (new LlmMemoryEditorView($model))->render_body($model->getMemoryKeys()[0], $message);
}

function attr($html, $name)
{
    return preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
}

/** The version and token a client that rendered the section holds. */
function client($w, array $fields = array())
{
    $html = body($w, $fields);
    return array('memory_key' => attr($html, 'data-memory-key'), 'memory_version' => attr($html, 'data-memory-version'), 'csrf_token' => attr($html, 'data-csrf-token'));
}

/**
 * Post an action to the editor of a section: the controller of that section runs on the request.
 *
 * @param int      $model_section the section whose controller runs
 * @param int|null $addressed_to  the section the request is for (default: the same)
 * @return EditorTestResponse|null null when the controller did not answer
 */
function post($w, $action, array $post, array $fields = array(), $model_section = SECTION_ID, $addressed_to = null)
{
    $model = model($w, $fields, $model_section);
    // a request names the key of the body it was sent from; the first key of the section unless the test says otherwise
    $_POST = array('__llm_memory_action' => $action, 'section_id' => (string)($addressed_to ?? $model_section)) + $post
        + array('memory_key' => $model->getMemoryKeys()[0]);
    try {
        new TestEditorController($model);
    } catch (EditorTestResponse $response) {
        return $response;
    }
    return null;
}

/** An action as a page that rendered the section sends it (current key, version and token); $post wins. */
function act($w, $action, array $post, array $fields = array())
{
    return post($w, $action, $post + client($w, $fields), $fields);
}

function current_row($w, $user_id = 5, $key = 'global')
{
    foreach ($w->store->tableRows('llm_memory', $user_id) as $row) {
        if ($row['memory_key'] === $key) {
            return $row;
        }
    }
    return null;
}

function current_items($w, $user_id = 5, $key = 'global')
{
    $row = current_row($w, $user_id, $key);
    $decoded = $row ? json_decode($row['memory_json'], true) : null;
    return is_array($decoded) ? $decoded['items'] : null;
}

function history_rows($w, $user_id = 5)
{
    return $w->store->tableRows('llm_memory_history', $user_id);
}

function last_item($w, $user_id = 5)
{
    $items = current_items($w, $user_id);
    return end($items);
}

function item_by_id(array $items, $id)
{
    foreach ($items as $candidate) {
        if ($candidate['id'] === $id) {
            return $candidate;
        }
    }
    return null;
}

/** A snapshot of everything the editor could write: to assert that nothing changed. */
function stored($w)
{
    return array('rows' => $w->store->rows, 'saves' => count($w->store->saves));
}

/* =========================================================================
 * 1. Edit
 * ========================================================================= */

echo "\n-- edit_item --\n";
login();
$w = world();
seed_items($w, forest_items());
$before_other = array(item_by_id(forest_items(), 'm_bbbbbb'), item_by_id(forest_items(), 'm_cccccc'), item_by_id(forest_items(), 'm_dddddd'));
$old_version = client($w)['memory_version'];

$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'));
check('edit: answered with 200 and success', $r !== null && $r->status === 200 && $r->data['success'] === true && $r->data['status'] === 'ok');
$items = current_items($w);
$edited = item_by_id($items, 'm_aaaaaa');
check('edit: the text is the new one', $edited['text'] === 'Forest is 15 years old');
check('edit: the item is now the participant\'s (source user)', $edited['source'] === 'user');
check('edit: the section and id are unchanged', $edited['section'] === 'person' && $edited['id'] === 'm_aaaaaa');
check('edit: updated_at is new', $edited['updated_at'] !== '2026-01-01 00:00:00' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $edited['updated_at']) === 1);
check('edit: every other item is exactly as it was', array(item_by_id($items, 'm_bbbbbb'), item_by_id($items, 'm_cccccc'), item_by_id($items, 'm_dddddd')) === $before_other);
check('edit: the memory still has the four items, in order', array_column($items, 'id') === array('m_aaaaaa', 'm_bbbbbb', 'm_cccccc', 'm_dddddd'));
$row = current_row($w);
check('edit: memory_json is items/v1', json_decode($row['memory_json'], true)['format'] === 'items/v1');
check('edit: memory_text is rendered from the items', $row['memory_text'] === "### Person\n- Forest is 15 years old\n- Lives in Bern\n\n### Context\n- Plays in a football club\n\n### Goals\n- Wants to become a football player");
check('edit: the row records who wrote it', $row['source_type'] === 'user_edit' && (int)$row['rule_id'] === 0);
check('edit: the memory has a new version', preg_match('/^\d{14}$/', $row['memory_version']) === 1 && $row['memory_version'] !== OLD_VERSION);
check('edit: there is still one current row', count($w->store->tableRows('llm_memory', 5)) === 1);

$history = history_rows($w);
check('edit: one history row is appended', count($history) === 1);
$h = $history[0];
check('edit: history source_type is user_edit, rule_id 0, status applied', $h['source_type'] === 'user_edit' && (int)$h['rule_id'] === 0 && $h['update_status'] === 'applied');
check('edit: history keeps the memory it saved', $h['memory_text'] === $row['memory_text'] && $h['memory_json'] === $row['memory_json'] && $h['memory_key'] === 'global');
$ref = json_decode($h['source_ref'], true);
check('edit: history source_ref names the action, the section and the item', $ref === array('action' => 'edit_item', 'section_id' => SECTION_ID, 'item_id' => 'm_aaaaaa'));
check('edit: history change_summary names the participant and the section', $h['change_summary'] === 'Participant edited an item in "Person"');
$editor_saves = array_slice($w->store->saves, 1); // the first save is the seed
check('edit: the current row and the history row were written, for the session user', count($editor_saves) === 2 && array_unique(array_column(array_column($editor_saves, 'fields'), 'id_users')) === array(5));
check('edit: both rows were saved in one transaction', $w->db->transactions === array('begin', 'commit'));

check('edit: the answer carries the new body with the new text', strpos($r->data['html'], 'Forest is 15 years old') !== false && strpos($r->data['html'], 'Forest is 14 years old') === false);
check('edit: the body carries the new version for the next request', attr($r->data['html'], 'data-memory-version') === $row['memory_version'] && $row['memory_version'] !== $old_version);
check('edit: the body has no error message', strpos($r->data['html'], 'lme-message alert alert-danger" role="alert" hidden') !== false);

echo "\n-- no LLM, nothing else touched --\n";
check('no LLM provider call', $llm_calls === 0);
check('no llmConversations / llmMessages written', $w->db->inserts === array() && $w->db->updates === array());
check('no statement the store did not plan for', $w->db->unexpected === array());

/* =========================================================================
 * 2. Delete
 * ========================================================================= */

echo "\n-- delete_item --\n";
login();
$w = world();
seed_items($w, forest_items(), 5, 'global', 'both');
check('delete: the seed left one history row', count(history_rows($w)) === 1);
$seed_history = history_rows($w)[0];

$r = act($w, 'delete_item', array('item_id' => 'm_bbbbbb'));
check('delete: answered with 200 and success', $r !== null && $r->status === 200 && $r->data['status'] === 'ok');
$items = current_items($w);
check('delete: only that item is gone', array_column($items, 'id') === array('m_aaaaaa', 'm_cccccc', 'm_dddddd'));
check('delete: the other items are exactly as they were', array_values(array_filter(forest_items(), function ($i) { return $i['id'] !== 'm_bbbbbb'; })) === $items);
check('delete: memory_text no longer has the item', strpos(current_row($w)['memory_text'], 'Lives in Bern') === false && strpos(current_row($w)['memory_text'], 'Forest is 14 years old') !== false);
check('delete: history has a new row, the old one is kept as it was', count(history_rows($w)) === 2 && history_rows($w)[1] === $seed_history);
$h = history_rows($w)[0];
check('delete: the new history row is user_edit with the action and the item', $h['source_type'] === 'user_edit'
    && json_decode($h['source_ref'], true) === array('action' => 'delete_item', 'section_id' => SECTION_ID, 'item_id' => 'm_bbbbbb')
    && $h['change_summary'] === 'Participant deleted an item from "Person"');
check('delete: the history snapshot taken before still contains the deleted item', strpos($seed_history['memory_text'], 'Lives in Bern') !== false);
check('delete: the answer\'s body does not show the item', strpos($r->data['html'], 'Lives in Bern') === false && strpos($r->data['html'], 'Forest is 14 years old') !== false);

$r = act($w, 'delete_item', array('item_id' => 'm_cccccc'));
check('delete: the last item of a section removes the section from memory_text', strpos(current_row($w)['memory_text'], '### Context') === false);

$r = act($w, 'delete_item', array('item_id' => 'm_aaaaaa'));
$r = act($w, 'delete_item', array('item_id' => 'm_dddddd'));
check('delete: the last item leaves an empty items memory, not a missing one', current_items($w) === array() && current_row($w)['memory_text'] === '');
check('delete: an empty memory is shown with the empty text', strpos($r->data['html'], 'Nothing saved yet.') !== false);

/* =========================================================================
 * 3. Add
 * ========================================================================= */

echo "\n-- add_item --\n";
login();
$w = world();
seed_items($w, forest_items());
$r = act($w, 'add_item', array('section' => 'goals', 'text' => '  Wants to play   in a team  '));
check('add: answered with 200 and success', $r !== null && $r->status === 200 && $r->data['status'] === 'ok');
$items = current_items($w);
check('add: one item more, the others unchanged and first', count($items) === 5 && array_slice($items, 0, 4) === forest_items());
$new = $items[4];
check('add: the item has a new id', preg_match('/^m_[0-9a-f]{6}$/', $new['id']) === 1 && !in_array($new['id'], array_column(forest_items(), 'id'), true));
check('add: it is in the section, with the text trimmed and spaces collapsed, from the participant', $new['section'] === 'goals' && $new['text'] === 'Wants to play in a team' && $new['source'] === 'user');
check('add: memory_text lists it under its section', strpos(current_row($w)['memory_text'], "### Goals\n- Wants to become a football player\n- Wants to play in a team") !== false);
$h = history_rows($w)[0];
check('add: history row names the action and the new item', $h['source_type'] === 'user_edit'
    && json_decode($h['source_ref'], true) === array('action' => 'add_item', 'section_id' => SECTION_ID, 'item_id' => $new['id'])
    && $h['change_summary'] === 'Participant added an item to "Goals"');
check('add: the answer\'s body shows the new item', strpos($r->data['html'], 'Wants to play in a team') !== false);

$before = stored($w);
$r = act($w, 'add_item', array('section' => 'hobbies', 'text' => 'Chess'));
check('add: a section the key does not define is refused (422)', $r->status === 422 && $r->data['success'] === false && $r->data['status'] === 'invalid');
check('add: ... and nothing is saved', stored($w) === $before);
$r = act($w, 'add_item', array('section' => 'goals', 'text' => '   '));
check('add: empty text is refused with a message (422)', $r->status === 422 && $r->data['message'] === 'Please enter some text.' && stored($w) === $before);
check('add: an invalid text is answered without a body (the page keeps its form open)', !isset($r->data['html']));
$r = act($w, 'add_item', array('section' => 'goals', 'text' => str_repeat('x', LlmMemoryItems::MAX_TEXT_LENGTH + 1)));
check('add: too long a text is refused (422)', $r->status === 422 && strpos($r->data['message'], 'too long') !== false && stored($w) === $before);
$r = act($w, 'add_item', array('section' => 'goals', 'text' => str_repeat('y', LlmMemoryItems::MAX_TEXT_LENGTH)));
check('add: a text of the largest length is saved', $r->status === 200 && strlen(last_item($w)['text']) === LlmMemoryItems::MAX_TEXT_LENGTH);

$w2 = world();
seed_items($w2, forest_items());
$r = act($w2, 'add_item', array('section' => 'person', 'text' => '<script>alert(1)</script> & <b>bold</b>'));
check('add: markup in a text is kept as text in memory_json', last_item($w2)['text'] === '<script>alert(1)</script> & <b>bold</b>');
check('add: ... and escaped in the body of the answer', strpos($r->data['html'], '<script>') === false && strpos($r->data['html'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;bold&lt;/b&gt;') !== false);
check('add: ... and has no tags in memory_text', strpos(current_row($w2)['memory_text'], '<') === false);

echo "\n-- add_item to a user without a memory --\n";
login(7);
$w = world();
check('no memory: the body shows an add button per section and the empty text', substr_count(body($w), 'lme-add-btn') === 3 && substr_count(body($w), 'Nothing saved yet.') === 3);
$r = post($w, 'add_item', client($w) + array('section' => 'context', 'text' => 'Likes chess'));
check('no memory: the first item creates the memory (200)', $r->status === 200 && $r->data['status'] === 'ok');
check('no memory: it is items/v1 with that item, written for user 7', count(current_items($w, 7)) === 1 && current_items($w, 7)[0]['text'] === 'Likes chess' && current_items($w, 7)[0]['source'] === 'user'
    && current_row($w, 7)['memory_text'] === "### Context\n- Likes chess");
check('no memory: one history row (user_edit) is appended', count(history_rows($w, 7)) === 1 && history_rows($w, 7)[0]['source_type'] === 'user_edit');
check('no memory: nothing was written for anyone else', $w->store->tableRows('llm_memory', 5) === array());

/* =========================================================================
 * 4. Unchanged edit
 * ========================================================================= */

echo "\n-- unchanged edit --\n";
login();
$w = world();
seed_items($w, forest_items());
$before = stored($w);
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 14 years old'));
check('unchanged: answered with 200 and success, status unchanged', $r->status === 200 && $r->data['success'] === true && $r->data['status'] === 'unchanged');
check('unchanged: nothing is saved (no history row, no new version)', stored($w) === $before && history_rows($w) === array());
check('unchanged: the body is returned', isset($r->data['html']) && strpos($r->data['html'], 'Forest is 14 years old') !== false);
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => '  Forest is   14 years old '));
check('unchanged: the same text with other spacing is the same text', $r->data['status'] === 'unchanged' && stored($w) === $before);

echo "\n-- an item that is gone --\n";
$r = act($w, 'edit_item', array('item_id' => 'm_zzzzzz', 'text' => 'Anything'));
check('gone: edit of an unknown id is 404 with the current body', $r->status === 404 && $r->data['status'] === 'not_found' && strpos($r->data['html'], 'Forest is 14 years old') !== false && stored($w) === $before);
$r = act($w, 'delete_item', array('item_id' => 'm_zzzzzz'));
check('gone: delete of an unknown id is 404', $r->status === 404 && stored($w) === $before);
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => ''));
check('gone: an empty text for an edit is refused (422)', $r->status === 422 && stored($w) === $before);

/* =========================================================================
 * 5. Stale version, two tabs
 * ========================================================================= */

echo "\n-- memory version --\n";
login();
$w = world();
seed_items($w, forest_items());
$tab_a = client($w);
$tab_b = client($w);
check('versions: both tabs hold the stored version', $tab_a['memory_version'] === OLD_VERSION && $tab_b['memory_version'] === OLD_VERSION);

$r = post($w, 'edit_item', $tab_a + array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'));
check('versions: tab A\'s edit is applied', $r->data['status'] === 'ok');
$after_a = stored($w);

$r = post($w, 'edit_item', $tab_b + array('item_id' => 'm_bbbbbb', 'text' => 'Lives in Zurich'));
check('versions: tab B (older version) is refused with 409 "memory changed"', $r->status === 409 && $r->data['success'] === false && $r->data['status'] === 'conflict'
    && strpos($r->data['message'], 'changed in the meantime') !== false);
check('versions: ... and nothing is saved', stored($w) === $after_a && item_by_id(current_items($w), 'm_bbbbbb')['text'] === 'Lives in Bern');
check('versions: the answer shows tab A\'s change', strpos($r->data['html'], 'Forest is 15 years old') !== false);
check('versions: the answer says so, as a warning', strpos($r->data['html'], 'alert-warning') !== false && strpos($r->data['html'], 'changed in the meantime') !== false);
$fresh = array('memory_version' => attr($r->data['html'], 'data-memory-version'), 'csrf_token' => attr($r->data['html'], 'data-csrf-token'));
check('versions: the answer carries the current version', $fresh['memory_version'] === current_row($w)['memory_version'] && $fresh['memory_version'] !== OLD_VERSION);
$r = post($w, 'edit_item', $fresh + array('item_id' => 'm_bbbbbb', 'text' => 'Lives in Zurich'));
check('versions: with the version from the answer, tab B\'s retry is applied', $r->data['status'] === 'ok' && item_by_id(current_items($w), 'm_bbbbbb')['text'] === 'Lives in Zurich');
check('versions: both edits are in the memory', item_by_id(current_items($w), 'm_aaaaaa')['text'] === 'Forest is 15 years old');

$before = stored($w);
foreach (array('delete_item' => array('item_id' => 'm_cccccc'), 'add_item' => array('section' => 'goals', 'text' => 'More')) as $action => $input) {
    $r = post($w, $action, array('memory_version' => OLD_VERSION, 'csrf_token' => LlmCsrfService::getToken()) + $input);
    check("versions: $action with an old version is refused (409)", $r->status === 409 && stored($w) === $before);
}
$r = post($w, 'edit_item', array('memory_version' => '', 'csrf_token' => LlmCsrfService::getToken(), 'item_id' => 'm_aaaaaa', 'text' => 'x'));
check('versions: an empty version is not the stored one for an existing memory (409)', $r->status === 409 && stored($w) === $before);
$r = post($w, 'edit_item', array('csrf_token' => LlmCsrfService::getToken(), 'item_id' => 'm_aaaaaa', 'text' => 'x'));
check('versions: a request without a version is invalid (422)', $r->status === 422 && stored($w) === $before);

echo "\n-- version when only the history is stored (log mode) --\n";
login();
$w = world('', 'log');
seed_items($w, forest_items(), 5, 'global', 'log');
check('log mode: there is no current row, only the history', $w->store->tableRows('llm_memory', 5) === array() && count(history_rows($w)) === 1);
$tab_a = client($w);
$tab_b = client($w);
check('log mode: the version is the id of the latest history row', $tab_a['memory_version'] === (string)history_rows($w)[0]['record_id']);
$r = post($w, 'edit_item', $tab_a + array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'));
check('log mode: the edit is applied (a history row is appended)', $r->data['status'] === 'ok' && count(history_rows($w)) === 2 && $w->store->tableRows('llm_memory', 5) === array());
check('log mode: the new memory is the latest history row', strpos(history_rows($w)[0]['memory_text'], 'Forest is 15 years old') !== false);
$r = post($w, 'edit_item', $tab_b + array('item_id' => 'm_bbbbbb', 'text' => 'Lives in Zurich'));
check('log mode: a tab with the version before the edit is refused (409)', $r->status === 409 && count(history_rows($w)) === 2);

/* =========================================================================
 * 6. Whose memory, which key
 * ========================================================================= */

echo "\n-- own memory only --\n";
login(5);
$w = world();
seed_items($w, forest_items(), 5);
seed_items($w, array(item('m_aaaaaa', 'person', 'Alex is a teacher'), item('m_eeeeee', 'goals', 'Run a marathon')), 9);
seed_items($w, array(item('m_aaaaaa', 'person', 'Forest in the other key')), 5, 'profile');
$alex_before = current_row($w, 9);
$profile_before = current_row($w, 5, 'profile');

$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old', 'user_id' => '9', 'id_users' => '9', 'record_id' => (string)$alex_before['record_id']));
check('own: the edit is applied', $r->data['status'] === 'ok');
check('own: it changed the session user\'s memory under the key of the section', item_by_id(current_items($w, 5), 'm_aaaaaa')['text'] === 'Forest is 15 years old');
check('own: user_id / id_users / record_id in the request are ignored: the other user\'s memory is untouched', current_row($w, 9) === $alex_before);
check('own: no history row for the other user', history_rows($w, 9) === array());

$before = stored($w);
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest in profile', 'memory_key' => 'profile'));
check('key: a key the section does not show is refused (403), without markup', $r->status === 403 && $r->data['status'] === 'forbidden' && !isset($r->data['html']));
check('key: ... and nothing is saved, the other key is untouched', stored($w) === $before && current_row($w, 5, 'profile') === $profile_before);

$r = act($w, 'edit_item', array('item_id' => 'm_eeeeee', 'text' => 'Hacked'));
check('own: an id that only exists in another user\'s memory is not found (404)', $r->status === 404 && current_row($w, 9) === $alex_before);
$r = act($w, 'delete_item', array('item_id' => 'm_eeeeee'));
check('own: ... for delete too', $r->status === 404 && current_row($w, 9) === $alex_before);

$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest in profile'), array('memory_keys' => 'profile'));
check('key: a section with memory_keys profile edits the profile memory', $r->data['status'] === 'ok' && item_by_id(current_items($w, 5, 'profile'), 'm_aaaaaa')['text'] === 'Forest in profile');
check('key: ... and leaves the global memory as it was', item_by_id(current_items($w, 5), 'm_aaaaaa')['text'] === 'Forest is 15 years old');
check('key: the history row has the key of the section', history_rows($w)[0]['memory_key'] === 'profile');

$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest again'), array('memory_keys' => ''));
check('key: a section without a memory key uses the module default key', $r->data['status'] === 'ok' && item_by_id(current_items($w, 5), 'm_aaaaaa')['text'] === 'Forest again');

$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest in Profile'), array('memory_keys' => '  Profile '));
check('key: the key of the section is trimmed and lower case, as the rules store it', $r->data['status'] === 'ok' && item_by_id(current_items($w, 5, 'profile'), 'm_aaaaaa')['text'] === 'Forest in Profile'
    && history_rows($w)[0]['memory_key'] === 'profile');
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest in PROFILE', 'memory_key' => ' PROFILE'), array('memory_keys' => 'profile'));
check('key: the key of the request is compared trimmed and in lower case too', $r->data['status'] === 'ok' && item_by_id(current_items($w, 5, 'profile'), 'm_aaaaaa')['text'] === 'Forest in PROFILE');

echo "\n-- several keys --\n";
login(5);
$w = world();
seed_items($w, forest_items(), 5);
seed_items($w, array(item('m_aaaaaa', 'person', 'Forest in the other key')), 5, 'profile');
$fields = array('memory_keys' => 'global, profile,global');
$model = model($w, $fields);
check('keys: the keys of the field, trimmed, each once, in their order', $model->getMemoryKeys() === array('global', 'profile'));
ob_start();
(new LlmMemoryEditorView($model))->output_content();
$page = ob_get_clean();
check('keys: one body per key, each with its key and version', substr_count($page, 'class="llm-memory-editor-body') === 2
    && strpos($page, 'data-memory-key="global"') !== false && strpos($page, 'data-memory-key="profile"') !== false && strpos($page, 'data-memory-key="global"') < strpos($page, 'data-memory-key="profile"'));
check('keys: every body has the heading of its key (the label of the key registry)', substr_count($page, 'class="lme-key-title"') === 2 && strpos($page, '>Global</h4>') !== false && strpos($page, '>Profile</h4>') !== false);
$global_before = current_row($w, 5);
$profile_client = array('memory_key' => 'profile', 'memory_version' => OLD_VERSION, 'csrf_token' => LlmCsrfService::getToken());
$r = post($w, 'edit_item', $profile_client + array('item_id' => 'm_aaaaaa', 'text' => 'Forest edited in profile'), $fields);
check('keys: an edit names its key and changes only that memory', $r->data['status'] === 'ok' && item_by_id(current_items($w, 5, 'profile'), 'm_aaaaaa')['text'] === 'Forest edited in profile' && current_row($w, 5) === $global_before);
check('keys: the answer is the body of that key only', substr_count($r->data['html'], 'class="llm-memory-editor-body') === 1 && attr($r->data['html'], 'data-memory-key') === 'profile'
    && strpos($r->data['html'], 'Forest edited in profile') !== false && strpos($r->data['html'], 'Lives in Bern') === false);
$r = post($w, 'edit_item', array('memory_key' => 'global', 'memory_version' => '20200101000000', 'csrf_token' => LlmCsrfService::getToken(), 'item_id' => 'm_aaaaaa', 'text' => 'x'), $fields);
check('keys: the version is checked per key (the other key\'s save does not matter)', $r->status === 409 && attr($r->data['html'], 'data-memory-key') === 'global');
$single = body($w);
check('keys: a section with one key shows no key heading', strpos($single, 'lme-key-title') === false);

echo "\n-- key without sections --\n";
login(5);
$w = world(array('global' => array(), 'notes' => array()));
$r = act($w, 'add_item', array('text' => 'Likes tea'));
check('flat key: an item is added without a section', $r->data['status'] === 'ok' && last_item($w)['section'] === '' && last_item($w)['text'] === 'Likes tea');
check('flat key: memory_text is a plain list, no heading', current_row($w)['memory_text'] === '- Likes tea');
$r = act($w, 'add_item', array('section' => 'person', 'text' => 'Is 14'));
check('flat key: a section the key does not have is refused', $r->status === 422 && count(current_items($w)) === 1);
foreach (array('sections', 'accordion', 'tabs', 'cards', 'flat') as $mode) {
    $html = body($w, array('display_mode' => $mode));
    check("flat key: layout $mode shows one flat list with an add button", strpos($html, 'lme-layout-flat') !== false && substr_count($html, 'lme-add-btn') === 1
        && strpos($html, '<div class="lme-add" data-section="">') !== false && strpos($html, 'lme-section-title') === false && strpos($html, 'Likes tea') !== false);
}
$r = act($w, 'edit_item', array('item_id' => last_item($w)['id'], 'text' => 'Likes green tea'));
check('flat key: edit works', $r->data['status'] === 'ok' && last_item($w)['text'] === 'Likes green tea');

echo "\n-- labels (memory_labels) --\n";
login(5);
$w = world(array('global' => array(array('code' => 'person', 'label' => 'Person', 'hint' => ''), array('code' => 'Goals', 'label' => 'Goals', 'hint' => '')), 'coach' => array(array('code' => 'person', 'label' => 'Person', 'hint' => ''))));
seed_items($w, array(item('m_aaaaaa', 'person', 'Forest is 14 years old'), item('m_dddddd', 'Goals', 'Run 5 km')));
$labels = json_encode(array('global' => 'Mein <Gedächtnis>', 'global.person' => 'Über mich', 'goals' => 'Ziele', 'coach.person' => 'Coach: ich'));
$model = model($w, array('memory_keys' => 'global,coach', 'memory_labels' => $labels));
check('labels: "<key>.<code>" wins over the registry label', $model->getDisplaySections('global')[0]['label'] === 'Über mich');
check('labels: "<code>" applies to every key, compared in lower case', $model->getDisplaySections('global')[1]['label'] === 'Ziele');
check('labels: "<key>.<code>" of another key', $model->getDisplaySections('coach')[0]['label'] === 'Coach: ich');
check('labels: the key heading comes from "<key>", else from the registry', $model->getKeyLabel('global') === 'Mein <Gedächtnis>' && $model->getKeyLabel('coach') === 'Coach');
check('labels: not translated, the registry label stays', model($w)->getDisplaySections('global')[0]['label'] === 'Person');
check('labels: an invalid field is ignored', model($w, array('memory_labels' => '{oops'))->getDisplaySections('global')[0]['label'] === 'Person');
$html = (new LlmMemoryEditorView($model))->render_body('global');
check('labels: the body shows the translated headings, escaped', strpos($html, 'Mein &lt;Gedächtnis&gt;') !== false && strpos($html, 'Über mich') !== false && strpos($html, 'Ziele') !== false);
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'), array('memory_keys' => 'global,coach', 'memory_labels' => $labels));
check('labels: memory_text keeps the labels of the registry (it is not per language)', $r->data['status'] === 'ok' && strpos(current_row($w)['memory_text'], '### Person') !== false && strpos(current_row($w)['memory_text'], 'Über mich') === false);

echo "\n-- guest and no session --\n";
foreach (array('guest' => GUEST_USER_ID, 'no session' => 0) as $who => $user_id) {
    login($user_id);
    $w = world();
    seed_items($w, forest_items(), 5);
    seed_items($w, array(item('m_aaaaaa', 'person', 'The guest account\'s memory')), GUEST_USER_ID);
    $before = stored($w);
    $_SESSION['csrf_token'] = str_repeat('a', 64);
    $r = post($w, 'edit_item', array('memory_version' => OLD_VERSION, 'csrf_token' => str_repeat('a', 64), 'item_id' => 'm_aaaaaa', 'text' => 'Changed'));
    check("$who: refused (403)", $r !== null && $r->status === 403 && $r->data['status'] === 'forbidden' && $r->data['success'] === false);
    check("$who: nothing is saved, not even for the guest account", stored($w) === $before);
    check("$who: the refusal carries no markup of the memory", !isset($r->data['html']));
    ob_start();
    (new LlmMemoryEditorView(model($w)))->output_content();
    check("$who: the view shows nothing", ob_get_clean() === '');
}

/* =========================================================================
 * 7. Request checks: CSRF, field types, the section addressed, the action
 * ========================================================================= */

echo "\n-- CSRF --\n";
login();
$w = world();
seed_items($w, forest_items());
$before = stored($w);
$_SESSION['csrf_token'] = str_repeat('b', 64);
$r = post($w, 'edit_item', array('memory_version' => OLD_VERSION, 'item_id' => 'm_aaaaaa', 'text' => 'Changed'));
check('csrf: no token is refused (403)', $r->status === 403 && stored($w) === $before);
check('csrf: ... with a message to reload', $r->data['message'] === 'Security check failed. Please reload the page and try again.');
$r = post($w, 'edit_item', array('memory_version' => OLD_VERSION, 'csrf_token' => str_repeat('c', 64), 'item_id' => 'm_aaaaaa', 'text' => 'Changed'));
check('csrf: a wrong token is refused (403)', $r->status === 403 && stored($w) === $before);
$r = post($w, 'edit_item', array('memory_version' => OLD_VERSION, 'csrf_token' => array(str_repeat('b', 64)), 'item_id' => 'm_aaaaaa', 'text' => 'Changed'));
check('csrf: a token that is an array is refused (403)', $r->status === 403 && stored($w) === $before);
unset($_SESSION['csrf_token']);
$r = post($w, 'edit_item', array('memory_version' => OLD_VERSION, 'csrf_token' => '', 'item_id' => 'm_aaaaaa', 'text' => 'Changed'));
check('csrf: a session without a token never accepts an empty one (403)', $r->status === 403 && stored($w) === $before);
$_SESSION['csrf_token'] = str_repeat('b', 64);
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);
$r = post($w, 'edit_item', array('memory_version' => OLD_VERSION, 'item_id' => 'm_aaaaaa', 'text' => 'Changed by header'));
check('csrf: the token in the X-CSRF-Token header is accepted', $r->status === 200 && item_by_id(current_items($w), 'm_aaaaaa')['text'] === 'Changed by header');

echo "\n-- field types --\n";
login();
$w = world();
seed_items($w, forest_items());
$before = stored($w);
foreach (array('text', 'item_id', 'memory_version') as $field) {
    $post = client($w) + array('item_id' => 'm_aaaaaa', 'text' => 'Changed');
    $post[$field] = array('x');
    $r = post($w, 'edit_item', $post);
    check("types: $field as an array is invalid (422)", $r->status === 422 && stored($w) === $before);
}
$post = client($w) + array('section' => array('goals'), 'text' => 'More');
$r = post($w, 'add_item', $post);
check('types: section as an array is invalid (422)', $r->status === 422 && stored($w) === $before);

echo "\n-- which controller answers --\n";
login();
$w = world();
seed_items($w, forest_items());
$before = stored($w);
$page = client($w);
check('section: a request addressed to another section is not this one\'s', post($w, 'edit_item', $page + array('item_id' => 'm_aaaaaa', 'text' => 'Changed'), array(), 42, SECTION_ID) === null && stored($w) === $before);
check('section: ... whichever of the two it is', post($w, 'edit_item', $page + array('item_id' => 'm_aaaaaa', 'text' => 'Changed'), array(), SECTION_ID, 42) === null && stored($w) === $before);
$_POST = array('__llm_memory_action' => 'edit_item') + $page + array('item_id' => 'm_aaaaaa', 'text' => 'Changed');
$answered = false;
try { new TestEditorController(model($w)); } catch (EditorTestResponse $e) { $answered = true; }
check('section: a request without a section_id is nobody\'s', !$answered && stored($w) === $before);
foreach (array('no action' => '', 'unknown action' => 'purge_memory', 'array action' => array('edit_item'), 'a page action' => 'delete') as $label => $action) {
    $_POST = array('__llm_memory_action' => $action, 'section_id' => (string)SECTION_ID) + $page + array('item_id' => 'm_aaaaaa', 'text' => 'Changed');
    $answered = false;
    try { new TestEditorController(model($w)); } catch (EditorTestResponse $e) { $answered = true; }
    check("section: $label is not answered", !$answered && stored($w) === $before);
}
$_POST = array('section_id' => array('41'), '__llm_memory_action' => 'edit_item') + $page;
$answered = false;
try { new TestEditorController(model($w)); } catch (EditorTestResponse $e) { $answered = true; }
check('section: a section_id that is an array is not this one\'s', !$answered);
$r = post($w, 'edit_item', $page + array('item_id' => 'm_aaaaaa', 'text' => 'Changed'), array(), SECTION_ID, SECTION_ID);
check('section: the section\'s own id is answered', $r !== null && $r->data['status'] === 'ok');

echo "\n-- the CMS checkboxes --\n";
foreach (array(
    array('edit_item', 'allow_edit', array('item_id' => 'm_aaaaaa', 'text' => 'Changed')),
    array('delete_item', 'allow_delete', array('item_id' => 'm_aaaaaa')),
    array('add_item', 'allow_add', array('section' => 'goals', 'text' => 'More')),
) as $case) {
    list($action, $flag, $input) = $case;
    login();
    $w = world();
    seed_items($w, forest_items());
    $before = stored($w);
    $fields = array($flag => '0');
    $r = post($w, $action, client($w, $fields) + $input, $fields);
    check("flags: $action is refused when $flag is off (403)", $r->status === 403 && $r->data['status'] === 'forbidden' && stored($w) === $before);
    $others = array_diff(array('allow_add', 'allow_edit', 'allow_delete'), array($flag));
    $html = body($w, $fields);
    check("flags: with $flag off its button is not in the body", strpos($html, array('allow_edit' => 'lme-edit"', 'allow_delete' => 'lme-delete"', 'allow_add' => 'lme-add-btn')[$flag]) === false);
    foreach ($others as $other) {
        check("flags: ... and the button of $other still is", strpos($html, array('allow_edit' => 'lme-edit"', 'allow_delete' => 'lme-delete"', 'allow_add' => 'lme-add-btn')[$other]) !== false);
    }
}
login();
$w = world();
seed_items($w, forest_items());
$r = act($w, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Changed'), array('allow_edit' => array('content' => '', 'default' => '1'), 'allow_delete' => array('content' => '', 'default' => '0')));
check('flags: an empty checkbox takes the default of the style (edit on)', $r->data['status'] === 'ok');
$r = act($w, 'delete_item', array('item_id' => 'm_aaaaaa'), array('allow_delete' => array('content' => '', 'default' => '0')));
check('flags: ... (delete off)', $r->status === 403);
$r = act($w, 'delete_item', array('item_id' => 'm_aaaaaa'));
check('flags: a field that was never saved allows the action', $r->data['status'] === 'ok');
$all_off = array('allow_add' => '0', 'allow_edit' => '0', 'allow_delete' => '0');
$html = body($w, $all_off);
check('flags: with everything off the body is read-only: no buttons, no inline form', strpos($html, '<button') === false && strpos($html, 'lme-form-template') === false && strpos($html, 'Lives in Bern') !== false);

/* =========================================================================
 * 8. A free-text memory is read-only
 * ========================================================================= */

echo "\n-- legacy memory --\n";
login();
$w = world();
seed_legacy($w, "### Person\n- Forest is **14** years old\n<script>alert(1)</script>");
$before = stored($w);
foreach (array(
    'edit_item' => array('item_id' => 'm_aaaaaa', 'text' => 'x'),
    'delete_item' => array('item_id' => 'm_aaaaaa'),
    'add_item' => array('section' => 'goals', 'text' => 'x'),
) as $action => $input) {
    $r = act($w, $action, $input);
    check("legacy: $action is refused (409 read_only)", $r->status === 409 && $r->data['status'] === 'read_only' && stored($w) === $before);
}
$html = body($w);
check('legacy: the memory text is shown, rendered as markdown', strpos($html, '<strong>14</strong>') !== false && strpos($html, 'Forest is') !== false);
check('legacy: raw HTML in it is not (markdown safe mode)', strpos($html, '<script>') === false);
check('legacy: a note says it is not editable yet', strpos($html, 'lme-note') !== false && strpos($html, 'cannot be changed here') !== false);
$html = (new LlmMemoryEditorView(model($w, array('read_only_text' => 'Noch nicht <b>bearbeitbar</b>'))))->render_body('global');
check('legacy: the note is the CMS field read_only_text (escaped)', strpos($html, 'Noch nicht &lt;b&gt;bearbeitbar&lt;/b&gt;') !== false && strpos($html, 'cannot be changed here') === false);
check('legacy: no item list, no buttons', strpos($html, 'lme-item') === false && strpos($html, '<button') === false);

/* =========================================================================
 * 9. One transaction
 * ========================================================================= */

echo "\n-- transaction --\n";
login();
$w = world();
seed_items($w, forest_items(), 5, 'global', 'both');
$before = stored($w);
$page = client($w);
$w->store->failing_table = 'llm_memory_history';
clear_log();
$r = post($w, 'edit_item', $page + array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'));
check('failure: a history row that cannot be saved fails the request (500)', $r->status === 500 && $r->data['success'] === false && $r->data['status'] === 'failed');
check('failure: the participant gets a message and no markup', $r->data['message'] === 'Your change could not be saved. Please try again.' && !isset($r->data['html']));
check('failure: the current row is not changed either (rolled back)', $w->store->rows === $before['rows'] && history_rows($w) !== array() && count(history_rows($w)) === 1);
check('failure: the transaction was rolled back, not committed', $w->db->transactions === array('begin', 'rollback'));
check('failure: only the user-input cache was cleared, never everything', $w->db->get_cache()->cleared === array(Cache::CACHE_TYPE_USER_INPUT));
check('failure: it is logged with its context', strpos(logged(), 'llmMemoryEditor save') !== false);
$w->store->failing_table = '';
$w->db->transactions = array();
$r = post($w, 'edit_item', $page + array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'));
check('failure: the same request goes through once the store is back', $r->data['status'] === 'ok' && $w->db->transactions === array('begin', 'commit'));

$w = world();
seed_items($w, forest_items(), 5, 'global', 'both');
$before = stored($w);
$page = client($w);
$w->store->failing_table = 'llm_memory';
$r = post($w, 'delete_item', $page + array('item_id' => 'm_aaaaaa'));
check('failure: a current row that cannot be saved fails the request (500)', $r->status === 500);
check('failure: the history row that was appended after it is rolled back too', $w->store->rows === $before['rows'] && count(history_rows($w)) === 1);

$w = world();
seed_items($w, forest_items(), 5, 'global', 'both');
$before = stored($w);
$page = client($w);
$w->store->before_save = function ($table) {
    throw new Error('connection lost'); // not an Exception: the storage does not catch it
};
clear_log();
$r = post($w, 'delete_item', $page + array('item_id' => 'm_aaaaaa'));
check('failure: an error while saving is a failed request (500), not a crash', $r !== null && $r->status === 500 && $w->store->rows === $before['rows']);
check('failure: ... rolled back, and the error is logged with its context', $w->db->transactions === array('begin', 'rollback') && strpos(logged(), 'llmMemoryEditor save') !== false && strpos(logged(), 'connection lost') !== false);
$w->store->before_save = null;

/* =========================================================================
 * 10. The view
 * ========================================================================= */

echo "\n-- view --\n";
login();
$config = json_encode(array('global' => array('sections' => array(
    array('code' => 'goals', 'label' => 'My <goals>', 'hint' => ''),
    array('code' => 'person', 'label' => 'About me', 'hint' => ''),
    array('code' => 'notes', 'label' => 'Notes', 'hint' => ''),
))));
$w = world($config);
seed_items($w, array_merge(forest_items(), array(
    item('m_ffffff', 'hobbies', 'Chess & <i>go</i>'),
    item('m_999999', 'person', '<img src=x onerror=alert(1)>'),
)));
$fields = array(
    'label_edit' => 'Ändern', 'label_delete' => 'Löschen', 'label_add' => 'Hinzufügen', 'label_save' => 'Speichern', 'label_cancel' => 'Abbrechen',
    'confirm_delete_text' => 'Wirklich "löschen"?',
);
ob_start();
(new LlmMemoryEditorView(model($w, $fields)))->output_content();
$html = ob_get_clean();
check('view: the section has its id, the style classes and the body', strpos($html, 'id="section-41"') !== false && strpos($html, 'llm-memory-editor') !== false && strpos($html, 'llm-memory-editor-body') !== false);
check('view: the body carries the section id, the version and the token', attr($html, 'data-section-id') === '41' && attr($html, 'data-memory-version') === OLD_VERSION && attr($html, 'data-csrf-token') === LlmCsrfService::getToken());
check('view: the sections are in the order of the key, with their labels (escaped)', strpos($html, 'My &lt;goals&gt;') !== false && strpos($html, 'My &lt;goals&gt;') < strpos($html, 'About me'));
check('view: the section the key does not define comes last, headed by its code', strpos($html, 'hobbies') > strpos($html, 'About me') && strpos($html, 'data-section="hobbies"') !== false);
check('view: the section of the key without items is shown, to add to', strpos($html, 'data-section="notes"') !== false);
check('view: the sections the key no longer defines come after the defined ones', strpos($html, 'data-section="context"') > strpos($html, 'data-section="notes"') && strpos($html, 'data-section="hobbies"') > strpos($html, 'data-section="context"'));
check('view: the item texts are escaped', strpos($html, 'Chess &amp; &lt;i&gt;go&lt;/i&gt;') !== false && strpos($html, '<i>go</i>') === false);
check('view: markup in an item is not markup', strpos($html, '<img') === false && strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false);
check('view: every item has its id for the script', substr_count($html, 'data-item-id=') === 6 && strpos($html, 'data-item-id="m_aaaaaa"') !== false);
check('view: the labels come from the CMS fields', strpos($html, '>Ändern<') !== false && strpos($html, '>Löschen<') !== false && strpos($html, '+ Hinzufügen') !== false && strpos($html, '>Speichern<') !== false && strpos($html, '>Abbrechen<') !== false);
preg_match('/data-confirmation="([^"]*)"/', $html, $m);
$confirmation = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
check('view: the delete button carries the confirmation in the shape the core scripts use', $confirmation === array(
    'confirmation_title' => 'Löschen', 'confirmation_message' => 'Wirklich "löschen"?', 'confirmation_continue' => 'Löschen', 'label_cancel' => 'Abbrechen',
));
check('view: the add button is only in the sections of the key', substr_count($html, 'lme-add-btn') === 3 && strrpos($html, 'lme-add-btn') < strpos($html, 'data-section="context"'));
check('view: with adding off the section without items is not shown', strpos(body($w, array('allow_add' => '0')), 'data-section="notes"') === false);
check('view: the delete button is no core .btn and sits in no form (core scripts would submit it)', strpos($html, 'class="btn') === false && strpos($html, '<form') === false);
check('view: the inline form template has an input limited to the item length', strpos($html, 'maxlength="' . LlmMemoryItems::MAX_TEXT_LENGTH . '"') !== false);
check('view: output twice, the markup is the same (nothing is remembered between renders)', body($w, $fields) === body($w, $fields));

$html = body($w);
check('view: the default labels', strpos($html, '>Edit<') !== false && strpos($html, '>Delete<') !== false && strpos($html, '+ Add') !== false && strpos($html, '>Save<') !== false && strpos($html, '>Cancel<') !== false);

echo "\n-- view: empty --\n";
login(11);
$w = world();
$html = body($w, array('allow_add' => '0', 'empty_text' => 'Nothing here <yet>'));
check('empty: no memory and no adding shows the empty text, escaped', strpos($html, 'Nothing here &lt;yet&gt;') !== false && strpos($html, 'lme-section') === false);
$html = body($w, array('allow_add' => '0'));
check('empty: without an empty_text field the default text is shown', strpos($html, 'Nothing saved yet.') !== false);

echo "\n-- view: follows the memory --\n";
login();
$w = world();
seed_items($w, forest_items());
$view = new LlmMemoryEditorView(model($w));
$first = $view->render_body('global');
$w->storage->saveMemoryUpdate(5, 'global', array(
    'memory_text' => 'x', 'memory_object' => LlmMemoryItems::toMemoryObject(array(item('m_123456', 'goals', 'Added by the worker', 'llm'))),
    'flat_fields' => array(), 'change_summary' => 'Worker',
), array('rule_id' => 5, 'source_type' => 'form_action_submit', 'source_ref' => 'r', 'payload_json' => array(), 'event_at' => '2026-02-01 00:00:00', 'dedupe_key' => 'r'), 'record');
$second = $view->render_body('global');
check('refresh: the same view renders the memory as it is now (section refresh, refresh_sections)', strpos($first, 'Lives in Bern') !== false && strpos($second, 'Lives in Bern') === false && strpos($second, 'Added by the worker') !== false);
check('refresh: ... with the new version', attr($first, 'data-memory-version') === OLD_VERSION && attr($second, 'data-memory-version') !== OLD_VERSION);

/* =========================================================================
 * 11. Layouts: the CMS field display_mode
 * ========================================================================= */

/** The body of the section in a layout. */
function layout($w, $mode, array $fields = array(), $section_id = SECTION_ID)
{
    return (new LlmMemoryEditorView(model($w, array('display_mode' => $mode) + $fields, $section_id)))->render_body('global');
}

/** All ids of the markup. */
function ids($html)
{
    preg_match_all('/\sid="([^"]*)"/', $html, $m);
    return $m[1];
}

/** The numbers in the count badges of the section headers, in order. */
function counts($html)
{
    preg_match_all('/<span class="lme-count[^"]*">(\d+)<\/span>/', $html, $m);
    return array_map('intval', $m[1]);
}

/** The values of an attribute, in order. */
function attr_all($html, $name)
{
    preg_match_all('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $html, $m);
    return $m[1];
}

/** The markup as a DOM, to check what is a child of what (Bootstrap's tab script needs it). */
function xpath($html)
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>');
    libxml_clear_errors();
    return new DOMXPath($dom);
}

echo "\n-- view: layouts (display_mode) --\n";
login();
$modes = array('sections', 'accordion', 'tabs', 'cards', 'flat');
$config = json_encode(array('global' => array('sections' => array(
    array('code' => 'person', 'label' => 'About me', 'hint' => ''),
    array('code' => 'notes', 'label' => 'Notes', 'hint' => ''),
    array('code' => 'goals', 'label' => 'Goals <b>', 'hint' => ''),
))));
$w = world($config);
seed_items($w, array(
    item('m_aaaaaa', 'person', 'Forest is 14 years old'),
    item('m_bbbbbb', 'person', 'Lives in Bern'),
    item('m_dddddd', 'goals', 'Wants to become a football player'),
    item('m_ffffff', 'hobbies', 'Chess & <i>go</i>'),
));
// four blocks: person (2 items), notes (empty, to add to), goals (1) and hobbies (1, no longer defined by the key)

check('layouts: the view knows the five layouts, the default first', LlmMemoryEditorView::DISPLAY_MODES === $modes);
foreach ($modes as $mode) {
    $html = layout($w, $mode);
    check("layout $mode: the body carries its layout class, and only that one", substr_count($html, 'lme-layout-') === 1 && strpos($html, 'llm-memory-editor-body lme-layout-' . $mode . '"') !== false);
    check("layout $mode: the same item rows as in every layout (4 items, escaped, edit and delete on each)", substr_count($html, 'data-item-id=') === 4
        && substr_count($html, 'lme-edit"') === 4 && substr_count($html, 'lme-delete"') === 4
        && strpos($html, 'Chess &amp; &lt;i&gt;go&lt;/i&gt;') !== false && strpos($html, '<i>go</i>') === false);
    check("layout $mode: one inline form template, no core .btn, no <form>", substr_count($html, 'lme-form-template') === 1 && strpos($html, 'class="btn') === false && strpos($html, '<form') === false);
    check("layout $mode: the body keeps what the script needs (section id, version, token)", attr($html, 'data-section-id') === '41' && attr($html, 'data-memory-version') === OLD_VERSION && attr($html, 'data-csrf-token') === LlmCsrfService::getToken());
}

// no field, an empty one or a value that is no layout is the default
check('layouts: without a display_mode field the layout is sections', strpos(body($w), 'lme-layout-sections') !== false);
foreach (array('', 'carousel', 'Accordion', 'tabs,flat', '<script>alert(1)</script>') as $unknown) {
    $html = layout($w, $unknown);
    check("layouts: '$unknown' is no layout, so it is sections", substr_count($html, 'lme-layout-') === 1 && strpos($html, 'lme-layout-sections') !== false && strpos($html, '<script>') === false);
}
check('layouts: blanks around a layout are ignored', strpos(layout($w, ' tabs '), 'lme-layout-tabs') !== false);

// sections: headings, no widgets
$html = layout($w, 'sections');
check('sections: a header with the title for each section, no collapse, no tabs, no cards', substr_count($html, 'lme-section-header') === 4 && substr_count($html, '<h5 class="lme-section-title"') === 4
    && strpos($html, 'data-toggle') === false && strpos($html, 'nav-tabs') === false && strpos($html, 'card') === false);
check('sections: the add button is in the sections of the key (3), at the foot of each', substr_count($html, 'lme-add-btn') === 3 && substr_count($html, '<div class="lme-add" data-section="') === 3);

// accordion
$html = layout($w, 'accordion');
check('accordion: one collapse toggle and one panel for each section', substr_count($html, 'data-toggle="collapse"') === 4 && substr_count($html, 'lme-section-panel collapse') === 4);
check('accordion: the first section with items is open, the others are closed', substr_count($html, 'aria-expanded="true"') === 1 && substr_count($html, 'aria-expanded="false"') === 3
    && substr_count($html, 'lme-section-panel collapse show"') === 1 && substr_count($html, 'lme-section-toggle collapsed"') === 3
    && strpos($html, 'data-target="#lme-41-global--person-panel" aria-expanded="true"') !== false);
check('accordion: every header shows the title and the number of items', counts($html) === array(2, 0, 1, 1) && substr_count($html, '<span class="lme-section-title">') === 4);
$all_ids = ids($html);
check('accordion: the ids are unique', count($all_ids) === count(array_unique($all_ids)));
check('accordion: every toggle names a panel that exists (data-target, aria-controls) and every panel its header', array_map(function ($t) { return ltrim($t, '#'); }, attr_all($html, 'data-target')) === attr_all($html, 'aria-controls')
    && array_diff(attr_all($html, 'aria-controls'), $all_ids) === array() && array_diff(attr_all($html, 'aria-labelledby'), $all_ids) === array() && count(attr_all($html, 'aria-labelledby')) === 4);
$parents = array_unique(attr_all($html, 'data-parent'));
check('accordion: the panels share one parent that exists, so one is open at a time', count($parents) === 1 && in_array(ltrim($parents[0], '#'), $all_ids, true) && count(attr_all($html, 'data-parent')) === 4);
check('accordion: two editors on a page share no id', array_intersect($all_ids, ids(layout($w, 'accordion', array(), 42))) === array());
$x = xpath($html);
check('accordion: the add button and the items are inside the panel of their section', $x->query('//div[contains(@class,"lme-section-panel")]//button[contains(@class,"lme-add-btn")]')->length === 3
    && $x->query('//div[contains(@class,"lme-section-panel")]//li[contains(@class,"lme-item")]')->length === 4 && $x->query('//button[contains(@class,"lme-section-toggle")]//span[contains(@class,"lme-count")]')->length === 4);

$config_notes_first = json_encode(array('global' => array('sections' => array(
    array('code' => 'notes', 'label' => 'Notes', 'hint' => ''),
    array('code' => 'person', 'label' => 'About me', 'hint' => ''),
))));
$w_nf = world($config_notes_first);
$html = layout($w_nf, 'accordion');
check('accordion: with nothing saved yet the first section is the one that is open', strpos($html, 'data-target="#lme-41-global--notes-panel" aria-expanded="true"') !== false && substr_count($html, 'aria-expanded="true"') === 1);
seed_items($w_nf, array(item('m_aaaaaa', 'person', 'Forest is 14 years old')));
$html = layout($w_nf, 'accordion');
check('accordion: when the first section is empty the first one with items is open', strpos($html, 'data-target="#lme-41-global--person-panel" aria-expanded="true"') !== false && strpos($html, 'data-target="#lme-41-global--notes-panel" aria-expanded="false"') !== false);
$html = layout($w_nf, 'tabs');
check('tabs: ... and the same section is the selected tab', strpos($html, 'lme-tab active" id="lme-41-global--person-tab"') !== false && strpos($html, 'lme-tab" id="lme-41-global--notes-tab"') !== false && substr_count($html, 'aria-selected="true"') === 1);

// tabs
$html = layout($w, 'tabs');
$x = xpath($html);
check('tabs: Bootstrap nav-tabs with one tab and one pane for each section', substr_count($html, 'nav nav-tabs') === 1 && substr_count($html, 'data-toggle="tab"') === 4 && substr_count($html, 'role="tab"') === 4 && substr_count($html, 'role="tabpanel"') === 4);
check('tabs: the tabs are direct children of the nav and the panes of the tab content (the tab script needs it)', $x->query('//div[contains(@class,"nav-tabs")]/button[@role="tab"]')->length === 4 && $x->query('//div[contains(@class,"tab-content")]/section[@role="tabpanel"]')->length === 4);
check('tabs: the first section with items is selected: active tab, shown pane', substr_count($html, 'aria-selected="true"') === 1 && substr_count($html, 'lme-tab active"') === 1 && substr_count($html, 'tab-pane fade lme-section show active"') === 1
    && strpos($html, 'lme-tab active" id="lme-41-global--person-tab"') !== false && strpos($html, 'id="lme-41-global--person-panel"') !== false);
check('tabs: every tab shows the title and the number of items', counts($html) === array(2, 0, 1, 1) && $x->query('//button[@role="tab"]/span[contains(@class,"lme-count")]')->length === 4);
$all_ids = ids($html);
check('tabs: the ids are unique, and every tab and pane names the other', count($all_ids) === count(array_unique($all_ids))
    && array_map(function ($t) { return ltrim($t, '#'); }, attr_all($html, 'data-target')) === attr_all($html, 'aria-controls') && array_diff(attr_all($html, 'aria-controls'), $all_ids) === array() && array_diff(attr_all($html, 'aria-labelledby'), $all_ids) === array());
check('tabs: the add button and the items are inside the panes (3 add buttons, 4 items)', $x->query('//section[@role="tabpanel"]//button[contains(@class,"lme-add-btn")]')->length === 3 && $x->query('//section[@role="tabpanel"]//li[contains(@class,"lme-item")]')->length === 4);
$same_code = true;
foreach (array('person', 'notes', 'goals', 'hobbies') as $code) {
    $same_code = $same_code && $x->query('//button[@role="tab"][@data-section="' . $code . '"]')->length === 1 && $x->query('//section[@role="tabpanel"][@data-section="' . $code . '"]')->length === 1;
}
check('tabs: the tab and the pane of a section carry its code (the script restores the tab by it)', $same_code);
check('tabs: two editors on a page share no id', array_intersect($all_ids, ids(layout($w, 'tabs', array(), 42))) === array());

// cards
$html = layout($w, 'cards');
$x = xpath($html);
check('cards: one Bootstrap card for each section with a header (title and number of items) and a body', substr_count($html, 'lme-section card"') === 4 && substr_count($html, 'class="lme-section-header"') === 4 && substr_count($html, 'class="lme-section-body"') === 4
    && counts($html) === array(2, 0, 1, 1) && $x->query('//section[contains(@class,"card")]/div[@class="lme-section-header"]/h5[contains(@class,"lme-section-title")]')->length === 4);
check('cards: the header and the body are the editor\'s own, a site theme that restyles .card-header / .card-body cannot reach them', strpos($html, 'card-header') === false && strpos($html, 'card-body') === false);
check('cards: the items and the add buttons are in the card bodies', $x->query('//section[contains(@class,"card")]/div[@class="lme-section-body"]//li[contains(@class,"lme-item")]')->length === 4 && $x->query('//section[contains(@class,"card")]/div[@class="lme-section-body"]//button[contains(@class,"lme-add-btn")]')->length === 3
    && strpos($html, 'data-toggle') === false);

// flat
$html = layout($w, 'flat');
check('flat: one list with all the items, no section headings', substr_count($html, '<ul') === 1 && substr_count($html, 'data-item-id=') === 4
    && strpos($html, 'lme-section-title') === false && strpos($html, '<h5') === false && strpos($html, 'About me') === false && strpos($html, 'lme-section-header') === false);
check('flat: the key has several sections, so there is no add button (which section would it add to?)', strpos($html, 'lme-add-btn') === false && strpos($html, 'class="lme-add"') === false);
check('flat: with the default sections of a key (person, context, goals) it is the same', strpos(layout(world(), 'flat'), 'lme-add-btn') === false);

$config_one = json_encode(array('global' => array('sections' => array(array('code' => 'notes', 'label' => 'Notes', 'hint' => '')))));
$w_one = world($config_one);
seed_items($w_one, array(item('m_aaaaaa', 'notes', 'First note'), item('m_bbbbbb', 'notes', 'Second note'), item('m_cccccc', 'old', 'From a section the key dropped')));
$html = layout($w_one, 'flat');
check('flat: a key with exactly one section has the add button, and it adds to that section', substr_count($html, 'lme-add-btn') === 1 && strpos($html, '<div class="lme-add" data-section="notes">') !== false && substr_count($html, 'data-item-id=') === 3);
check('flat: ... only while adding is allowed', strpos(layout($w_one, 'flat', array('allow_add' => '0')), 'lme-add-btn') === false);
$r = act($w_one, 'add_item', array('section' => 'notes', 'text' => 'Third note'), array('display_mode' => 'flat'));
$added = last_item($w_one);
check('flat: the add goes through and the answer is the flat list again', $r->data['status'] === 'ok' && $added['section'] === 'notes' && $added['text'] === 'Third note'
    && strpos($r->data['html'], 'lme-layout-flat') !== false && substr_count($r->data['html'], 'data-item-id=') === 4 && substr_count($r->data['html'], 'lme-add-btn') === 1);
$r = act($w_one, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'First note, edited'), array('display_mode' => 'flat'));
check('flat: edit works as usual', $r->data['status'] === 'ok' && item_by_id(current_items($w_one), 'm_aaaaaa')['text'] === 'First note, edited');
$r = act($w_one, 'delete_item', array('item_id' => 'm_bbbbbb'), array('display_mode' => 'flat'));
check('flat: delete works as usual', $r->data['status'] === 'ok' && item_by_id(current_items($w_one), 'm_bbbbbb') === null);
$html = layout(world($config_one), 'flat');
check('flat: nothing saved yet shows the empty text and the add button of the one section', strpos($html, 'Nothing saved yet.') !== false && substr_count($html, 'lme-add-btn') === 1);

// a free-text memory is read-only in every layout, an empty memory without adding shows the empty text only
$w_legacy = world();
seed_legacy($w_legacy);
login(11);
$w_nothing = world();
foreach ($modes as $mode) {
    login();
    $html = layout($w_legacy, $mode);
    check("layout $mode: a free-text memory is shown read-only (no list, buttons, collapse or tabs)", strpos($html, 'lme-legacy') !== false && strpos($html, 'lme-layout-' . $mode . '"') !== false
        && strpos($html, '<button') === false && strpos($html, 'lme-item') === false && strpos($html, 'data-toggle') === false && strpos($html, 'nav-tabs') === false);
    login(11);
    $html = layout($w_nothing, $mode, array('allow_add' => '0'));
    check("layout $mode: no memory and no adding shows the empty text only", strpos($html, 'Nothing saved yet.') !== false
        && strpos($html, 'lme-section') === false && strpos($html, 'lme-flat') === false && strpos($html, 'data-toggle') === false && strpos($html, 'nav-tabs') === false);
}
login();

// the answer of every action is the body in the layout of the page
foreach (array('sections', 'accordion', 'tabs', 'cards', 'flat') as $mode) {
    $w_act = world($config);
    seed_items($w_act, array(item('m_aaaaaa', 'person', 'Forest is 14 years old'), item('m_dddddd', 'goals', 'Wants to become a football player')));
    $r = act($w_act, 'edit_item', array('item_id' => 'm_aaaaaa', 'text' => 'Forest is 15 years old'), array('display_mode' => $mode));
    check("layout $mode: the answer of an edit is the body in the same layout", $r->data['status'] === 'ok' && strpos($r->data['html'], 'llm-memory-editor-body lme-layout-' . $mode . '"') !== false && strpos($r->data['html'], 'Forest is 15 years old') !== false);
}
$w_act = world($config);
seed_items($w_act, array(item('m_aaaaaa', 'person', 'Forest is 14 years old')));
$r = post($w_act, 'edit_item', array('memory_version' => '20200101000000', 'csrf_token' => LlmCsrfService::getToken(), 'item_id' => 'm_aaaaaa', 'text' => 'x'), array('display_mode' => 'tabs'));
check('layout tabs: the answer to an outdated page is the current memory in the layout too', $r->status === 409 && $r->data['status'] === 'conflict' && strpos($r->data['html'], 'lme-layout-tabs') !== false && strpos($r->data['html'], 'Forest is 14 years old') !== false);

// the select of the CMS (lookups of the migration, field map of the hooks) and the view agree on the layouts
$sql = (string)file_get_contents(dirname(__DIR__) . '/server/db/v1.10.0.sql');
preg_match_all("/\\(\\s*'llmMemoryDisplayMode'\\s*,\\s*'([^']+)'\\s*,\\s*'([^']+)'/", $sql, $lookups, PREG_SET_ORDER);
check('select: the migration registers the layouts of the view as the options, in its order, each with a label', array_column($lookups, 1) === LlmMemoryEditorView::DISPLAY_MODES && count(array_filter(array_column($lookups, 2))) === count($lookups));
check('select: the migration links display_mode to the style with the default of the view', preg_match("/get_style_id\\('llmMemoryEditor'\\),\\s*get_field_id\\('display_mode'\\),\\s*'" . preg_quote(LlmMemoryEditorView::DISPLAY_MODES[0], '/') . "'/", $sql) === 1
    && preg_match("/INSERT IGNORE INTO `fieldType`[^;]*'select-llm-memory-display-mode'/", $sql) === 1 && preg_match("/INSERT IGNORE INTO `fields`[^;]*'display_mode'/", $sql) === 1);
check('select: the hooks render display_mode on the lookup type the migration registers', preg_match("/'display_mode'\\s*=>\\s*array\\('select-llm-memory-display-mode',\\s*LLM_LOOKUP_MEMORY_DISPLAY_MODE\\)/", (string)file_get_contents($server . '/component/LlmHooks.php')) === 1
    && preg_match("/define\\('LLM_LOOKUP_MEMORY_DISPLAY_MODE',\\s*'llmMemoryDisplayMode'\\)/", (string)file_get_contents($server . '/service/globals.php')) === 1);

echo "\n-- mobile --\n";
login();
$w = world();
seed_items($w, forest_items());
$mobile = (new LlmMemoryEditorView(model($w, array('memory_keys' => 'global'))))->output_content_mobile();
check('mobile: the layout is part of the data (sections by default)', $mobile['display_mode'] === 'sections');
check('mobile: ... as the CMS set it, or sections when it is no layout', (new LlmMemoryEditorView(model($w, array('display_mode' => 'accordion'))))->output_content_mobile()['display_mode'] === 'accordion'
    && (new LlmMemoryEditorView(model($w, array('display_mode' => 'carousel'))))->output_content_mobile()['display_mode'] === 'sections');
check('mobile: read-only data with the key, the sections and the items', count($mobile['memories']) === 1 && $mobile['memories'][0]['memory_key'] === 'global' && $mobile['editable'] === false && $mobile['memories'][0]['legacy_text'] === ''
    && array_column($mobile['memories'][0]['sections'], 'code') === array('person', 'context', 'goals')
    && $mobile['memories'][0]['sections'][0]['items'] === array(array('id' => 'm_aaaaaa', 'text' => 'Forest is 14 years old'), array('id' => 'm_bbbbbb', 'text' => 'Lives in Bern')));
check('mobile: the output is JSON-serializable', json_encode($mobile) !== false);
$w = world();
seed_legacy($w);
$mobile = (new LlmMemoryEditorView(model($w)))->output_content_mobile();
check('mobile: a free-text memory is given as text', $mobile['memories'][0]['legacy_text'] === "### Person\n- Forest is 14 years old" && $mobile['editable'] === false);

/* =========================================================================
 * Guard
 * ========================================================================= */

echo "\n-- guard --\n";
check('the LLM provider was never called' . ($llm_trap ? '' : ' (not trapped: no uopz)'), $llm_calls === 0);

echo "\n" . $total . " checks, " . $failed . " failed\n";
exit($failed > 0 ? 1 : 0);
