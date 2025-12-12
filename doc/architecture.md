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
│   └── streaming.md               # Streaming implementation
├── gulp/                          # Build system
│   ├── gulpfile.js
│   └── package.json
├── react/                         # React frontend
│   ├── src/
│   │   ├── LlmChat.tsx           # Entry point
│   │   ├── types/                # TypeScript definitions
│   │   ├── components/           # React components
│   │   ├── hooks/                # Custom hooks
│   │   └── utils/                # Utilities
│   └── vite.config.ts
├── server/
│   ├── component/                 # MVC components
│   │   ├── LlmHooks.php          # Plugin hooks
│   │   ├── moduleLlmAdminConsole/ # Admin console
│   │   └── style/llmchat/        # Chat component
│   ├── service/                   # Business logic
│   │   ├── globals.php           # Constants
│   │   ├── LlmService.php        # Core service
│   │   ├── LlmStreamingService.php
│   │   ├── LlmApiFormatterService.php
│   │   └── LlmFileUploadService.php
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
│  │ LlmchatModel │ │LlmchatContrl │ │ LlmchatView  │    │
│  │ - Config     │ │ - Requests   │ │ - Templates  │    │
│  │ - Data       │ │ - API calls  │ │ - React init │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│                    Services                              │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐    │
│  │ LlmService   │ │ Streaming    │ │ ApiFormatter │    │
│  │ - DB ops     │ │ - SSE        │ │ - Messages   │    │
│  │ - API calls  │ │ - Buffering  │ │ - Multimodal │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
└─────────────────────────────────────────────────────────┘
```

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
│  │ useChatState │ │ useStreaming │ │ useSmartScrl │    │
│  │ - Convs      │ │ - SSE        │ │ - Auto scroll│    │
│  │ - Messages   │ │ - Chunks     │ │              │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
│                                                         │
│  ┌────────────────────────────────────────────────┐    │
│  │              Sub-components                     │    │
│  │  ConversationSidebar | MessageList | Input     │    │
│  └────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────┘
```

## Data Flow

### Message Submission Flow

```
User Input
    │
    ▼
React Component (MessageInput)
    │
    ├─[Non-Streaming]─► messagesApi.send()
    │                        │
    │                        ▼
    │                   LlmchatController::handleMessageSubmission()
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
    └─[Streaming]──────► messagesApi.prepareStreaming()
                              │
                              ▼
                         LlmchatController (prepare_streaming)
                              │
                              ▼
                         LlmService::addMessage() (user)
                              │
                              ▼
                         Return conversation_id
                              │
                              ▼
                         StreamingApi.connect() (SSE)
                              │
                              ▼
                         LlmchatController::handleStreamingRequest()
                              │
                              ▼
                         LlmStreamingService::startStreamingResponse()
                              │
                              ▼
                         LlmService::streamLlmResponse()
                              │
                              ▼
                         SSE Events → React UI
```

## Database Schema

### Tables

| Table | Purpose |
|-------|---------|
| `llmConversations` | Stores conversation metadata |
| `llmMessages` | Stores individual messages |
| `styles_fields` (llmChat) | Component configuration |
| `pages_fields` (sh_module_llm) | Global LLM configuration |

### Key Relationships

```
users (SelfHelp Core)
    │
    └── llmConversations (1:many)
            │
            └── llmMessages (1:many)
                    │
                    └── File attachments (optional)
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

### Streaming Optimization

- Zero database writes during streaming
- Memory buffering with atomic commit
- SSE with disabled nginx buffering
- 2ms delay between chunks for smooth rendering

### React Optimization

- Memoized callbacks with useCallback
- Smart scroll to prevent unnecessary renders
- Lazy loading via API instead of embedded data

