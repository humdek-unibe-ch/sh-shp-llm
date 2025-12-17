# Progress Tracking System

## Overview

The Progress Tracking System monitors user learning progress through educational content. It uses **confirmation-based tracking** where users explicitly confirm their understanding of topics rather than relying on keyword detection.

## Database Schema

### Table: `llmConversationProgress`

```sql
CREATE TABLE IF NOT EXISTS `llmConversationProgress` (
    `id` INT UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` INT UNSIGNED ZEROFILL NOT NULL,
    `id_sections` INT UNSIGNED ZEROFILL NOT NULL,
    `progress_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `topic_coverage` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_conversation_section` (`id_llmConversations`, `id_sections`),
    CONSTRAINT `fk_llmConversationProgress_conversations` 
        FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_llmConversationProgress_sections` 
        FOREIGN KEY (`id_sections`) REFERENCES `sections` (`id`) ON DELETE CASCADE
);
```

### Column Descriptions

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `id_llmConversations` | INT | Foreign key to conversation |
| `id_sections` | INT | Foreign key to page section |
| `progress_percentage` | DECIMAL(5,2) | Progress percentage (0.00 - 100.00) |
| `topic_coverage` | JSON | Detailed coverage data for each topic |
| `created_at` | TIMESTAMP | Record creation time |
| `updated_at` | TIMESTAMP | Last update time |

---

## Topic Coverage JSON Structure

The `topic_coverage` column stores a JSON object with the following structure:

```json
{
  "topic_f2ce64d0": {
    "id": "topic_f2ce64d0",
    "title": "Was ist Angst?",
    "coverage": 0,
    "depth": 0,
    "matches": [],
    "is_covered": false
  },
  "topic_991efcb8": {
    "id": "topic_991efcb8",
    "title": "Teufelskreis der Angst",
    "coverage": 100,
    "depth": 1,
    "matches": [],
    "is_covered": true,
    "confirmed_at": "2025-12-17 10:35:00"
  }
}
```

### Topic Object Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique topic identifier (generated from topic title hash) |
| `title` | string | Human-readable topic name |
| `coverage` | number | Coverage percentage for this topic (0 or 100) |
| `depth` | number | How many times the user has engaged with this topic |
| `matches` | array | Keywords that matched (legacy, may be empty) |
| `is_covered` | boolean | Whether the user has confirmed understanding |
| `confirmed_at` | string | Timestamp when user confirmed (only if is_covered=true) |

---

## Progress Percentage Calculation

The progress percentage is calculated as:

```
percentage = (confirmed_topics / total_topics) * 100
```

Where:
- `confirmed_topics` = count of topics where `is_covered = true`
- `total_topics` = total number of topics defined in the context

### Example

If context defines 25 topics and 1 is confirmed:
```
percentage = (1 / 25) * 100 = 4%
```

### Monotonic Increase

Progress percentage is **monotonically increasing** - it can only go up, never down. This ensures users never feel like they're losing progress.

```php
// In calculateProgress()
$percentage = max($rawPercentage, $previous_progress);
```

---

## Topic Definition in Context

Topics are extracted from the conversation context using the `TRACKABLE_TOPICS` section:

### Format 1: YAML-like (Recommended)

```markdown
## TRACKABLE_TOPICS
- name: Was ist Angst?
  keywords: angst, anxiety, fear, furcht
- name: Teufelskreis der Angst
  keywords: teufelskreis, vicious cycle, kreislauf
```

### Format 2: Inline

```markdown
## TRACKABLE_TOPICS
- Was ist Angst?: angst, anxiety, fear, furcht
- Teufelskreis der Angst: teufelskreis, vicious cycle, kreislauf
```

### Format 3: Explicit Markers

```markdown
[TOPIC: Was ist Angst? | angst, anxiety, fear, furcht]
Content about this topic...
[/TOPIC]
```

---

## Confirmation-Based Progress Tracking

### How It Works

1. **Topic Discussion**: The LLM discusses a topic with the user
2. **Confirmation Question**: After sufficient discussion, the LLM asks if the user understands
3. **User Confirmation**: User confirms via form or free text
4. **Progress Update**: System marks topic as covered and updates percentage

### Confirmation Form Structure

The LLM should generate forms with this structure:

```json
{
  "id": "topic_confirmation_form",
  "title": "Bestätigung des Verständnisses",
  "fields": [
    {
      "id": "topic_confirmation_id",
      "type": "hidden",
      "label": "",
      "value": "topic_991efcb8"
    },
    {
      "id": "understanding_confirmation",
      "type": "radio",
      "label": "Do you feel you understand this topic?",
      "required": true,
      "options": [
        {"value": "yes_understand", "label": "Yes, I understand"},
        {"value": "need_more", "label": "I need more explanation"},
        {"value": "explain_again", "label": "Please explain again"}
      ]
    }
  ],
  "submit_label": "Submit"
}
```

### Detection Logic

The system detects topic confirmations by looking for:

1. **Explicit Topic ID**: Fields with IDs containing `topic_id`, `topic_confirmation_id`, or `thema_id`

2. **Understanding Fields**: Fields with IDs containing:
   - `confirmation`, `understanding`, `comprehension`
   - `verstanden`, `verstehe`, `verständnis` (German)
   - `knowledge`, `wissen`

3. **Confirmation Values**: Values indicating understanding:
   - `yes`, `ja`, `oui`, `sí`, `si`
   - `yes_understand`, `ja_verstehe`, `understand`
   - `very_well`, `sehr_gut`, `excellent`, `gut`, `good`
   - `completely`, `vollständig`, `clear`, `klar`

4. **Numeric Levels**: Values of 4 or 5 on a 1-5 scale are considered confirmations

### Topic Inference

If no explicit `topic_id` is provided, the system infers the current topic by:

1. Looking up existing progress from the database
2. Finding the first uncovered topic in sequence
3. Marking that topic as confirmed

---

## Dynamic Topic Updates

### When Context Changes

If you modify the context and add new topics:

1. **New Topics Added**: They will appear in `topic_coverage` with `is_covered: false` on next progress calculation
2. **Topics Removed**: Old topic data remains in JSON but won't affect percentage (only defined topics count)
3. **Percentage Recalculation**: Based on confirmed topics / new total topics

### Example: Adding Topics

**Before** (5 topics, 2 confirmed = 40%):
```json
{
  "topic_a": {"is_covered": true},
  "topic_b": {"is_covered": true},
  "topic_c": {"is_covered": false},
  "topic_d": {"is_covered": false},
  "topic_e": {"is_covered": false}
}
```

**After adding 5 more topics** (10 topics, 2 confirmed = 20%):
```json
{
  "topic_a": {"is_covered": true},
  "topic_b": {"is_covered": true},
  "topic_c": {"is_covered": false},
  "topic_d": {"is_covered": false},
  "topic_e": {"is_covered": false},
  "topic_f": {"is_covered": false},
  "topic_g": {"is_covered": false},
  "topic_h": {"is_covered": false},
  "topic_i": {"is_covered": false},
  "topic_j": {"is_covered": false}
}
```

**Note**: Due to monotonic increase rule, the displayed percentage will remain at 40% until more topics are confirmed.

---

## API Endpoints

### GET Progress

```
GET /page?action=get_progress&conversation_id={id}&section_id={id}
```

Response:
```json
{
  "progress": {
    "percentage": 4.0,
    "topics_total": 25,
    "topics_covered": 1,
    "topic_coverage": {...},
    "is_complete": false
  }
}
```

### POST Confirm Topic (Direct)

```
POST /page?action=confirm_topic&section_id={id}
Body: {
  "conversation_id": "13",
  "topic_id": "topic_991efcb8"
}
```

Response:
```json
{
  "success": true,
  "topic_id": "topic_991efcb8",
  "progress": {
    "percentage": 8.0,
    "topics_total": 25,
    "topics_covered": 2,
    ...
  }
}
```

---

## Frontend Integration

### Progress Indicator Component

The `ProgressIndicator` component displays:
- Progress bar with percentage
- Expandable topic list showing covered/uncovered topics
- Milestone celebrations at 25%, 50%, 75%, 100%

### Automatic Updates

Progress updates automatically when:
1. Streaming response completes (progress included in response)
2. Form submission triggers topic confirmation
3. Page/conversation loads (fetches current progress)

### Manual Refresh

Progress can be manually refreshed via:
```typescript
await progressApi.get(conversationId, sectionId);
```

---

## Language Support

Confirmation prompts are available in multiple languages:

| Language | Question |
|----------|----------|
| English | "Do you feel you understand this topic well enough to continue?" |
| German | "Hast du das Gefühl, dass du dieses Thema gut genug verstehst, um fortzufahren?" |
| French | "Pensez-vous comprendre suffisamment ce sujet pour continuer?" |
| Spanish | "¿Sientes que entiendes este tema lo suficiente para continuar?" |
| Italian | "Senti di capire abbastanza questo argomento per continuare?" |
| Portuguese | "Você sente que entende este tópico o suficiente para continuar?" |
| Dutch | "Heb je het gevoel dat je dit onderwerp goed genoeg begrijpt om door te gaan?" |

The language is automatically detected from `$_SESSION['user_language_locale']`.

---

## Debugging

### Debug Endpoint

```
GET /page?action=debug_progress&conversation_id={id}&section_id={id}
```

Returns detailed information about:
- Context analysis
- Topic extraction method used
- Extracted topics
- Current progress state

### Logging

Topic confirmations are logged:
```
LLM: Topic topic_991efcb8 confirmed for conversation 13 (level: confirmed)
```

---

## Best Practices

1. **Define Clear Topics**: Use explicit TRACKABLE_TOPICS section in context
2. **Use Hidden Fields**: Include `topic_confirmation_id` in confirmation forms
3. **Language Consistency**: Ensure confirmation questions match context language
4. **Test Topic Extraction**: Use debug endpoint to verify topics are extracted correctly
5. **Handle Edge Cases**: System gracefully handles missing topics or malformed data

---

## Troubleshooting

### Progress Not Updating

1. Check if progress tracking is enabled in section settings
2. Verify topics are defined in context (use debug endpoint)
3. Check form field IDs match expected patterns
4. Verify confirmation value is recognized

### Topics Not Extracted

1. Check context format matches one of the supported formats
2. Ensure TRACKABLE_TOPICS heading is present
3. Check for HTML conversion issues (context may be HTML not Markdown)

### Progress Shows 0%

1. No topics confirmed yet (expected for new conversations)
2. Topics not defined in context
3. Context may be empty or not loaded

