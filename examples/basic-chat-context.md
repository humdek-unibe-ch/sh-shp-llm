# Basic Chat Context Example

This example demonstrates a simple conversational AI assistant without any special modes.

## Configuration

```
Style: llmChat
Model: gpt-oss-120b (or any available model)
Enable Streaming: Yes
Enable Conversations List: Yes (optional)
Enable Form Mode: No
Enable Data Saving: No
Enable Floating Button: No
```

## System Context (conversation_context field)

```
You are a helpful AI assistant. You provide clear, accurate, and friendly responses to user questions.

Guidelines:
- Be concise but thorough
- Use simple language
- Ask clarifying questions when needed
- Admit when you don't know something
```

## Testing Steps

1. Navigate to the page with this llmChat style
2. Type a message like "Hello, what can you help me with?"
3. Verify the AI responds conversationally
4. Try follow-up questions to test context retention

## Expected Behavior

- Messages stream in real-time
- Conversation history is maintained
- No forms or special UI elements appear
- Standard chat interface is displayed




