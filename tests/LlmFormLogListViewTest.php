<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the llmFormLogList view's native field rendering
 * (plugin v1.6.0): the interview service renders one fragment per native
 * field in every response, so the view must not repeat tree-wide work
 * (schema walk, input flags, value propagation) for each of them.
 *
 * No bootstrap, no database: the real LlmFormLogListView runs against a
 * stub model whose children are fake components built on the real
 * FormFieldModel/FormFieldComponent classes.
 *
 * Run: php tests/LlmFormLogListViewTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');

// The core files end with stray newlines after their closing tags.
ob_start();
require_once LLM_PATH . '/component/style/llmFormLogList/LlmFormLogListView.php';
ob_end_clean();

/* =========================================================================
 * Fakes
 * ========================================================================= */

/** Real FormFieldModel with a bypassed constructor; counts schema reads. */
class ViewFakeFieldModel extends FormFieldModel
{
    public static $reads = 0;
    private $fields;

    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    public function get_db_field($key, $default = "")
    {
        return array_key_exists($key, $this->fields) ? $this->fields[$key] : $default;
    }

    public function get_db_field_full($key)
    {
        return array_key_exists($key, $this->fields) ? array('content' => $this->fields[$key]) : '';
    }

    public function get_style_name() { return 'input'; }

    public function get_condition_result()
    {
        self::$reads++;
        return array('result' => true);
    }

    public function get_children() { return array(); }
    public function get_form_field_value() { return null; }
}

class ViewFakeFieldView
{
    public $value = null;
    public $sets = 0;
    private $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function get_name_base() { return $this->name; }

    public function set_value($value)
    {
        $this->value = $value;
        $this->sets++;
    }
}

/** Real FormFieldComponent (abstract in core) with counted input flags. */
class ViewFakeFieldComponent extends FormFieldComponent
{
    public $user_input_calls = 0;

    public function __construct($name, $model)
    {
        $this->model = $model;
        $this->view = new ViewFakeFieldView($name);
    }

    public function enable_user_input()
    {
        $this->user_input_calls++;
    }
}

/** StyleComponent stand-in: the wrapper around a field style instance. */
class ViewFakeChild
{
    public $style;

    public function __construct($style)
    {
        $this->style = $style;
    }

    public function get_style_instance() { return $this->style; }
    public function get_children() { return array(); }
    public function get_model() { return $this->style->get_model(); }
    public function get_id_section() { return 0; }
    public function has_access() { return true; }

    /**
     * Raw echo of the view value, which is then consumed - like the core
     * input views, which overwrite their value while rendering.
     */
    public function output_content()
    {
        $view = $this->style->get_view();
        echo '<input name="' . $view->get_name_base() . '" value="' . $view->value . '">';
        $view->value = 'consumed';
    }
}

class ViewFakeFormModel
{
    public $tree = array();
    public $entry = array();

    public function get_children() { return $this->tree; }
    public function get_entry_record() { return $this->entry; }
    public function getInterviewWritableHiddenFields() { return array(); }
    public function isInterviewAllowManualEdit() { return true; }
    public function getScopeBoundValues() { return array(); }
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

/**
 * Fresh child components for the five fields below (a reload of the
 * model's children creates new objects like this).
 *
 * @return array name => ViewFakeChild
 */
function build_tree()
{
    $types = array(
        'alpha' => 'text', 'beta' => 'text', 'gamma' => 'text',
        'color_pick' => 'color',   // unsupported control: native only
        'secret' => 'hidden',      // hidden: never rendered
    );
    $tree = array();
    foreach ($types as $name => $type) {
        $model = new ViewFakeFieldModel(array('name' => $name, 'type_input' => $type, 'label' => $name));
        $tree[$name] = new ViewFakeChild(new ViewFakeFieldComponent($name, $model));
    }
    return $tree;
}

function view_for($model)
{
    $view = (new ReflectionClass('LlmFormLogListView'))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty('BaseView', 'model');
    $property->setAccessible(true);
    $property->setValue($view, $model);
    return $view;
}

function user_input_calls(array $tree)
{
    $calls = array();
    foreach ($tree as $name => $child) {
        $calls[$name] = $child->style->user_input_calls;
    }
    return $calls;
}

function value_sets(array $tree)
{
    $sets = array();
    foreach ($tree as $name => $child) {
        $sets[$name] = $child->style->get_view()->sets;
    }
    return $sets;
}

/* =========================================================================
 * Per-field rendering
 * ========================================================================= */

$model = new ViewFakeFormModel();
$model->tree = build_tree();
$model->entry = array('alpha' => '<b>"x"</b>', 'beta' => 'plain', 'color_pick' => '#ff0000');
$view = view_for($model);
$field_count = count($model->tree);

ViewFakeFieldModel::$reads = 0;
$alpha = $view->renderNativeFieldFragment('alpha');
check(
    'alpha: wrapped in its data-field-name container',
    strpos($alpha, '<div class="llm-native-field" data-field-name="alpha">') === 0
);
check(
    'alpha: raw-echo input value is context-escaped',
    strpos($alpha, 'value="&lt;b&gt;&quot;x&quot;&lt;/b&gt;"') !== false
);
check(
    'alpha: only the rendered field was prepared',
    user_input_calls($model->tree) === array('alpha' => 1, 'beta' => 0, 'gamma' => 0, 'color_pick' => 0, 'secret' => 0)
        && value_sets($model->tree) === array('alpha' => 1, 'beta' => 0, 'gamma' => 0, 'color_pick' => 0, 'secret' => 0)
);

$beta = $view->renderNativeFieldFragment('beta');
$gamma = $view->renderNativeFieldFragment('gamma');
$color = $view->renderNativeFieldFragment('color_pick');
check('beta: value propagated from the entry', strpos($beta, 'value="plain"') !== false);
check('gamma: no entry value leaves the view value untouched', strpos($gamma, 'value=""') !== false);
check('color_pick: native-only control is rendered', strpos($color, 'data-field-name="color_pick"') !== false);
check('hidden field is not rendered', $view->renderNativeFieldFragment('secret') === '');
check('unknown field is not rendered', $view->renderNativeFieldFragment('nope') === '' && $view->renderNativeFieldFragment('') === '');

check(
    'each rendered field was prepared once, nothing else',
    user_input_calls($model->tree) === array('alpha' => 1, 'beta' => 1, 'gamma' => 1, 'color_pick' => 1, 'secret' => 0)
        && value_sets($model->tree) === array('alpha' => 1, 'beta' => 1, 'gamma' => 0, 'color_pick' => 1, 'secret' => 0)
);
check(
    'a response of native fields walks the form once, not once per field',
    ViewFakeFieldModel::$reads === $field_count
);

// Rendering consumes the view value; a repeated render must still match.
check(
    'a repeated render of the same field is identical',
    $view->renderNativeFieldFragment('alpha') === $alpha
);
check('the repeat did not walk the form again', ViewFakeFieldModel::$reads === $field_count);

/* =========================================================================
 * Reloaded children, full render
 * ========================================================================= */

$old_tree = $model->tree;
$model->tree = build_tree();
$reloaded = $view->renderNativeFieldFragment('alpha');
check('reloaded children: rendered from the new components', $reloaded === $alpha
    && $model->tree['alpha']->style->user_input_calls === 1
    && $old_tree['alpha']->style->user_input_calls === 2);
check('reloaded children: the form is walked once more', ViewFakeFieldModel::$reads === 2 * $field_count);

$controls = new ReflectionMethod('LlmFormLogListView', 'renderNativeControlsFragment');
$controls->setAccessible(true);
$full = $controls->invoke($view);
preg_match_all('/data-field-name="([^"]+)"/', $full, $names);
check(
    'full render: every renderable native field once, in tree order',
    $names[1] === array('alpha', 'beta', 'gamma', 'color_pick')
);
check('full render: reuses the walk of the unchanged tree', ViewFakeFieldModel::$reads === 2 * $field_count);

$model->tree = array();
check('empty tree: nothing rendered', $controls->invoke($view) === '' && $view->renderNativeFieldFragment('alpha') === '');

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
