<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmMemoryItems (plugin v1.10.0, structured memory):
 * reading a stored memory, applying add / update / delete operations and
 * rendering `memory_text` from the items.
 *
 * No bootstrap, no database: LlmMemoryItems is pure, the real class runs as is.
 * Only randomness is injected (new item ids).
 *
 * Run: php tests/LlmMemoryItemsTest.php
 */

require_once dirname(__DIR__) . '/server/service/LlmMemoryItems.php';

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

/** A repeatable source of "random" hex for new ids. */
function sequence(array $values)
{
    return function () use (&$values) {
        return array_shift($values);
    };
}

$sections = array(
    array('code' => 'person', 'label' => 'Person'),
    array('code' => 'context', 'label' => 'Context'),
    array('code' => 'goals', 'label' => 'Goals'),
);

$items = array(
    array('id' => 'm_aaaaaa', 'section' => 'person', 'text' => 'Name: Alex', 'source' => 'llm', 'updated_at' => '2026-11-01 10:00:00'),
    array('id' => 'm_bbbbbb', 'section' => 'context', 'text' => 'Works night shifts', 'source' => 'llm', 'updated_at' => '2026-11-02 10:00:00'),
    array('id' => 'm_cccccc', 'section' => 'goals', 'text' => 'Sleep better', 'source' => 'user', 'updated_at' => '2026-11-03 10:00:00'),
    array('id' => 'm_dddddd', 'section' => 'person', 'text' => 'Lives in Bern', 'source' => 'llm', 'updated_at' => '2026-11-04 10:00:00'),
);

/* ------------------------------------------------------------------ format */

$stored = json_encode(LlmMemoryItems::toMemoryObject($items));
check('toMemoryObject writes the format marker', json_decode($stored, true)['format'] === 'items/v1');
check('isItemsFormat accepts items/v1', LlmMemoryItems::isItemsFormat(json_decode($stored, true)));
check('isItemsFormat accepts an empty item list', LlmMemoryItems::isItemsFormat(array('format' => 'items/v1', 'items' => array())));
check('isItemsFormat rejects another format', !LlmMemoryItems::isItemsFormat(array('format' => 'items/v2', 'items' => array())));
check('isItemsFormat rejects a free-form object', !LlmMemoryItems::isItemsFormat(array('summary' => 'x')));
check('isItemsFormat rejects items that are no list', !LlmMemoryItems::isItemsFormat(array('format' => 'items/v1', 'items' => 'x')));
check('isItemsFormat rejects null / strings', !LlmMemoryItems::isItemsFormat(null) && !LlmMemoryItems::isItemsFormat('items/v1'));

/* ----------------------------------------------------------------- fromRow */

$row = LlmMemoryItems::fromRow(array('memory_json' => $stored, 'memory_text' => 'rendered'));
check('fromRow: items memory is kind items', $row['kind'] === 'items');
check('fromRow: items come back exactly as stored', json_encode($row['items']) === json_encode($items));
check('fromRow: round trip through memory_json is byte identical', json_encode(LlmMemoryItems::toMemoryObject($row['items'])) === $stored);

$broken = LlmMemoryItems::fromRow(array('memory_json' => json_encode(array('format' => 'items/v1', 'items' => array(
    $items[0],
    'garbage',
    array('id' => '', 'section' => 'person', 'text' => 'no id'),
    array('id' => 'm_x', 'section' => 'person'),
    array('id' => 'm_y', 'section' => 'person', 'text' => array('not a string')),
)))));
check('fromRow: entries that are no items are left out', $broken['kind'] === 'items' && count($broken['items']) === 1 && $broken['items'][0]['id'] === 'm_aaaaaa');

$empty_items = LlmMemoryItems::fromRow(array('memory_json' => '{"format":"items/v1","items":[]}', 'memory_text' => ''));
check('fromRow: an emptied items memory stays an items memory', $empty_items['kind'] === 'items' && $empty_items['items'] === array());

$legacy = LlmMemoryItems::fromRow(array('memory_json' => '{"summary":"old"}', 'memory_text' => "  - likes tea\n"));
check('fromRow: free-text memory is legacy', $legacy['kind'] === 'legacy');
check('fromRow: legacy keeps the trimmed text and the old json', $legacy['legacy_text'] === '- likes tea' && $legacy['legacy_json'] === '{"summary":"old"}');
check('fromRow: legacy with text only', LlmMemoryItems::fromRow(array('memory_text' => 'Just text', 'memory_json' => ''))['kind'] === 'legacy');
check('fromRow: legacy with json only', LlmMemoryItems::fromRow(array('memory_json' => '{"a":1}', 'memory_text' => ''))['kind'] === 'legacy');
check('fromRow: format marker without a list is legacy, not items',
    LlmMemoryItems::fromRow(array('memory_json' => '{"format":"items/v1","items":"x"}', 'memory_text' => 'T'))['kind'] === 'legacy');
check('fromRow: invalid json with text is legacy', LlmMemoryItems::fromRow(array('memory_json' => '{oops', 'memory_text' => 'T'))['kind'] === 'legacy');
check('fromRow: no row is none', LlmMemoryItems::fromRow(null)['kind'] === 'none' && LlmMemoryItems::fromRow(array())['kind'] === 'none');
check('fromRow: blank text and json is none', LlmMemoryItems::fromRow(array('memory_json' => '', 'memory_text' => " \n "))['kind'] === 'none');
check('fromRow: an empty json object is none', LlmMemoryItems::fromRow(array('memory_json' => '{}', 'memory_text' => ''))['kind'] === 'none');

/* ---------------------------------------------------------------------- add */

$result = LlmMemoryItems::applyOps($items, array(
    array('op' => 'add', 'section' => 'goals', 'text' => 'Run a 10k'),
), $sections, 'llm', sequence(array('123abc')));
check('add: one item more', count($result['items']) === 5 && $result['applied'] === 1 && $result['skipped'] === array());
$added = $result['items'][4];
check('add: new id is m_ + random hex', $added['id'] === 'm_123abc');
check('add: section, text and source are set', $added['section'] === 'goals' && $added['text'] === 'Run a 10k' && $added['source'] === 'llm');
check('add: updated_at is a timestamp', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $added['updated_at']) === 1);
check('add: existing items are untouched (byte identical)', json_encode(array_slice($result['items'], 0, 4)) === json_encode($items));

$user_add = LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => 'x')), $sections, 'user');
check('add: source user is kept', $user_add['items'][0]['source'] === 'user');
check('add: an unknown source falls back to llm', LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => 'x')), $sections, 'whatever')['items'][0]['source'] === 'llm');
check('add: default random ids look like m_ + 6 hex characters', preg_match('/^m_[0-9a-f]{6}$/', $user_add['items'][0]['id']) === 1);

$unknown_section = LlmMemoryItems::applyOps($items, array(array('op' => 'add', 'section' => 'hobbies', 'text' => 'Chess')), $sections, 'llm');
check('add: unknown section is rejected', count($unknown_section['items']) === 4
    && $unknown_section['skipped'] === array(array('index' => 0, 'op' => 'add', 'reason' => 'unknown_section')));
check('add: missing section is rejected', LlmMemoryItems::applyOps($items, array(array('op' => 'add', 'text' => 'x')), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_section');
check('add: a section that is no string is rejected', LlmMemoryItems::applyOps($items, array(array('op' => 'add', 'section' => array('person'), 'text' => 'x')), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_section');

foreach (array('empty' => '', 'blank' => "  \n\t ", 'null' => null, 'array' => array('x')) as $label => $text) {
    $rejected = LlmMemoryItems::applyOps($items, array(array('op' => 'add', 'section' => 'person', 'text' => $text)), $sections, 'llm');
    check("add: $label text is rejected", count($rejected['items']) === 4 && $rejected['skipped'][0]['reason'] === 'empty_text');
}
check('add: a missing text is rejected', LlmMemoryItems::applyOps($items, array(array('op' => 'add', 'section' => 'person')), $sections, 'llm')['skipped'][0]['reason'] === 'empty_text');

$limit_ok = LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => str_repeat('a', 500))), $sections, 'llm');
check('add: a text of 500 characters is accepted', count($limit_ok['items']) === 1 && $limit_ok['skipped'] === array());
$limit_over = LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => str_repeat('a', 501))), $sections, 'llm');
check('add: a text of 501 characters is rejected', $limit_over['items'] === array() && $limit_over['skipped'][0]['reason'] === 'text_too_long');
check('add: the length counts characters, not bytes (500 x ä is accepted)',
    count(LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => str_repeat('ä', 500))), $sections, 'llm')['items']) === 1);
check('add: 501 x ä is rejected', LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => str_repeat('ä', 501))), $sections, 'llm')['skipped'][0]['reason'] === 'text_too_long');
check('add: the limit applies after whitespace is collapsed',
    count(LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => '  ' . str_repeat('a', 500) . "\n\n  ")), $sections, 'llm')['items']) === 1);

$multiline = LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => "  Likes\n tea \t and\r\ncoffee ")), $sections, 'llm');
check('add: a fact is one line, whitespace collapsed', $multiline['items'][0]['text'] === 'Likes tea and coffee');
check('add: control characters are removed', LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => "a\x00b\x07c")), $sections, 'llm')['items'][0]['text'] === 'a b c');
check('add: invalid UTF-8 is rejected', LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => "bad \xC3\x28")), $sections, 'llm')['skipped'][0]['reason'] === 'empty_text');

/* --------------------------------------------------------------- unique ids */

$twice = LlmMemoryItems::applyOps(array(), array(
    array('op' => 'add', 'section' => 'person', 'text' => 'one'),
    array('op' => 'add', 'section' => 'person', 'text' => 'two'),
), $sections, 'llm', sequence(array('aaaaaa', 'aaaaaa', 'bbbbbb')));
check('ids: a random id that is already taken is not used again', array_column($twice['items'], 'id') === array('m_aaaaaa', 'm_bbbbbb'));
check('ids: an id of an existing item is not reused', LlmMemoryItems::generateId(array('m_aaaaaa', 'm_bbbbbb'), sequence(array('aaaaaa', 'bbbbbb', 'cccccc'))) === 'm_cccccc');
$exhausted = false;
try {
    LlmMemoryItems::generateId(array('m_same'), function () {
        return 'same';
    });
} catch (RuntimeException $e) {
    $exhausted = true;
}
check('ids: a broken random source ends in an error, not in a duplicate', $exhausted);
$many = array();
for ($i = 0; $i < 200; $i++) {
    $many[] = LlmMemoryItems::generateId($many);
}
check('ids: 200 default ids are unique', count(array_unique($many)) === 200);

/* ------------------------------------------------------------------- update */

$result = LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_bbbbbb', 'text' => 'Works day shifts now')), $sections, 'llm');
check('update: applied', $result['applied'] === 1 && $result['skipped'] === array() && count($result['items']) === 4);
check('update: the text of that id changed', $result['items'][1]['text'] === 'Works day shifts now' && $result['items'][1]['id'] === 'm_bbbbbb');
check('update: its section is kept and updated_at is refreshed', $result['items'][1]['section'] === 'context' && $result['items'][1]['updated_at'] !== '2026-11-02 10:00:00');
check('update: all other items are byte identical', json_encode(array($result['items'][0], $result['items'][2], $result['items'][3])) === json_encode(array($items[0], $items[2], $items[3])));
check('update: the position in the list is kept', array_column($result['items'], 'id') === array('m_aaaaaa', 'm_bbbbbb', 'm_cccccc', 'm_dddddd'));

$by_user = LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_aaaaaa', 'text' => 'Name: Alexander')), $sections, 'user');
check('update: the source is the last writer', $by_user['items'][0]['source'] === 'user');
$by_llm = LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_cccccc', 'text' => 'Sleep eight hours')), $sections, 'llm');
check('update: an item of the user, changed by the model, is source llm', $by_llm['items'][2]['source'] === 'llm');

$moved = LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_bbbbbb', 'text' => 'Works night shifts', 'section' => 'person')), $sections, 'llm');
check('update: the section can change', $moved['items'][1]['section'] === 'person' && $moved['applied'] === 1);
check('update: section only, no text', LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_bbbbbb', 'section' => 'goals')), $sections, 'llm')['items'][1]['section'] === 'goals');
check('update: an unknown new section is rejected', LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_bbbbbb', 'text' => 'new', 'section' => 'nope')), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_section');

$ghost = LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_zzzzzz', 'text' => 'new')), $sections, 'llm');
check('update: an unknown id is skipped and nothing changes', json_encode($ghost['items']) === json_encode($items)
    && $ghost['skipped'] === array(array('index' => 0, 'op' => 'update', 'reason' => 'unknown_id')) && $ghost['applied'] === 0);
check('update: a missing id is skipped', LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'text' => 'x')), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_id');
check('update: an empty text is rejected, the item is kept', LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_aaaaaa', 'text' => '  ')), $sections, 'llm')['skipped'][0]['reason'] === 'empty_text');
check('update: a text that is too long is rejected', LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_aaaaaa', 'text' => str_repeat('x', 501))), $sections, 'llm')['skipped'][0]['reason'] === 'text_too_long');
check('update: an update that changes nothing is skipped (updated_at stays)', json_encode(LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_aaaaaa', 'text' => ' Name:   Alex ')), $sections, 'llm')['items']) === json_encode($items));
check('update: an operation without text or section changes nothing', LlmMemoryItems::applyOps($items, array(array('op' => 'update', 'id' => 'm_aaaaaa')), $sections, 'llm')['skipped'][0]['reason'] === 'unchanged');

$orphans = array(array('id' => 'm_oooooo', 'section' => 'removed_section', 'text' => 'old fact', 'source' => 'llm', 'updated_at' => '2026-11-01 10:00:00'));
check('update: the text of an item whose section is no longer configured can change',
    LlmMemoryItems::applyOps($orphans, array(array('op' => 'update', 'id' => 'm_oooooo', 'text' => 'new fact')), $sections, 'llm')['applied'] === 1);
check('update: ... and it can stay in that section',
    LlmMemoryItems::applyOps($orphans, array(array('op' => 'update', 'id' => 'm_oooooo', 'text' => 'new fact', 'section' => 'removed_section')), $sections, 'llm')['items'][0]['section'] === 'removed_section');

/* ------------------------------------------------------------------- delete */

$result = LlmMemoryItems::applyOps($items, array(array('op' => 'delete', 'id' => 'm_bbbbbb')), $sections, 'llm');
check('delete: that item is gone', $result['applied'] === 1 && count($result['items']) === 3 && !in_array('m_bbbbbb', array_column($result['items'], 'id'), true));
check('delete: all other items are byte identical and in order', json_encode($result['items']) === json_encode(array($items[0], $items[2], $items[3])));
check('delete: the list stays a list', array_keys($result['items']) === array(0, 1, 2));

$ghost = LlmMemoryItems::applyOps($items, array(array('op' => 'delete', 'id' => 'm_zzzzzz')), $sections, 'llm');
check('delete: an unknown id is skipped and nothing changes', json_encode($ghost['items']) === json_encode($items) && $ghost['skipped'][0]['reason'] === 'unknown_id');
check('delete: a missing id is skipped', LlmMemoryItems::applyOps($items, array(array('op' => 'delete')), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_id');
check('delete: an id that is no string is skipped', LlmMemoryItems::applyOps($items, array(array('op' => 'delete', 'id' => array('m_aaaaaa'))), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_id');
$twice = LlmMemoryItems::applyOps($items, array(array('op' => 'delete', 'id' => 'm_aaaaaa'), array('op' => 'delete', 'id' => 'm_aaaaaa')), $sections, 'llm');
check('delete: the same id twice deletes once, the second is skipped', count($twice['items']) === 3 && $twice['applied'] === 1 && $twice['skipped'][0]['index'] === 1);
check('delete: everything can be deleted', LlmMemoryItems::applyOps($items, array_map(function ($i) {
    return array('op' => 'delete', 'id' => $i['id']);
}, $items), $sections, 'llm')['items'] === array());

/* -------------------------------------------------------------- mixed / odd */

$mixed = LlmMemoryItems::applyOps($items, array(
    array('op' => 'delete', 'id' => 'm_zzzzzz'),
    array('op' => 'update', 'id' => 'm_cccccc', 'text' => 'Sleep seven hours'),
    array('op' => 'add', 'section' => 'nope', 'text' => 'x'),
    array('op' => 'add', 'section' => 'person', 'text' => 'Has a dog'),
    array('op' => 'delete', 'id' => 'm_aaaaaa'),
), $sections, 'llm', sequence(array('0a0a0a')));
check('mixed: the valid operations apply, the others are skipped', $mixed['applied'] === 3 && count($mixed['skipped']) === 2);
check('mixed: skipped carry the position and reason, never the text', $mixed['skipped'] === array(
    array('index' => 0, 'op' => 'delete', 'reason' => 'unknown_id'),
    array('index' => 2, 'op' => 'add', 'reason' => 'unknown_section'),
));
check('mixed: result is the expected memory', array_column($mixed['items'], 'text') === array('Works night shifts', 'Sleep seven hours', 'Lives in Bern', 'Has a dog'));

$odd = LlmMemoryItems::applyOps($items, array('delete everything', null, 5, array(), array('op' => 'wipe'), array('op' => array('add')), array('id' => 'm_aaaaaa')), $sections, 'llm');
check('odd: malformed operations are skipped, never applied', json_encode($odd['items']) === json_encode($items) && count($odd['skipped']) === 7 && $odd['applied'] === 0);
check('odd: the reason tells invalid from unknown', $odd['skipped'][0]['reason'] === 'invalid_op' && $odd['skipped'][4]['reason'] === 'unknown_op');
check('no operations: the items come back as they are', json_encode(LlmMemoryItems::applyOps($items, array(), $sections, 'llm')['items']) === json_encode($items));
check('operations given as an object keyed list still run in order', LlmMemoryItems::applyOps($items, array('x' => array('op' => 'delete', 'id' => 'm_aaaaaa'), 'y' => array('op' => 'delete', 'id' => 'm_bbbbbb')), $sections, 'llm')['applied'] === 2);

$sections_without_codes = LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => 'person', 'text' => 'x')), array('junk', array('label' => 'no code')), 'llm');
check('sections without a code are ignored', $sections_without_codes['items'] === array() && $sections_without_codes['skipped'][0]['reason'] === 'unknown_section');

/* --------------------------------------------------------------- renderText */

$expected = "### Person\n- Name: Alex\n- Lives in Bern\n\n### Context\n- Works night shifts\n\n### Goals\n- Sleep better";
check('renderText: markdown, sections in config order, items in list order', LlmMemoryItems::renderText($items, $sections) === $expected);
check('renderText: the order of the config wins over the order of the items', LlmMemoryItems::renderText($items, array_reverse($sections))
    === "### Goals\n- Sleep better\n\n### Context\n- Works night shifts\n\n### Person\n- Name: Alex\n- Lives in Bern");
check('renderText: empty sections are skipped', LlmMemoryItems::renderText(array($items[2]), $sections) === "### Goals\n- Sleep better");
check('renderText: no items is an empty text', LlmMemoryItems::renderText(array(), $sections) === '');
check('renderText: a section without label shows its code', LlmMemoryItems::renderText(array($items[0]), array(array('code' => 'person'))) === "### person\n- Name: Alex");
check('renderText: items of a section the key no longer defines follow, headed by the code',
    LlmMemoryItems::renderText(array_merge($items, $orphans), $sections) === $expected . "\n\n### removed_section\n- old fact");
check('renderText: HTML in a text is written as entities', LlmMemoryItems::renderText(array(
    array('id' => 'm_1', 'section' => 'person', 'text' => 'Likes <b>bold</b> & <script>alert(1)</script>'),
), $sections) === "### Person\n- Likes &lt;b&gt;bold&lt;/b&gt; & &lt;script&gt;alert(1)&lt;/script&gt;");
check('renderText: a text with line breaks stays one list line', LlmMemoryItems::renderText(array(
    array('id' => 'm_1', 'section' => 'person', 'text' => "line one\n- line two\n\n### fake heading"),
), $sections) === "### Person\n- line one - line two ### fake heading");
/* ------------------------------------------------- a key without sections */

$flat = LlmMemoryItems::applyOps(array(), array(
    array('op' => 'add', 'text' => 'Likes tea'),
    array('op' => 'add', 'section' => '', 'text' => 'Plays chess'),
    array('op' => 'add', 'section' => 'person', 'text' => 'Not a section of this key'),
), array(), 'llm', function () {
    static $n = 0;
    return sprintf('%06d', ++$n);
});
check('no sections: add without a section (missing or "") is stored with section ""', $flat['applied'] === 2
    && array_column($flat['items'], 'section') === array('', '') && array_column($flat['items'], 'text') === array('Likes tea', 'Plays chess'));
check('no sections: a section the key does not have is rejected', $flat['skipped'] === array(array('index' => 2, 'op' => 'add', 'reason' => 'unknown_section')));
check('no sections: update keeps the item without a section', LlmMemoryItems::applyOps($flat['items'], array(array('op' => 'update', 'id' => 'm_000001', 'text' => 'Likes green tea')), array(), 'user')['items'][0]['section'] === '');
check('no sections: memory_text is a plain list', LlmMemoryItems::renderText($flat['items'], array()) === "- Likes tea\n- Plays chess");
check('a key with sections does not take items without one', LlmMemoryItems::applyOps(array(), array(array('op' => 'add', 'section' => '', 'text' => 'x')), $sections, 'llm')['skipped'][0]['reason'] === 'unknown_section');
check('renderText: items without a section come first, without a heading (added before the key got sections)',
    LlmMemoryItems::renderText(array_merge(array(array('id' => 'm_0', 'section' => '', 'text' => 'Loose fact')), $items), $sections) === "- Loose fact\n\n" . $expected);

check('renderText: the items are not changed by rendering', json_encode($items) === json_encode(LlmMemoryItems::fromRow(array('memory_json' => $stored))['items']));

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
