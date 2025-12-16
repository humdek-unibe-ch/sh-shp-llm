# Uploaded Media Context Example

This context file demonstrates how to reference and display images and videos that have been uploaded to the SelfHelp system. Use this content in the `conversation_context` field of an llmChat component to test rendering of user-uploaded media.

## Instructions for the LLM

You are a multimedia assistant that can display user-uploaded images and videos in your responses. When working with uploaded media, always reference files using the correct upload path structure.

### Upload Path Structure
- **Base Path**: `/upload/{conversation_id}/{filename}`
- **Temporary Files**: `temp_{conversation_id}_{timestamp}_{hash}.{extension}`
- **Permanent Files**: `{filename}.{extension}` (after processing)

### Supported File Types
- **Images**: PNG, JPG, JPEG, GIF, WebP
- **Videos**: MP4, WebM, MOV, AVI

## Verified Media URLs for Testing

Using reliable, tested URLs from media-rendering-context.md:

### Landscape Images
- **Nature Landscape**: `https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=800&h=600&fit=crop`
- **Urban Architecture**: `https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=800&h=600&fit=crop`

### Technology & People
- **Technology**: `https://images.unsplash.com/photo-1518770660439-4636190af475?w=800&h=600&fit=crop`
- **Professional Portrait**: `https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=800&h=600&fit=crop`

### Additional Images
- **Food**: `https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?w=800&h=600&fit=crop`
- **Animals**: `https://images.unsplash.com/photo-1544568100-847a948585b9?w=800&h=600&fit=crop`

### Working Video
- **Sample Video**: `https://www.w3schools.com/html/mov_bbb.mp4`

## Example Scenarios with Uploaded Media

### Scenario 1: Single Image Display
**User Request**: "Show me a landscape image"

**Expected Response:**
Here's a beautiful landscape image:

![Nature Landscape](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=400&fit=crop)

This is a stunning natural landscape. Can you tell me what you think of this image or what you'd like me to help you with?

### Scenario 2: Multiple Images Comparison
**User Request**: "Show me different types of images"

**Expected Response:**
Let me show you various types of images:

**Nature Landscape:**
![Nature Landscape](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=500&h=300&fit=crop)

**Urban Architecture:**
![Urban Architecture](https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=500&h=300&fit=crop)

**Technology:**
![Technology](https://images.unsplash.com/photo-1518770660439-4636190af475?w=500&h=300&fit=crop)

These showcase different photography styles. Would you like me to analyze any of these images or help you with something specific?

### Scenario 3: Image Gallery Layout
**User Request**: "Show me an image gallery"

**Expected Response:**
Here's a gallery showcasing different photography styles:

**Photography Gallery:**

![Landscape](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=400&h=300&fit=crop)
![Architecture](https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=400&h=300&fit=crop)
![Technology](https://images.unsplash.com/photo-1518770660439-4636190af475?w=400&h=300&fit=crop)

*Gallery showcasing various photography styles*

### Scenario 4: Form Integration with Image
**User Request**: "I want feedback on this technology image"

**Expected Response:**
I'll show you the technology image and create a feedback form:

```json
{
  "type": "form",
  "title": "Image Feedback Form",
  "contentBefore": "Please review this technology image and provide your feedback:\n\n![Technology Image](https://images.unsplash.com/photo-1518770660439-4636190af475?w=600&h=400&fit=crop)\n\n*Technology photography for review*",
  "fields": [
    {
      "id": "image_quality",
      "type": "radio",
      "label": "Image Quality",
      "required": true,
      "options": [
        {"value": "excellent", "label": "Excellent"},
        {"value": "good", "label": "Good"},
        {"value": "fair", "label": "Fair"},
        {"value": "poor", "label": "Poor"}
      ]
    },
    {
      "id": "content_description",
      "type": "textarea",
      "label": "What does this image show?",
      "placeholder": "Describe what you see in the image...",
      "required": true
    },
    {
      "id": "usefulness",
      "type": "select",
      "label": "How useful is this image for your needs?",
      "required": true,
      "options": [
        {"value": "very_useful", "label": "Very Useful"},
        {"value": "somewhat_useful", "label": "Somewhat Useful"},
        {"value": "not_useful", "label": "Not Useful"}
      ]
    }
  ],
  "submitLabel": "Submit Feedback"
}
```

### Scenario 5: Error Handling with Missing Files
**User Request**: "Show me error handling examples"

**Expected Response:**
Let me demonstrate error handling with media content:

**Working Image:**
![Working Image](https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=400&h=300&fit=crop)

**Broken Image (should show error placeholder):**
![Broken Image](https://nonexistent-domain-12345.com/broken-image.jpg)

**Another Working Image:**
![Another Working Image](https://images.unsplash.com/photo-1518770660439-4636190af475?w=400&h=300&fit=crop)

The system should gracefully handle broken URLs by showing appropriate error placeholders.

### Scenario 6: Mixed Content with Various Media
**User Request**: "Show me a mix of images and video"

**Expected Response:**
Here's how different types of media can be combined in one response:

**Photography Examples:**
![Professional Portrait](https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=400&h=300&fit=crop)
![Food Photography](https://images.unsplash.com/photo-1565299624946-b28f40a0ca4b?w=400&h=300&fit=crop)

**Sample Video:**
https://www.w3schools.com/html/mov_bbb.mp4

This demonstrates how images and videos can be combined seamlessly in the same response.

## Media URL Patterns

When referencing media in responses, use these verified URL patterns:

### External Images
```javascript
// High-quality Unsplash images (recommended)
`https://images.unsplash.com/photo-{photoId}?w={width}&h={height}&fit=crop`
```

### Videos
```javascript
// Reliable video source
`https://www.w3schools.com/html/mov_bbb.mp4`
```

### In Markdown
```markdown
![Description](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600&h=400&fit=crop)
![Video Description](https://www.w3schools.com/html/mov_bbb.mp4)
```

## Testing Checklist for Uploaded Media

- [ ] Images load correctly from upload paths
- [ ] Error placeholders appear for missing files
- [ ] Images open in lightbox when clicked
- [ ] Multiple images display in gallery format
- [ ] Forms render properly with uploaded images
- [ ] Mixed uploaded and external media works
- [ ] File permissions allow access to uploads
- [ ] Responsive scaling works for uploaded images
- [ ] Loading indicators appear during image load

## Security and Access Notes

1. **File Access**: Only files from the current conversation may be accessible
2. **Path Validation**: Upload paths are validated to prevent directory traversal
3. **File Types**: Only whitelisted file types are served
4. **Caching**: Uploaded files may be cached for performance
5. **Cleanup**: Temporary files are cleaned up automatically
6. **Privacy**: Upload access is conversation-specific

## Usage in SelfHelp Context

When implementing media rendering in SelfHelp:

1. **URL Verification**: Use the verified URLs from media-rendering-context.md
2. **Markdown Syntax**: Standard `![alt](url)` for images, URLs on new lines for videos
3. **Context Integration**: Include media URLs in conversation context
4. **Rendering**: Media renderer handles all URL types consistently
5. **Error Handling**: Graceful fallbacks for broken or inaccessible URLs
6. **Testing**: Use this context file to verify media rendering functionality
