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
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * LLM Prompt Playground Service
 *
 * Provides interactive prompt execution for the Prompt Lab UI. Handles
 * one-shot runs, conversation-based execution, and parameter overrides
 * (model, temperature, max tokens). Supports execution profiles that
 * determine how the prompt interacts with the LLM (chat vs. script vs.
 * strict conversation vs. form mode).
 *
 * Also supports batch replays used by the evaluation runner for dataset
 * test cases.
 *
 * @package LLM Plugin
 * @see LlmPromptRegistryService For prompt CRUD
 * @see LlmPromptExecutionProfileService For profile resolution
 * @see LlmEvaluationRunnerService For dataset replay orchestration
 */
class LlmPromptPlaygroundService extends BaseLlmService
{
    /** @var LlmService Core LLM API call service */
    private $llm_service;

    /** @var LlmPromptExecutionProfileService Profile resolution and config */
    private $profile_service;

    /** @var LlmPromptResponseRenderService Response normalization for UI */
    private $render_service;

    /** @var LlmPromptRegistryService Prompt registry for version resolution */
    private $registry_service;

    /** @var LlmScriptService Script execution for script-type profiles */
    private $script_service;

    /** @var LlmPromptAssetLoader Loads prompt templates from disk */
    private $prompt_assets;

    /**
     * @param object $services SelfHelp services container.
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
        $this->profile_service = new LlmPromptExecutionProfileService($services);
        $this->render_service = new LlmPromptResponseRenderService();
        $this->registry_service = new LlmPromptRegistryService($services);
        $this->script_service = new LlmScriptService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
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

    /**
     * Dispatch a single-model playground run to the appropriate runtime handler based on execution profile.
     *
     * @param string $profile             Execution profile code (chat_runtime, form_runtime, etc.).
     * @param array  $descriptor          Prompt owner descriptor.
     * @param string $draft_prompt        Raw prompt template text.
     * @param array  $runtime_values      Model/temperature/max_tokens overrides and feature flags.
     * @param array  $variables           Template variable key-value pairs.
     * @param array  $message_history     Conversation history (for chat runtimes).
     * @param string $model_name          LLM model identifier.
     * @param array  $config_snapshot     Resolved config from the execution profile.
     * @param string|null $comparison_group_id Unique ID linking multi-model comparison runs.
     * @param array  $options             Additional options (run_mode, target_version_id).
     * @return array Run result with rendered content, metadata, and IDs.
     * @throws Exception If profile is not playground-executable.
     */
    private function runSingleModel($profile, $descriptor, $draft_prompt, $runtime_values, $variables, $message_history, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $runtime_type = $this->profile_service->getPlaygroundRuntimeType($profile);

        if ($runtime_type === 'chat' || $this->profile_service->isChatLikeExecutionProfile($profile)) {
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

        if ($runtime_type === 'form' || $profile === 'form_runtime') {
            return $this->runFormRuntime(
                $profile,
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

        if ($profile === 'memory_runtime') {
            return $this->runMemoryRuntime(
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

        if ($runtime_type === 'script' || $profile === 'script_runtime') {
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

    /**
     * Execute a memory-rule prompt.
     *
     * When the caller supplies a structured `memory_context` (dataset replays
     * populate this from the imported sent_context), the runtime reassembles
     * the exact prompt the live memory worker produced: the original system
     * message, and the original user message with every context section
     * (Scope, Current Memory, Submitted Data, Additional Context, Reminder)
     * preserved. Only the `## Instructions` block is replaced by the draft
     * admin prompt, interpolated with the variables parsed from the case.
     * The messages are then sent to the LLM directly via callLlmApi.
     *
     * When no memory_context is available (raw playground runs from the UI
     * without an imported scenario), the runtime falls back to the script
     * runtime so the admin can still iterate on an instruction template with
     * mock variables.
     *
     * @param array  $descriptor          Prompt owner descriptor.
     * @param string $draft_prompt        Raw prompt template text.
     * @param array  $runtime_values      Override values including 'name' for the rule.
     * @param array  $variables           Template variable key-value pairs.
     * @param array  $message_history     Message history from the dataset case.
     * @param string $model_name          LLM model identifier.
     * @param array  $config_snapshot     Resolved config snapshot.
     * @param string|null $comparison_group_id Comparison group ID for multi-model runs.
     * @param array  $options             Additional run options. May carry 'memory_context'.
     * @return array Run result with execution_profile set to 'memory_runtime'.
     */
    private function runMemoryRuntime($descriptor, $draft_prompt, $runtime_values, $variables, $message_history, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $runtime_values = is_array($runtime_values) ? $runtime_values : array();
        if (empty($runtime_values['name'])) {
            $runtime_values['name'] = $descriptor['title'] ?? 'Memory Rule';
        }

        $memory_config_snapshot = is_array($config_snapshot) ? $config_snapshot : array();
        $memory_config_snapshot['execution_profile'] = 'memory_runtime';
        $memory_config_snapshot['playground_runtime_type'] = 'memory_runtime';
        $memory_config_snapshot['playground_runtime_label'] = 'Memory Rule';

        $memory_context = is_array($options['memory_context'] ?? null) ? $options['memory_context'] : null;

        if ($memory_context !== null) {
            $result = $this->runMemoryStructuredReplay(
                $descriptor,
                $draft_prompt,
                $variables,
                $memory_context,
                $model_name,
                $memory_config_snapshot,
                $comparison_group_id,
                $options
            );
        } else {
            $result = $this->runScriptRuntime(
                $descriptor,
                $draft_prompt,
                $runtime_values,
                $variables,
                $model_name,
                $memory_config_snapshot,
                $comparison_group_id,
                $options
            );
        }

        $result['execution_profile'] = 'memory_runtime';
        $result['playground_runtime_type'] = 'memory_runtime';
        return $result;
    }

    /**
     * Replay a memory rule prompt using the structured memory_context captured
     * at import time. The draft admin prompt replaces the `## Instructions`
     * block of the original user message; everything else (system message,
     * Current Memory, Submitted Data, Additional Context, Reminder) is sent
     * verbatim so the LLM sees exactly what the live memory worker would see.
     *
     * @param array  $descriptor
     * @param string $draft_prompt
     * @param array  $variables           Variables parsed from the imported case plus user overrides.
     * @param array  $memory_context      Structured memory context from dataset case input_payload.
     * @param string $model_name
     * @param array  $config_snapshot
     * @param string|null $comparison_group_id
     * @param array  $options
     * @return array Normalized run result.
     */
    private function runMemoryStructuredReplay($descriptor, $draft_prompt, $variables, $memory_context, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $context_variables = is_array($memory_context['variables'] ?? null) ? $memory_context['variables'] : array();
        $effective_variables = array_merge($context_variables, is_array($variables) ? $variables : array());

        $interpolated_instructions = $this->db->replace_calced_values(
            (string)$draft_prompt,
            $effective_variables
        );

        $prefix = (string)($memory_context['prefix_before_instructions'] ?? '');
        $suffix = (string)($memory_context['suffix_after_instructions'] ?? '');
        $user_message = rtrim($prefix)
            . "\n\n## Instructions\n"
            . $interpolated_instructions
            . ($suffix !== '' ? $suffix : '');

        $system_message = (string)($memory_context['system_message'] ?? '');
        $effective_messages = array();
        if ($system_message !== '') {
            $effective_messages[] = array('role' => 'system', 'content' => $system_message);
        }
        $effective_messages[] = array('role' => 'user', 'content' => $user_message);

        $temperature = $config_snapshot['temperature'] ?? LLM_DEFAULT_TEMPERATURE;
        $max_tokens = $config_snapshot['max_tokens'] ?? LLM_DEFAULT_MAX_TOKENS;

        $conversation_id = $this->getOrCreatePromptLabConversation(
            (int)($_SESSION['id_user'] ?? 0),
            $model_name,
            $temperature,
            $max_tokens,
            (int)($descriptor['owner_id'] ?? 0),
            (string)($descriptor['prompt_slot'] ?? 'memory_rule')
        );

        $request_msg_id = $this->llm_service->addMessage(
            $conversation_id,
            'user',
            $user_message,
            null,
            $model_name,
            0,
            null,
            $effective_messages,
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
                'sent_context'    => $effective_messages,
                'is_validated'    => true
            )
        );

        $rendered = $this->render_service->render($response['content'] ?? '', $model_name);
        $duration_ms = !empty($response['processing_time'])
            ? (int)round(((float)$response['processing_time']) * 1000)
            : (int)round((microtime(true) - $started_at) * 1000);

        $normalized = array_merge($rendered, array(
            'model' => $model_name,
            'execution_profile' => 'memory_runtime',
            'request_payload' => $response['request_payload'] ?? null,
            'effective_context' => $effective_messages,
            'id_llmConversations' => $conversation_id,
            'id_llmMessages_request' => $request_msg_id,
            'id_llmMessages_response' => $response['logged_message_id'] ?? null,
            'tokens_used' => $response['usage']['total_tokens'] ?? null,
            'duration_ms' => $duration_ms,
            'logged_message_id' => $response['logged_message_id'] ?? null
        ));

        $normalized['id_llm_prompt_playground_runs'] = $this->logRun(
            $descriptor,
            $config_snapshot,
            $effective_variables,
            $normalized,
            $comparison_group_id,
            $options
        );

        return $normalized;
    }

    /**
     * Execute a chat-style playground run: builds context messages, calls the LLM API, and logs the run.
     *
     * @param string $execution_profile   Profile code.
     * @param array  $descriptor          Prompt owner descriptor.
     * @param string $draft_prompt        System prompt / conversation context text.
     * @param array  $runtime_values      Override values for model parameters.
     * @param array  $message_history     User/assistant message history.
     * @param string $model_name          LLM model identifier.
     * @param array  $config_snapshot     Resolved config snapshot.
     * @param string|null $comparison_group_id Comparison group ID.
     * @param array  $options             Additional run options.
     * @return array Run result with rendered content, conversation ID, message IDs, tokens, and duration.
     */
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
                array('role' => 'user', 'content' => $this->profile_service->resolveDefaultChatPromptForProfile($execution_profile))
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

    /**
     * Execute a form-style playground run: interpolates variables into the prompt, builds system+user messages, and calls the LLM.
     *
     * @param string $execution_profile   Profile code.
     * @param array  $descriptor          Prompt owner descriptor.
     * @param string $draft_prompt        Raw prompt template with {{placeholders}}.
     * @param array  $runtime_values      Override values for model parameters.
     * @param array  $variables           Form field key-value pairs for interpolation.
     * @param string $model_name          LLM model identifier.
     * @param array  $config_snapshot     Resolved config snapshot.
     * @param string|null $comparison_group_id Comparison group ID.
     * @param array  $options             Additional run options.
     * @return array Run result with rendered content, effective context, and IDs.
     */
    private function runFormRuntime($execution_profile, $descriptor, $draft_prompt, $runtime_values, $variables, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $context_clean = trim(strip_tags((string)$draft_prompt));
        $filtered_form_data = $this->filterInterpolationValues($context_clean, $variables);
        $interpolated_context = $this->interpolateTemplate($context_clean, $filtered_form_data);
        $user_prompt = $this->buildFormUserPrompt($filtered_form_data);
        if ($user_prompt === '') {
            // Guard: AI-imported datasets can carry canonical variable names that do
            // not exactly match prompt placeholders. Use full variable payload as
            // fallback so replay does not degrade to "Form submission".
            $fallback_form_data = $this->normalizeReplayFallbackVariables($variables);
            $user_prompt = $this->buildFormUserPrompt($fallback_form_data);
            if (!empty($fallback_form_data)) {
                $filtered_form_data = $fallback_form_data;
            }
        }
        $language_code = $this->getSessionLanguageCode();
        $system_prompt = $interpolated_context;

        if ($language_code !== '') {
            $suffix = $this->prompt_assets->load('core.playground.language_suffix');
            $system_prompt .= "\n\n" . strtr($suffix, array('{{language_code}}' => $language_code));
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
            'execution_profile' => $execution_profile,
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

    /**
     * Execute a script-style playground run via LlmScriptService, wrapping the result in the standard run format.
     *
     * @param array  $descriptor          Prompt owner descriptor.
     * @param string $draft_prompt        Script prompt text.
     * @param array  $runtime_values      Override values including data_config.
     * @param array  $variables           Variable key-value pairs for script interpolation.
     * @param string $model_name          LLM model identifier.
     * @param array  $config_snapshot     Resolved config snapshot.
     * @param string|null $comparison_group_id Comparison group ID.
     * @param array  $options             Additional run options.
     * @return array Normalized run result.
     * @throws Exception If script execution fails.
     */
    private function runScriptRuntime($descriptor, $draft_prompt, $runtime_values, $variables, $model_name, $config_snapshot, $comparison_group_id, $options)
    {
        $data_config = $runtime_values['data_config'] ?? '[]';
        if (is_string($data_config)) {
            $decoded = json_decode($data_config, true);
            $data_config = is_array($decoded) ? $decoded : array();
        }

        // Only scripts should be linked via id_llm_scripts. Memory rules and other
        // owner types must pass null to avoid FK violations on llmConversations.
        $owner_type = (string)($descriptor['owner_type'] ?? '');
        $effective_script_id = $owner_type === LLM_PROMPT_OWNER_SCRIPT
            ? ($descriptor['owner_id'] ?? null)
            : null;
        $default_name_prefix = $owner_type === LLM_PROMPT_OWNER_MEMORY_RULE
            ? 'Memory Rule '
            : 'Script ';
        $effective_script_name = $runtime_values['name']
            ?? ($default_name_prefix . ($descriptor['owner_id'] ?? '0'));

        $started_at = microtime(true);
        $result = $this->script_service->execute_llm_script(
            $draft_prompt,
            $data_config,
            $variables,
            $_SESSION['id_user'] ?? null,
            $model_name,
            $config_snapshot['temperature'] ?? null,
            $config_snapshot['max_tokens'] ?? null,
            $effective_script_id,
            $effective_script_name
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

    /**
     * Persist a playground run record via the prompt registry service.
     *
     * @param array       $descriptor          Prompt owner descriptor.
     * @param array       $config_snapshot     Config snapshot at time of run.
     * @param array       $variables           Variables used for this run.
     * @param array       $result              Execution result with IDs and content.
     * @param string|null $comparison_group_id Comparison group ID (null for single-model runs).
     * @param array       $options             Additional options (run_mode, target_version_id).
     * @return int|null Inserted playground run ID.
     */
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

    /**
     * Filter and normalize a raw message history array to valid system/user/assistant entries.
     *
     * @param array $message_history Raw message array.
     * @return array<int, array{role: string, content: string}> Cleaned message entries.
     */
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

    /**
     * Keep only variable values whose keys match {{placeholder}} tokens in the template.
     *
     * @param string $template Prompt template text.
     * @param array  $values   All available variable key-value pairs.
     * @return array Filtered subset of values matching template placeholders.
     */
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

    /**
     * Replace {{placeholder}} tokens in a template with their corresponding values.
     *
     * @param string $template Template text with {{key}} tokens.
     * @param array  $values   Key-value replacements.
     * @return string Interpolated text (unresolved placeholders left as-is).
     */
    private function interpolateTemplate($template, $values)
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($values) {
            $key = $matches[1];
            return isset($values[$key]) ? (string)$values[$key] : $matches[0];
        }, $template);
    }

    /**
     * Build a human-readable "Label: value" user prompt from form data key-value pairs.
     *
     * @param array $form_data Associative array of field names to values.
     * @return string Newline-separated "Label: value" text (empty if no non-empty values).
     */
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

    /**
     * Flatten and filter replay variables into non-empty scalar string values for fallback prompts.
     *
     * @param array $variables Raw variable payload (may contain arrays or nulls).
     * @return array<string, string> Filtered key-value pairs with non-empty scalar values.
     */
    private function normalizeReplayFallbackVariables($variables)
    {
        $normalized = array();
        foreach ((array)$variables as $key => $value) {
            if (is_array($value)) {
                $scalar = json_encode($value);
            } elseif ($value === null) {
                $scalar = '';
            } else {
                $scalar = (string)$value;
            }
            $scalar = trim((string)$scalar);
            if ($scalar === '') {
                continue;
            }
            $normalized[(string)$key] = $scalar;
        }
        return $normalized;
    }

    /**
     * Extract the two-letter language code from the session locale.
     *
     * @return string Two-letter language code (e.g. 'en'), or empty string if unavailable.
     */
    private function getSessionLanguageCode()
    {
        $locale = $_SESSION['user_language_locale'] ?? '';
        if ($locale === '') {
            return '';
        }

        return strtolower(substr($locale, 0, 2));
    }

    /**
     * Find or create a Prompt Lab conversation for the current user and prompt context.
     *
     * @param int    $user_id    User primary key.
     * @param string $model_name LLM model identifier.
     * @param float  $temperature Model temperature.
     * @param int    $max_tokens  Model max tokens.
     * @param int    $section_id  CMS section ID associated with the prompt.
     * @param string $prompt_slot Prompt slot identifier.
     * @return int Conversation ID (existing or newly created).
     */
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

/**
 * Lightweight value object emulating the LlmChatModel interface for playground
 * prompt execution. Provides the conversation context, system prompt, model
 * parameters, and form schema that the chat controller expects, without
 * requiring a real CMS section or database-backed component.
 *
 * @package LLM Plugin
 */
class LlmPromptChatRuntimeModel
{
    private $prompt;
    private $runtime_values;
    private $model_name;

    /**
     * @param string $prompt         System prompt / conversation context text.
     * @param array  $runtime_values Feature flags and parameter overrides.
     * @param string $model_name     LLM model identifier.
     */
    public function __construct($prompt, $runtime_values, $model_name)
    {
        $this->prompt = (string)$prompt;
        $this->runtime_values = is_array($runtime_values) ? $runtime_values : array();
        $this->model_name = $model_name;
    }

    /**
     * Parse the prompt into structured context messages. Supports JSON array format or plain-text system prompt.
     *
     * @return array<int, array{role: string, content: string}> Parsed context messages with optional media instructions appended.
     */
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

    /** @return string Raw prompt text. */
    public function getConversationContext()
    {
        return $this->prompt;
    }

    /** @return string Two-letter session language code (defaults to 'en'). */
    public function getContextLanguage()
    {
        $locale = $_SESSION['user_language_locale'] ?? 'en-GB';
        return substr($locale, 0, 2);
    }

    /** @return bool Whether progress tracking is enabled in runtime values. */
    public function isProgressTrackingEnabled()
    {
        return ($this->runtime_values['enable_progress_tracking'] ?? '0') === '1';
    }

    /** @return bool Whether floating button mode is enabled. */
    public function isFloatingButtonEnabled()
    {
        return ($this->runtime_values['enable_floating_button'] ?? '0') === '1';
    }

    /** @return bool Whether strict conversation mode is enabled. */
    public function isStrictConversationModeEnabled()
    {
        return ($this->runtime_values['strict_conversation_mode'] ?? '0') === '1';
    }

    /** @return bool Whether strict mode should be applied (enabled and has context). */
    public function shouldApplyStrictMode()
    {
        return $this->isStrictConversationModeEnabled() && $this->hasConversationContext();
    }

    /** @return bool Whether the prompt text is non-empty. */
    public function hasConversationContext()
    {
        return trim($this->prompt) !== '';
    }

    /** @return bool Whether form mode is enabled in runtime values. */
    public function isFormModeEnabled()
    {
        return ($this->runtime_values['enable_form_mode'] ?? '0') === '1';
    }

    /** @return bool Whether danger keyword detection is enabled. */
    public function isDangerDetectionEnabled()
    {
        return ($this->runtime_values['enable_danger_detection'] ?? '0') === '1';
    }

    /** @return string Comma-separated danger keywords, or empty string. */
    public function getDangerKeywords()
    {
        return $this->runtime_values['danger_keywords'] ?? '';
    }

    /** @return string The LLM model identifier for this runtime. */
    public function getConfiguredModel()
    {
        return $this->model_name;
    }

    /**
     * Append a system instruction for markdown media rendering if media rendering is enabled.
     *
     * @param array $messages Existing context messages.
     * @return array Messages with optional media instruction appended.
     */
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
