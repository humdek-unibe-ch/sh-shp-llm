# Prompt Assets

All static LLM-facing prompt text in `sh-shp-llm` is stored under `assets/prompts/`.

## Structure

- One file per prompt string (`.md` for multiline, `.txt` for short text).
- Runtime services resolve prompt text by key through:
  - `server/service/prompt/LlmPromptAssetRegistry.php`
  - `server/service/prompt/LlmPromptAssetLoader.php`

## Rules

- Add new prompt text in `assets/prompts/` first.
- Register the key in `LlmPromptAssetRegistry`.
- Load by key in services/components (`LlmPromptAssetLoader::load($key)`).
- Missing key/file is fail-closed (runtime exception).

## Troubleshooting

- If execution fails with a prompt-asset error:
  - Verify key exists in registry.
  - Verify mapped file path exists and is non-empty.
  - Verify deployment includes `assets/prompts/` files.
