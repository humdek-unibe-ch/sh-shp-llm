<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Base LLM Exception
 * 
 * Base exception class for all LLM plugin exceptions.
 * Provides consistent error handling patterns with context data support.
 * 
 * All custom LLM exceptions should extend this class.
 * 
 * Usage:
 * ```php
 * throw new LlmException('Something went wrong', 500, ['user_id' => $userId]);
 * 
 * // In catch block:
 * catch (LlmException $e) {
 *     $errorData = $e->toArray();
 *     // Returns: ['error' => true, 'type' => 'LlmException', 'message' => '...', ...]
 * }
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmException extends Exception
{
    /** @var array Additional context data for debugging */
    protected $context = [];

    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param int $code HTTP-like error code (default: 500)
     * @param array $context Additional context data for debugging
     * @param Throwable|null $previous Previous exception for chaining
     */
    public function __construct($message = '', $code = 500, array $context = [], $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get context data
     * 
     * @return array Context data
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Add context data
     * 
     * @param string $key Context key
     * @param mixed $value Context value
     * @return self For method chaining
     */
    public function addContext($key, $value)
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get a specific context value
     * 
     * @param string $key Context key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Context value or default
     */
    public function getContextValue($key, $default = null)
    {
        return $this->context[$key] ?? $default;
    }

    /**
     * Get a JSON-serializable representation of the exception
     * 
     * Useful for API responses and logging.
     * 
     * @return array Error data structure
     */
    public function toArray()
    {
        return [
            'error' => true,
            'type' => $this->getExceptionType(),
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'context' => $this->context
        ];
    }

    /**
     * Get the short class name (without namespace)
     * 
     * @return string Exception type name
     */
    protected function getExceptionType()
    {
        $class = get_class($this);
        $pos = strrpos($class, '\\');
        return $pos !== false ? substr($class, $pos + 1) : $class;
    }

    /**
     * Convert exception to JSON string
     * 
     * @return string JSON representation
     */
    public function toJson()
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Log the exception using error_log
     * 
     * @return void
     */
    public function log()
    {
        $contextStr = !empty($this->context) 
            ? ' | Context: ' . json_encode($this->context, JSON_UNESCAPED_SLASHES) 
            : '';
        error_log("[LLM Exception] {$this->getExceptionType()}: {$this->getMessage()}{$contextStr}");
    }
}
