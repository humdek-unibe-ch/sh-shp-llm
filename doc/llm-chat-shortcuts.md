# LLM Chat Shortcuts

## Overview

The `llm_chat_shortcuts` field allows authors to configure quick-start message shortcuts that appear as pill buttons around the floating chat button. When a user clicks a shortcut, the chat panel opens (or continues) and the configured message is sent automatically.

This feature is available in plugin v1.4.0+ and mobile app v4.0.5+.

## Field Configuration

- **Field name**: `llm_chat_shortcuts`
- **Type**: JSON
- **Default value**: `[]` (empty array)
- **Translatable**: Yes (`display = 1`)
- **Required**: No

## JSON Schema

The field expects a JSON array of shortcut objects. Each object has:

- `label` (required): The text shown on the shortcut pill button
- `message` (optional): The message sent to the chat when clicked. If omitted or empty, the `label` is used as the message.

### Example

```json
[
  {
    "label": "What did I do last?",
    "message": "What did I do last?"
  },
  {
    "label": "Continue the discussion",
    "message": "Please continue the previous discussion."
  },
  {
    "label": "Help me with homework"
  }
]
```

In the third example, since `message` is omitted, the `label` ("Help me with homework") will be sent as the message.

## Behavior

### Web

- Shortcuts appear as pill buttons in a tray around the floating chat button
- The tray is shown on **hover** or **focus** over the floating button
- Clicking a shortcut opens the chat panel and sends the message immediately
- The tray disappears when the user's mouse leaves the floating button/tray area or focus moves outside

### Mobile

- Shortcuts appear as pill buttons in a tray near the floating button
- **First tap** on the floating button shows the shortcut tray (if shortcuts are configured)
- **Tapping a shortcut** opens the chat and sends the message
- **Tapping the floating button again** when the tray is open opens the chat normally
- If no shortcuts are configured, the first tap on the floating button opens the chat directly (existing behavior)

## Requirements

- The floating chat button must be enabled (`enable_floating_button = 1`)
- The field works in both new conversations and existing conversations
- Invalid entries (missing `label`, non-object values) are silently ignored
- Empty array (default) means no shortcuts are shown

## Relationship to Other Features

This feature is **separate** from `enable_hint_suggestions`:

- **`llm_chat_shortcuts`**: Author-configured quick-start messages shown as pills around the FAB
- **`enable_hint_suggestions`**: AI-generated suggestion buttons shown in the chat after a response (when the model includes them)

Both features can coexist and serve different purposes:
- Shortcuts provide quick access to common starting points
- Hint suggestions provide contextual follow-up options based on the AI's response

## Localization

Since the field is translatable (`display = 1`), you can configure different shortcuts for different languages in the CMS. This allows you to:

- Show language-specific prompts
- Adapt shortcuts to cultural context
- Provide region-specific quick-start options

## Troubleshooting

**Shortcuts not appearing:**
- Verify `enable_floating_button` is set to `1`
- Check that the `llm_chat_shortcuts` field contains a valid JSON array
- Ensure at least one shortcut has a non-empty `label`

**Message not sending when shortcut clicked:**
- Check browser console for JavaScript errors
- Verify the chat service is properly configured
- Ensure the user has permission to send messages

**Tray closing too quickly (web):**
- This is by design - the tray stays open while hovering over the FAB or tray
- Use keyboard focus (Tab) for better accessibility instead of mouse hover
