# Global User Memory Design for `sh-shp-llm`

## 1. Goal

We need one global user memory system that fits the existing SelfHelp architecture and can be configured easily by admins.

The end goal is:

- memory is global per user, not per `llmChat` section
- memory can be updated from different user actions
- admins can easily configure:
  - when memory is updated
  - which LLM prompt/rule is used
  - whether memory is saved as `record`, `log`, or `both`
  - where memory is later used
- admins can inspect:
  - the current memory of a user
  - the memory history over time
  - which action/rule created each update

This document is the implementation plan.

---

## 2. Existing System Reality

This design must follow the actual system flows that already exist.

### 2.1 Core form submissions

Core `formUserInput` submissions go through:

- `FormUserInputController::execute()`
- `FormUserInputModel::save_user_input()` or `update_user_input()`
- `UserInput::save_data()`
- `UserInput::queue_job_from_actions()`

Important consequence:

- core forms already have a generic extensibility point through `formActions`
- after a successful save, actions/jobs can already be queued
- this is the best existing integration point for memory updates

### 2.2 SurveyJS submissions

`surveyJS` also ends in:

- `SurveyJSModel::save_survey()`
- `UserInput::save_data()`

Important consequence:

- survey submissions also automatically fit the same `formActions`/job scheduling model

### 2.3 LLM form submissions

`llmChat` form mode currently goes through:

- `LlmChatController::handleFormSubmission()`
- `LlmDataSavingService::saveFormData()`
- `UserInput::save_data()` when `enable_data_saving = 1`

Important consequence:

- if LLM form data saving is enabled, it also lands in the same `UserInput::save_data()` pipeline
- therefore the generic form-action/job system can also be reused here
- if LLM form data saving is disabled, we need an additional direct memory trigger path from the controller

### 2.4 Login and profile changes

These do not pass through `UserInput::save_data()`.

Relevant existing methods:

- `Login::update_timestamp()`
- `ProfileModel::change_user_name()`

Important consequence:

- these need hook-based integration using the existing hook system

### 2.5 LLM async execution

The plugin already has an async background execution pattern:

- `LlmHooks::execute_llm_task()`
- `llm_async_worker.php`
- refresh events for UI updates

Important consequence:

- memory generation should reuse this async pattern
- source actions must not wait for the LLM response

---

## 3. Main Design Decision

The global memory system should be implemented as a **module-level capability of `sh_module_llm`**, not as a property of one chat style.

### Why

- multiple `llmChat` sections may exist, but memory must remain one per user
- core forms and surveys are outside `llmChat`
- login and profile changes are global events
- module config is already the right place for shared LLM behavior

So the design should have:

- module-level memory configuration
- reusable global memory rules
- lightweight local bindings from styles/forms to those rules

---

## 4. Product Model

We define **Global Memory** as a reusable user-level data product with:

- one current effective state
- optional change history
- one or more update rules
- optional usage bindings into LLM contexts and other styles

### 4.1 Vocabulary

#### Memory Definition
The top-level module configuration for global memory.

#### Memory Rule
A reusable definition that explains:

- which event can update memory
- how to build the input payload
- which LLM prompt/template to use
- how to save the output

#### Memory Update
One executed application of a rule to one user event.

#### Effective Memory
The latest memory state that other components use.

---

## 5. Storage Design

Memory must use the standard SelfHelp data structure so it remains compatible with:

- Data admin
- `data_config`
- interpolation
- transaction and action patterns

### 5.1 Tables

Use normal `dataTables`-backed storage.

There are two logical tables:

#### 1. Current Memory Table
Purpose:

- store the current effective memory per user
- used for interpolation and LLM context loading

Default name:

- `llm_memory`

Recommended columns:

- `id_users`
- `memory_key`
- `memory_text`
- `memory_json`
- `memory_version`
- `last_rule_key`
- `last_source_type`
- `last_source_ref`
- `last_trigger_type`
- `last_payload_json`
- `last_updated_at`
- dynamic flattened columns from `flat_fields`

Expected write mode:

- record

#### 2. Memory History Table
Purpose:

- store every successful memory update event
- support timeline/history UI
- allow audit/debug/rebuild

Default name:

- `llm_memory_history`

Recommended columns:

- `id_users`
- `memory_key`
- `memory_text`
- `memory_json`
- `prev_memory_json`
- `rule_key`
- `source_type`
- `source_ref`
- `trigger_type`
- `payload_json`
- `change_summary`
- `worker_status`
- `created_at`
- dynamic flattened columns from `flat_fields`

Expected write mode:

- log

### 5.2 Storage Mode

This must be configurable because the system already uses the concepts `record` and `log`, and you explicitly want the ability to overwrite or accumulate history.

Supported storage modes:

- `record`
  - write only to current memory table
  - effective memory is directly stored there
- `log`
  - append only to history table
  - effective memory must be resolved as latest valid history row
- `both`
  - update current memory table and append history row
  - this should be the default because it gives fast reads and full history

  Do these as lookp_values, then new field with hooks to be loaded in the CMS for selection. 

### 5.3 Recommendation

Default the system to:

- global memory enabled
- storage mode = `both`

Reason:

- best UX for interpolation and LLM context loading
- preserves history for inspection
- matches your need to see memory over time

### 5.4 Flattened Fields

The worker output should contain:

- `memory_text`
- `memory_object`
- `flat_fields`
- `change_summary`

`flat_fields` should be written as normal columns in whichever table(s) are used.

Example:

```json
{
  "memory_text": "User prefers short motivational guidance and is working on sleep regularity.",
  "memory_object": {
    "preferred_tone": "short_motivational",
    "main_goal": "sleep regularity",
    "risk_level": "low"
  },
  "flat_fields": {
    "preferred_tone": "short_motivational",
    "main_goal": "sleep regularity",
    "risk_level": "low"
  },
  "change_summary": "Updated main goal from stress reduction to sleep regularity."
}
```

This keeps memory easy to use in:

- `data_config`
- markdown interpolation
- reports
- memory admin UI

---

## 6. Configuration Design

## 6.1 Module-Level Config

Add global memory fields to `sh_module_llm`.

Important constraint:

- current LLM config loading reads only fields prefixed with `llm_`
- all new module fields must therefore start with `llm_`

Recommended module fields:

- `llm_memory_enabled`
- `llm_memory_key`
  - default: `global`
- `llm_memory_storage_mode`
  - enum: `record | log | both`
  - default: `both`
- `llm_memory_table_name`
  - default: `llm_memory`
- `llm_memory_history_table_name`
  - default: `llm_memory_history`
- `llm_memory_rules`
  - JSON registry of reusable rules
- `llm_memory_admin_enabled`
  - enable memory admin tab/page

### 6.2 Rule Registry

`llm_memory_rules` is the central configuration registry.

Each rule should define:

- `key`
- `label`
- `enabled`
- `memory_key`
  - default `global`
- `source_type`
- `source_match`
- `trigger_types`
- `storage_mode_override`
  - optional `record | log | both`
- `data_config`
- `prompt_template`
- `llm_model`
- `llm_temperature`
- `llm_max_tokens`
- `refresh_sections`
- `usage_tags`
  - optional helper labels for later selection in UI

### 6.3 Supported `source_type` Values in v1

#### Generic, system-fitting sources

- `form_action_submit`
  - canonical source for all forms that end in `UserInput::save_data()`
- `llm_chat_form_submit`
  - direct path for `llmChat` dynamic form submissions, especially when data saving is disabled
- `login`
- `profile_name_change`

### 6.4 `source_match`

This defines where a rule applies.

Examples:

#### For `form_action_submit`
- `table_name`
- `table_id`
- `form_name`
- optional `section_id`

#### For `llm_chat_form_submit`
- `section_id`
- `section_name`

#### For `login`
- typically empty or global

#### For `profile_name_change`
- typically empty or global

### 6.5 `trigger_types`

For data-table-based submissions, reuse the system trigger vocabulary:

- `started`
- `finished`
- `updated`
- `deleted`

Defaults:

- core forms: `finished`
- surveys: commonly `finished`, optionally `updated`
- login/profile: not used

---

## 7. Trigger Architecture

This is the most important system-fit part.

## 7.1 Canonical Trigger Path for Forms

For all forms that save through `UserInput::save_data()`, the preferred memory integration must be:

1. user submits form
2. data is saved normally
3. existing `UserInput::queue_job_from_actions()` runs
4. one new custom job type is available in form actions:
   - `llm_memory_update`
5. that job executes asynchronously and updates memory

### Why this is the best fit

- core forms already use it
- surveyJS already uses it
- `llmChat` with data saving already uses it
- admins already understand form actions
- no parallel configuration system is needed for most forms

This means memory becomes easy to use on any form in the system:

- create or edit a form
- add a form action
- choose job type `llm_memory_update`
- select a memory rule key

## 7.2 Direct Trigger Path for `llmChat`

`llmChat` needs an additional direct trigger path because form mode can exist even when `enable_data_saving = 0`.

Therefore:

- if `enable_data_saving = 1`
  - memory can be triggered via form actions on the saved `llmChat_*` table
- if `enable_data_saving = 0`
  - `LlmChatController::handleFormSubmission()` should dispatch the memory update directly after successful form parsing/message creation

This direct path should still use the same memory rule model and the same async worker.

## 7.3 Login Trigger

Use hook integration on:

- `Login::update_timestamp()`

Behavior:

- run original logic first
- if login succeeded, enqueue matching memory rules asynchronously

## 7.4 Profile Name Trigger

Use hook integration on:

- `ProfileModel::change_user_name()`

Behavior:

- run original logic first
- if name change succeeded, enqueue matching memory rules asynchronously
- include both old and new values in payload

---

## 8. New Job Type Design

To fit the existing `UserInput` action/job architecture, add a new job type:

- `llm_memory_update`

This should be implemented similarly to existing `llm_script` integration:

- add new job type to job config schema
- add hook support for task execution
- allow admins to configure it in form actions

### 8.1 Job Config Shape

Recommended config fields:

- `type = llm_memory_update`
- `memory_rule_key`
- optional `memory_key_override`
- optional `force_storage_mode`
- optional `run_async`
  - default true

### 8.2 Job Payload Source

For form action jobs, the job receives standard form payload from `UserInput`.

This gives:

- form/table identity
- trigger type
- record id
- submitted fields
- user id

That is exactly the right input for memory building.

---

## 9. Async Worker Design

All LLM-based memory generation must run in the background.

### 9.1 Why

These source actions must stay fast:

- form submission
- survey submission
- chat form submission
- login
- profile update

### 9.2 Worker Flow

Each memory update should do this:

1. resolve the memory rule
2. resolve effective `memory_key`
3. load current effective memory for the user
4. normalize source payload
5. resolve optional `data_config`
6. build the interpolation variables
7. interpolate `prompt_template`
8. call the LLM asynchronously
9. validate result structure
10. persist according to storage mode
11. write refresh events if needed
12. log worker result for audit/debug

### 9.3 Validation

The output contract must be strict.

Required fields:

- `memory_text`
- `memory_object`
- `flat_fields`
- `change_summary`

If validation fails:

- do not overwrite effective memory
- do not append history row
- store worker failure logs

---

## 10. Prompt and Payload Design

Each memory rule needs its own prompt because login, surveys, and forms all have different payload semantics.

This is required for good memory quality.

### 10.1 Standard Variables Available to the Prompt

All rule prompts should be able to interpolate:

- `{{memory_key}}`
- `{{memory_text}}`
- `{{memory_json}}`
- `{{source_type}}`
- `{{source_ref}}`
- `{{trigger_type}}`
- `{{event_payload_json}}`
- resolved `data_config` fields

### 10.2 Event-Specific Variables

#### `form_action_submit`
- submitted field values
- `record_id`
- `form_name`
- `table_name`
- `trigger_type`

#### `llm_chat_form_submit`
- `conversation_id`
- `message_id`
- `section_id`
- generated readable text
- parsed form values

#### `login`
- `user_name`
- `last_login`

#### `profile_name_change`
- `old_name`
- `new_name`

### 10.3 Prompt Template Requirement

Each prompt should explicitly tell the model:

- what the source event means
- whether to merge, replace, or ignore information
- how to avoid bloating the memory
- how to keep only stable, useful user facts

---

## 11. Usage Design

Admins need easy control over where memory is used.

## 11.1 Usage Channels

Memory should be usable in two ways:

### A. `data_config`
Any style can load memory data through standard `data_config`.

This is the only runtime usage path for prompts and content generation.

This is the most system-native reuse path and keeps memory usage explicit.

### B. Admin Inspection UI
Memory can be viewed in a dedicated UI.

## 11.2 `llmChat` Usage

`llmChat` should not have a hidden or automatic memory injection feature.

Instead, memory should be loaded only through explicit `data_config` interpolation in the same way as other reusable data in the system.

Recommended behavior:

- admins add memory into the prompt/context field through `data_config`
- admins decide exactly which memory fields are loaded
- admins can add explanatory wrapper text around the memory
- admins can describe to the LLM what the memory means and how it should be used

Example pattern:

- `Current user memory summary: {{memory_text}}`
- `Structured user memory JSON: {{memory_json}}`
- `Preferred name: {{preferred_name}}`

This is better because:

- usage stays fully visible in configuration
- prompt authors control wording and safety
- there is no confusion about when memory is present
- memory reuse works consistently with the rest of the SelfHelp interpolation model

## 11.3 Core Form Usage

Core forms do not need custom memory UI to use memory. They can consume it through:

- `data_config`
- interpolation in child fields
- future helper snippets

---

## 12. Admin UI Design

Data admin alone is not enough. We need a nicer memory-specific UI.

## 12.1 Best Fit

Extend the existing LLM admin console with a new memory area. The ui shoudl be build with react as we do. With all the libraries whcih we havw access. Everything modular and reusable.

Recommended approach:

- add a new `Memory` tab in `moduleLlmAdminConsole`

This fits because:

- it already belongs to the LLM plugin
- it already serves admin-only inspection use cases
- memory is conceptually part of LLM operations

## 12.2 Memory Admin Features

### User list

Show one row per user memory:

- user name
- user email/code
- memory key
- current summary preview
- last updated date
- last rule key
- last source type
- storage mode

### User detail panel

Show:

- full current `memory_text`
- pretty printed `memory_json`
- flattened fields
- source metadata
- links to related data row / conversation if available

### Timeline/history panel

Show one row per memory update:

- timestamp
- rule key
- source type
- source reference
- change summary
- before/after preview

### Diff view

Optional but recommended:

- show previous `memory_json`
- show new `memory_json`
- simple JSON diff or side-by-side compare

### Manual tools

Recommended admin actions:

- re-run selected memory rule for a user
- rebuild effective memory from history
- manually edit current memory
- mark broken update rows

## 12.3 Underlying Storage Remains Standard

Even with this custom UI:

- source of truth remains `dataTables/dataRows/dataCells`
- Data admin stays usable as fallback/raw inspection

---

## 13. Configuration UX

Admins need this to be easy.

## 13.1 For Core Forms and SurveyJS

Preferred UX:

- admin configures memory rules centrally on `sh_module_llm`
- admin uses existing form action UI on the target form/table
- admin selects job type `llm_memory_update`
- admin chooses a rule key (this keys shoudl be expalined what they are. The user shoudl see what is there)

This is easy and fits the existing system best.

## 13.2 For `llmChat`

Preferred UX:

- same central rule registry
- optional simple section field for direct binding when data saving is off:
  - `memory_rule_keys`

If data saving is on, admins can still use form actions on the generated `llmChat_*` table.

## 13.3 For Login/Profile

These are global rules configured only in module config.

No local style config is needed.

---

## 14. Detailed Implementation Pieces

## 14.1 New Services

### `LlmMemoryConfigService`

Responsibilities:

- load module memory fields
- parse rule registry
- resolve rules by key/source
- apply defaults

### `LlmMemoryStorageService`

Responsibilities:

- initialize current/history data tables
- resolve effective memory
- save current memory
- append history rows
- flatten top-level fields into columns

### `LlmMemoryTriggerService`

Responsibilities:

- normalize payloads from:
  - form action jobs
  - direct llmChat submissions
  - login
  - profile name change

### `LlmMemoryUpdateService`

Responsibilities:

- orchestrate one memory update
- build interpolation variables
- build prompt
- call worker/LLM
- validate result
- persist data

### `LlmMemoryAdminService`

Responsibilities:

- provide memory list queries
- provide per-user detail and history queries
- provide diff-friendly payloads

## 14.2 New Hook/Job Integration

### New job type support

Add LLM plugin hook support for:

- `llm_memory_update` job type

### Login/profile hooks

Register hooks for:

- `Login::update_timestamp`
- `ProfileModel::change_user_name`

## 14.3 New UI Pieces

### LLM admin console

Add:

- memory list endpoint
- memory detail endpoint
- memory history endpoint
- optional memory rebuild/re-run endpoint

---

## 15. Data Model for Rule Registry

Example rule:

```json
[
  {
    "key": "sleep_form_finished",
    "label": "Sleep Form Finished",
    "enabled": true,
    "memory_key": "global",
    "source_type": "form_action_submit",
    "source_match": {
      "table_name": "0000001234"
    },
    "trigger_types": ["finished"],
    "storage_mode_override": "both",
    "data_config": [
      {
        "table": "llm_memory",
        "retrieve": "last",
        "current_user": true,
        "map_fields": [
          { "field_name": "memory_text", "value": "memory_text" },
          { "field_name": "memory_json", "value": "memory_json" }
        ]
      }
    ],
    "prompt_template": "You update the global user memory. Current memory text: {{memory_text}}. Current memory json: {{memory_json}}. Trigger type: {{trigger_type}}. Submitted payload: {{event_payload_json}}. Return strict JSON with memory_text, memory_object, flat_fields, change_summary.",
    "llm_model": "",
    "llm_temperature": 0.2,
    "llm_max_tokens": 1200,
    "refresh_sections": []
  }
]
```

---

## 16. Recommended Source Strategy

To keep the system simple for admins:

### v1 recommendation

- use `form_action_submit` as the canonical memory trigger for:
  - core forms
  - surveyJS
  - any LLM forms that save into data tables
- use `llm_chat_form_submit` only as a fallback when `llmChat` form mode is used without data saving
- use hook-based rules for login/profile

This keeps the solution close to how SelfHelp already works.

---

## 17. Testing Plan

## 17.1 Functional

- core `formUserInput` submission with `llm_memory_update` action updates memory correctly
- `surveyJS` submission with matching trigger updates memory correctly
- `llmChat` form submit with data saving enabled updates memory via form action path
- `llmChat` form submit with data saving disabled updates memory via direct path
- login queues memory update only after successful login
- profile name change queues memory update only after successful update
- `record`, `log`, and `both` storage modes all behave correctly
- flattened fields are available via `data_config`
- `llmChat` can use memory only when admins explicitly load it through `data_config`
- prompts can combine memory data with extra explanatory text in a predictable way

## 17.2 Failure

- invalid worker output does not overwrite effective memory
- invalid worker output does not append history
- missing rule key fails safely
- non-matching rules do nothing
- async worker failure does not break source action

## 17.3 Admin UI

- current memory list loads correctly
- per-user detail view loads current memory
- timeline/history renders in order
- before/after data is inspectable

## 17.4 Performance

- form submissions remain immediate
- login remains immediate
- profile update remains immediate
- memory UI reads current snapshot efficiently when `record` or `both` mode is used

---

## 18. Final Recommendations

### Strong recommendation 1

Do not design memory as an `llmChat`-only feature.  
It must live at module level.

### Strong recommendation 2

Use the existing `UserInput::save_data()` plus `queue_job_from_actions()` pipeline as the default memory trigger mechanism for forms.

This is the cleanest and most system-native design.

### Strong recommendation 3

Support storage mode:

- `record`
- `log`
- `both`

Default to `both`.

### Strong recommendation 4

Provide a dedicated memory admin UI, ideally as a new tab in the LLM admin console.

Data admin should remain the raw fallback, but not the only user-facing inspection tool.

---

## 19. Open Implementation Defaults

Unless changed during review, the implementation should assume:

- memory is global per user
- default `memory_key = global`
- default storage mode = `both`
- default current table = `llm_memory`
- default history table = `llm_memory_history`
- memory rules live in module config
- forms use new form-action job type `llm_memory_update`
- `llmChat` direct binding exists only as fallback for non-saved dynamic forms
- memory is loaded for prompts only through explicit `data_config` interpolation

---

## 20. Implementation Order

1. Add DB fields for module config and style config.
2. Add new job type `llm_memory_update` and hook integration.
3. Build memory config, trigger, storage, update, and admin services.
4. Implement async worker path for memory updates.
5. Integrate core form-action execution path.
6. Integrate direct `llmChat` fallback path.
7. Add login and profile hooks.
8. Add memory tab to the LLM admin console.
9. Add documentation examples for rule JSON and recommended setup.
10. Add the changes to the changelog. This is version 1.2.0. Pre-release version
