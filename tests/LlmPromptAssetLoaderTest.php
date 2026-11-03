<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmPromptAssetLoader line endings (plugin v1.7.0,
 * request traces).
 *
 * A prompt asset must reach the model with the same bytes on every checkout:
 * a Windows checkout with core.autocrlf=true has CRLF files, a Linux one LF.
 * The request trace hashes those bytes, so the loader normalises to LF.
 *
 * No bootstrap, no database: the real loader and registry read throw-away
 * asset trees.
 *
 * Run: php tests/LlmPromptAssetLoaderTest.php
 */

$real_server = dirname(__DIR__) . '/server';
require_once $real_server . '/service/prompt/LlmPromptAssetLoader.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_asset_loader_test_' . getmypid();

function write_asset($base, $text)
{
    $path = $base . '/core/form-mode/system.md';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $text);
}

function remove_tree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? remove_tree($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(function () use ($root) {
    remove_tree($root);
});

/** Load the form-mode asset from a tree, bypassing the per-request static cache. */
function load_from($base)
{
    $cache = new ReflectionProperty('LlmPromptAssetLoader', 'cache');
    $cache->setAccessible(true);
    $cache->setValue(null, array());
    return (new LlmPromptAssetLoader($base))->load('core.form_mode.system');
}

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

write_asset($root . '/lf', "Line one\nLine two\n\nLine four\n");
write_asset($root . '/crlf', "Line one\r\nLine two\r\n\r\nLine four\r\n");
write_asset($root . '/cr', "Line one\rLine two\r\rLine four\r");

$lf = load_from($root . '/lf');
check('crlf and lf assets load identically', load_from($root . '/crlf') === $lf);
check('old Mac CR line endings load like lf', load_from($root . '/cr') === $lf);
check('loaded text has no carriage return', strpos(load_from($root . '/crlf'), "\r") === false);
check('content is otherwise unchanged (trimmed)', $lf === "Line one\nLine two\n\nLine four");

// Request traces: a loaded asset is code text, so it is declared shared (sent
// verbatim it is stored once for all users); also on a cached load
require_once $real_server . '/service/trace/LlmTraceHints.php';
LlmTraceHints::reset();
load_from($root . '/lf');
check('a loaded asset is declared shared', LlmTraceHints::sharedTexts() === array($lf));
LlmTraceHints::reset();
(new LlmPromptAssetLoader($root . '/lf'))->load('core.form_mode.system');
check('a cached load declares it shared too', LlmTraceHints::sharedTexts() === array($lf));

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
