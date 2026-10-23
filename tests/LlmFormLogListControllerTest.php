<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the llmFormLogList controller guard (plugin
 * v1.6.0): a plain __form_name post for this style is a bypass attempt
 * unless it is a real delete or a real quick action, and the guard must
 * read the record ids exactly like the core controller does.
 *
 * No bootstrap, no database: the real LlmFormLogListController runs its
 * real constructor routing against a stub model. execute() (the inherited
 * core write path) and sendJsonResponse() (which exits) are replaced by a
 * spy, so every case reports whether the post was rejected by the guard
 * or handed on to the core.
 *
 * Run: php tests/LlmFormLogListControllerTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');

// Core constants (server/service/globals.php).
foreach (array(
    'ENTRY_RECORD_ID' => 'record_id',
    'SELECTED_RECORD_ID' => 'selected_record_id',
    'DELETE_RECORD_ID' => 'delete_record_id',
    'QUICK_ACTION_ID' => 'quick_action_id',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

// The core files end with stray newlines after their closing tags.
ob_start();
require_once LLM_PATH . '/component/style/llmFormLogList/LlmFormLogListController.php';
ob_end_clean();

/* =========================================================================
 * Fakes
 * ========================================================================= */

class GuardFakeRouter
{
    public function log_user_activity() {}
}

class GuardFakeServices
{
    public function get_router() { return new GuardFakeRouter(); }
}

/** Only what the controller constructor and the guard read from the model. */
class GuardFakeModel
{
    public function get_section_id() { return 42; }
    public function get_db_field($key, $default = '') { return $key === 'name' ? 'interview_form' : $default; }
    public function get_services() { return new GuardFakeServices(); }
}

/** Raised instead of the JSON response + exit of the real controller. */
class GuardRejected extends Exception
{
    public $data;
    public $status;

    public function __construct($data, $status)
    {
        parent::__construct('rejected');
        $this->data = $data;
        $this->status = $status;
    }
}

class GuardSpyController extends LlmFormLogListController
{
    public $executed = false;

    /** The inherited core write path: reaching it means the guard let the post through. */
    public function execute()
    {
        $this->executed = true;
    }

    protected function sendJsonResponse($data, $status_code = 200)
    {
        throw new GuardRejected($data, $status_code);
    }

    /** The core controller's own id reading (protected in FormUserInputController). */
    public function coreId($value)
    {
        return $this->normalize_post_id($value);
    }
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
 * Run the real constructor routing for the given request.
 *
 * @return array {outcome: rejected|core, status, error, controller}
 */
function run_controller(array $post, array $get = array())
{
    $_POST = $post;
    $_GET = $get;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    try {
        $controller = new GuardSpyController(new GuardFakeModel());
    } catch (GuardRejected $e) {
        return array('outcome' => 'rejected', 'status' => $e->status, 'error' => $e->data['error'], 'controller' => null);
    }
    return array(
        'outcome' => $controller->executed ? 'core' : 'none',
        'status' => null,
        'error' => null,
        'controller' => $controller,
    );
}

/** A post addressed to this form (the guard only looks at those). */
function form_post(array $fields = array())
{
    return array_merge(array('__form_name' => 'interview_form'), $fields);
}

function expect_rejected($label, array $post, array $get = array())
{
    $r = run_controller($post, $get);
    check(
        "$label: rejected as bad_request (400)",
        $r['outcome'] === 'rejected'
            && $r['status'] === 400
            && $r['error']['type'] === 'bad_request'
            && $r['error']['message'] === 'This form is saved through the interview approval'
    );
}

function expect_core($label, array $post, array $get = array())
{
    $r = run_controller($post, $get);
    check("$label: handed to the core", $r['outcome'] === 'core');
}

/* =========================================================================
 * delete_record_id: the reported bypass
 * ========================================================================= */

// Wrapped ids the core parses to a non-positive number must not count as
// a delete (a bare (int) cast of the wrapper array is 1).
expect_rejected('delete_record_id[value]=0', form_post(array(DELETE_RECORD_ID => array('value' => '0'))));
expect_rejected('delete_record_id[value]=abc', form_post(array(DELETE_RECORD_ID => array('value' => 'abc'))));
expect_rejected('delete_record_id[value]=-5', form_post(array(DELETE_RECORD_ID => array('value' => '-5'))));
expect_rejected('delete_record_id[]', form_post(array(DELETE_RECORD_ID => array())));
expect_rejected('delete_record_id[value]=""', form_post(array(DELETE_RECORD_ID => array('value' => ''))));

// Scalars and the missing key.
expect_rejected('delete_record_id=0', form_post(array(DELETE_RECORD_ID => '0')));
expect_rejected('delete_record_id=abc', form_post(array(DELETE_RECORD_ID => 'abc')));
expect_rejected('delete_record_id=-5', form_post(array(DELETE_RECORD_ID => '-5')));
expect_rejected('no delete key (plain save)', form_post(array('title' => 'x')));

// Real deletes stay with the core, which authorizes them.
expect_core('delete_record_id=7', form_post(array(DELETE_RECORD_ID => '7')));
expect_core('delete_record_id[value]=7', form_post(array(DELETE_RECORD_ID => array('value' => '7'))));
expect_core('delete_record_id=7 (int)', form_post(array(DELETE_RECORD_ID => 7)));

/* =========================================================================
 * Quick actions: positive record + non-empty action id
 * ========================================================================= */

expect_core('quick action on record 3', form_post(array(QUICK_ACTION_ID => 'abc123', SELECTED_RECORD_ID => '3')));
expect_core('quick action on record [value]=3', form_post(array(
    QUICK_ACTION_ID => array('value' => 'abc123'), SELECTED_RECORD_ID => array('value' => '3'),
)));
expect_rejected('quick action on record [value]=0', form_post(array(QUICK_ACTION_ID => 'abc123', SELECTED_RECORD_ID => array('value' => '0'))));
expect_rejected('quick action on record [value]=abc', form_post(array(QUICK_ACTION_ID => 'abc123', SELECTED_RECORD_ID => array('value' => 'abc'))));
expect_rejected('quick action on record [value]=-5', form_post(array(QUICK_ACTION_ID => 'abc123', SELECTED_RECORD_ID => array('value' => '-5'))));
expect_rejected('quick action on record 0', form_post(array(QUICK_ACTION_ID => 'abc123', SELECTED_RECORD_ID => '0')));
expect_rejected('quick action without a record', form_post(array(QUICK_ACTION_ID => 'abc123')));
expect_rejected('record without a quick action', form_post(array(SELECTED_RECORD_ID => '3')));
expect_rejected('blank quick action on record 3', form_post(array(QUICK_ACTION_ID => '  ', SELECTED_RECORD_ID => '3')));

/* =========================================================================
 * Parity with the core id reading
 * ========================================================================= */

$samples = array(
    '0', '7', 'abc', '-5', '', ' 8', '1e3', 7, 0,
    array(), array('x'),
    array('value' => '0'), array('value' => '7'), array('value' => 'abc'),
    array('value' => '-5'), array('value' => ''), array('value' => null), array('value' => array('1')),
);
$core = run_controller(array())['controller'];
check('parity: harness reached the core on an empty post', $core instanceof GuardSpyController);
foreach ($samples as $raw) {
    $expected = $core->coreId($raw) > 0 ? 'core' : 'rejected';
    $shown = json_encode($raw);
    $delete = run_controller(form_post(array(DELETE_RECORD_ID => $raw)));
    check("parity: delete_record_id=$shown is $expected like the core", $delete['outcome'] === $expected);
    $quick = run_controller(form_post(array(QUICK_ACTION_ID => 'abc123', SELECTED_RECORD_ID => $raw)));
    check("parity: quick action on selected_record_id=$shown is $expected like the core", $quick['outcome'] === $expected);
}

/* =========================================================================
 * Requests the guard does not own
 * ========================================================================= */

expect_core('empty post', array());
expect_core('post without __form_name', array('title' => 'x'));
expect_core('post for another form', array('__form_name' => 'other_form', 'title' => 'x'));

// A marker addressed to another section never exempts a crafted save ...
expect_rejected('foreign-section marker + crafted save', form_post(array(
    '__llm_interview_action' => 'turn', 'section_id' => '99',
)));
expect_rejected('malformed section marker + crafted save', form_post(array(
    '__llm_interview_action' => 'turn', 'section_id' => '42abc',
)));
// ... and without a form post it is left to the core untouched.
expect_core('foreign-section marker without a form post', array(
    '__llm_interview_action' => 'turn', 'section_id' => '99',
));

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
