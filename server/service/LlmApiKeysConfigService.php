<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmSecretService.php';
require_once __DIR__ . '/LlmSettingsValidator.php';

/**
 * LLM API Keys Config Service
 *
 * The `llm_api_keys` page field is a JSON list of API servers. The list is
 * stored as
 *   [{"id":"<16 hex>","name":"...","base_url":"https://...","api_key":"<stored form>"}]
 * where the stored form of the key is described by LlmSecretService
 * (encrypted, `env:LLM_NAME` reference, or legacy plain text).
 *
 * Browsers never receive a key. They get toClient(): the same list with
 * `has_key`, `key_hint` ("..." + last four characters), `key_source` and
 * `key_readable` instead of `api_key`. When they save, an entry is matched
 * to the stored one by `id`:
 *   - `api_key` typed          -> new key (validated, then protected)
 *   - `clear_key` true         -> key removed
 *   - neither                  -> "keep existing" (only while the server
 *                                 address - scheme, host, port - is unchanged,
 *                                 so the stored key cannot be redirected to
 *                                 another host)
 * prepareForSave() does all of it and either returns the JSON to store or
 * the list of errors; nothing is stored on errors.
 *
 * Used by the settings console and by the CMS field editor, so both paths
 * validate, merge and protect the same way.
 *
 * @package LLM Plugin
 */
class LlmApiKeysConfigService
{
    const MAX_ENTRIES = 25;
    const NAME_MAX_LENGTH = 100;
    const SECRET_MAX_LENGTH = 512;

    /** Separator of "Server name :: model" ids (BaseLlmService::MODEL_SERVER_SEPARATOR). */
    const MODEL_SERVER_SEPARATOR = ' :: ';

    /**
     * Normalized entries of the stored JSON. `api_key` is the stored form.
     *
     * @param mixed $json Stored page field value
     * @return array<int, array{id:string,name:string,base_url:string,api_key:string}>
     */
    public static function decodeStored($json)
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return array();
        }

        $entries = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = array(
                'id' => '',
                'name' => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '',
                'base_url' => isset($entry['base_url']) && is_string($entry['base_url']) ? rtrim($entry['base_url'], '/') : '',
                'api_key' => isset($entry['api_key']) && is_string($entry['api_key']) ? $entry['api_key'] : '',
            );
            $normalized['id'] = self::entryId(array_merge($normalized, array('id' => $entry['id'] ?? '')));
            $entries[] = $normalized;
        }
        return $entries;
    }

    /**
     * Stable id of an entry: the stored one, or - for entries saved before
     * ids existed - a hash of name and base URL.
     *
     * @param array $entry
     * @return string 16 hex characters
     */
    public static function entryId(array $entry)
    {
        if (isset($entry['id']) && is_string($entry['id']) && preg_match('/^[a-f0-9]{16}$/', $entry['id']) === 1) {
            return $entry['id'];
        }
        return substr(sha1((string)($entry['name'] ?? '') . "\n" . rtrim((string)($entry['base_url'] ?? ''), '/')), 0, 16);
    }

    /**
     * The list a browser may see: no key, only what describe() allows.
     *
     * @param mixed $json Stored page field value
     * @return array<int, array>
     */
    public static function toClient($json)
    {
        $entries = array();
        foreach (self::decodeStored($json) as $entry) {
            $entries[] = array_merge(
                array('id' => $entry['id'], 'name' => $entry['name'], 'base_url' => $entry['base_url']),
                LlmSecretService::describe($entry['api_key'])
            );
        }
        return $entries;
    }

    /**
     * @param mixed $json Stored page field value
     * @return string toClient() as JSON
     */
    public static function toClientJson($json)
    {
        $encoded = json_encode(self::toClient($json), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * Number of keys stored as plain text (neither encrypted nor an `env:` reference).
     *
     * @param mixed $json Stored page field value
     * @return int
     */
    public static function countPlainKeys($json)
    {
        $count = 0;
        foreach (self::decodeStored($json) as $entry) {
            if (self::isPlain($entry['api_key'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Encrypt the plain text keys of the stored JSON.
     *
     * @param mixed $json Stored page field value
     * @param int $converted Out: number of keys that were encrypted
     * @return string JSON to store (unchanged keys stay as they are; without a
     *                master secret nothing is converted)
     * @throws RuntimeException When OpenSSL fails
     */
    public static function protectStoredKeys($json, &$converted)
    {
        $converted = 0;
        $entries = self::decodeStored($json);
        foreach ($entries as $index => $entry) {
            if (self::isPlain($entry['api_key'])) {
                $protected = LlmSecretService::protect($entry['api_key']);
                if ($protected !== $entry['api_key']) {
                    $entries[$index]['api_key'] = $protected;
                    $converted++;
                }
            }
        }
        return json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate the list a browser submitted, merge it with the stored one
     * ("keep existing" keys) and protect new keys.
     *
     * @param string|array $submitted JSON (or the decoded list) from the browser
     * @param mixed $stored_json Currently stored page field value
     * @param array $errors Out: error messages, empty on success
     * @return string|null JSON to store, or null when there are errors
     */
    public static function prepareForSave($submitted, $stored_json, &$errors)
    {
        $errors = array();

        if (is_string($submitted)) {
            $submitted = json_decode($submitted, true);
        }
        if (!is_array($submitted) || ($submitted !== array() && array_keys($submitted) !== range(0, count($submitted) - 1))) {
            $errors[] = 'The API server list is not valid.';
            return null;
        }
        if (count($submitted) > self::MAX_ENTRIES) {
            $errors[] = 'At most ' . self::MAX_ENTRIES . ' API servers can be configured.';
            return null;
        }

        $stored_by_id = array();
        foreach (self::decodeStored($stored_json) as $stored) {
            $stored_by_id[$stored['id']] = $stored;
        }

        $entries = array();
        $seen_names = array();
        $used_ids = array();
        foreach ($submitted as $index => $entry) {
            if (!is_array($entry)) {
                $errors[] = 'Server ' . ($index + 1) . ': the entry is not valid.';
                continue;
            }

            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            $label = $name === ''
                ? 'Server ' . ($index + 1)
                : '"' . preg_replace('/[\x00-\x1f\x7f]/', ' ', self::truncate($name, 40)) . '"';

            $name_error = self::validateName($name, $seen_names);
            if ($name_error !== null) {
                $errors[] = $label . ': ' . $name_error;
            }

            $url = LlmSettingsValidator::validateBaseUrl($entry['base_url'] ?? '');
            if ($url['error'] !== null) {
                $errors[] = $label . ': ' . $url['error'];
            }

            $id = isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : '';
            $matched = ($id !== '' && isset($stored_by_id[$id]) && !isset($used_ids[$id])) ? $stored_by_id[$id] : null;
            if ($matched !== null) {
                $used_ids[$id] = true;
            }
            $typed = isset($entry['api_key']) && is_string($entry['api_key']) ? trim($entry['api_key']) : '';

            $api_key = '';
            try {
                if ($typed !== '') {
                    $secret_error = self::validateSecret($typed);
                    if ($secret_error !== null) {
                        $errors[] = $label . ': ' . $secret_error;
                    } else {
                        $api_key = LlmSecretService::protect($typed);
                    }
                } elseif (!empty($entry['clear_key'])) {
                    $api_key = '';
                } elseif ($matched !== null) {
                    if ($url['value'] !== null
                        && LlmSettingsValidator::originOf($url['value']) !== LlmSettingsValidator::originOf($matched['base_url'])) {
                        $errors[] = $label . ': enter the API key again - the server address (scheme, host or port) changed, so the stored key is not kept.';
                    } else {
                        $api_key = LlmSecretService::protect($matched['api_key']);
                    }
                } elseif ($id !== '') {
                    $errors[] = $label . ': this entry was changed or removed in the meantime. Reload the page and try again.';
                }
            } catch (RuntimeException $e) {
                $errors[] = $label . ': the API key could not be protected.';
            }

            $entries[] = array(
                'id' => $matched !== null ? $matched['id'] : bin2hex(random_bytes(8)),
                'name' => $name,
                'base_url' => $url['value'] ?? '',
                'api_key' => $api_key,
            );
        }

        if (!empty($errors)) {
            return null;
        }

        $encoded = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $errors[] = 'The API server list could not be encoded.';
            return null;
        }
        return $encoded;
    }

    /**
     * @param string $stored Stored form of a key
     * @return bool True for non-empty keys that are neither encrypted nor references
     */
    private static function isPlain($stored)
    {
        return $stored !== ''
            && !LlmSecretService::isEncrypted($stored)
            && strpos($stored, LlmSecretService::ENV_PREFIX) !== 0;
    }

    /**
     * @param string $name Trimmed name
     * @param array $seen_names In/out: lower case names seen so far
     * @return string|null Error message
     */
    private static function validateName($name, array &$seen_names)
    {
        if ($name === '') {
            return 'the name is required.';
        }
        if (self::length($name) > self::NAME_MAX_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return 'the name must be at most ' . self::NAME_MAX_LENGTH . ' characters without control characters.';
        }
        if (strpos($name, self::MODEL_SERVER_SEPARATOR) !== false) {
            return 'the name must not contain "' . self::MODEL_SERVER_SEPARATOR . '".';
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (isset($seen_names[$key])) {
            return 'another server already uses this name.';
        }
        $seen_names[$key] = true;
        return null;
    }

    /**
     * @param string $secret Trimmed, non-empty key typed by the administrator
     * @return string|null Error message
     */
    private static function validateSecret($secret)
    {
        if (strlen($secret) > self::SECRET_MAX_LENGTH) {
            return 'the API key is too long.';
        }
        if (LlmSecretService::isEncrypted($secret)) {
            return 'the API key is not valid.';
        }
        if (strpos($secret, LlmSecretService::ENV_PREFIX) === 0) {
            return LlmSecretService::isEnvReference($secret)
                ? null
                : 'an environment reference must look like env:LLM_MY_KEY (names start with LLM_, upper case letters, digits and underscores).';
        }
        if (preg_match('/^[\x21-\x7e]+$/', $secret) !== 1) {
            return 'the API key may only contain visible ASCII characters, no spaces.';
        }
        return null;
    }

    /**
     * @param string $value
     * @return int Length in characters
     */
    private static function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * @param string $value
     * @param int $length
     * @return string
     */
    private static function truncate($value, $length)
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
