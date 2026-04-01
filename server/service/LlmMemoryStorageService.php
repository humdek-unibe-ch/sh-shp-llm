<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmMemoryConfigService.php';

/**
 * Handles memory storage operations: initializing dataTables,
 * reading effective memory, writing current memory, appending history rows,
 * and flattening fields into columns.
 */
class LlmMemoryStorageService extends BaseLlmService
{
    /** @var LlmMemoryConfigService */
    private $config_service;

    public function __construct($services, ?LlmMemoryConfigService $config_service = null)
    {
        parent::__construct($services);
        $this->config_service = $config_service ?: new LlmMemoryConfigService($services);
    }

    /**
     * Ensure both memory dataTables exist, creating them if needed.
     */
    public function initializeMemoryTables()
    {
        $user_input = $this->services->get_user_input();
        $current_table = $this->config_service->getCurrentTableName();
        $history_table = $this->config_service->getHistoryTableName();

        $this->ensureDataTable($user_input, $current_table, 'LLM Memory (Current)');
        $this->ensureDataTable($user_input, $history_table, 'LLM Memory (History)');
    }

    /**
     * Get the current effective memory for a user and memory key.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @return array|null Memory row or null if not found
     */
    public function getEffectiveMemory($user_id, $memory_key = '')
    {
        if (empty($memory_key)) {
            $memory_key = $this->config_service->getDefaultMemoryKey();
        }

        $storage_mode = $this->config_service->getStorageMode();

        if ($storage_mode === 'log') {
            return $this->getLatestHistoryRow($user_id, $memory_key);
        }

        return $this->getCurrentMemoryRow($user_id, $memory_key);
    }

    /**
     * Save a memory update according to the resolved storage mode.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @param array  $memory_data Keys: memory_text, memory_json, flat_fields, change_summary
     * @param array  $metadata    Keys: rule_key, source_type, source_ref, trigger_type, payload_json, event_at, dedupe_key
     * @param string $storage_mode record|log|both
     * @return bool
     */
    public function saveMemoryUpdate($user_id, $memory_key, $memory_data, $metadata, $storage_mode)
    {
        $success = true;

        if ($storage_mode === 'record' || $storage_mode === 'both') {
            $success = $this->upsertCurrentMemory($user_id, $memory_key, $memory_data, $metadata) && $success;
        }

        if ($storage_mode === 'log' || $storage_mode === 'both') {
            $success = $this->appendHistoryRow($user_id, $memory_key, $memory_data, $metadata) && $success;
        }

        return $success;
    }

    /**
     * Get memory history rows for a user.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    public function getMemoryHistory($user_id, $memory_key = '', $limit = 50, $offset = 0)
    {
        if (empty($memory_key)) {
            $memory_key = $this->config_service->getDefaultMemoryKey();
        }

        $history_table = $this->config_service->getHistoryTableName();
        $user_input = $this->services->get_user_input();
        $table_id = $user_input->get_dataTable_id($history_table);
        if (!$table_id) {
            return [];
        }

        $row_ids = $this->findMatchingRowIds(
            $table_id,
            ['memory_key' => $memory_key],
            $user_id,
            false,
            $limit,
            $offset
        );

        return $this->hydrateRowsByIds($table_id, $row_ids, $user_id);
    }

    /**
     * Get all applied history rows for a user across all memory keys.
     *
     * @param int $user_id
     * @return array
     */
    public function getAppliedHistoryForUser($user_id)
    {
        $history_table = $this->config_service->getHistoryTableName();
        $user_input = $this->services->get_user_input();
        $table_id = $user_input->get_dataTable_id($history_table);
        if (!$table_id) {
            return [];
        }

        $row_ids = $this->findMatchingRowIds(
            $table_id,
            ['update_status' => LLM_MEMORY_STATUS_APPLIED],
            $user_id
        );

        return $this->hydrateRowsByIds($table_id, $row_ids, $user_id);
    }

    /**
     * Get recent history rows across all users for admin overview widgets.
     *
     * @param int $limit
     * @return array
     */
    public function getRecentHistory($limit = 10)
    {
        $history_table = $this->config_service->getHistoryTableName();
        $user_input = $this->services->get_user_input();
        $table_id = $user_input->get_dataTable_id($history_table);
        if (!$table_id) {
            return [];
        }

        $row_ids = $this->findMatchingRowIds($table_id, [], null, false, $limit, 0);
        return $this->hydrateRowsByIds($table_id, $row_ids, null);
    }

    /**
     * Get all user memory entries (for admin listing).
     * Storage-mode-neutral: reads current table first, falls back to
     * history table if current is empty (pure log mode).
     *
     * @param int|null $limit
     * @param int $offset
     * @param array $filters Optional: user_id, memory_key
     * @return array
     */
    public function getMemoryList($limit = null, $offset = 0, $filters = [])
    {
        $user_input = $this->services->get_user_input();
        $user_id_filter = !empty($filters['user_id']) ? (int)$filters['user_id'] : null;
        $memory_key_filter = isset($filters['memory_key']) && $filters['memory_key'] !== ''
            ? (string)$filters['memory_key']
            : null;
        $cell_filters = [];
        if ($memory_key_filter !== null) {
            $cell_filters['memory_key'] = $memory_key_filter;
        }

        $current_table = $this->config_service->getCurrentTableName();
        $current_id = $user_input->get_dataTable_id($current_table);
        if ($current_id) {
            $row_ids = $this->findMatchingRowIds($current_id, $cell_filters, $user_id_filter, false, $limit, $offset);
            $rows = $this->hydrateRowsByIds($current_id, $row_ids, $user_id_filter);
            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        }

        $history_table = $this->config_service->getHistoryTableName();
        $history_id = $user_input->get_dataTable_id($history_table);
        if ($history_id) {
            $history_filters = $cell_filters;
            $history_filters['update_status'] = LLM_MEMORY_STATUS_APPLIED;
            $row_ids = $this->findMatchingRowIds($history_id, $history_filters, $user_id_filter, false, $limit, $offset);
            return $this->hydrateRowsByIds($history_id, $row_ids, $user_id_filter);
        }

        return [];
    }

    /**
     * Check if a dedupe key already exists (checks both current and history tables).
     *
     * @param string $dedupe_key
     * @return bool
     */
    public function dedupeKeyExists($dedupe_key)
    {
        $user_input = $this->services->get_user_input();

        $current_table = $this->config_service->getCurrentTableName();
        $current_id = $user_input->get_dataTable_id($current_table);
        if ($current_id) {
            $row_ids = $this->findMatchingRowIds($current_id, ['last_dedupe_key' => $dedupe_key], null, true, 1, 0);
            if (!empty($row_ids)) {
                return true;
            }
        }

        $history_table = $this->config_service->getHistoryTableName();
        $history_id = $user_input->get_dataTable_id($history_table);
        if ($history_id) {
            $row_ids = $this->findMatchingRowIds(
                $history_id,
                [
                    'dedupe_key' => $dedupe_key,
                    'update_status' => LLM_MEMORY_STATUS_APPLIED,
                ],
                null,
                true,
                1,
                0
            );
            if (!empty($row_ids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the reserved, non-flat memory field names.
     *
     * @return array
     */
    public static function getReservedMemoryFieldNames()
    {
        return [
            'id_users', 'memory_key', 'memory_text', 'memory_json', 'memory_version',
            'last_rule_key', 'last_source_type', 'last_source_ref', 'last_trigger_type',
            'last_payload_json', 'last_updated_at', 'last_event_at', 'last_dedupe_key',
            'prev_memory_json', 'rule_key', 'source_type', 'source_ref', 'trigger_type',
            'payload_json', 'change_summary', 'worker_status', 'created_at', 'event_at',
            'dedupe_key', 'update_status', 'record_id', 'created', 'updated', 'deleted'
        ];
    }

    /**
     * Extract flattened dynamic fields from a memory row.
     *
     * @param array $row
     * @return array
     */
    public function extractFlatFieldsFromRow($row)
    {
        $reserved = array_flip(self::getReservedMemoryFieldNames());
        $flat = [];
        foreach ((array)$row as $key => $value) {
            if (isset($reserved[$key]) || $value === null || $value === '') {
                continue;
            }
            $flat[$key] = $value;
        }
        return $flat;
    }

    /**
     * Check ordering guard: is the incoming event newer than what's stored?
     * Checks both current table and latest history row for robustness.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @param string $event_at ISO timestamp
     * @return bool True if the incoming event is newer or no current state exists
     */
    public function isNewerEvent($user_id, $memory_key, $event_at)
    {
        $incoming = strtotime($event_at);
        $latest_stored = null;

        $current = $this->getCurrentMemoryRow($user_id, $memory_key);
        if ($current && !empty($current['last_event_at'])) {
            $latest_stored = strtotime($current['last_event_at']);
        }

        $latest_history = $this->getLatestHistoryRow($user_id, $memory_key);
        if ($latest_history && !empty($latest_history['event_at'])) {
            $history_ts = strtotime($latest_history['event_at']);
            if ($latest_stored === null || $history_ts > $latest_stored) {
                $latest_stored = $history_ts;
            }
        }

        if ($latest_stored === null) {
            return true;
        }

        return $incoming >= $latest_stored;
    }

    /**
     * Persist an ignored-outcome row to the history table.
     *
     * @param int    $user_id
     * @param string $memory_key
     * @param array  $metadata    Must include update_status = ignored_duplicate|ignored_stale
     * @return bool
     */
    public function persistIgnoredHistory($user_id, $memory_key, $metadata)
    {
        $empty_data = [
            'memory_text'    => '',
            'memory_object'  => [],
            'flat_fields'    => [],
            'change_summary' => 'Skipped: ' . ($metadata['update_status'] ?? 'unknown'),
        ];
        return $this->appendHistoryRow($user_id, $memory_key, $empty_data, $metadata);
    }

    /* Private Methods *********************************************************/

    private function ensureDataTable($user_input, $table_name, $display_name)
    {
        $existing_id = $user_input->get_dataTable_id($table_name);
        if ($existing_id) {
            return $existing_id;
        }

        try {
            $this->db->insert('dataTables', [
                'name'        => $table_name,
                'displayName' => $display_name,
            ]);
            return $this->db->get_last_insert_id();
        } catch (Exception $e) {
            $this->logError('Failed to create memory dataTable: ' . $table_name, ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getCurrentMemoryRow($user_id, $memory_key)
    {
        $current_table = $this->config_service->getCurrentTableName();
        $user_input = $this->services->get_user_input();
        $table_id = $user_input->get_dataTable_id($current_table);
        if (!$table_id) {
            return null;
        }

        $row_ids = $this->findMatchingRowIds($table_id, ['memory_key' => $memory_key], $user_id, true, 1, 0);
        $rows = $this->hydrateRowsByIds($table_id, $row_ids, $user_id, true);
        return $rows ?: null;
    }

    private function getLatestHistoryRow($user_id, $memory_key)
    {
        $history_table = $this->config_service->getHistoryTableName();
        $user_input = $this->services->get_user_input();
        $table_id = $user_input->get_dataTable_id($history_table);
        if (!$table_id) {
            return null;
        }

        $row_ids = $this->findMatchingRowIds(
            $table_id,
            [
                'memory_key' => $memory_key,
                'update_status' => LLM_MEMORY_STATUS_APPLIED,
            ],
            $user_id,
            true,
            1,
            0
        );
        $rows = $this->hydrateRowsByIds($table_id, $row_ids, $user_id, true);
        return $rows ?: null;
    }

    private function hydrateRowsByIds($table_id, $row_ids, $user_id = null, $db_first = false)
    {
        $row_ids = array_values(array_unique(array_filter(array_map('intval', (array)$row_ids))));
        if (empty($row_ids)) {
            return $db_first ? null : [];
        }

        $user_input = $this->services->get_user_input();
        $filter = ' AND record_id IN (' . implode(',', $row_ids) . ') ORDER BY record_id DESC';
        $rows = $user_input->get_data($table_id, $filter, false, $user_id, false);
        if (!is_array($rows) || empty($rows)) {
            return $db_first ? null : [];
        }

        $by_id = [];
        foreach ($rows as $row) {
            if (isset($row['record_id'])) {
                $by_id[(int)$row['record_id']] = $row;
            }
        }

        $ordered = [];
        foreach ($row_ids as $row_id) {
            if (isset($by_id[$row_id])) {
                $ordered[] = $by_id[$row_id];
            }
        }

        if ($db_first) {
            return !empty($ordered) ? $ordered[0] : null;
        }

        return $ordered;
    }

    private function findMatchingRowIds($table_id, $cell_filters = [], $user_id = null, $first_only = false, $limit = null, $offset = 0)
    {
        $params = [':table_id' => (int)$table_id];
        $sql = "SELECT dr.id
                FROM dataRows dr
                WHERE dr.id_dataTables = :table_id";

        if ($user_id !== null && (int)$user_id > 0) {
            $sql .= " AND dr.id_users = :user_id";
            $params[':user_id'] = (int)$user_id;
        }

        $deleted_trigger_id = $this->db->get_lookup_id_by_value(actionTriggerTypes, actionTriggerTypes_deleted);
        if ($deleted_trigger_id) {
            $sql .= " AND IFNULL(dr.id_actionTriggerTypes, 0) <> :deleted_trigger_id";
            $params[':deleted_trigger_id'] = (int)$deleted_trigger_id;
        }

        $index = 0;
        foreach ((array)$cell_filters as $column_name => $value) {
            if ($column_name === '' || $value === null) {
                continue;
            }

            $name_key = ':filter_name_' . $index;
            $value_key = ':filter_value_' . $index;
            $sql .= " AND EXISTS (
                SELECT 1
                FROM dataCols dc
                INNER JOIN dataCells cell ON cell.id_dataCols = dc.id AND cell.id_dataRows = dr.id
                WHERE dc.id_dataTables = dr.id_dataTables
                  AND dc.name = {$name_key}
                  AND cell.value = {$value_key}
            )";
            $params[$name_key] = (string)$column_name;
            $params[$value_key] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
            $index++;
        }

        $sql .= " ORDER BY dr.id DESC";

        if ($first_only) {
            $sql .= " LIMIT 1";
        } elseif ($limit !== null) {
            $sql .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = (int)$limit;
            $params[':offset'] = (int)$offset;
        }

        $rows = $this->db->query_db($sql, $params);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(function ($row) {
            return (int)$row['id'];
        }, $rows);
    }

    private function upsertCurrentMemory($user_id, $memory_key, $memory_data, $metadata)
    {
        $current_table = $this->config_service->getCurrentTableName();
        $user_input = $this->services->get_user_input();

        $fields = $this->buildCurrentMemoryFields($memory_key, $memory_data, $metadata);
        $table_id = $user_input->get_dataTable_id($current_table);
        if (!$table_id) {
            $this->initializeMemoryTables();
            $table_id = $user_input->get_dataTable_id($current_table);
        }

        if (!$table_id) {
            return false;
        }

        try {
            $existing = $this->getCurrentMemoryRow($user_id, $memory_key);
            if ($existing && isset($existing['record_id'])) {
                return $user_input->save_data(
                    transactionTypes_update,
                    TRANSACTION_BY_LLM_MEMORY,
                    $table_id,
                    $fields,
                    $user_id,
                    true,
                    $existing['record_id']
                ) !== false;
            }

            return $user_input->save_data(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_MEMORY,
                $table_id,
                $fields,
                $user_id
            ) !== false;
        } catch (Exception $e) {
            $this->logError('Failed to upsert current memory', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function appendHistoryRow($user_id, $memory_key, $memory_data, $metadata)
    {
        $history_table = $this->config_service->getHistoryTableName();
        $user_input = $this->services->get_user_input();
        $table_id = $user_input->get_dataTable_id($history_table);
        if (!$table_id) {
            $this->initializeMemoryTables();
            $table_id = $user_input->get_dataTable_id($history_table);
        }

        if (!$table_id) {
            return false;
        }

        $current = $this->getCurrentMemoryRow($user_id, $memory_key);
        $prev_json = $current ? ($current['memory_json'] ?? '') : '';

        $fields = $this->buildHistoryFields($memory_key, $memory_data, $metadata, $prev_json);

        try {
            return $user_input->save_data(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_MEMORY,
                $table_id,
                $fields,
                $user_id
            ) !== false;
        } catch (Exception $e) {
            $this->logError('Failed to append memory history row', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function buildCurrentMemoryFields($memory_key, $memory_data, $metadata)
    {
        $fields = [
            'memory_key'        => $memory_key,
            'memory_text'       => $memory_data['memory_text'] ?? '',
            'memory_json'       => is_string($memory_data['memory_object'] ?? null)
                ? $memory_data['memory_object']
                : json_encode($memory_data['memory_object'] ?? [], JSON_UNESCAPED_SLASHES),
            'memory_version'    => date('YmdHis'),
            'last_rule_key'     => $metadata['rule_key'] ?? '',
            'last_source_type'  => $metadata['source_type'] ?? '',
            'last_source_ref'   => $metadata['source_ref'] ?? '',
            'last_trigger_type' => $metadata['trigger_type'] ?? '',
            'last_payload_json' => is_string($metadata['payload_json'] ?? null)
                ? $metadata['payload_json']
                : json_encode($metadata['payload_json'] ?? [], JSON_UNESCAPED_SLASHES),
            'last_updated_at'   => date('Y-m-d H:i:s'),
            'last_event_at'     => $metadata['event_at'] ?? date('Y-m-d H:i:s'),
            'last_dedupe_key'   => $metadata['dedupe_key'] ?? '',
        ];

        $flat_fields = $memory_data['flat_fields'] ?? [];
        if (is_array($flat_fields)) {
            foreach ($flat_fields as $key => $value) {
                $fields[$key] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $fields;
    }

    private function buildHistoryFields($memory_key, $memory_data, $metadata, $prev_json)
    {
        $fields = [
            'memory_key'     => $memory_key,
            'memory_text'    => $memory_data['memory_text'] ?? '',
            'memory_json'    => is_string($memory_data['memory_object'] ?? null)
                ? $memory_data['memory_object']
                : json_encode($memory_data['memory_object'] ?? [], JSON_UNESCAPED_SLASHES),
            'prev_memory_json' => $prev_json,
            'rule_key'       => $metadata['rule_key'] ?? '',
            'source_type'    => $metadata['source_type'] ?? '',
            'source_ref'     => $metadata['source_ref'] ?? '',
            'trigger_type'   => $metadata['trigger_type'] ?? '',
            'payload_json'   => is_string($metadata['payload_json'] ?? null)
                ? $metadata['payload_json']
                : json_encode($metadata['payload_json'] ?? [], JSON_UNESCAPED_SLASHES),
            'change_summary' => $memory_data['change_summary'] ?? '',
            'update_status'  => $metadata['update_status'] ?? LLM_MEMORY_STATUS_APPLIED,
            'created_at'     => date('Y-m-d H:i:s'),
            'event_at'       => $metadata['event_at'] ?? date('Y-m-d H:i:s'),
            'dedupe_key'     => $metadata['dedupe_key'] ?? '',
        ];

        $flat_fields = $memory_data['flat_fields'] ?? [];
        if (is_array($flat_fields)) {
            foreach ($flat_fields as $key => $value) {
                $fields[$key] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $fields;
    }
}
?>
