# Prompt Lab User Guide (CMS Editors)

## Who this is for

This guide is for CMS editors/admins who configure prompt fields in:

- `llmChat` (`conversation_context`)
- `llmFormRecord` / `llmFormLog` (`llm_context`)
- LLM Scripts (`script`)

No coding is required.

## What Prompt Lab adds

The prompt field now supports:

- prompt version history
- version compare (diff)
- playground testing with real runtime context
- multi-model compare (up to 3 models)
- "Build With AI" prompt improvement assistant

Important: the normal CMS translation behavior is unchanged. You still edit per language in CMS.

## Main UI areas

In the prompt toolbar you have:

- `Versions`: open full version history
- `Compare`: compare active version vs current draft
- `Playground`: test prompt with runtime context and inspect output
- `Build With AI`: improve current draft
- `Version Comment`: optional note for the next saved version

## Version save behavior

Prompt versions are created only when you click the normal CMS page Save.

- editing text alone does not create a version yet
- choosing an old version fills the textarea immediately
- the selected/drafted content is persisted only on CMS save
- if nothing changed, no duplicate version is created

## Version Comment behavior

`Version Comment` is saved on the next created version.

- use it before page Save
- if no new version is created, the comment is not persisted
- a hint icon in the toolbar explains this directly in the UI

## Playground behavior

Playground is runtime-aware, not prompt-only.

That means tests include the same context layers used in production, such as:

- language instructions
- strict/form/safety additions
- structured-response expectations
- script data/test variable expansion

You can:

- run one model
- select 2-3 models to compare
- inspect effective context/messages
- inspect parsed output and raw output
- inspect request payload and token/time metadata

## Structured JSON outputs

This system commonly returns JSON. Playground shows:

- parsed structured response
- extracted display content
- fallback raw output when parsing fails

So the preview is closer to what product users actually see.

## Build With AI behavior

Builder uses the current prompt draft as input and improves it.

- it does not start from blank unless your current prompt is blank
- only `prompt_template` is inserted into the editor on apply
- notes/variable suggestions/change summary are shown separately
- helper model can be selected per run

## Scripts module behavior

Scripts now use the same prompt-lab flow:

- versioning
- compare
- playground
- builder

The active runtime script still syncs to `llm_scripts.script`.

## Permissions and safety

Prompt-lab actions are permission checked.

- if a user can edit the page/script, they can run related prompt actions
- mutating actions require CSRF validation
- all LLM runs are logged in central LLM logs (`llmConversations`, `llmMessages`)

## Quick workflow

1. Edit prompt text.
2. Add optional `Version Comment`.
3. Test in `Playground` (single model or compare mode).
4. Optionally refine with `Build With AI`.
5. Save page/script to create the immutable version.
6. Use `Versions` and `Compare` to audit or restore later.

## Troubleshooting

- `No version`: save has not created the first snapshot yet, or no content/config was available at bootstrap.
- `Playground disabled`: owner runtime profile is non-executable (`text_only`) or field is read-only.
- Model list empty: check module LLM server/API-key configuration and model availability.
- API returns HTML/non-JSON: endpoint/path is wrong; Prompt Lab expects the plugin AJAX endpoint and base path.
