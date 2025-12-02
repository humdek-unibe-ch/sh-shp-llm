# Changelog - LLM Plugin

## [1.0.0] - 2025-12-02 (Not released yet)

### Added
- Complete LLM chat plugin implementation
- Support for all gpustack models (qwen3-vl-8b-instruct, apertus-8b-instruct-2509, etc.)
- Real-time streaming responses via Server-Sent Events
- File uploads for vision models (internvl3-8b-instruct)
- Conversation management (create, view, delete)
- Rate limiting (10 requests/minute, 3 concurrent conversations)
- Admin interface for conversation monitoring
- Configuration page following SelfHelp pageType pattern
- Custom database tables for optimal performance
- OpenAI-compatible API integration

### Technical Features
- MVC architecture following SelfHelp patterns
- Custom tables: `llmConversations`, `llmMessages` (camelCase)
- SSE streaming for real-time responses
- Secure file upload system in plugin directory
- Transaction logging integration
- Admin components inside plugin directory with custom routing (non-standard location)
- Rate limiting (10 req/min, 3 concurrent conversations)
- Custom hook-based admin routing bypassing standard SelfHelp component loading
- Plugin-scoped admin components for conversations management with ACL integration
- Template-based views for all components (no direct HTML output in PHP)
- Standardized asset loading with DEBUG/production mode switching and git version cache-busting
- Improved configuration retrieval using SelfHelp's get_page_fields stored procedure
- Responsive UI with Bootstrap styling
- JavaScript ES6 classes for client-side functionality

### Files Created
- Database schema and migrations
- LlmService for API and database operations
- LLM chat component (MVC: Model, View, Controller)
- LlmHooks for plugin integration
- Admin interface for conversation management
- Complete CSS and JavaScript assets
- Configuration system using pageType fields
- Build system with gulp
