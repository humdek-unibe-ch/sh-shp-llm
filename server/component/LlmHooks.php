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

            $items = array();
            foreach ($models as $model) {
                $items[] = array(
                    'value' => $model['id'],
                    'text' => $model['id']
                );
            }

            return new BaseStyleComponent("select", array(
                "value" => $value,
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
        // Define available positions for the floating button
        $positions = array(
            array('value' => 'bottom-right', 'text' => 'Bottom Right'),
            array('value' => 'bottom-left', 'text' => 'Bottom Left'),
            array('value' => 'top-right', 'text' => 'Top Right'),
            array('value' => 'top-left', 'text' => 'Top Left'),
            array('value' => 'bottom-center', 'text' => 'Bottom Center'),
            array('value' => 'top-center', 'text' => 'Top Center')
        );

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
            require_once __DIR__ . "/../service/LlmSpeechToTextService.php";
            $speechService = new LlmSpeechToTextService($this->services);
            $models = $speechService->getAvailableAudioModels();

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
                "value" => $value,
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

    /**
     * Build the LLM admin panel with quick links.
     */
    private function outputLlmPanel()
    {
        return new BaseStyleComponent("card", array(
            "type" => "secondary",
            "is_expanded" => true,
            "is_collapsible" => true,
            "title" => "LLM Panel",
            "children" => array(
                new BaseStyleComponent("button", array(
                    "label" => "LLM Conversations",
                    "url" => $this->get_link_url(LLM_ADMIN_PAGE_KEYWORD),
                    "type" => "secondary",
                    "css" => "btn-sm mr-3"
                )),
                new BaseStyleComponent("button", array(
                    "label" => "LLM Scripts",
                    "url" => $this->get_link_url(LLM_SCRIPTS_PAGE_KEYWORD),
                    "type" => "secondary",
                    "css" => "btn-sm"
                ))
            )
        ));
    }

    /**
     * Add LLM panel into CMS field rendering.
     */
    public function outputFieldPanel($args)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);
        if ($field['name'] == 'llm_panel') {
            $panel = $this->outputLlmPanel();
            if ($panel && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $panel;
                $res->get_view()->set_children($children);
            }
        }
        return $res;
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

        // PHP_BINARY returns httpd when running as Apache module;
        // detect the CLI binary via multiple strategies
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            $php_bin = PHP_BINARY;
        } else {
            $php_bin = $this->find_php_cli_binary();
        }

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
     * Locate the PHP CLI binary when running under a web SAPI (Apache/FPM).
     * Tries multiple strategies: `which`, common paths, phpinfo-based hints.
     *
     * @return string Path to php CLI binary, or 'php' as last-resort fallback
     */
    private function find_php_cli_binary()
    {
        $is_win = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $bin_name = $is_win ? 'php.exe' : 'php';

        // Strategy 1: `which php` / `where php` (most reliable on Linux)
        if (!$is_win) {
            foreach (['command -v php', 'which php'] as $lookup_cmd) {
                $which = @shell_exec($lookup_cmd . ' 2>/dev/null');
                if ($which) {
                    $which = trim($which);
                    if ($which !== '' && file_exists($which)) {
                        return $which;
                    }
                }
            }
        } else {
            $where = @shell_exec('where php 2>NUL');
            if ($where) {
                $first_line = trim(strtok($where, "\n"));
                if ($first_line !== '' && file_exists($first_line)) {
                    return $first_line;
                }
            }
        }

        // Strategy 2: derive from extension_dir (works on Windows / some Linux)
        $ext_dir = ini_get('extension_dir');
        if ($ext_dir) {
            $php_dir = dirname(rtrim($ext_dir, '/\\'));
            $candidate = $php_dir . DIRECTORY_SEPARATOR . $bin_name;
            if (file_exists($candidate)) {
                return $candidate;
            }
            // On some Linux, extension_dir is /usr/lib/php/YYYYMMDD;
            // go one level higher: /usr/lib/php/ -> try /usr/bin/php
            $candidate2 = dirname($php_dir) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $bin_name;
            if (file_exists($candidate2)) {
                return $candidate2;
            }
        }

        // Strategy 3: well-known Linux/macOS paths (incl. ondrej/php PPA layout)
        if (!$is_win) {
            $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $common_paths = [
                '/usr/bin/php',
                '/usr/bin/php' . $ver,
                '/usr/bin/php' . PHP_MAJOR_VERSION,
                '/usr/local/bin/php',
                '/usr/local/bin/php' . $ver,
            ];
            foreach ($common_paths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return $bin_name;
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
                "id_users" => $_SESSION['id_user']
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

    public function outputFieldLlmResultPlacementEdit($args)
    {
        return $this->returnSelectLlmResultPlacementField($args, 0);
    }

    public function outputFieldLlmResultPlacementView($args)
    {
        return $this->returnSelectLlmResultPlacementField($args, 1);
    }

    /**
     * Output select LLM result panel type field (edit mode).
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

    public function outputFieldLlmResultPanelEdit($args)
    {
        return $this->returnSelectLlmResultPanelField($args, 0);
    }

    public function outputFieldLlmResultPanelView($args)
    {
        return $this->returnSelectLlmResultPanelField($args, 1);
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
                "inputName" => $inputName,
                "jsonValue" => json_encode($entries, JSON_UNESCAPED_SLASHES),
                "disabled" => $disabled
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

}
?>
