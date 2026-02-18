# LLM Chat Plugin Architecture

## Overview

The LLM Chat plugin follows SelfHelp's MVC architecture pattern with a React-based frontend. This document describes the technical architecture and component interactions.

## Directory Structure

```
server/plugins/sh-shp-llm/
├── README.md                      # Main documentation
├── CHANGELOG.md                   # Version history
├── doc/                           # Detailed documentation
│   ├── architecture.md            # This file
│   ├── api-reference.md           # API documentation
│   ├── configuration.md           # Configuration guide
│   ├── conversation-context.md    # Context module documentation
│   ├── provider-abstraction.md    # Provider system guide
│   └── provider-architecture-diagram.md # Visual diagrams
├── gulp/                          # Build system
│   ├── gulpfile.js
│   └── package.json
├── react/                         # React frontend
│   ├── src/
│   │   ├── LlmChat.tsx           # Chat entry point
│   │   ├── admin.tsx             # Admin console entry point
│   │   ├── scripts.tsx           # LLM Scripts entry point
│   │   ├── types/                # TypeScript definitions
│   │   ├── components/           # React components
│   │   │   ├── scripts/          # LLM Scripts manager
│   │   │   │   ├── ScriptsManager.tsx
│   │   │   │   ├── ScriptsManager.css
│   │   │   │   └── scriptsApi.ts
│   │   │   └── ...               # Chat and admin components
│   │   ├── hooks/                # Custom hooks
│   │   └── utils/                # Utilities
│   ├── vite.config.ts            # Chat build config
│   ├── vite.admin.config.ts      # Admin console build config
│   └── vite.scripts.config.ts    # LLM Scripts build config
├── server/
│   ├── ajax/                      # AJAX endpoints
│   │   └── AjaxLlmScripts.php    # LLM Scripts CRUD API
│   ├── component/                 # MVC components
│   │   ├── LlmHooks.php          # Plugin hooks
│   │   ├── moduleLlmAdminConsole/ # Admin console
│   │   ├── moduleLlmScript/       # LLM Scripts (React wrapper)
│   │   ├── moduleLlmScriptMode/   # LLM Scripts mode routing
│   │   └── style/                 # Style components
│   │       ├── llmchat/           # Chat component
│   │       └── llmResponse/       # LLM Response display style
│   ├── service/                   # Business logic
│   │   ├── globals.php           # Constants
│   │   ├── LlmService.php        # Core LLM service
│   │   ├── LlmScriptService.php  # LLM Scripts management & execution
│   │   ├── LlmApiFormatterService.php
│   │   ├── LlmFileUploadService.php
│   │   └── provider/             # Provider abstraction
│   │       ├── LlmProviderInterface.php
│   │       ├── BaseProvider.php
│   │       ├── GpuStackProvider.php
│   │       ├── BfhProvider.php
│   │       └── LlmProviderRegistry.php
│   └── db/
│       └── v1.0.0.sql            # Database schema
├── css/ext/                       # Built CSS
├── js/ext/                        # Built JS
└── upload/                        # File uploads
```

## Component Architecture

### Backend (PHP)

```
┌─────────────────────────────────────────────────────────┐
│                    LlmHooks.php                         │
│  - Plugin registration                                  │
│  - Hook handlers for custom field types                 │
│  - Admin component routing                              │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    Style Component                       │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐    │
│  │ LlmChatModel │ │LlmChatContrl │ │ LlmChatView  │    │
│  │ - Config     │ │ - Requests   │ │ - Templates  │    │
│  │ - Data       │ │ - API calls  │ │ - React init │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    Services                              │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐    │
│  │ - DB ops     │ │ - API calls  │ │ - Messages   │    │
│  │ - Validation │ │ - Response   │ │ - Multimodal │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
│                           │                             │
│                           ▼                             │
│  ┌─────────────────────────────────────────────────┐   │
│  │          Provider Abstraction Layer             │   │
│  │  ┌─────────────┐  ┌─────────────┐             │   │
│  │  │ GPUStack    │  │ BFH API     │  + More     │   │
│  │  │ Provider    │  │ Provider    │  providers  │   │
│  │  └─────────────┘  └─────────────┘             │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### LLM Scripts Module

```
┌─────────────────────────────────────────────────────────┐
│           React UI (ScriptsManager.tsx)                   │
│  - List / Create / Edit / Delete / Test scripts          │
│  - Monaco Editor for prompt templates                    │
│  - Config: async, model, temperature, max_tokens, etc.   │
└──────────────────────────┬──────────────────────────────┘
                           │ AJAX (FormData POST)
                           ▼
┌─────────────────────────────────────────────────────────┐
│           AjaxLlmScripts.php (Plugin AJAX)               │
│  - dispatch(): routes list/get/create/update/delete/test │
└──────────────────────────┬──────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│           LlmScriptService.php                           │
│  - CRUD for llm_scripts table                            │
│  - execute_llm_script() → LlmService::callLlmApi()      │
│  - log_execution() → UserInput::save_data()              │
│  - insert_refresh_event() → core refresh_events table    │
└─────────────────────────────────────────────────────────┘
```

### Refresh Events (Core Integration)

The refresh events mechanism is a **core SelfHelp feature** (v7.8.0), not a plugin
feature. The LLM plugin generates events; core handles polling and page refresh.

```
┌─────────────┐  async script   ┌──────────────────┐
│ LLM Script  │  completes      │ LlmScriptService │
│ Execution   │ ───────────────→│ insert_refresh_  │
│ (cron job)  │                 │ event()          │
└─────────────┘                 └────────┬─────────┘
                                         │ INSERT INTO
                                         ▼
                              ┌──────────────────────┐
                              │ refresh_events (core) │
                              │ refresh_events_       │
                              │ sections (core)       │
                              └──────────┬───────────┘
                                         │ polled by
                                         ▼
┌──────────────────┐          ┌──────────────────────┐
│ event-listener.js│ ◀────── │ AjaxRefreshEvents    │
│ (core JS)        │  JSON    │ ::check() (core PHP) │
│ polls every Ns   │          └──────────────────────┘
└────────┬─────────┘
         │ silent AJAX refresh
         ▼
┌──────────────────┐
│ Page sections    │
│ #section-{id}    │
│ updated in DOM   │
└──────────────────┘
```

**Configuration**: Enable via CMS page fields `enable_event_listener` (checkbox)
and `event_listener_interval` (seconds). Available on page types: core (2),
experiment (3), emails (7). Core `BasePage::output_event_listener()` handles
injection automatically — no plugin hook required.

### Frontend (React)

```
┌─────────────────────────────────────────────────────────┐
│                    LlmChat.tsx                          │
│                  (Entry Point)                          │
│  - Config loading (API or data attributes)              │
│  - React app initialization                             │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    LlmChat Component                     │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐    │
│  │ - Convs      │ │ - Responses  │ │ - Auto scroll│    │
│  │ - Messages   │ │ - Validation │ │              │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
│                                                         │
│  ┌────────────────────────────────────────────────┐    │
│  │              Sub-components                     │    │
│  │  ConversationSidebar | MessageList | Input     │    │
│  │  FormRenderer (Form Mode)                      │    │
│  └────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────┘
```

### Form Mode Architecture

When Interactive Form Mode is enabled:

```
┌─────────────────────────────────────────────────────────┐
│              Form Mode Data Flow                         │
│                                                         │
│  LLM Response (JSON Schema)                             │
│       │                                                 │
│       ▼                                                 │
│  parseFormDefinition() - types/index.ts                 │
│       │                                                 │
│       ▼                                                 │
│  FormRenderer.tsx - Renders Bootstrap 4.6 form         │
│       │                                                 │
│       ▼                                                 │
│  User Selection → formatFormSelectionsAsText()         │
│       │                                                 │
│       ▼                                                 │
│  formApi.submit() → LlmChatController                   │
│       │                                                 │
│       ▼                                                 │
│  Readable text sent to LLM → Next form response        │
└─────────────────────────────────────────────────────────┘
```

## Provider Abstraction System

### Overview

The plugin uses a provider abstraction layer to support multiple LLM APIs seamlessly. This enables the system to work with different API providers (GPUStack, BFH, OpenAI, etc.) while maintaining a consistent internal interface.

### Architecture

```
┌──────────────────────────────────────────────────────────┐
│                   LlmService                              │
│  - Manages conversations and messages                     │
│  - Coordinates with provider for API communication        │
└──────────────────┬───────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────┐
│            LlmProviderRegistry (Factory)                  │
│  - Auto-detects provider from llm_base_url                │
│  - Returns appropriate provider instance                  │
└──────────────────┬───────────────────────────────────────┘
                   │
                   ▼
┌──────────────────────────────────────────────────────────┐
│              LlmProviderInterface                         │
│  ┌─────────────────────────────────────────────────┐     │
│  │ • normalizeResponse(rawResponse)                │     │
│  │ • getApiUrl(baseUrl, endpoint)                 │     │
│  │ • getAuthHeaders(apiKey)                       │     │
│  │ • canHandle(baseUrl)                           │     │
│  └─────────────────────────────────────────────────┘     │
└──────────────────┬───────────────────────────────────────┘
                   │
        ┌──────────┴──────────┬─────────────┐
        ▼                     ▼             ▼
  ┌────────────┐      ┌────────────┐  ┌────────────┐
  │ GPUStack   │      │ BFH        │  │ Future     │
  │ Provider   │      │ Provider   │  │ Providers  │
  └────────────┘      └────────────┘  └────────────┘
```

### Automatic Provider Detection

The system automatically selects the correct provider based on the `llm_base_url`:

| Base URL | Provider | Features |
|----------|----------|----------|
| `https://gpustack.unibe.ch/v1` | GPUStack | Standard OpenAI-compatible |
| `https://inference.mlmp.ti.bfh.ch/api/v1` | BFH | Enhanced with reasoning content |

### Response Normalization

All providers normalize their responses to a standard format:

```php
[
    'content' => string,           // Message content
    'role' => 'assistant',         // Message role
    'finish_reason' => 'stop',     // Completion reason
    'usage' => [
        'total_tokens' => int,
        'completion_tokens' => int,
        'prompt_tokens' => int
    ],
    'reasoning' => string|null,    // Optional reasoning (BFH)
    'raw_response' => array        // Full original response
]
```

### Database Storage

The `reasoning` field in `llmMessages` table stores provider-specific reasoning content (optional, currently used by BFH API).

**For more details**, see:
- [Provider Abstraction Guide](provider-abstraction.md)
- [Provider Architecture Diagrams](provider-architecture-diagram.md)

## Data Flow

### Message Submission Flow

```
User Input (Text or Form)
    │
    ▼
React Component (MessageInput or FormRenderer)
    │
    ├─[Form Mode]─────► formApi.submit()
    │                        │
    │                        ▼
    │                   formatFormSelectionsAsText()
    │                        │
    │                        ▼
    │                   LlmChatController::handleFormSubmission()
    │                        │
    │                        ▼
    │                   LlmService::addMessage() (user - readable text)
    │                        │
    │                        ▼
    │                   buildFormModeContext() (inject form schema)
    │                        │
    │                        ▼
    │                   LLM API → JSON Form Response
    │
    │                        │
    │                        ▼
    │                   LlmChatController::handleMessageSubmission()
    │                        │
    │                        ▼
    │                   LlmService::addMessage() (user)
    │                        │
    │                        ▼
    │                   LlmApiFormatterService::convertToApiFormat()
    │                        │ (with context prepended)
    │                        ▼
    │                   LlmService::callLlmApi()
    │                        │
    │                        ▼
    │                   LlmService::addMessage() (assistant)
    │                        │
    │                        ▼
    │                   JSON Response
    │
                              │
                              ▼
                              │
                              ▼
                         LlmService::addMessage() (user)
                              │
                              ▼
                         Return conversation_id
                              │
                              ▼
                              │
                              ▼
                              │
                              ▼
                              │
                              ▼
                         LlmService::callLlmResponse()
                              │
                              ▼
                         API Response → React UI
```

## Multi-Section Support

### Overview

Multiple llmChat sections can exist on the same page, each with independent:
- Conversations (filtered by `id_sections`)
- Model configurations
- Context settings
- Floating button positions

### Section Isolation

Each llmChat section is identified by its `section_id`. When:
- Loading conversations: Only conversations with matching `id_sections` are returned
- Creating conversations: The `section_id` is stored with the conversation
- Loading messages: Section ownership is verified before returning data

### Implementation

```php
// In LlmService::getUserConversations()
$sql .= " AND id_sections = :section_id";

// In LlmService::getConversation()
$sql .= " AND id_sections = ?";
```

### Use Cases

1. **Different AI assistants**: Support chat + FAQ bot on same page
2. **A/B testing**: Compare different models or contexts
3. **Role-based chat**: Different assistants for different user roles

## Floating Chat Button

### Architecture

When `enable_floating_button` is enabled:

```
┌─────────────────────────────────────────────────────────┐
│                    LlmChatLoader                         │
│                                                         │
│  if (config.enableFloatingButton) {                     │
│    return <FloatingChat config={config} />;             │
│  } else {                                               │
│    return <LlmChat config={config} />;                  │
│  }                                                      │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    FloatingChat.tsx                      │
│  ┌──────────────┐                                       │
│  │ Floating Btn │ ◄─── Position from config             │
│  │ (Bootstrap)  │                                       │
│  └──────────────┘                                       │
│         │                                               │
│         ▼ onClick                                       │
│  ┌──────────────┐                                       │
│  │ Modal        │                                       │
│  │ ┌──────────┐ │                                       │
│  │ │ LlmChat  │ │ ◄─── Full chat interface in modal    │
│  │ └──────────┘ │                                       │
│  └──────────────┘                                       │
└─────────────────────────────────────────────────────────┘
```

### Position Configuration

The floating button position is stored as a select field with these options:
- `bottom-right` (default)
- `bottom-left`
- `top-right`
- `top-left`
- `bottom-center`
- `top-center`

A custom hook (`LlmHooks::outputFieldFloatingPositionEdit`) renders the select dropdown in the CMS.

## Database Schema

### Tables

| Table | Purpose |
|-------|---------|
| `llmConversations` | Stores conversation metadata (includes `id_sections`) |
| `llmMessages` | Stores individual messages (includes `id_dataRows` for form data linking) |
| `styles_fields` (llmChat) | Component configuration |
| `pages_fields` (sh_module_llm) | Global LLM configuration |
| `dataTables` | Form data tables (when data saving enabled) |
| `dataRows` | Form submission records |
| `dataCells` | Individual form field values |

### Key Relationships

```
users (SelfHelp Core)
    │
    └── llmConversations (1:many)
            │
            ├── id_sections → sections (llmChat component)
            │
            └── llmMessages (1:many)
                    │
                    ├── File attachments (optional)
                    │
                    └── id_dataRows → dataRows (form submissions)
```

## Security

### Authentication & Authorization

- All API calls require authenticated session
- User can only access their own conversations
- Admin ACL required for admin console access

### Data Validation

- Input sanitization on all user inputs
- File type validation for uploads
- Rate limiting (10 req/min, 3 concurrent conversations)

### Context Security

- Conversation context is stored in database, not exposed to frontend
- Context snapshots tracked with messages for audit
- System messages not visible to end users

## Performance Considerations

### Caching

- User conversations cached for 5 minutes
- Conversation messages cached for 5 minutes
- Rate limit data cached for 1 minute
- LLM config cached in static variable


- Response validation with schema enforcement
- JSON schema compliance checking
- Automatic retry logic for invalid responses

### React Optimization

- Memoized callbacks with useCallback
- Smart scroll to prevent unnecessary renders
- Lazy loading via API instead of embedded data

