<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmConversationStateService (producer-neutral
 * conversation state contract, plugin v1.6.0).
 *
 * No bootstrap, no live database, no LLM calls: a small fake DB evaluates
 * the exact SQL fragments emitted by the service against an in-memory
 * `llmConversations` table with CHANGED-row (not matched-row) update
 * semantics like MySQL's default rowCount, plus snapshot transactions.
 *
 * Run: php tests/LlmConversationStateServiceTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');

if (!defined('LLM_CONV_SOURCE_CHAT')) define('LLM_CONV_SOURCE_CHAT', 'chat');
if (!defined('LLM_CONV_SOURCE_MEMORY')) define('LLM_CONV_SOURCE_MEMORY', 'memory');
if (!defined('LLM_CONV_SOURCE_FORM_INTERVIEW')) define('LLM_CONV_SOURCE_FORM_INTERVIEW', 'form_interview');
if (!defined('transactionTypes_insert')) define('transactionTypes_insert', 'insert');
if (!defined('transactionTypes_update')) define('transactionTypes_update', 'update');
if (!defined('LLM_CACHE_USER_CONVERSATIONS')) define('LLM_CACHE_USER_CONVERSATIONS', 'llm_user_conversations');
if (!defined('LLM_CACHE_CONVERSATION_MESSAGES')) define('LLM_CACHE_CONVERSATION_MESSAGES', 'llm_conversation_messages');
if (!defined('TRANSACTION_BY_LLM_PLUGIN')) define('TRANSACTION_BY_LLM_PLUGIN', 'llm_plugin');

require_once LLM_PATH . '/service/LlmConversationStateService.php';
require_once LLM_PATH . '/service/LlmService.php';

/* =========================================================================
 * Fakes / stubs
 * ========================================================================= */

class FakeDb
{
    public $conversations = array();
    private $next_id = 1;
    public $clock = '2026-01-01 00:00:00';
    private $lookup_ids = array();
    public $queries = array();
    public $fail_next_update = false;
    public $raw_cache;
    private $tx_stack = array();

    public function __construct()
    {
        $this->raw_cache = new FakeRawCache();
        $this->lookup_ids = array(
            'llmConversationSourceType' => array(
                'chat' => 1,
                'memory' => 2,
                'form_interview' => 3
            )
        );
    }

    public function seedConversation(array $fields = array())
    {
        return $this->insertConversation($fields);
    }

    public function get_lookup_id_by_value($type, $value)
    {
        return isset($this->lookup_ids[$type][$value]) ? $this->lookup_ids[$type][$value] : null;
    }

    public function get_cache()
    {
        return $this->raw_cache;
    }

    public function begin_transaction()
    {
        $this->tx_stack[] = array($this->conversations, $this->next_id);
    }

    public function commit()
    {
        array_pop($this->tx_stack);
    }

    public function rollback()
    {
        $snapshot = array_pop($this->tx_stack);
        if ($snapshot !== null) {
            list($this->conversations, $this->next_id) = $snapshot;
        }
    }

    private function norm($sql)
    {
        $sql = preg_replace('/`/', '', strtolower($sql));
        return preg_replace('/\s+/', ' ', $sql);
    }

    private function eq($a, $b)
    {
        // loose <=> semantics: null-safe, so null === null is a match
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

    public function execute_update_db($sql, $args)
    {
        $sql = $this->norm($sql);
        $this->queries[] = $sql;
        if ($this->fail_next_update) {
            $this->fail_next_update = false;
            return false;
        }
        if (strpos($sql, 'update llmconversations') === false) {
            return 0;
        }
        $is_state_write = strpos($sql, 'set state = :state') !== false
            && strpos($sql, 'extract(state') !== false;
        $is_bind = strpos($sql, 'id_datarows = :sc_new_row') !== false;
        $is_completed = strpos($sql, 'completed_at = coalesce') !== false
            || strpos($sql, 'completed_at = null') !== false;
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
                $new['completed_at'] = $row['completed_at'] === null ? $this->clock : $row['completed_at'];
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

    private function insertConversation(array $fields)
    {
        $id = $this->next_id++;
        $this->conversations[$id] = array_merge(array(
            'id' => $id,
            'title' => 'conv ' . $id,
            'id_users' => 0,
            'id_sections' => null,
            'id_llm_conversation_sources' => null,
            'id_dataRows' => null,
            'context_key' => null,
            'state' => null,
            'completed_at' => null,
            'deleted' => 0,
            'model' => null,
            'created_at' => $this->clock,
            'updated_at' => $this->clock
        ), $fields);
        return $id;
    }
}

class FakeRawCache
{
    public $cleared = array();

    public function get($key) { return false; }
    public function set($key, $value, $ttl = 0) { return true; }
    public function delete($key) { return true; }
    public function clear_cache($type = null, $id = null) { $this->cleared[] = $type; return true; }
    public function generate_key($type, $name = null, $args = array()) { return 'k'; }
}

class FakeCacheManager
{
    public $cleared_users = array();
    public $cleared_conversations = array();

    public function clearUserCache($user_id) { $this->cleared_users[] = $user_id; }
    public function clearConversationCache($id) { $this->cleared_conversations[] = $id; }
    public function clearConversationMessageCache($id) { $this->cleared_conversations[] = $id; }
}

class FakeServices
{
    private $db;
    private $cache;

    public function __construct(FakeDb $db, FakeCacheManager $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    public function get_db() { return $this->db; }
    public function get_transaction() { return new FakeTransaction(); }
    public function get_cache() { return $this->cache; }
}

class FakeTransaction
{
    public $logs = array();

    public function add_transaction($type, $table, $by_user, $by_ip, $user_id, $mid, $id, $name = '')
    {
        $this->logs[] = array($type, $table, $id, $name);
    }
}

class FakeLlmService
{
    private $db;
    public $created = array();
    public $fail_create = false;

    public function __construct(FakeDb $db)
    {
        $this->db = $db;
    }

    public function createConversation($user_id, $title = null, $model = null,
        $temperature = null, $max_tokens = null, $section_id = null,
        $source_type = LLM_CONV_SOURCE_CHAT)
    {
        $this->created[] = func_get_args();
        if ($this->fail_create) {
            return false;
        }
        return $this->db->seedConversation(array(
            'id_users' => (int)$user_id,
            'id_sections' => $section_id,
            'id_llm_conversation_sources' => $this->db->get_lookup_id_by_value('llmConversationSourceType', $source_type),
            'title' => $title === null ? 'Untitled Conversation' : $title
        ));
    }
}

/* =========================================================================
 * Tiny assertion harness
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

/** Standard draft scope for tests. */
function scope(array $over = array())
{
    return array_merge(array(
        'user_id' => 5,
        'section_id' => 88,
        'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW,
        'id_dataRows' => null,
        'context_key' => 'module:a'
    ), $over);
}

function env()
{
    $db = new FakeDb();
    $cache = new FakeCacheManager();
    $services = new FakeServices($db, $cache);
    $llm = new FakeLlmService($db);
    $svc = new LlmConversationStateService($services, $llm);
    return array($db, $cache, $services, $llm, $svc);
}

function throws_callable($fn, $class)
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

/* =========================================================================
 * 1. Creation + source tagging (producer A: form interview)
 * ========================================================================= */

list($db, $cache, $services, $llm, $svc) = env();

$conv_a = $svc->createScopedConversation(5, 88, LLM_CONV_SOURCE_FORM_INTERVIEW, array(
    'context_key' => 'module:a',
    'payload' => array('collected' => array('a' => 'x'), 'cursor' => 'a')
));
$row = $db->conversations[$conv_a];
check('A: conversation id returned', $conv_a > 0);
check('A: tagged with form_interview source id 3', (int)$row['id_llm_conversation_sources'] === 3);
check('A: context key stored', $row['context_key'] === 'module:a');
check('A: id_dataRows stays NULL (unbound draft)', $row['id_dataRows'] === null);
$env1 = $svc->readState($row);
check('A: envelope format_version 1', $env1 !== null && $env1['format_version'] === 1);
check('A: envelope revision 0', $env1 !== null && $env1['revision'] === 0);
check('A: producer payload round-trips', $env1 !== null && $env1['payload'] === array('collected' => array('a' => 'x'), 'cursor' => 'a'));
check('A: user cache invalidated after create', in_array(LLM_CACHE_USER_CONVERSATIONS, $db->raw_cache->cleared, true));

/* =========================================================================
 * 2. Producer B: memory conversation, different payload shape
 * ========================================================================= */

$conv_b = $svc->createScopedConversation(5, 42, LLM_CONV_SOURCE_MEMORY, array(
    'payload' => array('job' => 'summarize', 'step' => 1)
));
$row_b = $db->conversations[$conv_b];
check('B: tagged with memory source id 2', (int)$row_b['id_llm_conversation_sources'] === 2);
$env_b = $svc->readState($row_b);
check('B: distinct producer payload stored', $env_b !== null && $env_b['payload'] === array('job' => 'summarize', 'step' => 1));

/* =========================================================================
 * 3. Source isolation - no cross-producer visibility
 * ========================================================================= */

$chat_id = $db->seedConversation(array('id_users' => 5, 'id_sections' => 88, 'id_llm_conversation_sources' => 1));
$chat_row = $db->conversations[$chat_id];

$found = $svc->findScopedConversation(scope());
check('isolation: interview scope finds interview conv', $found !== null && (int)$found['id'] === $conv_a);
$found = $svc->findScopedConversation(scope(array('section_id' => 42, 'source_type' => LLM_CONV_SOURCE_MEMORY, 'context_key' => null)));
check('isolation: memory scope finds memory conv', $found !== null && (int)$found['id'] === $conv_b);
$found = $svc->findScopedConversation(scope(array('source_type' => LLM_CONV_SOURCE_CHAT, 'context_key' => null)));
check('isolation: chat scope finds the chat row', $found !== null && (int)$found['id'] === $chat_id);
check('isolation: interview scope cannot see chat row', (int)$found['id'] !== $conv_a);
check('isolation: chat row has no interview context', $chat_row['context_key'] === null);

/* =========================================================================
 * 4. Fail-closed on unregistered source
 * ========================================================================= */

$threw = false;
try {
    $svc->createScopedConversation(5, 88, 'unregistered_source');
} catch (\LlmException $e) {
    $threw = true;
}
check('create: unregistered source throws instead of untagged insert', $threw);
check('create: no extra row after failed create', count($db->conversations) === 3);
check('get: unregistered source fails closed', $svc->getScopedConversation($conv_a, scope(array('source_type' => 'ghost'))) === null);
check('find: unregistered source fails closed', $svc->findScopedConversation(scope(array('source_type' => 'ghost'))) === null);
check('write: unregistered source fails closed', $svc->writeState($conv_a, scope(array('source_type' => 'ghost')), 0, array('x' => 1)) === false);
check('complete: unregistered source fails closed', $svc->setCompleted($conv_a, scope(array('source_type' => 'ghost')), 0) === false);
check('bind: unregistered source fails closed', $svc->bindRecord($conv_a, scope(array('source_type' => 'ghost')), 42, 0) === false);

/* =========================================================================
 * 5. Context-key + scope normalization
 * ========================================================================= */

check('ctx: null stays null', $svc->normalizeContextKey(null) === null);
check('ctx: empty -> null', $svc->normalizeContextKey('') === null);
check('ctx: whitespace -> null', $svc->normalizeContextKey('   ') === null);
check('ctx: trim', $svc->normalizeContextKey('  a b  ') === 'a b');
check('ctx: 191 chars pass', strlen($svc->normalizeContextKey(str_repeat('x', 191))) === 191);
check('ctx: 192 chars throw', throws_callable(function () use ($svc) {
    $svc->normalizeContextKey(str_repeat('x', 192));
}, 'LlmValidationException'));
check('scope: unbound scope requires context_key key', throws_callable(function () use ($svc) {
    $svc->findScopedConversation(array(
        'user_id' => 5, 'section_id' => 88,
        'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW, 'id_dataRows' => null
    ));
}, 'LlmValidationException'));
check('scope: unbound null context_key is an allowed exact match', is_array($svc->normalizeScope(scope(array('context_key' => null)))));
$boundScope = array('user_id' => 5, 'section_id' => 88, 'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW, 'id_dataRows' => 9);
$normalizedBound = $svc->normalizeScope($boundScope);
check('scope: bound scope may omit context_key entirely', is_array($normalizedBound) && !array_key_exists('context_key', $normalizedBound));
check('scope: missing user_id throws', throws_callable(function () use ($svc) {
    $svc->normalizeScope(array('section_id' => 1, 'source_type' => 'chat', 'id_dataRows' => 1));
}, 'LlmValidationException'));
check('scope: id_dataRows 0 throws', throws_callable(function () use ($svc) {
    $svc->normalizeScope(array('user_id' => 1, 'section_id' => 1, 'source_type' => 'chat', 'id_dataRows' => 0));
}, 'LlmValidationException'));

$conv_null_ctx = $svc->createScopedConversation(9, null, LLM_CONV_SOURCE_FORM_INTERVIEW, array('context_key' => ''));
check('ctx: blank option stores NULL context + NULL section', $db->conversations[$conv_null_ctx]['context_key'] === null && $db->conversations[$conv_null_ctx]['id_sections'] === null);
$found_null = $svc->findScopedConversation(scope(array('user_id' => 9, 'section_id' => null, 'context_key' => null)));
check('ctx: NULL section + NULL context scope finds it', $found_null !== null && (int)$found_null['id'] === $conv_null_ctx);
$wrong_ctx = $svc->findScopedConversation(scope(array('user_id' => 9, 'section_id' => null, 'context_key' => 'module:a')));
check('ctx: NULL context does not match keyed context', $wrong_ctx === null);
$wrong_sec = $svc->findScopedConversation(scope(array('user_id' => 9, 'context_key' => null)));
check('ctx: NULL section does not match section 88', $wrong_sec === null);

/* =========================================================================
 * 6. Record binding
 * ========================================================================= */

$bind_scope = scope();
check('bind: draft binds to record 42', $svc->bindRecord($conv_a, $bind_scope, 42, 0) === true);
check('bind: row now bound', (int)$db->conversations[$conv_a]['id_dataRows'] === 42);
check('bind: second bind to another record rejected', $svc->bindRecord($conv_a, scope(array('id_dataRows' => 42)), 43, 0) === false);
check('bind: re-bind same id is idempotent', $svc->bindRecord($conv_a, scope(array('id_dataRows' => 42)), 42, 0) === true);
check('bind: bound scope without context_key still scopes the row', $svc->getScopedConversation($conv_a, array(
    'user_id' => 5, 'section_id' => 88,
    'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW, 'id_dataRows' => 42
)) !== null);
check('bind: draft scope no longer matches bound row', $svc->getScopedConversation($conv_a, $bind_scope) === null);
check('bind: wrong record scope hides the thread', $svc->getScopedConversation($conv_a, scope(array('id_dataRows' => 77))) === null);
check('bind: non-positive record throws', throws_callable(function () use ($svc, $conv_a) {
    $svc->bindRecord($conv_a, array('user_id' => 5, 'section_id' => 88, 'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW, 'id_dataRows' => 9), 0, 0);
}, 'LlmValidationException'));
check('bind: stale revision rejected', $svc->bindRecord($conv_null_ctx, scope(array('user_id' => 9, 'section_id' => null, 'context_key' => null)), 11, 7) === false);

/* =========================================================================
 * 7. Revision-checked state writes against the full scope
 * ========================================================================= */

$write_scope = array(
    'user_id' => 5, 'section_id' => 88,
    'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW, 'id_dataRows' => 42
);
check('write: stale revision rejected', $svc->writeState($conv_a, $write_scope, 5, array('x' => 1)) === false);
check('write: revision 0 -> 1 stored', $svc->writeState($conv_a, $write_scope, 0, array('collected' => array('a' => 'y'))) === true);
$env2 = $svc->readState($db->conversations[$conv_a]);
check('write: payload updated', $env2 !== null && $env2['payload'] === array('collected' => array('a' => 'y')));
check('write: revision bumped to 1', $env2 !== null && $env2['revision'] === 1);
check('write: replayed revision rejected', $svc->writeState($conv_a, $write_scope, 0, array('x' => 1)) === false);
check('write: wrong actor rejected', $svc->writeState($conv_a, array_merge($write_scope, array('user_id' => 7)), 1, array('x' => 1)) === false);
check('write: wrong section rejected', $svc->writeState($conv_a, array_merge($write_scope, array('section_id' => 89)), 1, array('x' => 1)) === false);
check('write: wrong record rejected', $svc->writeState($conv_a, array_merge($write_scope, array('id_dataRows' => 77)), 1, array('x' => 1)) === false);
check('write: wrong context rejected', $svc->writeState($conv_a, array_merge($write_scope, array('context_key' => 'module:b')), 1, array('x' => 1)) === false);
check('write: wrong source rejected', $svc->writeState($conv_a, array_merge($write_scope, array('source_type' => LLM_CONV_SOURCE_MEMORY)), 1, array('x' => 1)) === false);
check('write: negative revision throws', throws_callable(function () use ($svc, $conv_a, $write_scope) {
    $svc->writeState($conv_a, $write_scope, -1, array('x' => 1));
}, 'LlmValidationException'));
check('write: non-integer revision throws', throws_callable(function () use ($svc, $conv_a, $write_scope) {
    $svc->writeState($conv_a, $write_scope, 'abc', array('x' => 1));
}, 'LlmValidationException'));
check('write: list payload throws', throws_callable(function () use ($svc, $conv_a, $write_scope) {
    $svc->writeState($conv_a, $write_scope, 1, array('a', 'b'));
}, 'LlmValidationException'));
check('write: empty map payload allowed', $svc->writeState($conv_a, $write_scope, 1, array()) === true);
$env3 = $svc->readState($db->conversations[$conv_a]);
check('write: empty payload reads back as empty map', $env3 !== null && $env3['payload'] === array());
check('write: cache invalidated on write', in_array(LLM_CACHE_CONVERSATION_MESSAGES, $db->raw_cache->cleared, true));

/* =========================================================================
 * 8. Strict state envelope reads
 * ========================================================================= */

$bad = array(
    'null' => null,
    'empty' => '',
    'malformed json' => '{not json',
    'missing envelope keys' => '{"foo":1}',
    'list payload' => '{"format_version":1,"revision":0,"payload":[1,2]}',
    'scalar payload' => '{"format_version":1,"revision":0,"payload":42}',
    'null payload' => '{"format_version":1,"revision":0,"payload":null}',
    'unknown version' => '{"format_version":2,"revision":0,"payload":{}}',
    'string revision' => '{"format_version":1,"revision":"0","payload":{}}',
    'negative revision' => '{"format_version":1,"revision":-1,"payload":{}}'
);
foreach ($bad as $label => $raw) {
    check("envelope: $label reads as no-envelope", $svc->readState(array('state' => $raw)) === null);
}
$good = $svc->readState(array('state' => '{"format_version":1,"revision":3,"payload":{"k":1}}'));
check('envelope: valid v1 envelope reads', $good !== null && $good['revision'] === 3 && $good['payload'] === array('k' => 1));
$empty_obj = $svc->readState(array('state' => '{"format_version":1,"revision":0,"payload":{}}'));
check('envelope: empty {} payload reads as empty map', $empty_obj !== null && $empty_obj['payload'] === array());

/* =========================================================================
 * 9. Completion + reopening (idempotent under changed-row counts)
 * ========================================================================= */

$done_scope = $write_scope;
check('complete: marks completed_at', $svc->setCompleted($conv_a, $done_scope, 2) === true);
check('complete: timestamp set', $db->conversations[$conv_a]['completed_at'] !== null);
check('complete: repeat at same revision is idempotent success', $svc->setCompleted($conv_a, $done_scope, 2) === true);
check('complete: completed filter excludes it from open drafts', $svc->findScopedConversation($done_scope, false) === null);
check('complete: completed filter finds it', ($c = $svc->findScopedConversation($done_scope, true)) !== null && (int)$c['id'] === $conv_a);
check('complete: stale revision rejected', $svc->setCompleted($conv_a, $done_scope, 0, false) === false);
check('complete: reopening clears timestamp', $svc->setCompleted($conv_a, $done_scope, 2, false) === true);
check('complete: timestamp cleared', $db->conversations[$conv_a]['completed_at'] === null);
check('complete: re-reopen of open thread idempotent', $svc->setCompleted($conv_a, $done_scope, 2, false) === true);
check('complete: wrong-scope reopen rejected', $svc->setCompleted($conv_a, array_merge($done_scope, array('id_dataRows' => 77)), 2, false) === false);
check('complete: wrong-source completion rejected', $svc->setCompleted($conv_a, array_merge($done_scope, array('source_type' => LLM_CONV_SOURCE_CHAT)), 2) === false);
check('complete: draft visible again', ($c = $svc->findScopedConversation($done_scope, false)) !== null && (int)$c['id'] === $conv_a);

/* =========================================================================
 * 10. getScopedConversation + deleted rows + FOR UPDATE
 * ========================================================================= */

check('get: right scope returns row', ($r = $svc->getScopedConversation($conv_a, $done_scope)) !== null && (int)$r['id'] === $conv_a);
$db->conversations[$conv_a]['deleted'] = 1;
check('get: deleted conversation inaccessible', $svc->getScopedConversation($conv_a, $done_scope) === null);
$db->conversations[$conv_a]['deleted'] = 0;
$svc->getScopedConversation($conv_a, $done_scope, true);
check('get: FOR UPDATE appended when requested', strpos(end($db->queries), 'for update') !== false);

/* =========================================================================
 * 11. Creation transaction rollback
 * ========================================================================= */

list($db2, $cache2, $services2, $llm2, $svc2) = env();
$db2->fail_next_update = true; // init update fails inside the transaction
$threw = false;
try {
    $svc2->createScopedConversation(5, 88, LLM_CONV_SOURCE_FORM_INTERVIEW, array('context_key' => 'k'));
} catch (\LlmException $e) {
    $threw = true;
}
check('tx: failed init update throws', $threw);
check('tx: conversation rolled back (no row persisted)', count($db2->conversations) === 0);

$llm2->fail_create = true;
$threw = false;
try {
    $svc2->createScopedConversation(5, 88, LLM_CONV_SOURCE_FORM_INTERVIEW);
} catch (\LlmException $e) {
    $threw = true;
}
check('tx: failed createConversation throws', $threw);
check('tx: no row persisted on create failure', count($db2->conversations) === 0);

/* =========================================================================
 * 12. Malformed stored envelopes cannot be mutated into a valid v1 write
 * ========================================================================= */

list($db3, $cache3, $services3, $llm3, $svc3) = env();
$mk = function ($state) use ($db3) {
    return $db3->seedConversation(array(
        'id_users' => 5,
        'id_sections' => 88,
        'id_llm_conversation_sources' => 3,
        'id_dataRows' => 7,
        'state' => $state
    ));
};
$scope7 = array(
    'user_id' => 5, 'section_id' => 88,
    'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW, 'id_dataRows' => 7
);
// v2 envelope: all mutations fail closed even at "expected" revision 0
$c_v2 = $mk('{"format_version":2,"revision":0,"payload":{}}');
check('mutate: v2 envelope writeState fails', $svc3->writeState($c_v2, $scope7, 0, array('x' => 1)) === false);
check('mutate: v2 envelope untouched', strpos($db3->conversations[$c_v2]['state'], '"format_version":2') !== false);
check('mutate: v2 envelope setCompleted fails', $svc3->setCompleted($c_v2, $scope7, 0) === false);
// string revision: CAST would have matched 0 without the JSON_TYPE guard
$c_strrev = $mk('{"format_version":1,"revision":"0","payload":{}}');
check('mutate: string-revision envelope writeState fails', $svc3->writeState($c_strrev, $scope7, 0, array('x' => 1)) === false);
check('mutate: string-revision envelope untouched', $db3->conversations[$c_strrev]['state'] === '{"format_version":1,"revision":"0","payload":{}}');
check('mutate: string-revision setCompleted fails', $svc3->setCompleted($c_strrev, $scope7, 0) === false);
// list payload: JSON_TYPE is ARRAY not OBJECT
$c_list = $mk('{"format_version":1,"revision":0,"payload":[1,2]}');
check('mutate: list-payload writeState fails', $svc3->writeState($c_list, $scope7, 0, array('x' => 1)) === false);
// null state: no envelope at all
$c_null = $mk(null);
check('mutate: null state writeState fails', $svc3->writeState($c_null, $scope7, 0, array('x' => 1)) === false);
// valid envelope still writes normally
$c_ok = $mk('{"format_version":1,"revision":0,"payload":{}}');
check('mutate: valid envelope writeState succeeds', $svc3->writeState($c_ok, $scope7, 0, array('x' => 1)) === true);
check('mutate: valid envelope now revision 1', $svc3->readState($db3->conversations[$c_ok])['revision'] === 1);

/* =========================================================================
 * 13. Strict scope id validation (no loose intval coercion)
 * ========================================================================= */

$bad_scopes = array(
    'array user_id' => array('user_id' => array(5)),
    'trailing text user_id' => array('user_id' => '5abc'),
    'float section_id' => array('section_id' => 4.5),
    'bool id_dataRows' => array('id_dataRows' => true),
    'hex id_dataRows' => array('id_dataRows' => '0x10'),
    'empty-string section_id' => array('section_id' => ''),
    'spaced user_id' => array('user_id' => ' 5'),
);
foreach ($bad_scopes as $label => $over) {
    $s = array_merge(scope(), $over);
    check("scope strict: $label throws", throws_callable(function () use ($svc3, $s) {
        $svc3->normalizeScope($s);
    }, 'LlmValidationException'));
}
check('scope strict: digit-string ids still accepted', $svc3->normalizeScope(array(
    'user_id' => '5', 'section_id' => '88',
    'source_type' => 'form_interview', 'id_dataRows' => '7'
))['user_id'] === 5);
check('create: list payload option throws', throws_callable(function () use ($svc3) {
    $svc3->createScopedConversation(5, 88, LLM_CONV_SOURCE_FORM_INTERVIEW, array('payload' => array('a', 'b')));
}, 'LlmValidationException'));
check('create: loose user_id rejected', throws_callable(function () use ($svc3) {
    $svc3->createScopedConversation('5abc', 88, LLM_CONV_SOURCE_FORM_INTERVIEW);
}, 'LlmValidationException'));
check('create: array section_id rejected', throws_callable(function () use ($svc3) {
    $svc3->createScopedConversation(5, array(88), LLM_CONV_SOURCE_FORM_INTERVIEW);
}, 'LlmValidationException'));

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
