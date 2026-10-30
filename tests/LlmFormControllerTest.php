<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmFormController (llmFormRecord / llmFormLog, plugin
 * v1.6.0):
 *
 *  - every LLM form on a page builds a controller for the same request, so a
 *    controller must act only on requests addressed to its own section
 *    (`section_id` for the regenerate/retry/generate_feedback actions,
 *    `__form_name` for a submit);
 *  - guests share one account and are rejected before anything is saved;
 *  - LLM calls are rate limited;
 *  - participants never see provider/internal error text;
 *  - an explicitly requested record id is loaded exactly or not at all (no
 *    silent fallback to the latest record of the user).
 *
 * No bootstrap, no database: the real controller file is copied into a
 * throw-away tree that mirrors its relative require_once targets, where small
 * stand-ins replace the core FormUserInputController, the JSON trait (which
 * exits), the prompt loader and LlmService.
 *
 * Run: php tests/LlmFormControllerTest.php
 */

foreach (array(
    'ENTRY_RECORD_ID' => 'record_id',
    'SELECTED_RECORD_ID' => 'selected_record_id',
    'DELETE_RECORD_ID' => 'delete_record_id',
    'TRANSACTION_BY_LLM_FORM' => 'by_llm_form',
    'GUEST_USER_ID' => 1,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

/* =========================================================================
 * Sandbox tree: the real controller + stand-ins for what it requires
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_form_controller_test_' . getmypid();
$plugin_server = $root . '/server/plugins/sh-shp-llm/server';

function sandbox_write($path, $contents)
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function sandbox_remove($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? sandbox_remove($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(function () use ($root) {
    sandbox_remove($root);
});

// The real controller, byte for byte
sandbox_write(
    $plugin_server . '/component/style/llmFormRecord/LlmFormController.php',
    file_get_contents(dirname(__DIR__) . '/server/component/style/llmFormRecord/LlmFormController.php')
);

// The real CSRF service the controller gates its LLM actions with
sandbox_write(
    $plugin_server . '/service/LlmCsrfService.php',
    file_get_contents(dirname(__DIR__) . '/server/service/LlmCsrfService.php')
);

// The reporter the controller writes its failures through
sandbox_write(
    $plugin_server . '/service/LlmErrorReporter.php',
    file_get_contents(dirname(__DIR__) . '/server/service/LlmErrorReporter.php')
);

// Core controller: the same early exits as FormUserInputController::execute()
sandbox_write($root . '/server/component/style/formUserInput/FormUserInputController.php', <<<'PHP'
<?php
class FormUserInputController
{
    public $success = false;
    public $fail = false;
    public $error_msgs = array();
    protected $model;

    public function __construct($model)
    {
        $this->model = $model;
        $GLOBALS['T']['events'][] = 'core_execute';
        if (!isset($_POST['__form_name']) || $_POST['__form_name'] !== $model->get_db_field('name')) {
            return;
        }
        unset($_POST['__form_name']);
        $this->success = true;
    }

    protected function normalize_post_id($value)
    {
        if (is_array($value) && isset($value['value'])) {
            return intval($value['value']);
        }
        return intval($value);
    }
}
PHP
);

// JSON trait: the real one exits, this one throws what it would have sent
sandbox_write($plugin_server . '/component/LlmJsonResponseTrait.php', <<<'PHP'
<?php
class TestJsonSent extends Exception
{
    public $data;
    public $status;

    public function __construct($data, $status)
    {
        parent::__construct('json sent');
        $this->data = $data;
        $this->status = $status;
    }
}

trait LlmJsonResponseTrait
{
    protected function sendJsonResponse($data, $status_code = 200)
    {
        throw new TestJsonSent($data, $status_code);
    }
}
PHP
);

sandbox_write($plugin_server . '/service/prompt/LlmPromptAssetLoader.php', <<<'PHP'
<?php
class LlmPromptAssetLoader
{
    public function load($key)
    {
        return 'Answer in {{language_code}}.';
    }
}
PHP
);

sandbox_write($plugin_server . '/service/LlmService.php', <<<'PHP'
<?php
class LlmRateLimitException extends Exception
{
}

class LlmService
{
    public function __construct($services)
    {
    }

    public function checkRateLimit($user_id)
    {
        $GLOBALS['T']['events'][] = 'rate_check';
        if (!empty($GLOBALS['T']['rate_limited'])) {
            throw new LlmRateLimitException('Rate limit exceeded: 10 requests per minute');
        }
        return array('requests' => 0);
    }

    public function updateRateLimit($user_id, $rate_data = null, $conversation_id = null)
    {
        $GLOBALS['T']['events'][] = 'rate_update';
    }

    public function getOrCreateConversationForModel($user_id, $model, $temperature, $max_tokens, $section_id)
    {
        return 77;
    }

    public function addMessage()
    {
        return 1;
    }

    public function callLlmApi($messages, $model, $temperature, $max_tokens, $options = array())
    {
        $GLOBALS['T']['events'][] = 'llm_call';
        $GLOBALS['T']['messages'] = $messages;
        if (!empty($GLOBALS['T']['api_error'])) {
            throw new RuntimeException($GLOBALS['T']['api_error']);
        }
        return array('content' => 'Great answer', 'usage' => array('total_tokens' => 5));
    }
}
PHP
);

// Danger detection: keyword hits are configured per test run; the real service is tested on its own
sandbox_write($plugin_server . '/service/LlmDangerDetectionService.php', <<<'PHP'
<?php
class LlmDangerDetectionService
{
    public function __construct($services, $model)
    {
    }

    public function detectKeywords($message)
    {
        $GLOBALS['T']['scanned'][] = $message;
        $found = array();
        foreach (array('kill myself', 'töten') as $keyword) {
            if (mb_strpos(mb_strtolower($message, 'UTF-8'), $keyword) !== false) {
                $found[] = $keyword;
            }
        }
        return $found;
    }

    public function handleDetection($detected, $message, $user_id, $conversation_id = null)
    {
        $GLOBALS['T']['events'][] = 'danger_handled';
        $GLOBALS['T']['danger'] = array('detected' => $detected, 'user_id' => $user_id, 'conversation_id' => $conversation_id);
        return array('safe' => false, 'message' => 'Please talk to someone you trust.', 'detected_keywords' => $detected,
            'notification_sent' => true, 'conversation_blocked' => false);
    }

    public function getCriticalSafetyContext()
    {
        return '[CRITICAL SAFETY INSTRUCTION] kill myself';
    }
}
PHP
);

ob_start();
require_once $plugin_server . '/component/style/llmFormRecord/LlmFormController.php';
ob_end_clean();

/* =========================================================================
 * Fakes
 * ========================================================================= */

/** Records: 7 and 9 belong to user 42, 8 belongs to somebody else. */
class TUserInput
{
    private $records = array(
        7 => array('record_id' => 7, 'id_users' => 42, 'name' => 'Eve'),
        8 => array('record_id' => 8, 'id_users' => 99, 'name' => 'Mallory'),
        9 => array('record_id' => 9, 'id_users' => 42, 'name' => 'Latest'),
    );

    public function get_dataTable_id($table)
    {
        return 5;
    }

    public function get_data($form_id, $filter, $own_entries_only, $user_id, $first)
    {
        $GLOBALS['T']['events'][] = 'get_data:' . trim($filter);
        if (preg_match('/record_id = (\d+)/', $filter, $m)) {
            $record = isset($this->records[(int)$m[1]]) ? $this->records[(int)$m[1]] : null;
            return ($record && $record['id_users'] == $user_id) ? $record : array();
        }
        $latest = array();
        foreach ($this->records as $record) {
            if ($record['id_users'] == $user_id) {
                $latest = $record;
            }
        }
        return $latest;
    }

    public function save_data($by, $table, $data, $update_based_on)
    {
        $GLOBALS['T']['saved'][] = array('table' => $table, 'data' => $data, 'based_on' => $update_based_on);
    }
}

class TServices
{
    public function get_user_input()
    {
        return $GLOBALS['T']['user_input'];
    }
}

class TModel
{
    public function get_section_id() { return 12; }
    public function get_db_field($key, $default = '') { return $key === 'name' ? 'my_form' : $default; }
    public function get_services() { return new TServices(); }
    public function isLlmEnabled() { return true; }
    public function isManualFeedbackEnabled() { return !empty($GLOBALS['T']['manual']); }
    public function getLlmContext() { return 'Context for {{name}}'; }
    public function extractContextFieldKeys() { return array('name'); }
    public function getUserLanguage() { return ''; }
    public function useUserLanguage() { return false; }
    public function getLlmModel() { return 'test-model'; }
    public function getLlmTemperature() { return 1; }
    public function getLlmMaxTokens() { return 100; }
    public function getLlmReasoningEffort() { return null; }
    public function getLlmResultFieldName() { return 'llm_result'; }
    public function getLlmResultMetaFieldName() { return 'llm_result_meta'; }
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
 * Build the real controller for one request.
 *
 * @param array $post
 * @param int|null $user_id Session user (null = no session user)
 * @param array $flags rate_limited, api_error, manual; site (Sec-Fetch-Site),
 *  origin (Origin) and session_token (the session's CSRF token) describe a browser request
 * @return array {json, status, events, saved}; json is null when no response was sent
 */
function run_controller($post, $user_id, $flags = array())
{
    $GLOBALS['T'] = array(
        'events' => array(),
        'saved' => array(),
        'rate_limited' => !empty($flags['rate_limited']),
        'api_error' => isset($flags['api_error']) ? $flags['api_error'] : null,
        'manual' => !empty($flags['manual']),
        'user_input' => new TUserInput(),
        'scanned' => array(),
        'messages' => array(),
        'danger' => null,
    );
    $_POST = $post;
    $_SESSION = $user_id === null ? array() : array('id_user' => $user_id);
    if (isset($flags['session_token'])) {
        $_SESSION['csrf_token'] = $flags['session_token'];
    }
    unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    if (isset($flags['site'])) {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $flags['site'];
    }
    if (isset($flags['origin'])) {
        $_SERVER['HTTP_ORIGIN'] = $flags['origin'];
    }

    $json = null;
    $status = null;
    try {
        new LlmFormController(new TModel());
    } catch (TestJsonSent $e) {
        $json = $e->data;
        $status = $e->status;
    }
    return array(
        'json' => $json,
        'status' => $status,
        'events' => $GLOBALS['T']['events'],
        'saved' => $GLOBALS['T']['saved'],
        'messages' => $GLOBALS['T']['messages'],
        'danger' => $GLOBALS['T']['danger'],
    );
}

function had($result, $event)
{
    return in_array($event, $result['events'], true);
}

/* =========================================================================
 * Requests addressed to another section are not answered
 * ========================================================================= */

echo "== Section addressing ==\n";

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '999', '__record_id' => '7'), 42);
check('action for another section: no response sent', $r['json'] === null);
check('action for another section: no LLM call, no rate-limit use', !had($r, 'llm_call') && !had($r, 'rate_check'));
check('action for another section: handed on to the core controller', had($r, 'core_execute'));

$r = run_controller(array('__llm_action' => 'regenerate', '__record_id' => '7'), 42);
check('action without section_id: not answered', $r['json'] === null && !had($r, 'llm_call'));

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'some_other_form', 'name' => 'Eve'), 42);
check('submit of another form: no response sent', $r['json'] === null);
check('submit of another form: no LLM call', !had($r, 'llm_call') && !had($r, 'rate_check'));

$r = run_controller(array('__form_name' => 'my_form', 'name' => 'Eve'), 42);
check('plain submit without __llm_form: left to the core controller', $r['json'] === null && had($r, 'core_execute') && !had($r, 'llm_call'));

/* =========================================================================
 * Guests
 * ========================================================================= */

echo "\n== Guests ==\n";

foreach (array('regenerate', 'retry') as $action) {
    $r = run_controller(array('__llm_action' => $action, 'section_id' => '12', '__record_id' => '7'), GUEST_USER_ID);
    check("$action as guest: 401", $r['status'] === 401 && $r['json']['success'] === false);
    check("$action as guest: nothing loaded, rated or called", !had($r, 'llm_call') && !had($r, 'rate_check') && count($r['events']) === 0);
}

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12'), null);
check('regenerate without any session user: 401', $r['status'] === 401);

$r = run_controller(array('__llm_action' => 'generate_feedback', 'section_id' => '12', 'name' => 'Eve'), GUEST_USER_ID, array('manual' => true));
check('generate_feedback as guest: 401, no LLM call', $r['status'] === 401 && !had($r, 'llm_call'));

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'Eve'), GUEST_USER_ID);
check('submit as guest: 401', $r['status'] === 401 && $r['json']['success'] === false);
check('submit as guest: the core controller never saved anything', !had($r, 'core_execute'));
check('submit as guest: no LLM call', !had($r, 'llm_call') && !had($r, 'rate_check'));

/* =========================================================================
 * Record selection
 * ========================================================================= */

echo "\n== Record selection ==\n";

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7'), 42);
check('regenerate with record 7: 200 + success', $r['status'] === 200 && $r['json']['success'] === true);
check('regenerate with record 7: that record only was loaded (no latest fallback query)', had($r, 'get_data:AND record_id = 7') && !had($r, 'get_data:ORDER BY record_id DESC'));
check('regenerate with record 7: result saved into record 7', count($r['saved']) === 1 && $r['saved'][0]['based_on'] === array('record_id' => 7));
check('regenerate with record 7: LLM text saved', $r['saved'][0]['data']['llm_result'] === 'Great answer');
check('regenerate with record 7: record id echoed in the meta', $r['json']['llm_meta']['record_id'] === 7);
check('regenerate with record 7: rate limit checked before and counted around the call',
    array_search('rate_check', $r['events']) < array_search('rate_update', $r['events'])
    && array_search('rate_update', $r['events']) < array_search('llm_call', $r['events']));

$r = run_controller(array('__llm_action' => 'retry', 'section_id' => '12', '__record_id' => array('value' => '7')), 42);
check('record id posted as {value: 7} is read as 7', had($r, 'get_data:AND record_id = 7') && $r['saved'][0]['based_on'] === array('record_id' => 7));

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '12345'), 42);
check('unknown record id: 404', $r['status'] === 404 && $r['json']['success'] === false);
check('unknown record id: no fallback to the latest record, no LLM call, nothing saved',
    !had($r, 'get_data:ORDER BY record_id DESC') && !had($r, 'llm_call') && count($r['saved']) === 0);

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '8'), 42);
check("somebody else's record id: 404, nothing called or saved", $r['status'] === 404 && !had($r, 'llm_call') && count($r['saved']) === 0);

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7 OR 1=1'), 42);
check('record id with SQL text is reduced to its integer', had($r, 'get_data:AND record_id = 7') && !had($r, 'get_data:AND record_id = 7 OR 1=1'));

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12'), 42);
check('regenerate without a record id uses the latest record of the user', had($r, 'get_data:ORDER BY record_id DESC') && $r['saved'][0]['based_on'] === array('record_id' => 9));

/* =========================================================================
 * Danger detection
 * ========================================================================= */

echo "
== Danger detection ==
";

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'I want to kill myself'), 42);
check('danger_word_in_user_prompt_skips_llm: no LLM call', !had($r, 'llm_call'));
check('danger_word_in_user_prompt_skips_llm: blocked message returned as the feedback',
    $r['status'] === 200 && $r['json']['success'] === true && $r['json']['llm_result'] === 'Please talk to someone you trust.');
check('danger_word_in_user_prompt_skips_llm: meta status safety_blocked with the keywords',
    $r['json']['llm_meta']['status'] === 'safety_blocked' && $r['json']['llm_meta']['detected_keywords'] === array('kill myself'));
check('danger_word_in_user_prompt_skips_llm: detection handled (audit + email) for the acting user',
    had($r, 'danger_handled') && $r['danger']['user_id'] === 42);
check('danger_word_in_user_prompt_skips_llm: the form conversation is not blocked (no conversation id passed)',
    $r['danger']['conversation_id'] === null);

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => array('Ich will TÖTEN')), 42);
check('danger_word_in_interpolated_context_detected: an array value (only in the context) is scanned unescaped',
    !had($r, 'llm_call') && $r['json']['llm_meta']['status'] === 'safety_blocked');

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7', 'name' => 'Eve'), 42);
check('safe_submission_has_safety_block: the LLM is called', had($r, 'llm_call') && !had($r, 'danger_handled'));
check('safe_submission_has_safety_block: the system prompt carries the critical safety instruction',
    isset($r['messages'][0]) && $r['messages'][0]['role'] === 'system'
    && strpos($r['messages'][0]['content'], 'CRITICAL SAFETY INSTRUCTION') !== false
    && strpos($r['messages'][0]['content'], 'Context for Eve') === 0);

/* =========================================================================
 * Rate limit and error text
 * ========================================================================= */

echo "\n== Rate limit and errors ==\n";

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7'), 42, array('rate_limited' => true));
check('rate limited: no success, the rate-limit text reaches the participant', $r['json']['success'] === false && $r['json']['error'] === 'Rate limit exceeded: 10 requests per minute');
check('rate limited: the LLM is not called and nothing is saved', !had($r, 'llm_call') && count($r['saved']) === 0);

$secret = '401 Unauthorized: invalid api key sk-SECRET-123 at /var/www/private/provider.php:88';
$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7'), 42, array('api_error' => $secret));
$encoded = json_encode($r['json']);
check('provider failure: no success, nothing saved', $r['json']['success'] === false && count($r['saved']) === 0);
check('provider failure: generic participant text', $r['json']['error'] === 'The assistant could not generate a response. Please try again.');
check('provider failure: no key or path anywhere in the response', strpos($encoded, 'sk-SECRET') === false && strpos($encoded, '/var/www') === false);
check('provider failure: the meta carries the same generic text', $r['json']['llm_meta']['error'] === $r['json']['error']);

/* =========================================================================
 * Submit and manual feedback
 * ========================================================================= */

echo "\n== Submit and manual feedback ==\n";

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'Eve'), 42);
check('submit as user: the core controller saved the form first', had($r, 'core_execute'));
check('submit as user: LLM answered and stored on the latest record', $r['status'] === 200 && $r['json']['success'] === true && $r['saved'][0]['based_on'] === array('record_id' => 9));

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'selected_record_id' => '7', 'name' => 'Eve'), 42);
check('submit editing record 7: result stored on record 7', $r['saved'][0]['based_on'] === array('record_id' => 7));

$r = run_controller(array('__llm_action' => 'generate_feedback', 'section_id' => '12', 'name' => 'Eve', 'selected_record_id' => array('value' => '7')), 42, array('manual' => true));
check('generate_feedback with record 7: success, stored on record 7', $r['json']['success'] === true && $r['saved'][0]['based_on'] === array('record_id' => 7));

$r = run_controller(array('__llm_action' => 'generate_feedback', 'section_id' => '12', 'name' => 'Eve'), 42, array('manual' => false));
check('generate_feedback while manual mode is off: 400', $r['status'] === 400 && !had($r, 'llm_call'));

$r = run_controller(array('__llm_action' => 'generate_feedback', 'section_id' => '999', 'name' => 'Eve'), 42, array('manual' => true));
check('generate_feedback for another section: not answered', $r['json'] === null && !had($r, 'llm_call'));

/* =========================================================================
 * CSRF: another website cannot trigger the LLM actions
 * ========================================================================= */

echo "\n== CSRF ==\n";

$evil = array('site' => 'cross-site', 'origin' => 'https://evil.example', 'session_token' => 'tok');

foreach (array('regenerate', 'retry') as $action) {
    $r = run_controller(array('__llm_action' => $action, 'section_id' => '12', '__record_id' => '7'), 42, $evil);
    check("$action from another site: 403", $r['status'] === 403 && $r['json']['success'] === false);
    check("$action from another site: nothing loaded, rated, called or saved", count($r['events']) === 0 && count($r['saved']) === 0);
}

$r = run_controller(array('__llm_action' => 'generate_feedback', 'section_id' => '12', 'name' => 'Eve'), 42, $evil + array('manual' => true));
check('generate_feedback from another site: 403, no LLM call', $r['status'] === 403 && !had($r, 'llm_call') && count($r['saved']) === 0);

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'Eve'), 42, $evil);
check('LLM submit from another site: 403', $r['status'] === 403 && $r['json']['success'] === false);
check('LLM submit from another site: refused before the core controller saved anything', !had($r, 'core_execute') && count($r['saved']) === 0);
check('LLM submit from another site: no LLM call, no rate-limit use', !had($r, 'llm_call') && !had($r, 'rate_check'));

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7', 'csrf_token' => 'wrong'), 42, $evil);
check('a wrong token from another site is refused', $r['status'] === 403 && !had($r, 'llm_call'));

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7', 'csrf_token' => 'tok'), 42, $evil);
check('regenerate from another site with the session token: allowed', $r['status'] === 200 && $r['json']['success'] === true && had($r, 'llm_call'));

$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'Eve', 'csrf_token' => 'tok'), 42, $evil);
check('LLM submit with the session token: allowed', $r['status'] === 200 && $r['json']['success'] === true && had($r, 'core_execute'));

$same = array('site' => 'same-origin', 'origin' => 'https://site.example', 'session_token' => 'tok');
$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7'), 42, $same);
check('regenerate from a page of this site without a token (stale page): allowed', $r['status'] === 200 && $r['json']['success'] === true);
$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'Eve'), 42, $same);
check('LLM submit from a page of this site without a token: allowed', $r['status'] === 200 && $r['json']['success'] === true);

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7'), 42, array('session_token' => 'tok'));
check('regenerate from a client without browser headers (native app): allowed', $r['status'] === 200 && $r['json']['success'] === true);
$r = run_controller(array('__llm_form' => '1', '__form_name' => 'my_form', 'name' => 'Eve'), 42, array('session_token' => 'tok'));
check('LLM submit from a client without browser headers (native app): allowed', $r['status'] === 200 && $r['json']['success'] === true);

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '12', '__record_id' => '7'), GUEST_USER_ID, $evil);
check('guest request from another site: refused as cross-site first', $r['status'] === 403);

$r = run_controller(array('__llm_action' => 'regenerate', 'section_id' => '999', '__record_id' => '7'), 42, $evil);
check('action addressed to another section is not this controller\'s business (no 403 from it)', $r['json'] === null && !had($r, 'llm_call'));
$r = run_controller(array('__llm_form' => '1', '__form_name' => 'some_other_form', 'name' => 'Eve'), 42, $evil);
check('submit of another form is not this controller\'s business (no 403 from it)', $r['json'] === null && !had($r, 'llm_call'));

echo "\n";
$failed = count($GLOBALS['__fails']);
if ($failed === 0) {
    echo "ALL PASSED ({$GLOBALS['__checks']} checks)\n";
} else {
    echo "$failed FAILED of {$GLOBALS['__checks']} checks:\n";
    foreach ($GLOBALS['__fails'] as $label) {
        echo " - $label\n";
    }
}
// uopz (loaded in this environment) ignores exit() unless it is explicitly allowed
if (function_exists('uopz_allow_exit')) {
    uopz_allow_exit(true);
}
exit($failed === 0 ? 0 : 1);
