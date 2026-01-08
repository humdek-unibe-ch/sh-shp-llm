# Configuration Guide

## Overview

The LLM Chat plugin uses a three-tier configuration hierarchy:

1. **Global Configuration** - Admin-level settings for all instances
2. **Component Configuration** - Per-component settings (llmChat style)
3. **Per-Conversation Settings** - Stored with each conversation

## Global Configuration

**Location:** Admin → Modules → LLM Configuration (`/admin/module_llm`)

These settings apply to all chat instances unless overridden at component level.

### API Settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_base_url` | text | `https://gpustack.unibe.ch/v1` | Base URL for LLM API |
| `llm_api_key` | password | - | API authentication token |
| `llm_timeout` | number | `30` | Request timeout in seconds |

#### Supported Providers

The plugin automatically detects the provider based on the `llm_base_url`. Supported providers include:

| Provider | Base URL | Features |
|----------|----------|----------|
| **GPUStack (UniBE)** | `https://gpustack.unibe.ch/v1` | Standard OpenAI-compatible API |
| **BFH Inference API** | `https://inference.mlmp.ti.bfh.ch/api/v1` | Enhanced with reasoning content and provider-specific fields |

See [Provider Abstraction](provider-abstraction.md) for more details on adding custom providers.

### Model Settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_default_model` | select | `qwen3-vl-8b-instruct` | Default model for new conversations |
| `llm_max_tokens` | number | `2048` | Maximum tokens per response |
| `llm_temperature` | text | `1` | Response randomness (0-2) |

### Temperature Guide

- `0.0` - Deterministic, repetitive
- `0.5` - Balanced creativity
- `1.0` - Standard randomness
- `1.5` - High creativity
- `2.0` - Maximum randomness

## Component Configuration

**Location:** CMS → Edit page → llmChat component settings

These settings override global defaults for specific chat instances.

### Behavior Settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `llm_model` | select | (global) | Override model for this component |
| `llm_temperature` | text | (global) | Override temperature |
| `llm_max_tokens` | number | (global) | Override max tokens |

### Feature Toggles

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enable_conversations_list` | checkbox | `true` | Show conversation sidebar |
| `enable_file_uploads` | checkbox | `true` | Allow file attachments |
| `strict_conversation_mode` | checkbox | `false` | Enable strict conversation mode to keep AI focused on defined topics |
| `enable_form_mode` | checkbox | `false` | Enable Interactive Form Mode - LLM returns structured forms instead of text |
| `enable_data_saving` | checkbox | `false` | Save form data to SelfHelp UserInput system (see [Form Data Saving](form-data-saving.md)) |
| `is_log` | checkbox | `false` | Data save mode: Enabled = Log Mode (new entry per submission), Disabled = Record Mode (update existing) |
| `enable_media_rendering` | checkbox | `true` | Enable rendering of images and videos in chat responses (see [Media Rendering](media-rendering.md)) |
| `enable_progress_tracking` | checkbox | `false` | Enable progress tracking to show context coverage (see [Progress Tracking](#progress-tracking)) |
| `enable_floating_button` | checkbox | `false` | Enable floating chat button mode - shows a floating button that opens chat in a modal |
| `floating_button_position` | select | `bottom-right` | Position of floating button: bottom-right, bottom-left, top-right, top-left, bottom-center, top-center |
| `floating_button_icon` | text | `fa-comments` | Font Awesome icon class for the floating button |
| `floating_button_label` | text | `Chat` | Label text shown on the floating button |
| `floating_chat_title` | text | `AI Assistant` | Title shown in the floating chat modal header |

### Interactive Form Mode

**Location:** CMS → Edit page → llmChat component settings → `enable_form_mode`

Interactive Form Mode transforms the chat interface from free-text input to structured form interactions. When enabled, the LLM is instructed to return JSON Schema-based forms instead of plain text responses, and the text input field is disabled.

#### When to Use Form Mode

- **Guided Conversations**: When you want to guide users through a structured decision tree
- **Questionnaires**: For collecting structured data through predefined questions
- **Assessment Tools**: Mental health screenings, intake forms, or surveys
- **Educational Modules**: Step-by-step learning with multiple-choice questions
- **Research Studies**: Ensuring consistent data collection across participants

#### How It Works

1. **Form Generation**: The LLM receives a system prompt instructing it to return responses as JSON Schema forms
2. **Form Rendering**: The frontend parses the JSON and renders Bootstrap 4.6 form components (radio buttons, checkboxes, dropdowns)
3. **User Selection**: Users interact with form elements instead of typing
4. **Readable Submission**: User selections are converted to human-readable text and displayed as their message
5. **LLM Response**: The LLM receives the readable text and generates the next form or response

#### Configuration Requirements

Form Mode works best when combined with:

| Setting | Requirement | Reason |
|---------|-------------|--------|
| `auto_start_conversation` | **Required** | LLM needs to initiate with a form since user cannot type |
| `conversation_context` | **Recommended** | Provides guidance on what forms to generate |
| `strict_conversation_mode` | **Optional** | Keeps form topics focused |

#### UI Labels (Translatable)

| Field | Default | Description |
|-------|---------|-------------|
| `form_mode_active_title` | "Form Mode Active" | Title shown when text input is disabled |
| `form_mode_active_description` | "Please use the form above to respond." | Description shown when text input is disabled |

#### Dynamic Form Detection

**NEW**: Forms are now detected and rendered automatically, even when `enable_form_mode` is disabled. This allows LLMs to dynamically return forms when appropriate. The key differences:

| Feature | Form Mode Enabled | Form Mode Disabled |
|---------|-------------------|---------------------|
| Text input | Hidden | Visible |
| Form detection | Always | Always |
| Form rendering | Always | When LLM returns form JSON |
| Form submission | Works | Works |

This means you can create hybrid experiences where users can type freely, but the LLM can occasionally present structured forms for specific interactions.

#### JSON Schema Format

The LLM returns forms conforming to this schema:

```json
{
  "type": "form",
  "title": "Form Title",
  "description": "Optional instructions for the user",
  "contentBefore": "Optional markdown content displayed before form fields",
  "fields": [
    {
      "id": "selection_field",
      "type": "radio|checkbox|select",
      "label": "Selection question",
      "required": true,
      "options": [
        { "value": "option1", "label": "Option 1" },
        { "value": "option2", "label": "Option 2" }
      ],
      "helpText": "Optional help text"
    },
    {
      "id": "text_field",
      "type": "text|textarea",
      "label": "Text input question",
      "required": false,
      "placeholder": "Enter your response...",
      "maxLength": 500,
      "rows": 3
    }
  ],
  "contentAfter": "Optional markdown content displayed after form fields",
  "submitLabel": "Submit Button Text"
}
```

**Important**: The `type` field must be `"form"` for the frontend to recognize it as a form definition.

#### Form Data Storage

When a user submits a form, the data is stored as follows:
- **User message content**: Human-readable text representation of selections
- **Message attachments**: JSON metadata with `type: "form_submission"` and `values` object

This allows:
- Clean display in chat history
- Structured data retrieval in admin console
- Form reconstruction with user selections highlighted

#### Rich Content Support

Forms can include rich markdown content before and after form fields using `contentBefore` and `contentAfter` properties. This is useful for:

- **Educational Content**: Explaining concepts before asking questions
- **Instructions**: Providing detailed guidance
- **Summaries**: Showing results or recommendations after form submission

For more complex layouts, use the `sections` array to interleave content and fields:

```json
{
  "type": "form",
  "title": "Learning Module",
  "sections": [
    { "type": "content", "content": "## Introduction\n\nWelcome to this module..." },
    { "id": "q1", "type": "radio", "label": "Question 1", "options": [...] },
    { "type": "content", "content": "### Important Note\n\nBefore continuing..." },
    { "id": "q2", "type": "checkbox", "label": "Question 2", "options": [...] }
  ],
  "fields": [],
  "submitLabel": "Continue"
}
```

**Note**: When using `sections`, you can leave `fields` as an empty array. The renderer will process sections in order.

#### Field Types

| Type | Description | Use Case |
|------|-------------|----------|
| `radio` | Single selection from options | Yes/No questions, single-choice |
| `checkbox` | Multiple selections allowed | Multi-select preferences |
| `select` | Single selection dropdown (uses react-select) | Long option lists |
| `text` | Single-line text input | "Other, please specify", short answers |
| `textarea` | Multi-line text input | Detailed responses, comments |

#### Field Properties

| Property | Required | Type | Description |
|----------|----------|------|-------------|
| `id` | Yes | All | Unique identifier for the field |
| `type` | Yes | All | One of: `radio`, `checkbox`, `select`, `text`, `textarea` |
| `label` | Yes | All | Question or field label displayed to user |
| `options` | Yes* | Selection | Array of `{value, label}` pairs (*required for radio/checkbox/select) |
| `required` | No | All | Whether field must be filled (default: false) |
| `helpText` | No | All | Additional help text shown below the label |
| `placeholder` | No | Text | Placeholder text for text/textarea fields |
| `maxLength` | No | Text | Maximum character length for text/textarea |
| `rows` | No | Textarea | Number of rows for textarea (default: 3) |

#### Text Field Examples

**Single-line text for "Other" option:**
```json
{
  "id": "other_reason",
  "type": "text",
  "label": "Please specify",
  "required": false,
  "placeholder": "Enter your reason...",
  "maxLength": 200
}
```

**Multi-line textarea for detailed responses:**
```json
{
  "id": "additional_comments",
  "type": "textarea",
  "label": "Any additional comments?",
  "required": false,
  "placeholder": "Share any additional thoughts...",
  "rows": 4,
  "maxLength": 1000
}
```

#### Multiple Questions Per Form

Forms can contain multiple questions when it makes sense to group them:

```json
{
  "type": "form",
  "title": "Personal Information",
  "description": "Please provide the following details",
  "fields": [
    {
      "id": "age_group",
      "type": "select",
      "label": "Age Group",
      "required": true,
      "options": [
        {"value": "18-24", "label": "18-24"},
        {"value": "25-34", "label": "25-34"},
        {"value": "35-44", "label": "35-44"},
        {"value": "45+", "label": "45 and above"}
      ]
    },
    {
      "id": "gender",
      "type": "radio",
      "label": "Gender",
      "required": true,
      "options": [
        {"value": "male", "label": "Male"},
        {"value": "female", "label": "Female"},
        {"value": "other", "label": "Other"},
        {"value": "prefer_not", "label": "Prefer not to say"}
      ]
    },
    {
      "id": "gender_other",
      "type": "text",
      "label": "If other, please specify",
      "required": false,
      "placeholder": "Enter your gender..."
    }
  ],
  "submitLabel": "Continue"
}
```

#### Controlling Text Fields via Context

If you want to restrict the LLM from using text fields, include explicit instructions in your conversation context:

```markdown
## Form Restrictions
- DO NOT use text or textarea fields
- Only use radio, checkbox, and select field types
- All user input must be through predefined options
```

Alternatively, to encourage text fields:
```markdown
## Form Guidelines
- For questions with "Other" options, always add a text field for specification
- Use textarea fields for open-ended questions requiring detailed responses
- Text fields are encouraged for collecting personalized information
```

#### Example Context for Form Mode

```markdown
# Anxiety Assessment Module

You are an AI assistant conducting an anxiety screening assessment.

## Instructions
- Always respond with a JSON form following the FormDefinition schema
- Present one question at a time for better user experience
- Use appropriate field types (radio for single-choice, checkbox for multi-select)
- After collecting responses, provide a summary and recommendations

## Assessment Questions
1. How often do you feel nervous or anxious? (Never, Sometimes, Often, Always)
2. What situations trigger your anxiety? (Work, Social, Health, Financial, Other)
3. How does anxiety affect your daily life? (Sleep, Appetite, Concentration, Relationships)

## Response Guidelines
- Be empathetic in form descriptions
- Use clear, non-clinical language in options
- Provide helpful context in form descriptions
```

#### User Experience Flow

1. **Page Load**: User visits page with Form Mode enabled
2. **Auto-Start**: LLM automatically sends first form (requires `auto_start_conversation`)
3. **Form Display**: User sees rendered form with radio/checkbox/dropdown options
4. **Selection**: User makes selections and clicks "Submit"
5. **Message Display**: User's selections appear as readable text in chat
6. **Next Form**: LLM responds with next form or summary

#### Example User Message Display

When a user submits a form with selections, it appears as:

```
Form Submission: "Anxiety Assessment - Question 1"

- How often do you feel nervous or anxious?: Sometimes
- What triggers your anxiety most?: Work, Social situations
```

#### Combining with Auto-Start

For Form Mode to work properly, `auto_start_conversation` should be enabled:

```
✅ Recommended Configuration:
- enable_form_mode: true
- auto_start_conversation: true
- conversation_context: [detailed form instructions]

❌ Not Recommended:
- enable_form_mode: true
- auto_start_conversation: false
  (User cannot type, so conversation cannot start!)
```

#### Best Practices

1. **Clear Instructions**: Provide detailed context about what forms to generate
2. **One Question at a Time**: Guide LLM to present forms progressively
3. **Meaningful Options**: Ensure form options are clear and comprehensive
4. **Fallback Handling**: LLM may occasionally return text instead of forms
5. **Testing**: Test form generation with your specific context before deployment

### Progress Tracking

**Location:** CMS → Edit page → llmChat component settings → `enable_progress_tracking`

Progress tracking shows users how much of the conversation context has been covered. This is useful for educational modules, guided conversations, or any scenario where you want users to explore all defined topics.

#### How It Works

**IMPORTANT**: Progress is tracked based on **USER QUESTIONS ONLY**, not AI responses. When the AI mentions a topic, it does NOT count as coverage. Only when the USER explicitly asks about a topic does it count toward progress.

1. **Topic Definition**: Define trackable topics in your conversation context using one of these formats:
   - **TRACKABLE_TOPICS section** (recommended for many topics)
   - **Inline [TOPIC: ...] markers** (for embedding in content)

2. **Coverage Calculation**: When a user asks about a topic (keyword match), that topic is marked as covered.

3. **Monotonic Progress**: Progress can only increase, never decrease.

4. **Depth Bonus**: Multiple user questions about the same topic add a small depth bonus (up to 50% extra per topic).

#### Defining Topics

**Method 1: TRACKABLE_TOPICS Section (Recommended)**

```markdown
## TRACKABLE_TOPICS

- name: Arsenal FC
  keywords: arsenal, gunners, emirates, arteta
- name: Chelsea FC
  keywords: chelsea, blues, stamford bridge
- name: Liverpool FC
  keywords: liverpool, reds, anfield, klopp
```

**Method 2: Inline Topic Markers**

```markdown
[TOPIC: Arsenal FC | arsenal, gunners, emirates, arteta]
[TOPIC: Chelsea FC | chelsea, blues, stamford bridge]
```

**Method 3: Attribute Format**

```markdown
[TOPIC:id="arsenal" name="Arsenal FC" keywords="arsenal,gunners,emirates"]
```

#### Configuration Options

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enable_progress_tracking` | checkbox | `false` | Enable progress tracking |
| `progress_bar_label` | text | `Progress` | Label shown next to the progress bar |
| `progress_complete_message` | text | `Great job! You have covered all topics.` | Message shown when 100% is reached |
| `progress_show_topics` | checkbox | `false` | Show expandable topic list with coverage status |

#### Example Configuration

For a Premier League teams educational module:

**enable_progress_tracking:** ✅ Enabled
**progress_bar_label:** `Learning Progress`
**progress_complete_message:** `🎉 Congratulations! You've explored all 20 teams!`
**progress_show_topics:** ✅ Enabled

**Conversation Context:**
```markdown
# Premier League Teams Expert

You are an expert on the English Premier League.

## TRACKABLE_TOPICS

- name: Arsenal FC
  keywords: arsenal, gunners, emirates, arteta
- name: Chelsea FC
  keywords: chelsea, blues, stamford bridge
- name: Tottenham Hotspur
  keywords: tottenham, spurs, north london
- name: Manchester United
  keywords: manchester united, man united, red devils, old trafford
- name: Liverpool FC
  keywords: liverpool, reds, anfield

## Team Information

**Arsenal FC** - The Gunners
- Stadium: Emirates Stadium
- Founded: 1886
...
```

#### Progress Calculation Example

| User Action | Progress |
|-------------|----------|
| Conversation starts | 0% |
| User asks: "Tell me about Arsenal" | 5% (1/20 topics) |
| AI responds with Arsenal info | Still 5% (AI responses don't count) |
| User asks: "What about Chelsea?" | 10% (2/20 topics) |
| User asks: "More about Arsenal's history" | 12.5% (depth bonus for Arsenal) |
| User asks about Man United and Liverpool | 20% (4/20 topics) |

#### Use Cases

- **Educational Modules**: Track which lessons or topics students have covered
- **Product Tours**: Guide users through all features of a product
- **Onboarding**: Ensure new users learn about all important aspects
- **Research Studies**: Verify participants have engaged with all required content
- **Guided Conversations**: Encourage users to explore all available topics

#### API Response

When progress tracking is enabled, the `get_conversation` and `get_progress` endpoints return:

```json
{
  "progress": {
    "percentage": 15.0,
    "topics_total": 20,
    "topics_covered": 3,
    "is_complete": false,
    "user_messages_analyzed": 5,
    "topic_coverage": {
      "topic_abc123": {
        "id": "topic_abc123",
        "title": "Arsenal FC",
        "coverage": 100,
        "depth": 2,
        "matches": ["arsenal", "gunners"],
        "is_covered": true
      },
      "topic_def456": {
        "id": "topic_def456",
        "title": "Chelsea FC",
        "coverage": 0,
        "depth": 0,
        "matches": [],
        "is_covered": false
      }
    },
    "config": {
      "enabled": true,
      "barLabel": "Learning Progress",
      "completeMessage": "Congratulations!",
      "showTopics": true
    }
  }
}
```

#### Best Practices

1. **Define Topics Explicitly**: Use TRACKABLE_TOPICS section or [TOPIC: ...] markers for reliable tracking
2. **Choose Good Keywords**: Include the topic name plus common variations, nicknames, and related terms
3. **Avoid Keyword Overlap**: Make keywords distinct enough that unrelated questions don't trigger coverage
4. **Appropriate Granularity**: 10-30 topics works well; too few = jumpy progress, too many = slow progress
5. **Test Your Keywords**: Try sample user questions to ensure they trigger the right topics

#### Troubleshooting

**Progress jumps to 100% immediately**
- Your topics aren't being parsed correctly
- Use the TRACKABLE_TOPICS section format
- Check that keywords are specific enough

**Progress doesn't increase**
- Keywords might not match user messages
- Keywords are case-insensitive but must be substrings
- Check the API response to see which keywords matched

**Progress increases when AI responds**
- This shouldn't happen - only USER messages count
- If it does, please report the bug

### Strict Conversation Mode

**Location:** CMS → Edit page → llmChat component settings → `strict_conversation_mode`

When enabled, strict conversation mode enhances the system context with enforcement instructions that guide the LLM to stay within defined topics. This feature is particularly useful for:

- **Educational modules** - Keep discussions focused on learning objectives
- **Therapeutic applications** - Maintain therapeutic boundaries
- **Research studies** - Ensure consistent experimental conditions

#### How It Works

1. **Context Enhancement**: The system automatically prepends enforcement instructions to your conversation context
2. **Topic Analysis**: Key topics are extracted from your configured context to provide specific redirection examples
3. **Natural Enforcement**: The AI itself enforces topic boundaries through polite redirection rather than separate processing

#### Configuration Requirements

Strict mode requires conversation context to be configured. Without context, the feature will be automatically disabled.

#### Enforcement Behavior

When users ask off-topic questions, the AI responds with polite redirection messages like:
- "I'm here to help you with [topics]. Is there something specific about these topics I can assist you with?"
- "That's outside my focus area for this conversation. I'm specialized in discussing [topics]. What would you like to know about that?"

#### Example Configuration

```markdown
# Mental Health Education Context
You are an AI assistant helping users learn about anxiety and stress management.

## Key Topics
- Anxiety symptoms and causes
- Stress reduction techniques
- Breathing exercises
- Cognitive behavioral strategies
- Professional help resources

## Guidelines
- Be empathetic and supportive
- Provide evidence-based information
- Encourage professional consultation when appropriate
```

With strict mode enabled, if a user asks "What's the weather like today?", the AI will redirect back to mental health topics.

### Auto-Start Conversation

**Location:** CMS → Edit page → llmChat component settings → `auto_start_conversation` and `auto_start_message`

The auto-start conversation feature automatically initiates a conversation when no active conversation exists, providing an immediate and engaging user experience.

#### How It Works

1. **Automatic Detection**: When a user visits a page with the llmChat component, the system checks if they have any existing conversations
2. **Context-Aware Messages**: If conversation context is configured, the AI analyzes the context and generates topic-specific initial messages
3. **Fallback Messages**: If no context is configured or analysis fails, the system uses the configured fallback message
4. **Session Tracking**: Auto-start happens only once per user per page section to prevent spam

#### Configuration Options

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `auto_start_conversation` | checkbox | `false` | Enable automatic conversation initiation |
| `auto_start_message` | markdown | `"Hello! I'm here to help you. What would you like to talk about?"` | Fallback message when context analysis isn't available |

#### Smart Context Analysis

When conversation context is configured, the system automatically generates engaging, topic-specific opening messages:

**Example Context:**
```markdown
You are a fitness coach helping users with exercise routines and nutrition.

Key topics:
- Strength training programs
- Cardiovascular exercises
- Nutrition and meal planning
- Injury prevention
- Motivation and goal setting
```

**Generated Auto-Start Message:**
*"Hi there! I'm your fitness coach, ready to help you with strength training, cardio routines, nutrition planning, and reaching your fitness goals. What aspect of fitness would you like to focus on today?"*

#### Behavior Rules

- **Single Auto-Start**: Occurs only once per user per page section
- **No Override**: Won't auto-start if user already has conversations
- **Context Integration**: Auto-start messages include the full conversation context
- **Rate Limited**: Respects existing rate limiting rules
- **Session-Based**: Uses session tracking to prevent duplicates

#### Use Cases

- **Educational Platforms**: Immediately engage learners with topic-specific introductions
- **Customer Support**: Provide instant assistance with context-aware greetings
- **Research Studies**: Ensure consistent initial interactions for experimental protocols
- **Therapeutic Applications**: Start sessions with appropriate therapeutic framing

#### Configuration Example

For a mental health support chat:

**auto_start_conversation:** ✅ Enabled
**auto_start_message:** `"I'm here to support you with anxiety management, stress reduction, and emotional wellness. What's on your mind today?"`

**Conversation Context:**
```markdown
You are a supportive AI companion for anxiety management.

Focus areas:
- Breathing techniques for anxiety
- Cognitive strategies
- Stress management tools
- Professional resource guidance
```

### Display Limits

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `conversation_limit` | number | `20` | Max conversations in sidebar |
| `message_limit` | number | `100` | Max messages loaded per conversation |

### Conversation Context

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `conversation_context` | markdown | - | System instructions sent to AI |

See [Conversation Context](conversation-context.md) for detailed usage.

### UI Labels (Translatable)

All UI text is configurable and supports SelfHelp translations:

#### Chat Interface

| Field | Default | Description |
|-------|---------|-------------|
| `chat_description` | "Chat with AI assistant" | Description above chat |
| `message_placeholder` | "Type your message here..." | Input placeholder |
| `default_chat_title` | "AI Chat" | Default conversation title |

#### Buttons

| Field | Default | Description |
|-------|---------|-------------|
| `submit_button_label` | "Send Message" | Send button text |
| `new_chat_button_label` | "New Conversation" | New conversation button |
| `clear_button_label` | "Clear" | Clear input button |

#### Sidebar

| Field | Default | Description |
|-------|---------|-------------|
| `conversations_heading` | "Conversations" | Sidebar heading |
| `no_conversations_message` | "No conversations yet..." | Empty state message |
| `select_conversation_heading` | "Select a conversation..." | No selection heading |
| `select_conversation_description` | "Choose from sidebar..." | No selection description |

#### Modal Dialogs

| Field | Default | Description |
|-------|---------|-------------|
| `new_conversation_title_label` | "New Conversation" | Create modal title |
| `conversation_title_label` | "Conversation Title (optional)" | Title input label |
| `conversation_title_placeholder` | "Enter title..." | Title input placeholder |
| `cancel_button_label` | "Cancel" | Cancel button |
| `create_button_label` | "Create Conversation" | Create button |
| `delete_confirmation_title` | "Delete Conversation" | Delete modal title |
| `delete_confirmation_message` | "Are you sure...?" | Delete confirmation |
| `confirm_delete_button_label` | "Delete" | Confirm delete button |
| `cancel_delete_button_label` | "Cancel" | Cancel delete button |

#### Status Messages

| Field | Default | Description |
|-------|---------|-------------|
| `loading_text` | "Loading..." | Loading indicator |
| `no_messages_message` | "No messages yet..." | Empty conversation |
| `empty_state_title` | "Start a conversation" | Empty state title |
| `empty_state_description` | "Send a message..." | Empty state description |
| `loading_messages_text` | "Loading messages..." | Messages loading |

#### Model Display

| Field | Default | Description |
|-------|---------|-------------|
| `model_label_prefix` | "Model: " | Model badge prefix |
| `tokens_used_suffix` | " tokens" | Token count suffix |

#### File Uploads

| Field | Default | Description |
|-------|---------|-------------|
| `upload_image_label` | "Upload Image (Vision Models)" | Upload label |
| `upload_help_text` | "Supported formats: JPG, PNG..." | Upload help |
| `attach_files_title` | "Attach files" | Attach button tooltip |
| `no_vision_support_title` | "Current model does not support..." | No vision tooltip |
| `no_vision_support_text` | "No vision" | No vision badge |
| `single_file_attached_text` | "1 file attached" | Single file indicator |
| `multiple_files_attached_text` | "{count} files attached" | Multiple files indicator |
| `remove_file_title` | "Remove file" | Remove file tooltip |

#### Error Messages

| Field | Default | Description |
|-------|---------|-------------|
| `empty_message_error` | "Please enter a message" | Empty input error |

#### Button Tooltips

| Field | Default | Description |
|-------|---------|-------------|
| `delete_button_title` | "Delete conversation" | Delete tooltip |
| `send_message_title` | "Send message" | Send tooltip |

## Admin Console Configuration

**Location:** Admin → Modules → LLM Conversations (`/admin/module_llm/conversations`)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `admin_page_size` | number | `50` | Items per page |
| `admin_refresh_interval` | number | `300` | Auto-refresh interval (0 = disabled) |
| `admin_default_view` | select | `conversations` | Default view mode |
| `admin_show_filters` | checkbox | `true` | Show filters by default |

## Constants

Defined in `server/service/globals.php`:

### Rate Limiting

```php
define('LLM_RATE_LIMIT_REQUESTS_PER_MINUTE', 10);
define('LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS', 3);
define('LLM_RATE_LIMIT_COOLDOWN_SECONDS', 60);
```

### File Uploads

```php
define('LLM_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('LLM_MAX_FILES_PER_MESSAGE', 5);

define('LLM_ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('LLM_ALLOWED_DOCUMENT_EXTENSIONS', ['pdf', 'txt', 'md', 'csv', 'json', 'xml']);
define('LLM_ALLOWED_CODE_EXTENSIONS', ['py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml']);
```

### Vision Models

```php
define('LLM_VISION_MODELS', [
    'internvl3-8b-instruct', 
    'qwen3-vl-8b-instruct'
]);
```

### API Defaults

```php
define('LLM_DEFAULT_MODEL', 'qwen3-vl-8b-instruct');
define('LLM_DEFAULT_TEMPERATURE', 0.7);
define('LLM_DEFAULT_MAX_TOKENS', 2048);
define('LLM_DEFAULT_TIMEOUT', 30);
define('LLM_DEFAULT_CONVERSATION_LIMIT', 20);
define('LLM_DEFAULT_MESSAGE_LIMIT', 100);
```

## Environment-Specific Configuration

### Development

For development, you may want to:

1. Set lower timeouts for faster feedback
2. Enable verbose logging
3. Use a test API endpoint

### Production

For production, ensure:

1. API key is properly secured
2. Rate limiting is appropriate for user base
3. Monitoring is configured
4. Backup strategy for conversation data

## Configuration Loading

### PHP Service Loading

```php
// In LlmService::getLlmConfig()
public function getLlmConfig() {
    static $config = null;
    
    if ($config === null) {
        // Load via stored procedure
        $page_data = $this->db->query_db_first(
            'CALL get_page_fields(?, ?, ?, ?, ?)',
            [$page_id, 1, 1, '', '']
        );
        
        // Extract llm_* fields
        foreach ($page_data as $key => $value) {
            if (strpos($key, 'llm_') === 0) {
                $config[$key] = $value;
            }
        }
        
        // Apply defaults
        $config = array_merge($defaults, $config);
    }
    
    return $config;
}
```

### React Config Loading

```typescript
// API-based loading (preferred)
const config = await configApi.get();

// Fallback from data attributes
const config = parseConfig(container);
```

## Troubleshooting Configuration

### Config Not Loading

1. Check page field values in database
2. Verify stored procedure access
3. Clear caches
4. Check for PHP errors

### Translations Not Working

1. Confirm field has `display = 1`
2. Add translations for each language
3. Clear template cache

### Model Not Appearing

1. Check API connectivity
2. Verify API key permissions
3. Check hook registration
4. Review browser console for errors

### Rate Limiting Issues

1. Check cache configuration
2. Verify user session
3. Monitor rate limit cache keys
4. Adjust limits if needed

