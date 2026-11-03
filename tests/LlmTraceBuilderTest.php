<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the request trace builder and its canonical hash
 * (plugin v1.7.0, request traces).
 *
 * A trace records the neutral `$messages` array that callLlmApi() sends:
 *  - a message equal to a stored history row becomes {"m": id};
 *  - any other text becomes chunks; text the code declared shared (schema,
 *    safety, mode texts) is split out as owner-0 chunks, the rest belongs to
 *    the user (it may hold participant data);
 *  - rebuilding the recipe gives back exactly the sent messages, and the hash
 *    does not depend on key order, float formatting or base64 bytes.
 *
 * No bootstrap, no database: the classes are pure.
 *
 * Run: php tests/LlmTraceBuilderTest.php
 */

$trace_dir = dirname(__DIR__) . '/server/service/trace';
require_once $trace_dir . '/LlmTraceHints.php';
require_once $trace_dir . '/LlmTraceCanonicalizer.php';
require_once $trace_dir . '/LlmTraceBuilder.php';

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

/** How a stored row is sent (stand-in for LlmApiFormatterService::formatStoredMessage). */
$format_row = function ($row) {
    if (trim((string)$row['content']) === '') {
        return null;
    }
    return array('role' => $row['role'], 'content' => $row['content']);
};

/** Rebuild with the builder's own chunk map (ref => content). */
function roundtrip($built, $rows, $format_row)
{
    $contents = array();
    foreach ($built['chunks'] as $ref => $chunk) {
        $contents[$ref] = $chunk['content'];
    }
    $rows_by_id = array();
    foreach ($rows as $row) {
        $rows_by_id[$row['id']] = $row;
    }
    return LlmTraceBuilder::rebuildMessages($built['recipe'], $contents, $rows_by_id, $format_row);
}

$USER = 7;
$schema = "Answer as JSON.\n{\n  \"type\": \"object\"\n}";
$safety = "CRITICAL SAFETY DETECTION: watch for suicide, kill myself";
$rows = array(
    array('id' => 11, 'role' => 'user', 'content' => 'Hello'),
    array('id' => 12, 'role' => 'assistant', 'content' => '{"content":{"text_blocks":[]}}'),
    array('id' => 13, 'role' => 'assistant', 'content' => '   '),
    array('id' => 14, 'role' => 'user', 'content' => 'I feel tired'),
);

/* ------------------------------------------------------------------------- */
$messages = array(
    array('role' => 'system', 'content' => $schema . "\n\n" . $safety . "\n\nName: O\\'Brien"),
    array('role' => 'user', 'content' => 'Hello'),
    array('role' => 'assistant', 'content' => '{"content":{"text_blocks":[]}}'),
    array('role' => 'user', 'content' => 'I feel tired'),
);
$built = LlmTraceBuilder::build($messages, $rows, $format_row, array($schema, $safety), $USER);

check('identical_history_row_becomes_ref',
    $built['recipe'][1] === array('m' => 11) && $built['recipe'][2] === array('m' => 12) && $built['recipe'][3] === array('m' => 14));
check('refs lists the referenced rows in order', $built['refs'] === array(11, 12, 14));

$system_refs = $built['recipe'][0]['c'] ?? array();
$owners = array();
$texts = array();
foreach ($system_refs as $ref) {
    $owners[] = $built['chunks'][$ref]['owner'];
    $texts[] = $built['chunks'][$ref]['content'];
}
check('shared_block_split_out_and_rest_per_user: shared, glue, shared, participant text',
    $owners === array(0, $USER, 0, $USER)
    && $texts === array($schema, "\n\n", $safety, "\n\nName: O\\'Brien"));
check('participant text is never in a shared chunk', (function () use ($built) {
    foreach ($built['chunks'] as $chunk) {
        if ($chunk['owner'] === 0 && strpos($chunk['content'], 'Brien') !== false) {
            return false;
        }
    }
    return true;
})());
check('rebuild_roundtrip_equal (text, refs, split)', roundtrip($built, $rows, $format_row) === $messages);

/* ------------------------------------------------------------------------- */
// Retry: the failed reply is echoed cut to RETRY_ECHO_MAX_CHARS, so it differs from its row
$long = str_repeat('x', 9000);
$retry_rows = array(array('id' => 21, 'role' => 'assistant', 'content' => $long));
$retry_messages = array(
    array('role' => 'assistant', 'content' => mb_substr($long, 0, 8000)),
    array('role' => 'user', 'content' => 'Fix the JSON: missing content'),
);
$built = LlmTraceBuilder::build($retry_messages, $retry_rows, $format_row, array(), $USER);
check('truncated_retry_echo_becomes_text', isset($built['recipe'][0]['c']) && $built['refs'] === array());
check('truncated retry echo rebuilds exactly', roundtrip($built, $retry_rows, $format_row) === $retry_messages);

/* ------------------------------------------------------------------------- */
$built = LlmTraceBuilder::build(array(array('role' => 'system', 'content' => 'Author context for Ana')), array(), $format_row, array(), $USER);
$only = $built['chunks'][$built['recipe'][0]['c'][0]];
check('unhinted_text_is_per_user', count($built['recipe'][0]['c']) === 1 && $only['owner'] === $USER);

/* ------------------------------------------------------------------------- */
// Order matters: a row is a ref only in sequence, never matched twice
$dup_rows = array(array('id' => 31, 'role' => 'user', 'content' => 'ok'));
$built = LlmTraceBuilder::build(array(array('role' => 'user', 'content' => 'ok'), array('role' => 'user', 'content' => 'ok')), $dup_rows, $format_row, array(), $USER);
check('a history row is referenced at most once', $built['recipe'][0] === array('m' => 31) && isset($built['recipe'][1]['c']));

/* ------------------------------------------------------------------------- */
// Multimodal content and extra keys: stored whole, as JSON
$multimodal = array(
    array('role' => 'user', 'content' => array(
        array('type' => 'text', 'text' => 'See'),
        array('type' => 'image_url', 'image_url' => array('url' => 'data:image/jpeg;base64,' . base64_encode('JPEGBYTES'))),
    )),
    array('role' => 'assistant', 'content' => 'x', 'name' => 'coach'),
);
$built = LlmTraceBuilder::build($multimodal, array(), $format_row, array(), $USER);
check('multimodal and extra-key messages are stored whole', isset($built['recipe'][0]['x'], $built['recipe'][1]['x']));
check('rebuild_roundtrip_equal (multimodal parts)', roundtrip($built, array(), $format_row) === $multimodal);

// An image whose upload is known is stored as a file reference, not as its bytes
$image_url = $multimodal[0]['content'][1]['image_url']['url'];
$attachment = array('path' => 'upload/7/photo.png', 'type' => 'image/png', 'is_collage' => false);
$image_refs = array(hash('sha256', $image_url) => $attachment);
$built = LlmTraceBuilder::build($multimodal, array(), $format_row, array(), $USER, $image_refs);
$stored = implode('', array_column($built['chunks'], 'content'));
check('image_stored_as_file_reference: no base64 bytes in the chunk', strpos($stored, base64_encode('JPEGBYTES')) === false
    && strpos($stored, '"img":{"path":"upload/7/photo.png"') !== false);
$contents = array();
foreach ($built['chunks'] as $ref => $chunk) {
    $contents[$ref] = $chunk['content'];
}
$load_image = function ($a) use ($attachment, $image_url) {
    return $a === $attachment ? $image_url : null;
};
check('image_stored_as_file_reference: rebuild loads the image back',
    LlmTraceBuilder::rebuildMessages($built['recipe'], $contents, array(), $format_row, $load_image) === $multimodal);
check('image_stored_as_file_reference: a changed file does not rebuild (hash check)',
    LlmTraceBuilder::rebuildMessages($built['recipe'], $contents, array(), $format_row, function () {
        return 'data:image/jpeg;base64,' . base64_encode('CHANGED');
    }) === null);
check('image_stored_as_file_reference: without an image loader there is no rebuild',
    LlmTraceBuilder::rebuildMessages($built['recipe'], $contents, array(), $format_row) === null);

/* ------------------------------------------------------------------------- */
$a = LlmTraceCanonicalizer::hash('openai', array('model' => 'm', 'temperature' => 0.7, 'max_tokens' => 10), $messages);
$b = LlmTraceCanonicalizer::hash('openai', array('max_tokens' => 10, 'temperature' => 0.7, 'model' => 'm'), $messages);
$old = ini_get('serialize_precision');
ini_set('serialize_precision', '17');
$c = LlmTraceCanonicalizer::hash('openai', array('model' => 'm', 'temperature' => 0.7, 'max_tokens' => 10), $messages);
ini_set('serialize_precision', $old);
check('hash_stable_under_key_order_and_float_precision', $a === $b && $a === $c && strlen($a) === 64);
check('hash restores serialize_precision', ini_get('serialize_precision') === $old);
check('hash depends on provider and params', $a !== LlmTraceCanonicalizer::hash('anthropic', array('model' => 'm', 'temperature' => 0.7, 'max_tokens' => 10), $messages)
    && $a !== LlmTraceCanonicalizer::hash('openai', array('model' => 'm', 'temperature' => 0.8, 'max_tokens' => 10), $messages));
check('hash keeps message order', $a !== LlmTraceCanonicalizer::hash('openai', array('model' => 'm', 'temperature' => 0.7, 'max_tokens' => 10), array_reverse($messages)));

$img1 = $multimodal;
$img2 = $multimodal;
$img2[0]['content'][1]['image_url']['url'] = 'data:image/jpeg;base64,' . base64_encode('OTHERBYTES');
$img3 = $multimodal;
$img3[0]['content'][0]['text'] = 'Look';
$h1 = LlmTraceCanonicalizer::hash('openai', array(), $img1);
check('hash_ignores_base64_bytes_not_text: other image bytes change the hash (via their sha256)', $h1 !== LlmTraceCanonicalizer::hash('openai', array(), $img2));
check('hash_ignores_base64_bytes_not_text: other text changes the hash', $h1 !== LlmTraceCanonicalizer::hash('openai', array(), $img3));
check('canonical form replaces data URLs by their sha256',
    strpos(LlmTraceCanonicalizer::canonicalJson(array('u' => 'data:image/jpeg;base64,QUJD')), 'sha256:' . hash('sha256', 'data:image/jpeg;base64,QUJD')) !== false
    && strpos(LlmTraceCanonicalizer::canonicalJson(array('u' => 'data:image/jpeg;base64,QUJD')), 'QUJD') === false);

/* ------------------------------------------------------------------------- */
LlmTraceHints::reset();
LlmTraceHints::share($schema);
LlmTraceHints::share('');
LlmTraceHints::share($schema);
LlmTraceHints::share($safety);
check('hints keep each non-empty shared text once', LlmTraceHints::sharedTexts() === array($schema, $safety));
LlmTraceHints::reset();
check('hints reset', LlmTraceHints::sharedTexts() === array());

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
