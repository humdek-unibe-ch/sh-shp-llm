<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for LlmCsrfService (no database, no web server).
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

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
