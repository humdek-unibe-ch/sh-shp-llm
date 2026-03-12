# Prompt Lab Developer Guide

## Scope

Prompt Lab introduces a shared prompt registry/versioning/playground layer for:

- style-backed CMS prompt fields (`conversation_context`, `llm_context`)
- script-backed prompts (`llm_scripts.script`)

The architecture is designed to be reused later by `sh-shp-llm_therapy_chat`.

## Core design rules

- Field translation ownership remains in core CMS (`sections_fields_translation`).
- Active runtime caches remain where production already reads them:
  - `sections_fields_translation.content`
  - `llm_scripts.script`
- Prompt history/version metadata lives in dedicated prompt tables.
- All playground/builder LLM traffic flows through central logs:
  - `llmConversations`
  - `llmMessages`

## Database model

New/relevant schema in `server/db/v1.1.0.sql`:

- `llm_prompt_entries`
- `llm_prompt_locales`
- `llm_prompt_versions`
- `llm_prompt_playground_runs`
- `llm_scripts.id_llm_prompt_entries`
- lookup types:
  - `llm_prompt_owner_types`
  - `llm_prompt_run_modes`
- field type: `llm_prompt`

Registry semantics:

- `llm_prompt_entries`: logical owner (`owner_type`, `owner_id`, `prompt_slot`)
- `llm_prompt_locales`: per-language stream pointer (`id_languages`)
- `llm_prompt_versions`: immutable full snapshots (`version_no`, `template_raw`, `config_json`, etc.)
- `llm_prompt_playground_runs`: fast index for prompt-lab runs (not canonical audit log)

## Services

Prompt-lab services:

- `LlmPromptRegistryService`
- `LlmPromptExecutionProfileService`
- `LlmPromptPlaygroundService`
- `LlmPromptBuilderService`
- `LlmPromptResponseRenderService`
- `LlmPromptVariableService`

### `LlmPromptRegistryService`

- bootstraps entry/locale/version state for UI
- syncs CMS/script saves into immutable versions
- normalizes/saves prompt meta linkage (`meta.prompt`)
- logs version lifecycle events in `transactions`
- stores run summaries in `llm_prompt_playground_runs`

### `LlmPromptExecutionProfileService`

- resolves runtime profile from owner descriptor
- declares companion fields affecting runtime composition
- builds config snapshot used by version metadata and playground runs

### `LlmPromptPlaygroundService`

- executes prompt tests with production-like runtime composition
- supports single-model and 2-3 model compare runs
- logs each run via existing `LlmService::callLlmApi(...)`
- attaches `comparison_group_id` for compare mode

### `LlmPromptBuilderService`

- improves current prompt draft (not blank-first workflow)
- expects strict JSON output shape:
  - `prompt_template`
  - `variables`
  - `notes`
  - `change_summary`

### `LlmPromptResponseRenderService`

- normalizes structured output for UI:
  - raw content
  - parsed response
  - display content
  - fallback parse error context

## AJAX endpoint and request flow

Endpoint: `AjaxLlmPromptLab::dispatch`

Supported actions:

- `bootstrap_owner`
- `list_versions`
- `get_version`
- `playground_run`
- `builder_run`

Access/security:

- `has_access` requires logged-in user
- per-action ACL checks:
  - style-field owners use page ACL (`page_id`)
  - script owners use `moduleLlmScript` ACL
- mutating actions validate CSRF token

Frontend request path is normalized with `BASE_PATH` in `promptApi.ts` to avoid `/request` vs `/selfhelp/request` mismatches.

## Hook integration

`LlmHooks` additions:

- `outputFieldLlmPromptEdit` / `outputFieldLlmPromptView`
- `addCmsPromptCssIncludes` / `addCmsPromptJsIncludes`
- `syncPromptVersionOnCmsSave`

Field rendering uses template shell:

- `server/component/tpl_prompt_field.php`
- React mount entry: `react/src/LlmPromptField.tsx`

Save flow:

1. CMS performs normal field save.
2. `syncPromptVersionOnCmsSave` runs after save.
3. Registry creates/updates immutable version stream.
4. Field `meta` is updated with prompt linkage/pending state.

## React architecture

Prompt components under `react/src/components/prompts/`:

- `PromptFieldApp.tsx`
- `PromptToolbar.tsx`
- `PromptVersionsModal.tsx`
- `PromptDiffModal.tsx`
- `PromptPlaygroundModal.tsx`
- `PromptBuilderModal.tsx`
- `PromptResultPanel.tsx`
- `PromptEffectiveContextPanel.tsx`
- `PromptVariableInputs.tsx`
- `promptApi.ts`
- `promptHooks.ts`
- `promptTypes.ts`

Key UI behavior:

- modals use consistent `90vw/90vh` layout (`PromptLab.css`)
- modal header/footer fixed, body scrollable
- buttons use Bootstrap small size (`btn-sm`)
- toolbar contains explicit version-comment hint/tooltip

## Runtime-awareness details

Playground profiles currently execute:

- `chat_runtime`
- `form_runtime`
- `script_runtime`

Execution uses owner-aware runtime composition instead of raw prompt-only calls.

Examples:

- chat uses context/message construction path (`LlmContextService` composition)
- form uses interpolation + language-aware system prompt generation
- scripts use script execution flow with `data_config` and test variables

## Scripts module integration

Scripts manager now reuses prompt-lab UI/services instead of a second prompt system.

Controller support lives in:

- `ModuleLlmScriptController.php`
- `ModuleLlmScriptView.php`
- `LlmScriptService.php`

Registry link is persisted via `llm_scripts.id_llm_prompt_entries`.

## Version comment implementation

Comment source:

- prompt toolbar input (`meta.prompt.pendingChangeNote`)

Persist behavior:

- consumed on next version-creating save
- stored in `llm_prompt_versions.change_note`
- cleared from pending state when consumed

## Logging and audit model

Canonical LLM logs:

- `llmConversations`
- `llmMessages`

Prompt-lab summary index:

- `llm_prompt_playground_runs`

Lifecycle audit:

- `transactions` entries for prompt registry operations

## Build outputs

New build artifacts:

- `js/ext/llm-prompt-field.umd.js`
- `css/ext/llm-prompt-field.css`

Related existing bundles updated:

- `js/ext/llm-scripts.umd.js`
- `css/ext/llm-scripts.css`

## Extension path for therapy plugin

The shared registry/services/components are intentionally plugin-agnostic at owner/profile level.

To onboard therapy owners later:

1. Add execution-profile mappings for therapy slots.
2. Implement owner-specific runtime composition and response extraction.
3. Reuse the same prompt-lab endpoint and React components.
