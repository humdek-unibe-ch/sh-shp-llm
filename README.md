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
| SelfHelp | v7.8.0+ | Core CMS framework; **v7.12.0+** required for `llmFormLogList` interviews |
| PHP | 8.2+ | With **cURL** and **GD** extensions |
| PHP `exif` | optional* | Bundled PHP extension (`exif_read_data`); needed for LLM-script image EXIF (datetime, camera, GPS) as prompt text |
| MySQL | 8.0+ | InnoDB, utf8mb4 |
| Node.js | 16+ | For building frontend assets |
| LLM API | Any | GPUStack, BFH, or any OpenAI-compatible endpoint |

\*Vision image attach/resize works with GD alone. Without `exif`, script image runs still work; EXIF metadata is simply omitted from the prompt. Enable with `extension=exif` in `php.ini` (Windows) or `php-exif` package on Linux. Check: `php -m | grep -i exif`.

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
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.3.0.sql
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.4.0.sql
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.5.0.sql
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/v1.6.0.sql
```

This creates core plugin tables and applies v1.1.0 prompt-lab extensions (prompt registry, datasets, and evaluations), the v1.2.0 memory-key registry for multi-key memory rules, and the v1.6.0 interview additions (`llmFormLogList` style registration, `form_interview` conversation source, and the `llmConversations` columns `id_dataRows`, `completed_at`, `context_key`, `state`). The migrations are rerunnable (`INSERT IGNORE` / `CREATE TABLE IF NOT EXISTS` pattern).
It also re-applies critical prompt-lab indexes and unique keys through the shared migration helper procedures so interrupted runs can be safely replayed. `v1.6.0.sql` requires SelfHelp core **v7.12.0** (the plugin style inherits the core `formUserInputLogList` seams added in that release; the `llmFormLogList` style itself is registered by this migration). Run plugin migrations in order. After running migrations, clear the SelfHelp cache (styles, fields, hooks, lookups are cached). The same core release provides `CORS_ALLOWED_ORIGINS`, the exact-match list of cross-origin clients that the chat and form endpoints trust without a CSRF token ([CSRF and cross-origin clients](#csrf-and-cross-origin-clients)).

**Optional (SurveyJS):** if the SurveyJS plugin (`sh-shp-survey_js`) is installed after the LLM plugin `v1.5.0` migration, also run `server/plugins/sh-shp-llm/server/db/optional/enable_surveyjs_llm_memory_rules.sql` once and clear the SelfHelp cache. It enables the memory rule selection on `surveyJS` sections (see [doc/global-memory-user-guide.md](doc/global-memory-user-guide.md)). The file is idempotent.

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
- `js/ext/llm-form-interview.umd.js` — `llmFormLogList` interview workspace

### 4. Configure the Module

1. Go to **Admin > Pages** and find the auto-created module page (`sh_module_llm`)
2. Set the required fields:
   - `llm_api_keys` — API key manager with one or more servers (`name`, `base_url`, `api_key`); see [API Key Protection](#api-key-protection)
   - `llm_default_model` — Default model name (e.g., `qwen3-vl-8b-instruct`)
3. Optionally adjust `llm_timeout`, `llm_max_tokens`, and `llm_temperature`
4. Optionally copy `server/service/globals_untracked.default.php` to `server/service/globals_untracked.php` for per-installation values (`LLM_SECRET_KEY`, `LLM_ALLOWED_API_HOSTS`). The file is **optional** - the plugin installs and runs without it (defaults apply), the CLI workers and CI need none, and environment variables of the same names work as well. `globals_untracked.php` is ignored by git; never commit it.

## Configuration

### Module-Level Settings

These apply globally to all chat sections and are configured on the module page.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_api_keys` | json-llm-api-keys | `[]` | Array of server configs: `[{id, name, base_url, api_key}]`. `api_key` is stored encrypted (`enc:v1:...`) or as an `env:LLM_NAME` reference and is never sent to a browser ([API Key Protection](#api-key-protection)) |
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

### API Key Protection

Saved API keys are never shown again: the settings console and the CMS field
editor display `Key: …1234` (the last four characters, only for keys of 12 or
more characters) and an empty key field on save means "keep the current key".
Keys are protected in one of two ways:

| Option | How |
|--------|-----|
| Encrypted at rest | Set the environment variable (or PHP constant, e.g. in `globals_untracked.php`) `LLM_SECRET_KEY` to a secret of at least 16 characters. New and changed keys are then stored as `enc:v1:...` (AES-256-GCM). Keys saved earlier in plain text keep working; **Encrypt stored keys now** on the settings page converts them. If the secret is lost or changed, encrypted keys cannot be read any more - enter them again. |
| Environment reference | Type `env:LLM_API_KEY_MAIN` instead of a key. Nothing secret is stored; the server reads the variable at run time. Names must start with `LLM_` (and cannot be `LLM_SECRET_KEY`). |

Base URLs must use `https` (plain `http` is only accepted for `localhost`) and
carry no credentials, query string or fragment. A stored key is only kept
while the server address (scheme, host, port) is unchanged; after changing the
host enter the key again. Set `LLM_ALLOWED_API_HOSTS` - a PHP constant (array
or comma separated string) or an environment variable - to pin the hosts the
keys may be sent to, e.g. `gpustack.example.com,*.models.example.org`.
Numeric settings are validated server side: temperature 0-2, max tokens
1-200000, timeout 1-600 seconds. Changing settings needs the `update` ACL on
the module page and a POST with the session CSRF token; every change is
written to the transaction log (names of the changed settings, never values).

### Security Model

| Topic | Behavior |
|-------|----------|
| Access | Every endpoint checks the SelfHelp ACL of the page that carries it before it does anything. The Prompt Lab decides access for a CMS section from the pages that really show it (read from the database); a `page_id` sent by the browser can only pick one of them, and a section that no page shows is refused. |
| CSRF | One session token (`LlmCsrfService`) is handed to each page and sent back as `csrf_token` or the `X-CSRF-Token` header. Admin console, settings, Prompt Lab, scripts, memory and interview mutations always require it. The chat and the LLM form styles (`llmChat`, `llmFormRecord`, `llmFormLog`) also accept a request without the token, because the native app has none, when it does not come from another website; the three cases, the core setting they read (`CORS_ALLOWED_ORIGINS`) and what to do about the session cookie are under [CSRF and cross-origin clients](#csrf-and-cross-origin-clients). Anything else gets a 403. |
| Rate limits | Chat and form requests: `LLM_RATE_LIMIT_REQUESTS_PER_MINUTE` per user (guests: per session, plus a site-wide ceiling). Speech-to-text: 6 recordings per minute per user (`LLM_RATE_LIMIT_SPEECH_REQUESTS_PER_MINUTE`, answer 429); the audio type is decided from the file content and only valid audio uses up the budget. |
| Output | Markdown from model output, participant messages and stored prompts is sanitized before it is rendered (no scripts, frames, forms, event handlers or `javascript:` URLs); the PHP `llmResponse` style escapes HTML. A Markdown image is still fetched from its host by the participant's browser. |
| Data exposure | The admin console API never returns the interview `state` (draft answers, approval receipt, tokens), also not to users with only `select` access. Failed provider calls are kept for admins as unvalidated `[API ERROR]` messages and are never part of a participant's message list. |
| Replay import | Prompt Lab dataset import (playground runs, form submissions, conversation messages, script fixtures) reads only records that the candidate list shows for the prompt's own owner. The ids in an import request come from the browser, so the import applies the same rule as the list (`LlmDatasetIngestionService`): a conversation, run or script that belongs to another section, script, memory rule or owner type is refused, whatever id is sent. See [prompt-replay-import-guide.md](doc/prompt-replay-import-guide.md). |
| Errors | Exceptions (provider answers, SQL, paths) are logged on the server, always through `LlmErrorReporter`: one line with the context, the exception class, its message and `file:line` - a database error as its SQLSTATE code only (the driver text can carry table and column names) unless `DEBUG` is on - and never a stack trace; a client gets a generic message. `tests/LlmConventionsTest.php` fails when plugin code hands an exception text to `error_log()` directly. Only texts written for participants (rate limit, validation, "conversation not found") pass through. A chat turn that produced no usable answer (provider failure, reply that never matches the schema) is answered with the fixed `notice` only (as `notice` and `message`, no `structured` block) that the chat shows as an error banner; neither the model's text nor the reason is returned. |
| Transactions | Plugin code opens, commits and rolls back transactions only through `LlmTransaction` (`begin` / `commit` / `rollback`); `tests/LlmConventionsTest.php` fails on a direct `begin_transaction()`, `commit()` or `rollback()` call anywhere else in the plugin. `LlmTransaction::rollback()` rolls the connection back itself and clears only the cache types the caller names; core's `BaseDb::rollback()` would drop the whole application cache, so a rejected request cannot cold-start the CMS. Memory updates that follow an interview approval or a form action are handed to `LlmTransaction::afterCommit($db, $task)`: they start when the outermost plugin transaction has committed, are dropped when it rolls back, and start at once when no transaction is open - nothing has to be opted into. A transaction that was opened by other code (core, another plugin) cannot be observed, so a task queued inside it is dropped and logged instead of being started early. Core's `UserInput::save_data()` keeps its own behavior: when it fails it rolls back through `BaseDb::rollback()`, which also clears the whole cache (the cache refills; no data is affected). |

### CSRF and cross-origin clients

The chat and LLM form endpoints (`llmChat`, `llmFormRecord`, `llmFormLog`) are also called by the native app, which has no CSRF token to send. So they accept a request without the token in exactly three cases, decided from the request alone (`LlmCsrfService::verifyRequestOrTrustedClient()`):

| Request | Why it is accepted |
|---------|--------------------|
| `Sec-Fetch-Site: same-origin` | A page of this site, also one whose token went stale (for example after a re-login in another tab). |
| Neither `Sec-Fetch-Site` nor `Origin` | Not a browser: the native app's HTTP client. |
| An `Origin` that is exactly one entry of core's `CORS_ALLOWED_ORIGINS`, while core `CORS` is `true` | A client the installation itself allows to call it across origins, the mobile web preview (for example `https://localhost:8100`). |

The third test is a strict comparison of the whole `Origin` string: scheme, host and port must match one entry. There is no prefix, substring, wildcard or case folding, and `Origin: null` never matches, so `https://selfhelp.philhum.unibe.ch.evil.example` is not `https://selfhelp.philhum.unibe.ch`. What core writes into `Access-Control-Allow-Origin` is never consulted: it is a response header of code this plugin does not own. Everything else - in particular `Sec-Fetch-Site: cross-site` from an origin that is not listed - needs the token and otherwise gets a 403. The admin console, settings, Prompt Lab, scripts, memory and interview endpoints never use this gate; they always need the token.

What the installation has to provide (this is core configuration, not plugin configuration):

- **`CORS_ALLOWED_ORIGINS`** (SelfHelp core v7.12.0 or later): the list of cross-origin clients, one exact origin per entry. The default is in core's `server/service/globals.php`; replace it for one installation with `define('CORS_ALLOWED_ORIGINS', array('https://localhost:8100', ...));` in core's `globals_untracked.php`. Core's `Selfhelp::cors()` approves the same list, so a client the plugin trusts is a client core answers. A core without the constant, or with `CORS` off, trusts no cross-origin client: such a client has to send the token.
- **Session cookie**: web sessions are `SameSite=Lax`, so another website's request does not carry the session at all. Core gives the sessions opened by the mobile web preview (`mobile_web`, `Login::init_session()`) `SameSite=None; Secure` on purpose, because the preview runs on another site. For those sessions the exact origin list is what keeps other websites out, so list only the origins you run. A browser too old to send either `Sec-Fetch-Site` or `Origin` cannot be told apart from the native app; that only matters for a `SameSite=None` session. Do not relax `SameSite` for the normal web login.

### Database engine

The plugin tables are created without an `ENGINE` clause, so they use the server's default engine (InnoDB on a standard MySQL 8 install). The interview approval, the revision compare-and-swap of the conversation state and the other multi-statement writes rely on transactions; on a MyISAM table a rollback undoes nothing. After the migration, check that every plugin table is InnoDB:

```sql
SELECT table_name, engine FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'llm%' AND engine <> 'InnoDB';
```

Convert each table that is listed with `ALTER TABLE <table_name> ENGINE=InnoDB;` (back up first).

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

For a user-facing setup guide, see [doc/global-memory-user-guide.md](doc/global-memory-user-guide.md).

Memory is:
- global per user, not per `llmChat` section
- updated from forms, surveys, `llmChat` fallback submissions, login, and profile-name changes
- stored in standard SelfHelp `dataTables`
- consumed explicitly through `data_config`

#### Module fields

Configure these defaults on the `sh_module_llm` page:
- `llm_memory_enabled`
- `llm_memory_storage_mode`

Manage memory rules, write sources, and user memory operations on the dedicated `moduleLlmMemory` admin page.

#### Storage modes

- `record`: write only the current snapshot table
- `log`: write only the history table; effective memory resolves from latest applied history row
- `both`: write current snapshot and history; recommended default

#### Rule example

Memory rules are stored in the normalized `llm_memory_rules` table and edited through the dedicated memory page. Rules are identified by integer `rule_id`.

```json
{
  "label": "Sleep Form Finished",
  "enabled": true,
  "source_type": "form_action_submit",
  "source_match_json": {
    "table_name": "0000001234"
  },
  "trigger_types_json": ["finished"],
  "storage_mode_override": "both",
  "llm_model": "",
  "llm_temperature": 0.2,
  "llm_max_tokens": 1200
}
```

Prompt Lab ownership is derived from the rule row itself:
- `owner_type = llm_memory_rule`
- `owner_id = <rule id>`
- `prompt_slot = memory_rule`

#### Auto-injected context

The system automatically injects the following into the LLM call:
- **Current memory** -- the user's existing memory_text and memory_json
- **Submitted data** -- all form field values or event payload
- **Data Config results** -- any extra data sources configured on the rule
- **User language** -- detected from the session; the LLM writes memory in the user's language
- **Memory key scope** -- the LLM is told which key is being updated and instructed to only modify facts related to the current submission

The admin prompt (from Prompt Lab or the default) only needs to contain instructions, e.g. "Extract the user's hobbies from the form data." The system prompt handles merge/append/replace logic and JSON output format.

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

**Core forms and surveys (v1.5.0)** — select the memory rules directly on the style, no form action needed:

- Field `llm_memory_rule_ids` (comma-separated `llm_memory_rules.id`, multi select in the CMS) on `formUserInput`, `formUserInputRecord`, `formUserInputLog`, `formUserInputLogList` (v1.6.0), `llmFormRecord`, `llmFormLog`, `llmFormLogList` (v1.6.0) and `surveyJS`. Empty = off. Only `form_action_submit` rules are offered.
- Hooks registered by `v1.5.0.sql`: `FormUserInputModel::save_user_input` / `update_user_input` and `SurveyJSModel::save_survey` → `LlmHooks::onFormSavedMemoryRules` / `onSurveySavedMemoryRules`. The original save runs first and is never changed. The rules are queued only after a saved `finished` submission of a logged-in (non-guest) user while memory is enabled. The hooked model is the submitting section, so the selection always belongs to the section that was submitted.
- Execution reuses the existing pipeline: `LlmMemoryTriggerService::normalizeFormActionPayload()` + `dispatchForRuleIds(..., async = true)`. The request only starts one detached worker per rule and memory key; missing or disabled rules are skipped; dedupe, ordering and failure handling are unchanged. Errors in the hook are logged (`LLM Memory: form rule trigger failed` / `survey rule trigger failed`) and never change the save result.
- `LlmMemorySubmissionContextService` builds the labeled snapshot inside the save request (the worker has no session language and no live form model). `fields` stays the flat name → value map; `readable_text` carries the JSON context (question labels, readable choice texts) that `LlmMemoryUpdateService` adds to the prompt as `## Submission Context` for `form_action_submit` payloads.
- SurveyJS is a separate plugin. If it is installed after this plugin, run `server/db/optional/enable_surveyjs_llm_memory_rules.sql` once and clear the SelfHelp cache (see the user guide).
- Form actions with `llm_memory_update` keep working and run in addition; a rule selected in both places runs once per path.

**Core forms and surveys with form actions** — alternatively use form actions:

1. Open the form or survey configuration
2. Add a form action
3. Select job type `llm_memory_update`
4. Optionally select specific rule keys (blank = auto-match by `source_type`)
5. Optionally override storage mode or prompt version

Form-action job config example:

```json
{
  "job_type": "llm_memory_update",
  "memory_rule_keys": "sleep_form_finished",
  "run_async": true,
  "force_storage_mode": "",
  "prompt_version_override": 0
}
```

**llmChat with data saving enabled** — use a form action on the generated `llmChat_*` data table, same as above.

**llmChat with data saving disabled** — select rules in the section field `memory_rule_ids` (multi-select of `llm_chat_form_submit` rules, v1.6.0). When empty, rules are matched by `source_type = llm_chat_form_submit`.

**Login** — no configuration needed. Rules with `source_type = login` fire automatically after successful login. Example rule:

```json
{
  "key": "login_tracker",
  "label": "Login Activity",
  "enabled": true,
  "source_type": "login"
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
5. For core forms and surveys (including `formUserInputLogList` / `llmFormLogList`), select the rules in `llm_memory_rule_ids` on the section (or add a `llm_memory_update` form action and select the rule key).
7. For `llmChat` without data saving, select rules in `memory_rule_ids` on the section (create rules with source `llm_chat_form_submit`).
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

Anonymous visitors (guests) share one SelfHelp account, so a guest's
conversations belong to the visitor's browser session only: every new guest
starts with an empty chat and never sees another guest's conversations. Guests
get no file upload, no speech input and no saved records or memories, and the
`llmFormRecord` / `llmFormLog` / `llmFormLogList` styles ask them to log in.

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

The page ACL decides what a group can do: `select` browses conversations and
messages, `update` blocks / unblocks conversations and also shows the sent
context and request payload of each message, `delete` deletes conversations.
Changes are POST only with the session CSRF token, and the transaction log
names the acting admin. The `v1.6.0.sql` migration grants `update` and
`delete` to the admin group; grant them to other moderator groups in the CMS.

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

### llmFormLogList (Style, v1.6.0+)

A `formUserInputLogList` extension that interviews participants
conversationally against the CMS child fields and saves the record through
the inherited form write path only on explicit approval. Drafts stay in a
scoped conversation state (never in `dataRows`). The plain page URL shows a
launcher card above the list; `?record=new&sid=<section>` (or
`?record=<id>&sid=<section>`) opens a two-pane workspace - in the page or,
with `show_form_in_modal`, in a real modal window over the list: the
answers (stepper, cards, list or none) that grow with their content and
highlight the current question, plus a Save button, next to the chat (chat
left, right, top or bottom, or chat only) - no floating chat bubble.
The chat uses the same header, bubbles and composer as `llmChat` (the
composer as one rounded, auto-growing field); the
default look is plain Bootstrap 4.6 and is themed through `--llm-iv-*`
custom properties and the `llm_chat_appearance` JSON; the list shows the
core's default look in both layouts (table and cards), themed by the same
properties. Coach questions
with fixed choices come as quick-reply buttons, `llm_interview_confirm`
decides whether saving first shows the record (new entries) and/or a
before/after view of the changes (saved records) or saves at once,
`llm_use_user_language` (on by default) makes the coach answer in the
participant's current UI language; uncheck it so the author context alone
decides. `enable_speech_to_text` adds a Whisper microphone. The form can be shared across pages with a CMS reference
section inside a loop, and combined with a hidden `status`, quick actions
and `history_log` into an archive/restore pattern. The field `extra_params`
(`module_id=&list_status=active`, core field of `formUserInputLogList`) makes
the section accept URL parameters: they are `{{__name__}}` placeholders in
its fields (filter, texts, button URLs), partition the unsaved drafts and pin
a form field of the same name - module filters are plain `button` sections
whose URL carries the parameters. See
[doc/llm-form-interview.md](doc/llm-form-interview.md).

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

Each script maintains a single conversation in `llmConversations` (linked via `id_llm_scripts`). Successive executions append messages to the same conversation.

**Images (v1.5.0+):** named form/survey variables that contain base64 data-URLs, SurveyJS file JSON, or `?file_path=` uploads are materialized under `upload/{userId}/scripts/{scriptId}/` and sent as vision attachments (use a vision model). Bookkeeping fields such as `_json` are skipped. Scripts Manager option **Combine images into collage** (`llm_scripts.combine_images`): when on, 2+ images become one labeled collage (`Image 1…N`) with a layout + EXIF guide in the prompt; when off (default), only the **first 4** images are sent. Optional PHP **`exif`** reads datetime/camera/GPS from the original files (API image bytes are re-encoded and lose EXIF).

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
- **LlmMemorySubmissionContextService** — Labeled snapshot (field / question labels, readable choice texts) of a finished form or SurveyJS submission (v1.5.0)
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
| Admin console: block / unblock / delete answer 403 | Missing `update` / `delete` ACL, or the page was opened before the upgrade (no CSRF token yet) | Grant the ACL on the console page; reload the page |
| Server marked "cannot be read - enter the key again" | The key was stored encrypted and `LLM_SECRET_KEY` is missing or changed | Restore the secret, or enter the key again ([API Key Protection](#api-key-protection)) |
| Settings save is rejected (422) | A value failed validation (non-https URL, host not in `LLM_ALLOWED_API_HOSTS`, out-of-range number) | Fix the listed fields; nothing was stored |
| Chat / form request answers 403 "Security check failed" | The request came from another website, a custom client sends browser headers (`Origin`) without the token, or the mobile web preview runs on an origin that is not in core's `CORS_ALLOWED_ORIGINS` (or `CORS` is off) | Reload the page; a custom client sends `X-CSRF-Token` (the native app sends no `Origin`); add the exact origin (scheme, host, port) to `CORS_ALLOWED_ORIGINS` in core's `globals_untracked.php` ([CSRF and cross-origin clients](#csrf-and-cross-origin-clients)) |
| Voice input answers 429 | More than 6 recordings in a minute | Wait a minute; the limit is `LLM_RATE_LIMIT_SPEECH_REQUESTS_PER_MINUTE` |
| Prompt Lab answers "Access denied" or "Missing page context" | No ACL on a page that shows the section, or the section is not placed on any page | Grant the ACL on the page that shows the section, or place the section on a page |
| Interview approval fails but plugin rows were changed | The `llm*` tables are not InnoDB, so the rollback cannot undo them | [Database engine](#database-engine) |

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
