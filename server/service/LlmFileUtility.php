<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM File Utility Class
 *
 * Shared utilities for file operations across LLM services.
 * Contains common file processing functions to eliminate code duplication.
 */
class LlmFileUtility
{
    /**
     * Detect MIME type using finfo
     * Falls back to file extension-based detection if finfo fails
     *
     * @param string $filePath Path to the file
     * @return string|null Detected MIME type
     */
    public static function detectMimeType($filePath)
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Try finfo first (most reliable)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);

            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Try mime_content_type as fallback
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        return null;
    }
}
?>
