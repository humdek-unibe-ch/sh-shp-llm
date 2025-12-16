# Data Logging Mode Context Example

This example demonstrates logging multiple entries per user (log mode) vs updating a single record (record mode).

## Configuration - Log Mode (Multiple Entries)

```
Style: llmChat
Model: gpt-oss-120b
Enable Form Mode: Yes
Enable Data Saving: Yes
Data Table Name: "Daily Mood Log"
Is Log Mode: Yes (ENABLED - creates new row for each submission)
```

### Log Mode System Context

```
You are a daily mood tracking assistant.

Each day, ask the user to log their mood and any notes. Each submission creates a new entry in the log.

FORM:
{
  "type": "form",
  "title": "Daily Mood Check-in",
  "description": "How are you feeling today?",
  "fields": [
    {
      "id": "mood",
      "type": "select",
      "label": "Current Mood",
      "options": [
        {"value": "great", "label": "😊 Great"},
        {"value": "good", "label": "🙂 Good"},
        {"value": "okay", "label": "😐 Okay"},
        {"value": "down", "label": "😔 Down"},
        {"value": "struggling", "label": "😢 Struggling"}
      ],
      "required": true
    },
    {
      "id": "energy_level",
      "type": "number",
      "label": "Energy Level (1-10)",
      "min": 1,
      "max": 10
    },
    {
      "id": "notes",
      "type": "textarea",
      "label": "Any notes about today?"
    }
  ]
}

After submission, thank the user and offer to log again tomorrow.
```

## Configuration - Record Mode (Single Entry)

```
Style: llmChat
Model: gpt-oss-120b
Enable Form Mode: Yes
Enable Data Saving: Yes
Data Table Name: "User Profile"
Is Log Mode: No (DISABLED - updates existing record or creates if none exists)
```

### Record Mode System Context

```
You are a profile management assistant.

Help users set up and update their profile. Each submission updates their single profile record.

FORM:
{
  "type": "form",
  "title": "Your Profile",
  "description": "Update your profile information",
  "fields": [
    {
      "id": "display_name",
      "type": "text",
      "label": "Display Name",
      "required": true
    },
    {
      "id": "bio",
      "type": "textarea",
      "label": "Bio"
    },
    {
      "id": "notification_preference",
      "type": "select",
      "label": "Notification Preference",
      "options": [
        {"value": "all", "label": "All"},
        {"value": "important_only", "label": "Important Only"},
        {"value": "none", "label": "None"}
      ]
    }
  ]
}

After submission, confirm the profile was updated.
```

## Testing Steps - Log Mode

1. Submit a mood entry
2. Check data table - one row should exist
3. Submit another entry
4. Check data table - two rows should exist
5. Each row has different timestamp

## Testing Steps - Record Mode

1. Submit profile info
2. Check data table - one row should exist
3. Submit updated profile info
4. Check data table - still one row, but updated
5. Same row ID, different data

## Data Table Structure

Both modes save to a data table with:
- `id_users` - User ID
- `llm_message_id` - Associated message ID
- `llm_conversation_id` - Associated conversation ID
- Form field values as columns

## Expected Behavior

### Log Mode (is_log = true)
- Each form submission creates a NEW row
- Useful for: journals, logs, surveys, feedback
- Multiple entries per user accumulate over time

### Record Mode (is_log = false)
- First submission creates a row
- Subsequent submissions UPDATE the same row
- Useful for: profiles, preferences, settings
- Only one record per user per section

