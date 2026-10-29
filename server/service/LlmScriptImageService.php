<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/globals.php';
require_once __DIR__ . '/LlmFileNamingService.php';
require_once __DIR__ . '/LlmFileUtility.php';
require_once __DIR__ . '/LlmErrorReporter.php';

/**
 * Materializes survey/form images for LLM script execution.
 *
 * Accepts:
 *  - data:image/...;base64,... strings
 *  - SurveyJS file arrays / JSON (name, type, content)
 *  - ?file_path=... / relative upload paths on disk
 *  - already-materialized sentinel JSON from a prior async pre-pass
 *
 * Writes files under upload/{user}/scripts/{scriptId}/ with trackable names
 * (response id + question key) and replaces bulky variable values with short
 * prompt markers so base64 never enters the script text or async args file.
 */
class LlmScriptImageService
{
    const SENTINEL_KEY = '__llm_script_image';

    /**
     * SurveyJS / SelfHelp bookkeeping keys. They often embed the same images
     * already present in named questions (especially `_json`) and must not be
     * scanned as attachment sources — that would duplicate vision payloads.
     */
    private static $SKIP_IMAGE_SOURCE_KEYS = array(
        '_json',
        'trigger_type',
        'survey_generated_id',
        'pageNo',
        'response_id',
        'record_id',
        'selected_record_id',
        'delete_record_id',
        '__form_name',
        'mobile',
    );

    /** @var int */
    private $maxImages;

    public function __construct($maxImages = null)
    {
        // Scripts may gather more than the API image cap; collage reduces them to 1.
        $default = defined('LLM_MAX_SCRIPT_SOURCE_IMAGES')
            ? (int)LLM_MAX_SCRIPT_SOURCE_IMAGES
            : (defined('LLM_MAX_FILES_PER_MESSAGE') ? (int)LLM_MAX_FILES_PER_MESSAGE : 12);
        $this->maxImages = $maxImages !== null ? (int)$maxImages : $default;
    }

    /**
     * Scan variables, materialize images to the LLM upload folder, and return
     * sanitized variables + chat-compatible attachment metadata.
     *
     * @param array $variables Script / form variables
     * @param int $userId User who owns the script run
     * @param int|string|null $scriptId Script id for folder/name tracking
     * @return array{variables: array, attachments: array, image_count: int}
     */
    public function materializeVariables(array $variables, $userId, $scriptId = null)
    {
        $userId = (int)$userId;
        $scriptId = (int)($scriptId ?? 0);
        $responseId = $this->resolveResponseId($variables);
        $attachments = array();
        $sanitized = $variables;
        $imageCount = 0;
        $seenHashes = array();

        foreach ($variables as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            // Still scrub base64 from bookkeeping fields, but never attach from them.
            if ($this->shouldSkipImageSourceKey($key)) {
                if ($this->containsBase64ImagePayload($value)) {
                    $sanitized[$key] = '[internes Feld — Bilddaten entfernt]';
                }
                continue;
            }

            $extracted = $this->extractImageCandidates($value);
            if (empty($extracted)) {
                continue;
            }

            $fieldAttachments = array();
            foreach ($extracted as $index => $candidate) {
                if ($imageCount >= $this->maxImages) {
                    break;
                }

                $attachment = $this->materializeCandidate(
                    $candidate,
                    $userId,
                    $scriptId,
                    $responseId,
                    $key,
                    $index + 1
                );
                if ($attachment === null) {
                    continue;
                }

                $attachment = $this->enrichAttachmentWithExif($attachment);

                // Dedupe identical binaries (same image via two fields).
                $hash = $this->attachmentContentHash($attachment);
                if ($hash !== null && isset($seenHashes[$hash])) {
                    @unlink(__DIR__ . '/../../' . $attachment['path']);
                    continue;
                }
                if ($hash !== null) {
                    $seenHashes[$hash] = true;
                }

                $fieldAttachments[] = $attachment;
                $attachments[] = $attachment;
                $imageCount++;
            }

            if (!empty($fieldAttachments)) {
                $sanitized[$key] = $this->buildPromptMarker($key, $fieldAttachments);
            } elseif ($this->looksLikeImagePayload($value) || $this->containsBase64ImagePayload($value)) {
                // Never leave raw base64 in script variables — it blows the context window.
                $sanitized[$key] = 'nicht verfügbar';
            }
        }

        // Final safety net: scrub any remaining data-URL / huge base64 blobs.
        $sanitized = $this->scrubBase64FromVariables($sanitized);

        return array(
            'variables' => $sanitized,
            'attachments' => $attachments,
            'image_count' => $imageCount,
        );
    }

    /**
     * @param string $key Variable / question name
     * @return bool
     */
    private function shouldSkipImageSourceKey($key)
    {
        if (in_array($key, self::$SKIP_IMAGE_SOURCE_KEYS, true)) {
            return true;
        }
        // SurveyJS meta fields: _meta_user_agent, _meta_pages, …
        if (strpos($key, '_meta') === 0) {
            return true;
        }
        return false;
    }

    /**
     * @param array $attachment
     * @return string|null md5 of file contents
     */
    private function attachmentContentHash(array $attachment)
    {
        $path = $attachment['path'] ?? '';
        if (!is_string($path) || $path === '' || strpos($path, '..') !== false) {
            return null;
        }
        $full = __DIR__ . '/../../' . ltrim($path, '/');
        if (!is_file($full)) {
            return null;
        }
        $hash = md5_file($full);
        return $hash !== false ? $hash : null;
    }

    /**
     * Build OpenAI-style multimodal user content from text + attachments.
     * Reuses LlmApiFormatterService encoding (resize / optimize).
     *
     * @param string $prompt Text prompt
     * @param array $attachments Attachment metadata arrays
     * @param object $services Services container
     * @param string|null $model Model id for vision capability checks
     * @return array Messages ready for callLlmApi
     */
    public function buildApiMessages($prompt, array $attachments, $services, $model = null)
    {
        require_once __DIR__ . '/LlmApiFormatterService.php';

        if (empty($attachments)) {
            return array(
                array('role' => 'user', 'content' => $prompt)
            );
        }

        $formatter = new LlmApiFormatterService($services, $model);
        return $formatter->convertToApiFormat(array(
            array(
                'role' => 'user',
                'content' => $prompt,
                'attachments' => json_encode($attachments),
            )
        ));
    }

    /**
     * Append per-image EXIF summaries to the script prompt text.
     * Call this before buildApiMessages so logged context matches the API text.
     *
     * Vision APIs re-encode images and strip EXIF; this is how the model sees it.
     *
     * @param string $prompt
     * @param array $attachments
     * @return string
     */
    public function appendExifTextToPrompt($prompt, array $attachments)
    {
        $lines = array();
        foreach ($attachments as $i => $att) {
            if (!is_array($att)) {
                continue;
            }
            $summary = $att['exif_summary'] ?? null;
            if (!is_string($summary) || $summary === '') {
                $att = $this->enrichAttachmentWithExif($att);
                $summary = $att['exif_summary'] ?? null;
            }
            if (!is_string($summary) || $summary === '') {
                continue;
            }
            $label = $att['original_name'] ?? ($att['question'] ?? ('image_' . ($i + 1)));
            $question = $att['question'] ?? '';
            $prefix = $question !== '' ? ($question . '/' . $label) : $label;
            $lines[] = '- Bild ' . ($i + 1) . ' (' . $prefix . '): ' . $summary;
        }

        if (empty($lines)) {
            return $prompt;
        }

        return rtrim((string)$prompt)
            . "\n\nBild-Metadaten (EXIF aus Originaldatei; Bilddaten für die API wurden neu kodiert):\n"
            . implode("\n", $lines);
    }

    /**
     * When 2+ images exist, stitch them into one labeled collage and describe the
     * grid + per-image EXIF in the prompt so the model knows Image 1/2/3 positions.
     * Returns one attachment (the collage) or the originals if stitching fails / count < 2.
     *
     * @param array $attachments Source image attachments (already on disk, with EXIF when possible)
     * @param int $userId
     * @param int $scriptId
     * @param string|null $responseId
     * @return array{attachments: array, layout_prompt: string, combined: bool, source_count: int}
     */
    public function combineIntoLabeledCollage(array $attachments, $userId, $scriptId, $responseId = null)
    {
        $sources = array();
        foreach ($attachments as $att) {
            if (is_array($att) && !empty($att['is_image']) && !empty($att['path'])) {
                $sources[] = $this->enrichAttachmentWithExif($att);
            }
        }
        $sourceCount = count($sources);
        if ($sourceCount < 2) {
            return array(
                'attachments' => $attachments,
                'layout_prompt' => '',
                'combined' => false,
                'source_count' => $sourceCount,
            );
        }

        $cols = (int)ceil(sqrt($sourceCount));
        $rows = (int)ceil($sourceCount / $cols);
        $cellMax = defined('LLM_SCRIPT_COLLAGE_CELL_MAX') ? (int)LLM_SCRIPT_COLLAGE_CELL_MAX : 1024;
        $gap = 12;
        $labelH = 48;
        $pad = 16;

        $loaded = array();
        $maxW = 0;
        $maxH = 0;
        foreach ($sources as $i => $att) {
            $gd = $this->loadGdImageFromAttachment($att);
            if ($gd === null) {
                continue;
            }
            $w = imagesx($gd);
            $h = imagesy($gd);
            // Fit into cell without upscaling past original
            $scale = min(1.0, $cellMax / max($w, $h, 1));
            $tw = max(1, (int)round($w * $scale));
            $th = max(1, (int)round($h * $scale));
            $maxW = max($maxW, $tw);
            $maxH = max($maxH, $th);
            $loaded[] = array(
                'gd' => $gd,
                'tw' => $tw,
                'th' => $th,
                'att' => $att,
                'index' => $i + 1,
            );
        }

        if (count($loaded) < 2) {
            foreach ($loaded as $item) {
                imagedestroy($item['gd']);
            }
            return array(
                'attachments' => $attachments,
                'layout_prompt' => '',
                'combined' => false,
                'source_count' => $sourceCount,
            );
        }

        // Recalculate grid for successfully loaded images
        $n = count($loaded);
        $cols = (int)ceil(sqrt($n));
        $rows = (int)ceil($n / $cols);
        $cellW = $maxW;
        $cellH = $maxH + $labelH;
        $canvasW = $pad * 2 + $cols * $cellW + ($cols - 1) * $gap;
        $canvasH = $pad * 2 + $rows * $cellH + ($rows - 1) * $gap;

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        if ($canvas === false) {
            foreach ($loaded as $item) {
                imagedestroy($item['gd']);
            }
            return array(
                'attachments' => $attachments,
                'layout_prompt' => '',
                'combined' => false,
                'source_count' => $sourceCount,
            );
        }
        $bg = imagecolorallocate($canvas, 245, 245, 245);
        $labelBg = imagecolorallocate($canvas, 30, 30, 30);
        $labelFg = imagecolorallocate($canvas, 255, 255, 255);
        $border = imagecolorallocate($canvas, 180, 180, 180);
        imagefilledrectangle($canvas, 0, 0, $canvasW, $canvasH, $bg);

        $layoutLines = array();
        $layoutLines[] = 'The single attached image is a COLLAGE of ' . $n
            . ' photos in a ' . $rows . '×' . $cols
            . ' grid (left-to-right, top-to-bottom). Use the panel labels "Image N".';

        foreach ($loaded as $slot => $item) {
            $col = $slot % $cols;
            $row = (int)floor($slot / $cols);
            $x0 = $pad + $col * ($cellW + $gap);
            $y0 = $pad + $row * ($cellH + $gap);

            imagefilledrectangle($canvas, $x0, $y0, $x0 + $cellW - 1, $y0 + $labelH - 1, $labelBg);
            $label = 'Image ' . $item['index'];
            $this->drawScaledCollageLabel($canvas, $x0, $y0, $cellW, $labelH, $label);

            $dstX = $x0 + (int)floor(($cellW - $item['tw']) / 2);
            $dstY = $y0 + $labelH + (int)floor(($maxH - $item['th']) / 2);
            imagecopyresampled(
                $canvas,
                $item['gd'],
                $dstX,
                $dstY,
                0,
                0,
                $item['tw'],
                $item['th'],
                imagesx($item['gd']),
                imagesy($item['gd'])
            );
            imagerectangle($canvas, $x0, $y0, $x0 + $cellW - 1, $y0 + $cellH - 1, $border);
            imagedestroy($item['gd']);

            $att = $item['att'];
            $q = $att['question'] ?? '';
            $name = $att['original_name'] ?? ('file_' . $item['index']);
            $pos = 'row ' . ($row + 1) . ', column ' . ($col + 1);
            if ($row === 0 && $col === 0) {
                $pos .= ' (top-left)';
            } elseif ($row === 0 && $col === $cols - 1) {
                $pos .= ' (top-right)';
            } elseif ($row === $rows - 1 && $col === 0) {
                $pos .= ' (bottom-left)';
            } elseif ($row === $rows - 1 && $col === $cols - 1) {
                $pos .= ' (bottom-right)';
            }
            $exif = $att['exif_summary'] ?? '';
            $line = '- Image ' . $item['index'] . ': ' . $pos
                . '; source=' . ($q !== '' ? ($q . '/' . $name) : $name);
            if (is_string($exif) && $exif !== '') {
                $line .= '; EXIF: ' . $exif;
            }
            $layoutLines[] = $line;
        }

        ob_start();
        imagejpeg($canvas, null, 90);
        $binary = ob_get_clean();
        imagedestroy($canvas);
        if ($binary === false || $binary === '') {
            return array(
                'attachments' => $attachments,
                'layout_prompt' => '',
                'combined' => false,
                'source_count' => $sourceCount,
            );
        }

        $responseId = $responseId !== null && $responseId !== ''
            ? (string)$responseId
            : ('collage_' . time());
        $collageAtt = $this->writeBinaryAsAttachment(
            $binary,
            'image/jpeg',
            'jpg',
            (int)$userId,
            (int)$scriptId,
            $responseId,
            'collage',
            1,
            'collage_' . $n . '_images.jpg'
        );
        if ($collageAtt === null) {
            return array(
                'attachments' => $attachments,
                'layout_prompt' => '',
                'combined' => false,
                'source_count' => $sourceCount,
            );
        }
        $collageAtt['is_collage'] = true;
        $collageAtt['collage_source_count'] = $n;
        $collageAtt['collage_grid'] = $rows . 'x' . $cols;

        return array(
            'attachments' => array($collageAtt),
            'layout_prompt' => implode("\n", $layoutLines),
            'combined' => true,
            'source_count' => $n,
            'source_attachments' => $sources,
        );
    }

    /**
     * Append collage layout + EXIF guide (or fall back to per-image EXIF).
     *
     * @param string $prompt
     * @param array $combineResult Result of combineIntoLabeledCollage()
     * @param array $fallbackAttachments Used when not combined
     * @return string
     */
    public function appendImageGuideToPrompt($prompt, array $combineResult, array $fallbackAttachments = array())
    {
        if (!empty($combineResult['combined']) && !empty($combineResult['layout_prompt'])) {
            return rtrim((string)$prompt)
                . "\n\n--- Image collage guide ---\n"
                . $combineResult['layout_prompt']
                . "\n--- end collage guide ---";
        }
        return $this->appendExifTextToPrompt($prompt, $fallbackAttachments);
    }

    /**
     * Draw a scaled, high-contrast bold label ("Image N") into a collage cell header
     * so the label remains legible even on large multi-panel grids after vision resize.
     *
     * @param \GdImage|resource $canvas
     * @param int $x0
     * @param int $y0
     * @param int $cellW
     * @param int $labelH
     * @param string $label
     * @return void
     */
    private function drawScaledCollageLabel($canvas, $x0, $y0, $cellW, $labelH, $label)
    {
        $font = 5;
        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $len = strlen($label);
        $srcW = max(1, $len * $charW + 4);
        $srcH = max(1, $charH + 2);

        $scale = $cellW >= 640 ? 2 : 1;
        $dstW = min($cellW - 16, $srcW * $scale);
        $dstH = min($labelH - 8, $srcH * $scale);

        $tmp = @imagecreatetruecolor($srcW, $srcH);
        if ($tmp === false) {
            $fg = imagecolorallocate($canvas, 255, 255, 255);
            imagestring($canvas, $font, $x0 + 10, $y0 + (int)max(4, floor(($labelH - $charH) / 2)), $label, $fg);
            return;
        }

        $bg = imagecolorallocate($tmp, 30, 30, 30);
        $fg = imagecolorallocate($tmp, 255, 255, 255);
        imagefilledrectangle($tmp, 0, 0, $srcW, $srcH, $bg);
        // Double-strike 1px horizontally for a bolder glyph weight
        imagestring($tmp, $font, 1, 1, $label, $fg);
        imagestring($tmp, $font, 2, 1, $label, $fg);

        $dstX = $x0 + 12;
        $dstY = $y0 + (int)max(4, floor(($labelH - $dstH) / 2));
        imagecopyresized($canvas, $tmp, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($tmp);
    }

    /**
     * @param array $attachment
     * @return \GdImage|resource|null
     */
    private function loadGdImageFromAttachment(array $attachment)
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $path = $attachment['path'] ?? '';
        if (!is_string($path) || $path === '' || strpos($path, '..') !== false) {
            return null;
        }
        $full = __DIR__ . '/../../' . ltrim(str_replace('\\', '/', $path), '/');
        if (!is_file($full)) {
            return null;
        }
        $mime = $attachment['type'] ?? (LlmFileUtility::detectMimeType($full) ?? '');
        $data = @file_get_contents($full);
        if ($data === false || $data === '') {
            return null;
        }
        $gd = @imagecreatefromstring($data);
        return $gd !== false ? $gd : null;
    }

    /**
     * Attach EXIF summary/fields from the on-disk original (before API recompress).
     *
     * @param array $attachment
     * @return array
     */
    private function enrichAttachmentWithExif(array $attachment)
    {
        $path = $attachment['path'] ?? '';
        if (!is_string($path) || $path === '' || strpos($path, '..') !== false) {
            return $attachment;
        }
        $full = __DIR__ . '/../../' . ltrim(str_replace('\\', '/', $path), '/');
        $exif = LlmFileUtility::extractImageExif($full);
        if ($exif === null) {
            return $attachment;
        }
        $attachment['exif_summary'] = $exif['summary'];
        $attachment['exif'] = $exif['fields'];
        return $attachment;
    }

    /**
     * Resolve a stable response tracker from common survey/form fields.
     *
     * @param array $variables
     * @return string
     */
    private function resolveResponseId(array $variables)
    {
        $candidates = array(
            'response_id',
            'survey_response_id',
            'evaluated_user_code',
            'user_code',
            'record_id',
            'selected_record_id',
        );
        if (defined('ENTRY_RECORD_ID')) {
            $candidates[] = ENTRY_RECORD_ID;
        }

        foreach ($candidates as $key) {
            if (!empty($variables[$key]) && (is_string($variables[$key]) || is_numeric($variables[$key]))) {
                return (string)$variables[$key];
            }
        }

        return 'na';
    }

    /**
     * @param mixed $value
     * @return array<int, array{source:string,content?:string,path?:string,mime?:string,original_name?:string,attachment?:array}>
     */
    private function extractImageCandidates($value)
    {
        if (is_array($value)) {
            if ($this->isSentinel($value)) {
                return array($this->candidateFromSentinel($value));
            }
            if ($this->isSurveyFileList($value)) {
                return $this->candidatesFromSurveyFiles($value);
            }
            // Single survey file object
            if ($this->isSurveyFileObject($value)) {
                return $this->candidatesFromSurveyFiles(array($value));
            }
            // ["data:image/...","data:image/..."] or mixed list
            return $this->candidatesFromMixedList($value);
        }

        if (!is_string($value) || $value === '') {
            return array();
        }

        $trimmed = trim($value);

        // Already-materialized sentinel JSON (async pre-pass)
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // SurveyJS / HTML sometimes escapes quotes; try a light unescape.
                $decoded = json_decode(html_entity_decode($trimmed, ENT_QUOTES, 'UTF-8'), true);
            }
            if (is_array($decoded)) {
                if ($this->isSentinel($decoded)) {
                    return array($this->candidateFromSentinel($decoded));
                }
                if ($this->isListOfSentinels($decoded)) {
                    $out = array();
                    foreach ($decoded as $item) {
                        $out[] = $this->candidateFromSentinel($item);
                    }
                    return $out;
                }
                if ($this->isSurveyFileList($decoded)) {
                    return $this->candidatesFromSurveyFiles($decoded);
                }
                if ($this->isSurveyFileObject($decoded)) {
                    return $this->candidatesFromSurveyFiles(array($decoded));
                }
                $fromList = $this->candidatesFromMixedList($decoded);
                if (!empty($fromList)) {
                    return $fromList;
                }
            }
        }

        // One or more data:image/... URLs anywhere in the string (incl. <img src="...">)
        $fromDataUrls = $this->candidatesFromDataUrlsInString($trimmed);
        if (!empty($fromDataUrls)) {
            return $fromDataUrls;
        }

        $path = $this->normalizeFilePathMarker($trimmed);
        if ($path !== null) {
            return array(array(
                'source' => 'path',
                'path' => $path,
                'original_name' => basename($path),
            ));
        }

        return array();
    }

    /**
     * Extract image candidates from a mixed PHP/JSON list.
     *
     * @param array $list
     * @return array
     */
    private function candidatesFromMixedList(array $list)
    {
        $out = array();
        foreach ($list as $item) {
            if (is_string($item)) {
                $out = array_merge($out, $this->extractImageCandidates($item));
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            if ($this->isSentinel($item)) {
                $out[] = $this->candidateFromSentinel($item);
                continue;
            }
            if ($this->isSurveyFileObject($item)) {
                $out = array_merge($out, $this->candidatesFromSurveyFiles(array($item)));
            }
        }
        return $out;
    }

    /**
     * Find every data:image/...;base64,... URL inside a string.
     *
     * @param string $text
     * @return array
     */
    private function candidatesFromDataUrlsInString($text)
    {
        if (!is_string($text) || stripos($text, 'data:image/') === false) {
            return array();
        }

        $out = array();
        if (!preg_match_all(
            '#data:image/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=\s]+#',
            $text,
            $matches
        )) {
            return array();
        }

        foreach ($matches[0] as $i => $dataUrl) {
            $dataUrl = preg_replace('/\s+/', '', $dataUrl);
            $out[] = array(
                'source' => 'base64',
                'content' => $dataUrl,
                'mime' => $this->mimeFromDataUrl($dataUrl),
                'original_name' => 'image_' . ($i + 1),
            );
        }
        return $out;
    }

    /**
     * True when a value still contains a large base64 image payload.
     *
     * @param mixed $value
     * @return bool
     */
    private function containsBase64ImagePayload($value)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsBase64ImagePayload($item)) {
                    return true;
                }
            }
            return false;
        }
        if (!is_string($value) || strlen($value) < 256) {
            return false;
        }
        return stripos($value, 'data:image/') !== false
            || (bool)preg_match('#^[A-Za-z0-9+/]{500,}={0,2}$#', preg_replace('/\s+/', '', $value));
    }

    /**
     * Replace any remaining base64 image payloads with a short marker.
     * Prevents {{var}} interpolation from injecting megabyte prompts.
     *
     * @param array $variables
     * @return array
     */
    public function scrubBase64FromVariables(array $variables)
    {
        foreach ($variables as $key => $value) {
            if (!$this->containsBase64ImagePayload($value)) {
                continue;
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded) && ($this->isSentinel($decoded) || $this->isListOfSentinels($decoded))) {
                    continue;
                }
            }
            $variables[$key] = is_string($key)
                ? ('[Bild: ' . $key . ' — raw base64 entfernt, nicht als Anhang erkannt]')
                : 'nicht verfügbar';
        }
        return $variables;
    }

    /**
     * Strip any data:image base64 URLs that still made it into the final prompt text.
     *
     * @param string $prompt
     * @return string
     */
    public function scrubBase64FromPrompt($prompt)
    {
        if (!is_string($prompt) || stripos($prompt, 'data:image/') === false) {
            return $prompt;
        }
        return preg_replace(
            '#data:image/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=\s]+#',
            '[Bildanhang — base64 aus Prompt entfernt]',
            $prompt
        );
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function looksLikeImagePayload($value)
    {
        return !empty($this->extractImageCandidates($value));
    }

    /**
     * @param array $sentinel
     * @return array
     */
    private function candidateFromSentinel(array $sentinel)
    {
        return array(
            'source' => 'sentinel',
            'attachment' => array(
                'path' => $sentinel['path'] ?? '',
                'original_name' => $sentinel['original_name'] ?? basename((string)($sentinel['path'] ?? 'image')),
                'type' => $sentinel['type'] ?? 'image/jpeg',
                'is_image' => true,
                'question' => $sentinel['question'] ?? null,
                'response_id' => $sentinel['response_id'] ?? null,
            ),
        );
    }

    /**
     * @param array $files
     * @return array
     */
    private function candidatesFromSurveyFiles(array $files)
    {
        $out = array();
        foreach ($files as $file) {
            if (!$this->isSurveyFileObject($file)) {
                continue;
            }
            $content = $file['content'] ?? '';
            if (!is_string($content) || $content === '') {
                continue;
            }

            $type = (string)($file['type'] ?? '');
            $name = (string)($file['name'] ?? 'image');

            // Prefer path markers when storeDataAsText is false
            $path = $this->normalizeFilePathMarker($content);
            if ($path !== null) {
                if (!$this->isImagePath($path) && !$this->isImageMime($type)) {
                    continue;
                }
                $out[] = array(
                    'source' => 'path',
                    'path' => $path,
                    'mime' => $type !== '' ? $type : null,
                    'original_name' => $name,
                );
                continue;
            }

            if (stripos($content, 'data:image/') === 0) {
                $out[] = array(
                    'source' => 'base64',
                    'content' => $content,
                    'mime' => $this->mimeFromDataUrl($content) ?: 'image/jpeg',
                    'original_name' => $name,
                );
                continue;
            }

            // Raw base64 with an image MIME (no data-URL prefix)
            if ($this->isImageMime($type) && preg_match('#^[A-Za-z0-9+/=\s]+$#', $content)) {
                $mime = $type !== '' ? $type : 'image/jpeg';
                $out[] = array(
                    'source' => 'base64',
                    'content' => 'data:' . $mime . ';base64,' . preg_replace('/\s+/', '', $content),
                    'mime' => $mime,
                    'original_name' => $name,
                );
            }
        }
        return $out;
    }

    /**
     * @param array $candidate
     * @param int $userId
     * @param int $scriptId
     * @param string $responseId
     * @param string $questionName
     * @param int $fileIndex
     * @return array|null Chat-compatible attachment metadata
     */
    private function materializeCandidate(
        array $candidate,
        $userId,
        $scriptId,
        $responseId,
        $questionName,
        $fileIndex
    ) {
        if (($candidate['source'] ?? '') === 'sentinel' && !empty($candidate['attachment']['path'])) {
            $att = $candidate['attachment'];
            $full = $this->llmPluginPath($att['path']);
            if ($full === null || !is_file($full)) {
                return null;
            }
            return $att;
        }

        try {
            LlmFileNamingService::ensureScriptImageDirectoryExists($userId, $scriptId);
        } catch (Exception $e) {
            LlmErrorReporter::log('Script image directory', $e);
            return null;
        }

        if (($candidate['source'] ?? '') === 'base64') {
            return $this->materializeBase64(
                (string)($candidate['content'] ?? ''),
                $userId,
                $scriptId,
                $responseId,
                $questionName,
                $fileIndex,
                $candidate['original_name'] ?? null,
                $candidate['mime'] ?? null
            );
        }

        if (($candidate['source'] ?? '') === 'path') {
            return $this->materializePath(
                (string)($candidate['path'] ?? ''),
                $userId,
                $scriptId,
                $responseId,
                $questionName,
                $fileIndex,
                $candidate['original_name'] ?? null,
                $candidate['mime'] ?? null
            );
        }

        return null;
    }

    /**
     * @param string $dataUrl
     * @param int $userId
     * @param int $scriptId
     * @param string $responseId
     * @param string $questionName
     * @param int $fileIndex
     * @param string|null $originalName
     * @param string|null $mime
     * @return array|null
     */
    private function materializeBase64(
        $dataUrl,
        $userId,
        $scriptId,
        $responseId,
        $questionName,
        $fileIndex,
        $originalName = null,
        $mime = null
    ) {
        if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m)) {
            return null;
        }

        $mime = $mime ?: $m[1];
        if (!$this->isImageMime($mime)) {
            return null;
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false || $binary === '') {
            return null;
        }
        if (strlen($binary) > LLM_MAX_FILE_SIZE) {
            error_log('LLM script image: base64 payload exceeds max file size for question ' . $questionName);
            return null;
        }

        $ext = LlmFileNamingService::getExtensionFromMimeType($mime)
            ?: $this->extensionFromName($originalName)
            ?: 'jpg';
        if (!in_array($ext, LLM_ALLOWED_IMAGE_EXTENSIONS, true)) {
            $ext = 'jpg';
        }

        return $this->writeBinaryAsAttachment(
            $binary,
            $mime,
            $ext,
            $userId,
            $scriptId,
            $responseId,
            $questionName,
            $fileIndex,
            $originalName
        );
    }

    /**
     * @param string $relativeOrMarker
     * @param int $userId
     * @param int $scriptId
     * @param string $responseId
     * @param string $questionName
     * @param int $fileIndex
     * @param string|null $originalName
     * @param string|null $mime
     * @return array|null
     */
    private function materializePath(
        $relativeOrMarker,
        $userId,
        $scriptId,
        $responseId,
        $questionName,
        $fileIndex,
        $originalName = null,
        $mime = null
    ) {
        $relative = $this->normalizeFilePathMarker($relativeOrMarker);
        if ($relative === null) {
            return null;
        }

        // Already inside LLM plugin upload — reuse without copying
        $llmFull = $this->llmPluginPath($relative);
        if ($llmFull !== null && is_file($llmFull) && $this->isImagePath($llmFull)) {
            $detectedMime = $mime ?: (LlmFileUtility::detectMimeType($llmFull) ?? 'image/jpeg');
            return array(
                'path' => $relative,
                'original_name' => $originalName ?: basename($relative),
                'type' => $detectedMime,
                'is_image' => true,
                'question' => $questionName,
                'response_id' => $responseId,
                'size' => filesize($llmFull) ?: null,
            );
        }

        $sourceFull = $this->resolveExternalPath($relative);
        if ($sourceFull === null || !is_file($sourceFull)) {
            error_log('LLM script image: path not found for question ' . $questionName . ': ' . $relative);
            return null;
        }
        if (!$this->isImagePath($sourceFull) && !$this->isImageMime((string)$mime)) {
            return null;
        }

        $size = filesize($sourceFull);
        if ($size === false || $size === 0 || $size > LLM_MAX_FILE_SIZE) {
            return null;
        }

        $binary = file_get_contents($sourceFull);
        if ($binary === false || $binary === '') {
            return null;
        }

        $detectedMime = $mime
            ?: (LlmFileUtility::detectMimeType($sourceFull) ?? 'image/jpeg');
        $ext = strtolower(pathinfo($sourceFull, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, LLM_ALLOWED_IMAGE_EXTENSIONS, true)) {
            $ext = LlmFileNamingService::getExtensionFromMimeType($detectedMime) ?: 'jpg';
        }

        return $this->writeBinaryAsAttachment(
            $binary,
            $detectedMime,
            $ext,
            $userId,
            $scriptId,
            $responseId,
            $questionName,
            $fileIndex,
            $originalName ?: basename($sourceFull)
        );
    }

    /**
     * @param string $binary
     * @param string $mime
     * @param string $ext
     * @param int $userId
     * @param int $scriptId
     * @param string $responseId
     * @param string $questionName
     * @param int $fileIndex
     * @param string|null $originalName
     * @return array|null
     */
    private function writeBinaryAsAttachment(
        $binary,
        $mime,
        $ext,
        $userId,
        $scriptId,
        $responseId,
        $questionName,
        $fileIndex,
        $originalName = null
    ) {
        $origBase = null;
        if (is_string($originalName) && $originalName !== '') {
            $origBase = pathinfo($this->sanitizeFileName($originalName), PATHINFO_FILENAME);
        }

        $filename = LlmFileNamingService::generateScriptImageFilename(
            $userId,
            $scriptId,
            $responseId,
            $questionName,
            $ext,
            $fileIndex,
            $origBase
        );

        $relativeDir = LlmFileNamingService::getScriptImageDirectory($userId, $scriptId);
        $relativePath = $relativeDir . '/' . $filename;
        $fullPath = __DIR__ . '/../../' . $relativePath;

        if (file_put_contents($fullPath, $binary) === false) {
            error_log('LLM script image: failed to write ' . $relativePath);
            return null;
        }

        return array(
            'path' => $relativePath,
            'original_name' => $originalName
                ? $this->sanitizeFileName($originalName)
                : ($questionName . '.' . $ext),
            'type' => $mime,
            'is_image' => true,
            'question' => $questionName,
            'response_id' => $responseId,
            'size' => strlen($binary),
            // Compact sentinel so async pre-pass can rehydrate without base64
            'sentinel' => array(
                self::SENTINEL_KEY => 1,
                'path' => $relativePath,
                'original_name' => $originalName
                    ? $this->sanitizeFileName($originalName)
                    : ($questionName . '.' . $ext),
                'type' => $mime,
                'question' => $questionName,
                'response_id' => $responseId,
            ),
        );
    }

    /**
     * Replace a variable with a short prompt marker (and optional sentinel JSON
     * when multiple attachments need round-tripping via async args).
     *
     * For prompt interpolation we only need the human marker. For async we
     * store sentinel JSON so a second materialize pass can reload paths.
     *
     * @param string $questionName
     * @param array $attachments
     * @return string
     */
    private function buildPromptMarker($questionName, array $attachments)
    {
        $count = count($attachments);
        $marker = $count > 1
            ? '[Bild: ' . $questionName . ' vorhanden (' . $count . ')]'
            : '[Bild: ' . $questionName . ' vorhanden]';

        // Single attachment: store sentinel JSON so async args stay small but
        // re-executable; execute_llm_script will turn it back into a marker.
        if ($count === 1 && !empty($attachments[0]['sentinel'])) {
            return json_encode($attachments[0]['sentinel'], JSON_UNESCAPED_SLASHES);
        }
        if ($count > 1) {
            $sentinels = array();
            foreach ($attachments as $att) {
                if (!empty($att['sentinel'])) {
                    $sentinels[] = $att['sentinel'];
                }
            }
            if (!empty($sentinels)) {
                return json_encode($sentinels, JSON_UNESCAPED_SLASHES);
            }
        }

        return $marker;
    }

    /**
     * Convert sanitized variables that still hold sentinels into prompt markers
     * after attachments have been collected (final prompt build).
     *
     * @param array $variables
     * @return array
     */
    public function markersForPrompt(array $variables)
    {
        $out = $variables;
        foreach ($variables as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
                continue;
            }
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                continue;
            }
            if ($this->isSentinel($decoded)) {
                $q = $decoded['question'] ?? $key;
                $out[$key] = '[Bild: ' . $q . ' vorhanden]';
            } elseif ($this->isListOfSentinels($decoded)) {
                $q = $decoded[0]['question'] ?? $key;
                $out[$key] = '[Bild: ' . $q . ' vorhanden (' . count($decoded) . ')]';
            }
        }
        return $out;
    }

    /**
     * Strip attachment sentinel helper keys before storing in llmMessages.
     *
     * @param array $attachments
     * @return array
     */
    public function attachmentsForStorage(array $attachments)
    {
        $out = array();
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            unset($att['sentinel']);
            $out[] = $att;
        }
        return $out;
    }

    private function isSentinel($value)
    {
        return is_array($value) && !empty($value[self::SENTINEL_KEY]) && !empty($value['path']);
    }

    private function isListOfSentinels($value)
    {
        if (!is_array($value) || empty($value) || !$this->isListArray($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$this->isSentinel($item)) {
                return false;
            }
        }
        return true;
    }

    private function isSurveyFileList($value)
    {
        if (!is_array($value) || empty($value) || !$this->isListArray($value)) {
            return false;
        }
        return $this->isSurveyFileObject($value[0]);
    }

    private function isSurveyFileObject($value)
    {
        return is_array($value)
            && isset($value['content'])
            && (isset($value['type']) || isset($value['name']));
    }

    private function isListArray(array $value)
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function isImageMime($mime)
    {
        if (!is_string($mime) || $mime === '') {
            return false;
        }
        $mime = strtolower(explode(';', $mime)[0]);
        return strpos($mime, 'image/') === 0;
    }

    private function isImagePath($path)
    {
        if (!is_string($path) || $path === '') {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, LLM_ALLOWED_IMAGE_EXTENSIONS, true);
    }

    private function mimeFromDataUrl($dataUrl)
    {
        if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,#i', $dataUrl, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function extensionFromName($name)
    {
        if (!is_string($name) || $name === '') {
            return null;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }

    private function sanitizeFileName($filename)
    {
        $filename = basename(str_replace(array("\0", "\r", "\n"), '', (string)$filename));
        $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename);
        return $filename !== '' ? $filename : 'image';
    }

    /**
     * @param string $value
     * @return string|null Relative path without ?file_path=
     */
    private function normalizeFilePathMarker($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $value = trim($value);
        $marker = '?file_path=';
        if (stripos($value, $marker) === 0) {
            $value = substr($value, strlen($marker));
        } elseif (stripos($value, 'file_path=') === 0) {
            $value = substr($value, strlen('file_path='));
        }

        $value = urldecode($value);
        $value = str_replace(array("\0", "\r", "\n"), '', $value);
        $value = ltrim($value, '/');

        if ($value === '' || strpos($value, '..') !== false) {
            return null;
        }

        // Must look like an upload path or an image file
        $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
        $looksLikeUpload = strpos($value, 'upload/') === 0 || strpos($value, LLM_UPLOAD_FOLDER . '/') === 0;
        if (!$looksLikeUpload && !in_array($ext, LLM_ALLOWED_IMAGE_EXTENSIONS, true)) {
            return null;
        }

        return $value;
    }

    /**
     * @param string $relative
     * @return string|null Absolute path under LLM plugin
     */
    private function llmPluginPath($relative)
    {
        $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            return null;
        }
        $full = realpath(__DIR__ . '/../../' . $relative);
        $root = realpath(__DIR__ . '/../../');
        if ($full === false || $root === false) {
            // realpath fails if file does not exist yet — still return constructed path for writes
            $constructed = __DIR__ . '/../../' . $relative;
            return $constructed;
        }
        if (strpos($full, $root) !== 0) {
            return null;
        }
        return $full;
    }

    /**
     * Resolve a relative upload path from LLM plugin, sibling plugins, or project root.
     * Avoids hard-coding a sibling plugin folder name.
     *
     * @param string $relative
     * @return string|null Absolute existing file path
     */
    private function resolveExternalPath($relative)
    {
        $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            return null;
        }

        $candidates = array();

        $llm = realpath(__DIR__ . '/../../' . $relative);
        if ($llm !== false) {
            $candidates[] = $llm;
        }

        $pluginsRoot = realpath(__DIR__ . '/../../../');
        if ($pluginsRoot !== false) {
            foreach (glob($pluginsRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: array() as $dir) {
                $candidate = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($candidate)) {
                    $resolved = realpath($candidate);
                    if ($resolved !== false) {
                        $candidates[] = $resolved;
                    }
                }
            }
        }

        $projectRoot = realpath(__DIR__ . '/../../../../../');
        if ($projectRoot !== false) {
            $candidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_file($candidate)) {
                $resolved = realpath($candidate);
                if ($resolved !== false) {
                    $candidates[] = $resolved;
                }
            }
        }

        return !empty($candidates) ? $candidates[0] : null;
    }
}
?>
