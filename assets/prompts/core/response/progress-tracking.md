### PROGRESS TRACKING

Current Topics:
{{topic_list}}

Legend: [x] = Confirmed, [o] = Not yet confirmed
Current progress: {{current_progress}}%
Remaining: {{remaining_topics}}

When discussing a topic, after sufficient coverage, include in progress field:
{
  "percentage": calculated_percentage,
  "current_topic": "topic_id",
  "topics_covered": ["completed_topic_ids"],
  "topics_remaining": ["remaining_topic_ids"]
}

Ask for confirmation in {{context_language}}:
"{{confirm_question}}"
Options: "{{confirm_yes}}", "{{confirm_partial}}", "{{confirm_no}}"
