<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../callback/BaseCallback.php";
require_once __DIR__ . "/../service/LlmScriptService.php";

/**
 * Callback handler for async LLM script execution.
 * Similar to CallbackRserve but for LLM scripts.
 *
 * Since LLM API calls are synchronous HTTP, the "async" mode means
 * the job runs via cron and results are saved upon completion.
 * This callback can be used for external integrations or webhook-style
 * notifications after script completion.
 */
class CallbackLlm extends BaseCallback
{
    const CALLBACK_LLM_GENERATED_ID = 'llm_generated_id';

    /** @var LlmScriptService */
    private $scriptService;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->scriptService = new LlmScriptService($services);
    }

    /**
     * Validate callback request parameters
     */
    private function validate_callback($data)
    {
        $result = array();
        $result['selfhelpCallback'] = array();
        $result[self::CALLBACK_STATUS] = self::CALLBACK_SUCCESS;

        if (!isset($data[self::CALLBACK_KEY]) || $this->db->get_callback_key() !== $data[self::CALLBACK_KEY]) {
            $result['selfhelpCallback'][] = 'wrong callback key';
            $result[self::CALLBACK_STATUS] = self::CALLBACK_ERROR;
            return $result;
        }
        return $result;
    }

    /**
     * Save data from an LLM script execution callback.
     *
     * @param array $data POST data containing:
     *   - callback_key: validation key
     *   - id_users: user ID
     *   - llm_generated_id: script's generated_id
     *   - id_scheduledJobs: job ID
     *   - ... additional result data
     */
    public function save_data($data)
    {
        $start_time = microtime(true);
        $start_date = date("Y-m-d H:i:s");
        $callback_log_id = $this->insert_callback_log($_SERVER, $data);
        $result = $this->validate_callback($data);

        if ($result[self::CALLBACK_STATUS] == self::CALLBACK_SUCCESS) {
            if (isset($data[self::CALLBACK_LLM_GENERATED_ID])) {
                $generated_id = $data[self::CALLBACK_LLM_GENERATED_ID];
                unset($data[self::CALLBACK_LLM_GENERATED_ID]);
                unset($data[self::CALLBACK_KEY]);

                $id_users = isset($data['id_users']) ? $data['id_users'] : null;
                $id_scheduledJobs = isset($data['id_scheduledJobs']) ? $data['id_scheduledJobs'] : null;

                $this->scriptService->save_llm_results(
                    array("result" => true, "data" => $data),
                    $id_users,
                    $id_scheduledJobs,
                    $generated_id
                );

                $script_info = $this->db->query_db_first(
                    "SELECT refresh_sections FROM llm_scripts WHERE generated_id = :gid",
                    array(':gid' => $generated_id)
                );
                if ($script_info && $script_info['refresh_sections']) {
                    $section_ids = json_decode($script_info['refresh_sections'], true);
                    if (is_array($section_ids) && !empty($section_ids) && $id_users) {
                        $this->scriptService->insert_refresh_event(
                            $id_users,
                            $section_ids,
                            'llm_script_completed',
                            json_encode(array('generated_id' => $generated_id))
                        );
                    }
                }
            } else {
                $result['selfhelpCallback'][] = 'No LLM generated id';
                $result[self::CALLBACK_STATUS] = self::CALLBACK_ERROR;
            }
        }

        $end_time = microtime(true);
        $result['time'] = array(
            'exec_time' => $end_time - $start_time,
            'start_date' => $start_date
        );
        $this->update_callback_log($callback_log_id, $result);
        echo json_encode($result);
    }
}
?>
