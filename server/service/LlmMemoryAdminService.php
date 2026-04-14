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
            $event_at = $entry['last_event_at'] ?? $entry['event_at'] ?? $entry['last_updated_at'] ?? null;
            if ($event_at && (!$user_map[$uid]['last_updated'] || $event_at > $user_map[$uid]['last_updated'])) {
                $user_map[$uid]['last_updated'] = $event_at;
            }
        }

        if ($search !== '') {
            $user_ids = array_keys($user_map);
            $users_info = $this->getUsersInfo($user_ids);
            $search_lower = strtolower($search);
            $user_map = array_filter($user_map, function ($entry) use ($users_info, $search_lower) {
                $uid = $entry['user_id'];
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
            $info = $users_info[$item['user_id']] ?? [];
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

    public function getRecentActivity($limit = 25)
    {
        $recent_activity = $this->storage_service->getRecentHistory((int)$limit);
        $recent_user_ids = array();
        foreach ($recent_activity as $entry) {
            if (!empty($entry['id_users'])) {
                $recent_user_ids[] = (int)$entry['id_users'];
            }
        }
        $recent_users = $this->getUsersInfo(array_values(array_unique($recent_user_ids)));

        return array_map(function ($entry) use ($recent_users) {
            $normalized = $this->normalizeMemoryRow($entry);
            $uid = (int)($normalized['id_users'] ?? 0);
            $normalized['user_name'] = $recent_users[$uid]['name'] ?? ('User #' . $uid);
            return $normalized;
        }, $recent_activity);
    }

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
     * Count how many write sources reference each rule key.
     *
     * @return array
     */
    public function getRuleUsageCounts()
    {
        $counts = array();
        foreach ($this->getWriteSources() as $source) {
            foreach ((array)($source['rule_keys'] ?? array()) as $rule_key) {
                if (!isset($counts[$rule_key])) {
                    $counts[$rule_key] = 0;
                }
                $counts[$rule_key]++;
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
        $sources = array_merge($sources, $this->getLlmChatFallbackSources());
        $sources = array_merge($sources, $this->getSystemRuleSources());
        return $sources;
    }

    /* =========================================================================
     * Write / Action Operations
     * ========================================================================= */

    /**
     * Manually re-run a memory rule for a specific user with an admin-supplied payload.
     *
     * @param int    $user_id
     * @param string $rule_key
     * @param array  $manual_payload   Optional field overrides
     * @return bool
     */
    public function reRunRuleForUser($user_id, $rule_key, $manual_payload = [])
    {
        $rule = $this->config_service->getRuleByKey($rule_key);
        if (!$rule) {
            return false;
        }

        $normalized = [
            'source_type'  => 'admin_manual',
            'source_ref'   => json_encode([
                'admin_rerun' => true,
                'rule_key' => $rule_key,
                'admin_user_id' => $_SESSION['id_user'] ?? null,
            ]),
            'trigger_type' => 'manual',
            'user_id'      => $user_id,
            'event_at'     => date('Y-m-d H:i:s'),
            'fields'       => $manual_payload,
        ];

        $update_service = new LlmMemoryUpdateService($this->services, $this->config_service);

        if ($rule['execution_mode'] === LLM_MEMORY_EXECUTION_DIRECT_MAPPING) {
            return $update_service->executeDirectMapping($rule, $normalized);
        }

        return $update_service->executeLlmSummarization($rule, $normalized);
    }

    /**
     * Rebuild a user's entire memory by re-running all enabled rules.
     *
     * @param int   $user_id
     * @param array $manual_payload  Optional field overrides
     * @return array Rule keys that were run
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

            $flat_fields = $this->storage_service->extractFlatFieldsFromRow($row);
            $memory_data = [
                'memory_text'    => (string)($row['memory_text'] ?? ''),
                'memory_object'  => $memory_object,
                'flat_fields'    => $flat_fields,
                'change_summary' => 'Rebuilt current memory from history.',
            ];

            $metadata = [
                'rule_key'      => 'admin_rebuild_from_history',
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
            'rule_key'      => 'admin_edit',
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
            'rule_key'      => 'admin_delete',
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
                LEFT JOIN view_user_codes vc ON u.id = vc.id_users
                WHERE u.id IN ($placeholders)";

        $rows = $db->query_db($sql, array_values($user_ids));
        foreach ($rows as $row) {
            $info[$row['id']] = [
                'name'  => $row['name'] ?? '',
                'email' => $row['email'] ?? '',
                'code'  => $row['code'] ?? '',
            ];
        }

        return $info;
    }

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
                $rule_keys = $this->resolveJobRuleKeys($job);
                $sources[] = array(
                    'source_category' => 'form_action',
                    'source_type' => LLM_MEMORY_SOURCE_FORM_ACTION,
                    'rule_keys' => $rule_keys,
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
            $values = $profile_service->getStyleFieldValues((int)$row['id'], 1, array('memory_rule_keys'));
            $rule_keys = $this->normalizeRuleKeys($values['memory_rule_keys'] ?? '');
            if (empty($rule_keys)) {
                continue;
            }

            $sources[] = array(
                'source_category' => 'llm_chat_fallback',
                'source_type' => LLM_MEMORY_SOURCE_LLM_CHAT_FORM,
                'rule_keys' => $rule_keys,
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
                'rule_keys' => array($rule['key']),
                'trigger_type' => '',
                'target_label' => $rule['source_type'] === LLM_MEMORY_SOURCE_LOGIN ? 'Login trigger' : 'Profile name change trigger',
                'target_secondary' => 'Plugin hook',
                'target_id' => 0,
                'target_url' => null,
                'details' => array(
                    'rule_id' => $rule['id'],
                    'rule_key' => $rule['key'],
                ),
            );
        }

        return $sources;
    }

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

    private function normalizeRuleKeys($raw)
    {
        if (is_array($raw)) {
            $keys = $raw;
        } else {
            $keys = explode(',', (string)$raw);
        }

        $keys = array_map('trim', $keys);
        $keys = array_values(array_filter(array_unique($keys)));
        return $keys;
    }

    private function normalizeRuleIds($raw)
    {
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $ids = explode(',', (string)$raw);
        }

        $ids = array_map('intval', $ids);
        return array_values(array_filter(array_unique($ids)));
    }

    private function resolveJobRuleKeys($job)
    {
        $rule_keys = $this->normalizeRuleKeys($job['memory_rule_keys'] ?? '');
        if (!empty($rule_keys)) {
            return $rule_keys;
        }

        $rule_ids = $this->normalizeRuleIds($job['memory_rule_id'] ?? ($job['memory_rule_ids'] ?? ''));
        if (empty($rule_ids)) {
            return array();
        }

        $resolved = array();
        foreach ($rule_ids as $rule_id) {
            $rule = $this->rule_service->getRuleById($rule_id);
            if (!empty($rule['key'])) {
                $resolved[] = $rule['key'];
            }
        }

        return array_values(array_unique($resolved));
    }

    private function buildActionUrl($action_id)
    {
        if ((int)$action_id <= 0) {
            return null;
        }

        return '/admin/formsActions/update?aid=' . (int)$action_id;
    }

    private function normalizeMemoryRow($row)
    {
        if (!is_array($row) || empty($row)) {
            return $row;
        }

        $row['flat_fields'] = $this->storage_service->extractFlatFieldsFromRow($row);
        $row['memory_json_decoded'] = $this->decodeJsonField($row['memory_json'] ?? '{}');
        $row['prev_memory_json_decoded'] = $this->decodeJsonField($row['prev_memory_json'] ?? '{}');
        $row['source_ref_decoded'] = $this->decodeJsonField($row['source_ref'] ?? '{}');
        $row['payload_json_decoded'] = $this->decodeJsonField($row['payload_json'] ?? '{}');
        $row['last_source_ref_decoded'] = $this->decodeJsonField($row['last_source_ref'] ?? '{}');
        $row['last_payload_json_decoded'] = $this->decodeJsonField($row['last_payload_json'] ?? '{}');

        return $row;
    }

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
