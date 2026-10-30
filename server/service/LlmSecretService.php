<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Secret Service
 *
 * Keeps provider API keys out of the database in plain text. A stored key
 * is one of:
 *
 *  - `enc:v1:<base64>`  AES-256-GCM ciphertext. The master secret is the
 *                       environment variable (or PHP constant) LLM_SECRET_KEY
 *                       (at least 16 characters), kept outside the database.
 *  - `env:LLM_NAME`     a reference to an environment variable; the key itself
 *                       is never stored. Only names starting with `LLM_` are
 *                       accepted (and never LLM_SECRET_KEY itself), so a
 *                       reference cannot be pointed at unrelated secrets.
 *  - anything else      legacy plain text. It keeps working and is re-protected
 *                       the next time the settings are saved / "Encrypt stored
 *                       keys" is used.
 *
 * Only resolve() returns a usable key and only the provider call path should
 * use it. Anything shown to a browser goes through describe(), which never
 * contains more than the last four characters of a long key.
 *
 * @package LLM Plugin
 */
class LlmSecretService
{
    /** Environment variable / PHP constant that holds the master secret. */
    const MASTER_KEY_NAME = 'LLM_SECRET_KEY';

    /** Prefix of encrypted values. */
    const ENCRYPTED_PREFIX = 'enc:v1:';

    /** Prefix of environment references. */
    const ENV_PREFIX = 'env:';

    /** Cipher used for stored keys. */
    const CIPHER = 'aes-256-gcm';

    /** Minimum length of the master secret. */
    const MIN_MASTER_LENGTH = 16;

    /** Keys shorter than this never show a hint (the last four would be too much of them). */
    const HINT_MIN_LENGTH = 12;

    /**
     * Whether new keys can be encrypted: a master secret is configured and
     * OpenSSL supports AES-256-GCM.
     *
     * @return bool
     */
    public static function isEncryptionAvailable()
    {
        return self::getMasterSecret() !== ''
            && function_exists('openssl_encrypt')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /**
     * @param mixed $stored
     * @return bool True for `env:LLM_NAME` references that are allowed
     */
    public static function isEnvReference($stored)
    {
        return is_string($stored)
            && preg_match('/^env:LLM_[A-Z0-9_]{1,60}$/', $stored) === 1
            && $stored !== self::ENV_PREFIX . self::MASTER_KEY_NAME;
    }

    /**
     * @param mixed $stored
     * @return bool True for `enc:v1:...` values
     */
    public static function isEncrypted($stored)
    {
        return is_string($stored) && strpos($stored, self::ENCRYPTED_PREFIX) === 0;
    }

    /**
     * Value to store for a key. Environment references and values that are
     * already encrypted are returned unchanged. Without a master secret the
     * key is returned as it is (plain text) - check isEncryptionAvailable()
     * when that matters.
     *
     * @param string $secret
     * @return string
     * @throws RuntimeException When OpenSSL fails to encrypt
     */
    public static function protect($secret)
    {
        $secret = is_string($secret) ? trim($secret) : '';
        if ($secret === '' || self::isEnvReference($secret) || self::isEncrypted($secret)) {
            return $secret;
        }
        if (!self::isEncryptionAvailable()) {
            return $secret;
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $secret,
            self::CIPHER,
            self::deriveKey(self::getMasterSecret()),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('The API key could not be encrypted.');
        }
        return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * The usable key for a stored value.
     *
     * @param mixed $stored Stored value (plain text, `enc:v1:...` or `env:LLM_...`)
     * @return string The key, or '' when it cannot be resolved (logged without the secret)
     */
    public static function resolve($stored)
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }

        if (strpos($stored, self::ENV_PREFIX) === 0) {
            if (!self::isEnvReference($stored)) {
                error_log('[LLM] An API key reference is not valid (expected env:LLM_NAME).');
                return '';
            }
            $name = substr($stored, strlen(self::ENV_PREFIX));
            $value = self::readEnv($name);
            if ($value === '') {
                error_log('[LLM] The environment variable ' . $name . ' (API key reference) is not set.');
            }
            return $value;
        }

        if (self::isEncrypted($stored)) {
            return self::decrypt($stored);
        }

        return $stored;
    }

    /**
     * What a browser may know about a stored key.
     *
     * @param mixed $stored
     * @return array{has_key:bool,key_source:string,key_hint:string,key_readable:bool}
     *   key_source: none | plain | encrypted | env. key_hint: `env:LLM_NAME` for
     *   references, otherwise "..." + the last four characters of keys of 12+
     *   characters (empty for shorter ones). key_readable: false when the key
     *   is stored but cannot be turned back into a key (wrong/missing master
     *   secret, unset environment variable).
     */
    public static function describe($stored)
    {
        if (!is_string($stored) || $stored === '') {
            return array('has_key' => false, 'key_source' => 'none', 'key_hint' => '', 'key_readable' => true);
        }

        $plain = self::resolve($stored);
        if (strpos($stored, self::ENV_PREFIX) === 0) {
            return array(
                'has_key' => true,
                'key_source' => 'env',
                'key_hint' => self::isEnvReference($stored) ? $stored : '',
                'key_readable' => $plain !== '',
            );
        }

        return array(
            'has_key' => true,
            'key_source' => self::isEncrypted($stored) ? 'encrypted' : 'plain',
            'key_hint' => self::hint($plain),
            'key_readable' => $plain !== '',
        );
    }

    /**
     * "..." plus the last four characters of a long key.
     *
     * @param string $plain
     * @return string
     */
    private static function hint($plain)
    {
        if (strlen($plain) < self::HINT_MIN_LENGTH) {
            return '';
        }
        // Last four code points; no match (invalid UTF-8) means no hint.
        return preg_match('/(.{4})$/us', $plain, $matches) === 1 ? "\u{2026}" . $matches[1] : '';
    }

    /**
     * @param string $stored `enc:v1:...` value
     * @return string The key, or '' when it cannot be decrypted
     */
    private static function decrypt($stored)
    {
        $secret = self::getMasterSecret();
        if ($secret === '' || !function_exists('openssl_decrypt')) {
            error_log('[LLM] An encrypted API key cannot be read: ' . self::MASTER_KEY_NAME . ' is not configured.');
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::ENCRYPTED_PREFIX)), true);
        if ($raw === false || strlen($raw) <= 28) {
            error_log('[LLM] An encrypted API key is damaged.');
            return '';
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            self::deriveKey($secret),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );
        if ($plain === false) {
            error_log('[LLM] An encrypted API key cannot be decrypted (wrong ' . self::MASTER_KEY_NAME . '?).');
            return '';
        }
        return $plain;
    }

    /**
     * @return string The master secret, or '' when none (or a too short one) is configured
     */
    private static function getMasterSecret()
    {
        $secret = getenv(self::MASTER_KEY_NAME);
        if (!is_string($secret) || $secret === '') {
            $secret = defined(self::MASTER_KEY_NAME) ? constant(self::MASTER_KEY_NAME) : '';
        }
        return is_string($secret) && strlen($secret) >= self::MIN_MASTER_LENGTH ? $secret : '';
    }

    /**
     * @param string $secret Master secret
     * @return string 32 byte AES key
     */
    private static function deriveKey($secret)
    {
        return hash_hkdf('sha256', $secret, 32, 'sh-shp-llm/api-key/v1');
    }

    /**
     * @param string $name Environment variable name
     * @return string Trimmed value or ''
     */
    private static function readEnv($name)
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            // Some web server setups (SetEnv) only fill $_SERVER / $_ENV.
            $value = $_SERVER[$name] ?? ($_ENV[$name] ?? '');
        }
        return is_string($value) ? trim($value) : '';
    }
}
