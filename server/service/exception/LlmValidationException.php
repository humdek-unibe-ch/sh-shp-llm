<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmException.php';

/**
 * LLM Validation Exception
 * 
 * Thrown when input validation fails.
 * Supports multiple field errors and provides helper methods for common validation scenarios.
 * 
 * Usage:
 * ```php
 * // Single field error
 * throw LlmValidationException::forField('email', 'Invalid email format');
 * 
 * // Multiple errors
 * throw new LlmValidationException('Validation failed', [
 *     'email' => 'Invalid email format',
 *     'password' => 'Password too short'
 * ]);
 * 
 * // Required field
 * throw LlmValidationException::required('username');
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmValidationException extends LlmException
{
    /** @var array Field-specific validation errors */
    protected $errors = [];

    /**
     * Constructor
     * 
     * @param string $message General error message
     * @param array $errors Field-specific errors (field => message)
     * @param array $context Additional context data
     */
    public function __construct($message = 'Validation failed', array $errors = [], array $context = [])
    {
        parent::__construct($message, 400, $context);
        $this->errors = $errors;
    }

    /**
     * Get all validation errors
     * 
     * @return array Field-specific errors
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Get error for a specific field
     * 
     * @param string $field Field name
     * @return string|null Error message or null
     */
    public function getFieldError($field)
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Check if a specific field has an error
     * 
     * @param string $field Field name
     * @return bool True if field has error
     */
    public function hasFieldError($field)
    {
        return isset($this->errors[$field]);
    }

    /**
     * Add an error for a field
     * 
     * @param string $field Field name
     * @param string $message Error message
     * @return self For method chaining
     */
    public function addError($field, $message)
    {
        $this->errors[$field] = $message;
        return $this;
    }

    /**
     * Get the first error message
     * 
     * @return string|null First error message or null
     */
    public function getFirstError()
    {
        return !empty($this->errors) ? reset($this->errors) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        $data = parent::toArray();
        $data['errors'] = $this->errors;
        return $data;
    }

    /* =========================================================================
     * STATIC FACTORY METHODS
     * ========================================================================= */

    /**
     * Create exception for a single field error
     * 
     * @param string $field Field name
     * @param string $message Error message
     * @return self
     */
    public static function forField($field, $message)
    {
        return new self("Validation failed for {$field}", [$field => $message]);
    }

    /**
     * Create exception for a required field
     * 
     * @param string $field Field name
     * @return self
     */
    public static function required($field)
    {
        return self::forField($field, "{$field} is required");
    }

    /**
     * Create exception for invalid type
     * 
     * @param string $field Field name
     * @param string $expectedType Expected type
     * @return self
     */
    public static function invalidType($field, $expectedType)
    {
        return self::forField($field, "{$field} must be a {$expectedType}");
    }

    /**
     * Create exception for invalid format
     * 
     * @param string $field Field name
     * @param string $format Expected format description
     * @return self
     */
    public static function invalidFormat($field, $format)
    {
        return self::forField($field, "{$field} has invalid format. Expected: {$format}");
    }

    /**
     * Create exception for value out of range
     * 
     * @param string $field Field name
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @return self
     */
    public static function outOfRange($field, $min, $max)
    {
        return self::forField($field, "{$field} must be between {$min} and {$max}");
    }

    /**
     * Create exception for value too long
     * 
     * @param string $field Field name
     * @param int $maxLength Maximum length
     * @return self
     */
    public static function tooLong($field, $maxLength)
    {
        return self::forField($field, "{$field} must not exceed {$maxLength} characters");
    }

    /**
     * Create exception for value too short
     * 
     * @param string $field Field name
     * @param int $minLength Minimum length
     * @return self
     */
    public static function tooShort($field, $minLength)
    {
        return self::forField($field, "{$field} must be at least {$minLength} characters");
    }

    /**
     * Create exception for invalid option
     * 
     * @param string $field Field name
     * @param array $validOptions Valid options
     * @return self
     */
    public static function invalidOption($field, array $validOptions)
    {
        $options = implode(', ', $validOptions);
        return self::forField($field, "{$field} must be one of: {$options}");
    }
}
