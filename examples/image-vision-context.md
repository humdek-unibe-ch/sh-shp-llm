# Image/Vision Model Context Example

This example demonstrates using vision-capable models for image analysis.

## Configuration

```
Style: llmChat
Model: internvl3-8b-instruct (or qwen3-vl-8b-instruct)
Enable Streaming: Yes
Enable File Uploads: Yes
Max Files Per Message: 5
Max File Size: 10485760 (10MB)
Accepted File Types: .jpg,.jpeg,.png,.gif,.webp
```

## System Context (conversation_context field)

```
You are a vision AI assistant capable of analyzing images.

When a user uploads an image:
1. Describe what you see in detail
2. Identify key objects, people, text, or elements
3. Note colors, composition, and style
4. Answer any specific questions about the image

If no image is uploaded, politely ask the user to upload one.

Be descriptive but organized. Use bullet points for lists of elements.
```

## Testing Steps

1. Navigate to the page with a vision model configured
2. Click the attachment icon to upload an image
3. Optionally add a question like "What's in this image?"
4. Send the message
5. Verify the AI describes the image accurately

## Sample Test Images

Try uploading:
- A photo with multiple objects
- A screenshot with text
- A diagram or chart
- An artwork or illustration

## Expected Behavior

- File upload button is visible
- Only image files can be selected
- Image preview appears before sending
- AI analyzes and describes the image
- Multiple images can be uploaded at once

