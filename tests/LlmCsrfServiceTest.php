<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for LlmCsrfService (no database, no web server).
 *
 * The trusted-origin rule reads two things core owns: the CORS switch and the
 * list CORS_ALLOWED_ORIGINS. They are constants, so this process runs with one
 * configuration and the other configurations (CORS off, no list, a list that is
 * not an array) are checked in child processes. Core's own cors() is not
 * executed (the CLI drops header()), but its source is checked against the
 * contract the plugin relies on, when the plugin sits inside a core checkout.
 *
 * Run: php tests/LlmCsrfServiceTest.php
 */

require_once __DIR__ . '/../server/service/LlmCsrfService.php';

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

// --- getToken ---------------------------------------------------------------
$_SESSION = array();
$token = LlmCsrfService::getToken();
check('getToken generates a 64-char hex token', preg_match('/^[0-9a-f]{64}$/', $token) === 1);
check('getToken stores it under $_SESSION[csrf_token]', $_SESSION['csrf_token'] === $token);
check('getToken is stable within the session', LlmCsrfService::getToken() === $token);

$_SESSION = array('csrf_token' => array('not', 'a', 'string'));
$replaced = LlmCsrfService::getToken();
check('getToken replaces a malformed session value', is_string($replaced) && strlen($replaced) === 64);

$_SESSION = array('csrf_token' => '');
check('getToken replaces an empty session value', strlen(LlmCsrfService::getToken()) === 64);

// --- isValid (strict) -----------------------------------------------------------
$_SESSION = array('csrf_token' => 'abc123');
check('isValid accepts the exact token', LlmCsrfService::isValid('abc123') === true);
check('isValid rejects a different token', LlmCsrfService::isValid('abc124') === false);
check('isValid rejects an empty token', LlmCsrfService::isValid('') === false);
check('isValid rejects null', LlmCsrfService::isValid(null) === false);
check('isValid rejects an array', LlmCsrfService::isValid(array('abc123')) === false);
check('isValid rejects a prefix of the token', LlmCsrfService::isValid('abc12') === false);
check('isValid is case sensitive', LlmCsrfService::isValid('ABC123') === false);

$_SESSION = array();
check('isValid refuses when the session has no token (no skip)', LlmCsrfService::isValid('') === false);
check('isValid refuses a posted token when the session has none', LlmCsrfService::isValid('abc123') === false);

$_SESSION = array('csrf_token' => '');
check('isValid refuses when the session token is empty', LlmCsrfService::isValid('') === false);

$_SESSION = array('token' => 'legacy', 'security_token' => 'legacy2');
check('isValid ignores the old alternate session keys', LlmCsrfService::isValid('legacy') === false
    && LlmCsrfService::isValid('legacy2') === false);

// --- fromRequest / verifyRequest ---------------------------------------------------
$_SESSION = array('csrf_token' => 'tok');
$_POST = array();
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
check('fromRequest is empty without POST field and header', LlmCsrfService::fromRequest() === '');
check('verifyRequest refuses a request without a token', LlmCsrfService::verifyRequest() === false);

$_POST = array('csrf_token' => 'tok');
check('fromRequest reads the POST field', LlmCsrfService::fromRequest() === 'tok');
check('verifyRequest accepts the POST field', LlmCsrfService::verifyRequest() === true);

$_POST = array();
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
check('fromRequest falls back to the X-CSRF-Token header', LlmCsrfService::fromRequest() === 'tok');
check('verifyRequest accepts the header', LlmCsrfService::verifyRequest() === true);

$_POST = array('csrf_token' => 'wrong');
check('the POST field wins over a valid header', LlmCsrfService::verifyRequest() === false);

$_POST = array('csrf_token' => array('tok'));
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
check('a non-scalar POST field falls through to the header', LlmCsrfService::fromRequest() === 'tok');

unset($_SERVER['HTTP_X_CSRF_TOKEN']);

// An endpoint that receives its POST data as an argument (Prompt Lab) passes it in
$_POST = array();
check('verifyRequest reads a given POST array instead of $_POST', LlmCsrfService::verifyRequest(array('csrf_token' => 'tok')) === true);
check('a given POST array without the field is refused', LlmCsrfService::verifyRequest(array('token' => 'tok')) === false);
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
check('a given POST array still falls back to the header', LlmCsrfService::verifyRequest(array()) === true);
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

// --- verifyRequestOrTrustedClient (chat / LLM form gate) --------------------------
// What core provides in this process: CORS on and the list of cross-origin clients.
define('CORS', true);
define('CORS_ALLOWED_ORIGINS', array(
    'https://localhost:8100',
    'http://localhost:8100',
    'https://selfhelp.philhum.unibe.ch',
));

function trusted_client($post, $site, $origin, $session_token = 'tok')
{
    $_SESSION = $session_token === null ? array() : array('csrf_token' => $session_token);
    $_POST = $post;
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
    if ($site !== null) {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $site;
    }
    if ($origin !== null) {
        $_SERVER['HTTP_ORIGIN'] = $origin;
    }
    return LlmCsrfService::verifyRequestOrTrustedClient();
}

$evil = 'https://evil.example';
check('gate: the session token passes even for a cross-site browser',
    trusted_client(array('csrf_token' => 'tok'), 'cross-site', $evil) === true);
check('gate: the header token passes too',
    (function () use ($evil) {
        $_SESSION = array('csrf_token' => 'tok');
        $_POST = array();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        $_SERVER['HTTP_ORIGIN'] = $evil;
        $ok = LlmCsrfService::verifyRequestOrTrustedClient();
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
        return $ok;
    })() === true);
check('gate: a cross-site browser without the token is refused',
    trusted_client(array(), 'cross-site', $evil) === false);
check('gate: a cross-site browser with a wrong token is refused',
    trusted_client(array('csrf_token' => 'nope'), 'cross-site', $evil) === false);
check('gate: a cross-site request without an Origin (link, image) is refused',
    trusted_client(array(), 'cross-site', null) === false);
check('gate: a same-site (sibling subdomain) request without the token is refused',
    trusted_client(array(), 'same-site', 'https://sibling.example') === false);
check('gate: a user-initiated navigation (none) without the token is refused',
    trusted_client(array(), 'none', null) === false);
check('gate: a browser that only sends Origin (no Fetch Metadata) is refused without the token',
    trusted_client(array(), null, $evil) === false);

check('gate: a page of this site passes without the token',
    trusted_client(array(), 'same-origin', 'https://site.example') === true);
check('gate: a same-origin page with a stale token passes',
    trusted_client(array('csrf_token' => 'stale'), 'same-origin', 'https://site.example') === true);
check('gate: the same-origin label is case-insensitive',
    trusted_client(array(), 'Same-Origin', null) === true);
check('gate: a same-origin page of a session without a token passes',
    trusted_client(array(), 'same-origin', null, null) === true);

check('gate: a client that sends no browser headers (native app) passes',
    trusted_client(array(), null, null) === true);
check('gate: an empty-string Origin counts as absent',
    trusted_client(array(), null, '') === true);
check('gate: a cross-site request is refused when the session has no token either',
    trusted_client(array('csrf_token' => 'anything'), 'cross-site', $evil, null) === false);

// --- cross-origin clients: exact entries of core's list ---------------------------------
check('gate: a listed origin (mobile preview, http) passes without the token',
    trusted_client(array(), 'cross-site', 'http://localhost:8100') === true);
check('gate: a listed origin (https) passes without the token',
    trusted_client(array(), 'cross-site', 'https://localhost:8100') === true);
check('gate: a listed origin passes when only Origin is sent (no Fetch Metadata)',
    trusted_client(array(), null, 'https://selfhelp.philhum.unibe.ch') === true);
check('gate: a listed origin passes as same-site',
    trusted_client(array(), 'same-site', 'https://selfhelp.philhum.unibe.ch') === true);
check('gate: a listed origin passes for a session without a token',
    trusted_client(array(), 'cross-site', 'https://localhost:8100', null) === true);

check('gate: another scheme of a listed host is refused',
    trusted_client(array(), 'cross-site', 'http://selfhelp.philhum.unibe.ch') === false);
check('gate: another port of a listed host is refused',
    trusted_client(array(), 'cross-site', 'https://localhost:8101') === false);
check('gate: the default port of a listed non-default port is refused',
    trusted_client(array(), 'cross-site', 'https://localhost') === false);
check('gate: a parent domain of a listed host is refused',
    trusted_client(array(), 'cross-site', 'https://philhum.unibe.ch') === false);
check('gate: a subdomain of a listed host is refused',
    trusted_client(array(), 'cross-site', 'https://app.selfhelp.philhum.unibe.ch') === false);
check('gate: the origin is compared case-sensitively (a browser sends it lower-case)',
    trusted_client(array(), 'cross-site', 'HTTP://LOCALHOST:8100') === false);
check('gate: a trailing slash is not part of an origin',
    trusted_client(array(), 'cross-site', 'https://localhost:8100/') === false);
check('gate: user info in front of a listed host is refused',
    trusted_client(array(), 'cross-site', 'https://localhost:8100@evil.example') === false);
check('gate: the literal null origin (sandboxed frame, data: URL) is refused',
    trusted_client(array(), 'cross-site', 'null') === false);
check('gate: a wildcard is not an origin',
    trusted_client(array(), 'cross-site', '*') === false);

// A lookalike origin through a reflecting header. The core check that used to approve origins by substring
// answered "Access-Control-Allow-Origin: <the request origin>" for it, and the gate used to trust any origin
// that header echoed. The gate no longer looks at response headers at all, so the reflection changes nothing.
function former_core_reflection($origin)
{
    $listed = array(
        'https://localhost:8100', 'http://localhost:8100', 'http://192.168.0.58', 'https://192.168.0.58',
        'http://192.168.0.58:8100', 'https://192.168.0.58:8100', 'https://tpf-test.humdek.unibe.ch',
        'https://selfhelp.philhum.unibe.ch',
    );
    foreach ($listed as $entry) {
        if (strpos($origin, $entry) !== false) {
            return array(
                'Access-Control-Allow-Origin: ' . $origin,
                'Access-Control-Allow-Credentials: true',
            );
        }
    }
    return array();
}

function former_gate_accepts($origin, array $response_headers)
{
    $prefix = 'access-control-allow-origin:';
    foreach ($response_headers as $header) {
        if (strtolower(substr($header, 0, strlen($prefix))) === $prefix && trim(substr($header, strlen($prefix))) === $origin) {
            return true;
        }
    }
    return false;
}

foreach (array(
    'https://selfhelp.philhum.unibe.ch.evil.example',
    'http://localhost:8100.evil.example',
    'https://tpf-test.humdek.unibe.ch.attacker.net',
    'http://192.168.0.58.evil.example',
    'https://evilhttps://localhost:8100',
) as $lookalike) {
    $reflected = former_core_reflection($lookalike);
    check("lookalike $lookalike: the former core check reflected it", $reflected[0] === 'Access-Control-Allow-Origin: ' . $lookalike);
    check("lookalike $lookalike: the former gate trusted that reflection", former_gate_accepts($lookalike, $reflected) === true);
    check("lookalike $lookalike: the gate refuses it, whatever the response says",
        trusted_client(array(), 'cross-site', $lookalike) === false);
}

// Only the request and core's list count: a header function the CLI drops cannot be used to smuggle approval in.
$_SERVER['HTTP_SEC_FETCH_SITE'] = array('same-origin');
$_SERVER['HTTP_ORIGIN'] = $evil;
check('gate: a non-string header value is ignored, not trusted',
    LlmCsrfService::verifyRequestOrTrustedClient() === false);
unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);

check('gate: the old response-header parameter is gone',
    (new ReflectionMethod('LlmCsrfService', 'verifyRequestOrTrustedClient'))->getNumberOfParameters() === 0);

// --- the same request under the other configurations core can be in -------------------------
/**
 * Run one request through the gate in a fresh process so that the core constants can differ.
 *
 * @param string $setup PHP that defines (or not) the core constants
 * @return string 'yes', 'no' or the process output when it broke
 */
function gate_in_process($setup, $site, $origin)
{
    $code = "<?php\n" . $setup . "\n"
        . 'require ' . var_export(__DIR__ . '/../server/service/LlmCsrfService.php', true) . ";\n"
        . '$_SESSION = array(); $_POST = array();' . "\n"
        . ($site !== null ? '$_SERVER["HTTP_SEC_FETCH_SITE"] = ' . var_export($site, true) . ";\n" : '')
        . ($origin !== null ? '$_SERVER["HTTP_ORIGIN"] = ' . var_export($origin, true) . ";\n" : '')
        . 'echo LlmCsrfService::verifyRequestOrTrustedClient() ? "yes" : "no";';
    $file = tempnam(sys_get_temp_dir(), 'llm_csrf_');
    file_put_contents($file, $code);
    $process = proc_open(array(PHP_BINARY, $file), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = '';
    if (is_resource($process)) {
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    @unlink($file);
    return trim($out);
}

$listed = "array('https://app.example.org')";
check('config: the helper sees a listed origin when core CORS is on',
    gate_in_process("define('CORS', true); define('CORS_ALLOWED_ORIGINS', $listed);", 'cross-site', 'https://app.example.org') === 'yes');
check('config: a listed origin is not trusted while core CORS is off',
    gate_in_process("define('CORS', false); define('CORS_ALLOWED_ORIGINS', $listed);", 'cross-site', 'https://app.example.org') === 'no');
check('config: a listed origin is not trusted when core defines no CORS switch',
    gate_in_process("define('CORS_ALLOWED_ORIGINS', $listed);", 'cross-site', 'https://app.example.org') === 'no');
check('config: no cross-origin client is trusted by a core without the list',
    gate_in_process("define('CORS', true);", 'cross-site', 'https://app.example.org') === 'no');
check('config: a list that is not an array trusts nobody',
    gate_in_process("define('CORS', true); define('CORS_ALLOWED_ORIGINS', 'https://app.example.org');", 'cross-site', 'https://app.example.org') === 'no');
check('config: an empty list trusts nobody',
    gate_in_process("define('CORS', true); define('CORS_ALLOWED_ORIGINS', array());", 'cross-site', 'https://app.example.org') === 'no');
check('config: a list entry "null" does not make the null origin trusted',
    gate_in_process("define('CORS', true); define('CORS_ALLOWED_ORIGINS', array('null'));", 'cross-site', 'null') === 'no');
check('config: same-origin pages do not need any core configuration',
    gate_in_process('', 'same-origin', 'https://site.example') === 'yes');
check('config: the native app does not need any core configuration',
    gate_in_process('', null, null) === 'yes');
check('config: a cross-site request is refused without any core configuration',
    gate_in_process('', 'cross-site', 'https://app.example.org') === 'no');

// --- the core contract the rule relies on (skipped outside a core checkout) -----------------------
$core_root = realpath(__DIR__ . '/../../../..');
$core_selfhelp = $core_root !== false && is_file($core_root . '/Selfhelp.php') ? file_get_contents($core_root . '/Selfhelp.php') : false;
$core_globals = $core_root !== false && is_file($core_root . '/server/service/globals.php') ? file_get_contents($core_root . '/server/service/globals.php') : false;
if ($core_selfhelp !== false && $core_globals !== false) {
    // The checkout may use Windows line endings
    $core_selfhelp = str_replace("\r\n", "\n", $core_selfhelp);
    $core_globals = str_replace("\r\n", "\n", $core_globals);
}
if ($core_selfhelp === false || $core_globals === false || strpos($core_selfhelp, 'function cors()') === false) {
    echo "skip: core contract (this plugin is not inside a core checkout)\n";
} else {
    preg_match('/function cors\(\)\s*\{(.*?)\n    \}\n/s', $core_selfhelp, $cors_body);
    $cors_body = isset($cors_body[1]) ? $cors_body[1] : '';
    check('core: cors() found', $cors_body !== '');
    check('core: cors() takes its list from CORS_ALLOWED_ORIGINS', strpos($cors_body, 'CORS_ALLOWED_ORIGINS') !== false);
    check('core: cors() compares the origin exactly', strpos($cors_body, 'in_array($origin, CORS_ALLOWED_ORIGINS, true)') !== false);
    check('core: cors() has no substring or prefix test left',
        preg_match('/\b(strpos|stripos|strstr|stristr|substr|preg_match|fnmatch)\s*\(/', $cors_body) === 0);
    check('core: cors() reflects only the checked origin', strpos($cors_body, 'Access-Control-Allow-Origin: {$origin}') !== false
        && strpos($cors_body, "Access-Control-Allow-Origin: {\$_SERVER") === false);
    check('core: globals.php defines the default list', strpos($core_globals, "define('CORS_ALLOWED_ORIGINS'") !== false);
    preg_match("/\n\s+define\('CORS_ALLOWED_ORIGINS', array\((.*?)\)\);/s", $core_globals, $default_list);
    preg_match_all("/'([^']*)'/", isset($default_list[1]) ? $default_list[1] : '', $entries);
    check('core: the default list is not empty', !empty($entries[1]));
    $malformed = array_filter($entries[1], function ($entry) {
        return preg_match('~^https?://[a-z0-9.\-]+(:[0-9]{1,5})?$~', $entry) !== 1;
    });
    check('core: every default entry is a bare origin (scheme, host, optional port)', empty($malformed));
}

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
