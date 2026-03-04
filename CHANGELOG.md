# Changelog

All notable changes to the **sh-shp-llm** plugin are documented in this file.

## [1.1.0] - 2026-03-04

### New Styles

- **`llmFormRecord`** — LLM-enhanced form in record mode. Extends the core `formUserInputRecord` pattern. On form submit, stores data normally and sends an LLM request with configurable context interpolation. The LLM result is displayed in a configurable panel alongside the form.
- **`llmFormLog`** — LLM-enhanced form in log mode. Extends the core `formUserInputLog` pattern. Each submission creates a new log entry with both the form data and the LLM response.

### New Fields (LLM Form Configuration)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_enabled` | checkbox | `1` | Enable/disable LLM generation per form |
| `llm_model` | select-llm-model | (global default) | Model to use for generation |
| `llm_temperature` | text | `1` | Randomness control (0-2) |
| `llm_max_tokens` | number | `2048` | Max tokens for LLM response |
| `llm_context` | markdown | (empty) | Prompt template with `{{field_name}}` interpolation |
| `llm_show_previous_result` | checkbox | `1` (record) / `0` (log) | Show previously generated result on refresh |
| `llm_result_field_name` | text | `llm_result` | Storage field name for LLM result text |
| `llm_result_meta_field_name` | text | `llm_result_meta` | Storage field name for result metadata JSON |
| `llm_result_placement` | select | `bottom` | Panel position: top, bottom, left, right |
| `llm_result_panel` | select | `card` | Panel type: default, card, modal, collapse |
| `llm_result_title` | text | `Result` | Panel title/label (translatable) |
| `llm_result_closable` | checkbox | `1` | Allow dismissing the result panel |
| `llm_result_css` | text | (empty) | Additional Bootstrap/CSS classes for result container |
| `llm_result_css_mobile` | text | (empty) | Mobile-specific CSS classes |
| `llm_show_errors` | checkbox | `1` | Show error alerts on LLM failure |
| `llm_retry_enabled` | checkbox | `1` | Allow retrying without resubmitting |
| `llm_retry_label` | text | `Retry` | Retry button label (translatable) |
| `llm_regenerate_enabled` | checkbox | `1` | Allow regenerating from saved data |
| `llm_regenerate_label` | text | `Regenerate` | Regenerate button label (translatable) |
| `llm_generating_text` | text | `Generating response...` | Loading indicator text (translatable) |

### Features

- **Context Interpolation** — `llm_context` supports `{{field_name}}` placeholders replaced with submitted form values at runtime (e.g., `"Our user {{name}} needs help with {{topic}}"`)
- **Language-Aware Responses** — Automatically adds the user's selected session language to the LLM context for localized output
- **Configurable Result Panel** — Four panel types (inline, card, modal, collapse) with four placement options, custom CSS classes, closable behavior
- **Retry & Regenerate** — Retry on error (same saved data, new LLM call) or regenerate successful results; both update the stored record
- **Error Handling UX** — Clean Bootstrap alerts on LLM failure with configurable visibility
- **Previous Result Display** — Optionally show the last generated LLM result when the form reloads
- **Result Metadata** — Stores model, temperature, token usage, timestamp, and status as JSON alongside the result text
- **Audit Logging** — All LLM interactions logged to `llmMessages` table via existing conversation infrastructure
- **Confirmation Dialog** — Preserves the core `$.confirm` modal behavior from `formUserInput`
- **Always AJAX** — LLM forms always submit via AJAX to receive the LLM response inline

### Database

- New lookup enums: `llmResultPlacement` (top/bottom/left/right), `llmResultPanel` (default/card/modal/collapse)
- New field types: `select-llm-result-placement`, `select-llm-result-panel`
- CMS hooks for placement and panel field selection dropdowns
- Transaction type: `by_llm_form`

### Architecture

- **LlmFormModel** extends `FormUserInputModel` — adds LLM config getters, previous result retrieval
- **LlmFormView** extends `FormUserInputView` — wraps form output with React container div
- **LlmFormController** extends `FormUserInputController` — intercepts save, calls LLM, handles regenerate
- **React bundle** `llm-form.umd.js` — separate Vite build for the form result panel UI
- **LlmResultDisplay** React component — renders result in the configured panel type with markdown formatting
- Reuses existing `MarkdownRenderer` from the chat module for consistent markdown rendering

## [1.0.0] - 2026-02-26

Initial release of the SelfHelp LLM plugin. Provides a complete AI chat integration layer for SelfHelp CMS with structured responses, multi-provider support, and an admin console.

### Core Chat System

- **Real-Time Chat Interface** — React 18 + TypeScript frontend with conversation sidebar, message input with markdown support, and streaming-style message rendering
- **Conversation Management** — Create, list, soft-delete conversations; per-user isolation with configurable limits (default: 20 conversations, 100 messages each)
- **Structured JSON Responses** — All LLM responses follow a mandatory JSON schema (`schemas/llm-response.schema.json`) with `safety`, `content`, `progress`, and `metadata` fields
- **Schema Validation with Auto-Retry** — Responses are validated against the JSON schema; invalid responses trigger up to 3 automatic retry attempts with error feedback to the LLM
- **Rate Limiting** — Built-in protection: 10 requests/minute, 3 concurrent conversations, 60-second cooldown

### Provider System

- **Multi-Provider Architecture** — Pluggable provider system (`LlmProviderInterface`) with automatic detection based on `llm_base_url`
- **GPUStack Provider** — Standard OpenAI-compatible API support (tested with UniBE GPUStack)
- **BFH Provider** — Bern University of Applied Sciences inference API with reasoning content support
- **Model Capabilities** — Automatic detection of vision, code, and reasoning capabilities per model

### Conversation Context

- **Configurable System Instructions** — Define AI behavior per chat section via the `conversation_context` CMS field (supports markdown and JSON array formats)
- **Strict Conversation Mode** — Optional topic enforcement that keeps the AI focused on defined subjects and politely redirects off-topic questions
- **Auto-Start Conversations** — Automatically initiate conversations with a context-aware opening message when users first visit
- **Context Tracking** — Every message records the full context sent to the LLM in the `sent_context` database column for audit

### Safety and Danger Detection

- **LLM-Based Safety Assessment** — Every response includes a `safety` field with `is_safe`, `danger_level` (null/warning/critical/emergency), `detected_concerns`, `requires_intervention`, and `safety_message`
- **Configurable Keywords** — CMS field `danger_keywords` injects safety-relevant topics into the LLM context
- **Automatic Blocking** — Critical/emergency danger levels trigger immediate conversation blocking
- **Email Notifications** — Configurable notification emails via SelfHelp's `JobScheduler` for safety events
- **Audit Logging** — All safety detections logged to the `transactions` table

### File Uploads

- **Image and Document Uploads** — Support for images (jpg, png, gif, webp), documents (pdf, txt, md, csv, json, xml), and code files (py, js, php, etc.)
- **Vision Model Support** — Images sent to vision-capable models (InternVL3, Qwen3-VL) for analysis
- **Automatic Image Resizing** — Large images resized to max 1024px and converted to optimized JPEG before sending to prevent context window overflow
- **Secure File Handling** — User-specific upload directories, MIME type validation, 10 MB size limit, max 5 files per message
- **Contextual File Naming** — Files named with prefixes: `{user_id}_section_{section_id}_conv_{conversation_id}_msg_{message_id}_{random}.{ext}`

### Speech-to-Text

- **Whisper Integration** — Voice input via MediaRecorder API with transcription through GPUStack faster-whisper models
- **Configurable Models** — CMS dropdown for selecting Whisper model (faster-whisper-large-v3, whisper-large-v3, etc.)
- **Audio File Storage** — Recordings saved with proper naming convention for audit trail

### Forms and Data Collection

- **Form Mode** — LLM can generate structured forms (radio, checkbox, select, text, textarea, number, scale fields) within the JSON response schema
- **Suggestions** — Quick-reply suggestion buttons returned by the LLM for common responses
- **Data Saving** — Form submissions saved to SelfHelp `dataTables` via `UserInput::save_data()` following the R Serve pattern
- **Progress Tracking** — Optional topic coverage tracking with percentage and per-topic status

### LLM Scripts Module

- **Script CRUD** — Full create, read, update, delete interface for reusable LLM prompt templates
- **React-Based Editor** — Scripts manager built with React 18 and Monaco Editor for script editing
- **Script Configuration** — Per-script settings for name, async/sync execution mode, model override, temperature, max tokens, data config, and test variables
- **Script Testing** — Test scripts directly from the UI with configurable test variables
- **Job Scheduler Integration** — Scripts can be assigned as scheduled job actions for automated execution
- **One Conversation Per Script** — Script executions reuse a single conversation in `llmConversations` (linked via `id_llm_scripts` FK)
- **Execution Logging** — Script results saved to `dataTables` with full context (template, data_config, test variables, resolved data, interpolated prompt) stored in `sent_context`

### LLM Response Component

- **`llmResponse` Style** — Display component for visualizing LLM response data with `{{field.path}}` interpolation syntax
- **Editable Mode** — Optional inline editing controlled by `enable_editing` field
- **Loading UX** — Automatic spinner overlay during async script execution with highlight animation on content arrival (uses core SelfHelp v7.8.0 event refresh mechanism via `data-event-refresh-loading="1"`)

### Floating Chat Button

- **Floating Mode** — Chat can appear as a floating button with configurable position and icon
- **Page Integration** — Configurable per section; works alongside the standard embedded mode

### Admin Console

- **Conversation Browser** — View all user conversations with date, user, and section filters
- **Message Inspector** — Chat-style message view with markdown rendering, validation status badges, and role indicators
- **Payload Inspector** — View the exact API request payload sent to the LLM for any message (model, temperature, messages array)
- **Validation Tracking** — Each assistant message shows green "Valid" or yellow "Invalid" badge; failed attempts are visually distinct
- **Script Filter** — Filter conversations by linked LLM script
- **Block/Unblock** — Admin controls for conversation blocking

### Message Validation Tracking

- **Schema Validation Logging** — Every LLM response attempt is saved with `is_validated` status (1=valid, 0=failed)
- **Full Request Payload** — Complete API payload (model, temperature, max_tokens, messages) stored in `request_payload` column
- **Failed Attempt History** — When retries occur, each failed attempt is preserved as a separate message for debugging

### Architecture

- **MVC Components** — `llmChat` (style), `llmResponse` (style), `moduleLlmAdminConsole` (module), `moduleLlmScript` (module)
- **Service Layer** — 20+ dedicated PHP services organized by responsibility (core, context, response, files, safety, scripts, speech, etc.)
- **Provider Abstraction** — `LlmProviderInterface` → `BaseProvider` → `GpuStackProvider` / `BfhProvider` with `LlmProviderRegistry`
- **Exception Hierarchy** — `LlmException` → `LlmApiException`, `LlmRateLimitException`, `LlmValidationException`
- **Callback Endpoint** — `CallbackLlm.php` for async script result processing
- **APCu Caching** — `LlmCacheManager` for conversations, messages, and rate limit data
- **React Build** — Three separate Vite entry points: chat (`llm-chat.umd.js`), admin (`llm-admin.umd.js`), scripts (`llm-scripts.umd.js`)
- **Gulp Integration** — Build tasks for installing dependencies, building React, and watching for changes

### Database Tables

| Table | Purpose |
|-------|---------|
| `llmConversations` | Conversations with user, section, model config, soft-delete, and blocking fields |
| `llmMessages` | Messages with role, content, attachments, tokens, context, validation status, and payload |
| `llmConversationProgress` | Topic coverage tracking per conversation and section |
| `llm_scripts` | Reusable prompt templates with execution configuration |

### Configuration

| Setting | Location | Description |
|---------|----------|-------------|
| `llm_base_url` | Module config | LLM API endpoint URL |
| `llm_api_key` | Module config | API authentication token |
| `llm_default_model` | Module config | Default model for all chats |
| `llm_timeout` | Module config | API request timeout (seconds) |
| `llm_max_tokens` | Module config | Max tokens per response |
| `llm_temperature` | Module config | Response randomness (0-2) |
| `llm_model` | Style field | Per-section model override |
| `conversation_context` | Style field | System instructions for the AI |
| `strict_conversation_mode` | Style field | Enable topic enforcement |
| `auto_start_conversation` | Style field | Auto-start conversations |
| `enable_conversations_list` | Style field | Show/hide conversation sidebar |
| `enable_file_uploads` | Style field | Enable file attachments |
| `enable_speech_to_text` | Style field | Enable voice input |
| `speech_to_text_model` | Style field | Whisper model for transcription |
| `enable_danger_detection` | Style field | Enable safety assessment |
| `danger_keywords` | Style field | Keywords for LLM safety context |
| `danger_notification_emails` | Style field | Safety notification recipients |
| `danger_blocked_message` | Style field | Message shown when conversation is blocked |

### Documentation

Detailed guides available in the `doc/` folder and 17 example conversation contexts in `examples/`.
