# Auto-Start Conversation Feature - Implementation Plan

## Overview
Implement an auto-start conversation feature that automatically initiates a conversation when no active conversation exists and the feature is enabled. When enabled, the AI will automatically send an initial message to start the conversation, providing context and guiding the user.

## Requirements
- Add configurable checkbox field `auto_start_conversation` to llmChat component
- Add configurable text field `auto_start_message` for the initial AI message
- Automatically create and send the auto-start message when no conversation exists
- Include conversation context in auto-start messages
- Ensure auto-start only happens once per user/section combination
- Respect existing conversation selection logic
- Work with both streaming and non-streaming modes
- Prevent duplicate auto-start messages

## Database Changes

### 1. Add Auto-Start Fields to llmChat Style
```sql
-- Add auto-start conversation checkbox field (user visible, translatable)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'auto_start_conversation', get_field_type_id('checkbox'), '1');

-- Add auto-start message field (user visible, translatable, supports markdown)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'auto_start_message', get_field_type_id('markdown'), '1');

-- Link fields to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('auto_start_conversation'), '0', 'Automatically start a conversation when no active conversation exists. The AI will send an initial message to begin the interaction.'),
(get_style_id('llmChat'), get_field_id('auto_start_message'), 'Hello! I''m here to help you. What would you like to talk about?', 'The initial message sent by the AI when auto-starting a conversation. This message will include the conversation context and help guide the user.');
```

## Code Changes

### 2. Update LlmchatModel.php
- Add `auto_start_conversation` property
- Add `auto_start_message` property
- Add getter methods `isAutoStartConversationEnabled()` and `getAutoStartMessage()`
- Initialize properties in constructor

### 3. Update LlmchatController.php
- Add `checkAndAutoStartConversation()` method
- Modify initialization logic to check for auto-start conditions
- Add API endpoint for auto-start message sending
- Ensure auto-start only happens when no conversation exists
- Include conversation context in auto-start messages

### 4. Update LlmService.php
- Add `createAutoStartMessage()` method
- Track auto-start messages to prevent duplicates
- Include context tracking for auto-start messages

### 5. Update React Configuration (LlmChat.tsx)
- Add parsing for `autoStartConversation` and `autoStartMessage` config fields
- Pass auto-start configuration to chat components

### 6. Update useChatState.ts
- Add auto-start logic in conversation loading
- Prevent auto-start when conversation already exists
- Handle auto-start message creation via API

## Implementation Strategy

### Best Practices & Safety Measures

1. **Single Auto-Start Per Session**: Only auto-start once when no conversation exists
2. **Context Inclusion**: Always include conversation context in auto-start messages
3. **Rate Limiting**: Respect existing rate limiting for message creation
4. **User Consent**: Only auto-start when explicitly enabled in component configuration
5. **Conversation Mode Awareness**:
   - When conversations list is **enabled**: Auto-start only if no conversations exist at all
   - When conversations list is **disabled**: Auto-start only if no conversation exists for this section
6. **Duplicate Prevention**: Track auto-start messages to avoid sending multiple times
7. **Error Handling**: Gracefully handle API failures during auto-start
8. **Backwards Compatibility**: Feature is opt-in, existing installations unaffected

### Auto-Start Logic Flow

```
User loads chat component
    ↓
Check if auto-start is enabled
    ↓
Check if conversation exists
    ↓ No conversation exists
    ↓
Check if auto-start already happened (session/cookie tracking)
    ↓ Not auto-started yet
    ↓
Create new conversation
    ↓
Send auto-start message with context
    ↓
Display conversation with AI's initial message
```

### Files to Modify
- `server/db/v1.0.0.sql` - Add new database fields
- `server/component/style/llmchat/LlmchatModel.php` - Add field properties and getters
- `server/component/style/llmchat/LlmchatController.php` - Add auto-start logic
- `server/service/LlmService.php` - Add auto-start message creation
- `react/src/LlmChat.tsx` - Add config parsing
- `react/src/hooks/useChatState.ts` - Add frontend auto-start handling

### Testing Checklist
- [ ] Auto-start works when enabled and no conversation exists
- [ ] Auto-start doesn't trigger when conversation already exists
- [ ] Auto-start includes conversation context properly
- [ ] Auto-start works with both streaming and non-streaming modes
- [ ] Auto-start respects conversation list enabled/disabled settings
- [ ] No duplicate auto-start messages are created
- [ ] Rate limiting is respected during auto-start
- [ ] Backwards compatibility maintained (feature is opt-in)
- [ ] Error handling works gracefully when API calls fail

### Configuration Examples

#### Basic Auto-Start Setup
```
auto_start_conversation: true
auto_start_message: "Hello! I'm here to help you learn about anxiety management. What specific topic would you like to explore today?"
```

#### Advanced Auto-Start with Context
```
conversation_context: "You are a supportive AI assistant specializing in anxiety education..."
auto_start_conversation: true
auto_start_message: "Welcome to your personalized anxiety learning journey! I'm here to guide you through evidence-based strategies and techniques. What aspect of anxiety would you like to focus on first?"
```

### Security Considerations
- Auto-start messages should not contain sensitive information
- Rate limiting prevents abuse through forced conversation creation
- Context validation ensures only configured context is used
- User authentication required before auto-start

### Performance Impact
- Minimal impact: Only triggers when no conversation exists
- Uses existing message creation infrastructure
- Context parsing happens only when needed
- Streaming mode handles auto-start efficiently

## Migration Notes
- Existing installations: Feature disabled by default, no migration needed
- New installations: Can enable auto-start during component setup
- Database: New fields added with default values, no data migration required
