# Changelog

All notable changes to the **sh-shp-llm** plugin are documented in this file.

## [1.1.0] - 2026-03-10

### Prompt asset externalization

- Moved hardcoded LLM-facing runtime prompts/instructions into file assets under `assets/prompts/`.
- Added key-based prompt loading via:
  - `server/service/prompt/LlmPromptAssetRegistry.php`
  - `server/service/prompt/LlmPromptAssetLoader.php`
- Updated core runtime services to load prompts from assets (fail-closed on missing key/file).
- Added `doc/prompt-assets.md` to document prompt ownership, naming, and troubleshooting.

### Multi-server model configuration

- Replaced the single `llm_base_url` / `llm_api_key` setup with the new `llm_api_keys` JSON field.
- Added a dedicated CMS manager UI for named LLM server entries.
- Unified model discovery so chat, forms, scripts, prompt lab, and speech-to-text all use the same scoped model catalog.
- Added migration from legacy API settings into a default multi-server entry.

### Prompt registry and prompt lab

- Added the shared prompt registry schema:
  - `llm_prompt_entries`
  - `llm_prompt_locales`
  - `llm_prompt_versions`
  - `llm_prompt_playground_runs`
- Added the `llm_prompt` field type for version-aware CMS prompt editing.
- Added immutable prompt versions, version comments, version restore, diffing, runtime-aware playground runs, and build-with-AI suggestions.
- Connected `llm_scripts.script` to the same prompt registry through `llm_scripts.id_llm_prompt_entries`.
- Added `AjaxLlmPromptLab` as the shared endpoint for prompt bootstrap, versions, compare, playground, builder, datasets, and evaluations.
- Unified JSON UX in plugin UIs:
  - standardized non-editable JSON rendering on shared `JsonInspector` tree/raw viewer
  - standardized editable JSON fields on shared Monaco-based JSON editor wrapper
  - removed remaining ad-hoc JSON `<pre>`/textarea surfaces in prompt-lab/admin JSON views

### Datasets and evaluations

- Added first-class dataset storage with:
  - `llm_eval_datasets`
  - `llm_eval_cases`
  - `llm_eval_dataset_case_links`
- Added first-class evaluation storage with:
  - `llm_eval_definitions`
  - `llm_eval_runs`
  - `llm_eval_run_cases`
  - `llm_eval_scores`
- Refactored prompt-lab case storage to use canonical reusable cases plus explicit dataset membership links, so cases can be promoted or moved between datasets without losing their identity/history.
- Implemented dataset ingestion from:
  - latest playground runs
  - saved form submissions
  - conversation history
  - script runs
- Implemented shared dataset replay through the existing runtime-aware prompt execution path.
- Added dataset metadata editing for rename, description, type changes, and guarded execution-profile changes.
- Updated the dataset browser to surface both dataset `Type` and runtime `Profile`.
- Added bulk case selection plus move/promotion flows between compatible datasets.
- Added case-level evaluation history so promoted cases keep their prior run and review trail.
- Added programmatic evaluators:
  - `json_validity`
  - `required_fields_present`
  - `no_empty_output`
  - `safety_label_match`
- Added `llm_judge` scoring support and made manual review part of every evaluation run by default.
- Added prompt-lab UI flows for dataset browsing, metadata editing, case preview, source import, case promotion, evaluation runs, result inspection, pending-review filtering, and manual review.
- Exposed the same datasets/evaluations workflow in both CMS prompt fields and the scripts manager.
- Added consistent CMS-style delete confirmation for dataset deletion (`$.confirm` with safe browser fallback).
- Added AI-assisted dataset case import (`Import With AI`) for bulk paste of tabular/free-text examples.
- Added new Prompt Lab actions:
  - `parse_cases_from_text`
  - `import_parsed_cases`
  - `move_dataset_cases`
  - `list_compatible_datasets`
  - `list_case_evaluation_history`
  - `list_evaluation_example_candidates`
- Added parser/import backend services:
  - `LlmDatasetAiImportParserService`
  - `LlmDatasetAiImportMapperService`
  - `LlmDatasetBatchImportService`
- Extended Build With AI so curated, manually approved evaluation examples can be imported directly into prompt-building context.
- Added editable playground-local drafts with explicit `Apply To Draft`, `Reset From Draft`, and shared Build With AI reuse inside playground.
- Added evaluation run history cleanup controls in Prompt Lab datasets:
  - single-run delete (`delete_eval_run`)
  - bulk delete per dataset (`delete_eval_runs_bulk`)
- Documented and surfaced cascade behavior:
  - deleting a dataset case removes related eval run-case/score rows by FK cascade
  - deleting eval runs removes dependent run-cases/scores by FK cascade
- Added parser prompt assets under `assets/prompts/core/dataset-import/` and registry keys:
  - `core.dataset_import.system`
  - `core.dataset_import.repair_json`
- Added dataset source provenance type `ai_text_import` in `llm_eval_source_types`.
- Fixed form-runtime replay reliability for AI-imported cases:
  - mapper now auto-aligns imported variable keys to active prompt placeholders (`{{...}}`) using alias/similarity matching
  - replay path now falls back to normalized variable payload when placeholder filtering would otherwise produce empty user input (`Form submission`)
- Kept full `input_payload_json` case shape for replay compatibility and debugging.
- Improved parser robustness for large/malformed model output:
  - parser token budget floor increased for multi-row imports
  - tolerant JSON candidate extraction (fences + embedded fragments)
  - automatic JSON repair pass when first parse fails
- Improved admin JSON inspection for nested/embedded JSON-like content and parser payload diagnostics.
### LLM forms

- Added the new `llmFormRecord` and `llmFormLog` styles.
- Added configurable result placement, panel type, result metadata storage, retry/regenerate behavior, and inline result rendering.
- Added manual feedback mode for `llmFormRecord`, including a separate feedback button and no-save generation flow.
- Kept all LLM form requests on the shared logging path through `llmConversations` and `llmMessages`.

### Migration and packaging

- Expanded `server/db/v1.1.0.sql` to include prompt lab, datasets, and evaluations.
- Kept the migration rerunnable with `INSERT IGNORE`, `CREATE TABLE IF NOT EXISTS`, and helper-based key/index convergence.
- Added prompt-lab frontend bundles and refreshed the shipped prompt-field and scripts assets.
- Added prompt-lab documentation for editors and developers, including dataset, replay-import, payload-shape, evaluator-authoring, and migration guides.

## [1.0.0] - 2026-02-26

### Initial release

- Added the core chat system with structured JSON responses, conversation history, safety handling, file uploads, and speech-to-text.
- Added reusable LLM scripts with sync/async execution, testing, and scheduler integration.
- Added the admin console for conversation inspection, payload debugging, and conversation blocking.
- Added the `llmResponse` rendering component and the initial React build pipeline.
