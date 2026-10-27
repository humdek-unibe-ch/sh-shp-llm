<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the URL parameters (`extra_params`) of the
 * llmFormLogList style (plugin v1.6.0): the core StyleModel contract as the
 * real LlmFormLogListModel uses it (value precedence, strict validation,
 * reserved interview query keys, `{{__name__}}` availability) and the pinned
 * fields derived from it.
 *
 * No bootstrap, no database: the real model class runs with a bypassed
 * constructor and a stub database/router.
 *
 * Run: php tests/LlmFormUrlParamsTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');
$core_server = dirname(LLM_PATH, 3);

// The core files end with stray newlines after their closing tags.
ob_start();
require_once $core_server . '/service/PageDb.php';
require_once $core_server . '/component/style/formField/FormFieldComponent.php';
require_once LLM_PATH . '/component/style/llmFormLogList/LlmFormLogListModel.php';
ob_end_clean();

// StyleModel::get_entry_value() reads the project name from the session.
$_SESSION = array('project' => 'test');

/* =========================================================================
 * Fakes
 * ========================================================================= */

class UrlParamsStubDb extends PageDb
{
    public function __construct() {}
    public function get_global_vars() { return array('@user' => 'tester'); }
    public function fetch_user_name() { return 'tester'; }
    public function get_user_code() { return 'code'; }
}

class UrlParamsStubRouter
{
    public function get_keyword_from_url() { return 'forest-woop'; }
}

class UrlParamsFieldView
{
    private $name;
    public function __construct($name) { $this->name = $name; }
    public function get_name_base() { return $this->name; }
}

/** Real FormFieldComponent (abstract in core) carrying just a field name. */
class UrlParamsFieldComponent extends FormFieldComponent
{
    public function __construct($name)
    {
        $this->view = new UrlParamsFieldView($name);
    }
}

/** StyleComponent stand-in: the wrapper around a field style instance. */
class UrlParamsChild
{
    private $style;
    public function __construct($style) { $this->style = $style; }
    public function get_style_instance() { return $this->style; }
    public function get_children() { return array(); }
}

/** The real model, minus constructor: only the state the URL params need. */
class UrlParamsModel extends LlmFormLogListModel
{
    public function __construct($declaration, array $params = array(), array $entry = array(), array $field_names = array())
    {
        $this->db = new UrlParamsStubDb();
        $this->router = new UrlParamsStubRouter();
        $this->params = $params;
        $this->entry_record = $entry;
        $this->children = array();
        foreach ($field_names as $name) {
            $this->children[] = new UrlParamsChild(new UrlParamsFieldComponent($name));
        }
        $this->accept_url_params(array(
            array('name' => 'extra_params', 'content' => $declaration, 'type' => 'text'),
        ));
    }

    public function params() { return $this->params; }
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

$decl = 'module_id=&list_status=active';

/* =========================================================================
 * Value precedence: route param > query string > default
 * ========================================================================= */

$_GET = array();
$m = new UrlParamsModel($decl);
check('defaults: absent names are declared and empty, defaults apply',
    $m->get_url_params() === array('module_id' => '', 'list_status' => 'active'));
$vars = $m->get_global_vars();
check('placeholders: {{__name__}} resolves in every field of the style',
    $vars['__module_id__'] === '' && $vars['__list_status__'] === 'active');

$_GET = array('module_id' => 'module_3', 'list_status' => 'archived');
check('query string overrides the defaults',
    (new UrlParamsModel($decl))->get_url_params() === array('module_id' => 'module_3', 'list_status' => 'archived'));
check('route param beats the query string',
    (new UrlParamsModel($decl, array('module_id' => 'route_1')))->get_url_params()['module_id'] === 'route_1');

$_GET = array();
$m = new UrlParamsModel('module_id=&amp;list_status=active');
check('CMS-escaped declaration (&amp;) is decoded',
    $m->get_url_params() === array('module_id' => '', 'list_status' => 'active'));

/* =========================================================================
 * Strict validation: values reach raw SQL filters and context keys
 * ========================================================================= */

$_GET = array('module_id' => "x' OR 1=1 --", 'list_status' => array('a'));
check('injection and array values fall back to the default',
    (new UrlParamsModel($decl))->get_url_params() === array('module_id' => '', 'list_status' => 'active'));
$_GET = array('module_id' => str_repeat('a', 65));
check('a value above 64 chars is ignored', (new UrlParamsModel($decl))->get_url_params()['module_id'] === '');
$_GET = array('module_id' => 'module 1');
check('a value with a space is ignored', (new UrlParamsModel($decl))->get_url_params()['module_id'] === '');
$_GET = array('module_id' => '{{__user__}}');
check('a placeholder in the URL is never interpolated', (new UrlParamsModel($decl))->get_url_params()['module_id'] === '');
$_GET = array('module_id' => '0');
check('zero is a value', (new UrlParamsModel($decl))->get_url_params()['module_id'] === '0');
$_GET = array('list_status' => '');
check('an empty query value counts as absent', (new UrlParamsModel($decl))->get_url_params()['list_status'] === 'active');

/* =========================================================================
 * Names: shape, duplicates, reserved (core + interview endpoint keys)
 * ========================================================================= */

$_GET = array();
check('invalid names are ignored',
    (new UrlParamsModel('1abc=x&bad-name=y&a b=z&good_1=ok'))->get_url_params() === array('good_1' => 'ok'));
check('duplicate names: the first declaration wins',
    (new UrlParamsModel('a=1&a=2'))->get_url_params() === array('a' => '1'));
$_GET = array('record' => '7', 'conversation_id' => '9', 'peek' => '1', 'action' => 'x');
$m = new UrlParamsModel(
    'record=a&sid=b&section_id=c&parent_id=d&relation=e&conversation_id=f&peek=g&revision=h&record_id=i&action=j&ok=1',
    array('parent_id' => 11)
);
check('reserved core and interview query keys are never accepted', $m->get_url_params() === array('ok' => '1'));
check('reserved params of the style stay untouched', $m->params()['parent_id'] === 11);

/* =========================================================================
 * Defaults with placeholders
 * ========================================================================= */

$_GET = array();
check('a default may use the entry record (loop row)',
    (new UrlParamsModel('module_id={{cat_module}}', array(), array('cat_module' => 'module_2')))->get_url_params()['module_id'] === 'module_2');
check('an unresolved placeholder never leaks into a value',
    (new UrlParamsModel('module_id={{nope}}', array(), array('x' => 'y')))->get_url_params()['module_id'] === '');
check('a default may use an earlier declared name',
    (new UrlParamsModel('a=x1&b={{__a__}}'))->get_url_params() === array('a' => 'x1', 'b' => 'x1'));

/* =========================================================================
 * Pinned fields: a declared param with a value whose name is also a field
 * ========================================================================= */

$_GET = array('module_id' => 'module_1');
$fields = array('module_id', 'plan');
check('pinned: the URL value of a declared param that is also a field',
    (new UrlParamsModel($decl, array(), array(), $fields))->getScopeBoundValues() === array('module_id' => 'module_1'));
$_GET = array();
check('pinned: an absent value pins nothing',
    (new UrlParamsModel($decl, array(), array(), $fields))->getScopeBoundValues() === array());
check('pinned: a param without a matching field pins nothing',
    (new UrlParamsModel('list_status=active', array(), array(), $fields))->getScopeBoundValues() === array());
check('pinned: a field without a declared param is not pinned',
    (new UrlParamsModel('', array(), array(), $fields))->getScopeBoundValues() === array());

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n" . $GLOBALS['__checks'] . ' checks, ' . count($GLOBALS['__fails']) . " failures\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
