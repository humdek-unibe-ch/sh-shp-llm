<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for the tri-state safety contract of LlmResponseService
 * (no database, no web server).
 *
 * `safety.is_safe` is true, false, or null - "the model gave no assessment".
 * Only an explicit false may be shown as a safety notice; null must never be
 * read as "unsafe" (and never be invented as "safe").
 *
 * Run: php tests/LlmResponseServiceSafetyTest.php
 */

// Some required plugin files end in a closing PHP tag plus a newline; keep that out of the report.
ob_start();
require_once __DIR__ . '/../server/service/LlmResponseService.php';
ob_end_clean();

// One attempt per call (the plugin default is 3, with a pause between attempts)
if (!defined('LLM_RESPONSE_MAX_ATTEMPTS')) {
    define('LLM_RESPONSE_MAX_ATTEMPTS', 1);
}

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    if ($condition) {
        echo "ok:   $label\n";
    } else {
        echo "FAIL: $label\n";
        $failed++;
    }
}

$svc = new LlmResponseService(new stdClass(), null);

function reply(array $safety = null)
{
    $r = array('content' => array('text_blocks' => array(array('type' => 'text', 'content' => 'Hello there'))));
    if ($safety !== null) {
        $r['safety'] = $safety;
    }
    return $r;
}

$notice = 'Safety Notice';

// --- toMarkdown -----------------------------------------------------------------
$md = $svc->toMarkdown(reply(array('is_safe' => false, 'safety_message' => 'Please take care')));
check('toMarkdown: is_safe=false with a message shows the safety notice', strpos($md, $notice) !== false
    && strpos($md, 'Please take care') !== false);
check('toMarkdown: the notice comes before the content', strpos($md, $notice) < strpos($md, 'Hello there'));

$md = $svc->toMarkdown(reply(array('is_safe' => null, 'safety_message' => 'Please take care')));
check('toMarkdown: is_safe=null (unassessed) is not shown as a safety notice', strpos($md, $notice) === false);
check('toMarkdown: unassessed still renders the content', strpos($md, 'Hello there') !== false);

$md = $svc->toMarkdown(reply(array('is_safe' => true, 'safety_message' => 'Please take care')));
check('toMarkdown: is_safe=true shows no notice', strpos($md, $notice) === false);

$md = $svc->toMarkdown(reply(array('safety_message' => 'Please take care')));
check('toMarkdown: a safety block without is_safe shows no notice', strpos($md, $notice) === false);

$md = $svc->toMarkdown(reply());
check('toMarkdown: no safety block at all is fine', strpos($md, $notice) === false && strpos($md, 'Hello there') !== false);

$md = $svc->toMarkdown(reply(array('is_safe' => false, 'safety_message' => '')));
check('toMarkdown: is_safe=false without a message adds nothing', strpos($md, $notice) === false);

// --- assessSafety (the producer side of the same contract) ---------------------------
$a = $svc->assessSafety(reply());
check('assessSafety: no safety block is unassessed', $a['assessed'] === false && $a['is_safe'] === null);

$a = $svc->assessSafety(reply(array('assessed' => false, 'is_safe' => null)));
check('assessSafety: an explicit unassessed block stays unassessed', $a['assessed'] === false && $a['is_safe'] === null);

$a = $svc->assessSafety(reply(array('is_safe' => false, 'danger_level' => 'critical', 'requires_intervention' => true)));
check('assessSafety: is_safe=false is assessed and unsafe', $a['assessed'] === true && $a['is_safe'] === false
    && $a['danger_level'] === 'critical');

$a = $svc->assessSafety(reply(array('is_safe' => true, 'danger_level' => null)));
check('assessSafety: is_safe=true is assessed and safe', $a['assessed'] === true && $a['is_safe'] === true);

$a = $svc->assessSafety(reply(array('detected_concerns' => array(), 'safety_message' => null)));
check('assessSafety: a block without is_safe is unassessed, not "safe"', $a['assessed'] === false && $a['is_safe'] === null);

$a = $svc->assessSafety(reply(array('danger_level' => 'critical')));
check('assessSafety: a danger level without is_safe means "not safe"', $a['assessed'] === true && $a['is_safe'] === false
    && $a['danger_level'] === 'critical');

$a = $svc->assessSafety(reply(array('assessed' => true, 'danger_level' => null)));
check('assessSafety: assessed=true without is_safe is unassessed, not "safe"', $a['assessed'] === false && $a['is_safe'] === null);

$a = $svc->assessSafety(reply(array('is_safe' => 'yes')));
check('assessSafety: a non-boolean is_safe is unassessed', $a['assessed'] === false && $a['is_safe'] === null);

// --- what a response becomes when the model's safety block is missing or odd --------------
// A server-built or reshaped response must never claim "checked and safe" on its own.
function private_call($svc, $method, array $args)
{
    $m = new ReflectionMethod($svc, $method);
    $m->setAccessible(true);
    return $m->invokeArgs($svc, $args);
}

function normalized_safety($svc, array $safety_part)
{
    $parsed = array(
        'content' => array('text_blocks' => array(array('type' => 'text', 'content' => 'Hi'))),
        'metadata' => array('model' => 'm'),
    ) + $safety_part;
    return $svc->assessSafety(private_call($svc, 'normalizeResponse', array($parsed)));
}

$a = normalized_safety($svc, array());
check('normalize: no safety block is unassessed, not "safe"', $a['assessed'] === false && $a['is_safe'] === null);

$a = normalized_safety($svc, array('safety' => array('detected_concerns' => array())));
check('normalize: a safety block without any flag is unassessed', $a['assessed'] === false && $a['is_safe'] === null);

$a = normalized_safety($svc, array('safety' => array('danger_level' => 'critical')));
check('normalize: a danger level without a flag means "not safe"', $a['assessed'] === true && $a['is_safe'] === false
    && $a['danger_level'] === 'critical');

$a = normalized_safety($svc, array('safety' => array('classifier' => 'safe')));
check('normalize: a classifier that says safe is safe', $a['assessed'] === true && $a['is_safe'] === true);
$a = normalized_safety($svc, array('safety' => array('level' => 'high')));
check('normalize: a level that is not low is not safe', $a['assessed'] === true && $a['is_safe'] === false);

foreach (array('safe', 'SAFE', ' low ', 'none') as $word) {
    $a = normalized_safety($svc, array('safety' => $word));
    check("normalize: the word \"$word\" is a safe assessment", $a['assessed'] === true && $a['is_safe'] === true);
}
foreach (array('unsafe', 'high', 'danger') as $word) {
    $a = normalized_safety($svc, array('safety' => $word));
    check("normalize: the word \"$word\" is not safe", $a['assessed'] === true && $a['is_safe'] === false && $a['danger_level'] === 'warning');
}
foreach (array('empty string' => '', 'blank string' => '  ', 'false' => false, 'true' => true, 'zero' => 0) as $label => $odd) {
    $a = normalized_safety($svc, array('safety' => $odd));
    check("normalize: a bare $label says nothing - unassessed, never invented as safe", $a['assessed'] === false && $a['is_safe'] === null);
}

$error = $svc->createErrorResponse('Something failed');
$a = $svc->assessSafety($error);
check('createErrorResponse: the error response carries no safety claim', $a['assessed'] === false && $a['is_safe'] === null
    && $error['content']['text_blocks'][0]['content'] === 'Something failed');

$fallback = private_call($svc, 'buildFallbackStructure', array('plain text'));
$a = $svc->assessSafety($fallback);
check('fallback structure: text the model produced without a safety block is unassessed', $a['assessed'] === false && $a['is_safe'] === null
    && $fallback['metadata']['is_fallback'] === true);

$produced = array('assessed' => true, 'is_safe' => false, 'danger_level' => 'critical', 'detected_concerns' => array('x'), 'requires_intervention' => true);
$fallback = private_call($svc, 'buildFallbackStructure', array('plain text', 'm', $produced));
check('fallback structure: a safety assessment the model did produce is kept', $fallback['safety'] === $produced);

$error_fallback = private_call($svc, 'createErrorFallback', array());
$a = $svc->assessSafety($error_fallback['data']);
check('error fallback: no response at all means no safety claim', $a['assessed'] === false && $a['is_safe'] === null);
check('error fallback: the participant text is the fixed notice',
    $error_fallback['data']['content']['text_blocks'][0]['content'] === LlmResponseService::NO_ANSWER_NOTICE
    && $error_fallback['is_error'] === true);

// --- a turn without a usable answer ---------------------------------------------------
// Each failed attempt is logged; one attempt per call keeps the test free of retry delays.
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_response_service_safety_test.log');

$reason = 'upstream: key sk-1234 rejected by secret-host.internal';
$down = $svc->callLlmWithSchemaValidation(function ($messages) use ($reason) {
    throw new InvalidArgumentException($reason);
}, array());
check('provider failure: not valid, and no raw response', $down['valid'] === false && $down['raw_response'] === null);
check('provider failure: the participant text is the fixed notice',
    $down['response']['data']['content']['text_blocks'][0]['content'] === LlmResponseService::NO_ANSWER_NOTICE);
check('provider failure: the technical reason stays in error, out of the participant response',
    $down['error'] === $reason && strpos(json_encode($down['response']), 'secret-host') === false);

$garbled = $svc->callLlmWithSchemaValidation(function ($messages) {
    return array('content' => 'this is not the JSON the schema asks for');
}, array());
check('a reply that never passes the schema is not valid (the chat keys its notice on this)',
    $garbled['valid'] === false && $garbled['raw_response']['content'] === 'this is not the JSON the schema asks for');

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
