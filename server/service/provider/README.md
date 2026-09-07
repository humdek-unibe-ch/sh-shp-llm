# LLM Provider Abstraction Layer

## Quick Start

The provider system automatically detects and uses the correct LLM API provider based on the configured `llm_base_url`:

```php
// In your code - provider is automatically selected
$llm_service = new LlmService($services);
$response = $llm_service->callLlmApi($messages, $model, $temperature, $maxTokens, [
    'conversation_id' => $conversationId,
    'sent_context' => $contextMessages,
    'is_validated' => true
]);
// Response is automatically normalized to standard format
```

## Supported Providers

| Provider | Base URL | Provider ID | Features |
|----------|----------|-------------|----------|
| OpenAI | `https://api.openai.com/v1` | `openai` | Remaps `max_tokens` → `max_completion_tokens`; vision via model-id heuristics; Chat Completions `reasoning_effort` |
| Anthropic | `https://api.anthropic.com/v1` | `anthropic` | Messages API (`/messages`); `x-api-key` + `anthropic-version`; vision via Claude heuristics; `output_config.effort` + adaptive thinking |
| GPUStack (UniBE) | `https://gpustack.unibe.ch/v1` | `gpustack` | Standard OpenAI-compatible API (`max_tokens`); vision via allowlist/patterns |
| BFH Inference API | `https://inference.mlmp.ti.bfh.ch/api/v1` | `bfh` | Enhanced with reasoning content |

Vision detection (`LlmModelCapabilities::isVisionModel` / `LlmService::modelSupportsVision`):
stock OpenAI `GET /v1/models` has **no** modality field. Providers may implement
`modelSupportsVision($id)` (`true`/`false`/`null`). Shared fallbacks: exact
`LLM_VISION_MODELS`, `LLM_VISION_MODEL_PATTERNS` (`*-vl-*`, …), then OpenAI and
Anthropic name heuristics.

Reasoning effort (`llm_reasoning_effort` style/module field):
options from `lookups` (`type_code` = `llmReasoningEffort`). Internal payload
key `reasoning_effort`. `OpenAIProvider` → Chat Completions top-level
`reasoning_effort` (not nested `reasoning`). `AnthropicProvider` →
`output_config.effort` + `thinking.type=adaptive` (`none` → thinking disabled;
`minimal` → `low`). `BaseProvider` strips it (GPUStack).

Model listing uses each provider's `getApiUrl()` + `getAuthHeaders()` (required
for Anthropic; Bearer alone returns 401).

## File Structure

```
server/service/provider/
├── README.md                    # This file
├── LlmProviderInterface.php     # Provider interface definition
├── BaseProvider.php             # Abstract base class
├── OpenAIProvider.php           # OpenAI / Azure
├── AnthropicProvider.php        # Anthropic Claude (Messages API)
├── GpuStackProvider.php         # GPUStack implementation
├── BfhProvider.php              # BFH implementation
└── LlmProviderRegistry.php      # Provider factory & registry
```

## Testing

Verify providers resolve by URL:

```bash
cd server/service/provider
php -r "require 'LlmProviderRegistry.php'; echo LlmProviderRegistry::getProviderForUrl('https://api.anthropic.com/v1')->getProviderId();"
```

Expected: `anthropic`

## Adding a New Provider

See `doc/provider-abstraction.md` for detailed instructions on adding new providers.

Quick steps:
1. Create new provider class extending `BaseProvider`
2. Implement required methods (normalize responses, handle streaming, etc.)
3. Register in `LlmProviderRegistry::initialize()`
4. Test with sample responses

## Architecture Benefits

- ✅ **Consistent Logging** - API responses are logged centrally in one place
- ✅ **Extensible** - Easy to add new providers
- ✅ **Clean Separation** - Provider logic isolated
- ✅ **Type Safety** - Interface ensures consistency
- ✅ **Testable** - Easy to unit test providers
- ✅ **Maintainable** - Clear structure and documentation

## Normalized Response Format

All providers return responses in this standard format:

```php
[
    'content' => string,              // Message content
    'role' => 'assistant',            // Message role
    'finish_reason' => 'stop',        // Completion reason
    'usage' => [
        'total_tokens' => int,
        'completion_tokens' => int,
        'prompt_tokens' => int
    ],
    'reasoning' => string|null,       // Optional reasoning (BFH only)
    'raw_response' => array           // Full original response
]
```

## Strict Logging Contract

`LlmService::callLlmApi()` runs in strict logging mode.
Every call must provide `log_options` with a valid `conversation_id`.
Assistant messages (content + payload + sent_context + raw_response + usage) are persisted centrally by `callLlmApi()`.

This means:
- Do not manually insert assistant API responses with `addMessage()` in callers.
- Always persist the user message before calling `callLlmApi()`.
- Use `sent_context` to store the exact context sent to the model.

## Database Changes

The `reasoning` field was added to `llmMessages` table:

```sql
ALTER TABLE llmMessages 
ADD COLUMN reasoning longtext DEFAULT NULL;
```

This field stores provider-specific reasoning content (currently used by BFH API).

## For More Information

See `doc/provider-abstraction.md` for comprehensive documentation including:
- Detailed architecture overview
- Provider interface documentation
- Adding custom providers
- Response normalization details
- Error handling
- Future extensions

