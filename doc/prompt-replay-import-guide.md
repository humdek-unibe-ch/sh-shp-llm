# Prompt Replay Import Guide

## Purpose

Prompt replay import turns existing usage data into reusable dataset cases. This is the main workflow for testing prompt changes against real user inputs.

## Supported import sources

Prompt Lab can import dataset cases from:

- playground runs
- form submissions
- conversations
- scripts

All imports create normalized dataset cases instead of storing raw database dumps.

## Import workflow

1. Open `Datasets`.
2. Select a dataset.
3. Click `Import Cases`.
4. Choose a source tab.
5. Select one or more candidate rows.
6. Click `Import Selected`.
7. Run an evaluation on the imported dataset.

## What each source captures

### Playground runs

- latest request variables
- runtime overrides
- message history
- prompt-lab run references

Only genuine playground activity is listed: manual playground runs
(`LLM_PROMPT_RUN_MODE_PLAYGROUND`) and multi-model comparisons
(`LLM_PROMPT_RUN_MODE_COMPARE`). Prompt-improvement runs from the "Build with
AI" workspace (`LLM_PROMPT_RUN_MODE_BUILDER`) and dataset-evaluation runs
(`LLM_PROMPT_RUN_MODE_DATASET_EVAL`) are intentionally excluded so the import
list reflects what the user actually tested, not iterations of the prompt
itself.

### Form submissions

- submitted form values
- owner descriptor
- runtime snapshot
- original assistant response when available

### Conversations

- recent message history
- triggering user message
- owner descriptor
- original assistant response when available

For **memory rule** owners the "Conversations" source surfaces the plugin's
internal memory-update conversations (title `__memory_update__<key>`). When
the rule has known memory keys only those specific conversations are listed;
otherwise every conversation whose title starts with `__memory_update__` is
shown. Imported cases use the `memory_runtime` execution profile and are
tagged with `memory_conversation`.

Because the memory worker logs only the assistant response (the prompt is
kept in `sent_context`), the listing queries **assistant** rows for memory
rules instead of user rows. On import the prompt stored in `sent_context` is
replayed as the message history, and the assistant content becomes the
reference response. For memory rule owners the import modal defaults to the
"From memory executions (conversations)" source and hides the form / script
options that do not apply, so genuine memory activity is visible out of the
box.

#### Structured memory replay

The memory worker assembles its user message from fixed markdown sections
(`## Scope`, `## Current Memory`, `## Submitted Data`, `## Additional Context`,
`## Instructions`, `## Reminder`). When a memory conversation is imported as
a dataset case, the plugin parses that user message and stores a
`memory_context` payload on the case containing:

- `system_message` — the original system prompt (output schema + language).
- `prefix_before_instructions` — everything before the `## Instructions` block
  (Scope, Current Memory JSON, Submitted Data JSON, Additional Context).
- `suffix_after_instructions` — the `## Reminder` block.
- `variables` — `memory_key`, `memory_text`, `memory_json`,
  `event_payload_json`, and every scalar form field from Submitted Data.

During evaluation the replay service forwards this payload as
`options.memory_context` to `LlmPromptPlaygroundService::runMemoryRuntime`.
That handler interpolates the **draft admin prompt** with the parsed
variables, splices it back into the `## Instructions` slot, and calls the
LLM directly with the reassembled `system + user` messages. This means the
LLM sees exactly the same Current Memory and Submitted Data that the live
memory worker saw — only the admin instruction block differs between runs,
so the evaluation measures the effect of your prompt change rather than the
effect of missing context.

When no `memory_context` is attached (raw playground runs driven from the
admin UI), `runMemoryRuntime` falls back to the script runtime so admins can
still iterate on a prompt template with synthetic variables.

### Scripts

Offered for prompts that belong to an LLM script (the script itself is the
fixture) and to users who may use the script module. A CMS style-field prompt
or a memory rule has no script source: the list stays empty and nothing can be
imported from it.

- test/runtime variables
- script owner descriptor
- runtime overrides such as `data_config`
- original assistant response when available

## Access control

Import is not a blind export tool. Two things are decided, in this order:

1. **Who may import.** The request has to pass the access check of the
   prompt's owner: a style-field prompt uses the ACL of a page that really
   shows the section (the pages are read from the database, not taken from the
   request); scripts and memory rules use the ACL of their module pages.
2. **What can be imported.** Only records of the prompt's own owner. The ids of
   the selected rows are sent by the browser, so the import does not trust
   them: it applies the same rule as the candidate list, and a record that the
   list would not show for this prompt is skipped. When none of the selected
   records is in scope the import fails with an error and creates nothing.

A record belongs to an owner by what it says about itself, never by the page
the owner is shown on:

| Source | CMS style field (section) | LLM script | Memory rule |
|--------|---------------------------|------------|-------------|
| Playground runs | Runs of a prompt entry with that owner type, owner id and (when the dialog sends one) prompt slot; run mode playground or compare | Same, for the script | Same, for the rule |
| Form submissions | User messages that carry form data, in conversations of that section | None | None |
| Conversations | User messages in conversations of that section | User messages in conversations of that script | Assistant messages of the `__memory_update__<key>` conversations of the rule's memory keys |
| Scripts | None | The script itself | None |

Not in scope, whatever id is sent: a conversation without a section or a
script (older rows), the conversations of another section, script or memory
rule, a run of another owner type that happens to share the owner's number, a
run of another prompt slot, and prompt-improvement or evaluation runs. The
import also fails closed: with no owner id, an unknown owner type, a memory
rule that does not exist or cannot be read, or a database error, nothing is
listed and nothing is imported.

Only users with update-level access should run imports or evaluations because the feature can expose real data and spend tokens.

## When to use replay import

Use replay import for:

- regression testing before prompt rollout
- pilot-study prompt iteration
- safety benchmark creation
- comparing a draft against the current active version

## Tips

- Import a focused slice first instead of everything.
- Tag or lock important replay datasets after review.
- Review expected labels after import if you need strict matching evaluators.

## Related guides

- [prompt-datasets-user-guide.md](prompt-datasets-user-guide.md)
- [prompt-lab-payload-shapes.md](prompt-lab-payload-shapes.md)
