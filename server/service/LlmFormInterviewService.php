<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/LlmConversationStateService.php';
require_once __DIR__ . '/LlmFormSchemaService.php';
require_once __DIR__ . '/LlmResponseService.php';
require_once __DIR__ . '/LlmDangerDetectionService.php';
require_once __DIR__ . '/LlmFormInterviewAbort.php';
require_once __DIR__ . '/LlmFormInterviewProjector.php';
require_once __DIR__ . '/LlmFormInterviewContextBuilder.php';
require_once __DIR__ . '/cache/LlmCacheManager.php';
require_once __DIR__ . '/LlmTransaction.php';
require_once __DIR__ . '/LlmErrorReporter.php';
require_once __DIR__ . '/exception/LlmRateLimitException.php';

/**
 * Interview runtime for the llmFormLogList style (v1.6.0).
 *
 * One scoped llmConversations thread per exact
 * participant/section/record/context scope holds the whole draft; no
 * dataRows row is written until explicit approval. The server stays
 * authoritative for eligibility, permissions, native values, requiredness,
 * visibility and approval; the LLM can only return a validated
 * `interview.collected_fields` patch.
 *
 * Draft payload (state envelope payload):
 *   phase ('draft'|'approving'|'abandoned'|'approved'), open_token,
 *   request ({id, status: pending|succeeded|failed}), draft,
 *   inactive_values, schema_signature, last_coach_signature,
 *   base_record_fingerprint, opening_context, active_field, approval.
 *
 * This class owns the write paths (open, draft, turn, approval, preview)
 * and their scope, lock and state rules. What the participant sees is
 * built by LlmFormInterviewProjector, the LLM context of a turn by
 * LlmFormInterviewContextBuilder.
 */
class LlmFormInterviewService
{
    const ORIGIN_MANUAL = 'manual';
    const ORIGIN_NATIVE = 'native';
    const ORIGIN_LLM = 'llm';
    /** Longest participant message of one turn, in characters */
    const MAX_MESSAGE_LENGTH = 10000;
    /** Longest request id / approval token kept in the conversation state */
    const MAX_TOKEN_LENGTH = 191;
    /** Seconds an open waits for another open of the same participant */
    const ACTOR_LOCK_TIMEOUT_SECONDS = 10;

    private $services;
    private $llm_service;
    private $state_service;
    private $schema_service;
    private $projector;
    private $context_builder;
    private $db;
    private $cache_manager;

    /**
     * @param object $services SelfHelp services container
     * @param object|null $llm_service Injectable LlmService-compatible collaborator
     * @param object|null $state_service Injectable LlmConversationStateService
     * @param object|null $schema_service Injectable LlmFormSchemaService
     */
    public function __construct($services, $llm_service = null, $state_service = null, $schema_service = null)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->llm_service = $llm_service !== null ? $llm_service : new LlmService($services);
        $this->state_service = $state_service !== null
            ? $state_service
            : new LlmConversationStateService($services, $this->llm_service);
        $this->schema_service = $schema_service !== null
            ? $schema_service
            : new LlmFormSchemaService();
        $this->projector = new LlmFormInterviewProjector(
            $this->db, $this->llm_service, $this->state_service, $this->schema_service
        );
        $this->context_builder = new LlmFormInterviewContextBuilder(
            $services, $this->llm_service, $this->schema_service, $this->projector
        );
        $this->cache_manager = new LlmCacheManager($this->db->get_cache(), $this->db);
    }

    /**
     * Server-rendered native control callback; receives a field name and
     * returns an HTML fragment (or empty string). The projection exposes
     * the fragments so the frontend can re-render native fallbacks
     * without rebuilding core components.
     */
    public function setNativeRenderer($renderer)
    {
        $this->projector->setNativeRenderer($renderer);
    }

    /* Public API *************************************************************/

    /**
     * Open (or continue) the scoped interview for one record scope.
     *
     * What an open does depends on the retention policy:
     *
     *   continue        reopen the unfinished draft; a saved record reopens its
     *                   thread, a new record starts fresh once none is open
     *   new_after_save  reopen the unfinished draft only; every open after a
     *                   save starts a fresh thread
     *   new_on_open     every open starts a fresh thread and discards an older
     *                   unfinished one - except a reload of the session that is
     *                   still open (`$resume`) or a retried call (same token)
     *
     * @param LlmFormLogListModel $model
     * @param int|null $record_id Bound record id; null = new-record draft
     * @param string $open_token Client-generated idempotency token
     * @param bool $restart Abandon a named thread and start fresh
     * @param int $restart_conversation_id Explicit reset target; required when $restart is set
     * @param bool $resume The client restores a session that was still open
     *   (page reload): keep the unfinished thread whatever the retention is
     * @return array Structured result with 'state' projection
     */
    public function open($model, $record_id, $open_token, $restart = false,
        $restart_conversation_id = 0, $resume = false)
    {
        try {
            $this->requireInterviewModel($model);
            $actor = $this->requireActor();
            $record_id = $this->normalizeRecordId($record_id);
            $entry = $this->authorizeRecord($model, $record_id);
            $section_id = (int)$model->get_section_id();
            $retention = $model->getInterviewChatRetention();
            $open_token = trim((string)$open_token);
            if ($open_token === '') {
                throw new LlmFormInterviewAbort('validation', 'An open token is required');
            }
            if (mb_strlen($open_token, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                throw new LlmFormInterviewAbort('validation', 'The open token is too long');
            }

            LlmTransaction::begin($this->db);
            try {
                $this->lockActor($actor);
                $model->setInterviewBaseEntry(is_array($entry) ? $entry : array());
                if ($record_id === null) {
                    $context_key = $this->openingContextKey($model);
                    $scope = $this->scope($actor, $section_id, null, $context_key);
                } else {
                    $scope = $this->scope($actor, $section_id, $record_id, null);
                }
                $unfinished = null;
                $completed = null;
                $unfinished_rows = array();
                foreach ($this->state_service->listScopedConversations($scope) as $row) {
                    // Crisis/danger blocks are per-thread: open never resumes
                    // a blocked conversation. Reload and new_on_open may
                    // therefore start a fresh interview for the same scope.
                    if (!empty($row['blocked'])) {
                        continue;
                    }
                    $env = $this->state_service->readState($row);
                    $phase = $env !== null && isset($env['payload']['phase'])
                        ? $env['payload']['phase'] : 'draft';
                    if ($phase === 'abandoned') {
                        continue;
                    }
                    if ($row['completed_at'] === null) {
                        $unfinished_rows[] = $row;
                        if ($unfinished === null) {
                            $unfinished = $row;
                        }
                    } else if ($completed === null) {
                        $completed = $row;
                    }
                }
                $conversation = null;
                if ($retention === 'new_on_open') {
                    // A retried open call (same token) or a reload of the
                    // session that is still open keeps its thread.
                    foreach (array($unfinished, $completed) as $row) {
                        if ($row === null) {
                            continue;
                        }
                        $env = $this->state_service->readState($row);
                        $stored = $env !== null && isset($env['payload']['open_token'])
                            ? $env['payload']['open_token'] : null;
                        if ($stored === $open_token) {
                            $conversation = $row;
                            break;
                        }
                    }
                    if ($conversation === null && $resume) {
                        $conversation = $unfinished;
                    }
                } else if ($retention === 'new_after_save') {
                    $conversation = $unfinished;
                } else {
                    $conversation = $unfinished !== null ? $unfinished : $completed;
                }
                if ($restart && $restart_conversation_id > 0) {
                    // Explicit reset event: abandon exactly the requested
                    // thread with a revision CAS, never a scope sweep.
                    $target = $this->state_service->getScopedConversation(
                        (int)$restart_conversation_id, $scope, true
                    );
                    if ($target !== null) {
                        $this->abandonConversation($target, $scope);
                    }
                    $conversation = null;
                } else if ($conversation === null && $retention === 'new_on_open') {
                    // A fresh chat on every open must not leave older
                    // unfinished threads behind: another tab or browser
                    // would keep writing to a draft nobody can see.
                    foreach ($unfinished_rows as $row) {
                        $this->abandonConversation($row, $scope);
                    }
                }
                if ($conversation === null) {
                    $model->rebuildInterviewChildren(array());
                    $this->assertRootEligible($model);
                    $schema = $this->schema_service->build($model);
                    LlmFormInterviewAbort::requireValidSchema($schema);
                    $payload = $this->newDraftPayload(
                        $model, $schema, $record_id, $entry, $open_token,
                        isset($scope['context_key']) ? $scope['context_key'] : null
                    );
                    $options = array(
                        'id_dataRows' => $record_id,
                        'payload' => $payload,
                        'model' => $model->getLlmModel(),
                        'temperature' => $model->getLlmTemperature(),
                        'max_tokens' => $model->getLlmMaxTokens(),
                    );
                    if ($record_id === null) {
                        $options['context_key'] = $scope['context_key'];
                    }
                    // Caller-transaction create: the actor lock above
                    // serializes concurrent opens of this scope.
                    $conversation_id = $this->state_service->createScopedConversationLocked(
                        $actor, $section_id, LLM_CONV_SOURCE_FORM_INTERVIEW, $options
                    );
                    $conversation = $this->state_service->getScopedConversation(
                        $conversation_id, $scope
                    );
                }
                if ($conversation === null) {
                    throw new LlmFormInterviewAbort(
                        'invalid_state', 'Scoped conversation could not be resolved'
                    );
                }
                LlmTransaction::commit($this->db);
                $this->unlockActor($actor);
            } catch (Throwable $e) {
                LlmTransaction::rollback($this->db);
                $this->unlockActor($actor);
                throw $e;
            }
            return $this->ok(array('state' => $this->projector->project($model, $record_id, $conversation)));
        } catch (LlmFormInterviewAbort $abort) {
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('open', $e, isset($conversation) ? $conversation : null);
        }
    }

    /**
     * Discard an unfinished thread (CAS on its revision). A thread whose
     * approval is running is left alone - the approval finishes it.
     */
    private function abandonConversation(array $conversation, array $scope)
    {
        $env = $this->state_service->readState($conversation);
        if ($env === null) {
            return;
        }
        $payload = $env['payload'];
        $this->normalizePayload($payload);
        if (isset($payload['phase']) && $payload['phase'] === 'approving') {
            return;
        }
        $payload['phase'] = 'abandoned';
        $this->state_service->writeState(
            (int)$conversation['id'], $scope, (int)$env['revision'], $payload
        );
    }

    /**
     * Projection that accompanies a conflict. The thread the client wrote
     * to may have been cleared elsewhere (another tab or browser started a
     * fresh chat): its own state is a dead end - every further write would
     * conflict again - so the live thread of the scope is handed over
     * instead, or null when there is none and the client opens a fresh one.
     * A stale revision or a running approval returns the thread's fresh
     * state as before.
     *
     * @return array|null Projection, or null when the scope has no live thread
     */
    private function conflictState($model, $actor, $record_id, array $conversation)
    {
        $fresh = $this->db->query_db_first(
            'SELECT * FROM llmConversations WHERE id = :id',
            array(':id' => (int)$conversation['id'])
        );
        if (is_array($fresh)) {
            $conversation = $fresh;
        }
        $env = $this->state_service->readState($conversation);
        $phase = $env !== null && isset($env['payload']['phase'])
            ? $env['payload']['phase'] : 'draft';
        if ($phase !== 'abandoned') {
            return $this->projector->project($model, $record_id, $conversation);
        }
        try {
            return $this->projector->project(
                $model, $record_id,
                $this->resolveLatestScopedConversation($model, $actor, $record_id)
            );
        } catch (LlmFormInterviewAbort $e) {
            return null;
        }
    }

    /**
     * Read-only participant-safe projection of a scoped conversation.
     *
     * @param object $model Interview model
     * @param int|null $record_id Bound record id, or null for a create scope
     * @param int|null $conversation_id Exact conversation id, or null to
     *   resolve the latest usable thread for the scope (unfinished first,
     *   otherwise the newest completed non-abandoned thread)
     * @return array Structured result with 'state' projection
     */
    public function read($model, $record_id, $conversation_id = null)
    {
        try {
            $this->requireInterviewModel($model);
            $actor = $this->requireActor();
            $record_id = $this->normalizeRecordId($record_id);
            $entry = $this->authorizeRecord($model, $record_id);
            $model->setInterviewBaseEntry(is_array($entry) ? $entry : array());
            if ($conversation_id === null || (int)$conversation_id <= 0) {
                $conversation = $this->resolveLatestScopedConversation($model, $actor, $record_id);
            } else {
                list($conversation) = $this->loadScopedConversation(
                    $model, $actor, $record_id, (int)$conversation_id
                );
            }
            return $this->ok(array('state' => $this->projector->project($model, $record_id, $conversation)));
        } catch (LlmFormInterviewAbort $abort) {
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('read', $e, isset($conversation) ? $conversation : null);
        }
    }

    /**
     * Apply draft patches with revision CAS: `patch` is the participant's
     * manual edit map, `native_patch` the server-rendered native fallback
     * map (native_only and manual-writable controls only - hidden,
     * scope-bound and upload fields are rejected by the schema).
     */
    public function updateDraft($model, $record_id, $conversation_id, $expected_revision,
        array $patch, array $native_patch = array())
    {
        try {
            $this->requireInterviewModel($model);
            $actor = $this->requireActor();
            $record_id = $this->normalizeRecordId($record_id);
            $entry = $this->authorizeRecord($model, $record_id);
            $model->setInterviewBaseEntry(is_array($entry) ? $entry : array());
            $expected_revision = $this->normalizeRevision($expected_revision);

            $conversation = null;
            LlmTransaction::begin($this->db);
            try {
                list($conversation, $scope) = $this->loadScopedConversation(
                    $model, $actor, $record_id, $conversation_id, true
                );
                $env = $this->state_service->readState($conversation);
                if ($env === null) {
                    throw new LlmFormInterviewAbort('invalid_state', 'Conversation state is missing');
                }
                $payload = $env['payload'];
                $this->normalizePayload($payload);
                $this->assertPhaseWritable($payload);
                if ((int)$env['revision'] !== $expected_revision) {
                    throw new LlmFormInterviewAbort('conflict', 'Stale revision', array(
                        'current_revision' => (int)$env['revision'],
                    ));
                }
                if ($conversation['completed_at'] !== null) {
                    $this->state_service->setCompleted(
                        (int)$conversation['id'], $scope, $expected_revision, false
                    );
                }
                $model->rebuildInterviewChildren($payload['draft']);
                $this->assertRootEligible($model);
                $schema = $this->schema_service->build($model);
                LlmFormInterviewAbort::requireValidSchema($schema);
                if (!empty($native_patch)) {
                    $normalized = $this->schema_service->normalizePatch(
                        $schema, $native_patch, self::ORIGIN_NATIVE
                    );
                    if (!empty($normalized['errors'])) {
                        throw new LlmFormInterviewAbort(
                            'validation', 'Native patch contains invalid fields',
                            array('field_errors' => $normalized['errors'])
                        );
                    }
                    $payload['draft'] = $normalized['values'] + $payload['draft'];
                }
                if (!empty($patch)) {
                    $normalized = $this->schema_service->normalizePatch(
                        $schema, $patch, self::ORIGIN_MANUAL
                    );
                    if (!empty($normalized['errors'])) {
                        throw new LlmFormInterviewAbort(
                            'validation', 'Patch contains invalid fields',
                            array('field_errors' => $normalized['errors'])
                        );
                    }
                    $payload['draft'] = $normalized['values'] + $payload['draft'];
                }
                $payload = $this->reconcile($model, $payload);
                $payload['phase'] = 'draft';
                if (!$this->state_service->writeState(
                    (int)$conversation['id'], $scope, $expected_revision, $payload
                )) {
                    throw new LlmFormInterviewAbort('conflict', 'Concurrent modification');
                }
                LlmTransaction::commit($this->db);
            } catch (LlmFormInterviewAbort $abort) {
                LlmTransaction::rollback($this->db);
                // Conflicts carry the fresh projection so the UI can
                // rebase instead of guessing the new revision.
                if ($abort->errorType() === 'conflict' && $conversation !== null) {
                    return $this->err('conflict', $abort->getMessage(),
                        $abort->errorData() + array(
                            'state' => $this->conflictState($model, $actor, $record_id, $conversation),
                        ));
                }
                throw $abort;
            } catch (Throwable $e) {
                LlmTransaction::rollback($this->db);
                throw $e;
            }
            return $this->ok(array('state' => $this->projector->project($model, $record_id, $conversation)));
        } catch (LlmFormInterviewAbort $abort) {
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('update draft', $e, isset($conversation) ? $conversation : null);
        }
    }

    /**
     * Run one interview turn: merge pending manual edits, call the LLM,
     * validate the unified response + interview contract, then merge the
     * accepted patch under CAS. No remote call happens during tests; the
     * callable seam goes through callLlmApi (each attempt auto-logs one
     * unvalidated assistant message).
     */
    public function turn($model, $record_id, $conversation_id, $expected_revision, $message,
        array $pending_patch = array(), $request_id = '', $start = false,
        array $native_patch = array())
    {
        try {
            $this->requireInterviewModel($model);
            $actor = $this->requireActor();
            $record_id = $this->normalizeRecordId($record_id);
            $entry = $this->authorizeRecord($model, $record_id);
            $model->setInterviewBaseEntry(is_array($entry) ? $entry : array());
            $expected_revision = $this->normalizeRevision($expected_revision);
            $request_id = trim((string)$request_id);
            $message = (string)$message;
            if ($request_id === '') {
                throw new LlmFormInterviewAbort('validation', 'A request id is required');
            }
            if (mb_strlen($request_id, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                throw new LlmFormInterviewAbort('validation', 'The request id is too long');
            }
            if (!$start && trim($message) === '') {
                throw new LlmFormInterviewAbort('validation', 'Message must not be empty');
            }
            if (mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
                throw new LlmFormInterviewAbort('validation', 'Message is too long');
            }

            $pre_merge_revision = null;
            $conversation = null;
            LlmTransaction::begin($this->db);
            try {
                list($conversation, $scope) = $this->loadScopedConversation(
                    $model, $actor, $record_id, $conversation_id, true
                );
                $env = $this->state_service->readState($conversation);
                if ($env === null) {
                    throw new LlmFormInterviewAbort('invalid_state', 'Conversation state is missing');
                }
                $payload = $env['payload'];
                $this->normalizePayload($payload);
                // Request replay is checked before the revision check so a
                // retried call with the original (now stale) revision is
                // still idempotent; only a succeeded request replays -
                // pending (crashed) and failed requests may be retried.
                $stored_request = isset($payload['request']) && is_array($payload['request'])
                    ? $payload['request'] : array();
                if (isset($stored_request['id']) && $stored_request['id'] === $request_id
                    && isset($stored_request['status']) && $stored_request['status'] === 'succeeded'
                ) {
                    LlmTransaction::commit($this->db);
                    return $this->ok(array(
                        'replayed' => true,
                        'state' => $this->projector->project($model, $record_id, $conversation),
                    ));
                }
                $this->assertPhaseWritable($payload);
                if ((int)$env['revision'] !== $expected_revision) {
                    throw new LlmFormInterviewAbort('conflict', 'Stale revision', array(
                        'current_revision' => (int)$env['revision'],
                    ));
                }
                // Keyword pre-scan before any draft/message write so a blocked
                // detection (and its audit/email) survives this transaction.
                if (!$start && trim($message) !== '') {
                    $danger = new LlmDangerDetectionService($this->services, $model);
                    $detected = $danger->detectKeywords($message);
                    if (!empty($detected)) {
                        // Nothing was written yet: release the conversation lock
                        // first, then block, audit and alert, so the alert email
                        // (SMTP) never runs inside the lock window.
                        LlmTransaction::commit($this->db);
                        $check = $danger->handleDetection($detected, $message, $actor, (int)$conversation['id']);
                        $safety = (string)$check['message'];
                        return $this->err('danger_detected', $safety, array(
                            'blocked' => true,
                            'safety_message' => $safety,
                            'detected_keywords' => $detected,
                            'state' => $this->projector->project($model, $record_id, $conversation),
                        ));
                    }
                }
                if ($conversation['completed_at'] !== null) {
                    $this->state_service->setCompleted(
                        (int)$conversation['id'], $scope, $expected_revision, false
                    );
                }
                if (!empty($pending_patch) || !empty($native_patch)) {
                    $model->rebuildInterviewChildren($payload['draft']);
                    $this->assertRootEligible($model);
                    $schema = $this->schema_service->build($model);
                    LlmFormInterviewAbort::requireValidSchema($schema);
                    if (!empty($native_patch)) {
                        $normalized = $this->schema_service->normalizePatch(
                            $schema, $native_patch, self::ORIGIN_NATIVE
                        );
                        if (!empty($normalized['errors'])) {
                            throw new LlmFormInterviewAbort(
                                'validation', 'Native patch contains invalid fields',
                                array('field_errors' => $normalized['errors'])
                            );
                        }
                        $payload['draft'] = $normalized['values'] + $payload['draft'];
                    }
                    if (!empty($pending_patch)) {
                        $normalized = $this->schema_service->normalizePatch(
                            $schema, $pending_patch, self::ORIGIN_MANUAL
                        );
                        if (!empty($normalized['errors'])) {
                            throw new LlmFormInterviewAbort(
                                'validation', 'Patch contains invalid fields',
                                array('field_errors' => $normalized['errors'])
                            );
                        }
                        $payload['draft'] = $normalized['values'] + $payload['draft'];
                    }
                    $payload = $this->reconcile($model, $payload);
                }
                $payload['phase'] = 'draft';
                $payload['request'] = array('id' => $request_id, 'status' => 'pending');
                if (!$this->state_service->writeState(
                    (int)$conversation['id'], $scope, $expected_revision, $payload
                )) {
                    throw new LlmFormInterviewAbort('conflict', 'Concurrent modification');
                }
                if ($start) {
                    $this->llm_service->addMessage(
                        (int)$conversation['id'], 'system', $this->context_builder->startInstruction($record_id !== null)
                    );
                } else {
                    $this->llm_service->addMessage((int)$conversation['id'], 'user', $message);
                }
                $pre_merge_revision = $expected_revision + 1;
                LlmTransaction::commit($this->db);
            } catch (LlmFormInterviewAbort $abort) {
                LlmTransaction::rollback($this->db);
                if ($abort->errorType() === 'conflict' && $conversation !== null) {
                    return $this->err('conflict', $abort->getMessage(),
                        $abort->errorData() + array(
                            'state' => $this->conflictState($model, $actor, $record_id, $conversation),
                        ));
                }
                throw $abort;
            } catch (Throwable $e) {
                LlmTransaction::rollback($this->db);
                throw $e;
            }

            // Remote call: outside the conversation lock, with the PHP
            // session released so concurrent native edits are not blocked.
            // Every remote attempt consumes one rate-limit slot up front,
            // including attempts that fail or are retried.
            $api_messages = $this->context_builder->buildTurnMessages(
                $model, $payload, $conversation, $entry, $message, $start, $record_id
            );
            $response_service = new LlmResponseService($model, $this->services);
            $service = $this;
            $llm_service = $this->llm_service;
            $llm_model = $model->getLlmModel();
            $llm_temperature = $model->getLlmTemperature();
            $llm_max_tokens = $model->getLlmMaxTokens();
            $llm_reasoning = $model->getLlmReasoningEffort();
            $conversation_id = (int)$conversation['id'];
            $rate_limited = null;
            $callable = function ($messages) use (
                $llm_service, $llm_model, $llm_temperature, $llm_max_tokens,
                $llm_reasoning, $conversation_id, $actor, &$rate_limited
            ) {
                try {
                    $rate_data = $llm_service->checkRateLimit($actor);
                    $llm_service->updateRateLimit($actor, $rate_data, $conversation_id);
                } catch (LlmRateLimitException $rate_e) {
                    $rate_limited = $rate_e;
                    throw $rate_e;
                }
                return $llm_service->callLlmApi($messages, $llm_model, $llm_temperature,
                    $llm_max_tokens, array(
                        'conversation_id' => $conversation_id,
                        'sent_context' => $messages,
                        'is_validated' => false,
                        'reasoning_effort' => $llm_reasoning,
                    ));
            };
            $semantic_validator = function ($data) use ($service, $model, $payload) {
                return $service->validateInterviewResponse($model, $payload, $data);
            };

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $result = $response_service->callLlmWithSchemaValidation(
                $callable, $api_messages, $semantic_validator
            );

            if (!$result['valid']) {
                $this->markRequestFailed($model, $actor, $record_id, $conversation_id, $pre_merge_revision);
                if ($rate_limited !== null) {
                    return $this->err('rate_limited', $rate_limited->getMessage(), array(
                        'state' => $this->projector->project($model, $record_id, $conversation),
                    ));
                }
                return $this->err('llm_invalid', 'The assistant response could not be validated', array(
                    'attempts' => $result['attempts'],
                    'state' => $this->projector->project($model, $record_id, $conversation),
                ));
            }

            $data = $result['response']['data'];
            $interview = isset($data['interview']) ? $data['interview'] : array();
            $coach_text = $this->extractCoachText($data);
            $logged_message_id = isset($result['raw_response']['logged_message_id'])
                ? (int)$result['raw_response']['logged_message_id'] : 0;
            // No audit row means the auto-log failed: publishing the
            // coach text without the log would silently lose the audit.
            if ($logged_message_id <= 0) {
                $this->markRequestFailed(
                    $model, $actor, $record_id, $conversation_id, $pre_merge_revision
                );
                return $this->err('llm_invalid', 'The assistant response was not logged', array(
                    'state' => $this->projector->project($model, $record_id, $conversation),
                ));
            }

            // CAS merge of the validated LLM patch at the pre-call revision:
            // a manual edit that landed meanwhile wins and the reply stays
            // an unvalidated admin-only log row. The assistant message is
            // marked validated only inside the same transaction, after the
            // state write succeeds, so stale text never reaches the
            // participant projection.
            LlmTransaction::begin($this->db);
            try {
                list($conversation, $scope) = $this->loadScopedConversation(
                    $model, $actor, $record_id, $conversation_id, true
                );
                $env = $this->state_service->readState($conversation);
                if ($env === null || (int)$env['revision'] !== $pre_merge_revision) {
                    LlmTransaction::commit($this->db);
                    return $this->err('conflict', 'Draft changed while the assistant replied', array(
                        'state' => $this->conflictState($model, $actor, $record_id, $conversation),
                    ));
                }
                $payload = $env['payload'];
                $this->normalizePayload($payload);
                $model->rebuildInterviewChildren($payload['draft']);
                $this->assertRootEligible($model);
                $schema = $this->schema_service->build($model);
                LlmFormInterviewAbort::requireValidSchema($schema);
                $patch = isset($interview['collected_fields']) && is_array($interview['collected_fields'])
                    ? $interview['collected_fields'] : array();
                $normalized = $this->schema_service->normalizePatch($schema, $patch, self::ORIGIN_LLM);
                if (!empty($normalized['errors'])) {
                    $payload['request'] = array('id' => $request_id, 'status' => 'failed');
                    $this->state_service->writeState(
                        (int)$conversation['id'], $scope, $pre_merge_revision, $payload
                    );
                    LlmTransaction::commit($this->db);
                    return $this->err('llm_invalid', 'Assistant patch became invalid', array(
                        'field_errors' => $normalized['errors'],
                        'state' => $this->projector->project($model, $record_id, $conversation),
                    ));
                }
                $payload['draft'] = $normalized['values'] + $payload['draft'];
                $payload = $this->reconcile($model, $payload);
                $payload['active_field'] = isset($interview['active_field'])
                    ? $interview['active_field'] : null;
                $payload['suggestions'] = $this->extractSuggestions($data);
                $payload['request'] = array('id' => $request_id, 'status' => 'succeeded');
                if ($record_id !== null) {
                    // The coach has taken part in this edit session; the
                    // opening question is not asked again on a reload.
                    $payload['edit_started'] = true;
                }
                // Signature of the eligibility tree this answer was
                // produced against; only an exact match may replay the
                // last coach message on later turns.
                $payload['last_coach_signature'] = $this->context_builder->schemaSignature(
                    $this->schema_service->build($model)
                );
                if (!$this->state_service->writeState(
                    (int)$conversation['id'], $scope, $pre_merge_revision, $payload
                )) {
                    LlmTransaction::commit($this->db);
                    return $this->err('conflict', 'Draft changed while the assistant replied', array(
                        'state' => $this->conflictState($model, $actor, $record_id, $conversation),
                    ));
                }
                if ($logged_message_id > 0) {
                    $updated = $this->llm_service->updateMessage(
                        $logged_message_id,
                        array('content' => $coach_text, 'is_validated' => 1)
                    );
                    if ($updated === false) {
                        throw new LlmFormInterviewAbort(
                            'llm_invalid', 'The validated assistant message could not be stored'
                        );
                    }
                }
                LlmTransaction::commit($this->db);
            } catch (LlmFormInterviewAbort $abort) {
                LlmTransaction::rollback($this->db);
                throw $abort;
            } catch (Throwable $e) {
                LlmTransaction::rollback($this->db);
                throw $e;
            }
            // After the merge commits: LLM safety may block the conversation
            // without rolling back the validated coach reply.
            $danger = new LlmDangerDetectionService($this->services, $model);
            $danger->processSafetyDetection(
                $response_service,
                $data,
                $conversation_id,
                $actor,
                (int)$model->get_section_id()
            );
            $this->cache_manager->clearConversationMessageCache($conversation_id);
            $state = $this->projector->project($model, $record_id, $conversation);
            $safety = $response_service->assessSafety($data);
            $critical = in_array(
                isset($safety['danger_level']) ? $safety['danger_level'] : null,
                array('critical', 'emergency'),
                true
            );
            // Same UX as keyword pre-scan: danger_detected + CMS markdown
            // message + locked composer. State still carries the validated
            // coach turn that triggered the block.
            if (!empty($state['blocked']) || $critical) {
                $msg = (new LlmSafetyConfigService($this->services, $model))->getBlockedMessage();
                $out = array(
                    'blocked' => true,
                    'safety_message' => $msg,
                    'state' => $state,
                );
                if ($safety['is_safe'] === false || $safety['danger_level'] !== null) {
                    $out['safety'] = $safety;
                }
                if (!empty($safety['detected_concerns']) && is_array($safety['detected_concerns'])) {
                    $out['detected_keywords'] = $safety['detected_concerns'];
                }
                return $this->err('danger_detected', $msg, $out);
            }
            $out = array('state' => $state);
            if ($safety['is_safe'] === false || $safety['danger_level'] !== null) {
                $out['safety'] = $safety;
            }
            return $this->ok($out);
        } catch (LlmFormInterviewAbort $abort) {
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('turn', $e, isset($conversation) ? $conversation : null);
        }
    }

    /**
     * Phase 1 of approval, executed inside the caller-held outer
     * transaction: locks the scope conversation, verifies scope/revision/
     * record fingerprint and full requiredness, flushes the accepted draft
     * into the native field payload and marks the thread approving.
     *
     * @return array {ok, already_completed?, receipt?, post?, conversation?,
     *   scope?, revision?, state?}
     */
    public function prepareApproval($model, $record_id, $conversation_id, $expected_revision,
        $request_token, array $patch = array(), array $native_patch = array())
    {
        try {
            $this->requireInterviewModel($model);
            $actor = $this->requireActor();
            $record_id = $this->normalizeRecordId($record_id);
            $expected_revision = $this->normalizeRevision($expected_revision);
            $request_token = trim((string)$request_token);
            if ($request_token === '') {
                throw new LlmFormInterviewAbort('validation', 'A request token is required');
            }
            if (mb_strlen($request_token, 'UTF-8') > self::MAX_TOKEN_LENGTH) {
                throw new LlmFormInterviewAbort('validation', 'The request token is too long');
            }

            $conversation = null;
            $entry = null;
            if ($record_id !== null) {
                // Lock the target row before fingerprinting so a
                // concurrent quick-action/native update cannot slip
                // between the check and the write.
                $this->lockRecord($record_id);
                $entry = $this->authorizeRecord($model, $record_id);
                $model->setInterviewBaseEntry($entry);
            } else {
                $model->setInterviewBaseEntry(array());
            }
            // The strict scope lookup and the create-scope receipt replay
            // both verify every scope component server-side; the caller's
            // outer transaction holds the FOR UPDATE lock either way.
            list($conversation, $scope) = $this->loadApprovalConversation(
                $model, $actor, $record_id, $conversation_id, true
            );
            $env = $this->state_service->readState($conversation);
            if ($env === null) {
                throw new LlmFormInterviewAbort('invalid_state', 'Conversation state is missing');
            }
            $payload = $env['payload'];
            $this->normalizePayload($payload);
            if ($conversation['completed_at'] !== null) {
                if ($conversation['id_dataRows'] !== null && $record_id === null) {
                    $this->authorizeRecord($model, (int)$conversation['id_dataRows']);
                }
                $receipt = isset($payload['approval']['receipt']) ? $payload['approval']['receipt'] : null;
                $stored_token = isset($payload['approval']['request_token'])
                    ? $payload['approval']['request_token'] : null;
                if ($receipt !== null && $stored_token === $request_token) {
                    return $this->ok(array(
                        'already_completed' => true,
                        'receipt' => $receipt,
                        'state' => $this->projector->project($model, $record_id, $conversation),
                    ));
                }
                throw new LlmFormInterviewAbort(
                    'conflict', 'This interview was already approved'
                );
            }
            $this->assertPhaseWritable($payload);
            if ((int)$env['revision'] !== $expected_revision) {
                throw new LlmFormInterviewAbort('conflict', 'Stale revision', array(
                    'current_revision' => (int)$env['revision'],
                ));
            }
            if ($record_id !== null
                && isset($payload['base_record_fingerprint'])
                && $payload['base_record_fingerprint'] !== null
                && $this->fingerprint($entry) !== $payload['base_record_fingerprint']
            ) {
                throw new LlmFormInterviewAbort(
                    'conflict', 'The record was modified since this interview started'
                );
            }

            $model->rebuildInterviewChildren($payload['draft']);
            $this->assertRootEligible($model);
            $schema = $this->schema_service->build($model);
            LlmFormInterviewAbort::requireValidSchema($schema);
            if (!empty($native_patch)) {
                $normalized = $this->schema_service->normalizePatch(
                    $schema, $native_patch, self::ORIGIN_NATIVE
                );
                if (!empty($normalized['errors'])) {
                    throw new LlmFormInterviewAbort(
                        'validation', 'Native patch contains invalid fields',
                        array('field_errors' => $normalized['errors'])
                    );
                }
                $payload['draft'] = $normalized['values'] + $payload['draft'];
            }
            if (!empty($patch)) {
                $normalized = $this->schema_service->normalizePatch(
                    $schema, $patch, self::ORIGIN_MANUAL
                );
                if (!empty($normalized['errors'])) {
                    throw new LlmFormInterviewAbort(
                        'validation', 'Patch contains invalid fields',
                        array('field_errors' => $normalized['errors'])
                    );
                }
                $payload['draft'] = $normalized['values'] + $payload['draft'];
            }
            $payload = $this->reconcile($model, $payload);
            $schema = $this->schema_service->build($model);
            LlmFormInterviewAbort::requireValidSchema($schema);
            $errors = $this->schema_service->validateValues($schema, $payload['draft'], true);
            if (!empty($errors)) {
                throw new LlmFormInterviewAbort(
                    'validation',
                    'The form is incomplete or contains invalid values',
                    array('field_errors' => $errors)
                );
            }
            if (!$this->projector->hasSavableContent(
                $this->projector->projectDraft($model, $payload, $record_id), $record_id !== null
            )) {
                throw new LlmFormInterviewAbort('validation', 'There is nothing to save yet');
            }

            $post = array('__form_name' => $model->get_db_field('name'));
            if ($record_id !== null) {
                $post[SELECTED_RECORD_ID] = $record_id;
                // Preserve row ownership on edits from the authorized
                // record, never from client input.
                if (is_array($entry) && isset($entry['id_users'])) {
                    $post['id_users'] = (int)$entry['id_users'];
                }
            }
            foreach ($schema as $name => $def) {
                if (!is_array($def)) {
                    continue;
                }
                $value = array_key_exists($name, $payload['draft'])
                    ? $payload['draft'][$name]
                    : $def['initial_value'];
                $post[$name] = array('id' => $def['section_id'], 'value' => $value);
            }

            // The configured redirect is captured here; it is suppressed
            // while the inherited write runs and only surfaces in the
            // approval receipt after a successful write.
            $redirect = (string)$model->get_db_field('redirect_at_end', '');
            $payload['phase'] = 'approving';
            $payload['approval'] = array('request_token' => $request_token);
            if (!$this->state_service->writeState(
                (int)$conversation['id'], $scope, $expected_revision, $payload
            )) {
                throw new LlmFormInterviewAbort('conflict', 'Concurrent modification');
            }
            return $this->ok(array(
                'post' => $post,
                'conversation' => $conversation,
                'scope' => $scope,
                'prepared_revision' => $expected_revision + 1,
                'request_token' => $request_token,
                'redirect' => $redirect,
                'payload' => $payload,
            ));
        } catch (LlmFormInterviewAbort $abort) {
            // Conflicts carry the fresh projection so the UI can rebase.
            if ($abort->errorType() === 'conflict' && $conversation !== null) {
                return $this->err('conflict', $abort->getMessage(),
                    $abort->errorData() + array(
                        'state' => $this->conflictState($model, $actor, $record_id, $conversation),
                    ));
            }
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('approval prepare', $e, isset($conversation) ? $conversation : null);
        }
    }

    /**
     * Phase 2 of approval (still inside the caller's outer transaction,
     * after the inherited core write succeeded): binds the conversation and
     * its audit messages to the written row, completes the scope and stores
     * the receipt.
     *
     * @param array $prep The successful prepareApproval result
     * @param int $record_id_result The record id returned by the core write
     * @return array {ok, approval, state}
     */
    public function completeApproval($model, array $prep, $record_id_result)
    {
        try {
            $this->requireInterviewModel($model);
            $actor = $this->requireActor();
            $new_id = is_numeric($record_id_result) ? (int)$record_id_result : 0;
            if ($new_id <= 0) {
                throw new LlmFormInterviewAbort(
                    'approval_failed', 'The record was not written'
                );
            }
            $conversation = $prep['conversation'];
            $scope = $prep['scope'];
            $revision = (int)$prep['prepared_revision'];
            $record_id = $this->normalizeRecordId(
                isset($scope['id_dataRows']) ? $scope['id_dataRows'] : null
            );

            $payload = $prep['payload'];
            $payload['phase'] = 'approved';
            $payload['edit_started'] = false;
            // Rebase the fingerprint to the freshly written row so later
            // edits on this thread compare against the saved state, not
            // the pre-approval snapshot.
            $fresh = $model->get_form_entry_record(
                $new_id, $model->get_db_field('own_entries_only', 1)
            );
            if (is_array($fresh) && !empty($fresh)) {
                $payload['base_record_fingerprint'] = $this->fingerprint($fresh);
                $model->setInterviewBaseEntry($fresh);
            }
            $receipt = array(
                'record_id' => $new_id,
                'request_token' => $prep['request_token'],
                'approved_at' => date('Y-m-d H:i:s'),
            );
            if (isset($prep['redirect']) && $prep['redirect'] !== '') {
                // Resolve the configured page keyword the same way the
                // core redirect path does, so clients navigate a real URL.
                $resolved = $model->get_services()->get_router()->get_url($prep['redirect']);
                $receipt['redirect'] = is_string($resolved) && $resolved !== ''
                    ? $resolved : $prep['redirect'];
            }
            $payload['approval']['receipt'] = $receipt;
            if (!$this->state_service->writeState(
                (int)$conversation['id'], $scope, $revision, $payload
            )) {
                throw new LlmFormInterviewAbort('conflict', 'Concurrent modification');
            }
            $bound_scope = $scope;
            if ($scope['id_dataRows'] === null) {
                if (!$this->state_service->bindRecord(
                    (int)$conversation['id'], $scope, $new_id, $revision + 1
                )) {
                    throw new LlmFormInterviewAbort(
                        'approval_failed', 'The draft could not be bound to the record'
                    );
                }
                $bound_scope = array(
                    'user_id' => $scope['user_id'],
                    'section_id' => $scope['section_id'],
                    'source_type' => $scope['source_type'],
                    'id_dataRows' => $new_id,
                );
            }
            if (!$this->state_service->setCompleted(
                (int)$conversation['id'], $bound_scope, $revision + 1, true
            )) {
                throw new LlmFormInterviewAbort(
                    'approval_failed', 'The interview could not be completed'
                );
            }
            $bound_messages = $this->db->execute_update_db(
                'UPDATE llmMessages SET id_dataRows = :row'
                . ' WHERE id_llmConversations = :conv AND deleted = 0',
                array(':row' => $new_id, ':conv' => (int)$conversation['id'])
            );
            if ($bound_messages === false) {
                throw new LlmFormInterviewAbort(
                    'approval_failed', 'The audit messages could not be bound to the record'
                );
            }
            $conversation['id_dataRows'] = $new_id;
            $conversation['completed_at'] = date('Y-m-d H:i:s');
            return $this->ok(array(
                'approval' => $payload['approval']['receipt'],
                'state' => $this->projector->project($model, $new_id, $conversation),
            ));
        } catch (LlmFormInterviewAbort $abort) {
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('approval complete', $e, isset($conversation) ? $conversation : null);
        }
    }

    /**
     * Semantic validator for one parsed interview response: requires the
     * interview contract, rejects invented content.form/progress payloads,
     * validates collected_fields as an LLM patch and active_field against
     * the post-merge eligibility tree.
     *
     * @return array List of semantic errors (empty = valid)
     */
    public function validateInterviewResponse($model, array $payload, $data)
    {
        $errors = array();
        if (!is_array($data)) {
            return array('response is not an object');
        }
        if (isset($data['content']) && is_array($data['content'])
            && isset($data['content']['form']) && $data['content']['form'] !== null
        ) {
            $errors[] = 'content.form must be null for form interviews';
        }
        if (isset($data['progress']) && $data['progress'] !== null) {
            $errors[] = 'progress must be null for form interviews';
        }
        if (!isset($data['interview']) || !is_array($data['interview'])) {
            $errors[] = 'interview object is required';
            return $errors;
        }
        $interview = $data['interview'];
        $draft = isset($payload['draft']) && is_array($payload['draft'])
            ? $payload['draft'] : array();
        $model->rebuildInterviewChildren($draft);
        $schema = $this->schema_service->build($model);
        if (!empty($schema[LlmFormSchemaService::ERRORS_KEY])) {
            $errors[] = 'form schema is invalid: '
                . implode('; ', (array)$schema[LlmFormSchemaService::ERRORS_KEY]);
            return $errors;
        }
        $patch = isset($interview['collected_fields']) && is_array($interview['collected_fields'])
            ? $interview['collected_fields'] : array();
        $normalized = $this->schema_service->normalizePatch($schema, $patch, self::ORIGIN_LLM);
        foreach ($normalized['errors'] as $name => $error) {
            $errors[] = 'collected_fields.' . $name . ': ' . $error;
        }
        if (!empty($errors)) {
            return $errors;
        }
        $active = isset($interview['active_field']) ? $interview['active_field'] : null;
        if ($active !== null) {
            $merged = $normalized['values'] + $draft;
            $model->rebuildInterviewChildren($merged);
            $post_schema = $this->schema_service->build($model);
            if (!empty($post_schema[LlmFormSchemaService::ERRORS_KEY])) {
                $errors[] = 'form schema became invalid after the patch';
                return $errors;
            }
            if (!isset($post_schema[$active]) || !is_array($post_schema[$active])
                || empty($post_schema[$active]['interview'])
            ) {
                $errors[] = 'interview.active_field is not an eligible interview field';
            }
        }
        return $errors;
    }

    /* Internals **************************************************************/

    private function ok(array $extra = array())
    {
        return array('ok' => true) + $extra;
    }

    private function err($type, $message, array $extra = array())
    {
        return array('ok' => false, 'error' => array('type' => $type, 'message' => $message)) + $extra;
    }

    /**
     * Answer an unexpected failure: the cause goes to the server log (with the
     * conversation it concerned), the participant only sees a generic error.
     *
     * @param string $operation What was being done, e.g. "turn"
     * @param Throwable $e The failure
     * @param array|null $conversation Conversation row, when one was loaded
     * @param string $message What the participant sees
     * @return array The error envelope
     */
    private function failure($operation, $e, $conversation = null, $message = 'Interview request failed')
    {
        LlmErrorReporter::log(
            'Interview ' . $operation,
            $e,
            (is_array($conversation) && isset($conversation['id'])) ? (int)$conversation['id'] : null
        );
        return $this->err('error', $message);
    }

    private function requireInterviewModel($model)
    {
        $required = array('get_section_id', 'rebuildInterviewChildren', 'get_db_field',
            'get_form_entry_record', 'getInterviewChatRetention', 'getInterviewContextTemplate',
            'get_url_params', 'getLlmModel', 'getConfiguredModel',
            'set_entry_record', 'get_entry_record', 'get_entry_value',
            'setInterviewBaseEntry');
        foreach ($required as $method) {
            if (!is_object($model) || !method_exists($model, $method)) {
                throw new LlmFormInterviewAbort(
                    'misconfigured', 'Interview service requires an llmFormLogList model'
                );
            }
        }
    }

    private function requireActor()
    {
        $actor = isset($_SESSION['id_user']) ? intval($_SESSION['id_user']) : 0;
        if ($actor <= 0 || (defined('GUEST_USER_ID') && $actor === (int)GUEST_USER_ID)) {
            throw new LlmFormInterviewAbort('unauthenticated', 'Not authenticated');
        }
        return $actor;
    }

    private function normalizeRecordId($record_id)
    {
        if ($record_id === null || $record_id === '' ) {
            return null;
        }
        $record_id = (int)$record_id;
        return $record_id > 0 ? $record_id : null;
    }

    private function normalizeRevision($revision)
    {
        $revision = is_numeric($revision) ? (int)$revision : -1;
        if ($revision < 0) {
            throw new LlmFormInterviewAbort('validation', 'A valid expected revision is required');
        }
        return $revision;
    }

    /**
     * Authorize the record scope through the form model: bound records must
     * exist and pass own_entries_only; null scope needs no row.
     *
     * @return array|null The authorized entry row (null for create scope)
     */
    private function authorizeRecord($model, $record_id)
    {
        if ($record_id === null) {
            return null;
        }
        $entry = $model->get_form_entry_record(
            $record_id, $model->get_db_field('own_entries_only', 1)
        );
        if (!is_array($entry) || empty($entry)) {
            throw new LlmFormInterviewAbort('forbidden', 'Record not found or not accessible');
        }
        return $entry;
    }

    private function scope($actor, $section_id, $record_id, $context_key)
    {
        $scope = array(
            'user_id' => $actor,
            'section_id' => $section_id,
            'source_type' => LLM_CONV_SOURCE_FORM_INTERVIEW,
            'id_dataRows' => $record_id,
        );
        if ($record_id === null) {
            $scope['context_key'] = $context_key;
        }
        return $scope;
    }

    /**
     * The server-only context key of an unbound (create) scope without
     * declared URL parameters: a sha256 fingerprint over the sorted
     * immutable values (pinned and read-only hidden fields) of the eligible
     * schema. Client-posted context_key values are never trusted.
     */
    private function resolveContextKey(array $schema)
    {
        $values = array();
        foreach ($schema as $name => $def) {
            if (is_array($def)) {
                $values[$name] = $def['initial_value'];
            }
        }
        $immutable = array();
        foreach ($schema as $name => $def) {
            if (!is_array($def)) {
                continue;
            }
            if (!empty($def['scope_bound']) || (!empty($def['hidden']) && empty($def['llm_writable']))) {
                $immutable[$name] = array_key_exists($name, $values) ? $values[$name] : null;
            }
        }
        ksort($immutable);
        return 'auto:' . substr(hash('sha256', json_encode($immutable)), 0, 32);
    }

    /**
     * Canonical context_key of the declared URL parameters (core
     * `extra_params`): sorted `name=value&…`. Every declared name counts,
     * empty when absent, so drafts do not silently merge across URLs. The
     * column is VARCHAR(191): a longer list is hashed.
     *
     * @param array $params name => effective value
     * @return string
     */
    private function contextKeyFromUrlParams(array $params)
    {
        ksort($params);
        $pairs = array();
        foreach ($params as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $key = implode('&', $pairs);
        if (strlen($key) > 191) {
            $key = 'p:' . hash('sha256', $key);
        }
        return $this->state_service->normalizeContextKey($key);
    }

    /**
     * The immutable opening scope for an unbound (create) thread. Declared
     * URL params need no schema rebuild; the auto-fingerprint path still
     * resolves the base-entry child tree so hidden bindings are current.
     */
    private function openingContextKey($model)
    {
        $params = $model->get_url_params();
        if (!empty($params)) {
            return $this->contextKeyFromUrlParams($params);
        }
        $model->rebuildInterviewChildren(array());
        $schema = $this->schema_service->build($model);
        LlmFormInterviewAbort::requireValidSchema($schema);
        return $this->resolveContextKey($schema);
    }

    /**
     * Resolve the latest usable conversation for a record/create scope
     * without creating one. Prefers an unfinished non-abandoned thread,
     * otherwise the newest completed non-abandoned thread.
     *
     * @return array Conversation row
     */
    private function resolveLatestScopedConversation($model, $actor, $record_id)
    {
        $context_key = null;
        if ($record_id === null) {
            $context_key = $this->openingContextKey($model);
        }
        $scope = $this->scope($actor, (int)$model->get_section_id(), $record_id, $context_key);
        $unfinished = null;
        $completed = null;
        foreach ($this->state_service->listScopedConversations($scope) as $row) {
            if (!empty($row['blocked'])) {
                continue;
            }
            $env = $this->state_service->readState($row);
            $phase = $env !== null && isset($env['payload']['phase'])
                ? $env['payload']['phase'] : 'draft';
            if ($phase === 'abandoned') {
                continue;
            }
            if ($row['completed_at'] === null) {
                if ($unfinished === null) {
                    $unfinished = $row;
                }
            } else if ($completed === null) {
                $completed = $row;
            }
        }
        $conversation = $unfinished !== null ? $unfinished : $completed;
        if ($conversation === null) {
            throw new LlmFormInterviewAbort(
                'not_found', 'Conversation not found for this scope'
            );
        }
        $this->assertConversationUsable($conversation, $model);
        return $conversation;
    }

    /**
     * Load a conversation by id under the exact recomputed scope. For
     * unbound threads the opening context is re-resolved server-side -
     * a conversation opened under different bindings (e.g. another
     * module's hidden values) never matches. No bound-row fallback: only
     * the approval receipt replay resolves a completed create-scope
     * thread (see loadApprovalConversation).
     */
    private function loadScopedConversation($model, $actor, $record_id, $conversation_id, $for_update = false)
    {
        $context_key = null;
        if ($record_id === null) {
            $context_key = $this->openingContextKey($model);
        }
        $scope = $this->scope($actor, (int)$model->get_section_id(), $record_id, $context_key);
        $conversation = $this->state_service->getScopedConversation(
            (int)$conversation_id, $scope, $for_update
        );
        if ($conversation === null) {
            throw new LlmFormInterviewAbort(
                'not_found', 'Conversation not found for this scope'
            );
        }
        $this->assertConversationUsable($conversation, $model);
        return array($conversation, $scope);
    }

    /**
     * Approval-only load: strict scope first; for a create scope that
     * cannot resolve (because the completed thread was bound to its
     * approved row), fall back to the id only after re-verifying every
     * scope component server-side - actor, section, source lookup,
     * recomputed opening context, non-deleted, non-blocked, completed and
     * bound - plus live authorization on the bound row.
     */
    private function loadApprovalConversation($model, $actor, $record_id, $conversation_id, $for_update = false)
    {
        try {
            return $this->loadScopedConversation(
                $model, $actor, $record_id, $conversation_id, $for_update
            );
        } catch (LlmFormInterviewAbort $e) {
            if ($e->errorType() !== 'not_found' || $record_id !== null) {
                throw $e;
            }
        }
        $context_key = $this->openingContextKey($model);
        $sql = 'SELECT * FROM llmConversations WHERE id = :id AND deleted = 0';
        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->db->query_db_first($sql, array(':id' => (int)$conversation_id));
        $source_id = $this->state_service->resolveSourceId(LLM_CONV_SOURCE_FORM_INTERVIEW);
        if (!is_array($row)
            || $source_id === null
            || (int)$row['id_users'] !== $actor
            || (int)$row['id_sections'] !== (int)$model->get_section_id()
            || (int)$row['id_llm_conversation_sources'] !== $source_id
            || $row['id_dataRows'] === null
            || $row['completed_at'] === null
            || (string)$row['context_key'] !== (string)$context_key
        ) {
            throw new LlmFormInterviewAbort(
                'not_found', 'Conversation not found for this scope'
            );
        }
        $this->assertConversationUsable($row, $model);
        $this->authorizeRecord($model, (int)$row['id_dataRows']);
        $scope = $this->scope($actor, (int)$model->get_section_id(), null, $context_key);
        return array($row, $scope);
    }

    private function assertConversationUsable(array $conversation, $model = null)
    {
        if (!empty($conversation['blocked'])) {
            $msg = $model !== null
                ? (new LlmSafetyConfigService($this->services, $model))->getBlockedMessage()
                : 'This conversation is blocked';
            throw new LlmFormInterviewAbort('danger_detected', $msg, array(
                'blocked' => true,
                'safety_message' => $msg,
            ));
        }
    }

    /**
     * Guarantee the payload keys every write path dereferences exist with
     * the expected types, even for a hand-built or legacy envelope.
     */
    private function normalizePayload(array &$payload)
    {
        if (!isset($payload['draft']) || !is_array($payload['draft'])) {
            $payload['draft'] = array();
        }
        if (!isset($payload['inactive_values']) || !is_array($payload['inactive_values'])) {
            $payload['inactive_values'] = array();
        }
    }

    /**
     * Reject writes against cleared or mid-approval threads. Completed
     * threads stay writable - an edit reopens them through the caller's
     * setCompleted(false) path.
     */
    private function assertPhaseWritable(array $payload)
    {
        $phase = isset($payload['phase']) ? $payload['phase'] : 'draft';
        if ($phase === 'abandoned') {
            throw new LlmFormInterviewAbort('conflict', 'This interview was cleared');
        }
        if ($phase === 'approving') {
            throw new LlmFormInterviewAbort('conflict', 'An approval is in progress');
        }
    }

    /**
     * After a draft-driven child rebuild the recomputed root condition
     * must still hold - a form that conditioned itself out is no longer
     * writable through this service.
     */
    private function assertRootEligible($model)
    {
        $condition = $model->get_condition_result();
        if (is_array($condition) && isset($condition['result']) && !$condition['result']) {
            throw new LlmFormInterviewAbort('forbidden', 'This form is not available');
        }
    }

    /**
     * Serialize concurrent opens of one participant with a named lock instead
     * of a row lock on the core `users` table, which would block core
     * login/profile writes for the whole open. It must be the first statement
     * of the open transaction (no read view exists yet), so every read that
     * follows sees what the previous holder committed. The caller releases it
     * with unlockActor() after commit/rollback; it also ends with the DB
     * connection.
     */
    private function lockActor($actor)
    {
        $row = $this->db->query_db_first(
            'SELECT GET_LOCK(:name, :timeout) AS acquired',
            array(':name' => $this->actorLockName($actor), ':timeout' => self::ACTOR_LOCK_TIMEOUT_SECONDS)
        );
        if (!is_array($row) || (int)$row['acquired'] !== 1) {
            throw new LlmFormInterviewAbort('conflict', 'Another interview request is in progress');
        }
    }

    private function unlockActor($actor)
    {
        try {
            $this->db->query_db_first(
                'SELECT RELEASE_LOCK(:name) AS released',
                array(':name' => $this->actorLockName($actor))
            );
        } catch (Exception $e) {
            LlmErrorReporter::log('Interview actor lock release', $e);
        }
    }

    private function actorLockName($actor)
    {
        return 'llm_interview_open_' . (int)$actor;
    }

    /**
     * Lock the target record row inside the caller's transaction so a
     * concurrent quick-action update cannot race the fingerprint check.
     */
    private function lockRecord($record_id)
    {
        $row = $this->db->query_db_first(
            'SELECT id FROM dataRows WHERE id = :id FOR UPDATE',
            array(':id' => (int)$record_id)
        );
        if (!is_array($row)) {
            throw new LlmFormInterviewAbort('forbidden', 'Record not found or not accessible');
        }
    }

    /**
     * Mark the stored turn request failed (best effort) so a retry with
     * the same request id is not silently reported as a replayed success.
     */
    private function markRequestFailed($model, $actor, $record_id, $conversation_id, $expected_revision)
    {
        try {
            LlmTransaction::begin($this->db);
            list($conversation, $scope) = $this->loadScopedConversation(
                $model, $actor, $record_id, $conversation_id, true
            );
            $env = $this->state_service->readState($conversation);
            if ($env !== null
                && (int)$env['revision'] === $expected_revision
            ) {
                $payload = $env['payload'];
                if (isset($payload['request']) && is_array($payload['request'])) {
                    $payload['request']['status'] = 'failed';
                    $this->state_service->writeState(
                        (int)$conversation['id'], $scope, $expected_revision, $payload
                    );
                }
            }
            LlmTransaction::commit($this->db);
        } catch (Throwable $e) {
            // Best effort: a failed mark must not turn the original
            // failure into a second one.
            LlmTransaction::rollback($this->db);
        }
    }

    private function newDraftPayload($model, array $schema, $record_id, $entry, $open_token, $context_key = null)
    {
        $draft = array();
        foreach ($schema as $name => $def) {
            if (is_array($def) && isset($def['name']) && $def['initial_value'] !== null) {
                $draft[$name] = $def['initial_value'];
            }
        }
        return array(
            'phase' => 'draft',
            'open_token' => $open_token !== '' ? $open_token : null,
            'request' => null,
            'last_coach_signature' => null,
            'draft' => $draft,
            'inactive_values' => array(),
            'schema_signature' => $this->context_builder->schemaSignature($schema),
            'base_record_fingerprint' => $this->fingerprint($entry),
            // Only the fingerprint above is needed to detect a changed record; the
            // opened entry itself (the participant's stored values) is deliberately
            // not copied into the conversation.
            'opening_context' => array(
                'context_key' => $context_key,
                'opened_at' => date('Y-m-d H:i:s'),
            ),
            'active_field' => null,
            'approval' => null,
        );
    }

    /**
     * Rebuild eligibility from base_entry + draft: drop values of fields
     * that are no longer eligible into private inactive_values and restore
     * values when fields re-enter.
     */
    private function reconcile($model, array $payload)
    {
        $draft = isset($payload['draft']) && is_array($payload['draft']) ? $payload['draft'] : array();
        $inactive = isset($payload['inactive_values']) && is_array($payload['inactive_values'])
            ? $payload['inactive_values'] : array();
        $model->rebuildInterviewChildren($draft);
        $schema = $this->schema_service->build($model);
        LlmFormInterviewAbort::requireValidSchema($schema);
        $new_draft = array();
        foreach ($schema as $name => $def) {
            if (!is_array($def) || !isset($def['name'])) {
                continue;
            }
            if (array_key_exists($name, $draft)) {
                $new_draft[$name] = $draft[$name];
                unset($inactive[$name]);
            } else if (array_key_exists($name, $inactive)) {
                $new_draft[$name] = $inactive[$name];
                unset($inactive[$name]);
            } else if ($def['initial_value'] !== null) {
                $new_draft[$name] = $def['initial_value'];
            }
        }
        foreach ($draft as $name => $value) {
            if (!array_key_exists($name, $new_draft)) {
                $inactive[$name] = $value;
            }
        }
        $payload['draft'] = $new_draft;
        $payload['inactive_values'] = $inactive;
        $payload['schema_signature'] = $this->context_builder->schemaSignature($schema);
        return $payload;
    }

    private function fingerprint($entry)
    {
        if (!is_array($entry)) {
            return null;
        }
        $copy = $entry;
        unset(
            $copy[USER_INPUT_HISTORY_LOG_COL], $copy['triggerType'],
            $copy['id_actionTriggerTypes'], $copy['user_name'], $copy['user_code']
        );
        ksort($copy);
        return hash('sha256', json_encode($copy));
    }

    /**
     * Quick-reply buttons the coach offered with its last answer: at most
     * six distinct, single-line labels. They are only shortcuts for what
     * the participant could type; choosing one sends it as a normal message.
     *
     * @return array List of label strings
     */
    private function extractSuggestions($data)
    {
        $out = array();
        if (!isset($data['content']['suggestions']) || !is_array($data['content']['suggestions'])) {
            return $out;
        }
        foreach ($data['content']['suggestions'] as $item) {
            $text = is_array($item) && isset($item['text']) && is_string($item['text'])
                ? $item['text'] : (is_string($item) ? $item : '');
            $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
            if ($text === '' || mb_strlen($text, 'UTF-8') > 120) {
                continue;
            }
            $out[$text] = $text;
            if (count($out) >= 6) {
                break;
            }
        }
        return array_values($out);
    }

    private function extractCoachText($data)
    {
        $texts = array();
        if (isset($data['content']) && is_array($data['content'])) {
            if (isset($data['content']['subject']) && is_string($data['content']['subject'])
                && $data['content']['subject'] !== ''
            ) {
                $texts[] = $data['content']['subject'];
            }
            if (isset($data['content']['text_blocks']) && is_array($data['content']['text_blocks'])) {
                foreach ($data['content']['text_blocks'] as $block) {
                    if (is_array($block) && isset($block['content']) && is_string($block['content'])
                        && $block['content'] !== ''
                    ) {
                        $texts[] = $block['content'];
                    }
                }
            }
        }
        $text = trim(implode("\n\n", $texts));
        return $text !== '' ? $text : '(empty response)';
    }

    /**
     * Prompt Lab preview turn: runs one interview turn against a
     * read-only preview model and a playground conversation - the same
     * context assembly, schema validation and draft merge as turn(),
     * but without interview state, participant rows, approval, memory
     * or history writes.
     *
     * @param object $model LlmFormInterviewPreviewModel instance
     * @param array $draft Test draft values (field name => value)
     * @param array $history Message history; the trailing user message is
     *  the turn input, earlier entries are replayed as context. Empty
     *  history runs the frozen bootstrap start.
     * @param array $options conversation_id, model, temperature,
     *  max_tokens, reasoning_effort overrides
     * @return array {ok, state?|error{type,message}, content?, draft?,
     *  fields?, progress?, field_errors?, effective_context?,
     *  request_payload?, logged_message_id?, conversation_id?, attempts?}
     */
    public function previewTurn($model, array $draft, array $history = array(), array $options = array())
    {
        try {
            $this->requireInterviewModel($model);
            $this->requireActor();
            $payload = array(
                'phase' => 'draft',
                'draft' => $draft,
                'inactive_values' => array(),
                'last_coach_signature' => '',
                'active_field' => null,
                'approval' => null,
            );
            // Eligibility/condition reconciliation on the test draft: the
            // preview sees exactly the tree a participant would.
            $payload = $this->reconcile($model, $payload);

            $message = isset($options['message']) ? trim((string)$options['message']) : '';
            $prior = $history;
            if ($message === '') {
                $last = end($prior);
                if (is_array($last) && isset($last['role']) && $last['role'] === 'user'
                    && trim((string)$last['content']) !== ''
                ) {
                    $message = trim((string)$last['content']);
                    array_pop($prior);
                }
            }
            $start = $message === '';

            $conversation_id = isset($options['conversation_id'])
                ? (int)$options['conversation_id'] : 0;
            $conversation = array('id' => $conversation_id);
            $entry = is_array($model->get_entry_record()) ? $model->get_entry_record() : array();
            $api_messages = $this->context_builder->buildTurnMessages(
                $model, $payload, $conversation, $entry, $message, $start
            );
            if (!empty($prior)) {
                array_splice($api_messages, count($api_messages) - 1, 0, $prior);
            }

            $llm_service = $this->llm_service;
            $llm_model = isset($options['model']) && $options['model'] !== ''
                ? (string)$options['model'] : $model->getLlmModel();
            $llm_temperature = isset($options['temperature']) && $options['temperature'] !== null
                ? (float)$options['temperature'] : $model->getLlmTemperature();
            $llm_max_tokens = isset($options['max_tokens']) && $options['max_tokens'] !== null
                ? (int)$options['max_tokens'] : $model->getLlmMaxTokens();
            $llm_reasoning = isset($options['reasoning_effort'])
                ? (string)$options['reasoning_effort'] : $model->getLlmReasoningEffort();
            $callable = function ($messages) use (
                $llm_service, $llm_model, $llm_temperature,
                $llm_max_tokens, $llm_reasoning, $conversation_id
            ) {
                return $llm_service->callLlmApi($messages, $llm_model, $llm_temperature,
                    $llm_max_tokens, array(
                        'conversation_id' => $conversation_id,
                        'sent_context' => $messages,
                        'is_validated' => false,
                        'reasoning_effort' => $llm_reasoning,
                    ));
            };
            $service = $this;
            $semantic_validator = function ($data) use ($service, $model, $payload) {
                return $service->validateInterviewResponse($model, $payload, $data);
            };

            $response_service = new LlmResponseService($model, $this->services);
            $result = $response_service->callLlmWithSchemaValidation(
                $callable, $api_messages, $semantic_validator
            );

            if (!$result['valid']) {
                return $this->err('llm_invalid', 'The assistant response could not be validated', array(
                    'attempts' => $result['attempts'],
                    'effective_context' => $api_messages,
                    'request_payload' => isset($result['request_payload'])
                        ? $result['request_payload'] : null,
                ));
            }

            $data = $result['response']['data'];
            $interview = isset($data['interview']) ? $data['interview'] : array();
            $coach_text = $this->extractCoachText($data);
            $logged_message_id = isset($result['raw_response']['logged_message_id'])
                ? (int)$result['raw_response']['logged_message_id'] : 0;
            if ($logged_message_id <= 0) {
                return $this->err('llm_invalid', 'The assistant response was not logged', array(
                    'effective_context' => $api_messages,
                ));
            }

            $model->rebuildInterviewChildren($payload['draft']);
            $schema = $this->schema_service->build($model);
            LlmFormInterviewAbort::requireValidSchema($schema);
            $patch = isset($interview['collected_fields']) && is_array($interview['collected_fields'])
                ? $interview['collected_fields'] : array();
            $normalized = $this->schema_service->normalizePatch($schema, $patch, self::ORIGIN_LLM);
            if (!empty($normalized['errors'])) {
                return $this->err('llm_invalid', 'Assistant patch became invalid', array(
                    'field_errors' => $normalized['errors'],
                    'effective_context' => $api_messages,
                ));
            }
            $payload['draft'] = $normalized['values'] + $payload['draft'];
            $payload['active_field'] = isset($interview['active_field'])
                ? $interview['active_field'] : null;
            $payload = $this->reconcile($model, $payload);

            $updated = $this->llm_service->updateMessage(
                $logged_message_id,
                array('content' => $coach_text, 'is_validated' => 1)
            );
            if ($updated === false) {
                throw new LlmFormInterviewAbort(
                    'llm_invalid', 'The validated assistant message could not be stored'
                );
            }
            if ($conversation_id > 0) {
                $this->cache_manager->clearConversationMessageCache($conversation_id);
            }

            $projection = $this->projector->projectDraft($model, $payload);
            return $this->ok(array(
                'content' => $coach_text,
                'data' => $data,
                'draft' => $projection['draft'],
                'fields' => $projection['fields'],
                'progress' => $projection['progress'],
                'effective_context' => $api_messages,
                'request_payload' => isset($result['request_payload'])
                    ? $result['request_payload'] : null,
                'logged_message_id' => $logged_message_id,
                'conversation_id' => $conversation_id,
                'attempts' => $result['attempts'],
                'tokens_used' => isset($result['raw_response']['usage']['total_tokens'])
                    ? $result['raw_response']['usage']['total_tokens'] : null,
                'duration_ms' => isset($result['raw_response']['processing_time'])
                    ? (int)round(((float)$result['raw_response']['processing_time']) * 1000) : null,
            ));
        } catch (LlmFormInterviewAbort $abort) {
            return $this->err($abort->errorType(), $abort->getMessage(), $abort->errorData());
        } catch (Throwable $e) {
            return $this->failure('preview turn', $e, isset($conversation) ? $conversation : null, 'Interview preview request failed');
        }
    }
}
?>
