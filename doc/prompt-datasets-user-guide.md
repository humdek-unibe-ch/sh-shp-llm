# Prompt Datasets User Guide

## Who this is for

This guide is for CMS editors and developers who use Prompt Lab to build reusable test sets for prompt work.

## What a dataset is

A dataset is a named collection of replayable prompt cases. Each case stores a normalized input payload and optional expectations such as expected labels or expected structured fields.

Use datasets when you want to:

- keep a stable golden set of important examples
- replay the same cases against new drafts or versions
- compare multiple models or prompt variants
- review failures before rolling out a prompt change

## Common workflow

1. Open a prompt field or script.
2. Open `Playground` and run a realistic test.
3. Open `Datasets`.
4. Create a dataset or select an existing one.
5. Click `Add Latest Playground` to store the current case.
6. Repeat until the dataset covers your important scenarios.
7. Click `Run Evaluation` to replay the dataset against the current draft, the active version, or a saved version.

## Dataset types

Prompt Lab supports one dataset system with multiple dataset types. The current UI is optimized for:

- `golden_manual`
- `production_replay`
- `pilot_study_replay`
- `conversation_replay`
- `form_submission_replay`
- `script_fixture`

Choose the type that best describes why the dataset exists. The replay behavior stays the same.

## Good dataset habits

- Keep titles short and recognizable.
- Add cases from real edge cases, not only happy paths.
- Lock benchmark datasets once the team starts relying on them.
- Prefer expected labels over full expected output when wording can vary.
- Keep one dataset focused on one owner/runtime profile.

## Running evaluations

The evaluation runner lets you choose:

- a dataset
- a target prompt
- one or more models
- one or more evaluators
- an optional baseline run

Current built-in objective evaluators include:

- `json_validity`
- `required_fields_present`
- `no_empty_output`
- `safety_label_match`

The results screen shows:

- pass rate
- average score
- failed cases
- per-case output
- evaluator details
- human review inputs for `human_review` evaluators

## Locking and editing

Lock a dataset when it becomes a benchmark. Locked datasets stay visible and runnable, but case mutation actions are blocked.

## Related guides

- [prompt-replay-import-guide.md](prompt-replay-import-guide.md)
- [prompt-lab-user-guide.md](prompt-lab-user-guide.md)
