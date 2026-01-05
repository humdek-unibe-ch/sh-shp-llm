# LLM Response Schema v1.0.0

## Overview

All LLM responses in the SelfHelp LLM Plugin follow a **mandatory unified JSON schema** with **guaranteed compliance through validation and retry logic**. This ensures predictable, parseable, and safe responses with integrated safety detection.

**Key Principles:**
1. **Structured Responses Only** - All responses are JSON, never plain text
2. **Safety First** - Built-in danger detection at LLM level via `safety` field
3. **Predictable Parsing** - Frontend knows exactly what to expect
4. **Flexible Content** - Supports text blocks, forms, media, and suggestions
5. **Progress Tracking** - Optional topic coverage tracking
6. **Schema Validation** - Backend validates all responses against schema
7. **Auto-Retry Logic** - Invalid responses trigger automatic correction attempts
8. **Professional Schema Management** - Schema stored in dedicated JSON file

## Schema Validation & Retry System

### How Validation Works

The plugin implements a robust validation system to ensure **100% schema compliance**:

1. **Dynamic Schema Loading**: Schema loaded from `schemas/llm-response.schema.json` at runtime
2. **LLM Integration**: AI receives the actual JSON schema in system prompts
3. **Response Validation**: Every response validated against schema before acceptance
4. **Automatic Retry**: Failed validation triggers retry with error feedback (up to 3 attempts)
5. **Self-Correction**: LLM receives specific validation errors and corrects itself

### Retry Logic Flow

```
LLM Response → Schema Validation → Valid? → Accept Response
                                      ↓ No
                         Send Error Feedback → Retry (up to 3x) → Valid? → Accept
                                                                ↓ No → Fail & Log
```

### Retry Mechanism Details

**Configuration:**
- **Max Attempts**: 3 retry attempts per response
- **Delay**: 0.5 second delay between attempts to avoid API throttling
- **Error Feedback**: Specific validation errors sent to LLM for self-correction

**Retry Process:**
1. **Initial Request**: LLM receives schema in system prompt
2. **Validation**: Response checked against JSON schema
3. **Success**: Valid response accepted immediately
4. **Failure**: Error details added to context, retry attempted
5. **Correction**: LLM uses error feedback to fix response format
6. **Termination**: After 3 attempts, falls back to error handling

**Error Messages Sent to LLM:**
```
⚠️ Your previous response did not match the required JSON schema.
Please review the schema carefully and provide a response that strictly follows it.
Errors: Missing required field: type, Invalid type: expected 'response', got 'invalid'
```

### Technical Implementation

- **Schema File**: `schemas/llm-response.schema.json` (JSON Schema Draft 07)
- **Loading Method**: `LlmResponseSchema::getSchema()` with static caching
- **Validation Method**: `LlmResponseSchema::validate($response)`
- **Retry Logic**: `LlmResponseService::callLlmWithSchemaValidation($callable, $messages)`
- **Error Handling**: Graceful fallback with inline schema if JSON file unavailable

### Schema File Management

The schema is professionally managed in a dedicated JSON file:

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["type", "safety", "content", "metadata"],
  "properties": {
    // Complete schema definition
  }
}
```

**Benefits of JSON Schema File:**
- ✅ **Version Control**: Schema changes tracked in git
- ✅ **Tool Support**: JSON Schema validators can verify the schema itself
- ✅ **Maintainability**: Schema separate from PHP business logic
- ✅ **Standards Compliance**: Follows JSON Schema Draft 07 standard
- ✅ **Documentation**: Self-documenting with detailed descriptions

### Benefits

- 🎯 **Zero Tolerance for Errors**: No malformed responses accepted
- 🔄 **Self-Healing System**: AI automatically fixes validation errors
- 📊 **Reliability**: Guaranteed structured output across all models/providers
- 🛡️ **Predictability**: Frontend parsing is always safe and reliable
- 📈 **Quality Assurance**: Built-in quality control for AI responses
- 🏗️ **Professional Architecture**: Dedicated schema file with standards compliance

---

## Quick Reference

```json
{
  "type": "response",
  "safety": { "is_safe": true, "danger_level": null, "detected_concerns": [], "requires_intervention": false, "safety_message": null },
  "content": { "text_blocks": [...], "form": null, "media": [], "suggestions": [] },
  "progress": null,
  "metadata": { "model": "model-name", "tokens_used": 100, "language": "en" }
}
```

---

## Complete Schema Structure

### Top-Level Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | ✅ | Always `"response"` |
| `safety` | object | ✅ | Safety assessment of user message |
| `content` | object | ✅ | The actual response content |
| `progress` | object\|null | ❌ | Optional progress tracking |
| `metadata` | object | ✅ | Response metadata |

---

## Safety Object (Required)

The `safety` object is evaluated by the LLM for every user message and determines how the system responds to potentially dangerous content.

```json
{
  "safety": {
    "is_safe": true,
    "danger_level": null,
    "detected_concerns": [],
    "requires_intervention": false,
    "safety_message": null
  }
}
```

### Safety Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `is_safe` | boolean | ✅ | `true` if safe, `false` if danger detected at critical/emergency level |
| `danger_level` | string\|null | ✅ | Severity: `null`, `"warning"`, `"critical"`, `"emergency"` |
| `detected_concerns` | string[] | ✅ | Array of detected concern categories |
| `requires_intervention` | boolean | ✅ | `true` if administrators should be notified |
| `safety_message` | string\|null | ❌ | Supportive message when danger detected |

### Danger Levels

| Level | Description | System Action |
|-------|-------------|---------------|
| `null` | Safe content, no concerns | Normal conversation flow |
| `"warning"` | Mentions sensitive topics, general distress | Log only, continue conversation |
| `"critical"` | Concerning content, potential risk | Notify administrators via email |
| `"emergency"` | Imminent danger | Block conversation, show crisis resources |

### Detected Concerns Categories

- `suicide` - Suicidal thoughts, plans, or ideation
- `self_harm` - Cutting, burning, self-injury
- `harm_others` - Threats or plans to harm others
- `violence` - Violent acts or intentions
- `sexual_abuse` - Sexual assault, abuse, or exploitation
- `substance_abuse` - Overdose, addiction crisis
- `eating_disorder` - Anorexia, bulimia, extreme behaviors
- `domestic_violence` - Partner violence or abuse
- `child_safety` - Child abuse or endangerment
- `terrorism` - Terrorist plans or activities

---

## Content Object (Required)

The `content` object contains all displayable content for the response.

```json
{
  "content": {
    "text_blocks": [...],
    "form": null,
    "media": [],
    "suggestions": []
  }
}
```

### Content Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `text_blocks` | array | ✅ | Array of text content blocks (min 1) |
| `form` | object\|null | ❌ | Optional form for structured input |
| `media` | array | ❌ | Optional media items (images, videos, audio) |
| `suggestions` | array | ❌ | Optional quick reply suggestion buttons |

---

## Text Blocks (Required)

Every response must have at least one text block. Text blocks define the main content displayed to the user.

```json
{
  "text_blocks": [
    {
      "type": "text",
      "content": "Your message here with **markdown** support",
      "style": "default"
    },
    {
      "type": "heading",
      "content": "Section Title"
    },
    {
      "type": "info",
      "content": "This is an informational callout"
    }
  ]
}
```

### Text Block Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | ✅ | Block type (determines rendering style) |
| `content` | string | ✅ | Text content (supports markdown) |
| `style` | string | ❌ | Additional styling: `"default"`, `"bold"`, `"italic"`, `"code"`, `"quote"` |

### Text Block Types

| Type | Description | Frontend Rendering |
|------|-------------|-------------------|
| `text` | Normal paragraph | Default text styling |
| `heading` | Section heading | Bold, larger font |
| `info` | Informational callout | Blue box with info icon |
| `warning` | Warning message | Yellow box with warning icon |
| `error` | Critical/error message | Red box with error icon |
| `success` | Success/positive message | Green box with check icon |
| `code` | Code snippet | Monospace font, code block styling |

---

## Suggestions (Quick Reply Buttons)

Suggestions provide clickable buttons for common responses. **This is different from forms** - suggestions send a message when clicked, while forms collect structured data.

### ⚠️ STRICT FORMAT - No Variations Allowed

Suggestions **MUST** use **EXACTLY** this format. The property name **MUST** be `"text"`.

#### ✅ CORRECT Format (The ONLY accepted format)

```json
{
  "suggestions": [
    { "text": "Option 1" },
    { "text": "Option 2" },
    { "text": "Option 3" }
  ]
}
```

#### ❌ WRONG Formats (Will NOT render)

```json
// WRONG - Plain strings
{ "suggestions": ["Option 1", "Option 2"] }

// WRONG - Using "label" instead of "text"
{ "suggestions": [{ "label": "Option 1" }] }

// WRONG - Using "name" instead of "text"  
{ "suggestions": [{ "name": "Option 1" }] }

// WRONG - Using "title" instead of "text"
{ "suggestions": [{ "title": "Option 1" }] }
```

> **⚠️ IMPORTANT:** The frontend **strictly** requires the `"text"` property. Any other property name (label, name, title, etc.) will be ignored and the suggestion will not render.

### Suggestion Object Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `text` | string | ✅ **REQUIRED** | The button label shown to the user. **MUST use "text" as the property name.** |

### Example: Suggestions with Question

```json
{
  "content": {
    "text_blocks": [
      {
        "type": "text",
        "content": "Which Premier League club interests you most?"
      }
    ],
    "suggestions": [
      { "text": "Manchester City" },
      { "text": "Liverpool" },
      { "text": "Arsenal" },
      { "text": "Chelsea" },
      { "text": "Other team" }
    ]
  }
}
```

---

## Form Structure (Optional)

Forms are used when you need structured user input (questionnaires, ratings, multi-field data collection).

```json
{
  "form": {
    "title": "Your Feedback",
    "description": "Please rate your experience",
    "fields": [
      {
        "id": "rating",
        "type": "scale",
        "label": "How would you rate this session?",
        "required": true,
        "min": 1,
        "max": 10
      },
      {
        "id": "comments",
        "type": "textarea",
        "label": "Additional comments",
        "required": false,
        "placeholder": "Enter your thoughts..."
      }
    ],
    "submit_label": "Submit Feedback"
  }
}
```

### Form Object Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | ❌ | Form title displayed at top |
| `description` | string | ❌ | Form description/instructions |
| `fields` | array | ✅ | Array of form fields |
| `submit_label` | string | ❌ | Submit button label (default: "Submit") |

### Form Field Structure

```json
{
  "id": "unique_field_id",
  "type": "radio",
  "label": "Question or field label",
  "required": true,
  "options": [
    { "value": "opt1", "label": "Option 1" },
    { "value": "opt2", "label": "Option 2" }
  ],
  "helpText": "Additional help text for the field"
}
```

### Form Field Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string | ✅ | Unique identifier for the field |
| `type` | string | ✅ | Field type (see table below) |
| `label` | string | ✅ | Question or field label |
| `required` | boolean | ❌ | Whether field is required (default: false) |
| `options` | array | For selection types | Options for radio/checkbox/select |
| `placeholder` | string | ❌ | Placeholder text for text inputs |
| `helpText` | string | ❌ | Additional help text below field |
| `min` | number | ❌ | Minimum value for number/scale |
| `max` | number | ❌ | Maximum value for number/scale |

### Form Field Types

| Type | Description | Required Properties | Optional Properties |
|------|-------------|-------------------|-------------------|
| `radio` | Single selection (radio buttons) | `options` | `helpText` |
| `checkbox` | Multiple selection (checkboxes) | `options` | `helpText` |
| `select` | Dropdown single selection | `options` | `helpText` |
| `text` | Single line text input | - | `placeholder`, `helpText` |
| `textarea` | Multi-line text input | - | `placeholder`, `helpText` |
| `number` | Numeric input | - | `min`, `max`, `placeholder`, `helpText` |
| `scale` | Rating scale (1-10) | `min`, `max` | `helpText` |

### Frontend Rendering

Forms are rendered as interactive components with:
- **Field labels** prominently displayed
- **Appropriate input controls** based on field type
- **Validation feedback** for required fields
- **Help text** displayed below fields when provided
- **Submit button** with custom label
- **Form state management** with error handling

### Validation Rules

The schema enforces these validation rules:
- Forms must have at least one field
- Selection fields must have non-empty options arrays
- Scale fields must have valid min/max values
- All field IDs must be unique strings
- Field types must match allowed values

### Option Structure

```json
{
  "options": [
    { "value": "value1", "label": "Display Label 1" },
    { "value": "value2", "label": "Display Label 2" }
  ]
}
```

---

## Media (Optional)

Media items allow embedding images, videos, and audio in responses.

```json
{
  "media": [
    {
      "type": "image",
      "url": "https://example.com/image.jpg",
      "alt": "Description for accessibility",
      "caption": "Optional caption below image"
    },
    {
      "type": "video",
      "url": "/assets/videos/tutorial.mp4",
      "caption": "Tutorial video"
    }
  ]
}
```

### Media Object Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | ✅ | Media type: `"image"`, `"video"`, `"audio"` |
| `url` | string | ✅ | URL or path to the media file |
| `alt` | string | ❌ | Alt text for accessibility (images) |
| `caption` | string | ❌ | Caption displayed below media |

---

## Progress (Optional)

Progress tracking for educational modules or guided conversations.

```json
{
  "progress": {
    "percentage": 50,
    "current_topic": "breathing_exercises",
    "topics_covered": ["introduction", "basics"],
    "topics_remaining": ["advanced", "practice"],
    "milestones_reached": ["25%", "50%"]
  }
}
```

### Progress Object Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `percentage` | number | ❌ | Overall completion (0-100) |
| `current_topic` | string | ❌ | ID/name of current topic |
| `topics_covered` | string[] | ❌ | List of completed topic IDs/names |
| `topics_remaining` | string[] | ❌ | List of remaining topic IDs/names |
| `milestones_reached` | string[] | ❌ | Milestones achieved (e.g., "25%", "50%") |

---

## Metadata (Required)

Metadata provides information about the response for logging and debugging.

```json
{
  "metadata": {
    "model": "gpt-4o-mini",
    "tokens_used": 245,
    "confidence": 0.95,
    "language": "en"
  }
}
```

### Metadata Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `model` | string | ✅ | Name of the LLM model |
| `tokens_used` | number | ❌ | Tokens consumed for response |
| `confidence` | number | ❌ | Confidence score (0-1) |
| `language` | string | ❌ | Detected/used language code (en, de, fr, etc.) |

---

## Complete Example Responses

### Normal Response with Suggestions

```json
{
  "type": "response",
  "safety": {
    "is_safe": true,
    "danger_level": null,
    "detected_concerns": [],
    "requires_intervention": false,
    "safety_message": null
  },
  "content": {
    "text_blocks": [
      {
        "type": "heading",
        "content": "Welcome to Anxiety Management"
      },
      {
        "type": "text",
        "content": "I'm here to help you learn techniques for managing anxiety. We'll cover breathing exercises, cognitive techniques, and mindfulness practices."
      },
      {
        "type": "text",
        "content": "What would you like to start with?"
      }
    ],
    "form": null,
    "media": [],
    "suggestions": [
      { "text": "Breathing exercises" },
      { "text": "Cognitive techniques" },
      { "text": "Mindfulness practices" },
      { "text": "Tell me about all of them", "value": "Please give me an overview of all the techniques" }
    ]
  },
  "progress": {
    "percentage": 5,
    "current_topic": "introduction",
    "topics_covered": [],
    "topics_remaining": ["breathing", "cognitive", "mindfulness"]
  },
  "metadata": {
    "model": "gpt-4o-mini",
    "tokens_used": 180,
    "language": "en"
  }
}
```

### Response with Form

```json
{
  "type": "response",
  "safety": {
    "is_safe": true,
    "danger_level": null,
    "detected_concerns": [],
    "requires_intervention": false,
    "safety_message": null
  },
  "content": {
    "text_blocks": [
      {
        "type": "text",
        "content": "Let's assess your current anxiety level."
      }
    ],
    "form": {
      "title": "Anxiety Assessment",
      "description": "Please answer the following questions honestly",
      "fields": [
        {
          "id": "anxiety_level",
          "type": "scale",
          "label": "Rate your current anxiety level (1-10)",
          "required": true,
          "min": 1,
          "max": 10,
          "helpText": "1 = Very calm, 10 = Extremely anxious"
        },
        {
          "id": "symptoms",
          "type": "checkbox",
          "label": "Which symptoms are you experiencing?",
          "required": false,
          "options": [
            { "value": "racing_thoughts", "label": "Racing thoughts" },
            { "value": "tension", "label": "Muscle tension" },
            { "value": "rapid_heartbeat", "label": "Rapid heartbeat" },
            { "value": "sweating", "label": "Sweating" },
            { "value": "difficulty_breathing", "label": "Difficulty breathing" }
          ]
        }
      ],
      "submit_label": "Submit Assessment"
    },
    "media": [],
    "suggestions": []
  },
  "progress": null,
  "metadata": {
    "model": "gpt-4o-mini",
    "tokens_used": 220,
    "language": "en"
  }
}
```

### Emergency Response (Danger Detected)

```json
{
  "type": "response",
  "safety": {
    "is_safe": false,
    "danger_level": "emergency",
    "detected_concerns": ["suicide", "self_harm"],
    "requires_intervention": true,
    "safety_message": "I'm very concerned about what you've shared. Your safety is the top priority right now."
  },
  "content": {
    "text_blocks": [
      {
        "type": "error",
        "content": "I'm very concerned about what you've shared. Your safety is the top priority.",
        "style": "bold"
      },
      {
        "type": "warning",
        "content": "**🆘 Immediate Help Available**\n\n**Emergency Services:** Call 911 (US) or 112 (Europe)\n\n**📞 Crisis Hotlines:**\n- National Suicide Prevention Lifeline: **988** (US)\n- Crisis Text Line: Text **HOME** to **741741**\n- Samaritans: **116 123** (UK)\n\n💚 **You are not alone. People want to help you.**"
      },
      {
        "type": "info",
        "content": "Please reach out to one of the resources above. Professional help is available 24/7."
      }
    ],
    "form": null,
    "media": [],
    "suggestions": []
  },
  "progress": null,
  "metadata": {
    "model": "gpt-4o-mini",
    "tokens_used": 280,
    "language": "en"
  }
}
```

---

## Frontend Integration

### TypeScript Types

The frontend defines TypeScript interfaces in `react/src/types/index.ts`:

```typescript
interface LlmStructuredResponse {
  type: 'response';
  safety: SafetyAssessment;
  content: {
    text_blocks: TextBlock[];
    form?: FormDefinition | null;
    media?: MediaItem[];
    suggestions?: SuggestionItem[];
  };
  progress?: ProgressData | null;
  metadata: {
    model: string;
    tokens_used?: number | null;
    language?: string | null;
  };
}

interface SuggestionItem {
  text: string;
  value?: string;
  action?: 'send_message' | 'navigate' | 'external_link';
}
```

### Parsing Responses

```typescript
import { parseStructuredResponse, parseLlmResponse } from './types';

// Parse message content
const response = parseStructuredResponse(messageContent);

if (response) {
  // Access structured data
  const textBlocks = response.content.text_blocks;
  const suggestions = response.content.next_step?.suggestions || [];
  const form = response.content.forms?.[0];
}
```

### Suggestions Handling

The frontend normalizes suggestions to handle both formats:

```typescript
// Both of these are supported:
// String array (backwards compatible): ["Option 1", "Option 2"]
// Object array (preferred): [{text: "Option 1"}, {text: "Option 2"}]

const normalizedSuggestions = response.content.next_step?.suggestions || [];
// Always returns string[] for rendering
```

---

## Backend Validation

The backend validates all LLM responses using `LlmResponseSchema::validate()`:

```php
$response = json_decode($llmResponse, true);
$validation = LlmResponseSchema::validate($response);

if (!$validation['valid']) {
    error_log('Invalid LLM response: ' . implode(', ', $validation['errors']));
}
```

### Validation Checks

1. Required top-level fields: `type`, `safety`, `content`, `metadata`
2. `type` must be `"response"`
3. Safety object has all required fields
4. `danger_level` is valid enum value
5. `content.text_blocks` is array with at least one block
6. Each text block has `type` and `content`
7. `metadata.model` is present

---

## System Instructions

The schema is injected into the LLM context via `LlmResponseSchema::getSystemInstructions()`. This ensures the LLM knows exactly what format to return.

Key points emphasized to the LLM:
1. **Always return JSON, never plain text**
2. **Suggestions must be objects with `text` property**
3. **At least one text_block required**
4. **Safety assessment is mandatory**
5. **Match user's language**

---

## Migration Notes

### From Legacy Format

If upgrading from an older version:
- `enable_structured_response` field is deprecated (always enabled now)
- Old `meta.response_type` format is still supported for reading
- Keyword-based danger detection replaced with LLM-based evaluation

### Breaking Changes

None - the schema is backwards compatible. Frontend handles both:
- Old `meta` format with `response_type`
- New unified format with `type: "response"` and `safety`
