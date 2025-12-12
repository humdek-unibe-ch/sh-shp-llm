# Conversation Context Module

## Overview

The Conversation Context Module allows researchers and designers to define custom AI behavior and system instructions without hardcoded prompts. Context is configured per llmChat component instance and sent to the LLM at the start of each API call.

## Features

- **Configurable Context**: Define AI behavior through CMS fields
- **Multi-format Support**: JSON array or free text/markdown formats
- **Context Tracking**: Each message records what context was sent for debugging
- **Multi-language Support**: Context field supports SelfHelp translations
- **No User Visibility**: Context messages are internal and not shown to users

## Configuration

### Setting Context in CMS

1. Navigate to the page containing your llmChat component
2. Edit the component in the CMS
3. Find the `conversation_context` field
4. Enter your context in one of the supported formats

### Format Options

#### Free Text / Markdown (Recommended for Simple Cases)

Simply enter your system instructions as plain text or markdown:

```markdown
You are an AI assistant helping users learn about anxiety and anxiety disorders.

Key guidelines:
- Be empathetic and supportive
- Use simple, clear language
- Break down complex concepts
- Encourage questions and discussion

You are guiding the user through this educational module. Provide helpful, structured guidance based on this module content.
```

This will be automatically converted to a single system message.

#### JSON Format (For Advanced Cases)

For multiple system messages or specific role assignments, use JSON array format:

```json
[
  {
    "role": "system",
    "content": "You are a helpful AI assistant specializing in mental health education."
  },
  {
    "role": "system", 
    "content": "Always respond in a supportive and non-judgmental manner. If a user expresses distress, encourage them to seek professional help."
  }
]
```

## Technical Implementation

### How Context is Processed

1. **Configuration Load**: When the llmChat component initializes, it loads the `conversation_context` field from the database

2. **Context Parsing**: The `getParsedConversationContext()` method in `LlmchatModel.php` parses the context:
   - If JSON array: Validates structure and returns array of message objects
   - If free text: Wraps in single system message object

3. **API Call**: When a message is sent, the `LlmApiFormatterService` prepends context messages to the API request

4. **Tracking**: The context snapshot is stored with each assistant message in the `sent_context` column for debugging

### Code Flow

```php
// In LlmchatController::handleMessageSubmission()

// Get parsed context
$context_messages = $this->model->getParsedConversationContext();

// Convert messages with context prepended
$api_messages = $this->api_formatter_service->convertToApiFormat(
    $messages, 
    $model, 
    $context_messages  // Context prepended here
);

// For streaming, context is tracked
$this->streaming_service->startStreamingResponse(
    $conversation_id,
    $api_messages,
    $model,
    $is_new_conversation,
    $context_messages  // Tracked for audit
);
```

### Database Storage

#### Configuration Field

```sql
-- Field definition
INSERT INTO `fields` (`name`, `id_type`, `display`) VALUES
('conversation_context', get_field_type_id('markdown'), '0');

-- Linked to llmChat style
INSERT INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('conversation_context'), '', 'System context/instructions...');
```

#### Context Tracking

The `llmMessages` table includes a `sent_context` column:

```sql
ALTER TABLE `llmMessages` ADD COLUMN `sent_context` longtext DEFAULT NULL;
```

This stores a JSON snapshot of the context that was sent with each message, enabling:
- Debugging context issues
- Auditing AI behavior
- Tracking context changes over time

## Use Cases

### Educational Modules

```markdown
You are an AI tutor helping students learn about [topic].

Teaching approach:
- Start with fundamentals before advanced concepts
- Use real-world examples and analogies
- Ask comprehension questions to check understanding
- Provide encouragement and positive reinforcement

Current module: [Module Name]
Learning objectives:
1. [Objective 1]
2. [Objective 2]
```

### Therapeutic Support

```markdown
You are a supportive AI companion for a mental health app.

Guidelines:
- Always maintain empathetic, non-judgmental tone
- Never provide medical advice or diagnoses
- Encourage professional help when appropriate
- Use evidence-based therapeutic techniques

Safety rules:
- If user mentions self-harm, provide crisis resources
- If user mentions abuse, encourage reporting
```

### Research Studies

```json
[
  {
    "role": "system",
    "content": "You are participating in a research study about AI-assisted learning. Your responses should follow the experimental protocol defined below."
  },
  {
    "role": "system",
    "content": "Protocol: Condition A - Provide detailed explanations with examples. Always end responses with a follow-up question."
  }
]
```

## Multi-Language Support

The `conversation_context` field supports SelfHelp's translation system:

1. Configure context in the default language (usually English)
2. Switch to other languages in CMS and provide translated context
3. Users see context in their selected language

Example for German translation:
```markdown
Sie sind ein KI-Assistent, der Nutzern hilft, mehr über Angst und Angststörungen zu erfahren.

Wichtige Richtlinien:
- Seien Sie einfühlsam und unterstützend
- Verwenden Sie einfache, klare Sprache
- Erklären Sie komplexe Konzepte verständlich
- Ermutigen Sie zu Fragen und Diskussion
```

## Best Practices

### Do's

- ✅ Keep context focused and relevant
- ✅ Use clear, specific instructions
- ✅ Include safety guidelines when appropriate
- ✅ Test context with various user inputs
- ✅ Use markdown formatting for readability
- ✅ Track context versions for research reproducibility

### Don'ts

- ❌ Don't include sensitive data in context
- ❌ Don't make context too long (impacts token usage)
- ❌ Don't include user-specific information
- ❌ Don't rely on context for security measures
- ❌ Don't forget to test translations

## Troubleshooting

### Context Not Applied

1. Check if field value is saved correctly in CMS
2. Verify JSON syntax if using array format
3. Check browser console for parsing errors
4. Verify `hasConversationContext` returns true in API config

### Context Tracking Not Working

1. Ensure database migration was run
2. Check `sent_context` column exists in `llmMessages`
3. Verify streaming service is passing context to addMessage

### Translation Issues

1. Confirm field is marked as translatable (`display = 1`)
2. Check translation exists for user's language
3. Verify SelfHelp language settings

## API Reference

### Model Methods

```php
// Get raw context string
$context = $model->getConversationContext();

// Get parsed context array
$messages = $model->getParsedConversationContext();

// Check if context is configured
$hasContext = $model->hasConversationContext();
```

### Config API Response

```json
{
  "config": {
    "hasConversationContext": true,
    // ... other config fields
  }
}
```

Note: The actual context content is never exposed to the frontend for security reasons.

