<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Service class for LLM Floating Mode functionality.
 * Handles floating mode context building to optimize text formatting for chat panels.
 */
class LlmFloatingModeService
{
    /**
     * Build floating mode context to encourage width-optimized responses
     *
     * @param array $existing_context Existing conversation context
     * @return array Context with floating mode instructions prepended
     */
    public function buildFloatingModeContext($existing_context = [])
    {
        $floating_mode_instruction = [
            'role' => 'system',
            'content' => 'IMPORTANT: You are operating in FLOATING CHAT MODE. Optimize your responses for narrow chat panels (approximately 380px width).

FORMATTING GUIDELINES:
- Keep text concise and readable in narrow columns
- Use short paragraphs (2-4 sentences max per paragraph)
- Avoid wide tables - prefer bullet points or numbered lists
- Break long lists into smaller chunks
- Use simple markdown formatting only (bold, italic, links)
- Avoid complex layouts that require horizontal scrolling
- Prioritize mobile-friendly formatting
- If showing data, use vertical lists instead of wide tables

WIDTH CONSTRAINTS:
- Chat panel width: ~380px on desktop, ~100% on mobile
- Text should wrap naturally without requiring horizontal scroll
- Tables should be avoided or made very simple (2-3 columns max)
- Code blocks should be short and avoid long lines

RESPONSE STYLE:
- Be conversational and direct
- Use line breaks for readability
- Keep responses scannable in narrow viewports
- Focus on clarity over complex formatting'
        ];

        // Prepend floating mode instruction to existing context
        return array_merge([$floating_mode_instruction], $existing_context);
    }
}
?>
