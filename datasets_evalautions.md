# Datasets And Evaluations Plan For `sh-shp-llm`

## Goal

Extend the prompt-lab system with two new capabilities:

- first-class datasets
- first-class evaluations

This should allow editors and developers to:

- save curated prompt test cases as reusable datasets
- build datasets from real submitted user data and real conversation traces
- replay those cases against different prompt versions or prompt drafts
- compare prompt variants on many cases at once
- score outputs with automatic checks, rubric-based LLM judges, and human review
- use the same architecture later inside `sh-shp-llm_therapy_chat`

This document is implementation-focused and intentionally contains no code.

## Main Product Idea

The current prompt lab is good for:

- one draft
- one prompt
- one test run
- one compare run

Datasets and evaluations add the next layer:

- one dataset
- many cases
- many prompt versions or drafts
- repeatable scores
- regression protection

In practice this turns:

- "this looked good in the playground"

into:

- "this prompt passed 38 of 40 real cases and improved safety classification on last pilot-study data"

## Core Use Cases

### 1. Manual golden dataset

An editor creates a dataset by hand with important examples:

- common support questions
- risky edge cases
- structured form submissions
- multilingual prompt examples

This is the stable benchmark set.

### 2. Build dataset from real submitted data

This is the feature you called out and yes, it should absolutely exist.

The system should let an editor create dataset cases from:

- existing form submissions
- existing `llm_scripts` test variables / runtime data
- existing `llmConversations` and `llmMessages`
- existing logged prompt-lab runs

This is not a separate unrelated feature.
This should be one dataset-ingestion path inside the dataset system.

### 3. Replay historical real-world inputs against a new prompt

Example:

- a pilot study already collected form submissions or chat messages
- the team improves the prompt later
- the team reruns those same historical cases against:
  - current production prompt
  - draft prompt
  - prompt version A
  - prompt version B

This is one of the highest-value features because it makes your real data reusable for safe prompt iteration.

### 4. Evaluate candidate prompts before rollout

Before saving or promoting a prompt version, the editor should be able to run:

- the current dataset
- the golden dataset
- a filtered dataset subset

against the selected prompt and review scores.

### 5. Human review for subjective quality

For cases where "correct" is not deterministic, a reviewer should be able to score:

- tone
- helpfulness
- faithfulness
- safety
- therapeutic appropriateness later in the therapy plugin

## Important Clarification

The feature you described:

- "run in the playground tests based on the data already that was submitted and what the users typed"

should be treated as:

- dataset case creation from real system data
- plus dataset replay inside the prompt lab

So yes, this belongs under datasets, not as a completely separate tool.

## Design Principles

- reuse the current prompt-lab architecture instead of creating a second testing system
- keep canonical LLM request/response logging in `llmConversations` and `llmMessages`
- use a small optional dataset/evaluation layer on top
- keep runtime composition owner-aware and profile-aware
- support both curated golden cases and replay-from-production cases
- keep files small and responsibilities separate
- keep React UI simple and Bootstrap 4.6 friendly
- avoid duplicating logic between chat, form, script, and later therapy owners

## Fit With Current Prompt-Lab Architecture

Datasets and evaluations should sit on top of the existing prompt-lab system:

- prompt registry stays the source of prompt versions
- execution profiles still define how runtime requests are composed
- owner adapters still build effective prompt/messages exactly like production
- prompt-lab AJAX remains the entry point pattern
- central request logging remains in `llmMessages`

New layer to add:

- dataset definitions
- dataset cases
- evaluation definitions
- evaluation runs and scores

## Recommended Concept Model

### Dataset

A dataset is a named collection of cases for a specific execution profile or owner type.

Examples:

- `llmChat Safety Regression Set`
- `Pilot Study 2026-03 Intake Forms`
- `Script 45 Real Production Cases`
- `Therapy Draft Review Set`

### Dataset case

A dataset case is one replayable input example.

It should store:

- source type
- source reference
- normalized runtime input
- optional expected output or expected labels
- tags / notes

### Evaluation definition

An evaluation definition describes how outputs should be scored.

Examples:

- `json_validity`
- `required_fields_present`
- `safety_label_match`
- `llm_judge_helpfulness`
- `human_review_clinical_quality`

### Evaluation run

An evaluation run means:

- one dataset
- one prompt candidate or prompt version
- one or more evaluators
- results stored per case and aggregated at run level

## Recommended Dataset Types

Use one dataset system with a `dataset_type` field rather than separate architectures.

Suggested dataset types:

- `golden_manual`
- `production_replay`
- `pilot_study_replay`
- `conversation_replay`
- `form_submission_replay`
- `script_fixture`

This keeps the data model unified while allowing different ingestion and UI flows.

## Recommended Case Types

Suggested case types:

- `chat_case`
- `form_case`
- `script_case`
- `text_only_case`

Each case type maps to one execution profile.

## Production Replay Idea

This is the most important addition for your system.

### Replay from real form submissions

For `llmForm`, a dataset case should capture:

- form submission values
- owner/style metadata
- language
- runtime companion fields snapshot
- original prompt version if known
- original produced output if available

Then later a new prompt can be tested against the same form input.

### Replay from real chat/conversation history

For `llmChat`, a dataset case should capture:

- message history
- the triggering user message
- effective language context
- relevant runtime config snapshot
- original assistant response if available

Then later a different prompt or prompt version can be rerun against that same conversation slice.

### Replay from scripts

For `llm_scripts`, a dataset case should capture:

- variables
- `data_config` resolution snapshot or fixture
- companion config snapshot
- original output if available

### Why this matters for studies

Yes, this directly supports your pilot-study scenario:

- collect real data
- freeze it into a dataset
- iterate prompts later
- rerun many prompt variants
- compare outputs and scores
- build better final prompts before broader rollout

This is one of the strongest reasons to add datasets.

## Recommended Data Model

Keep this separate from the prompt registry tables.

### 1. `llm_eval_datasets`

Purpose:

- one dataset definition

Suggested columns:

- `id`
- `name`
- `description`
- `dataset_type`
- `id_lookups_execution_profile`
- `owner_type_scope` nullable
- `owner_id_scope` nullable
- `is_locked`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Notes:

- `dataset_type` should be lookup-backed
- lock datasets when they become important benchmarks

### 2. `llm_eval_dataset_cases`

Purpose:

- one reusable replay case

Suggested columns:

- `id`
- `id_llm_eval_datasets`
- `case_key`
- `case_type`
- `title`
- `input_payload_json`
- `expected_output_json` nullable
- `expected_labels_json` nullable
- `source_type` nullable
- `source_ref_json` nullable
- `tags_json` nullable
- `notes` nullable
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Important:

- `input_payload_json` should store normalized replay input, not raw random database dumps
- `source_ref_json` should still remember where the case came from

### 3. `llm_eval_definitions`

Purpose:

- define reusable evaluators

Suggested columns:

- `id`
- `name`
- `eval_type`
- `description`
- `config_json`
- `is_active`
- `created_by`
- `updated_by`
- `created_at`
- `updated_at`

Suggested `eval_type` values: ALL EVAL TYPES ARE lawasy in lookups table.

- `programmatic`
- `llm_judge`
- `human_review`

### 4. `llm_eval_runs`

Purpose:

- top-level run record

Suggested columns:

- `id`
- `id_llm_eval_datasets`
- `target_type`
- `target_ref_json`
- `run_mode`
- `status`
- `summary_json`
- `created_by`
- `created_at`
- `completed_at`

`target_ref_json` examples:

- draft prompt from current editor
- active prompt version id
- specific prompt version id
- compare group of prompt versions

### 5. `llm_eval_run_cases`

Purpose:

- one case execution inside one run

Suggested columns:

- `id`
- `id_llm_eval_runs`
- `id_llm_eval_dataset_cases`
- `id_llmConversations` nullable
- `id_llmMessages_request` nullable
- `id_llmMessages_response` nullable
- `output_payload_json`
- `normalized_output_json`
- `created_at`

### 6. `llm_eval_scores`

Purpose:

- store detailed per-case scores

Suggested columns:

- `id`
- `id_llm_eval_run_cases`
- `id_llm_eval_definitions`
- `score_type`
- `score_value_numeric` nullable
- `score_value_label` nullable
- `passed` nullable
- `details_json` nullable
- `created_by` nullable
- `created_at`

This allows:

- many evaluations per case
- both machine and human scores

## Lookup Strategy

Use `lookups` for enum-like values:

- dataset types
- case types
- evaluation types
- run statuses
- execution profiles if you want alignment with prompt-lab profiles

## Normalized Case Payload Strategy

Do not store cases as vague blobs only.
Store a normalized payload shape per execution profile.

### `chat_runtime`

Recommended case payload:

```json
{
  "execution_profile": "chat_runtime",
  "owner_descriptor": {
    "owner_type": "style_field",
    "owner_id": 123,
    "prompt_slot": "conversation_context",
    "id_languages": 1
  },
  "message_history": [
    { "role": "user", "content": "..." },
    { "role": "assistant", "content": "..." }
  ],
  "trigger_message": "User's last message",
  "runtime_overrides": {},
  "source_context": {
    "id_llmConversations": 45,
    "message_window": "last_12"
  }
}
```

### `form_runtime`

Recommended case payload:

```json
{
  "execution_profile": "form_runtime",
  "owner_descriptor": {
    "owner_type": "style_field",
    "owner_id": 456,
    "prompt_slot": "llm_context",
    "id_languages": 1
  },
  "variables": {
    "reflection": "I struggled this week ..."
  },
  "form_data": {
    "reflection": "I struggled this week ...",
    "stress_level": "8"
  },
  "runtime_overrides": {}
}
```

### `script_runtime`

Recommended case payload:

```json
{
  "execution_profile": "script_runtime",
  "owner_descriptor": {
    "owner_type": "llm_script",
    "owner_id": 45,
    "prompt_slot": "script",
    "id_languages": 1
  },
  "variables": {
    "name": "Stefan"
  },
  "runtime_overrides": {
    "data_config": { }
  }
}
```

This lets one shared replay engine run all case types without custom spaghetti.

## Evaluation Types

Start with three evaluator families.

### 1. Programmatic evaluators

Use for objective checks.

Examples:

- JSON valid
- required fields present
- required enum values used
- max length not exceeded
- no banned phrase
- exact label match
- exact boolean safety match

These should be the first evaluators you build because they are stable and cheap.

### 2. LLM-judge evaluators

Use for semi-subjective quality.

Examples:

- helpfulness
- clarity
- faithfulness
- empathy
- instruction adherence
- therapeutic tone later

These evaluators should return structured score payloads, not prose only.

Recommended output:

```json
{
  "score": 4,
  "passed": true,
  "label": "good",
  "reason": "The answer is empathetic and follows the requested structure."
}
```

### 3. Human-review evaluators

Use for subjective or high-risk outputs.

Examples:

- approve / reject
- 1-5 quality score
- safety concern label
- clinically acceptable yes/no later

These should be stored in the same `llm_eval_scores` table so reporting stays unified.

## Who Evaluates What

### Engineers

Own:

- parser validity
- schema checks
- regression protection
- cost/latency thresholds

### CMS editors / content owners

Own:

- tone
- usefulness
- wording preference
- content quality

### Researchers or therapists later

Own:

- appropriateness
- safety
- faithfulness to intervention rules
- risk-sensitive output review

The system should support all three, not assume only one evaluator type.

## Recommended Backend Architecture

Keep this modular and small.

### New services

- `LlmDatasetService`
- `LlmDatasetIngestionService`
- `LlmDatasetReplayService`
- `LlmEvaluationDefinitionService`
- `LlmEvaluationRunnerService`
- `LlmEvaluationScoringService`
- `LlmEvaluationAggregationService`
- `LlmEvaluationReviewService`

### Responsibilities

#### `LlmDatasetService`

- CRUD for datasets and cases
- search/filter/tag datasets
- lock/archive datasets

#### `LlmDatasetIngestionService`

- create cases from real system data
- normalize source records into replay payloads
- support:
  - form submission -> dataset case
  - chat conversation slice -> dataset case
  - script run -> dataset case
  - prompt-lab run -> dataset case

#### `LlmDatasetReplayService`

- replay one dataset case through the existing owner execution pipeline
- reuse execution profile services and owner adapters
- no duplicate runtime composition code

#### `LlmEvaluationDefinitionService`

- load evaluator configs
- validate evaluator schemas

#### `LlmEvaluationRunnerService`

- orchestrate a dataset run
- call replay service for each case
- run selected evaluators
- persist run/case/score records

#### `LlmEvaluationScoringService`

- dispatch evaluator implementations
- support:
  - programmatic
  - llm_judge
  - human_review placeholder state

#### `LlmEvaluationAggregationService`

- compute run-level summary:
  - pass rate
  - average score
  - failure buckets
  - score deltas vs baseline

#### `LlmEvaluationReviewService`

- manage reviewer assignments and manual scores later

## Reuse of Existing Prompt-Lab Components

Do not build a separate second UI stack.

Reuse:

- prompt owner descriptors
- execution profiles
- prompt response normalization
- central LLM logging
- compare-mode logic patterns

New UI should extend prompt lab, not replace it.

## Recommended Request Flow

### Dataset creation from current playground run

1. User runs a playground test
2. UI shows "Add to dataset"
3. Backend converts the run into normalized case payload
4. User selects existing dataset or creates a new one
5. Case is stored with source reference back to the original log

### Dataset creation from production records

1. User opens a dataset-import modal
2. User selects source type:
   - conversation history
   - form submissions
   - script runs
3. Backend lists selectable source records
4. User selects records
5. Backend normalizes them into dataset cases
6. Cases are added to chosen dataset

### Evaluation run

1. User selects dataset
2. User selects target prompt:
   - active version
   - chosen version
   - current draft
3. User selects evaluators
4. Backend replays each case through existing owner runtime logic
5. Scores are stored
6. UI shows summary + failed cases + per-case details

## Recommended AJAX / Endpoint Pattern

Follow the same dedicated endpoint style as prompt lab.

Suggested endpoint classes:

- `AjaxLlmDatasetLab`
- `AjaxLlmEvaluationLab`

Suggested actions:

### Dataset actions

- `list_datasets`
- `get_dataset`
- `create_dataset`
- `update_dataset`
- `list_dataset_cases`
- `add_case_from_playground_run`
- `add_cases_from_source`
- `get_import_candidates`
- `delete_dataset_case`

### Evaluation actions

- `list_eval_definitions`
- `run_dataset_eval`
- `get_eval_run`
- `list_eval_run_cases`
- `save_human_score`

Keep ACL and CSRF rules aligned with prompt lab.

## Recommended React UI Plan

Keep it clean and reusable with Bootstrap 4.6.

### 1. Dataset browser

Simple reusable page/modal component with:

- dataset list
- search
- tags
- owner/execution-profile filters
- create dataset button

### 2. Dataset case table

Use a straightforward table/card hybrid:

- case title
- type
- source
- tags
- expected label summary
- actions:
  - preview
  - remove
  - rerun

### 3. Import modal

This is the important new workflow.

Tabs or segmented controls:

- `From playground runs`
- `From form submissions`
- `From conversations`
- `From scripts`

Each tab should use a small reusable candidate list component.

### 4. Evaluation runner modal

Recommended controls:

- dataset selector
- target prompt selector
- evaluator checklist
- optional baseline comparator
- run button

### 5. Evaluation results view

Recommended summary blocks:

- total cases
- pass rate
- average score
- failed cases count
- score delta vs baseline

Then a cases table below:

- case
- output preview
- scores
- pass/fail
- inspect details

### 6. Case detail drawer/modal

Show:

- normalized input
- effective context/messages
- returned output
- expected labels
- evaluator results
- source references

### 7. Prompt-lab integration

Inside existing prompt lab:

- add `Run Dataset`
- add `Add To Dataset`
- add `Replay Historical Cases`

This keeps the system cohesive.

## UI Style Guidance

- Bootstrap 4.6 layout first
- small reusable components
- light custom CSS only when needed
- keep heavy visual logic in shared components
- avoid building separate styles for each runtime type

Recommended React file areas:

- `react/src/components/datasets/`
- `react/src/components/evaluations/`
- `react/src/components/shared/`

Suggested dataset components:

- `DatasetBrowser.tsx`
- `DatasetTable.tsx`
- `DatasetCaseTable.tsx`
- `DatasetImportModal.tsx`
- `DatasetCasePreviewModal.tsx`

Suggested evaluation components:

- `EvaluationRunnerModal.tsx`
- `EvaluationResultsView.tsx`
- `EvaluationSummaryCards.tsx`
- `EvaluationCaseTable.tsx`
- `HumanReviewPanel.tsx`

Suggested service-facing utilities:

- `datasetApi.ts`
- `evaluationApi.ts`
- `datasetTypes.ts`
- `evaluationTypes.ts`

## Reuse Strategy For Small Files

To keep the codebase maintainable:

- one ingestion service per source family only if complexity really differs
- one replay engine shared across all dataset cases
- one score dispatcher with small evaluator implementations
- one summary/aggregation service
- React tables and modals should be generic and configured by props
- case preview component should reuse existing prompt result and effective-context panels

Avoid:

- one full UI stack per owner type
- one evaluation system for scripts and another for forms
- owner-specific copy-paste replay logic

## Execution Profile Reuse

Do not invent a second replay mechanism.

Dataset replay should call the same runtime-aware execution logic already planned for prompt lab:

- `chat_runtime`
- `form_runtime`
- `script_runtime`
- later therapy runtimes

That keeps:

- context composition consistent
- parser behavior consistent
- response rendering consistent
- logs consistent

## Expected Output Strategy

Not every case needs a full expected answer.

Support several expectation styles:

- exact expected output
- expected labels only
- expected structured fields only
- no expected output, only qualitative scoring

Examples:

- form case: require JSON field presence and exact label match
- chat case: require safety category and use helpfulness score
- therapy draft later: require human review and safety pass

## Programmatic Evaluator Examples For Your System

### `llmForm`

- JSON parse success
- required fields present
- no extra forbidden field
- target language match

### `llmChat`

- no empty response
- safety object exists
- detected risk label matches expectation when dataset provides one
- blocked-message branch triggered when expected

### `llm_scripts`

- output parsable
- placeholders resolved
- expected key values present

## Human Review Workflow

This can be phase 2, but the plan should leave room for it now.

Minimal future workflow:

1. evaluation run marks some cases as needing human review
2. reviewer opens results screen
3. reviewer scores selected cases
4. scores become part of aggregated run result

For therapy later, this is especially important.

## Comparison With Current CMS Workflow

Current workflow:

- edit prompt
- test manually
- inspect response
- save

Planned dataset/eval workflow:

- edit prompt
- run dataset replay against real or curated cases
- score automatically
- inspect failures
- optionally request human review
- compare against previous prompt version
- then save or promote

This is the practical quality upgrade.

## Phased Rollout

### Phase 1. Dataset foundation

- dataset and case tables
- dataset browser
- add case from playground run
- replay dataset through prompt-lab target prompt
- no human review yet

### Phase 2. Production replay import

- import cases from form submissions
- import cases from conversations
- import cases from script runs
- source normalization service

### Phase 3. Programmatic evaluations

- required fields
- JSON validity
- label match
- pass/fail aggregation

### Phase 4. LLM-judge evaluators

- helpfulness
- faithfulness
- empathy / tone

### Phase 5. Human review

- reviewer UI
- manual annotation scores
- filtered review queues

### Phase 6. Therapy plugin expansion

- therapy conversation replay
- therapy draft and summary evaluation
- therapist review workflows

## Security And Access Control

Use the same explicit ACL and CSRF discipline as prompt lab.

Rules:

- dataset creation/import requires update-level access
- evaluation runs require update-level access because they spend tokens and expose real data
- human review save actions require explicit update access
- import from production data must respect owner/page/module ACL, not only dataset ACL

## Logging And Audit

Keep canonical request logging in:

- `llmConversations`
- `llmMessages`

Dataset/evaluation tables should store links back to canonical logs.

Transactions should log:

- dataset created
- dataset locked
- evaluation run started
- evaluation run completed
- major human-review actions later

## Documentation Needed

When this work starts, also add:

- user guide for creating datasets
- user guide for replaying real submitted data
- developer guide for normalized payload shapes
- evaluator authoring guide
- migration notes

## Acceptance Criteria

The feature should be considered complete only if:

- datasets are reusable objects, not just saved playground results
- real submitted form/chat/script data can be turned into dataset cases
- dataset cases replay through the same runtime composition path as production
- one dataset can be run against multiple prompt versions or drafts
- evaluations support objective checks first
- the architecture leaves clean room for LLM judges and human review
- UI remains simple, Bootstrap-friendly, and reusable
- backend logic stays modular with no duplicated owner-specific replay code
- the design can later be reused in `sh-shp-llm_therapy_chat`

## Final Recommendation

The best implementation path is:

- treat "replay real submitted data" as a dataset-ingestion feature
- build one normalized dataset-case system for all execution profiles
- reuse prompt-lab execution profiles and owner adapters for replay
- start with dataset replay plus programmatic evaluators
- add LLM-judge and human review later
- keep UI inside the existing prompt-lab family instead of inventing a second tool

If built this way, the feature will fit SelfHelp CMS well:

- modular
- small-file friendly
- runtime-accurate
- reusable across owners
- ready for later therapy plugin expansion

## Changelog
 - add everything to v1.1.0. We are still workign on it
 - add what was done and implemented - clean and feature based changelog.
 - use 1.1.0.sql file. The file shoudlbe possiible to be rerun multipel times. Use our helper sotred precdeurs if needed

## Rule: Windows command safety for the agent

Commands generated for Windows CMD or PowerShell must stay well below the 8191 character limit. Prefer a maximum of 7000 characters to avoid truncation issues.

If a command may exceed this limit, the agent must:

split execution into multiple commands, or

use an arguments/response file (e.g. @args.txt), or

move configuration into files instead of CLI parameters.
