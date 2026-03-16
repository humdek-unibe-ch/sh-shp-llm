# AI Dataset Import User Guide

## Purpose

Use `Import With AI` in Prompt Datasets to bulk-import many examples from pasted text (Excel/Sheets/CSV/TSV or free text), instead of importing one case at a time from playground runs.

## Where to find it

1. Open Prompt Lab for a prompt field or script.
2. Open `Datasets`.
3. Select or create a dataset.
4. Click `Import With AI`.

## Flow

### 1) Paste Input

- Paste your source examples (table paste or text blocks).
- Choose parser model.
- Default model is the owner/style default; you can switch models.

### 2) AI Parse Preview

- The parser returns:
  - detected format
  - inferred mapping
  - parsed case rows
- Check warnings if present.

### 3) Review & Import

- Edit titles, input payload, expected output, and notes.
- Select/deselect rows.
- Import only selected valid rows.

## What gets imported

Each row is normalized to a dataset case with:

- `title`
- `input_payload`
- `expected_output` (if feedback/reference output was detected)
- `notes`
- `tags`
- source type `ai_text_import`

Import is always scoped to the currently selected dataset/profile.

For `form_runtime`, Prompt Lab also aligns imported variables to the active prompt placeholders so replay/evaluation uses real user input instead of generic fallback text.

## Tips for better parsing

- Keep header names clear (for example: question, student answer, feedback, notes).
- Paste complete rows (avoid partial/cut rows).
- If output is mixed/noisy, adjust mapping and edit rows before import.
- Use review mode to remove weak rows and keep benchmark quality high.

## Common issues

### "Parser returned invalid JSON"

- This is now auto-repaired when possible.
- If it still fails:
  - paste fewer rows per run
  - remove unrelated text around the table
  - retry with a stronger model

### Import button disabled

- At least one selected row must be valid.
- Fix row validation errors (usually title or `input_payload` JSON object).

## Related docs

- [prompt-datasets-user-guide.md](prompt-datasets-user-guide.md)
- [prompt-replay-import-guide.md](prompt-replay-import-guide.md)
- [prompt-lab-payload-shapes.md](prompt-lab-payload-shapes.md)
