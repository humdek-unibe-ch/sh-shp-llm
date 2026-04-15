<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryConfigService.php';

/**
 * Normalizes payloads from different trigger sources into a standard
 * memory update request shape, and dispatches memory updates to the
 * async worker or direct update path.
 */
class LlmMemoryTriggerService extends BaseLlmService
{
    /** @var LlmMemoryConfigService */
    private $config_service;

    public function __construct($services, ?LlmMemoryConfigService $config_service = null)
    {
        parent::__construct($services);
        $this->config_service = $config_service ?: new LlmMemoryConfigService($services);
    }

    /**
     * Normalize a form-action-based trigger payload.
     *
     * @param array $form_data  Keys: form_fields, form_name, table_name, trigger_type, record_id
     * @param int   $user_id
     * @return array Normalized payload
     */
    public function normalizeFormActionPayload($form_data, $user_id)
    {
        $form_fields = $form_data['form_fields'] ?? [];
        $user_input = $this->services->get_user_input();
        $form_values = $user_input->get_form_values($form_fields);

        return [
            'source_type'  => LLM_MEMORY_SOURCE_FORM_ACTION,
            'source_ref'   => json_encode([
                'table_name' => $form_data['table_name'] ?? '',
                'form_name'  => $form_data['form_name'] ?? '',
                'record_id'  => $form_data['record_id'] ?? null,
            ], JSON_UNESCAPED_SLASHES),
            'trigger_type' => $form_data['trigger_type'] ?? 'finished',
            'user_id'      => $user_id,
            'event_at'     => date('Y-m-d H:i:s'),
            'fields'       => $form_values,
            'match_criteria' => [
                'table_name' => $form_data['table_name'] ?? '',
                'form_name'  => $form_data['form_name'] ?? '',
            ],
        ];
    }

    /**
     * Normalize a direct llmChat form submission payload.
     *
     * @param array  $form_values     Parsed form values
     * @param string $readable_text   Human-readable text of the submission
     * @param int    $user_id
     * @param int    $section_id
     * @param int|null $conversation_id
     * @param int|null $message_id
     * @return array Normalized payload
     */
    public function normalizeLlmChatFormPayload($form_values, $readable_text, $user_id, $section_id, $conversation_id = null, $message_id = null)
    {
        return [
            'source_type'  => LLM_MEMORY_SOURCE_LLM_CHAT_FORM,
            'source_ref'   => json_encode([
                'section_id'      => $section_id,
                'conversation_id' => $conversation_id,
                'message_id'      => $message_id,
            ], JSON_UNESCAPED_SLASHES),
            'trigger_type' => 'finished',
            'user_id'      => $user_id,
            'event_at'     => date('Y-m-d H:i:s'),
            'fields'       => $form_values,
            'readable_text' => $readable_text,
            'match_criteria' => [
                'section_id' => $section_id,
            ],
        ];
    }

    /**
     * Normalize a login trigger payload.
     *
     * @param int    $user_id
     * @param string $user_name
     * @param string $last_login Previous login timestamp
     * @return array Normalized payload
     */
    public function normalizeLoginPayload($user_id, $user_name, $last_login = '')
    {
        return [
            'source_type'  => LLM_MEMORY_SOURCE_LOGIN,
            'source_ref'   => json_encode(['user_id' => $user_id], JSON_UNESCAPED_SLASHES),
            'trigger_type' => '',
            'user_id'      => $user_id,
            'event_at'     => date('Y-m-d H:i:s'),
            'fields'       => [
                'user_name'  => $user_name,
                'last_login' => $last_login,
                'login_time' => date('Y-m-d H:i:s'),
            ],
            'match_criteria' => [],
        ];
    }

    /**
     * Normalize a profile name change trigger payload.
     *
     * @param int    $user_id
     * @param string $old_name
     * @param string $new_name
     * @return array Normalized payload
     */
    public function normalizeProfileNamePayload($user_id, $old_name, $new_name)
    {
        return [
            'source_type'  => LLM_MEMORY_SOURCE_PROFILE_NAME,
            'source_ref'   => json_encode(['user_id' => $user_id], JSON_UNESCAPED_SLASHES),
            'trigger_type' => '',
            'user_id'      => $user_id,
            'event_at'     => date('Y-m-d H:i:s'),
            'fields'       => [
                'old_name' => $old_name,
                'new_name' => $new_name,
            ],
            'match_criteria' => [],
        ];
    }

    /**
     * Dispatch a normalized payload to matching memory rules.
     * Returns the list of rule keys that were dispatched.
     *
     * @param array $normalized_payload
     * @param bool  $async Whether to run asynchronously (default true)
     * @return array Rule keys that were dispatched
     */
    public function dispatchMemoryUpdate($normalized_payload, $async = true, $rule_overrides = [])
    {
        if (!$this->config_service->isMemoryEnabled()) {
            return [];
        }

        $source_type = $normalized_payload['source_type'];
        $match_criteria = $normalized_payload['match_criteria'] ?? [];
        $rules = $this->config_service->findMatchingRules($source_type, $match_criteria);

        if (empty($rules)) {
            return [];
        }

        $dispatched = [];
        foreach ($rules as $rule) {
            $trigger_type = $normalized_payload['trigger_type'] ?? '';
            if (!empty($rule['trigger_types']) && !empty($trigger_type)) {
                if (!in_array($trigger_type, $rule['trigger_types'])) {
                    continue;
                }
            }

            $rule = $this->applyRuleOverrides($rule, $rule_overrides);
            $dispatched[] = $rule['key'];
            foreach ($this->config_service->getRuleTargetMemoryKeys($rule) as $target_memory_key) {
                $payload_for_key = $normalized_payload;
                $payload_for_key['memory_key_override'] = $target_memory_key;
                $this->enqueueMemoryUpdate($rule, $payload_for_key, $async);
            }
        }

        return $dispatched;
    }

    /**
     * Dispatch a single memory update for specific rule keys.
     *
     * @param array  $rule_keys
     * @param array  $normalized_payload
     * @param bool   $async
     * @return array Rule keys dispatched
     */
    public function dispatchForRuleKeys($rule_keys, $normalized_payload, $async = true, $rule_overrides = [])
    {
        if (!$this->config_service->isMemoryEnabled()) {
            return [];
        }

        $dispatched = [];
        foreach ($rule_keys as $key) {
            $rule = $this->config_service->getRuleByKey(trim($key));
            if (!$rule) {
                continue;
            }
            if (!$rule['enabled']) {
                $this->logDisabledRuleSkip($rule, $normalized_payload, 'rule_key_dispatch');
                continue;
            }
            $rule = $this->applyRuleOverrides($rule, $rule_overrides);
            $dispatched[] = $rule['key'];
            foreach ($this->config_service->getRuleTargetMemoryKeys($rule) as $target_memory_key) {
                $payload_for_key = $normalized_payload;
                $payload_for_key['memory_key_override'] = $target_memory_key;
                $this->enqueueMemoryUpdate($rule, $payload_for_key, $async);
            }
        }

        return $dispatched;
    }

    /**
     * Dispatch a single memory update for specific rule ids.
     *
     * @param array $rule_ids
     * @param array $normalized_payload
     * @param bool $async
     * @param array $rule_overrides
     * @return array
     */
    public function dispatchForRuleIds($rule_ids, $normalized_payload, $async = true, $rule_overrides = [])
    {
        if (!$this->config_service->isMemoryEnabled()) {
            return [];
        }

        require_once __DIR__ . '/LlmMemoryRuleService.php';
        $rule_service = new LlmMemoryRuleService($this->services);
        $dispatched = [];

        foreach ((array)$rule_ids as $rule_id) {
            $rule = $rule_service->getRuleById((int)$rule_id);
            if (!$rule) {
                continue;
            }
            if (!$rule['enabled']) {
                $this->logDisabledRuleSkip($rule, $normalized_payload, 'rule_id_dispatch');
                continue;
            }

            $rule = $this->applyRuleOverrides($rule, $rule_overrides);
            $dispatched[] = $rule['key'];
            foreach ($this->config_service->getRuleTargetMemoryKeys($rule) as $target_memory_key) {
                $payload_for_key = $normalized_payload;
                $payload_for_key['memory_key_override'] = $target_memory_key;
                $this->enqueueMemoryUpdate($rule, $payload_for_key, $async);
            }
        }

        return $dispatched;
    }

    /**
     * Enqueue a memory update for one rule and payload.
     *
     * @param array $rule
     * @param array $normalized_payload
     * @param bool  $async
     */
    private function enqueueMemoryUpdate($rule, $normalized_payload, $async)
    {
        $worker_args = [
            'rule_key'            => $rule['key'],
            'user_id'             => $normalized_payload['user_id'],
            'source_type'         => $normalized_payload['source_type'],
            'source_ref'          => $normalized_payload['source_ref'] ?? '',
            'trigger_type'        => $normalized_payload['trigger_type'] ?? '',
            'event_at'            => $normalized_payload['event_at'] ?? date('Y-m-d H:i:s'),
            'fields'              => $normalized_payload['fields'] ?? [],
            'readable_text'       => $normalized_payload['readable_text'] ?? '',
            'memory_key_override' => $normalized_payload['memory_key_override'] ?? '',
            'force_storage_mode'  => $normalized_payload['force_storage_mode'] ?? '',
            'http_host'           => $_SERVER['HTTP_HOST'] ?? 'localhost',
        ];

        if ($rule['execution_mode'] === LLM_MEMORY_EXECUTION_DIRECT_MAPPING) {
            require_once __DIR__ . '/LlmMemoryUpdateService.php';
            $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);
            $update_service->executeDirectMapping($rule, $normalized_payload);
            return;
        }

        if (!$async) {
            require_once __DIR__ . '/LlmMemoryUpdateService.php';
            $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);
            $update_service->executeLlmSummarization($rule, $normalized_payload);
            return;
        }

        $this->spawnAsyncWorker($worker_args);
    }

    /**
     * Spawn the background PHP worker for async memory updates.
     *
     * @param array $worker_args
     */
    private function spawnAsyncWorker($worker_args)
    {
        $tmp_file = tempnam(sys_get_temp_dir(), 'llm_mem_');
        if (!$tmp_file || file_put_contents($tmp_file, json_encode($worker_args)) === false) {
            error_log('LLM Memory: failed to write temp args file, falling back to sync');
            $this->executeFallbackSync($worker_args);
            return;
        }

        $worker_script = realpath(__DIR__ . '/llm_memory_worker.php');
        if (!$worker_script) {
            error_log('LLM Memory: worker script not found, falling back to sync');
            @unlink($tmp_file);
            $this->executeFallbackSync($worker_args);
            return;
        }

        $php_bin = $this->findPhpCliBinary();
        $php_flags = '-d apc.enable_cli=1';
        $log_file = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'llm_memory_worker.log';

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

        $handle = popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
        } else {
            error_log('LLM Memory: popen failed, falling back to sync');
            @unlink($tmp_file);
            $this->executeFallbackSync($worker_args);
        }
    }

    /**
     * Execute a memory update synchronously as a fallback when async scheduling fails.
     *
     * @param array $worker_args Worker arguments containing user_id, rule_keys, payload, etc.
     */
    private function executeFallbackSync($worker_args)
    {
        require_once __DIR__ . '/LlmMemoryUpdateService.php';
        $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);

        $rule = $this->config_service->getRuleByKey($worker_args['rule_key']);
        if (!$rule) {
            return;
        }

        $normalized = [
            'source_type'         => $worker_args['source_type'],
            'source_ref'          => $worker_args['source_ref'],
            'trigger_type'        => $worker_args['trigger_type'],
            'user_id'             => $worker_args['user_id'],
            'event_at'            => $worker_args['event_at'],
            'fields'              => $worker_args['fields'],
            'readable_text'       => $worker_args['readable_text'] ?? '',
            'memory_key_override' => $worker_args['memory_key_override'] ?? '',
            'force_storage_mode'  => $worker_args['force_storage_mode'] ?? '',
        ];

        if (($rule['execution_mode'] ?? '') === LLM_MEMORY_EXECUTION_DIRECT_MAPPING) {
            $update_service->executeDirectMapping($rule, $normalized);
            return;
        }

        $update_service->executeLlmSummarization($rule, $normalized);
    }

    /**
     * Locate the PHP CLI binary via shared utility.
     *
     * @return string
     */
    private function findPhpCliBinary()
    {
        return BaseLlmService::resolvePhpCliBinary();
    }

    /**
     * Merge ad-hoc task-level overrides into a resolved rule.
     *
     * @param array $rule
     * @param array $rule_overrides
     * @return array
     */
    private function applyRuleOverrides($rule, $rule_overrides)
    {
        if (!is_array($rule_overrides) || empty($rule_overrides)) {
            return $rule;
        }

        if (!empty($rule_overrides['execution_mode'])) {
            $rule['execution_mode'] = $rule_overrides['execution_mode'];
        }

        if (!empty($rule_overrides['field_mapping']) && is_array($rule_overrides['field_mapping'])) {
            $rule['field_mapping'] = $rule_overrides['field_mapping'];
        }

        if (!empty($rule_overrides['prompt_version_override'])) {
            $rule['prompt_version_override'] = (int)$rule_overrides['prompt_version_override'];
        }

        return $rule;
    }

    /**
     * Write an audit transaction when an explicitly targeted rule is disabled.
     *
     * @param array $rule
     * @param array $normalized_payload
     * @param string $dispatch_mode
     * @return void
     */
    private function logDisabledRuleSkip($rule, $normalized_payload, $dispatch_mode)
    {
        $this->services->get_transaction()->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_MEMORY,
            $_SESSION['id_user'] ?? null,
            null,
            null,
            false,
            'LLM Memory skipped disabled rule: ' . json_encode([
                'dispatch_mode' => $dispatch_mode,
                'rule_id' => (int)($rule['id'] ?? 0),
                'rule_key' => (string)($rule['key'] ?? ''),
                'rule_label' => (string)($rule['label'] ?? ''),
                'user_id' => (int)($normalized_payload['user_id'] ?? 0),
                'source_type' => (string)($normalized_payload['source_type'] ?? ''),
            ], JSON_UNESCAPED_SLASHES)
        );
    }
}
?>
