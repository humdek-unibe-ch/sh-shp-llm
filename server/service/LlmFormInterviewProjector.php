<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmFormInterviewAbort.php';
require_once __DIR__ . '/LlmFormSchemaService.php';

/**
 * Participant-facing view of an interview thread (v1.6.0): the visible
 * draft, field definitions, progress, edit baseline and transcript the
 * frontend renders. Read-only: it never writes conversation state.
 *
 * Used by LlmFormInterviewService (every operation answers with a
 * projection) and by LlmFormInterviewContextBuilder (saved values and
 * change detection for edit sessions).
 */
class LlmFormInterviewProjector
{
    private $db;
    private $llm_service;
    private $state_service;
    private $schema_service;
    private $native_renderer = null;

    /**
     * @param object $db SelfHelp database
     * @param object $llm_service LlmService-compatible collaborator (conversation messages)
     * @param object $state_service LlmConversationStateService
     * @param object $schema_service LlmFormSchemaService
     */
    public function __construct($db, $llm_service, $state_service, $schema_service)
    {
        $this->db = $db;
        $this->llm_service = $llm_service;
        $this->state_service = $state_service;
        $this->schema_service = $schema_service;
    }

    /**
     * Server-rendered native control callback; receives a field name and
     * returns an HTML fragment (or empty string). The projection exposes
     * the fragments so the frontend can re-render native fallbacks
     * without rebuilding core components.
     */
    public function setNativeRenderer($renderer)
    {
        $this->native_renderer = is_callable($renderer) ? $renderer : null;
    }

    /**
     * Participant-safe projection: no raw_response, sent_context,
     * request_payload or private inactive values.
     */
    public function project($model, $record_id, $conversation)
    {
        $fresh = $this->db->query_db_first(
            'SELECT * FROM llmConversations WHERE id = :id',
            array(':id' => (int)$conversation['id'])
        );
        if (is_array($fresh)) {
            $conversation = $fresh;
        }
        $env = $this->state_service->readState($conversation);
        $payload = $env !== null ? $env['payload'] : array();
        $projection = $this->projectDraft($model, $payload, $record_id);

        $completed_at = isset($conversation['completed_at']) ? $conversation['completed_at'] : null;

        $messages = array();
        $has_assistant = false;
        foreach ($this->llm_service->getConversationMessages((int)$conversation['id'], 50) as $msg) {
            if (!isset($msg['role']) || !in_array($msg['role'], array('user', 'assistant'), true)) {
                continue;
            }
            if ($msg['role'] === 'assistant') {
                $has_assistant = true;
            }
            $messages[] = array(
                'id' => isset($msg['id']) ? (int)$msg['id'] : null,
                'role' => $msg['role'],
                'content' => $msg['content'],
                'timestamp' => isset($msg['timestamp']) ? $msg['timestamp'] : null,
            );
        }

        $completed = $completed_at !== null;
        $phase = isset($payload['phase']) ? $payload['phase'] : 'draft';
        $edit = $record_id !== null;
        // A saved record reopened for editing: the coach opens the session
        // with "what do you want to change?" even though the thread
        // already holds the conversation that created the record.
        $edit_pending = $edit && $completed && empty($payload['edit_started']);
        // Saving needs something to save: an edit at least one change, a
        // new record at least one answer (besides a complete draft).
        $has_changes = $this->hasSavableContent($projection, $edit);
        return array(
            'conversation_id' => (int)$conversation['id'],
            'record_id' => $record_id,
            'mode' => $edit ? 'edit' : 'new',
            'revision' => $env !== null ? (int)$env['revision'] : 0,
            'phase' => $phase,
            'draft' => $projection['draft'],
            'changed' => $projection['changed'],
            'baseline' => (object)$projection['baseline'],
            'fields' => $projection['fields'],
            'native_html' => $projection['native_html'],
            'progress' => $projection['progress'],
            'asking' => $projection['asking'],
            'messages' => $messages,
            'completed_at' => $completed_at,
            'can_approve' => !$completed && $phase === 'draft' && $has_changes
                && $projection['progress']['ready_for_approval']
                && empty($conversation['blocked']),
            'needs_start' => !$has_assistant || $edit_pending,
            'suggestions' => !$completed && $phase === 'draft'
                && isset($payload['suggestions']) && is_array($payload['suggestions'])
                && empty($conversation['blocked'])
                ? array_values($payload['suggestions']) : array(),
            'blocked' => !empty($conversation['blocked']),
        );
    }

    /**
     * Whether a projected draft holds something worth saving: an edit needs
     * at least one change against the saved entry, a new record at least
     * one answered question (a form without questions is always savable).
     *
     * @param array $projection projectDraft() result
     * @param bool $edit Editing a saved entry
     * @return bool
     */
    public function hasSavableContent(array $projection, $edit)
    {
        if ($edit) {
            return !empty($projection['changed']);
        }
        return (int)$projection['progress']['total'] === 0
            || (int)$projection['progress']['done'] > 0;
    }

    /**
     * Visible draft + field defs + progress for one payload, without the
     * conversation-level data. Shared by project() and previewTurn().
     */
    public function projectDraft($model, array $payload, $record_id = null)
    {
        $draft = isset($payload['draft']) && is_array($payload['draft']) ? $payload['draft'] : array();
        $saved = $record_id !== null ? $this->savedValues($model, $draft) : array();
        $model->rebuildInterviewChildren($draft);
        $schema = $this->schema_service->build($model);
        LlmFormInterviewAbort::requireValidSchema($schema);

        // Visible fields only: interview questions plus native-only and
        // manually editable fallbacks, each flagged so the frontend can
        // render them with the correct write permissions. Hidden fields
        // are never projected.
        $visible_draft = array();
        $visible_defs = array();
        foreach ($schema as $name => $def) {
            if (!is_array($def) || !isset($def['name']) || !empty($def['hidden'])) {
                continue;
            }
            $visible_defs[$name] = array(
                'name' => $name,
                'label' => $def['label'],
                'hint' => isset($def['placeholder']) ? $def['placeholder'] : '',
                'style' => $def['style'],
                'input_type' => $def['input_type'],
                'required' => $def['required'],
                'options' => $def['options'],
                'constraints' => $def['constraints'],
                'interview' => !empty($def['interview']),
                'native_only' => !empty($def['native_only']),
                'manual_writable' => !empty($def['manual_writable']),
                'llm_writable' => !empty($def['llm_writable']),
            );
            if (array_key_exists($name, $draft)) {
                $visible_draft[$name] = $draft[$name];
            } else if (isset($def['initial_value']) && $def['initial_value'] !== null) {
                // Masking projection: a field that entered the schema
                // after the draft was stored shows its seed default.
                $visible_draft[$name] = $def['initial_value'];
            }
        }
        // Edit sessions: which visible answers differ from the saved record,
        // and what the record holds today (shown as "before" next to them).
        $changed = array();
        $baseline = array();
        if ($record_id !== null) {
            foreach ($visible_defs as $name => $def) {
                $current = array_key_exists($name, $visible_draft) ? $visible_draft[$name] : null;
                $before = array_key_exists($name, $saved) ? $saved[$name] : null;
                if (!$this->sameValue($current, $before)) {
                    $changed[] = $name;
                    $baseline[$name] = $before;
                }
            }
        }
        $native_html = array();
        if ($this->native_renderer !== null) {
            foreach ($visible_defs as $name => $def) {
                if (empty($def['native_only']) && empty($def['manual_writable'])) {
                    continue;
                }
                $html = call_user_func($this->native_renderer, $name);
                if (is_string($html) && $html !== '') {
                    $native_html[$name] = $html;
                }
            }
        }
        $progress = $this->schema_service->progress(
            $schema, $draft, isset($payload['active_field']) ? $payload['active_field'] : null
        );
        // The question the coach is asking right now. Unlike
        // progress.active it may also be an answered field: an edit
        // session talks about fields that already have a value.
        $asking = isset($payload['active_field']) && is_string($payload['active_field'])
            && isset($visible_defs[$payload['active_field']])
            ? $payload['active_field'] : null;
        return array(
            'draft' => $visible_draft,
            'changed' => $changed,
            'baseline' => $baseline,
            'fields' => $visible_defs,
            'native_html' => $native_html,
            'progress' => $progress,
            'asking' => $asking,
        );
    }

    /**
     * The saved record's values (normalized like draft values) for the
     * eligible fields, computed on a child tree built without any draft.
     * The draft tree is restored afterwards.
     *
     * @param array $draft Draft to restore on the model
     * @return array field name => saved value
     */
    public function savedValues($model, array $draft)
    {
        $model->rebuildInterviewChildren(array());
        $saved_schema = $this->schema_service->build($model);
        $model->rebuildInterviewChildren($draft);
        $saved = array();
        if (!empty($saved_schema[LlmFormSchemaService::ERRORS_KEY])) {
            return $saved;
        }
        foreach ($saved_schema as $name => $def) {
            if (is_array($def) && isset($def['name']) && array_key_exists('initial_value', $def)) {
                $saved[$name] = $def['initial_value'];
            }
        }
        return $saved;
    }

    /**
     * Value equality for change detection: null equals the empty string,
     * scalars compare as strings, lists compare as unordered sets.
     */
    public function sameValue($a, $b)
    {
        $norm = function ($v) {
            if ($v === null || $v === '') {
                return '';
            }
            if (is_array($v)) {
                $items = array_map('strval', array_values($v));
                sort($items);
                return $items;
            }
            return is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        };
        return $norm($a) === $norm($b);
    }
}
?>
