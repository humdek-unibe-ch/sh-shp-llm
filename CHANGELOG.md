# Changelog - LLM Plugin

## [1.0.0] - 2024-12-12

### Added

#### Core Features
- Complete LLM chat plugin implementation for SelfHelp CMS
- Real-time streaming responses via Server-Sent Events (SSE)
- Support for multiple LLM models including vision-capable models
- File upload functionality for images and documents
- Conversation management (create, view, delete conversations)
- Rate limiting system (10 requests/minute, 3 concurrent conversations)
- Comprehensive Admin Console for browsing all user conversations and messages
- OpenAI-compatible API integration
- React-based frontend with TypeScript support
- MVC architecture following SelfHelp component patterns
- Multi-language UI support with translatable labels
- Secure file upload system with validation

#### Conversation Context Module (New)
- **Configurable AI Behavior**: Define system instructions per chat component via CMS field
- **Multi-format Support**: Supports both markdown/free text and JSON array formats
- **Context Tracking**: Each message records the context sent for debugging/audit
- **Parsing Methods**: `getConversationContext()`, `getParsedConversationContext()`, `hasConversationContext()`
- **API Integration**: Context prepended to API messages via `LlmApiFormatterService`
- **Database Tracking**: New `sent_context` column in `llmMessages` table
- **Strict Conversation Mode**: Optional topic enforcement that keeps AI focused on defined subjects
- **Auto-Start Conversations**: Automatic conversation initiation with context-aware opening messages

#### API Improvements
- **Config API Endpoint**: New `?action=get_config` for React component initialization
- **API-First Architecture**: React components can now fetch config via API instead of data attributes
- **Backwards Compatible**: Supports both API-based and data attribute configuration

#### Component Configuration
- `conversation_context`: System instructions sent to AI (markdown field)
- `enable_conversations_list`: Show/hide conversations sidebar
- `enable_file_uploads`: Enable/disable file upload capability
- `enable_full_page_reload`: Use AJAX page reload instead of React refresh
- `strict_conversation_mode`: Enable topic enforcement for focused conversations (checkbox)
- `auto_start_conversation`: Automatically start conversations (checkbox)
- `auto_start_message`: Fallback message for auto-start conversations (markdown)

#### Admin Features
- Comprehensive Admin Console for monitoring all user conversations
- LLM panel with quick admin links in configuration page
- Date filtering support in admin console
- User and section filtering

#### Documentation
- Restructured documentation into `doc/` folder
- [Architecture Guide](doc/architecture.md) - System design and component interactions
- [Conversation Context Guide](doc/conversation-context.md) - Context module configuration
- [API Reference](doc/api-reference.md) - Controller actions and endpoints
- [Configuration Guide](doc/configuration.md) - Complete configuration reference
- Streamlined README with quick start and links to detailed docs

### Technical Implementation

#### Conversation Context Processing
- `LlmchatModel`: New `conversation_context` property with JSON/text parsing
- `LlmApiFormatterService`: Updated `convertToApiFormat()` accepts context parameter
- `LlmStreamingService`: Updated to track and store sent context
- `LlmService`: Updated `addMessage()` accepts `$sent_context` parameter
- `LlmchatController`: Context processing in both streaming and non-streaming paths

#### Strict Conversation Mode Implementation
- `StrictConversationService`: New service for topic enforcement and context enhancement
- Context prepended with enforcement instructions for topic boundaries
- Intelligent topic extraction from configured context
- Polite redirection for off-topic questions

#### Auto-Start Conversation Implementation
- Context-aware message generation based on configured conversation topics
- Session-based tracking to prevent duplicate auto-start messages
- New `get_auto_started` API endpoint for frontend detection
- Smart fallback to configured default messages

#### Database Changes
- New `conversation_context` field in `styles_fields` for llmChat style
- New `sent_context` column in `llmMessages` table for context tracking
- New `strict_conversation_mode` checkbox field for topic enforcement
- New `auto_start_conversation` checkbox field for automatic conversation initiation
- New `auto_start_message` markdown field for custom auto-start messages

#### Architecture
- **Streaming Architecture**: Event-driven SSE implementation with zero partial saves
- **Database Design**: Optimized schema with proper foreign keys and indexing
- **Security**: Input validation, rate limiting, and secure file handling
- **API Integration**: Flexible OpenAI-compatible API client
- **File Management**: Secure upload directory with MIME type validation
- **Caching**: APCu integration for performance optimization
- **Error Handling**: Comprehensive error recovery and logging
- **Build System**: Gulp-based asset compilation with DEBUG/production modes
- **React Architecture**: API-first config loading with data attribute fallback

### Files Created
- Database schema (`server/db/v1.0.0.sql`)
- Core services (`LlmService.php`, `LlmStreamingService.php`, `LlmApiFormatterService.php`, `LlmFileUploadService.php`, `StrictConversationService.php`)
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

#### Admin Console UI Improvements (December 10, 2025)
- **Complete UI Redesign**: Professional Bootstrap 4.6-based admin console with modern card layout
- **Date Filtering**: Added date range filter (From/To dates) with default to current date
- **Enhanced Filters Panel**: Reorganized with visual hierarchy, clear separators, and "Clear all" functionality
- **Improved Conversations List**: Better cards with hover effects, date badges, message counts, and user information
- **Chat-Style Messages Panel**: Modern chat bubble design with directional tails and role indicators
- **Advanced Markdown Rendering**: Integrated MarkdownRenderer with syntax highlighting and copy-to-clipboard
- **Performance Enhancements**: Smooth 60fps animations, custom scrollbars, and efficient React re-rendering
- **Enhanced Pagination**: First/Last buttons, current page indicators, and disabled state handling
- **Header & Stats**: Prominent heading with total/filtered counts and refresh functionality
- **Dark Mode Support**: CSS media query support for `prefers-color-scheme: dark`
- **Responsive Design**: Mobile, tablet, and desktop compatibility
- **Backend Date Filtering**: Efficient SQL queries using DATE() function for conversation creation date filtering

### Technical Implementation Details
- **New Components**: `react/src/components/shared/MarkdownRenderer.tsx`, comprehensive admin CSS (600+ lines)
- **Modified Components**: Complete AdminConsole.tsx redesign, MessageRow integration with MarkdownRenderer
- **Backend Changes**: Date filter parameters in controller, SQL WHERE clauses in service, new labels in model
- **Performance**: < 1s initial load, 8.55 KB CSS (2.03 KB gzipped), 512.77 KB JS (150.64 KB gzipped)
- **Browser Compatibility**: Chrome/Edge, Firefox, Safari, mobile browsers
- **Migration**: No breaking changes, scoped CSS to avoid conflicts

### Known Limitations
- Danger word detection system planned for future version
- Advanced analytics and reporting features planned

### Migration Notes
- No breaking changes from previous development builds
- Run database migration to add new `conversation_context` and `sent_context` fields
- Rebuild React assets after updating (`gulp build`)
