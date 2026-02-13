# LLM Chat Plugin — User Guide for CMS Editors (Non-Technical)

## Who this guide is for

This guide is for **content editors and administrators** who configure and use the LLM Chat plugin in the CMS.

You do **not** need programming knowledge.

---

## What this plugin does (in simple words)

The plugin adds an AI chat to your pages. You can:

- connect your AI provider using an API key,
- add chat to any CMS page,
- choose how the chat should behave,
- control style labels/text,
- see user conversations in an admin console,
- optionally save form answers to SelfHelp data tables.

---

## Quick setup (recommended order)

1. Configure global AI connection (API URL + API key)
2. Add `llmChat` section to a page
3. Configure behavior (context, strict mode, form mode, auto-start, etc.)
4. Test with a normal user account
5. Open admin conversations console and confirm logs are visible

---

## 1) Enter API settings (including API key)

### Where

Go to:

**Admin → Modules → LLM Configuration**

(URL: `/admin/module_llm`)

### Required fields

- **llm_base_url**: the provider API endpoint
- **llm_api_key**: your private API key/token
- **llm_default_model**: default model to use
- **llm_timeout**: request timeout (seconds)
- **llm_max_tokens**: response length limit
- **llm_temperature**: creativity level (0–2)

### API key best practice (important)

- Paste the key only into the **`llm_api_key` password field**.
- Do **not** place keys inside conversation context or page text.
- Treat API keys like passwords (share only with trusted admins).
- If a key is exposed, rotate/regenerate it immediately at your provider.

---

## 2) Add chat to a page

### Where

Go to your page editor and add a section with style:

**`llmChat`**

(Usually in: **CMS → Edit page**)

### Basic fields to start with

- `llm_model` (optional override per page section)
- `conversation_limit`
- `message_limit`
- `enable_conversations_list`
- `enable_file_uploads`

Tip: If you leave `llm_model` empty, the global default model is used.

---

## 3) Configure behavior (plain-language guide)

## A) Conversation Context (what the AI should focus on)

- Field: `conversation_context`
- Use this to tell the AI role, scope, tone, and rules.

Example:

```markdown
You are a supportive study coach.
Focus only on exam preparation, planning, and learning strategies.
Use simple language.
```

---

## B) Strict Conversation Mode (stay on topic)

- Field: `strict_conversation_mode`
- Turn ON when you want the AI to politely redirect off-topic questions.

Use this for:
- therapy/health modules,
- course-specific learning,
- structured research modules.

---

## C) Auto-Start Conversation

- Fields:
  - `auto_start_conversation`
  - `auto_start_message`

When ON, the user sees an opening message automatically when no chat exists yet.

Recommended with guided modules and especially with Form Mode.

---

## D) Form Mode (guided forms instead of free typing)

- Field: `enable_form_mode`
- When ON, users answer forms (radio/checkbox/select/text) rather than open chat typing.

Also configure labels:
- `form_mode_active_title`
- `form_mode_active_description`
- `continue_button_label`

Recommended combination:
- `enable_form_mode` = ON
- `auto_start_conversation` = ON
- `conversation_context` contains clear form instructions

---

## E) Save form data to SelfHelp tables

Fields:
- `enable_data_saving`
- `data_table_name`
- `is_log`

How modes work:
- **Log mode (`is_log` ON)**: every submission creates a new row.
- **Record mode (`is_log` OFF)**: one row per user is updated.

Use Log mode for tracking over time.
Use Record mode for profile/preference style data.

---

## F) Track topic progress

Fields:
- `enable_progress_tracking`
- `progress_bar_label`
- `progress_complete_message`
- `progress_show_topics`

Important: progress is based on **what users ask**, not what AI mentions.

To make tracking work well, define trackable topics in `conversation_context`.

---

## G) Floating chat button mode

Fields:
- `enable_floating_button`
- `floating_button_position`
- `floating_button_icon`
- `floating_button_label`
- `floating_chat_title`

When ON, users see a floating button (instead of an always-open chat block).
Clicking it opens chat in an overlay/modal.

---

## H) Media and upload options

Useful fields:
- `enable_file_uploads`
- `enable_media_rendering`
- `allowed_media_domains`

Use `allowed_media_domains` if you want to limit external image/video sources.

---

## 4) Customize visible text (labels and wording)

You can edit button text, empty-state text, tooltips, and headings directly in llmChat fields.

Common examples:
- `submit_button_label`
- `new_chat_button_label`
- `message_placeholder`
- `conversations_heading`
- `empty_state_title`
- `empty_state_description`

This is useful for:
- localization,
- child-friendly language,
- formal vs. informal tone,
- module-specific branding.

---

## 5) View conversations from users (admin console)

### Where

Open:

**Admin → Modules → LLM Conversations**

(URL: `/admin/module_llm/conversations`)

You can:
- filter by user,
- filter by section/page module,
- search conversations,
- filter by date,
- open full message history,
- block/unblock conversations,
- soft-delete conversations from user view.

There is also an **LLM Panel** shortcut button in LLM module settings linking to this page.

---

## 6) "Add Values" usage in CMS (practical note)

Depending on your CMS screen labels, these llmChat fields may appear under:

- section style values,
- add values,
- component settings.

For daily work, think of them as:

- **Global values** (Admin → Modules → LLM Configuration), and
- **Per-page/per-section values** (inside each `llmChat` section).

Per-section values let you run different chat behavior on different pages.

---

## Safe starter presets (copy this approach)

## Preset 1: Simple open chat

- `enable_conversations_list` ON
- `strict_conversation_mode` OFF
- `enable_form_mode` OFF
- `auto_start_conversation` ON
- `enable_data_saving` OFF

Use when you want flexible, friendly Q&A.

## Preset 2: Guided educational module

- `strict_conversation_mode` ON
- `enable_form_mode` ON
- `auto_start_conversation` ON
- `enable_progress_tracking` ON
- `progress_show_topics` ON

Use when users should stay on a defined learning path.

## Preset 3: Research / data collection

- `enable_form_mode` ON
- `enable_data_saving` ON
- `is_log` ON
- `strict_conversation_mode` ON
- `auto_start_conversation` ON

Use when every response should be stored and auditable.

---

## Pre-publish checklist

Before going live, verify:

- [ ] API key works in global LLM configuration
- [ ] Correct model is selected
- [ ] Context text is clear and focused
- [ ] Strict mode ON/OFF matches your use case
- [ ] Auto-start behavior is tested
- [ ] Form mode tested (if enabled)
- [ ] Data saving tested (if enabled)
- [ ] Progress tracking tested with real user-style questions
- [ ] Floating button placement looks good on desktop/mobile
- [ ] Admin console can see new conversations

---

## Troubleshooting (non-technical)

## Chat does not answer

Check:
1. API key is entered in LLM Configuration
2. Base URL is correct
3. Model name is available
4. Timeout is not too low

## Form mode seems stuck

Check:
1. `auto_start_conversation` is ON
2. context includes clear instructions for form responses
3. continue button appears when no form is returned

## No saved form data visible

Check:
1. `enable_data_saving` is ON
2. section has been loaded after enabling
3. correct save mode (`is_log`) is selected

## Conversations not visible in admin

Check:
1. you are on `/admin/module_llm/conversations`
2. filters (date/user/section) are not hiding results
3. you have admin permissions

---

## Final recommendation

Start simple, then add advanced features one by one:

1. global API config,
2. basic page chat,
3. context,
4. strict mode,
5. form mode,
6. data saving,
7. progress tracking,
8. floating button.

This gives stable rollouts and easier troubleshooting.
