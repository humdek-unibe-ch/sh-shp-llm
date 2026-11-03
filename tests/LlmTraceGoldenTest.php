<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Golden request traces (plugin v1.7.0): one representative request per LLM
 * call site in tests/fixtures/traces/cases.json.
 *
 * For every case: the trace rebuilds exactly the messages that were sent, and
 * request_hash equals the pinned value. A pinned hash that changes means
 * every stored trace of that shape no longer verifies - change the
 * canonicalizer only with a new trace format_version.
 *
 * Regenerate the pinned hashes (only together with a format_version bump):
 *   php tests/LlmTraceGoldenTest.php --write
 *
 * Run: php tests/LlmTraceGoldenTest.php
 */

$trace_dir = dirname(__DIR__) . '/server/service/trace';
require_once $trace_dir . '/LlmTraceCanonicalizer.php';
require_once $trace_dir . '/LlmTraceBuilder.php';

$fixture = __DIR__ . '/fixtures/traces/cases.json';
$cases = is_file($fixture) ? json_decode(file_get_contents($fixture), true) : null;

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

$expected_sites = array('chat', 'chat_retry', 'chat_anthropic', 'form_record', 'form_interview', 'memory', 'script_image',
    'evaluation_judge', 'dataset_import', 'prompt_builder', 'playground');
check('the fixture covers every call site', is_array($cases) && array_column($cases, 'name') === $expected_sites);
if (!is_array($cases)) {
    fwrite(STDOUT, "\n$total checks, $failed failed\n");
    exit(1);
}

$format_row = function ($row) {
    return trim((string)$row['content']) === '' ? null : array('role' => $row['role'], 'content' => $row['content']);
};
$write = in_array('--write', $argv, true);

foreach ($cases as &$case) {
    $built = LlmTraceBuilder::build($case['messages'], $case['rows'], $format_row, $case['shared_texts'], 7);
    $contents = array();
    foreach ($built['chunks'] as $ref => $chunk) {
        $contents[$ref] = $chunk['content'];
    }
    $rows = array_column($case['rows'], null, 'id');
    $rebuilt = LlmTraceBuilder::rebuildMessages($built['recipe'], $contents, $rows, $format_row);
    $hash = LlmTraceCanonicalizer::hash($case['provider'], $case['params'], $case['messages']);
    check($case['name'] . ': the trace rebuilds the sent messages', $rebuilt === $case['messages']);
    check($case['name'] . ': history rows are referenced', count($built['refs']) === count($case['rows']));
    if ($write) {
        $case['hash'] = $hash;
    }
    check($case['name'] . ': request_hash is the pinned value', $hash === ($case['hash'] ?? null));
}
unset($case);

if ($write) {
    file_put_contents($fixture, json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    fwrite(STDOUT, "pinned hashes written to $fixture\n");
}

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
