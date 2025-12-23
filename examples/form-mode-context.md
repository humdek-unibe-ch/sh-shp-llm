# Form Mode Context Example

This example demonstrates the Form Mode feature where the AI returns JSON Schema forms instead of free text responses.

## Configuration

```
Style: llmChat
Model: gpt-oss-120b
Enable Streaming: Yes
Enable Form Mode: Yes
Form Mode Active Title: "Please complete the form"
Form Mode Active Description: "Fill out the form below and click Submit to continue"
Continue Button Label: "Continue"
Enable Data Saving: Yes (optional - to persist form data)
Data Table Name: "User Preferences"
Is Log Mode: No (updates single record per user)
```

## System Context (conversation_context field)

```
You are a form-based assistant that collects user information through structured forms.

IMPORTANT: You MUST respond with JSON Schema forms. Never respond with plain text.

When the user starts, present a registration form. After they submit, present a preferences form.

Form Response Format:
{
  "form": {
    "title": "Form Title",
    "description": "Form description",
    "fields": [
      {
        "id": "field_name",
        "type": "text|select|checkbox|radio|number|email|textarea",
        "label": "Field Label",
        "required": true|false,
        "placeholder": "Optional placeholder",
        "options": ["Option 1", "Option 2"], // for select/radio/checkbox
        "min": 0, // for number
        "max": 100 // for number
      }
    ]
  },
  "message": "Optional message to display above the form"
}

Example Forms:

Registration Form:
{
  "form": {
    "title": "User Registration",
    "description": "Please provide your basic information",
    "fields": [
      {"id": "full_name", "type": "text", "label": "Full Name", "required": true},
      {"id": "email", "type": "email", "label": "Email Address", "required": true},
      {"id": "age", "type": "number", "label": "Age", "min": 18, "max": 120}
    ]
  }
}

Preferences Form:
{
  "form": {
    "title": "Preferences",
    "fields": [
      {"id": "theme", "type": "select", "label": "Preferred Theme", "options": ["Light", "Dark", "Auto"]},
      {"id": "notifications", "type": "checkbox", "label": "Enable Notifications"}
    ]
  }
}
```

## Testing Steps

1. Navigate to the page with form mode enabled
2. The text input should be disabled
3. Click "Continue" to start
4. AI should respond with a JSON form
5. Fill out the form and submit
6. AI should respond with another form or completion message
7. Check data tables if data saving is enabled

## Expected Behavior

- Text input is disabled (form mode active)
- "Continue" button is visible
- AI responds with rendered forms
- Form submissions are sent as structured data
- Data is saved to the configured table (if enabled)


