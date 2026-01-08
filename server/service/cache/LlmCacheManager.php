<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Cache Manager
 * 
 * Centralized cache management for the LLM plugin.
 * Provides consistent cache operations with proper key generation and TTL management.
 * 
 * This class eliminates duplicated cache logic across services and ensures
 * consistent cache behavior throughout the plugin.
 * 
 * Usage:
 * ```php
 * $cacheManager = new LlmCacheManager($cache, $db);
 * 
 * // Clear all user data
 * $cacheManager->clearUserCache($userId);
 * 
 * // Get/set with automatic key generation
 * $data = $cacheManager->get(LLM_CACHE_USER_CONVERSATIONS, $userId, ['limit' => 20]);
 * $cacheManager->set(LLM_CACHE_USER_CONVERSATIONS, $userId, $data, ['limit' => 20]);
 * ```
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmCacheManager
{
    /** @var object SelfHelp cache instance */
    private $cache;

    /** @var object SelfHelp database instance */
    private $db;

    /** @var int Default cache TTL in seconds (5 minutes) */
    const DEFAULT_TTL = 300;

    /** @var int Rate limit cache TTL in seconds (1 minute) */
    const RATE_LIMIT_TTL = 60;

    /** @var int Short cache TTL for frequently changing data (1 minute) */
    const SHORT_TTL = 60;

    /** @var int Long cache TTL for stable data (30 minutes) */
    const LONG_TTL = 1800;

    /**
     * Constructor
     * 
     * @param object $cache SelfHelp cache instance
     * @param object $db SelfHelp database instance
     */
    public function __construct($cache, $db)
    {
        $this->cache = $cache;
        $this->db = $db;
    }

    /* =========================================================================
     * USER CACHE OPERATIONS
     * ========================================================================= */

    /**
     * Clear all cache data for a user
     * 
     * This is the primary method to call when user data changes.
     * Clears conversations list and all message caches.
     * 
     * @param int $user_id User ID
     * @return void
     */
    public function clearUserCache($user_id)
    {
        // Clear the user's conversation list cache
        $this->cache->clear_cache(LLM_CACHE_USER_CONVERSATIONS, $user_id);
        
        // Clear message caches for all user's conversations
        $this->clearUserConversationsMessageCache($user_id);
    }

    /**
     * Clear message cache for all conversations belonging to a user
     * 
     * @param int $user_id User ID
     * @return void
     */
    public function clearUserConversationsMessageCache($user_id)
    {
        // Get all conversation IDs for this user (including blocked ones)
        $conversations = $this->db->query_db(
            "SELECT id FROM llmConversations WHERE id_users = ? AND deleted = 0",
            [$user_id]
        );

        if ($conversations) {
            foreach ($conversations as $conversation) {
                $this->clearConversationMessageCache($conversation['id']);
            }
        }
    }

    /* =========================================================================
     * CONVERSATION CACHE OPERATIONS
     * ========================================================================= */

    /**
     * Clear all cache data for a conversation
     * 
     * @param int $conversation_id Conversation ID
     * @return void
     */
    public function clearConversationCache($conversation_id)
    {
        $this->clearConversationMessageCache($conversation_id);
    }

    /**
     * Clear message cache for a specific conversation
     * 
     * @param int $conversation_id Conversation ID
     * @return void
     */
    public function clearConversationMessageCache($conversation_id)
    {
        $this->cache->clear_cache(LLM_CACHE_CONVERSATION_MESSAGES, $conversation_id);
    }

    /* =========================================================================
     * RATE LIMIT CACHE OPERATIONS
     * ========================================================================= */

    /**
     * Get rate limit data for a user
     * 
     * @param int $user_id User ID
     * @return array|false Rate limit data or false if not cached
     */
    public function getRateLimitData($user_id)
    {
        $cache_key = LLM_CACHE_RATE_LIMIT . '_' . $user_id;
        return $this->cache->get($cache_key);
    }

    /**
     * Set rate limit data for a user
     * 
     * @param int $user_id User ID
     * @param array $data Rate limit data structure
     * @return void
     */
    public function setRateLimitData($user_id, array $data)
    {
        $cache_key = LLM_CACHE_RATE_LIMIT . '_' . $user_id;
        $this->cache->set($cache_key, $data, self::RATE_LIMIT_TTL);
    }

    /**
     * Initialize a new rate limit data structure
     * 
     * @return array Initial rate limit data with current minute
     */
    public function initRateLimitData()
    {
        return [
            'minute' => date('Y-m-d H:i'),
            'requests' => 0,
            'conversations' => []
        ];
    }

    /**
     * Check if rate limit data needs to be reset (new minute)
     * 
     * @param array $rate_data Existing rate limit data
     * @return bool True if data should be reset
     */
    public function shouldResetRateLimit(array $rate_data)
    {
        $current_minute = date('Y-m-d H:i');
        return !isset($rate_data['minute']) || $rate_data['minute'] !== $current_minute;
    }

    /* =========================================================================
     * GENERIC CACHE OPERATIONS
     * ========================================================================= */

    /**
     * Get cached data with automatic key generation
     * 
     * @param string $prefix Cache key prefix (use LLM_CACHE_* constants)
     * @param mixed $id Primary identifier
     * @param array $params Additional parameters for key generation
     * @return mixed|false Cached data or false if not found
     */
    public function get($prefix, $id, array $params = [])
    {
        $cache_key = $this->cache->generate_key($prefix, $id, $params);
        return $this->cache->get($cache_key);
    }

    /**
     * Set cached data with automatic key generation
     * 
     * @param string $prefix Cache key prefix (use LLM_CACHE_* constants)
     * @param mixed $id Primary identifier
     * @param mixed $data Data to cache
     * @param array $params Additional parameters for key generation
     * @param int $ttl Cache TTL in seconds (default: 5 minutes)
     * @return void
     */
    public function set($prefix, $id, $data, array $params = [], $ttl = self::DEFAULT_TTL)
    {
        $cache_key = $this->cache->generate_key($prefix, $id, $params);
        $this->cache->set($cache_key, $data, $ttl);
    }

    /**
     * Clear cached data
     * 
     * @param string $prefix Cache key prefix
     * @param mixed $id Primary identifier
     * @return void
     */
    public function clear($prefix, $id)
    {
        $this->cache->clear_cache($prefix, $id);
    }

    /**
     * Check if data exists in cache
     * 
     * @param string $prefix Cache key prefix
     * @param mixed $id Primary identifier
     * @param array $params Additional parameters for key generation
     * @return bool True if cached data exists
     */
    public function has($prefix, $id, array $params = [])
    {
        return $this->get($prefix, $id, $params) !== false;
    }

    /**
     * Get or set cached data (cache-aside pattern)
     * 
     * If data exists in cache, return it. Otherwise, call the callback,
     * cache the result, and return it.
     * 
     * @param string $prefix Cache key prefix
     * @param mixed $id Primary identifier
     * @param callable $callback Function to generate data if not cached
     * @param array $params Additional parameters for key generation
     * @param int $ttl Cache TTL in seconds
     * @return mixed Cached or generated data
     */
    public function remember($prefix, $id, callable $callback, array $params = [], $ttl = self::DEFAULT_TTL)
    {
        $cached = $this->get($prefix, $id, $params);
        
        if ($cached !== false) {
            return $cached;
        }

        $data = $callback();
        $this->set($prefix, $id, $data, $params, $ttl);
        
        return $data;
    }
}
