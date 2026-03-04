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
        // Check for LLM-specific AJAX actions before parent processes
        if (isset($_POST['__llm_action']) && in_array($_POST['__llm_action'], ['regenerate', 'retry'])) {
            $this->model = $model;
            $this->success = false;
            $this->fail = false;
            $this->handleLlmAction($model);
            return;
        }

        // Let parent handle the normal form submission (validate + save)
        parent::__construct($model);

        // After parent saves, if this was an LLM form submit, call LLM
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

        // Load the existing record data from UserInput
        $table_name = sprintf('%010d', $section_id);
        $form_data = $this->loadRecordData($model, $table_name, $user_id, $record_id);

        if (empty($form_data)) {
            $this->sendJsonResponse(['success' => false, 'error' => 'No saved data found to regenerate from'], 404);
            return;
        }

        $result = $this->callLlmWithData($model, $form_data);

        if ($result['success']) {
            // Update only the LLM result fields in the existing record
            $update_data = [
                $model->getLlmResultFieldName() => $result['llm_result'],
                $model->getLlmResultMetaFieldName() => json_encode($result['llm_meta']),
            ];

            $user_input_service = $model->get_services()->get_user_input();
            $rid = $record_id ?: ($form_data['record_id'] ?? $form_data['id'] ?? null);

            if ($rid) {
                $user_input_service->save_data(
                    $table_name,
                    $update_data,
                    $user_id,
                    $section_id,
                    $rid
                );
            }
        }

        $this->sendJsonResponse($result);
    }

    /**
     * Process LLM generation after a successful form save.
     */
    private function processLlmGeneration($model)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        $section_id = $model->get_section_id();
        $table_name = sprintf('%010d', $section_id);

        // Collect the submitted form data from POST
        $form_data = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, '__') === 0) continue;
            if (is_array($value) && isset($value['value'])) {
                $form_data[$key] = $value['value'];
            } else if (!is_array($value)) {
                $form_data[$key] = $value;
            }
        }

        $result = $this->callLlmWithData($model, $form_data);

        if ($result['success']) {
            // Save the LLM result to the data record
            $user_input_service = $model->get_services()->get_user_input();
            $update_data = [
                $model->getLlmResultFieldName() => $result['llm_result'],
                $model->getLlmResultMetaFieldName() => json_encode($result['llm_meta']),
            ];

            // Get the record_id from the form or load last record
            $record_id = $_POST[SELECTED_RECORD_ID] ?? null;
            if (!$record_id) {
                $rows = $this->loadRecordData($model, $table_name, $user_id, null);
                $record_id = $rows['record_id'] ?? $rows['id'] ?? null;
            }

            if ($record_id) {
                $user_input_service->save_data(
                    $table_name,
                    $update_data,
                    $user_id,
                    $section_id,
                    $record_id
                );
            }
        }

        $this->sendJsonResponse($result);
    }

    /**
     * Load record data for a user from the dataTables system.
     *
     * @param LlmFormModel $model
     * @param string $table_name
     * @param int $user_id
     * @param string|null $record_id
     * @return array
     */
    private function loadRecordData($model, $table_name, $user_id, $record_id)
    {
        $user_input_service = $model->get_services()->get_user_input();

        if ($record_id) {
            $data = $user_input_service->get_data($table_name, $record_id);
            if (!empty($data)) return $data;
        }

        $rows = $user_input_service->get_data_for_user($table_name, $user_id, false);
        if (!empty($rows)) {
            return end($rows);
        }

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
     * Call the LLM with given form data using the plugin's LlmService.
     *
     * @param LlmFormModel $model
     * @param array $form_data
     * @return array ['success' => bool, 'llm_result' => string, 'llm_meta' => array]
     */
    private function callLlmWithData($model, $form_data)
    {
        $context_template = $model->getLlmContext();
        $interpolated_context = $this->interpolateContext($context_template, $form_data);
        $user_language = $model->getUserLanguage();

        $language_instruction = "Please respond in the following language: {$user_language}.";
        $full_context = $interpolated_context . "\n\n" . $language_instruction;

        $llm_service = new LlmService($model->get_services());

        $messages = [
            ['role' => 'system', 'content' => $full_context],
            ['role' => 'user', 'content' => 'Process the submitted form data and provide a response.'],
        ];

        $llm_model = $model->getLlmModel();
        $temperature = $model->getLlmTemperature();
        $max_tokens = $model->getLlmMaxTokens();

        try {
            $api_response = $llm_service->callLlmApi($messages, $llm_model, $temperature, $max_tokens);

            $content = '';
            $tokens_used = 0;
            if (isset($api_response['choices'][0]['message']['content'])) {
                $content = $api_response['choices'][0]['message']['content'];
            }
            if (isset($api_response['usage']['total_tokens'])) {
                $tokens_used = $api_response['usage']['total_tokens'];
            }

            $meta = [
                'model' => $llm_model,
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
                'tokens_used' => $tokens_used,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'success',
                'language' => $user_language,
            ];

            $this->logLlmInteraction($model, $llm_service, $messages, $content, $meta);

            return [
                'success' => true,
                'llm_result' => $content,
                'llm_meta' => $meta,
            ];
        } catch (\Exception $e) {
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
     * Log the LLM interaction to llmMessages for audit.
     */
    private function logLlmInteraction($model, $llm_service, $messages, $content, $meta)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        $section_id = $model->get_section_id();

        try {
            $conversation_id = $llm_service->getOrCreateConversationForModel(
                $user_id,
                $meta['model'],
                $meta['temperature'] ?? null,
                $meta['max_tokens'] ?? null,
                $section_id
            );

            if ($conversation_id) {
                $llm_service->addMessage(
                    $conversation_id,
                    'user',
                    json_encode(['form_submission' => true, 'context' => $messages[0]['content'] ?? '']),
                    null,
                    $meta['model'],
                    0,
                    null,
                    json_encode($messages)
                );

                $llm_service->addMessage(
                    $conversation_id,
                    'assistant',
                    $content,
                    null,
                    $meta['model'],
                    $meta['tokens_used'] ?? 0
                );
            }
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
