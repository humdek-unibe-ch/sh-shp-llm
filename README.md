# SelfHelp Plugin - LLM Chat

A SelfHelp plugin that enables Large Language Model (LLM) integration with real-time chat functionality. Supports OpenAI-compatible APIs with gpustack backend.

## Features

### Core Features
- **Real-time Chat Interface**: Interactive conversations with LLMs
- **Multiple Model Support**: Support for all gpustack models including vision models
- **Streaming Responses**: Real-time streaming of LLM responses via Server-Sent Events
- **File Uploads**: Image uploads for vision-capable models
- **Conversation Management**: Create, view, and delete conversations
- **Rate Limiting**: Built-in rate limiting and concurrent conversation limits
- **Admin Interface**: Administrative access to all user conversations
- **MVC Architecture**: Clean separation of concerns following SelfHelp patterns
- **Multi-language Support**: Built-in translation support for UI elements

### Planned Features
- **Conversation Context Module**: Configurable AI behavior and context per component
- **Danger Word Detection System**: Safety monitoring with configurable keywords and notifications

## Requirements

- SelfHelp v7.0+
- PHP 8.2+ with cURL extension
- MySQL 8.0+
- gpustack API access

## Installation

1. Copy the plugin to `server/plugins/sh-shp-llm/`
2. Run the database migration: `server/plugins/sh-shp-llm/server/db/v1.0.0.sql`
3. Install build dependencies: `cd server/plugins/sh-shp-llm/gulp && npm install`
4. Build assets: `cd server/plugins/sh-shp-llm/gulp && npm run build`
5. Configure LLM settings via Admin → Modules → LLM Configuration
6. Add the `llmChat` style to your pages
7. Ensure upload directories have proper permissions for file uploads (vision models)

## File Structure

```
server/plugins/sh-shp-llm/
├── README.md & CHANGELOG.md
├── gulp/                          # Build system
│   ├── gulpfile.js
│   └── package.json
├── react/                         # React frontend application
│   ├── package.json               # React dependencies (react-markdown, etc.)
│   ├── vite.config.ts            # Vite build configuration
│   ├── tsconfig.json             # TypeScript configuration
│   └── src/
│       ├── LLMChat.tsx            # Entry point and initialization
│       ├── types/index.ts        # TypeScript type definitions
│       ├── components/
│       │   ├── LlmChat.tsx       # Main chat component
│       │   ├── LlmChat.css       # Component styles
│       │   ├── MessageList.tsx   # Messages display
│       │   ├── MessageInput.tsx  # Input with file upload
│       │   ├── ConversationSidebar.tsx  # Conversations list
│       │   ├── StreamingIndicator.tsx   # Streaming status
│       │   └── MarkdownRenderer.tsx     # Advanced markdown rendering
│       ├── hooks/
│       │   ├── useChatState.ts   # State management hook
│       │   └── useStreaming.ts   # SSE streaming hook
│       └── utils/
│           ├── api.ts            # API communication
│           └── formatters.ts     # Utility functions
├── server/
│   ├── component/
│   │   ├── LlmHooks.php           # Plugin hooks and component registration
│   │   ├── moduleLlmAdminConsole/ # Admin console component (MVC)
│   │   └── style/llmchat/         # Chat component (MVC)
│   ├── service/
│   │   ├── globals.php            # Plugin constants
│   │   ├── LlmService.php         # API and database operations
│   │   ├── LlmStreamingService.php  # SSE streaming service
│   │   ├── LlmApiFormatterService.php # API message formatting
│   │   └── LlmFileUploadService.php  # File upload handling
│   └── db/
│       └── v1.0.0.sql             # Database schema
├── css/ext/                       # Built CSS assets
│   └── llm-chat.css               # React component styles
├── js/ext/                        # Built JS assets
│   └── llm-chat.umd.js            # React component UMD bundle
├── toDos/                         # Implementation plans and context files
└── assets/                        # Static assets and screenshots
```

## How Streaming Works

### Technical Implementation

The LLM plugin implements real-time streaming using **Server-Sent Events (SSE)** and **HTTP chunked responses**. Here's the complete technical flow:

#### 1. Message Submission Flow

**Frontend (JavaScript):**
```javascript
// User submits message with streaming enabled
sendStreamingMessage(formData) {
    // Step 1: Prepare streaming (save user message to database)
    formData.append('prepare_streaming', '1');
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: formData,
        success: function(response) {
            // Step 2: Start SSE connection
            const streamingUrl = new URL(window.location.href);
            streamingUrl.searchParams.set('streaming', '1');
            streamingUrl.searchParams.set('conversation', conversationId);
            startStreaming(streamingUrl.toString());
        }
    });
}
```

**Backend (PHP Controller):**
```php
// Step 1: Streaming Preparation (handleMessageSubmission)
if ($is_streaming_prep) {
    // Save user message to database
    $this->llm_service->addMessage($conversation_id, 'user', $message, null, $model);
    // Update rate limiting and conversation metadata
    // Return "prepared" status
}

// Step 2: SSE Stream Handler (handleStreamingRequest)
private function handleStreamingRequest() {
    // Set SSE headers for real-time streaming
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Critical: Disable nginx buffering

    // Disable PHP output buffering
    while (ob_get_level()) ob_end_clean();
    @ini_set('output_buffering', 'Off');
    @ini_set('zlib.output_compression', 'Off');
    ob_implicit_flush(true);

    // Get conversation messages and convert to API format
    $messages = $this->llm_service->getConversationMessages($conversation_id, 50);
    $api_messages = $this->convertToApiFormat($messages);

    // Start streaming with callback
    $this->llm_service->streamLlmResponse($api_messages, $model, $temperature, $max_tokens,
        function($chunk) use (&$full_response, &$tokens_used, $conversation_id, $model) {
            if ($chunk === '[DONE]') {
                // Save complete assistant response to database
                $this->llm_service->addMessage($conversation_id, 'assistant', $full_response, null, $model, $tokens_used);
                return;
            }
            // Send real-time chunks to client
            $this->sendSSE(['type' => 'chunk', 'content' => $chunk]);
        }
    );
}
```

#### 2. LLM API Streaming

**LlmService::streamLlmResponse()** uses direct cURL with streaming callback:

```php
public function streamLlmResponse($messages, $model, $temperature, $max_tokens, $callback) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $llm_api_url,
        CURLOPT_RETURNTRANSFER => false,  // Don't buffer response
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $max_tokens,
            'stream' => true  // Enable streaming mode
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ],
        CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
            // Parse streaming data chunks
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $json_data = substr($line, 6);
                }

                if ($json_data === '[DONE]') {
                    $callback('[DONE]');
                    return strlen($data);
                }

                $parsed = json_decode($json_data, true);
                if ($parsed && isset($parsed['choices'][0]['delta']['content'])) {
                    $content = $parsed['choices'][0]['delta']['content'];
                    if (!empty($content)) {
                        $callback($content);  // Send chunk to client
                    }
                }
            }
            return strlen($data);
        }
    ]);

    curl_exec($ch);
    curl_close($ch);
}
```

#### 3. Frontend Streaming Reception

**JavaScript SSE Handler:**
```javascript
startStreaming(streamingUrl) {
    this.eventSource = new EventSource(streamingUrl);

    this.eventSource.onmessage = function(event) {
        const data = JSON.parse(event.data);

        switch (data.type) {
            case 'chunk':
                // Append content to UI in real-time
                appendStreamChunk(data.content);
                break;
            case 'done':
                // Streaming finished - refresh conversation data
                finishStreaming();
                loadConversationMessages(conversationId);
                break;
            case 'error':
                showError(data.message);
                break;
        }
    };
}
```

### Streaming Data Flow

```
1. User submits message
   ↓
2. Frontend: sendStreamingMessage() → prepare_streaming=1 POST
   ↓
3. Backend: handleMessageSubmission() → saves user message to database
   ↓
4. Frontend: receives "prepared" → starts SSE connection
   ↓
5. Backend: handleStreamingRequest() → gets conversation history
   ↓
6. Backend: streamLlmResponse() → opens cURL streaming connection to LLM API
   ↓
7. LLM API: streams response chunks back to server
   ↓
8. Backend: receives chunks → sends SSE events to client
   ↓
9. Frontend: receives SSE events → appends content to UI in real-time
   ↓
10. Streaming complete → backend saves full assistant response to database
    ↓
11. Frontend: refreshes conversation to show properly formatted message
```

### Key Technical Details

- **SSE Headers**: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`
- **Buffering**: All PHP output buffering disabled for immediate delivery
- **Chunking**: Responses parsed from `data: {...}` format and `delta.content` fields
- **Error Handling**: Graceful fallback to non-streaming if SSE fails
- **Performance**: 5ms delay between chunks to prevent overwhelming the UI

### Streaming Data Integrity

The plugin implements **enterprise-grade streaming** with zero partial saves during streaming to ensure data consistency:

#### Event-Driven Architecture
- **Zero Partial Saves**: No database writes occur during streaming to prevent corruption
- **Atomic Commits**: Complete response saved in single database transaction
- **Memory Buffering**: Response chunks accumulated in memory until completion
- **Error Recovery**: Automatic rollback on streaming failures

#### Streaming Buffer Implementation
```php
class StreamingBuffer {
    private $conversation_id;
    private $model;
    private $accumulated_content = '';
    private $has_content = false;

    public function appendChunk($chunk) {
        $this->accumulated_content .= $chunk;
        $this->has_content = true;
    }

    public function finalize() {
        // Single atomic save of complete response
        return $this->llm_service->addMessage(
            $this->conversation_id,
            'assistant',
            $this->accumulated_content,
            null,
            $this->model,
            $tokens_used
        );
    }
}
```


## Context Maintenance

The LLM plugin maintains conversation context by sending the complete message history to the LLM API for each interaction, enabling coherent and contextual responses.

### How Context Works

#### Conversation-Based Context
1. **Message History Storage**: Each conversation stores a complete chronological history of user and assistant messages in the `llmMessages` table
2. **Context Retrieval**: When sending any message, the system retrieves the full conversation history using `getConversationMessages($conversation_id, $limit)`
3. **API Format Conversion**: Messages are converted to OpenAI-compatible format using `convertToApiFormat($messages)`
4. **Full Context Transmission**: The entire message history is sent to the LLM API as the `messages` array, allowing the AI to understand the full conversation context

#### Context Retrieval Process

**For Regular (Non-Streaming) Messages:**
```php
// Retrieve conversation history (up to configured limit)
$messages = $this->llm_service->getConversationMessages($conversation_id, 50);

// Convert to OpenAI API format
$api_messages = $this->api_formatter_service->convertToApiFormat($messages);

// Send full context to LLM API
$response = $this->llm_service->callLlmApi($api_messages, $model, $temperature, $max_tokens);
```

**For Streaming Messages:**
```php
// Same process for streaming - full context ensures coherent responses
$messages = $this->llm_service->getConversationMessages($conversation_id, 50);
$api_messages = $this->api_formatter_service->convertToApiFormat($messages);

// Stream response while maintaining full conversation context
$this->llm_service->streamLlmResponse($api_messages, $model, $temperature, $max_tokens, $callback);
```

#### Context Window Management

- **Default Limit**: 50 messages per conversation (configurable via `message_limit` component field)
- **Purpose**: Prevents context from growing indefinitely while maintaining recent conversation history
- **Configuration**: Administrators can adjust the context window size through the component's `message_limit` setting
- **Benefits**:
  - **Coherent Responses**: AI understands previous messages and maintains conversation flow
  - **Persistent Context**: Context survives page refreshes and user sessions
  - **Efficient Storage**: Configurable limits prevent excessive API token usage
  - **Performance**: Cached message retrieval reduces database load

#### Message Format Conversion

The `LlmApiFormatterService::convertToApiFormat()` method handles:
- **Role Assignment**: Maps database `role` field to API format (`user`, `assistant`, `system`)
- **Content Preservation**: Maintains message content with proper encoding
- **Attachment Processing**: Handles file uploads for vision-capable models
- **Chronological Ordering**: Ensures messages are sent in timestamp order

This approach ensures that every AI response has access to the complete conversation context within the configured limits, enabling natural, contextual chatbot interactions that remember previous exchanges.

## Database Structure & Data Storage

The LLM plugin creates and manages several database tables to store conversations, messages, and configuration data.

### Core Tables

#### `llmConversations` Table
**Purpose**: Stores conversation metadata and settings
**Primary Key**: `id` (auto-increment, 10-digit zero-filled)
**Foreign Keys**: `id_users` → `users.id`

**Columns:**
- `id` (int 10 unsigned zerofill): Unique conversation identifier
- `id_users` (int 10 unsigned zerofill): User who owns the conversation (references `users.id`)
- `title` (varchar 255): Conversation title (auto-generated or user-defined)
- `model` (varchar 100): LLM model used for this conversation
- `temperature` (decimal 3,2): Response randomness setting (0.00-1.00)
- `max_tokens` (int): Maximum tokens per response
- `deleted` (tinyint 1): Soft delete flag (0=active, 1=deleted)
- `created_at` (timestamp): Conversation creation timestamp
- `updated_at` (timestamp): Last modification timestamp (auto-updated)

**Indexes:**
- `idx_user_created` (`id_users`, `created_at`): User conversations by creation date
- `idx_user_updated` (`id_users`, `updated_at`): User conversations by modification date
- `idx_deleted` (`deleted`): Quick filtering of deleted conversations

**Data Stored:**
- **Conversation ownership**: Links conversations to specific users
- **Model settings**: Per-conversation model and parameter settings
- **Soft deletion**: Conversations marked as deleted but retained for audit
- **Timestamps**: Creation and modification tracking for UI sorting

#### `llmMessages` Table
**Purpose**: Stores individual messages within conversations
**Primary Key**: `id` (auto-increment, 10-digit zero-filled)
**Foreign Keys**: `id_llmConversations` → `llmConversations.id`

**Columns:**
- `id` (int 10 unsigned zerofill): Unique message identifier
- `id_llmConversations` (int 10 unsigned zerofill): Parent conversation (references `llmConversations.id`)
- `role` (enum): Message role - 'user', 'assistant', or 'system'
- `content` (longtext): Message content (text or markdown)
- `attachments` (longtext): JSON array of attachment metadata (files, images, documents)
- `model` (varchar 100): Model used for this specific message
- `tokens_used` (int): Token count for assistant responses
- `raw_response` (longtext): Full JSON response from LLM API (debugging)
- `deleted` (tinyint 1): Soft delete flag (0=active, 1=deleted)
- `timestamp` (timestamp): Message creation timestamp

**Indexes:**
- `idx_conversation_time` (`id_llmConversations`, `timestamp`): Messages within conversation by time
- `idx_deleted` (`deleted`): Quick filtering of deleted messages

**Data Stored:**
- **Message content**: User inputs and AI responses with full text
- **File attachments**: Image paths for vision-capable models
- **API metadata**: Token usage, model information, raw API responses
- **Conversation threading**: Messages linked to specific conversations
- **Audit trail**: Complete message history with timestamps

### Configuration Tables (SelfHelp Core)

#### `pages` & `pages_fields` - LLM Configuration Page
**Purpose**: Stores global LLM configuration settings
**Page Keyword**: `sh_module_llm`

**Configuration Fields Stored:**
- `llm_base_url`: API endpoint URL (e.g., `https://gpustack.unibe.ch/v1`)
- `llm_api_key`: Authentication token for LLM service
- `llm_default_model`: Default model for new conversations
- `llm_timeout`: Request timeout in seconds
- `llm_max_tokens`: Default maximum tokens per response
- `llm_temperature`: Default response randomness (0.0-1.0)
- `llm_streaming_enabled`: Global streaming toggle (0=disabled, 1=enabled)

#### `styles` & `styles_fields` - llmChat Component
**Purpose**: Component configuration for chat interface
**Style Name**: `llmChat`

**Internal Configuration Fields:**
- `conversation_limit`: Number of conversations to show in sidebar (default: 20)
- `message_limit`: Messages to load per conversation (default: 100)
- `llm_model`: Component-specific model override
- `llm_temperature`: Component-specific temperature override
- `llm_max_tokens`: Component-specific token limit override
- `llm_streaming_enabled`: Component-specific streaming toggle

**User-Visible Label Fields:**
- `submit_button_label`: Send message button text
- `new_chat_button_label`: New conversation button text
- `delete_chat_button_label`: Delete conversation button text
- `chat_description`: Description text above chat interface
- `conversations_heading`: Sidebar conversations header
- `no_conversations_message`: Empty conversations message
- `select_conversation_heading`: No conversation selected header
- `select_conversation_description`: No conversation selected description
- `model_label_prefix`: Model display prefix (e.g., "Model: ")
- `no_messages_message`: Empty conversation message
- `tokens_used_suffix`: Token count suffix (e.g., " tokens")
- `loading_text`: Screen reader text for loading states
- `ai_thinking_text`: Streaming indicator text
- `upload_image_label`: Image upload field label
- `upload_help_text`: Image upload help text
- `message_placeholder`: Message input placeholder
- `clear_button_label`: Clear message button text
- And 10+ additional modal and button labels...

### Access Control Tables (SelfHelp Core)

#### `acl_groups` - Admin Permissions
**Purpose**: Grant admin access to LLM management pages

**Permissions Granted:**
- `llmAdmin`: Access to `/admin/module_llm/conversations` (comprehensive admin console)

#### `pageType_fields` - Admin Console Configuration
**Purpose**: Configure admin console behavior and display options

**Fields for `sh_llm_admin` pageType:**
- `admin_page_size`: Number of conversations/messages per page (default: 50)
- `admin_refresh_interval`: Auto-refresh interval in seconds (default: 300, 0 = disabled)
- `admin_default_view`: Default view mode - 'conversations' or 'messages' (default: conversations)
- `admin_show_filters`: Show filter panel by default (default: 1/enabled)

#### `hooks` - Dynamic Field Rendering
**Purpose**: Register plugin hooks for dynamic form field generation

**Hook Registrations:**
```sql
-- LLM Model Selection Field (Edit Mode)
INSERT INTO hooks (id_hookTypes, name, description, class, function, exec_class, exec_function)
VALUES (hook_overwrite_return, 'field-llm-model-edit', 'Output select LLM Model field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmModelEdit');

-- LLM Model Selection Field (View Mode)
INSERT INTO hooks (id_hookTypes, name, description, class, function, exec_class, exec_function)
VALUES (hook_overwrite_return, 'field-llm-model-view', 'Output select LLM Model field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmModelView');
```

### File Storage Structure

#### Upload Directory Structure
```
upload/llm/
├── {user_id}/
│   └── {conversation_id}/
│       ├── 20241202_143052_6789abcdef.jpg
│       ├── 20241202_143053_fedcba9876.png
│       └── ...
```

**File Naming Convention:**
- Format: `{YYYYMMDD_HHMMSS}_{uniqid}.{extension}`
- Example: `20241202_143052_6789abcdef.jpg`
- Purpose: Unique filenames, sortable by upload time

**File Metadata Storage:**
- Files stored in `upload/llm/{user_id}/{conversation_id}/`
- Metadata stored in `llmMessages.attachments` column as JSON
- Base64 encoded for API transmission to vision models

### Data Relationships

```
users (SelfHelp Core)
├── llmConversations (1:many)
│   ├── llmMessages (1:many)
│   │   ├── File attachments (optional)
│   └── Rate limiting data (cached)

pages (SelfHelp Core)
├── pages_fields (LLM Configuration)
└── acl_groups (Admin Permissions)

styles (SelfHelp Core)
├── styles_fields (Component Configuration)
└── hooks (Dynamic Fields)
```

### Data Flow & Storage Operations

#### Conversation Creation
1. **Validation**: Check user permissions and rate limits
2. **Database**: Insert into `llmConversations` with user ID, default settings
3. **Title Generation**: Auto-generate title from first message (if provided)
4. **Rate Limiting**: Update user's conversation count in cache
5. **Audit**: Log transaction in SelfHelp's transaction system

#### Message Storage
1. **User Messages**: Saved immediately during form submission
2. **Assistant Messages**: Saved after complete response (streaming) or immediately (non-streaming)
3. **File Attachments**: Files saved to disk, paths stored in database
4. **API Responses**: Full JSON responses stored for debugging
5. **Token Tracking**: Usage statistics stored for billing/monitoring

#### Data Retrieval
1. **Conversations**: User-specific queries with soft delete filtering
2. **Messages**: Ordered by timestamp within conversations
3. **Configuration**: Cached static loading from pages_fields
4. **Admin Access**: Unfiltered queries for all user data (admin only)

#### Cleanup Operations
1. **Soft Deletes**: Messages and conversations marked as deleted
2. **File Cleanup**: Orphaned files removed during maintenance
3. **Cache Invalidation**: Automatic cache clearing on data changes
4. **Rate Limit Reset**: Time-based cache expiration for rate limiting

## Plugin Architecture Notes

This plugin uses a **non-standard component location** for admin interfaces:
- Admin components are located inside the plugin directory (`server/component/`) instead of the standard `server/component/` location
- Components are loaded directly through `LlmHooks::handleAdminComponentRequest()` to bypass SelfHelp's standard routing system
- ACL permissions are checked in the hooks before component instantiation
- This approach allows admin functionality to be self-contained within the plugin while maintaining proper access control
- Admin access is controlled by the `llmAdmin` page permissions defined in the database

### Template Usage (REQUIRED)

**All view output must use templates, never direct HTML output in PHP code.**

**✅ Correct - Use Templates:**
```php
public function output_content() {
    $data = $this->model->getData();
    include __DIR__ . '/tpl/component_template.php';
}
```

**❌ Incorrect - Direct HTML Output:**
```php
public function output_content() {
    echo '<div class="component">';
    echo '<h1>' . $this->model->getTitle() . '</h1>';
    echo '</div>';
}
```

**Template Structure:**
- Templates are stored in `tpl/` subdirectory
- Use PHP includes to load templates
- Pass data through view properties (`$this->model`)
- Keep business logic in models, presentation logic in templates

**Asset Management:**
- All `get_css_includes()` and `get_js_includes()` methods follow DEBUG/production pattern
- DEBUG mode loads unminified files for development
- Production mode loads minified files with git version cache-busting
- Use `shell_exec("git describe --tags")` for version-based cache invalidation

**Configuration Retrieval:**
- Uses SelfHelp's `get_page_fields` stored procedure for proper page configuration
- Automatically handles field translations and defaults
- Cached for performance with static variable pattern

## Configuration

The LLM plugin supports multi-level configuration with global defaults, component overrides, and per-conversation settings.

### Global Configuration (Admin Only)

**Location**: Admin → Modules → LLM Configuration (`/admin/module_llm`)

**Database Storage**: `pages` table with keyword `sh_module_llm`

**Configuration Fields:**
- **llm_base_url**: API endpoint URL (default: `https://gpustack.unibe.ch/v1`)
  - *Storage*: `pages_fields` table
  - *Purpose*: Base URL for all LLM API calls
  - *Validation*: Must be valid HTTP/HTTPS URL

- **llm_api_key**: Authentication token
  - *Storage*: `pages_fields` table (password field type)
  - *Purpose*: Bearer token for API authentication
  - *Security*: Stored encrypted, masked in UI

- **llm_default_model**: Default model selection
  - *Storage*: `pages_fields` table with custom field type `select-llm-model`
  - *Purpose*: Fallback model when none specified
  - *Dynamic*: Populated via hook from available models API

- **llm_timeout**: Request timeout in seconds (default: 30)
  - *Storage*: `pages_fields` table
  - *Purpose*: Maximum time to wait for API responses
  - *Range*: 10-300 seconds

- **llm_max_tokens**: Default token limit (default: 2048)
  - *Storage*: `pages_fields` table
  - *Purpose*: Maximum tokens per API response
  - *Range*: 100-32768 tokens

- **llm_temperature**: Response randomness (default: 0.7)
  - *Storage*: `pages_fields` table
  - *Purpose*: Controls response creativity (0.0 = deterministic, 1.0 = very random)
  - *Range*: 0.0-1.0

- **llm_streaming_enabled**: Global streaming toggle (default: 1)
  - *Storage*: `pages_fields` table (checkbox)
  - *Purpose*: Enable/disable real-time streaming globally
  - *Override*: Can be disabled per component

### Component-Level Configuration

**Location**: CMS → Sections → llmChat component settings

**Database Storage**: `sections_fields` table linked to component instances

**Configuration Hierarchy:**
```
Global Config (pages_fields) → Component Config (sections_fields) → Conversation Config (llmConversations)
```

**Component Fields:**
- **conversation_limit**: Sidebar conversation count (default: 20)
- **message_limit**: Messages per conversation load (default: 100)
- **llm_model**: Override global default model
- **llm_temperature**: Override global temperature
- **llm_max_tokens**: Override global token limit
- **llm_streaming_enabled**: Override global streaming setting
- **enable_conversations_list**: Show/hide conversations sidebar (default: 1)
- **enable_file_uploads**: Enable/disable file upload capability (default: 1)
- **enable_full_page_reload**: Use AJAX page reload instead of React refresh (default: 0)

**User Interface Labels** (30+ configurable text fields):
- **submit_button_label**: Send button text (default: "Send Message")
- **new_chat_button_label**: New conversation button (default: "New Conversation")
- **ai_thinking_text**: Streaming indicator (default: "AI is thinking...")
- **upload_image_label**: File upload label (default: "Upload Image (Vision Models)")
- **message_placeholder**: Input placeholder (default: "Type your message here...")
- And 25+ additional labels for modals, buttons, and status messages

### Per-Conversation Configuration

**Storage**: `llmConversations` table columns

**Settings Stored:**
- **model**: Specific model for this conversation
- **temperature**: Conversation-specific randomness
- **max_tokens**: Conversation-specific token limit
- **title**: User-defined or auto-generated conversation title

### Configuration Loading Process

#### 1. Global Config Loading (`LlmService::getLlmConfig()`)
```php
public function getLlmConfig() {
    static $config = null;
    if ($config === null) {
        // Load from pages_fields using stored procedure
        $page_data = $this->db->query_db_first(
            'CALL get_page_fields(?, ?, ?, ?, ?)',
            [$page_id, 1, 1, '', '']
        );

        // Extract llm_* fields
        foreach ($page_data as $key => $value) {
            if (strpos($key, 'llm_') === 0) {
                $config[$key] = $value;
            }
        }

        // Apply defaults for missing values
        $config = array_merge($defaults, $config);
    }
    return $config;
}
```

#### 2. Component Config Loading (`LlmchatModel`)
```php
public function __construct($services, $id, $params, $id_page, $entry_record) {
    parent::__construct($services, $id, $params, $id_page, $entry_record);

    // Load component configuration from sections_fields
    $this->conversation_limit = $this->get_db_field('conversation_limit', '20');
    $this->message_limit = $this->get_db_field('message_limit', '100');
    $this->streaming_enabled = $this->get_db_field('llm_streaming_enabled', '1');

    // Load all UI labels (30+ fields)
    $this->submit_button_label = $this->get_db_field('submit_button_label', 'Send Message');
    // ... 30+ more label fields
}
```

#### 3. Conversation Config Loading
```php
public function getConversation($conversation_id, $user_id) {
    return $this->db->query_db_first(
        "SELECT * FROM llmConversations
         WHERE id = ? AND id_users = ? AND deleted = 0",
        [$conversation_id, $user_id]
    );
    // Returns: model, temperature, max_tokens, title, etc.
}
```

### Configuration Caching Strategy

- **Global Config**: Cached in static variable (reused across requests)
- **Component Config**: Cached per component instance
- **Conversation Config**: Loaded on-demand, not cached
- **UI Labels**: Cached in component model instances
- **Available Models**: Cached with 5-minute TTL (API-dependent)

### Configuration Validation

#### Input Validation Rules
- **URLs**: Must be valid HTTP/HTTPS format
- **API Keys**: Minimum 20 characters, no whitespace
- **Numeric Fields**: Range validation (temperature: 0.0-1.0, tokens: 100-32768)
- **Model Names**: Must exist in available models list (dynamically validated)

#### Runtime Validation
```php
public function validateConfig($config) {
    $errors = [];

    if (!filter_var($config['llm_base_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Invalid API URL format';
    }

    if (strlen($config['llm_api_key']) < 20) {
        $errors[] = 'API key too short';
    }

    if ($config['llm_temperature'] < 0.0 || $config['llm_temperature'] > 1.0) {
        $errors[] = 'Temperature must be between 0.0 and 1.0';
    }

    return $errors;
}
```

## Hooks System

The LLM plugin extensively uses SelfHelp's hook system to integrate with core functionality and provide dynamic features.

### Hook Architecture Overview

SelfHelp hooks use the `uopz` PHP extension for runtime method interception:

```php
// Hook registration in database
INSERT INTO hooks (id_hookTypes, name, class, function, exec_class, exec_function)
VALUES (?, 'hook-name', 'TargetClass', 'targetMethod', 'HookClass', 'hookMethod');

// Runtime activation
uopz_set_hook('TargetClass', 'targetMethod', function() {
    // Execute hook logic
});
```

### Plugin Hook Types

#### 1. Field Rendering Hooks (`hook_overwrite_return`)

**Purpose**: Dynamically generate form fields for LLM model selection

**Hook Registration:**
```sql
-- Edit mode hook
INSERT INTO hooks (id_hookTypes, name, class, function, exec_class, exec_function)
VALUES (
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-model-edit',
    'CmsView',
    'create_field_form_item',
    'LlmHooks',
    'outputFieldLlmModelEdit'
);

-- View mode hook
INSERT INTO hooks (id_hookTypes, name, class, function, exec_class, exec_function)
VALUES (
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'),
    'field-llm-model-view',
    'CmsView',
    'create_field_item',
    'LlmHooks',
    'outputFieldLlmModelView'
);
```

**Hook Implementation (`LlmHooks` class):**

```php
public function outputFieldLlmModelEdit($args) {
    return $this->returnSelectLlmModelField($args, 0); // 0 = edit mode
}

public function outputFieldLlmModelView($args) {
    return $this->returnSelectLlmModelField($args, 1); // 1 = view mode (disabled)
}

private function returnSelectLlmModelField($args, $disabled) {
    $field = $this->get_param_by_name($args, 'field');

    // Only intercept llm_model and llm_default_model fields
    if ($field['name'] == 'llm_model' || $field['name'] == 'llm_default_model') {
        try {
            $llmService = new LlmService($this->services);
            $models = $llmService->getAvailableModels();

            // Convert to select field format
            $items = array_map(function($model) {
                return ['value' => $model['id'], 'text' => $model['id']];
            }, $models);

            return new BaseStyleComponent("select", [
                "value" => $field['content'],
                "name" => "fields[{$field['name']}][{$field['id_language']}][{$field['id_gender']}][content]",
                "disabled" => $disabled,
                "items" => $items,
                "live_search" => true,
                "is_required" => true
            ]);
        } catch (Exception $e) {
            // Fallback to error message
            return new BaseStyleComponent("select", [
                "value" => $field['content'],
                "disabled" => $disabled,
                "items" => [['value' => '', 'text' => 'Error: ' . $e->getMessage()]]
            ]);
        }
    }

    // Not our field, continue normal processing
    $res = $this->execute_private_method($args);
    return $res;
}
```

**Hook Execution Flow:**
```
1. CMS loads field for editing
2. CmsView::create_field_form_item() called
3. Hook intercepts call before original method
4. LlmHooks::outputFieldLlmModelEdit() executes
5. Hook checks if field is llm_model or llm_default_model
6. If yes: fetch available models from API, return select component
7. If no: call original CmsView::create_field_form_item()
8. CMS renders the returned component
```

### Hook Data Flow

#### Model List Retrieval (`LlmService::getAvailableModels()`)
```php
public function getAvailableModels() {
    $cache_key = 'llm_available_models';
    $cached = $this->cache->get($cache_key);

    if ($cached !== false) {
        return $cached;
    }

    $config = $this->getLlmConfig();
    $url = rtrim($config['llm_base_url'], '/') . '/models';

    $response = BaseModel::execute_curl_call([
        'URL' => $url,
        'request_type' => 'GET',
        'header' => ['Authorization: Bearer ' . $config['llm_api_key']]
    ]);

    if (!$response) {
        throw new Exception('Failed to fetch models from API');
    }

    $data = json_decode($response, true);
    if (!isset($data['data'])) {
        throw new Exception('Invalid API response format');
    }

    $models = array_map(function($model) {
        return [
            'id' => $model['id'],
            'object' => $model['object'],
            'created' => $model['created'],
            'owned_by' => $model['owned_by']
        ];
    }, $data['data']);

    // Cache for 5 minutes
    $this->cache->set($cache_key, $models, 300);

    return $models;
}
```

### Hook Error Handling

Hooks include comprehensive error handling to prevent breaking the CMS:

```php
try {
    $llmService = new LlmService($this->services);
    $models = $llmService->getAvailableModels();
    // Process models...
} catch (Exception $e) {
    // Return fallback component with error message
    return new BaseStyleComponent("select", [
        "value" => $field['content'],
        "disabled" => $disabled,
        "items" => [['value' => '', 'text' => 'Error loading models: ' . $e->getMessage()]]
    ]);
}
```

### Hook Performance Considerations

- **Caching**: Model list cached for 5 minutes to reduce API calls
- **Lazy Loading**: Models only fetched when LLM fields are rendered
- **Fallback**: Graceful degradation when API unavailable
- **Minimal Overhead**: Hooks only activate for specific field types

### Advanced Hook Patterns

#### Conditional Hook Execution
```php
private function returnSelectLlmModelField($args, $disabled) {
    $field = $this->get_param_by_name($args, 'field');

    // Only intercept our specific fields
    if (in_array($field['name'], ['llm_model', 'llm_default_model'])) {
        // Execute hook logic
        return $this->outputSelectLlmModelField($field['content'], $field['name'], $disabled);
    }

    // Pass through to original method
    return $this->execute_private_method($args);
}
```

#### Parameter Extraction
```php
private function get_param_by_name($args, $param_name) {
    // SelfHelp's hook parameter passing mechanism
    $reflector = new ReflectionObject($args['hookedClassInstance']);
    $method = $reflector->getMethod($args['methodName']);
    $method->setAccessible(true);

    // Extract parameters from hooked method call
    $params = $method->invoke($args['hookedClassInstance'], ...$args['original_parameters']);
    $method->setAccessible(false);

    return $params[$param_name] ?? null;
}
```

### Hook Debugging

Enable debug logging to troubleshoot hook execution:

```php
// In LlmHooks constructor
$this->debug_mode = true;

private function logHookExecution($hook_name, $args, $result) {
    if ($this->debug_mode) {
        error_log("LLM Hook [$hook_name]: " . json_encode([
            'field' => $args['field']['name'] ?? 'unknown',
            'mode' => $args['disabled'] ? 'view' : 'edit',
            'result_type' => get_class($result)
        ]));
    }
}
```

## React Component

The LLM Chat plugin includes a React-based implementation that provides the same functionality as the vanilla JavaScript version with additional benefits:

- **Smooth UI Experience**: React's virtual DOM provides efficient updates and smooth animations
- **Component Architecture**: Modular, maintainable codebase with reusable components
- **TypeScript Support**: Full type safety for better development experience
- **Modern Build System**: Vite-based build with UMD output for easy integration

### React File Structure

```
react/
├── package.json                 # Dependencies and scripts
├── vite.config.ts              # Build configuration
├── tsconfig.json               # TypeScript configuration
└── src/
    ├── LLMChat.tsx             # Entry point and initialization
    ├── types/
    │   └── index.ts            # TypeScript type definitions
    ├── components/
    │   ├── LlmChat.tsx         # Main chat component
    │   ├── LlmChat.css         # Component styles
    │   ├── MessageList.tsx     # Messages display
    │   ├── MessageInput.tsx    # Input with file upload
    │   ├── ConversationSidebar.tsx  # Conversations list
    │   └── StreamingIndicator.tsx   # Streaming status
    ├── hooks/
    │   ├── useChatState.ts     # State management hook
    │   └── useStreaming.ts     # SSE streaming hook
    └── utils/
        ├── api.ts              # API communication
        └── formatters.ts       # Utility functions
```

### Building the React Component

```bash
# Navigate to gulp directory
cd server/plugins/sh-shp-llm/gulp

# Install gulp dependencies (first time only)
npm install

# Install React dependencies (first time only)
gulp react-install

# Or install manually (includes markdown dependencies):
cd ../react
npm install
# Installs: react, react-dom, react-markdown, remark-gfm, rehype-highlight, etc.

# Build everything (React + legacy assets)
cd ../gulp
gulp build

# Or build React only
gulp react-build
```

### React Dependencies

The React component uses these key dependencies:
- `react` / `react-dom`: Core React library
- `react-markdown`: Markdown rendering
- `remark-gfm`: GitHub Flavored Markdown support
- `rehype-highlight`: Syntax highlighting for code blocks
- `@types/hast`: TypeScript types for rehype

### Output Files

After building, the following files are generated:

| File | Description |
|------|-------------|
| `js/ext/llm-chat.umd.js` | React component UMD bundle (~505KB, includes React/ReactDOM/react-markdown) |
| `css/ext/llm-chat.css` | React component styles (~13KB, includes syntax highlighting) |
| `css/ext/style.css` | Combined styles for component |
| `js/ext/llmchat.min.js` | Legacy vanilla JS (minified) |
| `css/ext/llmchat.min.css` | Legacy CSS (minified) |

### React Component Architecture

#### Main Components

**LlmChat.tsx** - The main container component that orchestrates:
- State management via custom hooks
- Conversation selection and creation
- Message sending (streaming and non-streaming)
- File attachment handling

**MessageList.tsx** - Displays messages with:
- User messages (right-aligned, blue background)
- Assistant messages (left-aligned, light background)
- Streaming message with typing cursor
- Thinking indicator during AI processing
- File attachment indicators

**MessageInput.tsx** - Modern input area with:
- Auto-resizing textarea (24px to 120px, internal scroll)
- Fixed button container that doesn't scale with textarea
- File attachment button (conditionally shown)
- Drag-and-drop file support
- Character count indicator
- Clear and Send buttons with icon states
- Loading spinner during streaming

**MarkdownRenderer.tsx** - Advanced markdown component with:
- GitHub Flavored Markdown support (tables, task lists)
- Syntax highlighting for code blocks
- Copy-to-clipboard buttons
- External link handling (opens in new tab)
- Responsive table wrappers

**ConversationSidebar.tsx** - Conversation management:
- List of conversations with delete buttons
- New conversation button and modal
- Active conversation highlighting
- Empty state message

#### Custom Hooks

**useChatState.ts** - Manages all chat state:
```typescript
const {
  conversations,        // Array of conversations
  currentConversation,  // Currently selected
  messages,             // Messages in current conversation
  isLoading,           // Loading state
  error,               // Error message
  loadConversations,   // Load conversation list
  loadConversationMessages,  // Load messages
  createConversation,  // Create new conversation
  deleteConversation,  // Delete conversation
  selectConversation,  // Select conversation
  sendMessage,         // Send message (non-streaming)
  addUserMessage,      // Add user message to UI
  clearError,          // Clear error state
  setError,            // Set error message
  getActiveModel       // Get active model (conversation or config)
} = useChatState(config);
```

**useStreaming.ts** - Manages SSE streaming:
```typescript
const {
  isStreaming,          // Whether currently streaming
  streamingContent,     // Accumulated response content
  sendStreamingMessage, // Send with streaming response
  stopStreaming,        // Stop current stream
  clearStreamingContent // Clear accumulated content
} = useStreaming({ 
  config, 
  onChunk,              // Callback for each chunk
  onDone,               // Callback when streaming completes
  onError,              // Callback on error
  onRefreshMessages,    // Callback to refresh messages (React-only refresh)
  getActiveModel        // Callback to get active model
});
```

**useSmartScroll** (inline in LlmChat.tsx) - Smart scroll management:
```typescript
const {
  scrollToBottom,       // Scroll if user is near bottom
  forceScrollToBottom,  // Always scroll to bottom
  handleScroll          // Handle manual scroll events
} = useSmartScroll(containerRef);
```

#### API Communication

All API calls go through the controller using `window.location` for security:

```typescript
// Get conversations
const conversations = await conversationsApi.getAll();

// Create conversation
const conversationId = await conversationsApi.create(title, model);

// Delete conversation
await conversationsApi.delete(conversationId);

// Load messages
const { conversation, messages } = await messagesApi.getByConversation(conversationId);

// Send message (non-streaming)
const response = await messagesApi.send(message, conversationId, model, files);

// Send message (streaming)
const prepResponse = await messagesApi.prepareStreaming(message, conversationId, model, files);
const streamingApi = new StreamingApi(conversationId);
streamingApi.connect(onMessage, onError);
```

### Configuration

The React component receives configuration via data attributes on the container element:

```html
<div id="llm-chat-root"
     data-user-id="123"
     data-current-conversation-id="456"
     data-configured-model="qwen3-vl-8b-instruct"
     data-enable-conversations-list="1"
     data-enable-file-uploads="1"
     data-streaming-enabled="1"
     data-enable-full-page-reload="0"
     data-message-placeholder="Type your message..."
     data-no-conversations-message="No conversations yet"
     data-ai-thinking-text="AI is thinking..."
     data-max-file-size="10485760"
     data-max-files="5">
</div>
```

Or via JSON config (recommended):

```html
<div id="llm-chat-root"
     data-user-id="123"
     data-config='<?php echo htmlspecialchars($this->getReactConfig()); ?>'>
</div>
```

#### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `userId` | number | required | Current user ID |
| `currentConversationId` | string | - | Pre-selected conversation |
| `configuredModel` | string | required | Default LLM model |
| `enableConversationsList` | boolean | true | Show/hide sidebar |
| `enableFileUploads` | boolean | true | Enable file attachments |
| `streamingEnabled` | boolean | true | Enable SSE streaming |
| `enableFullPageReload` | boolean | false | AJAX reload vs React refresh |
| `fileConfig` | object | - | File upload constraints |
| `messagePlaceholder` | string | "Type your message..." | Input placeholder |
| `aiThinkingText` | string | "AI is thinking..." | Streaming indicator |

### Type Definitions

Key types used throughout the React component:

```typescript
// Message structure
interface Message {
  id: string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  formatted_content?: string;
  timestamp: string;
  tokens_used?: number;
  attachments?: string; // JSON string
}

// Conversation structure
interface Conversation {
  id: string;
  title: string;
  model: string;
  created_at: string;
  updated_at: string;
}

// File upload tracking
interface SelectedFile {
  id: string;
  file: File;
  hash: string;
  previewUrl?: string;
}

// Component configuration
interface LlmChatConfig {
  userId: number;
  currentConversationId?: string;
  configuredModel: string;
  enableConversationsList: boolean;
  enableFileUploads: boolean;
  streamingEnabled: boolean;
  enableFullPageReload: boolean;  // Use AJAX page reload instead of React refresh
  fileConfig: FileConfig;
  acceptedFileTypes: string;
  // UI labels
  messagePlaceholder: string;
  noConversationsMessage: string;
  newConversationTitleLabel: string;
  conversationTitleLabel: string;
  cancelButtonLabel: string;
  createButtonLabel: string;
  deleteConfirmationTitle: string;
  deleteConfirmationMessage: string;
  tokensSuffix: string;
  aiThinkingText: string;
}
```

### Development Workflow

For React development with hot reload:

```bash
# Terminal 1: Start Vite dev server
cd server/plugins/sh-shp-llm/react
npm run dev

# Terminal 2: Watch for changes and rebuild
cd server/plugins/sh-shp-llm/gulp
gulp watch-react
```

### Switching Between Vanilla JS and React

The plugin supports both implementations. To switch:

1. **Use Vanilla JS**: Include `llmchat.min.js` and `llmchat.min.css`
2. **Use React**: Include `llm-chat.umd.js` and `llm-chat.css`

The controller template (`llm_chat_main.php`) determines which version to use based on component configuration.

## Usage

### Adding Chat to a Page

1. Create a new page or edit an existing one
2. Add a new section with style `llmchat`
3. Configure the following fields:
   - `conversation_limit`: Number of recent conversations to show (default: 20)
   - `message_limit`: Messages to load per conversation (default: 100)
   - `default_model`: Override default model
   - `temperature`: Default temperature setting
   - `max_tokens`: Default max tokens
   - `streaming_enabled`: Enable/disable streaming
   - `submit_button_label`: Send button text
   - `new_chat_button_label`: New conversation button text

### Supported Models

The plugin supports all gpustack models:
- **Text Models**: `qwen3-vl-8b-instruct`, `apertus-8b-instruct-2509`, `deepseek-r1-0528-qwen3-8b`, etc.
- **Vision Models**: `internvl3-8b-instruct` (with image upload support)
- **Embedding Models**: `bge-m3`, `qwen3-embedding-0.6b`
- **Specialized Models**: OCR, reranking, speech-to-text

## API Integration

The plugin uses OpenAI-compatible API format, making it compatible with:
- gpustack
- OpenAI API
- Other OpenAI-compatible LLM providers

## File Uploads

For vision models, users can upload images that are:
- Stored in `upload/llm/{user_id}/{conversation_id}/`
- Base64 encoded for API transmission
- Accessible via secure file paths

## Security Features

- User-based conversation isolation
- Rate limiting (10 requests/minute, 3 concurrent conversations)
- Input sanitization and validation
- Secure file upload handling
- Admin audit trail access


## Admin Features

Administrators can:
- Access the LLM Admin Console component via `/admin/module_llm/conversations` for a comprehensive interface to browse all user conversations and messages
- View individual conversation details via conversation links in the admin console
- Monitor usage and performance across all users
- Access quick admin links through the LLM panel in the configuration page
- Access is controlled by the `llmAdmin` page permissions and admin group membership

## Troubleshooting

### Common Issues

**Chat not loading**: Check LLM configuration settings
**API errors**: Verify gpustack connection and API key
**File uploads failing**: Check upload directory permissions
**Streaming not working**: Ensure SSE is supported by the browser

### Logs

Check SelfHelp logs for detailed error information:
- API request/response logs
- Database operation logs
- File upload errors

## Version History

### 1.0.0 (December 2024)
- Complete LLM chat plugin implementation for SelfHelp CMS
- Real-time streaming responses via Server-Sent Events (SSE)
- Support for multiple LLM models including vision-capable models
- File upload functionality for images (vision models)
- Conversation management (create, view, delete conversations)
- Rate limiting system (10 requests/minute, 3 concurrent conversations)
- Comprehensive Admin Console for monitoring all user conversations and messages
- LLM panel with quick admin links in configuration page
- Global configuration page following SelfHelp pageType patterns
- Custom database tables: `llmConversations`, `llmMessages`
- OpenAI-compatible API integration
- React-based frontend with TypeScript support
- MVC architecture following SelfHelp component patterns
- Multi-language UI support with translatable labels
- Secure file upload system with validation
- Transaction logging integration
- Plugin hooks for dynamic model selection and admin panel
- Admin components with custom routing

#### Component Configuration Enhancements
- **Granular Chat Control**: Added `enable_conversations_list`, `enable_file_uploads`, and `enable_full_page_reload` component fields for flexible chat interface customization
- **Improved Temperature Configuration**: Changed temperature field type from number to text for better flexibility, updated default from 0.7 to 1.0 with enhanced descriptions
- **Legacy Conversation Management**: Added `getOrCreateConversationForModel()` method for model-specific conversation handling

#### Enhanced User Experience
- **Advanced Markdown Rendering**: Improved code block copy functionality and JSON key color highlighting
- **UI/UX Refinements**: Cleaned up chat interface styling, removed blue border on textarea focus
- **Smart Conversation Selection**: Fixed conversation selection behavior during creation
- **Responsive File Uploads**: Improved file upload path handling and API formatting

#### Technical Improvements
- **Build System**: Moved React CSS output to `css/ext` folder for better organization
- **Streaming Enhancements**: Better streaming response display and parameter validation
- **Code Quality**: Removed debug logging from services and controllers
- **Asset Management**: Improved asset compilation and React component integration

## License

This plugin is licensed under the Mozilla Public License, v. 2.0.
