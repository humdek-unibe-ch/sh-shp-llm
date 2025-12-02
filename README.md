# SelfHelp Plugin - LLM Chat

A SelfHelp plugin that enables Large Language Model (LLM) integration with real-time chat functionality. Supports OpenAI-compatible APIs with gpustack backend.

## Features

- **Real-time Chat Interface**: Interactive conversations with LLMs
- **Multiple Model Support**: Support for all gpustack models including vision models
- **Streaming Responses**: Real-time streaming of LLM responses via Server-Sent Events
- **File Uploads**: Image uploads for vision-capable models
- **Conversation Management**: Create, view, and delete conversations
- **Rate Limiting**: Built-in rate limiting and concurrent conversation limits
- **Admin Interface**: Administrative access to all user conversations

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
├── server/
│   ├── component/
│   │   ├── LlmHooks.php          # Plugin hooks and component registration
│   │   ├── llmConversations/     # Admin conversations list component (plugin-scoped)
│   │   ├── llmConversation/      # Admin conversation details component (plugin-scoped)
│   │   └── style/llmchat/        # Chat component (MVC)
│   ├── service/
│   │   ├── globals.php           # Plugin constants
│   │   └── LlmService.php        # API and database operations
│   └── db/
│       └── v1.0.0.sql            # Database schema
└── css/ext/ & js/ext/            # Built assets
```

## Plugin Architecture Notes

This plugin uses a **non-standard component location** for admin interfaces:
- Admin components are located inside the plugin directory (`server/component/`) instead of the standard `server/component/` location
- Components are loaded directly through `LlmHooks::handleAdminComponentRequest()` to bypass SelfHelp's standard routing system
- ACL permissions are checked in the hooks before component instantiation
- This approach allows admin functionality to be self-contained within the plugin while maintaining proper access control
- Admin access is controlled by the `admin_llm_conversations` and `admin_llm_conversation` page permissions defined in the database

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

Configure the following settings via the LLM Configuration page:

- **Base URL**: gpustack API endpoint (e.g., `http://localhost:8080`)
- **API Key**: Authentication key for gpustack
- **Default Model**: Default LLM model to use
- **Timeout**: Request timeout in seconds
- **Max Tokens**: Maximum tokens per response
- **Temperature**: Response randomness (0.0-1.0)
- **Streaming**: Enable real-time response streaming

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
- View all user conversations and messages via `/admin/llm/conversations`
- View individual conversation details via `/admin/llm/conversation?id={id}`
- Monitor usage and performance
- Access is controlled by the `admin_llm_conversations` and `admin_llm_conversation` page permissions

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

## License

This plugin is licensed under the Mozilla Public License, v. 2.0.
