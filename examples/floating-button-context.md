# Floating Chat Button Context Example

This example demonstrates the floating chat button feature for embedding a chat widget.

## Configuration

```
Style: llmChat
Model: gpt-oss-120b
Enable Floating Button: Yes
Floating Button Position: bottom-right (or: bottom-left, top-right, top-left, bottom-center, top-center)
Floating Button Icon: fa-comments (or: fa-robot, fa-headset, fa-message)
Floating Button Label: "Chat" (optional - leave empty for icon-only)
Floating Chat Title: "AI Assistant"
Enable Conversations List: No (recommended for floating mode)
```

## System Context (conversation_context field)

```
You are a helpful customer support assistant embedded as a floating chat widget.

Guidelines:
- Be concise - users expect quick answers
- Be friendly and professional
- Offer to help with common questions
- Provide links or references when helpful
- Ask if the user needs anything else after answering

Common topics you can help with:
- Product information
- Account questions
- Technical support
- General inquiries
```

## Testing Steps

1. Navigate to the page
2. Observe the floating button in the configured position
3. Click the button to open the chat panel
4. Send a message and verify response
5. Close the panel (click X or outside)
6. Reopen and verify conversation persists
7. Test on mobile to see full-screen mode

## Position Options

| Position | Description |
|----------|-------------|
| bottom-right | Lower right corner (default, most common) |
| bottom-left | Lower left corner |
| top-right | Upper right corner |
| top-left | Upper left corner |
| bottom-center | Bottom center of screen |
| top-center | Top center of screen |

## Multiple Floating Buttons

You can have multiple floating chat buttons on the same page:

1. Create multiple llmChat styles with different section IDs
2. Configure each with `Enable Floating Button: Yes`
3. Set different positions for each (or same position - they will stack)
4. Each button maintains its own conversation

Example: One for sales (bottom-right) and one for support (bottom-left)

## Expected Behavior

- Floating button appears at configured position
- Button shows icon (and optional label)
- Click opens chat panel next to button
- Panel has header with title and close buttons
- Messages scroll within panel
- Input is always visible at bottom
- ESC key closes panel
- Click outside closes panel
- Mobile: panel goes full-screen




