<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryConfigService.php';
require_once __DIR__ . '/LlmMemoryStorageService.php';
require_once __DIR__ . '/LlmMemoryUpdateService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';
require_once __DIR__ . '/LlmPromptExecutionProfileService.php';

/**
 * Admin-facing operations for the Memory tab in the LLM admin console.
 * Provides user listing, detail retrieval, history, and manual actions.
 */
class LlmMemoryAdminService extends BaseLlmService
{
    /** @var LlmMemoryConfigService */
    private $config_service;

    /** @var LlmMemoryStorageService */
    private $storage_service;

    /** @var LlmMemoryRuleService */
    private $rule_service;

    /**
     * @param object                   $services       SelfHelp services container.
     * @param LlmMemoryConfigService|null $config_service Optional override for config service.
     */
    public function __construct($services, ?LlmMemoryConfigService $config_service = null)
    {
        parent::__construct($services);
        $this->config_service = $config_service ?: new LlmMemoryConfigService($services);
        $this->storage_service = new LlmMemoryStorageService($services, $this->config_service);
        $this->rule_service = new LlmMemoryRuleService($services);
    }

    /* =========================================================================
     * Read Operations
     * ========================================================================= */

    /**
     * Get a paginated list of users that have at least one memory entry.
     *
     * @param int    $page
     * @param int    $per_page
     * @param string $search   Optional username/email substring filter
     * @return array  ['items' => [...], 'total' => int, 'page' => int, 'per_page' => int]
     */
    public function getMemoryUserList($page = 1, $per_page = 25, $search = '')
    {
        if (!$this->config_service->isMemoryEnabled()) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page];
        }

        $all_entries = $this->storage_service->getMemoryList();
        $user_map = [];
        foreach ($all_entries as $entry) {
            $uid = $entry['id_users'] ?? null;
            if (!$uid) continue;
            if (!isset($user_map[$uid])) {
                $user_map[$uid] = [
                    'user_id'      => $uid,
                    'memory_count' => 0,
                    'last_updated' => null,
                    'memory_keys'  => [],
                ];
            }
            $user_map[$uid]['memory_count']++;
            $mk = $entry['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY;
            if (!in_array($mk, $user_map[$uid]['memory_keys'])) {
                $user_map[$uid]['memory_keys'][] = $mk;
            }
            $event_at = $entry['event_at'] ?? $entry['updated_at'] ?? null;
            if ($event_at && (!$user_map[$uid]['last_updated'] || $event_at > $user_map[$uid]['last_updated'])) {
                $user_map[$uid]['last_updated'] = $event_at;
            }
        }

        if ($search !== '') {
            $user_ids = array_keys($user_map);
            $users_info = $this->getUsersInfo($user_ids);
            $search_lower = strtolower($search);
            $user_map = array_filter($user_map, function ($entry) use ($users_info, $search_lower) {
                $uid = (int)$entry['user_id'];
                $info = $users_info[$uid] ?? [];
                $name = strtolower($info['name'] ?? '');
                $email = strtolower($info['email'] ?? '');
                $code = strtolower($info['code'] ?? '');
                return strpos($name, $search_lower) !== false
                    || strpos($email, $search_lower) !== false
                    || strpos($code, $search_lower) !== false;
            });
        }

        usort($user_map, function ($a, $b) {
            return strcmp($b['last_updated'] ?? '', $a['last_updated'] ?? '');
        });
        $user_list = array_values($user_map);

        $total = count($user_list);
        $offset = ($page - 1) * $per_page;
        $items = array_slice($user_list, $offset, $per_page);

        $user_ids = array_column($items, 'user_id');
        $users_info = $this->getUsersInfo($user_ids);
        foreach ($items as &$item) {
            $info = $users_info[(int)$item['user_id']] ?? [];
            $item['user_name'] = $info['name'] ?? ('User #' . $item['user_id']);
            $item['user_email'] = $info['email'] ?? '';
        }
        unset($item);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Get current effective memory for a user and optional memory key.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @return array|null
     */
    public function getUserMemory($user_id, $memory_key = null)
    {
        if ($memory_key === null) {
            $memory_key = $this->config_service->getDefaultMemoryKey();
        }

        $this->storage_service->initializeMemoryTables();
        return $this->normalizeMemoryRow($this->storage_service->getEffectiveMemory($user_id, $memory_key));
    }

    /**
     * Get the full history for a user's memory key.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    public function getUserMemoryHistory($user_id, $memory_key = null, $limit = 50, $offset = 0)
    {
        if ($memory_key === null) {
            $memory_key = $this->config_service->getDefaultMemoryKey();
        }

        $history = $this->storage_service->getMemoryHistory($user_id, $memory_key, $limit, $offset);
        return array_map([$this, 'normalizeMemoryRow'], $history);
    }

    /**
     * Get all memory keys that a specific user has data for.
     *
     * @param int $user_id
     * @return array
     */
    public function getUserMemoryKeys($user_id)
    {
        $all = $this->storage_service->getMemoryList(null, 0, ['user_id' => $user_id]);
        $keys = [];
        foreach ($all as $entry) {
            if (($entry['id_users'] ?? null) == $user_id) {
                $mk = $entry['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY;
                if (!in_array($mk, $keys)) {
                    $keys[] = $mk;
                }
            }
        }
        sort($keys);
        return $keys;
    }

    /**
     * Get the full memory configuration overview for admin display.
     *
     * @return array
     */
    public function getMemoryOverview()
    {
        $config = $this->config_service->getMemoryConfig();
        $rules = array_values($this->config_service->getRules());
        $all_entries = $this->storage_service->getMemoryList();

        $unique_users = [];
        $total_entries = count($all_entries);
        $total_keys = [];
        foreach ($all_entries as $entry) {
            $uid = $entry['id_users'] ?? null;
            if ($uid) $unique_users[$uid] = true;
            $mk = $entry['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY;
            $total_keys[$mk] = true;
        }

        $write_sources = $this->getWriteSources();
        $latest_activity_at = $this->getLatestActivityAt();

        // Where each rule is used (sections, form actions, llmChat, system
        // hooks), so a user's Rules tab can show the rules of one memory key.
        $used_in = [];
        foreach ($write_sources as $source) {
            foreach ((array)($source['rule_ids'] ?? []) as $rid) {
                $used_in[(int)$rid][] = [
                    'source_category' => $source['source_category'] ?? '',
                    'target_label' => $source['target_label'] ?? '',
                    'target_secondary' => $source['target_secondary'] ?? '',
                    'target_url' => $source['target_url'] ?? null,
                ];
            }
        }
        foreach ($rules as &$rule) {
            $rule['used_in'] = $used_in[(int)$rule['id']] ?? [];
        }
        unset($rule);

        return [
            'enabled'           => $this->config_service->isMemoryEnabled(),
            'storage_mode'      => $this->config_service->getStorageMode(),
            'current_table'     => $config['table_name'],
            'history_table'     => $config['history_table_name'],
            'rules_count'       => count($rules),
            'enabled_rules'     => count(array_filter($rules, function ($r) { return $r['enabled']; })),
            'total_entries'     => $total_entries,
            'unique_users'      => count($unique_users),
            'unique_keys'       => array_keys($total_keys),
            'sources_count'     => count($write_sources),
            'latest_activity_at'=> $latest_activity_at,
            'rules'             => $rules,
        ];
    }

    /**
     * Paginated, filterable activity feed (newest first) built from the memory history table.
     * Every item names the participant (name, code, email), the rule and memory key, what
     * triggered the update and who caused it, so no other tab has to be opened to read it.
     *
     * @param array $filters  Optional: q (name / email / validation code / user id), rule_id (0 = manual
     *                        admin actions), memory_key, status, source_type, date_from / date_to (Y-m-d).
     * @param int   $page     1-based page number.
     * @param int   $per_page Page size (max 100).
     * @return array ['items', 'total', 'page', 'per_page', 'filter_options']
     */
    public function getActivity($filters = array(), $page = 1, $per_page = 25)
    {
        $page = max(1, (int)$page);
        $per_page = max(1, min(100, (int)$per_page));

        $storage_filters = array(
            'rule_id' => $filters['rule_id'] ?? '',
            'memory_key' => $filters['memory_key'] ?? '',
            'update_status' => $filters['status'] ?? '',
            'source_type' => $filters['source_type'] ?? '',
            'date_from' => $filters['date_from'] ?? '',
            'date_to' => $filters['date_to'] ?? '',
        );
        $search = trim((string)($filters['q'] ?? ''));
        if ($search !== '') {
            $storage_filters['user_ids'] = $this->findUserIdsBySearch($search);
        }

        $history = $this->storage_service->getHistoryActivity($storage_filters, $per_page, ($page - 1) * $per_page);

        // participants and acting admins are loaded together in one query
        $entries = array();
        $user_ids = array();
        foreach ($history['rows'] as $entry) {
            $source_ref = $this->decodeJsonField($entry['source_ref'] ?? '');
            $source_ref = is_array($source_ref) ? $source_ref : array();
            $entries[] = array($entry, $source_ref);
            $user_ids[] = (int)($entry['id_users'] ?? 0);
            $user_ids[] = (int)($source_ref['admin_user_id'] ?? 0);
        }
        $users_info = $this->getUsersInfo(array_values(array_unique(array_filter($user_ids))));

        $rules = $this->rule_service->listRules();
        $rule_labels = array();
        foreach ($rules as $rule) {
            $rule_labels[(int)$rule['id']] = trim((string)$rule['label']);
        }
        $memory_keys = $this->rule_service->listMemoryKeys();
        $key_labels = array();
        foreach ($memory_keys as $key) {
            $key_labels[(string)$key['code']] = (string)$key['label'];
        }

        $items = array();
        foreach ($entries as $pair) {
            list($entry, $source_ref) = $pair;
            $user_id = (int)($entry['id_users'] ?? 0);
            $user = $users_info[$user_id] ?? array();
            $rule_id = (int)($entry['rule_id'] ?? 0);
            $memory_key = (string)($entry['memory_key'] ?? '');
            $event_at = (string)($entry['event_at'] ?? '');

            $items[] = array_merge(
                array(
                    'record_id' => (int)($entry['record_id'] ?? 0),
                    'event_at' => $event_at !== '' ? $event_at : (string)($entry['entry_date'] ?? ''),
                    'saved_at' => (string)($entry['created_at'] ?? $entry['entry_date'] ?? ''),
                    'id_users' => $user_id,
                    'user_name' => (string)($user['name'] ?? $entry['user_name'] ?? ''),
                    'user_code' => (string)($user['code'] ?? $entry['user_code'] ?? ''),
                    'user_email' => (string)($user['email'] ?? ''),
                    'rule_id' => $rule_id,
                    'rule_label' => $rule_id > 0 ? ($rule_labels[$rule_id] ?? '') : 'Manual (no rule)',
                    'memory_key' => $memory_key,
                    'memory_key_label' => $key_labels[$memory_key] ?? $memory_key,
                    'update_status' => (string)($entry['update_status'] ?? ''),
                    'change_summary' => (string)($entry['change_summary'] ?? ''),
                ),
                $this->describeActivitySource($entry, $source_ref, $users_info)
            );
        }

        return array(
            'items' => $items,
            'total' => (int)$history['total'],
            'page' => $page,
            'per_page' => $per_page,
            'filter_options' => $this->getActivityFilterOptions($rules, $memory_keys),
        );
    }

    /** @return string|null Timestamp of the most recent memory activity, or null. */
    private function getLatestActivityAt()
    {
        $recent_activity = $this->storage_service->getRecentHistory(1);
        if (empty($recent_activity)) {
            return null;
        }

        $entry = $this->normalizeMemoryRow($recent_activity[0]);
        return $entry['event_at'] ?? $entry['created_at'] ?? null;
    }

    /**
     * Count how many write sources reference each rule ID.
     *
     * @return array Keyed by rule ID => count.
     */
    public function getRuleUsageCounts()
    {
        $counts = array();
        foreach ($this->getWriteSources() as $source) {
            foreach ((array)($source['rule_ids'] ?? array()) as $rule_id) {
                $rule_id = (int)$rule_id;
                if ($rule_id <= 0) continue;
                if (!isset($counts[$rule_id])) {
                    $counts[$rule_id] = 0;
                }
                $counts[$rule_id]++;
            }
        }
        return $counts;
    }

    /**
     * Build the derived write-source index for the dedicated memory page.
     *
     * @return array
     */
    public function getWriteSources()
    {
        $sources = array();
        $sources = array_merge($sources, $this->getFormActionSources());
        $sources = array_merge($sources, $this->getSectionFieldSources());
        $sources = array_merge($sources, $this->getLlmChatFallbackSources());
        $sources = array_merge($sources, $this->getSystemRuleSources());
        return $this->decorateSourcesWithRuleRefs($sources);
    }

    /* =========================================================================
     * Write / Action Operations
     * ========================================================================= */

    /**
     * Manually re-run a memory rule for a specific user with an admin-supplied payload.
     *
     * @param int    $user_id
     * @param int    $rule_id
     * @param array  $manual_payload   Optional field overrides
     * @return bool
     */
    public function reRunRuleForUser($user_id, $rule_id, $manual_payload = [])
    {
        $rule = $this->config_service->getRuleById((int)$rule_id);
        if (!$rule) {
            return false;
        }

        $normalized = [
            'source_type'  => 'admin_manual',
            'source_ref'   => json_encode([
                'admin_rerun' => true,
                'rule_id' => (int)$rule_id,
                'admin_user_id' => $_SESSION['id_user'] ?? null,
            ]),
            'trigger_type' => 'manual',
            'user_id'      => $user_id,
            'event_at'     => date('Y-m-d H:i:s'),
            'fields'       => $manual_payload,
        ];

        $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);

        return $update_service->executeLlmSummarization($rule, $normalized);
    }

    /**
     * Rebuild a user's entire memory by re-running all enabled rules.
     *
     * @param int   $user_id
     * @param array $manual_payload  Optional field overrides
     * @return array Memory keys that were rebuilt
     */
    public function rebuildUserMemory($user_id, $manual_payload = [])
    {
        $history_rows = $this->storage_service->getAppliedHistoryForUser($user_id);
        if (empty($history_rows)) {
            return [];
        }

        $latest_by_key = [];
        foreach ($history_rows as $row) {
            $memory_key = $row['memory_key'] ?? LLM_MEMORY_DEFAULT_KEY;
            if (!isset($latest_by_key[$memory_key])) {
                $latest_by_key[$memory_key] = $row;
            }
        }

        $rebuilt_keys = [];
        foreach ($latest_by_key as $memory_key => $row) {
            $memory_object = json_decode((string)($row['memory_json'] ?? '{}'), true);
            if (!is_array($memory_object)) {
                $memory_object = [];
            }

            $memory_data = [
                'memory_text'    => (string)($row['memory_text'] ?? ''),
                'memory_object'  => $memory_object,
                'flat_fields'    => [],
                'change_summary' => 'Rebuilt current memory from history.',
            ];

            $metadata = [
                'rule_id'       => 0,
                'source_type'   => 'admin_manual',
                'source_ref'    => json_encode([
                    'rebuild_from_history' => true,
                    'history_record_id' => $row['record_id'] ?? null,
                    'admin_user_id' => $_SESSION['id_user'] ?? null,
                ]),
                'trigger_type'  => 'manual_rebuild',
                'payload_json'  => [],
                'event_at'      => $row['event_at'] ?? date('Y-m-d H:i:s'),
                'dedupe_key'    => hash('sha256', 'admin_rebuild_' . $user_id . '_' . $memory_key . '_' . microtime(true)),
                'update_status' => LLM_MEMORY_STATUS_APPLIED,
            ];

            $success = $this->storage_service->saveMemoryUpdate($user_id, $memory_key, $memory_data, $metadata, 'record');
            if ($success) {
                $rebuilt_keys[] = $memory_key;
            }
        }

        return $rebuilt_keys;
    }

    /**
     * Directly edit a user's current memory text and/or JSON.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @param array  $memory_data  Keys: memory_text, memory_object, flat_fields, change_summary
     * @return bool
     */
    public function editUserMemory($user_id, $memory_key, $memory_data)
    {
        $storage_mode = $this->config_service->getStorageMode();

        $metadata = [
            'rule_id'       => 0,
            'source_type'   => 'admin_manual',
            'source_ref'    => json_encode([
                'admin_edit' => true,
                'admin_user_id' => $_SESSION['id_user'] ?? null,
            ]),
            'trigger_type'  => 'manual_edit',
            'payload_json'  => $memory_data,
            'event_at'      => date('Y-m-d H:i:s'),
            'dedupe_key'    => hash('sha256', 'admin_edit_' . $user_id . '_' . $memory_key . '_' . microtime(true)),
            'update_status' => LLM_MEMORY_STATUS_APPLIED,
        ];

        $this->storage_service->initializeMemoryTables();
        return $this->storage_service->saveMemoryUpdate($user_id, $memory_key, $memory_data, $metadata, $storage_mode);
    }

    /**
     * Delete (soft-delete) a user's memory for a given key.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @return bool
     */
    public function deleteUserMemory($user_id, $memory_key)
    {
        $empty_data = [
            'memory_text'    => '',
            'memory_object'  => [],
            'flat_fields'    => [],
            'change_summary' => 'Memory cleared by admin.',
        ];

        $metadata = [
            'rule_id'       => 0,
            'source_type'   => 'admin_manual',
            'source_ref'    => json_encode([
                'admin_delete' => true,
                'admin_user_id' => $_SESSION['id_user'] ?? null,
            ]),
            'trigger_type'  => 'manual_delete',
            'payload_json'  => [],
            'event_at'      => date('Y-m-d H:i:s'),
            'dedupe_key'    => hash('sha256', 'admin_del_' . $user_id . '_' . $memory_key . '_' . microtime(true)),
            'update_status' => LLM_MEMORY_STATUS_APPLIED,
        ];

        $this->storage_service->initializeMemoryTables();
        return $this->storage_service->saveMemoryUpdate($user_id, $memory_key, $empty_data, $metadata, 'both');
    }

    /* =========================================================================
     * Private Helpers
     * ========================================================================= */

    /**
     * Batch-load user info (name, email, code) for a list of user IDs.
     *
     * @param array $user_ids
     * @return array Keyed by user_id
     */
    private function getUsersInfo($user_ids)
    {
        if (empty($user_ids)) return [];

        $info = [];
        $db = $this->services->get_db();

        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $sql = "SELECT u.id, u.`name`, u.email, vc.`code`
                FROM users u
                LEFT JOIN validation_codes vc ON vc.id_users = u.id
                WHERE u.id IN ($placeholders)";

        $rows = $db->query_db($sql, array_values($user_ids));
        foreach ($rows as $row) {
            // ids come back zero-padded ("0000000009"); key by int so every caller can look up with (int)
            $info[(int)$row['id']] = [
                'name'  => $row['name'] ?? '',
                'email' => $row['email'] ?? '',
                'code'  => $row['code'] ?? '',
            ];
        }

        return $info;
    }

    /**
     * Resolve a free-text participant search to user ids.
     *
     * @param string $search Substring of the user name, email or validation code, or an exact user id.
     * @return int[] Matching user ids (capped at 500).
     */
    private function findUserIdsBySearch($search)
    {
        $like = '%' . addcslashes($search, '\\%_') . '%';
        $sql = "SELECT DISTINCT u.id
                FROM users u
                LEFT JOIN validation_codes vc ON vc.id_users = u.id
                WHERE u.`name` LIKE ? OR u.email LIKE ? OR vc.`code` LIKE ?";
        $params = array($like, $like, $like);
        if (ctype_digit($search)) {
            $sql .= " OR u.id = ?";
            $params[] = (int)$search;
        }

        $rows = $this->db->query_db($sql . " LIMIT 500", $params);
        return is_array($rows) ? array_map('intval', array_column($rows, 'id')) : array();
    }

    /**
     * Display labels for the source types found in the memory history.
     *
     * @return array source_type => label
     */
    private function getActivitySourceLabels()
    {
        return array(
            LLM_MEMORY_SOURCE_FORM_ACTION => 'Form / survey submit',
            LLM_MEMORY_SOURCE_LLM_CHAT_FORM => 'llmChat form submit',
            LLM_MEMORY_SOURCE_LOGIN => 'Login',
            LLM_MEMORY_SOURCE_PROFILE_NAME => 'Profile name change',
            'admin_manual' => 'Admin action',
        );
    }

    /**
     * Describe what triggered a history entry and who caused it.
     * Participants cause every source except admin_manual, where source_ref carries the acting admin.
     *
     * @param array $entry      Hydrated history row.
     * @param array $source_ref Decoded source_ref JSON of the row.
     * @param array $users_info Output of getUsersInfo() covering the participant and acting admin.
     * @return array source_label, source_detail and triggered_by {type, label, name, email}.
     */
    private function describeActivitySource($entry, $source_ref, $users_info)
    {
        $source_type = (string)($entry['source_type'] ?? '');
        $labels = $this->getActivitySourceLabels();
        $detail = '';
        $triggered_by = array('type' => 'participant', 'label' => 'Participant', 'name' => '', 'email' => '');

        if ($source_type === LLM_MEMORY_SOURCE_FORM_ACTION) {
            $form = trim((string)($source_ref['form_name'] ?? ''));
            if ($form === '') {
                $form = trim((string)($source_ref['table_name'] ?? ''));
            }
            $record_id = (int)($source_ref['record_id'] ?? 0);
            $detail = $form . ($record_id > 0 ? ' (record #' . $record_id . ')' : '');
        } elseif ($source_type === LLM_MEMORY_SOURCE_LLM_CHAT_FORM) {
            $parts = array();
            if (!empty($source_ref['section_id'])) {
                $parts[] = 'section #' . (int)$source_ref['section_id'];
            }
            if (!empty($source_ref['conversation_id'])) {
                $parts[] = 'conversation #' . (int)$source_ref['conversation_id'];
            }
            $detail = implode(', ', $parts);
        } elseif ($source_type === 'admin_manual') {
            $actions = array(
                'admin_rerun' => 'Rule re-run',
                'rebuild_from_history' => 'Rebuilt from history',
                'admin_edit' => 'Manual edit',
                'admin_delete' => 'Memory cleared',
            );
            foreach ($actions as $flag => $action_label) {
                if (!empty($source_ref[$flag])) {
                    $detail = $action_label;
                    break;
                }
            }

            $admin_id = (int)($source_ref['admin_user_id'] ?? 0);
            $admin = $users_info[$admin_id] ?? array();
            $triggered_by = array(
                'type' => 'admin',
                'label' => 'Admin',
                'name' => (string)($admin['name'] ?? ($admin_id > 0 ? 'User #' . $admin_id : '')),
                'email' => (string)($admin['email'] ?? ''),
            );
        }

        return array(
            'source_type' => $source_type,
            'source_label' => $labels[$source_type] ?? $source_type,
            'source_detail' => $detail,
            'triggered_by' => $triggered_by,
        );
    }

    /**
     * Options for the activity filters: rules, memory keys, statuses and source types.
     *
     * @param array $rules       Output of LlmMemoryRuleService::listRules().
     * @param array $memory_keys Output of LlmMemoryRuleService::listMemoryKeys().
     * @return array rules, memory_keys, statuses, source_types
     */
    private function getActivityFilterOptions($rules, $memory_keys)
    {
        $rule_options = array();
        foreach ($rules as $rule) {
            $label = trim((string)$rule['label']);
            $rule_options[] = array(
                'id' => (int)$rule['id'],
                'label' => ($label !== '' ? $label : 'Rule') . ' (#' . (int)$rule['id'] . ')',
            );
        }
        // history rows written by admin actions carry rule_id 0
        $rule_options[] = array('id' => 0, 'label' => 'Manual admin action (no rule)');

        $key_options = array();
        foreach ($memory_keys as $key) {
            $key_options[] = array('code' => (string)$key['code'], 'label' => (string)$key['label']);
        }

        $source_type_options = array();
        foreach ($this->getActivitySourceLabels() as $value => $label) {
            $source_type_options[] = array('value' => $value, 'label' => $label);
        }

        return array(
            'rules' => $rule_options,
            'memory_keys' => $key_options,
            'statuses' => array(
                array('value' => LLM_MEMORY_STATUS_APPLIED, 'label' => 'Applied'),
                array('value' => LLM_MEMORY_STATUS_DUPLICATE, 'label' => 'Ignored (duplicate)'),
                array('value' => LLM_MEMORY_STATUS_STALE, 'label' => 'Ignored (stale)'),
            ),
            'source_types' => $source_type_options,
        );
    }

    /**
     * Collect memory write-source definitions from form actions that contain memory-update jobs.
     *
     * @return array Source descriptors with rule_ids, trigger_type, and target metadata.
     */
    private function getFormActionSources()
    {
        $rows = $this->db->query_db(
            "SELECT * FROM view_formActions ORDER BY action_name ASC"
        );

        if (!is_array($rows)) {
            return array();
        }

        $sources = array();
        foreach ($rows as $row) {
            $config = json_decode((string)($row['config'] ?? ''), true);
            if (!is_array($config)) {
                continue;
            }

            foreach ($this->extractMemoryJobsFromConfig($config) as $job) {
                $rule_ids = $this->resolveJobRuleIds($job);
                $sources[] = array(
                    'source_category' => 'form_action',
                    'source_type' => LLM_MEMORY_SOURCE_FORM_ACTION,
                    'rule_ids' => $rule_ids,
                    'trigger_type' => $row['trigger_type'] ?? ($job['trigger_type'] ?? ''),
                    'target_label' => $row['action_name'] ?? ('Action #' . ($row['id'] ?? '')),
                    'target_secondary' => $row['table_name'] ?? ($row['dataTable_name'] ?? ''),
                    'target_id' => (int)($row['id'] ?? 0),
                    'target_url' => $this->buildActionUrl((int)($row['id'] ?? 0)),
                    'details' => array(
                        'table_name' => $row['table_name'] ?? ($row['dataTable_name'] ?? ''),
                        'action_name' => $row['action_name'] ?? '',
                        'job' => $job,
                    ),
                );
            }
        }

        return $sources;
    }

    /**
     * Collect memory write-sources from sections whose llm_memory_rule_ids field selects rules
     * (formUserInput*, llmForm* and surveyJS styles). A finished submission of such a section runs the selected rules.
     *
     * @return array Source descriptors with section/page metadata and resolved rule IDs.
     */
    private function getSectionFieldSources()
    {
        // non-display fields are stored for language 1 / gender 1 (same as the llmChat fallback lookup)
        $rows = $this->db->query_db(
            "SELECT s.id AS section_id, s.name AS section_name, st.name AS style_name, sft.content AS rule_ids
             FROM sections_fields_translation sft
             INNER JOIN fields f ON f.id = sft.id_fields AND f.name = :field_name
             INNER JOIN sections s ON s.id = sft.id_sections
             INNER JOIN styles st ON st.id = s.id_styles
             WHERE sft.id_languages = 1 AND sft.id_genders = 1
               AND TRIM(IFNULL(sft.content, '')) <> ''
             ORDER BY s.id ASC",
            array(':field_name' => LLM_MEMORY_FIELD_RULE_IDS)
        );
        if (!is_array($rows)) {
            return array();
        }

        $rule_ids_by_section = array();
        foreach ($rows as $row) {
            $rule_ids = $this->rule_service->normalizeRuleIds($row['rule_ids'] ?? '');
            if (!empty($rule_ids)) {
                $rule_ids_by_section[(int)$row['section_id']] = $rule_ids;
            }
        }

        $pages = $this->findSectionPages(array_keys($rule_ids_by_section));

        $sources = array();
        foreach ($rows as $row) {
            $section_id = (int)$row['section_id'];
            if (!isset($rule_ids_by_section[$section_id])) {
                continue;
            }

            $page = $pages[$section_id] ?? null;
            $sources[] = array(
                'source_category' => 'section_field',
                'source_type' => LLM_MEMORY_SOURCE_FORM_ACTION,
                'rule_ids' => $rule_ids_by_section[$section_id],
                'trigger_type' => actionTriggerTypes_finished,
                'target_label' => trim((string)$row['section_name']) !== '' ? $row['section_name'] : ('Section #' . $section_id),
                'target_secondary' => $row['style_name'] . ' | ' . ($page ? $page['page_url'] : 'not placed on a page'),
                'target_id' => $section_id,
                'target_url' => $page ? $this->buildCmsSectionUrl($page['page_id'], $page['root_section_id'], $section_id) : null,
                'details' => array(
                    'section_id' => $section_id,
                    'style_name' => $row['style_name'],
                    'page_id' => $page ? $page['page_id'] : null,
                    'page_keyword' => $page ? $page['page_keyword'] : null,
                    'page_url' => $page ? $page['page_url'] : null,
                ),
            );
        }

        return $sources;
    }

    /**
     * Resolve the page each section is shown on. A section can be nested in other sections, so
     * sections_hierarchy is walked upwards until a section attached to a page is found.
     * One query covers all sections.
     *
     * @param int[] $section_ids Section ids to resolve.
     * @return array section_id => [page_id, page_keyword, page_url, root_section_id] (nearest page only).
     */
    private function findSectionPages($section_ids)
    {
        $section_ids = array_values(array_unique(array_filter(array_map('intval', $section_ids))));
        if (empty($section_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($section_ids), '?'));
        $rows = $this->db->query_db(
            "WITH RECURSIVE ancestors (origin, id, depth) AS (
                 SELECT s.id, s.id, 0 FROM sections s WHERE s.id IN ($placeholders)
                 UNION ALL
                 SELECT a.origin, sh.parent, a.depth + 1
                 FROM sections_hierarchy sh
                 INNER JOIN ancestors a ON sh.child = a.id
                 WHERE a.depth < 20
             )
             SELECT a.origin AS section_id, a.id AS root_section_id,
                    p.id AS page_id, p.keyword AS page_keyword, p.url AS page_url
             FROM ancestors a
             INNER JOIN pages_sections ps ON ps.id_sections = a.id
             INNER JOIN pages p ON p.id = ps.id_pages
             ORDER BY a.origin ASC, a.depth ASC, p.id ASC",
            $section_ids
        );

        $pages = array();
        foreach ((array)$rows as $row) {
            $section_id = (int)($row['section_id'] ?? 0);
            if ($section_id > 0 && !isset($pages[$section_id])) {
                $pages[$section_id] = array(
                    'page_id' => (int)$row['page_id'],
                    'page_keyword' => (string)$row['page_keyword'],
                    'page_url' => (string)$row['page_url'],
                    'root_section_id' => (int)$row['root_section_id'],
                );
            }
        }

        return $pages;
    }

    /**
     * Generate the CMS admin URL that opens a section, in the CMS UI the current admin uses
     * (same routing as BaseModel::get_cms_item_url).
     *
     * @param int $page_id         Page the section is shown on.
     * @param int $root_section_id Section attached to the page (the section itself when it is not nested).
     * @param int $section_id      Section to open.
     * @return string|null Router-generated URL, or null if routing fails.
     */
    private function buildCmsSectionUrl($page_id, $root_section_id, $section_id)
    {
        $selected_section_id = (int)$section_id === (int)$root_section_id ? null : (int)$section_id;

        try {
            $router = $this->services->get_router();
            if ($this->services->get_user_input()->is_new_ui_enabled()) {
                return $router->generate('cmsUpdate', array(
                    'pid' => (int)$page_id,
                    'sid' => (int)$root_section_id,
                    'ssid' => $selected_section_id,
                    'mode' => UPDATE,
                    'type' => 'prop',
                ));
            }

            return $router->generate('cmsSelect', array(
                'pid' => (int)$page_id,
                'sid' => (int)$root_section_id,
                'ssid' => $selected_section_id,
            ));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Collect memory write-sources from llmChat sections that have fallback memory_rule_ids configured.
     *
     * @return array Source descriptors with section/page metadata and resolved rule IDs.
     */
    private function getLlmChatFallbackSources()
    {
        $profile_service = new LlmPromptExecutionProfileService($this->services);
        $section_rows = $this->db->query_db(
            "SELECT s.id, s.name AS section_name, p.id AS page_id, p.url AS page_url
             FROM sections s
             INNER JOIN styles st ON st.id = s.id_styles
             LEFT JOIN pages_sections ps ON ps.id_sections = s.id
             LEFT JOIN pages p ON p.id = ps.id_pages
             WHERE st.name = :style_name
             ORDER BY p.id ASC, ps.position ASC, s.id ASC",
            array(':style_name' => 'llmChat')
        );

        if (!is_array($section_rows)) {
            return array();
        }

        $sources = array();
        foreach ($section_rows as $row) {
            $values = $profile_service->getStyleFieldValues((int)$row['id'], 1, array('memory_rule_ids'));
            $rule_ids = $this->rule_service->normalizeRuleIds($values['memory_rule_ids'] ?? '');
            if (empty($rule_ids)) {
                continue;
            }

            $sources[] = array(
                'source_category' => 'llm_chat_fallback',
                'source_type' => LLM_MEMORY_SOURCE_LLM_CHAT_FORM,
                'rule_ids' => $rule_ids,
                'trigger_type' => 'finished',
                'target_label' => $row['section_name'] ?? ('Section #' . $row['id']),
                'target_secondary' => 'llmChat fallback',
                'target_id' => (int)$row['id'],
                'target_url' => !empty($row['page_url']) ? $row['page_url'] : null,
                'details' => array(
                    'section_id' => (int)$row['id'],
                    'section_name' => $row['section_name'] ?? '',
                    'page_id' => !empty($row['page_id']) ? (int)$row['page_id'] : null,
                ),
            );
        }

        return $sources;
    }

    /**
     * Collect memory write-sources from system-level rules (login, profile name change hooks).
     *
     * @return array Source descriptors for enabled rules with system source types.
     */
    private function getSystemRuleSources()
    {
        $sources = array();
        foreach ($this->rule_service->listRules() as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            if (!in_array($rule['source_type'], array(LLM_MEMORY_SOURCE_LOGIN, LLM_MEMORY_SOURCE_PROFILE_NAME), true)) {
                continue;
            }

            $sources[] = array(
                'source_category' => 'system',
                'source_type' => $rule['source_type'],
                'rule_ids' => array((int)$rule['id']),
                'trigger_type' => '',
                'target_label' => $rule['source_type'] === LLM_MEMORY_SOURCE_LOGIN ? 'Login trigger' : 'Profile name change trigger',
                'target_secondary' => 'Plugin hook',
                'target_id' => 0,
                'target_url' => null,
                'details' => array(
                    'rule_id' => (int)$rule['id'],
                ),
            );
        }

        return $sources;
    }

    /**
     * Recursively walk a form-action config tree and extract all memory-update job nodes.
     *
     * @param array $config Form action configuration (potentially nested).
     * @return array Flat list of job nodes whose job_type is LLM_MEMORY_UPDATE.
     */
    private function extractMemoryJobsFromConfig($config)
    {
        $jobs = array();
        $walk = function ($node) use (&$walk, &$jobs) {
            if (!is_array($node)) {
                return;
            }

            if (($node['job_type'] ?? '') === ACTION_JOB_TYPE_LLM_MEMORY_UPDATE || ($node['type'] ?? '') === ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
                $jobs[] = $node;
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($config);
        return $jobs;
    }

    /**
     * Resolve rule IDs from a job node.
     *
     * @param array $job Memory job node with memory_rule_id(s).
     * @return int[] Resolved rule IDs.
     */
    private function resolveJobRuleIds($job)
    {
        return $this->rule_service->normalizeRuleIds($job['memory_rule_id'] ?? ($job['memory_rule_ids'] ?? ''));
    }

    /**
     * Generate the CMS admin URL for a given form action.
     *
     * @param int $action_id Form action ID.
     * @return string|null Router-generated URL, or null for invalid IDs.
     */
    private function buildActionUrl($action_id)
    {
        if ((int)$action_id <= 0) {
            return null;
        }

        return $this->services->get_router()->generate('moduleFormsAction', array('aid' => (int)$action_id, 'mode' => UPDATE));
    }

    /**
     * Enrich source descriptors with resolved rule reference objects (id, label).
     *
     * @param array $sources Source descriptors containing rule_ids arrays.
     * @return array Same sources with an added rule_refs array on each entry.
     */
    private function decorateSourcesWithRuleRefs($sources)
    {
        $rule_index = array();
        foreach ($this->rule_service->listRules() as $rule) {
            $rule_id = (int)($rule['id'] ?? 0);
            if ($rule_id <= 0) {
                continue;
            }

            $rule_index[$rule_id] = array(
                'id' => $rule_id,
                'label' => trim((string)($rule['label'] ?? '')),
            );
        }

        foreach ($sources as &$source) {
            $refs = array();
            foreach ((array)($source['rule_ids'] ?? array()) as $rid) {
                $rid = (int)$rid;
                if ($rid <= 0) {
                    continue;
                }

                if (isset($rule_index[$rid])) {
                    $refs[] = $rule_index[$rid];
                } else {
                    $refs[] = array(
                        'id' => $rid,
                        'label' => '',
                    );
                }
            }
            $source['rule_refs'] = $refs;
        }
        unset($source);

        return $sources;
    }

    /**
     * Normalize a raw memory storage row by decoding JSON fields.
     *
     * @param array $row Raw memory row from storage.
     * @return array Enriched row with decoded memory/payload/source JSON.
     */
    private function normalizeMemoryRow($row)
    {
        if (!is_array($row) || empty($row)) {
            return $row;
        }

        $row['memory_json_decoded'] = $this->decodeJsonField($row['memory_json'] ?? '{}');
        $row['prev_memory_json_decoded'] = $this->decodeJsonField($row['prev_memory_json'] ?? '{}');
        $row['source_ref_decoded'] = $this->decodeJsonField($row['source_ref'] ?? '{}');
        $row['payload_json_decoded'] = $this->decodeJsonField($row['payload_json'] ?? '{}');

        return $row;
    }

    /**
     * Safely decode a JSON string, returning null on failure.
     *
     * @param string $value JSON string.
     * @return mixed Decoded value, or null on parse error.
     */
    private function decodeJsonField($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
?>
