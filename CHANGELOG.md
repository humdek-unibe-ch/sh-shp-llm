# Changelog - LLM Plugin

## [1.0.0] - 2025-12-23

### Added

#### Unified JSON Response Schema (December 23, 2025)
- **Mandatory Structured Responses**: All LLM responses now follow a unified JSON schema
- **Predictable Parsing**: Frontend knows exactly what to expect from every response
- **Built-in Safety Detection**: Safety assessment is now part of every LLM response
- **Integrated Form Support**: Forms, media, and suggestions all in one schema
- **Progress Tracking**: Optional topic coverage tracking in response schema
- **Schema Validation**: Backend validates all responses against schema

**New Schema Fields:**
- `type`: Always "response" for identification
- `safety`: Safety assessment with `is_safe`, `danger_level`, `detected_concerns`
- `content`: Text blocks, optional form, media, suggestions
- `progress`: Optional progress tracking data
- `metadata`: Model name, tokens used, language

**New Files:**
- `server/service/LlmResponseService.php`: Unified response handling
- `server/constants/LlmResponseSchema.php`: Schema constants with detailed field documentation
- `doc/response-schema.md`: Complete schema documentation

#### Suggestions (Quick Reply Buttons) Feature
- **Quick Reply Buttons**: Users can click suggestion buttons for common responses
- **Flexible Actions**: Supports `send_message`, `navigate`, and `external_link` actions
- **Custom Values**: Optional `value` property allows different message than button label
- **Backwards Compatible**: Frontend handles both object and string array formats

**Suggestion Object Format:**
```json
{
  "suggestions": [
    { "text": "Option 1" },
    { "text": "Custom action", "value": "Send this message instead" }
  ]
}
```

### Fixed

#### Suggestions Rendering Fix (December 23, 2025)
- **Fixed**: Suggestions not rendering when LLM returns wrong property names
- **Root Cause**: LLM sometimes used `label`, `name`, or `title` instead of required `text` property
- **Solution**: Strict validation - only `{"text": "..."}` format is accepted
- **Schema Update**: System instructions now explicitly show WRONG formats to avoid (label, name, title, etc.)
- **No Backwards Compatibility**: Removed support for non-standard formats - LLM must use exact schema

### Changed

#### Schema Documentation Improvements (December 23, 2025)
- **Enhanced System Instructions**: Much clearer formatting with visual separators
- **Explicit Suggestions Format**: System prompt now clearly shows correct vs incorrect format
- **Field Descriptions**: Added detailed descriptions to all schema fields
- **Complete Examples**: Added comprehensive examples for all content types

#### Schema Validation & Retry Logic (January 5, 2026)
- **Mandatory Schema Compliance**: LLM responses must now match the JSON schema exactly - no randomness allowed
- **Automatic Retry Logic**: Failed schema validation triggers up to 3 automatic retry attempts
- **Professional Schema Management**: Moved schema from PHP constants to dedicated JSON file (`schemas/llm-response.schema.json`)
- **Enhanced System Instructions**: LLM now receives the actual JSON schema in prompts instead of hardcoded examples
- **Self-Correcting AI**: LLM automatically fixes invalid responses based on validation error feedback

**New Features:**
- Schema validation with retry loop in `LlmResponseService::callLlmWithSchemaValidation()`
- Dedicated JSON schema file for better maintainability
- Enhanced error feedback sent to LLM on retry attempts
- Improved null value handling in schema validation

**Technical Implementation:**
- Updated `LlmResponseService::buildSchemaInstruction()` to load schema from JSON file
- Added retry logic with configurable max attempts (default: 3)
- Fixed validation edge cases (null vs empty string handling)
- Enhanced logging for retry attempts and validation failures

#### Schema Architecture Improvements (January 5, 2026)
- **Moved Schema to JSON File**: Schema now stored in `schemas/llm-response.schema.json` instead of PHP constants
- **Dynamic Schema Loading**: `LlmResponseSchema::getSchema()` loads and caches JSON schema
- **Enhanced Validation**: Fixed null handling and improved error messages
- **Better Error Recovery**: Graceful fallback to inline schema if JSON file unavailable

#### Form Schema & Validation (January 5, 2026)
- **Complete Form Schema**: Added comprehensive form structure definition with all field types
- **Form Validation**: Added strict validation for form objects, fields, options, and field types
- **Field Type Support**: Full support for radio, checkbox, select, text, textarea, number, scale fields
- **Option Validation**: Validates option arrays with required value/label properties
- **Scale Field Validation**: Enforces min/max requirements for rating scales
- **Documentation Enhancement**: Updated form documentation with complete field specifications

#### Removed Hints Functionality
- **Removed**: Form field hints (`HintsDisplay` component) - replaced by suggestions
- **Migration**: Use `content.suggestions` instead of `field.hints` for quick input options
- **Cleaner Forms**: Forms now focus on structured input, suggestions handle quick replies

#### LLM-Based Danger Detection (December 23, 2025)
- **AI-Powered Safety**: Danger detection moved from keyword scanning to LLM evaluation
- **Contextual Understanding**: LLM understands nuance and context of messages
- **Immediate Email Notifications**: Uses SelfHelp's JobScheduler for instant delivery
- **Audit Logging**: All detections logged to transactions table
- **Emergency Blocking**: Conversations automatically blocked on emergency level
- **Multi-language Support**: Configure keywords in any language

**Safety Detection Flow:**
1. Keywords injected into LLM context as critical safety instructions
2. LLM evaluates each message and returns safety assessment in response
3. Backend processes safety field: logs, notifies, blocks as needed
4. Frontend displays safety message and handles blocked state

**Configuration Fields:**
- `enable_danger_detection`: Enable/disable the feature per section
- `danger_keywords`: Comma-separated list of keywords for LLM to detect
- `danger_notification_emails`: Email addresses for safety notifications
- `danger_blocked_message`: Customizable safety message (markdown)

**New Files:**
- `server/service/LlmDangerDetectionService.php`: Notification handling
- `server/constants/LlmResponseSchema.php`: Schema constants
- `doc/danger-word-detection.md`: Feature documentation

**Technical Implementation:**
- LLM context injection via `LlmResponseService.buildResponseContext()`
- Safety processing in `LlmChatController.handleSafetyDetection()`
- Email delivery via `JobScheduler.add_and_execute_job()`
- React frontend types for unified schema in `types/index.ts`

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
- `LlmChatModel`: New `conversation_context` property with JSON/text parsing
- `LlmApiFormatterService`: Updated `convertToApiFormat()` accepts context parameter
- `LlmStreamingService`: Updated to track and store sent context
- `LlmService`: Updated `addMessage()` accepts `$sent_context` parameter
- `LlmChatController`: Context processing in both streaming and non-streaming paths

#### Strict Conversation Mode Implementation
- `LlmStrictConversationService`: New service for topic enforcement and context enhancement
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
- Core services (`LlmService.php`, `LlmStreamingService.php`, `LlmApiFormatterService.php`, `LlmFileUploadService.php`, `LlmStrictConversationService.php`)
- MVC components (`LlmChatModel.php`, `LlmChatView.php`, `LlmChatController.php`)
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

### Changed (December 22, 2025)
- **BFH Provider URL Update**: Updated BFH Inference API base URL from `https://inference.mlmp.ti.bfh.ch/api` to `https://inference.mlmp.ti.bfh.ch/api/v1`
- **Documentation Updates**: Updated all documentation files to reflect the new BFH API endpoint
- **Provider Detection**: BfhProvider now correctly detects and handles the new `/api/v1` endpoint
- **Response Structure**: Confirmed support for BFH's enhanced response format including `reasoning_content` and `provider_specific_fields`

### Known Limitations
- Advanced analytics and reporting features planned
- Danger word severity levels (emergency/critical/warning) planned for future version

### Migration Notes
- No breaking changes from previous development builds
- Run database migration to add new `conversation_context` and `sent_context` fields
- Rebuild React assets after updating (`gulp build`)
- **BFH Provider Users**: Update your `llm_base_url` configuration to `https://inference.mlmp.ti.bfh.ch/api/v1` in the admin panel
