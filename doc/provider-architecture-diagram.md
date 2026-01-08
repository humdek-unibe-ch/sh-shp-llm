# LLM Provider Architecture - Visual Diagram

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     LLM Chat Component                          │
│                   (LlmChatController.php)                       │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            │ Uses
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                   LlmRequestService.php                         │
│  (Handles user requests, conversation management)               │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            │ Calls
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                      LlmService.php                             │
│  • Initializes provider based on llm_base_url                   │
│  • Manages conversations & messages                             │
│  • Calls provider methods for API communication                 │
└──────┬──────────────────────────────────────────────────────────┘
       │
       │ Uses
       ▼
┌─────────────────────────────────────────────────────────────────┐
│              LlmProviderRegistry (Factory)                      │
│  • Maintains list of all providers                              │
│  • Auto-detects correct provider from URL                       │
│  • Returns appropriate provider instance                        │
└──────┬──────────────────────────────────────────────────────────┘
       │
       │ Returns
       ▼
┌─────────────────────────────────────────────────────────────────┐
│                  LlmProviderInterface                           │
│  • normalizeResponse($rawResponse)                              │
│  • getApiUrl($baseUrl, $endpoint)                               │
│  • getAuthHeaders($apiKey)                                      │
│  • canHandle($baseUrl)                                          │
└──────┬──────────────────────────────────────────────────────────┘
       │
       │ Implemented by
       │
       ├───────────────────┬──────────────────┐
       ▼                   ▼                  ▼
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  GPUStack    │  │  BFH         │  │  Future      │
│  Provider    │  │  Provider    │  │  Providers   │
│              │  │              │  │  (OpenAI,    │
│ gpustack.    │  │ inference.   │  │  Anthropic,  │
│ unibe.ch     │  │ mlmp.ti.bfh  │  │  etc.)       │
└──────────────┘  └──────────────┘  └──────────────┘
```


```
User Request
    │
    ▼
Controller receives request
    │
    ▼
RequestService.callLlmApi()
    │
    ▼
LlmService.callLlmApi()
    │
    ├─→ Get provider from registry based on llm_base_url
    │
    ├─→ Build API URL using provider.getApiUrl()
    │
    ├─→ Get auth headers using provider.getAuthHeaders()
    │
    ├─→ Send HTTP request to API
    │
    ├─→ Receive raw response (provider-specific format)
    │
    ├─→ provider.normalizeResponse(rawResponse)
    │
    └─→ Return normalized response
         │
         ▼
Controller saves message with reasoning
    │
    ▼
Response sent to user
```


```
    │
    ▼
    │
    ▼
LlmService.callLlmResponse()
    │
    ├─→ Get provider from registry
    │
    ├─→ Build API URL using provider
    │
    ├─→ Send API request
    │
    ├─→ Receive complete response
    │       │
    │       ├─→ Parse response content
    │       ├─→ Extract token usage
    │       └─→ Validate response format
    │
         │
         └─→ Save complete message to database
```

## Provider Detection Logic

```
llm_base_url configured in CMS
         │
         ▼
LlmProviderRegistry.getProviderForUrl(baseUrl)
         │
         ├─→ For each registered provider:
         │       │
         │       ├─→ Call provider.canHandle(baseUrl)
         │       │
         │       └─→ If true: return this provider
         │
         └─→ No match: return default provider (GPUStack)
```

## Response Normalization Flow

```
┌──────────────────────────────────────────────────────────────┐
│              Raw API Response (Provider-Specific)            │
└──────────────────────┬───────────────────────────────────────┘
                       │
                       ▼
         ┌─────────────────────────┐
         │ Provider.normalizeResponse() │
         └─────────────┬───────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────┐
│                 Normalized Response                          │
│  {                                                           │
│    content: "AI response text",                             │
│    role: "assistant",                                       │
│    finish_reason: "stop",                                   │
│    usage: {                                                 │
│      total_tokens: 268,                                     │
│      completion_tokens: 53,                                 │
│      prompt_tokens: 215                                     │
│    },                                                       │
│    reasoning: "AI's thinking process" (optional),           │
│    raw_response: {...original response...}                  │
│  }                                                          │
└──────────────────────────────────────────────────────────────┘
```

## Database Schema

```
┌──────────────────────────────────────────────────────────────┐
│                    llmConversations                          │
├──────────────────────────────────────────────────────────────┤
│ id                  INT (PK)                                 │
│ id_users            INT (FK → users)                         │
│ id_sections         INT (FK → sections)                      │
│ title               VARCHAR                                  │
│ model               VARCHAR                                  │
│ temperature         DECIMAL                                  │
│ max_tokens          INT                                      │
│ created_at          TIMESTAMP                                │
│ updated_at          TIMESTAMP                                │
└──────────────────────────────────────────────────────────────┘
                            │
                            │ 1:N
                            ▼
┌──────────────────────────────────────────────────────────────┐
│                      llmMessages                             │
├──────────────────────────────────────────────────────────────┤
│ id                  INT (PK)                                 │
│ id_llmConversations INT (FK)                                 │
│ role                ENUM(user, assistant, system)            │
│ content             LONGTEXT                                 │
│ attachments         LONGTEXT (JSON)                          │
│ model               VARCHAR                                  │
│ tokens_used         INT                                      │
│ raw_response        LONGTEXT (JSON)                          │
│ sent_context        LONGTEXT (JSON)                          │
│ reasoning           LONGTEXT ← NEW FIELD                     │
│ id_dataRows         INT (FK)                                 │
│ timestamp           TIMESTAMP                                │
└──────────────────────────────────────────────────────────────┘
```

## Provider Class Hierarchy

```
LlmProviderInterface (Interface)
        │
        │ implements
        ▼
BaseProvider (Abstract Class)
        │
        │ extends
        │
        ├────────────────────┬─────────────────┐
        ▼                    ▼                 ▼
GpuStackProvider    BfhProvider      FutureProvider
  (Concrete)         (Concrete)        (Concrete)
```

## Key Design Patterns Used

1. **Interface Segregation** - Clean contract via LlmProviderInterface
2. **Factory Pattern** - LlmProviderRegistry creates providers
3. **Strategy Pattern** - Different providers = different strategies
4. **Template Method** - BaseProvider provides common functionality
5. **Registry Pattern** - Central provider registry
6. **Dependency Injection** - Provider injected into services

## Benefits Visualization

```
┌─────────────────────────────────────────────────────────────┐
│                    Before                                   │
├─────────────────────────────────────────────────────────────┤
│  LlmService ──┐                                            │
│               ├─→ if (gpustack) { ... }                    │
│               ├─→ if (bfh) { ... }                         │
│               └─→ if (openai) { ... }                      │
│                                                             │
│  Problems:                                                  │
│  ❌ Mixed concerns                                         │
│  ❌ Hard to test                                           │
│  ❌ Difficult to extend                                    │
│  ❌ Tight coupling                                         │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    After                                    │
├─────────────────────────────────────────────────────────────┤
│  LlmService ──→ Provider ──┐                               │
│                            ├─→ GpuStackProvider            │
│                            ├─→ BfhProvider                 │
│                            └─→ Future providers            │
│                                                             │
│  Benefits:                                                  │
│  ✅ Separated concerns                                     │
│  ✅ Easy to test                                           │
│  ✅ Extensible                                             │
│  ✅ Loose coupling                                         │
└─────────────────────────────────────────────────────────────┘
```

## Adding a New Provider - Flow

```
1. Create Provider Class
   └─→ Extend BaseProvider
       └─→ Implement required methods

2. Register Provider
   └─→ Add to LlmProviderRegistry::initialize()

3. Test
   └─→ Run test_providers.php
       └─→ Verify all tests pass

4. Deploy
   └─→ System auto-detects new provider
       └─→ No configuration changes needed
```

