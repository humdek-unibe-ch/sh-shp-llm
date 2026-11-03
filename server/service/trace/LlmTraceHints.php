<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Process-scoped hints for request traces.
 *
 * share(): a context builder declares text it adds from code and global config
 * only (schema instruction, safety block, prompt assets, language rules). The
 * request trace stores such text once for all users; everything else is
 * stored per user, because it may hold participant data. A missing hint costs
 * deduplication, never correctness: the trace always rebuilds the exact
 * message.
 *
 * image(): the attachment formatter declares the upload behind each image data
 * URL it sends, so a trace stores a file reference instead of the base64 bytes.
 *
 * The hints are deliberately not reset per call: a retry sends the same context
 * again without rebuilding it, so it must still find them. Both lists stay
 * small - shared texts are bounded by the code / config texts that exist, and
 * an image hint is a hash plus a path.
 */
class LlmTraceHints
{
    /** @var array<string, true> */
    private static $shared = array();
    /** @var array<string, array{path:string, type:?string, is_collage:bool}> sha256 of the data URL => attachment */
    private static $images = array();

    /**
     * @param string $text Text built from code / global config only
     */
    public static function share($text)
    {
        if (is_string($text) && $text !== '') {
            self::$shared[$text] = true;
        }
    }

    /** @return string[] in the order they were declared */
    public static function sharedTexts()
    {
        return array_map('strval', array_keys(self::$shared));
    }

    /**
     * @param string $data_url Image data URL as sent
     * @param array $attachment Stored attachment it was encoded from (path, type, is_collage)
     */
    public static function image($data_url, array $attachment)
    {
        if (is_string($data_url) && $data_url !== '' && is_string($attachment['path'] ?? null) && $attachment['path'] !== '') {
            self::$images[hash('sha256', $data_url)] = array(
                'path' => $attachment['path'],
                'type' => isset($attachment['type']) && is_string($attachment['type']) ? $attachment['type'] : null,
                'is_collage' => !empty($attachment['is_collage']),
            );
        }
    }

    /** @return array<string, array> sha256 of the data URL => attachment */
    public static function images()
    {
        return self::$images;
    }

    public static function reset()
    {
        self::$shared = array();
        self::$images = array();
    }
}
?>
