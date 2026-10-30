<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/formUserInput/FormUserInputController.php";
require_once __DIR__ . "/../../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../../service/LlmService.php";
require_once __DIR__ . "/../../../service/LlmCsrfService.php";
require_once __DIR__ . "/../../../service/LlmErrorReporter.php";
require_once __DIR__ . "/../../../service/LlmDangerDetectionService.php";
require_once __DIR__ . "/../../../service/LlmResponseService.php";
require_once __DIR__ . "/../../../service/prompt/LlmPromptAssetLoader.php";

/**
 * Controller for LLM form styles (llmFormRecord and llmFormLog).
 * Extends FormUserInputController to add LLM generation after form save.
 *
 * Flow:
 * 1. For regenerate/retry AJAX requests: handles directly, returns JSON
 * 2. For normal form submit with __llm_form=1: parent saves data, then we call LLM and return JSON
 * 3. For normal form submit without __llm_form: behaves exactly like parent (non-LLM mode)
 */
class LlmFormController extends FormUserInputController
{
    use LlmJsonResponseTrait;

    /** @var LlmPromptAssetLoader */
    private $prompt_assets;
    /* Constructors ***********************************************************/

    public function __construct($model)
    {
        $this->prompt_assets = new LlmPromptAssetLoader();

        // Every LLM form on the page builds a controller for the same request, and a
        // JSON response ends it: each instance acts only on requests addressed to its
        // own section (the action posts `section_id`, a submit posts `__form_name`).
        if (isset($_POST['__llm_action']) && in_array($_POST['__llm_action'], ['regenerate', 'retry', 'generate_feedback'])
            && (int)($_POST['section_id'] ?? 0) === (int)$model->get_section_id()) {
            $this->model = $model;
            $this->success = false;
            $this->fail = false;
            $this->rejectCrossSiteRequest();
            if ($_POST['__llm_action'] === 'generate_feedback') {
                $this->handleGenerateFeedback($model);
            } else {
                $this->handleLlmAction($model);
            }
            return;
        }

        // Decided before parent::__construct(), which consumes `__form_name`
        $is_llm_submit = isset($_POST['__llm_form']) && $_POST['__llm_form'] === '1' && $model->isLlmEnabled()
            && isset($_POST['__form_name']) && $_POST['__form_name'] === $model->get_db_field('name');
        if ($is_llm_submit) {
            // Before parent::__construct(), which saves the record
            $this->rejectCrossSiteRequest();
        }
        if ($is_llm_submit && $this->isGuestSession()) {
            // Nothing is saved: guests share one account, so their records and LLM results
            // could not be kept apart.
            $this->sendJsonResponse(['success' => false, 'error' => 'Please log in to use this form'], 401);
        }

        parent::__construct($model);

        if ($is_llm_submit) {
            if ($model->isManualFeedbackEnabled()) {
                $this->sendJsonResponse([
                    'success' => $this->success && !$this->fail,
                    'manual_feedback_mode' => true,
                    'form_errors' => $this->fail ? ($this->error_msgs ?? []) : null,
                    'error' => $this->fail ? 'Form validation failed' : null,
                ]);
            } else if ($this->success && !$this->fail) {
                $this->processLlmGeneration($model);
            } else if ($this->fail) {
                $this->sendJsonResponse([
                    'success' => false,
                    'form_errors' => $this->error_msgs,
                    'error' => 'Form validation failed',
                ]);
            } else {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Form was not processed. Please try again.',
                ]);
            }
        }
    }

    /* Private Methods *********************************************************/

    /**
     * End the request with a 403 when another website made it on the
     * participant's behalf (see LlmCsrfService::verifyRequestOrTrustedClient
     * for what passes; the native app sends no browser headers).
     */
    private function rejectCrossSiteRequest()
    {
        if (!LlmCsrfService::verifyRequestOrTrustedClient()) {
            $this->sendJsonResponse([
                'success' => false,
                'error' => 'Security check failed. Please reload the page and try again.',
            ], 403);
        }
    }

    /**
     * Anonymous visitors all share the guest account, so records and LLM results
     * cannot be kept apart per person: logged-in users only.
     *
     * @return bool True when there is no logged-in user
     */
    private function isGuestSession()
    {
        $user_id = $_SESSION['id_user'] ?? null;
        return !$user_id || (defined('GUEST_USER_ID') && (int)$user_id === (int)GUEST_USER_ID);
    }

    /**
     * Handle manual feedback generation.
     * Reads form field values from POST without saving, calls LLM, returns result.
     */
    private function handleGenerateFeedback($model)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        if ($this->isGuestSession()) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
            return;
        }

        if (!$model->isLlmEnabled() || !$model->isManualFeedbackEnabled()) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Manual feedback mode is not enabled'], 400);
            return;
        }

        $form_data = $this->collectFormData();

        if (empty($form_data)) {
            $this->sendJsonResponse(['success' => false, 'error' => 'No form data provided'], 400);
            return;
        }

        $result = $this->callLlmWithData($model, $form_data);

        $section_id = $model->get_section_id();
        $table_name = sprintf('%010d', $section_id);
        $record = $this->loadRecordData($model, $table_name, $user_id, $_POST[SELECTED_RECORD_ID] ?? null);
        if ($result['success'] && !empty($record)) {
            $this->saveLlmResultToRecord($model, $table_name, $record, $result);
        }
        $result = $this->attachRecordIdToResult($result, $record);

        $this->sendJsonResponse($result);
    }

    /**
     * Handle regenerate/retry AJAX requests.
     * Loads existing record data and re-runs the LLM call.
     */
    private function handleLlmAction($model)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        if ($this->isGuestSession()) {
            $this->sendJsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
            return;
        }

        if (!$model->isLlmEnabled()) {
            $this->sendJsonResponse(['success' => false, 'error' => 'LLM generation is disabled'], 400);
            return;
        }

        $section_id = $model->get_section_id();
        $record_id = $_POST['__record_id'] ?? null;
        $table_name = sprintf('%010d', $section_id);
        $form_data = $this->loadRecordData($model, $table_name, $user_id, $record_id);

        if (empty($form_data)) {
            $this->sendJsonResponse(['success' => false, 'error' => 'No saved data found to regenerate from'], 404);
            return;
        }

        $result = $this->callLlmWithData($model, $form_data);

        if ($result['success']) {
            $this->saveLlmResultToRecord($model, $table_name, $form_data, $result);
        }
        $result = $this->attachRecordIdToResult($result, $form_data);

        $this->sendJsonResponse($result);
    }

    /**
     * Process LLM generation after a successful form save.
     */
    private function processLlmGeneration($model)
    {
        $section_id = $model->get_section_id();
        $table_name = sprintf('%010d', $section_id);
        $user_id = $_SESSION['id_user'] ?? null;

        $form_data = $this->collectFormData();

        $result = $this->callLlmWithData($model, $form_data);

        $record = null;
        if ($result['success']) {
            $record = $this->loadRecordData($model, $table_name, $user_id, $_POST[SELECTED_RECORD_ID] ?? null);
            if (!empty($record)) {
                $this->saveLlmResultToRecord($model, $table_name, $record, $result);
            } else {
                error_log("LLM Form: No record found to save LLM result into. table=$table_name, user=$user_id");
            }
        }
        $result = $this->attachRecordIdToResult($result, $record);

        $this->sendJsonResponse($result);
    }

    /**
     * Attach the current data record id to the LLM response meta so the
     * client can use it for subsequent regenerate / retry actions. Falls
     * back to the latest record for the user when `$record` is empty.
     */
    private function attachRecordIdToResult($result, $record)
    {
        $record_id = null;
        if (is_array($record)) {
            $record_id = $record[ENTRY_RECORD_ID] ?? $record['record_id'] ?? null;
        }
        if (!$record_id) {
            return $result;
        }
        if (!isset($result['llm_meta']) || !is_array($result['llm_meta'])) {
            $result['llm_meta'] = [];
        }
        $result['llm_meta']['record_id'] = $record_id;
        return $result;
    }

    /**
     * Save LLM result fields into the existing data record.
     * Uses the correct UserInput::save_data() signature:
     *   save_data($transaction_by, $table_name, $data, $updateBasedOn)
     */
    private function saveLlmResultToRecord($model, $table_name, $record_data, $llm_result)
    {
        $user_input = $model->get_services()->get_user_input();
        $rid = $record_data[ENTRY_RECORD_ID] ?? $record_data['record_id'] ?? null;

        if (!$rid) {
            return;
        }

        $update_data = [
            'id_users' => $_SESSION['id_user'] ?? 1,
            $model->getLlmResultFieldName() => $llm_result['llm_result'],
            $model->getLlmResultMetaFieldName() => json_encode($llm_result['llm_meta']),
        ];

        $user_input->save_data(
            TRANSACTION_BY_LLM_FORM,
            $table_name,
            $update_data,
            ['record_id' => $rid]
        );
    }

    /**
     * Collect submitted form data from POST, filtering internal fields.
     *
     * @return array Key-value pairs of form field data
     */
    private function collectFormData()
    {
        $form_data = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, '__') === 0) continue;
            if ($key === SELECTED_RECORD_ID || $key === DELETE_RECORD_ID || $key === ENTRY_RECORD_ID) continue;
            $form_data[$key] = $this->normalizePostedValue($value);
        }
        return $form_data;
    }

    /**
     * Normalize posted field values to scalar strings.
     * Handles nested CMS field payloads like:
     * - ['value' => '...']
     * - [lang][gender]['content'] => '...'
     * - arrays of values (multi-select/checkbox)
     *
     * @param mixed $value
     * @return string
     */
    private function normalizePostedValue($value)
    {
        if ($value === null) {
            return '';
        }

        if (!is_array($value)) {
            return is_scalar($value) ? (string)$value : '';
        }

        if (array_key_exists('value', $value)) {
            return $this->normalizePostedValue($value['value']);
        }

        if (array_key_exists('content', $value)) {
            return $this->normalizePostedValue($value['content']);
        }

        $parts = [];
        foreach ($value as $item) {
            $normalized = $this->normalizePostedValue($item);
            if (trim($normalized) !== '') {
                $parts[] = $normalized;
            }
        }

        if (empty($parts)) {
            return '';
        }

        return implode(', ', $parts);
    }

    /**
     * Load record data for a user from the dataTables system.
     *
     * @param LlmFormModel $model
     * @param string $table_name Zero-padded section ID
     * @param int $user_id
     * @param string|null $record_id
     * @return array
     */
    private function loadRecordData($model, $table_name, $user_id, $record_id)
    {
        $user_input = $model->get_services()->get_user_input();
        $form_id = $user_input->get_dataTable_id($table_name);

        if (!$form_id) {
            return [];
        }

        // An explicitly requested record is loaded exactly or not at all: a wrong or foreign
        // id must not silently resolve to (and later overwrite) the user's latest record.
        // Only a request without a record id falls back to the latest one.
        $record_id = $this->normalize_post_id($record_id);
        if ($record_id > 0) {
            $filter = " AND record_id = " . $record_id;
            $data = $user_input->get_data($form_id, $filter, true, $user_id, true);
            return !empty($data) ? $data : [];
        }

        // Get the latest record (ORDER BY record_id DESC) for the user
        $data = $user_input->get_data($form_id, 'ORDER BY record_id DESC', true, $user_id, true);
        if (!empty($data)) return $data;

        return [];
    }

    /**
     * Interpolate the LLM context template with form field values.
     * Replaces {{field_name}} with submitted values.
     *
     * @param string $context Template string
     * @param array $form_data Key-value pairs from form
     * @return string Interpolated context
     */
    private function interpolateContext($context, $form_data)
    {
        if (empty($context)) return $context;

        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($form_data) {
            $key = $matches[1];
            if (isset($form_data[$key])) {
                $val = $form_data[$key];
                return is_array($val) ? json_encode($val) : (string)$val;
            }
            return $matches[0];
        }, $context);
    }

    /**
     * Keep only form fields that are referenced by context interpolation keys.
     *
     * @param array $form_data
     * @param array $allowed_keys
     * @return array
     */
    private function filterFormDataByKeys($form_data, $allowed_keys)
    {
        if (empty($allowed_keys)) {
            return [];
        }

        $allowed = array_fill_keys($allowed_keys, true);
        $filtered = [];
        foreach ($form_data as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    /**
     * Build a user prompt from form data.
     * Creates a readable text from the submitted field values.
     *
     * @param array $form_data Key-value pairs from form
     * @return string The user prompt text
     */
    private function buildUserPrompt($form_data)
    {
        $skip_keys = [
            'trigger_type', 'mobile', 'ajax', '__form_name',
            'record_id', 'id_users', 'timestamp', 'id', 'edit_time',
            'llm_result', 'llm_result_meta',
            ENTRY_RECORD_ID, SELECTED_RECORD_ID, DELETE_RECORD_ID,
        ];
        $parts = [];
        foreach ($form_data as $key => $value) {
            if (in_array($key, $skip_keys)) continue;
            if (strpos($key, '__') === 0) continue;
            if (empty($value) && $value !== '0') continue;
            $label = str_replace('_', ' ', $key);
            $label = ucfirst($label);
            $parts[] = "{$label}: {$value}";
        }
        return implode("\n", $parts);
    }

    /**
     * Call the LLM with given form data using the plugin's LlmService.
     *
     * The llm_context field serves as the system prompt (instructions).
     * The form data is interpolated into the context and also sent
     * as a structured user message.
     *
     * @param LlmFormModel $model
     * @param array $form_data
     * @return array ['success' => bool, 'llm_result' => string, 'llm_meta' => array]
     */
    private function callLlmWithData($model, $form_data)
    {
        $context_template = $model->getLlmContext();
        // Strip HTML tags - the context field is markdown type which gets
        // converted to HTML by Parsedown. We need raw text for the LLM.
        $context_clean = strip_tags($context_template);
        $interpolation_keys = $model->extractContextFieldKeys();
        $filtered_form_data = $this->filterFormDataByKeys($form_data, $interpolation_keys);
        $interpolated_context = $this->interpolateContext($context_clean, $filtered_form_data);
        $user_language = $model->getUserLanguage();
        $user_prompt = $this->buildUserPrompt($filtered_form_data);

        $system_prompt = $interpolated_context;
        if (!empty($user_language) && $model->useUserLanguage()) {
            $suffix = $this->prompt_assets->load('core.playground.language_suffix');
            $system_prompt .= "\n\n" . strtr($suffix, array('{{language_code}}' => $user_language));
        }

        $llm_service = new LlmService($model->get_services());
        $danger_service = new LlmDangerDetectionService($model->get_services(), $model);
        $safety_context = $danger_service->getCriticalSafetyContext();
        if ($safety_context) {
            $system_prompt .= "

" . $safety_context;
        }

        // Same structured reply as chat and interview, so the model's own safety
        // assessment can block, alert and lock like everywhere else.
        // No model: the form model has no getConfiguredModel() for the metadata fallback.
        $response_service = new LlmResponseService(null, $model->get_services());
        $messages = $response_service->buildResponseContext(
            [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt],
            ],
            false,
            [],
            ['enabled' => true, 'keywords' => $danger_service->getKeywords()]
        );

        $llm_model = $model->getLlmModel();
        $temperature = $model->getLlmTemperature();
        $max_tokens = $model->getLlmMaxTokens();
        $reasoning_effort = $model->getLlmReasoningEffort();
        $user_id = $_SESSION['id_user'] ?? null;
        $section_id = $model->get_section_id();
        $blocked_result = function ($message, $detected = []) use ($llm_model, $user_language) {
            return [
                'success' => true,
                'llm_result' => $message,
                'llm_meta' => [
                    'model' => $llm_model,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'status' => 'safety_blocked',
                    'detected_keywords' => $detected,
                    'language' => $user_language,
                ],
                'error' => null,
            ];
        };

        try {
            // A detection anywhere (chat, interview, form) locks the user out of every LLM
            if ($danger_service->isUserBlocked($user_id)) {
                return $blocked_result($danger_service->getBlockedMessage());
            }

            // Same per-user throttle as the chat endpoints; every LLM call here costs money
            $rate_data = $llm_service->checkRateLimit($user_id);

            $conversation_id = $llm_service->getOrCreateConversationForModel(
                $user_id,
                $llm_model,
                $temperature,
                $max_tokens,
                $section_id
            );

            if (!$conversation_id) {
                throw new Exception('Failed to resolve conversation for LLM form logging');
            }

            $llm_service->updateRateLimit($user_id, $rate_data, $conversation_id);

            // Every participant text that reaches the LLM is scanned: the user prompt and
            // the values interpolated into the context (not the author's own context text).
            $scan_text = $user_prompt;
            foreach ($filtered_form_data as $value) {
                $scan_text .= "
" . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value);
            }
            $detected = $danger_service->detectKeywords($scan_text);
            if (!empty($detected)) {
                // Blocking the form conversation locks the user like a chat detection does
                $check = $danger_service->handleDetection($detected, $scan_text, $user_id, $conversation_id);
                return $blocked_result($check['message'], $detected);
            }

            $sent_context = [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => !empty($user_prompt) ? $user_prompt : 'Form submission'],
            ];

            // Log user prompt before the LLM call so message order is user -> assistant.
            $this->logLlmInteraction(
                $model,
                $llm_service,
                $user_prompt,
                [
                    'model' => $llm_model,
                    'temperature' => $temperature,
                    'max_tokens' => $max_tokens,
                    'tokens_used' => 0
                ],
                $conversation_id
            );

            $llm_callable = function ($api_messages) use ($llm_service, $llm_model, $temperature, $max_tokens, $conversation_id, $sent_context, $reasoning_effort) {
                return $llm_service->callLlmApi(
                    $api_messages,
                    $llm_model,
                    $temperature,
                    $max_tokens,
                    [
                        'conversation_id' => $conversation_id,
                        'sent_context' => $sent_context,
                        // mark false initially; the final attempt is updated below
                        'is_validated' => false,
                        'reasoning_effort' => $reasoning_effort,
                    ]
                );
            };
            $result = $response_service->callLlmWithSchemaValidation($llm_callable, $messages);
            $api_response = $result['raw_response'];
            if (!$api_response) {
                // Every attempt failed; the technical reason stays in the server log
                throw new Exception($result['error'] ?? 'LLM API request failed');
            }
            if (!empty($api_response['logged_message_id'])) {
                $llm_service->updateMessage($api_response['logged_message_id'], ['is_validated' => $result['valid'] ? 1 : 0]);
            }

            $parsed = $result['response'];
            if ($parsed['valid'] && isset($parsed['data'])) {
                $danger_service->processSafetyDetection($response_service, $parsed['data'], $conversation_id, $user_id, $section_id);
                $safety = $response_service->assessSafety($parsed['data']);
                if (in_array($safety['danger_level'], ['critical', 'emergency'], true)) {
                    return $blocked_result($danger_service->getBlockedMessage(), $safety['detected_concerns']);
                }
            }

            // The participant sees the reply's text blocks (or the fallback built from an invalid reply)
            $content = isset($parsed['data']) ? $response_service->toMarkdown($parsed['data']) : '';
            $tokens_used = $api_response['usage']['total_tokens'] ?? 0;

            $meta = [
                'model' => $llm_model,
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
                'tokens_used' => $tokens_used,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => !empty($content) ? 'success' : 'empty_response',
                'language' => $user_language,
            ];

            return [
                'success' => !empty($content),
                'llm_result' => $content,
                'llm_meta' => $meta,
                'error' => empty($content) ? 'LLM returned an empty response' : null,
            ];
        } catch (\Exception $e) {
            LlmErrorReporter::log('Form LLM call', $e);
            // The rate-limit text is meant for the participant; provider/DB/internal
            // messages stay in the server log.
            $participant_error = $e instanceof LlmRateLimitException
                ? $e->getMessage()
                : 'The assistant could not generate a response. Please try again.';
            return [
                'success' => false,
                'llm_result' => '',
                'llm_meta' => [
                    'model' => $llm_model,
                    'temperature' => $temperature,
                    'max_tokens' => $max_tokens,
                    'tokens_used' => 0,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'status' => 'error',
                    'error' => $participant_error,
                    'language' => $user_language,
                ],
                'error' => $participant_error,
            ];
        }
    }

    /**
     * Log the user prompt to llmMessages.
     * Assistant API responses are logged centrally in LlmService::callLlmApi().
     */
    private function logLlmInteraction($model, $llm_service, $user_prompt, $meta, $conversation_id = null)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        $section_id = $model->get_section_id();

        try {
            if (!$conversation_id) {
                $conversation_id = $llm_service->getOrCreateConversationForModel(
                    $user_id,
                    $meta['model'],
                    $meta['temperature'] ?? null,
                    $meta['max_tokens'] ?? null,
                    $section_id
                );
            }

            if (!$conversation_id) {
                error_log("LLM Form: getOrCreateConversationForModel returned falsy: " . var_export($conversation_id, true)
                    . " user_id=$user_id, model={$meta['model']}, section_id=$section_id");
                return;
            }

            // addMessage signature:
            // ($conversation_id, $role, $content, $attachments, $model, $tokens_used,
            //  $raw_response, $sent_context, $reasoning, $is_validated, $request_payload)
            $user_msg_id = $llm_service->addMessage(
                $conversation_id,
                'user',
                !empty($user_prompt) ? $user_prompt : 'Form submission',
                null,                   // attachments
                $meta['model'],         // model
                0,                      // tokens_used
                null,                   // raw_response
                null,                   // sent_context
                null,                   // reasoning
                true,                   // is_validated
                null                    // request_payload
            );

            // Assistant message logging is centralized in LlmService::callLlmApi()
            // when log options are provided by the caller.

        } catch (\Exception $e) {
            LlmErrorReporter::log('Form interaction logging', $e);
        }
    }

}
?>
