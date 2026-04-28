<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * LLM Dataset AI Import Parser Service
 *
 * Uses LLM calls to parse unstructured or semi-structured data (CSV, JSON,
 * text) into standardized dataset test cases. The LLM acts as an intelligent
 * parser that understands column semantics and maps them to the expected
 * input/output schema.
 *
 * Creates a dedicated conversation for each import session so the parsing
 * history is auditable through the admin console.
 *
 * @package LLM Plugin
 * @see LlmDatasetAiImportMapperService For post-parse normalization
 */
class LlmDatasetAiImportParserService extends BaseLlmService
{
    /** @var LlmService Core LLM service for API calls */
    private $llm_service;

    /** @var LlmPromptAssetLoader Loads prompt templates from disk */
    private $prompt_assets;

    /** @var LlmDatasetAiImportMapperService Post-parse normalization */
    private $mapper_service;

    /**
     * @param object                          $services       SelfHelp services container.
     * @param LlmDatasetAiImportMapperService $mapper_service Post-parse normalization service.
     */
    public function __construct($services, $mapper_service)
    {
        parent::__construct($services);
        $this->llm_service = new LlmService($services);
        $this->prompt_assets = new LlmPromptAssetLoader();
        $this->mapper_service = $mapper_service;
    }

    /**
     * Use an LLM to parse raw pasted text into structured dataset test cases.
     *
     * Sends the text to the LLM with a specialized parser prompt, decodes the response,
     * normalizes via the mapper service, and returns the structured result.
     *
     * @param array       $descriptor         Owner descriptor.
     * @param string      $execution_profile  Target execution profile code.
     * @param string      $raw_text           Pasted CSV/JSON/text to parse.
     * @param string|null $selected_model     Override model, or null for default.
     * @param array       $runtime_overrides  Runtime parameter overrides.
     * @return array{mapping: array, cases: array, warnings: string[], model: string, ...}
     * @throws Exception If text is empty or parser returns no usable cases.
     */
    public function parseCasesFromText($descriptor, $execution_profile, $raw_text, $selected_model = null, $runtime_overrides = array())
    {
        $raw_text = trim((string)$raw_text);
        if ($raw_text === '') {
            throw new Exception('Paste input text is required');
        }

        $config = $this->getLlmConfig();
        $model_name = $selected_model ?: $config['llm_default_model'];
        $temperature = 0.1;
        // Parser output can be large for multi-row tabular inputs; keep a safer floor.
        $max_tokens = max(4096, (int)$config['llm_max_tokens']);

        $system_prompt = $this->prompt_assets->load('core.dataset_import.system');
        $user_prompt = json_encode(array(
            'descriptor' => $descriptor,
            'execution_profile' => $execution_profile,
            'raw_text' => $raw_text
        ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $messages = array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => (string)$user_prompt)
        );

        $conversation_id = $this->getOrCreateDatasetImportConversation(
            (int)($_SESSION['id_user'] ?? 0),
            $model_name,
            $temperature,
            $max_tokens,
            (int)($descriptor['owner_id'] ?? 0)
        );

        $request_msg_id = $this->llm_service->addMessage(
            $conversation_id,
            'user',
            'Parse pasted dataset examples into structured cases',
            null,
            $model_name,
            0,
            null,
            $messages,
            null,
            true,
            null
        );

        $response = $this->llm_service->callLlmApi(
            $messages,
            $model_name,
            $temperature,
            $max_tokens,
            array(
                'conversation_id' => $conversation_id,
                'sent_context' => $messages,
                'is_validated' => true
            )
        );

        $decoded = $this->decodeParserResponse(
            (string)($response['content'] ?? ''),
            $model_name,
            $max_tokens
        );
        $normalized = $this->mapper_service->normalizeParsedPayload(
            $decoded,
            $descriptor,
            (string)$execution_profile,
            is_array($runtime_overrides) ? $runtime_overrides : array()
        );

        if (empty($normalized['cases'])) {
            throw new Exception('Parser returned no usable dataset cases. Please refine the pasted text or mapping.');
        }

        return array(
            'mapping' => $normalized['mapping'] ?? array(),
            'cases' => $normalized['cases'] ?? array(),
            'warnings' => $normalized['warnings'] ?? array(),
            'model' => $model_name,
            'request_payload' => $response['request_payload'] ?? null,
            'id_llmConversations' => $conversation_id,
            'id_llmMessages_request' => $request_msg_id,
            'id_llmMessages_response' => $response['logged_message_id'] ?? null
        );
    }

    /**
     * Decode the LLM parser response, attempting repair via a second LLM call on failure.
     *
     * @param string $content    Raw LLM response content.
     * @param string $model_name Model used for repair call.
     * @param int    $max_tokens Max tokens for repair call.
     * @return array Decoded parser payload with 'cases' and optional 'mapping'.
     * @throws Exception If JSON cannot be decoded even after repair.
     */
    private function decodeParserResponse($content, $model_name, $max_tokens)
    {
        if (!is_string($content) || trim($content) === '') {
            throw new Exception('Parser returned an empty response');
        }

        $decoded = $this->decodeCandidatePayload($content);
        if (is_array($decoded)) {
            return $decoded;
        }

        $repaired = $this->repairParserJsonResponse($content, $model_name, $max_tokens);
        $decoded = $this->decodeCandidatePayload($repaired);
        if (is_array($decoded)) {
            return $decoded;
        }

        $preview = substr(trim((string)$content), 0, 240);
        throw new Exception('Parser returned invalid JSON. Preview: ' . $preview);
    }

    /**
     * Try all JSON candidates extracted from raw text and return the first valid parser payload.
     *
     * @param string $raw Raw text potentially containing JSON.
     * @return array|null Decoded payload, or null if none valid.
     */
    private function decodeCandidatePayload($raw)
    {
        $candidates = $this->collectJsonCandidates((string)$raw);
        foreach ($candidates as $candidate) {
            $decoded = $this->tryDecodeArrayPayload($candidate);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract all possible JSON fragments from raw text: full text, code-fence blocks, balanced fragments.
     *
     * @param string $raw Raw LLM response text.
     * @return string[] Unique non-empty candidate JSON strings.
     */
    private function collectJsonCandidates($raw)
    {
        $raw = trim((string)$raw);
        $candidates = array();
        if ($raw === '') {
            return $candidates;
        }

        $candidates[] = $raw;

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $raw, $matches)) {
            $candidates[] = trim((string)$matches[1]);
        }

        if (preg_match_all('/```(?:json)?\s*(.*?)\s*```/is', $raw, $blocks)) {
            foreach (($blocks[1] ?? array()) as $block) {
                $candidates[] = trim((string)$block);
            }
        }

        $fragment = $this->extractBalancedJsonFragment($raw);
        if ($fragment !== null) {
            $candidates[] = $fragment;
        }

        return array_values(array_unique(array_filter($candidates, function ($item) {
            return trim((string)$item) !== '';
        })));
    }

    /**
     * Extract the first balanced JSON object or array from text by tracking brace/bracket depth.
     *
     * @param string $text Text containing a JSON fragment.
     * @return string|null Extracted JSON string, or null if no balanced fragment found.
     */
    private function extractBalancedJsonFragment($text)
    {
        $text = (string)$text;
        $len = strlen($text);
        $start = -1;
        $start_char = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === '{' || $ch === '[') {
                $start = $i;
                $start_char = $ch;
                break;
            }
        }

        if ($start < 0) {
            return null;
        }

        $end_char = $start_char === '{' ? '}' : ']';
        $depth = 0;
        $in_string = false;
        $escaped = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $in_string = false;
                }
                continue;
            }

            if ($ch === '"') {
                $in_string = true;
                continue;
            }

            if ($ch === $start_char) {
                $depth++;
            } elseif ($ch === $end_char) {
                $depth--;
                if ($depth === 0) {
                    return trim(substr($text, $start, $i - $start + 1));
                }
            }
        }

        return null;
    }

    /**
     * Attempt to JSON-decode a candidate string, also trying trailing-comma removal as a fallback.
     *
     * @param string $candidate JSON candidate string.
     * @return array|null Decoded array if it looks like a parser payload, or null.
     */
    private function tryDecodeArrayPayload($candidate)
    {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            return null;
        }

        $decoded = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $this->looksLikeParserPayload($decoded)) {
            return $decoded;
        }

        $candidate_no_trailing_commas = preg_replace('/,\s*([}\]])/', '$1', $candidate);
        if ($candidate_no_trailing_commas !== null && $candidate_no_trailing_commas !== $candidate) {
            $decoded = json_decode($candidate_no_trailing_commas, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $this->looksLikeParserPayload($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Check if a decoded array has the expected parser payload keys (cases, mapping, column_mapping).
     *
     * @param mixed $decoded Decoded JSON value.
     * @return bool True if it contains expected parser keys.
     */
    private function looksLikeParserPayload($decoded)
    {
        if (!is_array($decoded)) {
            return false;
        }

        return array_key_exists('cases', $decoded)
            || array_key_exists('mapping', $decoded)
            || array_key_exists('column_mapping', $decoded);
    }

    /**
     * Attempt to repair malformed JSON by sending it through a repair-focused LLM call.
     *
     * @param string $content    Malformed JSON content.
     * @param string $model_name LLM model for the repair call.
     * @param int    $max_tokens Max tokens for the repair response.
     * @return string Repaired response content.
     */
    private function repairParserJsonResponse($content, $model_name, $max_tokens)
    {
        $repair_system = $this->prompt_assets->load('core.dataset_import.repair_json');
        $repair_messages = array(
            array('role' => 'system', 'content' => $repair_system),
            array('role' => 'user', 'content' => (string)$content)
        );

        $repair_response = $this->llm_service->callLlmApi(
            $repair_messages,
            $model_name,
            0.0,
            min(4096, max(1024, (int)$max_tokens)),
            null
        );

        return (string)($repair_response['content'] ?? '');
    }

    /**
     * Retrieve or create a dedicated conversation for dataset import parsing sessions.
     *
     * @param int    $user_id    Current user ID.
     * @param string $model_name LLM model identifier.
     * @param float  $temperature Model temperature.
     * @param int    $max_tokens  Max response tokens.
     * @param int    $section_id  Owner section/script ID.
     * @return int Conversation ID.
     */
    private function getOrCreateDatasetImportConversation($user_id, $model_name, $temperature, $max_tokens, $section_id)
    {
        $title = '[Dataset Import Parser] Section ' . ($section_id ?: 0);

        $existing = $this->db->query_db_first(
            "SELECT id
             FROM llmConversations
             WHERE id_users = :id_users
               AND id_sections <=> :id_sections
               AND model = :model
               AND title = :title
               AND deleted = 0
             ORDER BY updated_at DESC
             LIMIT 1",
            array(
                ':id_users' => $user_id,
                ':id_sections' => $section_id ?: null,
                ':model' => $model_name,
                ':title' => $title
            )
        );

        if (!empty($existing['id'])) {
            return (int)$existing['id'];
        }

        return $this->llm_service->createConversation(
            $user_id,
            $title,
            $model_name,
            $temperature,
            $max_tokens,
            $section_id ?: null
        );
    }
}
?>
