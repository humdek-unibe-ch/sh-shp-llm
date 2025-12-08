# Changelog - LLM Plugin

## [1.0.0] - 2024-12-05

### Added
- Complete LLM chat plugin implementation for SelfHelp CMS
- Real-time streaming responses via Server-Sent Events (SSE)
- Support for multiple LLM models including vision-capable models
- File upload functionality for images (vision models)
- Conversation management (create, view, delete conversations)
- Rate limiting system (10 requests/minute, 3 concurrent conversations)
- Admin interface for monitoring all user conversations
- Global configuration page following SelfHelp pageType patterns
- Custom database tables: `llmConversations`, `llmMessages`
- OpenAI-compatible API integration
- React-based frontend with TypeScript support
- MVC architecture following SelfHelp component patterns
- Multi-language UI support with translatable labels
- Secure file upload system with validation
- Transaction logging integration
- Plugin hooks for dynamic model selection
- Admin components with custom routing (non-standard location)

### Technical Implementation
- **Streaming Architecture**: Event-driven SSE implementation with zero partial saves
- **Database Design**: Optimized schema with proper foreign keys and indexing
- **Security**: Input validation, rate limiting, and secure file handling
- **API Integration**: Flexible OpenAI-compatible API client
- **File Management**: Secure upload directory with MIME type validation
- **Caching**: APCu integration for performance optimization
- **Error Handling**: Comprehensive error recovery and logging
- **Build System**: Gulp-based asset compilation with DEBUG/production modes
- **Asset Management**: Git version cache-busting and minification

### Files Created
- Database schema (`server/db/v1.0.0.sql`)
- Core services (`LlmService.php`, `LlmStreamingService.php`, `LlmApiFormatterService.php`)
- MVC components (`LlmchatModel.php`, `LlmchatView.php`, `LlmchatController.php`)
- Plugin hooks (`LlmHooks.php`)
- Admin components (`llmConversations/`, `llmConversation/`)
- React frontend with TypeScript (`react/` directory)
- Build system (`gulp/` directory)
- Configuration and constants (`globals.php`)
- CSS and JavaScript assets

### Known Limitations
- Conversation context module planned but not yet implemented
- Advanced UI features (smart auto-scroll, enhanced markdown) planned for future versions
