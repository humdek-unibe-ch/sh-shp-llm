<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Stand-ins for the I/O below the memory storage, shared by the structured
 * memory tests (LlmMemoryUpdateServiceTest, LlmMemoryEditorControllerTest).
 *
 * The code under test runs for real: LlmMemoryStorageService (its row lookups
 * and the fields it writes), LlmMemoryConfigService, LlmMemoryUpdateService,
 * LlmMemoryItems, and the interpolation of the core PageDb. Only what reaches
 * MySQL is replaced:
 *
 *  - MemoryTestStore     the rows of the memory dataTables, kept in an array;
 *  - MemoryTestDb        a PageDb without its constructor: answers the one
 *                        row-id query of the storage service from the store
 *                        and the few lookups around it;
 *  - MemoryTestUserInput a UserInput without its constructor: its three I/O
 *                        methods (get_dataTable_id, get_data, save_data) read
 *                        and write the store; every save_data() is recorded;
 *  - MemoryTestCache     the core Cache without APCu: never a hit, remembers
 *                        what was cleared;
 *  - MemoryTestConfig    the real LlmMemoryConfigService with the module config (storage
 *                        mode) given here and the memory key registry (llm_memory_keys rows,
 *                        with their sections) seeded into MemoryTestDb, read by the real
 *                        LlmMemoryRuleService::listMemoryKeys();
 *  - MemoryTestServices  the Services getters the memory code uses.
 *
 * A query the store does not know is recorded in MemoryTestDb::$unexpected, so
 * a test can assert that nothing reached the database it did not plan for.
 *
 * Not a test itself: required by the tests.
 */

ob_start(); // core files end with stray newlines after their closing tags
require_once dirname(__DIR__, 5) . '/server/service/PageDb.php';
require_once dirname(__DIR__, 5) . '/server/service/Cache.php';
require_once dirname(__DIR__, 5) . '/server/service/UserInput.php';
ob_end_clean();

// the plugin constants come from the plugin (some of its services load the file themselves);
// the core constants the memory code reads are defined here
ob_start();
require_once dirname(__DIR__, 2) . '/server/service/globals.php';
ob_end_clean();

foreach (array(
    'actionTriggerTypes' => 'actionTriggerTypes',
    'actionTriggerTypes_finished' => 'finished',
    'actionTriggerTypes_deleted' => 'deleted',
    'transactionTypes_insert' => 'insert',
    'PROJECT_NAME' => 'selfhelp_test',
    'DEBUG' => 0,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ob_start(); // some plugin files end with stray newlines after their closing tags
require_once dirname(__DIR__, 2) . '/server/service/LlmMemoryConfigService.php';
ob_end_clean();

/** The rows of the memory dataTables. */
class MemoryTestStore
{
    /** @var array table name => id */
    public $tables = array();
    /** @var array row id => array('table' => int, 'user' => int, 'status' => string, 'cells' => array(column => string)) */
    public $rows = array();
    /** @var array every save_data() call: array('by', 'table', 'fields', 'update_based_on', 'own_entries_only') */
    public $saves = array();
    /** @var string name of the table whose save_data() fails (returns false); '' = none */
    public $failing_table = '';
    /** @var callable|null runs at the start of every save_data(): for a write that fails halfway */
    public $before_save = null;

    private $next_row_id = 100;

    /** @return int the table id */
    public function addTable($name)
    {
        $this->tables[$name] = 10 + count($this->tables) + 1;
        return $this->tables[$name];
    }

    /** @return int the row id */
    public function insertRow($table_name, $user_id, array $cells, $status = 'finished')
    {
        $id = ++$this->next_row_id;
        $this->rows[$id] = array(
            'table' => $this->tables[$table_name],
            'user' => (int)$user_id,
            'status' => $status,
            'cells' => array_map('strval', $cells),
        );
        return $id;
    }

    /**
     * Rows of a table, newest first, in the shape the stored procedure returns
     * them: record_id, id_users and one entry per column.
     *
     * @param string   $table_name
     * @param int|null $user_id    only the rows of this user
     * @return array
     */
    public function tableRows($table_name, $user_id = null)
    {
        $rows = array();
        foreach (array_reverse($this->rows, true) as $id => $row) {
            if ($row['table'] !== $this->tables[$table_name]) {
                continue;
            }
            if ($user_id !== null && $row['user'] !== (int)$user_id) {
                continue;
            }
            $rows[] = array('record_id' => $id, 'id_users' => $row['user']) + $row['cells'];
        }
        return $rows;
    }
}

/** The core Cache without APCu. */
class MemoryTestCache extends Cache
{
    /** @var array every clear_cache() call: the type (null = everything) */
    public $cleared = array();

    public function clear_cache($type = null, $id = null)
    {
        $this->cleared[] = $type;
    }

    public function get($key)
    {
        return false;
    }

    public function set($key, $value, $ttl = 0)
    {
    }
}

/** What get_dbh() returns: the connection of MemoryTestDb (transaction state only). */
class MemoryTestDbh
{
    private $db;

    public function __construct(MemoryTestDb $db)
    {
        $this->db = $db;
    }

    public function inTransaction()
    {
        return $this->db->open_transactions > 0;
    }

    public function rollBack()
    {
        $this->db->rollBackStore();
    }
}

/** The core PageDb without MySQL. */
class MemoryTestDb extends PageDb
{
    /** @var array statements the store has no answer for */
    public $unexpected = array();
    /** @var array 'begin' | 'commit' | 'rollback' in the order they happened */
    public $transactions = array();
    /** @var int transactions begun and not ended */
    public $open_transactions = 0;
    /** @var array every insert(): array(table, entries) */
    public $inserts = array();
    /** @var array every update_by_ids(): array(table, entries, ids) */
    public $updates = array();
    /** @var array rows of llm_memory_keys (key_code, label, description, sections_json, enabled) */
    public $memory_keys = array();
    /** @var array|false the llmConversations row the worker looks up */
    public $conversation = array('id' => 7, 'model' => 'test-model');

    private $store;
    /** @var array the rows when the outermost transaction began */
    private $snapshot = array();

    public function __construct(MemoryTestStore $store)
    {
        $this->store = $store;
        $this->cache = new MemoryTestCache(); // BaseDb::$cache, read by get_cache()
    }

    public function get_dbh()
    {
        return new MemoryTestDbh($this);
    }

    /** A transaction of MySQL: the rows are as they were when the outermost one began, after a roll back. */
    public function begin_transaction()
    {
        $this->transactions[] = 'begin';
        if ($this->open_transactions === 0) {
            $this->snapshot = $this->store->rows;
        }
        $this->open_transactions++;
    }

    public function commit()
    {
        $this->transactions[] = 'commit';
        $this->open_transactions = max(0, $this->open_transactions - 1);
    }

    /** What MemoryTestDbh::rollBack() does: nothing written since the outermost begin_transaction() stays. */
    public function rollBackStore()
    {
        $this->transactions[] = 'rollback';
        $this->store->rows = $this->snapshot;
        $this->open_transactions = 0;
    }

    public function get_lookup_id_by_value($type, $value)
    {
        return 91;
    }

    public function insert($table, $entries, $update_entries = array())
    {
        $this->inserts[] = array($table, $entries);
        return count($this->inserts);
    }

    public function update_by_ids($table, $entries, $ids)
    {
        $this->updates[] = array($table, $entries, $ids);
        return true;
    }

    public function query_db($sql, $arguments = array(), $fetch_style = PDO::FETCH_ASSOC)
    {
        if (strpos(ltrim($sql), 'SELECT dr.id') === 0) {
            return $this->matchingRowIds($sql, $arguments);
        }
        if (strpos($sql, 'FROM llm_memory_keys') !== false) {
            return $this->memory_keys;
        }
        $this->unexpected[] = $sql;
        return array();
    }

    public function query_db_first($sql, $arguments = array(), $fetch_style = PDO::FETCH_ASSOC)
    {
        if (strpos($sql, 'FROM llmConversations') !== false) {
            return $this->conversation;
        }
        if (strpos($sql, "SHOW TABLES LIKE 'llm_memory_keys'") !== false) {
            return array('table' => 'llm_memory_keys');
        }
        if (strpos($sql, 'FROM pages WHERE keyword') !== false) {
            return false; // the module config page: there is none here, so the defaults apply
        }
        $this->unexpected[] = $sql;
        return false;
    }

    /**
     * The rows LlmMemoryStorageService::findMatchingRowIds() asks for: the
     * bound parameters say which table, user and cell values; newest first.
     */
    private function matchingRowIds($sql, $arguments)
    {
        $ids = array();
        foreach (array_reverse($this->store->rows, true) as $id => $row) {
            if ($row['table'] !== $arguments[':table_id']) {
                continue;
            }
            if (isset($arguments[':user_id']) && $row['user'] !== $arguments[':user_id']) {
                continue;
            }
            if (isset($arguments[':deleted_trigger_id']) && $row['status'] === 'deleted') {
                continue;
            }
            $matches = true;
            for ($i = 0; isset($arguments[':filter_name_' . $i]); $i++) {
                $name = $arguments[':filter_name_' . $i];
                if (!isset($row['cells'][$name]) || $row['cells'][$name] !== $arguments[':filter_value_' . $i]) {
                    $matches = false;
                }
            }
            if ($matches) {
                $ids[] = $id;
            }
        }
        if (preg_match('/LIMIT (\d+)(?: OFFSET (\d+))?\s*$/', $sql, $limit)) {
            $ids = array_slice($ids, isset($limit[2]) ? (int)$limit[2] : 0, (int)$limit[1]);
        }
        return array_map(function ($id) {
            return array('id' => $id);
        }, $ids);
    }
}

/** The core UserInput without MySQL: the three I/O methods the memory code uses. */
class MemoryTestUserInput extends UserInput
{
    private $store;

    public function __construct(MemoryTestStore $store)
    {
        $this->store = $store;
    }

    public function get_dataTable_id($name)
    {
        return isset($this->store->tables[$name]) ? $this->store->tables[$name] : false;
    }

    public function get_data($form_id, $filter, $own_entries_only = true, $user_id = null, $db_first = false, $exclude_deleted = true, $selected_columns = '')
    {
        $ids = null;
        if (preg_match('/record_id IN \(([0-9,\s]+)\)/', $filter, $match)) {
            $ids = array_map('intval', explode(',', $match[1]));
        }
        $rows = array();
        foreach (array_reverse($this->store->rows, true) as $id => $row) {
            if ($row['table'] !== $form_id || ($ids !== null && !in_array($id, $ids, true))) {
                continue;
            }
            if ($user_id && $row['user'] !== (int)$user_id) {
                continue;
            }
            if ($exclude_deleted && $row['status'] === 'deleted') {
                continue;
            }
            $rows[] = array('record_id' => $id, 'id_users' => $row['user']) + $row['cells'];
        }
        return $db_first ? ($rows ? $rows[0] : false) : $rows;
    }

    public function save_data($transaction_by, $table_name, $data, $updateBasedOn = null, $own_entries_only = true)
    {
        $this->store->saves[] = array(
            'by' => $transaction_by,
            'table' => $table_name,
            'fields' => $data,
            'update_based_on' => $updateBasedOn,
            'own_entries_only' => $own_entries_only,
        );
        if ($this->store->before_save !== null) {
            call_user_func($this->store->before_save, $table_name);
        }
        if ($this->store->failing_table === $table_name) {
            return false;
        }

        $user_id = (int)$data['id_users'];
        $status = isset($data['trigger_type']) ? $data['trigger_type'] : 'finished';
        unset($data['id_users'], $data['trigger_type']);
        $cells = array();
        foreach ($data as $column => $value) {
            $cells[$column] = is_scalar($value) ? (string)$value : '';
        }
        if (!isset($this->store->tables[$table_name])) {
            $this->store->addTable($table_name);
        }

        if ($updateBasedOn) {
            $id = (int)$updateBasedOn['record_id'];
            $row = isset($this->store->rows[$id]) ? $this->store->rows[$id] : null;
            if ($row === null || $row['table'] !== $this->store->tables[$table_name] || ($own_entries_only && $row['user'] !== $user_id)) {
                return false;
            }
            $this->store->rows[$id]['cells'] = $cells + $row['cells'];
            $this->store->rows[$id]['status'] = $status;
            return $id;
        }
        return $this->store->insertRow($table_name, $user_id, $cells, $status);
    }
}

/** The transaction log of the core: remembers the entries. */
class MemoryTestTransactionLog
{
    const TABLE_SCHEDULED_JOBS = 'scheduledJobs';

    /** @var array every add_transaction() call: its arguments */
    public $entries = array();

    public function add_transaction()
    {
        $this->entries[] = func_get_args();
        return true;
    }
}

/** The real rule service without its prompt registry (the tests only read the memory keys through it). */
class MemoryTestRuleService extends LlmMemoryRuleService
{
    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cache = $this->db->get_cache();
    }
}

/**
 * The real config service; the module config comes from here instead of the database and the
 * memory keys are rows of MemoryTestDb::$memory_keys.
 */
class MemoryTestConfig extends LlmMemoryConfigService
{
    /** Sections the tests give a key when they configure nothing (a fixture, not a default of the plugin). */
    const PCG_SECTIONS = array(
        array('code' => 'person', 'label' => 'Person', 'hint' => 'Current personal facts only, no history of changes'),
        array('code' => 'context', 'label' => 'Context', 'hint' => 'Situations, observations, working hypotheses'),
        array('code' => 'goals', 'label' => 'Goals', 'hint' => 'Things the user wants to achieve'),
    );

    private $storage_mode;

    /**
     * @param object $services MemoryTestServices
     * @param array|string|null $keys The key registry: memory key => its sections (a list, or the
     *   stored sections_json text as is); a JSON text {"key": {"sections": [...]}} is read the same
     *   way. '' / null: the keys `global` and `profile` with PCG_SECTIONS.
     * @param string $storage_mode
     */
    public function __construct($services, $keys = null, $storage_mode = 'both')
    {
        if ($keys === null || $keys === '') {
            $keys = array('global' => self::PCG_SECTIONS, 'profile' => self::PCG_SECTIONS);
        } elseif (is_string($keys)) {
            $decoded = json_decode($keys, true);
            $keys = array();
            foreach (is_array($decoded) ? $decoded : array() as $code => $entry) {
                $keys[$code] = $entry['sections'] ?? array();
            }
        }
        foreach ($keys as $code => $sections) {
            $services->get_db()->memory_keys[] = array(
                'key_code' => $code,
                'label' => ucfirst($code),
                'description' => '',
                'sections_json' => is_string($sections) ? $sections : ($sections === array() ? null : json_encode($sections)),
                'enabled' => 1,
            );
        }
        parent::__construct($services, new MemoryTestRuleService($services));
        $this->storage_mode = $storage_mode;
    }

    protected function getLlmConfig()
    {
        return array(
            'llm_memory_enabled' => '1',
            'llm_memory_storage_mode' => $this->storage_mode,
            'llm_default_model' => 'test-model',
        );
    }
}

/** The Services getters the memory code uses. */
class MemoryTestServices
{
    public $db;
    public $user_input;
    public $transaction;

    public function __construct(MemoryTestDb $db, MemoryTestUserInput $user_input)
    {
        $this->db = $db;
        $this->user_input = $user_input;
        $this->transaction = new MemoryTestTransactionLog();
    }

    public function get_db()
    {
        return $this->db;
    }

    public function get_user_input()
    {
        return $this->user_input;
    }

    public function get_transaction()
    {
        return $this->transaction;
    }
}
