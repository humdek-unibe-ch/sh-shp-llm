# BFH Provider Update - December 22, 2024

## Overview

The BFH Inference API endpoint has been updated to the new versioned endpoint structure. This document outlines the changes and how the provider handles the new response format.

## URL Changes

### Old Base URL
```
https://inference.mlmp.ti.bfh.ch/api
```

### New Base URL
```
https://inference.mlmp.ti.bfh.ch/api/v1
```

### Full Endpoint
The system constructs the complete endpoint by concatenating:
- Base URL: `https://inference.mlmp.ti.bfh.ch/api/v1`
- Endpoint: `/chat/completions`
- Result: `https://inference.mlmp.ti.bfh.ch/api/v1/chat/completions`

## Request Format

The BFH provider uses the standard OpenAI-compatible request format:

```json
{
  "model": "beechat-v3-gpt-oss",
  "messages": [
    {
      "role": "user",
      "content": "Hello, can you respond?"
    }
  ],
  "temperature": 0.7,
  "max_tokens": 2048,
  "stream": false
}
```

## Response Format

### BFH Enhanced Response Structure

The BFH API returns an enhanced response that includes reasoning content and provider-specific fields:

```json
{
  "id": "chatcmpl-571",
  "created": 1766402209,
  "model": "gpt-oss:120b",
  "object": "chat.completion",
  "system_fingerprint": "fp_ollama",
  "choices": [
    {
      "finish_reason": "stop",
      "index": 0,
      "message": {
        "content": "Yes, I'm here and ready to help. What do you need?",
        "role": "assistant",
        "reasoning_content": "We need to respond briefly, confirm we can respond.",
        "provider_specific_fields": {
          "reasoning": "We need to respond briefly, confirm we can respond.",
          "reasoning_content": "We need to respond briefly, confirm we can respond."
        }
      }
    }
  ],
  "usage": {
    "completion_tokens": 36,
    "prompt_tokens": 215,
    "total_tokens": 251
  }
}
```

### Key Response Fields

#### Standard Fields
- `id`: Unique completion identifier
- `created`: Unix timestamp
- `model`: Model used for generation
- `object`: Response type (`chat.completion`)
- `system_fingerprint`: System identifier

#### Message Fields
- `content`: The actual response text from the AI
- `role`: Always `"assistant"` for AI responses
- `reasoning_content`: **BFH-specific** - The AI's reasoning/thinking process
- `provider_specific_fields`: **BFH-specific** - Additional metadata from the provider

#### Usage Statistics
- `completion_tokens`: Tokens in the response
- `prompt_tokens`: Tokens in the request
- `total_tokens`: Sum of both

## Provider Implementation

### Detection

The `BfhProvider` automatically detects when the base URL contains `inference.mlmp.ti.bfh.ch`:

```php
public function canHandle($baseUrl)
{
    return strpos($baseUrl, 'inference.mlmp.ti.bfh.ch') !== false;
}
```

### Response Normalization

The provider's `normalizeResponse()` method handles the BFH-specific response structure:

```php
public function normalizeResponse($rawResponse)
{
    // Extract standard fields
    $message = $rawResponse['choices'][0]['message'];
    $usage = $rawResponse['usage'] ?? [];

    // Extract reasoning content from BFH-specific fields
    $reasoning = null;
    if (isset($message['reasoning_content']) && !empty($message['reasoning_content'])) {
        $reasoning = $message['reasoning_content'];
    } elseif (isset($message['provider_specific_fields']['reasoning'])) {
        $reasoning = $message['provider_specific_fields']['reasoning'];
    }

    // Return normalized structure
    return [
        'content' => $message['content'],
        'role' => $message['role'],
        'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? 'stop',
        'usage' => [
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0
        ],
        'reasoning' => $reasoning,
        'raw_response' => $rawResponse
    ];
}
```

### Reasoning Content Extraction

The provider intelligently extracts reasoning content from multiple possible locations:

1. **Primary**: `message.reasoning_content` (direct field)
2. **Fallback**: `message.provider_specific_fields.reasoning` (nested field)

This ensures compatibility with different response formats while prioritizing the most specific field.

## Database Storage

The reasoning content is stored in the `llmMessages` table:

```sql
CREATE TABLE `llmMessages` (
    `id` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` int(10) UNSIGNED ZEROFILL NOT NULL,
    `role` enum('user','assistant','system') NOT NULL,
    `content` longtext NOT NULL,
    `reasoning` longtext DEFAULT NULL,  -- ← BFH reasoning content stored here
    `raw_response` longtext DEFAULT NULL,
    -- ... other fields
);
```

## Configuration

### Admin Panel Setup

1. Navigate to **Admin → Modules → LLM Configuration**
2. Set the base URL to: `https://inference.mlmp.ti.bfh.ch/api/v1`
3. Configure your API key
4. Select an appropriate model (e.g., `beechat-v3-gpt-oss`)
5. Save configuration

### Automatic Provider Detection

The system automatically:
- Detects that you're using the BFH provider based on the URL
- Uses the BFH-specific response normalization
- Extracts and stores reasoning content
- Handles provider-specific fields

No additional configuration is required beyond setting the base URL.

## Streaming Support

The BFH provider supports real-time streaming responses via Server-Sent Events (SSE). The streaming implementation uses the same endpoint with `stream: true` in the request payload.

### Streaming Response Format

```
data: {"choices":[{"delta":{"content":"Hello"}}]}

data: {"choices":[{"delta":{"content":" there"}}]}

data: {"choices":[{"finish_reason":"stop"}]}

data: [DONE]
```

The `normalizeStreamingChunk()` method handles these chunks and returns plain text content.

## Benefits of the Update

### 1. Versioned API Endpoint
- Clear API versioning (`/api/v1`)
- Better backwards compatibility for future updates
- Explicit API contract

### 2. Enhanced Response Metadata
- Access to AI reasoning/thinking process
- Provider-specific fields for extensibility
- Richer debugging capabilities

### 3. Transparent Integration
- No changes required to existing code
- Automatic provider detection
- Seamless reasoning content extraction

## Migration Guide

### For Existing Installations

If you're currently using the old BFH endpoint:

1. **Update Configuration**
   ```
   Old: https://inference.mlmp.ti.bfh.ch/api
   New: https://inference.mlmp.ti.bfh.ch/api/v1
   ```

2. **No Code Changes Required**
   - The BfhProvider already handles both formats
   - Provider detection works with both URLs
   - Response normalization is backward compatible

3. **Verify Operation**
   - Test with a simple chat message
   - Check that reasoning content is captured (if available)
   - Verify streaming responses work correctly

### For New Installations

Simply use the new base URL from the start:
```
https://inference.mlmp.ti.bfh.ch/api/v1
```

## Troubleshooting

### Provider Not Detected

If the system isn't detecting the BFH provider:
- Verify the base URL contains `inference.mlmp.ti.bfh.ch`
- Check that the URL is correctly saved in the admin configuration
- Clear any caches (`LlmService::getLlmConfig()` uses static caching)

### Reasoning Content Not Appearing

The reasoning content is optional and may not be present in all responses:
- Check the `raw_response` field in the database to see the full API response
- Verify the model you're using supports reasoning content
- Ensure the `llmMessages.reasoning` column exists in your database

### Connection Issues

If you cannot connect to the API:
- Verify network connectivity to `inference.mlmp.ti.bfh.ch`
- Check that your API key is valid and has proper permissions
- Ensure firewall rules allow outbound HTTPS connections
- Review PHP error logs for curl errors

## Documentation Updates

The following documentation files have been updated to reflect the new BFH endpoint:

- `server/service/provider/BfhProvider.php` - Provider implementation
- `server/service/provider/README.md` - Provider overview
- `README.md` - Main plugin README
- `doc/architecture.md` - System architecture
- `doc/provider-abstraction.md` - Provider abstraction guide
- `doc/configuration.md` - Configuration guide with supported providers
- `CHANGELOG.md` - Version history

## API Compatibility

The BFH provider maintains compatibility with:
- OpenAI-compatible request format
- Standard chat completion structure
- SSE streaming protocol
- Industry-standard authentication (Bearer token)

## Future Considerations

### Potential Enhancements

1. **Reasoning Display**: Add UI elements to display the AI's reasoning process to users
2. **Reasoning Storage**: Implement indexing/search on reasoning content
3. **Provider Fields**: Expose more provider-specific fields through the admin interface
4. **Multi-Provider Support**: Allow switching between providers per conversation

### Version Management

As the BFH API evolves:
- Monitor for new API versions (e.g., `/api/v2`)
- Track deprecation notices for older endpoints
- Plan migration strategies for breaking changes
- Consider version negotiation in the provider

## Support

For questions or issues related to the BFH provider:

1. **Plugin Issues**: Check the main plugin documentation
2. **API Issues**: Consult the BFH Inference API documentation
3. **Provider Bugs**: Review `server/service/provider/BfhProvider.php`
4. **Integration Help**: See `doc/provider-abstraction.md`

## References

- [Provider Abstraction Guide](provider-abstraction.md)
- [Architecture Overview](architecture.md)
- [Configuration Guide](configuration.md)
- [API Reference](api-reference.md)

