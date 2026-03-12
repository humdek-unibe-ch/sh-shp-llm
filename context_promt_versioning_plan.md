# Context Prompt Versioning And Playground Plan

## Goal

Implement a Langfuse-like prompt registry inside `sh-shp-llm` so prompt-like content can be:

- versioned
- diffed
- tested in a playground
- reverted safely
- audited by user, date, and change history
- reused later by other plugins, especially `sh-shp-llm_therapy_chat`

Important correction:

- language and gender handling for field-backed prompts is already owned by the core SelfHelp CMS through `sections_fields_translation`
- this feature must **reuse** that system, not replace it

This plan is implementation-focused but does not include code yet.

## Current State

### Field-backed prompts

- `conversation_context` is a normal CMS section field on `llmChat`
- `llm_context` is a normal CMS section field on `llmFormRecord` and `llmFormLog`
- the active values are stored by core SelfHelp in `sections_fields_translation`
- translation fallback and `display` behavior are already handled by core CMS
- `sections_fields_translation` already has a `meta` column, which is intended for custom-field extra state
- runtime code reads active values through `get_db_field(...)`

### Script-backed prompts

- Scripts are stored in `llm_scripts`
- the active prompt text is stored in `llm_scripts.script`
- script config such as `model`, `temperature`, `max_tokens`, `data_config`, and `test_variables` lives beside the prompt in the same row
- the scripts module already has a React editor and a test flow
- this existing scripts UI should still be improved and should reuse the new versioning + playground system instead of staying separate

### Core conventions we must follow

- field translations remain in core CMS tables
- section field translation rows are keyed by `id_sections`, `id_fields`, `id_languages`, `id_genders`, but prompt versioning will track language only
- linked column naming should follow the existing pattern like `id_users`, `id_sections`, `id_genders`, `id_languages`, `id_llm_scripts`
- enum-like values should use the core `lookups` table when appropriate
- audit must go through `transactions`
- request/page activity is logged by core `user_activity`

## Design Principles

- Do not create a second translation system for field-backed prompts
- Do not break existing runtime code during rollout
- Keep the simple textarea editing experience in CMS
- Use one prompt registry for chat fields, form fields, and scripts
- Make the shared prompt system extensible for `sh-shp-llm_therapy_chat`
- Keep files small and logic separated by responsibility

## Recommended Architecture

Use one central prompt registry with owner adapters.

### Core idea

- introduce a new custom field type, recommended name: `llm_prompt`
- switch `conversation_context` and `llm_context` to that field type
- keep the active field value in `sections_fields_translation.content`
- keep lightweight linkage in `sections_fields_translation.meta`
- keep the active script value in `llm_scripts.script`
- store prompt history, version metadata, and playground references in dedicated plugin tables
- route all testing/building calls through the existing centralized LLM conversation/message logging flow

### Why this is the safest design

- existing models can continue reading `content` and `llm_scripts.script`
- CMS translation fallback stays fully owned by core SelfHelp
- scripts get versioning and playground without losing the current editor flow
- the same base services can later be reused by `sh-shp-llm_therapy_chat`

## Scope For Phase 1

Phase 1 should cover only prompt surfaces that directly affect LLM behavior in this plugin:

- `conversation_context`
- `llm_context`
- `llm_scripts.script`

Planned expansion after that:

- `sh-shp-llm_therapy_chat`
- likely prompt fields there: `conversation_context`, `therapy_draft_context`, `therapy_summary_context`, `therapy_auto_start_context`

## Data Model

Recommended tables:

### 1. `llm_prompt_entries`

Logical prompt identity independent from language.

Important:

- language does **not** belong in this table
- this table represents the owner only
- same owner, different languages should still point to the same entry

Suggested columns:

- `id`
- `id_llm_prompt_owner_types` -> FK to `lookups.id`
- `owner_id` -> section id for field owners, script id for script owners
- `prompt_slot` -> string such as `conversation_context`, `llm_context`, `script`
- `title`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Lookup type:

- `type_code = 'llm_prompt_owner_types'`
- initial `lookup_code` values:
  - `style_field`
  - `llm_script`

Suggested uniqueness:

- unique on `id_llm_prompt_owner_types + owner_id + prompt_slot`

Why language is not here:

- one section field owner can have many language variants
- that variant split belongs in the next table

### 2. `llm_prompt_locales`

Version stream pointer for one owner-language combination.

This table does not replace CMS translation storage.
It only binds one version history to one existing CMS language variant.

Suggested columns:

- `id`
- `id_llm_prompt_entries`
- `id_languages` nullable only for non-translated owners
- `active_version_id`
- `active_version_no`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Suggested uniqueness:

- unique on `id_llm_prompt_entries + id_languages`

Example:

- one `conversation_context` field on one section creates one `llm_prompt_entries` row
- English and German each get their own `llm_prompt_locales` row
- each locale row has its own active version and version chain

### 3. `llm_prompt_versions`

Immutable full snapshots.

Suggested columns:

- `id`
- `id_llm_prompt_locales`
- `version_no`
- `template_raw` longtext
- `template_hash` varchar
- `config_json` longtext nullable
- `metadata_json` longtext nullable
- `variables_schema_json` longtext nullable
- `tags_json` longtext nullable
- `change_note` varchar nullable
- `based_on_version_id` nullable
- `created_by`
- `created_at`

Suggested uniqueness:

- unique on `id_llm_prompt_locales + version_no`

Important design decision:

- store full snapshots, not Git-style deltas

Reason:

- prompts are relatively small
- diff-only chains complicate restore, compare, and debugging
- full snapshots are simpler and safer for v1.1.0
- `template_hash` prevents duplicate versions when content did not actually change

### 4. `llm_prompt_playground_runs`

Optional summary/index table, but not the canonical LLM log.

Canonical request/response audit should still live in:

- `llmConversations`
- `llmMessages`

This table is only for fast prompt-tool UI retrieval and grouping.

Suggested columns:

- `id`
- `id_llm_prompt_entries` nullable
- `id_llm_prompt_locales` nullable
- `id_llm_prompt_versions` nullable
- `id_llmConversations`
- `id_llmMessages_request` nullable
- `id_llmMessages_response` nullable
- `run_mode` -> `playground`, `builder`, `compare`
- `comparison_group_id` nullable
- `variables_json`
- `config_snapshot_json`
- `created_by`
- `created_at`

Recommended:

- `run_mode` lookup-backed column, use the LOOKUP table

### Script table extension

Recommended additions to `llm_scripts`:

- `id_llm_prompt_entries` nullable

Keep `llm_scripts.script` as the active prompt cache in phase 1.

## Field Storage Strategy

For the new `llm_prompt` field type:

- `sections_fields_translation.content` remains the active prompt text
- `sections_fields_translation.meta` stores only prompt linkage and field UI state

Recommended `meta` shape:

```json
{
  "prompt": {
    "entryId": 12,
    "localeId": 34,
    "activeVersionId": 89,
    "activeVersionNo": 6,
    "lastComparedVersionId": 84
  }
}
```

Do not store history in `meta`.
History belongs in the prompt tables.

## CMS Language Strategy

The plan must explicitly follow core CMS language handling.

### Field-backed prompts

- active content stays in `sections_fields_translation.content`
- the selected CMS language determines which field value is being edited
- version history is bound to that same language variant via `llm_prompt_locales`
- we should backfill from raw `sections_fields_translation` rows, not from rendered model output

### Script-backed prompts

- scripts do not currently use the CMS field translation system
- for phase 1, the scripts UI can bind prompt editing to the current admin language and store that `id_languages`
- the schema should already support future multilingual scripts without another DB redesign

## Version Storage Strategy

### Recommendation

Use full snapshot versions, not Git-style stored diffs.

### Why not delta-only storage now

- version restore becomes harder
- compare needs chain reconstruction
- bug recovery is harder
- prompt sizes are usually small enough that full snapshots are acceptable

### Practical safeguards

- add `template_hash`
- only create a new version when text or versioned config changed
- add indexes on locale/version lookups
- if size ever becomes a real problem, archive older prompt versions later

### Diff implementation

- compute diffs on demand
- for UI, Monaco Diff Editor is enough because Monaco is already used in the scripts module
- no extra diff storage is needed

## Configuration Versioning

### What `config_json` means

`config_json` is **not** a new CMS field in phase 1.

It is an internal JSON snapshot stored on a prompt version row, for example:

```json
{
  "model": "gpt-4o",
  "temperature": 0.2,
  "max_tokens": 2048,
  "tags": ["stable", "feedback"],
  "metadata": {
    "playgroundPreset": "strict-short"
  }
}
```

### Why keep it

- it lets us compare prompt versions together with the model settings used at that time
- it helps reproducibility in the playground and audit trail

### What should remain true in phase 1

- current style fields and script columns remain the runtime authority
- `llm_model`, `llm_temperature`, `llm_max_tokens`, and script config stay exactly where they are today
- `config_json` is only a snapshot for version history, diffing, and audit

### Is `config_json` translatable

No, not in phase 1.

Reason:

- model, temperature, and token settings are operational config, not translated content
- translated text remains in the prompt template itself through the CMS field language rows

### What about owners that have no prompt text but still use model/temperature

Keep the current behavior.

- those settings stay in the current fields/columns
- prompt versioning should not force a prompt row just because config exists
- prompt registry applies when we are actually managing prompt-like text

### Version comments

Yes, include them.

- `change_note` on `llm_prompt_versions`
- simple optional comment input in the save UI
- keep it lightweight

## Variable And Template Handling

Use one shared template parser service across field prompts and scripts.

### Rules

- store raw templates with `{{variable}}` placeholders
- auto-detect variables from raw template text
- allow optional `variables_schema_json` for richer playground inputs
- keep compatibility with existing `replace_calced_values(...)` style interpolation

### Playground input behavior

- if a variable schema exists, build typed inputs from it
- if no schema exists, auto-create simple inputs from detected placeholders
- always provide an advanced raw JSON mode

## Shared Owner Adapter Pattern

To avoid duplicating logic, introduce one shared prompt playground/versioning layer with owner-specific adapters.

Recommended adapters:

- `ChatPromptOwnerAdapter`
- `FormPromptOwnerAdapter`
- `ScriptPromptOwnerAdapter`

Each adapter should know:

- how to load the active owner prompt
- how to resolve current config fields
- how to test a draft prompt using the same runtime behavior as production
- how to sync the selected active prompt back into `content` or `llm_scripts.script`

### Why this matters

- `llmChat` testing must respect `LlmContextService`
- `llmForm` testing must respect `LlmFormController` interpolation and prompt composition
- `llm_scripts` testing must respect `LlmScriptService`

## Expansion To `sh-shp-llm_therapy_chat`

This should be planned from the start.

Recommended rule:

- the registry tables and shared React prompt components live in `sh-shp-llm`
- other plugins reuse them through adapters and hooks

First likely therapy plugin prompt targets:

- `conversation_context`
- `therapy_draft_context`
- `therapy_summary_context`
- `therapy_auto_start_context`

This avoids a second prompt-versioning implementation inside the therapy plugin.

## Playground Plan

The playground should be built on the central LLM logging flow, not as a side channel.

### Main mode

- load current draft prompt
- set variables/test data
- override model if needed
- run one test
- inspect rendered prompt, response, raw payload, tokens, and duration

### Model override

- yes, the user should be able to choose a model in the playground even if the style already has a saved model
- this override is only for testing
- it must not overwrite the saved style/script config unless the user explicitly saves normal config

### Multi-model compare

Recommended as a bounded feature:

- phase 1 base playground supports single-model testing
- add optional compare mode if feasible
- compare mode should be limited to 2-3 selected models to avoid cost and clutter
- compare mode is playground-only, never runtime behavior

If compare mode is implemented, log all runs with a shared `comparison_group_id`.

## Prompt Builder Assistant

Add a second tool in the playground called something like:

- `Prompt Assistant`
- or `Build With AI`

### Purpose

The user describes:

- what the prompt should do
- target audience
- tone
- constraints
- output format
- variables they want to use

The assistant returns:

- a suggested prompt template
- optional variable suggestions
- optional tags / notes
- a short explanation of why it is structured that way

### Model handling

- the user chooses which helper model to use
- they can change the model any time
- helper-model choice is separate from the owner's saved runtime model

### Logging

All builder requests should:

- go through `LlmService::callLlmApi(..., log_options)`
- create/request-reuse a dedicated prompt-tool conversation
- store prompt-tool metadata in `llmMessages.sent_context`
- optionally create a `llm_prompt_playground_runs` row pointing to those messages

Recommended `sent_context` additions:

- `prompt_tool`: `playground` or `builder`
- `prompt_owner_type`
- `prompt_owner_id`
- `prompt_slot`
- `prompt_entry_id`
- `prompt_locale_id`
- `prompt_version_id`
- `selected_model`
- `comparison_group_id` nullable

## Central Logging And Audit

### Canonical LLM execution log

All playground and builder requests should use the existing central tables:

- `llmConversations`
- `llmMessages`

This is important because the master admin can already inspect those systems.

### Conversation strategy

Recommended:

- one per-user prompt-lab conversation per owner or tool context
- clear title pattern, for example:
  - `[Prompt Lab] Section 123 conversation_context`
  - `[Prompt Lab] Script 45`
  - `[Prompt Builder] Section 123`

### Additional audit layers

- `transactions` for prompt version lifecycle events
- `user_activity` still logs the page requests through the router
- `llm_prompt_playground_runs` only as fast UI index, not as sole audit trail

### Important note on `user_activity`

`user_activity` is useful, but it is not enough by itself for prompt-tool auditing.

Reason:

- it logs page/request activity at router level
- it does not replace per-run LLM request metadata

So the plan should rely on:

- `llmMessages` for prompt run details
- `transactions` for prompt lifecycle changes
- `user_activity` as supporting request history

## Security And Access Control

This part must be explicit.

### What is automatic in core

- page/component access is protected through the normal page/component ACL flow
- `AjaxRequest` classes do call `has_access(...)` automatically

### What is **not** automatic enough for this feature

- `BaseController` itself does not provide automatic per-action ACL enforcement
- page-level access does not replace explicit checks inside JSON-style controller actions

### Plan requirement

All new prompt/version/playground endpoints must:

- explicitly check ACL for the relevant action
- explicitly validate CSRF for mutating requests
- keep the same access model already used in `ModuleLlmScriptController`

### Recommended rules

- CMS field prompt endpoints require the same update access as the page/section being edited
- script prompt endpoints reuse the `moduleLlmScript` page ACL and still do explicit per-action checks
- playground and builder actions are protected exactly like update actions, because they spend tokens and expose data

## React UI Plan

### 1. New CMS prompt field

Create a React-based field UI rendered by a new custom field type `llm_prompt`.

UI goals:

- keep a simple textarea editing experience
- add versioning/playground actions beside it
- work in Bootstrap 4.6 CMS layouts
- keep hidden real inputs in sync

Recommended visible elements:

- textarea editor
- version badge: `v6`, author, timestamp
- buttons: `Playground`, `Versions`, `Compare`
- optional save comment field
- optional `Build With AI` action

Recommended hidden inputs:

- `content`
- `meta`

### Save behavior

- selecting an older version updates the textarea immediately
- nothing is persisted until the normal CMS save happens
- on CMS save, backend creates the next immutable version if something changed

### 2. Versions modal

Recommended features:

- version list
- author
- timestamp
- comment
- compare action
- use/revert action

### 3. Diff modal

Recommended features:

- current draft vs active version
- version A vs version B
- Monaco diff display

### 4. Playground modal

Recommended features:

- variable inputs
- raw JSON mode
- model selector
- optional compare mode
- rendered prompt preview
- response preview
- raw payload copy
- tokens/time info
- `Build With AI` tab or secondary modal

### 5. Script editor integration

The scripts module should not build a second prompt system.

Recommended approach:

- keep the current `ScriptsManager` shell
- replace the raw script editor block with shared prompt editor/playground components
- keep script-specific config and `data_config` UI
- route script testing through the same shared playground services

## Suggested React File Layout

- `react/src/components/prompts/PromptFieldApp.tsx`
- `react/src/components/prompts/PromptEditor.tsx`
- `react/src/components/prompts/PromptToolbar.tsx`
- `react/src/components/prompts/PromptVersionsModal.tsx`
- `react/src/components/prompts/PromptDiffModal.tsx`
- `react/src/components/prompts/PromptPlaygroundModal.tsx`
- `react/src/components/prompts/PromptBuilderModal.tsx`
- `react/src/components/prompts/PromptVariableInputs.tsx`
- `react/src/components/prompts/promptApi.ts`
- `react/src/components/prompts/promptTypes.ts`
- `react/src/components/prompts/promptHooks.ts`

Reuse target:

- CMS `llm_prompt` field
- scripts manager
- later therapy plugin prompt fields

## Backend Services

Recommended new services:

- `LlmPromptRegistryService`
- `LlmPromptVersionService`
- `LlmPromptResolverService`
- `LlmPromptDiffService`
- `LlmPromptPlaygroundService`
- `LlmPromptBuilderService`
- `LlmPromptFieldSyncService`
- `LlmPromptVariableService`

### `LlmPromptRegistryService`

- create/find entries and locale rows
- load history
- create next version
- activate version

### `LlmPromptResolverService`

- resolve active prompt from field/script owner
- fallback to current field content or `llm_scripts.script`

### `LlmPromptPlaygroundService`

- run owner-aware playground tests
- log via central `llmConversations` / `llmMessages`
- optionally write fast index rows to `llm_prompt_playground_runs`

### `LlmPromptBuilderService`

- generate prompt suggestions from user instructions
- reuse same centralized logging path

### `LlmPromptFieldSyncService`

- sync CMS field save payloads into version registry
- update field `meta`
- keep `content` as the active cache

## Runtime Integration Changes

### `llmChat`

- `conversation_context` remains the runtime source in phase 1
- registry save flow keeps it synchronized

### `llmForm`

- `llm_context` remains the runtime source in phase 1
- prompt versions store raw template text before interpolation
- playground uses the same interpolation flow as production

### `llm_scripts`

- `llm_scripts.script` remains the runtime cache in phase 1
- current scripts UI is upgraded to use the new versioning and playground
- saved active version syncs back to `llm_scripts.script`

## Migration Plan

### Step 1. Schema changes

- add prompt registry tables
- add lookup type `llm_prompt_owner_types`
- add `id_llm_prompt_entries` to `llm_scripts`
- register field type `llm_prompt`

### Step 2. Backfill existing data

Use a PHP migration/backfill service, not SQL-only.

Backfill rules:

- create one `llm_prompt_entries` row per logical owner
- create one `llm_prompt_locales` row per existing language variant
- create version `1` from raw stored text
- update `sections_fields_translation.meta` linkage for field-backed prompts
- update `llm_scripts.id_llm_prompt_entries` for scripts
- leave current `content` and `script` values in place as active caches

### Step 3. Flip field type

- change `conversation_context` and `llm_context` to type `llm_prompt`
- keep field names unchanged

### Step 4. Enable shared UI

- deploy the CMS prompt field
- integrate shared prompt components into `ScriptsManager`

### Step 5. Later plugin expansion

- wire the same services/components into `sh-shp-llm_therapy_chat`

## Version Target

Because plugin `1.1.0` is still not released:

- DB and feature changes can be folded into the current unreleased `server/db/v1.1.0.sql`
- do not create a new plugin DB version just for this if release has not happened yet

## Documentation And Release Work

When implementation starts, it should also include:

- plugin `CHANGELOG.md`
- user documentation for CMS editors
- developer documentation for architecture, tables, and adapter flow
- playground/builder usage guide
- migration notes

Later, when therapy plugin integration is done:

- update `sh-shp-llm_therapy_chat/CHANGELOG.md`
- add plugin-specific user and developer docs there too

## Acceptance Criteria

The implementation should be accepted only if all of these are true:

- translations for field-backed prompts still rely on core CMS storage and fallback behavior
- users can edit `conversation_context` and `llm_context` with a React prompt field that still feels like a simple textarea
- every real save creates a new immutable prompt version when content changed
- users can inspect version history, compare versions, add an optional comment, and restore old content safely
- scripts reuse the same versioning and playground logic instead of a separate implementation
- owner types are lookup-backed
- language version streams are separated through `id_languages`
- playground and builder requests are logged through the central LLM conversation/message system
- prompt lifecycle actions are logged in `transactions`
- controller actions keep explicit ACL and CSRF checks
- design is reusable later for `sh-shp-llm_therapy_chat`

## Final Recommendation

The best path for `v1.1.0` is:

- keep core CMS translation ownership exactly as it is
- add a central prompt registry on top of it
- store full snapshot versions, not delta chains
- keep model/temperature/token fields where they are today
- treat `config_json` as version snapshot metadata only
- upgrade scripts to the new shared prompt UI and playground
- log playground and builder traffic through `llmConversations` and `llmMessages`
- design the shared services so therapy plugin prompt fields can adopt them later without another rewrite
