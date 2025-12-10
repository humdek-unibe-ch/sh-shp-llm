# Changelog - LLM Plugin

## [1.0.0] - 2024-12-09

### Added
- Complete LLM chat plugin implementation for SelfHelp CMS
- Real-time streaming responses via Server-Sent Events (SSE)
- Support for multiple LLM models including vision-capable models
- File upload functionality for images (vision models)
- Conversation management (create, view, delete conversations)
- Rate limiting system (10 requests/minute, 3 concurrent conversations)
- Comprehensive Admin Console for browsing all user conversations and messages
- LLM panel with quick admin links in configuration page
- Plugin hooks for admin panel display (edit and view modes)
- Dedicated admin page type (`sh_llm_admin`) and admin console style
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
- **Component Configuration Enhancements**: Added granular control over chat interface features
  - `enable_conversations_list`: Show/hide conversations sidebar (default: enabled)
  - `enable_file_uploads`: Enable/disable file upload capability (default: enabled)
  - `enable_full_page_reload`: Use AJAX page reload instead of React refresh for post-chat updates
- **Improved Temperature Configuration**: Changed temperature field type from number to text for better flexibility, updated default from 0.7 to 1.0 with enhanced descriptions
- **Legacy Conversation Management**: Added `getOrCreateConversationForModel()` method for model-specific conversation handling
- **Enhanced Markdown Rendering**: Improved code block copy functionality and JSON key color highlighting
- **UI/UX Improvements**: Refined chat interface styling and user experience

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
- **Component Architecture**: Admin console restructured as proper component (not style)

### Files Created
- Database schema (`server/db/v1.0.0.sql`)
- Core services (`LlmService.php`, `LlmStreamingService.php`, `LlmApiFormatterService.php`, `LlmFileUploadService.php`)
- MVC components (`LlmchatModel.php`, `LlmchatView.php`, `LlmchatController.php`)
- Plugin hooks (`LlmHooks.php`)
- Admin components (`moduleLlmAdminConsole/` - comprehensive admin console component with configurable pageType_fields)
- React frontend with TypeScript (`react/` directory)
- Build system (`gulp/` directory)
- Configuration and constants (`globals.php`)
- CSS and JavaScript assets

### Changed
- **Temperature Settings**: Default temperature changed from 0.7 to 1.0 with improved parameter descriptions
- **Field Types**: Changed `llm_temperature` fields from number to text type for better validation
- **Parameter Descriptions**: Enhanced descriptions for max_tokens and temperature parameters to provide clearer guidance

### Fixed
- **Code Copy Functionality**: Fixed code block text extraction for proper copy-to-clipboard behavior
- **JSON Syntax Highlighting**: Improved color highlighting for JSON keys in code blocks
- **File Upload Handling**: Fixed upload path issues and API formatting, ensured files require accompanying messages
- **Vision Model Detection**: Fixed vision model detection and upload path handling
- **Conversation URL Behavior**: Corrected URL handling when conversations list is disabled
- **Streaming Response Display**: Fixed display issues with streaming responses and parameter validation
- **Textarea Focus Styling**: Removed blue border on textarea focus for cleaner appearance
- **Conversation Selection**: Fixed conversation selection behavior during creation

### Technical Improvements
- **Database Schema**: Updated field types and default values for better configuration flexibility
- **API Integration**: Enhanced model selection and parameter validation
- **Build System**: Improved asset compilation and React component integration
- **Error Handling**: Better error recovery and user feedback

### Known Limitations
- Conversation context module planned but not yet implemented
- Advanced UI features (smart auto-scroll, enhanced markdown) planned for future versions
