# Context-Aware Auto-Start Message Examples

## How Context Analysis Works

The auto-start feature now intelligently analyzes your conversation context and generates engaging, topic-specific initial messages instead of generic greetings.

## Example Contexts and Generated Messages

### 1. Anxiety Education Context
**Context:**
```markdown
You are an AI assistant helping users learn about anxiety and anxiety disorders.

Key guidelines:
- Be empathetic and supportive
- Use simple, clear language
- Break down complex concepts
- Encourage questions and discussion

Educational topics covered:
- What is anxiety?
- Physical symptoms of anxiety
- Cognitive symptoms of anxiety
- Anxiety triggers and causes
- Coping strategies and techniques
- When to seek professional help
```

**Generated Auto-Start Message:**
> "Hello! I'm here to support you on your journey to better understand and manage anxiety. We can explore topics like anxiety, coping, education. What specific area would you like to focus on today?"

### 2. Depression Support Context
**Context:**
```json
[
  {
    "role": "system",
    "content": "You are a supportive AI assistant specializing in depression awareness and mental health support."
  },
  {
    "role": "system",
    "content": "Key topics: depression symptoms, mood tracking, therapy options, self-care strategies, building support networks, suicide prevention resources."
  }
]
```

**Generated Auto-Start Message:**
> "Hello! I'm here to support you on your journey to better understand and manage depression. We can explore topics like depression, therapy, health. What specific area would you like to focus on today?"

### 3. Learning Module Context
**Context:**
```
Welcome to our comprehensive learning module on mindfulness and meditation.

Module Objectives:
- Understand mindfulness principles
- Learn basic meditation techniques
- Practice breathing exercises
- Develop stress reduction skills
- Build daily mindfulness habits

Topics covered:
1. Introduction to Mindfulness
2. Breathing Techniques
3. Body Scan Meditation
4. Mindful Walking
5. Dealing with Difficult Emotions
```

**Generated Auto-Start Message:**
> "Hi there! I'm excited to help you learn about mindfulness, meditation, breathing. This educational module covers these important topics. Which one interests you most, or shall we start from the beginning?"

### 4. Health & Wellness Context
**Context:**
```
You are a health assistant providing information about nutrition, exercise, and wellness.

Available topics:
- Balanced nutrition principles
- Creating healthy meal plans
- Exercise recommendations
- Stress management techniques
- Sleep hygiene
- Building healthy habits
- Weight management strategies
```

**Generated Auto-Start Message:**
> "Welcome! I'm here to provide helpful information about nutrition, exercise, wellness. Let's work through this together. What questions do you have, or would you like me to explain any specific topic?"

## Topic Detection Logic

The system analyzes context using these methods:

1. **Keyword Matching**: Identifies topic keywords (anxiety, depression, therapy, etc.)
2. **Content Structure**: Extracts topics from headings, bullet points, and structured lists
3. **Context Type Classification**: Determines if content is educational, therapeutic, or informational
4. **Message Generation**: Creates appropriate greeting based on detected topics and context type

## Fallback Behavior

- If no context is configured → Uses the configured `auto_start_message`
- If context exists but no topics detected → Uses the configured `auto_start_message`
- If context analysis fails → Uses the configured `auto_start_message`

## Benefits

- **More Engaging**: Context-aware messages feel personalized and relevant
- **Topic-Specific**: Immediately guides users toward available content
- **Interactive**: Encourages users to specify their interests
- **Professional**: Maintains appropriate tone based on content type
- **Flexible**: Works with both structured JSON and free-form text contexts
