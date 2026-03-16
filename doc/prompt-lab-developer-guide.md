# Prompt Lab Developer Guide

## Scope

Prompt Lab introduces a shared prompt registry/versioning/playground layer for:

- style-backed CMS prompt fields (`conversation_context`, `llm_context`)
- script-backed prompts (`llm_scripts.script`)

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
- `llm_eval_datasets`
- `llm_eval_dataset_cases`
- `llm_eval_definitions`
- `llm_eval_runs`
- `llm_eval_run_cases`
- `llm_eval_scores`
- `llm_scripts.id_llm_prompt_entries`
- lookup types:
  - `llm_prompt_owner_types`
  - `llm_prompt_run_modes`
  - `llm_eval_dataset_types`
  - `llm_eval_execution_profiles`
  - `llm_eval_case_types`
  - `llm_eval_source_types`
  - `llm_eval_types`
  - `llm_eval_run_modes`
  - `llm_eval_run_statuses`
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
- `LlmPromptRuntimeValueService`
- `LlmPromptPlaygroundService`
- `LlmPromptBuilderService`
- `LlmPromptResponseRenderService`
- `LlmPromptVariableService`
- `LlmDatasetService`
- `LlmDatasetIngestionService`
- `LlmDatasetReplayService`
- `LlmEvaluationService`
- `LlmEvaluationDefinitionService`
- `LlmEvaluationRunnerService`
- `LlmEvaluationScoringService`
- `LlmEvaluationAggregationService`
- `LlmEvaluationReviewService`

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
- returns `id_llm_prompt_playground_runs` so latest runs can be promoted into dataset cases

### `LlmDatasetService`

- dataset CRUD/listing
- dataset case CRUD/listing
- lock-state enforcement
- shared normalization helpers used by ingestion/replay flows

### `LlmDatasetIngestionService`

- add case from latest playground payload
- import candidates from production-like sources (playground runs, form submissions, conversation messages, scripts)
- bulk import from selected source IDs
- owner-aware source filtering for import candidate and import execution paths
- capture original assistant output when a source already has one

### `LlmDatasetReplayService`

- replays one normalized dataset case through the same prompt runtime path used by production-like playground execution
- resolves live runtime values plus case overrides
- logs replay runs in the normal LLM logging system

### `LlmEvaluationService`

- evaluation definition listing
- dataset run execution orchestration
- run/case/score persistence
- baseline programmatic evaluators (`json_validity`, `required_fields_present`, `no_empty_output`, `safety_label_match`)
- `llm_judge` execution with structured JSON scoring output
- human-review score save endpoint
- run status lifecycle handling (`running` -> `completed` / `failed`)

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
- `list_datasets`
- `get_dataset`
- `create_dataset`
- `update_dataset`
- `list_dataset_cases`
- `add_case_from_playground_run`
- `get_import_candidates`
- `add_cases_from_source`
- `delete_dataset_case`
- `list_eval_definitions`
- `run_dataset_eval`
- `get_eval_run`
- `list_eval_run_cases`
- `list_eval_runs`
- `delete_eval_run`
- `delete_eval_runs_bulk`
- `save_human_score`

Access/security:

- `has_access` requires logged-in user
- per-action ACL checks:
  - style-field owners use page ACL (`page_id`)
  - script owners use `moduleLlmScript` ACL
- mutating actions validate CSRF token
- dataset-scoped eval actions enforce descriptor scope checks before mutation/listing

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
- `PromptDatasetsModal.tsx`
- `PromptResultPanel.tsx`
- `PromptEffectiveContextPanel.tsx`
- `PromptVariableInputs.tsx`
- `promptApi.ts`
- `promptHooks.ts`
- `promptTypes.ts`

Key UI behavior:

## JSON UI contract (v1.1.0)

For plugin-maintained prompt/admin screens:

- Read-only JSON must use `JsonInspector` (tree/raw toggle + copy).
- Editable JSON must use the shared Monaco JSON editor wrapper.
- Do not add new ad-hoc JSON `<pre>` blocks or plain textareas for JSON editing.

This keeps parsing/formatting behavior consistent across prompt-lab and admin tooling.

- modals use consistent `90vw/90vh` layout (`PromptLab.css`)
- modal header/footer fixed, body scrollable
- buttons use Bootstrap small size (`btn-sm`)
- toolbar contains explicit version-comment hint/tooltip
- toolbar now includes `Datasets` entry point for replay/evaluation workflows

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

## Dataset evaluation deletion policy

Deletion in v1.1.0 is hard-delete:

- deleting one run removes the `llm_eval_runs` row and cascades to related run-cases/scores
- bulk delete removes all runs for the selected dataset and cascades dependent rows

Case deletion behavior remains cascade-based:

- deleting `llm_eval_dataset_cases` rows removes related eval run-cases/scores for that case

This is the official policy in this version (no soft-delete/audit shadow table for eval runs/cases).

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

## Related documents

- [prompt-lab-payload-shapes.md](prompt-lab-payload-shapes.md)
- [prompt-evaluator-authoring-guide.md](prompt-evaluator-authoring-guide.md)
- [prompt-lab-migration-notes-v1.1.0.md](prompt-lab-migration-notes-v1.1.0.md)
