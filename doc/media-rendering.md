# Media Rendering in LLM Chat

## Overview

LLM Chat supports rendering images and videos within chat responses. This enables rich, interactive conversations with visual content from:
- SelfHelp assets (uploaded files)
- External URLs (public images/videos)

## Configuration

### llmChat Style Fields

| Field | Type | Description |
|-------|------|-------------|
| `enable_media_rendering` | checkbox | Enable rendering of images and videos (default: enabled) |
| `allowed_media_domains` | textarea | List of allowed external domains (one per line, empty = allow all) |

## Supported Media Types

### Images

| Format | Extension | MIME Type |
|--------|-----------|-----------|
| JPEG | .jpg, .jpeg | image/jpeg |
| PNG | .png | image/png |
| GIF | .gif | image/gif |
| WebP | .webp | image/webp |

### Videos

| Format | Extension | MIME Type |
|--------|-----------|-----------|
| MP4 | .mp4 | video/mp4 |
| WebM | .webm | video/webm |
| OGG | .ogg | video/ogg |
| MOV | .mov | video/quicktime |
| M4V | .m4v | video/mp4 |

## Markdown Syntax

The LLM can include media using standard markdown syntax:

### Images

```markdown
![Alt text](path/to/image.jpg)
![Alt text](https://example.com/image.png "Optional title")
```

### Videos

Videos can be included in two ways:

1. **URL on its own line** - The URL is automatically detected and rendered as a video:
```markdown
Here's a demonstration video:

https://example.com/video.mp4

Continue with the explanation...
```

2. **Image syntax with video URL** - The system detects video extensions and renders appropriately:
```markdown
![Demo video](https://example.com/video.mp4)
```

## URL Patterns

### SelfHelp Assets (Internal)

Assets uploaded to SelfHelp are accessed via:

```
/assets/{asset_path}
assets/{asset_path}
```

Example:
```markdown
![Relaxation exercise](/assets/images/relaxation-exercise.jpg)
```

### External URLs

Full URLs are supported:

```markdown
![Nature scene](https://images.unsplash.com/photo-example)
![Tutorial video](https://www.w3schools.com/html/mov_bbb.mp4)
```

## Features

### Image Features

1. **Loading States**: Shows a spinner while images load
2. **Error Handling**: Displays a placeholder if image fails to load
3. **Lightbox**: Click on images to view them fullscreen
4. **Responsive**: Images automatically scale to fit the container
5. **Hover Effects**: Subtle scale effect on hover

### Video Features

1. **Native Controls**: Play, pause, volume, fullscreen controls
2. **Preload Metadata**: Videos load metadata for quick playback
3. **Error Handling**: Shows error message if video fails to load
4. **Responsive**: Videos scale to fit container (max 400px height)

## Implementation

### MarkdownRenderer Component

The `MarkdownRenderer` React component (`react/src/components/shared/MarkdownRenderer.tsx`) handles media rendering:

```typescript
// Image component with lightbox support
const ImageComponent: React.FC<ImageComponentProps> = ({ src, alt, title }) => {
  const [isLoading, setIsLoading] = useState(true);
  const [hasError, setHasError] = useState(false);
  const [isExpanded, setIsExpanded] = useState(false);
  
  const resolvedSrc = resolveMediaPath(src || '');
  
  // Check if this is actually a video URL
  if (isVideoUrl(resolvedSrc)) {
    return <VideoComponent src={resolvedSrc} />;
  }
  
  // ... render image with loading/error states and lightbox
};

// Video component with native controls
const VideoComponent: React.FC<VideoComponentProps> = ({ src, title }) => {
  const [hasError, setHasError] = useState(false);
  const resolvedSrc = resolveMediaPath(src || '');
  
  // ... render video with error handling
};
```

### Path Resolution

```typescript
function resolveMediaPath(src: string): string {
  // External URLs pass through
  if (src.startsWith('http://') || src.startsWith('https://') || src.startsWith('//')) {
    return src;
  }
  
  // Internal paths - ensure starts with /
  if (src.startsWith('/')) {
    return src;
  }
  
  // Relative paths - prepend /
  return '/' + src;
}
```

### Video Detection

```typescript
function isVideoUrl(url: string): boolean {
  const videoExtensions = ['.mp4', '.webm', '.ogg', '.mov', '.m4v'];
  const lowerUrl = url.toLowerCase();
  return videoExtensions.some(ext => lowerUrl.includes(ext));
}
```

## Styling

Media elements are styled in `SharedMessages.css`:

```css
/* Media wrapper */
.media-wrapper {
  display: block;
  margin: 12px 0;
  position: relative;
}

/* Image styling */
.md-image {
  max-width: 100%;
  height: auto;
  border-radius: var(--llm-radius-medium);
  cursor: zoom-in;
}

.md-image:hover {
  transform: scale(1.02);
  box-shadow: var(--llm-shadow-medium);
}

/* Video styling */
.md-video {
  max-width: 100%;
  width: 100%;
  max-height: 400px;
  border-radius: var(--llm-radius-medium);
  background: #000;
}

/* Lightbox */
.media-lightbox {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.9);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}
```

## Context Configuration

To enable media in LLM responses, include instructions in the conversation context:

### Basic Media Context

```markdown
# Media-Enabled Assistant

You can include images and videos in your responses using markdown.

## Available Assets
- /assets/exercises/breathing.mp4 - Breathing exercise video
- /assets/images/calm-nature.jpg - Calming nature image
- /assets/images/anxiety-scale.png - Anxiety level diagram

## Usage
When appropriate, include relevant media:
- Use images to illustrate concepts
- Use videos for guided exercises
- Always include descriptive alt text
```

### Test Context with External Media

See `examples/media-rendering-context.md` for a complete test context with external media URLs.

## Security Considerations

### Content Security Policy

For external media, ensure CSP headers allow the domains:

```
Content-Security-Policy: img-src 'self' https://trusted-domain.com;
```

### Allowed Domains

Use the `allowed_media_domains` field to restrict external media to specific domains:

```
images.unsplash.com
www.w3schools.com
picsum.photos
```

If empty, all external domains are allowed.

### URL Validation

The renderer validates URLs to prevent:
- JavaScript injection (`javascript:`)
- Local file access (`file://`)
- Malformed URLs

### Asset Access Control

SelfHelp assets respect ACL permissions. Users can only view assets they have access to.

## Troubleshooting

### Image Not Loading

1. Check the path is correct
2. Verify asset exists in SelfHelp
3. Check user has permission to access asset
4. Check browser console for CSP errors
5. Verify the domain is in `allowed_media_domains` (if configured)

### Video Not Playing

1. Verify video format is supported
2. Verify video file is accessible
3. Check for codec compatibility
4. Check browser console for errors

### External Media Blocked

1. Add domain to `allowed_media_domains`
2. Add domain to CSP allowlist
3. Ensure HTTPS is used
4. Check for CORS restrictions

### Lightbox Not Working

1. Ensure JavaScript is enabled
2. Check for CSS conflicts
3. Verify z-index is high enough

## Example Conversation

**User**: I'm feeling anxious. Can you show me a breathing exercise?

**Assistant**: I understand. Let me show you the 4-7-8 breathing technique:

![Breathing diagram](/assets/images/breath-diagram.png)

Here's a guided video to follow along:

https://www.w3schools.com/html/mov_bbb.mp4

**Steps:**
1. Breathe in through your nose for 4 seconds
2. Hold your breath for 7 seconds
3. Exhale through your mouth for 8 seconds
4. Repeat 3-4 times

Would you like to try this together?

## Media in Form Mode

When using form mode with media, you can include images in the form's `contentBefore` or `contentAfter` fields:

```json
{
  "type": "form",
  "title": "Visual Feedback",
  "contentBefore": "Please look at this image:\n\n![Sample](https://picsum.photos/400/300)",
  "fields": [
    {
      "id": "impression",
      "type": "radio",
      "label": "What is your impression?",
      "options": [
        {"value": "positive", "label": "Positive"},
        {"value": "neutral", "label": "Neutral"},
        {"value": "negative", "label": "Negative"}
      ]
    }
  ]
}
```
