# API Reference

## Overview

The LLM Chat plugin exposes several API endpoints through the `LlmChatController`. All requests require authentication and use the current page's URL.

## Base URL

All endpoints use the current page URL with query parameters:

```
{page_url}?action={action_name}
```

## Authentication

All requests require:
- Valid session authentication
- User must be logged in
- For admin endpoints: admin ACL permissions

## Endpoints

### Configuration

#### GET `?action=get_config`

Fetch complete chat configuration for the current component.

**Response:**
```json
{
  "config": {
    "userId": 123,
    "currentConversationId": "0000000001",
    "configuredModel": "qwen3-vl-8b-instruct",
    "maxFilesPerMessage": 5,
    "maxFileSize": 10485760,
    "enableConversationsList": true,
    "enableFileUploads": true,
    "enableFullPageReload": false,
    "acceptedFileTypes": ".pdf,.txt,.md,...",
    "isVisionModel": true,
    "hasConversationContext": true,
    "messagePlaceholder": "Type your message...",
    "noConversationsMessage": "No conversations yet",
    // ... all UI labels
    "fileConfig": {
      "maxFileSize": 10485760,
      "maxFilesPerMessage": 5,
      "allowedImageExtensions": ["jpg", "jpeg", "png", "gif", "webp"],
      "allowedDocumentExtensions": ["pdf", "txt", "md", "csv", "json", "xml"],
      "allowedCodeExtensions": ["py", "js", "php", "html", "css", "sql", "sh", "yaml", "yml"],
      "allowedExtensions": [...],
      "visionModels": ["internvl3-8b-instruct", "qwen3-vl-8b-instruct"]
    }
  }
}
```

**Error Response:**
```json
{
  "error": "User not authenticated"
}
```

### Conversations

#### GET `?action=get_conversations`

Fetch all conversations for the current user.

**Response:**
```json
{
  "conversations": [
    {
      "id": "0000000001",
      "title": "Conversation Title",
      "model": "qwen3-vl-8b-instruct",
      "created_at": "2024-12-10 14:30:00",
      "updated_at": "2024-12-10 15:45:00"
    }
  ]
}
```

#### GET `?action=get_conversation&conversation_id={id}`

Fetch a specific conversation with its messages.

**Parameters:**
- `conversation_id` (required): Conversation ID

**Response:**
```json
{
  "conversation": {
    "id": "0000000001",
    "title": "Conversation Title",
    "model": "qwen3-vl-8b-instruct",
    "temperature": 1.0,
    "max_tokens": 2048,
    "created_at": "2024-12-10 14:30:00",
    "updated_at": "2024-12-10 15:45:00"
  },
  "messages": [
    {
      "id": "0000000001",
      "role": "user",
      "content": "Hello!",
      "formatted_content": "<p>Hello!</p>",
      "timestamp": "2024-12-10 14:30:00",
      "attachments": null,
      "model": null,
      "tokens_used": null
    },
    {
      "id": "0000000002",
      "role": "assistant",
      "content": "Hi there! How can I help you?",
      "formatted_content": "<p>Hi there! How can I help you?</p>",
      "timestamp": "2024-12-10 14:30:05",
      "model": "qwen3-vl-8b-instruct",
      "tokens_used": 15
    }
  ]
}
```

#### POST `action=new_conversation`

Create a new conversation.

**Body (FormData):**
- `action`: `new_conversation`
- `title`: Conversation title (optional)
- `model`: Model to use (optional, uses configured default)

**Response:**
```json
{
  "conversation_id": "0000000002"
}
```

#### POST `action=delete_conversation`

Delete a conversation (soft delete).

**Body (FormData):**
- `action`: `delete_conversation`
- `conversation_id`: ID of conversation to delete

**Response:**
```json
{
  "success": true
}
```

### Messages

#### POST `action=send_message`

Send a message and get AI response.

**Body (FormData):**
- `action`: `send_message`
- `message`: Message content (required)
- `conversation_id`: Target conversation (optional, creates new if not provided)
- `model`: Model to use (optional)
- `temperature`: Temperature setting (optional)
- `max_tokens`: Max tokens (optional)
- `uploaded_files[]`: File attachments (optional, multiple)

```json
{
  "conversation_id": "0000000001",
  "message": "AI response content",
  "is_new_conversation": false
}
```



**Body (FormData):**
- `action`: `send_message`
- `message`: Message content (required)
- `conversation_id`: Target conversation (optional)
- `model`: Model to use (optional)
- `uploaded_files[]`: File attachments (optional)

**Response:**
```json
{
  "status": "prepared",
  "conversation_id": "0000000001",
  "is_new_conversation": false,
  "user_message": {
    "id": "0000000003",
    "role": "user",
    "content": "Message content",
    "attachments": null,
    "model": "qwen3-vl-8b-instruct",
    "created_at": "2024-12-10 15:00:00"
  }
}
```



Returns the complete AI response.

**Response:**
```json
{
  "status": "success",
  "conversation_id": "0000000001",
  "message": {
    "id": "0000000004",
    "role": "assistant",
    "content": "Complete AI response content",
    "model": "qwen3-vl-8b-instruct",
    "tokens_used": 15,
    "created_at": "2024-12-10 15:00:01"
  }
}

data: {"type": "close"}
```

**Event Types:**
- `connected`: Connection established
- `chunk`: Content chunk (append to response)
- `error`: Error occurred
- `close`: Connection should be closed

### Admin Endpoints

Require admin ACL permissions.

#### GET `?action=admin_filters`

Get filter options for admin console.

**Response:**
```json
{
  "filters": {
    "users": [
      {"id": 1, "name": "John Doe", "email": "john@example.com"}
    ],
    "sections": [
      {"id": 1, "name": "Chat Section"}
    ]
  }
}
```

#### GET `?action=admin_conversations`

Fetch conversations for admin view.

**Parameters:**
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 50, max: 100)
- `user_id`: Filter by user ID
- `section_id`: Filter by section ID
- `q`: Search query
- `date_from`: Filter by date (YYYY-MM-DD)
- `date_to`: Filter by date (YYYY-MM-DD)

**Response:**
```json
{
  "items": [
    {
      "id": "0000000001",
      "title": "Conversation",
      "model": "qwen3-vl-8b-instruct",
      "created_at": "2024-12-10 14:30:00",
      "updated_at": "2024-12-10 15:45:00",
      "id_users": 123,
      "user_name": "John Doe",
      "user_email": "john@example.com",
      "message_count": 5
    }
  ],
  "page": 1,
  "per_page": 50,
  "total": 100
}
```

#### GET `?action=admin_messages&conversation_id={id}`

Fetch messages for a conversation (admin view).

**Parameters:**
- `conversation_id`: Conversation ID

**Response:**
```json
{
  "conversation": {
    "id": "0000000001",
    "title": "Conversation",
    "user_name": "John Doe",
    // ... other fields
  },
  "messages": [
    {
      "id": "0000000001",
      "role": "user",
      "content": "Hello",
      "timestamp": "2024-12-10 14:30:00"
    }
  ]
}
```

## Error Handling

All endpoints return errors in consistent format:

```json
{
  "error": "Error message"
}
```

HTTP Status Codes:
- `200`: Success
- `400`: Bad request (missing parameters)
- `401`: Unauthorized (not authenticated)
- `403`: Forbidden (insufficient permissions)
- `404`: Not found
- `500`: Server error

## Rate Limiting

Rate limits are enforced per user:
- 10 requests per minute
- 3 concurrent conversations

Exceeding limits returns:
```json
{
  "error": "Rate limit exceeded: 10 requests per minute"
}
```

## File Uploads

### Supported Types

**Images:**
- jpg, jpeg, png, gif, webp

**Documents:**
- pdf, txt, md, csv, json, xml

**Code:**
- py, js, php, html, css, sql, sh, yaml, yml

### Limits

- Max file size: 10MB
- Max files per message: 5

### Upload Format

Files should be sent as multipart/form-data with key `uploaded_files[]`:

```javascript
const formData = new FormData();
formData.append('uploaded_files[]', file1);
formData.append('uploaded_files[]', file2);
```




