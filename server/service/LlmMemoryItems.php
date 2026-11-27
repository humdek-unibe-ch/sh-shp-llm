<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Structured memory: a memory is a list of short facts (items), optionally grouped
 * into the sections of its memory key (doc/structured-memory-design.md). A key
 * without sections stores its items with section '' (one flat list).
 *
 * Stored in the `memory_json` column of the memory row, next to the rendered
 * `memory_text`:
 *
 *   {"format": "items/v1", "items": [
 *       {"id": "m_8f3a2c", "section": "person", "text": "...", "source": "llm", "updated_at": "2026-11-26 10:12:04"}
 *   ]}
 *
 * Pure functions, no database and no services: the memory worker (LLM ops)
 * and the llmMemoryEditor style (user edits) both change a memory only through
 * applyOps(), and both write `memory_text` with renderText().
 *
 * @package LLM Plugin
 */
class LlmMemoryItems
{
    /** Value of `format` in memory_json. Readers must check it before assuming items. */
    const FORMAT = 'items/v1';

    /** Longest item text, in characters. */
    const MAX_TEXT_LENGTH = 500;

    /** Item `source`: the last writer was the memory worker / the user in the editor. */
    const SOURCE_LLM = 'llm';
    const SOURCE_USER = 'user';

    /**
     * Is the decoded memory_json an items memory?
     *
     * @param mixed $decoded json_decode($memory_json, true)
     * @return bool
     */
    public static function isItemsFormat($decoded)
    {
        return is_array($decoded)
            && isset($decoded['format'], $decoded['items'])
            && $decoded['format'] === self::FORMAT
            && is_array($decoded['items']);
    }

    /**
     * Read a stored memory row (as returned by the memory storage service).
     *
     * - `items`:  memory_json holds items/v1 (the list can be empty);
     * - `legacy`: an older free-text memory (memory_text and/or other JSON),
     *             to be converted by the memory worker on its next update;
     * - `none`:   no row, or an empty one.
     *
     * Items are returned as stored; an entry without id, section and text
     * (never written by this class) is left out.
     *
     * @param array|null $row Memory row with memory_json / memory_text
     * @return array{kind:string,items:array,legacy_text:string,legacy_json:string}
     */
    public static function fromRow($row)
    {
        $result = array('kind' => 'none', 'items' => array(), 'legacy_text' => '', 'legacy_json' => '');
        if (!is_array($row) || empty($row)) {
            return $result;
        }

        $json = isset($row['memory_json']) && is_string($row['memory_json']) ? trim($row['memory_json']) : '';
        $decoded = $json !== '' ? json_decode($json, true) : null;
        if (self::isItemsFormat($decoded)) {
            $result['kind'] = 'items';
            foreach ($decoded['items'] as $item) {
                if (is_array($item)
                    && isset($item['id'], $item['section'], $item['text'])
                    && is_string($item['id']) && $item['id'] !== ''
                    && is_string($item['section']) && is_string($item['text'])) {
                    $result['items'][] = $item;
                }
            }
            return $result;
        }

        $text = isset($row['memory_text']) && is_string($row['memory_text']) ? trim($row['memory_text']) : '';
        $has_json = is_array($decoded) && !empty($decoded);
        if ($text !== '' || $has_json) {
            $result['kind'] = 'legacy';
            $result['legacy_text'] = $text;
            $result['legacy_json'] = $has_json ? $json : '';
        }
        return $result;
    }

    /**
     * The memory_json value for a list of items.
     *
     * @param array $items
     * @return array
     */
    public static function toMemoryObject(array $items)
    {
        return array('format' => self::FORMAT, 'items' => array_values($items));
    }

    /**
     * Apply operations to a list of items.
     *
     * - add:    new item with a new id; needs a text and a known section (a key without
     *           sections: no section or '', stored as '')
     * - update: changes `text` (and optionally `section`) of that id only
     * - delete: removes that id only
     *
     * An operation that cannot be applied is skipped, the others still run:
     * unknown id, unknown section, empty or too long text, unknown or
     * malformed operation, or an update that changes nothing. Items no
     * operation names are returned exactly as they came in, so a memory
     * changed meanwhile (an id that is gone) is never overwritten.
     *
     * @param array         $items      Current items
     * @param array         $ops        Operations: ['op' => 'add', 'section' => ..., 'text' => ...],
     *                                  ['op' => 'update', 'id' => ..., 'text' => ...], ['op' => 'delete', 'id' => ...]
     * @param array         $sections   Section definitions of the memory key (each with a `code`); [] = no sections
     * @param string        $source     Who writes: SOURCE_LLM or SOURCE_USER
     * @param callable|null $random_hex Returns random hex for new ids (default: random_bytes), tests inject it
     * @return array{items:array,skipped:array,applied:int} skipped: list of ['index', 'op', 'reason'], never the text
     */
    public static function applyOps(array $items, array $ops, array $sections, $source, $random_hex = null)
    {
        $source = $source === self::SOURCE_USER ? self::SOURCE_USER : self::SOURCE_LLM;
        $codes = array();
        foreach ($sections as $section) {
            if (is_array($section) && isset($section['code']) && is_string($section['code'])) {
                $codes[] = $section['code'];
            }
        }

        $items = array_values($items);
        $skipped = array();
        $applied = 0;
        $now = date('Y-m-d H:i:s');

        foreach (array_values($ops) as $index => $op) {
            $name = is_array($op) && isset($op['op']) && is_string($op['op']) ? $op['op'] : '';
            $reason = '';

            if (!is_array($op)) {
                $reason = 'invalid_op';
            } elseif ($name === 'add') {
                $reason = self::applyAdd($items, $op, $codes, $source, $now, $random_hex);
            } elseif ($name === 'update') {
                $reason = self::applyUpdate($items, $op, $codes, $source, $now);
            } elseif ($name === 'delete') {
                $reason = self::applyDelete($items, $op);
            } else {
                $reason = 'unknown_op';
            }

            if ($reason === '') {
                $applied++;
            } else {
                $skipped[] = array('index' => $index, 'op' => $name, 'reason' => $reason);
            }
        }

        return array('items' => $items, 'skipped' => $skipped, 'applied' => $applied);
    }

    /**
     * A new item id: `m_` and random hex, not in $taken_ids.
     *
     * @param array         $taken_ids  Ids already in the memory
     * @param callable|null $random_hex Returns random hex (default: 6 characters from random_bytes)
     * @return string
     * @throws RuntimeException when no free id is found (a broken random source)
     */
    public static function generateId(array $taken_ids, $random_hex = null)
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $hex = $random_hex !== null ? (string)call_user_func($random_hex) : bin2hex(random_bytes(3));
            $id = 'm_' . $hex;
            if (!in_array($id, $taken_ids, true)) {
                return $id;
            }
        }
        throw new RuntimeException('No free memory item id found');
    }

    /**
     * The `memory_text` of a list of items: markdown, one `### Label` block per
     * section with a `- text` line per item, in the order of the sections,
     * empty sections left out. Items without a section (section '': a key
     * without sections, or items added before the key got sections) come first,
     * without a heading, so a key without sections is just a list. Items of a
     * section the key no longer defines follow after the defined sections,
     * headed by their section code, so nothing disappears from the text.
     *
     * `<` and `>` in an item text are written as entities: the text comes from
     * users and the model, and the markdown it is shown in lets raw HTML through.
     *
     * @param array $items
     * @param array $sections Section definitions of the memory key (`code`, `label`)
     * @return string
     */
    public static function renderText(array $items, array $sections)
    {
        $by_section = array();
        foreach ($items as $item) {
            if (is_array($item) && isset($item['section'], $item['text']) && is_string($item['section']) && is_string($item['text'])) {
                $by_section[$item['section']][] = '- ' . str_replace(array('<', '>'), array('&lt;', '&gt;'), self::normalizeText($item['text']));
            }
        }

        $blocks = array();
        if (!empty($by_section[''])) {
            $blocks[] = implode("
", $by_section['']);
            unset($by_section['']);
        }
        foreach ($sections as $section) {
            $code = isset($section['code']) ? (string)$section['code'] : '';
            if ($code === '' || empty($by_section[$code])) {
                continue;
            }
            $label = isset($section['label']) && $section['label'] !== '' ? (string)$section['label'] : $code;
            $blocks[] = '### ' . $label . "\n" . implode("\n", $by_section[$code]);
            unset($by_section[$code]);
        }
        foreach ($by_section as $code => $lines) {
            $blocks[] = '### ' . $code . "\n" . implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /* Private Methods ********************************************************/

    /** @return string '' when applied, else the reason */
    private static function applyAdd(array &$items, array $op, array $codes, $source, $now, $random_hex)
    {
        $section = isset($op['section']) && is_string($op['section']) ? $op['section'] : '';
        // a key without sections takes items without a section only; a key with sections needs one of them
        if (empty($codes) ? $section !== '' : !in_array($section, $codes, true)) {
            return 'unknown_section';
        }
        $text = self::normalizeText($op['text'] ?? '');
        $error = self::checkText($text);
        if ($error !== '') {
            return $error;
        }

        $items[] = array(
            'id' => self::generateId(array_column($items, 'id'), $random_hex),
            'section' => $section,
            'text' => $text,
            'source' => $source,
            'updated_at' => $now,
        );
        return '';
    }

    /** @return string '' when applied, else the reason */
    private static function applyUpdate(array &$items, array $op, array $codes, $source, $now)
    {
        $position = self::findItem($items, $op);
        if ($position === null) {
            return 'unknown_id';
        }

        $changes = array();
        if (array_key_exists('text', $op)) {
            $text = self::normalizeText($op['text']);
            $error = self::checkText($text);
            if ($error !== '') {
                return $error;
            }
            $changes['text'] = $text;
        }
        if (array_key_exists('section', $op)) {
            $section = is_string($op['section']) ? $op['section'] : '';
            // the section an item already has stays valid, even if the key no longer defines it
            if ($section !== ($items[$position]['section'] ?? null) && !in_array($section, $codes, true)) {
                return 'unknown_section';
            }
            $changes['section'] = $section;
        }

        $unchanged = true;
        foreach ($changes as $field => $value) {
            if (($items[$position][$field] ?? null) !== $value) {
                $unchanged = false;
            }
        }
        if (empty($changes) || $unchanged) {
            return 'unchanged';
        }

        $items[$position] = array_merge($items[$position], $changes, array('source' => $source, 'updated_at' => $now));
        return '';
    }

    /** @return string '' when applied, else the reason */
    private static function applyDelete(array &$items, array $op)
    {
        $position = self::findItem($items, $op);
        if ($position === null) {
            return 'unknown_id';
        }
        array_splice($items, $position, 1);
        return '';
    }

    /** @return int|null Position of the item the operation names */
    private static function findItem(array $items, array $op)
    {
        if (!isset($op['id']) || !is_string($op['id']) || $op['id'] === '') {
            return null;
        }
        foreach ($items as $position => $item) {
            if (isset($item['id']) && $item['id'] === $op['id']) {
                return $position;
            }
        }
        return null;
    }

    /** One fact on one line: control characters and runs of whitespace become one space. */
    private static function normalizeText($text)
    {
        if (!is_string($text)) {
            return '';
        }
        $clean = preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text);
        return $clean === null ? '' : trim($clean);
    }

    /** @return string '' when the text may be stored, else the reason */
    private static function checkText($text)
    {
        if ($text === '') {
            return 'empty_text';
        }
        return mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LENGTH ? 'text_too_long' : '';
    }
}
