<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Canonical JSON and hash of a request for request traces.
 *
 * request_hash = sha256(canonical JSON of {provider, params, messages}):
 *  - object keys sorted recursively, list order kept;
 *  - JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
 *  - floats with serialize_precision -1 (CLI and FPM may differ otherwise);
 *  - every base64 data URL replaced by "sha256:<hex of the data URL>", so the
 *    hash is cheap and still changes when an image changes.
 */
class LlmTraceCanonicalizer
{
    /**
     * @param string $provider Provider id
     * @param array $params Adapted payload without messages / system
     * @param array $messages Neutral messages as passed to callLlmApi()
     * @return string sha256 hex
     */
    public static function hash($provider, array $params, array $messages)
    {
        return hash('sha256', self::canonicalJson(array(
            'provider' => (string)$provider,
            'params' => $params,
            'messages' => $messages,
        )));
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function canonicalJson($value)
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $json = json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } finally {
            ini_set('serialize_precision', $precision);
        }
        return $json === false ? '' : $json;
    }

    private static function normalize($value)
    {
        if (is_string($value)) {
            return (strpos($value, 'data:') === 0 && strpos($value, ';base64,') !== false)
                ? 'sha256:' . hash('sha256', $value)
                : $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = array();
        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item);
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }
}
?>
