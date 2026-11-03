<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmRequestTraceService.php';

/**
 * Checks stored request traces (server/scripts/llm_verify_traces.php).
 *
 * For every trace in scope: rebuild it from the database and compare the
 * hash; while full payloads are still stored (dual write), also compare the
 * rebuilt messages with the stored request_payload. It also lists traces that
 * failed their write-time self-check and assistant replies that have no trace
 * at all (API error rows and canned replies excluded). The trace setting may be switched to
 * trace-only once a run over production finds nothing.
 */
class LlmTraceVerifier
{
    const BATCH = 200;

    /** @var object BaseDb / PageDb */
    private $db;
    /** @var callable row => sent message|null */
    private $format_row;
    /** @var callable|null (provider, params, messages) => wire payload */
    private $to_wire;
    /** @var callable|null attachment => image data URL|null */
    private $load_image;

    /**
     * @param object $db BaseDb / PageDb
     * @param callable $format_row LlmApiFormatterService::formatStoredMessage() wrapper
     * @param callable|null $to_wire LlmAdminService::toWirePayload(); stored payloads are in the
     *   provider's wire format, so the rebuild is compared in that form
     * @param callable|null $load_image LlmApiFormatterService::imageDataUrl()
     */
    public function __construct($db, callable $format_row, $to_wire = null, $load_image = null)
    {
        $this->db = $db;
        $this->format_row = $format_row;
        $this->to_wire = $to_wire;
        $this->load_image = $load_image;
    }

    /**
     * @param array $options since (Y-m-d, by message time), conversation (id)
     * @return array{checked:int, verified:int, hash_mismatch:int[], blob_mismatch:int[], write_unverified:int[], untraced:int[]}
     */
    public function run(array $options)
    {
        $where = array('1 = 1');
        $params = array();
        if (!empty($options['since'])) {
            $where[] = 'm.timestamp >= :since';
            $params[':since'] = $options['since'];
        }
        if (!empty($options['conversation'])) {
            $where[] = 'm.id_llmConversations = :conversation';
            $params[':conversation'] = (int)$options['conversation'];
        }
        $scope = implode(' AND ', $where);

        $report = array('checked' => 0, 'verified' => 0, 'hash_mismatch' => array(), 'blob_mismatch' => array(),
            'write_unverified' => array(), 'untraced' => array());

        // Stored payloads are read per batch: in dual write every message has one
        $traces = $this->db->query_db(
            "SELECT t.id_llmMessages, t.verified
             FROM llmRequestTraces t INNER JOIN llmMessages m ON m.id = t.id_llmMessages
             WHERE $scope ORDER BY t.id_llmMessages",
            $params
        ) ?: array();
        $service = new LlmRequestTraceService($this->db);
        foreach (array_chunk($traces, self::BATCH) as $batch) {
            $ids = array_map('intval', array_column($batch, 'id_llmMessages'));
            $rebuilt = $service->rebuildMany($ids, $this->format_row, $this->load_image);
            $payloads = array();
            foreach ($this->db->query_db('SELECT id, request_payload FROM llmMessages WHERE id IN (' . implode(',', $ids) . ')') ?: array() as $row) {
                $payloads[(int)$row['id']] = $row['request_payload'];
            }
            foreach ($batch as $trace) {
                $id = (int)$trace['id_llmMessages'];
                $report['checked']++;
                $result = $rebuilt[$id] ?? null;
                if ($result !== null && $result['verified']) {
                    $report['verified']++;
                } else {
                    $report['hash_mismatch'][] = $id;
                }
                if ((int)$trace['verified'] !== 1) {
                    $report['write_unverified'][] = $id;
                }
                $payload = $payloads[$id] ?? null;
                if ($payload !== null && !$this->sameAsBlob($result, $payload)) {
                    $report['blob_mismatch'][] = $id;
                }
            }
        }

        // Only replies that came from a model call can have a trace: canned
        // assistant rows (auto-start greeting, fallback texts) have no
        // raw_response (addMessage() stores a missing one as JSON 'null').
        // Replies older than the first trace predate request traces.
        foreach ($this->db->query_db(
            "SELECT m.id FROM llmMessages m LEFT JOIN llmRequestTraces t ON t.id_llmMessages = m.id
             WHERE $scope AND m.role = 'assistant' AND t.id IS NULL AND m.content NOT LIKE '[API ERROR]%'
               AND m.raw_response IS NOT NULL AND m.raw_response <> 'null'
               AND m.id > (SELECT COALESCE(MIN(id_llmMessages), 0) FROM llmRequestTraces)
             ORDER BY m.id",
            $params
        ) ?: array() as $row) {
            $report['untraced'][] = (int)$row['id'];
        }
        return $report;
    }

    /** @return bool true when the report has no finding */
    public static function isClean(array $report)
    {
        return empty($report['hash_mismatch']) && empty($report['blob_mismatch'])
            && empty($report['write_unverified']) && empty($report['untraced']);
    }

    /**
     * The rebuild, in the provider's wire format, against the stored payload:
     * the top-level system prompt and the text messages must match one to
     * one. Multimodal parts are skipped (stored payloads have no image bytes).
     *
     * @param array|null $result rebuildMany() entry
     * @param string $request_payload Stored JSON
     * @return bool
     */
    private function sameAsBlob($result, $request_payload)
    {
        $blob = json_decode((string)$request_payload, true);
        if (!is_array($blob) || !isset($blob['messages'])) {
            return true;
        }
        if ($result === null || $result['messages'] === null) {
            return false;
        }
        $wire = is_callable($this->to_wire)
            ? call_user_func($this->to_wire, $result['provider'], $result['params'], $result['messages'])
            : $result['params'] + array('messages' => $result['messages']);
        if (($wire['system'] ?? null) !== ($blob['system'] ?? null)) {
            return false;
        }
        $messages = $wire['messages'] ?? array();
        if (count($messages) !== count($blob['messages'])) {
            return false;
        }
        foreach ($messages as $index => $message) {
            $stored = $blob['messages'][$index];
            if (($message['role'] ?? null) !== ($stored['role'] ?? null)) {
                return false;
            }
            if (is_string($message['content'] ?? null) && is_string($stored['content'] ?? null)
                && $message['content'] !== $stored['content']) {
                return false;
            }
        }
        return true;
    }
}
?>
