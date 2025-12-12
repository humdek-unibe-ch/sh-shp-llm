# Configuration Guide

## Overview

The LLM Chat plugin uses a three-tier configuration hierarchy:

1. **Global Configuration** - Admin-level settings for all instances
2. **Component Configuration** - Per-component settings (llmChat style)
3. **Per-Conversation Settings** - Stored with each conversation

## Global Configuration

**Location:** Admin → Modules → LLM Configuration (`/admin/module_llm`)

These settings apply to all chat instances unless overridden at component level.

### API Settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_base_url` | text | `https://gpustack.unibe.ch/v1` | Base URL for LLM API |
| `llm_api_key` | password | - | API authentication token |
| `llm_timeout` | number | `30` | Request timeout in seconds |

### Model Settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_default_model` | select | `qwen3-vl-8b-instruct` | Default model for new conversations |
| `llm_max_tokens` | number | `2048` | Maximum tokens per response |
| `llm_temperature` | text | `1` | Response randomness (0-2) |
| `llm_streaming_enabled` | checkbox | `true` | Enable streaming globally |

### Temperature Guide

- `0.0` - Deterministic, repetitive
- `0.5` - Balanced creativity
- `1.0` - Standard randomness
- `1.5` - High creativity
- `2.0` - Maximum randomness

## Component Configuration

**Location:** CMS → Edit page → llmChat component settings

These settings override global defaults for specific chat instances.

### Behavior Settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_model` | select | (global) | Override model for this component |
| `llm_temperature` | text | (global) | Override temperature |
| `llm_max_tokens` | number | (global) | Override max tokens |
| `llm_streaming_enabled` | checkbox | (global) | Override streaming setting |

### Feature Toggles

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enable_conversations_list` | checkbox | `true` | Show conversation sidebar |
| `enable_file_uploads` | checkbox | `true` | Allow file attachments |
| `enable_full_page_reload` | checkbox | `false` | Reload page after streaming (vs. React refresh) |

### Display Limits

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `conversation_limit` | number | `20` | Max conversations in sidebar |
| `message_limit` | number | `100` | Max messages loaded per conversation |

### Conversation Context

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `conversation_context` | markdown | - | System instructions sent to AI |

See [Conversation Context](conversation-context.md) for detailed usage.

### UI Labels (Translatable)

All UI text is configurable and supports SelfHelp translations:

#### Chat Interface

| Field | Default | Description |
|-------|---------|-------------|
| `chat_description` | "Chat with AI assistant" | Description above chat |
| `message_placeholder` | "Type your message here..." | Input placeholder |
| `ai_thinking_text` | "AI is thinking..." | Streaming indicator |
| `default_chat_title` | "AI Chat" | Default conversation title |

#### Buttons

| Field | Default | Description |
|-------|---------|-------------|
| `submit_button_label` | "Send Message" | Send button text |
| `new_chat_button_label` | "New Conversation" | New conversation button |
| `clear_button_label` | "Clear" | Clear input button |

#### Sidebar

| Field | Default | Description |
|-------|---------|-------------|
| `conversations_heading` | "Conversations" | Sidebar heading |
| `no_conversations_message` | "No conversations yet..." | Empty state message |
| `select_conversation_heading` | "Select a conversation..." | No selection heading |
| `select_conversation_description` | "Choose from sidebar..." | No selection description |

#### Modal Dialogs

| Field | Default | Description |
|-------|---------|-------------|
| `new_conversation_title_label` | "New Conversation" | Create modal title |
| `conversation_title_label` | "Conversation Title (optional)" | Title input label |
| `conversation_title_placeholder` | "Enter title..." | Title input placeholder |
| `cancel_button_label` | "Cancel" | Cancel button |
| `create_button_label` | "Create Conversation" | Create button |
| `delete_confirmation_title` | "Delete Conversation" | Delete modal title |
| `delete_confirmation_message` | "Are you sure...?" | Delete confirmation |
| `confirm_delete_button_label` | "Delete" | Confirm delete button |
| `cancel_delete_button_label` | "Cancel" | Cancel delete button |

#### Status Messages

| Field | Default | Description |
|-------|---------|-------------|
| `loading_text` | "Loading..." | Loading indicator |
| `no_messages_message` | "No messages yet..." | Empty conversation |
| `empty_state_title` | "Start a conversation" | Empty state title |
| `empty_state_description` | "Send a message..." | Empty state description |
| `loading_messages_text` | "Loading messages..." | Messages loading |

#### Model Display

| Field | Default | Description |
|-------|---------|-------------|
| `model_label_prefix` | "Model: " | Model badge prefix |
| `tokens_used_suffix` | " tokens" | Token count suffix |

#### File Uploads

| Field | Default | Description |
|-------|---------|-------------|
| `upload_image_label` | "Upload Image (Vision Models)" | Upload label |
| `upload_help_text` | "Supported formats: JPG, PNG..." | Upload help |
| `attach_files_title` | "Attach files" | Attach button tooltip |
| `no_vision_support_title` | "Current model does not support..." | No vision tooltip |
| `no_vision_support_text` | "No vision" | No vision badge |
| `single_file_attached_text` | "1 file attached" | Single file indicator |
| `multiple_files_attached_text` | "{count} files attached" | Multiple files indicator |
| `remove_file_title` | "Remove file" | Remove file tooltip |

#### Error Messages

| Field | Default | Description |
|-------|---------|-------------|
| `empty_message_error` | "Please enter a message" | Empty input error |
| `streaming_active_error` | "Please wait for the current..." | Concurrent send error |

#### Button Tooltips

| Field | Default | Description |
|-------|---------|-------------|
| `delete_button_title` | "Delete conversation" | Delete tooltip |
| `send_message_title` | "Send message" | Send tooltip |
| `streaming_in_progress_placeholder` | "Streaming in progress..." | Streaming placeholder |

## Admin Console Configuration

**Location:** Admin → Modules → LLM Conversations (`/admin/module_llm/conversations`)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `admin_page_size` | number | `50` | Items per page |
| `admin_refresh_interval` | number | `300` | Auto-refresh interval (0 = disabled) |
| `admin_default_view` | select | `conversations` | Default view mode |
| `admin_show_filters` | checkbox | `true` | Show filters by default |

## Constants

Defined in `server/service/globals.php`:

### Rate Limiting

```php
define('LLM_RATE_LIMIT_REQUESTS_PER_MINUTE', 10);
define('LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS', 3);
define('LLM_RATE_LIMIT_COOLDOWN_SECONDS', 60);
```

### File Uploads

```php
define('LLM_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('LLM_MAX_FILES_PER_MESSAGE', 5);

define('LLM_ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('LLM_ALLOWED_DOCUMENT_EXTENSIONS', ['pdf', 'txt', 'md', 'csv', 'json', 'xml']);
define('LLM_ALLOWED_CODE_EXTENSIONS', ['py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml']);
```

### Vision Models

```php
define('LLM_VISION_MODELS', [
    'internvl3-8b-instruct', 
    'qwen3-vl-8b-instruct'
]);
```

### API Defaults

```php
define('LLM_DEFAULT_MODEL', 'qwen3-vl-8b-instruct');
define('LLM_DEFAULT_TEMPERATURE', 0.7);
define('LLM_DEFAULT_MAX_TOKENS', 2048);
define('LLM_DEFAULT_TIMEOUT', 30);
define('LLM_DEFAULT_CONVERSATION_LIMIT', 20);
define('LLM_DEFAULT_MESSAGE_LIMIT', 100);
```

## Environment-Specific Configuration

### Development

For development, you may want to:

1. Set lower timeouts for faster feedback
2. Enable verbose logging
3. Use a test API endpoint

### Production

For production, ensure:

1. API key is properly secured
2. Rate limiting is appropriate for user base
3. Monitoring is configured
4. Backup strategy for conversation data

## Configuration Loading

### PHP Service Loading

```php
// In LlmService::getLlmConfig()
public function getLlmConfig() {
    static $config = null;
    
    if ($config === null) {
        // Load via stored procedure
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
        
        // Apply defaults
        $config = array_merge($defaults, $config);
    }
    
    return $config;
}
```

### React Config Loading

```typescript
// API-based loading (preferred)
const config = await configApi.get();

// Fallback from data attributes
const config = parseConfig(container);
```

## Troubleshooting Configuration

### Config Not Loading

1. Check page field values in database
2. Verify stored procedure access
3. Clear caches
4. Check for PHP errors

### Translations Not Working

1. Confirm field has `display = 1`
2. Add translations for each language
3. Clear template cache

### Model Not Appearing

1. Check API connectivity
2. Verify API key permissions
3. Check hook registration
4. Review browser console for errors

### Rate Limiting Issues

1. Check cache configuration
2. Verify user session
3. Monitor rate limit cache keys
4. Adjust limits if needed

