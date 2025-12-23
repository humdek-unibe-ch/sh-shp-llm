# Multiple Chat Instances Context Example

This example demonstrates having multiple llmChat instances on the same page.

## Overview

You can have multiple llmChat styles on a single page. Each instance:
- Has its own unique section ID
- Maintains separate conversations
- Can have different configurations
- Can be a mix of floating and inline styles

## Configuration Examples

### Instance 1: General Assistant (Inline)

```
Style: llmChat
Section ID: 2255 (auto-assigned)
Model: gpt-oss-120b
Enable Floating Button: No
Enable Conversations List: Yes
```

System Context:
```
You are a general-purpose AI assistant.
```

### Instance 2: Code Helper (Inline)

```
Style: llmChat
Section ID: 2256 (auto-assigned)
Model: gpt-oss-120b
Enable Floating Button: No
Enable Conversations List: No
```

System Context:
```
You are a coding assistant. Help with programming questions, code review, and debugging.
Format code using markdown code blocks with language specification.
```

### Instance 3: Support Bot (Floating)

```
Style: llmChat
Section ID: 2257 (auto-assigned)
Model: gpt-oss-120b
Enable Floating Button: Yes
Floating Button Position: bottom-right
Floating Button Icon: fa-headset
Floating Chat Title: "Support"
```

System Context:
```
You are a customer support assistant. Be helpful and concise.
```

### Instance 4: Sales Bot (Floating)

```
Style: llmChat
Section ID: 2258 (auto-assigned)
Model: gpt-oss-120b
Enable Floating Button: Yes
Floating Button Position: bottom-left
Floating Button Icon: fa-comments
Floating Chat Title: "Sales Chat"
```

System Context:
```
You are a sales assistant. Help users learn about products and services.
```

## Page Layout Example

```html
<div class="container">
  <div class="row">
    <!-- Inline Chat 1 -->
    <div class="col-md-6">
      <h3>General Assistant</h3>
      <!-- llmChat section 2255 -->
    </div>
    
    <!-- Inline Chat 2 -->
    <div class="col-md-6">
      <h3>Code Helper</h3>
      <!-- llmChat section 2256 -->
    </div>
  </div>
</div>

<!-- Floating buttons will appear automatically -->
<!-- Section 2257: bottom-right -->
<!-- Section 2258: bottom-left -->
```

## Testing Steps

1. Add multiple llmChat styles to a page
2. Configure each with different settings
3. Navigate to the page
4. Verify all instances load correctly
5. Type in one chat - verify it doesn't appear in others
6. Open floating buttons - each has its own conversation
7. Refresh page - conversations persist separately

## Technical Details

### Section Isolation

Each chat instance uses its section ID for:
- API calls (`?action=get_conversation&section_id=2255`)
- Conversation filtering (only shows conversations for that section)
- Data saving (saves to section-specific table)

### DOM Structure

```html
<!-- Each instance has class, not ID -->
<div class="llm-chat-root" data-section-id="2255" data-config="...">
</div>

<div class="llm-chat-root" data-section-id="2256" data-config="...">
</div>

<!-- Floating buttons use unique IDs -->
<div id="llm-float-2257" class="llm-floating-chat-wrapper">
</div>

<div id="llm-float-2258" class="llm-floating-chat-wrapper">
</div>
```

### Z-Index Stacking

Multiple floating buttons at the same position stack properly:
- Base z-index: 1050
- Each position has an offset
- Section ID provides additional offset
- Panels appear above their buttons

## Expected Behavior

- All chat instances load independently
- Typing in one doesn't affect others
- Each maintains its own conversation history
- Floating buttons can coexist
- Configurations are independent
- Data saving is isolated per section


