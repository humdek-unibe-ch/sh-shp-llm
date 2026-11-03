<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Builds and replays the recipe of a request trace (pure, no database).
 *
 * Each sent message becomes one recipe entry:
 *  - {"m": id}            the message equals the stored history row `id`
 *                         formatted the way it is sent;
 *  - {"role", "c": [..]}  string content as chunk refs (concatenated);
 *  - {"x": ref}           anything else (multimodal parts, extra keys): the
 *                         whole message as one per-user JSON chunk. An image
 *                         data URL whose upload is known (LlmTraceHints::image)
 *                         is stored as {"img": attachment, "sha256": hash of
 *                         the data URL} instead of its bytes.
 *
 * Chunk refs are "<owner>:<sha256>". Owner 0 holds text the code declared
 * shared (LlmTraceHints); owner = user id holds everything else. The trace
 * service replaces refs by chunk row ids when it stores the recipe.
 */
class LlmTraceBuilder
{
    /**
     * @param array $messages Neutral messages as passed to callLlmApi()
     * @param array $candidate_rows History rows the caller sent (llmMessages rows, in order)
     * @param callable $format_row row => sent message|null (LlmApiFormatterService::formatStoredMessage)
     * @param string[] $shared_texts Texts declared shared
     * @param int $user_id Owner of the per-user chunks
     * @param array $image_refs sha256 of an image data URL => attachment (LlmTraceHints::images())
     * @return array{recipe: array, chunks: array<string, array{owner:int, sha256:string, content:string}>, refs: int[]}
     */
    public static function build(array $messages, array $candidate_rows, callable $format_row, array $shared_texts, $user_id, array $image_refs = array())
    {
        $user_id = (int)$user_id;
        $candidates = array();
        foreach ($candidate_rows as $row) {
            if (isset($row['id'])) {
                $candidates[] = array('id' => (int)$row['id'], 'sent' => $format_row($row));
            }
        }

        $recipe = array();
        $chunks = array();
        $refs = array();
        $next = 0;
        foreach ($messages as $message) {
            $matched = null;
            for ($i = $next; $i < count($candidates); $i++) {
                if ($candidates[$i]['sent'] !== null && $candidates[$i]['sent'] === $message) {
                    $matched = $i;
                    break;
                }
            }
            if ($matched !== null) {
                $recipe[] = array('m' => $candidates[$matched]['id']);
                $refs[] = $candidates[$matched]['id'];
                $next = $matched + 1;
                continue;
            }

            if (is_array($message) && array_keys($message) === array('role', 'content') && is_string($message['content'])) {
                $entry = array('role' => $message['role'], 'c' => array());
                foreach (self::split($message['content'], $shared_texts) as $piece) {
                    $entry['c'][] = self::addChunk($chunks, $piece['shared'] ? 0 : $user_id, $piece['text']);
                }
                $recipe[] = $entry;
                continue;
            }

            $json = json_encode(self::imagesToRefs($message, $image_refs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $recipe[] = array('x' => self::addChunk($chunks, $user_id, (string)$json));
        }

        return array('recipe' => $recipe, 'chunks' => $chunks, 'refs' => $refs);
    }

    /**
     * Replay a recipe.
     *
     * @param array $recipe
     * @param array $chunk_contents chunk ref (or stored chunk id) => content
     * @param array $rows_by_id llmMessages rows by id
     * @param callable $format_row row => sent message|null
     * @param callable|null $load_image attachment => image data URL|null (LlmApiFormatterService::imageDataUrl)
     * @return array|null The messages, or null when a chunk, row or image is missing
     */
    public static function rebuildMessages(array $recipe, array $chunk_contents, array $rows_by_id, callable $format_row, $load_image = null)
    {
        $messages = array();
        foreach ($recipe as $entry) {
            if (isset($entry['m'])) {
                $row = $rows_by_id[$entry['m']] ?? null;
                $sent = $row !== null ? $format_row($row) : null;
                if ($sent === null) {
                    return null;
                }
                $messages[] = $sent;
            } elseif (isset($entry['c'])) {
                $content = '';
                foreach ($entry['c'] as $ref) {
                    if (!array_key_exists($ref, $chunk_contents)) {
                        return null;
                    }
                    $content .= $chunk_contents[$ref];
                }
                $messages[] = array('role' => $entry['role'], 'content' => $content);
            } elseif (isset($entry['x'])) {
                $message = array_key_exists($entry['x'], $chunk_contents) ? json_decode($chunk_contents[$entry['x']], true) : null;
                $message = is_array($message) ? self::refsToImages($message, $load_image) : null;
                if (!is_array($message)) {
                    return null;
                }
                $messages[] = $message;
            } else {
                return null;
            }
        }
        return $messages;
    }

    /**
     * Cut a text around the first occurrence of each shared text.
     *
     * @return array<int, array{text:string, shared:bool}> pieces whose concatenation is $text
     */
    private static function split($text, array $shared_texts)
    {
        $pieces = array(array('text' => $text, 'shared' => false));
        // Longest first, so a shared text that contains another one wins
        usort($shared_texts, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        foreach ($shared_texts as $shared) {
            if (!is_string($shared) || $shared === '') {
                continue;
            }
            foreach ($pieces as $index => $piece) {
                if ($piece['shared'] || ($pos = strpos($piece['text'], $shared)) === false) {
                    continue;
                }
                $replacement = array();
                if ($pos > 0) {
                    $replacement[] = array('text' => substr($piece['text'], 0, $pos), 'shared' => false);
                }
                $replacement[] = array('text' => $shared, 'shared' => true);
                $rest = (string)substr($piece['text'], $pos + strlen($shared));
                if ($rest !== '') {
                    $replacement[] = array('text' => $rest, 'shared' => false);
                }
                array_splice($pieces, $index, 1, $replacement);
                break;
            }
        }
        return $pieces;
    }

    /** Replace known image data URLs by {"img", "sha256"} references. */
    private static function imagesToRefs($value, array $image_refs)
    {
        if (is_string($value)) {
            if (!empty($image_refs) && strpos($value, 'data:') === 0) {
                $sha = hash('sha256', $value);
                if (isset($image_refs[$sha])) {
                    return array('img' => $image_refs[$sha], 'sha256' => $sha);
                }
            }
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::imagesToRefs($item, $image_refs);
            }
        }
        return $value;
    }

    /**
     * Load image references back into data URLs.
     *
     * @return array|null null when an image cannot be loaded or no longer has its hash
     */
    private static function refsToImages(array $value, $load_image)
    {
        if (array_keys($value) === array('img', 'sha256')) {
            return null; // a reference at message level is never valid
        }
        foreach ($value as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_keys($item) === array('img', 'sha256')) {
                $url = is_callable($load_image) && is_array($item['img']) ? call_user_func($load_image, $item['img']) : null;
                if (!is_string($url) || hash('sha256', $url) !== $item['sha256']) {
                    return null;
                }
                $value[$key] = $url;
                continue;
            }
            $value[$key] = self::refsToImages($item, $load_image);
            if ($value[$key] === null) {
                return null;
            }
        }
        return $value;
    }

    private static function addChunk(array &$chunks, $owner, $content)
    {
        $sha = hash('sha256', $content);
        $ref = $owner . ':' . $sha;
        $chunks[$ref] = array('owner' => $owner, 'sha256' => $sha, 'content' => $content);
        return $ref;
    }
}
?>
