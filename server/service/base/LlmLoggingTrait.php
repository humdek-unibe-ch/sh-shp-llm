<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Logging Trait
 * 
 * Provides consistent transaction logging and debug logging across all LLM services.
 * Uses SelfHelp's Transaction service for audit trail.
 * 
 * Usage:
 * ```php
 * class MyService {
 *     use LlmLoggingTrait;
 *     
 *     protected $services; // Required for logTransaction()
 *     
 *     public function doSomething() {
 *         $this->logDebug('Starting operation', ['param' => $value]);
 *         // ... do work ...
 *         $this->logTransaction(transactionTypes_insert, 'table', $id, $userId, 'Details');
 *     }
 * }
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
trait LlmLoggingTrait
{
    /**
     * Log a transaction using SelfHelp's Transaction service
     * 
     * Records an audit trail entry for database operations.
     * Requires $this->services to be set.
     * 
     * @param string $operation Transaction type constant (transactionTypes_insert, transactionTypes_update, transactionTypes_delete)
     * @param string $table Database table name
     * @param int $record_id The ID of the affected record
     * @param int $user_id The ID of the user performing the action
     * @param string $details Human-readable description of the action
     * @return void
     */
    protected function logTransaction($operation, $table, $record_id, $user_id, $details = '')
    {
        if (!isset($this->services)) {
            error_log('[LLM] LlmLoggingTrait::logTransaction() - services not available');
            return;
        }

        try {
            $this->services->get_transaction()->add_transaction(
                $operation,                    // tran_type
                TRANSACTION_BY_LLM_PLUGIN,     // tran_by
                $user_id,                      // user_id
                $table,                        // table_name
                $record_id,                    // entry_id
                false,                         // log_row (don't log full row data for privacy)
                $details                       // verbal_log
            );
        } catch (Exception $e) {
            error_log('[LLM] LlmLoggingTrait::logTransaction() failed - ' . $e->getMessage());
        }
    }

    /**
     * Log a debug message with consistent formatting
     * 
     * Outputs to PHP error log with class context.
     * Use for development and troubleshooting.
     * 
     * @param string $message The debug message
     * @param array $context Additional context data (will be JSON encoded)
     * @return void
     */
    protected function logDebug($message, array $context = [])
    {
        $class = static::class;
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[LLM:{$class}] {$message}{$contextStr}");
    }

    /**
     * Log an error message with consistent formatting
     * 
     * Outputs to PHP error log with ERROR prefix.
     * Use for error conditions that need attention.
     * 
     * @param string $message The error message
     * @param Exception|null $exception Optional exception for additional context
     * @return void
     */
    protected function logError($message, ?Exception $exception = null)
    {
        $class = static::class;
        $exceptionStr = $exception 
            ? ' | Exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()
            : '';
        error_log("[LLM:{$class}] ERROR: {$message}{$exceptionStr}");
    }

    /**
     * Log a warning message with consistent formatting
     * 
     * Outputs to PHP error log with WARNING prefix.
     * Use for non-critical issues that should be monitored.
     * 
     * @param string $message The warning message
     * @param array $context Additional context data
     * @return void
     */
    protected function logWarning($message, array $context = [])
    {
        $class = static::class;
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[LLM:{$class}] WARNING: {$message}{$contextStr}");
    }

    /**
     * Log an info message with consistent formatting
     * 
     * Outputs to PHP error log with INFO prefix.
     * Use for significant events that aren't errors.
     * 
     * @param string $message The info message
     * @param array $context Additional context data
     * @return void
     */
    protected function logInfo($message, array $context = [])
    {
        $class = static::class;
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        error_log("[LLM:{$class}] INFO: {$message}{$contextStr}");
    }
}
