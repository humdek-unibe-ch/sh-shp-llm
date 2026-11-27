# Global Memory User Guide

This guide explains how to use the LLM plugin's global memory feature as an admin or CMS editor.

## What Global Memory Is

Global memory stores reusable user-specific information for the LLM module.

It is:
- global per user
- shared across LLM pages and flows
- updated from configured triggers such as forms, surveys, chat submissions, login, or profile name changes
- a list of short facts (items) in sections, so a single fact can be changed or deleted (since v1.10.0, see [Items And Sections](#items-and-sections))
- available later through explicit `data_config` loading

It is not:
- tied to only one `llmChat` section

Note: When a memory rule runs, the current memory, submitted data, and user language are automatically injected into the LLM call. You do not need to include them in your rule prompt.

## Items And Sections

A memory is a list of short facts (items). A memory key can have sections; then every item belongs to one of them. Without sections the memory is just a list:

```markdown
- Name: Stefan Kodzhabashev, prefers to be addressed as Baseff
- Wants to run 5 km
```

With the sections Person and Goals:

```markdown
### Person
- Name: Stefan Kodzhabashev, prefers to be addressed as Baseff

### Goals
- Wants to run 5 km
```

- The LLM does not rewrite the memory. It answers **operations** on single items (add, update, delete) and the plugin applies them to the memory as it is stored at that moment. Items the model is not asked about stay exactly as they are, and a change a participant made while the model was working is not lost.
- `memory_text` is built by the plugin from the items after every change: one `- <fact>` line per item; with sections a `### <section label>` heading per section, in the configured order, empty sections left out. `{{memory_text}}`, `data_config` and the LLM context work as before.
- `memory_json` holds the items: `{"format": "items/v1", "items": [{"id", "section", "text", "source", "updated_at"}]}` (`section` is `""` for a key without sections). The id is made by the plugin, `source` says who wrote the item last (`llm` or `user`).
- A participant can change their own items directly, without an LLM call, with the `llmMemoryEditor` style (see [Let Participants Edit Their Memory](#let-participants-edit-their-memory)).
- Deleting an item removes it from the **current** memory only. The history keeps the snapshots of earlier states; there is no purge feature.
- A memory written before v1.10.0 is free text. It is converted to items on its **next update**: the model splits the old text into items (one LLM call). Until then the editor shows it read-only. Nothing is converted up front.

### Define The Sections Of A Memory Key (optional)

Sections are optional and belong to one memory key. Open the `LLM Memory` page, tab **Keys**, and click **Edit** on the key. Add a row per section, for example:

| code | label | hint |
|---|---|---|
| `person` | Person | Current personal facts only, no history of changes |
| `context` | Context | Situations, observations, working hypotheses |
| `goals` | Goals | Things the user wants to achieve |

- A key without sections is one list of facts. That is the default for every key.
- `code`: 1 to 40 letters, digits, `_` or `-`, unique within the key. It is filled from the label while you type it and is stored with every item, so it is locked once saved. Removing a section keeps its items: they are still shown (after the other sections) and can be edited or deleted, but nothing new can be added there. Items that were added while the key had no sections are shown first, without a heading.
- `label`: the default heading for participants and the heading in `memory_text` (default: the code). Translate it for participants with the field `memory_labels` of the editor (see [Let Participants Edit Their Memory](#let-participants-edit-their-memory)).
- `hint`: tells the model what belongs in the section (default: none).
- The order of the rows is the order of the sections (arrows to move them). A mistake is shown when you save and nothing is stored.

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
- the LLM prompt that controls which facts are added to, updated in or deleted from the memory
- which memory key it writes to

### Writing The Prompt

You only need to write the **instructions** for the LLM. The system automatically injects:
- the sections of the memory key (code, label, hint)
- the user's current memory items, each with its id
- the submitted form data (all field values)
- any extra Data Config results you configured
- the user's language (memory content is written in that language)

Example prompt:

```text
Extract the user's hobbies and preferences from the submitted form data.
```

The system wraps your instructions with a smart system prompt: the model answers with operations (add, update or delete single items) in a fixed JSON format and the plugin applies them. Write what should be remembered, not how to merge it. You do not need to include `{{event_payload_json}}` or `{{memory_text}}` -- they are injected automatically.

You can still reference interpolation variables if you want fine-grained control:
- `{{user_language}}` -- the user's language name (e.g. "Deutsch (Schweiz)")
- `{{user_language_locale}}` -- the locale code (e.g. "de-CH")
- `{{hobbies}}`, `{{email}}`, etc. -- any submitted form field by name
- `{{memory_key}}`, `{{source_type}}`, `{{trigger_type}}` -- event metadata

### Memory Key Scoping

When multiple rules write to the same memory key, each rule's LLM call sees the full memory for that key. The system prompt instructs the LLM to only modify facts related to the current submission and preserve all other existing facts unchanged. On top of that the plugin applies only the operations the model returns: an item that no operation names stays exactly as it is. This keeps memory consistent even when different rules contribute different topics to the same key.

The model is told to delete only the items that a request in the submitted data names explicitly (for example "forget my workplace"); the rule's instructions alone do not authorise deleting items. The deleted items leave the current memory, the history keeps its snapshots.

## Step 3: Connect A Trigger

### Forms And Surveys

When a form or survey is finished, memory can be updated in two ways. Both can be used on the same form and both run.

#### Select the rules on the form or survey (recommended)

1. In the CMS open the section of the form (`formUserInput`, `formUserInputRecord`, `formUserInputLog`, `formUserInputLogList`, `llmFormRecord`, `llmFormLog`, `llmFormLogList`) or of the survey (`surveyJS`).
2. Open the field `memory_rule_ids` and select one or more memory rules. Every rule is listed as "Label (Source)"; a picked rule runs on this form whatever its source. Leave the field empty to switch this off for the form.
3. Save the section. No form action is needed.

What happens:

- The rules run only after a successful save of a **finished** submission. Validation errors, started or updated (unfinished) surveys, and failed saves do nothing.
- The participant never waits. The request only starts one background worker per rule; the LLM call and the memory write happen there. A failure in the background does not affect the saved answers.
- Each submission creates one scheduled job "LLM Memory Update (form: ...)" that is executed immediately, so it appears under **Scheduled Jobs** and in the calendar like a form action. The job is marked done when the background worker starts; open the job to see the worker's result ("LLM memory update finished" or "failed", with the rule and memory key). The same applies to the memory rules of an `llmChat` form submit.
- A rule that was deleted or disabled after it was selected is skipped.
- Deleting an entry runs a rule only when **Also run when an entry is deleted** is ticked in the rule editor. The rule then gets the entry's last values and the note that the participant deleted it. Nothing is removed from memory automatically: write in the rule's instructions what should happen, for example "Remove the deleted WOOP from the memory" or "Keep deleted WOOPs and mark them as deleted". Without such an instruction the memory stays as it is.
- Global memory must be enabled (Step 1). Submissions of guests (not logged in) are ignored, because memory is stored per user.
- Identical submissions are not applied twice (see Deduplication).

What the LLM receives: the usual `Submitted Data` (raw values by field name) plus a `Submission Context` section with the full question labels and the readable answers. It is built at the moment the submission is saved:

```json
{
  "source": "form",
  "form": { "name": "daily-check", "section_id": 55, "table_name": "0000000055", "record_id": 12 },
  "answers": [
    { "name": "mood", "label": "How is your mood today?", "type": "radio", "value": "2", "text": "Okay" },
    { "name": "symptoms", "label": "Symptoms", "type": "select", "value": ["a", "c"], "text": ["Headache", "Fatigue"] },
    { "name": "notes", "label": "Notes", "type": "textarea", "value": null }
  ]
}
```

- `value` is what was stored. `text` is the readable choice text and is only added when it differs from the value.
- `value: null` means the question was not answered. `0`, `false` and an empty text are kept as they are.
- Labels are plain text (HTML removed), in the language of the participant.
- For SurveyJS (`"source": "survey"`) the context also contains the survey name and title, the question names and titles, the choice texts, matrix rows and columns, the items of multiple text questions, dynamic panels and rows (`entries`), comments and "other" texts (`comment`), and a `scale` hint for ratings. File uploads and signatures are left out.

#### Form action with `llm_memory_update`

A form action with job type `llm_memory_update` works exactly as before. It sends the submitted values only, without the `Submission Context`. If a form has both a form action and selected rules, both run. A rule that is selected in both places runs once per path.

#### SurveyJS installed after the LLM plugin

`surveyJS` is a style of the separate SurveyJS plugin. The LLM migration `v1.8.0` registers the survey save hook always (it is skipped while SurveyJS is not installed) and links `memory_rule_ids` to `surveyJS` when the style exists. If SurveyJS was installed afterwards, or the field is missing on a SurveyJS section, run this file once:

```bash
mysql -u <user> -p <database> < server/plugins/sh-shp-llm/server/db/optional/enable_surveyjs_llm_memory_rules.sql
```

Then clear the SelfHelp cache, because styles and fields are cached. The file can be run again at any time. It changes nothing when SurveyJS or the LLM `v1.8.0` migration is missing.

### llmChat With Data Saving Enabled

Use the same `llm_memory_update` form action on the generated chat data table. The section field `memory_rule_ids` is ignored in this mode.

### llmChat With Data Saving Disabled

1. Create a memory rule on the LLM Memory → Rules tab (source `llmChat form submit` labels it for chats; any rule can be picked).
2. On the `llmChat` section, open `memory_rule_ids` and select one or more rules. Leave empty to switch memory off for the chat.
3. Memory still runs only on **chat form** submissions (structured answers), not on free-text messages.

### Sources Of Other Plugins

Other plugins can add their own source, for example the agentic chat plugin
(`Agentic chat end`, when a conversation ends). Select the rules in the same
field `memory_rule_ids` on the plugin's section. The plugin's documentation
says when they run.

### Register A Memory Source From A Plugin

For developers. A plugin needs no hook to add a memory source:

1. Insert one lookup row in the plugin's migration. `lookup_code` is the source
   type, `lookup_value` the label shown in the LLM Memory page:
   ```sql
   INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
   VALUES ('llmMemorySourceType', 'my_source', 'My source', 'Triggered when ...');
   ```
2. Link the field `memory_rule_ids` to the plugin's style (`styles_fields`).
   The CMS shows the rule picker in it, and the Sources tab shows the
   sections that select rules. Read the selected ids with
   `get_db_field(LLM_MEMORY_FIELD_RULE_IDS)`; empty = off.
3. When the event happens, schedule the selected rules:
   ```php
   $trigger = new LlmMemoryTriggerService($services);
   $payload = $trigger->normalizeSourcePayload('my_source', $source_ref, $fields, $user_id, $readable_text);
   $trigger->scheduleRuleJob($rule_ids, $payload, true, 'LLM Memory Update (my source)');
   ```
   `fields` is shown to the rule as Submitted Data. `source_ref` (e.g.
   `section_id`, `conversation_id`) is part of the dedupe key: it must identify
   the event, so the same event never updates memory twice. Its `section_id`
   and `conversation_id` are shown in the Activity tab.

### Login

Rules with `source_type = login` run automatically after a successful login.

### Profile Name Change

Rules with `source_type = profile_name_change` run automatically after a successful profile rename.

## Step 4: Use Memory In Prompts Or Content

Memory is only used when you load it explicitly through `data_config`.

Example (the memory of the key `reflection`):

```json
[
  {
    "table": "llm_memory",
    "retrieve": "last",
    "current_user": true,
    "filter": "AND memory_key = 'reflection' ORDER BY record_id DESC",
    "fields": [
      { "field_name": "memory_text", "field_holder": "reflection_memory", "not_found_text": "No memory yet." },
      { "field_name": "preferred_tone", "field_holder": "preferred_tone", "not_found_text": "" },
      { "field_name": "memory_key", "field_holder": "reflection_memory_key", "not_found_text": "" }
    ]
  }
]
```

- All memory keys share the table `llm_memory` (one row per user and key), so
  filter by `memory_key`; without the filter the newest row of any key is used.
  Keys are stored in lower case. Core only selects the columns listed in
  `fields`, so `memory_key` must be listed there for the filter to use it.
- `field_name` is the column (`memory_text`, `memory_json` or a flat field the
  rule writes), `field_holder` the name used in the text. `not_found_text`
  replaces the placeholder while the participant has no memory yet.
- `map_fields` only applies to `"retrieve": "JSON"`; with `first` / `last` /
  `all` use `fields`.
- `memory_text` is the markdown list the plugin renders from the items (`### Person`, `- Name: ...`), in markdown fields it shows as headings and bullets. `memory_json` is the items JSON (`items/v1`). Use `memory_text` to show or send a memory.

Then use the values in prompts or content:

```text
Current user memory summary: {{reflection_memory}}
Preferred tone: {{preferred_tone}}
```

This keeps memory usage explicit and predictable.

## Let Participants Edit Their Memory

The style `llmMemoryEditor` (style group Form, v1.10.0) shows the memory of the **logged-in participant** with a row per item (one block per section when the key has sections). The participant can edit, delete and add items **without an LLM call**.

1. In the CMS add a section with the style `llmMemoryEditor` to a page.
2. Pick the memory keys to show in `memory_keys` (for example `global`); empty uses the default memory key of the module. With several keys each key is shown with its heading, one below the other, and saved on its own.
3. To show the headings in the participant's language, fill the translatable field `memory_labels` per language, for example `{"global": "Mein Gedächtnis", "global.goals": "Meine Ziele", "person": "Über mich"}`: `"<key>"` is the heading of a key (only shown with several keys), `"<key>.<section code>"` the heading of a section of that key, `"<section code>"` the heading of that section in every key. What is not listed uses the label of the Keys tab. Like every translatable field, a language whose value is empty shows the value of the default language: to show the Keys-tab labels in a language, enter `{}` there.
4. Untick `allow_add`, `allow_edit` or `allow_delete` to take a function away. Set `confirm_delete_text`, `empty_text`, `read_only_text` and the `label_*` texts if the defaults (English) do not fit.
5. Pick the layout in `display_mode` (see [Layout And Look](#layout-and-look)); the default is `sections`.
6. Save and clear the SelfHelp cache once after the migration.

What the editor does:

- It always acts on the memory of the logged-in user. It never takes a user id from the request; a guest (not logged in) sees nothing and can change nothing.
- **Edit** opens an inline input (Enter saves, Esc cancels), **delete** asks for confirmation, **add** (under each section) opens an inline input. A text is plain text of at most 500 characters.
- Every change is saved at once and written to the history like any update: source `user_edit`, no rule, with a summary such as `Participant edited an item in "Goals"` (`... in the memory` for a key without sections) (the Activity tab shows it as "Item edited"). The current row and the history row are saved together or not at all.
- Nothing is overwritten silently: the page knows which version of the memory it shows. When the memory changed in the meantime (another tab, a memory rule), the change is refused, the block reloads with the current items and says so.
- A participant's change never cancels a rule update: an update that was still waiting in the queue, or is already running, adds its changes to the participant's version of the memory (an operation on an item the participant deleted is skipped).
- A memory that is still free text (written before v1.10.0 and not converted yet) is shown read-only with a note. It becomes editable after its first update by a rule.

### Layout And Look

The field `display_mode` decides how the sections of the memory are laid out. A key without sections is always one list, whatever the layout. The items, buttons and everything the editor does are the same in every layout.

| `display_mode` | What the participant sees |
|---|---|
| `sections` (default) | A heading and a list for each section, one below the other |
| `accordion` | One collapsible panel per section with the number of items; the first section that has items is open; opening another closes it |
| `tabs` | One tab per section with the number of items; the first section that has items is selected |
| `cards` | One card per section: heading with the number of items on top, the items and "add" inside |
| `flat` | One list of all items without headings. "Add" is only there when the memory key has no sections or exactly one (with several sections it would be unclear where the item goes); edit and delete work as usual |

After a change the participant stays where they were: the accordion panel or tab they worked in is still open.

The colours, corners and spacing are not set in the CMS. Without anything extra the editor looks like plain Bootstrap. To use the built-in theme, write `lme-theme-forest` into the field `css` of the section (deep teal on sand, rounded, little chrome). To give a website its own look, a developer defines a class with the `--lme-*` CSS variables in the site's CSS and the CMS editor writes that class into `css`; the list of variables and an example are in the plugin README (section *Look and themes*).

### Free-Text Change Requests

A participant can also ask in their own words ("remove my old workplace"). That stays a normal form plus a memory rule, as in the sections above:

1. A `formUserInput` with a textarea (for example `memory_change_request`) on the same page, with the memory rule selected in `memory_rule_ids`.
2. A rule whose instructions say that the textarea holds the participant's request to change the memory.
3. In the rule (card **Advanced**) pick the `llmMemoryEditor` section, and a markdown section that shows `{{memory_text}}`, in **Refresh Sections**, so they reload when the worker has finished.

The model then answers operations for the items it is asked about only; the other items stay as they are.

## How The Main Tabs Help

On the `LLM Memory` page:

- `Overview`: shows whether memory is enabled, storage mode, tables, and quick links
- `Keys`: list the memory keys with their rule count and the number of current / history rows
- `Rules`: create and manage update rules
- `Sources`: inspect which source events can write to memory (sections of any style with selected rules, form actions with a memory-update job, login / profile hooks)
- `Users`: browse the current memory and history for users. An admin edit there writes `memory_text` and `memory_json` as typed. The items of a memory are in `memory_json`: change them there. A changed `memory_text` alone is replaced by the text rendered from the items at the next update.
- `Activity`: event log of every memory update with participant (name, email, code), rule, memory key, what triggered it (form / survey submit, llmChat form, login, profile change, admin action, participant edit in the editor) and the result; filter by participant, rule, key, trigger, status and date

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
4. Select the rules in `memory_rule_ids` on the forms or surveys that should update memory (or add `llm_memory_update` as a form action).
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

If a form or survey with selected rules does not update memory, also check:
- `memory_rule_ids` is filled on the section that was submitted
- the SelfHelp cache was cleared after the migration (and, for SurveyJS, the field is listed on the section; otherwise run `enable_surveyjs_llm_memory_rules.sql`)
- the submission was finished and saved (started or updated surveys never trigger)
- the participant is logged in (guests are skipped)
- the rule exists, is enabled and has the source `form_action_submit`
- the scheduled job of the submission under **Scheduled Jobs** and its log (it says whether the update finished or failed)
- `llm_memory_worker.log` in the plugin folder and the PHP error log (`LLM Memory: form rule trigger failed` / `survey rule trigger failed`)

If memory is not visible in prompts, check:
- the table name in `data_config`
- the mapped field names
- the prompt content actually references `{{memory_text}}`, `{{memory_json}}`, or mapped values

If the `llmMemoryEditor` shows nothing or does not work, check:
- the participant is logged in (a guest sees nothing) and the section is on a page the participant can open
- the SelfHelp cache was cleared after the migration (the style and its fields are cached)
- `memory_keys` lists the key the rules write to (empty = the default memory key of the module)
- the memory is not still free text (the editor shows it read-only until the next rule update converts it)
- a change that is refused with "memory changed" is not an error: the memory was changed by another tab or a rule in the meantime; the block has reloaded, change the item again
- the browser console for a failed request (the editor posts to the page URL, so the page must accept POST requests)

If the sections do not look as configured, check in **LLM Memory > Keys** that the key of the rule or of the editor has the sections (a key without sections is one list). If a heading is not translated, check the key names in `memory_labels` (`"<key>.<section code>"` or `"<section code>"`; upper or lower case does not matter).

## Related Technical Docs

For deeper implementation details, see:
- `README.md` section `Global User Memory`
- [doc/structured-memory-design.md](structured-memory-design.md): the design of items, sections, operations and the editor
