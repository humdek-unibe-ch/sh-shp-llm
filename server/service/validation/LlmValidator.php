<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/../exception/LlmValidationException.php';

/**
 * LLM Validator
 * 
 * Centralized validation for the LLM plugin.
 * Provides consistent validation patterns and error messages.
 * 
 * All methods are static for easy use throughout the codebase.
 * Methods throw LlmValidationException on failure or return the validated/sanitized value.
 * 
 * Usage:
 * ```php
 * // Validate and get sanitized value
 * $content = LlmValidator::messageContent($rawContent);
 * $userId = LlmValidator::userId($id);
 * $temp = LlmValidator::temperature($temp, 0.7); // with default
 * 
 * // File validation
 * LlmValidator::fileUpload($file);
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmValidator
{
    /* =========================================================================
     * MESSAGE VALIDATION
     * ========================================================================= */

    /**
     * Validate message content
     * 
     * @param mixed $content Content to validate
     * @return string Validated and trimmed content
     * @throws LlmValidationException If validation fails
     */
    public static function messageContent($content)
    {
        if (!is_string($content)) {
            throw LlmValidationException::invalidType('content', 'string');
        }

        $content = trim($content);

        if (empty($content)) {
            throw LlmValidationException::required('content');
        }

        return $content;
    }

    /**
     * Validate message role
     * 
     * @param string $role Role to validate
     * @return string Validated role
     * @throws LlmValidationException If validation fails
     */
    public static function role($role)
    {
        $validRoles = ['user', 'assistant', 'system'];
        
        if (!in_array($role, $validRoles, true)) {
            throw LlmValidationException::invalidOption('role', $validRoles);
        }

        return $role;
    }

    /* =========================================================================
     * ID VALIDATION
     * ========================================================================= */

    /**
     * Validate conversation ID
     * 
     * @param mixed $id ID to validate
     * @return int Validated ID
     * @throws LlmValidationException If validation fails
     */
    public static function conversationId($id)
    {
        return self::positiveInt($id, 'conversation_id');
    }

    /**
     * Validate user ID
     * 
     * @param mixed $id ID to validate
     * @return int Validated ID
     * @throws LlmValidationException If validation fails
     */
    public static function userId($id)
    {
        return self::positiveInt($id, 'user_id');
    }

    /**
     * Validate message ID
     * 
     * @param mixed $id ID to validate
     * @return int Validated ID
     * @throws LlmValidationException If validation fails
     */
    public static function messageId($id)
    {
        return self::positiveInt($id, 'message_id');
    }

    /**
     * Validate section ID
     * 
     * @param mixed $id ID to validate
     * @return int Validated ID
     * @throws LlmValidationException If validation fails
     */
    public static function sectionId($id)
    {
        return self::positiveInt($id, 'section_id');
    }

    /**
     * Validate any positive integer ID
     * 
     * @param mixed $id ID to validate
     * @param string $fieldName Field name for error messages
     * @return int Validated ID
     * @throws LlmValidationException If validation fails
     */
    public static function positiveInt($id, $fieldName = 'id')
    {
        if (!is_numeric($id) || (int)$id <= 0) {
            throw LlmValidationException::forField($fieldName, "Invalid {$fieldName}: must be a positive integer");
        }

        return (int)$id;
    }

    /* =========================================================================
     * LLM PARAMETER VALIDATION
     * ========================================================================= */

    /**
     * Validate temperature value
     * 
     * Clamps value to valid range (0.0 - 2.0).
     * 
     * @param mixed $temperature Temperature to validate
     * @param float $default Default value if null/empty
     * @return float Validated and clamped temperature
     */
    public static function temperature($temperature, $default = null)
    {
        if ($default === null) {
            $default = defined('LLM_DEFAULT_TEMPERATURE') ? LLM_DEFAULT_TEMPERATURE : 0.7;
        }

        if ($temperature === null || $temperature === '') {
            return (float)$default;
        }

        $temp = (float)$temperature;
        
        // Clamp to valid range (0.0 - 2.0)
        return max(0.0, min(2.0, $temp));
    }

    /**
     * Validate max tokens value
     * 
     * Clamps value to valid range.
     * 
     * @param mixed $maxTokens Max tokens to validate
     * @param int $default Default value if null/empty
     * @param int $max Maximum allowed value
     * @return int Validated and clamped max tokens
     */
    public static function maxTokens($maxTokens, $default = null, $max = 16384)
    {
        if ($default === null) {
            $default = defined('LLM_DEFAULT_MAX_TOKENS') ? LLM_DEFAULT_MAX_TOKENS : 2048;
        }

        if ($maxTokens === null || $maxTokens === '') {
            return (int)$default;
        }

        $tokens = (int)$maxTokens;
        
        // Clamp to valid range
        if ($tokens < 1) {
            return (int)$default;
        }
        
        return min($tokens, $max);
    }

    /**
     * Validate timeout value
     * 
     * @param mixed $timeout Timeout in seconds
     * @param int $default Default value
     * @param int $max Maximum allowed value
     * @return int Validated timeout
     */
    public static function timeout($timeout, $default = 30, $max = 300)
    {
        if ($timeout === null || $timeout === '') {
            return $default;
        }

        $timeout = (int)$timeout;
        
        if ($timeout < 1) {
            return $default;
        }
        
        return min($timeout, $max);
    }

    /**
     * Validate model name
     * 
     * @param mixed $model Model name to validate
     * @return string Validated model name
     * @throws LlmValidationException If validation fails
     */
    public static function modelName($model)
    {
        if (!is_string($model) || empty(trim($model))) {
            throw LlmValidationException::required('model');
        }

        $model = trim($model);

        // Basic validation - model names should be alphanumeric with some special chars
        if (!preg_match('/^[a-zA-Z0-9_\-.:\/]+$/', $model)) {
            throw LlmValidationException::invalidFormat('model', 'alphanumeric with - _ . : /');
        }

        return $model;
    }

    /* =========================================================================
     * FILE VALIDATION
     * ========================================================================= */

    /**
     * Validate file upload
     * 
     * @param array $file File data from $_FILES
     * @return array Validated file data
     * @throws LlmValidationException If validation fails
     */
    public static function fileUpload(array $file)
    {
        // Check for upload errors
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new LlmValidationException(
                'File upload error',
                ['file' => self::getUploadErrorMessage($errorCode)]
            );
        }

        // Check file size
        $maxSize = defined('LLM_MAX_FILE_SIZE') ? LLM_MAX_FILE_SIZE : 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw LlmValidationException::forField(
                'file',
                'File exceeds maximum size of ' . self::formatFileSize($maxSize)
            );
        }

        // Check file size is not zero
        if ($file['size'] === 0) {
            throw LlmValidationException::forField('file', 'File is empty');
        }

        // Check extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (empty($extension)) {
            throw LlmValidationException::forField('file', 'File has no extension');
        }

        $allowedExtensions = defined('LLM_ALLOWED_EXTENSIONS') ? LLM_ALLOWED_EXTENSIONS : [];
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
            $allowed = implode(', ', $allowedExtensions);
            throw LlmValidationException::forField(
                'file',
                "File type .{$extension} not allowed. Allowed: {$allowed}"
            );
        }

        // Validate MIME type if function exists
        if (function_exists('llm_validate_mime_type')) {
            if (!llm_validate_mime_type($extension, $file['type'])) {
                throw LlmValidationException::forField(
                    'file',
                    "Invalid content type for .{$extension} file"
                );
            }
        }

        return $file;
    }

    /**
     * Validate file extension
     * 
     * @param string $filename Filename to check
     * @return string Validated lowercase extension
     * @throws LlmValidationException If validation fails
     */
    public static function fileExtension($filename)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (empty($extension)) {
            throw LlmValidationException::forField('file', 'File has no extension');
        }

        $allowedExtensions = defined('LLM_ALLOWED_EXTENSIONS') ? LLM_ALLOWED_EXTENSIONS : [];
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
            throw LlmValidationException::invalidOption('file_extension', $allowedExtensions);
        }

        return $extension;
    }

    /* =========================================================================
     * GENERAL VALIDATION
     * ========================================================================= */

    /**
     * Validate required string
     * 
     * @param mixed $value Value to validate
     * @param string $fieldName Field name for error messages
     * @return string Trimmed string
     * @throws LlmValidationException If validation fails
     */
    public static function requiredString($value, $fieldName)
    {
        if (!is_string($value)) {
            throw LlmValidationException::invalidType($fieldName, 'string');
        }

        $value = trim($value);

        if (empty($value)) {
            throw LlmValidationException::required($fieldName);
        }

        return $value;
    }

    /**
     * Validate optional string
     * 
     * @param mixed $value Value to validate
     * @param string|null $default Default value if empty
     * @return string|null Trimmed string or default
     */
    public static function optionalString($value, $default = null)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            return $default;
        }

        return trim($value);
    }

    /**
     * Validate array
     * 
     * @param mixed $value Value to validate
     * @param string $fieldName Field name for error messages
     * @return array Validated array
     * @throws LlmValidationException If validation fails
     */
    public static function requiredArray($value, $fieldName)
    {
        if (!is_array($value)) {
            throw LlmValidationException::invalidType($fieldName, 'array');
        }

        if (empty($value)) {
            throw LlmValidationException::required($fieldName);
        }

        return $value;
    }

    /**
     * Validate JSON string
     * 
     * @param mixed $value Value to validate
     * @param string $fieldName Field name for error messages
     * @return array Decoded JSON as array
     * @throws LlmValidationException If validation fails
     */
    public static function jsonString($value, $fieldName)
    {
        if (!is_string($value)) {
            throw LlmValidationException::invalidType($fieldName, 'JSON string');
        }

        $decoded = json_decode($value, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw LlmValidationException::invalidFormat($fieldName, 'valid JSON');
        }

        return $decoded;
    }

    /**
     * Validate boolean
     * 
     * @param mixed $value Value to validate
     * @param bool $default Default value
     * @return bool Boolean value
     */
    public static function boolean($value, $default = false)
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /* =========================================================================
     * HELPER METHODS
     * ========================================================================= */

    /**
     * Get upload error message
     * 
     * @param int $errorCode PHP upload error code
     * @return string Human-readable error message
     */
    private static function getUploadErrorMessage($errorCode)
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
        ];

        return $messages[$errorCode] ?? 'Unknown upload error';
    }

    /**
     * Format file size for display
     * 
     * @param int $bytes Size in bytes
     * @return string Human-readable size
     */
    private static function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
