STANDARD PROMPT SCAFFOLD FOR {{owner_type}} (execution profile: {{execution_profile}})

Keep authored prompts in this section order whenever possible:
{{section_order}}

Section intent:
- `task_role`: what the assistant is and the job it performs
- `style_requirements`: tone, format, brevity, and writing constraints
- `domain_safety_or_business_rules`: task-specific safety, escalation, or business rules
- `examples`: curated examples or demonstrations in one stable section
- `output_behavior`: the final instruction describing how to answer

Scaffold rules:
- Preserve the existing section order when those sections already exist.
- If the prompt is missing some sections, add only the missing sections needed for clarity.
- Do not duplicate the global runtime JSON schema or low-level safety envelope in the prompt body.
- Keep domain-specific safety or business rules in the prompt only when they are specific to the task.
- Put imported evaluation examples only in the `examples` section.
