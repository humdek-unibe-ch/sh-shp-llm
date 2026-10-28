<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM CSRF Service
 *
 * One session CSRF token for every plugin page and endpoint that changes
 * data. The SelfHelp core has no CSRF mechanism, so the plugin keeps a
 * single token per session under `$_SESSION['csrf_token']` (the key the
 * interview style already used).
 *
 * - A view hands the token to its page with getToken() (generated on first
 *   use).
 * - An endpoint verifies what the page sent back (POST field `csrf_token`
 *   or the `X-CSRF-Token` header) with verifyRequest() / isValid().
 *
 * The check is strict: a session without a token, or a request without
 * one, is "invalid" - never "skip".
 *
 * @package LLM Plugin
 */
class LlmCsrfService
{
    /** Session key holding the token. */
    const SESSION_KEY = 'csrf_token';

    /** POST field the pages send the token in. */
    const POST_PARAM = 'csrf_token';

    /**
     * Token of the current session; generated once, on first use.
     *
     * @return string
     */
    public static function getToken()
    {
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Compare a received token with the session token (constant time).
     *
     * @param mixed $posted The token the request carried
     * @return bool True only when the session has a token and it matches
     */
    public static function isValid($posted)
    {
        $session_token = isset($_SESSION[self::SESSION_KEY]) && is_string($_SESSION[self::SESSION_KEY])
            ? $_SESSION[self::SESSION_KEY] : '';
        return $session_token !== ''
            && is_scalar($posted)
            && (string)$posted !== ''
            && hash_equals($session_token, (string)$posted);
    }

    /**
     * Token the current request carries: the POST field, else the header.
     *
     * @return string Empty when the request carries none
     */
    public static function fromRequest()
    {
        if (isset($_POST[self::POST_PARAM]) && is_scalar($_POST[self::POST_PARAM])) {
            return (string)$_POST[self::POST_PARAM];
        }
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        return '';
    }

    /**
     * Verify the token of the current request.
     *
     * @return bool
     */
    public static function verifyRequest()
    {
        return self::isValid(self::fromRequest());
    }
}
