<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmFormInterviewService (llmFormLogList interview
 * runtime, plugin v1.6.0).
 *
 * No bootstrap, no live database, no LLM calls: a fake DB evaluates the
 * exact SQL emitted by the state service against in-memory
 * llmConversations/llmMessages tables, a stub LlmService returns scripted
 * normalized responses, and a fake form model drives the real
 * LlmFormSchemaService over fake child components.
 *
 * These are unit/fixture tests, NOT integration tests: the real GUMP/save
 * path, page ACL and session layer are exercised only through fakes.
 *
 * Run: php tests/LlmFormInterviewServiceTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');

if (!defined('LLM_CONV_SOURCE_FORM_INTERVIEW')) define('LLM_CONV_SOURCE_FORM_INTERVIEW', 'form_interview');
if (!defined('LLM_CONV_SOURCE_CHAT')) define('LLM_CONV_SOURCE_CHAT', 'chat');
if (!defined('transactionTypes_insert')) define('transactionTypes_insert', 'insert');
if (!defined('transactionTypes_update')) define('transactionTypes_update', 'update');
if (!defined('LLM_CACHE_USER_CONVERSATIONS')) define('LLM_CACHE_USER_CONVERSATIONS', 'llm_user_conversations');
if (!defined('LLM_CACHE_CONVERSATION_MESSAGES')) define('LLM_CACHE_CONVERSATION_MESSAGES', 'llm_conversation_messages');
if (!defined('TRANSACTION_BY_LLM_PLUGIN')) define('TRANSACTION_BY_LLM_PLUGIN', 'llm_plugin');
if (!defined('LLM_RESPONSE_MAX_ATTEMPTS')) define('LLM_RESPONSE_MAX_ATTEMPTS', 3);
// Core constants the danger-alert mail path reads
if (!defined('jobTypes')) define('jobTypes', 'jobTypes');
if (!defined('jobTypes_email')) define('jobTypes_email', 'email');
if (!defined('scheduledJobsStatus')) define('scheduledJobsStatus', 'scheduledJobsStatus');
if (!defined('scheduledJobsStatus_queued')) define('scheduledJobsStatus_queued', 'queued');
if (!defined('transactionBy_by_system')) define('transactionBy_by_system', 'by_system');
if (!defined('SH_EMAIL')) define('SH_EMAIL', 'email');
if (!defined('PF_EMAIL_ACTIVATE_EMAIL_ADDRESS')) define('PF_EMAIL_ACTIVATE_EMAIL_ADDRESS', 'email_activate_email_address');
if (!defined('SELECTED_RECORD_ID')) define('SELECTED_RECORD_ID', 'selected_record_id');
if (!defined('USER_INPUT_HISTORY_LOG_COL')) define('USER_INPUT_HISTORY_LOG_COL', '_history_log');
if (!defined('PLUGIN_PATH')) define('PLUGIN_PATH', '/server/plugins');
// Danger detection built-in defaults (globals.php), used when no section or global value is set
if (!defined('LLM_DEFAULT_DANGER_KEYWORDS')) define('LLM_DEFAULT_DANGER_KEYWORDS', 'suicide,kill myself');
if (!defined('LLM_DEFAULT_DANGER_BLOCKED_MESSAGE')) define('LLM_DEFAULT_DANGER_BLOCKED_MESSAGE', 'Please reach out for help.');
if (!defined('PAGE_LLM_CONFIG')) define('PAGE_LLM_CONFIG', 'sh_module_llm');
if (!defined('PLUGIN_SERVER_PATH')) define('PLUGIN_SERVER_PATH', dirname(dirname(LLM_PATH)));

require_once LLM_PATH . '/service/LlmFormInterviewService.php';
require_once LLM_PATH . '/service/LlmFormSchemaService.php';

/* =========================================================================
 * Fake DB: llmConversations + llmMessages + users + snapshot transactions
 * ========================================================================= */

class InterviewFakeDb
{
    public $conversations = array();
    public $messages = array();
    public $users = array(5 => array('id' => 5), 7 => array('id' => 7));
    public $entries = array();
    public $queries = array();
    private $next_id = 1;
    private $next_msg_id = 1;
    private $lookup_ids = array('llmConversationSourceType' => array(
        'chat' => 1, 'memory' => 2, 'form_interview' => 3));
    private $tx_stack = array();

    public function get_lookup_id_by_value($type, $value)
    {
        return isset($this->lookup_ids[$type][$value]) ? $this->lookup_ids[$type][$value] : null;
    }

    /** @var InterviewFakeRawCache|null one cache for the whole fake so a wipe stays visible */
    private $raw_cache = null;

    public function get_cache()
    {
        if ($this->raw_cache === null) {
            $this->raw_cache = new InterviewFakeRawCache();
        }
        return $this->raw_cache;
    }

    /** @var array held named locks (GET_LOCK) => hold count */
    public $locks = array();
    /** @var array [action, lock name, transaction depth] per GET_LOCK/RELEASE_LOCK */
    public $lock_log = array();
    /** @var bool false makes GET_LOCK time out */
    public $lock_available = true;
    /** @var array page info rows by keyword for fetch_page_info() */
    public $page_info = array();
    /** @var array [table, data, where, transaction depth] per update_by_ids() */
    public $updates = array();

    public function txDepth() { return count($this->tx_stack); }

    public function fetch_page_info($keyword)
    {
        return isset($this->page_info[$keyword]) ? $this->page_info[$keyword] : null;
    }

    public function update_by_ids($table, $data, $where)
    {
        $this->updates[] = array($table, $data, $where, count($this->tx_stack));
        if ($table === 'llmConversations' && isset($where['id'], $this->conversations[(int)$where['id']])) {
            $this->conversations[(int)$where['id']] = array_merge($this->conversations[(int)$where['id']], $data);
        }
        return true;
    }

    public function begin_transaction()
    {
        $this->tx_stack[] = array($this->conversations, $this->messages, $this->next_id, $this->next_msg_id);
    }

    public function commit() { array_pop($this->tx_stack); }

    /** @var array nth CAS state write (see failStateWrite) => Throwable it throws */
    private $fail_state_writes = array();
    private $state_writes = 0;

    /**
     * Failure injection: the $nth state write from now on throws $throwable,
     * inside whatever transaction happens to run it.
     */
    public function failStateWrite($nth, \Throwable $throwable)
    {
        $this->state_writes = 0;
        $this->fail_state_writes = array((int)$nth => $throwable);
    }

    /** @var int BaseDb::rollback() calls: core's version wipes the whole APCu cache */
    public $wipes = 0;

    /** Mirrors BaseDb::rollback(): PDO rollback + typeless clear_cache(). */
    public function rollback()
    {
        $this->wipes++;
        $this->restoreSnapshot();
        $this->get_cache()->clear_cache();
    }

    /** Mirrors BaseDb::get_dbh(): the PDO handle rolls back without touching the cache. */
    public function get_dbh()
    {
        return new InterviewFakeDbh($this);
    }

    public function inTransaction() { return count($this->tx_stack) > 0; }

    public function restoreSnapshot()
    {
        $snapshot = array_pop($this->tx_stack);
        if ($snapshot !== null) {
            list($this->conversations, $this->messages, $this->next_id, $this->next_msg_id) = $snapshot;
        }
    }

    public function seedConversation(array $fields = array())
    {
        $id = $this->next_id++;
        $this->conversations[$id] = array_merge(array(
            'id' => $id, 'title' => 'conv ' . $id, 'id_users' => 0,
            'id_sections' => null, 'id_llm_conversation_sources' => null,
            'id_dataRows' => null, 'context_key' => null, 'state' => null,
            'completed_at' => null, 'deleted' => 0, 'model' => null,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ), $fields);
        return $id;
    }

    public function seedMessage(array $fields = array())
    {
        $id = $this->next_msg_id++;
        $this->messages[$id] = array_merge(array(
            'id' => $id, 'id_llmConversations' => 0, 'role' => 'user',
            'content' => '', 'is_validated' => 1, 'deleted' => 0,
            'id_dataRows' => null, 'timestamp' => '2026-01-01 00:00:00',
        ), $fields);
        return $id;
    }

    private function norm($sql)
    {
        $sql = preg_replace('/`/', '', strtolower($sql));
        return preg_replace('/\s+/', ' ', $sql);
    }

    private function eq($a, $b)
    {
        if ($a === null && $b === null) return true;
        if ($a === null || $b === null) return false;
        return $a == $b;
    }

    private function rowEnvelope(array $row)
    {
        $raw = isset($row['state']) ? $row['state'] : null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw) : null;
        return is_object($decoded) ? $decoded : null;
    }

    private function rowRevision(array $row)
    {
        $env = $this->rowEnvelope($row);
        return ($env !== null && isset($env->revision) && is_int($env->revision))
            ? $env->revision : null;
    }

    private function matches($sql, $args, array $row)
    {
        if (strpos($sql, 'where id = :id') !== false && (int)$row['id'] !== (int)$args[':id']) return false;
        if (strpos($sql, 'deleted = 0') !== false && (int)$row['deleted'] !== 0) return false;
        if (strpos($sql, 'id_users = :sc_user') !== false && (int)$row['id_users'] !== (int)$args[':sc_user']) return false;
        if (strpos($sql, 'id_sections <=>') !== false && !$this->eq($row['id_sections'], $args[':sc_section'])) return false;
        if (strpos($sql, 'id_llm_conversation_sources <=>') !== false && !$this->eq($row['id_llm_conversation_sources'], $args[':sc_source'])) return false;
        if (strpos($sql, 'id_datarows <=>') !== false && !$this->eq($row['id_dataRows'], $args[':sc_row'])) return false;
        if (strpos($sql, 'context_key <=>') !== false && !$this->eq($row['context_key'], $args[':sc_ctx'])) return false;
        if (strpos($sql, 'id_datarows is null') !== false && $row['id_dataRows'] !== null) return false;
        if (strpos($sql, 'completed_at is not null') !== false && $row['completed_at'] === null) return false;
        if (strpos($sql, 'completed_at is null') !== false && $row['completed_at'] !== null) return false;
        if (strpos($sql, "json_extract(state, '$.format_version')") !== false) {
            $env = $this->rowEnvelope($row);
            if ($env === null || !isset($env->format_version) || (int)$env->format_version !== 1) return false;
        }
        if (strpos($sql, "json_type(json_extract(state, '$.revision')) = 'integer'") !== false) {
            $env = $this->rowEnvelope($row);
            if ($env === null || !isset($env->revision) || !is_int($env->revision)) return false;
        }
        if (strpos($sql, "json_type(json_extract(state, '$.payload')) = 'object'") !== false) {
            $env = $this->rowEnvelope($row);
            if ($env === null || !isset($env->payload) || !is_object($env->payload)) return false;
        }
        if (strpos($sql, "json_extract(state, '$.revision')) as unsigned) = :sc_revision") !== false) {
            if ($this->rowRevision($row) !== (int)$args[':sc_revision']) return false;
        }
        return true;
    }

    public function query_db_first($sql, $args = array())
    {
        $sql = $this->norm($sql);
        $this->queries[] = $sql;
        if (strpos($sql, 'get_lock(') !== false) {
            $this->lock_log[] = array('get', $args[':name'], count($this->tx_stack));
            if (!$this->lock_available) {
                return array('acquired' => 0);
            }
            $this->locks[$args[':name']] = (isset($this->locks[$args[':name']]) ? $this->locks[$args[':name']] : 0) + 1;
            return array('acquired' => 1);
        }
        if (strpos($sql, 'release_lock(') !== false) {
            $this->lock_log[] = array('release', $args[':name'], count($this->tx_stack));
            $held = isset($this->locks[$args[':name']]);
            if ($held && --$this->locks[$args[':name']] <= 0) {
                unset($this->locks[$args[':name']]);
            }
            return array('released' => $held ? 1 : 0);
        }
        if (strpos($sql, 'from users') !== false && strpos($sql, 'where id = :id') !== false) {
            return isset($this->users[(int)$args[':id']]) ? $this->users[(int)$args[':id']] : null;
        }
        if (strpos($sql, 'from datarows') !== false && strpos($sql, 'where id = :id') !== false) {
            // Lock/existence primitive only: authorizeRecord() performs the
            // real ownership/existence check against the model's entries.
            return (int)$args[':id'] > 0 ? array('id' => (int)$args[':id']) : null;
        }
        if (strpos($sql, 'from llmconversations') !== false) {
            $rows = array();
            foreach ($this->conversations as $row) {
                if ($this->matches($sql, $args, $row)) {
                    $rows[] = $row;
                }
            }
            usort($rows, function ($a, $b) { return $b['id'] - $a['id']; });
            return isset($rows[0]) ? $rows[0] : null;
        }
        return null;
    }

    public function query_db($sql, $args = array())
    {
        $first = $this->query_db_first($sql, $args);
        return $first === null ? array() : array($first);
    }

    public function execute_query($sql, $args = array())
    {
        $sql = $this->norm($sql);
        $this->queries[] = $sql;
        if (strpos($sql, 'select id from users') !== false) {
            return isset($this->users[(int)$args[':id']]) ? array($this->users[(int)$args[':id']]) : array();
        }
        return 0;
    }

    public function execute_update_db($sql, $args)
    {
        $sql = $this->norm($sql);
        $this->queries[] = $sql;
        if (strpos($sql, 'update llmmessages') !== false && strpos($sql, 'set id_datarows') !== false) {
            $changed = 0;
            foreach ($this->messages as $id => $msg) {
                if ((int)$msg['id_llmConversations'] === (int)$args[':conv'] && (int)$msg['deleted'] === 0) {
                    $this->messages[$id]['id_dataRows'] = $args[':row'];
                    $changed++;
                }
            }
            return $changed;
        }
        if (strpos($sql, 'update llmconversations') === false) {
            return 0;
        }
        $is_state_write = strpos($sql, 'set state = :state') !== false
            && strpos($sql, 'extract(state') !== false;
        if ($is_state_write && isset($this->fail_state_writes[++$this->state_writes])) {
            throw $this->fail_state_writes[$this->state_writes];
        }
        $is_bind = strpos($sql, 'id_datarows = :sc_new_row') !== false;
        $is_init = strpos($sql, 'context_key = :context_key') !== false;
        $changed = 0;
        foreach ($this->conversations as $id => $row) {
            if (!$this->matches($sql, $args, $row)) continue;
            $new = $row;
            if ($is_state_write) {
                $new['state'] = $args[':state'];
            } elseif ($is_bind) {
                $new['id_dataRows'] = (int)$args[':sc_new_row'];
            } elseif (strpos($sql, 'completed_at = coalesce') !== false) {
                $new['completed_at'] = $row['completed_at'] === null ? '2026-01-01 00:00:00' : $row['completed_at'];
            } elseif (strpos($sql, 'completed_at = null') !== false) {
                $new['completed_at'] = null;
            } elseif ($is_init) {
                $new['id_dataRows'] = $args[':id_dataRows'];
                $new['context_key'] = $args[':context_key'];
                $new['state'] = $args[':state'];
            }
            if ($new !== $row) {
                $this->conversations[$id] = $new;
                $changed++;
            }
        }
        return $changed;
    }
}

/** PDO handle stand-in: inTransaction()/rollBack() only, like ExtendedPdo. */
class InterviewFakeDbh
{
    private $db;

    public function __construct(InterviewFakeDb $db) { $this->db = $db; }
    public function inTransaction() { return $this->db->inTransaction(); }
    public function rollBack() { $this->db->restoreSnapshot(); return true; }
}

class InterviewFakeRawCache
{
    const CACHE_TYPE_USER_INPUT = 'USER_INPUT';
    const CACHE_TYPE_SECTIONS = 'CACHE_SECTIONS';

    public $cleared = array();

    /** True when some caller asked for the typeless (global) clear. */
    public function wasWiped() { return in_array(null, $this->cleared, true); }
    public function get($key) { return false; }
    public function set($key, $value, $ttl = 0) { return true; }
    public function delete($key) { return true; }
    public function clear_cache($type = null, $id = null) { $this->cleared[] = $type; return true; }
    public function generate_key($type, $name = null, $args = array()) { return 'k'; }
}

class InterviewFakeCacheManager
{
    public $cleared_users = array();
    public $cleared_conversations = array();
    public function clearUserCache($user_id) { $this->cleared_users[] = $user_id; }
    public function clearConversationCache($id) { $this->cleared_conversations[] = $id; }
    public function clearConversationMessageCache($id) { $this->cleared_conversations[] = $id; }
}

class InterviewFakeTransaction
{
    public $logs = array();
    public function add_transaction($type, $table, $by_user, $by_ip, $user_id, $mid, $id, $name = '')
    {
        $this->logs[] = array($type, $table, $id, $name);
    }
}

class InterviewFakeJobScheduler
{
    /** @var array [job data, transaction depth at send time] */
    public $jobs = array();
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function add_and_execute_job($data, $by)
    {
        $this->jobs[] = array($data, $this->db->txDepth());
        return true;
    }
}

class InterviewFakeServices
{
    private $db;
    private $cache;
    public $scheduler;
    public function __construct($db, $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->scheduler = new InterviewFakeJobScheduler($db);
    }
    public function get_db() { return $this->db; }
    public function get_transaction() { return new InterviewFakeTransaction(); }
    public function get_cache() { return $this->cache; }
    public function get_job_scheduler() { return $this->scheduler; }
}

/**
 * LlmService stub: conversation creation + message log + scripted LLM
 * responses (mirrors callLlmApi's auto-log of one unvalidated assistant
 * message per attempt).
 */
class StubLlmService
{
    private $db;
    /** @var array queue of response contents (JSON strings) or exceptions */
    public $scripted = array();
    public $api_calls = 0;
    public $rate_limited = false;
    /** @var bool updateMessage() reports failure (the merge cannot validate the reply) */
    public $fail_update = false;
    public $on_call = null;
    /** @var array messages of the most recent callLlmApi */
    public $last_messages = array();

    public function __construct(InterviewFakeDb $db)
    {
        $this->db = $db;
    }

    public function createConversation($user_id, $title = null, $model = null,
        $temperature = null, $max_tokens = null, $section_id = null, $source_type = null)
    {
        return $this->db->seedConversation(array(
            'id_users' => (int)$user_id,
            'id_sections' => $section_id,
            'id_llm_conversation_sources' => $this->db->get_lookup_id_by_value('llmConversationSourceType', $source_type),
        ));
    }

    public function addMessage($conversation_id, $role, $content, $attachments = null,
        $model = null, $tokens_used = null, $raw_response = null, $sent_context = null,
        $reasoning = null, $is_validated = true, $request_payload = null)
    {
        return $this->db->seedMessage(array(
            'id_llmConversations' => (int)$conversation_id,
            'role' => $role,
            'content' => (string)$content,
            'is_validated' => $is_validated ? 1 : 0,
        ));
    }

    public function updateMessage($message_id, $data)
    {
        if ($this->fail_update) {
            return false;
        }
        if (isset($this->db->messages[$message_id])) {
            foreach ($data as $k => $v) {
                $this->db->messages[$message_id][$k] = $v;
            }
            return true;
        }
        return false;
    }

    public function getConversationMessages($conversation_id, $limit = 50)
    {
        $msgs = array();
        foreach ($this->db->messages as $m) {
            if ((int)$m['id_llmConversations'] === (int)$conversation_id
                && (int)$m['deleted'] === 0 && (int)$m['is_validated'] === 1) {
                $msgs[] = $m;
            }
        }
        usort($msgs, function ($a, $b) { return $a['id'] - $b['id']; });
        return $msgs;
    }

    public function checkRateLimit($user_id)
    {
        if ($this->rate_limited) {
            throw new LlmRateLimitException('rate limited', 0, array());
        }
        return array('requests' => 0, 'conversations' => array());
    }

    public function updateRateLimit($user_id, $rate_data = null, $conversation_id = null)
    {
    }

    public function callLlmApi($messages, $model, $temperature = null, $max_tokens = null, $log_options = null)
    {
        $this->api_calls++;
        $this->last_messages = $messages;
        if (is_callable($this->on_call)) {
            call_user_func($this->on_call);
        }
        $content = array_shift($this->scripted);
        if ($content instanceof Exception) {
            throw $content;
        }
        $is_validated = isset($log_options['is_validated']) ? (bool)$log_options['is_validated'] : true;
        $logged_id = $this->addMessage(
            (int)$log_options['conversation_id'], 'assistant', $content,
            null, $model, null, null, $log_options['sent_context'] ?? null,
            null, $is_validated, array('payload' => 'request')
        );
        return array(
            'content' => $content,
            'model' => $model,
            'request_payload' => array('model' => $model, 'messages' => $messages),
            'logged_message_id' => $logged_id,
        );
    }
}

/* =========================================================================
 * Fake form model + child components for the real schema service
 * ========================================================================= */

class IntFieldModel extends FormFieldModel
{
    private $fake_style;
    private $fake_fields;
    private $fake_entry;
    private $fake_children;

    public function __construct($style, array $fields, array $entry)
    {
        $this->fake_style = $style;
        $this->fake_fields = $fields;
        $this->fake_entry = $entry;
        $this->fake_children = array();
    }

    public function get_db_field($key, $default = "")
    {
        return array_key_exists($key, $this->fake_fields) ? $this->fake_fields[$key] : $default;
    }

    public function get_db_field_full($key)
    {
        return array_key_exists($key, $this->fake_fields)
            ? array('content' => $this->fake_fields[$key]) : '';
    }

    public function get_style_name() { return $this->fake_style; }
    public function get_condition_result() { return array('result' => true); }
    public function get_children() { return $this->fake_children; }
    public function get_entry_record() { return $this->fake_entry; }
    public function get_form_field_value()
    {
        $name = $this->get_db_field('name');
        return ($name !== '' && array_key_exists($name, $this->fake_entry))
            ? $this->fake_entry[$name] : null;
    }
    public function set_fake_entry(array $entry) { $this->fake_entry = $entry; }
}

class IntChildComponent
{
    private $model;
    private $id_section;
    public function __construct($model, $id_section) { $this->model = $model; $this->id_section = $id_section; }
    public function get_model() { return $this->model; }
    public function get_id_section() { return $this->id_section; }
    public function has_access() { return true; }
}

/**
 * Interview form model stand-in: field 'extra' is eligible only when the
 * draft/entry value 'show_extra' is 'yes' (simulates a conditional branch).
 */
class InterviewFormModel
{
    public $section_id = 88;
    public $retention = 'continue';
    public $context_template = 'Tell the user about the form. Current answers: {{title}}';
    /** Effective declared URL params (core `extra_params`): name => value. */
    public $url_params = array();
    public $manual_edit = true;
    public $hidden_allowlist = array();
    /** Pinned fields: declared URL param with a value that is also a field name => value. */
    public $scope_bound = array();
    public $include_history = false;
    public $history_log = false;
    public $language = 'en';
    public $use_user_language = true;
    public $title_required = true;
    private $base_entry;
    /** Page/loop context supplied at construction (never replaced by a record entry). */
    private $context_entry;
    private $draft = array();
    private $children = array();
    private $entries;

    public function __construct(array $base_entry = array(), array $entries = array())
    {
        $this->base_entry = $base_entry;
        $this->context_entry = $base_entry;
        $this->entries = $entries;
        $this->rebuildInterviewChildren(array());
    }

    public function get_children() { return $this->children; }
    public function get_section_id() { return $this->section_id; }
    public function get_condition_result() { return array('result' => true); }
    public function get_db_field($key, $default = "")
    {
        $map = array(
            'name' => 'interview_form',
            'own_entries_only' => 1,
            'history_log' => $this->history_log ? 1 : 0,
        );
        return array_key_exists($key, $map) ? $map[$key] : $default;
    }
    public function get_form_entry_record($record_id, $own_entries_only)
    {
        return isset($this->entries[(int)$record_id]) ? $this->entries[(int)$record_id] : false;
    }
    public function addEntry($record_id, array $entry)
    {
        $this->entries[(int)$record_id] = $entry;
    }
    public function get_entry_record()
    {
        return array_merge($this->base_entry, $this->draft);
    }
    public function set_entry_record($entry_record)
    {
        $this->base_entry = is_array($entry_record) ? $entry_record : array();
    }
    public function setInterviewBaseEntry(array $entry)
    {
        $this->set_entry_record(array_merge($this->context_entry, $entry));
    }
    public function get_entry_value($entry, $value)
    {
        $replace = array();
        foreach ((array)$entry as $k => $v) {
            $replace['{{' . $k . '}}'] = is_scalar($v) ? (string)$v : json_encode($v);
        }
        return strtr($value, $replace);
    }
    /** Mirrors LlmFormLogListModel: loop/page context is part of the template scope. */
    public function resolveInterviewTemplate($template, array $values)
    {
        return $this->get_entry_value(array_merge($this->context_entry, $values), $template);
    }
    public function getInterviewChatRetention() { return $this->retention; }
    public function getInterviewContextTemplate() { return $this->context_template; }
    public function get_url_params() { return $this->url_params; }
    public function getInterviewIncludeHistory() { return $this->include_history; }
    public function getInterviewWritableHiddenFields() { return $this->hidden_allowlist; }
    public function isInterviewAllowManualEdit() { return $this->manual_edit; }
    public function getScopeBoundValues() { return $this->scope_bound; }
    public function getLlmModel() { return 'test-model'; }
    public function getConfiguredModel() { return $this->getLlmModel(); }
    public function getLlmTemperature() { return 0.7; }
    public function getLlmMaxTokens() { return 2048; }
    public function getLlmReasoningEffort() { return null; }
    public function getUserLanguage() { return $this->language; }
    public function useUserLanguage() { return $this->use_user_language; }
    public function getInterviewApproveLabel() { return 'Save it now'; }
    // Danger detection (keyword pre-scan) is off for this stub.
    public function isDangerDetectionEnabled() { return false; }
    public function getDangerKeywords() { return ''; }
    public function getDangerBlockedMessage() { return 'blocked'; }

    public function rebuildInterviewChildren(array $draft = array())
    {
        $this->draft = $draft;
        $entry = $this->get_entry_record();
        $children = array();
        $children[] = new IntChildComponent(new IntFieldModel('input', array(
            'name' => 'title', 'type_input' => 'text', 'label' => 'Title',
            'is_required' => $this->title_required ? '1' : '0',
        ), $entry), 11);
        $children[] = new IntChildComponent(new IntFieldModel('input', array(
            'name' => 'show_extra', 'type_input' => 'checkbox', 'checkbox_value' => 'yes', 'label' => 'Show extra',
        ), $entry), 12);
        if (isset($entry['show_extra']) && (string)$entry['show_extra'] === 'yes') {
            $children[] = new IntChildComponent(new IntFieldModel('input', array(
                'name' => 'extra', 'type_input' => 'text', 'label' => 'Extra',
            ), $entry), 13);
        }
        $children[] = new IntChildComponent(new IntFieldModel('input', array(
            'name' => 'secret', 'type_input' => 'hidden', 'value' => 's3cr3t',
        ), $entry), 14);
        $this->children = $children;
    }
}

/* =========================================================================
 * Harness
 * ========================================================================= */

$GLOBALS['__checks'] = 0;
$GLOBALS['__fails'] = array();

function check($label, $cond)
{
    $GLOBALS['__checks']++;
    if (!$cond) {
        $GLOBALS['__fails'][] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

function env()
{
    $db = new InterviewFakeDb();
    $cache = new InterviewFakeCacheManager();
    $services = new InterviewFakeServices($db, $cache);
    $llm = new StubLlmService($db);
    $state = new LlmConversationStateService($services, $llm);
    $schema = new LlmFormSchemaService();
    $svc = new LlmFormInterviewService($services, $llm, $state, $schema);
    return array($db, $cache, $services, $llm, $state, $schema, $svc);
}

function valid_interview_response(array $collected = array(), $active = 'title')
{
    return json_encode(array(
        'type' => 'response',
        'safety' => array(
            'is_safe' => true, 'danger_level' => null,
            'detected_concerns' => array(), 'requires_intervention' => false,
        ),
        'content' => array(
            'text_blocks' => array(array('type' => 'text', 'content' => 'What is the title?')),
            'form' => null,
        ),
        'metadata' => array('model' => 'test', 'tokens_used' => null),
        'interview' => array(
            'collected_fields' => (object)$collected,
            'active_field' => $active,
            'ready_for_approval' => false,
        ),
    ));
}

/* =========================================================================
 * 1. open: scoped conversation, draft from initial values, safe projection
 * ========================================================================= */

$_SESSION = array('id_user' => 5);
list($db, $cache, $services, $llm, $state, $schema, $svc) = env();
$model = new InterviewFormModel();

$res = $svc->open($model, null, 'tok-1');
check('open: ok', !empty($res['ok']));
$st = $res['state'];
check('open: conversation id', $st['conversation_id'] > 0);
check('open: revision 0 (fresh)', $st['revision'] === 0);
check('open: record_id null for create scope', $st['record_id'] === null);
check('open: draft excludes hidden secret', !array_key_exists('secret', $st['draft']));
check('open: fields exclude hidden + include title', isset($st['fields']['title']) && !isset($st['fields']['secret']));
check('open: extra absent while show_extra unset', !isset($st['fields']['extra']));
check('open: needs_start true', $st['needs_start'] === true);
check('open: can_approve false while required title missing', $st['can_approve'] === false);
$row = $db->conversations[$st['conversation_id']];
check('open: source tagged form_interview', (int)$row['id_llm_conversation_sources'] === 3);
check('open: unbound id_dataRows NULL', $row['id_dataRows'] === null);
check('open: context_key set (sha256 fallback)', is_string($row['context_key']) && strpos($row['context_key'], 'auto:') === 0);

// second open continues the same conversation
$res2 = $svc->open($model, null, 'tok-2');
check('open: continue retention reuses conversation', $res2['state']['conversation_id'] === $st['conversation_id']);

/* =========================================================================
 * 2. updateDraft: manual patch merged before any remote call
 * ========================================================================= */

$res = $svc->updateDraft($model, null, $st['conversation_id'], 0, array('title' => 'Hello'));
check('updateDraft: ok', !empty($res['ok']));
check('updateDraft: revision bumped to 1', $res['state']['revision'] === 1);
check('updateDraft: title merged', $res['state']['draft']['title'] === 'Hello');
check('updateDraft: can_approve when required filled', $res['state']['can_approve'] === true);

// stale revision rejected
$res = $svc->updateDraft($model, null, $st['conversation_id'], 0, array('title' => 'X'));
check('updateDraft: stale revision conflicts', !$res['ok'] && $res['error']['type'] === 'conflict');

// unknown + non-writable fields rejected
$res = $svc->updateDraft($model, null, $st['conversation_id'], 1, array('nope' => 1));
check('updateDraft: unknown field rejected', !$res['ok'] && $res['error']['type'] === 'validation'
    && isset($res['field_errors']['nope']));
$res = $svc->updateDraft($model, null, $st['conversation_id'], 1, array('secret' => 'x'));
check('updateDraft: non-allowlisted hidden rejected', !$res['ok'] && $res['error']['type'] === 'validation'
    && isset($res['field_errors']['secret']));

/* =========================================================================
 * 3. turn: LLM turn, validated message, patch merge, active field
 * ========================================================================= */

$llm->scripted[] = valid_interview_response(array('title' => 'From LLM'), 'show_extra');
$res = $svc->turn($model, null, $st['conversation_id'], 1, 'hi', array(), 'req-1');
check('turn: ok', !empty($res['ok']));
check('turn: llm patch merged', $res['state']['draft']['title'] === 'From LLM');
check('turn: revision bumped twice (pre-merge + merge)', $res['state']['revision'] === 3);
check('turn: active field applied', $res['state']['progress']['active'] !== null);
$msgs = $res['state']['messages'];
$assistant = array_filter($msgs, function ($m) { return $m['role'] === 'assistant'; });
check('turn: coach message validated + natural', count($assistant) === 1
    && strpos(end($assistant)['content'], 'What is the title?') !== false);
$users = array_filter($msgs, function ($m) { return $m['role'] === 'user'; });
check('turn: participant message logged', count($users) === 1 && end($users)['content'] === 'hi');
check('turn: no unvalidated assistant messages visible', count($assistant) === 1);

// request replay returns without another API call
$calls_before = $llm->api_calls;
$res = $svc->turn($model, null, $st['conversation_id'], 3, 'hi again', array(), 'req-1');
check('turn: request_id replay skips LLM call', !empty($res['replayed']) && $llm->api_calls === $calls_before);

/* =========================================================================
 * 4. turn: semantic invalid responses retry, attempts logged once each
 * ========================================================================= */

$llm->scripted[] = json_encode(array('not' => 'json-schema'));        // invalid
$llm->scripted[] = valid_interview_response(array('secret' => 'pwn')); // semantically invalid (not llm_writable)
$llm->scripted[] = valid_interview_response(array(), 'title');          // valid
$res = $svc->turn($model, null, $st['conversation_id'], 3, 'next', array(), 'req-2');
check('turn retries: ok after invalid attempts', !empty($res['ok']));
check('turn retries: three api calls', $llm->api_calls === 4);
$unvalidated = array_filter($db->messages, function ($m) {
    return $m['role'] === 'assistant' && (int)$m['is_validated'] === 0;
});
check('turn retries: exactly one unvalidated log per invalid attempt', count($unvalidated) === 2);
$asst = array_filter($db->messages, function ($m) {
    return $m['role'] === 'assistant' && (int)$m['is_validated'] === 1;
});
check('turn retries: valid attempt validated exactly once', count($asst) === 2);
check('turn retries: invalid secret patch never merged', !isset($res['state']['draft']['secret']));

/* =========================================================================
 * 5. turn: CAS protects against a late reply overwriting a manual edit
 * ========================================================================= */

list($db_c, $cache_c, $services_c, $llm_c, $state_c, $schema_c, $svc_c) = env();
$model_c = new InterviewFormModel();
$res = $svc_c->open($model_c, null, 'tok-c');
$conv_c = $res['state']['conversation_id'];
$svc_c->updateDraft($model_c, null, $conv_c, 0, array('title' => 'first'));

// baseline: no concurrent edit → llm patch merges
$llm_c->scripted[] = valid_interview_response(array('title' => 'llm-late'), 'title');
$res = $svc_c->turn($model_c, null, $conv_c, 1, 'go', array(), 'req-c');
check('cas: turn ok path applies llm patch when no concurrent edit', !empty($res['ok'])
    && $res['state']['draft']['title'] === 'llm-late');

// concurrent edit fires inside the remote call (after pre-merge commit):
// the merge CAS at the pre-call revision must refuse to clobber it
$llm_c->scripted[] = valid_interview_response(array('title' => 'llm-clobber'), 'title');
$llm_c->on_call = function () use ($svc_c, $model_c, $conv_c) {
    // pre-merge write already bumped the draft to rev 4; this edit lands at 5
    $svc_c->updateDraft($model_c, null, $conv_c, 4, array('title' => 'manual-wins'));
};
$res = $svc_c->turn($model_c, null, $conv_c, 3, 'again', array(), 'req-c2');
$llm_c->on_call = null;
check('cas: late reply conflicts instead of clobbering', !$res['ok'] && $res['error']['type'] === 'conflict');
check('cas: manual edit preserved', $res['state']['draft']['title'] === 'manual-wins');
// the conflicting coach reply stays in the audit log, but unvalidated:
// only an accepted message may be upgraded and shown to the participant
$all_asst = array_filter($db_c->messages, function ($m) {
    return $m['role'] === 'assistant';
});
$validated_asst = array_filter($all_asst, function ($m) {
    return (int)$m['is_validated'] === 1;
});
check('cas: conflicting reply kept in log, unvalidated', count($all_asst) === 2 && count($validated_asst) === 1);

/* =========================================================================
 * 6. Conditional eligibility: value moves to private inactive, restored
 * ========================================================================= */

list($db_e, $c_e, $s_e, $llm_e, $st_e, $sc_e, $svc_e) = env();
$model_e = new InterviewFormModel();
$res = $svc_e->open($model_e, null, 'tok-e');
$conv_e = $res['state']['conversation_id'];
$svc_e->updateDraft($model_e, null, $conv_e, 0, array('show_extra' => 'yes'));
$res = $svc_e->updateDraft($model_e, null, $conv_e, 1, array('extra' => 'keep me'));
check('inactive: extra merged when eligible', !empty($res['ok']) && $res['state']['draft']['extra'] === 'keep me');
$res = $svc_e->updateDraft($model_e, null, $conv_e, 2, array('show_extra' => 'no'));
check('inactive: extra removed from projection when branch closes', !empty($res['ok'])
    && !isset($res['state']['draft']['extra']) && !isset($res['state']['fields']['extra']));
$env_e = $st_e->readState($db_e->conversations[$conv_e]);
check('inactive: value retained privately in inactive_values',
    isset($env_e['payload']['inactive_values']['extra']) && $env_e['payload']['inactive_values']['extra'] === 'keep me');
$res = $svc_e->updateDraft($model_e, null, $conv_e, 3, array('show_extra' => 'yes'));
check('inactive: value restored when branch re-opens', !empty($res['ok'])
    && $res['state']['draft']['extra'] === 'keep me');

/* =========================================================================
 * 7. Scope isolation: wrong section / foreign record / wrong actor
 * ========================================================================= */

$other_model = new InterviewFormModel();
$other_model->section_id = 999;
$res = $svc_e->read($other_model, null, $conv_e);
check('scope: wrong section rejects read', !$res['ok'] && $res['error']['type'] === 'not_found');

$_SESSION['id_user'] = 7;
$res = $svc_e->read($model_e, null, $conv_e);
check('scope: foreign actor rejects read', !$res['ok'] && $res['error']['type'] === 'not_found');
$_SESSION['id_user'] = 5;

$bound_model = new InterviewFormModel(array(), array(
    42 => array('record_id' => 42, 'title' => 'Saved', 'id_users' => 5),
));
$res_b = $svc_e->open($bound_model, 42, 'tok-b');
check('scope: bound record opens', !empty($res_b['ok']) && $res_b['state']['record_id'] === 42);
$res = $svc_e->read($bound_model, 43, $res_b['state']['conversation_id']);
check('scope: foreign record rejected (unauthorized)', !$res['ok'] && $res['error']['type'] === 'forbidden');
$model_99 = new InterviewFormModel(array(), array(99 => array('record_id' => 99, 'title' => 'Other', 'id_users' => 5)));
$res = $svc_e->read($model_99, 99, $res_b['state']['conversation_id']);
check('scope: authorized-but-wrong record rejected', !$res['ok'] && $res['error']['type'] === 'not_found');

/* =========================================================================
 * 7b. The opened entry is not copied into the conversation
 *
 * base_record_fingerprint is what detects a changed record. A verbatim copy
 * of the opened entry in the thread state (every stored column, not only the
 * form fields) is never read, so it must not be stored. The draft itself
 * legitimately holds the form fields' initial values.
 * ========================================================================= */

$entry_model = new InterviewFormModel(array(), array(
    61 => array('record_id' => 61, 'title' => 'Participant answer', 'internal_note' => 'private-non-field-value', 'id_users' => 5),
));
$res_p = $svc_e->open($entry_model, 61, 'tok-p');
check('opening_context: the bound record opens', !empty($res_p['ok']));
$conv_p = $res_p['state']['conversation_id'];
$env_p = $st_e->readState($db_e->conversations[$conv_p]);
$opening = isset($env_p['payload']['opening_context']) ? $env_p['payload']['opening_context'] : null;
check('opening_context: still records the context key and the open time',
    is_array($opening) && array_key_exists('context_key', $opening) && !empty($opening['opened_at']));
check('opening_context: the participant entry is not copied', is_array($opening) && !array_key_exists('entry', $opening));
check('opening_context: entry data that is not a form field is nowhere in the stored state',
    strpos((string)$db_e->conversations[$conv_p]['state'], 'private-non-field-value') === false);
check('opening_context: the changed-record fingerprint is still stored',
    !empty($env_p['payload']['base_record_fingerprint']));

/* =========================================================================
 * 8. Approval: prepare + complete (outer tx), binding, receipt, idempotency
 * ========================================================================= */

list($db_a, $c_a, $s_a, $llm_a, $state_a, $sc_a, $svc_a) = env();
$model_a = new InterviewFormModel();
$res = $svc_a->open($model_a, null, 'tok-a');
$conv_a = $res['state']['conversation_id'];
$svc_a->updateDraft($model_a, null, $conv_a, 0, array('title' => 'Done'));
$db_a->seedMessage(array('id_llmConversations' => $conv_a, 'role' => 'user', 'content' => 'm1'));
$db_a->seedMessage(array('id_llmConversations' => $conv_a, 'role' => 'assistant', 'content' => 'm2'));

// required missing → approval blocked (distinct scope so it is a fresh draft)
$model_incomplete = new InterviewFormModel();
$model_incomplete->url_params = array('scope' => 'incomplete');
$res_inc = $svc_a->open($model_incomplete, null, 'tok-inc');
$db_a->begin_transaction();
$prep = $svc_a->prepareApproval($model_incomplete, null, $res_inc['state']['conversation_id'], 0, 'req-a');
LlmTransaction::rollback($db_a);
check('approve: incomplete draft rejected with field errors', !$prep['ok']
    && $prep['error']['type'] === 'validation' && isset($prep['field_errors']['title']));

// happy path: prepare → (core write simulated) → complete
$db_a->begin_transaction();
$prep = $svc_a->prepareApproval($model_a, null, $conv_a, 1, 'req-a');
check('approve: prepare ok', !empty($prep['ok']));
check('approve: POST carries __form_name', $prep['post']['__form_name'] === 'interview_form');
check('approve: POST field carries section id + value',
    isset($prep['post']['title']) && $prep['post']['title']['id'] === 11 && $prep['post']['title']['value'] === 'Done');
check('approve: no selected_record_id for create scope', !isset($prep['post'][SELECTED_RECORD_ID]));
check('approve: no internal names in POST', !isset($prep['post']['_history_log']) && !isset($prep['post']['id_users']));

$done = $svc_a->completeApproval($model_a, $prep, 555);
check('approve: complete ok', !empty($done['ok']));
// the core write produced row 555; it is now part of the actor's records
$model_a->addEntry(555, array('record_id' => 555, 'title' => 'Done', 'id_users' => 5));
check('approve: receipt has record id', $done['approval']['record_id'] === 555);
check('approve: receipt echoes request token', $done['approval']['request_token'] === 'req-a');
$db_a->commit();
$conv_row = $db_a->conversations[$conv_a];
check('approve: conversation bound to row', (int)$conv_row['id_dataRows'] === 555);
check('approve: completed_at set', $conv_row['completed_at'] !== null);
$bound = array_filter($db_a->messages, function ($m) use ($conv_a) { return $m['id_llmConversations'] === $conv_a; });
check('approve: audit messages bound to row', count($bound) === 2
    && array_reduce($bound, function ($c, $m) { return $c && (int)$m['id_dataRows'] === 555; }, true));

// duplicate approve with same token → original receipt, no new write
$db_a->begin_transaction();
$prep2 = $svc_a->prepareApproval($model_a, null, $conv_a, 99, 'req-a');
check('approve: idempotent replay returns receipt', !empty($prep2['ok'])
    && !empty($prep2['already_completed']) && $prep2['receipt']['record_id'] === 555);
$db_a->commit();

// different token on completed conversation → conflict, not a new write
$db_a->begin_transaction();
$prep3 = $svc_a->prepareApproval($model_a, null, $conv_a, 99, 'req-other');
check('approve: different token on completed conflicts', !$prep3['ok'] && $prep3['error']['type'] === 'conflict');
$db_a->commit();

// the token is stored in the conversation state: its length is capped
$db_a->begin_transaction();
$prep_long = $svc_a->prepareApproval($model_a, null, $conv_a, 99, str_repeat('t', LlmFormInterviewService::MAX_TOKEN_LENGTH + 1));
LlmTransaction::rollback($db_a);
check('approve: over-long request token rejected', !$prep_long['ok']
    && $prep_long['error']['type'] === 'validation' && strpos($prep_long['error']['message'], 'too long') !== false);
$db_a->begin_transaction();
$prep_max = $svc_a->prepareApproval($model_a, null, $conv_a, 99, str_repeat('t', LlmFormInterviewService::MAX_TOKEN_LENGTH));
$db_a->commit();
check('approve: a request token at the length cap passes validation', !$prep_max['ok'] && $prep_max['error']['type'] === 'conflict');

/* =========================================================================
 * 9. Rollback: failed completion restores draft state
 * ========================================================================= */

list($db_r, $c_r, $s_r, $llm_r, $state_r, $sc_r, $svc_r) = env();
$model_r = new InterviewFormModel();
$res = $svc_r->open($model_r, null, 'tok-r');
$conv_r = $res['state']['conversation_id'];
$svc_r->updateDraft($model_r, null, $conv_r, 0, array('title' => 'Keep'));
$db_r->begin_transaction();
$prep = $svc_r->prepareApproval($model_r, null, $conv_r, 1, 'req-r');
check('rollback: prepare ok', !empty($prep['ok']));
$bad = $svc_r->completeApproval($model_r, $prep, 0);
check('rollback: bad write result fails', !$bad['ok']);
LlmTransaction::rollback($db_r);
$row_r = $db_r->conversations[$conv_r];
check('rollback: conversation still unbound', $row_r['id_dataRows'] === null);
check('rollback: not completed', $row_r['completed_at'] === null);
$env_r = $state_r->readState($row_r);
check('rollback: phase restored to pre-approval revision',
    $env_r['revision'] === 1 && $env_r['payload']['phase'] === 'draft');

/* =========================================================================
 * 10. Bound-scope approval preserves scope + fingerprint stale check
 * ========================================================================= */

list($db_b, $c_b, $s_b, $llm_b, $state_b, $sc_b, $svc_b) = env();
$entry_b = array('record_id' => 42, 'title' => 'Orig', 'id_users' => 5);
$model_b = new InterviewFormModel(array('record_id' => 42, 'title' => 'Orig'), array(42 => $entry_b));
$res = $svc_b->open($model_b, 42, 'tok-bb');
$conv_b = $res['state']['conversation_id'];
$res_u = $svc_b->updateDraft($model_b, 42, $conv_b, 0, array('title' => 'Edited'));
check('bound: draft edit ok', !empty($res_u['ok']));

// stale row: external edit changes fingerprint
$model_b_stale = new InterviewFormModel(array('record_id' => 42, 'title' => 'Orig'), array(
    42 => array('record_id' => 42, 'title' => 'Changed-externally', 'id_users' => 5),
));
$db_b->begin_transaction();
$prep_s = $svc_b->prepareApproval($model_b_stale, 42, $conv_b, 1, 'req-s');
check('bound: stale record fingerprint conflicts', !$prep_s['ok'] && $prep_s['error']['type'] === 'conflict');
LlmTransaction::rollback($db_b);

// fresh: same fingerprint approves; update carries SELECTED_RECORD_ID
$db_b->begin_transaction();
$prep_b = $svc_b->prepareApproval($model_b, 42, $conv_b, 1, 'req-b');
check('bound: prepare ok', !empty($prep_b['ok']));
check('bound: POST carries selected_record_id', isset($prep_b['post'][SELECTED_RECORD_ID])
    && (int)$prep_b['post'][SELECTED_RECORD_ID] === 42);
$done_b = $svc_b->completeApproval($model_b, $prep_b, 42);
check('bound: complete ok (no rebind needed)', !empty($done_b['ok']));
$db_b->commit();
check('bound: conversation stays bound to 42', (int)$db_b->conversations[$conv_b]['id_dataRows'] === 42);
check('bound: completed', $db_b->conversations[$conv_b]['completed_at'] !== null);

/* =========================================================================
 * 11. Retention policies
 * ========================================================================= */

list($db_t, $c_t, $s_t, $llm_t, $state_t, $sc_t, $svc_t) = env();
$m_new_open = new InterviewFormModel();
$m_new_open->retention = 'new_on_open';
$r1 = $svc_t->open($m_new_open, null, 'tok-1');
$r2 = $svc_t->open($m_new_open, null, 'tok-1');
check('retention: same open token reuses', $r2['state']['conversation_id'] === $r1['state']['conversation_id']);
$r3 = $svc_t->open($m_new_open, null, 'tok-2');
check('retention: new open token creates new', $r3['state']['conversation_id'] !== $r1['state']['conversation_id']);

$m_new_save = new InterviewFormModel();
$m_new_save->retention = 'new_after_save';
$ra = $svc_t->open($m_new_save, null, 't');
$conv_ns = $ra['state']['conversation_id'];
$svc_t->updateDraft($m_new_save, null, $conv_ns, 0, array('title' => 'x'));
$db_t->begin_transaction();
$prep_ns = $svc_t->prepareApproval($m_new_save, null, $conv_ns, 1, 'req-ns');
$done_ns = $svc_t->completeApproval($m_new_save, $prep_ns, 777);
$db_t->commit();
$rb = $svc_t->open($m_new_save, null, 't2');
check('retention: new_after_save creates fresh after completion', $rb['state']['conversation_id'] !== $conv_ns);
$m_cont = new InterviewFormModel();
$rc = $svc_t->open($m_cont, null, 't');
$svc_t->updateDraft($m_cont, null, $rc['state']['conversation_id'], 0, array('title' => 'y'));
$db_t->begin_transaction();
$pc = $svc_t->prepareApproval($m_cont, null, $rc['state']['conversation_id'], 1, 'req-c');
$dc = $svc_t->completeApproval($m_cont, $pc, 778);
$db_t->commit();
// the approved draft is bound to the saved row: a fresh create-scope open
// starts a new thread
$rd = $svc_t->open($m_cont, null, 't2');
check('retention: continue create-scope opens new thread after binding',
    $rd['state']['conversation_id'] !== $rc['state']['conversation_id']);
// the completed thread continues under its bound record scope
$m_cont_bound = new InterviewFormModel(
    array('record_id' => 778, 'title' => 'y'),
    array(778 => array('record_id' => 778, 'title' => 'y', 'id_users' => 5))
);
$rd2 = $svc_t->open($m_cont_bound, 778, 't3');
check('retention: continue returns completed thread for its bound record',
    $rd2['state']['conversation_id'] === $rc['state']['conversation_id']
    && $rd2['state']['completed_at'] !== null);
// a new edit reopens it
$re = $svc_t->updateDraft($m_cont_bound, 778, $rc['state']['conversation_id'], 3, array('title' => 'again'));
check('retention: edit on completed reopens', !empty($re['ok']) && $re['state']['completed_at'] === null);

/* =========================================================================
 * 12. Unauthenticated + bad input
 * ========================================================================= */

unset($_SESSION['id_user']);
$res = $svc_t->open($m_cont, null, 't');
check('auth: unauthenticated open rejected', !$res['ok'] && $res['error']['type'] === 'unauthenticated');
$_SESSION['id_user'] = 5;

$res = $svc_t->turn($m_cont, null, $rc['state']['conversation_id'], 3, '   ');
check('input: empty message rejected', !$res['ok'] && $res['error']['type'] === 'validation');
$res = $svc_t->turn($m_cont, null, $rc['state']['conversation_id'], 'abc', 'x');
check('input: non-numeric revision rejected', !$res['ok'] && $res['error']['type'] === 'validation');

// Size caps are answered before a transaction, lock or LLM call. A request
// at the cap gets past validation (a stale revision then fails as a conflict).
check('input: the shipped caps are the documented ones (10000-character message, 191-character tokens)',
    LlmFormInterviewService::MAX_MESSAGE_LENGTH === 10000 && LlmFormInterviewService::MAX_TOKEN_LENGTH === 191);
$queries_before = count($db_t->queries);
$messages_before = count($db_t->messages);
$res = $svc_t->turn($m_cont, null, $rc['state']['conversation_id'], 3,
    str_repeat('x', LlmFormInterviewService::MAX_MESSAGE_LENGTH + 1), array(), 'req-long');
check('input: over-long message rejected', !$res['ok'] && $res['error']['type'] === 'validation'
    && strpos($res['error']['message'], 'Message is too long') !== false);
$res = $svc_t->turn($m_cont, null, $rc['state']['conversation_id'], 3,
    str_repeat("\xC3\xA4", LlmFormInterviewService::MAX_MESSAGE_LENGTH + 1), array(), 'req-long2');
check('input: the message cap counts characters', !$res['ok'] && $res['error']['type'] === 'validation'
    && strpos($res['error']['message'], 'Message is too long') !== false);
$res = $svc_t->turn($m_cont, null, $rc['state']['conversation_id'], 3, 'hi',
    array(), str_repeat('r', LlmFormInterviewService::MAX_TOKEN_LENGTH + 1));
check('input: over-long request id rejected', !$res['ok'] && $res['error']['type'] === 'validation'
    && strpos($res['error']['message'], 'request id is too long') !== false);
$open_queries_before = count($db_t->queries);
$res = $svc_t->open($m_cont, null, str_repeat('o', LlmFormInterviewService::MAX_TOKEN_LENGTH + 1));
check('input: over-long open token rejected', !$res['ok'] && $res['error']['type'] === 'validation'
    && strpos($res['error']['message'], 'open token is too long') !== false);
check('input: open token size cap is answered before any database work',
    count($db_t->queries) === $open_queries_before && $db_t->txDepth() === 0);
check('input: size caps are answered before any database work',
    count($db_t->queries) === $queries_before && count($db_t->messages) === $messages_before
    && $db_t->txDepth() === 0);
$res = $svc_t->turn($m_cont_bound, 778, $rc['state']['conversation_id'], 99,
    str_repeat('x', LlmFormInterviewService::MAX_MESSAGE_LENGTH), array(), 'req-cap');
check('input: a message at the cap passes validation', !$res['ok'] && $res['error']['type'] === 'conflict');
$res = $svc_t->turn($m_cont_bound, 778, $rc['state']['conversation_id'], 99, 'hi',
    array(), str_repeat('r', LlmFormInterviewService::MAX_TOKEN_LENGTH));
check('input: a request id at the cap passes validation', !$res['ok'] && $res['error']['type'] === 'conflict');

/* =========================================================================
 * 13. View escaping: quote + <script> payloads context-escaped
 * ========================================================================= */

// Load the view through a subclass to exercise the private escape path
// without a constructor (BaseView wiring needs real services).
$view_class_ok = true;
$view_err = '';
try {
    if (!class_exists('LlmFormLogListView')) {
        require_once LLM_PATH . '/component/style/llmFormLogList/LlmFormLogListView.php';
    }
} catch (\Throwable $e) {
    $view_class_ok = false;
    $view_err = get_class($e) . ': ' . $e->getMessage();
}
check('escaping: view class loads standalone' . ($view_err !== '' ? ' [' . $view_err . ']' : ''),
    $view_class_ok && class_exists('LlmFormLogListView'));

if ($view_class_ok) {
    $ref = new ReflectionClass('LlmFormLogListView');
    $view = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('escapeFieldValue');
    $m->setAccessible(true);

    $field_style = new class {
        public function get_model() {
            return new class {
                public function get_style_name() { return 'input'; }
            };
        }
    };
    $payload = '"><script>alert(1)</script>';
    $escaped = $m->invoke($view, $field_style, $payload);
    check('escaping: <script> payload escaped for input',
        $escaped === htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'));
    $quote = "it's \"quoted\" <b>x</b>";
    $escaped_q = $m->invoke($view, $field_style, $quote);
    check('escaping: quote payload escaped for input',
        $escaped_q === htmlspecialchars($quote, ENT_QUOTES, 'UTF-8'));

    $ta_style = new class {
        public function get_model() {
            return new class {
                public function get_style_name() { return 'textarea'; }
            };
        }
    };
    check('escaping: textarea payload escaped',
        $m->invoke($view, $ta_style, $payload) === htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'));

    $sel_style = new class {
        public function get_model() {
            return new class {
                public function get_style_name() { return 'select'; }
            };
        }
    };
    check('escaping: select option key kept raw (SelectView escapes at render)',
        $m->invoke($view, $sel_style, 'opt-"1"') === 'opt-"1"');
}

/* =========================================================================
 * 14. Review round: language lock, quick replies, read-only echo, scope key
 * ========================================================================= */

list($db_l, $cache_l, $services_l, $llm_l, $state_l, $schema_l, $svc_l) = env();
$model_l = new InterviewFormModel();
$res = $svc_l->open($model_l, null, 'tok-l');
$conv_l = $res['state']['conversation_id'];

function language_lock_messages(array $messages)
{
    return array_values(array_filter($messages, function ($m) {
        return $m['role'] === 'system' && strpos($m['content'], 'CRITICAL LANGUAGE INSTRUCTION') === 0;
    }));
}

// English is instructed too - a German author context must not win.
$llm_l->scripted[] = valid_interview_response(array(), 'title');
$res = $svc_l->turn($model_l, null, $conv_l, 0, 'hello', array(), 'req-l1');
$lock = language_lock_messages($llm_l->last_messages);
check('language: en turn carries exactly one language lock', count($lock) === 1
    && strpos($lock[0]['content'], 'Respond in English') !== false
    && strpos($lock[0]['content'], 'ONLY English') !== false);
$rev = $res['state']['revision'];

$model_l->language = 'de';
$llm_l->scripted[] = valid_interview_response(array(), 'title');
$res = $svc_l->turn($model_l, null, $conv_l, $rev, 'hallo', array(), 'req-l2');
$lock = language_lock_messages($llm_l->last_messages);
check('language: de turn switches the lock', count($lock) === 1
    && strpos($lock[0]['content'], 'Antworte auf Deutsch') !== false);
$rev = $res['state']['revision'];
check('language: lock follows the protocol message', $llm_l->last_messages[0]['role'] === 'system'
    && strpos($llm_l->last_messages[0]['content'], 'CRITICAL LANGUAGE INSTRUCTION') !== 0);

// Quick replies: sanitized unique list, replaced by the next answer.
$with = json_decode(valid_interview_response(array(), 'title'), true);
$with['interview']['collected_fields'] = new stdClass(); // keep a JSON object
$with['content']['suggestions'] = array(
    array('text' => 'Resilienz verstehen'),
    array('text' => "Beziehungen\npflegen"),
    array('text' => 'Resilienz verstehen'),
    array('text' => ''),
);
$llm_l->scripted[] = json_encode($with);
$res = $svc_l->turn($model_l, null, $conv_l, $rev, 'which modules?', array(), 'req-l3');
check('suggestions: sanitized unique list projected',
    !empty($res['ok']) && $res['state']['suggestions'] === array('Resilienz verstehen', 'Beziehungen pflegen'));
$rev = $res['state']['revision'];
$llm_l->scripted[] = valid_interview_response(array(), 'title');
$res = $svc_l->turn($model_l, null, $conv_l, $rev, 'anything', array(), 'req-l4');
check('suggestions: cleared by the next answer', $res['state']['suggestions'] === array());
$rev = $res['state']['revision'];

// A read-only field repeated with its own value is ignored; a changed one is not.
$calls = $llm_l->api_calls;
$llm_l->scripted[] = valid_interview_response(array('secret' => 's3cr3t', 'title' => 'Echo ok'), 'title');
$res = $svc_l->turn($model_l, null, $conv_l, $rev, 'echo', array(), 'req-l5');
check('echo: unchanged read-only value does not reject the turn',
    !empty($res['ok']) && $llm_l->api_calls === $calls + 1);
check('echo: writable value of the same turn still merged', $res['state']['draft']['title'] === 'Echo ok');
check('echo: read-only value stays out of the projection', !array_key_exists('secret', $res['state']['draft']));

// The declared URL params (core `extra_params`) are the context key: sorted
// `name=value&...`, every declared name counts (empty when absent), and a
// key above the VARCHAR(191) column is hashed instead of rejected.
list($db_k, $cache_k, $services_k, $llm_k, $state_k, $schema_k, $svc_k) = env();
$model_k = new InterviewFormModel();
$model_k->url_params = array('module_id' => '', 'list_status' => 'active');
$res = $svc_k->open($model_k, null, 'tok-k');
check('url params key: sorted, a declared-but-absent param is empty', !empty($res['ok'])
    && $db_k->conversations[$res['state']['conversation_id']]['context_key'] === 'list_status=active&module_id=');
$model_k2 = new InterviewFormModel();
$model_k2->url_params = array('module_id' => 'module_1', 'list_status' => 'active');
$res2 = $svc_k->open($model_k2, null, 'tok-k2');
check('url params key: another value keeps the modules apart', !empty($res2['ok'])
    && $db_k->conversations[$res2['state']['conversation_id']]['context_key'] === 'list_status=active&module_id=module_1'
    && $res2['state']['conversation_id'] !== $res['state']['conversation_id']);
$model_k3 = new InterviewFormModel();
$model_k3->url_params = array('list_status' => 'active', 'module_id' => 'module_1');
$res3 = $svc_k->open($model_k3, null, 'tok-k3');
check('url params key: declaration order is irrelevant, the module draft is resumed', !empty($res3['ok'])
    && $res3['state']['conversation_id'] === $res2['state']['conversation_id']);
$model_k4 = new InterviewFormModel();
$model_k4->url_params = array(
    'a_long_parameter_name_one' => str_repeat('x', 64),
    'a_long_parameter_name_two' => str_repeat('y', 64),
    'a_long_parameter_name_three' => str_repeat('z', 64),
);
$res4 = $svc_k->open($model_k4, null, 'tok-k4');
$key4 = !empty($res4['ok']) ? $db_k->conversations[$res4['state']['conversation_id']]['context_key'] : '';
check('url params key: a key above 191 chars is hashed to fit the column',
    strpos($key4, 'p:') === 0 && strlen($key4) === 66);

// A pinned field (declared URL param that is also a field) is seeded into the
// new draft and never asked: the required title is satisfied by the URL value.
$model_p = new InterviewFormModel();
$model_p->url_params = array('title' => 'Walk');
$model_p->scope_bound = array('title' => 'Walk');
$res_p = $svc_k->open($model_p, null, 'tok-p');
$stored_p = json_decode($db_k->conversations[$res_p['state']['conversation_id']]['state'], true);
check('pinned: the URL value seeds the stored draft but is never asked',
    !empty($res_p['ok']) && $stored_p['payload']['draft']['title'] === 'Walk'
    && !isset($res_p['state']['fields']['title']));
check('pinned: the seeded value satisfies the required field',
    $res_p['state']['progress']['required_total'] === 0 && $res_p['state']['progress']['ready_for_approval'] === true);

/* =========================================================================
 * 15. Language rule is on by default (`llm_use_user_language`); off disables it
 * ========================================================================= */

list($db_o, $c_o, $s_o, $llm_o, $state_o, $sc_o, $svc_o) = env();
$model_o = new InterviewFormModel();
$model_o->use_user_language = false;
$res = $svc_o->open($model_o, null, 'tok-o');
$conv_o = $res['state']['conversation_id'];
$llm_o->scripted[] = valid_interview_response(array(), 'title');
$res = $svc_o->turn($model_o, null, $conv_o, 0, 'hello', array(), 'req-o1');
check('language off: turn works without the rule', !empty($res['ok']));
check('language off: no language lock is sent', count(language_lock_messages($llm_o->last_messages)) === 0);
$rev_o = $res['state']['revision'];
$model_o->use_user_language = true;
$llm_o->scripted[] = valid_interview_response(array(), 'title');
$res = $svc_o->turn($model_o, null, $conv_o, $rev_o, 'again', array(), 'req-o2');
check('language on: the lock is sent again', count(language_lock_messages($llm_o->last_messages)) === 1);

/* =========================================================================
 * 16. Fresh chat on every open: abandon, conflict hand-over, resume
 * ========================================================================= */

list($db_n, $c_n, $s_n, $llm_n, $state_n, $sc_n, $svc_n) = env();
$m_n = new InterviewFormModel();
$m_n->retention = 'new_on_open';
$a = $svc_n->open($m_n, null, 'n-1');
$conv_a = $a['state']['conversation_id'];
$svc_n->updateDraft($m_n, null, $conv_a, 0, array('title' => 'typed in tab one'));
$b = $svc_n->open($m_n, null, 'n-2');
$conv_b = $b['state']['conversation_id'];
check('fresh open: a new thread starts', $conv_b !== $conv_a);
check('fresh open: new thread starts empty', !isset($b['state']['draft']['title']) || $b['state']['draft']['title'] === '');
$env_a = $state_n->readState($db_n->conversations[$conv_a]);
check('fresh open: the older unfinished thread is abandoned', $env_a['payload']['phase'] === 'abandoned');

$stale = $svc_n->updateDraft($m_n, null, $conv_a, 1, array('title' => 'late write from the other tab'));
check('abandoned thread: write conflicts', !$stale['ok'] && $stale['error']['type'] === 'conflict');
check('abandoned thread: conflict hands over the live thread',
    isset($stale['state']) && is_array($stale['state']) && $stale['state']['conversation_id'] === $conv_b);
check('abandoned thread: late write never lands in the live draft',
    !isset($stale['state']['draft']['title']) || $stale['state']['draft']['title'] === '');

$resumed = $svc_n->open($m_n, null, 'n-3', false, 0, true);
check('resume: a reload keeps the open thread under new_on_open',
    $resumed['state']['conversation_id'] === $conv_b);
$retried = $svc_n->open($m_n, null, 'n-2');
check('resume: a retried open (same token) keeps the thread', $retried['state']['conversation_id'] === $conv_b);
$fresh_again = $svc_n->open($m_n, null, 'n-4');
check('fresh open: the next explicit open starts yet another thread',
    $fresh_again['state']['conversation_id'] !== $conv_b);

// A conflict on an abandoned thread with no live replacement yields no state.
$cleared = $svc_n->open($m_n, null, 'n-5');
$conv_c = $cleared['state']['conversation_id'];
$reset = $svc_n->open($m_n, null, 'n-6', true, $conv_c);
check('reset: abandons exactly the named thread', !empty($reset['ok'])
    && $reset['state']['conversation_id'] !== $conv_c);

/* =========================================================================
 * 17. Edit sessions: mode, change tracking, guided start
 * ========================================================================= */

list($db_e, $c_e, $s_e, $llm_e, $state_e, $sc_e, $svc_e) = env();
$entry_e = array('record_id' => 42, 'title' => 'Orig', 'id_users' => 5);
$model_e = new InterviewFormModel(array('record_id' => 42, 'title' => 'Orig'), array(42 => $entry_e));
$res = $svc_e->open($model_e, 42, 'tok-e');
$st_e = $res['state'];
$conv_e = $st_e['conversation_id'];
check('edit: mode is edit for a saved record', $st_e['mode'] === 'edit');
check('edit: create scope mode stays new',
    $svc_e->open(new InterviewFormModel(), null, 'tok-e-new')['state']['mode'] === 'new');
check('edit: nothing changed yet', $st_e['changed'] === array() && (array)$st_e['baseline'] === array());
check('edit: cannot save without a change', $st_e['can_approve'] === false);

$up = $svc_e->updateDraft($model_e, 42, $conv_e, 0, array('title' => 'Edited'));
check('edit: the changed field is reported', $up['state']['changed'] === array('title'));
check('edit: the saved value is the baseline', $up['state']['baseline']->title === 'Orig');
check('edit: a change enables saving', $up['state']['can_approve'] === true);
$up2 = $svc_e->updateDraft($model_e, 42, $conv_e, $up['state']['revision'], array('title' => 'Orig'));
check('edit: reverting the value clears the change and saving', $up2['state']['changed'] === array()
    && $up2['state']['can_approve'] === false);
$up3 = $svc_e->updateDraft($model_e, 42, $conv_e, $up2['state']['revision'], array('title' => 'Edited'));

// The coach sees what changed and the exact approve button label.
$llm_e->scripted[] = valid_interview_response(array(), 'title');
$turn_e = $svc_e->turn($model_e, 42, $conv_e, $up3['state']['revision'], 'anything else?', array(), 'req-e0');
$sent = '';
foreach ($llm_e->last_messages as $m_sent) {
    $sent .= "\n" . $m_sent['content'];
}
check('edit context: the coach is told it is an edit session', strpos($sent, '"mode":"edit"') !== false);
check('edit context: changed fields are listed', strpos($sent, '"changed_fields":["title"]') !== false);
check('edit context: the saved value of a changed field is included',
    strpos($sent, '"saved_values_of_changed_fields":{"title":"Orig"}') !== false);
check('edit context: the exact approve button label is included',
    strpos($sent, '"approve_button_label":"Save it now"') !== false);

// Approve, reopen: the saved thread asks "what do you want to change?" once.
$db_e->begin_transaction();
$prep_e = $svc_e->prepareApproval($model_e, 42, $conv_e, $turn_e['state']['revision'], 'req-e');
check('edit approve: prepare ok', !empty($prep_e['ok']));
$done_e = $svc_e->completeApproval($model_e, $prep_e, 42);
$db_e->commit();
check('edit approve: complete ok', !empty($done_e['ok']));
$reopen = $svc_e->open($model_e, 42, 'tok-e2');
check('edit reopen: the saved thread is resumed', $reopen['state']['conversation_id'] === $conv_e
    && $reopen['state']['completed_at'] !== null);
check('edit reopen: the coach still has to open the session', $reopen['state']['needs_start'] === true);
$llm_e->scripted[] = valid_interview_response(array(), 'title');
$started = $svc_e->turn($model_e, 42, $conv_e, $reopen['state']['revision'], '', array(), 'req-e-start', true);
check('edit start: the coach opened the session', !empty($started['ok']) && $started['state']['needs_start'] === false);
$start_prompt = '';
foreach ($llm_e->last_messages as $m_start) {
    $start_prompt .= "\n" . $m_start['content'];
}
check('edit start: the edit greeting instruction is sent', stripos($start_prompt, 'what') !== false
    && stripos($start_prompt, 'change') !== false);
$reopen2 = $svc_e->open($model_e, 42, 'tok-e3');
check('edit start: a reload does not greet twice', $reopen2['state']['needs_start'] === false);

/* =========================================================================
 * 18. Saving needs content: one answer for a new record, enforced server-side
 * ========================================================================= */

list($db_n, $c_n, $s_n, $llm_n, $state_n, $sc_n, $svc_n) = env();
$model_n = new InterviewFormModel();
$model_n->title_required = false;
$model_n->rebuildInterviewChildren(array());
$res_n = $svc_n->open($model_n, null, 'tok-n');
$conv_n = $res_n['state']['conversation_id'];
check('nothing to save: an empty form without required fields is not savable', $res_n['state']['can_approve'] === false);
$db_n->begin_transaction();
$prep_n = $svc_n->prepareApproval($model_n, null, $conv_n, $res_n['state']['revision'], 'req-n0');
LlmTransaction::rollback($db_n);
check('nothing to save: approval is refused server-side',
    empty($prep_n['ok']) && $prep_n['error']['type'] === 'validation');
$up_n = $svc_n->updateDraft($model_n, null, $conv_n, $res_n['state']['revision'], array('title' => 'Something'));
check('nothing to save: one answer makes it savable', $up_n['state']['can_approve'] === true);
$db_n->begin_transaction();
$prep_n2 = $svc_n->prepareApproval($model_n, null, $conv_n, $up_n['state']['revision'], 'req-n1');
LlmTransaction::rollback($db_n);
check('nothing to save: approval passes once something is answered', !empty($prep_n2['ok']));

/* =========================================================================
 * Actor lock + danger alert outside the lock window
 * ========================================================================= */

class InterviewDangerModel extends InterviewFormModel
{
    public function getDangerKeywords() { return 'harm myself, suicide'; }
    public function getDangerBlockedMessage() { return 'blocked-danger'; }
    public function getDangerNotificationEmails() { return array('admin@example.org'); }
    public function getSectionId() { return $this->get_section_id(); }
}

/** A section that leaves the blocked message empty: the global / built-in message applies. */
class InterviewDangerNoMessageModel extends InterviewDangerModel
{
    public function getDangerBlockedMessage() { return ''; }
}

$_SESSION = array('id_user' => 5);
list($db_l, , , , , , $svc_l) = env();
$model_l = new InterviewFormModel();
$res_l = $svc_l->open($model_l, null, 'tok-l');
check('actor lock: open succeeds', !empty($res_l['ok']));
check('actor lock: named lock is the first statement inside the open transaction',
    $db_l->lock_log[0] === array('get', 'llm_interview_open_5', 1));
check('actor lock: released after the transaction ended, nothing left held',
    end($db_l->lock_log) === array('release', 'llm_interview_open_5', 0) && empty($db_l->locks));
$user_queries = array_filter($db_l->queries, function ($q) { return strpos($q, 'from users') !== false; });
check('actor lock: the core users row is never read or locked', count($user_queries) === 0);

$db_l->lock_available = false;
$busy = $svc_l->open($model_l, null, 'tok-l2');
check('actor lock: a busy lock fails closed as conflict',
    empty($busy['ok']) && $busy['error']['type'] === 'conflict');
check('actor lock: nothing left held after a failed acquire', empty($db_l->locks));

// Keyword pre-scan: the block, audit and alert email run after the transaction ended
list($db_d, , $services_d, $llm_d, , , $svc_d) = env();
$db_d->page_info = array('email' => array('email_activate_email_address' => 'system@example.org'));
$model_d = new InterviewDangerModel();
$res_d = $svc_d->open($model_d, null, 'tok-d');
$conv_d = $res_d['state']['conversation_id'];
$out_d = $svc_d->turn($model_d, null, $conv_d, $res_d['state']['revision'],
    'I want to harm myslef <script>alert(1)</script>', array(), 'req-d');
check('danger pre-scan: a typo of a keyword phrase is caught',
    empty($out_d['ok']) && $out_d['error']['type'] === 'danger_detected');
check('danger pre-scan: no LLM call was made', $llm_d->api_calls === 0);

list(, , , , , , $svc_m) = env();
$model_m = new InterviewDangerNoMessageModel();
$res_m = $svc_m->open($model_m, null, 'tok-m');
$out_m = $svc_m->turn($model_m, null, $res_m['state']['conversation_id'], $res_m['state']['revision'],
    'I want to harm myself', array(), 'req-m');
check('danger pre-scan: an empty section message shows the global / built-in message',
    empty($out_m['ok']) && $out_m['error']['type'] === 'danger_detected'
    && ($out_m['safety_message'] ?? $out_m['error']['safety_message'] ?? null) === LLM_DEFAULT_DANGER_BLOCKED_MESSAGE);
$again_m = $svc_m->turn($model_m, null, $res_m['state']['conversation_id'], $res_m['state']['revision'],
    'hello again', array(), 'req-m2');
check('blocked conversation: an empty section message shows the global / built-in message',
    empty($again_m['ok']) && $again_m['error']['type'] === 'danger_detected'
    && $again_m['error']['message'] === LLM_DEFAULT_DANGER_BLOCKED_MESSAGE);
check('danger pre-scan: conversation blocked after the transaction ended',
    count($db_d->updates) === 1 && $db_d->updates[0][0] === 'llmConversations'
    && (int)$db_d->updates[0][1]['blocked'] === 1 && $db_d->updates[0][3] === 0);
$alerts_d = $services_d->scheduler->jobs;
check('danger pre-scan: one alert email, sent outside any transaction',
    count($alerts_d) === 1 && $alerts_d[0][1] === 0);
check('danger pre-scan: alert sender is the core system sender, not a recipient',
    $alerts_d[0][0]['from_email'] === 'system@example.org'
    && $alerts_d[0][0]['reply_to'] === 'system@example.org'
    && $alerts_d[0][0]['recipient_emails'] === 'admin@example.org');
// The core mailer renders the body as Markdown -> HTML (safe mode off): render it the same way
$body_d = $alerts_d[0][0]['body'];
$parsedown_file = dirname(__DIR__, 3) . '/service/ext/Parsedown.php';
if (is_file($parsedown_file)) {
    require_once $parsedown_file;
    $parsedown_d = new Parsedown();
    $parsedown_d->setSafeMode(false);
    $html_d = $parsedown_d->text($body_d);
    $injection_safe = strpos($html_d, '<script') === false && strpos($html_d, '&lt;script&gt;') !== false;
} else {
    $injection_safe = (bool)preg_match('/`[^`]*<script>[^`]*`/', $body_d);
}
check('danger pre-scan: participant text cannot inject markup into the alert', $injection_safe);

// No sender configured on the core email page: core default noreply@<host>
list($db_f, , $services_f, , , , $svc_f) = env();
$_SERVER['HTTP_HOST'] = 'cms.example.test:8443';
$model_f = new InterviewDangerModel();
$res_f = $svc_f->open($model_f, null, 'tok-f');
$svc_f->turn($model_f, null, $res_f['state']['conversation_id'], $res_f['state']['revision'],
    'I think about suicide', array(), 'req-f');
$alerts_f = $services_f->scheduler->jobs;
check('danger pre-scan: unconfigured sender falls back to noreply@host',
    count($alerts_f) === 1 && $alerts_f[0][0]['from_email'] === 'noreply@cms.example.test');
unset($_SERVER['HTTP_HOST']);

/* =========================================================================
 * Expected failures roll back through the PDO handle: no global cache wipe
 * (BaseDb::rollback() would apcu_clear_cache() the whole application) and
 * no transaction left open - not even for a PHP Error.
 * ========================================================================= */

/** Model whose child rebuild throws a PHP Error (TypeError-like) inside the transaction. */
class InterviewErrorModel extends InterviewFormModel
{
    public $explode = false;

    public function rebuildInterviewChildren(array $draft = array())
    {
        if ($this->explode) {
            throw new \Error('boom inside the transaction');
        }
        return parent::rebuildInterviewChildren($draft);
    }
}

function assert_quiet_rollback($label, InterviewFakeDb $db)
{
    check("$label: no global cache wipe", $db->wipes === 0 && !$db->get_cache()->wasWiped());
    check("$label: no transaction left open", !$db->inTransaction());
}

list($db_w, , , , , , $svc_w) = env();
$model_w = new InterviewErrorModel();
$res_w = $svc_w->open($model_w, null, 'tok-w');
$conv_w = $res_w['state']['conversation_id'];
$rev_w = $res_w['state']['revision'];
assert_quiet_rollback('wipe: open', $db_w);

$stale_w = $svc_w->updateDraft($model_w, null, $conv_w, $rev_w + 7, array('title' => 'x'));
check('wipe: stale draft update is a conflict',
    empty($stale_w['ok']) && $stale_w['error']['type'] === 'conflict');
assert_quiet_rollback('wipe: stale draft update', $db_w);

$invalid_w = $svc_w->updateDraft($model_w, null, $conv_w, $rev_w, array('no_such_field' => 'x'));
check('wipe: unknown field is a validation error',
    empty($invalid_w['ok']) && $invalid_w['error']['type'] === 'validation');
assert_quiet_rollback('wipe: invalid patch', $db_w);

$turn_w = $svc_w->turn($model_w, null, $conv_w, $rev_w + 7, 'hello', array(), 'req-w1');
check('wipe: stale turn is a conflict',
    empty($turn_w['ok']) && $turn_w['error']['type'] === 'conflict');
assert_quiet_rollback('wipe: stale turn', $db_w);

$db_w->lock_available = false;
$busy_w = $svc_w->open($model_w, null, 'tok-w2');
check('wipe: busy actor lock is a conflict',
    empty($busy_w['ok']) && $busy_w['error']['type'] === 'conflict');
assert_quiet_rollback('wipe: busy actor lock', $db_w);
$db_w->lock_available = true;

$rows_before_w = $db_w->conversations;
$model_w->explode = true;
$escaped_w = null;
try {
    $svc_w->updateDraft($model_w, null, $conv_w, $rev_w, array('title' => 'x'));
} catch (\Throwable $t) {
    $escaped_w = $t;
}
$model_w->explode = false;
check('wipe: an Error inside the transaction does not leave it open',
    !$db_w->inTransaction());
check('wipe: an Error inside the transaction rolled the writes back',
    $db_w->conversations === $rows_before_w);
check('wipe: an Error inside the transaction does not wipe the cache either',
    $db_w->wipes === 0 && !$db_w->get_cache()->wasWiped());
check('wipe: the service stays usable afterwards',
    !empty($svc_w->updateDraft($model_w, null, $conv_w, $rev_w, array('title' => 'ok'))['ok']));

/* =========================================================================
 * The other transactions of the interview: open(), both transactions of
 * turn(), the merge abort and the best-effort markRequestFailed(). A PHP
 * Error (not only an Exception) rolls back through the PDO handle, leaves
 * neither the transaction nor the actor lock open and never wipes the cache.
 * ========================================================================= */

function run_guarded(callable $fn)
{
    try {
        return array($fn(), null);
    } catch (\Throwable $t) {
        return array(null, $t);
    }
}

function request_status(InterviewFakeDb $db, $conversation_id)
{
    $env = json_decode($db->conversations[$conversation_id]['state'], true);
    return isset($env['payload']['request']['status']) ? $env['payload']['request']['status'] : null;
}

// open(): an Error inside the transaction
list($db_e1, , , , , , $svc_e1) = env();
$model_e1 = new InterviewErrorModel();
$model_e1->explode = true;
run_guarded(function () use ($svc_e1, $model_e1) {
    return $svc_e1->open($model_e1, null, 'tok-e1');
});
assert_quiet_rollback('open Error', $db_e1);
check('open Error: nothing was created', count($db_e1->conversations) === 0);
check('open Error: the actor lock is released', $db_e1->locks === array());
$model_e1->explode = false;
$retry_e1 = $svc_e1->open($model_e1, null, 'tok-e1');
check('open Error: a retry after the failure succeeds', !empty($retry_e1['ok']));

// turn(), first transaction (draft + participant message): an Error on the state write
list($db_e2, , , $llm_e2, , , $svc_e2) = env();
$model_e2 = new InterviewErrorModel();
$conv_e2 = $svc_e2->open($model_e2, null, 'tok-e2')['state']['conversation_id'];
$rows_e2 = $db_e2->conversations;
$msgs_e2 = $db_e2->messages;
$db_e2->failStateWrite(1, new \Error('state write failed in the first transaction'));
run_guarded(function () use ($svc_e2, $model_e2, $conv_e2) {
    return $svc_e2->turn($model_e2, null, $conv_e2, 0, 'hello', array(), 'req-e2');
});
assert_quiet_rollback('turn first transaction Error', $db_e2);
check('turn first transaction Error: state and messages are rolled back',
    $db_e2->conversations === $rows_e2 && $db_e2->messages === $msgs_e2);
check('turn first transaction Error: the LLM was never called', $llm_e2->api_calls === 0);
$llm_e2->scripted[] = valid_interview_response(array('title' => 'after retry'), 'title');
$retry_e2 = $svc_e2->turn($model_e2, null, $conv_e2, 0, 'hello', array(), 'req-e2');
check('turn first transaction Error: the same request can be retried', !empty($retry_e2['ok']));

// turn(), merge transaction: an Error on the merge's state write
list($db_e3, , , $llm_e3, , , $svc_e3) = env();
$model_e3 = new InterviewErrorModel();
$conv_e3 = $svc_e3->open($model_e3, null, 'tok-e3')['state']['conversation_id'];
$llm_e3->scripted[] = valid_interview_response(array('title' => 'merged'), 'title');
$pre_merge_e3 = null;
$llm_e3->on_call = function () use ($db_e3, &$pre_merge_e3) {
    $pre_merge_e3 = $db_e3->conversations;
};
$db_e3->failStateWrite(2, new \Error('state write failed in the merge'));
run_guarded(function () use ($svc_e3, $model_e3, $conv_e3) {
    return $svc_e3->turn($model_e3, null, $conv_e3, 0, 'hello', array(), 'req-e3');
});
assert_quiet_rollback('turn merge Error', $db_e3);
check('turn merge Error: the merge is rolled back, the pre-merge state stands',
    $pre_merge_e3 !== null && $db_e3->conversations === $pre_merge_e3);
check('turn merge Error: the reply stays unvalidated (never shown to the participant)',
    count(array_filter($db_e3->messages, function ($m) {
        return $m['role'] === 'assistant' && (int)$m['is_validated'] === 1;
    })) === 0);

// turn(), merge transaction: the validated reply cannot be stored (abort, not an Error)
list($db_e4, , , $llm_e4, , , $svc_e4) = env();
$model_e4 = new InterviewErrorModel();
$conv_e4 = $svc_e4->open($model_e4, null, 'tok-e4')['state']['conversation_id'];
$llm_e4->scripted[] = valid_interview_response(array('title' => 'merged'), 'title');
$llm_e4->fail_update = true;
$pre_merge_e4 = null;
$llm_e4->on_call = function () use ($db_e4, &$pre_merge_e4) {
    $pre_merge_e4 = $db_e4->conversations;
};
$res_e4 = $svc_e4->turn($model_e4, null, $conv_e4, 0, 'hello', array(), 'req-e4');
check('turn merge abort: the turn answers llm_invalid', empty($res_e4['ok']) && $res_e4['error']['type'] === 'llm_invalid');
assert_quiet_rollback('turn merge abort', $db_e4);
check('turn merge abort: the merge write is rolled back, the pre-merge state stands',
    $pre_merge_e4 !== null && $db_e4->conversations === $pre_merge_e4);

// markRequestFailed() (best effort): control first, then an Error inside it
list($db_e5, , , $llm_e5, , , $svc_e5) = env();
$model_e5 = new InterviewErrorModel();
$conv_e5 = $svc_e5->open($model_e5, null, 'tok-e5')['state']['conversation_id'];
for ($i = 0; $i < 4; $i++) {
    $llm_e5->scripted[] = json_encode(array('not' => 'json-schema'));
}
$res_e5 = $svc_e5->turn($model_e5, null, $conv_e5, 0, 'hello', array(), 'req-e5');
check('mark failed (control): an invalid reply answers llm_invalid', empty($res_e5['ok']) && $res_e5['error']['type'] === 'llm_invalid');
check('mark failed (control): the request is marked failed', request_status($db_e5, $conv_e5) === 'failed');

list($db_e6, , , $llm_e6, , , $svc_e6) = env();
$model_e6 = new InterviewErrorModel();
$conv_e6 = $svc_e6->open($model_e6, null, 'tok-e6')['state']['conversation_id'];
for ($i = 0; $i < 4; $i++) {
    $llm_e6->scripted[] = json_encode(array('not' => 'json-schema'));
}
$pre_mark_e6 = null;
$llm_e6->on_call = function () use ($db_e6, &$pre_mark_e6) {
    $pre_mark_e6 = $db_e6->conversations;
};
$db_e6->failStateWrite(2, new \Error('state write failed while marking the request failed'));
list($res_e6, $thrown_e6) = run_guarded(function () use ($svc_e6, $model_e6, $conv_e6) {
    return $svc_e6->turn($model_e6, null, $conv_e6, 0, 'hello', array(), 'req-e6');
});
check('mark failed Error: the failing best-effort mark does not change the outcome (llm_invalid, nothing thrown)',
    $thrown_e6 === null && empty($res_e6['ok']) && $res_e6['error']['type'] === 'llm_invalid');
assert_quiet_rollback('mark failed Error', $db_e6);
check('mark failed Error: its write is rolled back (the request stays pending)',
    $pre_mark_e6 !== null && $db_e6->conversations === $pre_mark_e6 && request_status($db_e6, $conv_e6) === 'pending');

/* =========================================================================
 * Unexpected failures: generic answer for the participant, cause in the log
 *
 * Every public entry point ends in a catch-all. It must (a) catch a PHP
 * Error as well as an Exception, (b) answer with the generic error envelope
 * instead of the exception text and (c) log the cause with the conversation
 * it concerned - without quoting database driver messages, which can carry
 * the participant's answers.
 * ========================================================================= */

/** Model that throws a PHP Error from the method named in $bomb. */
class InterviewBombModel extends InterviewFormModel
{
    public $bomb = '';

    private function maybeExplode($method)
    {
        if ($this->bomb === $method) {
            throw new \Error('bomb: ' . $method);
        }
    }

    public function rebuildInterviewChildren(array $draft = array())
    {
        $this->maybeExplode(__FUNCTION__);
        return parent::rebuildInterviewChildren($draft);
    }

    public function setInterviewBaseEntry(array $entry)
    {
        $this->maybeExplode(__FUNCTION__);
        return parent::setInterviewBaseEntry($entry);
    }

    public function get_form_entry_record($record_id, $own_entries_only)
    {
        $this->maybeExplode(__FUNCTION__);
        return parent::get_form_entry_record($record_id, $own_entries_only);
    }

    /** Asked by assertRootEligible(), i.e. after the conversation row was loaded. */
    public function get_condition_result()
    {
        $this->maybeExplode(__FUNCTION__);
        return parent::get_condition_result();
    }
}

/** A PDOException the way PDO throws it: the code is the SQLSTATE string. */
function driver_error($message, $sqlstate)
{
    $e = new \PDOException($message);
    $property = new \ReflectionProperty('Exception', 'code');
    $property->setAccessible(true);
    $property->setValue($e, $sqlstate);
    return $e;
}

$failure_log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_interview_failure_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $failure_log);
register_shutdown_function(function () use ($failure_log) {
    if (is_file($failure_log)) {
        unlink($failure_log);
    }
});

function failure_log_reset()
{
    global $failure_log;
    file_put_contents($failure_log, '');
}

function failure_log()
{
    global $failure_log;
    return is_file($failure_log) ? (string)file_get_contents($failure_log) : '';
}

function is_generic_failure($res, $message = 'Interview request failed')
{
    return is_array($res) && empty($res['ok']) && isset($res['error']['type'])
        && $res['error']['type'] === 'error' && $res['error']['message'] === $message;
}

/**
 * Run one entry point with a bomb armed and check the three promises.
 *
 * @param string $label Scenario name
 * @param string $operation What the log line must say ("Interview <operation> failed")
 * @param string $bomb Method of InterviewBombModel that throws
 * @param int|null $conversation Conversation id the log line must carry (null: none expected)
 */
function check_failure_site($label, $operation, $bomb, callable $run, $conversation = null, $message = 'Interview request failed')
{
    failure_log_reset();
    list($res, $thrown) = run_guarded($run);
    $log = failure_log();
    check("failure $label: the Error does not escape", $thrown === null);
    check("failure $label: the participant gets the generic error", is_generic_failure($res, $message));
    check("failure $label: the cause is logged with the operation",
        strpos($log, "Interview $operation failed") !== false && strpos($log, "Error: bomb: $bomb") !== false);
    check("failure $label: the conversation is logged" . ($conversation === null ? ' only when known' : ''),
        $conversation === null
            ? strpos($log, 'conversation') === false || preg_match('/\(conversation \d+\)/', $log) === 1
            : strpos($log, "(conversation $conversation)") !== false);
}

// open()
list($db_f1, , , , , , $svc_f1) = env();
$model_f1 = new InterviewBombModel();
$model_f1->bomb = 'rebuildInterviewChildren';
check_failure_site('open', 'open', 'rebuildInterviewChildren', function () use ($svc_f1, $model_f1) {
    return $svc_f1->open($model_f1, null, 'tok-f1');
});
assert_quiet_rollback('failure open', $db_f1);
check('failure open: the actor lock is released', $db_f1->locks === array());

// read()
list($db_f2, , , , , , $svc_f2) = env();
$model_f2 = new InterviewBombModel();
$conv_f2 = $svc_f2->open($model_f2, null, 'tok-f2')['state']['conversation_id'];
$model_f2->bomb = 'setInterviewBaseEntry';
check_failure_site('read', 'read', 'setInterviewBaseEntry', function () use ($svc_f2, $model_f2, $conv_f2) {
    return $svc_f2->read($model_f2, null, $conv_f2);
});

// updateDraft()
list($db_f3, , , , , , $svc_f3) = env();
$model_f3 = new InterviewBombModel();
$opened_f3 = $svc_f3->open($model_f3, null, 'tok-f3')['state'];
$model_f3->bomb = 'get_condition_result';
check_failure_site('update draft', 'update draft', 'get_condition_result', function () use ($svc_f3, $model_f3, $opened_f3) {
    return $svc_f3->updateDraft($model_f3, null, $opened_f3['conversation_id'], $opened_f3['revision'], array('title' => 'x'));
}, $opened_f3['conversation_id']);
assert_quiet_rollback('failure update draft', $db_f3);

// turn(): a database error on the first state write; its message is not logged
list($db_f4, , , $llm_f4, , , $svc_f4) = env();
$model_f4 = new InterviewBombModel();
$conv_f4 = $svc_f4->open($model_f4, null, 'tok-f4')['state']['conversation_id'];
$db_f4->failStateWrite(1, driver_error("SQLSTATE[23000]: Duplicate entry 'jane.doe@example.org' for key 'answers'", '23000'));
failure_log_reset();
list($res_f4, $thrown_f4) = run_guarded(function () use ($svc_f4, $model_f4, $conv_f4) {
    return $svc_f4->turn($model_f4, null, $conv_f4, 0, 'hello', array(), 'req-f4');
});
$log_f4 = failure_log();
check('failure turn: the database error does not escape', $thrown_f4 === null);
check('failure turn: the participant gets the generic error', is_generic_failure($res_f4)
    && strpos(json_encode($res_f4), 'jane.doe') === false && stripos(json_encode($res_f4), 'sqlstate') === false);
check('failure turn: logged with operation, class, SQLSTATE and conversation',
    strpos($log_f4, 'Interview turn failed (conversation ' . $conv_f4 . '): PDOException: database error 23000') !== false);
check('failure turn: the driver message (it quotes the answers) is not logged', strpos($log_f4, 'jane.doe') === false
    && stripos($log_f4, 'Duplicate entry') === false);
assert_quiet_rollback('failure turn', $db_f4);
check('failure turn: the LLM was never called', $llm_f4->api_calls === 0);

// prepareApproval() and completeApproval()
list($db_f5, , , , , , $svc_f5) = env();
$model_f5 = new InterviewBombModel();
$conv_f5 = $svc_f5->open($model_f5, null, 'tok-f5')['state']['conversation_id'];
$svc_f5->updateDraft($model_f5, null, $conv_f5, 0, array('title' => 'Done'));
$model_f5->bomb = 'get_condition_result';
$db_f5->begin_transaction();
check_failure_site('approval prepare', 'approval prepare', 'get_condition_result', function () use ($svc_f5, $model_f5, $conv_f5) {
    return $svc_f5->prepareApproval($model_f5, null, $conv_f5, 1, 'req-f5');
}, $conv_f5);
LlmTransaction::rollback($db_f5);

$model_f5->bomb = '';
$db_f5->begin_transaction();
$prep_f5 = $svc_f5->prepareApproval($model_f5, null, $conv_f5, 1, 'req-f5');
check('failure approval complete: setup - prepare succeeded', !empty($prep_f5['ok']));
$model_f5->bomb = 'get_form_entry_record';
check_failure_site('approval complete', 'approval complete', 'get_form_entry_record', function () use ($svc_f5, $model_f5, $prep_f5) {
    return $svc_f5->completeApproval($model_f5, $prep_f5, 555);
}, $conv_f5);
LlmTransaction::rollback($db_f5);
assert_quiet_rollback('failure approval', $db_f5);

// previewTurn() (CMS editor test panel): its own envelope message
list($db_f6, , , , , , $svc_f6) = env();
$model_f6 = new InterviewBombModel();
$model_f6->bomb = 'rebuildInterviewChildren';
check_failure_site('preview turn', 'preview turn', 'rebuildInterviewChildren', function () use ($svc_f6, $model_f6) {
    return $svc_f6->previewTurn($model_f6, array('title' => 'x'));
}, null, 'Interview preview request failed');

// Deliberate refusals are not failures: nothing is logged for them
list($db_f7, , , , , , $svc_f7) = env();
$model_f7 = new InterviewBombModel();
$conv_f7 = $svc_f7->open($model_f7, null, 'tok-f7')['state']['conversation_id'];
failure_log_reset();
$stale_f7 = $svc_f7->updateDraft($model_f7, null, $conv_f7, 99, array('title' => 'x'));
check('a conflict is answered as a conflict and is not logged as a failure',
    empty($stale_f7['ok']) && $stale_f7['error']['type'] === 'conflict' && failure_log() === '');

/* =========================================================================
 * Done
 * ========================================================================= */

echo "\n" . $GLOBALS['__checks'] . ' checks, ' . count($GLOBALS['__fails']) . " failures\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
