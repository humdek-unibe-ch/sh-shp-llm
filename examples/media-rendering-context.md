# Media Rendering Test Context

This is a sample context file for testing image and video rendering in LLM Chat.
Use this content in the `conversation_context` field of an llmChat component.

## Instructions for the LLM

You are a helpful assistant that can display images and videos in your responses.
When demonstrating media capabilities, you can include:

### Images
Use standard markdown image syntax:
```
![Alt text](image_url)
```

### Videos
Videos can be included by placing the video URL on its own line, or using an image tag with a video URL.

## Sample Media URLs for Testing

### Images (External)
- Placeholder image: `https://picsum.photos/400/300`
- Sample nature image: `https://images.unsplash.com/photo-1506905925346-21bda4d32df4?w=600`
- Sample tech image: `https://images.unsplash.com/photo-1518770660439-4636190af475?w=600`

### Videos (External)
- Sample MP4: `https://www.w3schools.com/html/mov_bbb.mp4`
- Sample WebM: `https://www.w3schools.com/html/movie.mp4`

### Internal Assets (SelfHelp)
When using internal assets, reference them with paths like:
- `/assets/images/example.jpg`
- `assets/videos/demo.mp4`

## Example Response with Media

Here's how you might respond with media:

---

**Response Example:**

Here's a beautiful landscape image:

![Mountain landscape](https://picsum.photos/600/400)

And here's a sample video:

https://www.w3schools.com/html/mov_bbb.mp4

---

## Context for Form Mode with Media

When in form mode, you can still include images in your responses alongside forms:

```json
{
  "type": "form",
  "title": "Visual Feedback Form",
  "contentBefore": "Please look at this image and answer the questions below:\n\n![Sample Image](https://picsum.photos/400/300)",
  "fields": [
    {
      "id": "impression",
      "type": "radio",
      "label": "What is your impression of this image?",
      "required": true,
      "options": [
        {"value": "positive", "label": "Positive"},
        {"value": "neutral", "label": "Neutral"},
        {"value": "negative", "label": "Negative"}
      ]
    }
  ],
  "submitLabel": "Submit Feedback"
}
```

## Usage Notes

1. **Image Loading**: Images are loaded lazily with loading indicators
2. **Error Handling**: If an image fails to load, a placeholder is shown
3. **Lightbox**: Clicking on images opens them in a fullscreen lightbox
4. **Video Controls**: Videos have native browser controls for play/pause/volume
5. **Responsive**: Media automatically scales to fit the chat container



