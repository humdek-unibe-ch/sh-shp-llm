<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class LlmPromptAssetRegistry
{
    public static function getMap()
    {
        return array(
            'core.prompt_builder.system' => 'core/prompt-builder/system.md',
            'core.evaluation.judge.system' => 'core/evaluation/judge-system.txt',
            'core.form_mode.system' => 'core/form-mode/system.md',
            'core.floating_mode.system' => 'core/floating-mode/system.md',
            'core.strict_conversation.enforcement' => 'core/strict-conversation/enforcement.md',
            'core.danger_detection.critical_safety' => 'core/danger-detection/critical-safety.md',
            'core.response.schema.instruction' => 'core/response/schema-instruction.md',
            'core.response.safety_detection' => 'core/response/safety-detection.md',
            'core.response.progress_tracking' => 'core/response/progress-tracking.md',
            'core.response.retry_prompt' => 'core/response/retry-prompt.md',
            'core.playground.language_suffix' => 'core/playground/language-suffix.txt',
            'core.prompt_execution.default_chat_prompt' => 'core/playground/default-chat-prompt.txt',
            'core.chat.media_rendering_instructions' => 'core/chat/media-rendering-instructions.md',
            'core.response_schema.system_instructions' => 'core/response/schema-system-instructions.md',
            'core.dataset_import.system' => 'core/dataset-import/system.md',
            'core.dataset_import.repair_json' => 'core/dataset-import/repair-json.md',
        );
    }
}
?>
