# Conversation Context Module - Implementation Plan

## Overview
Implement a flexible conversation context system for the llmChat component that allows researchers/designers to define custom AI behavior and context without hardcoded instructions.

## Requirements
- Add configurable conversation context field to llmChat component
- Support both JSON and free text/markdown formats
- Track what context was sent with each message for debugging
- Support multi-language context content
- Maintain conversation flow while providing structured guidance

## Database Changes

### 1. Add Context Field to llmChat Style
```sql
-- Add conversation context field (user visible, translatable)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'conversation_context', get_field_type_id('textarea'), '1');

-- Link to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('conversation_context'), '', 'Context/guidance sent to AI. Can be free text, markdown, or JSON. This defines how the AI should behave and what information it has access to.');
```

### 2. Add Context Tracking to Messages
```sql
-- Add context tracking to llmMessages table
ALTER TABLE `llmMessages` ADD COLUMN `sent_context` longtext DEFAULT NULL;
```

## Code Changes

### 3. Update LlmChatModel.php
- Add `conversation_context` property
- Add getter method `getConversationContext()`
- Initialize property in constructor

### 4. Update LlmService.php
- Modify `addMessage()` method to accept `$sent_context` parameter
- Store context as JSON in `sent_context` field

### 5. Update LlmChatController.php
- Add `prepareConversationContext()` method to handle JSON/text parsing
- Modify `handleMessageSubmission()` to include context in API calls
- Update both streaming and non-streaming message handling
- Pass context snapshot for tracking

### 6. Update LlmStreamingService.php
- Modify `startStreamingResponse()` to accept and track context
- Store context snapshot with assistant messages

### 7. Update LlmApiFormatterService.php
- Add optional `$include_context` parameter to `convertToApiFormat()`
- Skip context messages when displaying to users

## Context Format Support

### JSON Format
```json
[
  {
    "role": "system",
    "content": "You are an AI assistant helping users learn about anxiety and anxiety disorders.\n\nKey guidelines:\n- Be empathetic and supportive\n- Use simple, clear language\n- Break down complex concepts\n- Encourage questions and discussion\n\nYou are guiding the user through this educational module. Provide helpful, structured guidance based on this module content. Track progress through the topics and suggest the next logical steps."
  }
]
```

### Free Text Format
```
You are an AI assistant helping users learn about anxiety and anxiety disorders.

Key guidelines:
- Be empathetic and supportive
- Use simple, clear language
- Break down complex concepts
- Encourage questions and discussion

You are guiding the user through this educational module. Provide helpful, structured guidance based on this module content. Track progress through the topics and suggest the next logical steps.

[Educational content here]
```

## Implementation Steps

1. **Database Migration**: Add new fields and table column
2. **Model Updates**: Add context handling to LlmChatModel
3. **Service Updates**: Update LlmService and LlmStreamingService for context tracking
4. **Controller Updates**: Modify LlmChatController for context processing
5. **Formatter Updates**: Update LlmApiFormatterService for context filtering
6. **Testing**: Test both JSON and text formats
7. **Documentation**: Update README with new features

## Files to Modify
- `server/plugins/sh-shp-llm/server/db/v1.0.0.sql`
- `server/plugins/sh-shp-llm/server/component/style/llmchat/LlmChatModel.php`
- `server/plugins/sh-shp-llm/server/service/LlmService.php`
- `server/plugins/sh-shp-llm/server/service/LlmStreamingService.php`
- `server/plugins/sh-shp-llm/server/component/style/llmchat/LlmChatController.php`
- `server/plugins/sh-shp-llm/server/service/LlmApiFormatterService.php`
- `server/plugins/sh-shp-llm/README.md`

## Context Files

**`context_german.md`** - Complete German context including:
- AI assistant behavior guidelines (empathy, language, engagement)
- Module guidance instructions (progress tracking, structured learning)
- Full anxiety disorder educational content covering all therapeutic topics

**`context_english.md`** - English translation with identical structure and content

## Testing Checklist
- [ ] JSON format context parsing works
- [ ] Free text format context works
- [ ] Context is properly tracked in database
- [ ] Context messages are excluded from user display
- [ ] Both streaming and non-streaming work with context
- [ ] Multi-language context support
- [ ] Backwards compatibility maintained
