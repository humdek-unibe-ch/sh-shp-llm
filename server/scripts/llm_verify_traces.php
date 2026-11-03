<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Request trace verifier (doc/request-traces.md).
 *
 * Rebuilds every request trace in scope from the database and checks its
 * hash; while full payloads are still stored (dual write) it also compares
 * the rebuild with the stored request_payload. Lists traces that failed their
 * write-time self-check and assistant replies without a trace.
 *
 * Usage:
 *   php server/plugins/sh-shp-llm/server/scripts/llm_verify_traces.php [--since=YYYY-MM-DD] [--conversation=ID] [--host=HOST]
 *
 * Exit status 0 when nothing was found, 1 otherwise (and on bad arguments).
 * Switch "Store full request payloads" off only after runs over production
 * report nothing.
 */

require_once __DIR__ . '/../service/llm_worker_bootstrap.php';

if (function_exists('uopz_allow_exit')) {
    uopz_allow_exit(true);
}

$options = getopt('', array('since:', 'conversation:', 'host:'));
if (isset($options['since']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['since'])) {
    fwrite(STDERR, "--since must be YYYY-MM-DD\n");
    exit(1);
}
if (isset($options['conversation']) && (int)$options['conversation'] <= 0) {
    fwrite(STDERR, "--conversation must be a conversation id\n");
    exit(1);
}

$services = llm_worker_boot_services('llm-verify-traces', $options['host'] ?? 'localhost', null);
require_once __DIR__ . '/../service/trace/LlmTraceVerifier.php';
require_once __DIR__ . '/../service/LlmApiFormatterService.php';
require_once __DIR__ . '/../service/LlmAdminService.php';

$formatter = new LlmApiFormatterService($services);
$report = (new LlmTraceVerifier($services->get_db(), function ($row) use ($formatter) {
    return $formatter->formatStoredMessage($row, false);
}, array(new LlmAdminService($services), 'toWirePayload'), array($formatter, 'imageDataUrl')))->run(array(
    'since' => $options['since'] ?? null,
    'conversation' => $options['conversation'] ?? null,
));

echo "checked:          {$report['checked']}\n";
echo "verified:         {$report['verified']}\n";
foreach (array('hash_mismatch', 'blob_mismatch', 'write_unverified', 'untraced') as $key) {
    $ids = $report[$key];
    echo str_pad($key . ':', 18) . count($ids) . (empty($ids) ? '' : '  (messages ' . implode(', ', array_slice($ids, 0, 50))
        . (count($ids) > 50 ? ', ...' : '') . ')') . "\n";
}
exit(LlmTraceVerifier::isClean($report) ? 0 : 1);
