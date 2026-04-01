# sh-shp-llm

LLM integration plugin for [SelfHelp CMS](https://github.com/humdek-unibe-ch/sh-selfhelp). Adds AI-powered chat, structured responses, file uploads, speech-to-text, reusable prompt scripts, and a full admin console to any SelfHelp page.

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Components](#components)
- [LLM Scripts](#llm-scripts)
- [Prompt Assets](#prompt-assets)
- [Architecture](#architecture)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Overview

This plugin provides a general-purpose LLM integration layer for SelfHelp. It is designed as a **base plugin** that other plugins can extend (e.g., `sh-shp-llm_therapy_chat` builds therapy-specific features on top of it).

`sh-shp-llm` has no runtime dependency on `sh-shp-llm_therapy_chat`.

**Key capabilities:**

- Chat interface with conversation history and structured JSON responses
- Multi-provider support (GPUStack, BFH Inference API, any OpenAI-compatible endpoint)
- File uploads with vision model support for image analysis
- Speech-to-text via Whisper models
- Configurable system instructions per chat section
- LLM-based safety assessment and danger detection
- Reusable prompt scripts with sync/async execution and job scheduler integration
- Prompt datasets and replay-based evaluations in prompt lab
- Admin console for monitoring all user conversations

## Prerequisites

| Requirement | Version | Notes |
|-------------|---------|-------|
| SelfHelp | v7.8.0+ | Core CMS framework |
| PHP | 8.2+ | With cURL and GD extensions |
| MySQL | 8.0+ | InnoDB, utf8mb4 |
| Node.js | 16+ | For building frontend assets |
| LLM API | Any | GPUStack, BFH, or any OpenAI-compatible endpoint |

The LLM API must expose `/v1/chat/completions` and `/v1/models` endpoints following the OpenAI API format.

## Installation

### 1. Place the Plugin

Copy the plugin folder into the SelfHelp plugins directory:

```
server/plugins/sh-shp-llm/
```

### 2. Run Database Migration

```bash
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.0.0.sql
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.1.0.sql
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.2.0.sql
```

This creates core plugin tables and applies v1.1.0 prompt-lab extensions (prompt registry, datasets, and evaluations). The v1.1.0 migration is rerunnable (`INSERT IGNORE` / `CREATE TABLE IF NOT EXISTS` pattern).
It also re-applies critical prompt-lab indexes and unique keys through the shared migration helper procedures so interrupted runs can be safely replayed.

### 3. Build Frontend Assets

```bash
cd server/plugins/sh-shp-llm/gulp
npm install
npm run build
```

This installs React dependencies and builds UMD bundles, including:
- `js/ext/llm-chat.umd.js` — Chat interface
- `js/ext/llm-admin.umd.js` — Admin console
- `js/ext/llm-scripts.umd.js` — Scripts manager
- `js/ext/llm-apikeys.umd.js` — CMS API key manager
- `js/ext/llm-memory.umd.js` - Dedicated memory manager page

### 4. Configure the Module

1. Go to **Admin > Pages** and find the auto-created module page (`sh_module_llm`)
2. Set the required fields:
   - `llm_api_keys` — JSON API key manager with one or more servers (`name`, `base_url`, `api_key`)
   - `llm_default_model` — Default model name (e.g., `qwen3-vl-8b-instruct`)
3. Optionally adjust `llm_timeout`, `llm_max_tokens`, and `llm_temperature`

## Configuration

### Module-Level Settings

These apply globally to all chat sections and are configured on the module page.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_api_keys` | json-llm-api-keys | `[]` | Array of server configs: `[{name, base_url, api_key}]` |
| `llm_default_model` | select | `qwen3-vl-8b-instruct` | Default model for all chats |
| `llm_timeout` | number | `30` | API request timeout in seconds |
| `llm_max_tokens` | number | `2048` | Maximum tokens per response |
| `llm_temperature` | number | `1` | Response randomness (0-2) |

Model identifiers are stored and submitted in canonical scoped format:
`Server Name :: model-id`

The same backend catalog (`LlmService::getAvailableModels(..., 'llm'|'audio')`) feeds:
- `select-llm-model` dropdowns
- `select-audio-model` dropdowns
- runtime server/model resolution for API requests

### Section-Level Settings (llmChat style)

These are configured per chat section and override module defaults where applicable.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_model` | select | — | Override model for this section |
| `conversation_context` | markdown | — | System instructions sent to the AI |
| `strict_conversation_mode` | checkbox | off | Keep AI focused on defined topics |
| `auto_start_conversation` | checkbox | off | Auto-create conversation on first visit |
| `auto_start_message` | markdown | — | Opening message for auto-started conversations |
| `enable_conversations_list` | checkbox | on | Show the conversation sidebar |
| `enable_file_uploads` | checkbox | on | Allow file attachments |
| `enable_speech_to_text` | checkbox | off | Enable voice input button |
| `speech_to_text_model` | select | — | Whisper model for transcription |
| `enable_danger_detection` | checkbox | off | Enable LLM safety assessment |
| `danger_keywords` | text | — | Safety topics for LLM context (comma-separated) |
| `danger_notification_emails` | text | — | Email addresses for safety alerts |
| `danger_blocked_message` | markdown | — | Message shown when conversation is blocked |
| `conversation_limit` | number | `20` | Max conversations in sidebar |
| `message_limit` | number | `100` | Max messages per conversation |

### Conversation Context

The `conversation_context` field defines the AI's behavior. It is prepended to every API call as a system message. Write it as plain text or markdown:

```markdown
You are a learning assistant for German vocabulary.

Rules:
- Only discuss German language topics
- Provide example sentences for every new word
- Quiz the user after every 5 new words
```

For advanced use, you can provide a JSON array of message objects:

```json
[
  {"role": "system", "content": "You are a helpful assistant."},
  {"role": "user", "content": "What topics can I ask about?"},
  {"role": "assistant", "content": "You can ask me about anything related to..."}
]
```

See `examples/` for 17 ready-to-use context templates covering education, health, assessment, and research scenarios.

## Prompt Assets

Static LLM-facing prompt text is externalized to `assets/prompts/` (one file per prompt) and loaded by key through the prompt asset registry/loader layer.

See [doc/prompt-assets.md](doc/prompt-assets.md) for naming, loading, and fail-closed behavior details.

## Usage

### Global User Memory

Version `1.2.0` adds a module-level global memory system for `sh_module_llm`.

Memory is:
- global per user, not per `llmChat` section
- updated from forms, surveys, `llmChat` fallback submissions, login, and profile-name changes
- stored in standard SelfHelp `dataTables`
- consumed explicitly through `data_config`

#### Module fields

Configure these defaults on the `sh_module_llm` page:
- `llm_memory_enabled`
- `llm_memory_key`
- `llm_memory_storage_mode`
- `llm_memory_table_name`
- `llm_memory_history_table_name`

Manage memory rules, write sources, and user memory operations on the dedicated `moduleLlmMemory` admin page.

#### Storage modes

- `record`: write only the current snapshot table
- `log`: write only the history table; effective memory resolves from latest applied history row
- `both`: write current snapshot and history; recommended default

#### Rule example

Memory rules are stored in the normalized `llm_memory_rules` table and edited through the dedicated memory page.

```json
{
  "rule_key": "sleep_form_finished",
  "label": "Sleep Form Finished",
  "enabled": true,
  "memory_key": "global",
  "source_type": "form_action_submit",
  "source_match_json": {
    "table_name": "0000001234"
  },
  "trigger_types_json": ["finished"],
  "storage_mode_override": "both",
  "execution_mode": "llm_summarize",
  "data_config_json": [
    {
      "table": "llm_memory",
      "retrieve": "last",
      "current_user": true,
      "map_fields": [
        { "field_name": "memory_text", "value": "memory_text" },
        { "field_name": "memory_json", "value": "memory_json" }
      ]
    }
  ],
  "llm_model": "",
  "llm_temperature": 0.2,
  "llm_max_tokens": 1200,
  "refresh_sections_json": []
}
```

Prompt Lab ownership is derived from the rule row itself:
- `owner_type = llm_memory_rule`
- `owner_id = <rule id>`
- `prompt_slot = memory_rule`

#### Execution modes

- `llm_summarize`: send the event payload to the async memory worker and let the prompt decide what to keep
- `direct_mapping`: write stable facts directly from submitted fields without calling the LLM

Direct-mapping example:

```json
{
  "type": "llm_memory_update",
  "memory_rule_key": "onboarding_direct_fields",
  "execution_mode": "direct_mapping",
  "field_mapping": {
    "preferred_name": "{{first_name}}",
    "main_goal": "{{goal}}",
    "last_onboarding_step": "{{step_name}}"
  }
}
```

#### Ordering and dedupe behavior

Each memory update carries:
- `event_at`
- `dedupe_key`

The runtime guarantees:
- duplicate events are marked `ignored_duplicate`
- stale out-of-order events are marked `ignored_stale`
- invalid worker output does not overwrite effective memory
- invalid worker output does not append a history row

#### Trigger integration

**Core forms and surveys** — use form actions:

1. Open the form or survey configuration
2. Add a form action
3. Select job type `llm_memory_update`
4. Optionally select specific rule keys (blank = auto-match by `source_type`)
5. Optionally override execution mode, field mapping, storage mode, or prompt version

Form-action job config example:

```json
{
  "job_type": "llm_memory_update",
  "memory_rule_keys": "sleep_form_finished",
  "run_async": true,
  "force_storage_mode": "",
  "execution_mode": "",
  "field_mapping": "",
  "prompt_version_override": 0
}
```

**llmChat with data saving enabled** — use a form action on the generated `llmChat_*` data table, same as above.

**llmChat with data saving disabled** — configure the section-level `memory_rule_keys` field:

```
sleep_form_finished, general_chat_update
```

When `memory_rule_keys` is set, only the listed rules fire. When empty, rules are matched by `source_type = llm_chat_form_submit`.

**Login** — no configuration needed. Rules with `source_type = login` fire automatically after successful login. Example rule:

```json
{
  "key": "login_tracker",
  "label": "Login Activity",
  "enabled": true,
  "source_type": "login",
  "execution_mode": "direct_mapping",
  "field_mapping": {
    "last_login_time": "{{login_time}}",
    "last_known_name": "{{user_name}}"
  }
}
```

**Profile name change** — no configuration needed. Rules with `source_type = profile_name_change` fire automatically. The payload includes `old_name` and `new_name`.

#### Using memory in prompts (data_config)

Memory is never injected automatically. Load it explicitly through `data_config` on any style field:

```json
[
  {
    "table": "llm_memory",
    "retrieve": "last",
    "current_user": true,
    "map_fields": [
      { "field_name": "memory_text", "value": "memory_text" },
      { "field_name": "memory_json", "value": "memory_json" },
      { "field_name": "preferred_tone", "value": "preferred_tone" }
    ]
  }
]
```

Then reference the mapped values in any prompt or content field:

```
Current user memory summary: {{memory_text}}
Preferred tone: {{preferred_tone}}
```

This works in `conversation_context`, LLM script prompts, `llmResponse` templates, and any other field that supports `data_config` interpolation.

#### Recommended admin setup

1. Enable global memory on `sh_module_llm`.
2. Open the dedicated `LLM Memory` page.
3. Create one or more rules in the `Rules` tab.
4. Edit the rule prompt in Prompt Lab from that page.
5. Choose the execution mode per rule:
   - `llm_summarize` for free text, complex surveys, and conversational input
   - `direct_mapping` for simple field-to-field writes (no LLM call)
6. For core forms and surveys, add a `llm_memory_update` form action and select the rule key.
7. For `llmChat` without data saving, configure `memory_rule_keys` on the section.
8. Load memory explicitly through `data_config` in prompts or content (see above).
9. Use the dedicated memory page tabs to:
   - manage rules
   - inspect derived write sources
   - browse per-user memory snapshots and history
   - re-run rules, rebuild from history, or manually edit memory

### Adding a Chat to a Page

1. In the CMS, create or edit a page
2. Add a new section with style **`llmChat`**
3. Configure the section fields (model, context, features)
4. Set ACL permissions for the page
5. Save and visit the page

### Adding an LLM Response Display

1. Add a section with style **`llmResponse`** to any page
2. Configure `data_config` to point to a dataTable containing LLM results
3. Use `{{field.path}}` syntax in the template to interpolate values from the response JSON
4. Optionally enable `enable_editing` for inline editing

### Using the Admin Console

Navigate to **Admin > Modules > LLM Admin Console** to:
- Browse all user conversations with date, user, and section filters
- View messages with validation status and sent context
- Inspect the full API payload for any message
- Block or unblock conversations

## Components

### llmChat (Style)

The main chat interface. Renders a React application with:
- Conversation sidebar (toggleable)
- Message list with markdown rendering
- Message input with file upload and voice recording
- Structured response rendering (forms, suggestions, media, progress)

### llmResponse (Style)

A display component for showing LLM script results. Supports:
- `{{field.path}}` interpolation from JSON data
- Optional inline editing
- Automatic loading overlay during async processing

### moduleLlmAdminConsole (Module)

Admin-only page for monitoring all LLM conversations across users and sections.

### moduleLlmScript (Module)

Admin page for managing reusable LLM prompt scripts with CRUD operations, testing, and job scheduler integration.

## LLM Scripts

Scripts are reusable prompt templates that can be executed programmatically or via the job scheduler.

### Creating a Script

1. Navigate to **Admin > Modules > LLM Scripts**
2. Click "New Script"
3. Configure:
   - **Name** — Display name
   - **Script** — Prompt template with `{{variable}}` placeholders
   - **Data Config** — JSON configuration for resolving variables from dataTables
   - **Test Variables** — JSON key-value pairs for testing
   - **Async** — Whether to execute asynchronously
   - **Model/Temperature/Max Tokens** — Override defaults

### Script Execution

Scripts can be triggered:
- **Manually** — Via the "Test" button in the scripts UI
- **Via Job Scheduler** — Assign a script as a scheduled job action (`llm_script` job type)
- **Via Callback** — Through the `CallbackLlm.php` endpoint for async results

Each script maintains a single conversation in `llmConversations` (linked via `id_llm_scripts`). Successive executions append messages to the same conversation.

## Architecture

```
sh-shp-llm/
├── server/
│   ├── component/
│   │   ├── LlmHooks.php                  # Hook implementations
│   │   ├── style/llmChat/                 # Chat MVC component
│   │   ├── style/llmResponse/             # Response display component
│   │   ├── moduleLlmAdminConsole/         # Admin console MVC
│   │   └── moduleLlmScript/               # Scripts manager MVC
│   ├── service/
│   │   ├── LlmService.php                 # Core: conversations, messages, API calls
│   │   ├── LlmResponseService.php         # Schema validation, retry, safety
│   │   ├── LlmContextService.php          # Context building for API calls
│   │   ├── LlmScriptService.php           # Script CRUD and execution
│   │   ├── LlmAdminService.php            # Admin operations
│   │   ├── LlmDangerDetectionService.php  # Safety detection and blocking
│   │   ├── LlmFileUploadService.php       # File handling
│   │   ├── LlmSpeechToTextService.php     # Whisper transcription
│   │   ├── LlmDataSavingService.php       # Form data persistence
│   │   ├── LlmProgressTrackingService.php # Topic progress tracking
│   │   ├── provider/                      # Provider abstraction layer
│   │   └── ...                            # Additional services
│   ├── db/v1.0.0.sql                      # Database schema
│   ├── callback/CallbackLlm.php           # Async callback endpoint
│   └── constants/LlmResponseSchema.php    # Schema loader
├── react/src/                             # React + TypeScript frontend
├── schemas/llm-response.schema.json       # Response JSON schema
├── examples/                              # 17 conversation context templates
├── templates/                             # Categorized prompt templates
├── doc/                                   # Detailed documentation (21 guides)
├── gulp/                                  # Build system
├── css/ext/                               # Built CSS
└── js/ext/                                # Built JavaScript bundles
```

### Service Hierarchy

The core `LlmService` handles conversations, messages, rate limiting, and API calls. Specialized services handle specific concerns:

- **LlmResponseService** — JSON schema validation, retry logic, safety context injection
- **LlmContextService** — Assembles the full message array sent to the API
- **LlmScriptService** — Script lifecycle and execution
- **Provider layer** — `LlmProviderRegistry` resolves `BaseProvider` subclasses (`GpuStackProvider`, `BfhProvider`) based on the configured URL

**Memory services** (v1.2.0):

- **LlmMemoryConfigService** — Module-level configuration loader and rule registry
- **LlmMemoryStorageService** — DataTable initialization, read/write with dedupe and stale ordering guards
- **LlmMemoryTriggerService** — Source-agnostic payload normalization and rule dispatching
- **LlmMemoryUpdateService** — Direct mapping and LLM-summarization execution (async via `llm_memory_worker.php`)
- **LlmMemoryAdminService** — Admin UI data (overview, user list, history, manual actions)

### Database Schema

Four tables are created:

- **`llmConversations`** — Conversation records with user, section, model config, soft-delete, and blocking state
- **`llmMessages`** — Individual messages with role, content, attachments, tokens, sent context, validation status, and full request payload
- **`llmConversationProgress`** — Topic coverage tracking per conversation
- **`llm_scripts`** — Reusable prompt templates with execution configuration

### Response Schema

All LLM responses are validated against `schemas/llm-response.schema.json`:

```json
{
  "type": "response",
  "safety": { "is_safe": true, "danger_level": null, ... },
  "content": { "text_blocks": ["..."], "form": null, "suggestions": [...] },
  "progress": { "percentage": 60, "current_topic": "..." },
  "metadata": { "model": "...", "tokens_used": 150 }
}
```

## Development

### Building Assets

```bash
cd server/plugins/sh-shp-llm/gulp

npm install       # Install dependencies (first time only)
npm run build     # Production build
npm run watch     # Development mode with file watching
```

### React Development

```bash
cd server/plugins/sh-shp-llm/react

npm install       # Install dependencies
npm run dev       # Development build with HMR
npm run build     # Production build (all configured entry points)
```

The React app has multiple entry points configured via separate Vite configs:
- `vite.config.ts` — Chat UI (`LLMChat.tsx`)
- `vite.admin.config.ts` — Admin console (`admin.tsx`)
- `vite.scripts.config.ts` — Scripts manager (`scripts.tsx`)
- `vite.memory.config.ts` - Dedicated memory manager (`memory.tsx`)

### Supported Providers

| Provider | Base URL Pattern | Notes |
|----------|-----------------|-------|
| GPUStack | `*/v1` | Standard OpenAI-compatible API |
| BFH Inference | `*/api/v1` | Enhanced with `reasoning_content` |
| Any OpenAI-compatible | `*/v1` | Uses GPUStack provider |

The provider is auto-detected from the selected server's `base_url`. To add a new provider, implement `LlmProviderInterface` and register it in `LlmProviderRegistry`.

### Tested Models

| Category | Models |
|----------|--------|
| Text | qwen3-vl-8b-instruct, gpt-oss-120b, deepseek-r1-0528-qwen3-8b, beechat-v3-gpt-oss |
| Vision | internvl3-8b-instruct, qwen3-vl-8b-instruct |
| Code | qwen3-coder-30b-a3b-instruct |
| Audio (STT) | faster-whisper-large-v3, whisper-large-v3, whisper-medium, whisper-small |

## Troubleshooting

| Symptom | Possible Cause | Solution |
|---------|---------------|----------|
| Chat not loading | Missing JS bundle | Run `npm run build` in `gulp/` |
| "No models available" | Wrong API URL or key | Verify entries in `llm_api_keys` (base URL + API key) in module config |
| API timeout | Slow model or network | Increase `llm_timeout` |
| File uploads failing | Directory permissions | Ensure `upload/` directory is writable by PHP |
| Speech-to-text not available | Missing config | Enable `enable_speech_to_text` AND select a `speech_to_text_model` |
| Schema validation failures | Model not following schema | Check admin console payload inspector; consider a more capable model |
| Blank admin console | JS not built | Run `npm run build` in `gulp/` |

Check SelfHelp logs and `data/clockwork` for detailed error traces.

## Documentation

Detailed guides are available in the `doc/` folder:

| Document | Description |
|----------|-------------|
| [architecture.md](doc/architecture.md) | System design, data flow, service interactions |
| [configuration.md](doc/configuration.md) | Complete field reference for all settings |
| [conversation-context.md](doc/conversation-context.md) | How to write effective system instructions |
| [api-reference.md](doc/api-reference.md) | All controller actions and request/response formats |
| [response-schema.md](doc/response-schema.md) | JSON response schema specification |
| [provider-abstraction.md](doc/provider-abstraction.md) | Multi-provider system and how to add new providers |
| [danger-word-detection.md](doc/danger-word-detection.md) | Safety detection setup and behavior |
| [speech-to-text.md](doc/speech-to-text.md) | Whisper integration and configuration |
| [file-naming-conventions.md](doc/file-naming-conventions.md) | Upload file naming and directory structure |
| [message-validation-tracking.md](doc/message-validation-tracking.md) | Validation status and payload debugging |
| [prompt-lab-user-guide.md](doc/prompt-lab-user-guide.md) | Prompt versions, playground, datasets, and evaluations for editors |
| [prompt-datasets-user-guide.md](doc/prompt-datasets-user-guide.md) | How to build reusable benchmark datasets |
| [prompt-dataset-ai-import-user-guide.md](doc/prompt-dataset-ai-import-user-guide.md) | How to bulk import dataset cases from pasted text using AI |
| [prompt-replay-import-guide.md](doc/prompt-replay-import-guide.md) | How to turn real submitted data into replay datasets |
| [prompt-lab-payload-shapes.md](doc/prompt-lab-payload-shapes.md) | Normalized dataset case payload formats |
| [prompt-evaluator-authoring-guide.md](doc/prompt-evaluator-authoring-guide.md) | How to add or extend evaluators |
| [prompt-lab-developer-guide.md](doc/prompt-lab-developer-guide.md) | Prompt registry, replay, and evaluation architecture |
| [prompt-dataset-ai-import-developer-guide.md](doc/prompt-dataset-ai-import-developer-guide.md) | Backend/frontend architecture for AI-assisted dataset import |
| [prompt-lab-migration-notes-v1.1.0.md](doc/prompt-lab-migration-notes-v1.1.0.md) | Migration details for prompt-lab and dataset/eval schema |
| [form-data-saving.md](doc/form-data-saving.md) | How form responses are saved to dataTables |
| [progress-tracking-system.md](doc/progress-tracking-system.md) | Topic coverage and progress tracking |
| [floating-chat-button.md](doc/floating-chat-button.md) | Floating chat button setup |
| [media-rendering.md](doc/media-rendering.md) | Media content in responses |
| [user-guide-for-cms-editors.md](doc/user-guide-for-cms-editors.md) | CMS editor guide for non-developers |

## License

Mozilla Public License, v. 2.0 — see [LICENSE](https://mozilla.org/MPL/2.0/).

