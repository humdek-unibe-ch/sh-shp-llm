# LLM Conversation Templates

Ready-to-use conversation context templates for the LLM Chat plugin.

## 📋 How to Use

1. **Browse** the templates in the category folders below
2. **Open** the template that fits your use case
3. **Copy** the content from the **System Context** section
4. **Paste** it into the `conversation_context` field in your llmChat style configuration
5. **Customize** the template as needed for your specific requirements

## 📁 Template Categories

### 🤖 general/
General-purpose assistant templates for common use cases.

| Template | Description |
|----------|-------------|
| `basic-assistant.md` | Simple, friendly AI assistant for general conversations |
| `customer-support.md` | Professional customer service agent |
| `faq-bot.md` | Efficient FAQ answering system |

### 📚 education/
Educational and learning-focused templates.

| Template | Description |
|----------|-------------|
| `language-tutor.md` | Interactive language learning assistant |
| `quiz-master.md` | Gamified quiz system with scoring |
| `study-guide.md` | Study companion and note-taking helper |
| `exam-prep.md` | Exam preparation and practice |

### 💚 health/
Health and wellness templates (always include appropriate disclaimers).

| Template | Description |
|----------|-------------|
| `mental-health-support.md` | Compassionate mental wellness companion |
| `wellness-coach.md` | General wellness and lifestyle guidance |

### 📊 assessment/
Data collection and assessment templates.

| Template | Description |
|----------|-------------|
| `survey-collector.md` | Structured survey/questionnaire system |
| `feedback-form.md` | Customer/user feedback collection |
| `personality-quiz.md` | Personality assessment and insights |

### 🔬 research/
Research-focused templates for academic and clinical use.

| Template | Description |
|----------|-------------|
| `data-collection.md` | Structured research data collection |
| `interview-guide.md` | Structured interview administration |
| `participant-screening.md` | Research participant eligibility screening |

## 📝 Template Structure

Each template follows this consistent structure:

```markdown
# Template Name

Brief description of the template's purpose.

## Use Cases
- When to use this template
- Target audience
- Common scenarios

## Configuration
Recommended llmChat settings (form mode, data saving, etc.)

## System Context
The actual context to copy into the conversation_context field.

## Customization
How to adapt the template for your specific needs.

## Example Interaction
Sample conversation showing expected behavior.
```

## ✅ Best Practices

### Before Using a Template

1. **Read the entire template** - Understand what it does before using it
2. **Check the configuration** - Enable/disable features as recommended
3. **Review safety settings** - Enable danger detection for sensitive topics

### Customizing Templates

1. **Always customize** - Templates are starting points, not final solutions
2. **Replace placeholders** - Look for `[BRACKETED TEXT]` and replace with your values
3. **Add your branding** - Include organization name, contact info, etc.
4. **Consider your audience** - Adapt language and tone appropriately

### Testing

1. **Test thoroughly** - Verify the AI behaves as expected
2. **Try edge cases** - Test with unusual inputs
3. **Get feedback** - Have others test before going live
4. **Iterate** - Refine based on actual usage

### Language Considerations

1. **Adapt for your language** - Templates are in English by default
2. **Cultural sensitivity** - Adjust examples and references
3. **Use native speakers** - Have translations reviewed

## ⚙️ Configuration Reference

### Common Settings

| Setting | When to Enable |
|---------|---------------|
| `enable_form_mode` | For structured data collection (surveys, assessments) |
| `enable_data_saving` | When you need to store user responses |
| `is_log` | For tracking over time (vs. single record per user) |
| `strict_conversation_mode` | To keep AI focused on specific topics |
| `enable_danger_detection` | For health/mental health topics |
| `auto_start_conversation` | For guided experiences |
| `enable_floating_button` | For unobtrusive chat access |

### Form Mode Tips

When using form mode:
- AI returns JSON forms instead of text
- Users interact via form controls
- Great for structured data collection
- Combine with `enable_data_saving` to store responses

### Progress Tracking

For educational content:
- Add `[TOPIC]` markers to your context
- Enable progress tracking in settings
- Users see their progress through topics

## 🆘 Troubleshooting

### AI Not Following Template

- Ensure context is in `conversation_context` field (not elsewhere)
- Check for syntax errors in JSON examples
- Verify the context isn't too long (may be truncated)

### Forms Not Rendering

- Enable `enable_form_mode` in settings
- Check JSON syntax in form examples
- Verify field types are supported

### Data Not Saving

- Enable `enable_data_saving` in settings
- Ensure `data_table_name` is set
- Check user permissions

## 📄 License

These templates are provided under the Mozilla Public License 2.0.
Feel free to modify and adapt them for your needs.
