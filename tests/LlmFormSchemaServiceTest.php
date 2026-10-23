<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmFormSchemaService (llmFormLogList schema
 * contract, plugin v1.6.0).
 *
 * No bootstrap, no live database, no LLM calls: fake field/container
 * models subclass the real FormFieldModel/StyleModel with a bypassed
 * constructor so the service exercises the same instanceof/duck-typed
 * paths it uses in production.
 *
 * Run: php tests/LlmFormSchemaServiceTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');

require_once LLM_PATH . '/service/LlmFormSchemaService.php';

/* =========================================================================
 * Fakes
 * ========================================================================= */

/**
 * Leaf form field (input/select/checkbox/radio/slider/textarea/custom).
 * Bypasses StyleModel::__construct so no services/db are needed.
 */
class FakeFieldModel extends FormFieldModel
{
    private $fake_style;
    private $fake_fields;
    private $fake_children;
    private $fake_condition;
    private $fake_entry;

    public function __construct($style, array $fields = array(), array $children = array(), $condition = true, array $entry = array())
    {
        $this->fake_style = $style;
        $this->fake_fields = $fields;
        $this->fake_children = $children;
        $this->fake_condition = $condition;
        $this->fake_entry = $entry;
    }

    public function get_db_field($key, $default = "")
    {
        return array_key_exists($key, $this->fake_fields) ? $this->fake_fields[$key] : $default;
    }

    public function get_db_field_full($key)
    {
        return array_key_exists($key, $this->fake_fields)
            ? array('content' => $this->fake_fields[$key]) : '';
    }

    public function get_style_name() { return $this->fake_style; }
    public function get_condition_result() { return array('result' => $this->fake_condition); }
    public function get_children() { return $this->fake_children; }
    public function get_entry_record() { return $this->fake_entry; }

    public function get_form_field_value()
    {
        $name = $this->get_db_field('name');
        return ($name !== '' && array_key_exists($name, $this->fake_entry))
            ? $this->fake_entry[$name] : null;
    }
}

/** Container (div/conditionalContainer/etc.) - deliberately not a FormFieldModel. */
class FakeContainerModel extends StyleModel
{
    private $fake_style;
    private $fake_fields;
    private $fake_children;
    private $fake_condition;

    public function __construct($style, array $fields = array(), array $children = array(), $condition = true)
    {
        $this->fake_style = $style;
        $this->fake_fields = $fields;
        $this->fake_children = $children;
        $this->fake_condition = $condition;
    }

    public function get_db_field($key, $default = "")
    {
        return array_key_exists($key, $this->fake_fields) ? $this->fake_fields[$key] : $default;
    }

    public function get_style_name() { return $this->fake_style; }
    public function get_condition_result() { return array('result' => $this->fake_condition); }
    public function get_children() { return $this->fake_children; }
}

/** StyleComponent stand-in. */
class FakeChildComponent
{
    private $model;
    private $id_section;
    private $access;

    public function __construct($model, $id_section, $access = true)
    {
        $this->model = $model;
        $this->id_section = $id_section;
        $this->access = $access;
    }

    public function get_model() { return $this->model; }
    public function get_id_section() { return $this->id_section; }
    public function has_access() { return $this->access; }
}

/** LlmFormLogListModel stand-in: children + interview config getters. */
class FakeFormModel
{
    private $children;
    private $hidden_allowlist;
    private $manual_edit;
    private $scope_bound;

    public function __construct(array $children, array $hidden_allowlist = array(), $manual_edit = true, array $scope_bound = array())
    {
        $this->children = $children;
        $this->hidden_allowlist = $hidden_allowlist;
        $this->manual_edit = $manual_edit;
        $this->scope_bound = $scope_bound;
    }

    public function get_children() { return $this->children; }
    public function getInterviewWritableHiddenFields() { return $this->hidden_allowlist; }
    public function isInterviewAllowManualEdit() { return $this->manual_edit; }
    public function getScopeBoundFieldNames() { return $this->scope_bound; }
}

/** Field model that counts how often a schema walk reads its condition. */
class CountingFieldModel extends FakeFieldModel
{
    public static $reads = 0;

    public function get_condition_result()
    {
        self::$reads++;
        return parent::get_condition_result();
    }
}

/**
 * Form model whose child tree and settings can change, like a model that
 * reloaded its children (a reload creates new component objects).
 */
class ReloadableFormModel extends FakeFormModel
{
    public $tree;
    public $allowlist = array();
    public $manual = true;

    public function __construct(array $tree)
    {
        $this->tree = $tree;
    }

    public function get_children() { return $this->tree; }
    public function getInterviewWritableHiddenFields() { return $this->allowlist; }
    public function isInterviewAllowManualEdit() { return $this->manual; }
    public function getScopeBoundFieldNames() { return array(); }
}

function field($style, $name, array $extra = array(), $id = 0, $children = array(), $condition = true, $entry = array())
{
    $fields = array_merge(array('name' => $name), $extra);
    return new FakeChildComponent(new FakeFieldModel($style, $fields, array(), $condition, $entry), $id);
}

function box(array $children, array $fields = array(), $condition = true)
{
    return new FakeChildComponent(new FakeContainerModel('div', $fields, $children, $condition), 0);
}

function counted_field($name, array $extra = array(), $id = 0)
{
    $fields = array_merge(array('name' => $name, 'type_input' => 'text'), $extra);
    return new FakeChildComponent(new CountingFieldModel('input', $fields, array(), true, array()), $id);
}

/* =========================================================================
 * Harness
 * ========================================================================= */

$GLOBALS['__checks'] = 0;
$GLOBALS['__fails'] = array();

function check($label, $cond)
{
    $GLOBALS['__checks']++;
    if (!$cond) {
        $GLOBALS['__fails'][] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

$svc = new LlmFormSchemaService();

/* =========================================================================
 * Tree fixture
 * ========================================================================= */

$children = array(
    field('input', 'title', array('type_input' => 'text', 'label' => 'Title', 'is_required' => '1', 'min' => 2, 'max' => 50), 11),
    box(array(
        field('input', 'email', array('type_input' => 'email', 'label' => 'E-mail'), 12),
        box(array(
            field('input', 'module_id', array('type_input' => 'hidden', 'value' => '7'), 13),
            field('input', 'secret_flag', array('type_input' => 'hidden', 'value' => '0'), 14),
            field('input', 'secret_token', array('type_input' => 'hidden', 'value' => 'tok'), 15),
            field('textarea', 'hidden_note', array('label' => 'Hidden note'), 16),
        ), array('css' => 'd-none'))
    )),
    field('select', 'color', array(
        'label' => 'Colour', 'is_required' => '1',
        'items' => array(
            array('value' => 'r', 'text' => 'Red'),
            array('value' => 'b', 'text' => 'Blue')
        )
    ), 17),
    field('select', 'tags', array(
        'label' => 'Tags', 'is_multiple' => 1, 'max' => 2,
        'items' => array(
            array('value' => 'a', 'text' => 'A'),
            array('value' => 'b', 'text' => 'B'),
            array('value' => 'c', 'text' => 'C')
        )
    ), 18),
    field('checkbox', 'agree', array('label' => 'Agree', 'is_required' => '1', 'checkbox_value' => 'yes'), 19),
    field('slider', 'satisfaction', array('label' => 'Satisfaction', 'min' => 1, 'max' => 5, 'labels' => array('Low', 'Mid', 'High')), 20),
    field('input', 'count', array('type_input' => 'number', 'label' => 'Count', 'min' => 0, 'max' => 10), 21),
    field('input', 'birth', array('type_input' => 'date', 'label' => 'Birthday', 'value' => '2000-01-01'), 22, array(), true, array('birth' => '1990-05-05')),
    box(array(
        field('input', 'conditional_field', array('label' => 'Conditional'), 23)
    ), array(), false),
    field('autocomplete', 'city', array('label' => 'City', 'is_required' => '1'), 24),
    field('input', 'file_up', array('type_input' => 'file', 'label' => 'Upload'), 25),
    field('input', 'title', array('type_input' => 'text', 'label' => 'Duplicate title'), 26),
    field('input', 'record_id', array('type_input' => 'text'), 27),
    field('input', 'week_no', array('type_input' => 'week', 'label' => 'Week'), 28)
);

// scope template `module:{{module_id}}` makes module_id scope-bound;
// secret_flag is hidden-allowlisted, secret_token is not.
$form = new FakeFormModel($children, array('secret_flag', 'module_id'), true, array('module_id'));
$schema = $svc->build($form);

/* =========================================================================
 * build(): eligibility, hidden, native-only, diagnostics
 * ========================================================================= */

$schema_names = array_keys(array_filter($schema, function ($v, $k) {
    return is_array($v) && $k !== LlmFormSchemaService::ERRORS_KEY;
}, ARRAY_FILTER_USE_BOTH));
check('build: author order preserved', $schema_names === array(
    'title', 'email', 'module_id', 'secret_flag', 'secret_token', 'hidden_note',
    'color', 'tags', 'agree', 'satisfaction', 'count', 'birth',
    'city', 'file_up', 'week_no'
));
check('build: condition-false subtree excluded entirely', !isset($schema['conditional_field']));
check('build: hidden input flagged', $schema['secret_flag']['hidden'] === true);
check('build: hidden-by-ancestor field flagged', $schema['hidden_note']['hidden'] === true);
check('build: allowlisted hidden is llm_writable', $schema['secret_flag']['llm_writable'] === true);
check('build: non-allowlisted hidden not llm_writable', $schema['secret_token']['llm_writable'] === false);
check('build: scope-bound hidden not llm_writable despite allowlist', $schema['module_id']['llm_writable'] === false);
check('build: hidden fields are not interview questions', $schema['secret_flag']['interview'] === false);
check('build: hidden fields never manual_writable', $schema['secret_flag']['manual_writable'] === false);
check('build: native-only style flagged', $schema['city']['native_only'] === true && $schema['city']['llm_writable'] === false && $schema['city']['manual_writable'] === false);
check('build: native-only input type flagged', $schema['file_up']['native_only'] === true && $schema['file_up']['llm_writable'] === false);
check('build: native-only not an interview field', $schema['city']['interview'] === false);
check('build: duplicate name diagnosed once', isset($schema[LlmFormSchemaService::ERRORS_KEY]) && count(array_filter($schema[LlmFormSchemaService::ERRORS_KEY], function ($e) { return strpos($e, 'Duplicate') !== false; })) === 1);
check('build: first duplicate wins', $schema['title']['label'] === 'Title');
check('build: reserved name diagnosed', count(array_filter($schema[LlmFormSchemaService::ERRORS_KEY], function ($e) { return strpos($e, 'record_id') !== false; })) === 1);
check('build: reserved name absent from schema', !isset($schema['record_id']));
check('build: select options normalized', $schema['color']['options'] === array(
    array('value' => 'r', 'label' => 'Red'),
    array('value' => 'b', 'label' => 'Blue')
));
check('build: multi-select input_type', $schema['tags']['input_type'] === 'select-multiple');
check('build: slider legend options', $schema['satisfaction']['options'] === array(
    array('value' => '1', 'label' => 'Low'),
    array('value' => '2', 'label' => 'Mid'),
    array('value' => '3', 'label' => 'High')
));
check('build: checkbox checked_value constraint', $schema['agree']['constraints']['checked_value'] === 'yes');
check('build: required flags', $schema['title']['required'] === true && $schema['email']['required'] === false);
check('build: section ids recorded', $schema['title']['section_id'] === 11 && $schema['agree']['section_id'] === 19);
check('build: native default seeds initial value', $schema['module_id']['initial_value'] === '7');
check('build: entry record overrides native default', $schema['birth']['initial_value'] === '1990-05-05');
check('build: visible writable flags', $schema['title']['llm_writable'] === true && $schema['title']['manual_writable'] === true && $schema['title']['interview'] === true);

/* =========================================================================
 * normalizePatch(): origins, hidden allowlist, unknown/system keys
 * ========================================================================= */

$res = $svc->normalizePatch($schema, array('title' => 'Hello'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: llm sets visible field', $res['values'] === array('title' => 'Hello') && !$res['errors']);

$res = $svc->normalizePatch($schema, array('secret_flag' => '1'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: llm writes allowlisted hidden', $res['values'] === array('secret_flag' => '1') && !$res['errors']);

$res = $svc->normalizePatch($schema, array('secret_token' => 'x'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: llm cannot write non-allowlisted hidden', isset($res['errors']['secret_token']) && !isset($res['values']['secret_token']));

$res = $svc->normalizePatch($schema, array('module_id' => '9'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: scope-bound name not writable via allowlist', isset($res['errors']['module_id']));

// The model often echoes the read-only facts it was shown: repeating the
// value the server already holds is ignored, changing it stays an error.
$res = $svc->normalizePatch($schema, array('module_id' => '7'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: repeated scope-bound value is ignored, not rejected', !$res['errors'] && !$res['values']);

$res = $svc->normalizePatch($schema, array('secret_token' => ' tok ', 'title' => 'Hi'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: repeated hidden value does not block real changes', !$res['errors'] && $res['values'] === array('title' => 'Hi'));

$res = $svc->normalizePatch($schema, array('secret_token' => null), LlmFormSchemaService::ORIGIN_LLM);
check('patch: clearing a read-only value is still rejected', isset($res['errors']['secret_token']));

$res = $svc->normalizePatch($schema, array('conditional_field' => 'x', 'nope' => 'x', 'record_id' => 5), LlmFormSchemaService::ORIGIN_LLM);
check('patch: excluded/unknown/reserved names rejected', isset($res['errors']['conditional_field'], $res['errors']['nope'], $res['errors']['record_id']) && !$res['values']);

$res = $svc->normalizePatch($schema, array('city' => 'Zurich', 'file_up' => '/tmp/x'), LlmFormSchemaService::ORIGIN_LLM);
check('patch: native-only fields rejected', isset($res['errors']['city'], $res['errors']['file_up']) && !$res['values']);

$res = $svc->normalizePatch($schema, array('title' => null), LlmFormSchemaService::ORIGIN_LLM);
check('patch: null clears a field', array_key_exists('title', $res['values']) && $res['values']['title'] === null && !$res['errors']);

$res = $svc->normalizePatch($schema, array('title' => 'Manual'), LlmFormSchemaService::ORIGIN_MANUAL);
check('patch: manual edit allowed when enabled', $res['values'] === array('title' => 'Manual'));

$res = $svc->normalizePatch($schema, array('secret_flag' => '1'), LlmFormSchemaService::ORIGIN_MANUAL);
check('patch: manual cannot write hidden fields', isset($res['errors']['secret_flag']));

$form_no_manual = new FakeFormModel(array(
    field('input', 'title', array('type_input' => 'text'), 30)
), array(), false, array());
$schema_no_manual = $svc->build($form_no_manual);
$res = $svc->normalizePatch($schema_no_manual, array('title' => 'Manual'), LlmFormSchemaService::ORIGIN_MANUAL);
check('patch: manual forbidden when disabled', isset($res['errors']['title']));

/* =========================================================================
 * normalizePatch(): native value rules, 0/false distinct from missing
 * ========================================================================= */

$res = $svc->normalizePatch($schema, array('agree' => true), LlmFormSchemaService::ORIGIN_LLM);
check('checkbox: true -> native checked value', $res['values']['agree'] === 'yes');
$res = $svc->normalizePatch($schema, array('agree' => false), LlmFormSchemaService::ORIGIN_LLM);
check('checkbox: false -> explicit 0, not missing', array_key_exists('agree', $res['values']) && $res['values']['agree'] === 0);
$res = $svc->normalizePatch($schema, array('agree' => 'maybe'), LlmFormSchemaService::ORIGIN_LLM);
check('checkbox: non-boolean rejected', isset($res['errors']['agree']));

$res = $svc->normalizePatch($schema, array('color' => 'b'), LlmFormSchemaService::ORIGIN_LLM);
check('choice: valid option accepted', $res['values']['color'] === 'b');
$res = $svc->normalizePatch($schema, array('color' => 'z'), LlmFormSchemaService::ORIGIN_LLM);
check('choice: invalid option rejected', isset($res['errors']['color']));
$res = $svc->normalizePatch($schema, array('tags' => array('a', 'c')), LlmFormSchemaService::ORIGIN_LLM);
check('multi: flat array of options accepted', $res['values']['tags'] === array('a', 'c'));
$res = $svc->normalizePatch($schema, array('tags' => array('a', 'x')), LlmFormSchemaService::ORIGIN_LLM);
check('multi: unknown option rejected', isset($res['errors']['tags']));
$res = $svc->normalizePatch($schema, array('tags' => array('a', 'b', 'c')), LlmFormSchemaService::ORIGIN_LLM);
check('multi: beyond max selections rejected', isset($res['errors']['tags']));

$res = $svc->normalizePatch($schema, array('count' => 5, 'satisfaction' => 3), LlmFormSchemaService::ORIGIN_LLM);
check('range: in-bounds numeric accepted', $res['values'] === array('count' => 5, 'satisfaction' => 3));
$res = $svc->normalizePatch($schema, array('count' => 0), LlmFormSchemaService::ORIGIN_LLM);
check('range: 0 is a value, not missing', array_key_exists('count', $res['values']) && $res['values']['count'] === 0 && !$res['errors']);
$res = $svc->normalizePatch($schema, array('count' => 11, 'satisfaction' => 9), LlmFormSchemaService::ORIGIN_LLM);
check('range: out-of-bounds rejected', isset($res['errors']['count'], $res['errors']['satisfaction']));
$res = $svc->normalizePatch($schema, array('count' => 'abc'), LlmFormSchemaService::ORIGIN_LLM);
check('range: non-numeric rejected', isset($res['errors']['count']));

$res = $svc->normalizePatch($schema, array('birth' => '2024-01-05', 'week_no' => '2024-W09'), LlmFormSchemaService::ORIGIN_LLM);
check('date: valid date + week accepted', $res['values'] === array('birth' => '2024-01-05', 'week_no' => '2024-W09'));
$res = $svc->normalizePatch($schema, array('birth' => '2024-02-30', 'week_no' => '2024-W99'), LlmFormSchemaService::ORIGIN_LLM);
check('date: invalid date + week rejected', isset($res['errors']['birth'], $res['errors']['week_no']));
$res = $svc->normalizePatch($schema, array('email' => 'user@example.com'), LlmFormSchemaService::ORIGIN_LLM);
check('email: valid accepted', $res['values']['email'] === 'user@example.com');
$res = $svc->normalizePatch($schema, array('email' => 'not-an-email'), LlmFormSchemaService::ORIGIN_LLM);
check('email: invalid rejected', isset($res['errors']['email']));

$res = $svc->normalizePatch($schema, array('title' => 'x'), LlmFormSchemaService::ORIGIN_LLM);
check('text: below min length rejected', isset($res['errors']['title']));
$res = $svc->normalizePatch($schema, array('title' => str_repeat('x', 51)), LlmFormSchemaService::ORIGIN_LLM);
check('text: above max length rejected', isset($res['errors']['title']));
$res = $svc->normalizePatch($schema, array('title' => array('a', 'b')), LlmFormSchemaService::ORIGIN_LLM);
check('text: array value rejected for scalar control', isset($res['errors']['title']));

/* =========================================================================
 * validateValues()
 * ========================================================================= */

$errors = $svc->validateValues($schema, array('title' => 'Hi', 'count' => 0, 'agree' => 0));
check('validate: provided values incl. 0 pass', $errors === array());
$errors = $svc->validateValues($schema, array('ghost' => 'x'));
check('validate: unknown value name rejected', isset($errors['ghost']));
$errors = $svc->validateValues($schema, array('birth' => '30.02.2024'));
check('validate: malformed value rejected', isset($errors['birth']));

$errors = $svc->validateValues($schema, array(), true);
check('validate: requireAll flags missing required', isset($errors['title'], $errors['color'], $errors['agree']));
check('validate: optional empty does not block', !isset($errors['email']) && !isset($errors['week_no']));
check('validate: hidden non-required stays unchecked', !isset($errors['secret_token']));
check('validate: native-only required flagged with control error', isset($errors['city']) && strpos($errors['city'], 'native control') !== false);
$errors = $svc->validateValues($schema, array('title' => 'Hi', 'color' => 'r', 'agree' => 'yes', 'city' => 'Zurich'), true);
check('validate: complete required set incl. native fallback passes', $errors === array());
$errors = $svc->validateValues($schema, array('title' => 'Hi', 'color' => 'r', 'agree' => 0, 'city' => 'x'), true);
check('validate: unchecked required checkbox fails', isset($errors['agree']));

$form_hidden_req = new FakeFormModel(array(
    field('input', 'hidden_req', array('type_input' => 'hidden', 'is_required' => '1'), 40)
), array(), true, array());
$schema_hr = $svc->build($form_hidden_req);
$errors = $svc->validateValues($schema_hr, array(), true);
check('validate: required hidden missing produces config error', isset($errors['hidden_req']) && strpos($errors['hidden_req'], 'hidden') !== false);
$errors = $svc->validateValues($schema_hr, array('hidden_req' => 'v'), true);
check('validate: required hidden satisfied', $errors === array());

/* =========================================================================
 * progress()
 * ========================================================================= */

$p = $svc->progress($schema, array(), null);
check('progress: hidden/native-only excluded from steps', !isset($p['fields']['secret_flag'], $p['fields']['secret_token'], $p['fields']['city'], $p['fields']['file_up']));
check('progress: all pending initially', $p['done'] === 0 && $p['required_total'] === 3 && $p['active'] === 'title');
check('progress: not ready', $p['ready_for_approval'] === false);

$p = $svc->progress($schema, array('title' => 'Hi'), 'email');
check('progress: explicit active visible field honored', $p['fields']['email']['status'] === 'active' && $p['active'] === 'email');
check('progress: done field marked done', $p['fields']['title']['status'] === 'done');

$p = $svc->progress($schema, array('title' => 'Hi'), 'secret_flag');
check('progress: hidden active falls back to first pending required', $p['active'] === 'color');

$p = $svc->progress($schema, array('title' => 'Hi', 'color' => 'r', 'agree' => 'yes', 'email' => 'u@e.com', 'tags' => array('a'), 'satisfaction' => 2, 'count' => 0, 'birth' => '2000-01-01', 'week_no' => '2024-W09', 'city' => 'Zurich'), null);
check('progress: all visible done -> ready', $p['ready_for_approval'] === true && $p['done'] === $p['total']);
check('progress: 0 counts as done', $p['fields']['count']['status'] === 'done');

$p2 = $svc->progress($schema, array('title' => 'Hi', 'color' => 'r', 'agree' => 0, 'email' => 'u@e.com', 'tags' => array('a'), 'satisfaction' => 2, 'count' => 0, 'birth' => '2000-01-01', 'week_no' => '2024-W09', 'city' => 'Z'), null);
check('progress: unchecked required checkbox is pending, not ready', $p2['ready_for_approval'] === false && $p2['fields']['agree']['status'] === 'active');

/* =========================================================================
 * ORIGIN_NATIVE: server-only fallback writes
 * ========================================================================= */

$res = $svc->normalizePatch($schema, array('city' => 'Zurich'), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: native-only field accepts fallback value', $res['values'] === array('city' => 'Zurich') && !$res['errors']);
$res = $svc->normalizePatch($schema, array('file_up' => '/tmp/evil.png'), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: upload field rejected - use native widget', isset($res['errors']['file_up']) && !isset($res['values']['file_up']));
$res = $svc->normalizePatch($schema, array('file_up' => 'C:\\fake\\x.pdf'), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: upload path rejected', isset($res['errors']['file_up']));
$res = $svc->normalizePatch($schema, array('module_id' => '9'), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: scope-bound field stays immutable', isset($res['errors']['module_id']));
$res = $svc->normalizePatch($schema, array('secret_token' => 'server-set'), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: non-allowlisted hidden rejected', isset($res['errors']['secret_token']) && !isset($res['values']['secret_token']));
$res = $svc->normalizePatch($schema, array('title' => 'Native', 'secret_flag' => '1'), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: visible accepted, allowlisted hidden still rejected through native controls', $res['values'] === array('title' => 'Native') && isset($res['errors']['secret_flag']));
$res = $svc->normalizePatch($schema, array('city' => array('a' => array('nested'))), LlmFormSchemaService::ORIGIN_NATIVE);
check('native: deep structures rejected for native-only', isset($res['errors']['city']));
$res = $svc->normalizePatch($schema, array('title' => 'x'), 'participant-native');
check('native: unknown origin string rejected', isset($res['errors'][LlmFormSchemaService::ERRORS_KEY]));

/* =========================================================================
 * Blank optional scalars, slider integers, multi-select decoding
 * ========================================================================= */

$res = $svc->normalizePatch($schema, array('email' => '', 'birth' => '', 'count' => '', 'color' => ''), LlmFormSchemaService::ORIGIN_LLM);
check('blank: optional scalars validate as empty', $res['errors'] === array() && $res['values'] === array('email' => '', 'birth' => '', 'count' => '', 'color' => ''));
$res = $svc->normalizePatch($schema, array('tags' => ''), LlmFormSchemaService::ORIGIN_LLM);
check('blank: multi-select empty normalizes to empty list', $res['errors'] === array() && $res['values']['tags'] === array());
$errors = $svc->validateValues($schema, array('email' => '', 'count' => '', 'color' => ''));
check('blank: validateValues treats empty scalars as empty', $errors === array());
$res = $svc->normalizePatch($schema, array('satisfaction' => 2.5), LlmFormSchemaService::ORIGIN_LLM);
check('slider: fractional value rejected', isset($res['errors']['satisfaction']));
$res = $svc->normalizePatch($schema, array('satisfaction' => '3'), LlmFormSchemaService::ORIGIN_LLM);
check('slider: integer string accepted as int', $res['values']['satisfaction'] === 3);

$form_multi = new FakeFormModel(array(
    field('select', 'tags', array(
        'is_multiple' => 1, 'value' => '["a","b"]',
        'items' => array(array('value' => 'a', 'text' => 'A'), array('value' => 'b', 'text' => 'B'))
    ), 41),
    field('select', 'tags2', array(
        'is_multiple' => 1,
        'items' => array(array('value' => 'a', 'text' => 'A'))
    ), 42, array(), true, array('tags2' => '[&quot;a&quot;]')),
    field('input', 'zero', array('type_input' => 'number', 'value' => 0), 43),
    field('input', 'falsy', array('type_input' => 'text', 'value' => false), 44)
), array(), true, array());
$schema_m = $svc->build($form_multi);
check('multi: JSON default decoded to native array', $schema_m['tags']['initial_value'] === array('a', 'b'));
check('multi: entity-encoded JSON entry decoded', $schema_m['tags2']['initial_value'] === array('a'));
check('defaults: explicit 0 preserved through loose default', $schema_m['zero']['initial_value'] === 0);
check('defaults: explicit false preserved', $schema_m['falsy']['initial_value'] === false);

/* =========================================================================
 * Field-name shape + has_access gating
 * ========================================================================= */

$form_names = new FakeFormModel(array(
    field('input', 'evil[x]', array('type_input' => 'text'), 50),
    field('input', 'dot.name', array('type_input' => 'text'), 51),
    field('input', 'id_users', array('type_input' => 'text'), 52),
    field('input', 'delete_record_id', array('type_input' => 'text'), 53),
    field('input', 'redirect_at_end', array('type_input' => 'text'), 54),
    field('input', 'quick_action_id', array('type_input' => 'text'), 55),
    field('input', 'mobile', array('type_input' => 'text'), 56),
    field('input', 'legit', array('type_input' => 'text'), 57)
), array(), true, array());
$schema_n = $svc->build($form_names);
check('names: bracket/dot names rejected', !isset($schema_n['evil[x]'], $schema_n['dot.name']) && count($schema_n[LlmFormSchemaService::ERRORS_KEY]) >= 2);
check('names: ownership + envelope names reserved', !isset($schema_n['id_users'], $schema_n['delete_record_id'], $schema_n['redirect_at_end'], $schema_n['quick_action_id'], $schema_n['mobile']));
check('names: legit field survives', isset($schema_n['legit']));

$form_acl = new FakeFormModel(array(
    new FakeChildComponent(new FakeFieldModel('input', array('name' => 'secret', 'type_input' => 'text'), array(), true), 60, false),
    field('input', 'visible_field', array('type_input' => 'text'), 61)
), array(), true, array());
$schema_a = $svc->build($form_acl);
check('access: denied child excluded from schema', !isset($schema_a['secret']) && isset($schema_a['visible_field']));

/* =========================================================================
 * Memoization: one walk per loaded child tree
 * ========================================================================= */

$memo_form = new ReloadableFormModel(array(
    counted_field('first', array('label' => 'First'), 101),
    counted_field('second', array('label' => 'Second'), 102)
));
CountingFieldModel::$reads = 0;
$memo_first = $svc->build($memo_form);
$walk_reads = CountingFieldModel::$reads;
check('memo: first build walks every field', $walk_reads === 2 && isset($memo_first['first'], $memo_first['second']));

$memo_again = (new LlmFormSchemaService())->build($memo_form);
check('memo: unchanged tree is not walked again, even by another service instance', CountingFieldModel::$reads === $walk_reads);
check('memo: reused schema equals the first', $memo_again === $memo_first);

$memo_again['first']['label'] = 'tampered';
unset($memo_again['second']);
check('memo: a caller cannot change the stored schema', $svc->build($memo_form) === $memo_first && CountingFieldModel::$reads === $walk_reads);

// A child reload creates new component objects (here: same definitions).
$memo_form->tree = array(
    counted_field('first', array('label' => 'First'), 101),
    counted_field('second', array('label' => 'Second'), 102)
);
$memo_reloaded = $svc->build($memo_form);
check('memo: a reloaded tree is walked again', CountingFieldModel::$reads === $walk_reads + 2);
check('memo: same definitions give the same schema after a reload', $memo_reloaded === $memo_first);

$memo_form->tree = array(counted_field('third', array('label' => 'Third'), 103));
$memo_third = $svc->build($memo_form);
check('memo: a different tree gets its own schema', isset($memo_third['third']) && !isset($memo_third['first']));

$memo_form->tree = array();
check('memo: an emptied tree gives an empty schema', $svc->build($memo_form) === array());

// Same child objects, changed model settings: the settings are part of the key.
$memo_form->tree = array(
    counted_field('visible', array('label' => 'Visible'), 104),
    counted_field('secret', array('type_input' => 'hidden'), 105)
);
$memo_form->manual = true;
$memo_form->allowlist = array();
$settings_a = $svc->build($memo_form);
$memo_form->manual = false;
$settings_b = $svc->build($memo_form);
check(
    'memo: a changed manual-edit setting is not served from the memo',
    $settings_a['visible']['manual_writable'] === true && $settings_b['visible']['manual_writable'] === false
);
$memo_form->allowlist = array('secret');
$settings_c = $svc->build($memo_form);
check(
    'memo: a changed hidden-field allowlist is not served from the memo',
    $settings_b['secret']['llm_writable'] === false && $settings_c['secret']['llm_writable'] === true
);

$other_form = new ReloadableFormModel(array(counted_field('other_field', array(), 106)));
$other_schema = $svc->build($other_form);
check(
    'memo: models never share schemas',
    isset($other_schema['other_field']) && !isset($other_schema['visible']) && isset($svc->build($memo_form)['visible'])
);

$no_tree = new stdClass();
check('memo: a model without a child tree gives an empty schema', $svc->build($no_tree) === array());

$gone = new ReloadableFormModel(array(counted_field('gone', array(), 107)));
$svc->build($gone);
$gone_ref = WeakReference::create($gone);
unset($gone);
check('memo: a collected model is not kept alive by the memo', $gone_ref->get() === null);

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
