<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/GpuStackProvider.php';
require_once __DIR__ . '/BfhProvider.php';

/**
 * LLM Provider Registry
 * 
 * Central registry for all LLM providers.
 * Handles provider detection, instantiation, and management.
 * 
 * Design Pattern: Registry + Factory
 * - Maintains a registry of all available providers
 * - Automatically detects the correct provider based on base URL
 * - Provides singleton access to provider instances
 * 
 * @author SelfHelp Team
 */
class LlmProviderRegistry
{
    /**
     * @var LlmProviderInterface[] Registered providers
     */
    private static $providers = null;

    /**
     * @var LlmProviderInterface Default provider instance
     */
    private static $defaultProvider = null;

    /**
     * Initialize the provider registry
     * Registers all available providers
     */
    private static function initialize()
    {
        if (self::$providers !== null) {
            return;
        }

        self::$providers = [
            new GpuStackProvider(),
            new BfhProvider()
        ];

        // Set default provider (GPUStack for backward compatibility)
        self::$defaultProvider = self::$providers[0];
    }

    /**
     * Get all registered providers
     * 
     * @return LlmProviderInterface[] Array of provider instances
     */
    public static function getAllProviders()
    {
        self::initialize();
        return self::$providers;
    }

    /**
     * Get provider for a specific base URL
     * 
     * Iterates through registered providers and returns the first one
     * that can handle the given base URL.
     * 
     * @param string $baseUrl Base URL from configuration
     * @return LlmProviderInterface Provider instance
     */
    public static function getProviderForUrl($baseUrl)
    {
        self::initialize();

        foreach (self::$providers as $provider) {
            if ($provider->canHandle($baseUrl)) {
                return $provider;
            }
        }

        // No specific provider found, return default
        error_log("LLM Provider: No specific provider found for URL: $baseUrl, using default");
        return self::$defaultProvider;
    }

    /**
     * Get provider by ID
     * 
     * @param string $providerId Provider identifier (e.g., 'gpustack', 'bfh')
     * @return LlmProviderInterface|null Provider instance or null if not found
     */
    public static function getProviderById($providerId)
    {
        self::initialize();

        foreach (self::$providers as $provider) {
            if ($provider->getProviderId() === $providerId) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Get default provider
     * 
     * @return LlmProviderInterface Default provider instance
     */
    public static function getDefaultProvider()
    {
        self::initialize();
        return self::$defaultProvider;
    }

    /**
     * Register a custom provider
     * 
     * Allows dynamic registration of additional providers at runtime.
     * Useful for plugins or extensions.
     * 
     * @param LlmProviderInterface $provider Provider instance to register
     */
    public static function registerProvider(LlmProviderInterface $provider)
    {
        self::initialize();
        
        // Check if provider already registered
        foreach (self::$providers as $existingProvider) {
            if ($existingProvider->getProviderId() === $provider->getProviderId()) {
                error_log("LLM Provider: Provider with ID {$provider->getProviderId()} already registered");
                return;
            }
        }

        self::$providers[] = $provider;
    }

    /**
     * Get provider information for debugging
     * 
     * @return array Array with provider details
     */
    public static function getProviderInfo()
    {
        self::initialize();

        $info = [
            'total_providers' => count(self::$providers),
            'default_provider' => self::$defaultProvider->getProviderId(),
            'providers' => []
        ];

        foreach (self::$providers as $provider) {
            $info['providers'][] = [
                'id' => $provider->getProviderId(),
                'name' => $provider->getProviderName(),
                
            ];
        }

        return $info;
    }
}
