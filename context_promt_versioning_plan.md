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

## Runtime-Aware Playground Strategy

The playground must not test only the raw saved prompt text.

It must test the same effective runtime input that production sends to the LLM.

### Why this is required

In both plugins, the saved prompt is only one part of the final LLM request.

Production also adds extra context such as:

- language instructions
- response-format / JSON-schema instructions
- danger detection and safety guidance
- style-mode additions such as strict, floating, or form wrappers
- therapy system prompts and therapist-authoritative message handling
- script `data_config` expansion and test variable resolution

So the playground must reuse the same runtime composition logic instead of constructing a simplified standalone request.

### Effective context preview

The playground should show both:

- the editable prompt template
- the fully rendered effective context/messages actually sent to the LLM

This is important for debugging because the saved prompt alone does not explain the final runtime behavior.

## Shared Owner Adapter Pattern

To avoid duplicating logic, introduce one shared prompt playground/versioning layer with owner-specific adapters.

Recommended adapters:

- `ChatPromptOwnerAdapter`
- `FormPromptOwnerAdapter`
- `ScriptPromptOwnerAdapter`

Planned expansion adapters:

- `TherapyChatPromptOwnerAdapter`
- `TherapyDraftPromptOwnerAdapter`
- `TherapySummaryPromptOwnerAdapter`
- `TextOnlyPromptOwnerAdapter`

Each adapter should know:

- how to load the active owner prompt
- how to resolve current config fields
- how to resolve companion runtime fields that change the final context
- how to build the effective runtime request exactly like production
- how to test a draft prompt using the same runtime behavior as production
- how to parse and render structured JSON results using the same production rules
- how to sync the selected active prompt back into `content` or `llm_scripts.script`

### Why this matters

- `llmChat` testing must respect `LlmContextService`
- `llmForm` testing must respect `LlmFormController` interpolation and prompt composition
- `llm_scripts` testing must respect `LlmScriptService`

### Execution profiles

Prompt playground behavior should inherit from the owner/style runtime type, not from a manual UI toggle.

Recommended execution profiles:

- `chat_runtime` for `llmChat.conversation_context`
- `form_runtime` for `llmFormRecord.llm_context` and `llmFormLog.llm_context`
- `script_runtime` for `llm_scripts.script`
- `therapy_chat_runtime` for therapy chat `conversation_context`
- `therapy_draft_runtime` for `therapy_draft_context`
- `therapy_summary_runtime` for `therapy_summary_context`
- `text_only` for prompt-like text that is not sent through an LLM call, such as `therapy_auto_start_context`

Each execution profile defines:

- whether the playground is conversation-style or one-shot generation
- whether message roles must be shown in the preview
- which extra context layers are injected
- which structured-response parser / renderer is used
- whether the owner is playground-executable at all

### Unsaved companion field overrides

The playground should use unsaved CMS form values when those values affect runtime context.

Reason:

- a CMS editor may change model, temperature, language behavior, safety settings, or other related fields before saving
- the playground should test that current draft state, not only the last persisted database state

Recommended rule:

- each adapter exposes a list of companion field names it depends on
- the React UI sends current unsaved companion values as `runtime_overrides`
- the backend composes the effective request from persisted owner data plus draft overrides

This keeps playground behavior aligned with what the user is currently editing in the CMS.

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
- inspect rendered prompt, effective context/messages, response, raw payload, tokens, and duration

### Production-context requirement

The playground must reuse the same runtime composition path as production.

That means:

- `llmChat` playground runs through the same context-building logic as live chat
- `llmForm` playground runs through the same interpolation and one-shot request composition as form execution
- script playground runs through the same interpolation, `data_config`, and test-variable resolution as script execution
- therapy playground runs through the same therapy context builders, schema instructions, and response extraction logic as production

The playground request is therefore not just "send this prompt".
It is "send this prompt inside the owner's full runtime context".

### Chat vs context testing

The playground must distinguish between conversation-style testing and context-generation testing.

Examples:

- chat/therapy chat styles work with role-based message arrays
- form/script/draft/summary styles usually work as one-shot generation with a built system/user composition

This distinction should come from the execution profile and style type, not from a manual user choice.

### Model override

- yes, the user should be able to choose a model in the playground even if the style already has a saved model
- this override is only for testing
- it must not overwrite the saved style/script config unless the user explicitly saves normal config

### Multi-model compare

This should be part of phase 1, with bounded scope:

- phase 1 supports both single-model run and compare mode
- compare mode should be limited to 2-3 selected models to avoid cost and clutter
- compare mode is playground-only, never runtime behavior

Requirements:

- every compared run uses the exact same effective context/messages
- all compared runs are logged with a shared `comparison_group_id`
- the UI renders each model result separately with model name, tokens, time, and parsed output

### Structured response handling

The playground should assume structured JSON responses are normal in this system.

Recommended result payload:

- `raw_content`
- `display_content`
- `parsed_response`
- `safety`
- `request_payload`
- `effective_context`
- `logged_message_id`

Behavior:

- if the owner/runtime expects structured JSON, parse it with the same runtime parser used in production
- show both the raw JSON and the extracted display content
- if the owner has a custom content extractor, use that extractor
- if parsing fails, show fallback raw/markdown output and the parse error details for debugging

For therapy and other structured flows, the visible result in the playground should match what users would effectively see in the product, not just the raw model JSON.

## Prompt Builder Assistant

Add a second tool in the playground called something like:

- `Prompt Assistant`
- or `Build With AI`

### Purpose

The user describes:

- the current prompt draft that already exists
- what the prompt should do
- target audience
- tone
- constraints
- output format
- variables they want to use

The assistant returns:

- an improved prompt template based on the current draft
- optional variable suggestions
- optional tags / notes
- a short change summary kept outside the prompt body

### Builder input rule

The builder should improve the current prompt draft or selected version.

It should not assume every builder run starts from a blank prompt.

Recommended input sources:

- current unsaved draft first
- otherwise selected active version
- otherwise current stored owner prompt

### Builder output structure

Builder output should be structured JSON, not mixed prose inside the prompt body.

Recommended response shape:

```json
{
  "prompt_template": "Improved prompt text here",
  "variables": [
    {
      "name": "context",
      "type": "string",
      "required": true,
      "description": "Runtime context block"
    }
  ],
  "notes": [
    "Explains why the prompt structure was changed"
  ],
  "change_summary": "Condensed explanation of the improvement"
}
```

UI rule:

- only `prompt_template` is inserted into the editor
- `variables`, `notes`, and `change_summary` are shown in separate UI panels
- the builder must never silently append notes/explanations into the prompt text

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
- `execution_profile`
- `selected_model`
- `comparison_group_id` nullable
- `effective_context`
- `runtime_overrides`

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

## Backend Request Flow

The new React field is mounted through hooks inside the CMS page, but the prompt-lab actions still need a dedicated backend entry point.

### Recommended backend pattern

Use a dedicated AJAX endpoint class for prompt-lab actions, for example:

- `AjaxLlmPromptLab`

This endpoint should handle requests such as:

- bootstrap owner state
- list/get versions
- compare versions
- run playground test
- run builder
- activate version
- prepare save metadata

### Why this is the best fit

- the CMS field is already hook-rendered, so it can call a normal plugin AJAX endpoint without changing the CMS page controller structure
- page controllers should stay focused on normal page rendering and save flows
- the same prompt-lab endpoint can be reused by the scripts module and later by the therapy plugin

### Controller and hook responsibilities

- hooks render the field shell and mount the React app
- the React app calls the dedicated prompt-lab AJAX endpoint
- the standard CMS save still posts the field `content` and `meta` as usual
- backend field-sync services convert that normal save into prompt-version updates

So the request flow stays consistent with the existing CMS/plugin architecture and does not require extending the CMS controller for every new prompt action.

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
- compare mode for 2-3 models
- effective context / final message preview
- rendered prompt preview
- parsed response preview
- raw payload copy
- tokens/time info
- `Build With AI` tab or secondary modal

The visible response area should support:

- structured JSON viewer
- extracted display-content preview
- fallback plain/raw output view
- per-model result cards in compare mode

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
- `react/src/components/prompts/PromptResultPanel.tsx`
- `react/src/components/prompts/PromptEffectiveContextPanel.tsx`
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
- `LlmPromptExecutionProfileService`
- `LlmPromptPlaygroundService`
- `LlmPromptBuilderService`
- `LlmPromptFieldSyncService`
- `LlmPromptVariableService`
- `LlmPromptResponseRenderService`
- `LlmPromptAjaxService`

### `LlmPromptRegistryService`

- create/find entries and locale rows
- load history
- create next version
- activate version

### `LlmPromptResolverService`

- resolve active prompt from field/script owner
- fallback to current field content or `llm_scripts.script`

### `LlmPromptExecutionProfileService`

- resolve execution profile from owner type, slot, and style/module
- declare required companion fields
- choose the correct runtime composer and response renderer

### `LlmPromptPlaygroundService`

- run owner-aware playground tests
- build the same effective context/messages as production
- log via central `llmConversations` / `llmMessages`
- optionally write fast index rows to `llm_prompt_playground_runs`

### `LlmPromptResponseRenderService`

- parse structured JSON outputs
- extract display content using owner/runtime-aware rules
- build normalized playground response payloads

### `LlmPromptBuilderService`

- generate prompt suggestions from the current prompt draft plus user instructions
- reuse same centralized logging path

### `LlmPromptFieldSyncService`

- sync CMS field save payloads into version registry
- update field `meta`
- keep `content` as the active cache

## Runtime Integration Changes

### `llmChat`

- `conversation_context` remains the runtime source in phase 1
- registry save flow keeps it synchronized
- playground must still use the full `LlmContextService` composition, including language, schema, danger, and mode-specific additions

### `llmForm`

- `llm_context` remains the runtime source in phase 1
- prompt versions store raw template text before interpolation
- playground uses the same interpolation flow as production
- result rendering should respect structured JSON output rules when the form flow expects them

### `llm_scripts`

- `llm_scripts.script` remains the runtime cache in phase 1
- current scripts UI is upgraded to use the new versioning and playground
- saved active version syncs back to `llm_scripts.script`
- playground must include `data_config`, test variables, and any script-specific structured output handling

### `sh-shp-llm_therapy_chat`

When this expansion is wired in:

- therapy chat playground must use the same built message context, therapist-authoritative history handling, and JSON extraction rules as production
- therapy draft and summary playgrounds must use the same schema and context composition as their runtime generators
- `therapy_auto_start_context` stays versionable but is treated as `text_only`, not playground-executable

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
- wire the dedicated prompt-lab AJAX endpoint and owner execution-profile resolution

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
- playground runs use the same effective runtime context as production, not just the raw saved prompt
- chat-style and one-shot context-style playground behavior is inherited from the owner runtime profile
- structured JSON responses are parsed and rendered into display content in the playground
- builder improves the current prompt draft/version instead of always starting from scratch
- compare mode for 2-3 models exists in phase 1
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
- make the playground runtime-aware so it reuses full production context composition and structured response rendering
- use a dedicated prompt-lab AJAX endpoint for hook-mounted CMS fields and shared tool actions
- log playground and builder traffic through `llmConversations` and `llmMessages`
- design the shared services so therapy plugin prompt fields can adopt them later without another rewrite
