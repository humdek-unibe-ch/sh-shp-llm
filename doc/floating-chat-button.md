# Floating Chat Button Feature

## Overview

The Floating Chat Button feature transforms the LLM Chat interface into a floating action button that opens a chat modal when clicked. This is useful for:

- **Non-intrusive chat access**: Users can access chat without it taking up page real estate
- **Contextual help**: Provide AI assistance without disrupting the main page content
- **Mobile-friendly**: The floating button works well on all screen sizes
- **Multiple instances**: Support for multiple llmChat sections on the same page, each with its own floating button

## Configuration

### Enable Floating Button

**Location:** CMS → Edit page → llmChat component settings

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enable_floating_button` | checkbox | `false` | Enable floating chat button mode |
| `allow_floating_chat_resize` | checkbox | `false` | Allow desktop users to resize and move the floating panel (see below) |
| `floating_button_position` | select | `bottom-right` | Position of the floating button |
| `floating_button_icon` | text | `fa-comments` | Font Awesome icon class, or an image path/URL (`assets/chat.png`, `/assets/chat.svg`, `https://...`) |

A value containing `/` or ending in `.png/.jpg/.jpeg/.gif/.svg/.webp` is treated as an image: the
button and the panel header avatar show it as `<img>` (button image class: `llm-float-btn-img`).
Relative paths such as `assets/chat.png` resolve against the SelfHelp base path.
| `floating_button_label` | text | `Chat` | Label text (hidden on mobile) |
| `floating_chat_title` | text | `AI Assistant` | Modal header title |

### Position Options

The floating button can be positioned at six locations:

| Position | Description |
|----------|-------------|
| `bottom-right` | Bottom right corner (default) |
| `bottom-left` | Bottom left corner |
| `top-right` | Top right corner |
| `top-left` | Top left corner |
| `bottom-center` | Bottom center |
| `top-center` | Top center |

### Icon Options

Use any Font Awesome icon class. Common options:

| Icon | Class | Use Case |
|------|-------|----------|
| 💬 | `fa-comments` | General chat (default) |
| 🤖 | `fa-robot` | AI assistant |
| 🎧 | `fa-headset` | Support chat |
| ❓ | `fa-question-circle` | Help/FAQ |
| 💡 | `fa-lightbulb` | Ideas/suggestions |

## How It Works

### User Flow

1. **Page Load**: A floating button appears in the configured position
2. **Click Button**: Chat modal opens with full chat interface
3. **Chat**: User interacts with AI assistant in the modal
4. **Close**: User closes modal to return to page content
5. **Reopen**: Button remains accessible to continue conversation

### Technical Implementation

When `enable_floating_button` is enabled:

1. The regular chat interface is hidden
2. A `FloatingChat` component is rendered instead
3. The component displays a Bootstrap-styled floating action button
4. Clicking the button opens a Bootstrap modal containing the full `LlmChat` component
5. All chat features work identically within the modal

### Modal Behavior

- **Centered**: Modal is centered on screen
- **Large size**: Uses Bootstrap `lg` size for comfortable chat experience
- **Responsive**: Full-screen on mobile devices
- **Keyboard accessible**: Escape key closes modal
- **Focus management**: Focus is trapped within modal when open

## Styling

The floating chat uses Bootstrap 4.6 classes with minimal custom CSS:

### Button Styles

- Uses Bootstrap `btn-primary` for consistent theming
- 56px circular button on desktop, 50px on mobile
- Smooth hover/active transitions
- Shadow for depth and visibility

### Modal Styles

- Clean white header with primary background
- Rounded corners (12px)
- 70vh height with 600px max for comfortable reading
- Full-screen on mobile for better usability

### Customization

The floating button respects Bootstrap theming. To customize:

```css
/* Change button color */
.llm-floating-btn {
  background-color: #28a745; /* Bootstrap success green */
}

/* Adjust button size */
.llm-floating-btn {
  width: 64px;
  height: 64px;
  font-size: 24px;
}

/* Adjust position offset */
.llm-floating-bottom-right {
  bottom: 32px;
  right: 32px;
}
```

## Multiple Instances

You can have multiple llmChat sections on the same page, each with its own floating button:

```
Page Layout:
├── Section 1: llmChat (floating, bottom-right, "Support")
├── Section 2: llmChat (floating, bottom-left, "FAQ")
└── Main content...
```

Each section:
- Has its own conversations (filtered by section_id)
- Can use different models
- Can have different context configurations
- Maintains separate floating button positions

## Best Practices

### 1. Position Selection

- **Bottom-right**: Most common, expected location for chat
- **Bottom-left**: Good for RTL layouts or when bottom-right is occupied
- **Top positions**: Use sparingly, may conflict with navigation

### 2. Icon and Label

- Keep labels short (1-2 words)
- Use recognizable icons
- Consider hiding labels on mobile (automatic)

### 3. Context Integration

Combine with other features for best results:

```
✅ Recommended Configuration:
- enable_floating_button: true
- auto_start_conversation: true (for immediate engagement)
- conversation_context: [relevant context for the page]
- enable_conversations_list: false (simpler single-conversation mode)
```

### 4. Resizing and moving the panel (desktop)

Requires `allow_floating_chat_resize` (default **off**). On viewports ≥577px:

- Drag any edge or corner to resize
- Drag the panel header to move
- Small **undo** button in the header restores the default size and position (disabled until the layout has been changed)

Layout is stored in `localStorage` as `llm-float-panel-layout-{sectionId}` (UI preference only — no personal data, no cookie consent). Restore clears it and returns to the default placement for the configured button position. Mobile stays full-screen (no resize/move).

### 5. Mobile Considerations

- Button automatically adjusts size on mobile
- Panel goes full-screen on small devices (resize handles hidden)
- Test on various screen sizes

## Example Configurations

### Support Chat

```
enable_floating_button: ✅
floating_button_position: bottom-right
floating_button_icon: fa-headset
floating_button_label: Support
floating_chat_title: Customer Support
enable_conversations_list: false
auto_start_conversation: true
conversation_context: "You are a helpful customer support assistant..."
```

### Educational Assistant

```
enable_floating_button: ✅
floating_button_position: bottom-left
floating_button_icon: fa-graduation-cap
floating_button_label: Ask AI
floating_chat_title: Learning Assistant
enable_form_mode: true
auto_start_conversation: true
conversation_context: "You are an educational assistant helping students..."
```

### FAQ Bot

```
enable_floating_button: ✅
floating_button_position: bottom-right
floating_button_icon: fa-question-circle
floating_button_label: FAQ
floating_chat_title: Frequently Asked Questions
strict_conversation_mode: true
conversation_context: "You answer questions about our product. Topics: pricing, features, support..."
```

## Troubleshooting

### Button Not Appearing

1. Verify `enable_floating_button` is enabled
2. Check for CSS conflicts hiding the button
3. Ensure React component is loading (check console)
4. Verify user is authenticated

### Modal Not Opening

1. Check browser console for JavaScript errors
2. Verify Bootstrap modal CSS is loaded
3. Check for z-index conflicts

### Chat Not Working in Modal

1. Verify API endpoints are accessible
2. Check conversation context is valid
3. Ensure section_id is being passed correctly

### Position Incorrect

1. Verify position value is one of the valid options
2. Check for CSS conflicts overriding position
3. Clear browser cache and reload

## API Configuration

The floating button configuration is included in the `get_config` API response:

```json
{
  "config": {
    "enableFloatingButton": true,
    "floatingButtonPosition": "bottom-right",
    "floatingButtonIcon": "fa-comments",
    "floatingButtonLabel": "Chat",
    "floatingChatTitle": "AI Assistant",
    ...
  }
}
```

This allows the React component to conditionally render either the full chat interface or the floating button based on configuration.

