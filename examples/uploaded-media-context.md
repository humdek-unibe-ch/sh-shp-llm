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

## Available Uploaded Files for Testing

Based on current upload directory contents:

### Conversation ID: 0000000003
- **File**: `temp_0000000003_1765205667_86f4dd9ac916084d.png`
- **Path**: `/upload/0000000003/temp_0000000003_1765205667_86f4dd9ac916084d.png`
- **Type**: PNG Image

### Conversation ID: 3
- **File**: `temp_3_1765370040_e2fff99d24cd6e00.png`
- **Path**: `/upload/3/temp_3_1765370040_e2fff99d24cd6e00.png`
- **Type**: PNG Image

### Conversation ID: 4
- **File**: `temp_4_1765876608_d594306d971a2395.png`
- **Path**: `/upload/4/temp_4_1765876608_d594306d971a2395.png`
- **Type**: PNG Image

## Example Scenarios with Uploaded Media

### Scenario 1: Single Image Display
**User Request**: "Show me the uploaded image from conversation 3"

**Expected Response:**
Here's the image that was uploaded to conversation 3:

![Uploaded Image from Conversation 3](/upload/3/temp_3_1765370040_e2fff99d24cd6e00.png)

This appears to be a user-uploaded image. Can you tell me what this image shows or what you'd like me to help you with regarding this image?

### Scenario 2: Multiple Images Comparison
**User Request**: "Compare the images from different conversations"

**Expected Response:**
Let me show you the images from different conversation uploads:

**Conversation 0000000003:**
![Conversation 0000000003 Image](/upload/0000000003/temp_0000000003_1765205667_86f4dd9ac916084d.png)

**Conversation 3:**
![Conversation 3 Image](/upload/3/temp_3_1765370040_e2fff99d24cd6e00.png)

**Conversation 4:**
![Conversation 4 Image](/upload/4/temp_4_1765876608_d594306d971a2395.png)

These are all the currently uploaded images in the system. Would you like me to analyze any of these images or help you with something specific?

### Scenario 3: Image Gallery Layout
**User Request**: "Show me all uploaded images in a gallery"

**Expected Response:**
Here's a gallery of all uploaded images in the system:

**Uploaded Images Gallery:**

![Image 1](/upload/0000000003/temp_0000000003_1765205667_86f4dd9ac916084d.png)
![Image 2](/upload/3/temp_3_1765370040_e2fff99d24cd6e00.png)
![Image 3](/upload/4/temp_4_1765876608_d594306d971a2395.png)

*Gallery of user-uploaded images from various conversations*

### Scenario 4: Form Integration with Uploaded Image
**User Request**: "I want feedback on this uploaded image"

**Expected Response:**
I'll show you the uploaded image and create a feedback form:

```json
{
  "type": "form",
  "title": "Image Feedback Form",
  "contentBefore": "Please review this uploaded image and provide your feedback:\n\n![Uploaded Image](/upload/4/temp_4_1765876608_d594306d971a2395.png)\n\n*User-uploaded image for review*",
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
**User Request**: "Show me an image that doesn't exist"

**Expected Response:**
Let me demonstrate error handling with uploaded media:

**Existing Image:**
![Valid Upload](/upload/3/temp_3_1765370040_e2fff99d24cd6e00.png)

**Non-existent Image (should show error):**
![Missing Image](/upload/999/nonexistent_image.png)

The system should gracefully handle missing uploaded files by showing an appropriate error placeholder or message.

### Scenario 6: Mixed Content with Uploads
**User Request**: "Combine uploaded images with external content"

**Expected Response:**
Here's how uploaded images can be combined with external media:

**User-Uploaded Images:**
![Upload 1](/upload/0000000003/temp_0000000003_1765205667_86f4dd9ac916084d.png)
![Upload 2](/upload/4/temp_4_1765876608_d594306d971a2395.png)

**External Reference Image:**
![External Image](https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=400&h=300&fit=crop)

**Sample Video for Comparison:**
https://www.w3schools.com/html/mov_bbb.mp4

This demonstrates how uploaded files integrate seamlessly with external media content.

## Dynamic Upload References

When working with newly uploaded files, use these patterns:

### During Upload Process
```javascript
// Reference pattern for files being uploaded
`/upload/${conversationId}/temp_${conversationId}_${timestamp}_${hash}.${extension}`
```

### After Processing
```javascript
// Reference pattern for processed files
`/upload/${conversationId}/${processed_filename}.${extension}`
```

### In Markdown
```markdown
![Uploaded Image](/upload/123/temp_123_1765876608_abc123.png)
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

When implementing this in SelfHelp:

1. **Upload Component**: Use the file upload feature to add images/videos
2. **Path Generation**: System generates correct upload paths automatically
3. **Context Integration**: Include upload paths in conversation context
4. **Rendering**: Media renderer handles upload paths like any other media URL
5. **Error Handling**: Graceful fallbacks for missing or inaccessible files
