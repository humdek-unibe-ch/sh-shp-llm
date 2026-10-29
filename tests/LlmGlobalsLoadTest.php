<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test: the plugin must load without its local configuration file.
 *
 * Selfhelp::loadPluginGlobals() requires server/service/globals.php of every
 * plugin while the application boots, so a fatal there takes down the whole
 * site. globals_untracked.php is git-ignored and therefore missing on a fresh
 * checkout, in CI and on the CLI workers - it has to be optional.
 *
 * Every scenario runs in a fresh PHP process against a throw-away copy of
 * server/service, so the developer's own globals_untracked.php is never used.
 *
 * Run: php tests/LlmGlobalsLoadTest.php
 */

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

function removeTree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeTree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * @param string $sandbox Sandbox root that contains service/
 * @return array{0:int,1:string} exit code and combined output
 */
function runGlobals($sandbox)
{
    $runner = $sandbox . DIRECTORY_SEPARATOR . 'runner.php';
    file_put_contents($runner, <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/service/globals.php';
echo 'LOADED model=', LLM_DEFAULT_MODEL, ' secret=', defined('LLM_SECRET_KEY') ? constant('LLM_SECRET_KEY') : 'none', "\n";
PHP
    );
    $output = array();
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $code);
    return array($code, implode("\n", $output));
}

$service_dir = realpath(__DIR__ . '/../server/service');
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_globals_' . bin2hex(random_bytes(6));
mkdir($sandbox . DIRECTORY_SEPARATOR . 'service', 0777, true);
foreach (glob($service_dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
    if (basename($file) === 'globals_untracked.php') {
        continue;
    }
    copy($file, $sandbox . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . basename($file));
}

// --- 1. no local configuration file ------------------------------------------------
check('sandbox has no globals_untracked.php', !is_file($sandbox . '/service/globals_untracked.php'));
list($code, $out) = runGlobals($sandbox);
check('globals.php loads without globals_untracked.php (exit 0)', $code === 0);
check('no fatal / warning is printed without the file', stripos($out, 'fatal') === false && stripos($out, 'warning') === false);
check('the plugin constants are available without the file', strpos($out, 'LOADED model=') !== false);
check('LLM_SECRET_KEY stays undefined without the file', strpos($out, 'secret=none') !== false);

// --- 2. the shipped template alone is not a local configuration ----------------------
check('the template ships next to globals.php', is_file($service_dir . '/globals_untracked.default.php'));
list($lint_code) = (function ($file) {
    $o = array();
    $c = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $o, $c);
    return array($c);
})($service_dir . '/globals_untracked.default.php');
check('the template is valid PHP', $lint_code === 0);

$template = (string)file_get_contents($service_dir . '/globals_untracked.default.php');
$active_defines = 0;
foreach (token_get_all($template) as $token) {
    if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'define') {
        $active_defines++;
    }
}
check('the template defines nothing until it is edited (no active define())', $active_defines === 0);
check('the template documents LLM_SECRET_KEY', strpos($template, 'LLM_SECRET_KEY') !== false);
check('the template documents LLM_ALLOWED_API_HOSTS', strpos($template, 'LLM_ALLOWED_API_HOSTS') !== false);
check('the template contains no usable default secret', preg_match("/define\\(\\s*'LLM_SECRET_KEY'\\s*,\\s*'(?!replace-with)[^']+'/", $template) !== 1);

copy($service_dir . '/globals_untracked.default.php', $sandbox . '/service/globals_untracked.php');
list($code, $out) = runGlobals($sandbox);
check('copying the template unchanged loads cleanly', $code === 0 && strpos($out, 'LOADED') !== false);
check('copying the template unchanged does not define a secret', strpos($out, 'secret=none') !== false);

// --- 3. a real local configuration file is still honoured --------------------------------
file_put_contents($sandbox . '/service/globals_untracked.php', "<?php\ndefine('LLM_SECRET_KEY', 'a-local-test-secret-value');\n");
list($code, $out) = runGlobals($sandbox);
check('a present globals_untracked.php is still loaded', $code === 0 && strpos($out, 'secret=a-local-test-secret-value') !== false);

removeTree($sandbox);

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
