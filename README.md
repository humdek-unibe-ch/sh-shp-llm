# SelfHelp Plugin - LLM Chat

A comprehensive LLM integration plugin for SelfHelp CMS, enabling real-time AI chat functionality with OpenAI-compatible APIs.

## Features

### Core Features
- 🤖 **Real-time Chat Interface** - Interactive conversations with LLMs
- 🔄 **Streaming Responses** - Real-time SSE-based response streaming
- 📁 **File Uploads** - Image and document uploads for vision models
- 💬 **Conversation Management** - Create, view, delete conversations
- ⚡ **Rate Limiting** - Built-in protection (10 req/min, 3 concurrent conversations)
- 🛡️ **Admin Console** - Monitor all user conversations
- 🌐 **Multi-language UI** - Translatable interface labels
- 🔌 **Multi-Provider Support** - Works with GPUStack, BFH, OpenAI, and more

### Advanced Features
- 📋 **Conversation Context Module** - Configurable AI behavior per component
- 🎯 **Model-specific Settings** - Per-component model configuration
- 📊 **Token Tracking** - Usage monitoring and logging
- 🔍 **Context Debugging** - Track context sent with each message

## Quick Start

### Requirements
- SelfHelp v7.0+
- PHP 8.2+ with cURL extension
- MySQL 8.0+
- gpustack or OpenAI-compatible API

### Installation

```bash
# 1. Copy plugin to SelfHelp plugins directory
cp -r sh-shp-llm server/plugins/

# 2. Run database migration
mysql < server/plugins/sh-shp-llm/server/db/v1.0.0.sql

# 3. Install dependencies and build
cd server/plugins/sh-shp-llm/gulp
npm install
npm run build

# 4. Configure LLM settings in Admin → Modules → LLM Configuration
```

### Adding Chat to a Page

1. Create/edit a page in CMS
2. Add section with style `llmChat`
3. Configure model, streaming, and other settings
4. Save and test

## Configuration

### Global Settings (Admin → Modules → LLM Configuration)

| Setting | Description | Default |
|---------|-------------|---------|
| `llm_base_url` | API endpoint URL | `https://gpustack.unibe.ch/v1` |
| `llm_api_key` | Authentication token | - |
| `llm_default_model` | Default model | `qwen3-vl-8b-instruct` |
| `llm_timeout` | Request timeout (seconds) | `30` |
| `llm_max_tokens` | Max tokens per response | `2048` |
| `llm_temperature` | Response randomness (0-2) | `1` |
| `llm_streaming_enabled` | Enable streaming globally | `true` |

### Component Settings

| Setting | Description | Default |
|---------|-------------|---------|
| `llm_model` | Override global model | - |
| `conversation_limit` | Sidebar conversation count | `20` |
| `message_limit` | Messages per conversation | `100` |
| `enable_conversations_list` | Show conversation sidebar | `true` |
| `enable_file_uploads` | Enable file attachments | `true` |
| `conversation_context` | AI system instructions | - |

## Conversation Context Module

Define custom AI behavior for each chat component:

```markdown
You are an AI assistant helping users learn about anxiety disorders.

Guidelines:
- Be empathetic and supportive
- Use simple, clear language
- Break down complex concepts
- Encourage questions

This context will guide all AI responses in this chat component.
```

Supports both markdown/text and JSON array formats. See [doc/conversation-context.md](doc/conversation-context.md) for details.

## File Structure

```
sh-shp-llm/
├── doc/                    # Detailed documentation
├── gulp/                   # Build system
├── react/                  # React frontend (TypeScript)
├── server/
│   ├── component/          # MVC components
│   ├── service/            # Business logic services
│   └── db/                 # Database migrations
├── css/ext/                # Built CSS
└── js/ext/                 # Built JavaScript
```

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](doc/architecture.md) | System design and component interactions |
| [Provider Abstraction](doc/provider-abstraction.md) | Multi-provider API support system |
| [Provider Architecture Diagrams](doc/provider-architecture-diagram.md) | Visual architecture and flow diagrams |
| [Conversation Context](doc/conversation-context.md) | Context module configuration guide |
| [API Reference](doc/api-reference.md) | Controller actions and endpoints |
| [Configuration](doc/configuration.md) | Complete configuration reference |

## Development

### Building Assets

```bash
cd gulp

# Build everything
npm run build

# Watch for changes (development)
npm run watch
```

### React Development

```bash
cd react

# Install dependencies
npm install

# Development build
npm run dev

# Production build
npm run build
```

## Supported Providers & Models

The plugin uses a provider abstraction layer to support multiple LLM APIs seamlessly:

### Supported Providers

| Provider | Base URL | Features |
|----------|----------|----------|
| **GPUStack (UniBE)** | `https://gpustack.unibe.ch/v1` | Standard OpenAI-compatible |
| **BFH Inference API** | `https://inference.mlmp.ti.bfh.ch/api` | Enhanced with reasoning content |
| **OpenAI** | Coming soon | Full API support |
| **Anthropic** | Coming soon | Claude models |

The system automatically detects the correct provider based on your `llm_base_url` configuration. See [Provider Abstraction](doc/provider-abstraction.md) for details.

### Tested Models

- **Text Models**: qwen3-vl-8b-instruct, gpt-oss-120b, deepseek-r1-0528-qwen3-8b, beechat-v3-gpt-oss
- **Vision Models**: internvl3-8b-instruct, qwen3-vl-8b-instruct
- **Coding Models**: qwen3-coder-30b-a3b-instruct

## Security

- User-based conversation isolation
- Rate limiting protection
- Input sanitization and validation
- Secure file upload handling
- Admin audit trail
- Context stored server-side only

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Chat not loading | Check LLM configuration settings |
| API errors | Verify API endpoint and key |
| File uploads failing | Check upload directory permissions |
| Streaming not working | Ensure SSE support in browser |

See SelfHelp logs for detailed error information.

## Version History

### 1.0.0 (December 2024)
- Initial release with full LLM chat functionality
- Real-time streaming via SSE
- Multi-model support including vision models
- Conversation context module for configurable AI behavior
- Admin console for conversation monitoring
- React-based frontend with TypeScript
- Comprehensive file upload support

## License

Mozilla Public License, v. 2.0
