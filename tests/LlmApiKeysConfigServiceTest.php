<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for the API key protection (no database, no web server):
 * LlmSecretService (encryption / env references / hints),
 * LlmSettingsValidator (base URL, numeric bounds) and
 * LlmApiKeysConfigService (what a browser sees, "keep existing", validation).
 *
 * Run: php tests/LlmApiKeysConfigServiceTest.php
 */

require_once __DIR__ . '/../server/service/LlmSecretService.php';
require_once __DIR__ . '/../server/service/LlmSettingsValidator.php';
require_once __DIR__ . '/../server/service/LlmApiKeysConfigService.php';

// The services log (without secrets) when something cannot be read; keep the test output clean.
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_api_keys_config_test.log');

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

function masterOn($secret = '0123456789abcdef0123456789abcdef')
{
    putenv('LLM_SECRET_KEY=' . $secret);
}

function masterOff()
{
    putenv('LLM_SECRET_KEY');
}

/** Submit the way the browser does and return [json|null, errors]. */
function submit(array $entries, $stored = '')
{
    $errors = array();
    $json = LlmApiKeysConfigService::prepareForSave(json_encode($entries), $stored, $errors);
    return array($json, $errors);
}

$KEY = 'sk-test-0123456789abcdef';

// =============================================================================
// LlmSecretService
// =============================================================================
masterOff();
check('no master secret: encryption not available', LlmSecretService::isEncryptionAvailable() === false);
check('no master secret: protect keeps the key as it is', LlmSecretService::protect($KEY) === $KEY);
check('plain text keys still resolve', LlmSecretService::resolve($KEY) === $KEY);

masterOn('too-short');
check('a master secret shorter than 16 characters is ignored', LlmSecretService::isEncryptionAvailable() === false);

masterOn();
check('master secret: encryption available', LlmSecretService::isEncryptionAvailable() === true);
$enc = LlmSecretService::protect($KEY);
check('protect encrypts with the enc:v1: prefix', strpos($enc, 'enc:v1:') === 0);
check('the ciphertext does not contain the key', strpos($enc, $KEY) === false);
check('resolve returns the key again', LlmSecretService::resolve($enc) === $KEY);
check('every protect uses a fresh IV', LlmSecretService::protect($KEY) !== $enc);
check('protect is idempotent for encrypted values', LlmSecretService::protect($enc) === $enc);
check('protect trims whitespace', LlmSecretService::resolve(LlmSecretService::protect("  $KEY \n")) === $KEY);
check('protect of an empty key is empty', LlmSecretService::protect('') === '');

$tampered = substr($enc, 0, -4) . (substr($enc, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
check('a tampered ciphertext does not resolve', LlmSecretService::resolve($tampered) === '');
check('a damaged value does not resolve', LlmSecretService::resolve('enc:v1:%%%') === '');
masterOn('another-master-secret-0123456789');
check('another master secret cannot read it', LlmSecretService::resolve($enc) === '');
masterOff();
check('without master secret an encrypted key does not resolve', LlmSecretService::resolve($enc) === '');
masterOn();

// Environment references
check('env:LLM_NAME is a reference', LlmSecretService::isEnvReference('env:LLM_API_KEY_MAIN') === true);
check('env: names outside LLM_ are refused', LlmSecretService::isEnvReference('env:DB_PASSWORD') === false);
check('the master secret itself cannot be referenced', LlmSecretService::isEnvReference('env:LLM_SECRET_KEY') === false);
check('lower case names are refused', LlmSecretService::isEnvReference('env:LLM_key') === false);
check('a reference with spaces is refused', LlmSecretService::isEnvReference('env:LLM_A B') === false);
check('protect leaves a reference alone', LlmSecretService::protect('env:LLM_API_KEY_MAIN') === 'env:LLM_API_KEY_MAIN');
putenv('LLM_API_KEY_MAIN=  sk-from-env-0123456789  ');
check('resolve reads the environment variable (trimmed)', LlmSecretService::resolve('env:LLM_API_KEY_MAIN') === 'sk-from-env-0123456789');
putenv('LLM_API_KEY_MAIN');
check('an unset variable resolves to nothing', LlmSecretService::resolve('env:LLM_API_KEY_MAIN') === '');
check('a refused reference resolves to nothing', LlmSecretService::resolve('env:LLM_SECRET_KEY') === '');
putenv('LLM_SECRET_KEY=' . '0123456789abcdef0123456789abcdef');
check('the master secret is not readable through a reference', LlmSecretService::resolve('env:LLM_SECRET_KEY') === '');

// describe() is what a browser may see
$d = LlmSecretService::describe('');
check('describe: no key', $d['has_key'] === false && $d['key_source'] === 'none' && $d['key_hint'] === '');
$d = LlmSecretService::describe($enc);
check('describe: encrypted key shows source and last four', $d['has_key'] && $d['key_source'] === 'encrypted' && $d['key_hint'] === "\u{2026}cdef" && $d['key_readable']);
check('describe never contains the key', strpos(json_encode($d), $KEY) === false && strpos(json_encode($d), '0123456789abcdef') === false);
$d = LlmSecretService::describe($KEY);
check('describe: plain key is labelled plain', $d['key_source'] === 'plain' && $d['key_hint'] === "\u{2026}cdef");
$d = LlmSecretService::describe('env:LLM_API_KEY_MAIN');
check('describe: reference shows its name and is unreadable while unset', $d['key_source'] === 'env' && $d['key_hint'] === 'env:LLM_API_KEY_MAIN' && $d['key_readable'] === false);
putenv('LLM_API_KEY_MAIN=sk-from-env-0123456789');
check('describe: reference is readable once the variable is set', LlmSecretService::describe('env:LLM_API_KEY_MAIN')['key_readable'] === true);
putenv('LLM_API_KEY_MAIN');
check('describe: no hint for keys shorter than 12 characters', LlmSecretService::describe('sk-12345678')['key_hint'] === '');
check('describe: hint from 12 characters', LlmSecretService::describe('sk-123456789')['key_hint'] === "\u{2026}6789");
$d = LlmSecretService::describe('enc:v1:%%%');
check('describe: a damaged key is reported unreadable', $d['has_key'] === true && $d['key_readable'] === false && $d['key_hint'] === '');

// =============================================================================
// LlmSettingsValidator - scalar settings
// =============================================================================
$ctx = array('reasoning_codes' => array('low', 'high'), 'storage_modes' => array('memory_storage_both'));
function val($name, $raw, $ctx)
{
    return LlmSettingsValidator::validate($name, $raw, $ctx);
}
check('temperature 0.7 is accepted', val('llm_temperature', '0.7', $ctx) === array('value' => '0.7', 'error' => null));
check('temperature 1 stays 1', val('llm_temperature', '1', $ctx)['value'] === '1');
check('temperature 0 and 2 are accepted', val('llm_temperature', '0', $ctx)['value'] === '0' && val('llm_temperature', '2', $ctx)['value'] === '2');
check('temperature is trimmed', val('llm_temperature', ' 0.5 ', $ctx)['value'] === '0.5');
check('blank temperature means plugin default', val('llm_temperature', '', $ctx)['value'] === '');
check('temperature above 2 is refused', val('llm_temperature', '2.01', $ctx)['error'] !== null);
check('negative temperature is refused', val('llm_temperature', '-0.1', $ctx)['error'] !== null);
check('text temperature is refused', val('llm_temperature', 'hot', $ctx)['error'] !== null);
check('exponent temperature is refused', val('llm_temperature', '1e3', $ctx)['error'] !== null);
check('array temperature is refused', val('llm_temperature', array('1'), $ctx)['error'] !== null);
check('max tokens 2048 is accepted', val('llm_max_tokens', '2048', $ctx)['value'] === '2048');
check('max tokens 0 is refused', val('llm_max_tokens', '0', $ctx)['error'] !== null);
check('max tokens above the limit is refused', val('llm_max_tokens', '200001', $ctx)['error'] !== null);
check('max tokens at the limit is accepted', val('llm_max_tokens', '200000', $ctx)['error'] === null);
check('fractional max tokens is refused', val('llm_max_tokens', '12.5', $ctx)['error'] !== null);
check('negative max tokens is refused', val('llm_max_tokens', '-5', $ctx)['error'] !== null);
check('huge max tokens is refused', val('llm_max_tokens', '99999999999999999999', $ctx)['error'] !== null);
check('blank max tokens means plugin default', val('llm_max_tokens', '', $ctx)['value'] === '');
check('timeout 30 is accepted', val('llm_timeout', '30', $ctx)['value'] === '30');
check('timeout 0 is refused', val('llm_timeout', '0', $ctx)['error'] !== null);
check('timeout above 600 is refused', val('llm_timeout', '601', $ctx)['error'] !== null);
check('model name is accepted', val('llm_default_model', 'gpt-oss-120b', $ctx)['value'] === 'gpt-oss-120b');
check('model name with a line break is refused', val('llm_default_model', "gpt\nx", $ctx)['error'] !== null);
check('too long model name is refused', val('llm_default_model', str_repeat('a', 256), $ctx)['error'] !== null);
check('reasoning effort from the lookups is accepted', val('llm_reasoning_effort', 'low', $ctx)['value'] === 'low');
check('reasoning effort default / blank are accepted', val('llm_reasoning_effort', 'default', $ctx)['error'] === null && val('llm_reasoning_effort', '', $ctx)['error'] === null);
check('unknown reasoning effort is refused', val('llm_reasoning_effort', 'turbo', $ctx)['error'] !== null);
check('memory flag accepts 0 and 1 only', val('llm_memory_enabled', '1', $ctx)['error'] === null && val('llm_memory_enabled', '0', $ctx)['error'] === null && val('llm_memory_enabled', '2', $ctx)['error'] !== null);
check('storage mode from the lookups is accepted', val('llm_memory_storage_mode', 'memory_storage_both', $ctx)['error'] === null);
check('unknown storage mode is refused', val('llm_memory_storage_mode', 'drop table', $ctx)['error'] !== null);
check('unknown setting is refused', val('llm_whatever', '1', $ctx)['error'] !== null);

// =============================================================================
// LlmSettingsValidator - base URL
// =============================================================================
putenv('LLM_ALLOWED_API_HOSTS');
function url($u)
{
    return LlmSettingsValidator::validateBaseUrl($u);
}
check('https URL is accepted', url('https://gpustack.example.com/v1')['value'] === 'https://gpustack.example.com/v1');
check('trailing slash is removed', url('https://gpustack.example.com/v1/')['value'] === 'https://gpustack.example.com/v1');
check('https with port is accepted', url('https://llm.example.com:8443/v1')['error'] === null);
check('plain http is refused', url('http://llm.example.com/v1')['error'] !== null);
check('http is accepted for localhost', url('http://localhost:11434/v1')['error'] === null);
check('http is accepted for 127.0.0.1', url('http://127.0.0.1:8080')['error'] === null);
check('http is accepted for ::1', url('http://[::1]:8080/v1')['error'] === null);
check('other schemes are refused', url('ftp://llm.example.com')['error'] !== null && url('javascript:alert(1)')['error'] !== null);
check('credentials in the URL are refused', url('https://user:pw@llm.example.com/v1')['error'] !== null);
check('a query string is refused', url('https://llm.example.com/v1?key=1')['error'] !== null);
check('a fragment is refused', url('https://llm.example.com/v1#x')['error'] !== null);
check('spaces are refused', url('https://llm.example.com/ v1')['error'] !== null);
check('an empty URL is refused', url('')['error'] !== null);
check('text is refused', url('not a url')['error'] !== null && url('https://')['error'] !== null);
check('a non-string is refused', url(array('https://x.example.com'))['error'] !== null);

putenv('LLM_ALLOWED_API_HOSTS=gpustack.example.com, *.models.example.org');
check('allow-list: listed host is accepted', url('https://gpustack.example.com/v1')['error'] === null);
check('allow-list: other host is refused', url('https://evil.example.net/v1')['error'] !== null);
check('allow-list: wildcard accepts sub domains', url('https://a.models.example.org/v1')['error'] === null);
check('allow-list: wildcard does not accept the apex', url('https://models.example.org/v1')['error'] !== null);
check('allow-list: wildcard needs the dot boundary', url('https://xmodels.example.org/v1')['error'] !== null);
check('allow-list: matching is case insensitive', url('https://GPUSTACK.example.com/v1')['error'] === null);
putenv('LLM_ALLOWED_API_HOSTS');
check('no allow-list: any https host is accepted again', url('https://evil.example.net/v1')['error'] === null);

check('origin ignores path, case and default port', LlmSettingsValidator::originOf('https://Host.com/v1') === LlmSettingsValidator::originOf('https://host.com:443/other'));
check('origin differs by scheme', LlmSettingsValidator::originOf('http://localhost') !== LlmSettingsValidator::originOf('https://localhost'));
check('origin differs by port', LlmSettingsValidator::originOf('https://h.com:8443') !== LlmSettingsValidator::originOf('https://h.com'));
check('origin of garbage is empty', LlmSettingsValidator::originOf('garbage') === '');

// =============================================================================
// LlmApiKeysConfigService
// =============================================================================
masterOn();
$A = array('name' => 'Server A', 'base_url' => 'https://a.example.com/v1', 'api_key' => $KEY);

list($stored, $errors) = submit(array($A));
check('a new server with a key is accepted', $stored !== null && $errors === array());
$entries = json_decode($stored, true);
check('the stored key is encrypted, not plain', strpos($entries[0]['api_key'], 'enc:v1:') === 0 && strpos($stored, $KEY) === false);
check('the stored key decrypts to the typed key', LlmSecretService::resolve($entries[0]['api_key']) === $KEY);
check('every entry gets a 16 hex id', preg_match('/^[a-f0-9]{16}$/', $entries[0]['id']) === 1);
$idA = $entries[0]['id'];

// What a browser gets
$client = LlmApiKeysConfigService::toClient($stored);
$clientJson = LlmApiKeysConfigService::toClientJson($stored);
check('the browser list has no api_key field', !array_key_exists('api_key', $client[0]));
check('the browser list never contains the key or ciphertext', strpos($clientJson, $KEY) === false && strpos($clientJson, 'enc:v1:') === false);
check('the browser list shows has_key and the last four', $client[0]['has_key'] === true && $client[0]['key_hint'] === "\u{2026}cdef" && $client[0]['key_source'] === 'encrypted');
check('the browser list keeps the id', $client[0]['id'] === $idA);

// Keep existing: browser sends the list back without keys
$back = $client;
$back[0]['name'] = 'Server A renamed';
$back[0]['base_url'] = 'https://a.example.com/v2';
list($kept, $errors) = submit($back, $stored);
check('keeping a key while renaming / changing the path works', $kept !== null && $errors === array());
$keptEntries = json_decode($kept, true);
check('the kept entry has the identical stored key', $keptEntries[0]['api_key'] === $entries[0]['api_key']);
check('the kept entry keeps its id', $keptEntries[0]['id'] === $idA);
check('the new name and path are stored', $keptEntries[0]['name'] === 'Server A renamed' && $keptEntries[0]['base_url'] === 'https://a.example.com/v2');

$moved = $client;
$moved[0]['base_url'] = 'https://evil.example.net/v1';
list($json, $errors) = submit($moved, $stored);
check('keeping a key is refused when the host changes', $json === null && count($errors) === 1);
check('the refusal does not contain the key', strpos(implode(' ', $errors), $KEY) === false);
$moved[0]['base_url'] = 'http://localhost/v1';
check('keeping a key is refused when scheme/host change', submit($moved, $stored)[0] === null);
$moved[0]['base_url'] = 'https://a.example.com:8443/v1';
check('keeping a key is refused when the port changes', submit($moved, $stored)[0] === null);
$moved = $client;
$moved[0]['base_url'] = 'https://evil.example.net/v1';
$moved[0]['api_key'] = 'sk-new-key-0123456789';
list($json, $errors) = submit($moved, $stored);
check('a changed host is accepted with a newly typed key', $json !== null && LlmSecretService::resolve(json_decode($json, true)[0]['api_key']) === 'sk-new-key-0123456789');

$replace = $client;
$replace[0]['api_key'] = 'sk-replaced-0123456789';
list($json) = submit($replace, $stored);
check('a typed key replaces the stored key (same id)', $json !== null && json_decode($json, true)[0]['id'] === $idA && LlmSecretService::resolve(json_decode($json, true)[0]['api_key']) === 'sk-replaced-0123456789');

$clear = $client;
$clear[0]['clear_key'] = true;
list($json) = submit($clear, $stored);
check('clear_key removes the key', $json !== null && json_decode($json, true)[0]['api_key'] === '');
check('a cleared entry reports has_key false', LlmApiKeysConfigService::toClient($json)[0]['has_key'] === false);

$gone = array(array('id' => 'ffffffffffffffff', 'name' => 'Ghost', 'base_url' => 'https://g.example.com/v1'));
list($json, $errors) = submit($gone, $stored);
check('an unknown id without a key is refused (never a silent key loss)', $json === null && count($errors) === 1);
$gone[0]['api_key'] = 'sk-ghost-0123456789';
check('an unknown id with a typed key is accepted as a new server', submit($gone, $stored)[0] !== null);

list($json) = submit(array(array('name' => 'Local', 'base_url' => 'http://localhost:11434/v1')));
check('a new server without a key is accepted (local servers)', $json !== null && json_decode($json, true)[0]['api_key'] === '');

list($json) = submit(array());
check('an empty list is accepted', $json === '[]');

// Several entries, order and independence
list($multi) = submit(array($A, array('name' => 'Server B', 'base_url' => 'https://b.example.com/v1', 'api_key' => 'sk-b-key-0123456789')));
$multiClient = LlmApiKeysConfigService::toClient($multi);
check('two servers keep independent keys', count($multiClient) === 2 && $multiClient[0]['key_hint'] === "\u{2026}cdef" && $multiClient[1]['key_hint'] === "\u{2026}6789");
$swapped = array($multiClient[1], $multiClient[0]);
list($json) = submit($swapped, $multi);
$swappedEntries = json_decode($json, true);
check('reordering keeps every key with its server', LlmSecretService::resolve($swappedEntries[0]['api_key']) === 'sk-b-key-0123456789' && LlmSecretService::resolve($swappedEntries[1]['api_key']) === $KEY);
$dupId = array($multiClient[0], $multiClient[0]);
$dupId[1]['name'] = 'Server A copy';
list($json, $errors) = submit($dupId, $multi);
check('the same id twice cannot clone a stored key', $json === null);

// Validation
check('duplicate names are refused (case-insensitive)', submit(array($A, array_merge($A, array('name' => 'server a'))))[0] === null);
check('an empty name is refused', submit(array(array_merge($A, array('name' => '  '))))[0] === null);
check('a name with the model separator is refused', submit(array(array_merge($A, array('name' => 'A :: B'))))[0] === null);
check('a name with control characters is refused', submit(array(array_merge($A, array('name' => "A\nB"))))[0] === null);
check('a too long name is refused', submit(array(array_merge($A, array('name' => str_repeat('n', 101)))))[0] === null);
check('http base URL is refused', submit(array(array_merge($A, array('base_url' => 'http://a.example.com/v1'))))[0] === null);
check('a missing base URL is refused', submit(array(array('name' => 'X', 'api_key' => $KEY)))[0] === null);
list($json, $errors) = submit(array(array_merge($A, array('api_key' => 'sk bad key'))));
check('a key with a space is refused', $json === null && strpos(implode(' ', $errors), 'sk bad key') === false);
check('a key with a line break (header injection) is refused', submit(array(array_merge($A, array('api_key' => "sk-abc\r\nX-Evil: 1"))))[0] === null);
check('a too long key is refused', submit(array(array_merge($A, array('api_key' => str_repeat('k', 513)))))[0] === null);
check('a pasted ciphertext is refused', submit(array(array_merge($A, array('api_key' => $enc))))[0] === null);
check('an invalid env reference is refused', submit(array(array_merge($A, array('api_key' => 'env:DB_PASSWORD'))))[0] === null);
list($json) = submit(array(array_merge($A, array('api_key' => 'env:LLM_API_KEY_MAIN'))));
check('a valid env reference is stored as is', $json !== null && json_decode($json, true)[0]['api_key'] === 'env:LLM_API_KEY_MAIN');
check('too many servers are refused', submit(array_fill(0, 26, $A))[0] === null);
$errors = array();
check('an object instead of a list is refused', LlmApiKeysConfigService::prepareForSave('{"name":"A"}', '', $errors) === null && count($errors) === 1);
check('invalid JSON is refused', LlmApiKeysConfigService::prepareForSave('{nope', '', $errors) === null);
check('a non-array entry is refused', LlmApiKeysConfigService::prepareForSave('["x"]', '', $errors) === null);
list($json, $errors) = submit(array(array_merge($A, array('name' => 'Bad', 'base_url' => 'http://x.example.com')), array_merge($A, array('name' => ''))));
check('every problem is reported, nothing is stored', $json === null && count($errors) === 2);

// Legacy plain text keys
$legacy = json_encode(array(array('name' => 'Old', 'base_url' => 'https://old.example.com/v1', 'api_key' => 'sk-legacy-0123456789')));
check('legacy keys are counted as plain', LlmApiKeysConfigService::countPlainKeys($legacy) === 1);
check('encrypted / env / empty keys are not counted', LlmApiKeysConfigService::countPlainKeys($stored) === 0
    && LlmApiKeysConfigService::countPlainKeys(json_encode(array(array('name' => 'E', 'base_url' => 'https://e.example.com', 'api_key' => 'env:LLM_X')))) === 0
    && LlmApiKeysConfigService::countPlainKeys(json_encode(array(array('name' => 'N', 'base_url' => 'https://n.example.com')))) === 0);
$legacyClient = LlmApiKeysConfigService::toClient($legacy);
check('legacy entries get a stable id from name and URL', preg_match('/^[a-f0-9]{16}$/', $legacyClient[0]['id']) === 1 && $legacyClient[0]['id'] === LlmApiKeysConfigService::toClient($legacy)[0]['id']);
check('legacy keys are labelled plain, without the key', $legacyClient[0]['key_source'] === 'plain' && strpos(json_encode($legacyClient), 'sk-legacy-0123456789') === false);
list($json) = submit($legacyClient, $legacy);
$migrated = json_decode($json, true);
check('keeping a legacy plain key encrypts it', strpos($migrated[0]['api_key'], 'enc:v1:') === 0 && LlmSecretService::resolve($migrated[0]['api_key']) === 'sk-legacy-0123456789');
check('the legacy id is kept through the migration', $migrated[0]['id'] === $legacyClient[0]['id']);

$converted = -1;
$protected = LlmApiKeysConfigService::protectStoredKeys($legacy, $converted);
check('protectStoredKeys encrypts plain keys and counts them', $converted === 1 && strpos($protected, 'sk-legacy-0123456789') === false && LlmApiKeysConfigService::countPlainKeys($protected) === 0);
check('protectStoredKeys keeps the ids', json_decode($protected, true)[0]['id'] === $legacyClient[0]['id']);
LlmApiKeysConfigService::protectStoredKeys($protected, $converted);
check('protectStoredKeys is idempotent', $converted === 0);

masterOff();
$converted = -1;
$unchanged = LlmApiKeysConfigService::protectStoredKeys($legacy, $converted);
check('without a master secret protectStoredKeys converts nothing', $converted === 0 && LlmApiKeysConfigService::countPlainKeys($unchanged) === 1);
list($json) = submit(array($A));
check('without a master secret a typed key is stored plain (legacy behaviour)', $json !== null && json_decode($json, true)[0]['api_key'] === $KEY);
list($json) = submit($legacyClient, $legacy);
check('without a master secret a kept legacy key stays as it is', $json !== null && json_decode($json, true)[0]['api_key'] === 'sk-legacy-0123456789');
masterOn();
list($json) = submit($client, $stored);
masterOff();
check('keeping an encrypted key does not need the master secret', $json !== null && json_decode($json, true)[0]['api_key'] === $entries[0]['api_key']);

check('garbage stored values decode to an empty list', LlmApiKeysConfigService::decodeStored('') === array()
    && LlmApiKeysConfigService::decodeStored('not json') === array()
    && LlmApiKeysConfigService::decodeStored(null) === array());

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
