<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Builds a labeled snapshot of a finished form / SurveyJS submission for the
 * memory pipeline.
 *
 * Result of every build method:
 *  - table_name / form_name: data table and display name of the source
 *  - fields: flat name => value map (same idea as the form action payload; used
 *    for {{field}} interpolation and the dedupe fingerprint)
 *  - readable_text: compact JSON that ties every answer to its question label
 *    and the readable choice text, e.g.
 *    {"source":"form","form":{...},"answers":[{"name","label","type","value","text"}]}
 *
 * The snapshot is built inside the save request (read-only, no DB writes)
 * because the async memory worker runs without session language and without the
 * live form / survey model. buildFormContext() and buildSurveyContext() only
 * collect definitions from the models; buildFormPayload() and
 * buildSurveyPayload() work on plain arrays.
 *
 * Unanswered questions have value null; 0, false and "" are kept as they are.
 */
class LlmMemorySubmissionContextService
{
    /** Keys that carry SelfHelp / SurveyJS bookkeeping and never an answer. */
    private static $INTERNAL_KEYS = array(
        'trigger_type', 'record_id', 'selected_record_id', 'delete_record_id',
        '__form_name', 'mobile', '_json', '_meta', 'response_id', 'survey_generated_id'
    );

    /** Survey question types that never carry an answer worth sending to the LLM (files can be huge base64). */
    private static $SKIPPED_SURVEY_TYPES = array('html', 'image', 'file', 'signaturepad');

    /** Invalid UTF-8 in an answer must not make the whole snapshot fail. */
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /* Model adapters *********************************************************/

    /**
     * Snapshot of a form that was just saved.
     *
     * @param object $form_model FormUserInputModel (or subclass) of the submitting section
     * @param array $user_input The saved name => value pairs
     * @param int|string $record_id Saved record id
     * @return array table_name, form_name, fields, readable_text
     */
    public function buildFormContext($form_model, array $user_input, $record_id)
    {
        $field_defs = array();
        $this->collectFormFieldDefs($form_model, $field_defs);
        $section_id = (int)$form_model->get_db_field('id');

        return $this->buildFormPayload(array(
            'name' => (string)$form_model->get_db_field('name'),
            'section_id' => $section_id,
            'table_name' => sprintf('%010d', $section_id),
            'record_id' => is_numeric($record_id) ? (int)$record_id : $record_id
        ), $field_defs, $user_input);
    }

    /**
     * Snapshot of a SurveyJS submission that was just saved.
     *
     * @param object $survey_model SurveyJSModel of the submitting section
     * @param array $data The data passed to SurveyJSModel::save_survey (typed answers in _json)
     * @param int|string $record_id Saved record id
     * @return array|null Same shape as buildFormContext, null if the survey row is gone
     */
    public function buildSurveyContext($survey_model, array $data, $record_id)
    {
        $survey = $survey_model->get_survey();
        if (!is_array($survey)) {
            return null;
        }

        // effective published definition: includes dynamic replacements the participant saw
        $definition = $survey['content'] ?? '';
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }

        // the typed answers travel in _json; the flattened top level keys are only a fallback
        $answers = array();
        if (isset($data['_json']) && is_string($data['_json'])) {
            $decoded = json_decode($data['_json'], true);
            $answers = is_array($decoded) ? $decoded : array();
        }
        if (empty($answers)) {
            $answers = $data;
        }

        $locale = (isset($answers['_meta']) && is_array($answers['_meta'])) ? (string)($answers['_meta']['language'] ?? '') : '';
        $definition = is_array($definition) ? $definition : array();
        $title = $this->localized($definition['title'] ?? null, $locale);
        $name = (string)($survey['survey_name'] ?? '');

        return $this->buildSurveyPayload(array(
            'name' => $name !== '' ? $name : $title,
            'title' => $title,
            'section_id' => (int)$survey_model->get_db_field('id'),
            'survey_generated_id' => (string)($survey['survey_generated_id'] ?? ''),
            'response_id' => (string)($answers['response_id'] ?? ''),
            'record_id' => is_numeric($record_id) ? (int)$record_id : $record_id,
            'language' => $locale
        ), $definition, $answers, $locale);
    }

    /* Payload builders (plain arrays) ****************************************/

    /**
     * @param array $meta name, section_id, table_name, record_id
     * @param array $field_defs List of name, label, type, items (value => readable text map)
     * @param array $user_input Saved name => value pairs
     * @return array table_name, form_name, fields, readable_text
     */
    public function buildFormPayload(array $meta, array $field_defs, array $user_input)
    {
        $fields = array();
        foreach ($user_input as $key => $value) {
            if (!in_array($key, self::$INTERNAL_KEYS, true)) {
                $fields[$key] = $this->flatValue($value);
            }
        }

        // every field of the form, in form order, so unanswered ones are visible too
        $entries = array();
        $known = array();
        foreach ($field_defs as $def) {
            $name = (string)$def['name'];
            $known[$name] = true;
            $value = array_key_exists($name, $user_input) ? $this->decodeList($user_input[$name]) : null;
            $label = (string)($def['label'] ?? '');
            $entry = array(
                'name' => $name,
                'label' => $label !== '' ? $label : $name,
                'type' => (string)($def['type'] ?? ''),
                'value' => $value
            );
            $text = $this->choiceText($value, (array)($def['items'] ?? array()));
            if ($text !== null) {
                $entry['text'] = $text;
            }
            $entries[] = $entry;
        }
        // submitted values without a field definition are kept rather than lost
        foreach (array_keys($fields) as $name) {
            if (!isset($known[$name])) {
                $entries[] = array('name' => (string)$name, 'label' => (string)$name, 'value' => $this->decodeList($user_input[$name]));
            }
        }

        return array(
            'table_name' => (string)($meta['table_name'] ?? ''),
            'form_name' => (string)($meta['name'] ?? ''),
            'fields' => $fields,
            'readable_text' => $this->encode(array('source' => 'form', 'form' => $meta, 'answers' => $entries))
        );
    }

    /**
     * @param array $meta name, title, section_id, survey_generated_id, response_id, record_id, language
     * @param array $definition Published SurveyJS JSON as array
     * @param array $answers Typed answers (the _json document of the submission)
     * @param string $locale Survey locale of the participant, '' if unknown
     * @return array table_name, form_name, fields, readable_text
     */
    public function buildSurveyPayload(array $meta, array $definition, array $answers, $locale)
    {
        $locale = (string)$locale;
        $entries = $this->surveyAnswers($this->surveyQuestions($definition), $answers, $locale);

        $fields = array();
        foreach ($entries as $entry) {
            $name = $entry['name'];
            if (array_key_exists($name, $answers)) {
                $fields[$name] = $this->flatValue($answers[$name]);
            }
            if (isset($entry['comment'])) {
                $fields[$name . '-Comment'] = $entry['comment'];
            }
        }

        if (empty($entries)) {
            // no usable definition: send the raw answers instead of nothing
            foreach ($answers as $name => $value) {
                if (!in_array($name, self::$INTERNAL_KEYS, true)) {
                    $entries[] = array('name' => (string)$name, 'label' => (string)$name, 'value' => $value);
                    $fields[$name] = $this->flatValue($value);
                }
            }
        }

        return array(
            'table_name' => (string)($meta['survey_generated_id'] ?? ''),
            'form_name' => (string)($meta['name'] ?? ''),
            'fields' => $fields,
            'readable_text' => $this->encode(array('source' => 'survey', 'survey' => $meta, 'answers' => $entries))
        );
    }

    /* Form helpers ***********************************************************/

    /**
     * Walk the loaded form tree (containers included) and collect every form field.
     * Labels and items are already resolved for the participant's session language.
     */
    private function collectFormFieldDefs($model, array &$defs)
    {
        if (!is_object($model) || !method_exists($model, 'get_children')) {
            return;
        }
        foreach ((array)$model->get_children() as $child) {
            if (!is_object($child) || !method_exists($child, 'get_model')) {
                continue;
            }
            $child_model = $child->get_model();
            if (!($child_model instanceof FormFieldModel)) {
                $this->collectFormFieldDefs($child_model, $defs);
                continue;
            }
            $name = trim((string)$child_model->get_db_field('name'));
            if ($name === '') {
                continue;
            }
            $style = (string)$child_model->get_style_name();
            $label = $this->plainText($child_model->get_db_field('label'));
            if ($label === '') {
                $label = $this->plainText($child_model->get_db_field('placeholder'));
            }
            $defs[] = array(
                'name' => $name,
                'label' => $label,
                'type' => $style === 'input' ? (string)$child_model->get_db_field('type_input', 'text') : $style,
                'items' => $this->formItemMap($child_model, $style)
            );
        }
    }

    /**
     * value => readable text for select / radio items; slider values map to their legend labels.
     */
    private function formItemMap($field_model, $style)
    {
        $map = array();
        if ($style === 'slider') {
            $min = (int)$field_model->get_db_field('min', 0);
            $labels = $field_model->get_db_field('labels', array());
            foreach ((is_array($labels) ? $labels : array()) as $idx => $label) {
                if (is_scalar($label)) {
                    $map[(string)($min + (int)$idx)] = $this->plainText($label);
                }
            }
            return $map;
        }
        $items = $field_model->get_db_field('items', array());
        foreach ((is_array($items) ? $items : array()) as $item) {
            // radio items may hold style definitions instead of plain text; only plain text is a label
            if (is_array($item) && isset($item['value']) && is_scalar($item['value']) && isset($item['text']) && is_string($item['text'])) {
                $map[(string)$item['value']] = $this->plainText($item['text']);
            }
        }
        return $map;
    }

    /* Survey helpers *********************************************************/

    /** Questions of all pages in order; static panels are flattened (their answers live at the top level). */
    private function surveyQuestions(array $definition)
    {
        $elements = array();
        foreach ((array)($definition['pages'] ?? array()) as $page) {
            if (is_array($page)) {
                $elements = array_merge($elements, (array)($page['elements'] ?? $page['questions'] ?? array()));
            }
        }
        return $this->flattenElements(array_merge($elements, (array)($definition['elements'] ?? array())));
    }

    private function flattenElements(array $elements)
    {
        $questions = array();
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['type'] ?? '') === 'panel') {
                $questions = array_merge($questions, $this->flattenElements((array)($element['elements'] ?? array())));
            } else {
                $questions[] = $element;
            }
        }
        return $questions;
    }

    private function surveyAnswers(array $questions, array $data, $locale)
    {
        $entries = array();
        foreach ($questions as $question) {
            $entry = $this->surveyAnswer($question, $data, $locale);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * One question with its answer. Matrices, multiple text and dynamic panels carry
     * their parts in `answers` (rows / items) or `entries` (one list per row / panel)
     * so every value stays tied to its row, column or item title.
     */
    private function surveyAnswer(array $q, array $data, $locale)
    {
        $type = (string)($q['type'] ?? 'text');
        $name = (string)($q['name'] ?? '');
        if ($name === '' || in_array($type, self::$SKIPPED_SURVEY_TYPES, true)) {
            return null;
        }

        $title = $this->localized($q['title'] ?? null, $locale);
        $entry = array('name' => $name, 'label' => $title !== '' ? $title : $name, 'type' => $type);
        $value = array_key_exists($name, $data) ? $data[$name] : null;
        $rows = $this->choiceMap($q['rows'] ?? array(), $locale);

        if ($type === 'matrix') {
            $columns = $this->choiceMap($q['columns'] ?? array(), $locale);
            $entry['answers'] = array();
            foreach ($rows as $row_value => $row_text) {
                $cell = (is_array($value) && array_key_exists($row_value, $value)) ? $value[$row_value] : null;
                $entry['answers'][] = $this->answerEntry($row_value, $row_text, $cell, $columns);
            }
        } else if ($type === 'matrixdropdown') {
            $entry['answers'] = array();
            foreach ($rows as $row_value => $row_text) {
                $cells = (is_array($value) && isset($value[$row_value]) && is_array($value[$row_value])) ? $value[$row_value] : array();
                $entry['answers'][] = array(
                    'name' => (string)$row_value,
                    'label' => $row_text,
                    'answers' => $this->matrixCells($q, $cells, $locale)
                );
            }
        } else if ($type === 'matrixdynamic') {
            $entry['entries'] = array();
            foreach ((is_array($value) ? $value : array()) as $row) {
                $entry['entries'][] = $this->matrixCells($q, is_array($row) ? $row : array(), $locale);
            }
        } else if ($type === 'paneldynamic') {
            $template = $this->flattenElements((array)($q['templateElements'] ?? $q['elements'] ?? array()));
            $entry['entries'] = array();
            foreach ((is_array($value) ? $value : array()) as $panel) {
                $entry['entries'][] = $this->surveyAnswers($template, is_array($panel) ? $panel : array(), $locale);
            }
        } else if ($type === 'multipletext') {
            $entry['answers'] = array();
            foreach ((array)($q['items'] ?? array()) as $item) {
                $item_name = is_array($item) ? (string)($item['name'] ?? '') : '';
                if ($item_name === '') {
                    continue;
                }
                $item_title = $this->localized($item['title'] ?? null, $locale);
                $item_value = (is_array($value) && array_key_exists($item_name, $value)) ? $value[$item_name] : null;
                $entry['answers'][] = $this->answerEntry($item_name, $item_title !== '' ? $item_title : $item_name, $item_value, array());
            }
        } else {
            $entry['value'] = $value;
            if ($type === 'boolean') {
                $label_text = $this->localized($q[$value === true ? 'labelTrue' : 'labelFalse'] ?? null, $locale);
                if ($value !== null && $label_text !== '') {
                    $entry['text'] = $label_text;
                }
            } else {
                $text = $this->choiceText($value, $this->surveyChoiceMap($q, $locale));
                if ($text !== null) {
                    $entry['text'] = $text;
                }
            }
            if ($type === 'rating' && empty($q['rateValues'])) {
                // a bare number means nothing without the scale it was given on
                $scale = array();
                $low = $this->localized($q['minRateDescription'] ?? null, $locale);
                $high = $this->localized($q['maxRateDescription'] ?? null, $locale);
                if ($low !== '') {
                    $scale[] = ($q['rateMin'] ?? 1) . ' = ' . $low;
                }
                if ($high !== '') {
                    $scale[] = ($q['rateMax'] ?? 5) . ' = ' . $high;
                }
                if (!empty($scale)) {
                    $entry['scale'] = implode(', ', $scale);
                }
            }
        }

        // free text of the "other" choice and comment areas is stored as <name>-Comment
        $comment = $name . '-Comment';
        if (isset($data[$comment]) && is_scalar($data[$comment]) && trim((string)$data[$comment]) !== '') {
            $entry['comment'] = (string)$data[$comment];
        }

        return $entry;
    }

    /** Cells of a matrixdropdown row / matrixdynamic row: every column is a small question of its own. */
    private function matrixCells(array $q, array $row_data, $locale)
    {
        $cells = array();
        foreach ((array)($q['columns'] ?? array()) as $column) {
            if (!is_array($column) || empty($column['name'])) {
                continue;
            }
            $cell_type = $column['cellType'] ?? 'default';
            $cell = $this->surveyAnswer(array(
                'name' => $column['name'],
                'title' => $column['title'] ?? $column['name'],
                'type' => $cell_type === 'default' ? ($q['cellType'] ?? 'dropdown') : $cell_type,
                'choices' => $column['choices'] ?? ($q['choices'] ?? array())
            ) + $column, $row_data, $locale);
            if ($cell !== null) {
                $cells[] = $cell;
            }
        }
        return $cells;
    }

    /** Choices of a question including the built-in "other" / "none" items. */
    private function surveyChoiceMap(array $q, $locale)
    {
        $map = $this->choiceMap($q['choices'] ?? ($q['rateValues'] ?? array()), $locale);
        if (!empty($q['showOtherItem']) || !empty($q['hasOther'])) {
            $text = $this->localized($q['otherText'] ?? null, $locale);
            $map['other'] = $text !== '' ? $text : 'Other';
        }
        if (!empty($q['showNoneItem']) || !empty($q['hasNone'])) {
            $text = $this->localized($q['noneText'] ?? null, $locale);
            $map['none'] = $text !== '' ? $text : 'None';
        }
        return $map;
    }

    /** value => readable text for SurveyJS choices / rows / columns (plain values or {value, text}). */
    private function choiceMap($choices, $locale)
    {
        $map = array();
        foreach ((is_array($choices) ? $choices : array()) as $choice) {
            if (is_array($choice)) {
                if (!isset($choice['value']) || !is_scalar($choice['value'])) {
                    continue;
                }
                $text = $this->localized($choice['text'] ?? null, $locale);
                $map[(string)$choice['value']] = $text !== '' ? $text : (string)$choice['value'];
            } else if (is_scalar($choice)) {
                $map[(string)$choice] = (string)$choice;
            }
        }
        return $map;
    }

    /* Shared helpers *********************************************************/

    private function answerEntry($name, $label, $value, array $choice_map)
    {
        $entry = array('name' => (string)$name, 'label' => (string)$label, 'value' => $value);
        $text = $this->choiceText($value, $choice_map);
        if ($text !== null) {
            $entry['text'] = $text;
        }
        return $entry;
    }

    /**
     * Readable text of a value (or list of values) from a value => text map.
     *
     * @return string|array|null null when there is nothing to add (no map, or the text equals the value)
     */
    private function choiceText($value, array $map)
    {
        if (empty($map) || $value === null) {
            return null;
        }
        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }
            $texts = array();
            foreach ($value as $item) {
                $texts[] = (is_scalar($item) && array_key_exists((string)$item, $map)) ? $map[(string)$item] : $item;
            }
            return $texts == $value ? null : $texts;
        }
        if (is_bool($value) || !is_scalar($value) || !array_key_exists((string)$value, $map)) {
            return null;
        }
        return $map[(string)$value] === (string)$value ? null : $map[(string)$value];
    }

    /** Form values arrive JSON-encoded when a field holds several values. */
    private function decodeList($value)
    {
        if (is_string($value) && strlen($value) > 1 && $value[0] === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }

    /** Scalars stay as they are, lists / objects become JSON text. */
    private function flatValue($value)
    {
        if (is_array($value)) {
            $json = json_encode($value, self::JSON_FLAGS);
            return $json === false ? '' : $json;
        }
        return $value;
    }

    /**
     * SurveyJS strings are plain or localized ({"default": "...", "de": "..."}):
     * exact locale, language part, default, then the first non-empty value.
     */
    private function localized($value, $locale)
    {
        if (is_array($value)) {
            $locale = (string)$locale;
            $picked = null;
            foreach (array($locale, substr($locale, 0, strcspn($locale, '-_')), 'default') as $key) {
                if ($key !== '' && isset($value[$key]) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
                    $picked = $value[$key];
                    break;
                }
            }
            if ($picked === null) {
                foreach ($value as $candidate) {
                    if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                        $picked = $candidate;
                        break;
                    }
                }
            }
            $value = $picked;
        }
        return is_scalar($value) ? $this->plainText($value) : '';
    }

    /** HTML to plain text; block level tags become spaces so words do not run together. */
    private function plainText($html)
    {
        $text = is_scalar($html) ? (string)$html : '';
        if (strpbrk($text, '<&') !== false) {
            $text = preg_replace('~<\s*(?:br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>~i', ' ', $text) ?? $text;
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    private function encode(array $document)
    {
        $json = json_encode($document, self::JSON_FLAGS);
        return $json === false ? '' : $json;
    }
}
