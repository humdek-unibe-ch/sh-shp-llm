<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

class LlmPromptBuilderService extends BaseLlmService
{
    /** @var LlmService */
    private $llm_service;
    /** @var LlmPromptAssetLoader */
    private $prompt_assets;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
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

        $system_prompt = $this->prompt_assets->load('core.prompt_builder.system');

        $user_prompt = json_encode(array(
            'owner' => $descriptor,
            'current_prompt' => $current_prompt,
            'instructions' => $instructions
        ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt)
        );

        $section_id = ($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_STYLE_FIELD
            ? (int)($descriptor['owner_id'] ?? 0)
            : null;
        $conversation_id = $this->getOrCreatePromptBuilderConversation(
            (int)($_SESSION['id_user'] ?? 0),
            $model_name,
            $temperature,
            $max_tokens,
            $section_id,
            (string)($descriptor['prompt_slot'] ?? 'prompt')
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

        $notes = array();
        if (is_array($decoded['notes'] ?? null)) {
            $notes = $decoded['notes'];
        } elseif (is_string($decoded['notes'] ?? null)) {
            $single_note = trim((string)$decoded['notes']);
            if ($single_note !== '') {
                $notes = array($single_note);
            }
        }

        return array(
            'prompt_template' => (string)($decoded['prompt_template'] ?? ''),
            'variables' => is_array($decoded['variables'] ?? null) ? $decoded['variables'] : array(),
            'notes' => $notes,
            'change_summary' => (string)($decoded['change_summary'] ?? '')
        );
    }

    private function getOrCreatePromptBuilderConversation($user_id, $model_name, $temperature, $max_tokens, $section_id, $prompt_slot)
    {
        $title = '[Prompt Builder] ' . ($prompt_slot ?: 'prompt');
        if ($section_id) {
            $title .= ' Section ' . $section_id;
        }

        $existing = $this->db->query_db_first(
            "SELECT id
             FROM llmConversations
             WHERE id_users = :id_users
               AND id_sections <=> :id_sections
               AND model = :model
               AND title = :title
               AND deleted = 0
             ORDER BY updated_at DESC
             LIMIT 1",
            array(
                ':id_users' => $user_id,
                ':id_sections' => $section_id ?: null,
                ':model' => $model_name,
                ':title' => $title
            )
        );

        if (!empty($existing['id'])) {
            return (int)$existing['id'];
        }

        return $this->llm_service->createConversation(
            $user_id,
            $title,
            $model_name,
            $temperature,
            $max_tokens,
            $section_id ?: null
        );
    }
}
?>
