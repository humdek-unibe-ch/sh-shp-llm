<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/base/BaseLlmService.php";
require_once __DIR__ . "/LlmService.php";
require_once __DIR__ . "/LlmPromptRegistryService.php";

/**
 * Service for LLM Script execution.
 * Handles script CRUD, execution (sync/async), and result saving.
 * Follows the same pattern as R Serve's ModuleRModel.
 */
class LlmScriptService extends BaseLlmService
{
    /** @var LlmService */
    private $llmService;

    /** @var object UserInput service */
    private $user_input;

    /** @var object Transaction service */
    private $transaction;

    /** @var LlmPromptRegistryService */
    private $prompt_registry_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->llmService = new LlmService($services);
        $this->user_input = $services->get_user_input();
        $this->transaction = $services->get_transaction();
        $this->prompt_registry_service = new LlmPromptRegistryService($services);
    }

    /**
     * Fetch a script by ID
     * @param int $sid Script ID
     * @return array|false Script record or false
     */
    public function fetch_script($sid)
    {
        return $this->db->query_db_first(
            "SELECT * FROM llm_scripts WHERE id = :id",
            array(':id' => $sid)
        );
    }

    /**
     * Fetch all scripts
     * @return array All script records
     */
    public function get_scripts()
    {
        return $this->db->select_table(LLM_TABLE_SCRIPTS);
    }

    /**
     * Create a dataTable entry for a new script.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to be safe against re-runs.
     */
    private function create_dataTables_entry($name, $displayName)
    {
        $res = $this->db->insert(
            'dataTables',
            array(
                "displayName" => $displayName,
                'name' => $name
            ),
            array(
                "displayName" => $displayName
            )
        );
        $this->db->clear_cache($this->db->get_cache()::CACHE_TYPE_SECTIONS);
        return $res;
    }

    /**
     * Update an existing dataTable's displayName by its name (generated_id).
     * Uses a direct UPDATE to avoid accidentally creating duplicate rows.
     */
    private function update_dataTables_displayName($generated_id, $displayName)
    {
        $res = $this->db->update_by_ids(
            'dataTables',
            array("displayName" => $displayName),
            array("name" => $generated_id)
        );
        $this->db->clear_cache($this->db->get_cache()::CACHE_TYPE_SECTIONS);
        return $res;
    }

    /**
     * Insert a new LLM script
     * @return int|false New script ID or false on failure
     */
    public function insert_new_script()
    {
        try {
            $this->db->begin_transaction();
            $generated_id = "LLM_SCRIPT_" . substr(uniqid(), -9);
            $this->create_dataTables_entry($generated_id, $generated_id);
            $sid = $this->db->insert(LLM_TABLE_SCRIPTS, array(
                "generated_id" => $generated_id,
                "name" => $generated_id
            ));
            $this->transaction->add_transaction(
                transactionTypes_insert,
                transactionBy_by_user,
                $_SESSION['id_user'],
                LLM_TABLE_SCRIPTS,
                $sid
            );
            $this->db->commit();
            return $sid;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * Update a script
     * @param int $sid Script ID
     * @param string $name Script name
     * @param string $script Script prompt template
     * @param string $test_variables JSON test variables
     * @param bool $async Whether async
     * @param string $data_config JSON data config
     * @param string|null $model Model override
     * @param float|null $temperature Temperature override
     * @param int|null $max_tokens Max tokens override
     * @param string|null $refresh_sections JSON array of section IDs
     * @param string|null $prompt_meta_json Prompt registry metadata snapshot
     * @return int|false Script ID or false on failure
     */
    public function update_script($sid, $name, $script, $test_variables, $async, $data_config, $model = null, $temperature = null, $max_tokens = null, $refresh_sections = null, $prompt_change_note = null, $prompt_meta_json = null)
    {
        try {
            $this->db->begin_transaction();
            $current = $this->fetch_script($sid);
            if ($current) {
                $this->update_dataTables_displayName($current['generated_id'], $name);
            }
            $this->db->update_by_ids(LLM_TABLE_SCRIPTS, array(
                "name" => $name,
                "script" => $script,
                "test_variables" => $test_variables,
                "async" => (int)$async,
                "data_config" => $data_config,
                "model" => $model,
                "temperature" => $temperature,
                "max_tokens" => $max_tokens,
                "refresh_sections" => $refresh_sections
            ), array('id' => $sid));

            $updated_script = $this->fetch_script($sid);
            if ($updated_script) {
                $this->prompt_registry_service->syncScriptSave($updated_script, $prompt_change_note, $prompt_meta_json);
            }

            $this->transaction->add_transaction(
                transactionTypes_update,
                transactionBy_by_user,
                $_SESSION['id_user'],
                LLM_TABLE_SCRIPTS,
                $sid
            );
            $this->db->commit();
            return $sid;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * Delete a script
     * @param int $sid Script ID
     * @return bool Success
     */
    public function delete_script($sid)
    {
        return $this->db->remove_by_ids(LLM_TABLE_SCRIPTS, array("id" => $sid));
    }

    /**
     * Execute an LLM script synchronously.
     * Follows R Serve pattern: resolve data_config, interpolate variables, call LLM API.
     *
     * @param string $script The prompt template
     * @param array|null $data_config JSON-decoded data config
     * @param array $variables Test/form variables for interpolation
     * @param int|null $id_users User ID for data_config resolution
     * @param string|null $model Model override
     * @param float|null $temperature Temperature override
     * @param int|null $max_tokens Max tokens override
     * @param int|null $script_id Optional script ID for conversation grouping
     * @param string|null $script_name Optional script name for conversation title
     * @return array Result with 'result' (bool), 'data', and 'context' keys
     */
    public function execute_llm_script($script, $data_config = null, $variables = array(), $id_users = null, $model = null, $temperature = null, $max_tokens = null, $script_id = null, $script_name = null)
    {
        try {
            if (!is_array($variables) && $variables != null) {
                return array(
                    "result" => false,
                    "data" => "Error in the variables"
                );
            }
            if ($variables == null) {
                $variables = array();
            }
            if ($data_config == null) {
                $data_config = array();
            }

            $data_config = $this->db->replace_calced_values($data_config, $variables);
            $data_config_values = $data_config ? $this->fetch_data($data_config, $id_users) : [];
            $merged_vars = array_merge($data_config_values, $variables);
            $prompt = $this->db->replace_calced_values($script, $merged_vars);

            $config = $this->getLlmConfig();
            $use_model = $model ?: $config['llm_default_model'];
            $use_temperature = $temperature !== null ? $temperature : $config['llm_temperature'];
            $use_max_tokens = $max_tokens !== null ? $max_tokens : $config['llm_max_tokens'];

            $execution_context = array(
                'script_template' => $script,
                'data_config' => $data_config,
                'test_variables' => $variables,
                'data_config_values' => $data_config_values,
                'merged_variables' => $merged_vars,
                'interpolated_prompt' => $prompt,
                'model' => $use_model,
                'temperature' => $use_temperature,
                'max_tokens' => $use_max_tokens,
            );

            $messages = array(
                array('role' => 'user', 'content' => $prompt)
            );

            $effective_user_id = $id_users ?: ($_SESSION['id_user'] ?? null);
            if (!$effective_user_id) {
                return array(
                    "result" => false,
                    "data" => "Missing user context for strict LLM logging"
                );
            }

            $conversation_id = null;
            $sent_context = [
                [
                    'role' => 'system',
                    'content' => json_encode([
                        'script_template' => $script,
                        'data_config' => $data_config,
                        'test_variables' => $variables,
                        'data_config_values' => $data_config_values,
                        'merged_variables' => $merged_vars,
                        'interpolated_prompt' => $prompt,
                    ])
                ],
                ['role' => 'user', 'content' => $prompt]
            ];

            $conversation_id = $this->getOrCreateScriptConversation(
                $effective_user_id,
                $script_id,
                $script_name,
                $use_model,
                $use_temperature,
                $use_max_tokens
            );
            if (!$conversation_id) {
                return array(
                    "result" => false,
                    "data" => "Failed to create conversation for strict LLM logging"
                );
            }

            $this->llmService->addMessage(
                $conversation_id,
                'user',
                $prompt,
                null,
                $use_model
            );

            $start_time = microtime(true);
            $response = $this->llmService->callLlmApi(
                $messages,
                $use_model,
                $use_temperature,
                $use_max_tokens,
                [
                    'conversation_id' => $conversation_id,
                    'sent_context' => $sent_context,
                    'is_validated' => true
                ]
            );
            $execution_time = round((microtime(true) - $start_time) * 1000);

            $execution_context['conversation_id'] = $conversation_id;

            if ($response && isset($response['content'])) {
                $result_data = array(
                    'content' => $response['content'],
                    'model' => $response['model'] ?? $use_model,
                    'tokens_used' => $response['usage']['total_tokens'] ?? null,
                    'execution_time_ms' => $execution_time,
                    'raw_response' => json_encode($response),
                    'logged_message_id' => $response['logged_message_id'] ?? null
                );

                return array(
                    "result" => true,
                    "data" => $result_data,
                    "context" => $execution_context
                );
            } else {
                return array(
                    "result" => false,
                    "data" => "LLM API call failed or returned empty response",
                    "raw_response" => $response,
                    "context" => $execution_context
                );
            }
        } catch (Exception $e) {
            return array(
                "result" => false,
                "data" => $e->getMessage()
            );
        }
    }

    /**
     * Resolve data_config by fetching data from dataTables.
     * Mirrors BaseModel::fetch_data logic.
     *
     * @param array $data_config Data config array
     * @param int|null $id_users User ID
     * @return array Fetched data values
     */
    private function fetch_data($data_config, $id_users = null)
    {
        $result = array();
        if (!is_array($data_config)) {
            return $result;
        }
        foreach ($data_config as $config) {
            if (!isset($config['table'])) {
                continue;
            }
            $table_id = $this->user_input->get_dataTable_id($config['table']);
            if (!$table_id) {
                continue;
            }

            $filter = "ORDER BY record_id ASC";
            if (isset($config['retrieve']) && $config['retrieve'] === 'last') {
                $filter = "ORDER BY record_id DESC";
            }
            if (isset($config['filter']) && $config['filter'] != '') {
                $filter = $config['filter'];
            }

            $current_user = isset($config['current_user']) ? $config['current_user'] : true;

            $data = $this->user_input->get_data(
                $table_id,
                $filter,
                $current_user,
                $id_users
            );

            if ($data) {
                $data = array_filter($data, function ($value) {
                    return (!isset($value["deleted"]) || $value["deleted"] != 1);
                });
            }

            if (!$data || count($data) === 0) {
                continue;
            }

            $retrieve = isset($config['retrieve']) ? $config['retrieve'] : 'last';
            if ($retrieve === 'JSON') {
                $display_name = $this->user_input->get_dataTable_displayName($table_id);
                $result[$display_name] = $data;
            } else if ($retrieve === 'last') {
                $row = end($data);
                if ($row) {
                    $result = array_merge($result, $row);
                }
            } else if ($retrieve === 'first') {
                $row = reset($data);
                if ($row) {
                    $result = array_merge($result, $row);
                }
            } else if ($retrieve === 'all_as_array') {
                $display_name = $this->user_input->get_dataTable_displayName($table_id);
                $result[$display_name] = array_values($data);
            } else {
                $row = end($data);
                if ($row) {
                    $result = array_merge($result, $row);
                }
            }
        }
        return $result;
    }

    /**
     * Resolve or create a conversation dedicated to LLM script execution.
     *
     * @param int $user_id
     * @param int|null $script_id
     * @param string|null $script_name
     * @param string $model
     * @param float|null $temperature
     * @param int|null $max_tokens
     * @return int|null
     */
    private function getOrCreateScriptConversation($user_id, $script_id, $script_name, $model, $temperature, $max_tokens)
    {
        try {
            if ($script_id) {
                $existing = $this->db->query_db_first(
                    "SELECT id
                     FROM llmConversations
                     WHERE id_llm_scripts = :sid
                       AND id_users = :uid
                       AND IFNULL(model, '') = :model
                     ORDER BY id DESC
                     LIMIT 1",
                    [
                        ':sid' => $script_id,
                        ':uid' => $user_id,
                        ':model' => (string) $model,
                    ]
                );
                if ($existing) {
                    $this->db->update_by_ids('llmConversations', [
                        'temperature' => $temperature,
                        'max_tokens' => $max_tokens,
                        'title' => '[Script] ' . ($script_name ?: ('Script #' . $script_id)),
                    ], ['id' => $existing['id']]);
                    return $existing['id'];
                }
            }

            return $this->db->insert('llmConversations', [
                'id_users' => $user_id,
                'id_sections' => null,
                'id_llm_scripts' => $script_id,
                'title' => '[Script] ' . ($script_name ?: 'Script execution'),
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
            ]);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Save LLM script results to a dataTable.
     * Follows the same pattern as R Serve's save_r_results.
     *
     * @param array $result Result array with 'result' and 'data' keys
     * @param int $id_users User ID
     * @param int $id_scheduledJobs Scheduled job ID
     * @param string $generated_id Script's generated_id (used as table name)
     * @param int|null $linked_record_id Source record id for record-triggered runs
     * @return bool Success
     */
    public function save_llm_results($result, $id_users, $id_scheduledJobs, $generated_id, $linked_record_id = null)
    {
        if ($result['result']) {
            $result['data']['id_users'] = $id_users;
            if ($linked_record_id !== null) {
                $result['data']['linked_record_id'] = (int)$linked_record_id;
            }
            foreach ($result['data'] as $key => $value) {
                if (is_array($value)) {
                    $result['data'][$key] = json_encode($value);
                }
            }
            $save_result = $this->user_input->save_data(TRANSACTION_BY_LLM_SCRIPT, $generated_id, $result['data']);
            if ($save_result) {
                $this->transaction->add_transaction(
                    transactionTypes_insert,
                    TRANSACTION_BY_LLM_SCRIPT,
                    null,
                    $this->transaction::TABLE_SCHEDULED_JOBS,
                    $id_scheduledJobs,
                    false,
                    "LLM script results were saved in table " . $generated_id
                );
            }
            return $save_result;
        } else {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_SCRIPT,
                null,
                $this->transaction::TABLE_SCHEDULED_JOBS,
                $id_scheduledJobs,
                false,
                array(
                    "error" => "Error while executing LLM Script",
                    "error_msg" => $result['data']
                )
            );
            return false;
        }
    }

    /**
     * Insert a refresh event for the given user and sections.
     *
     * @param int $id_users User ID
     * @param array $section_ids Array of section IDs to refresh
     * @param string $event_type Event type identifier
     * @param string|null $event_data Optional event data JSON
     */
    public function insert_refresh_event($id_users, $section_ids, $event_type = 'llm_script_completed', $event_data = null)
    {
        if (empty($section_ids)) {
            return;
        }
        try {
            $event_id = $this->db->insert('refresh_events', array(
                'id_users' => $id_users,
                'event_type' => $event_type,
                'event_data' => $event_data
            ));
            if ($event_id) {
                foreach ($section_ids as $section_id) {
                    $this->db->insert('refresh_events_sections', array(
                        'id_refresh_events' => $event_id,
                        'id_sections' => $section_id
                    ));
                }
            }
        } catch (Exception $e) {
            $this->logError('Failed to insert refresh event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get LLM configuration defaults for the scripts UI.
     * Returns the default model, temperature, and max_tokens from page config.
     *
     * @return array Defaults array
     */
    public function get_llm_defaults()
    {
        $config = $this->getLlmConfig();
        return [
            'default_model' => $config['llm_default_model'] ?? LLM_DEFAULT_MODEL,
            'default_temperature' => $config['llm_temperature'] ?? LLM_DEFAULT_TEMPERATURE,
            'default_max_tokens' => $config['llm_max_tokens'] ?? LLM_DEFAULT_MAX_TOKENS,
        ];
    }

    /**
     * Check for unconsumed refresh events for a user.
     * Marks them as consumed after retrieval.
     *
     * @param int $id_users User ID
     * @return array Array of events with their section IDs
     */
    public function check_refresh_events($id_users)
    {
        $events = $this->db->query_db(
            "SELECT re.id, re.event_type, re.event_data, re.created_at,
                    GROUP_CONCAT(res.id_sections) as section_ids
             FROM refresh_events re
             LEFT JOIN refresh_events_sections res ON res.id_refresh_events = re.id
             WHERE re.id_users = :id_users AND re.consumed = 0
             GROUP BY re.id
             ORDER BY re.created_at ASC",
            array(':id_users' => $id_users)
        );

        if ($events && count($events) > 0) {
            $event_ids = array_column($events, 'id');
            $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
            $this->db->query_db(
                "UPDATE refresh_events SET consumed = 1 WHERE id IN ($placeholders)",
                $event_ids
            );
        }

        return $events ?: array();
    }
}
?>
