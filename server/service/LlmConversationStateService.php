<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmTransaction.php';
require_once __DIR__ . '/exception/LlmException.php';
require_once __DIR__ . '/exception/LlmValidationException.php';

/**
 * Shared producer-neutral conversation state service (v1.6.0).
 *
 * Every backend producer that keeps work-in-progress inside
 * `llmConversations` (the llmFormLogList interview first; chat, scripts,
 * playground and memory may opt in later) uses this service instead of
 * feature-specific tables. The storage contract is generic:
 *
 *   - Thread identity = scope array with required keys
 *       user_id (int>0), section_id (int|null), source_type (string),
 *       id_dataRows (positive int|null; NULL = unbound/new draft, exact
 *       match incl. NULL), plus context_key (opaque producer-defined
 *       scenario identity). For unbound scopes the context_key key must be
 *       present (null means "only threads without a key"); for bound
 *       scopes an absent context_key intentionally matches any context.
 *   - `state` is a strict JSON envelope {"format_version": 1,
 *     "revision": <int >= 0>, "payload": <object/map>}. The source
 *     identifies the producer; each producer validates its own payload.
 *   - Writes are revision checked: an update only lands while the stored
 *     revision equals the caller's expectation.
 *   - `completed_at` marks a finished unit of work (e.g. interview
 *     approval); binding a record does not imply completion.
 *
 * Scoped operations fail closed when the producer's source lookup is not
 * registered: a thread must never silently degrade into an untagged
 * sidebar-visible chat row. Reads are deliberately uncached - scoped draft
 * lookups must see the latest committed revision; mutation paths reuse the
 * existing cache-manager invalidation.
 */
class LlmConversationStateService extends BaseLlmService
{
    /** Current state envelope format version. */
    const STATE_FORMAT_VERSION = 1;

    /** Hard bound for context_key (schema: VARCHAR(191)). */
    const CONTEXT_KEY_MAX_LENGTH = 191;

    /** @var object LlmService (or compatible collaborator) used for provider-independent conversation creation */
    private $llm_service;

    /** @var array Memoized llmConversationSourceType lookup ids by source code */
    private $source_lookup_ids = array();

    /**
     * @param object $services SelfHelp services container
     * @param object|null $llm_service Optional collaborator exposing
     *  createConversation(); a real LlmService is created lazily when omitted.
     */
    public function __construct($services, $llm_service = null)
    {
        parent::__construct($services);
        if ($llm_service === null) {
            require_once __DIR__ . '/LlmService.php';
            $llm_service = new LlmService($services);
        }
        $this->llm_service = $llm_service;
    }

    /* =========================================================================
     * SOURCE + CONTEXT + SCOPE
     * ========================================================================= */

    /**
     * Resolve a `llmConversationSourceType` lookup value to its id.
     * Public so producers can fail fast before starting a unit of work.
     *
     * @param string $source_type One of the LLM_CONV_SOURCE_* constants
     * @return int|null Lookup id, or null when the source is not registered
     */
    public function resolveSourceId($source_type)
    {
        if (!is_string($source_type) || $source_type === '') {
            return null;
        }
        if (!array_key_exists($source_type, $this->source_lookup_ids)) {
            try {
                $id = $this->db->get_lookup_id_by_value('llmConversationSourceType', $source_type);
            } catch (\Throwable $e) {
                $id = null;
            }
            $this->source_lookup_ids[$source_type] = $id ? (int)$id : null;
        }
        return $this->source_lookup_ids[$source_type];
    }

    /**
     * Normalize a producer-defined context key: trimmed, NULL/empty → null,
     * rejected once it exceeds the storage bound. Opaque to this service.
     *
     * @param string|null $context_key Raw context key
     * @return string|null
     * @throws LlmValidationException When the key exceeds CONTEXT_KEY_MAX_LENGTH
     */
    public function normalizeContextKey($context_key)
    {
        if ($context_key === null) {
            return null;
        }
        $key = trim((string)$context_key);
        if ($key === '') {
            return null;
        }
        if (mb_strlen($key, 'UTF-8') > self::CONTEXT_KEY_MAX_LENGTH) {
            throw new LlmValidationException(
                'context_key must be at most ' . self::CONTEXT_KEY_MAX_LENGTH . ' characters'
            );
        }
        return $key;
    }

    /**
     * Validate and normalize a scope array. Required keys:
     * user_id (int>0), section_id (int|null), source_type (string),
     * id_dataRows (positive int|null). context_key: required key for
     * unbound scopes (id_dataRows null), optional for bound scopes.
     *
     * @param array $scope
     * @return array Normalized scope
     * @throws LlmValidationException On missing/invalid scope keys
     */
    public function normalizeScope(array $scope)
    {
        foreach (array('user_id', 'section_id', 'source_type', 'id_dataRows') as $key) {
            if (!array_key_exists($key, $scope)) {
                throw new LlmValidationException("scope key '$key' is required");
            }
        }
        $user_id = self::strictId($scope['user_id'], 'user_id');
        $section_id = self::strictId($scope['section_id'], 'section_id', true);
        $source_type = $scope['source_type'];
        if (!is_string($source_type) || $source_type === '') {
            throw new LlmValidationException('scope source_type must be a non-empty string');
        }
        $id_dataRows = self::strictId($scope['id_dataRows'], 'id_dataRows', true);
        if ($id_dataRows === null && !array_key_exists('context_key', $scope)) {
            throw new LlmValidationException(
                'scope context_key is required for unbound (id_dataRows = null) scopes'
            );
        }
        $normalized = array(
            'user_id' => $user_id,
            'section_id' => $section_id,
            'source_type' => $source_type,
            'id_dataRows' => $id_dataRows
        );
        if (array_key_exists('context_key', $scope)) {
            $normalized['context_key'] = $this->normalizeContextKey($scope['context_key']);
        }
        return $normalized;
    }

    /* =========================================================================
     * CONVERSATION CREATION
     * ========================================================================= */

    /**
     * Create a scoped conversation thread through the provider-independent
     * LlmService and set its generic identity/state columns inside one
     * transaction (rolled back when initialization fails).
     *
     * @param int $user_id Actor id
     * @param int|null $section_id Producing section (exact match incl. NULL)
     * @param string $source_type LLM_CONV_SOURCE_* constant
     * @param array $options Optional keys:
     *   - context_key (string|null) opaque scenario identity (bounded)
     *   - id_dataRows (positive int|null) initial record binding
     *   - payload (array map|null) initial producer payload (revision 0)
     *   - title, model, temperature, max_tokens forwarded to LlmService
     * @return int New conversation id
     * @throws LlmException When the source is not registered or the write fails
     */
    public function createScopedConversation($user_id, $section_id, $source_type, array $options = array())
    {
        LlmTransaction::begin($this->db);
        try {
            $conversation_id = $this->createScopedConversationRow(
                $user_id, $section_id, $source_type, $options
            );
            LlmTransaction::commit($this->db);
        } catch (\Throwable $e) {
            LlmTransaction::rollback($this->db);
            throw ($e instanceof \Exception) ? $e : new LlmException($e->getMessage());
        }
        $this->afterScopedCreate($user_id, $conversation_id, $source_type);
        return (int)$conversation_id;
    }

    /**
     * Caller-transaction variant of createScopedConversation: identical
     * create + identity init, but no transaction handling - the caller
     * already holds the transaction that serializes the scope (e.g. an
     * actor row lock) and must commit or roll it back.
     */
    public function createScopedConversationLocked($user_id, $section_id, $source_type, array $options = array())
    {
        $conversation_id = $this->createScopedConversationRow(
            $user_id, $section_id, $source_type, $options
        );
        $this->afterScopedCreate($user_id, $conversation_id, $source_type);
        return (int)$conversation_id;
    }

    private function createScopedConversationRow($user_id, $section_id, $source_type, array $options)
    {
        if ($this->resolveSourceId($source_type) === null) {
            throw new LlmException(
                "Conversation source '$source_type' is not registered; refusing to create an untagged thread."
            );
        }
        $user_id = self::strictId($user_id, 'user_id');
        $section_id = self::strictId($section_id, 'section_id', true);
        $context_key = $this->normalizeContextKey($options['context_key'] ?? null);
        $id_dataRows = isset($options['id_dataRows'])
            ? self::strictId($options['id_dataRows'], 'id_dataRows')
            : null;
        $payload = $options['payload'] ?? array();
        $this->assertMapPayload($payload);
        $state = $this->buildEnvelope(0, $payload);

        $conversation_id = $this->llm_service->createConversation(
            $user_id,
            $options['title'] ?? null,
            $options['model'] ?? null,
            $options['temperature'] ?? null,
            $options['max_tokens'] ?? null,
            $section_id,
            $source_type
        );
        if (!$conversation_id) {
            throw new LlmException('Scoped conversation could not be created');
        }
        $affected = $this->db->execute_update_db(
            'UPDATE llmConversations
                SET id_dataRows = :id_dataRows,
                    context_key = :context_key,
                    state = :state
                WHERE id = :id',
            array(
                ':id_dataRows' => $id_dataRows,
                ':context_key' => $context_key,
                ':state' => $state,
                ':id' => (int)$conversation_id
            )
        );
        if ($affected !== 1) {
            throw new LlmException('Scoped conversation identity could not be initialized');
        }
        return (int)$conversation_id;
    }

    private function afterScopedCreate($user_id, $conversation_id, $source_type)
    {
        $this->cacheManager->clearUserCache((int)$user_id);
        $this->logTransaction(
            transactionTypes_insert,
            'llmConversations',
            $conversation_id,
            $user_id,
            'Scoped conversation created (source: ' . $source_type . ')'
        );
    }

    /* =========================================================================
     * SCOPED READS
     * ========================================================================= */

    /**
     * Fetch one conversation row after re-checking the full scope.
     * Client-supplied conversation ids are references only.
     *
     * @param int $conversation_id
     * @param array $scope Full scope (see normalizeScope)
     * @param bool $for_update Lock the row for a subsequent scoped write
     *   (SELECT ... FOR UPDATE; only meaningful inside a transaction)
     * @return array|null Conversation row or null when out of scope
     */
    public function getScopedConversation($conversation_id, array $scope, $for_update = false)
    {
        $scope = $this->normalizeScope($scope);
        $where = $this->scopeWhere($scope);
        if ($where === null) {
            return null;
        }
        $sql = 'SELECT * FROM llmConversations
                WHERE id = :id AND deleted = 0' . $where['where'];
        $args = array_merge(array(':id' => (int)$conversation_id), $where['args']);
        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->db->query_db_first($sql, $args);
        return $row ?: null;
    }

    /**
     * Find the latest conversation for an exact scope.
     *
     * @param array $scope Full scope (see normalizeScope); for bound scopes
     *   omit context_key to match any recorded context
     * @param bool|null $completed null = any, false = unfinished only,
     *   true = completed only
     * @return array|null Latest matching conversation row or null
     */
    public function findScopedConversation(array $scope, $completed = null)
    {
        $scope = $this->normalizeScope($scope);
        $where = $this->scopeWhere($scope);
        if ($where === null) {
            return null;
        }
        $sql = 'SELECT * FROM llmConversations
                WHERE deleted = 0' . $where['where'];
        if ($completed === false) {
            $sql .= ' AND completed_at IS NULL';
        } elseif ($completed === true) {
            $sql .= ' AND completed_at IS NOT NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $row = $this->db->query_db_first($sql, $where['args']);
        return $row ?: null;
    }

    /**
     * All conversations in one exact scope, newest first. Used by
     * producers that must select by producer-side state (e.g. phase)
     * rather than only by completion flag.
     *
     * @param array $scope Full scope (see normalizeScope)
     * @param int $limit Max rows returned (bounded)
     * @return array Conversation rows ordered by id DESC
     */
    public function listScopedConversations(array $scope, $limit = 50)
    {
        $scope = $this->normalizeScope($scope);
        $where = $this->scopeWhere($scope);
        if ($where === null) {
            return array();
        }
        $limit = (int)$limit;
        if ($limit < 1) {
            $limit = 1;
        } else if ($limit > 200) {
            $limit = 200;
        }
        $rows = $this->db->query_db(
            'SELECT * FROM llmConversations
                WHERE deleted = 0' . $where['where']
            . ' ORDER BY id DESC LIMIT ' . $limit,
            $where['args']
        );
        return is_array($rows) ? $rows : array();
    }

    /* =========================================================================
     * STATE ENVELOPE
     * ========================================================================= */

    /**
     * Decode the shared state envelope from a conversation row.
     * Strict: only format_version 1, integer revision >= 0 and an
     * object/map payload (empty map allowed) qualify; anything else -
     * including unknown versions, string/negative revisions, scalar, null
     * or list payloads - reads as "no envelope" instead of being coerced.
     *
     * @param array $conversation Conversation row (must contain `state`)
     * @return array|null ['format_version'=>1,'revision'=>int,'payload'=>array] or null
     */
    public function readState(array $conversation)
    {
        $raw = isset($conversation['state']) ? $conversation['state'] : null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw);
        if (!is_object($decoded)
            || !isset($decoded->format_version)
            || $decoded->format_version !== self::STATE_FORMAT_VERSION
            || !isset($decoded->revision)
            || !is_int($decoded->revision)
            || $decoded->revision < 0
            || !property_exists($decoded, 'payload')
            || !is_object($decoded->payload)
        ) {
            return null;
        }
        $payload = json_decode(json_encode($decoded->payload), true);
        return array(
            'format_version' => self::STATE_FORMAT_VERSION,
            'revision' => $decoded->revision,
            'payload' => is_array($payload) ? $payload : array()
        );
    }

    /**
     * Revision-checked state write against the full scope. Succeeds only
     * while the stored envelope revision equals `$expected_revision`; the
     * payload is stored under revision `$expected_revision + 1`.
     *
     * @param int $conversation_id
     * @param array $scope Full scope of the thread being written
     * @param int $expected_revision Non-negative integer revision the caller read
     * @param array $payload Producer payload (object/map; empty allowed)
     * @return bool True when exactly one row was updated
     */
    public function writeState($conversation_id, array $scope, $expected_revision, array $payload)
    {
        $scope = $this->normalizeScope($scope);
        $revision = $this->normalizeRevision($expected_revision);
        $this->assertMapPayload($payload);
        $where = $this->scopeWhere($scope);
        if ($where === null) {
            return false;
        }
        $state = $this->buildEnvelope($revision + 1, $payload);
        $affected = $this->db->execute_update_db(
            'UPDATE llmConversations
                SET state = :state
                WHERE id = :id AND deleted = 0' . $where['where']
                . self::stateEnvelopeGuard()
                . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(state, \'$.revision\')) AS UNSIGNED) = :sc_revision',
            array_merge($where['args'], array(
                ':id' => (int)$conversation_id,
                ':state' => $state,
                ':sc_revision' => $revision
            ))
        );
        $ok = ($affected === 1);
        if ($ok) {
            $this->invalidateConversationCache($scope['user_id'], (int)$conversation_id);
            // No audit row here: a revision is bookkeeping, written on every
            // draft autosave (400 ms debounce) and turn, and core's
            // add_transaction() would store the whole session with each of
            // them. The events that matter (creation, record binding,
            // completion, messages) are audited by their own calls.
        }
        return $ok;
    }

    /* =========================================================================
     * RECORD BINDING + COMPLETION
     * ========================================================================= */

    /**
     * One-time record binding: links a still-unbound draft to a dataRows id.
     * Fails when the scope/revision no longer matches or the thread is
     * already bound; an already-bound thread carrying the requested id
     * counts as an idempotent success.
     *
     * @param int $conversation_id
     * @param array $scope Scope of the draft (id_dataRows null + context_key)
     * @param int $id_dataRows dataRows id (>0)
     * @param int $expected_revision Revision the caller read
     * @return bool
     */
    public function bindRecord($conversation_id, array $scope, $id_dataRows, $expected_revision)
    {
        $scope = $this->normalizeScope($scope);
        $revision = $this->normalizeRevision($expected_revision);
        $id_dataRows = (int)$id_dataRows;
        if ($id_dataRows <= 0) {
            throw new LlmValidationException('id_dataRows must be a positive row id');
        }
        $where = $this->scopeWhere($scope);
        if ($where === null) {
            return false;
        }
        $affected = $this->db->execute_update_db(
            'UPDATE llmConversations
                SET id_dataRows = :sc_new_row
                WHERE id = :id AND deleted = 0' . $where['where']
                . ' AND id_dataRows IS NULL'
                . self::stateEnvelopeGuard()
                . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(state, \'$.revision\')) AS UNSIGNED) = :sc_revision',
            array_merge($where['args'], array(
                ':id' => (int)$conversation_id,
                ':sc_new_row' => $id_dataRows,
                ':sc_revision' => $revision
            ))
        );
        if ($affected === 1) {
            $this->invalidateConversationCache($scope['user_id'], (int)$conversation_id);
            $this->logTransaction(
                transactionTypes_update,
                'llmConversations',
                $conversation_id,
                $scope['user_id'],
                'Conversation bound to dataRows ' . $id_dataRows
            );
            return true;
        }
        // idempotent: already bound to the requested row in the same scope/revision
        $row = $this->getScopedConversation($conversation_id, $scope);
        return $row !== null
            && (int)$row['id_dataRows'] === $id_dataRows
            && $this->stateRevision($row) === $revision;
    }

    /**
     * Set or clear the completion timestamp. Completing keeps the first
     * `completed_at` (COALESCE). Idempotent: a thread already in the
     * requested state for the same scope/revision reports success instead
     * of falsely failing on an unchanged-row count.
     *
     * @param int $conversation_id
     * @param array $scope Full scope of the thread
     * @param int $expected_revision Revision the caller read
     * @param bool $completed True = mark completed, false = reopen
     * @return bool
     */
    public function setCompleted($conversation_id, array $scope, $expected_revision, $completed = true)
    {
        $scope = $this->normalizeScope($scope);
        $revision = $this->normalizeRevision($expected_revision);
        $where = $this->scopeWhere($scope);
        if ($where === null) {
            return false;
        }
        $affected = $this->db->execute_update_db(
            'UPDATE llmConversations
                SET completed_at = ' . ($completed ? 'COALESCE(completed_at, NOW())' : 'NULL') . '
                WHERE id = :id AND deleted = 0' . $where['where']
                . ($completed ? ' AND completed_at IS NULL' : ' AND completed_at IS NOT NULL')
                . self::stateEnvelopeGuard()
                . ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(state, \'$.revision\')) AS UNSIGNED) = :sc_revision',
            array_merge($where['args'], array(
                ':id' => (int)$conversation_id,
                ':sc_revision' => $revision
            ))
        );
        if ($affected === 1) {
            $this->invalidateConversationCache($scope['user_id'], (int)$conversation_id);
            $this->logTransaction(
                transactionTypes_update,
                'llmConversations',
                $conversation_id,
                $scope['user_id'],
                $completed ? 'Conversation marked completed' : 'Conversation reopened'
            );
            return true;
        }
        // idempotent: already in the requested state for the same scope/revision
        $row = $this->getScopedConversation($conversation_id, $scope);
        if ($row === null || $this->stateRevision($row) !== $revision) {
            return false;
        }
        $is_completed = $row['completed_at'] !== null && $row['completed_at'] !== '';
        return $is_completed === (bool)$completed;
    }

    /* =========================================================================
     * INTERNALS
     * ========================================================================= */

    /**
     * Shared scope WHERE fragment over all given scope keys (exact NULL
     * semantics via <=>). Returns null - fail closed - when the source
     * lookup is not registered.
     *
     * @param array $scope Normalized scope
     * @return array|null ['where' => ' AND ...', 'args' => [...]]
     */
    private function scopeWhere(array $scope)
    {
        $source_id = $this->resolveSourceId($scope['source_type']);
        if ($source_id === null) {
            return null;
        }
        $where = ' AND id_users = :sc_user'
            . ' AND id_sections <=> :sc_section'
            . ' AND id_llm_conversation_sources <=> :sc_source'
            . ' AND id_dataRows <=> :sc_row';
        $args = array(
            ':sc_user' => $scope['user_id'],
            ':sc_section' => $scope['section_id'],
            ':sc_source' => $source_id,
            ':sc_row' => $scope['id_dataRows']
        );
        if (array_key_exists('context_key', $scope)) {
            $where .= ' AND context_key <=> :sc_ctx';
            $args[':sc_ctx'] = $scope['context_key'];
        }
        return array('where' => $where, 'args' => $args);
    }

    /**
     * SQL predicates asserting the stored envelope is a valid v1 object:
     * mutations must never re-shape a stale invalid or future-version
     * envelope into a valid v1 write.
     *
     * @return string AND-prefixed WHERE fragment
     */
    private static function stateEnvelopeGuard()
    {
        return ' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(state, \'$.format_version\')) AS UNSIGNED) = 1'
            . ' AND JSON_TYPE(JSON_EXTRACT(state, \'$.revision\')) = \'INTEGER\''
            . ' AND JSON_TYPE(JSON_EXTRACT(state, \'$.payload\')) = \'OBJECT\'';
    }

    /**
     * Strict positive-integer scope id: accepts int or a pure-digit string
     * only; arrays, floats, booleans, nonnumeric text and trailing junk are
     * rejected instead of being coerced via intval().
     *
     * @param mixed $value
     * @param string $name Scope key name for the error message
     * @param bool $allow_null
     * @return int|null
     * @throws LlmValidationException
     */
    private static function strictId($value, $name, $allow_null = false)
    {
        if ($value === null && $allow_null) {
            return null;
        }
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $id = (int)$value;
        } else {
            $id = 0;
        }
        if ($id <= 0) {
            throw new LlmValidationException(
                'scope ' . $name . ' must be a positive integer' . ($allow_null ? ' or null' : '')
            );
        }
        return $id;
    }

    private function normalizeRevision($expected_revision)
    {
        if (is_int($expected_revision)) {
            $revision = $expected_revision;
        } elseif (is_string($expected_revision) && ctype_digit($expected_revision)) {
            $revision = (int)$expected_revision;
        } else {
            throw new LlmValidationException('expected_revision must be a non-negative integer');
        }
        if ($revision < 0) {
            throw new LlmValidationException('expected_revision must be a non-negative integer');
        }
        return $revision;
    }

    private function assertMapPayload($payload)
    {
        if (!is_array($payload)
            || ($payload !== array() && array_is_list($payload))
        ) {
            throw new LlmValidationException('state payload must be an object/map (non-list array)');
        }
    }

    private function stateRevision(array $row)
    {
        $state = $this->readState($row);
        return $state !== null ? $state['revision'] : null;
    }

    private function invalidateConversationCache($user_id, $conversation_id)
    {
        $this->cacheManager->clearUserCache((int)$user_id);
        $this->cacheManager->clearConversationMessageCache((int)$conversation_id);
    }

    /**
     * Encode a state envelope; an empty payload map is stored as a JSON
     * object `{}` so the on-disk shape stays an object, never a list.
     *
     * @param int $revision
     * @param array $payload Producer-owned payload
     * @return string JSON envelope
     * @throws LlmException When the payload cannot be encoded
     */
    private function buildEnvelope($revision, $payload)
    {
        $this->assertMapPayload($payload);
        $json = $this->jsonEncode(array(
            'format_version' => self::STATE_FORMAT_VERSION,
            'revision' => (int)$revision,
            'payload' => $payload === array() ? new \stdClass() : $payload
        ));
        if ($json === null) {
            throw new LlmException('Conversation state could not be encoded');
        }
        return $json;
    }
}
?>
