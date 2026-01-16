# Message Validation Tracking

This document describes the message validation tracking feature that logs all LLM response attempts, including failed schema validation attempts, for debugging and audit purposes.

## Overview

When the LLM plugin sends requests to the AI model, it expects responses to follow a specific JSON schema. If the response doesn't match the schema, the system automatically retries up to 3 times. This feature tracks all attempts (including failed ones) in the database for debugging.

## Key Concepts

### Validation Status (`is_validated`)

Each message in the database has an `is_validated` field:
- **1 (true)**: The message passed JSON schema validation
- **0 (false)**: The message failed validation (retry attempt)

### Request Payload (`request_payload`)

For assistant messages, the **complete API request payload** sent to the LLM is stored. This includes:
- `model`: The model identifier (e.g., "gpt-oss-120b")
- `temperature`: Temperature setting
- `max_tokens`: Maximum tokens setting
- `stream`: Whether streaming is enabled
- `response_format`: Response format (e.g., "json")
- `messages`: Array of all messages sent, including:
  - System instructions
  - Conversation history
  - User's current message
  - Retry instructions (if this was a retry attempt)

Example payload structure:
```json
{
  "model": "gpt-oss-120b",
  "temperature": 0,
  "max_tokens": 2048,
  "stream": false,
  "response_format": "json",
  "messages": [
    {
      "role": "system",
      "content": "You are a JSON-only response engine..."
    },
    {
      "role": "user",
      "content": "User's message here"
    }
  ]
}
```

This payload can be directly copied and used in Postman or other API testing tools.

## Database Schema

```sql
-- New fields in llmMessages table
`is_validated` TINYINT(1) DEFAULT 1 NOT NULL COMMENT 'Whether the message passed JSON schema validation (1=valid, 0=invalid/retry attempt)',
`request_payload` longtext DEFAULT NULL COMMENT 'JSON payload sent to LLM API for debugging (stored on assistant messages)',
```

## User vs Admin View

### User View (Chat Interface)

Users **only see validated messages** (`is_validated = 1`). This ensures:
- Clean conversation history
- No confusing failed attempts
- Consistent user experience

### Admin View (Admin Console)

Admins see **all messages** including:
- Validated messages (green badge)
- Failed validation attempts (yellow badge, highlighted)
- Request payloads for debugging

## Admin Console Features

### Validation Status Badge

Each assistant message displays a badge:
- **Green "Valid"**: Message passed schema validation
- **Yellow "Invalid"**: Message failed validation (retry attempt)

### Failed Attempt Highlighting

Messages that failed validation are visually distinct:
- Yellow left border
- Reduced opacity (70%)
- Warning banner explaining it's a retry attempt

### Payload Popup

Click the "Payload" button to view:
- The exact JSON payload sent to the LLM API
- Formatted view of all messages in the request
- One-click copy for testing in Postman

## How It Works

### Normal Flow (Single Attempt)

1. User sends message
2. System builds API request with context
3. LLM returns valid JSON response
4. Message saved with `is_validated = 1`

### Retry Flow (Multiple Attempts)

1. User sends message
2. System builds API request with context
3. LLM returns invalid response (or API call fails)
4. **Failed attempt saved** with `is_validated = 0` and full `request_payload`
5. System adds retry instruction to context
6. LLM tries again
7. If valid, save with `is_validated = 1`
8. If still invalid after 3 attempts, save fallback with `is_validated = 0`

### API Error Flow

When the API call itself fails (e.g., normalization error, timeout):

1. User sends message
2. System builds API request with context
3. API call fails with exception
4. **Failed attempt saved** with `is_validated = 0`, error message as content, and `request_payload`
5. System retries up to 3 times
6. If all attempts fail, error fallback is returned to user

## API Changes

### LlmService::addMessage()

New parameters:
```php
public function addMessage(
    $conversation_id, 
    $role, 
    $content, 
    $attachments = null, 
    $model = null, 
    $tokens_used = null, 
    $raw_response = null, 
    $sent_context = null, 
    $reasoning = null,
    $is_validated = true,      // NEW: validation status
    $request_payload = null    // NEW: API request payload
)
```

### LlmRequestService::addAssistantMessage()

New parameters:
```php
public function addAssistantMessage(
    $conversation_id, 
    $content, 
    $tokens_used = null, 
    $raw_response = null, 
    $context_messages = null, 
    $reasoning = null,
    $is_validated = true,      // NEW: validation status
    $request_payload = null    // NEW: API request payload
)
```

### LlmResponseService::callLlmWithSchemaValidation()

Returns additional data:
```php
return [
    'response' => $parsed,
    'attempts' => $attempt,
    'valid' => true,
    'raw_response' => $response,
    'request_payload' => $full_payload,        // NEW: full API payload (model, temp, messages, etc.)
    'all_attempts' => $all_attempts            // NEW: all attempt data with payloads
];
```

Each attempt in `all_attempts` contains:
```php
[
    'attempt' => 1,                    // Attempt number
    'request_payload' => [...],       // Full API payload for this attempt
    'response' => [...],              // API response (if successful)
    'parsed' => [...],                // Parsed response
    'valid' => false,                 // Whether validation passed
    'error' => null                   // Error message (if API call failed)
]
```

## TypeScript Types

```typescript
export interface Message {
  // ... existing fields ...
  
  /** Whether the message passed JSON schema validation (1=valid, 0=invalid/retry attempt) */
  is_validated?: number | boolean;
  
  /** JSON payload sent to LLM API for debugging (stored on assistant messages) */
  request_payload?: string;
}
```

## Use Cases

### Debugging Schema Validation Failures

1. Go to Admin Console
2. Find conversation with issues
3. Look for yellow "Invalid" badges
4. Click "Payload" to see what was sent
5. Copy payload to Postman
6. Test and identify the issue

### Monitoring Schema Compliance

1. Query database for messages with `is_validated = 0`
2. Analyze patterns in failed validations
3. Adjust schema instructions if needed

### Audit Trail

All LLM interactions are logged, providing:
- Complete history of what was sent
- When validation failed
- How many retries were needed
- Final response (valid or fallback)

## Best Practices

1. **Regular Monitoring**: Check admin console for failed validations
2. **Schema Refinement**: If many failures, consider simplifying schema
3. **Payload Testing**: Use copied payloads to test in isolation
4. **Context Review**: Check sent_context for overly complex instructions

## Security Considerations

- Request payloads may contain sensitive context
- Admin-only access to validation details
- Payloads stored in database (consider retention policy)
- No user data exposed through validation tracking

## Related Documentation

- [Response Schema](response-schema.md) - JSON schema specification
- [Architecture](architecture.md) - System overview
- [API Reference](api-reference.md) - Controller actions
