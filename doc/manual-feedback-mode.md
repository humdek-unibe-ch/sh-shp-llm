# Manual Feedback Mode

## Overview

Manual Feedback Mode is a feature for the `llmFormRecord` style that separates **saving form data** from **generating LLM feedback**. Instead of automatically generating AI feedback every time the form is saved, users can choose when to request feedback using a dedicated button.

This is particularly useful in **evaluation scenarios** where:
- Feedback should only be generated intentionally, not on every save
- Users want to draft and save their answers multiple times before requesting feedback
- The form is used for iterative work where feedback is needed at specific moments
- Separation of concerns is important — saving data is a different action than evaluating it

## How It Works

### Without Manual Feedback Mode (default)

In the default mode, saving the form triggers both actions:

1. Form data is saved to the dataTable
2. LLM is called with the saved data
3. The LLM result is displayed in the result panel

### With Manual Feedback Mode enabled

When `llm_manual_feedback_enabled` is checked, the two actions are completely separated:

**Save Button** (standard form submit):
- Saves form data to the dataTable as usual
- Does NOT call the LLM
- Shows a success alert when saved

**Generate Feedback Button** (new):
- Reads the current form field values (without saving)
- Sends them to the LLM with the configured context
- Displays the LLM result in the result panel
- Does NOT save any data

This means users can:
1. Fill in the form
2. Click "Generate Feedback" to preview the AI response
3. Modify their answers based on the feedback
4. Click "Save" when satisfied
5. Click "Generate Feedback" again if they want updated feedback

## Configuration

### Enabling Manual Feedback Mode

In the CMS, configure the `llmFormRecord` section with:

| Field | Value | Description |
|-------|-------|-------------|
| `llm_manual_feedback_enabled` | ✅ checked | Turns on manual feedback mode |
| `llm_feedback_button_label` | e.g. "Get AI Feedback" | Label displayed on the button |
| `llm_feedback_button_color` | e.g. "primary" | Bootstrap color class |

### Button Label

The `llm_feedback_button_label` field is translatable (display=1), so it can be set per language. Examples:
- "Generate Feedback"
- "Get AI Feedback"
- "Evaluate Answer"
- "Check My Response"

### Button Color

The `llm_feedback_button_color` field uses the `style-bootstrap` type, which provides a dropdown with standard Bootstrap color classes:

| Value | Appearance |
|-------|------------|
| `primary` | Blue (default) |
| `secondary` | Gray |
| `success` | Green |
| `danger` | Red |
| `warning` | Yellow/amber |
| `info` | Cyan |
| `light` | Light gray |
| `dark` | Dark/black |

### Button Size

The Generate Feedback button automatically follows the `use_small_buttons` setting. When small buttons are enabled, it renders as `btn-sm`; otherwise it renders at the default Bootstrap button size.

## Context-Aware Visibility

The Generate Feedback button does not appear immediately. It dynamically monitors the form fields and only becomes visible when **all required context fields have non-empty values**.

### How context fields are detected

The system extracts field names from the `llm_context` template. Any `{{field_name}}` placeholder in the context defines a required field. For example:

```
You are a teacher. Evaluate the student's reflection below.

Student's answer: {{reflection}}
Topic: {{topic}}
```

In this example, the button will only appear when both `reflection` and `topic` fields have values.

### Dynamic tracking

The button visibility updates in real-time as the user types or changes field values:
- When all referenced fields are filled → button appears
- When any referenced field is emptied → button hides

This prevents users from generating feedback with incomplete data.

## Behavior Rules

### Regenerate Button Override

When manual feedback mode is enabled, the **regenerate button is always hidden**, even if `llm_regenerate_enabled` is checked. This is because:

1. In manual mode, the user explicitly controls when to generate feedback
2. The "Generate Feedback" button already serves the purpose of regeneration
3. Having both buttons would create confusion about which one to use

The retry button (for error recovery) remains available if `llm_retry_enabled` is checked.

### Form Save Behavior

When manual feedback mode is on and the user clicks Save:
- The form is submitted via AJAX with `__llm_form=1` (same as normal)
- The controller detects `manualFeedbackEnabled` and skips the LLM call
- A JSON response with `manual_feedback_mode: true` is returned
- The frontend shows a success/error alert instead of an LLM result

### Generate Feedback Action

When the user clicks "Generate Feedback":
- The current form field values are collected from the DOM (not from saved data)
- A POST request is sent with `__llm_action=generate_feedback`
- The controller calls the LLM without saving any data
- The result is displayed in the configured result panel

## Scope

This feature is **only available for `llmFormRecord`** (record mode). It is not available for `llmFormLog` (log mode) because:

- Record mode updates a single record per user, making the save/feedback separation meaningful
- Log mode creates a new entry per submission, where the LLM result is tied to the specific submission
- In log mode, separating save from feedback would create log entries without corresponding feedback

## Example Use Case

### Student Reflection Evaluation

**Scenario**: Students write reflections on a topic. A teacher-coach AI provides feedback on their writing.

**Configuration**:
- `llm_manual_feedback_enabled`: ✅
- `llm_feedback_button_label`: "Evaluate My Reflection"
- `llm_feedback_button_color`: "success"
- `llm_context`: "You are a supportive teacher-coach. Provide constructive feedback on the student's reflection about {{topic}}: {{reflection}}"

**User Flow**:
1. Student writes their reflection in the `reflection` field
2. The "Evaluate My Reflection" button appears (green, because `success`)
3. Student clicks it → AI feedback appears in the result panel
4. Student revises their reflection based on feedback
5. Student clicks "Evaluate My Reflection" again → updated feedback
6. When satisfied, student clicks "Save" to persist their final answer
