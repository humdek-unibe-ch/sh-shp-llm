# File Naming Conventions

This document describes the file naming conventions used for all uploaded files in the LLM plugin, including images, documents, and audio recordings.

## Overview

All files are named using a consistent convention that includes contextual information about the upload:

- **User ID**: Identifies who uploaded the file
- **Section ID**: Identifies which page/section the file was uploaded from
- **Conversation ID**: Links the file to a specific conversation
- **Message ID**: Links the file to a specific message (for uploads)
- **Timestamp**: When the file was created (for temp/audio files)
- **Random Suffix**: Ensures uniqueness and prevents collisions

## Naming Patterns

### Finalized Upload Files (Images/Documents)

When a user uploads files with a message, they are initially saved as temporary files, then renamed once the message is saved.

**Pattern:**
```
{user_id}_{section_id}_{conversation_id}_{message_id}_{random}.{ext}
```

**Example:**
```
42_15_123_456_a1b2c3d4e5f6g7h8.png
```

**Components:**
| Component | Description | Example |
|-----------|-------------|---------|
| `user_id` | The user's ID in the system | `42` |
| `section_id` | The page section ID where uploaded | `15` |
| `conversation_id` | The conversation ID | `123` |
| `message_id` | The message ID this file is attached to | `456` |
| `random` | 16-character hex random string | `a1b2c3d4e5f6g7h8` |
| `ext` | File extension (lowercase) | `png` |

### Temporary Upload Files

Before a message is saved, uploaded files use a temporary naming pattern.

**Pattern:**
```
{user_id}_{section_id}_{conversation_id}_temp_{timestamp}_{random}.{ext}
```

**Example:**
```
42_15_123_temp_1765876608_a1b2c3d4e5f6.png
```

**Components:**
| Component | Description | Example |
|-----------|-------------|---------|
| `user_id` | The user's ID | `42` |
| `section_id` | The page section ID | `15` |
| `conversation_id` | The conversation ID | `123` |
| `temp` | Literal string indicating temporary file | `temp` |
| `timestamp` | Unix timestamp when uploaded | `1765876608` |
| `random` | 16-character hex random string | `a1b2c3d4e5f6` |
| `ext` | File extension | `png` |

### Audio Recording Files (Speech-to-Text)

Audio recordings from the speech-to-text feature are saved separately. They don't have a message ID because they are recorded before the message is composed.

**Pattern:**
```
{user_id}_{section_id}_{conversation_id}_audio_{timestamp}_{random}.{ext}
```

**Example:**
```
42_15_123_audio_1765876608_a1b2c3d4e5f6.webm
```

**Components:**
| Component | Description | Example |
|-----------|-------------|---------|
| `user_id` | The user's ID | `42` |
| `section_id` | The page section ID | `15` |
| `conversation_id` | The conversation ID (0 if none) | `123` |
| `audio` | Literal string indicating audio file | `audio` |
| `timestamp` | Unix timestamp when recorded | `1765876608` |
| `random` | 16-character hex random string | `a1b2c3d4e5f6` |
| `ext` | Audio file extension | `webm` |

## Directory Structure

Files are organized by user ID to keep each user's files together:

```
upload/
├── 42/                                          # User ID 42's files
│   ├── 42_15_123_456_a1b2c3d4e5f6g7h8.png      # Finalized upload
│   ├── 42_15_123_457_b2c3d4e5f6g7h8i9.jpg      # Another upload
│   └── 42_15_123_audio_1765876608_c3d4e5f6.webm # Audio recording
├── 43/                                          # User ID 43's files
│   └── ...
└── 44/                                          # User ID 44's files
    └── ...
```

## Service Implementation

The naming logic is centralized in `LlmFileNamingService.php`:

### Key Methods

| Method | Purpose |
|--------|---------|
| `generateTempUploadFilename()` | Create temp filename for initial upload |
| `generateUploadFilename()` | Create final filename with message ID |
| `generateAudioFilename()` | Create filename for audio recordings |
| `buildRelativePath()` | Build path relative to plugin root |
| `buildFullPath()` | Build full filesystem path |
| `parseFilename()` | Extract components from a filename |
| `validateFileOwnership()` | Check if file belongs to a user |

### Usage Examples

```php
// Generate temporary upload filename
$filename = LlmFileNamingService::generateTempUploadFilename(
    $userId,      // 42
    $sectionId,   // 15
    $conversationId, // 123
    'png'
);
// Result: "42_15_123_temp_1765876608_a1b2c3d4e5f6.png"

// Generate final upload filename
$filename = LlmFileNamingService::generateUploadFilename(
    $userId,      // 42
    $sectionId,   // 15
    $conversationId, // 123
    $messageId,   // 456
    'png'
);
// Result: "42_15_123_456_a1b2c3d4e5f6g7h8.png"

// Generate audio filename
$filename = LlmFileNamingService::generateAudioFilename(
    $userId,      // 42
    $sectionId,   // 15
    $conversationId, // 123
    'webm'
);
// Result: "42_15_123_audio_1765876608_a1b2c3d4e5f6.webm"

// Parse a filename to extract components
$parsed = LlmFileNamingService::parseFilename('42_15_123_456_a1b2c3d4e5f6g7h8.png');
// Result:
// [
//     'user_id' => 42,
//     'section_id' => 15,
//     'conversation_id' => 123,
//     'message_id' => 456,
//     'type' => 'upload',
//     'timestamp' => null,
//     'random' => 'a1b2c3d4e5f6g7h8',
//     'extension' => 'png'
// ]
```

## Security Considerations

1. **User Isolation**: Files are stored in user-specific directories, making it easier to manage permissions and cleanup.

2. **Random Suffix**: The 16-character hex random suffix (8 bytes of entropy) prevents filename guessing attacks.

3. **No Original Filenames**: Original filenames are stored in metadata but not used in the filesystem, preventing path traversal attacks.

4. **Ownership Validation**: The `validateFileOwnership()` method can verify a file belongs to a specific user before serving it.

## Migration Notes

This naming convention was introduced to replace the previous format:
- Old: `temp_{conversation_id}_{timestamp}_{random}.{ext}` or `conv_{conversation_id}_msg_{message_id}_{random}.{ext}`
- New: Includes user_id and section_id for better traceability

Existing files in the old format will continue to work but new uploads will use the new naming convention.
