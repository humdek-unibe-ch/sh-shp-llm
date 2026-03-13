<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/LlmContextService.php';
require_once __DIR__ . '/LlmFloatingModeService.php';
require_once __DIR__ . '/LlmStrictConversationService.php';
require_once __DIR__ . '/LlmFormModeService.php';
require_once __DIR__ . '/LlmResponseService.php';
require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/LlmPromptResponseRenderService.php';
require_once __DIR__ . '/LlmPromptRegistryService.php';
require_once __DIR__ . '/LlmScriptService.php';

class LlmPromptPlaygroundService extends BaseLlmService
{
    /** @var LlmService */
    private $llm_service;

    /** @var LlmPromptExecutionProfileService */
    private $profile_service;

    /** @var LlmPromptResponseRenderService */
    private $render_service;

    /** @var LlmPromptRegistryService */
    private $registry_service;

    /** @var LlmScriptService */
    private $script_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->render_service = new LlmPromptResponseRenderService();
        $this->registry_service = new LlmPromptRegistryService($services);
        $this->script_service = new LlmScriptService($services);
    }

    /**
     * Run prompt playground execution for one or multiple models.
     *
     * @param array $descriptor
     * @param string $draft_prompt
     * @param array $runtime_values
     * @param array $variables
     * @param array $message_history
     * @param array $selected_models
     * @param array $options
     * @return array
     */
    public function run($descriptor, $draft_prompt, $runtime_values = array(), $variables = array(), $message_history = array(), $selected_models = array(), $options = array())
    {
        $profile = $this->profile_service->resolveExecutionProfile($descriptor);
        $config_snapshot = $this->profile_service->buildConfigSnapshot($profile, $runtime_values);
        $models = array_values(array_filter(array_unique($selected_models)));

        if (empty($models)) {
            $default_model = $config_snapshot['model'] ?? null;
            if ($default_model) {
                $models = array($default_model);
            }
        }

        if (empty($models)) {
            $config = $this->getLlmConfig();
            $models = array($config['llm_default_model']);
        }

        $comparison_group_id = count($models) > 1 ? uniqid('compare_', true) : null;
        $runs = array();

        foreach ($models as $model_name) {
            $runs[] = $this->runSingleModel(
                $profile,
                $descriptor,
                $draft_prompt,
                $runtime_values,
                $variables,
                $message_history,
                $model_name,
                $config_snapshot,
                $comparison_group_id,
                $options
            );
        }

        return array(
            'execution_profile' => $profile,
            'comparison_group_id' => $comparison_group_id,
            'runs' => $runs
        );
    }

    private function runSingleModel($profile, $descriptor, $draft_prompt, $runtime_values, $variables, $message_history, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        if (in_array($profile, array('chat_runtime', 'therapy_chat_runtime', 'therapy_draft_runtime', 'therapy_summary_runtime'), true)) {
            return $this->runChatRuntime(
                $profile,
                $descriptor,
                $draft_prompt,
                $runtime_values,
                $message_history,
                $model_name,
                $config_snapshot,
                $comparison_group_id,
                $options
            );
        }

        if ($profile === 'form_runtime') {
            return $this->runFormRuntime(
                $descriptor,
                $draft_prompt,
                $runtime_values,
                $variables,
                $model_name,
                $config_snapshot,
                $comparison_group_id,
                $options
            );
        }

        if ($profile === 'script_runtime') {
            return $this->runScriptRuntime(
                $descriptor,
                $draft_prompt,
                $runtime_values,
                $variables,
                $model_name,
                $config_snapshot,
                $comparison_group_id,
                $options
            );
        }

        throw new Exception('Prompt owner is not playground-executable');
    }

    private function runChatRuntime($execution_profile, $descriptor, $draft_prompt, $runtime_values, $message_history, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $proxy = new LlmPromptChatRuntimeModel($draft_prompt, $runtime_values, $model_name);
        $floating_mode_service = new LlmFloatingModeService();
        $strict_conversation_service = new LlmStrictConversationService($this->llm_service);
        $form_mode_service = new LlmFormModeService();
        $response_service = new LlmResponseService($proxy, $this->services);
        $context_service = new LlmContextService(
            $proxy,
            $response_service,
            $floating_mode_service,
            $strict_conversation_service,
            $form_mode_service,
            null
        );

        $history = $this->normalizeMessageHistory($message_history);
        if (empty($history)) {
            $history = array(
                array('role' => 'user', 'content' => $this->getDefaultChatPromptForProfile($execution_profile))
            );
        }

        $context_messages = $context_service->buildContextMessages(null, $descriptor['owner_id'] ?? null);
        $effective_messages = array_merge($context_messages, $history);

        $temperature = $config_snapshot['temperature'] ?? LLM_DEFAULT_TEMPERATURE;
        $max_tokens = $config_snapshot['max_tokens'] ?? LLM_DEFAULT_MAX_TOKENS;
        $conversation_id = $this->getOrCreatePromptLabConversation(
            (int)($_SESSION['id_user'] ?? 0),
            $model_name,
            $temperature,
            $max_tokens,
            (int)($descriptor['owner_id'] ?? 0),
            (string)($descriptor['prompt_slot'] ?? 'prompt')
        );

        $request_msg_id = $this->llm_service->addMessage(
            $conversation_id,
            'user',
            $history[count($history) - 1]['content'],
            null,
            $model_name,
            0,
            null,
            $context_messages,
            null,
            true,
            null
        );

        $started_at = microtime(true);
        $response = $this->llm_service->callLlmApi(
            $effective_messages,
            $model_name,
            $temperature,
            $max_tokens,
            array(
                'conversation_id' => $conversation_id,
                'sent_context' => $context_messages,
                'is_validated' => true
            )
        );

        $rendered = $this->render_service->render($response['content'] ?? '', $model_name);
        $duration_ms = null;
        if (!empty($response['processing_time'])) {
            $duration_ms = (int)round(((float)$response['processing_time']) * 1000);
        } else {
            $duration_ms = (int)round((microtime(true) - $started_at) * 1000);
        }
        $result = array_merge($rendered, array(
            'model' => $model_name,
            'execution_profile' => $execution_profile,
            'request_payload' => $response['request_payload'] ?? null,
            'effective_context' => $effective_messages,
            'id_llmConversations' => $conversation_id,
            'id_llmMessages_request' => $request_msg_id,
            'id_llmMessages_response' => $response['logged_message_id'] ?? null,
            'tokens_used' => $response['usage']['total_tokens'] ?? null,
            'duration_ms' => $duration_ms,
            'logged_message_id' => $response['logged_message_id'] ?? null
        ));

        $result['id_llm_prompt_playground_runs'] = $this->logRun($descriptor, $config_snapshot, array(), $result, $comparison_group_id, $options);

        return $result;
    }

    private function getDefaultChatPromptForProfile($profile)
    {
        if ($profile === 'therapy_draft_runtime') {
            return 'Create a therapist-facing reply draft for the latest patient message.';
        }
        if ($profile === 'therapy_summary_runtime') {
            return 'Summarize this therapy conversation with key themes, risks, and next steps.';
        }
        return 'Test this prompt in playground mode.';
    }

    private function runFormRuntime($descriptor, $draft_prompt, $runtime_values, $variables, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $context_clean = trim(strip_tags((string)$draft_prompt));
        $filtered_form_data = $this->filterInterpolationValues($context_clean, $variables);
        $interpolated_context = $this->interpolateTemplate($context_clean, $filtered_form_data);
        $user_prompt = $this->buildFormUserPrompt($filtered_form_data);
        $language_code = $this->getSessionLanguageCode();
        $system_prompt = $interpolated_context;

        if ($language_code !== '') {
            $system_prompt .= "\n\nPlease respond in the following language: {$language_code}.";
        }

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt !== '' ? $user_prompt : 'Form submission')
        );

        $temperature = $config_snapshot['temperature'] ?? LLM_DEFAULT_TEMPERATURE;
        $max_tokens = $config_snapshot['max_tokens'] ?? LLM_DEFAULT_MAX_TOKENS;
        $conversation_id = $this->getOrCreatePromptLabConversation(
            (int)($_SESSION['id_user'] ?? 0),
            $model_name,
            $temperature,
            $max_tokens,
            (int)($descriptor['owner_id'] ?? 0),
            'llm_context'
        );

        $request_msg_id = $this->llm_service->addMessage(
            $conversation_id,
            'user',
            $user_prompt !== '' ? $user_prompt : 'Form submission',
            null,
            $model_name,
            0,
            null,
            $messages,
            null,
            true,
            null
        );

        $started_at = microtime(true);
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

        $rendered = $this->render_service->render($response['content'] ?? '', $model_name);
        $duration_ms = null;
        if (!empty($response['processing_time'])) {
            $duration_ms = (int)round(((float)$response['processing_time']) * 1000);
        } else {
            $duration_ms = (int)round((microtime(true) - $started_at) * 1000);
        }
        $result = array_merge($rendered, array(
            'model' => $model_name,
            'execution_profile' => 'form_runtime',
            'request_payload' => $response['request_payload'] ?? null,
            'effective_context' => $messages,
            'id_llmConversations' => $conversation_id,
            'id_llmMessages_request' => $request_msg_id,
            'id_llmMessages_response' => $response['logged_message_id'] ?? null,
            'tokens_used' => $response['usage']['total_tokens'] ?? null,
            'duration_ms' => $duration_ms,
            'logged_message_id' => $response['logged_message_id'] ?? null
        ));

        $result['id_llm_prompt_playground_runs'] = $this->logRun($descriptor, $config_snapshot, $filtered_form_data, $result, $comparison_group_id, $options);

        return $result;
    }

    private function runScriptRuntime($descriptor, $draft_prompt, $runtime_values, $variables, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $data_config = $runtime_values['data_config'] ?? '[]';
        if (is_string($data_config)) {
            $decoded = json_decode($data_config, true);
            $data_config = is_array($decoded) ? $decoded : array();
        }

        $started_at = microtime(true);
        $result = $this->script_service->execute_llm_script(
            $draft_prompt,
            $data_config,
            $variables,
            $_SESSION['id_user'] ?? null,
            $model_name,
            $config_snapshot['temperature'] ?? null,
            $config_snapshot['max_tokens'] ?? null,
            $descriptor['owner_id'] ?? null,
            $runtime_values['name'] ?? ('Script ' . ($descriptor['owner_id'] ?? '0'))
        );

        if (empty($result['result'])) {
            throw new Exception($result['data'] ?? 'Script playground run failed');
        }

        $response_data = $result['data'] ?? array();
        $raw_response_decoded = array();
        if (!empty($response_data['raw_response'])) {
            $decoded = json_decode($response_data['raw_response'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw_response_decoded = $decoded;
            }
        }

        $rendered = $this->render_service->render($response_data['content'] ?? '', $model_name);
        $normalized = array_merge($rendered, array(
            'model' => $model_name,
            'execution_profile' => 'script_runtime',
            'request_payload' => $raw_response_decoded['request_payload'] ?? null,
            'effective_context' => array(
                array(
                    'role' => 'system',
                    'content' => json_encode($result['context'] ?? array())
                ),
                array(
                    'role' => 'user',
                    'content' => $result['context']['interpolated_prompt'] ?? $draft_prompt
                )
            ),
            'id_llmConversations' => $result['context']['conversation_id'] ?? null,
            'id_llmMessages_request' => null,
            'id_llmMessages_response' => $response_data['logged_message_id'] ?? null,
            'tokens_used' => $response_data['tokens_used'] ?? null,
            'duration_ms' => (int)round((microtime(true) - $started_at) * 1000),
            'logged_message_id' => $response_data['logged_message_id'] ?? null
        ));

        $normalized['id_llm_prompt_playground_runs'] = $this->logRun($descriptor, $config_snapshot, $variables, $normalized, $comparison_group_id, $options);

        return $normalized;
    }

    private function logRun($descriptor, $config_snapshot, $variables, $result, $comparison_group_id, $options = array())
    {
        $bootstrap = $this->registry_service->bootstrapOwner($descriptor);
        $run_mode = !empty($options['run_mode'])
            ? (string)$options['run_mode']
            : ($comparison_group_id ? LLM_PROMPT_RUN_MODE_COMPARE : LLM_PROMPT_RUN_MODE_PLAYGROUND);
        $target_version_id = isset($options['target_version_id']) ? (int)$options['target_version_id'] : 0;

        return $this->registry_service->logPlaygroundRun(array(
            'id_llm_prompt_entries' => $bootstrap['entry']['id'] ?? null,
            'id_llm_prompt_locales' => $bootstrap['locale']['id'] ?? null,
            'id_llm_prompt_versions' => $target_version_id > 0
                ? $target_version_id
                : ($bootstrap['active_version']['id'] ?? null),
            'id_llmConversations' => $result['id_llmConversations'] ?? null,
            'id_llmMessages_request' => $result['id_llmMessages_request'] ?? null,
            'id_llmMessages_response' => $result['id_llmMessages_response'] ?? null,
            'run_mode' => $run_mode,
            'comparison_group_id' => $comparison_group_id,
            'variables_json' => $variables,
            'config_snapshot_json' => $config_snapshot
        ));
    }

    private function normalizeMessageHistory($message_history)
    {
        $history = array();
        foreach ((array)$message_history as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = $item['role'] ?? '';
            $content = trim((string)($item['content'] ?? ''));
            if (!in_array($role, array('system', 'user', 'assistant'), true) || $content === '') {
                continue;
            }

            $history[] = array(
                'role' => $role,
                'content' => $content
            );
        }

        return $history;
    }

    private function filterInterpolationValues($template, $values)
    {
        $allowed = array();
        if (preg_match_all('/\{\{(\w+)\}\}/', $template, $matches)) {
            $allowed = array_fill_keys(array_unique($matches[1]), true);
        }

        if (empty($allowed)) {
            return array();
        }

        $filtered = array();
        foreach ($values as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = is_array($value) ? json_encode($value) : (string)$value;
            }
        }

        return $filtered;
    }

    private function interpolateTemplate($template, $values)
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($values) {
            $key = $matches[1];
            return isset($values[$key]) ? (string)$values[$key] : $matches[0];
        }, $template);
    }

    private function buildFormUserPrompt($form_data)
    {
        $parts = array();
        foreach ($form_data as $key => $value) {
            if ($value === '' && $value !== '0') {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', $key));
            $parts[] = $label . ': ' . $value;
        }

        return implode("\n", $parts);
    }

    private function getSessionLanguageCode()
    {
        $locale = $_SESSION['user_language_locale'] ?? '';
        if ($locale === '') {
            return '';
        }

        return strtolower(substr($locale, 0, 2));
    }

    private function getOrCreatePromptLabConversation($user_id, $model_name, $temperature, $max_tokens, $section_id, $prompt_slot)
    {
        $title = '[Prompt Lab] Section ' . ($section_id ?: 0) . ' ' . ($prompt_slot ?: 'prompt');
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

class LlmPromptChatRuntimeModel
{
    private $prompt;
    private $runtime_values;
    private $model_name;

    public function __construct($prompt, $runtime_values, $model_name)
    {
        $this->prompt = (string)$prompt;
        $this->runtime_values = is_array($runtime_values) ? $runtime_values : array();
        $this->model_name = $model_name;
    }

    public function getParsedConversationContext()
    {
        $context = trim($this->prompt);
        if ($context === '') {
            return array();
        }

        if (substr($context, 0, 1) === '[') {
            $decoded = json_decode($context, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $messages = array();
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $role = $item['role'] ?? 'system';
                    $content = trim((string)($item['content'] ?? ''));
                    if ($content === '') {
                        continue;
                    }
                    $messages[] = array('role' => $role, 'content' => $content);
                }
                if (!empty($messages)) {
                    return $this->appendMediaInstructions($messages);
                }
            }
        }

        return $this->appendMediaInstructions(array(
            array('role' => 'system', 'content' => $context)
        ));
    }

    public function getConversationContext()
    {
        return $this->prompt;
    }

    public function getContextLanguage()
    {
        $locale = $_SESSION['user_language_locale'] ?? 'en-GB';
        return substr($locale, 0, 2);
    }

    public function isProgressTrackingEnabled()
    {
        return ($this->runtime_values['enable_progress_tracking'] ?? '0') === '1';
    }

    public function isFloatingButtonEnabled()
    {
        return ($this->runtime_values['enable_floating_button'] ?? '0') === '1';
    }

    public function isStrictConversationModeEnabled()
    {
        return ($this->runtime_values['strict_conversation_mode'] ?? '0') === '1';
    }

    public function shouldApplyStrictMode()
    {
        return $this->isStrictConversationModeEnabled() && $this->hasConversationContext();
    }

    public function hasConversationContext()
    {
        return trim($this->prompt) !== '';
    }

    public function isFormModeEnabled()
    {
        return ($this->runtime_values['enable_form_mode'] ?? '0') === '1';
    }

    public function isDangerDetectionEnabled()
    {
        return ($this->runtime_values['enable_danger_detection'] ?? '0') === '1';
    }

    public function getDangerKeywords()
    {
        return $this->runtime_values['danger_keywords'] ?? '';
    }

    public function getConfiguredModel()
    {
        return $this->model_name;
    }

    private function appendMediaInstructions($messages)
    {
        if (($this->runtime_values['enable_media_rendering'] ?? '0') !== '1') {
            return $messages;
        }

        $messages[] = array(
            'role' => 'system',
            'content' => 'When referencing images or videos, format them as markdown media links so the client can render them.'
        );

        return $messages;
    }
}
?>
