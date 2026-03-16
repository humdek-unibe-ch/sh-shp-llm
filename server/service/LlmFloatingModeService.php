<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Service class for LLM Floating Mode functionality.
 * Handles floating mode context building to optimize text formatting for chat panels.
 */
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

class LlmFloatingModeService
{
    /** @var LlmPromptAssetLoader */
    private $prompt_assets;

    public function __construct()
    {
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

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
            'content' => $this->prompt_assets->load('core.floating_mode.system')
        ];

        // Prepend floating mode instruction to existing context
        return array_merge([$floating_mode_instruction], $existing_context);
    }
}
?>
