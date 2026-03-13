# Prompt Lab Migration Notes For v1.1.0

## Summary

Version `1.1.0` expands the plugin in four major areas:

- multi-server API key management
- prompt registry and versioning
- runtime-aware prompt playground and builder
- datasets and evaluations

## New schema

### Prompt registry

- `llm_prompt_entries`
- `llm_prompt_locales`
- `llm_prompt_versions`
- `llm_prompt_playground_runs`
- `llm_scripts.id_llm_prompt_entries`

### Datasets and evaluations

- `llm_eval_datasets`
- `llm_eval_dataset_cases`
- `llm_eval_definitions`
- `llm_eval_runs`
- `llm_eval_run_cases`
- `llm_eval_scores`

## New lookup groups

- `llm_prompt_owner_types`
- `llm_prompt_run_modes`
- `llm_eval_dataset_types`
- `llm_eval_execution_profiles`
- `llm_eval_case_types`
- `llm_eval_source_types`
- `llm_eval_types`
- `llm_eval_run_modes`
- `llm_eval_run_statuses`

## Rerun behavior

`server/db/v1.1.0.sql` is intended to be rerunnable.

It uses:

- `INSERT IGNORE` for lookup and seed rows
- `CREATE TABLE IF NOT EXISTS` for new tables
- helper procedures such as `add_index` and `add_unique_key` to converge key/index state on reruns

## Operational notes

- Prompt-lab requests continue to log canonically in `llmConversations` and `llmMessages`.
- Dataset replay adds prompt-lab summary rows but does not replace canonical LLM logs.
- Evaluation runs can spend tokens and should be treated like update-level actions.

## Follow-up

After applying the migration:

1. Rebuild frontend bundles.
2. Verify the `ajax_llm_prompt_lab` page exists and has ACLs.
3. Confirm that prompt fields and scripts expose `Playground`, `Datasets`, and `Build With AI`.
