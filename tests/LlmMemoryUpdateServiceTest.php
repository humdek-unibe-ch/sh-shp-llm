<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the memory worker of structured memory (plugin v1.10.0):
 * LlmMemoryUpdateService::executeLlmSummarization() answers with operations on
 * the items of a memory instead of rewriting it.
 *
 *  - A free-text memory (written before items existed) goes to the model as
 *    "Legacy Memory (to convert)"; its add operations become items/v1 and
 *    memory_text is rendered from them.
 *  - An items memory goes to the model with the ids; the operations change only
 *    the items they name, every other item stays exactly as it was.
 *  - Empty ops is a valid answer. An answer that is not valid is tried once more
 *    and then rejected without saving; so is a conversion that converts nothing.
 *  - The operations are applied to the memory as it is stored after the model
 *    answered: an edit made meanwhile survives, an operation on an item that is
 *    gone is skipped.
 *  - The sections of a key come from the key registry (llm_memory_keys.sections_json)
 *    and reach the prompt and the output schema; a key without sections is one
 *    flat list (no section in the schema, a section the model sends is dropped).
 *
 * The real LlmMemoryUpdateService, LlmMemoryStorageService, LlmMemoryConfigService,
 * LlmMemoryItems and the interpolation of the core PageDb run. Only the LLM provider
 * and the database below the storage are replaced (tests/support/LlmMemoryTestStore.php).
 *
 * Run: php tests/LlmMemoryUpdateServiceTest.php
 */

$server = dirname(__DIR__) . '/server';
require_once __DIR__ . '/support/LlmMemoryTestStore.php';
ob_start(); // some files end with stray newlines after their closing tags
require_once $server . '/service/LlmMemoryUpdateService.php';
require_once $server . '/service/LlmDatasetIngestionService.php';
ob_end_clean();

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

$log_file = tempnam(sys_get_temp_dir(), 'llm_mem_update_');
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
 * Stand-ins: the module config and the LLM provider
 * ========================================================================= */

/** The real worker; the module config comes from here instead of the database. */
class TestUpdateService extends LlmMemoryUpdateService
{
    protected function getLlmConfig()
    {
        return array('llm_default_model' => 'test-model', 'llm_servers' => array());
    }
}

/** The LLM provider boundary: answers with what the test queued, records what it was asked. */
class ScriptedLlm extends LlmService
{
    /** @var array each call: array('messages', 'model', 'temperature', 'max_tokens', 'log_options') */
    public $calls = array();
    /** @var array queued answers: a content string, or false for a provider that returns nothing */
    public $responses = array();
    /** @var callable|null runs when a call arrives, with the number of the call: what happens while the model works */
    public $on_call = null;

    public function __construct()
    {
    }

    protected function getLlmConfig()
    {
        return array('llm_default_model' => 'test-model', 'llm_servers' => array());
    }

    public function callLlmApi($messages, $model, $temperature = null, $max_tokens = null, $log_options = null)
    {
        $this->calls[] = array(
            'messages' => $messages, 'model' => $model, 'temperature' => $temperature,
            'max_tokens' => $max_tokens, 'log_options' => $log_options,
        );
        if ($this->on_call !== null) {
            call_user_func($this->on_call, count($this->calls));
        }
        $content = array_shift($this->responses);
        return $content === null || $content === false ? false : array('content' => $content);
    }

    public function createConversation($user_id, $title = null, $model = null, $temperature = null, $max_tokens = null, $section_id = null, $source_type = null)
    {
        return 7;
    }
}

/* =========================================================================
 * Helpers
 * ========================================================================= */

/** A worker with the stores, the config and the scripted LLM around it. */
function world($keys = '')
{
    $store = new MemoryTestStore();
    $store->addTable('llm_memory');
    $store->addTable('llm_memory_history');
    $db = new MemoryTestDb($store);
    $services = new MemoryTestServices($db, new MemoryTestUserInput($store));
    $config = new MemoryTestConfig($services, $keys);
    $storage = new LlmMemoryStorageService($services, $config);
    $llm = new ScriptedLlm();

    $worker = (new ReflectionClass('TestUpdateService'))->newInstanceWithoutConstructor();
    foreach (array('services' => $services, 'db' => $db, 'cache' => $db->get_cache()) as $name => $value) {
        $property = new ReflectionProperty('BaseLlmService', $name);
        $property->setAccessible(true);
        $property->setValue($worker, $value);
    }
    foreach (array('config_service' => $config, 'storage_service' => $storage, 'llm_service' => $llm) as $name => $value) {
        $property = new ReflectionProperty('LlmMemoryUpdateService', $name);
        $property->setAccessible(true);
        $property->setValue($worker, $value);
    }

    return (object)array(
        'worker' => $worker, 'llm' => $llm, 'store' => $store, 'db' => $db,
        'services' => $services, 'storage' => $storage,
    );
}

function rule($extra = array())
{
    return array_merge(array('id' => 5, 'label' => 'Forest profile', 'memory_keys' => array('global')), $extra);
}

/** A form submission event; $n makes it a different, later one each time. */
function payload($n, $request, $extra = array())
{
    return array_merge(array(
        'user_id' => 5,
        'source_type' => 'form_action_submit',
        'source_ref' => 'form-' . $n,
        'trigger_type' => 'finished',
        'fields' => array('memory_change_request' => $request),
        'event_at' => date('Y-m-d H:i:s', strtotime('2026-11-26 10:00:00') + $n),
        'user_language' => '',
    ), $extra);
}

function ops_answer(array $ops, $summary = 'Changed the memory')
{
    return json_encode(array('ops' => $ops, 'change_summary' => $summary));
}

/** The memory of the free-text kind, as the worker wrote it before items existed. */
function seed_legacy($w)
{
    return $w->storage->saveMemoryUpdate(5, 'global', array(
        'memory_text' => "### Person\n- Forest is 14 years old\n- Lives in Bern\n\n### Context\n- Plays in a football club\n\n### Goals\n- Wants to become a football player",
        'memory_object' => '{"person":{"name":"Forest","age":14}}',
        'flat_fields' => array(),
        'change_summary' => 'Earlier update',
    ), array(
        'rule_id' => 5, 'source_type' => 'form_action_submit', 'source_ref' => 'old', 'trigger_type' => 'finished',
        'payload_json' => array(), 'event_at' => '2026-01-01 00:00:00', 'dedupe_key' => 'old',
    ), 'record');
}

function current_row($w, $user_id = 5)
{
    $rows = $w->store->tableRows('llm_memory', $user_id);
    return $rows ? $rows[0] : null;
}

function history_rows($w)
{
    return $w->store->tableRows('llm_memory_history', 5);
}

function items_of($row)
{
    $decoded = json_decode($row['memory_json'], true);
    return is_array($decoded) && isset($decoded['items']) ? $decoded['items'] : null;
}

function find_item(array $items, $text)
{
    foreach ($items as $item) {
        if ($item['text'] === $text) {
            return $item;
        }
    }
    return null;
}

function texts(array $items)
{
    return array_map(function ($item) {
        return $item['section'] . ': ' . $item['text'];
    }, $items);
}

function system_prompt($w, $call = 0)
{
    return $w->llm->calls[$call]['messages'][0]['content'];
}

function user_prompt($w, $call = 0)
{
    return $w->llm->calls[$call]['messages'][1]['content'];
}

$messages = array('Merge the new data into the memory.');

/* =========================================================================
 * 1. A free-text memory is converted by the model's add operations
 * ========================================================================= */

echo "\n-- free-text memory is converted --\n";

$w = world();
seed_legacy($w);
$legacy_row = current_row($w);
$w->llm->responses[] = ops_answer(array(
    array('op' => 'add', 'section' => 'person', 'text' => 'Is 14 years old'),
    array('op' => 'add', 'section' => 'person', 'text' => 'Lives in Bern'),
    array('op' => 'add', 'section' => 'context', 'text' => 'Plays in a football club'),
    array('op' => 'add', 'section' => 'goals', 'text' => 'Wants to become a football player'),
    array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest'),
), 'Converted the memory and added the name');
$saves_before = count($w->store->saves);
$request_payload = payload(1, 'My name is Forest');
$ok = $w->worker->executeLlmSummarization(rule(), $request_payload);

check('conversion: the update succeeds', $ok === true);
check('conversion: the model is asked once', count($w->llm->calls) === 1);
check('conversion: the call is logged on the memory conversation', $w->llm->calls[0]['log_options']['conversation_id'] === 7);

$system = system_prompt($w);
$user = user_prompt($w);
check('conversion prompt: the system message names the memory key', strpos($system, 'memory key "global"') !== false);
check('conversion prompt: the output schema is the ops envelope with the section codes',
    strpos($system, '"required":["ops","change_summary"]') !== false
    && strpos($system, '"enum":["person","context","goals"]') !== false);
check('conversion prompt: the old envelope is gone from the system message',
    strpos($system, 'flat_fields') === false && strpos($system, 'memory_object') === false);
check('conversion prompt: the placeholders are filled', strpos($system, '{{') === false);
check('conversion prompt: the sections come with code, label and hint',
    strpos($user, '- `person` (Person): Current personal facts only, no history of changes') !== false
    && strpos($user, '- `context` (Context): Situations, observations, working hypotheses') !== false
    && strpos($user, '- `goals` (Goals): Things the user wants to achieve') !== false);
check('conversion prompt: the old text is sent as legacy memory to convert',
    strpos($user, "## Legacy Memory (to convert)\n### Person\n- Forest is 14 years old") !== false
    && strpos($user, '{"person":{"name":"Forest","age":14}}') !== false);
check('conversion prompt: Current Memory says it has no items yet', strpos($user, "## Current Memory\nThe memory has no items yet: it is still free text") !== false);
check('conversion prompt: the request is part of the submitted data', strpos($user, 'My name is Forest') !== false);
check('conversion prompt: the reminder demands the conversion', strpos($user, 'convert EVERY fact of the Legacy Memory') !== false && strpos($user, 'ops MUST NOT be empty') !== false);
check('conversion prompt: the reminder asks for ops, not for a rewritten memory',
    strpos($user, 'valid JSON with exactly these keys: ops, change_summary') !== false && strpos($user, 'memory_text') === false);
$positions = array();
foreach (array('## Scope', '## Sections', '## Current Memory', '## Legacy Memory (to convert)', '## Submitted Data', '## Instructions', '## Reminder') as $heading) {
    $positions[] = strpos($user, $heading);
}
$sorted = $positions;
sort($sorted);
check('conversion prompt: the sections come in the order Prompt Lab expects', !in_array(false, $positions, true) && $positions === $sorted);

$row = current_row($w);
$items = items_of($row);
check('conversion: the stored memory is items/v1', json_decode($row['memory_json'], true)['format'] === 'items/v1');
check('conversion: one item per add operation, in order', is_array($items) && texts($items) === array(
    'person: Is 14 years old', 'person: Lives in Bern', 'context: Plays in a football club',
    'goals: Wants to become a football player', 'person: Name: Forest'));
$ids = array_column($items, 'id');
check('conversion: every item has its own id', count(array_unique($ids)) === 5 && count(array_filter($ids, function ($id) {
    return preg_match('/^m_[0-9a-f]{6}$/', $id) === 1;
})) === 5);
check('conversion: the model is the source of the items', array_unique(array_column($items, 'source')) === array('llm'));
check('conversion: every item has a time', count(array_filter(array_column($items, 'updated_at'), function ($t) {
    return preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $t) === 1;
})) === 5);
check('conversion: memory_text is rendered from the items', $row['memory_text']
    === "### Person\n- Is 14 years old\n- Lives in Bern\n- Name: Forest\n\n### Context\n- Plays in a football club\n\n### Goals\n- Wants to become a football player");
check('conversion: the same row is updated, not a second one', count($w->store->tableRows('llm_memory', 5)) === 1 && $row['record_id'] === $legacy_row['record_id']);
check('conversion: the version is a timestamp', preg_match('/^\d{14}$/', $row['memory_version']) === 1);
check('conversion: rule, source and event are stored', $row['rule_id'] === '5' && $row['source_type'] === 'form_action_submit'
    && $row['source_ref'] === 'form-1' && $row['event_at'] === $request_payload['event_at']);

$history = history_rows($w);
check('conversion: one history row is appended', count($history) === 1);
check('conversion: the history row is applied and carries the change summary',
    $history[0]['update_status'] === 'applied' && $history[0]['change_summary'] === 'Converted the memory and added the name');
check('conversion: the history row holds the items too', items_of($history[0]) === $items && $history[0]['memory_text'] === $row['memory_text']);
check('conversion: both rows go through the storage service (save_data)', array_column(array_slice($w->store->saves, $saves_before), 'table') === array('llm_memory', 'llm_memory_history'));
check('conversion: the current row is updated by its record id', $w->store->saves[$saves_before]['update_based_on'] === array('record_id' => (int)$legacy_row['record_id']));
check('conversion: nothing reached the database that the test did not plan for', $w->db->unexpected === array());
check('conversion: no failure is recorded', $w->services->transaction->entries === array());

$prompt_lab = (new ReflectionClass('LlmDatasetIngestionService'))->newInstanceWithoutConstructor();
$extract = new ReflectionMethod($prompt_lab, 'extractMemoryVariablesFromUserMessage');
$extract->setAccessible(true);
$variables = $extract->invoke($prompt_lab, $user);
check('prompt lab: the memory key and rule are read from the captured message', ($variables['memory_key'] ?? '') === 'global' && ($variables['rule_label'] ?? '') === 'Forest profile');
check('prompt lab: the submitted data is read from the captured message', strpos($variables['event_payload_json'] ?? '', 'My name is Forest') !== false);
check('prompt lab: the Instructions slot can still be replaced', strpos($user, "\n## Instructions\n") !== false && strpos($user, "\n\n## Reminder") !== false);

/* =========================================================================
 * 2. An items memory: the operations change only the items they name
 * ========================================================================= */

echo "\n-- operations on an items memory --\n";

$before = $items;
$bern = find_item($before, 'Lives in Bern');
$football = find_item($before, 'Plays in a football club');
$w->llm->responses[] = ops_answer(array(
    array('op' => 'update', 'id' => $bern['id'], 'text' => 'Lives in Zurich'),
    array('op' => 'delete', 'id' => $football['id']),
    array('op' => 'add', 'section' => 'goals', 'text' => 'Wants to learn the guitar'),
), 'Moved to Zurich, quit football, new goal');
$ok = $w->worker->executeLlmSummarization(rule(), payload(2, 'I moved to Zurich, quit football and want to learn the guitar'));

check('ops: the update succeeds', $ok === true);
$user = user_prompt($w, 1);
check('ops prompt: the items are sent with their ids', strpos($user, "## Current Memory\n### Structured Data\n```json\n[") !== false
    && strpos($user, '{"id":"' . $bern['id'] . '","section":"person","text":"Lives in Bern"}') !== false);
check('ops prompt: every item is sent', count(array_filter($before, function ($item) use ($user) {
    return strpos($user, '"id":"' . $item['id'] . '"') !== false;
})) === 5);
check('ops prompt: the items carry nothing but id, section and text', strpos($user, 'updated_at') === false && strpos($user, '"source"') === false);
check('ops prompt: no legacy block, no conversion demand', strpos($user, 'Legacy Memory') === false && strpos($user, 'ops MUST NOT be empty') === false);
$variables = $extract->invoke($prompt_lab, $user);
check('prompt lab: the item list is read as the memory json', is_array(json_decode($variables['memory_json'] ?? '', true)) && count(json_decode($variables['memory_json'], true)) === 5);

$row = current_row($w);
$after = items_of($row);
check('ops: the items after the update', texts($after) === array(
    'person: Is 14 years old', 'person: Lives in Zurich', 'goals: Wants to become a football player',
    'person: Name: Forest', 'goals: Wants to learn the guitar'));
$updated = find_item($after, 'Lives in Zurich');
check('ops: an updated item keeps its id and its place', $updated['id'] === $bern['id'] && $after[1]['id'] === $bern['id']);
check('ops: an updated item is the model\'s', $updated['source'] === 'llm' && $updated['section'] === 'person');
check('ops: a deleted item is gone', find_item($after, 'Plays in a football club') === null);
$added = find_item($after, 'Wants to learn the guitar');
check('ops: an added item has a new id', $added !== null && !in_array($added['id'], $ids, true) && preg_match('/^m_[0-9a-f]{6}$/', $added['id']) === 1);
$untouched = array_values(array_filter($before, function ($item) use ($bern, $football) {
    return $item['id'] !== $bern['id'] && $item['id'] !== $football['id'];
}));
$untouched_after = array_values(array_filter($after, function ($item) use ($bern, $added) {
    return $item['id'] !== $bern['id'] && $item['id'] !== $added['id'];
}));
check('ops: every item no operation names is exactly as it was', $untouched === $untouched_after);
check('ops: memory_text is rendered again', $row['memory_text']
    === "### Person\n- Is 14 years old\n- Lives in Zurich\n- Name: Forest\n\n### Goals\n- Wants to become a football player\n- Wants to learn the guitar");
check('ops: a second history row, with the change summary', count(history_rows($w)) === 2 && history_rows($w)[0]['change_summary'] === 'Moved to Zurich, quit football, new goal');

/* =========================================================================
 * 3. Empty ops: nothing to change
 * ========================================================================= */

echo "\n-- empty ops --\n";

$before_row = current_row($w);
$w->llm->responses[] = ops_answer(array(), 'Nothing to change');
$ok = $w->worker->executeLlmSummarization(rule(), payload(3, 'Thanks'));
$row = current_row($w);
check('empty ops: valid, the update succeeds', $ok === true);
check('empty ops: the items and the text stay exactly as they are', $row['memory_json'] === $before_row['memory_json'] && $row['memory_text'] === $before_row['memory_text']);
check('empty ops: the update is still recorded in the history', count(history_rows($w)) === 3 && history_rows($w)[0]['change_summary'] === 'Nothing to change' && history_rows($w)[0]['update_status'] === 'applied');
check('empty ops: the model is asked once, no retry', count($w->llm->calls) === 3);

$w2 = world();
$w2->llm->responses[] = ops_answer(array(), 'Nothing to change');
$ok = $w2->worker->executeLlmSummarization(rule(), payload(1, 'Hello'));
$row = current_row($w2);
check('empty ops on a user without memory: an empty items memory is stored', $ok === true && items_of($row) === array() && $row['memory_text'] === '');
check('no memory: the prompt says there is none', strpos(user_prompt($w2), "## Current Memory\nNo existing memory for this user yet.") !== false
    && strpos(user_prompt($w2), 'Legacy Memory') === false);

/* =========================================================================
 * 4. Answers that are not valid
 * ========================================================================= */

echo "\n-- invalid answers --\n";

$w = world();
$w->llm->responses[] = ops_answer(array(
    array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest'),
    array('op' => 'add', 'section' => 'goals', 'text' => 'Wants to become a football player'),
));
$w->worker->executeLlmSummarization(rule(), payload(1, 'My name is Forest and I want to become a football player'));
$good_row = current_row($w);
$good_history = count(history_rows($w));

$invalid = array(
    'text that is not JSON' => 'I have updated the memory.',
    'the old envelope' => json_encode(array('memory_text' => 'x', 'memory_object' => new stdClass(), 'flat_fields' => new stdClass(), 'change_summary' => 'c')),
    'ops that is not a list' => json_encode(array('ops' => 'add', 'change_summary' => 'c')),
    'ops that is an object' => '{"ops": {"a": {"op": "delete", "id": "m_000000"}}, "change_summary": "c"}',
    'an unknown operation' => ops_answer(array(array('op' => 'explode', 'id' => 'm_000000'))),
    'an operation that is no object' => ops_answer(array('delete')),
    'an add without text' => ops_answer(array(array('op' => 'add', 'section' => 'person'))),
    'an add with a text that is no string' => ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => array('x')))),
    'an update without id' => ops_answer(array(array('op' => 'update', 'text' => 'x'))),
    'an update that changes nothing' => ops_answer(array(array('op' => 'update', 'id' => 'm_000000'))),
    'a delete without id' => ops_answer(array(array('op' => 'delete'))),
    'no change summary' => '{"ops": []}',
);
$n = 10;
foreach ($invalid as $label => $content) {
    $n++;
    $calls = count($w->llm->calls);
    $saves = count($w->store->saves);
    $failures = count($w->services->transaction->entries);
    $w->llm->responses = array($content, $content);
    $ok = $w->worker->executeLlmSummarization(rule(), payload($n, 'Change something'));
    check("invalid ($label): the update fails", $ok === false);
    check("invalid ($label): the model gets one more try", count($w->llm->calls) === $calls + 2);
    check("invalid ($label): nothing is saved", count($w->store->saves) === $saves && current_row($w) === $good_row && count(history_rows($w)) === $good_history);
    check("invalid ($label): the failure is recorded", count($w->services->transaction->entries) === $failures + 1
        && strpos((string)end($w->services->transaction->entries)[6], 'Failed to parse LLM output after retry') !== false);
}

$calls = count($w->llm->calls);
$w->llm->responses = array('not json at all', ops_answer(array(array('op' => 'add', 'section' => 'context', 'text' => 'Likes pizza'))));
$ok = $w->worker->executeLlmSummarization(rule(), payload(40, 'I like pizza'));
check('retry: a valid answer on the second try is applied', $ok === true && find_item(items_of(current_row($w)), 'Likes pizza') !== null);
check('retry: the second try gets more room to answer', count($w->llm->calls) === $calls + 2
    && $w->llm->calls[$calls + 1]['max_tokens'] === min($w->llm->calls[$calls]['max_tokens'] * 2, 8192));

$calls = count($w->llm->calls);
$saves = count($w->store->saves);
$w->llm->responses = array(false);
$ok = $w->worker->executeLlmSummarization(rule(), payload(41, 'Anything'));
check('no answer from the provider: the update fails and saves nothing', $ok === false && count($w->store->saves) === $saves && count($w->llm->calls) === $calls + 1);

echo "\n-- a free-text memory that is not converted --\n";

$w = world();
seed_legacy($w);
$legacy_row = current_row($w);
$saves = count($w->store->saves);

$w->llm->responses = array(ops_answer(array()), ops_answer(array()));
$ok = $w->worker->executeLlmSummarization(rule(), payload(1, 'Hello'));
check('legacy with empty ops: rejected, the model gets one more try', $ok === false && count($w->llm->calls) === 2);
check('legacy with empty ops: the free-text memory is untouched', current_row($w) === $legacy_row && count($w->store->saves) === $saves);

$w->llm->responses = array(ops_answer(array(array('op' => 'delete', 'id' => 'm_000000'))), ops_answer(array(array('op' => 'update', 'id' => 'm_000000', 'text' => 'x'))));
$ok = $w->worker->executeLlmSummarization(rule(), payload(2, 'Hello'));
check('legacy with operations but no add: rejected', $ok === false && current_row($w) === $legacy_row);

$calls = count($w->llm->calls);
$failures = count($w->services->transaction->entries);
$w->llm->responses = array(ops_answer(array(array('op' => 'add', 'section' => 'hobbies', 'text' => 'Plays football'), array('op' => 'add', 'section' => 'person', 'text' => ''))));
$ok = $w->worker->executeLlmSummarization(rule(), payload(3, 'Hello'));
check('legacy where no add can be applied: the update fails without a retry', $ok === false && count($w->llm->calls) === $calls + 1);
check('legacy where no add can be applied: the free-text memory is untouched', current_row($w) === $legacy_row && count($w->store->saves) === $saves && history_rows($w) === array());
check('legacy where no add can be applied: the failure says why', count($w->services->transaction->entries) === $failures + 1
    && strpos((string)end($w->services->transaction->entries)[6], 'was not converted') !== false);

$w->llm->responses = array(ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest'))));
$ok = $w->worker->executeLlmSummarization(rule(), payload(4, 'Hello'));
check('legacy: one applicable add is enough to convert', $ok === true && texts(items_of(current_row($w))) === array('person: Name: Forest'));

/* =========================================================================
 * 5. Operations that do not fit the memory are skipped, the others applied
 * ========================================================================= */

echo "\n-- skipped operations --\n";

$w = world();
$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest')));
$w->worker->executeLlmSummarization(rule(), payload(1, 'My name is Forest'));
$name = find_item(items_of(current_row($w)), 'Name: Forest');
clear_log();
$w->llm->responses[] = ops_answer(array(
    array('op' => 'add', 'section' => 'goals', 'text' => 'Secret goal text'),
    array('op' => 'update', 'id' => 'm_ffffff', 'text' => 'Secret update text'),
    array('op' => 'add', 'section' => 'hobbies', 'text' => 'Secret hobby text'),
    array('op' => 'delete', 'id' => 'm_eeeeee'),
    array('op' => 'add', 'section' => 'person', 'text' => '   '),
    array('op' => 'add', 'section' => 'person', 'text' => str_repeat('x', 501)),
));
$ok = $w->worker->executeLlmSummarization(rule(), payload(2, 'Something'));
check('skipped: the update succeeds', $ok === true);
check('skipped: only the operation that fits is applied', texts(items_of(current_row($w))) === array('person: Name: Forest', 'goals: Secret goal text'));
check('skipped: the item no operation names is untouched', items_of(current_row($w))[0] === $name);
$log = logged();
check('skipped: a warning names every reason', strpos($log, 'Memory operations skipped') !== false
    && strpos($log, 'unknown_id') !== false && strpos($log, 'unknown_section') !== false
    && strpos($log, 'empty_text') !== false && strpos($log, 'text_too_long') !== false);
check('skipped: the warning holds no memory text', strpos($log, 'Secret') === false && strpos($log, 'Forest') === false);

/* =========================================================================
 * 6. The memory changes while the model works
 * ========================================================================= */

echo "\n-- the memory changes while the model works --\n";

$w = world();
$w->llm->responses[] = ops_answer(array(
    array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest'),
    array('op' => 'add', 'section' => 'context', 'text' => 'Plays in a football club'),
    array('op' => 'add', 'section' => 'goals', 'text' => 'Wants to become a football player'),
));
$w->worker->executeLlmSummarization(rule(), payload(1, 'Start'));
$start = items_of(current_row($w));
$goal = find_item($start, 'Wants to become a football player');
$club = find_item($start, 'Plays in a football club');
$name = find_item($start, 'Name: Forest');

// the user edits the name and deletes the club in the editor while the model works
$w->llm->on_call = function ($number) use ($w, $start, $name, $club) {
    $edited = array();
    foreach ($start as $item) {
        if ($item['id'] === $club['id']) {
            continue;
        }
        if ($item['id'] === $name['id']) {
            $item['text'] = 'Name: Forest K.';
            $item['source'] = 'user';
        }
        $edited[] = $item;
    }
    $w->storage->saveMemoryUpdate(5, 'global', array(
        'memory_text' => LlmMemoryItems::renderText($edited, MemoryTestConfig::PCG_SECTIONS),
        'memory_object' => LlmMemoryItems::toMemoryObject($edited),
        'flat_fields' => array(),
        'change_summary' => 'Edited by the user',
    ), array(
        'rule_id' => 0, 'source_type' => 'user_edit', 'source_ref' => '', 'trigger_type' => 'finished',
        'payload_json' => array(), 'event_at' => date('Y-m-d H:i:s', strtotime('2026-11-26 10:00:00') + 5), 'dedupe_key' => 'edit',
    ), 'both');
};
$w->llm->responses[] = ops_answer(array(
    array('op' => 'update', 'id' => $goal['id'], 'text' => 'Wants to play in the national team'),
    array('op' => 'update', 'id' => $club['id'], 'text' => 'Plays in a football club in Bern'),
    array('op' => 'add', 'section' => 'context', 'text' => 'Trains twice a week'),
));
clear_log();
$ok = $w->worker->executeLlmSummarization(rule(), payload(10, 'I want to play in the national team and train twice a week'));
$after = items_of(current_row($w));
check('meanwhile: the update succeeds', $ok === true);
check('meanwhile: the edit of the user survives', find_item($after, 'Name: Forest K.') !== null && find_item($after, 'Name: Forest K.')['source'] === 'user' && find_item($after, 'Name: Forest') === null);
check('meanwhile: an item the user deleted is not brought back', find_item($after, 'Plays in a football club') === null && find_item($after, 'Plays in a football club in Bern') === null);
check('meanwhile: the operations that still fit are applied', texts($after) === array(
    'person: Name: Forest K.', 'goals: Wants to play in the national team', 'context: Trains twice a week'));
check('meanwhile: the operation on the deleted item is reported as skipped', strpos(logged(), 'unknown_id') !== false);
check('meanwhile: memory_text shows the edit and the update', current_row($w)['memory_text']
    === "### Person\n- Name: Forest K.\n\n### Context\n- Trains twice a week\n\n### Goals\n- Wants to play in the national team");

echo "\n-- a free-text memory converted by someone else meanwhile --\n";

$w = world();
seed_legacy($w);
$w->llm->on_call = function ($number) use ($w) {
    $items = array(array('id' => 'm_a1b2c3', 'section' => 'person', 'text' => 'Name: Forest', 'source' => 'llm', 'updated_at' => '2026-11-26 10:00:03'));
    $w->storage->saveMemoryUpdate(5, 'global', array(
        'memory_text' => LlmMemoryItems::renderText($items, MemoryTestConfig::PCG_SECTIONS),
        'memory_object' => LlmMemoryItems::toMemoryObject($items),
        'flat_fields' => array(),
        'change_summary' => 'Converted by another update',
    ), array(
        'rule_id' => 6, 'source_type' => 'form_action_submit', 'source_ref' => 'other', 'trigger_type' => 'finished',
        'payload_json' => array(), 'event_at' => '2026-11-26 10:00:04', 'dedupe_key' => 'other',
    ), 'record');
};
$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => 'Is 14 years old')));
$saves = count($w->store->saves);
$ok = $w->worker->executeLlmSummarization(rule(), payload(10, 'Hello'));
check('converted meanwhile: the conversion is dropped, not applied twice', $ok === false && texts(items_of(current_row($w))) === array('person: Name: Forest'));
check('converted meanwhile: nothing is saved by this update', count($w->store->saves) === $saves + 1);
check('converted meanwhile: the failure is recorded', strpos((string)end($w->services->transaction->entries)[6], 'changed while it was being converted') !== false);

/* =========================================================================
 * 7. Sections come from the key registry
 * ========================================================================= */

echo "\n-- sections of a key --\n";

$coach_config = '{"coach": {"sections": [{"code": "notes", "label": "Notes", "hint": "Free notes of the coach"}, {"code": "plan", "label": "My plan", "hint": "Next steps"}]}}';
$w = world($coach_config);
$w->llm->responses[] = ops_answer(array(
    array('op' => 'add', 'section' => 'notes', 'text' => 'Prefers short sessions'),
    array('op' => 'add', 'section' => 'person', 'text' => 'Not a section of this key'),
    array('op' => 'add', 'section' => 'plan', 'text' => 'Start with breathing exercises'),
));
$ok = $w->worker->executeLlmSummarization(rule(array('memory_keys' => array('coach'))), payload(1, 'Short sessions please', array('user_id' => 5)));
check('config: the update of a configured key succeeds', $ok === true);
$user = user_prompt($w);
check('config: the prompt lists the sections of the key', strpos($user, '- `notes` (Notes): Free notes of the coach') !== false
    && strpos($user, '- `plan` (My plan): Next steps') !== false && strpos($user, '`person`') === false);
check('config: the output schema allows the codes of the key only', strpos(system_prompt($w), '"enum":["notes","plan"]') !== false && strpos(system_prompt($w), '"person"') === false);
$coach = null;
foreach ($w->store->tableRows('llm_memory', 5) as $candidate) {
    if ($candidate['memory_key'] === 'coach') {
        $coach = $candidate;
    }
}
check('config: a section the key does not have is skipped', $coach !== null && texts(items_of($coach)) === array('notes: Prefers short sessions', 'plan: Start with breathing exercises'));
check('config: memory_text uses the labels of the config', $coach['memory_text'] === "### Notes\n- Prefers short sessions\n\n### My plan\n- Start with breathing exercises");

$w->llm->responses[] = ops_answer(array(
    array('op' => 'add', 'text' => 'Name: Forest'),
    array('op' => 'add', 'section' => 'person', 'text' => 'Is 14 years old'),
));
$ok = $w->worker->executeLlmSummarization(rule(), payload(2, 'My name is Forest, I am 14'));
$flat_user = user_prompt($w, 1);
$flat_system = system_prompt($w, 1);
check('no sections: a key the registry gives no sections is updated as one list', $ok === true
    && texts(items_of(current_row($w))) === array(': Name: Forest', ': Is 14 years old'));
check('no sections: the prompt says there are none, lists no section codes', strpos($flat_user, 'This memory has no sections') !== false && strpos($flat_user, '`person`') === false && strpos($flat_user, '`notes`') === false);
check('no sections: the output schema has no section field', strpos($flat_system, '"required":["op","text"]') !== false && strpos($flat_system, '"section":{') === false && strpos($flat_system, '"enum"') === false);
check('no sections: memory_text is a plain list without headings', current_row($w)['memory_text'] === "- Name: Forest\n- Is 14 years old");

$w = world(array('global' => '{oops'));
$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'goals', 'text' => 'Wants to learn the guitar')));
$ok = $w->worker->executeLlmSummarization(rule(), payload(1, 'I want to learn the guitar'));
check('config: stored sections that cannot be read are never fatal: the key is one list', $ok === true && texts(items_of(current_row($w))) === array(': Wants to learn the guitar'));

/* =========================================================================
 * 8. What did not change
 * ========================================================================= */

echo "\n-- unchanged behaviour --\n";

$w = world();
$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest')));
$first = payload(1, 'My name is Forest');
$w->worker->executeLlmSummarization(rule(), $first);
$calls = count($w->llm->calls);
$ok = $w->worker->executeLlmSummarization(rule(), $first);
check('duplicate event: ignored without asking the model', $ok === true && count($w->llm->calls) === $calls);
check('duplicate event: recorded in the history', history_rows($w)[0]['update_status'] === 'ignored_duplicate');
$ok = $w->worker->executeLlmSummarization(rule(), payload(2, 'Older event', array('event_at' => '2020-01-01 00:00:00')));
check('stale event: ignored without asking the model', $ok === true && count($w->llm->calls) === $calls && history_rows($w)[0]['update_status'] === 'ignored_stale');

$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'goals', 'text' => 'Wants to learn the guitar')));
$w->worker->executeLlmSummarization(rule(), payload(3, 'Guitar', array('user_language' => 'Deutsch (Schweiz)')));
$system = system_prompt($w, $calls);
check('language: the suffix names the language of the item texts', strpos($system, 'Deutsch (Schweiz)') !== false && strpos($system, '{{user_language}}') === false);
check('language: keys, operation names, ids and section codes stay as given', strpos($system, 'Keep the JSON keys, operation names ("add", "update", "delete"), ids and section codes exactly as given') !== false);
$w->llm->responses[] = ops_answer(array());
$w->worker->executeLlmSummarization(rule(), payload(4, 'Plain'));
check('language: without a user language there is no suffix', strpos(system_prompt($w, $calls + 1), '## Language') === false);

$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'context', 'text' => '<b>Bold</b> & "quoted"')));
$w->worker->executeLlmSummarization(rule(), payload(5, 'Markup'));
$row = current_row($w);
check('markup: the item keeps the text as it came', find_item(items_of($row), '<b>Bold</b> & "quoted"') !== null);
check('markup: memory_text is rendered without live markup', strpos($row['memory_text'], '&lt;b&gt;Bold&lt;/b&gt; & "quoted"') !== false && strpos($row['memory_text'], '<b>') === false);

$w->llm->responses[] = ops_answer(array());
$w->worker->executeLlmSummarization(rule(), payload(6, 'Deleted entry', array('trigger_type' => 'deleted')));
check('deleted entry: the reminder keeps the exception for the deleted entry', strpos(user_prompt($w, count($w->llm->calls) - 1), 'items that came only from the deleted entry may be deleted') !== false);

$w = world();
$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest')));
$saves = count($w->store->saves);
$ok = $w->worker->executeLlmSummarization(rule(array('storage_mode_override' => 'record')), payload(1, 'My name is Forest'));
check('storage mode record: only the current row is written', $ok === true && array_column(array_slice($w->store->saves, $saves), 'table') === array('llm_memory') && history_rows($w) === array());

$w = world();
$w->llm->responses[] = ops_answer(array(array('op' => 'add', 'section' => 'person', 'text' => 'Name: Forest')));
$ok = $w->worker->executeLlmSummarization(rule(array('refresh_sections' => array(12, 13))), payload(1, 'My name is Forest'));
$refresh = array_values(array_filter($w->db->inserts, function ($insert) {
    return $insert[0] === 'refresh_events';
}));
$refresh_sections = array_values(array_filter($w->db->inserts, function ($insert) {
    return $insert[0] === 'refresh_events_sections';
}));
check('refresh: a successful update notifies the sections of the rule', $ok === true && count($refresh) === 1
    && $refresh[0][1]['id_users'] === 5 && $refresh[0][1]['event_type'] === 'llm_memory_updated'
    && array_column(array_column($refresh_sections, 1), 'id_sections') === array(12, 13));
$w->db->inserts = array();
$w->llm->responses = array('not json', 'still not json');
$ok = $w->worker->executeLlmSummarization(rule(array('refresh_sections' => array(12, 13))), payload(2, 'Again'));
check('refresh: a failed update notifies nobody', $ok === false && $w->db->inserts === array());

check('no run reached the database with a statement the test did not plan for', $w->db->unexpected === array());

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
