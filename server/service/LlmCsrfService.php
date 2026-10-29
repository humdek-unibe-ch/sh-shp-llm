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
     * @param array|null $post POST data to read the field from (default $_POST)
     * @return string Empty when the request carries none
     */
    public static function fromRequest($post = null)
    {
        $post = is_array($post) ? $post : $_POST;
        if (isset($post[self::POST_PARAM]) && is_scalar($post[self::POST_PARAM])) {
            return (string)$post[self::POST_PARAM];
        }
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        return '';
    }

    /**
     * Verify the token of the current request.
     *
     * @param array|null $post POST data to read the field from (default $_POST)
     * @return bool
     */
    public static function verifyRequest($post = null)
    {
        return self::isValid(self::fromRequest($post));
    }

    /**
     * Gate for the chat and LLM form endpoints, which the native mobile app
     * calls too and which has no token to send. The request passes when it
     * carries the session token, or when it does not come from another
     * website:
     *
     * - `Sec-Fetch-Site: same-origin` - a page of this site (also one whose
     *   token went stale, e.g. after a re-login in another tab);
     * - neither `Sec-Fetch-Site` nor `Origin` - not a browser (the native
     *   app's HTTP client);
     * - an `Origin` that is exactly one entry of the core list
     *   `CORS_ALLOWED_ORIGINS` while core CORS is on (the mobile web
     *   preview): the origins the installation itself allows to call it
     *   with credentials.
     *
     * A browser attaches these headers itself and a page cannot forge or drop
     * them, so a cross-site request reaches this gate labelled as such and
     * needs the token it cannot read.
     *
     * The decision is made from the request and that list only. What core
     * answers in `Access-Control-Allow-Origin` is not consulted: it is a
     * response header, set by code this plugin does not own, and it echoes
     * the origin it approved - a lookalike origin that an older, looser core
     * check approved must not become a trusted client through it.
     *
     * @return bool
     */
    public static function verifyRequestOrTrustedClient()
    {
        if (self::verifyRequest()) {
            return true;
        }
        $site = strtolower(self::serverValue('HTTP_SEC_FETCH_SITE'));
        $origin = self::serverValue('HTTP_ORIGIN');
        if ($site === 'same-origin' || ($site === '' && $origin === '')) {
            return true;
        }
        return $origin !== '' && self::isAllowedOrigin($origin);
    }

    /**
     * Is $origin one of the origins core lets call this installation across
     * origins? Same list and same test as Selfhelp::cors(): the exact string
     * (scheme, host, port) as the browser sends it, strict comparison, no
     * prefix, substring or wildcard. Without core CORS, or with a core that
     * has no such list, no cross-origin client is trusted.
     *
     * @param string $origin The request's Origin header
     * @return bool
     */
    private static function isAllowedOrigin($origin)
    {
        if (!defined('CORS') || !CORS || !defined('CORS_ALLOWED_ORIGINS') || !is_array(CORS_ALLOWED_ORIGINS)) {
            return false;
        }
        // "null" is what a sandboxed frame, a data: URL or a redirect chain sends; it names nobody.
        return strtolower($origin) !== 'null' && in_array($origin, CORS_ALLOWED_ORIGINS, true);
    }

    /** A $_SERVER entry as a trimmed string ('' when missing or not a string). */
    private static function serverValue($key)
    {
        return isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? trim($_SERVER[$key]) : '';
    }
}
