<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone regression test for LlmApiFormatterService (plugin v1.6.0, audit C1).
 *
 * The `attachments` column of a message does not only hold uploaded files: a
 * form submission stores {"type":"form_submission","values":{...}} there, and
 * `values` comes from the participant. The formatter used to read any `path`
 * it found in that JSON as a file and put the file's text into the prompt -
 * a participant could name a form field `path` and read server files.
 *
 * Now only a real list of attachments is read, and only files that resolve
 * (realpath) inside the plugin upload folder.
 *
 * No bootstrap, no database: the real formatter and LlmFileUtility run in a
 * throw-away plugin tree (with an upload folder, files next to it and outside
 * the plugin) where small stand-ins replace BaseLlmService and
 * LlmModelCapabilities.
 *
 * Run: php tests/LlmApiFormatterServiceTest.php
 */

foreach (array(
    'LLM_UPLOAD_FOLDER' => 'upload',
    'LLM_ALLOWED_IMAGE_EXTENSIONS' => array('jpg', 'jpeg', 'png', 'gif', 'webp'),
    'LLM_DEFAULT_MODEL' => 'test-model',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_api_formatter_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_api_formatter_test_' . getmypid();
$plugin = $root . '/server/plugins/sh-shp-llm';
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
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        sandbox_remove($dir . '/' . $entry);
    }
    @rmdir($dir);
}

register_shutdown_function(function () use ($root) {
    sandbox_remove($root);
});

// Real files, byte for byte
foreach (array('service/LlmApiFormatterService.php', 'service/LlmFileUtility.php') as $file) {
    sandbox_write($plugin . '/server/' . $file, file_get_contents($real_server . '/' . $file));
}

// What the formatter leans on
sandbox_write($plugin . '/server/service/base/BaseLlmService.php', <<<'PHP'
<?php
class LlmModelCapabilities
{
    public static function isVisionModel($model)
    {
        return false;
    }
}

class BaseLlmService
{
    public function __construct($services)
    {
    }

    protected function logWarning($message, $context = array())
    {
    }

    protected function getLlmConfig()
    {
        return array('model' => 'test-model');
    }
}
PHP
);

// Files: one legitimate upload, secrets in every place a path could point to
sandbox_write($plugin . '/upload/7/note.txt', 'UPLOAD-OK-CONTENT');
sandbox_write($plugin . '/secret.txt', 'TOP-SECRET-PLUGIN-ROOT');
sandbox_write($plugin . '/server/config.txt', 'TOP-SECRET-SERVER-CONFIG');
sandbox_write($plugin . '/upload_evil/x.txt', 'TOP-SECRET-SIBLING-FOLDER');
sandbox_write($root . '/outside.txt', 'TOP-SECRET-OUTSIDE-PLUGIN');

$symlink_ok = false;
if (function_exists('symlink')) {
    $symlink_ok = @symlink($plugin . '/secret.txt', $plugin . '/upload/7/link.txt');
}

require_once $plugin . '/server/service/LlmApiFormatterService.php';

/* =========================================================================
 * Helpers
 * ========================================================================= */

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

/**
 * Format one stored user message and return the API payload as JSON text.
 *
 * @param mixed $attachments What is stored in the attachments column (array/object, or a raw string)
 */
function formatted($attachments, $content = 'Please look at this')
{
    $formatter = new LlmApiFormatterService(new stdClass(), 'test-model');
    $message = array(
        'role' => 'user',
        'content' => $content,
        'attachments' => is_string($attachments) ? $attachments : json_encode($attachments),
    );
    $api = $formatter->convertToApiFormat(array($message));
    return array($api, json_encode($api));
}

function leaks($json)
{
    return strpos($json, 'TOP-SECRET') !== false;
}

/* =========================================================================
 * The attack: participant input stored as form metadata
 * ========================================================================= */

foreach (array(
    'a form field named path (plugin root file)' => array('path' => 'secret.txt', 'original_name' => 'x.txt'),
    'a form field named path (traversal)' => array('path' => '../../../outside.txt'),
    'a form field named path (server folder)' => array('path' => 'server/config.txt'),
) as $label => $values) {
    list($api, $json) = formatted(array('type' => 'form_submission', 'values' => $values));
    check("form_submission metadata with $label is never read", !leaks($json) && is_string($api[0]['content']));
}

list($api, $json) = formatted(array('type' => 'form_submission', 'path' => 'secret.txt'));
check('a top-level path in the metadata is not a file reference either', !leaks($json) && $api[0]['content'] === 'Please look at this');

/* =========================================================================
 * Legitimate attachments still work
 * ========================================================================= */

list($api, $json) = formatted(array(array('path' => 'upload/7/note.txt', 'original_name' => 'note.txt', 'is_image' => false)));
check('a stored attachment list inside the upload folder is read', strpos($json, 'UPLOAD-OK-CONTENT') !== false && is_array($api[0]['content']));
check('the text of the message stays in front of the file', $api[0]['content'][0]['text'] === 'Please look at this');

list($api, $json) = formatted(array('path' => 'upload/7/note.txt', 'original_name' => 'note.txt'));
check('a lone object (not a list of attachments) is not treated as a file', strpos($json, 'UPLOAD-OK-CONTENT') === false
    && $api[0]['content'] === 'Please look at this');

list($api, $json) = formatted(array(array('path' => 'upload\\7\\note.txt', 'original_name' => 'note.txt')));
check('backslash separators inside the upload folder are accepted', strpos($json, 'UPLOAD-OK-CONTENT') !== false);

/* =========================================================================
 * Paths that leave the upload folder
 * ========================================================================= */

foreach (array(
    'a file next to the upload folder' => 'secret.txt',
    'traversal out of the upload folder' => 'upload/../secret.txt',
    'traversal through a sub folder' => 'upload/7/../../secret.txt',
    'traversal out of the plugin' => 'upload/../../../../outside.txt',
    'the server folder' => 'server/config.txt',
    'a sibling folder sharing the upload prefix' => 'upload_evil/x.txt',
    'an absolute path' => $root . '/outside.txt',
    'a path with a null byte' => "upload/7/note.txt\0.png",
    'a directory' => 'upload/7',
    'an empty path' => '',
    'a path that does not exist' => 'upload/7/missing.txt',
) as $label => $path) {
    list($api, $json) = formatted(array(array('path' => $path, 'original_name' => 'doc.txt')));
    check("attachment path: $label is not read", !leaks($json) && strpos($json, 'UPLOAD-OK-CONTENT') === false);
}

list($api, $json) = formatted(array(array('path' => 'secret.txt', 'original_name' => 'doc.txt')));
check('a refused attachment is reported as not found, not as content', strpos($json, 'Attachment not found: doc.txt') !== false);

if ($symlink_ok) {
    list($api, $json) = formatted(array(array('path' => 'upload/7/link.txt', 'original_name' => 'doc.txt')));
    check('a symlink inside the upload folder that points outside is not followed', !leaks($json));
} else {
    echo "skip: symlink check (the host does not allow creating symlinks)\n";
}

/* =========================================================================
 * Values of the wrong shape
 * ========================================================================= */

foreach (array(
    'path is an array' => array(array('path' => array('secret.txt'))),
    'path is a number' => array(array('path' => 12345)),
    'path is null' => array(array('path' => null)),
    'the entry is a string' => array('secret.txt'),
    'the entry is a number' => array(7),
    'original_name is an array' => array(array('path' => 'secret.txt', 'original_name' => array('x'))),
) as $label => $stored) {
    $error = null;
    try {
        list($api, $json) = formatted($stored);
    } catch (Throwable $e) {
        $error = $e;
        $json = '';
    }
    check("malformed attachments ($label) neither crash nor leak", $error === null && !leaks($json));
}

foreach (array(
    'invalid JSON' => '{not json',
    'a JSON string' => '"secret.txt"',
    'a JSON number' => '42',
    'an empty object' => '{}',
    'an object without path' => '{"type":"form_submission","values":{"name":"Ada"}}',
) as $label => $raw) {
    $error = null;
    try {
        list($api, $json) = formatted($raw);
    } catch (Throwable $e) {
        $error = $e;
        $api = null;
    }
    check("stored attachments column with $label: plain text message", $error === null && $api !== null
        && $api[0]['content'] === 'Please look at this');
}

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
