# LLM Provider Abstraction System

## Overview

The LLM plugin uses a provider abstraction layer to support multiple LLM API providers (GPUStack, BFH, OpenAI, etc.) with a unified interface. This architecture ensures backward compatibility while enabling easy integration of new providers.

## Architecture

### Components

1. **LlmProviderInterface** - Defines the contract all providers must implement
2. **BaseProvider** - Abstract base class with common functionality
3. **Concrete Providers** - GpuStackProvider, BfhProvider, etc.
4. **LlmProviderRegistry** - Central registry and factory for providers

### Directory Structure

```
server/service/provider/
├── LlmProviderInterface.php    # Interface definition
├── BaseProvider.php             # Abstract base class
├── GpuStackProvider.php         # GPUStack implementation
├── BfhProvider.php              # BFH implementation
└── LlmProviderRegistry.php      # Provider registry & factory
```

## Provider Interface

Each provider implements the following methods:

### Core Methods

- `getProviderId()` - Unique identifier (e.g., 'gpustack', 'bfh')
- `getProviderName()` - Human-readable name
- `canHandle($baseUrl)` - Check if provider handles this URL
- `supportsStreaming()` - Whether provider supports streaming

### Response Normalization

- `normalizeResponse($rawResponse)` - Convert provider-specific response to standard format
- `normalizeStreamingChunk($chunk)` - Normalize streaming chunks

### Request Configuration

- `getApiUrl($baseUrl, $endpoint)` - Build complete API URL
- `getAuthHeaders($apiKey)` - Get authentication headers
- `getAdditionalRequestParams($params)` - Add provider-specific parameters

## Normalized Response Format

All providers return responses in this standard format:

```php
[
    'content' => string,           // Assistant message content
    'role' => string,              // 'assistant'
    'finish_reason' => string,     // 'stop', 'length', etc.
    'usage' => [
        'total_tokens' => int,
        'completion_tokens' => int,
        'prompt_tokens' => int
    ],
    'reasoning' => string|null,    // Optional reasoning (provider-specific)
    'raw_response' => array        // Full original response
]
```

## Supported Providers

### GPUStack (UniBE)

- **Base URL**: `https://gpustack.unibe.ch/v1`
- **Provider ID**: `gpustack`
- **Format**: OpenAI-compatible
- **Streaming**: Yes
- **Features**: Standard text/vision models

### BFH Inference API

- **Base URL**: `https://inference.mlmp.ti.bfh.ch/api/v1`
- **Provider ID**: `bfh`
- **Format**: Enhanced with reasoning content
- **Streaming**: Yes
- **Features**: 
  - Reasoning content via `reasoning_content` field
  - Provider-specific fields support
  - Extended usage statistics

## Adding a New Provider

### Step 1: Create Provider Class

```php
<?php
require_once __DIR__ . '/BaseProvider.php';

class MyCustomProvider extends BaseProvider
{
    public function getProviderId() {
        return 'mycustom';
    }

    public function getProviderName() {
        return 'My Custom API';
    }

    public function canHandle($baseUrl) {
        return strpos($baseUrl, 'api.mycustom.com') !== false;
    }

    public function normalizeResponse($rawResponse) {
        // Convert your API format to normalized format
        return [
            'content' => $rawResponse['output']['text'],
            'role' => 'assistant',
            'finish_reason' => 'stop',
            'usage' => [
                'total_tokens' => $rawResponse['tokens']['total'],
                'completion_tokens' => $rawResponse['tokens']['output'],
                'prompt_tokens' => $rawResponse['tokens']['input']
            ],
            'reasoning' => null,
            'raw_response' => $rawResponse
        ];
    }

    public function normalizeStreamingChunk($chunk) {
        // Handle your streaming format
        // Return content string, '[DONE]', '[USAGE:123]', or null
    }

    public function supportsStreaming() {
        return true;
    }
}
```

### Step 2: Register Provider

Add to `LlmProviderRegistry::initialize()`:

```php
self::$providers = [
    new GpuStackProvider(),
    new BfhProvider(),
    new MyCustomProvider()  // Add here
];
```

### Step 3: Test

The system automatically detects the provider based on the `llm_base_url` configuration.

## Usage in Code

### Automatic Provider Selection

```php
$llm_service = new LlmService($services);
// Provider is automatically selected based on llm_base_url
$response = $llm_service->callLlmApi($messages, $model);
// Response is automatically normalized
```

### Manual Provider Access

```php
$provider = $llm_service->getProvider();
$providerId = $provider->getProviderId();
$providerName = $provider->getProviderName();
```

### Get Provider Info

```php
$info = LlmProviderRegistry::getProviderInfo();
// Returns: ['total_providers' => 2, 'default_provider' => 'gpustack', 'providers' => [...]]
```

## Database Schema

The `reasoning` field was added to `llmMessages` table to store provider-specific reasoning content:

```sql
ALTER TABLE llmMessages ADD COLUMN reasoning longtext DEFAULT NULL;
```

This field is optional and only populated by providers that support it (e.g., BFH API).

## API Response Examples

### GPUStack Response

```json
{
    "choices": [{
        "message": {
            "content": "Hello!",
            "role": "assistant"
        },
        "finish_reason": "stop"
    }],
    "usage": {
        "total_tokens": 15,
        "completion_tokens": 5,
        "prompt_tokens": 10
    }
}
```

### BFH Response

```json
{
    "id": "chatcmpl-761",
    "created": 1766394796,
    "model": "gpt-oss:120b",
    "choices": [{
        "message": {
            "content": "Hello!",
            "role": "assistant",
            "reasoning_content": "User greeted, respond politely.",
            "provider_specific_fields": {...}
        },
        "finish_reason": "stop"
    }],
    "usage": {
        "total_tokens": 268,
        "completion_tokens": 53,
        "prompt_tokens": 215
    }
}
```

### Normalized Response (Both)

```php
[
    'content' => 'Hello!',
    'role' => 'assistant',
    'finish_reason' => 'stop',
    'usage' => [
        'total_tokens' => 268,
        'completion_tokens' => 53,
        'prompt_tokens' => 215
    ],
    'reasoning' => 'User greeted, respond politely.', // Only BFH
    'raw_response' => [...] // Full original response
]
```

## Benefits

1. **Backward Compatibility** - Existing code continues working
2. **Extensibility** - Easy to add new providers
3. **Separation of Concerns** - Provider logic isolated from business logic
4. **Testability** - Easy to mock providers for testing
5. **Flexibility** - Support provider-specific features (reasoning, etc.)
6. **Maintainability** - Clear structure and documentation

## Error Handling

Providers handle errors during normalization:

```php
try {
    $normalized = $provider->normalizeResponse($response);
} catch (Exception $e) {
    error_log('Provider normalization error: ' . $e->getMessage());
    throw new Exception('Failed to normalize LLM response');
}
```

Streaming errors are handled gracefully with fallback content.

## Future Extensions

Potential additions:

- **OpenAI Provider** - Official OpenAI API support
- **Anthropic Provider** - Claude API support
- **Local Model Provider** - Ollama, LM Studio, etc.
- **Custom Model Mapping** - Map model names between providers
- **Rate Limiting** - Provider-specific rate limit handling
- **Cost Tracking** - Provider-specific usage costs

## Configuration

Providers are selected automatically based on `llm_base_url` in the CMS configuration page:

- `https://gpustack.unibe.ch/v1` → GPUStack Provider
- `https://inference.mlmp.ti.bfh.ch/api/v1` → BFH Provider
- Other URLs → Default Provider (GPUStack)

No additional configuration required!

