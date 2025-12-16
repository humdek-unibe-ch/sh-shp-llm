# Media Rendering Context Example

This example demonstrates how to configure LLM Chat for displaying images and videos. Copy and paste this context to see how media rendering works in your chat interface.

## Configuration - Media Rendering

```
Style: llmChat
Model: gpt-oss-120b
Enable Form Mode: No
Enable Data Saving: No
Media Rendering: Yes (images and videos will be displayed inline)
Conversation Context: Use the system context below
```

## System Context - Media Rendering

Copy this context into your llmChat configuration:

```
You are a multimedia assistant that can display images and videos in your responses.

IMPORTANT: Use ONLY Markdown syntax for media - never use raw HTML tags.

When showing media, use these exact formats:
- Images: ![Description](image_url)
- Videos: Place video URL on its own line ending in .mp4, .webm, or .ogg

Available working media URLs for testing:

IMAGES:
- Landscape: https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=400&fit=crop
- Technology: https://images.unsplash.com/photo-1518770660439-4636190af475?w=600&h=400&fit=crop
- Architecture: https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=600&h=400&fit=crop
- Portrait: https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=600&h=400&fit=crop

VIDEO:
- Sample Video: https://www.w3schools.com/html/mov_bbb.mp4

FORMATTING RULES:
1. For images, ALWAYS use: ![alt text](url) - NOT <img> tags
2. For videos, place the URL on its own line
3. For text formatting, use Markdown:
   - Bold: **text** or __text__
   - Italic: *text* or _text_
   - Headers: # H1, ## H2, ### H3
   - Lists: - item or 1. item
   - Links: [text](url)
   - Code: `inline code` or ```code block```
4. NEVER output raw HTML tags like <p>, <img>, <div>, etc.

Example correct image response:
Here's a beautiful landscape:

![Sunset over mountains](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=400&fit=crop)

Example correct video response:
Here's a sample video:

https://www.w3schools.com/html/mov_bbb.mp4
```

## Expected Behavior

### Image Display
- Images appear inline in the chat
- Clicking images opens them in a lightbox
- Images are responsive and scale to fit the chat width
- Loading indicators show while images load

### Video Display
- Videos show with native browser controls
- Videos can be played/paused/fullscreen
- Videos are responsive to chat container size

### Error Handling
- Broken URLs show error placeholders
- System gracefully handles missing media

## Testing Steps

1. Ask the assistant to show you an image
2. Verify the image loads and displays correctly
3. Ask for a video demonstration
4. Confirm video plays with controls
5. Test with a broken URL to see error handling
6. Try multiple images in one response

## Instructions for the LLM

You are a multimedia assistant capable of displaying various types of media content in your responses. Your goal is to demonstrate and test the full range of media rendering capabilities.

### Critical Rules - ALWAYS Follow

1. **NEVER use HTML tags** - Do not output `<p>`, `<img>`, `<div>`, `<br>`, or any other HTML tags
2. **Use Markdown syntax only** - All formatting must be in Markdown
3. **Images**: Always use `![alt text](url)` syntax
4. **Videos**: Place video URL on its own line (must end in .mp4, .webm, .ogg)
5. **Text formatting**: Use Markdown (`**bold**`, `*italic*`, `# headers`, etc.)

### Media Integration Guidelines
- **Images**: Use standard markdown syntax `![Alt text](url)` for inline images
- **Videos**: Place video URLs on their own line for automatic embedding
- **Mixed Content**: Combine text, images, and videos in natural conversation flow
- **Error Handling**: Test both working and broken media URLs
- **Accessibility**: Always provide meaningful alt text for images
- **Responsive Design**: Media should adapt to different screen sizes

### How to Test
Try these prompts to see media rendering in action:
- "Show me a beautiful landscape image"
- "Display a technology photo"
- "Play a sample video"
- "Show me multiple images in a gallery"
- "What happens with a broken image URL?"

## Working Media URLs

Copy these URLs directly into your responses:

### Images (Use in Markdown)
```
![Beautiful Landscape](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=400&fit=crop)
![Modern Technology](https://images.unsplash.com/photo-1518770660439-4636190af475?w=600&h=400&fit=crop)
![City Architecture](https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=600&h=400&fit=crop)
![Professional Portrait](https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=600&h=400&fit=crop)
```

### Video (Place URL on its own line)
```
https://www.w3schools.com/html/mov_bbb.mp4
```

### Error Testing (Broken URLs)
```
![Broken Image](https://nonexistent-domain-12345.com/broken-image.jpg)
```

## Example Responses

### Single Image
**Prompt**: "Show me a landscape"

**Correct Response**:
```
Here's a beautiful landscape image:

![Scenic mountain landscape with lake](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=400&fit=crop)

This image shows a stunning natural landscape with mountains and water.
```

### Multiple Images
**Prompt**: "Show me some photos"

**Correct Response**:
```
Here are several different types of photography:

**Technology**

![Modern computer technology](https://images.unsplash.com/photo-1518770660439-4636190af475?w=500&h=300&fit=crop)

**Architecture**

![City building skyline](https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=500&h=300&fit=crop)

**Portrait**

![Professional headshot](https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=500&h=300&fit=crop)
```

### Video Demonstration
**Prompt**: "Play a video"

**Correct Response**:
```
Here's a sample video demonstration:

https://www.w3schools.com/html/mov_bbb.mp4

This video shows basic multimedia content that can be embedded in chat.
```

### Form with Images
**Prompt**: "Show me a design and get feedback"

**Correct Response**:
```json
{
  "type": "form",
  "title": "Design Review",
  "contentBefore": "Please review this design:\n\n![Design Mockup](https://images.unsplash.com/photo-1467232004584-a241de8bcf5d?w=600&h=400&fit=crop)",
  "fields": [
    {
      "id": "rating",
      "type": "radio",
      "label": "Rating (1-5)",
      "required": true,
      "options": [
        {"value": "5", "label": "⭐⭐⭐⭐⭐ Excellent"},
        {"value": "3", "label": "⭐⭐⭐ Good"},
        {"value": "1", "label": "⭐ Poor"}
      ]
    }
  ],
  "submitLabel": "Submit Review"
}
```

### Error Handling
**Prompt**: "What happens with broken images?"

**Correct Response**:
```
Testing error handling:

**Working Image:**

![Good Image](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=400&h=300&fit=crop)

**Broken Image (shows error):**

![Broken Image](https://nonexistent-domain-12345.com/broken-image.jpg)

**Another Working Image:**

![Another Good Image](https://images.unsplash.com/photo-1518770660439-4636190af475?w=400&h=300&fit=crop)

The system gracefully handles broken URLs with error placeholders.
```

### Different Image Sizes
**Prompt**: "Show me different sized images"

**Correct Response**:
```
Testing responsive behavior with various image sizes:

**Small Image:**

![Small](https://images.unsplash.com/photo-1544568100-847a948585b9?w=300&h=200&fit=crop)

**Wide Banner:**

![Wide Banner](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=200&fit=crop)

**Tall Portrait:**

![Tall Portrait](https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=300&h=400&fit=crop)

**Large Landscape:**

![Large Landscape](https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=600&h=400&fit=crop)

All images automatically scale to fit the chat container.
```

## Quick Start Guide

1. **Copy the System Context** above into your llmChat component
2. **Set Configuration** as shown (Style: llmChat, etc.)
3. **Test with prompts** like "Show me an image" or "Play a video"
4. **Verify** that images load and videos play correctly
5. **Customize** URLs for your specific use case

## Media Syntax Reference

| Content Type | Syntax | Example |
|-------------|--------|---------|
| Image | `![alt](url)` | `![Mountain](https://example.com/img.jpg)` |
| Video | URL on own line | `https://example.com/video.mp4` |
| Bold | `**text**` | `**important**` |
| Italic | `*text*` | `*emphasis*` |
| Link | `[text](url)` | `[Click here](https://example.com)` |
| Header | `# text` | `# Title` |

## What to Expect

✅ **Images** appear inline in chat messages  
✅ **Videos** play with browser controls  
✅ **Clicking images** opens them in fullscreen  
✅ **Responsive** - media scales to fit chat width  
✅ **Error handling** - broken URLs show placeholders  
✅ **Loading indicators** while media loads  

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Images showing as text | Make sure you're using `![alt](url)` not `<img>` tags |
| Videos not playing | Ensure URL is on its own line and ends in .mp4/.webm/.ogg |
| HTML tags visible | Update context to instruct LLM to use Markdown only |
| Broken images | System shows error placeholders automatically |
| Slow loading | Images are optimized and cached |

## Technical Notes

The chat component now supports both:
1. **Markdown syntax** (preferred) - `![alt](url)` for images
2. **Raw HTML** (fallback) - `<img>`, `<video>`, `<p>` tags are also rendered

However, instructing the LLM to use Markdown produces more consistent and predictable results.
