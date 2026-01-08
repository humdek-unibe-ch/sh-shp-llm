# Language-Adaptive Context Example

This example demonstrates how the LLM Chat system automatically adapts context based on the user's language preference from their session.

## Overview

The system now automatically detects the user's language preference from `$_SESSION['user_language_locale']` and applies language-specific instructions to ensure the AI responds in the user's preferred language.

## How It Works

1. **Language Detection**: The system extracts the language code from the user's session locale (e.g., "de-CH" → "de", "en-GB" → "en")
2. **Context Modification**: Language instructions are automatically prepended to the system context
3. **Consistent Responses**: All AI responses are generated in the user's preferred language

## Configuration

```
Style: llmChat
Model: gpt-4o-mini
Enable Form Mode: No
Enable Data Saving: No
Enable Progress Tracking: Yes
```

## Base System Context

```
# Mental Health Support Assistant

You are a compassionate and professional mental health support assistant. You provide empathetic, non-judgmental support and guidance to users seeking help with mental health concerns.

## YOUR ROLE
- Provide emotional support and validation
- Offer practical coping strategies
- Encourage professional help when appropriate
- Maintain confidentiality and privacy
- Be patient, understanding, and supportive

## GUIDELINES
- Always prioritize user safety
- Never provide medical diagnoses
- Encourage healthy coping mechanisms
- Be culturally sensitive and inclusive
```

## Automatic Language Adaptation

When a user with German language preference (de-CH) accesses the chat, the system automatically adds a **separate critical language instruction** at the very beginning:

**First Context Message (Language Instruction):**
```
CRITICAL LANGUAGE INSTRUCTION: Antworte auf Deutsch. Verwende korrekte deutsche Grammatik und Wortschatz. Use Deutsch and ONLY Deutsch for all responses unless the user specifically requests to switch to a different language later in the conversation. This is your primary language rule that overrides any other instructions.
```

**Second Context Message (Original Context):**
```
# Mental Health Support Assistant

You are a compassionate and professional mental health support assistant. You provide empathetic, non-judgmental support and guidance to users seeking help with mental health concerns.

## YOUR ROLE
- Provide emotional support and validation
- Offer practical coping strategies
- Encourage professional help when appropriate
- Maintain confidentiality and privacy
- Be patient, understanding, and supportive

## GUIDELINES
- Always prioritize user safety
- Never provide medical diagnoses
- Encourage healthy coping mechanisms
- Be culturally sensitive and inclusive
```

## Supported Languages

The system supports the following languages:

- **English (en)**: Default language, no modification needed
- **Deutsch/German (de)**: "Antworte auf Deutsch. Verwende korrekte deutsche Grammatik und Wortschatz."
- **Français/French (fr)**: "Répondez en français. Utilisez une grammaire et un vocabulaire français corrects."
- **Español/Spanish (es)**: "Responde en español. Usa gramática y vocabulario español correctos."
- **Italiano/Italian (it)**: "Rispondi in italiano. Usa grammatica e vocabolario italiano corretti."
- **Português/Portuguese (pt)**: "Responda em português. Use gramática e vocabulário português corretos."
- **Nederlands/Dutch (nl)**: "Reageer in het Nederlands. Gebruik correcte Nederlandse grammatica en woordenschat."

## Language Detection Logic

The language is determined by:
1. Checking `$_SESSION['user_language_locale']` (e.g., "de-CH", "en-GB")
2. Extracting the first 2 characters as the language code
3. Validating against supported languages
4. Defaulting to English if not supported

## Benefits

- **Consistent Experience**: Users receive responses in their preferred language throughout their session
- **Automatic Adaptation**: No manual language switching required
- **Cultural Sensitivity**: Better user experience for non-English speakers
- **Seamless Integration**: Works with existing context configurations

## Example Conversation Flow

**User (German session)**: "Ich fühle mich sehr gestresst."

**AI Response**: "Es tut mir leid zu hören, dass du dich sehr gestresst fühlst. Stress ist eine häufige Erfahrung in unserer modernen Welt. Möchtest du mir erzählen, was dich im Moment am meisten belastet?"

**User (German session)**: "Ich habe Probleme mit meiner Arbeit."

**AI Response**: "Arbeitsstress kann sehr belastend sein. Es ist wichtig, Grenzen zu setzen und Pausen einzulegen. Hast du bereits Strategien ausprobiert, um mit dem Arbeitsstress umzugehen?"

The AI maintains German responses throughout the conversation, providing a consistent and culturally appropriate experience.
