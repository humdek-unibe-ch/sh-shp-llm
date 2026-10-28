<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Settings Validator
 *
 * Server-side checks for the module settings (page `sh_module_llm`). The
 * browser widgets check too, but only this class decides what is stored.
 *
 * Base URLs of API servers must use https (plain http only for a server on
 * the same machine: localhost / 127.0.0.1 / ::1), carry no credentials,
 * query or fragment, and - when LLM_ALLOWED_API_HOSTS is configured - point
 * to an allowed host. LLM_ALLOWED_API_HOSTS is a PHP constant (array or
 * comma separated string) or an environment variable (comma separated list)
 * of host names; `*.example.org` allows every sub domain of example.org.
 * It is the control that keeps an administrator from sending the stored keys
 * to a host that was not approved, so set it in production.
 *
 * @package LLM Plugin
 */
class LlmSettingsValidator
{
    const TEMPERATURE_MIN = 0;
    const TEMPERATURE_MAX = 2;
    const MAX_TOKENS_MIN = 1;
    const MAX_TOKENS_MAX = 200000;
    const TIMEOUT_MIN = 1;
    const TIMEOUT_MAX = 600;
    const MODEL_ID_MAX_LENGTH = 255;
    const BASE_URL_MAX_LENGTH = 500;

    /** Names the plain http scheme is accepted for. */
    const LOOPBACK_HOSTS = array('localhost', '127.0.0.1', '::1');

    /**
     * Validate one scalar setting.
     *
     * @param string $name Setting name (llm_temperature, llm_max_tokens, ...)
     * @param mixed $raw Value as posted
     * @param array $context 'reasoning_codes' (string[]) and 'storage_modes' (string[]) allowed values
     * @return array{value:?string,error:?string} The value to store, or the error message
     */
    public static function validate($name, $raw, array $context = array())
    {
        if ($raw !== null && !is_scalar($raw)) {
            return self::fail('The value is not valid.');
        }
        $value = trim((string)$raw);

        switch ($name) {
            case 'llm_temperature':
                if ($value === '') {
                    return self::ok('');
                }
                if (!is_numeric($value) || (float)$value < self::TEMPERATURE_MIN || (float)$value > self::TEMPERATURE_MAX) {
                    return self::fail('Temperature must be a number between ' . self::TEMPERATURE_MIN . ' and ' . self::TEMPERATURE_MAX . '.');
                }
                return self::ok(rtrim(rtrim(sprintf('%.2f', (float)$value), '0'), '.'));

            case 'llm_max_tokens':
                return self::validateWholeNumber($value, self::MAX_TOKENS_MIN, self::MAX_TOKENS_MAX, 'Max tokens');

            case 'llm_timeout':
                return self::validateWholeNumber($value, self::TIMEOUT_MIN, self::TIMEOUT_MAX, 'Timeout');

            case 'llm_default_model':
                if (strlen($value) > self::MODEL_ID_MAX_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    return self::fail('The model name must be at most ' . self::MODEL_ID_MAX_LENGTH . ' characters without control characters.');
                }
                return self::ok($value);

            case 'llm_reasoning_effort':
                $allowed = array_merge(array('', 'default'), $context['reasoning_codes'] ?? array());
                return in_array($value, $allowed, true)
                    ? self::ok($value)
                    : self::fail('Unknown reasoning effort.');

            case 'llm_memory_enabled':
                return in_array($value, array('', '0', '1'), true)
                    ? self::ok($value)
                    : self::fail('The value must be 0 or 1.');

            case 'llm_memory_storage_mode':
                $allowed = array_merge(array(''), $context['storage_modes'] ?? array());
                return in_array($value, $allowed, true)
                    ? self::ok($value)
                    : self::fail('Unknown storage mode.');
        }

        return self::fail('Unknown setting.');
    }

    /**
     * Validate the base URL of an API server.
     *
     * @param mixed $url
     * @return array{value:?string,error:?string} The URL without trailing slash, or the error message
     */
    public static function validateBaseUrl($url)
    {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return self::fail('The base URL is required.');
        }
        if (strlen($url) > self::BASE_URL_MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return self::fail('The base URL must be at most ' . self::BASE_URL_MAX_LENGTH . ' characters without spaces.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return self::fail('The base URL must look like https://host/v1.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return self::fail('The base URL must not contain credentials, a query string or a fragment.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true))) {
            return self::fail('The base URL must use https (http is only accepted for localhost).');
        }
        if (!self::isHostAllowed($host)) {
            return self::fail('The host "' . $host . '" is not in the list of allowed API hosts.');
        }

        return self::ok(rtrim($url, '/'));
    }

    /**
     * Scheme, host and port of a URL, lower case; '' when it cannot be parsed.
     * Two URLs with the same origin send the stored key to the same server.
     *
     * @param mixed $url
     * @return string
     */
    public static function originOf($url)
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower(trim($parts['host'], '[]')) . ':' . $port;
    }

    /**
     * @param string $host Lower case host name
     * @return bool True when no allow-list is configured or the host is on it
     */
    private static function isHostAllowed($host)
    {
        $allowed = self::getAllowedHosts();
        if (empty($allowed)) {
            return true;
        }
        foreach ($allowed as $pattern) {
            if ($pattern === $host) {
                return true;
            }
            if (strpos($pattern, '*.') === 0 && substr($host, -strlen($pattern) + 1) === substr($pattern, 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[] Lower case host names / `*.` patterns; empty when not configured
     */
    private static function getAllowedHosts()
    {
        $raw = defined('LLM_ALLOWED_API_HOSTS') ? constant('LLM_ALLOWED_API_HOSTS') : getenv('LLM_ALLOWED_API_HOSTS');
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return array();
        }
        $hosts = array();
        foreach ($raw as $item) {
            $item = is_string($item) ? strtolower(trim($item)) : '';
            if ($item !== '') {
                $hosts[] = $item;
            }
        }
        return $hosts;
    }

    /**
     * Blank means "use the plugin default" and is stored as ''.
     *
     * @param string $value Trimmed input
     * @param int $min
     * @param int $max
     * @param string $label Name used in the message
     * @return array{value:?string,error:?string}
     */
    private static function validateWholeNumber($value, $min, $max, $label)
    {
        if ($value === '') {
            return self::ok('');
        }
        if (!ctype_digit($value) || strlen($value) > 9 || (int)$value < $min || (int)$value > $max) {
            return self::fail($label . ' must be a whole number between ' . $min . ' and ' . $max . '.');
        }
        return self::ok((string)(int)$value);
    }

    /**
     * @param string $value
     * @return array{value:string,error:null}
     */
    private static function ok($value)
    {
        return array('value' => $value, 'error' => null);
    }

    /**
     * @param string $message
     * @return array{value:null,error:string}
     */
    private static function fail($message)
    {
        return array('value' => null, 'error' => $message);
    }
}
