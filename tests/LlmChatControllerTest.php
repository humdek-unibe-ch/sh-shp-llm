<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmChatController (llmChat, plugin v1.6.0):
 *
 *  - cross-site requests: every chat request that changes something (all
 *    POST actions and the one GET that creates a conversation) is answered
 *    403 when another website made it on the participant's behalf, before
 *    any service is touched. The native app (no browser headers), same-origin
 *    pages and requests that carry the session token keep working; plain
 *    reads are not gated;
 *  - the blocked-conversation check is scoped to the participant, runs
 *    before the model is reached and fails closed: "could not check" stops
 *    the request with a generic error instead of letting it through;
 *  - a turn without a usable answer (provider down, reply never valid) is
 *    answered with one fixed notice and nothing else of the reply: no raw
 *    text, no structured block, no reason (a danger flag the model produced
 *    is kept);
 *  - speech-to-text: the funnel's refusals reach the browser as 429 / 400,
 *    other failures keep their old shape, nothing internal leaks.
 *
 * No bootstrap, no database: the real controller and CSRF service are copied
 * into a throw-away tree that mirrors the controller's relative require_once
 * targets, where small stand-ins replace the core BaseController, the JSON
 * trait (which exits) and every other plugin service.
 *
 * Run: php tests/LlmChatControllerTest.php
 */

set_exception_handler(function ($e) {
    fwrite(STDOUT, 'FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});

foreach (array(
    'GUEST_USER_ID' => 1,
    'transactionTypes_insert' => 'insert',
    'transactionTypes_update' => 'update',
    'transactionTypes_delete' => 'delete',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

$error_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_chat_controller_test.log';
ini_set('log_errors', '1');
ini_set('error_log', $error_log);

/* =========================================================================
 * Sandbox tree: the real controller + stand-ins for what it requires
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_chat_controller_test_' . getmypid();
$plugin_server = $root . '/server/plugins/sh-shp-llm/server';
$real_server = str_replace('\\', '/', dirname(__DIR__)) . '/server';

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

// Real files, byte for byte
foreach (array(
    'component/style/llmChat/LlmChatController.php',
    'service/LlmCsrfService.php',
    'service/LlmErrorReporter.php',
    'service/globals.php',
    'service/LlmModelCapabilities.php',
    'service/exception/LlmException.php',
    'service/exception/LlmValidationException.php',
    'service/exception/LlmRateLimitException.php',
    'service/exception/LlmApiException.php',
) as $file) {
    sandbox_write($plugin_server . '/' . $file, file_get_contents($real_server . '/' . $file));
}

sandbox_write($root . '/server/component/BaseController.php', <<<'PHP'
<?php
class BaseController
{
    protected $model;

    public function __construct($model)
    {
        $this->model = $model;
    }
}
PHP
);

// JSON trait: the real one exits, this one throws what it would have sent.
// An Error, not an Exception: the controller's `catch (Exception $e)` blocks
// sit around calls that never return in production.
sandbox_write($plugin_server . '/component/LlmJsonResponseTrait.php', <<<'PHP'
<?php
class TestJsonSent extends Error
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

// The services: only what the covered paths call, every call recorded
sandbox_write($plugin_server . '/service/LlmService.php', <<<'PHP'
<?php
require_once __DIR__ . '/exception/LlmException.php';
require_once __DIR__ . '/exception/LlmValidationException.php';
require_once __DIR__ . '/exception/LlmRateLimitException.php';
require_once __DIR__ . '/exception/LlmApiException.php';

/** A provider error that (wrongly) carries a "not found" code: its text must never reach the participant */
class TestProviderNotFoundException extends LlmApiException
{
    public function __construct($message)
    {
        parent::__construct($message);
        $this->code = 404;
    }
}

class LlmService
{
    public function __construct($services)
    {
    }

    public function isGuestUser($user_id)
    {
        return false;
    }

    /**
     * Reaching this means the request got past the controller's own checks.
     * $GLOBALS['T']['rate_throw'] = [kind, message] makes it fail with another exception.
     */
    public function checkRateLimit($user_id)
    {
        $GLOBALS['T']['events'][] = 'rate_check';
        if (!empty($GLOBALS['T']['rate_throw'])) {
            list($kind, $message) = $GLOBALS['T']['rate_throw'];
            switch ($kind) {
                case 'validation':
                    throw new LlmValidationException($message);
                case 'not_found':
                    throw new LlmException($message, 404);
                case 'provider':
                    throw new LlmApiException($message);
                case 'provider_404':
                    throw new TestProviderNotFoundException($message);
                default:
                    throw new RuntimeException($message);
            }
        }
        throw new LlmRateLimitException('Too many requests', 60);
    }

    public function updateRateLimit($user_id, $rate_data = null, $conversation_id = null)
    {
    }

    public function createConversation()
    {
        $GLOBALS['T']['events'][] = 'create';
        return 99;
    }

    public function getConversation($conversation_id, $user_id, $section_id = null)
    {
        $GLOBALS['T']['events'][] = 'get_conversation';
        return array('id' => (int)$conversation_id, 'id_users' => (int)$user_id);
    }

    public function getConversationMessages($conversation_id, $limit = 50)
    {
        return array();
    }

    public function updateMessage($message_id, $fields)
    {
        $GLOBALS['T']['updates'][] = array($message_id, $fields);
    }

    public function getUserConversations()
    {
        $GLOBALS['T']['events'][] = 'list';
        return array();
    }

    public function deleteConversation($conversation_id, $user_id)
    {
        $GLOBALS['T']['events'][] = 'delete';
    }
}
PHP
);

foreach (array(
    'LlmFileUploadService' => 'LlmService $llm_service',
    'LlmApiFormatterService' => '$services',
    'LlmStrictConversationService' => '$llm_service',
    'LlmDataSavingService' => '$services',
    'LlmProgressTrackingService' => '$services',
    'LlmContextService' => '$a, $b, $c, $d, $e, $f',
) as $class => $args) {
    sandbox_write($plugin_server . '/service/' . $class . '.php', "<?php\nclass $class\n{\n    public function __construct($args)\n    {\n    }\n}\n");
}

// The real notice text is covered by tests/LlmResponseServiceSafetyTest.php. A sentinel here proves
// the controller hands out the constant instead of a copy of the text.
sandbox_write($plugin_server . '/service/LlmResponseService.php', <<<'PHP'
<?php
class LlmResponseService
{
    const NO_ANSWER_NOTICE = 'stand-in notice';

    public function __construct($model, $services)
    {
    }

    /** $GLOBALS['T']['safety']: the assessment the case needs (default: none) */
    public function assessSafety($data)
    {
        if (isset($GLOBALS['T']['safety'])) {
            return $GLOBALS['T']['safety'];
        }
        return array('assessed' => false, 'is_safe' => null, 'danger_level' => null);
    }
}
PHP
);

sandbox_write($plugin_server . '/service/LlmFloatingModeService.php', "<?php\nclass LlmFloatingModeService\n{\n}\n");

sandbox_write($plugin_server . '/service/LlmFormModeService.php', <<<'PHP'
<?php
class LlmFormModeService
{
    public function parseFormValues($json)
    {
        return array('choice' => array('a'));
    }

    public function hasSelections($values)
    {
        return true;
    }

    public function generateReadableTextFromFormValues($values)
    {
        return 'choice: a';
    }
}
PHP
);

sandbox_write($plugin_server . '/service/LlmDangerDetectionService.php', <<<'PHP'
<?php
class LlmDangerDetectionService
{
    public function __construct($services, $model)
    {
    }

    /** $GLOBALS['T']['blocked']: false | true | 'throw' */
    public function isConversationBlocked($conversation_id, $user_id)
    {
        $GLOBALS['T']['events'][] = 'blocked_check';
        $GLOBALS['T']['blocked_args'][] = array($conversation_id, $user_id);
        if ($GLOBALS['T']['blocked'] === 'throw') {
            throw new RuntimeException('database down at secret-host.internal');
        }
        return $GLOBALS['T']['blocked'];
    }

    public function checkMessage($message, $user_id, $conversation_id)
    {
        return array('safe' => true);
    }

    public function processSafetyDetection($response_service, $parsed_response, $conversation_id, $user_id, $section_id)
    {
        $GLOBALS['T']['events'][] = 'safety_detection';
    }
}
PHP
);

// The speech funnel answers what the case needs; the status mapping is the
// real one's contract (tests/LlmSpeechToTextServiceTest.php covers the real class)
sandbox_write($plugin_server . '/service/LlmSpeechToTextService.php', <<<'PHP'
<?php
class LlmSpeechToTextService
{
    public function __construct($services)
    {
    }

    public function isValidAudioType($mime_type)
    {
        return in_array(explode(';', (string)$mime_type)[0], array('audio/webm', 'audio/ogg', 'audio/mp4'), true);
    }

    public function getUserLanguage()
    {
        return 'de';
    }

    public function saveAndTranscribeAudio($audioFile, $userId, $sectionId, $conversationId = null, $model = null, $language = null, $keepFile = true)
    {
        $GLOBALS['T']['speech_calls'][] = func_get_args();
        if (!empty($GLOBALS['T']['speech_throw'])) {
            throw new RuntimeException('provider exploded at secret-host.internal');
        }
        return $GLOBALS['T']['speech_answer'];
    }

    public static function failureStatus(array $result)
    {
        $type = isset($result['error_type']) ? $result['error_type'] : '';
        return $type === 'rate_limited' ? 429 : ($type === 'invalid_audio' ? 400 : null);
    }
}
PHP
);

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class ChatFakeRouter
{
    public $route = array('params' => array());

    public function get_keyword_from_url()
    {
        return 'home';
    }

    public function log_user_activity()
    {
    }
}

class ChatFakeServices
{
    public function get_router()
    {
        return new ChatFakeRouter();
    }
}

class ChatFakeModel
{
    public $user_id = 7;
    public $speech_enabled = true;
    public $speech_model = 'whisper-1';
    public $auto_start = false;

    public function get_services()
    {
        return new ChatFakeServices();
    }

    public function getSectionId() { return 42; }
    public function getUserId() { return $this->user_id; }
    public function isSpeechToTextEnabled() { return $this->speech_enabled; }
    public function getSpeechToTextModel() { return $this->speech_model; }
    public function isAutoStartConversationEnabled() { return $this->auto_start; }
    public function isConversationsListEnabled() { return true; }
    public function isProgressTrackingEnabled() { return false; }
    public function getCurrentConversation() { return null; }
    public function getConfiguredModel() { return 'model-1'; }
    public function getLlmTemperature() { return 1; }
    public function getLlmMaxTokens() { return 2048; }
    public function getConversationLimit() { return 50; }
    public function getDangerBlockedMessage() { return 'blocked'; }
}

require_once $plugin_server . '/service/globals.php';
require_once $plugin_server . '/component/style/llmChat/LlmChatController.php';

/* =========================================================================
 * Harness
 * ========================================================================= */

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    if ($condition) {
        fwrite(STDOUT, "ok:   $label\n");
    } else {
        fwrite(STDOUT, "FAIL: $label\n");
        if (getenv('LLM_TEST_DEBUG')) {
            fwrite(STDOUT, '      last request: ' . json_encode($GLOBALS['last_run']) . "\n");
        }
        $failed++;
    }
}

/**
 * Run the real controller for one request.
 *
 * @param string $method GET | POST
 * @param array $fields $_GET or $_POST (section_id is added)
 * @param array $opts site, origin (browser headers), token (posted csrf_token),
 *  header_token (X-CSRF-Token), session_token, blocked, user_id, files, model overrides,
 *  rate_throw [kind, message] (the rate-limit call fails with that exception),
 *  invoke [private method, args] (called on the controller after construction)
 * @return array {status, data, events, returned} - status null when nothing was sent;
 *  returned is what the invoked private method returned
 */
function run_chat($method, array $fields, array $opts = array())
{
    $GLOBALS['T'] = array(
        'events' => array(),
        'rate_throw' => isset($opts['rate_throw']) ? $opts['rate_throw'] : null,
        'blocked' => isset($opts['blocked']) ? $opts['blocked'] : false,
        'blocked_args' => array(),
        'updates' => array(),
        'speech_calls' => array(),
        'speech_answer' => isset($opts['speech_answer']) ? $opts['speech_answer'] : array('success' => true, 'text' => 'hi'),
        'speech_throw' => !empty($opts['speech_throw']),
        'safety' => isset($opts['safety']) ? $opts['safety'] : null,
    );
    $fields = array_merge(array('section_id' => '42'), $fields);
    if (isset($opts['token'])) {
        $fields['csrf_token'] = $opts['token'];
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = $method === 'GET' ? $fields : array();
    $_POST = $method === 'POST' ? $fields : array();
    $_FILES = isset($opts['files']) ? $opts['files'] : array();
    $_SESSION = array('csrf_token' => isset($opts['session_token']) ? $opts['session_token'] : 'session-token');
    unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    if (isset($opts['header_token'])) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $opts['header_token'];
    }
    if (isset($opts['site'])) {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $opts['site'];
    }
    if (isset($opts['origin'])) {
        $_SERVER['HTTP_ORIGIN'] = $opts['origin'];
    }
    $model = new ChatFakeModel();
    foreach (array('user_id', 'speech_enabled', 'speech_model', 'auto_start') as $prop) {
        if (array_key_exists($prop, $opts)) {
            $model->$prop = $opts[$prop];
        }
    }
    $returned = null;
    try {
        $controller = new LlmChatController($model);
        if (isset($opts['invoke'])) {
            $private_method = new ReflectionMethod($controller, $opts['invoke'][0]);
            $private_method->setAccessible(true);
            $returned = $private_method->invokeArgs($controller, $opts['invoke'][1]);
        }
    } catch (TestJsonSent $e) {
        return $GLOBALS['last_run'] = array('status' => $e->status, 'data' => $e->data, 'events' => $GLOBALS['T']['events'], 'returned' => null);
    }
    return $GLOBALS['last_run'] = array('status' => null, 'data' => null, 'events' => $GLOBALS['T']['events'], 'returned' => $returned);
}

function audio_upload(array $overrides = array())
{
    return array('audio' => array_merge(
        array('name' => 'a.webm', 'type' => 'audio/webm', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 1234),
        $overrides
    ));
}

$cross_site = array('site' => 'cross-site', 'origin' => 'https://evil.example');
$refusal = 'Security check failed. Please reload the page and try again.';

/* =========================================================================
 * Cross-site requests
 * ========================================================================= */

$actions = array(
    'send_message' => array('action' => 'send_message', 'message' => 'hello'),
    'submit_form' => array('action' => 'submit_form', 'form_values' => '{"choice":["a"]}'),
    'continue_conversation' => array('action' => 'continue_conversation', 'conversation_id' => '5'),
    'new_conversation' => array('action' => 'new_conversation', 'title' => 'x'),
    'delete_conversation' => array('action' => 'delete_conversation', 'conversation_id' => '5'),
    // (no topic: a handled request ends in a 400 before the progress service)
    'confirm_topic' => array('action' => 'confirm_topic', 'conversation_id' => '5'),
    'speech_transcribe' => array('action' => 'speech_transcribe'),
    'legacy bare message' => array('message' => 'hello'),
);

foreach ($actions as $name => $post) {
    $opts = array('files' => $name === 'speech_transcribe' ? audio_upload() : array());

    $r = run_chat('POST', $post, $cross_site + $opts);
    check("$name: a request from another website is refused with 403", $r['status'] === 403 && $r['data'] === array('error' => $refusal));
    check("$name: ...before any service or model is reached", $r['events'] === array()
        && $GLOBALS['T']['speech_calls'] === array());

    $r = run_chat('POST', $post, array('site' => 'same-site', 'origin' => 'https://other.example.org') + $opts);
    check("$name: a request from a sibling site is refused too", $r['status'] === 403);

    $r = run_chat('POST', $post, array('site' => 'none') + $opts);
    check("$name: a navigation-less request (Sec-Fetch-Site: none) is refused", $r['status'] === 403);

    $r = run_chat('POST', $post, array('origin' => 'https://evil.example') + $opts);
    check("$name: an Origin-only request that nobody approved is refused", $r['status'] === 403);

    $r = run_chat('POST', $post, $cross_site + array('token' => 'wrong') + $opts);
    check("$name: a wrong token does not help", $r['status'] === 403);

    $r = run_chat('POST', $post, array('site' => 'same-origin', 'origin' => 'https://site.example') + $opts);
    check("$name: a same-origin request is handled", $r['status'] !== 403);

    $r = run_chat('POST', $post, $opts);
    check("$name: a request without browser headers (the native app) is handled", $r['status'] !== 403);

    $r = run_chat('POST', $post, $cross_site + array('token' => 'session-token') + $opts);
    check("$name: a request with the session token is handled even cross-site", $r['status'] !== 403);

    $r = run_chat('POST', $post, array('site' => 'same-origin', 'token' => 'stale', 'session_token' => 'newer') + $opts);
    check("$name: a stale token on a same-origin page is still handled", $r['status'] !== 403);
}

// What "handled" means: the request reaches the right service
$r = run_chat('POST', $actions['send_message'], array('site' => 'same-origin'));
check('send_message: a handled request goes on to the rate limit', in_array('rate_check', $r['events'], true) && $r['status'] === 429);
$r = run_chat('POST', $actions['delete_conversation'], array());
check('delete_conversation: a native request deletes', $r['events'] === array('delete') && $r['data'] === array('success' => true));
$r = run_chat('POST', $actions['new_conversation'], $cross_site);
check('new_conversation: nothing is created for another website', !in_array('create', $r['events'], true) && !in_array('rate_check', $r['events'], true));

// Requests that are not ours pass through untouched
$r = run_chat('POST', array('something_else' => '1'), $cross_site);
check('a cross-site post that does not address the chat is left alone (no response)', $r['status'] === null);
$r = run_chat('POST', array('action' => 'send_message', 'message' => 'x', 'section_id' => '43'), $cross_site);
check('a post addressed to another chat section is left to that section\'s controller', $r['status'] === null);

// GET: the auto-start creates a conversation, everything else reads
$r = run_chat('GET', array('action' => 'start_auto_conversation'), $cross_site + array('auto_start' => true));
check('start_auto_conversation: refused (403) for another website', $r['status'] === 403 && $r['data'] === array('error' => $refusal) && $r['events'] === array());
$r = run_chat('GET', array('action' => 'start_auto_conversation', 'conversation_id' => '5'), $cross_site + array('auto_start' => true));
check('start_auto_conversation with a conversation: refused too, and nothing was looked up', $r['status'] === 403 && $r['events'] === array());
$r = run_chat('GET', array('action' => 'start_auto_conversation'), array('site' => 'same-origin', 'auto_start' => false));
check('start_auto_conversation: a same-origin request is handled', $r['status'] === 400 && $r['data']['error'] === 'Auto-start conversation is not enabled');
$r = run_chat('GET', array('action' => 'start_auto_conversation'), array('auto_start' => false));
check('start_auto_conversation: the native app is handled', $r['status'] === 400 && $r['data']['error'] === 'Auto-start conversation is not enabled');
$r = run_chat('GET', array('action' => 'start_auto_conversation'), $cross_site + array('header_token' => 'session-token', 'auto_start' => false));
check('start_auto_conversation: a request with the session token header is handled', $r['status'] === 400);
$r = run_chat('GET', array('action' => 'start_auto_conversation'), $cross_site + array('token' => 'session-token', 'auto_start' => false));
check('start_auto_conversation: a token in the URL does not count (it would leak into logs)', $r['status'] === 403);
$r = run_chat('GET', array('action' => 'start_auto_conversation'), $cross_site + array('header_token' => 'wrong', 'auto_start' => false));
check('start_auto_conversation: a wrong token header does not help', $r['status'] === 403);

$r = run_chat('GET', array('action' => 'get_conversations'), $cross_site);
check('reads are not gated: get_conversations from another website is answered', $r['status'] === 200 && $r['data'] === array('conversations' => array()));
$r = run_chat('GET', array('action' => 'get_auto_started'), $cross_site);
check('reads are not gated: get_auto_started from another website is answered', $r['status'] === 200 && $r['data'] === array('auto_started' => false));

/* =========================================================================
 * Blocked conversations
 * ========================================================================= */

$msg = array('action' => 'send_message', 'message' => 'hello', 'conversation_id' => '5');

$r = run_chat('POST', $msg, array('blocked' => true));
check('a blocked conversation is answered as blocked', $r['status'] === 200 && $r['data']['blocked'] === true
    && $r['data']['type'] === 'conversation_blocked' && $r['data']['error'] === 'Conversation blocked');
check('...before the rate limit, the model or the database are reached', $r['events'] === array('blocked_check'));
check('...and the check is scoped to the signed-in participant', $GLOBALS['T']['blocked_args'] === array(array('5', 7)));

$r = run_chat('POST', $msg, array('blocked' => false));
check('a conversation that is not blocked goes on to the rate limit', $r['events'] === array('blocked_check', 'rate_check'));

$r = run_chat('POST', $msg, array('blocked' => 'throw'));
check('"could not check" stops the request with a generic 500', $r['status'] === 500
    && $r['data'] === array('error' => 'Something went wrong. Please try again.'));
check('...before the rate limit or the model are reached (fails closed)', $r['events'] === array('blocked_check'));
check('...without the internal error text in the answer', strpos(json_encode($r['data']), 'secret-host') === false);
check('...with the real reason in the server log', strpos(file_get_contents($error_log), 'database down at secret-host.internal') !== false);

$r = run_chat('POST', array('action' => 'send_message', 'message' => 'hello'), array('blocked' => true));
check('a message without a conversation has nothing to check', !in_array('blocked_check', $r['events'], true) && in_array('rate_check', $r['events'], true));

$r = run_chat('POST', array('action' => 'submit_form', 'form_values' => '{"choice":["a"]}', 'conversation_id' => '5'), array('blocked' => true));
check('a form submission into a blocked conversation is answered as blocked', $r['data']['type'] === 'conversation_blocked' && $r['events'] === array('blocked_check'));
$r = run_chat('POST', array('action' => 'submit_form', 'form_values' => '{"choice":["a"]}', 'conversation_id' => '5'), array('blocked' => 'throw'));
check('...and "could not check" stops it too', $r['status'] === 500 && $r['events'] === array('blocked_check'));

$r = run_chat('POST', array('action' => 'continue_conversation', 'conversation_id' => '5'), array('blocked' => true));
check('continuing a blocked conversation is answered as blocked', $r['data']['type'] === 'conversation_blocked'
    && $r['events'] === array('get_conversation', 'blocked_check'));
$r = run_chat('POST', array('action' => 'continue_conversation', 'conversation_id' => '5'), array('blocked' => 'throw'));
check('...and "could not check" stops it too (no rate limit)', $r['status'] === 500 && $r['events'] === array('get_conversation', 'blocked_check'));

$r = run_chat('GET', array('action' => 'start_auto_conversation', 'conversation_id' => '5'), array('blocked' => true, 'auto_start' => true));
check('seeding a blocked conversation is answered as blocked', $r['data']['type'] === 'conversation_blocked'
    && $r['events'] === array('get_conversation', 'blocked_check'));
$r = run_chat('GET', array('action' => 'start_auto_conversation', 'conversation_id' => '5'), array('blocked' => 'throw', 'auto_start' => true));
check('...and "could not check" stops it too', $r['status'] === 500 && $r['events'] === array('get_conversation', 'blocked_check'));

// The single choke point in front of the model checks on its own. The actions above check first, so
// this only matters when a future caller forgets - the guard must not rest on its callers
$choke = array('invoke' => array('sendLlmRequestAndRespond', array('5', false)));
$r = run_chat('GET', array(), array('blocked' => true) + $choke);
check('the model call itself refuses a blocked conversation', $r['status'] === 200 && $r['data']['type'] === 'conversation_blocked'
    && $r['events'] === array('blocked_check'));
$r = run_chat('GET', array(), array('blocked' => 'throw') + $choke);
check('...and "could not check" stops it there too', $r['status'] === 500 && $r['events'] === array('blocked_check'));
$r = run_chat('GET', array(), array('blocked' => false) + $choke);
check('...while a conversation that is not blocked goes on (here: no messages)', $r['status'] === 400
    && $r['data'] === array('error' => 'No messages found in conversation'));

/* =========================================================================
 * What a participant is told when a request fails
 * ========================================================================= */

$generic = array('error' => 'Something went wrong. Please try again.');

$r = run_chat('POST', $msg, array());
check('a rate limit is answered 429 with its own text', $r['status'] === 429 && $r['data'] === array('error' => 'Too many requests'));
$r = run_chat('POST', $msg, array('rate_throw' => array('validation', 'Your message is too long')));
check('a validation error is answered 400 with its own text', $r['status'] === 400 && $r['data'] === array('error' => 'Your message is too long'));
$r = run_chat('POST', $msg, array('rate_throw' => array('not_found', 'Conversation not found')));
check('a missing conversation is answered 404 with its own text', $r['status'] === 404 && $r['data'] === array('error' => 'Conversation not found'));
$r = run_chat('POST', $msg, array('rate_throw' => array('provider', 'upstream: key sk-1234 rejected by secret-host.internal')));
check('a provider error is a generic 500 without the provider text', $r['status'] === 500 && $r['data'] === $generic);
$r = run_chat('POST', $msg, array('rate_throw' => array('provider_404', 'upstream 404 at secret-host.internal')));
check('...even when it carries a "not found" code', $r['status'] === 500 && $r['data'] === $generic);
$r = run_chat('POST', $msg, array('rate_throw' => array('runtime', 'SQLSTATE[42S02]: table secret_table is missing')));
check('an unexpected error is a generic 500 and the reason stays in the server log', $r['status'] === 500 && $r['data'] === $generic
    && strpos(file_get_contents($error_log), 'secret_table is missing') !== false);

/* =========================================================================
 * A turn without a usable answer
 *
 * No validated reply is stored for the participant, so the reload shows nothing
 * new: the answer carries one fixed notice and nothing else of the reply (no raw
 * text, no structured block, no reason).
 * ========================================================================= */

$build = function ($parsed, $response, $result, array $opts = array()) {
    $r = run_chat('POST', array(), array('invoke' => array('buildLlmResponseData', array(
        $parsed, $response, $result, '5', false, array(), array(), array(),
    ))) + $opts);
    return $r['returned'];
};
$reply_blocks = function ($text, array $extra = array()) {
    return $extra + array('valid' => true, 'data' => array('content' => array('text_blocks' => array(
        array('type' => 'text', 'content' => $text),
    ))));
};

$answered = $build(
    $reply_blocks('Hello'),
    array('content' => '{"raw":"model text"}', 'logged_message_id' => 11),
    array('valid' => true)
);
check('an answer that passed validation carries no notice', !array_key_exists('notice', $answered) && $answered['conversation_id'] === '5');
check('...and its log row is marked validated', $GLOBALS['T']['updates'] === array(array(11, array('is_validated' => 1))));
check('...and keeps its reply text and its structured block', $answered['message'] === '{"raw":"model text"}'
    && $answered['structured'] === $reply_blocks('Hello')['data']);

$provider_down = $build(
    $reply_blocks('generic text from the service', array('is_error' => true)),
    null,
    array('valid' => false, 'raw_response' => null, 'error' => 'upstream: key sk-1234 rejected by secret-host.internal')
);
check('a provider failure carries the fixed notice', $provider_down['notice'] === LlmResponseService::NO_ANSWER_NOTICE);
check('...and no technical reason', strpos(json_encode($provider_down), 'secret-host') === false
    && strpos(json_encode($provider_down), 'sk-1234') === false);
check('...and nothing but the notice: message is the notice, no structured block, no service text',
    $provider_down['message'] === LlmResponseService::NO_ANSWER_NOTICE
    && !array_key_exists('structured', $provider_down)
    && strpos(json_encode($provider_down), 'generic text from the service') === false);

$never_valid = $build(
    $reply_blocks('extracted text', array('is_fallback' => true)),
    array('content' => 'raw model text', 'logged_message_id' => 12),
    array('valid' => false, 'raw_response' => array('content' => 'raw model text', 'logged_message_id' => 12))
);
check('a reply that never passed the schema carries the fixed notice', $never_valid['notice'] === LlmResponseService::NO_ANSWER_NOTICE);
check('...and its log row stays unvalidated, so the participant list does not show it',
    $GLOBALS['T']['updates'] === array(array(12, array('is_validated' => 0))));
check('...and nothing but the notice: neither the raw reply nor the extracted text is returned',
    $never_valid['message'] === LlmResponseService::NO_ANSWER_NOTICE
    && !array_key_exists('structured', $never_valid)
    && strpos(json_encode($never_valid), 'raw model text') === false
    && strpos(json_encode($never_valid), 'extracted text') === false);

// A failed turn is not a safe turn: what the model really flagged is still reported
$flagged = array(
    'assessed' => true, 'is_safe' => false, 'danger_level' => 'warning',
    'detected_concerns' => array('self-harm'), 'safety_message' => 'Please take care of yourself',
);
$never_valid_flagged = $build(
    $reply_blocks('extracted text', array('is_fallback' => true)),
    array('content' => 'raw model text', 'logged_message_id' => 13),
    array('valid' => false, 'raw_response' => array('content' => 'raw model text', 'logged_message_id' => 13)),
    array('safety' => $flagged)
);
check('a danger flag the model produced survives a failed turn, and the safety check still runs',
    $never_valid_flagged['safety'] === $flagged && in_array('safety_detection', $GLOBALS['T']['events'], true));
check('...while the answer still holds only the notice',
    $never_valid_flagged['message'] === LlmResponseService::NO_ANSWER_NOTICE
    && $never_valid_flagged['notice'] === LlmResponseService::NO_ANSWER_NOTICE
    && !array_key_exists('structured', $never_valid_flagged)
    && strpos(json_encode($never_valid_flagged), 'raw model text') === false);

/* =========================================================================
 * Speech-to-text
 * ========================================================================= */

$speech = array('action' => 'speech_transcribe', 'conversation_id' => '5');
$answers = array(
    'success' => array('success' => true, 'text' => 'hello world', 'audio_file' => array('filename' => 'x.webm')),
    'rate limit' => array('success' => false, 'error_type' => 'rate_limited', 'retry_after' => 60,
        'error' => 'Too many voice recordings. Please wait a minute and try again.'),
    'invalid audio' => array('success' => false, 'error_type' => 'invalid_audio',
        'error' => 'Invalid audio format. Supported: WebM/Opus, OGG/Opus, M4A/MP4, MP3, FLAC'),
    'provider failure' => array('success' => false, 'error' => 'Speech recognition service error'),
);

$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_answer' => $answers['success']));
check('speech: a transcription is returned as it is (200)', $r['status'] === 200 && $r['data'] === $answers['success']);
check('speech: the funnel is called for the participant, section, conversation and model (audio kept)',
    count($GLOBALS['T']['speech_calls']) === 1
    && $GLOBALS['T']['speech_calls'][0][1] === 7 && $GLOBALS['T']['speech_calls'][0][2] === 42
    && $GLOBALS['T']['speech_calls'][0][3] === '5' && $GLOBALS['T']['speech_calls'][0][4] === 'whisper-1'
    && $GLOBALS['T']['speech_calls'][0][5] === 'de' && $GLOBALS['T']['speech_calls'][0][6] === true);

$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_answer' => $answers['rate limit']));
check('speech: an exhausted budget is answered 429 with the funnel\'s body', $r['status'] === 429 && $r['data'] === $answers['rate limit']);

$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_answer' => $answers['invalid audio']));
check('speech: content that is not audio is answered 400 with the funnel\'s body', $r['status'] === 400 && $r['data'] === $answers['invalid audio']);

$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_answer' => $answers['provider failure']));
check('speech: a provider failure keeps its old shape (200, success false)', $r['status'] === 200 && $r['data'] === $answers['provider failure']);

$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_throw' => true));
check('speech: an exception in the funnel is a generic 500', $r['status'] === 500
    && $r['data'] === array('success' => false, 'error' => 'Speech transcription failed. Please try again.')
    && strpos(json_encode($r['data']), 'secret-host') === false);

// Checks in front of the funnel
$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_enabled' => false));
check('speech: switched off in the CMS -> 400, funnel not called', $r['status'] === 400 && $GLOBALS['T']['speech_calls'] === array());
$r = run_chat('POST', $speech, array('files' => audio_upload(), 'speech_model' => ''));
check('speech: no model configured -> 400, funnel not called', $r['status'] === 400 && $GLOBALS['T']['speech_calls'] === array());
$r = run_chat('POST', $speech, array('files' => array()));
check('speech: no upload -> 400, funnel not called', $r['status'] === 400 && $GLOBALS['T']['speech_calls'] === array());
$r = run_chat('POST', $speech, array('files' => audio_upload(array('error' => UPLOAD_ERR_PARTIAL))));
check('speech: a failed upload -> 400, funnel not called', $r['status'] === 400 && $GLOBALS['T']['speech_calls'] === array());
$r = run_chat('POST', $speech, array('files' => audio_upload(array('size' => LLM_MAX_AUDIO_SIZE + 1))));
check('speech: an oversized upload -> 400, funnel not called', $r['status'] === 400 && $GLOBALS['T']['speech_calls'] === array());
$r = run_chat('POST', $speech, array('files' => audio_upload(array('type' => 'text/html'))));
check('speech: a declared non-audio type -> 400, funnel not called', $r['status'] === 400 && $GLOBALS['T']['speech_calls'] === array());
$r = run_chat('POST', $speech, array('files' => audio_upload(), 'user_id' => null));
check('speech: nobody signed in -> 401', $r['status'] === 401 && $GLOBALS['T']['speech_calls'] === array());

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
