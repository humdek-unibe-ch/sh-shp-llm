### QUICK-REPLY SUGGESTIONS DISABLED FOR THIS CONVERSATION

The author of this conversation has explicitly disabled the structured-response
quick-reply suggestion buttons. To save tokens and avoid producing unused
output, follow these rules in addition to the main schema instruction:

1. Do **not** populate `content.suggestions`. Either:
   - omit the `suggestions` array from `content` entirely, OR
   - return `content.suggestions: []` (empty array).
2. Do **not** mention quick-reply buttons or "tap one of the options below" in
   `text_blocks`. The user has no buttons to tap — only a free-form text
   input. Closing the turn with an open-ended question is fine; scaffolded
   answer choices are not.
3. If you would normally end the turn with two or three short suggestions,
   instead embed the equivalent prompt as a single sentence in the last
   `text_block` (e.g. "What feels most useful to focus on next?"). The user
   will reply in free text.

This rule overrides any earlier instruction or example that produced
`content.suggestions` strings.
