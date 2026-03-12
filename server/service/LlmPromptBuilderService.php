<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmService.php';

class LlmPromptBuilderService extends BaseLlmService
{
    /** @var LlmService */
    private $llm_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
    }

    /**
     * Improve the current prompt draft using an LLM and return structured JSON.
     *
     * @param array $descriptor
     * @param string $current_prompt
     * @param string $instructions
     * @param string|null $model_name
     * @return array
     */
    public function buildSuggestion($descriptor, $current_prompt, $instructions, $model_name = null)
    {
        $config = $this->getLlmConfig();
        $model_name = $model_name ?: $config['llm_default_model'];
        $temperature = 0.3;
        $max_tokens = $config['llm_max_tokens'];

        $system_prompt = <<<PROMPT
You are a prompt engineering assistant for a CMS prompt playground.

Return ONE valid JSON object and nothing else.
The object must have exactly these top-level keys:
- prompt_template
- variables
- notes
- change_summary

Rules:
- Improve the existing prompt instead of starting from scratch.
- Keep the output clean. Do not append explanations into prompt_template.
- notes must stay outside the prompt body.
- variables must be an array of objects with keys: name, type, required, description.
- change_summary must be short.
- If the current prompt is already strong, refine it minimally.
PROMPT;

        $user_prompt = json_encode(array(
            'owner' => $descriptor,
            'current_prompt' => $current_prompt,
            'instructions' => $instructions
        ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt)
        );

        $conversation_id = $this->llm_service->createConversation(
            $_SESSION['id_user'],
            '[Prompt Builder] ' . ($descriptor['prompt_slot'] ?? 'prompt'),
            $model_name,
            $temperature,
            $max_tokens,
            ($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_STYLE_FIELD ? ($descriptor['owner_id'] ?? null) : null
        );

        $request_msg_id = $this->llm_service->addMessage(
            $conversation_id,
            'user',
            $instructions !== '' ? $instructions : 'Improve this prompt',
            null,
            $model_name,
            0,
            null,
            $messages,
            null,
            true,
            null
        );

        $response = $this->llm_service->callLlmApi(
            $messages,
            $model_name,
            $temperature,
            $max_tokens,
            array(
                'conversation_id' => $conversation_id,
                'sent_context' => $messages,
                'is_validated' => true
            )
        );

        $suggestion = $this->decodeBuilderResponse($response['content'] ?? '');
        if (!$suggestion) {
            throw new Exception('Prompt builder returned invalid JSON');
        }

        return array(
            'suggestion' => $suggestion,
            'model' => $model_name,
            'request_payload' => $response['request_payload'] ?? null,
            'logged_message_id' => $response['logged_message_id'] ?? null,
            'id_llmConversations' => $conversation_id,
            'id_llmMessages_request' => $request_msg_id,
            'id_llmMessages_response' => $response['logged_message_id'] ?? null
        );
    }

    private function decodeBuilderResponse($content)
    {
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        $clean = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        $decoded = json_decode($clean, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return array(
            'prompt_template' => (string)($decoded['prompt_template'] ?? ''),
            'variables' => is_array($decoded['variables'] ?? null) ? $decoded['variables'] : array(),
            'notes' => is_array($decoded['notes'] ?? null) ? $decoded['notes'] : array(),
            'change_summary' => (string)($decoded['change_summary'] ?? '')
        );
    }
}
?>
