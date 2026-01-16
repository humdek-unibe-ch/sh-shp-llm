<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/globals.php';

/**
 * LLM File Naming Service
 * 
 * Centralized file naming and path management for all LLM file operations.
 * Provides consistent naming conventions across:
 * - Image/document uploads (vision models)
 * - Audio recordings (speech-to-text)
 * 
 * File Naming Convention:
 * =======================
 * 
 * For uploaded files (images/documents) with message context:
 *   {user_id}_{section_id}_{conversation_id}_{message_id}_{random}.{ext}
 *   Example: 42_15_123_456_a1b2c3d4e5f6g7h8.png
 * 
 * For temporary uploads (before message ID is assigned):
 *   {user_id}_{section_id}_{conversation_id}_temp_{timestamp}_{random}.{ext}
 *   Example: 42_15_123_temp_1765876608_a1b2c3d4e5f6.png
 * 
 * For audio recordings (no message ID - recorded before send):
 *   {user_id}_{section_id}_{conversation_id}_audio_{timestamp}_{random}.{ext}
 *   Example: 42_15_123_audio_1765876608_a1b2c3d4e5f6.webm
 * 
 * Directory Structure:
 * ====================
 * upload/
 *   {user_id}/
 *     - All files for this user
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmFileNamingService
{
    /**
     * File type constants
     */
    const TYPE_UPLOAD = 'upload';      // Image/document uploads
    const TYPE_AUDIO = 'audio';        // Audio recordings for STT
    const TYPE_TEMP = 'temp';          // Temporary files before message ID

    /**
     * Generate a unique random suffix for filenames
     * 
     * @param int $bytes Number of random bytes (default: 8 = 16 hex chars)
     * @return string Hex-encoded random string
     */
    public static function generateRandomSuffix($bytes = 8)
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Generate filename for a temporary upload (before message ID is known)
     *
     * Format: {user_id}_section_{section_id}_conv_{conversation_id}_temp_{timestamp}_{random}.{ext}
     *
     * @param int $userId User ID
     * @param int $sectionId Section ID where the upload originated
     * @param int $conversationId Conversation ID
     * @param string $extension File extension (without dot)
     * @return string Generated filename
     */
    public static function generateTempUploadFilename($userId, $sectionId, $conversationId, $extension)
    {
        $timestamp = time();
        $random = self::generateRandomSuffix();
        $extension = strtolower(trim($extension, '.'));

        return sprintf(
            '%d_section_%d_conv_%d_temp_%d_%s.%s',
            (int)$userId,
            (int)$sectionId,
            (int)$conversationId,
            $timestamp,
            $random,
            $extension
        );
    }

    /**
     * Generate filename for a finalized upload (with message ID)
     *
     * Format: {user_id}_section_{section_id}_conv_{conversation_id}_msg_{message_id}_{random}.{ext}
     *
     * @param int $userId User ID
     * @param int $sectionId Section ID where the upload originated
     * @param int $conversationId Conversation ID
     * @param int $messageId Message ID
     * @param string $extension File extension (without dot)
     * @return string Generated filename
     */
    public static function generateUploadFilename($userId, $sectionId, $conversationId, $messageId, $extension)
    {
        $random = self::generateRandomSuffix();
        $extension = strtolower(trim($extension, '.'));

        return sprintf(
            '%d_section_%d_conv_%d_msg_%d_%s.%s',
            (int)$userId,
            (int)$sectionId,
            (int)$conversationId,
            (int)$messageId,
            $random,
            $extension
        );
    }

    /**
     * Generate filename for an audio recording (speech-to-text)
     *
     * Format: {user_id}_section_{section_id}_conv_{conversation_id}_audio_{timestamp}_{random}.{ext}
     *
     * Audio files don't have a message ID because they are recorded before
     * the message is sent. The timestamp helps with chronological ordering.
     *
     * @param int $userId User ID
     * @param int $sectionId Section ID where the recording originated
     * @param int|null $conversationId Conversation ID (null if no active conversation)
     * @param string $extension File extension (without dot), default 'webm'
     * @return string Generated filename
     */
    public static function generateAudioFilename($userId, $sectionId, $conversationId = null, $extension = 'webm')
    {
        $timestamp = time();
        $random = self::generateRandomSuffix();
        $extension = strtolower(trim($extension, '.'));

        // Use 0 for conversation ID if not yet created
        $convId = $conversationId ?? 0;

        return sprintf(
            '%d_section_%d_conv_%d_audio_%d_%s.%s',
            (int)$userId,
            (int)$sectionId,
            (int)$convId,
            $timestamp,
            $random,
            $extension
        );
    }

    /**
     * Get the upload directory path for a user
     * 
     * @param int $userId User ID
     * @return string Relative path from plugin root (e.g., "upload/42")
     */
    public static function getUserUploadDirectory($userId)
    {
        return LLM_UPLOAD_FOLDER . '/' . (int)$userId;
    }

    /**
     * Get the full filesystem path for a user's upload directory
     * 
     * @param int $userId User ID
     * @return string Full filesystem path
     */
    public static function getUserUploadFullPath($userId)
    {
        $relativePath = self::getUserUploadDirectory($userId);
        // Go up 2 levels from server/service/ to reach plugin root
        return __DIR__ . '/../../' . $relativePath;
    }

    /**
     * Build the relative file path for storage
     * 
     * @param int $userId User ID
     * @param string $filename The filename
     * @return string Relative path (e.g., "upload/42/42_15_123_456_abc123.png")
     */
    public static function buildRelativePath($userId, $filename)
    {
        return self::getUserUploadDirectory($userId) . '/' . $filename;
    }

    /**
     * Build the full filesystem path for a file
     * 
     * @param int $userId User ID
     * @param string $filename The filename
     * @return string Full filesystem path
     */
    public static function buildFullPath($userId, $filename)
    {
        return self::getUserUploadFullPath($userId) . '/' . $filename;
    }

    /**
     * Build the URL for accessing a file
     * 
     * @param string $relativePath Relative path from plugin root
     * @return string URL query string for file access
     */
    public static function buildFileUrl($relativePath)
    {
        return '?file_path=' . urlencode($relativePath);
    }

    /**
     * Ensure the upload directory exists for a user
     * 
     * @param int $userId User ID
     * @return bool True if directory exists or was created successfully
     * @throws Exception If directory creation fails
     */
    public static function ensureUserDirectoryExists($userId)
    {
        $fullPath = self::getUserUploadFullPath($userId);
        
        if (!is_dir($fullPath)) {
            if (!mkdir($fullPath, 0755, true)) {
                throw new Exception('Failed to create upload directory for user ' . $userId);
            }
        }
        
        return true;
    }

    /**
     * Parse a filename to extract its components
     * 
     * Returns an array with:
     * - user_id: int
     * - section_id: int
     * - conversation_id: int
     * - message_id: int|null (null for temp/audio files)
     * - type: string ('upload', 'temp', 'audio')
     * - timestamp: int|null (only for temp/audio files)
     * - random: string
     * - extension: string
     * 
     * @param string $filename The filename to parse
     * @return array|null Parsed components or null if invalid format
     */
    public static function parseFilename($filename)
    {
        // Remove extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // Try to match finalized upload: {user_id}_section_{section_id}_conv_{conversation_id}_msg_{message_id}_{random}
        // Pattern: digits_section_digits_conv_digits_msg_digits_hex
        if (preg_match('/^(\d+)_section_(\d+)_conv_(\d+)_msg_(\d+)_([a-f0-9]+)$/i', $basename, $matches)) {
            return [
                'user_id' => (int)$matches[1],
                'section_id' => (int)$matches[2],
                'conversation_id' => (int)$matches[3],
                'message_id' => (int)$matches[4],
                'type' => self::TYPE_UPLOAD,
                'timestamp' => null,
                'random' => $matches[5],
                'extension' => $extension
            ];
        }

        // Try to match temp upload: {user_id}_section_{section_id}_conv_{conversation_id}_temp_{timestamp}_{random}
        if (preg_match('/^(\d+)_section_(\d+)_conv_(\d+)_temp_(\d+)_([a-f0-9]+)$/i', $basename, $matches)) {
            return [
                'user_id' => (int)$matches[1],
                'section_id' => (int)$matches[2],
                'conversation_id' => (int)$matches[3],
                'message_id' => null,
                'type' => self::TYPE_TEMP,
                'timestamp' => (int)$matches[4],
                'random' => $matches[5],
                'extension' => $extension
            ];
        }

        // Try to match audio: {user_id}_section_{section_id}_conv_{conversation_id}_audio_{timestamp}_{random}
        if (preg_match('/^(\d+)_section_(\d+)_conv_(\d+)_audio_(\d+)_([a-f0-9]+)$/i', $basename, $matches)) {
            return [
                'user_id' => (int)$matches[1],
                'section_id' => (int)$matches[2],
                'conversation_id' => (int)$matches[3],
                'message_id' => null,
                'type' => self::TYPE_AUDIO,
                'timestamp' => (int)$matches[4],
                'random' => $matches[5],
                'extension' => $extension
            ];
        }

        return null;
    }

    /**
     * Validate that a file belongs to a specific user
     * 
     * @param string $filename The filename to check
     * @param int $userId The expected user ID
     * @return bool True if file belongs to user
     */
    public static function validateFileOwnership($filename, $userId)
    {
        $parsed = self::parseFilename($filename);
        if ($parsed === null) {
            return false;
        }
        return $parsed['user_id'] === (int)$userId;
    }

    /**
     * Get file extension from MIME type
     * 
     * @param string $mimeType The MIME type
     * @return string|null File extension or null if unknown
     */
    public static function getExtensionFromMimeType($mimeType)
    {
        $mimeToExt = [
            // Audio
            'audio/webm' => 'webm',
            'audio/wav' => 'wav',
            'audio/mp3' => 'mp3',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            // Images
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            // Documents
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
        ];
        
        // Handle MIME types with parameters (e.g., "audio/webm;codecs=opus")
        $baseMimeType = explode(';', $mimeType)[0];
        
        return $mimeToExt[$baseMimeType] ?? null;
    }
}
?>
