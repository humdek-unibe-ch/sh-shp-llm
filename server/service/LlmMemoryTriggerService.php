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
     * Resolve the current user's language name and locale from the session.
     *
     * @return array{user_language: string, user_language_locale: string}
     */
    private function resolveUserLanguage()
    {
        $lang_id = $_SESSION['user_language'] ?? null;
        if (!$lang_id) {
            return ['user_language' => '', 'user_language_locale' => ''];
        }
        $lang = $this->db->fetch_language($lang_id);
        if (!$lang) {
            return ['user_language' => '', 'user_language_locale' => ''];
        }
        return [
            'user_language'        => $lang['language'] ?? '',
            'user_language_locale' => $lang['locale'] ?? '',
        ];
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

        return array_merge([
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
        ], $this->resolveUserLanguage());
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
        return array_merge([
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
        ], $this->resolveUserLanguage());
    }

    /**
     * Normalize a login trigger payload.
     *
     * @param int    $user_id
     * @param array  $profile_fields
     * @param string $event_at
     * @return array Normalized payload
     */
    public function normalizeLoginPayload($user_id, $profile_fields = [], $event_at = '')
    {
        $event_at = $event_at ?: date('Y-m-d H:i:s');
        $profile_fields = is_array($profile_fields) ? $profile_fields : [];

        return array_merge([
            'source_type'  => LLM_MEMORY_SOURCE_LOGIN,
            'source_ref'   => json_encode(['user_id' => $user_id], JSON_UNESCAPED_SLASHES),
            'trigger_type' => '',
            'user_id'      => $user_id,
            'event_at'     => $event_at,
            'fields'       => array_merge([
                'login_now'  => $event_at,
                'login_time' => $event_at,
                'user_id'    => (string)$user_id,
            ], $profile_fields),
            'match_criteria' => [],
        ], $this->resolveUserLanguage());
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
        return array_merge([
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
        ], $this->resolveUserLanguage());
    }

    /**
     * Dispatch a normalized payload to matching memory rules.
     *
     * @param array $normalized_payload
     * @param bool  $async Whether to run asynchronously (default true)
     * @param array $rule_overrides
     * @return array Rule IDs that were dispatched
     */
    public function dispatchMemoryUpdate($normalized_payload, $async = true, $rule_overrides = [])
    {
        $this->appendWorkerLog('dispatchMemoryUpdate called', array(
            'source_type' => (string)($normalized_payload['source_type'] ?? ''),
            'trigger_type' => (string)($normalized_payload['trigger_type'] ?? ''),
            'user_id' => (int)($normalized_payload['user_id'] ?? 0),
            'async' => !empty($async),
            'match_criteria' => $normalized_payload['match_criteria'] ?? array(),
            'field_keys' => array_keys((array)($normalized_payload['fields'] ?? array())),
        ));

        if (!$this->config_service->isMemoryEnabled()) {
            $this->appendWorkerLog('dispatchMemoryUpdate skipped: memory disabled');
            return [];
        }

        $source_type = $normalized_payload['source_type'];
        $match_criteria = $normalized_payload['match_criteria'] ?? [];
        $rules = $this->config_service->findMatchingRules($source_type, $match_criteria);
        $this->appendWorkerLog('dispatchMemoryUpdate rules resolved', array(
            'source_type' => (string)$source_type,
            'rule_ids' => array_values(array_map(function ($rule) {
                return (int)($rule['id'] ?? 0);
            }, (array)$rules)),
        ));

        if (empty($rules)) {
            $this->appendWorkerLog('dispatchMemoryUpdate no matching rules', array(
                'source_type' => (string)$source_type,
                'match_criteria' => $match_criteria,
            ));
            return [];
        }

        $dispatched = [];
        foreach ($rules as $rule) {
            $trigger_type = $normalized_payload['trigger_type'] ?? '';
            if (!empty($rule['trigger_types']) && !empty($trigger_type)) {
                if (!in_array($trigger_type, $rule['trigger_types'])) {
                    $this->appendWorkerLog('dispatchMemoryUpdate skipped by trigger type', array(
                        'rule_id' => (int)($rule['id'] ?? 0),
                        'trigger_type' => (string)$trigger_type,
                        'allowed_trigger_types' => $rule['trigger_types'],
                    ));
                    continue;
                }
            }

            $rule = $this->applyRuleOverrides($rule, $rule_overrides);
            $dispatched[] = (int)$rule['id'];
            foreach ($this->config_service->getRuleTargetMemoryKeys($rule) as $target_memory_key) {
                $payload_for_key = $normalized_payload;
                $payload_for_key['memory_key_override'] = $target_memory_key;
                $this->enqueueMemoryUpdate($rule, $payload_for_key, $async);
            }
        }

        $this->appendWorkerLog('dispatchMemoryUpdate dispatched', array(
            'source_type' => (string)$source_type,
            'rule_ids' => $dispatched,
        ));

        return $dispatched;
    }

    /**
     * Dispatch memory updates for specific rule IDs.
     *
     * @param array $rule_ids
     * @param array $normalized_payload
     * @param bool $async
     * @param array $rule_overrides
     * @return array Rule IDs dispatched
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
            $dispatched[] = (int)$rule['id'];
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
            'rule_id'                => (int)$rule['id'],
            'user_id'                => $normalized_payload['user_id'],
            'source_type'            => $normalized_payload['source_type'],
            'source_ref'             => $normalized_payload['source_ref'] ?? '',
            'trigger_type'           => $normalized_payload['trigger_type'] ?? '',
            'event_at'               => $normalized_payload['event_at'] ?? date('Y-m-d H:i:s'),
            'fields'                 => $normalized_payload['fields'] ?? [],
            'readable_text'          => $normalized_payload['readable_text'] ?? '',
            'memory_key_override'    => $normalized_payload['memory_key_override'] ?? '',
            'force_storage_mode'     => $normalized_payload['force_storage_mode'] ?? '',
            'user_language'          => $normalized_payload['user_language'] ?? '',
            'user_language_locale'   => $normalized_payload['user_language_locale'] ?? '',
            'http_host'              => $_SERVER['HTTP_HOST'] ?? 'localhost',
        ];

        $this->appendWorkerLog('enqueueMemoryUpdate prepared', array(
            'rule_id' => (int)($rule['id'] ?? 0),
            'execution_mode' => (string)($rule['execution_mode'] ?? ''),
            'user_id' => (int)($normalized_payload['user_id'] ?? 0),
            'source_type' => (string)($normalized_payload['source_type'] ?? ''),
            'memory_key_override' => (string)($normalized_payload['memory_key_override'] ?? ''),
            'async' => !empty($async),
            'field_keys' => array_keys((array)($normalized_payload['fields'] ?? array())),
        ));

        if ($rule['execution_mode'] === LLM_MEMORY_EXECUTION_DIRECT_MAPPING) {
            $this->appendWorkerLog('enqueueMemoryUpdate executing direct mapping sync', array(
                'rule_id' => (int)($rule['id'] ?? 0),
            ));
            require_once __DIR__ . '/LlmMemoryUpdateService.php';
            $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);
            $update_service->executeDirectMapping($rule, $normalized_payload);
            return;
        }

        if (!$async) {
            $this->appendWorkerLog('enqueueMemoryUpdate executing summarization sync', array(
                'rule_id' => (int)($rule['id'] ?? 0),
            ));
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
            $this->appendWorkerLog('spawnAsyncWorker failed to write args file, using sync fallback', array(
                'rule_id' => (int)($worker_args['rule_id'] ?? 0),
                'user_id' => (int)($worker_args['user_id'] ?? 0),
            ));
            $this->executeFallbackSync($worker_args);
            return;
        }

        $worker_script = realpath(__DIR__ . '/llm_memory_worker.php');
        if (!$worker_script) {
            error_log('LLM Memory: worker script not found, falling back to sync');
            $this->appendWorkerLog('spawnAsyncWorker worker script missing, using sync fallback', array(
                'tmp_file' => $tmp_file,
            ));
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
                . ' >NUL 2>&1';
        } else {
            $cmd = escapeshellarg($php_bin)
                . ' ' . $php_flags
                . ' ' . escapeshellarg($worker_script)
                . ' ' . escapeshellarg($tmp_file)
                . ' >> ' . escapeshellarg($log_file) . ' 2>&1 &';
        }

        $this->appendWorkerLog('spawnAsyncWorker launching', array(
            'rule_id' => (int)($worker_args['rule_id'] ?? 0),
            'user_id' => (int)($worker_args['user_id'] ?? 0),
            'tmp_file' => $tmp_file,
            'php_bin' => $php_bin,
            'worker_script' => $worker_script,
            'log_file' => $log_file,
            'command' => $cmd,
        ));

        $handle = popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
            $this->appendWorkerLog('spawnAsyncWorker process launched', array(
                'rule_id' => (int)($worker_args['rule_id'] ?? 0),
                'user_id' => (int)($worker_args['user_id'] ?? 0),
            ));
        } else {
            error_log('LLM Memory: popen failed, falling back to sync');
            $this->appendWorkerLog('spawnAsyncWorker popen failed, using sync fallback', array(
                'rule_id' => (int)($worker_args['rule_id'] ?? 0),
                'user_id' => (int)($worker_args['user_id'] ?? 0),
            ));
            @unlink($tmp_file);
            $this->executeFallbackSync($worker_args);
        }
    }

    /**
     * Execute a memory update synchronously as a fallback when async scheduling fails.
     *
     * @param array $worker_args Worker arguments containing user_id, rule_id, payload, etc.
     */
    private function executeFallbackSync($worker_args)
    {
        $rule_id = (int)($worker_args['rule_id'] ?? 0);
        $this->appendWorkerLog('executeFallbackSync entered', array(
            'rule_id' => $rule_id,
            'user_id' => (int)($worker_args['user_id'] ?? 0),
        ));
        require_once __DIR__ . '/LlmMemoryUpdateService.php';
        $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);

        $rule = $this->config_service->getRuleById($rule_id);
        if (!$rule) {
            $this->appendWorkerLog('executeFallbackSync rule not found', array(
                'rule_id' => $rule_id,
            ));
            return;
        }

        $normalized = [
            'source_type'            => $worker_args['source_type'],
            'source_ref'             => $worker_args['source_ref'],
            'trigger_type'           => $worker_args['trigger_type'],
            'user_id'                => $worker_args['user_id'],
            'event_at'               => $worker_args['event_at'],
            'fields'                 => $worker_args['fields'],
            'readable_text'          => $worker_args['readable_text'] ?? '',
            'memory_key_override'    => $worker_args['memory_key_override'] ?? '',
            'force_storage_mode'     => $worker_args['force_storage_mode'] ?? '',
            'user_language'          => $worker_args['user_language'] ?? '',
            'user_language_locale'   => $worker_args['user_language_locale'] ?? '',
        ];

        if (($rule['execution_mode'] ?? '') === LLM_MEMORY_EXECUTION_DIRECT_MAPPING) {
            $this->appendWorkerLog('executeFallbackSync running direct mapping', array(
                'rule_id' => (int)($rule['id'] ?? 0),
            ));
            $update_service->executeDirectMapping($rule, $normalized);
            return;
        }

        $this->appendWorkerLog('executeFallbackSync running summarization', array(
            'rule_id' => (int)($rule['id'] ?? 0),
        ));
        $update_service->executeLlmSummarization($rule, $normalized);
    }

    private function appendWorkerLog($message, $context = array())
    {
        $log_file = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'llm_memory_worker.log';
        $line = '[' . date('Y-m-d H:i:s') . '] LLM Memory Trigger: ' . $message;
        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $line .= ' | ' . $encoded;
            }
        }
        @file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND);
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
                'rule_label' => (string)($rule['label'] ?? ''),
                'user_id' => (int)($normalized_payload['user_id'] ?? 0),
                'source_type' => (string)($normalized_payload['source_type'] ?? ''),
            ], JSON_UNESCAPED_SLASHES)
        );
    }
}
?>
