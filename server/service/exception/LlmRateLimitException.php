<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmException.php';

/**
 * LLM Rate Limit Exception
 * 
 * Thrown when rate limits are exceeded.
 * Provides information about when the limit will reset.
 * 
 * Usage:
 * ```php
 * // Requests per minute exceeded
 * throw LlmRateLimitException::requestsPerMinute(10);
 * 
 * // Concurrent conversations exceeded
 * throw LlmRateLimitException::concurrentConversations(3);
 * 
 * // Custom rate limit
 * throw new LlmRateLimitException('Custom limit exceeded', 30);
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmRateLimitException extends LlmException
{
    /** @var int Seconds until the rate limit resets */
    protected $retryAfter;

    /** @var string Type of rate limit that was exceeded */
    protected $limitType;

    /** @var int The limit value that was exceeded */
    protected $limitValue;

    /**
     * Constructor
     * 
     * @param string $message Error message
     * @param int $retryAfter Seconds until limit resets (default: 60)
     * @param array $context Additional context data
     */
    public function __construct($message = 'Rate limit exceeded', $retryAfter = 60, array $context = [])
    {
        parent::__construct($message, 429, $context);
        $this->retryAfter = $retryAfter;
        $this->limitType = $context['limit_type'] ?? 'unknown';
        $this->limitValue = $context['limit'] ?? 0;
    }

    /**
     * Get seconds until rate limit resets
     * 
     * @return int Seconds until reset
     */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }

    /**
     * Get the type of rate limit exceeded
     * 
     * @return string Limit type (e.g., 'requests_per_minute', 'concurrent_conversations')
     */
    public function getLimitType()
    {
        return $this->limitType;
    }

    /**
     * Get the limit value that was exceeded
     * 
     * @return int Limit value
     */
    public function getLimitValue()
    {
        return $this->limitValue;
    }

    /**
     * Get the timestamp when the limit will reset
     * 
     * @return int Unix timestamp
     */
    public function getResetTime()
    {
        return time() + $this->retryAfter;
    }

    /**
     * Get formatted reset time
     * 
     * @param string $format Date format
     * @return string Formatted reset time
     */
    public function getFormattedResetTime($format = 'H:i:s')
    {
        return date($format, $this->getResetTime());
    }

    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        $data = parent::toArray();
        $data['retry_after'] = $this->retryAfter;
        $data['reset_at'] = $this->getResetTime();
        $data['limit_type'] = $this->limitType;
        $data['limit_value'] = $this->limitValue;
        return $data;
    }

    /* =========================================================================
     * STATIC FACTORY METHODS
     * ========================================================================= */

    /**
     * Create exception for requests per minute limit
     * 
     * @param int $limit The limit that was exceeded
     * @return self
     */
    public static function requestsPerMinute($limit)
    {
        return new self(
            "Rate limit exceeded: {$limit} requests per minute",
            LLM_RATE_LIMIT_COOLDOWN_SECONDS,
            [
                'limit_type' => 'requests_per_minute',
                'limit' => $limit
            ]
        );
    }

    /**
     * Create exception for concurrent conversations limit
     * 
     * @param int $limit The limit that was exceeded
     * @return self
     */
    public static function concurrentConversations($limit)
    {
        return new self(
            "Concurrent conversation limit exceeded: {$limit} conversations",
            LLM_RATE_LIMIT_COOLDOWN_SECONDS,
            [
                'limit_type' => 'concurrent_conversations',
                'limit' => $limit
            ]
        );
    }

    /**
     * Create exception for API rate limit (from external provider)
     * 
     * @param string $provider Provider name
     * @param int $retryAfter Seconds until reset
     * @return self
     */
    public static function apiLimit($provider, $retryAfter = 60)
    {
        return new self(
            "API rate limit exceeded for {$provider}",
            $retryAfter,
            [
                'limit_type' => 'api_rate_limit',
                'provider' => $provider
            ]
        );
    }

    /**
     * Create exception for daily limit
     * 
     * @param int $limit Daily limit
     * @param int $secondsUntilReset Seconds until midnight reset
     * @return self
     */
    public static function dailyLimit($limit, $secondsUntilReset = null)
    {
        if ($secondsUntilReset === null) {
            // Calculate seconds until midnight
            $tomorrow = strtotime('tomorrow');
            $secondsUntilReset = $tomorrow - time();
        }

        return new self(
            "Daily limit of {$limit} requests exceeded",
            $secondsUntilReset,
            [
                'limit_type' => 'daily_limit',
                'limit' => $limit
            ]
        );
    }
}
