# Structured Response Schema

## Overview

The Structured Response Schema is a comprehensive JSON format that ALL LLM responses must follow. This enables:

- **Consistent Parsing**: Every response follows the same structure
- **Flexible Interaction**: Users can always type free text, forms are optional guidance
- **Progress Tracking**: Automatic topic coverage and milestone detection
- **Rich Content**: Support for text blocks, forms, media, and navigation
- **Predictable UX**: Frontend always knows what to render and where

## Critical Design Principle

> **Forms are OPTIONAL guidance, not mandatory gates.**
> 
> Users may always enter free text. Never block progress behind a form.
> The LLM provides structured options, users choose how to respond.

---

## RESPONSE_SCHEMA Definition

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["content", "meta"],
  "properties": {
    "content": {
      "type": "object",
      "description": "All displayable content",
      "required": ["text_blocks"],
      "properties": {
        "text_blocks": {
          "type": "array",
          "description": "Ordered list of text content to display",
          "items": {
            "type": "object",
            "required": ["type", "content"],
            "properties": {
              "type": {
                "type": "string",
                "enum": ["paragraph", "heading", "list", "quote", "info", "warning", "success", "tip"],
                "description": "Block type for styling"
              },
              "content": {
                "type": "string",
                "description": "Markdown-formatted text content"
              },
              "level": {
                "type": "integer",
                "minimum": 1,
                "maximum": 6,
                "description": "Heading level (only for type=heading)"
              }
            }
          }
        },
        "forms": {
          "type": "array",
          "description": "Optional forms for structured input (user can ignore and type freely)",
          "items": {
            "$ref": "#/definitions/form"
          }
        },
        "media": {
          "type": "array",
          "description": "Images, videos, or other media to display",
          "items": {
            "type": "object",
            "required": ["type", "src"],
            "properties": {
              "type": {
                "type": "string",
                "enum": ["image", "video", "audio"]
              },
              "src": {
                "type": "string",
                "description": "URL or asset path"
              },
              "alt": {
                "type": "string"
              },
              "caption": {
                "type": "string"
              }
            }
          }
        },
        "next_step": {
          "type": "object",
          "description": "Guidance on what to do next",
          "properties": {
            "prompt": {
              "type": "string",
              "description": "Suggested next action or question"
            },
            "suggestions": {
              "type": "array",
              "description": "Quick reply suggestions (optional shortcuts)",
              "items": {
                "type": "string"
              }
            },
            "can_skip": {
              "type": "boolean",
              "description": "Whether the user can skip this step",
              "default": true
            }
          }
        }
      }
    },
    "meta": {
      "type": "object",
      "description": "Metadata about the response",
      "required": ["response_type"],
      "properties": {
        "response_type": {
          "type": "string",
          "enum": ["educational", "conversational", "assessment", "summary", "error"],
          "description": "Type of response for context-aware rendering"
        },
        "progress": {
          "type": "object",
          "description": "Progress tracking information",
          "properties": {
            "percentage": {
              "type": "number",
              "minimum": 0,
              "maximum": 100
            },
            "covered_topics": {
              "type": "array",
              "items": {
                "type": "string"
              },
              "description": "List of topic IDs/names now covered"
            },
            "newly_covered": {
              "type": "array",
              "items": {
                "type": "string"
              },
              "description": "Topics covered in THIS message"
            },
            "remaining_topics": {
              "type": "integer",
              "description": "How many topics remain"
            },
            "milestone": {
              "type": "string",
              "enum": ["25%", "50%", "75%", "100%"],
              "description": "Milestone reached (if any)"
            }
          }
        },
        "module_state": {
          "type": "object",
          "description": "Current position in educational module",
          "properties": {
            "current_phase": {
              "type": "string",
              "description": "Current phase name"
            },
            "current_section": {
              "type": "string",
              "description": "Current section/topic being covered"
            },
            "sections_completed": {
              "type": "integer"
            },
            "total_sections": {
              "type": "integer"
            }
          }
        },
        "emotion": {
          "type": "string",
          "enum": ["neutral", "encouraging", "celebratory", "supportive", "informative"],
          "description": "Emotional tone of this response"
        }
      }
    }
  },
  "definitions": {
    "form": {
      "type": "object",
      "required": ["id", "fields"],
      "properties": {
        "id": {
          "type": "string",
          "description": "Unique form identifier"
        },
        "title": {
          "type": "string"
        },
        "description": {
          "type": "string"
        },
        "fields": {
          "type": "array",
          "items": {
            "$ref": "#/definitions/form_field"
          }
        },
        "submit_label": {
          "type": "string",
          "default": "Submit"
        },
        "optional": {
          "type": "boolean",
          "default": true,
          "description": "Whether the user can skip this form"
        }
      }
    },
    "form_field": {
      "type": "object",
      "required": ["id", "type", "label"],
      "properties": {
        "id": {
          "type": "string",
          "pattern": "^[a-z][a-z0-9_]*$",
          "description": "Field identifier (snake_case)"
        },
        "type": {
          "type": "string",
          "enum": ["radio", "checkbox", "select", "text", "textarea", "number"]
        },
        "label": {
          "type": "string"
        },
        "required": {
          "type": "boolean",
          "default": false
        },
        "options": {
          "type": "array",
          "description": "Required for radio, checkbox, select types",
          "items": {
            "type": "object",
            "required": ["value", "label"],
            "properties": {
              "value": {
                "type": "string"
              },
              "label": {
                "type": "string"
              }
            }
          }
        },
        "placeholder": {
          "type": "string"
        },
        "help_text": {
          "type": "string"
        },
        "min": {
          "type": "number"
        },
        "max": {
          "type": "number"
        }
      }
    }
  }
}
```

---

## Example Responses

### Educational Content with Optional Form

```json
{
  "content": {
    "text_blocks": [
      {
        "type": "heading",
        "content": "Understanding the Three Levels of Anxiety",
        "level": 2
      },
      {
        "type": "paragraph",
        "content": "Anxiety manifests on three interconnected levels: **physical**, **mental**, and **behavioral**. Understanding these levels helps you recognize your own anxiety patterns and develop effective coping strategies."
      },
      {
        "type": "list",
        "content": "1. **Physical Level**: Heart racing, sweating, trembling\n2. **Mental Level**: Worried thoughts, catastrophizing\n3. **Behavioral Level**: Avoidance, safety behaviors"
      },
      {
        "type": "info",
        "content": "These three levels interact and can reinforce each other, creating what's called the 'vicious cycle of anxiety.'"
      }
    ],
    "forms": [
      {
        "id": "anxiety_levels_check",
        "title": "Quick Reflection",
        "description": "This helps me understand which level resonates most with you.",
        "optional": true,
        "fields": [
          {
            "id": "primary_level",
            "type": "radio",
            "label": "Which level of anxiety do you notice most in yourself?",
            "required": false,
            "options": [
              {"value": "physical", "label": "Physical (body sensations)"},
              {"value": "mental", "label": "Mental (thoughts and worries)"},
              {"value": "behavioral", "label": "Behavioral (what I do or avoid)"},
              {"value": "all", "label": "All three equally"},
              {"value": "unsure", "label": "I'm not sure yet"}
            ]
          }
        ],
        "submit_label": "Share my experience"
      }
    ],
    "next_step": {
      "prompt": "Feel free to share which level you experience most, or ask me any questions about these concepts.",
      "suggestions": [
        "Tell me more about physical symptoms",
        "What are cognitive distortions?",
        "I experience all three"
      ],
      "can_skip": true
    }
  },
  "meta": {
    "response_type": "educational",
    "progress": {
      "percentage": 20,
      "covered_topics": ["Introduction to Anxiety", "Three Levels Overview"],
      "newly_covered": ["Three Levels Overview"],
      "remaining_topics": 20,
      "milestone": null
    },
    "module_state": {
      "current_phase": "Structured Learning",
      "current_section": "The Three Levels of Anxiety",
      "sections_completed": 2,
      "total_sections": 6
    },
    "emotion": "informative"
  }
}
```

### Conversational Response (No Form)

```json
{
  "content": {
    "text_blocks": [
      {
        "type": "paragraph",
        "content": "That's a great question! The fight-or-flight response is actually a survival mechanism that evolved to protect us from real danger."
      },
      {
        "type": "paragraph",
        "content": "When your brain perceives a threat, the **sympathetic nervous system** activates, releasing adrenaline and cortisol. This causes:"
      },
      {
        "type": "list",
        "content": "- Increased heart rate (to pump blood to muscles)\n- Rapid breathing (more oxygen)\n- Sweating (to cool down)\n- Muscle tension (ready for action)"
      },
      {
        "type": "tip",
        "content": "The good news is that the **parasympathetic nervous system** always kicks in eventually to calm things down. Anxiety cannot escalate forever!"
      }
    ],
    "next_step": {
      "prompt": "Would you like to learn about the mental level next, or do you have more questions about physical symptoms?",
      "suggestions": [
        "Tell me about the mental level",
        "How long do physical symptoms last?",
        "What can I do when symptoms start?"
      ]
    }
  },
  "meta": {
    "response_type": "conversational",
    "progress": {
      "percentage": 25,
      "covered_topics": ["Introduction to Anxiety", "Three Levels Overview", "Physical Level", "Fight-Flight Response"],
      "newly_covered": ["Physical Level", "Fight-Flight Response"],
      "remaining_topics": 18,
      "milestone": "25%"
    },
    "emotion": "encouraging"
  }
}
```

### Milestone Celebration

```json
{
  "content": {
    "text_blocks": [
      {
        "type": "heading",
        "content": "🎉 Great Progress!",
        "level": 2
      },
      {
        "type": "success",
        "content": "You've reached **50% completion**! You now understand the basics of anxiety, its three levels, and what causes anxiety disorders."
      },
      {
        "type": "paragraph",
        "content": "**Topics you've mastered:**\n- What anxiety is and how it differs from normal fear\n- The physical, mental, and behavioral levels\n- Causes including genetics, environment, and learning\n- The fight-or-flight response"
      },
      {
        "type": "info",
        "content": "In the next section, we'll explore what **maintains** anxiety - why it persists even when there's no real danger. This is crucial for understanding how to break the cycle."
      }
    ],
    "next_step": {
      "prompt": "Ready to learn about maintaining factors?",
      "suggestions": [
        "Yes, let's continue",
        "I'd like to review what we covered",
        "I have a question first"
      ]
    }
  },
  "meta": {
    "response_type": "summary",
    "progress": {
      "percentage": 50,
      "covered_topics": ["...12 topics..."],
      "newly_covered": [],
      "remaining_topics": 12,
      "milestone": "50%"
    },
    "module_state": {
      "current_phase": "Milestone Review",
      "current_section": "Progress Summary",
      "sections_completed": 3,
      "total_sections": 6
    },
    "emotion": "celebratory"
  }
}
```

### Assessment Form

```json
{
  "content": {
    "text_blocks": [
      {
        "type": "heading",
        "content": "Self-Assessment: Recognizing Your Patterns",
        "level": 2
      },
      {
        "type": "paragraph",
        "content": "Now that you understand the three levels of anxiety, let's see how they apply to your experience. This is for your self-reflection only - there are no right or wrong answers."
      }
    ],
    "forms": [
      {
        "id": "self_assessment_symptoms",
        "title": "Symptom Recognition",
        "description": "Check any symptoms you've experienced during anxious moments.",
        "optional": true,
        "fields": [
          {
            "id": "physical_symptoms",
            "type": "checkbox",
            "label": "Physical symptoms I experience:",
            "options": [
              {"value": "heart_racing", "label": "Racing heart"},
              {"value": "sweating", "label": "Sweating"},
              {"value": "trembling", "label": "Trembling or shaking"},
              {"value": "breathing", "label": "Shortness of breath"},
              {"value": "stomach", "label": "Stomach discomfort"},
              {"value": "dizziness", "label": "Dizziness"},
              {"value": "tension", "label": "Muscle tension"}
            ]
          },
          {
            "id": "mental_symptoms",
            "type": "checkbox",
            "label": "Mental symptoms I experience:",
            "options": [
              {"value": "worry", "label": "Excessive worry"},
              {"value": "catastrophize", "label": "Expecting the worst"},
              {"value": "focus", "label": "Difficulty concentrating"},
              {"value": "self_critical", "label": "Self-critical thoughts"},
              {"value": "what_if", "label": "'What if' thinking"}
            ]
          },
          {
            "id": "behavioral_symptoms",
            "type": "checkbox",
            "label": "Behavioral patterns I notice:",
            "options": [
              {"value": "avoidance", "label": "Avoiding situations"},
              {"value": "escape", "label": "Leaving situations early"},
              {"value": "safety", "label": "Using safety behaviors"},
              {"value": "reassurance", "label": "Seeking reassurance"}
            ]
          }
        ],
        "submit_label": "Complete Assessment"
      }
    ],
    "next_step": {
      "prompt": "You can fill out the assessment above, or simply tell me about your experiences in your own words.",
      "can_skip": true
    }
  },
  "meta": {
    "response_type": "assessment",
    "progress": {
      "percentage": 35,
      "remaining_topics": 16
    },
    "module_state": {
      "current_phase": "Interactive Assessment",
      "current_section": "Symptom Recognition"
    },
    "emotion": "supportive"
  }
}
```

---

## Text Block Types

| Type | Purpose | Rendering |
|------|---------|-----------|
| `paragraph` | Regular text content | Normal paragraph |
| `heading` | Section headings | H2-H6 based on `level` |
| `list` | Bulleted or numbered lists | Markdown list rendering |
| `quote` | Quotations or callouts | Blockquote styling |
| `info` | Informational callouts | Blue info box |
| `warning` | Warning or caution messages | Yellow warning box |
| `success` | Positive feedback or achievements | Green success box |
| `tip` | Helpful tips or suggestions | Light purple tip box |

---

## Form Field Types

| Type | Description | Required Properties |
|------|-------------|---------------------|
| `radio` | Single selection | `options` array |
| `checkbox` | Multiple selection | `options` array |
| `select` | Dropdown selection | `options` array |
| `text` | Single-line text input | - |
| `textarea` | Multi-line text input | - |
| `number` | Numeric input | Optional: `min`, `max` |

---

## Integration with Progress Tracking

The `meta.progress` object synchronizes with the backend progress tracking system:

1. **Automatic Topic Detection**: When users mention keywords in free text, topics are marked as covered
2. **Form-Based Coverage**: Form responses can also trigger topic coverage
3. **Milestone Celebrations**: The LLM should celebrate milestones (25%, 50%, 75%, 100%)
4. **Adaptive Content**: Use progress data to skip covered topics or offer reviews

---

## Enforcing the Schema

### System Context Instructions

Add this to the start of every conversation context:

```markdown
## CRITICAL OUTPUT RULE

You MUST ALWAYS return a SINGLE valid JSON object that strictly follows the RESPONSE_SCHEMA.
- No plain text outside the JSON
- No markdown code blocks wrapping the JSON
- No explanations outside the JSON structure
- Every response must be parseable JSON

## INTERACTION MODEL

- Users may always enter free text
- Forms are OPTIONAL guidance tools, not mandatory gates
- Never block user progress behind a form
- Always allow topic changes and free exploration

## STRUCTURE RULES

- Educational explanations go into content.text_blocks
- Forms go ONLY into content.forms (always optional)
- Guidance on what to do next goes into content.next_step
- Progress updates go into meta.progress

## FORM RULES

- Forms must be optional unless explicitly marked required
- Forms must be fully machine-parseable
- Never assume the user will fill out a form
- Provide text_blocks as alternative to forms

## PROGRESS TRACKING

- Detect topic coverage from free text OR form responses
- Update covered_topics and percentage accordingly
- Celebrate milestones in text_blocks with emotion: "celebratory"
- Track newly_covered topics for each response

## TONE

- Empathetic, calm, non-judgmental
- Simple, clear language
- Support user autonomy in navigation

## FAILURE CONDITIONS (NEVER DO)

- Do not output raw text without JSON wrapper
- Do not ask the user to "switch modes"
- Do not depend on forms for continuation
- Do not break the schema structure
```

---

## Frontend Parsing Strategy

1. **Parse JSON**: Extract the structured response
2. **Render text_blocks**: Map each block to appropriate component
3. **Render forms**: If present, show as optional interactive elements
4. **Render next_step**: Show suggestions as quick-reply buttons
5. **Update progress**: Sync meta.progress with UI indicators
6. **Handle fallback**: If JSON invalid, render as markdown (backwards compatibility)

---

## Migration from Legacy Format

### Legacy Form Format
```json
{
  "type": "form",
  "title": "...",
  "fields": [...]
}
```

### New Structured Format
```json
{
  "content": {
    "text_blocks": [
      {"type": "paragraph", "content": "Introduction text..."}
    ],
    "forms": [{
      "id": "unique_id",
      "title": "...",
      "fields": [...],
      "optional": true
    }]
  },
  "meta": {
    "response_type": "assessment"
  }
}
```

The frontend should support both formats during transition, with the new format taking precedence.

