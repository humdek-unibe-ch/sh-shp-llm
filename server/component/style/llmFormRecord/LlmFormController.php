<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/formUserInput/FormUserInputController.php";
require_once __DIR__ . "/../../../service/LlmService.php";

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
    /* Constructors ***********************************************************/

    public function __construct($model)
    {
        if (isset($_POST['__llm_action']) && in_array($_POST['__llm_action'], ['regenerate', 'retry'])) {
            $this->model = $model;
            $this->success = false;
            $this->fail = false;
            $this->handleLlmAction($model);
            return;
        }

        parent::__construct($model);

        if (isset($_POST['__llm_form']) && $_POST['__llm_form'] === '1' && $model->isLlmEnabled()) {
            if ($this->success && !$this->fail) {
                $this->processLlmGeneration($model);
            } else if ($this->fail) {
                $this->sendJsonResponse([
                    'success' => false,
                    'form_errors' => $this->error_msgs,
                    'error' => 'Form validation failed',
                ]);
            }
        }
    }

    /* Private Methods *********************************************************/

    /**
     * Handle regenerate/retry AJAX requests.
     * Loads existing record data and re-runs the LLM call.
     */
    private function handleLlmAction($model)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        if (!$user_id) {
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

        if ($result['success']) {
            $record = $this->loadRecordData($model, $table_name, $user_id, $_POST[SELECTED_RECORD_ID] ?? null);
            if (!empty($record)) {
                $this->saveLlmResultToRecord($model, $table_name, $record, $result);
            } else {
                error_log("LLM Form: No record found to save LLM result into. table=$table_name, user=$user_id");
            }
        }

        $this->sendJsonResponse($result);
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
            if (is_array($value) && isset($value['value'])) {
                $form_data[$key] = $value['value'];
            } else if (!is_array($value)) {
                $form_data[$key] = $value;
            }
        }
        return $form_data;
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

        if ($record_id) {
            $filter = " AND record_id = " . intval($record_id);
            $data = $user_input->get_data($form_id, $filter, true, $user_id, true);
            if (!empty($data)) return $data;
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
        $interpolated_context = $this->interpolateContext($context_clean, $form_data);
        $user_language = $model->getUserLanguage();
        $user_prompt = $this->buildUserPrompt($form_data);

        $system_prompt = $interpolated_context;
        if (!empty($user_language)) {
            $system_prompt .= "\n\nPlease respond in the following language: {$user_language}.";
        }

        $llm_service = new LlmService($model->get_services());

        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_prompt],
        ];

        $llm_model = $model->getLlmModel();
        $temperature = $model->getLlmTemperature();
        $max_tokens = $model->getLlmMaxTokens();
        $user_id = $_SESSION['id_user'] ?? null;
        $section_id = $model->get_section_id();

        try {
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

            $api_response = $llm_service->callLlmApi(
                $messages,
                $llm_model,
                $temperature,
                $max_tokens,
                [
                    'conversation_id' => $conversation_id,
                    'sent_context' => $sent_context,
                    'is_validated' => true
                ]
            );

            // callLlmApi returns a normalized response from the provider:
            //   'content' => string, 'usage' => [...], 'raw_response' => [...], 'request_payload' => [...]
            $content = $api_response['content'] ?? '';
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
            error_log("LLM Form API error: " . $e->getMessage());
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
                    'error' => $e->getMessage(),
                    'language' => $user_language,
                ],
                'error' => $e->getMessage(),
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
            error_log("LLM Form: Failed to log interaction: " . $e->getMessage());
        }
    }

    /**
     * Send a JSON response and exit.
     */
    private function sendJsonResponse($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit(0);
    }
}
?>
