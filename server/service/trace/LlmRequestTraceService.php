<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmTraceBuilder.php';
require_once __DIR__ . '/LlmTraceCanonicalizer.php';
require_once __DIR__ . '/LlmTraceHints.php';
require_once __DIR__ . '/LlmPromptVersionLinker.php';
require_once __DIR__ . '/../LlmErrorReporter.php';
require_once __DIR__ . '/../LlmTransaction.php';

/**
 * Stores request traces and rebuilds requests from them.
 *
 * record() writes the trace of one logged assistant message: deduplicated
 * chunks, the recipe (chunk ids and history row ids), the provider, the
 * adapted params and request_hash. It then rebuilds the request from the
 * database and compares the hash (self-check). It never throws: any problem
 * comes back as `fallback`, and the caller stores the full blobs instead.
 * Its writes run in their own LlmTransaction, so an exception half way leaves
 * no partial trace; a self-check mismatch keeps the trace (verified = 0) for
 * the verifier.
 *
 * @see doc/request-traces.md
 */
class LlmRequestTraceService
{
    /** @var object BaseDb / PageDb */
    private $db;

    /**
     * @param object $db BaseDb / PageDb
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Whether the full sent_context / request_payload are still stored next to
     * every trace (dual write). Only when the setting is exactly '1'; by
     * default they are stored only for a trace that could not be verified.
     *
     * @param array $llm_config getLlmConfig()
     * @return bool
     */
    public static function storeBlobsEnabled(array $llm_config)
    {
        return (string)($llm_config['llm_trace_store_blobs'] ?? LLM_TRACE_STORE_BLOBS_DEFAULT) === '1';
    }

    /**
     * The params part of a trace: the adapted payload without messages and
     * the provider's top-level system prompt (both are in the recipe).
     *
     * @param array $payload Wire payload
     * @return array
     */
    public static function paramsOf(array $payload)
    {
        unset($payload['messages'], $payload['system']);
        return $payload;
    }

    /**
     * @param int $message_id Logged assistant message
     * @param int $conversation_id Its conversation (owner of the per-user chunks)
     * @param string $provider Provider id
     * @param array $params paramsOf() the wire payload
     * @param array $messages Neutral messages passed to callLlmApi()
     * @param array $candidate_rows History rows the caller sent
     * @param callable $format_row row => sent message|null
     * @param string $plugin_version
     * @param callable|null $load_image attachment => image data URL|null (LlmApiFormatterService::imageDataUrl)
     * @return array{trace_id: int|null, verified: bool, fallback: string|null}
     */
    public function record($message_id, $conversation_id, $provider, array $params, array $messages, array $candidate_rows, callable $format_row, $plugin_version = '', $load_image = null)
    {
        $open = false;
        try {
            $conversation = $this->db->query_db_first(
                'SELECT id_users FROM llmConversations WHERE id = :id',
                array(':id' => (int)$conversation_id)
            );
            $user_id = (int)($conversation['id_users'] ?? 0);
            if ($user_id <= 0) {
                return $this->fallback(null, 'conversation has no owner');
            }

            LlmTransaction::begin($this->db);
            $open = true;
            LlmPromptVersionLinker::shareGlobalValues($this->db);
            $built = LlmTraceBuilder::build($messages, $candidate_rows, $format_row, LlmTraceHints::sharedTexts(), $user_id,
                is_callable($load_image) ? LlmTraceHints::images() : array());
            $chunk_ids = $this->storeChunks($built['chunks'], $user_id);

            $recipe = array();
            foreach ($built['recipe'] as $entry) {
                if (isset($entry['c'])) {
                    $entry['c'] = array_map(function ($ref) use ($chunk_ids) {
                        return $chunk_ids[$ref];
                    }, $entry['c']);
                } elseif (isset($entry['x'])) {
                    $entry['x'] = $chunk_ids[$entry['x']];
                }
                $recipe[] = $entry;
            }

            $request_hash = LlmTraceCanonicalizer::hash($provider, $params, $messages);
            $trace_id = (int)$this->db->insert('llmRequestTraces', array(
                'id_llmMessages' => (int)$message_id,
                'format_version' => LLM_TRACE_FORMAT_VERSION,
                'plugin_version' => (string)$plugin_version,
                'provider' => (string)$provider,
                'params_json' => self::encode($params),
                'recipe_json' => self::encode($recipe),
                'request_hash' => $request_hash,
                'verified' => 0,
            ));
            if ($trace_id <= 0) {
                throw new RuntimeException('trace insert failed');
            }

            foreach (LlmPromptVersionLinker::resolveAll($this->db, $messages) as $version_id) {
                $this->db->execute_update_db(
                    'INSERT IGNORE INTO llmTracePromptVersions (id_llmRequestTraces, id_llm_prompt_versions) VALUES (:trace, :version)',
                    array(':trace' => $trace_id, ':version' => (int)$version_id)
                );
            }

            if (!empty($built['refs'])) {
                $ids = implode(',', array_map('intval', array_unique($built['refs'])));
                $this->db->execute_update_db("UPDATE llmMessages SET trace_locked = 1 WHERE id IN ($ids) AND trace_locked = 0");
            }

            // Self-check: what the database now holds must rebuild the sent request
            $rebuilt = $this->rebuildMany(array((int)$message_id), $format_row, $load_image);
            $verified = isset($rebuilt[(int)$message_id]) && $rebuilt[(int)$message_id]['verified'] === true;
            if ($verified) {
                $this->db->execute_update_db('UPDATE llmRequestTraces SET verified = 1 WHERE id = :id', array(':id' => $trace_id));
            }
            LlmTransaction::commit($this->db);
            $open = false;
            return $verified
                ? array('trace_id' => $trace_id, 'verified' => true, 'fallback' => null)
                : $this->fallback($trace_id, 'self-check: rebuild does not match the sent request');
        } catch (\Throwable $e) {
            if ($open) {
                LlmTransaction::rollback($this->db);
            }
            LlmErrorReporter::log('trace_fallback: recording the request trace of message ' . (int)$message_id, $e);
            return array('trace_id' => null, 'verified' => false, 'fallback' => 'exception');
        }
    }

    /**
     * Rebuild the requests of logged assistant messages.
     *
     * @param int[] $message_ids
     * @param callable $format_row row => sent message|null
     * @param callable|null $load_image attachment => image data URL|null
     * @return array<int, array{trace_id:int, provider:string, params:array, messages:?array, context:?array, request_hash:string, verified:bool}>
     *   by message id; messages without a trace are missing
     */
    public function rebuildMany(array $message_ids, callable $format_row, $load_image = null)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $message_ids))));
        if (empty($ids)) {
            return array();
        }
        $traces = $this->db->query_db(
            'SELECT id, id_llmMessages, provider, params_json, recipe_json, request_hash
             FROM llmRequestTraces WHERE id_llmMessages IN (' . implode(',', $ids) . ')'
        ) ?: array();

        $chunk_ids = array();
        $row_ids = array();
        $decoded = array();
        foreach ($traces as $trace) {
            $recipe = json_decode((string)$trace['recipe_json'], true);
            $recipe = is_array($recipe) ? $recipe : array();
            $decoded[(int)$trace['id_llmMessages']] = $recipe;
            foreach ($recipe as $entry) {
                if (isset($entry['m'])) {
                    $row_ids[] = (int)$entry['m'];
                } elseif (isset($entry['c'])) {
                    foreach ($entry['c'] as $id) {
                        $chunk_ids[] = (int)$id;
                    }
                } elseif (isset($entry['x'])) {
                    $chunk_ids[] = (int)$entry['x'];
                }
            }
        }

        $chunks = array();
        if (!empty($chunk_ids)) {
            foreach ($this->db->query_db('SELECT id, content FROM llmTraceChunks WHERE id IN ('
                . implode(',', array_unique($chunk_ids)) . ')') ?: array() as $chunk) {
                $chunks[(int)$chunk['id']] = (string)$chunk['content'];
            }
        }
        $rows = array();
        if (!empty($row_ids)) {
            foreach ($this->db->query_db('SELECT id, role, content, attachments FROM llmMessages WHERE id IN ('
                . implode(',', array_unique($row_ids)) . ')') ?: array() as $row) {
                $rows[(int)$row['id']] = $row;
            }
        }

        $result = array();
        foreach ($traces as $trace) {
            $message_id = (int)$trace['id_llmMessages'];
            $params = json_decode((string)$trace['params_json'], true);
            $params = is_array($params) ? $params : array();
            $messages = LlmTraceBuilder::rebuildMessages($decoded[$message_id], $chunks, $rows, $format_row, $load_image);
            // What was sent besides stored history rows (system context, retry prompts, ...)
            $context = null;
            if ($messages !== null) {
                $context = array();
                foreach ($decoded[$message_id] as $index => $entry) {
                    if (!isset($entry['m'])) {
                        $context[] = $messages[$index];
                    }
                }
            }
            $result[$message_id] = array(
                'trace_id' => (int)$trace['id'],
                'provider' => (string)$trace['provider'],
                'params' => $params,
                'messages' => $messages,
                'context' => $context,
                'request_hash' => (string)$trace['request_hash'],
                'verified' => $messages !== null
                    && LlmTraceCanonicalizer::hash($trace['provider'], $params, $messages) === $trace['request_hash'],
            );
        }
        return $result;
    }

    /**
     * Fill sent_context / request_payload of message rows for readers (admin
     * console, dataset import): a stored blob wins, else the trace rebuild.
     * Adds `trace_status` (blob | trace | none) and `trace_verified`
     * (true / false, null without a trace). Only rows without a blob are
     * rebuilt; a row with a blob shows the write-time self-check (`verified`)
     * of its trace, the verifier script re-checks those.
     *
     * sent_context is what was sent besides stored history rows. The rebuilt
     * request_payload is $to_wire(provider, params, messages) - the
     * provider's wire format, see LlmAdminService - or, without $to_wire,
     * the neutral request (params + messages as passed to callLlmApi).
     *
     * @param array $rows llmMessages rows with id, sent_context, request_payload
     * @param callable $format_row row => sent message|null
     * @param callable|null $to_wire (string provider, array params, array messages) => array payload
     * @param callable|null $load_image attachment => image data URL|null
     * @return array rows
     */
    public function fillForReaders(array $rows, callable $format_row, $to_wire = null, $load_image = null)
    {
        $with_blob = array();
        $without_blob = array();
        // addMessage() stores a missing context / payload as the JSON text 'null'
        $stored_blob = function ($value) {
            return $value !== null && $value !== 'null';
        };
        foreach ($rows as $row) {
            if ($stored_blob($row['sent_context'] ?? null) || $stored_blob($row['request_payload'] ?? null)) {
                $with_blob[] = (int)$row['id'];
            } else {
                $without_blob[] = (int)$row['id'];
            }
        }
        $rebuilt = $this->rebuildMany($without_blob, $format_row, $load_image);
        $stored = array();
        if (!empty($with_blob)) {
            foreach ($this->db->query_db('SELECT id_llmMessages, verified FROM llmRequestTraces WHERE id_llmMessages IN ('
                . implode(',', $with_blob) . ')') ?: array() as $trace_row) {
                $stored[(int)$trace_row['id_llmMessages']] = (int)$trace_row['verified'] === 1;
            }
        }
        foreach ($rows as &$row) {
            $trace = $rebuilt[(int)$row['id']] ?? null;
            if (in_array((int)$row['id'], $with_blob, true)) {
                $row['trace_status'] = 'blob';
                $row['trace_verified'] = $stored[(int)$row['id']] ?? null;
                continue;
            }
            $row['trace_verified'] = $trace !== null ? $trace['verified'] : null;
            if ($trace !== null && $trace['messages'] !== null) {
                $row['trace_status'] = 'trace';
                $row['sent_context'] = self::encode($trace['context']);
                $row['request_payload'] = self::encode(is_callable($to_wire)
                    ? call_user_func($to_wire, $trace['provider'], $trace['params'], $trace['messages'])
                    : $trace['params'] + array('messages' => $trace['messages']));
            } else {
                $row['trace_status'] = 'none';
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Insert missing chunks and return their ids by builder ref.
     * Public so other plugins store their text the same way (once per owner and sha256).
     *
     * @param array $chunks ref "<owner>:<sha256>" => {owner, sha256, content}; owner 0 = shared, else id_users
     * @param int $user_id
     * @return array<string, int>
     */
    public function storeChunks(array $chunks, $user_id)
    {
        $ids = array();
        if (empty($chunks)) {
            return $ids;
        }
        foreach ($chunks as $chunk) {
            $this->db->execute_update_db(
                'INSERT IGNORE INTO llmTraceChunks (owner, id_users, sha256, content) VALUES (:owner, :id_users, :sha, :content)',
                array(
                    ':owner' => (int)$chunk['owner'],
                    ':id_users' => $chunk['owner'] === 0 ? null : (int)$user_id,
                    ':sha' => $chunk['sha256'],
                    ':content' => $chunk['content'],
                )
            );
        }
        $params = array();
        $placeholders = array();
        foreach (array_values($chunks) as $i => $chunk) {
            $placeholders[] = ':sha' . $i;
            $params[':sha' . $i] = $chunk['sha256'];
        }
        $rows = $this->db->query_db(
            'SELECT id, owner, sha256 FROM llmTraceChunks WHERE owner IN (0, ' . (int)$user_id . ') AND sha256 IN (' . implode(',', $placeholders) . ')',
            $params
        ) ?: array();
        foreach ($rows as $row) {
            $ids[(int)$row['owner'] . ':' . $row['sha256']] = (int)$row['id'];
        }
        foreach (array_keys($chunks) as $ref) {
            if (!isset($ids[$ref])) {
                throw new RuntimeException('Trace chunk not stored: ' . $ref);
            }
        }
        return $ids;
    }

    private function fallback($trace_id, $reason)
    {
        LlmErrorReporter::log('trace_fallback: ' . $reason, new RuntimeException($reason));
        return array('trace_id' => $trace_id, 'verified' => false, 'fallback' => $reason);
    }

    private static function encode($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
?>
