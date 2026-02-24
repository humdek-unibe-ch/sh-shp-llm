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

}
