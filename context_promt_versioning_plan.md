# Context Prompt Versioning And Playground Plan

## Goal

Implement a Langfuse-like prompt registry inside this plugin so prompt-like content can be:

- versioned
- diffed
- tested in a playground
- reverted safely
- translated per language
- audited by user, date, and change history

This must work for all current prompt surfaces:

- `llmChat` -> `conversation_context`
- `llmFormRecord` and `llmFormLog` -> `llm_context`
- `llm_scripts.script`

This plan is intentionally implementation-focused but does not include code yet.

## Current State

### Field-backed prompts

- `conversation_context` is a translatable style field on `llmChat`.
- `llm_context` is a translatable style field on `llmFormRecord` and `llmFormLog`.
- These values are currently stored through the normal SelfHelp field translation storage.
- Runtime code reads them directly through model helpers such as `get_db_field(...)`.
- Form prompts already use `{{variable}}` interpolation.

### Script-backed prompts

- Scripts are stored in `llm_scripts`.
- The prompt text is stored in `llm_scripts.script`.
- Script config such as `model`, `temperature`, `max_tokens`, `data_config`, and `test_variables` lives beside the prompt in the same row.
- The scripts module already has a React editor and a test flow.

### Important constraints

- Field prompts are translatable today and that must remain true.
- CMS users expect a simple textarea-like editing experience.
- Existing runtime logic must keep working during rollout.
- We should not create one system for fields and another unrelated system for scripts.

## Recommended Architecture

Use one central prompt registry with owner adapters.

### Core idea

- Introduce a new custom field type, recommended name: `llm_prompt`.
- Replace `conversation_context` and `llm_context` field types with this new field type.
- Keep using normal field `content` storage for the active prompt text so existing model/runtime code does not break.
- Use field `meta` only for lightweight linkage and UI state, not for full history.
- Store full prompt history in dedicated plugin tables.
- For scripts, keep `llm_scripts.script` as the active prompt cache for backward compatibility, but store the canonical history in the same prompt registry tables.

### Why this is the safest design

- Existing runtime code can continue reading `content` and `llm_scripts.script`.
- Version history, diff, audit, and playground become centralized.
- Field-backed and script-backed prompts share one storage model and one UI vocabulary.
- Migration risk stays low because the active prompt text is still mirrored into the legacy storage location.

## Scope For Phase 1

Phase 1 should cover only the prompt surfaces that directly affect LLM behavior:

- `conversation_context`
- `llm_context`
- `llm_scripts.script`

Other text fields such as labels, fallback messages, or user-facing copy can stay out of scope unless we explicitly decide to bring them into the registry later.

## Data Model

Recommended tables:

### 1. `llm_prompt_entries`

Logical prompt identity independent from a specific language version.

Suggested columns:

- `id`
- `owner_type` -> `style_field` or `llm_script`
- `owner_id` -> section id for fields, script id for scripts
- `prompt_slot` -> `conversation_context`, `llm_context`, or `script`
- `title`
- `source_style` nullable
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Suggested uniqueness:

- unique on `owner_type + owner_id + prompt_slot`

### 2. `llm_prompt_locales`

Translation-aware prompt variant.

Suggested columns:

- `id`
- `id_llm_prompt_entries`
- `id_languages` nullable for non-translated owners
- `id_gender` nullable for field compatibility
- `active_version_id`
- `active_version_no`
- `content_cache` longtext
- `meta_json` longtext nullable
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Suggested uniqueness:

- unique on `id_llm_prompt_entries + id_languages + id_gender`

### 3. `llm_prompt_versions`

Immutable saved versions.

Suggested columns:

- `id`
- `id_llm_prompt_locales`
- `version_no`
- `template_raw` longtext
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

### 4. `llm_prompt_playground_runs`

Optional but recommended for audit/debugging.

Suggested columns:

- `id`
- `id_llm_prompt_entries` nullable
- `id_llm_prompt_locales` nullable
- `id_llm_prompt_versions` nullable
- `owner_type`
- `owner_id`
- `template_raw`
- `rendered_prompt`
- `variables_json`
- `config_json`
- `request_payload`
- `response_payload`
- `created_by`
- `created_at`

### Script table extension

Recommended additions to `llm_scripts`:

- `id_llm_prompt_entries` nullable

Keep `script` as the active prompt cache in phase 1.

## Field Storage Strategy

For the new `llm_prompt` field type:

- `content` keeps the current active prompt text exactly as users expect.
- `meta` stores only linkage and lightweight state.

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

## Translation Strategy

Recommended rule:

- one `llm_prompt_entries` row per logical prompt owner
- one `llm_prompt_locales` row per language/gender variant
- one independent version history per locale row

This keeps the current translation behavior intact and avoids mixing German and English versions in the same history stream.

For scripts:

- design the tables for translations from day one
- in phase 1, the script UI can use the current CMS/admin language as the active locale
- later we can add explicit language tabs in the script editor without changing the database again

## Versioning Rules

### Version creation

- Never mutate an existing version row.
- A new version is created only when the user saves and the prompt text or versioned config changed.
- Field editors do not need server-side draft versions while the user is typing.
- Script editor keeps its current save model: save creates the next version.

### Active version

- `llm_prompt_locales.active_version_id` points to the live version.
- The active version text is mirrored into field `content` or `llm_scripts.script`.

### Revert

- Revert should create a new version whose content matches the selected historical version.
- Do not move the active pointer directly back to an old row without creating a new row.
- This preserves a clear audit trail.

### Diff

- Diff should compare raw template text, not rendered prompt text.
- Line-based unified diff is enough for phase 1.
- No dedicated diff table is needed; compute on demand.

## Configuration Versioning

Prompt versions should store a `config_json` block so prompt text and config can be versioned together.

Recommended config keys:

- `model`
- `temperature`
- `max_tokens`
- `tags`
- custom metadata

### Recommended rollout

Phase 1:

- keep current model/temperature/max token fields and script columns as runtime authority
- mirror those values into `config_json` when saving a prompt version

Phase 2, if wanted:

- allow prompt-owned config to become the primary runtime source
- reduce duplicated configuration fields in the UI

This staged approach gives versioned config now without forcing a high-risk runtime rewrite immediately.

## Variable And Template Handling

Use one shared template parser service across field prompts and scripts.

### Rules

- Store raw templates with `{{variable}}` placeholders.
- Auto-detect variables from the raw template.
- Allow optional `variables_schema_json` for richer input definitions in the playground.
- Keep compatibility with existing interpolation logic used by forms and scripts.

### Playground input behavior

- If a variable schema exists, build typed inputs from it.
- If no schema exists, auto-create simple text inputs from detected placeholders.
- Always provide an advanced raw JSON mode for complex variables.

## Shared Owner Adapter Pattern

To avoid duplicating logic, introduce one shared prompt playground/versioning layer with owner-specific adapters.

Recommended adapters:

- `ChatPromptOwnerAdapter`
- `FormPromptOwnerAdapter`
- `ScriptPromptOwnerAdapter`

Each adapter should know:

- how to load the current owner prompt
- how to produce the runtime config snapshot
- how to render/test a draft prompt using the same runtime rules as production
- how to sync the active prompt back into legacy storage

### Why this matters

- `llmChat` prompt testing must use the same context-building rules as `LlmContextService`
- `llmForm` prompt testing must use the same interpolation/user-prompt logic as `LlmFormController`
- `llm_scripts` testing must use the same execution path as `LlmScriptService`

One registry, one playground service, multiple adapters.

## Backend Services

Recommended new services:

- `LlmPromptRegistryService`
- `LlmPromptVersionService`
- `LlmPromptResolverService`
- `LlmPromptDiffService`
- `LlmPromptPlaygroundService`
- `LlmPromptFieldSyncService`
- `LlmPromptVariableService`

Recommended responsibilities:

### `LlmPromptRegistryService`

- create/find entry and locale rows
- load version history
- activate version
- create next version

### `LlmPromptResolverService`

- resolve the active prompt for chat/form/script
- fallback to legacy field content or `llm_scripts.script` if no prompt link exists

### `LlmPromptPlaygroundService`

- run prompt tests using raw draft text plus user inputs
- call the correct owner adapter
- store optional playground history

### `LlmPromptFieldSyncService`

- sync field save payloads into the registry
- update field `meta`
- mirror active text back into `content`

## Controllers And Endpoints

Recommended new admin endpoints:

- `list_versions`
- `get_version`
- `compare_versions`
- `playground_test`
- `activate_version`
- `save_prompt_version`
- `resolve_prompt_owner`

### Access control

- CMS field prompt endpoints should require the same edit permission as the page/section being edited.
- Script prompt endpoints should reuse the existing `moduleLlmScript` ACL model.
- All endpoints should require CSRF and current user context.

## React UI Plan

### 1. New CMS prompt field

Create a React-based field UI rendered by a new custom field type, for example `llm_prompt`.

UI goals:

- keep a simple textarea editing experience
- add versioning/playground actions beside it
- work inside Bootstrap 4.6 CMS layouts
- keep the hidden real inputs in sync

Recommended visible elements:

- textarea editor
- version badge: `v6`, author, timestamp
- buttons: `Playground`, `Versions`, `Compare`, `Use Version`
- optional change-note input shown only before save or in a modal
- variable summary chips

Recommended hidden inputs:

- `content`
- `meta`
- any temporary prompt action fields needed by the backend sync

### Save behavior

- If the user selects an older version in the UI, the textarea updates immediately.
- The actual database save still happens only when the CMS form is saved.
- On CMS save, the backend creates the next version and marks it active.

This matches the existing CMS mental model and is less surprising.

### 2. Versions panel/modal

Recommended features:

- list of versions
- author and timestamp
- optional change note
- tags
- compare action
- use/revert action

### 3. Diff modal

Recommended features:

- line-based diff
- compare current draft vs active version
- compare version A vs version B

### 4. Playground modal

Recommended features:

- dynamic variable inputs
- advanced JSON input mode
- config snapshot preview
- sample user message or form data input depending on owner type
- rendered prompt preview
- model response preview
- raw payload copy
- tokens/time debug info

### 5. Script editor integration

The scripts module should not build a second prompt system.

Recommended approach:

- keep the current `ScriptsManager` shell
- replace the plain script editor section with the shared prompt editor components
- keep the script-specific config block and data-config UI
- route the existing test action through the shared playground service

## Suggested React File Layout

Recommended small-file structure:

- `react/src/components/prompts/PromptFieldApp.tsx`
- `react/src/components/prompts/PromptEditor.tsx`
- `react/src/components/prompts/PromptToolbar.tsx`
- `react/src/components/prompts/PromptVersionsModal.tsx`
- `react/src/components/prompts/PromptDiffModal.tsx`
- `react/src/components/prompts/PromptPlaygroundModal.tsx`
- `react/src/components/prompts/PromptVariableInputs.tsx`
- `react/src/components/prompts/promptApi.ts`
- `react/src/components/prompts/promptTypes.ts`
- `react/src/components/prompts/promptHooks.ts`

This should be reused by:

- the CMS `llm_prompt` field
- the scripts manager editor

## Hook And Template Integration

Follow the same pattern already used for the API keys manager:

- add a new hook handler in `LlmHooks`
- render a PHP template that outputs hidden inputs and a React mount point
- include dedicated CSS/JS on CMS pages

Recommended additions:

- new field type registration for `llm_prompt`
- `outputFieldLlmPromptEdit`
- `outputFieldLlmPromptView`
- CSS include hook
- JS include hook

## Runtime Integration Changes

### `llmChat`

- `conversation_context` stays the runtime source in phase 1
- save flow keeps it synchronized with the active prompt version
- later we can have `LlmPromptResolverService` become the only source if we want

### `llmForm`

- `llm_context` stays the runtime source in phase 1
- prompt versions store raw template text before interpolation
- playground must use the same interpolation path as `LlmFormController`

### `llm_scripts`

- `llm_scripts.script` stays the runtime cache in phase 1
- save flow syncs it from the active prompt version
- testing and execution should resolve through the shared prompt services

## Migration Plan

### Step 1. Schema migration

- create prompt registry tables
- add `id_llm_prompt_entries` to `llm_scripts`
- register the new `llm_prompt` field type

### Step 2. Backfill existing data

Create a PHP migration/backfill service, not SQL-only.

Backfill rules:

- for each `conversation_context` translation row, create one prompt entry, one locale row, and version `1`
- for each `llm_context` translation row, do the same
- for each `llm_scripts.script`, create one prompt entry, one locale row, and version `1`
- fill field `meta` and `llm_scripts.id_llm_prompt_entries`
- leave existing `content` and `script` values untouched except for linkage updates
- read raw field translation values from storage tables during backfill, not rendered `get_db_field(...)` output

### Step 3. Flip field type

- change `conversation_context` and `llm_context` to use `llm_prompt`
- keep the same field names so runtime code changes stay small

### Step 4. Enable new UI

- deploy the new React prompt field
- integrate the same components into the scripts manager

### Step 5. Optional cleanup later

- decide whether old duplicated config fields should move fully into prompt-owned config
- decide whether more LLM prompt-like fields should join the registry

## Audit And Collaboration

To satisfy collaborative prompt management:

- every version row stores `created_by` and `created_at`
- active locale stores `updated_by` and `updated_at`
- revert creates a new version with a traceable `based_on_version_id`
- optional playground runs store `created_by`
- major actions should also write to the existing transaction system

Recommended transaction events:

- prompt version created
- prompt version activated
- prompt reverted
- script prompt updated

## Recommended User Flows

### CMS field prompt

1. User opens page/section edit screen.
2. Prompt field loads active version plus history from the registry.
3. User edits the textarea.
4. User optionally opens playground, tests, compares, or selects a previous version.
5. Selected version updates the textarea immediately.
6. User saves the CMS form.
7. Backend creates the next version, updates active pointers, syncs `content`, and updates `meta`.

### Script prompt

1. User opens script editor.
2. Shared prompt editor loads version history for that script.
3. User edits prompt and script config.
4. User tests in playground using the same service used by runtime execution.
5. User saves.
6. Backend creates the next version and syncs `llm_scripts.script`.

## Risks And Mitigations

### Risk: runtime breakage during rollout

Mitigation:

- keep field `content` and `llm_scripts.script` as active caches in phase 1
- add resolver fallback logic

### Risk: translation confusion

Mitigation:

- version per locale, not one mixed global version stream

### Risk: field UI becomes too heavy

Mitigation:

- keep the main editor a simple textarea
- use modals for versions/diff/playground

### Risk: duplicated config sources

Mitigation:

- treat current config fields as runtime authority in phase 1
- mirror them into version snapshots now
- consolidate only after the new flow is stable

## Acceptance Criteria

The implementation should be accepted only if all of these are true:

- users can edit `conversation_context` and `llm_context` in a React field with a simple textarea UX
- every save creates a new immutable prompt version when content or config changed
- users can view version history, compare versions, and restore an old version safely
- field restores update the visible textarea immediately but persist only on save
- scripts use the same versioning and playground logic, not a separate ad-hoc implementation
- translations continue to work for field-backed prompts
- author and timestamp are visible for prompt changes
- prompt playground uses the same effective runtime logic as production for chat, form, and script owners
- existing runtime consumers continue working during rollout

## Recommended Delivery Order

1. Finalize the target schema and owner-adapter contract.
2. Build DB migration plus backfill service.
3. Build backend prompt registry and playground services.
4. Build the shared React prompt components.
5. Integrate the new `llm_prompt` field into CMS.
6. Integrate the same components into `ScriptsManager`.
7. Add diff, audit polish, and migration QA.

## Final Recommendation

The best implementation path is:

- one central prompt registry
- one translation-aware version model
- one shared React prompt editor/playground
- one owner-adapter layer for chat, form, and script behavior
- backward-compatible mirroring into current field content and `llm_scripts.script`

That gives the requested versioning and playground features without forcing a risky big-bang rewrite of the current runtime.
