# Conversation Context Module

## Overview

The Conversation Context Module allows researchers and designers to define custom AI behavior and system instructions without hardcoded prompts. Context is configured per llmChat component instance and sent to the LLM at the start of each API call.

## Features

- **Configurable Context**: Define AI behavior through CMS fields
- **Multi-format Support**: JSON array or free text/markdown formats
- **Context Tracking**: Each message records what context was sent for debugging
- **Multi-language Support**: Context field supports SelfHelp translations
- **No User Visibility**: Context messages are internal and not shown to users

## Configuration

### Setting Context in CMS

1. Navigate to the page containing your llmChat component
2. Edit the component in the CMS
3. Find the `conversation_context` field
4. Enter your context in one of the supported formats

### Format Options

#### Free Text / Markdown (Recommended for Simple Cases)

Simply enter your system instructions as plain text or markdown:

```markdown
You are an AI assistant helping users learn about anxiety and anxiety disorders.

Key guidelines:
- Be empathetic and supportive
- Use simple, clear language
- Break down complex concepts
- Encourage questions and discussion

You are guiding the user through this educational module. Provide helpful, structured guidance based on this module content.
```

This will be automatically converted to a single system message.

#### JSON Format (For Advanced Cases)

For multiple system messages or specific role assignments, use JSON array format:

```json
[
  {
    "role": "system",
    "content": "You are a helpful AI assistant specializing in mental health education."
  },
  {
    "role": "system", 
    "content": "Always respond in a supportive and non-judgmental manner. If a user expresses distress, encourage them to seek professional help."
  }
]
```

## Technical Implementation

### How Context is Processed

1. **Configuration Load**: When the llmChat component initializes, it loads the `conversation_context` field from the database

2. **Context Parsing**: The `getParsedConversationContext()` method in `LlmChatModel.php` parses the context:
   - If JSON array: Validates structure and returns array of message objects
   - If free text: Wraps in single system message object

3. **API Call**: When a message is sent, the `LlmApiFormatterService` prepends context messages to the API request

4. **Tracking**: The context snapshot is stored with each assistant message in the `sent_context` column for debugging

### Code Flow

```php
// In LlmChatController::handleMessageSubmission()

// Get parsed context
$context_messages = $this->model->getParsedConversationContext();

// Convert messages with context prepended
$api_messages = $this->api_formatter_service->convertToApiFormat(
    $messages, 
    $model, 
    $context_messages  // Context prepended here
);

// For streaming, context is tracked
$this->streaming_service->startStreamingResponse(
    $conversation_id,
    $api_messages,
    $model,
    $is_new_conversation,
    $context_messages  // Tracked for audit
);
```

### Database Storage

#### Configuration Field

```sql
-- Field definition
INSERT INTO `fields` (`name`, `id_type`, `display`) VALUES
('conversation_context', get_field_type_id('markdown'), '0');

-- Linked to llmChat style
INSERT INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('conversation_context'), '', 'System context/instructions...');
```

#### Context Tracking

The `llmMessages` table includes a `sent_context` column:

```sql
ALTER TABLE `llmMessages` ADD COLUMN `sent_context` longtext DEFAULT NULL;
```

This stores a JSON snapshot of the context that was sent with each message, enabling:
- Debugging context issues
- Auditing AI behavior
- Tracking context changes over time

## Use Cases

### Educational Modules

```markdown
You are an AI tutor helping students learn about [topic].

Teaching approach:
- Start with fundamentals before advanced concepts
- Use real-world examples and analogies
- Ask comprehension questions to check understanding
- Provide encouragement and positive reinforcement

Current module: [Module Name]
Learning objectives:
1. [Objective 1]
2. [Objective 2]
```

### Therapeutic Support

```markdown
You are a supportive AI companion for a mental health app.

Guidelines:
- Always maintain empathetic, non-judgmental tone
- Never provide medical advice or diagnoses
- Encourage professional help when appropriate
- Use evidence-based therapeutic techniques

Safety rules:
- If user mentions self-harm, provide crisis resources
- If user mentions abuse, encourage reporting
```

### Research Studies

```json
[
  {
    "role": "system",
    "content": "You are participating in a research study about AI-assisted learning. Your responses should follow the experimental protocol defined below."
  },
  {
    "role": "system",
    "content": "Protocol: Condition A - Provide detailed explanations with examples. Always end responses with a follow-up question."
  }
]
```

## Integration with Advanced Features

### Strict Conversation Mode

When strict conversation mode is enabled, the system automatically enhances your context with enforcement instructions that guide the LLM to stay within defined topics.

#### How Context Works in Strict Mode

1. **Context Enhancement**: Your configured context is automatically prepended with enforcement instructions
2. **Topic Extraction**: Key topics are analyzed from your context to provide specific redirection examples
3. **Enforcement Layer**: An additional system message is added that instructs the AI to:
   - Only respond to questions related to your defined topics
   - Politely redirect off-topic questions back to relevant subjects
   - Maintain focus on the conversation's purpose

#### Example: Mental Health Context with Strict Mode

**Your Context:**
```markdown
You are an AI assistant helping users learn about anxiety management.

Key topics:
- Anxiety symptoms and triggers
- Breathing techniques
- Cognitive behavioral strategies
- Professional help resources
```

**What the AI Receives (Enhanced):**
```markdown
## STRICT CONVERSATION MODE ENABLED

You are operating in strict conversation mode. You must ONLY discuss topics directly related to the following context:

---
You are an AI assistant helping users learn about anxiety management.

Key topics:
- Anxiety symptoms and triggers
- Breathing techniques
- Cognitive behavioral strategies
- Professional help resources
---

Key Topics: anxiety, breathing techniques, cognitive behavioral strategies, professional help

Rules:
1. Stay On Topic: Only answer questions and provide information related to the topics above.
2. Polite Redirection: If a user asks about ANY unrelated topic, respond with a brief, friendly message like:
   - "I'm here to help you with anxiety, breathing techniques, cognitive behavioral strategies, professional help. Is there something specific about these topics I can assist you with?"
3. No Exceptions: Do not provide information about unrelated subjects, even if the request seems harmless.
4. Natural Flow: When redirecting, be warm and helpful, not robotic or dismissive.
```

#### Best Practices for Strict Mode

- **Clear Topic Definition**: Explicitly list key topics in your context for better enforcement
- **Comprehensive Coverage**: Include all acceptable discussion areas
- **Redirection Examples**: The system automatically generates appropriate redirection messages based on your topics
- **Natural Boundaries**: Define boundaries that make sense conversationally

### Auto-Start Conversation Feature

The auto-start conversation feature uses your context to generate intelligent, topic-specific initial messages that immediately engage users.

#### How Context Works in Auto-Start

1. **Context Analysis**: When no conversation exists, the system analyzes your configured context
2. **Topic Extraction**: Key topics and themes are identified from your context
3. **Message Generation**: A personalized opening message is created that:
   - References specific topics from your context
   - Invites engagement on relevant subjects
   - Maintains the tone and purpose defined in your context

#### Auto-Start Message Examples

**Educational Context:**
```
Context: "You are a math tutor specializing in algebra and geometry fundamentals."

Auto-Start: "Hi! I'm your math tutor, ready to help you with algebra equations, geometric proofs, and mathematical fundamentals. What mathematical concept would you like to explore today?"
```

**Therapeutic Context:**
```
Context: "You are a supportive AI companion for stress management and mindfulness."

Auto-Start: "Hello! I'm here to support you with stress reduction techniques, mindfulness practices, and finding calm. What's on your mind that I can help you with?"
```

**Research Context:**
```
Context: "You are conducting a study on AI-assisted learning for programming concepts."

Auto-Start: "Welcome to our programming study! I'm here to help you learn about algorithms, data structures, and coding best practices. Which programming topic interests you most?"
```

#### Context Analysis Process

The system looks for:
- **Explicit Topics**: Lists, bullet points, or headings in your context
- **Role Definition**: "You are a [role]" statements
- **Key Areas**: Repeated terms or emphasized concepts
- **Purpose Statements**: Clear descriptions of the conversation's goals

#### Configuration Tips for Auto-Start

- **Topic Lists**: Include clear lists of key topics for better message generation
- **Engaging Language**: Use welcoming, helpful language that sets the right tone
- **Specific Focus**: Clearly define the conversation's scope and purpose
- **Natural Flow**: Write context that enables natural, conversational opening messages

## Multi-Language Support

The `conversation_context` field supports SelfHelp's translation system:

1. Configure context in the default language (usually English)
2. Switch to other languages in CMS and provide translated context
3. Users see context in their selected language

Example for German translation:
```markdown
Sie sind ein KI-Assistent, der Nutzern hilft, mehr über Angst und Angststörungen zu erfahren.

Wichtige Richtlinien:
- Seien Sie einfühlsam und unterstützend
- Verwenden Sie einfache, klare Sprache
- Erklären Sie komplexe Konzepte verständlich
- Ermutigen Sie zu Fragen und Diskussion
```

## Best Practices

### Do's

- ✅ Keep context focused and relevant
- ✅ Use clear, specific instructions
- ✅ Include safety guidelines when appropriate
- ✅ Test context with various user inputs
- ✅ Use markdown formatting for readability
- ✅ Track context versions for research reproducibility

### Don'ts

- ❌ Don't include sensitive data in context
- ❌ Don't make context too long (impacts token usage)
- ❌ Don't include user-specific information
- ❌ Don't rely on context for security measures
- ❌ Don't forget to test translations

## Troubleshooting

### Context Not Applied

1. Check if field value is saved correctly in CMS
2. Verify JSON syntax if using array format
3. Check browser console for parsing errors
4. Verify `hasConversationContext` returns true in API config

### Context Tracking Not Working

1. Ensure database migration was run
2. Check `sent_context` column exists in `llmMessages`
3. Verify streaming service is passing context to addMessage

### Translation Issues

1. Confirm field is marked as translatable (`display = 1`)
2. Check translation exists for user's language
3. Verify SelfHelp language settings

## API Reference

### Model Methods

```php
// Get raw context string
$context = $model->getConversationContext();

// Get parsed context array
$messages = $model->getParsedConversationContext();

// Check if context is configured
$hasContext = $model->hasConversationContext();
```

### Config API Response

```json
{
  "config": {
    "hasConversationContext": true,
    // ... other config fields
  }
}
```

Note: The actual context content is never exposed to the frontend for security reasons.

## Trackable Topics and Progress Tracking

The Conversation Context Module supports **trackable topics** - a powerful feature that enables automatic progress tracking based on user engagement with defined topics. This is particularly valuable for educational modules, guided conversations, and research studies where you want to measure how thoroughly users explore content.

### How Trackable Topics Work

**🎯 Core Concept**: Trackable topics allow you to define specific learning objectives or discussion areas within your conversation context. The system automatically tracks when users engage with these topics through keyword detection in their messages.

**📊 Progress Calculation**: Progress is calculated as `(covered_topics / total_trackable_topics) × 100%`, with bonus points for deeper engagement with individual topics.

### Defining Trackable Topics

#### Method 1: TRACKABLE_TOPICS Section (Recommended)

The most comprehensive approach is to add a dedicated TRACKABLE_TOPICS section to your conversation context:

```markdown
# Your AI Assistant Context

You are an educational AI assistant...

## TRACKABLE_TOPICS

- name: Anxiety Fundamentals
  keywords: angst definition, was ist angst, normale angst, pathologische angst, angstauslöser

- name: Physical Symptoms
  keywords: körperliche symptome, physische ebene, sympathikus, parasympathikus, kampf flucht reaktion, herzrasen

- name: Cognitive Behavioral Therapy
  keywords: kognitive verhaltenstherapie, cbt, cognitive behavioral therapy, gedanken umstrukturierung

# Rest of your context...
```

**Format Rules:**
- Use `## TRACKABLE_TOPICS` heading (case-sensitive)
- Each topic entry starts with `- name:`
- Keywords follow on the next line with `keywords:`
- Keywords are comma-separated and case-insensitive
- The topic name itself is automatically included as a keyword

#### Method 2: Inline Topic Markers

For shorter contexts or when you want to embed topics within content:

```markdown
[TOPIC: Anxiety Fundamentals | angst definition, was ist angst, normale angst]
[TOPIC: Physical Symptoms | körperliche symptome, physische ebene, sympathikus]
[TOPIC: CBT | kognitive verhaltenstherapie, cbt, gedanken umstrukturierung]
```

#### Method 3: Attribute Format

For more structured topic definitions:

```markdown
[TOPIC:id="fundamentals" name="Anxiety Fundamentals" keywords="angst definition, was ist angst, normale angst"]
[TOPIC:id="symptoms" name="Physical Symptoms" keywords="körperliche symptome, physische ebene"]
```

### Progress Tracking Behavior

#### Automatic Coverage Detection

Topics are marked as "covered" when users mention relevant keywords in their messages:

```
User says: "Was ist der Unterschied zwischen normaler Angst und einer Angststörung?"
→ Covers: "Anxiety Fundamentals" (matches "was ist angst")

User says: "Mein sympathisches Nervensystem reagiert sehr stark"
→ Covers: "Physical Symptoms" (matches "sympathikus")

User says: "Kognitive Verhaltenstherapie klingt hilfreich"
→ Covers: "CBT" (matches "kognitive verhaltenstherapie")
```

#### Key Rules

1. **User Messages Only**: Only user questions and statements count toward progress - AI responses do NOT count
2. **Monotonic Progress**: Progress can only increase, never decrease
3. **Depth Bonus**: Multiple mentions of the same topic add engagement depth (up to 50% extra per topic)
4. **Automatic Keywords**: Topic names are automatically included as keywords

#### Progress Milestones

The system provides visual feedback at key milestones:
- **25% Complete**: Basic concepts covered
- **50% Complete**: Intermediate topics explored
- **75% Complete**: Advanced content engaged
- **100% Complete**: Full topic coverage achieved

### Integration with Progress Tracking System

Trackable topics work seamlessly with the progress tracking feature (`enable_progress_tracking`):

```json
{
  "progress": {
    "percentage": 15.0,
    "topics_total": 25,
    "topics_covered": 4,
    "is_complete": false,
    "topic_coverage": {
      "topic_abc123": {
        "id": "topic_abc123",
        "title": "Anxiety Fundamentals",
        "coverage": 100,
        "depth": 2,
        "matches": ["was ist angst", "angst definition"],
        "is_covered": true
      },
      "topic_def456": {
        "id": "topic_def456",
        "title": "Physical Symptoms",
        "coverage": 0,
        "depth": 0,
        "matches": [],
        "is_covered": false
      }
    }
  }
}
```

### Use Cases

#### Educational Modules
```markdown
## TRACKABLE_TOPICS

- name: Basic Algebra
  keywords: algebra, equations, variables, solve for x
- name: Quadratic Equations
  keywords: quadratic, square root, discriminant, parabola
- name: Word Problems
  keywords: word problems, applied math, real world examples
```

#### Therapeutic Support
```markdown
## TRACKABLE_TOPICS

- name: Anxiety Symptoms
  keywords: symptoms, panic attacks, worry, physical sensations
- name: Coping Strategies
  keywords: coping, breathing techniques, mindfulness, relaxation
- name: Professional Help
  keywords: therapist, counseling, professional help, treatment options
```

#### Product Onboarding
```markdown
## TRACKABLE_TOPICS

- name: Getting Started
  keywords: setup, installation, first steps, basic features
- name: Advanced Features
  keywords: advanced, power user, automation, integrations
- name: Troubleshooting
  keywords: problems, issues, errors, support, help
```

### Best Practices for Trackable Topics

#### Keyword Selection
- **Include Variations**: Add common misspellings, synonyms, and related terms
- **Topic Name First**: Always include the topic name as a primary keyword
- **Context-Specific**: Choose keywords that naturally occur in user conversations
- **Avoid Overlap**: Ensure keywords are distinct enough to avoid false positives

#### Topic Granularity
- **10-30 Topics**: Ideal range for most applications
- **Logical Grouping**: Group related concepts together
- **Progressive Difficulty**: Order from basic to advanced topics
- **Measurable Objectives**: Each topic should represent a clear learning goal

#### Context Integration
- **Natural Flow**: Topics should emerge naturally from conversation
- **Encouragement**: Guide users toward uncovered topics without pressure
- **Flexible Navigation**: Allow users to explore topics in any order

### Technical Implementation

#### Topic Extraction Process

1. **Context Parsing**: System scans for TRACKABLE_TOPICS sections or [TOPIC: ...] markers
2. **Keyword Processing**: Keywords are normalized and stored with each topic
3. **Message Analysis**: User messages are scanned for keyword matches
4. **Coverage Calculation**: Topics are marked covered when matches are found
5. **Progress Updates**: Database updated with current coverage status

#### Database Schema

Topics are stored with the following structure:
```php
[
    'id' => 'topic_abc123',        // Auto-generated unique ID
    'title' => 'Anxiety Fundamentals', // Display name
    'keywords' => ['angst', 'definition', 'was ist angst'], // Match terms
    'weight' => 5,                 // Importance weight
    'content' => 'Anxiety Fundamentals' // Topic content
]
```

#### API Integration

Topics are automatically included in conversation responses when progress tracking is enabled:

```json
{
  "conversation": {
    "messages": [...],
    "progress": {
      "percentage": 20.0,
      "topics_total": 25,
      "topics_covered": 5,
      "topic_coverage": {...}
    }
  }
}
```

### Troubleshooting Trackable Topics

#### Topics Not Being Detected

**Symptoms**: Progress doesn't increase when users ask relevant questions

**Solutions**:
- Verify TRACKABLE_TOPICS section format (exact heading match)
- Check keyword spelling and variations
- Ensure keywords are comma-separated
- Test with exact keyword matches first

#### Progress Increases Too Quickly

**Symptoms**: Progress jumps to 100% after few messages

**Solutions**:
- Review keywords for overlap or over-breadth
- Add more specific keyword variations
- Increase number of topics for finer granularity
- Use more restrictive keyword matching

#### Topics Not Parsing Correctly

**Symptoms**: Topics don't appear in progress API responses

**Solutions**:
- Validate YAML-like format in TRACKABLE_TOPICS section
- Check for proper indentation (name: and keywords: must align)
- Ensure no special characters in topic names
- Test context parsing with debug endpoint

#### Performance Considerations

- **Keyword Matching**: Case-insensitive substring matching is efficient
- **Database Load**: Progress updates are batched to minimize database calls
- **Memory Usage**: Topics are cached per conversation context
- **Scalability**: System handles 50+ topics efficiently

### Complete Example

Here's a complete educational context with trackable topics:

```markdown
# German Language Learning Assistant

You are a friendly German language tutor helping students learn German grammar and vocabulary.

## TRACKABLE_TOPICS

- name: Nomen (Substantive)
  keywords: nomen, substantive, artikel, der die das, geschlecht, genus, deklinieren

- name: Verben (Tätigkeitswörter)
  keywords: verben, tätigkeitswörter, konjugation, infinitiv, präsens, präteritum, perfekt

- name: Adjektive
  keywords: adjektive, eigenschaftswörter, komparation, steigerung, komparativ, superlativ

- name: Satzstruktur
  keywords: satzstruktur, wortstellung, verb zweit, aussagesatz, fragesatz, nebensatz

Learning German requires understanding these fundamental grammatical concepts. I'll help you master each one step by step.
```

This context will automatically track progress as students learn about German nouns, verbs, adjectives, and sentence structure.

