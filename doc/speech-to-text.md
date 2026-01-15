# Speech-to-Text Input

## Overview

The Speech-to-Text feature enables voice input for the LLM Chat component, allowing users to speak into their microphone and have their speech converted to text in real-time. This provides an accessible and convenient alternative to typing, making the chat interface more user-friendly.

## Business Value

- **Accessibility**: Supports users with motor impairments or typing difficulties
- **Convenience**: Allows faster message composition through voice
- **Modern UX**: Matches user expectations from contemporary messaging apps
- **Reduced Barriers**: Lowers barriers to engagement with the AI assistant

## Technical Architecture

### Components

```
┌─────────────────────────────────────────────────────────────┐
│                     React Frontend                           │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              MessageInput.tsx                        │   │
│  │  - MediaRecorder API for audio capture               │   │
│  │  - WebM/Opus audio format                            │   │
│  │  - Microphone button with visual feedback            │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼ POST /audio (FormData)
┌─────────────────────────────────────────────────────────────┐
│                     PHP Backend                              │
│  ┌─────────────────────────────────────────────────────┐   │
│  │           LlmChatController.php                      │   │
│  │  - action: speech_transcribe                         │   │
│  │  - Audio file validation                             │   │
│  │  - Language detection from session                   │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                  │
│                           ▼                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │         LlmSpeechToTextService.php                   │   │
│  │  - GPUStack Whisper API integration                  │   │
│  │  - Audio transcription processing                    │   │
│  │  - Error handling and validation                     │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ▼ API Call
┌─────────────────────────────────────────────────────────────┐
│                   GPUStack API                               │
│  - Endpoint: /audio/transcriptions                          │
│  - Model: faster-whisper-large-v3                           │
│  - OpenAI-compatible format                                 │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow

1. **User Action**: User clicks the microphone button in the message input area
2. **Permission Request**: Browser requests microphone permission (first time only)
3. **Audio Recording**: MediaRecorder captures audio in WebM/Opus format
4. **Stop Recording**: User clicks stop or the button again
5. **Upload**: Audio blob sent to server via POST with FormData
6. **Transcription**: Server sends audio to GPUStack Whisper API
7. **Response**: Transcribed text returned to frontend
8. **Display**: Text appended to message input for review/editing

### Privacy

- **No Storage**: Audio data is processed in real-time and not stored permanently
- **Temporary Files**: Server receives audio as temporary file, deleted after processing
- **User Control**: User can edit or clear transcribed text before sending

## Configuration

### Database Fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `enable_speech_to_text` | checkbox | `0` | Enable/disable speech input |
| `speech_to_text_model` | select-audio-model | `faster-whisper-large-v3` | Whisper model for transcription |

### CMS Configuration

1. Navigate to your page with the llmChat component
2. Edit the component settings
3. Check **Enable Speech to Text**
4. Select an audio model from the dropdown
5. Save the page

**Important**: The microphone button only appears when BOTH:
- `enable_speech_to_text` is checked
- An audio model is selected

### React Configuration

The following properties are passed to the React component:

```typescript
interface LlmChatConfig {
  // ... other config ...
  enableSpeechToText: boolean;    // Whether speech input is enabled
  speechToTextModel: string;      // Selected Whisper model
}
```

## API Reference

### POST action=speech_transcribe

Transcribes audio to text using the configured Whisper model.

**Request:**
- Method: POST
- Content-Type: multipart/form-data
- Body:
  - `action`: `speech_transcribe`
  - `section_id`: Component section ID
  - `audio`: Audio file (WebM, WAV, MP3, OGG, or FLAC)

**Response (Success):**
```json
{
  "success": true,
  "text": "Transcribed text content here",
  "language": "en"
}
```

**Response (Error):**
```json
{
  "success": false,
  "error": "Error message describing what went wrong"
}
```

### Supported Audio Formats

| Format | MIME Type | Notes |
|--------|-----------|-------|
| WebM | `audio/webm`, `audio/webm;codecs=opus` | Primary format (best browser support) |
| WAV | `audio/wav` | Uncompressed |
| MP3 | `audio/mp3`, `audio/mpeg` | Common format |
| OGG | `audio/ogg` | Open format |
| FLAC | `audio/flac` | Lossless |

### Audio Constraints

- Maximum file size: 25MB
- Recommended: WebM with Opus codec
- Sample rate: 16kHz (configurable)

## Language Detection

The speech-to-text feature automatically detects the user's language from their SelfHelp session:

1. Reads `$_SESSION['user_language_locale']` (e.g., "de-CH", "en-GB")
2. Extracts the 2-letter language code (e.g., "de", "en")
3. Passes to Whisper API for improved transcription accuracy

**Supported Languages:**
- English (en)
- German (de)
- French (fr)
- Spanish (es)
- Italian (it)
- Portuguese (pt)
- Dutch (nl)
- Polish (pl)
- Russian (ru)
- Japanese (ja)
- Chinese (zh)
- Korean (ko)

If the language is not in the supported list, "auto" detection is used.

## User Experience

### Visual Feedback

| State | Button Appearance | Description |
|-------|-------------------|-------------|
| Idle | Gray outline, microphone icon | Ready to record |
| Recording | Red background, stop icon, pulsing | Currently recording |
| Processing | Spinner icon | Transcribing audio |

### User Flow

1. Click microphone button → Button turns red, starts recording
2. Speak your message
3. Click stop button → Recording stops, shows spinner
4. Wait for transcription → Text appears in input field
5. Review/edit text if needed
6. Click send to submit message

### Error Handling

Common errors and their messages:

| Error | Message | Solution |
|-------|---------|----------|
| Permission Denied | "Microphone access denied..." | Allow microphone in browser settings |
| No Speech | "No speech detected..." | Speak clearly, try again |
| Network Error | "Speech processing failed..." | Check internet connection |
| Model Error | "Speech transcription failed..." | Contact administrator |

## Browser Compatibility

### Requirements

- Modern browser with MediaRecorder API support
- Microphone hardware
- HTTPS connection (required for microphone access)

### Tested Browsers

| Browser | Version | Status |
|---------|---------|--------|
| Chrome | 90+ | ✅ Full support |
| Firefox | 85+ | ✅ Full support |
| Safari | 14.1+ | ✅ Full support |
| Edge | 90+ | ✅ Full support |

### Feature Detection

The microphone button only appears if:
1. Speech-to-text is enabled in configuration
2. Audio model is selected
3. Browser supports `navigator.mediaDevices.getUserMedia`
4. MediaRecorder API is available

## Troubleshooting

### Microphone Button Not Appearing

1. Verify `enable_speech_to_text` is checked in CMS
2. Verify an audio model is selected
3. Check browser compatibility
4. Ensure HTTPS is being used

### No Audio Being Captured

1. Check browser microphone permissions
2. Verify microphone hardware is working
3. Try a different browser
4. Check system audio settings

### Transcription Errors

1. Verify GPUStack API is accessible
2. Check API key configuration
3. Verify audio model is available
4. Check server error logs

### Poor Transcription Quality

1. Speak clearly and at moderate pace
2. Minimize background noise
3. Use a better microphone
4. Try a different Whisper model

## Integration with Existing Features

### Form Mode

Speech-to-text is disabled when form mode is active, as text input is not available.

### Floating Chat

Speech-to-text works in floating chat mode with the same functionality.

### File Attachments

Speech-to-text and file attachments can be used together in the same message.

## Security Considerations

### Microphone Access

- Browser prompts user for permission before first use
- Permission can be revoked at any time in browser settings
- HTTPS required for microphone access

### Data Handling

- Audio files are temporary and deleted after processing
- No audio data is stored in the database
- Transcribed text follows same security as typed messages

### API Security

- Requires authenticated user session
- Section ID validation ensures proper access control
- Rate limiting applies to speech requests

## Constants

Defined in `server/service/globals.php`:

```php
// Audio models
define('LLM_AUDIO_MODELS', [
    'faster-whisper-large-v3',
    'whisper-large-v3',
    'whisper-medium',
    'whisper-small'
]);

// Default model
define('LLM_DEFAULT_SPEECH_MODEL', 'faster-whisper-large-v3');

// API endpoint
define('LLM_API_AUDIO_TRANSCRIPTIONS', '/audio/transcriptions');

// Max audio size (25MB)
define('LLM_MAX_AUDIO_SIZE', 25 * 1024 * 1024);

// Supported audio types
define('LLM_ALLOWED_AUDIO_TYPES', [
    'audio/webm',
    'audio/webm;codecs=opus',
    'audio/wav',
    'audio/mp3',
    'audio/mpeg',
    'audio/mp4',
    'audio/ogg',
    'audio/flac'
]);
```

## Future Enhancements

Potential future improvements:

1. **Real-time streaming**: Live transcription as user speaks
2. **Voice commands**: Start/stop recording with voice
3. **Language selection**: Manual language override
4. **Noise reduction**: Client-side audio preprocessing
5. **Transcription history**: Undo/redo for transcribed text
