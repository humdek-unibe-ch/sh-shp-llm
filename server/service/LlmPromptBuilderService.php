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
    public function buildSuggestion($descriptor, $current_prompt, $instructions, $model_name = null, $examples = array())
    {
        $config = $this->getLlmConfig();
        $model_name = $model_name ?: $config['llm_default_model'];
        $temperature = 0.3;
        $max_tokens = $config['llm_max_tokens'];

        $system_prompt = $this->prompt_assets->load('core.prompt_builder.system');
        $normalized_examples = $this->normalizeExamplesForBuilder($examples);

        $user_prompt = json_encode(array(
            'owner' => $descriptor,
            'current_prompt' => $current_prompt,
            'instructions' => $instructions,
            'examples' => $normalized_examples
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

    private function normalizeExamplesForBuilder($examples)
    {
        $normalized = array();
        foreach ((array)$examples as $example) {
            if (!is_array($example)) {
                continue;
            }

            $normalized[] = array(
                'case_id' => isset($example['case_id']) ? (int)$example['case_id'] : null,
                'title' => trim((string)($example['title'] ?? '')),
                'dataset_name' => trim((string)($example['dataset_name'] ?? '')),
                'approved_by_name' => trim((string)($example['approved_by_name'] ?? '')),
                'approved_at' => trim((string)($example['approved_at'] ?? '')),
                'student_input' => $this->extractExampleStudentInput($example),
                'approved_response' => $this->extractExampleApprovedResponse($example),
                'expected_response' => $this->extractExampleExpectedResponse($example),
                'notes' => trim((string)($example['notes'] ?? '')),
                'tags' => $this->decodeJsonList($example['tags_json'] ?? null),
            );
        }

        return $normalized;
    }

    private function extractExampleStudentInput($example)
    {
        $payload = $this->decodeJsonAssoc($example['input_payload_json'] ?? null);
        if (!$payload) {
            return '';
        }

        $candidate = $this->extractTextFromPayloadValue($payload['variables'] ?? null);
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = $this->extractTextFromPayloadValue($payload['form_data'] ?? null);
        if ($candidate !== '') {
            return $candidate;
        }

        return $this->extractTextFromPayloadValue($payload);
    }

    private function extractExampleApprovedResponse($example)
    {
        $normalized_output = $this->decodeJsonAssoc($example['normalized_output_json'] ?? null);
        $candidate = $this->extractTextFromOutputPayload($normalized_output);
        if ($candidate !== '') {
            return $candidate;
        }

        $output_payload = $this->decodeJsonAssoc($example['output_payload_json'] ?? null);
        $candidate = $this->extractTextFromOutputPayload($output_payload);
        if ($candidate !== '') {
            return $candidate;
        }

        return $this->extractExampleExpectedResponse($example);
    }

    private function extractExampleExpectedResponse($example)
    {
        $expected_output = $this->decodeJsonAssoc($example['expected_output_json'] ?? null);
        if (!$expected_output) {
            return '';
        }

        foreach (array('assistant_text', 'display_content', 'raw_content', 'content', 'text') as $key) {
            $candidate = $this->cleanExampleText($expected_output[$key] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $this->extractTextFromPayloadValue($expected_output);
    }

    private function decodeJsonAssoc($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function decodeJsonList($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return array();
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array();
        }

        return array_values(array_filter(array_map(function ($item) {
            return trim((string)$item);
        }, $decoded), function ($item) {
            return $item !== '';
        }));
    }

    private function extractTextFromOutputPayload($payload)
    {
        if (!is_array($payload)) {
            return '';
        }

        foreach (array('display_content', 'raw_content', 'assistant_text', 'content', 'text') as $key) {
            $candidate = $this->cleanExampleText($payload[$key] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (!empty($payload['parsed_response']['content']['text_blocks']) && is_array($payload['parsed_response']['content']['text_blocks'])) {
            foreach ($payload['parsed_response']['content']['text_blocks'] as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $candidate = $this->cleanExampleText($block['content'] ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $this->extractTextFromPayloadValue($payload);
    }

    private function extractTextFromPayloadValue($value)
    {
        if (is_string($value)) {
            return $this->cleanExampleText($value);
        }

        if (!is_array($value)) {
            return '';
        }

        $priority_keys = array(
            'student_support',
            'student_answer',
            'student_input',
            'answer',
            'input',
            'prompt',
            'message',
            'content',
            'text',
            'feedback',
        );

        foreach ($priority_keys as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $candidate = $this->extractTextFromPayloadValue($value[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach ($value as $item) {
            $candidate = $this->extractTextFromPayloadValue($item);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function cleanExampleText($value)
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace("/\r\n|\r/u", "\n", $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", $text);

        return trim((string)$text);
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
