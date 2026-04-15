<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../../../../component/style/BaseStyleComponent.php";
require_once __DIR__ . "/../service/LlmService.php";
require_once __DIR__ . "/../service/LlmScriptService.php";
require_once __DIR__ . "/../service/LlmPromptRegistryService.php";
require_once __DIR__ . "/../service/LlmPromptExecutionProfileService.php";
require_once __DIR__ . "/../service/LlmMemoryConfigService.php";
require_once __DIR__ . "/../service/LlmMemoryTriggerService.php";

/**
 * The class to define the hooks for the LLM plugin.
 */
class LlmHooks extends BaseHooks
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the hooks.
     * @param object $services
     *  The service handler instance which holds all services
     * @param object $params
     *  Various params
     */
    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);
    }

    /* Private Methods *********************************************************/

    /**
     * Output select LLM Model field
     * @param string $value
     * Value of the field
     * @param string $name
     * The name of the fields
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return instance of BaseStyleComponent -> select style
     */
    private function outputSelectLlmModelField($value, $name, $disabled)
    {
        try {
            $llmService = new LlmService($this->services);
            $models = $llmService->getAvailableModels();
            $normalizedValue = $llmService->normalizeModelIdentifier($value);

            $items = array();
            foreach ($models as $model) {
                $items[] = array(
                    'value' => $model['id'],
                    'text' => $model['id']
                );
            }

            return new BaseStyleComponent("select", array(
                "value" => $normalizedValue,
                "name" => $name,
                "max" => 10,
                "live_search" => 1,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        } catch (Exception $e) {
            // Fallback in case of error
            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 10,
                "live_search" => 0,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => array(
                    array('value' => '', 'text' => 'Error loading models: ' . $e->getMessage())
                )
            ));
        }
    }

    /**
     * Return a BaseStyleComponent object
     * @param object $args
     * Params passed to the method
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return a BaseStyleComponent object
     */
    private function returnSelectLlmModelField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] == 'llm_model' || $field['name'] == 'llm_default_model') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectLlmModelField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /**
     * Output select floating button position field
     * @param string $value
     * Value of the field
     * @param string $name
     * The name of the fields
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return instance of BaseStyleComponent -> select style
     */
    private function outputSelectFloatingPositionField($value, $name, $disabled)
    {
        $positions = $this->getFloatingButtonPositionsFromLookups();

        return new BaseStyleComponent("select", array(
            "value" => $value ?: 'bottom-right',
            "name" => $name,
            "max" => 10,
            "live_search" => 0,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $positions
        ));
    }

    /**
     * Load floating button positions from the shared lookups table.
     * Falls back to a static default set if the DB lookup fails.
     *
     * @return array Array of ['value' => ..., 'text' => ...] items
     */
    private function getFloatingButtonPositionsFromLookups()
    {
        try {
            $lookups = $this->db->query_db(
                "SELECT lookup_code, lookup_value FROM lookups WHERE type_code = ? ORDER BY lookup_value",
                array('floatingButtonPositions')
            );
            if (!empty($lookups)) {
                $positions = array();
                foreach ($lookups as $row) {
                    $positions[] = array('value' => $row['lookup_code'], 'text' => $row['lookup_value']);
                }
                return $positions;
            }
        } catch (Exception $e) {
            // fall through to defaults
        }

        return array(
            array('value' => 'bottom-right', 'text' => 'Bottom Right'),
            array('value' => 'bottom-left', 'text' => 'Bottom Left'),
            array('value' => 'top-right', 'text' => 'Top Right'),
            array('value' => 'top-left', 'text' => 'Top Left'),
            array('value' => 'bottom-center', 'text' => 'Bottom Center'),
            array('value' => 'top-center', 'text' => 'Top Center')
        );
    }

    /**
     * Return a BaseStyleComponent object for floating position field
     * 
     * This hook is triggered for any field with type 'select-floating-button-position'.
     * The hook name 'field-floating-button-position-edit' matches the field type
     * 'select-floating-button-position' following SelfHelp's hook naming convention.
     * 
     * @param object $args Params passed to the method
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return a BaseStyleComponent object
     */
    private function returnSelectFloatingPositionField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        // This hook is triggered for all fields with type 'select-floating-button-position'
        // Check field name to ensure we're processing the right field
        if ($field['name'] === 'floating_button_position') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectFloatingPositionField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /* Public Methods *********************************************************/

    /**
     * Return a BaseStyleComponent object for edit mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldLlmModelEdit($args)
    {
        return $this->returnSelectLlmModelField($args, 0);
    }

    /**
     * Return a BaseStyleComponent object for view mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldLlmModelView($args)
    {
        return $this->returnSelectLlmModelField($args, 1);
    }

    /**
     * Return a BaseStyleComponent object for floating position edit mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFloatingPositionEdit($args)
    {
        return $this->returnSelectFloatingPositionField($args, 0);
    }

    /**
     * Return a BaseStyleComponent object for floating position view mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFloatingPositionView($args)
    {
        return $this->returnSelectFloatingPositionField($args, 1);
    }

    /**
     * Output select audio model field for speech-to-text
     * @param string $value
     * Value of the field
     * @param string $name
     * The name of the fields
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return instance of BaseStyleComponent -> select style
     */
    private function outputSelectAudioModelField($value, $name, $disabled)
    {
        try {
            $llmService = new LlmService($this->services);
            $models = $llmService->getAvailableModels(null, 'audio');
            $normalizedValue = $llmService->normalizeModelIdentifier($value);

            // Transform models array to select format
            $items = array(
                array('value' => '', 'text' => '-- Select Audio Model --')
            );
            foreach ($models as $model) {
                $items[] = array(
                    'value' => $model['id'],
                    'text' => $model['id']
                );
            }

            return new BaseStyleComponent("select", array(
                "value" => $normalizedValue,
                "name" => $name,
                "max" => 10,
                "live_search" => 1,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        } catch (Exception $e) {
            // Fallback in case of error
            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 10,
                "live_search" => 0,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => array(
                    array('value' => '', 'text' => '-- Select Audio Model --'),
                    array('value' => 'faster-whisper-large-v3', 'text' => 'faster-whisper-large-v3')
                )
            ));
        }
    }

    /**
     * Return a BaseStyleComponent object for audio model field
     * 
     * This hook is triggered for any field with type 'select-audio-model'.
     * The hook name 'field-audio-model-edit' matches the field type
     * 'select-audio-model' following SelfHelp's hook naming convention.
     * 
     * @param object $args Params passed to the method
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return a BaseStyleComponent object
     */
    private function returnSelectAudioModelField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] === 'speech_to_text_model') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectAudioModelField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /**
     * Return a BaseStyleComponent object for audio model edit mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldAudioModelEdit($args)
    {
        return $this->returnSelectAudioModelField($args, 0);
    }

    /**
     * Return a BaseStyleComponent object for audio model view mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldAudioModelView($args)
    {
        return $this->returnSelectAudioModelField($args, 1);
    }

    /* =========================================================================
     * LLM SCRIPT JOB INTEGRATION HOOKS
     * ========================================================================= */

    /**
     * Execute LLM script task when job_type is llm_script.
     * Hook on Task::execute_task (priority 11, coexists with R Serve at 10).
     *
     * When the script has async=1, spawns a background PHP process so the
     * form submission returns immediately. The background worker executes the
     * LLM API call, saves results, and triggers section refresh events.
     *
     * @param array $args Hook arguments including task_info, user, etc.
     * @return bool Success
     */
    public function execute_llm_task($args)
    {
        if ($args['task_info']['config']['type'] == ACTION_JOB_TYPE_LLM_SCRIPT) {
            $scriptService = new LlmScriptService($this->services);
            $script_info = $scriptService->fetch_script(
                $args['task_info']['config'][ACTION_JOB_TYPE_LLM_SCRIPT]
            );

            if (!$script_info) {
                $this->transaction->add_transaction(
                    transactionTypes_insert,
                    TRANSACTION_BY_LLM_SCRIPT,
                    null, null, null, false,
                    "The LLM script was not found; " . json_encode($args)
                );
                return false;
            }

            if (!empty($script_info['async'])) {
                return $this->execute_llm_script_async($args, $script_info);
            }

            return $this->execute_llm_script_from_job($args, $script_info);
        } else {
            return $this->execute_private_method($args);
        }
    }

    /**
     * Spawn a background PHP process to execute the LLM script asynchronously.
     * Returns true immediately so the form submission completes without waiting.
     *
     * @param array $args Hook arguments
     * @param array $script_info Script record from DB
     * @return bool Always true (job marked as done; real work happens in background)
     */
    private function execute_llm_script_async($args, $script_info)
    {
        $form_values = $this->user_input->get_form_values(
            $args['task_info']['config']['form_data']['form_fields']
        );

        $worker_args = [
            'script_id' => $script_info['id'],
            'form_values' => $form_values,
            'id_users' => $args['user']['id_users'],
            'id_scheduledJobs' => $args['user']['id_scheduledJobs'],
            'http_host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        ];

        $tmp_file = tempnam(sys_get_temp_dir(), 'llm_async_');
        if (!$tmp_file || file_put_contents($tmp_file, json_encode($worker_args)) === false) {
            error_log('LLM async: failed to write temp args file, falling back to sync');
            return $this->execute_llm_script_from_job($args, $script_info);
        }

        $worker_script = realpath(__DIR__ . '/../service/llm_async_worker.php');
        if (!$worker_script) {
            error_log('LLM async: worker script not found, falling back to sync');
            @unlink($tmp_file);
            return $this->execute_llm_script_from_job($args, $script_info);
        }

        $php_bin = BaseLlmService::resolvePhpCliBinary();

        $is_absolute = ($php_bin[0] === '/' || (strlen($php_bin) > 1 && $php_bin[1] === ':'));
        if ($is_absolute && !file_exists($php_bin)) {
            error_log('LLM async: PHP CLI binary not found at: ' . $php_bin . ', falling back to sync');
            @unlink($tmp_file);
            return $this->execute_llm_script_from_job($args, $script_info);
        }
        $php_flags = '-d apc.enable_cli=1';
        $log_file = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'llm_async_worker.log';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" '
                . '"' . $php_bin . '" '
                . $php_flags . ' '
                . '"' . $worker_script . '" '
                . '"' . $tmp_file . '"'
                . ' >> "' . $log_file . '" 2>&1';
        } else {
            $cmd = escapeshellarg($php_bin)
                . ' ' . $php_flags
                . ' ' . escapeshellarg($worker_script)
                . ' ' . escapeshellarg($tmp_file)
                . ' >> ' . escapeshellarg($log_file) . ' 2>&1 &';
        }

        error_log('LLM async: spawning command: ' . $cmd);

        $handle = popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
        } else {
            error_log('LLM async: popen failed, falling back to sync');
            @unlink($tmp_file);
            return $this->execute_llm_script_from_job($args, $script_info);
        }

        if (defined('DEBUG') && DEBUG) {
            error_log('LLM async: spawned background worker for script '
                . $script_info['name'] . ' (user=' . $args['user']['id_users'] . ')');
        }

        return true;
    }

    /**
     * Internal: execute an LLM script from a scheduled job (synchronous).
     *
     * @param array $args Hook arguments
     * @param array|null $script_info Pre-fetched script record (avoids double fetch)
     */
    private function execute_llm_script_from_job($args, $script_info = null)
    {
        $scriptService = new LlmScriptService($this->services);

        if (!$script_info) {
            $script_info = $scriptService->fetch_script(
                $args['task_info']['config'][ACTION_JOB_TYPE_LLM_SCRIPT]
            );
        }

        if (!$script_info) {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_SCRIPT,
                null, null, null, false,
                "The LLM script was not found; " . json_encode($args)
            );
            return false;
        }

        $form_values = $this->user_input->get_form_values($args['task_info']['config']['form_data']['form_fields']);
        $data_config = $script_info['data_config'] ? json_decode($script_info['data_config'], true) : null;

        $result = $scriptService->execute_llm_script(
            $script_info['script'],
            $data_config,
            $form_values,
            $args['user']['id_users'],
            $script_info['model'],
            $script_info['temperature'] !== null ? floatval($script_info['temperature']) : null,
            $script_info['max_tokens'] !== null ? intval($script_info['max_tokens']) : null,
            $script_info['id'],
            $script_info['name']
        );

        $save_success = $scriptService->save_llm_results(
            $result,
            $args['user']['id_users'],
            $args['user']['id_scheduledJobs'],
            $script_info['generated_id']
        );

        if ($save_success && $script_info['refresh_sections']) {
            $section_ids = json_decode($script_info['refresh_sections'], true);
            if (is_array($section_ids) && !empty($section_ids)) {
                $scriptService->insert_refresh_event(
                    $args['user']['id_users'],
                    $section_ids,
                    'llm_script_completed',
                    json_encode(array('generated_id' => $script_info['generated_id']))
                );
            }
        }

        return $save_success;
    }

    /**
     * Add llm_script option to the jobConfig JSON schema.
     * Hook on JobConfigView::get_json_schema (priority 11).
     */
    public function get_json_schema($args)
    {
        $llm_scripts = $this->db->fetch_table_as_select_values(LLM_TABLE_SCRIPTS, 'id', array('generated_id', 'name'));
        $enum_titles = array();
        $enum = array();
        foreach ($llm_scripts as $value) {
            $enum_titles[] = $value['text'];
            $enum[] = $value['value'];
        }

        $res = (string) $this->execute_private_method($args);
        $res = json_decode($res, true);

        $llm_script_field = array(
            "type" => "string",
            "options" => array(
                "grid_columns" => 12,
                "enum_titles" => $enum_titles,
                "dependencies" => array(
                    "job_type" => array(ACTION_JOB_TYPE_LLM_SCRIPT)
                )
            ),
            "title" => "LLM script",
            "description" => "Select LLM script to execute",
            "enum" => $enum
        );

        $res['definitions']['job_ref']['properties']['job_type']['enum'][] = ACTION_JOB_TYPE_LLM_SCRIPT;
        $res['definitions']['job_ref']['properties']['job_type']['options']['enum_titles'][] = "LLM script";
        $res['definitions']['job_ref']['properties'][ACTION_JOB_TYPE_LLM_SCRIPT] = $llm_script_field;

        return json_encode($res);
    }

    /**
     * Build task config for LLM script jobs.
     * Hook on UserInput::get_task_config (priority 11).
     */
    public function get_task_config($args)
    {
        $job = $args['job'];
        if ($job['job_type'] == ACTION_JOB_TYPE_LLM_SCRIPT) {
            $script_name = '';
            $script_id = isset($job[ACTION_JOB_TYPE_LLM_SCRIPT]) ? $job[ACTION_JOB_TYPE_LLM_SCRIPT] : null;
            if ($script_id) {
                $scriptService = new LlmScriptService($this->services);
                $script_info = $scriptService->fetch_script($script_id);
                if ($script_info) {
                    $script_name = $script_info['name'];
                }
            }
            $description = isset($job['job_name']) && $job['job_name']
                ? $job['job_name']
                : 'LLM Script' . ($script_name ? ': ' . $script_name : '')
                    . ' (form: ' . $args['form_data']['form_name'] . ')';
            return array(
                "type" => $job[ACTION_JOB_TYPE],
                "description" => $description,
                ACTION_JOB_TYPE_LLM_SCRIPT => $job[ACTION_JOB_TYPE_LLM_SCRIPT],
                "form_data" => $args['form_data'],
                "id_users" => $_SESSION['id_user'] ?? null
            );
        } else {
            return $this->execute_private_method($args);
        }
    }

    /**
     * Return jobTypes_task for llm_script job type.
     * Hook on UserInput::get_job_type (priority 11).
     */
    public function get_job_type($args)
    {
        $res = $this->execute_private_method($args);
        if ($args['job']['job_type'] == ACTION_JOB_TYPE_LLM_SCRIPT) {
            return jobTypes_task;
        }
        return $res;
    }

    /**
     * Add moduleLlmScriptMode to sensible pages list.
     * Hook on Router::get_sensible_pages.
     */
    public function get_sensible_pages($args)
    {
        $res = $this->execute_private_method($args);
        $res[] = LLM_SCRIPTS_PAGE_KEYWORD;
        $res[] = LLM_MEMORY_PAGE_KEYWORD;
        return $res;
    }

    /**
     * Get the plugin version
     */
    public function get_plugin_db_version($plugin_name = 'llm')
    {
        return parent::get_plugin_db_version($plugin_name);
    }

    /* LLM Form Field Hooks ***************************************************/

    /**
     * Output select LLM result placement field (edit mode).
     */
    /**
     * Build a CMS select component for LLM result placement (top/bottom/left/right).
     *
     * @param string $value    Current placement value.
     * @param string $name     Form field name attribute.
     * @param int    $disabled 1 to disable editing, 0 for editable.
     * @return BaseStyleComponent Select component.
     */
    private function outputSelectLlmResultPlacementField($value, $name, $disabled)
    {
        $placements = [
            ['value' => 'top', 'text' => 'Top'],
            ['value' => 'bottom', 'text' => 'Bottom'],
            ['value' => 'left', 'text' => 'Left'],
            ['value' => 'right', 'text' => 'Right'],
        ];
        return new BaseStyleComponent("select", array(
            "value" => $value,
            "name" => $name,
            "is_required" => false,
            "disabled" => $disabled,
            "items" => $placements
        ));
    }

    /**
     * Hook handler that injects the result-placement select into the CMS field output.
     *
     * @param array $args     Hook arguments with field metadata.
     * @param int   $disabled 1 for view mode, 0 for edit mode.
     * @return mixed Modified component tree with placement select appended.
     */
    private function returnSelectLlmResultPlacementField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (!isset($field['type']) || $field['type'] !== 'select-llm-result-placement') {
            return $res;
        }

        $value = isset($field['content']) && $field['content'] !== '' ? $field['content'] : 'bottom';
        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = $this->outputSelectLlmResultPlacementField($value, $field_name_prefix . "[content]", $disabled);

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /** @return mixed CMS edit-mode output for the LLM result placement field. */
    public function outputFieldLlmResultPlacementEdit($args)
    {
        return $this->returnSelectLlmResultPlacementField($args, 0);
    }

    /** @return mixed CMS view-mode output for the LLM result placement field. */
    public function outputFieldLlmResultPlacementView($args)
    {
        return $this->returnSelectLlmResultPlacementField($args, 1);
    }

    /**
     * Output select LLM result panel type field (edit mode).
     */
    /**
     * Build a CMS select component for LLM result panel type (default/card/modal/collapse).
     *
     * @param string $value    Current panel value.
     * @param string $name     Form field name attribute.
     * @param int    $disabled 1 to disable editing, 0 for editable.
     * @return BaseStyleComponent Select component.
     */
    private function outputSelectLlmResultPanelField($value, $name, $disabled)
    {
        $panels = [
            ['value' => 'default', 'text' => 'Default (Inline)'],
            ['value' => 'card', 'text' => 'Card'],
            ['value' => 'modal', 'text' => 'Modal'],
            ['value' => 'collapse', 'text' => 'Collapse'],
        ];
        return new BaseStyleComponent("select", array(
            "value" => $value,
            "name" => $name,
            "is_required" => false,
            "disabled" => $disabled,
            "items" => $panels
        ));
    }

    /**
     * Hook handler that injects the result-panel select into the CMS field output.
     *
     * @param array $args     Hook arguments with field metadata.
     * @param int   $disabled 1 for view mode, 0 for edit mode.
     * @return mixed Modified component tree with panel select appended.
     */
    private function returnSelectLlmResultPanelField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (!isset($field['type']) || $field['type'] !== 'select-llm-result-panel') {
            return $res;
        }

        $value = isset($field['content']) && $field['content'] !== '' ? $field['content'] : 'card';
        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = $this->outputSelectLlmResultPanelField($value, $field_name_prefix . "[content]", $disabled);

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /** @return mixed CMS edit-mode output for the LLM result panel field. */
    public function outputFieldLlmResultPanelEdit($args)
    {
        return $this->returnSelectLlmResultPanelField($args, 0);
    }

    /** @return mixed CMS view-mode output for the LLM result panel field. */
    public function outputFieldLlmResultPanelView($args)
    {
        return $this->returnSelectLlmResultPanelField($args, 1);
    }

    /* Prompt Registry Field Hooks ********************************************/

    /**
     * Render the React-based prompt field shell for CMS.
     *
     * @param array $args
     * @param bool $disabled
     * @return object
     */
    private function returnLlmPromptField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (($field['type'] ?? '') !== 'llm_prompt') {
            return $res;
        }

        $cms_model = $this->get_private_property(array(
            'hookedClassInstance' => $args['hookedClassInstance'],
            'propertyName' => 'model'
        ));

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $uid = 'llm_prompt_' . md5($field_name_prefix);
        $endpoint = $this->get_link_url(LLM_PROMPT_LAB_PAGE_KEYWORD);
        if (!$endpoint || strpos($endpoint, '[AjaxLlmPromptLab:class]') !== false || strpos($endpoint, '[dispatch:method]') !== false) {
            $endpoint = '/request/AjaxLlmPromptLab/dispatch';
        }

        $templateComponent = new BaseStyleComponent("template", array(
            "path" => __DIR__ . "/tpl_prompt_field.php",
            "items" => array(
                "uid" => $uid,
                "fieldNamePrefix" => $field_name_prefix,
                "inputName" => $field_name_prefix . "[content]",
                "metaInputName" => $field_name_prefix . "[meta]",
                "jsonConfig" => json_encode(array(
                    'endpoint' => $endpoint,
                    'csrfToken' => $this->resolveCsrfToken(),
                    'disabled' => $disabled ? 1 : 0,
                    'ownerType' => LLM_PROMPT_OWNER_STYLE_FIELD,
                    'ownerId' => $cms_model->get_active_section_id(),
                    'pageId' => $cms_model->get_active_page_id(),
                    'promptSlot' => $field['name'] ?? '',
                    'languageId' => $field['id_language'] ?? 1,
                    'title' => $field['label'] ?? ucfirst(str_replace('_', ' ', $field['name'] ?? 'Prompt')),
                    'styleName' => $field['style_name'] ?? '',
                    'sectionName' => $field['section_name'] ?? ''
                ), JSON_UNESCAPED_SLASHES),
                "contentValue" => $field['content'] ?? '',
                "metaValue" => array_key_exists('meta', $field) ? $field['meta'] : '',
                "disabled" => $disabled,
                "fieldId" => $field['id'] ?? '',
                "fieldType" => $field['type'] ?? '',
                "fieldRelation" => $field['relation'] ?? '',
            )
        ));

        if ($templateComponent && $res) {
            $res->get_view()->set_children(array($templateComponent));
        }

        return $res;
    }

    /**
     * Resolve CSRF token from known core session keys.
     *
     * @return string
     */
    private function resolveCsrfToken()
    {
        if (!empty($_SESSION['csrf_token'])) {
            return (string)$_SESSION['csrf_token'];
        }
        if (!empty($_SESSION['token'])) {
            return (string)$_SESSION['token'];
        }
        if (!empty($_SESSION['security_token'])) {
            return (string)$_SESSION['security_token'];
        }

        return '';
    }

    /**
     * Output custom prompt field in edit mode.
     *
     * @param array $args
     * @return object
     */
    public function outputFieldLlmPromptEdit($args)
    {
        return $this->returnLlmPromptField($args, false);
    }

    /**
     * Output custom prompt field in view mode.
     *
     * @param array $args
     * @return object
     */
    public function outputFieldLlmPromptView($args)
    {
        return $this->returnLlmPromptField($args, true);
    }

    /**
     * Sync prompt version history after the normal CMS field save completed.
     *
     * @param array $args
     * @return mixed
     */
    public function syncPromptVersionOnCmsSave($args)
    {
        $res = $this->execute_private_method($args);

        if ($res !== true) {
            return $res;
        }

        $field_id = (int)$this->get_param_by_name($args, 'id');
        if ($field_id <= 0) {
            return $res;
        }

        $field_info = $this->services->get_db()->query_db_first(
            "SELECT f.name, ft.name AS type
             FROM fields f
             INNER JOIN fieldType ft ON ft.id = f.id_type
             WHERE f.id = :id",
            array(':id' => $field_id)
        );

        if (!$field_info) {
            return $res;
        }

        $field_type = $field_info['type'] ?? '';
        if ($field_type === 'llm_prompt') {
            return $this->syncStyleFieldPromptOnCmsSave($args, $res, $field_id, $field_info);
        }

        return $res;
    }

    /**
     * Sync a normal llm_prompt field save into prompt-lab version history.
     *
     * @param array $args
     * @param mixed $res
     * @param int $field_id
     * @param array $field_info
     * @return mixed
     */
    private function syncStyleFieldPromptOnCmsSave($args, $res, $field_id, $field_info)
    {
        $relation = $this->get_param_by_name($args, 'relation');
        if ($relation !== RELATION_SECTION_FIELD) {
            return $res;
        }

        $cms_model = $args['hookedClassInstance'];
        $section_id = $cms_model->get_active_section_id();
        if (!$section_id) {
            return $res;
        }

        $registry = new LlmPromptRegistryService($this->services);
        $profile_service = new LlmPromptExecutionProfileService($this->services);
        $descriptor = array(
            'owner_type' => LLM_PROMPT_OWNER_STYLE_FIELD,
            'owner_id' => $section_id,
            'prompt_slot' => $field_info['name'],
            'id_languages' => (int)$this->get_param_by_name($args, 'id_language'),
            'title' => ucfirst(str_replace('_', ' ', $field_info['name']))
        );

        $profile = $profile_service->resolveExecutionProfile($descriptor);
        $runtime_values = $profile_service->getStyleFieldValues(
            $section_id,
            $descriptor['id_languages'],
            $profile_service->getCompanionFieldNames($profile)
        );

        foreach ($this->getPostedRuntimeOverrides($profile_service->getCompanionFieldNames($profile)) as $key => $value) {
            $runtime_values[$key] = $value;
        }

        $content = (string)$this->get_param_by_name($args, 'content');
        $meta = $args['meta'] ?? null;
        $sync = $registry->syncFieldSave($descriptor, $field_id, $content, $meta, $runtime_values);

        $cms_model->update_section_fields_db(
            $field_id,
            $descriptor['id_languages'],
            (int)$this->get_param_by_name($args, 'id_gender'),
            $content,
            $section_id,
            $sync['meta_json']
        );

        return $res;
    }

    /**
     * Ensure prompt field CSS is loaded on CMS pages.
     *
     * @param array $args
     * @return array
     */
    public function addCmsPromptCssIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $css_file = __DIR__ . "/../../css/ext/llm-prompt-field.css";
        if (file_exists($css_file) && !in_array($css_file, $res, true)) {
            $res[] = $css_file;
        }

        return $res;
    }

    /**
     * Ensure prompt field JS is loaded on CMS pages.
     *
     * @param array $args
     * @return array
     */
    public function addCmsPromptJsIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $js_file = __DIR__ . "/../../js/ext/llm-prompt-field.umd.js";
        if (file_exists($js_file) && !in_array($js_file, $res, true)) {
            $res[] = $js_file;
        }

        return $res;
    }

    /**
     * Read unsaved companion field values from the current CMS POST payload.
     *
     * @param array $field_names
     * @return array
     */
    private function getPostedRuntimeOverrides($field_names)
    {
        $result = array();
        $posted_fields = $_POST['fields'] ?? array();
        if (!is_array($posted_fields)) {
            return $result;
        }

        foreach ($field_names as $field_name) {
            if (!isset($posted_fields[$field_name]) || !is_array($posted_fields[$field_name])) {
                continue;
            }

            foreach ($posted_fields[$field_name] as $by_language) {
                if (!is_array($by_language)) {
                    continue;
                }

                foreach ($by_language as $by_gender) {
                    if (!is_array($by_gender)) {
                        continue;
                    }

                    if (array_key_exists('content', $by_gender)) {
                        $result[$field_name] = $by_gender['content'];
                        break 2;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Persist CMS field content without re-entering CmsModel::update_db hooks.
     *
     * @param object $cms_model
     * @param int $field_id
     * @param int $language_id
     * @param int $gender_id
     * @param string $relation
     * @param string $content
     * @param string|null $meta
     * @return void
     */
    private function persistCmsFieldContent($cms_model, $field_id, $language_id, $gender_id, $relation, $content, $meta = null)
    {
        if ($relation === RELATION_SECTION_FIELD) {
            $cms_model->update_section_fields_db($field_id, $language_id, $gender_id, $content, null, $meta);
            return;
        }

        if ($relation === RELATION_PAGE_FIELD) {
            $insert = array(
                'content' => $content,
                'id_fields' => $field_id,
                'id_languages' => $language_id,
                'id_pages' => $cms_model->get_active_page_id()
            );
            $update = array('content' => $content);
            $this->db->insert('pages_fields_translation', $insert, $update);
        }
    }

    /* LLM API Keys Manager Hooks *********************************************/

    /**
     * Hook handler for the llm_api_keys field in CMS.
     * Uses the `template` BaseStyleComponent to render a PHP template.
     * Replaces the default field children so the raw JSON is never shown.
     *
     * @param array $args Hook arguments
     * @param bool $disabled Whether the field is read-only
     * @return object BaseStyleComponent
     */
    private function returnLlmApiKeysField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] !== 'llm_api_keys') {
            return $res;
        }

        $entries = json_decode($field['content'] ?? '[]', true);
        if (!is_array($entries)) {
            $entries = [];
        }

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $inputName = $field_name_prefix . "[content]";
        $uid = 'llm_api_keys_' . md5($inputName);

        $templateComponent = new BaseStyleComponent("template", array(
            "path" => __DIR__ . "/tpl_api_keys_manager.php",
            "items" => array(
                "uid" => $uid,
                "fieldNamePrefix" => $field_name_prefix,
                "inputName" => $inputName,
                "jsonValue" => json_encode($entries, JSON_UNESCAPED_SLASHES),
                "disabled" => $disabled,
                "fieldId" => $field['id'] ?? '',
                "fieldType" => $field['type'] ?? '',
                "fieldRelation" => $field['relation'] ?? '',
                "fieldMeta" => array_key_exists('meta', $field) ? $field['meta'] : null
            )
        ));

        if ($templateComponent && $res) {
            $res->get_view()->set_children(array($templateComponent));
        }

        return $res;
    }

    /**
     * Output custom LLM API keys manager in edit mode
     * @param array $args Hook arguments
     * @return object BaseStyleComponent
     */
    public function outputFieldLlmApiKeysEdit($args)
    {
        return $this->returnLlmApiKeysField($args, false);
    }

    /**
     * Output custom LLM API keys manager in view mode
     * @param array $args Hook arguments
     * @return object BaseStyleComponent
     */
    public function outputFieldLlmApiKeysView($args)
    {
        return $this->returnLlmApiKeysField($args, true);
    }

    /**
     * Ensure API keys manager CSS is loaded on CMS pages.
     * Needed in DEBUG mode where plugin ext assets are not auto-collected.
     *
     * @param array $args Hook arguments
     * @return array
     */
    public function addCmsApiKeysCssIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $css_file = __DIR__ . "/../../css/ext/llm-apikeys.css";
        if (file_exists($css_file) && !in_array($css_file, $res, true)) {
            $res[] = $css_file;
        }

        return $res;
    }

    /**
     * Ensure API keys manager JS is loaded on CMS pages.
     * Needed in DEBUG mode where plugin ext assets are not auto-collected.
     *
     * @param array $args Hook arguments
     * @return array
     */
    public function addCmsApiKeysJsIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $js_file = __DIR__ . "/../../js/ext/llm-apikeys.umd.js";
        if (file_exists($js_file) && !in_array($js_file, $res, true)) {
            $res[] = $js_file;
        }

        return $res;
    }

    /**
     * Output select memory storage mode field.
     */
    /**
     * Build a CMS select component for the memory storage mode, loading options from lookups.
     *
     * @param string $value    Current storage mode lookup code.
     * @param string $name     Form field name attribute.
     * @param int    $disabled 1 to disable editing, 0 for editable.
     * @return BaseStyleComponent Select component with storage mode options.
     */
    private function outputSelectMemoryStorageModeField($value, $name, $disabled)
    {
        try {
            $lookups = $this->db->query_db(
                "SELECT lookup_code, lookup_value, lookup_description FROM lookups WHERE type_code = ? ORDER BY lookup_value",
                array('llmMemoryStorageMode')
            );
            $items = array();
            foreach ($lookups as $row) {
                $items[] = array(
                    'value' => $row['lookup_code'],
                    'text' => $row['lookup_value'] . ' - ' . $row['lookup_description']
                );
            }
        } catch (Exception $e) {
            $items = array(
                array('value' => 'memory_storage_both', 'text' => 'both'),
                array('value' => 'memory_storage_record', 'text' => 'record'),
                array('value' => 'memory_storage_log', 'text' => 'log'),
            );
        }

        return new BaseStyleComponent("select", array(
            "value" => $value ?: 'memory_storage_both',
            "name" => $name,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $items
        ));
    }

    /**
     * Hook handler that injects the memory storage mode select into the CMS field output.
     *
     * @param array $args     Hook arguments with field metadata.
     * @param int   $disabled 1 for view mode, 0 for edit mode.
     * @return mixed Modified component tree with storage mode select appended.
     */
    private function returnSelectMemoryStorageModeField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] === 'llm_memory_storage_mode') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectMemoryStorageModeField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /** @return mixed CMS edit-mode output for the memory storage mode field. */
    public function outputFieldMemoryStorageModeEdit($args)
    {
        return $this->returnSelectMemoryStorageModeField($args, 0);
    }

    /** @return mixed CMS view-mode output for the memory storage mode field. */
    public function outputFieldMemoryStorageModeView($args)
    {
        return $this->returnSelectMemoryStorageModeField($args, 1);
    }

    /* =========================================================================
     * LLM MEMORY JOB INTEGRATION HOOKS
     * ========================================================================= */

    /**
     * Execute LLM memory update task when job_type is llm_memory_update.
     * Hook on Task::execute_task (priority 12).
     *
     * @param array $args Hook arguments
     * @return bool
     */
    public function execute_memory_task($args)
    {
        if (($args['task_info']['config']['type'] ?? '') !== ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
            return $this->execute_private_method($args);
        }

        $config = $args['task_info']['config'];
        $user_id = $args['user']['id_users'] ?? null;

        if (!$user_id) {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_MEMORY,
                null, null, null, false,
                "LLM Memory task: No user ID in job args; " . json_encode($args)
            );
            return false;
        }

        $rule_ids = $config['memory_rule_id'] ?? $config['memory_rule_ids'] ?? [];
        if (!is_array($rule_ids)) {
            $rule_ids = array_filter(array_map('trim', explode(',', (string)$rule_ids)));
        }
        $rule_ids = array_values(array_filter(array_map('intval', $rule_ids)));

        $rule_keys = $config['memory_rule_keys'] ?? [];
        if (is_string($rule_keys)) {
            $rule_keys = array_filter(array_map('trim', explode(',', $rule_keys)));
        }

        $run_async = !empty($config['run_async']);
        $form_fields = $config['form_data']['form_fields'] ?? [];

        $trigger_service = new LlmMemoryTriggerService($this->services);
        $normalized = $trigger_service->normalizeFormActionPayload([
            'form_fields'  => $form_fields,
            'form_name'    => $config['form_data']['form_name'] ?? '',
            'table_name'   => $config['form_data']['table_name'] ?? '',
            'trigger_type' => $config['trigger_type'] ?? 'finished',
            'record_id'    => $config['form_data']['record_id'] ?? null,
        ], $user_id);

        if (!empty($config['memory_key_override'])) {
            $normalized['memory_key_override'] = $config['memory_key_override'];
        }
        if (!empty($config['force_storage_mode'])) {
            $normalized['force_storage_mode'] = $config['force_storage_mode'];
        }

        $rule_overrides = array();
        if (!empty($config['execution_mode'])) {
            $rule_overrides['execution_mode'] = $config['execution_mode'];
        }
        if (!empty($config['field_mapping']) && is_array($config['field_mapping'])) {
            $rule_overrides['field_mapping'] = $config['field_mapping'];
        }
        if (!empty($config['prompt_version_override'])) {
            $rule_overrides['prompt_version_override'] = (int)$config['prompt_version_override'];
        }

        if (!empty($rule_ids)) {
            $dispatched = $trigger_service->dispatchForRuleIds($rule_ids, $normalized, $run_async, $rule_overrides);
        } elseif (!empty($rule_keys)) {
            $dispatched = $trigger_service->dispatchForRuleKeys($rule_keys, $normalized, $run_async, $rule_overrides);
        } else {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_MEMORY,
                null,
                null,
                null,
                false,
                "LLM Memory task skipped: no explicit rule configured; " . json_encode($config)
            );
            return true;
        }

        if (defined('DEBUG') && DEBUG) {
            error_log('LLM Memory task: dispatched rules: ' . implode(', ', $dispatched) . ' for user ' . $user_id);
        }

        return true;
    }

    /**
     * Add llm_memory_update option to jobConfig JSON schema.
     * Hook on JobConfigView::get_json_schema (priority 12).
     */
    public function get_memory_json_schema($args)
    {
        $res = (string)$this->execute_private_method($args);
        $res = json_decode($res, true);

        $config_service = new LlmMemoryConfigService($this->services);
        $rules = $config_service->getRules();

        $rule_titles = array();
        $rule_ids = array();
        foreach ($rules as $rule) {
            if (($rule['source_type'] ?? '') !== LLM_MEMORY_SOURCE_FORM_ACTION) {
                continue;
            }
            $label = trim((string)($rule['label'] ?? '')) ?: ('Rule #' . (int)($rule['id'] ?? 0));
            $rule_titles[] = $label . (!empty($rule['enabled']) ? '' : ' (disabled)');
            $rule_ids[] = (string)(int)($rule['id'] ?? 0);
        }
        $default_rule_id = !empty($rule_ids) ? $rule_ids[0] : '';

        $dep = array(
            "job_type" => array(ACTION_JOB_TYPE_LLM_MEMORY_UPDATE)
        );

        $memory_rule_field = array(
            "type" => "string",
            "enum" => $rule_ids,
            "options" => array(
                "grid_columns" => 12,
                "dependencies" => $dep,
                "enum_titles" => $rule_titles
            ),
            "default" => $default_rule_id,
            "title" => "Memory Rule",
            "description" => "Choose which memory rule this action should run."
        );

        $run_async_field = array(
            "type" => "boolean",
            "format" => "checkbox",
            "default" => true,
            "options" => array("grid_columns" => 12, "dependencies" => $dep),
            "title" => "Run async",
            "description" => "When checked, LLM summarization runs in a background worker."
        );

        $res['definitions']['job_ref']['properties']['job_type']['enum'][] = ACTION_JOB_TYPE_LLM_MEMORY_UPDATE;
        $res['definitions']['job_ref']['properties']['job_type']['options']['enum_titles'][] = "LLM Memory Update";
        $res['definitions']['job_ref']['properties']['memory_rule_id'] = $memory_rule_field;
        $res['definitions']['job_ref']['properties']['run_async'] = $run_async_field;

        return json_encode($res);
    }

    /**
     * Build task config for LLM memory update jobs.
     * Hook on UserInput::get_task_config (priority 12).
     */
    public function get_memory_task_config($args)
    {
        $job = $args['job'];
        if (($job['job_type'] ?? '') !== ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
            return $this->execute_private_method($args);
        }

        $description = !empty($job['job_name'])
            ? $job['job_name']
            : 'LLM Memory Update (form: ' . ($args['form_data']['form_name'] ?? 'unknown') . ')';

        $field_mapping = array();
        if (!empty($job['field_mapping'])) {
            $decoded_mapping = json_decode($job['field_mapping'], true);
            if (is_array($decoded_mapping)) {
                $field_mapping = $decoded_mapping;
            }
        }

        return array(
            "type" => $job[ACTION_JOB_TYPE],
            "description" => $description,
            "memory_rule_id" => (string)($job['memory_rule_id'] ?? ''),
            "memory_rule_keys" => $job['memory_rule_keys'] ?? '',
            "memory_key_override" => $job['memory_key_override'] ?? '',
            "force_storage_mode" => $job['force_storage_mode'] ?? '',
            "run_async" => !array_key_exists('run_async', $job) || !empty($job['run_async']),
            "execution_mode" => $job['execution_mode'] ?? '',
            "field_mapping" => $field_mapping,
            "prompt_version_override" => !empty($job['prompt_version_override']) ? (int)$job['prompt_version_override'] : 0,
            "trigger_type" => $job['trigger_type'] ?? 'finished',
            "form_data" => $args['form_data'],
            "id_users" => $_SESSION['id_user'] ?? null
        );
    }

    /**
     * Return jobTypes_task for llm_memory_update job type.
     * Hook on UserInput::get_job_type (priority 12).
     */
    public function get_memory_job_type($args)
    {
        $res = $this->execute_private_method($args);
        if (($args['job']['job_type'] ?? '') === ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
            return jobTypes_task;
        }
        return $res;
    }

    /* =========================================================================
     * LOGIN / PROFILE MEMORY TRIGGER HOOKS
     * ========================================================================= */

    /**
     * Trigger memory update after successful login.
     * Hook on Login::update_timestamp (hook_overwrite_return, priority 20).
     * Wraps the original method: runs it first, then dispatches memory update
     * only if the user session exists (indicating successful login).
     */
    public function onLoginMemoryTrigger($args)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        $previous_last_login = '';
        $target_user_name = '';
        if ($user_id > 0) {
            $prev = $this->db->query_db_first(
                "SELECT name, last_login FROM users WHERE id = :id LIMIT 1",
                array(':id' => $user_id)
            );
            $target_user_name = $prev['name'] ?? '';
            $previous_last_login = $prev['last_login'] ?? '';
        }

        $res = $this->execute_private_method($args);
        if ($res === false) {
            return $res;
        }

        try {
            if (!$user_id) {
                return $res;
            }

            $config_service = new LlmMemoryConfigService($this->services);
            if (!$config_service->isMemoryEnabled()) {
                return $res;
            }

            $trigger_service = new LlmMemoryTriggerService($this->services, $config_service);
            $normalized = $trigger_service->normalizeLoginPayload($user_id, $target_user_name, $previous_last_login);
            $trigger_service->dispatchMemoryUpdate($normalized);
        } catch (Exception $e) {
            error_log('LLM Memory: login trigger failed: ' . $e->getMessage());
        }

        return $res;
    }

    /**
     * Trigger memory update after profile name change.
     * Hook on ProfileModel::change_user_name (hook_overwrite_return, priority 20).
     * Captures the old name before running the original method and dispatches
     * only after a confirmed successful rename.
     */
    public function onProfileNameChangeMemoryTrigger($args)
    {
        $old_name = '';
        try {
            $old_name = $this->db->fetch_user_name() ?: '';
        } catch (Exception $e) {
            // best-effort only
        }

        $res = $this->execute_private_method($args);
        if ($res !== true) {
            return $res;
        }

        try {
            $config_service = new LlmMemoryConfigService($this->services);
            if (!$config_service->isMemoryEnabled()) {
                return $res;
            }

            $user_id = (int)($_SESSION['id_user'] ?? 0);
            if ($user_id <= 0) {
                return $res;
            }

            $new_name = (string)($args['original_parameters'][0] ?? '');

            $trigger_service = new LlmMemoryTriggerService($this->services, $config_service);
            $normalized = $trigger_service->normalizeProfileNamePayload($user_id, $old_name, $new_name);
            $trigger_service->dispatchMemoryUpdate($normalized);
        } catch (Exception $e) {
            error_log('LLM Memory: profile name change trigger failed: ' . $e->getMessage());
        }

        return $res;
    }


}
?>
