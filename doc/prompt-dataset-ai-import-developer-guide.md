# AI Dataset Import Developer Guide

## Scope

This guide documents the Prompt Lab AI-assisted dataset import path in `sh-shp-llm` (v1.1.0).

Feature goal:
- parse pasted tabular/free-text examples with LLM
- preview/edit drafts
- import selected rows in batch

## Architecture

### Frontend

- Modal component:
  - `react/src/components/datasets/DatasetAiImportModal.tsx`
- API bindings:
  - `react/src/components/prompts/promptApi.ts`
  - `react/src/components/datasets/datasetApi.ts`
- Types:
  - `react/src/components/datasets/datasetTypes.ts`

Flow:
1. `parseCasesFromText(...)`
2. local review/edit/select
3. `importParsedCases(...)`

### Backend

- AJAX entrypoints in:
  - `server/ajax/AjaxLlmPromptLab.php`
- Actions:
  - `parse_cases_from_text`
  - `import_parsed_cases`

Services:
- `LlmDatasetAiImportParserService`
  - calls LLM parser prompt
  - strict JSON decode with tolerant candidate extraction
  - repair pass for malformed JSON output
- `LlmDatasetAiImportMapperService`
  - normalizes parser payload to internal draft case shape
  - auto-maps imported variable keys to active prompt placeholders for `form_runtime`
- `LlmDatasetBatchImportService`
  - validates and persists selected drafts using dataset service methods

## Prompt assets

Registry:
- `server/service/prompt/LlmPromptAssetRegistry.php`

Keys:
- `core.dataset_import.system`
- `core.dataset_import.repair_json`

Files:
- `assets/prompts/core/dataset-import/system.md`
- `assets/prompts/core/dataset-import/repair-json.md`

Fail-closed behavior:
- missing/unreadable asset throws exception and aborts parsing.

## Data model and provenance

Lookup source type (SQL seed):
- `ai_text_import` in `llm_eval_source_types`

Case fields saved:
- `title`
- `case_type`
- `source_type = ai_text_import`
- `input_payload_json`
- `expected_output_json`
- `notes`
- optional `tags`

`input_payload_json` intentionally keeps full replay/debug structure (execution profile, owner descriptor, variables, message history, trigger message, runtime overrides).

## Parser reliability strategy

`LlmDatasetAiImportParserService` uses:

1. larger parser token floor (`max_tokens >= 4096`) for multi-row imports
2. candidate JSON extraction:
   - raw content
   - fenced JSON blocks
   - balanced embedded JSON fragment
3. trailing-comma cleanup attempt
4. repair pass via `core.dataset_import.repair_json` when initial parse fails

If all steps fail, action returns a deterministic error.

## Form-runtime replay reliability

Root issue fixed in v1.1.0:

- replay interpolation filters to prompt placeholders
- imported rows may use different variable keys
- resulting filtered payload can become empty and degrade to fallback user text (`Form submission`)

Mitigation now has two layers:

1. AI import mapper enriches variables with placeholder-compatible keys via alias/similarity matching.
2. Replay path falls back to normalized variables if placeholder filtering still yields empty input.

## Security and constraints

- Uses existing Prompt Lab ACL checks (`select`/`update`) in `AjaxLlmPromptLab`.
- Uses CSRF validation for mutating actions.
- Dataset lock checks are enforced in dataset service paths.
- Import is descriptor/dataset scoped; no cross-scope insert path.

## Extending

Recommended extension points:

- mapping normalization:
  - update `LlmDatasetAiImportMapperService::normalizeVariables(...)`
  - update expected output mapping in `normalizeExpectedOutput(...)`
- parser extraction policy:
  - update prompt assets only (preferred first)
- additional draft validation:
  - add checks in batch import service before persistence

Keep prompt text in assets (not inline PHP) to preserve clean diffs for prompt-only changes.
