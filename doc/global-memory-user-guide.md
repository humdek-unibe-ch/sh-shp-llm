# Global Memory User Guide

This guide explains how to use the LLM plugin's global memory feature as an admin or CMS editor.

## What Global Memory Is

Global memory stores reusable user-specific information for the LLM module.

It is:
- global per user
- shared across LLM pages and flows
- updated from configured triggers such as forms, surveys, chat submissions, login, or profile name changes
- available later through explicit `data_config` loading

It is not:
- tied to only one `llmChat` section

Note: When a memory rule runs, the current memory, submitted data, and user language are automatically injected into the LLM call. You do not need to include them in your rule prompt.

## Where To Manage It

Use these two places:

1. `LLM Konfiguration`
   Configure whether memory is enabled and define the default storage behavior.
2. `LLM Memory`
   Manage rules, inspect sources, and browse user memory.

## Step 1: Enable Global Memory

Open the `sh_module_llm` configuration page and set:
- `llm_memory_enabled` to enabled
- `llm_memory_key` if you want a default key such as `global`
- `llm_memory_storage_mode`
- `llm_memory_table_name`
- `llm_memory_history_table_name`

Recommended default:
- memory enabled
- memory key: `global`
- storage mode: `both`

## Step 2: Create A Memory Rule

Open the `LLM Memory` page and go to the `Rules` tab.

A rule defines:
- when memory should update
- which source event it listens to
- the LLM prompt that controls how submitted data is merged into existing memory
- which memory key it writes to

### Writing The Prompt

You only need to write the **instructions** for the LLM. The system automatically injects:
- the user's current memory state
- the submitted form data (all field values)
- any extra Data Config results you configured
- the user's language (memory content is written in that language)

Example prompt:

```text
Extract the user's hobbies and preferences from the submitted form data.
```

The system wraps your instructions with a smart system prompt that handles merge/append/replace logic and the correct JSON output format. You do not need to include `{{event_payload_json}}` or `{{memory_text}}` -- they are injected automatically.

You can still reference interpolation variables if you want fine-grained control:
- `{{user_language}}` -- the user's language name (e.g. "Deutsch (Schweiz)")
- `{{user_language_locale}}` -- the locale code (e.g. "de-CH")
- `{{hobbies}}`, `{{email}}`, etc. -- any submitted form field by name
- `{{memory_key}}`, `{{source_type}}`, `{{trigger_type}}` -- event metadata

### Memory Key Scoping

When multiple rules write to the same memory key, each rule's LLM call sees the full memory for that key. The system prompt instructs the LLM to only modify facts related to the current submission and preserve all other existing facts unchanged. This keeps memory consistent even when different rules contribute different topics to the same key.

## Step 3: Connect A Trigger

### Forms And Surveys

Add a form action with job type `llm_memory_update`.

Use this when a form submission should update memory after save.

### llmChat With Data Saving Enabled

Use the same `llm_memory_update` form action on the generated chat data table.

### llmChat With Data Saving Disabled

Use the section field `memory_rule_keys`.

Example:

```text
sleep_form_finished, general_chat_update
```

### Login

Rules with `source_type = login` run automatically after a successful login.

### Profile Name Change

Rules with `source_type = profile_name_change` run automatically after a successful profile rename.

## Step 4: Use Memory In Prompts Or Content

Memory is only used when you load it explicitly through `data_config`.

Example:

```json
[
  {
    "table": "llm_memory",
    "retrieve": "last",
    "current_user": true,
    "map_fields": [
      { "field_name": "memory_text", "value": "memory_text" },
      { "field_name": "memory_json", "value": "memory_json" },
      { "field_name": "preferred_tone", "value": "preferred_tone" }
    ]
  }
]
```

Then use the mapped values in prompts or content:

```text
Current user memory summary: {{memory_text}}
Preferred tone: {{preferred_tone}}
```

This keeps memory usage explicit and predictable.

## How The Main Tabs Help

On the `LLM Memory` page:

- `Overview`: shows whether memory is enabled, storage mode, tables, and quick links
- `Rules`: create and manage update rules
- `Sources`: inspect which source events can write to memory
- `Users`: browse the current memory and history for users

## Storage Modes

- `record`: writes only the current snapshot table
- `log`: writes only the history table
- `both`: writes current snapshot and history

Recommended default:
- `both`

## Recommended Setup

1. Enable global memory in `LLM Konfiguration`.
2. Open `LLM Memory`.
3. Create one or more rules with a prompt that describes what to extract.
4. Add `llm_memory_update` to forms or surveys that should update memory.
5. Load memory later with `data_config` wherever you want the LLM to use it.
6. Review memory writes and user state in the `Users` tab.

## Deduplication (`dedupe_key`)

Every persisted memory update stores a SHA-256 `dedupe_key` in
`llm_memory_history` (and on the `llm_memory_current` row when a record is
written). The key is a deterministic fingerprint of:

- `user_id`
- `memory_key`
- `rule_id`
- `source_type`
- `source_ref` (e.g. `{"table_name":"...","form_name":"...","record_id":123}`)
- `trigger_type`
- MD5 of the submitted `payload_fields`

Purpose:

- **Idempotency** — the async memory worker may be re-spawned if a job is
  retried or if the same form submission triggers the same rule more than
  once. When `LlmMemoryStorageService::dedupeKeyExists()` sees the same
  fingerprint, the update is skipped with status `LLM_MEMORY_STATUS_DUPLICATE`
  so the LLM is not called and the snapshot is not rewritten with identical
  content.
- **Staleness tracking** — if a newer snapshot already exists for the key,
  the duplicate update is marked `LLM_MEMORY_STATUS_STALE` instead of
  overwriting fresh data.
- **Audit** — the fingerprint is part of history rows so admins can trace
  which exact submission produced a memory change.

Because `source_ref` contributes to the fingerprint, **form-triggered updates
must include the correct `table_name` and `record_id`** -- otherwise two
different submissions collapse into the same dedupe key and the second one is
silently ignored as a duplicate. Since v1.2.0 `LlmHooks::execute_memory_task`
reads the table from `form_data.form_name` and the record id from
`form_data.form_fields[record_id]` to keep `source_ref` unique per
submission.

Admin-initiated memory rebuilds / edits / deletes build their own unique
`dedupe_key` per action (see `LlmMemoryAdminService`) so they never collide
with trigger-based updates.

## Troubleshooting

If memory is not updating, check:
- memory is enabled in `LLM Konfiguration`
- the correct rule is enabled
- the trigger matches the real source type
- the memory page shows the source and recent activity
- your prompt or field actually loads memory via `data_config`

If memory is not visible in prompts, check:
- the table name in `data_config`
- the mapped field names
- the prompt content actually references `{{memory_text}}`, `{{memory_json}}`, or mapped values

## Related Technical Docs

For deeper implementation details, see:
- `README.md` section `Global User Memory`
