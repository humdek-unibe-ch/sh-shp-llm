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

### Scripts

- test/runtime variables
- script owner descriptor
- runtime overrides such as `data_config`
- original assistant response when available

## Access control

Import is not a blind export tool. Candidate listing still respects owner scope:

- style-field prompts use page/module access checks
- scripts use the script module ACL

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
