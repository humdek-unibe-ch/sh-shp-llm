<?php
/**
 * LLM Streaming Service - Industry Standard Event-Driven Implementation
 *
 * Features:
 * - Zero database writes during streaming (memory-only buffering)
 * - Single atomic commit on completion
 * - Guaranteed data integrity
 * - Enterprise-grade error recovery
 * - Optimized SSE delivery
 */

class LlmStreamingService
{
    private $llm_service;
    private $conversation_id;
    private $model;
    private $has_finalized = false;

    public function __construct($llm_service)
    {
        $this->llm_service = $llm_service;
    }

    /**
     * Start streaming response using Server-Sent Events
     * Industry-standard implementation with zero partial saves
     */
    public function startStreamingResponse($conversation_id, $messages, $model, $is_new_conversation)
    {
        $this->conversation_id = $conversation_id;
        $this->model = $model;
        $this->has_finalized = false;

        // Validate headers
        if (headers_sent()) {
            return;
        }

        $this->setupSSEHeaders();
        $this->sendSSE(['type' => 'connected', 'conversation_id' => $conversation_id]);

        $streaming_buffer = new StreamingBuffer($conversation_id, $model, $this->llm_service);
        $tokens_used = 0;

        try {
            $config = $this->llm_service->getLlmConfig();

            $this->llm_service->streamLlmResponse(
                $messages,
                $model,
                $config['llm_temperature'] ?? 0.7,
                $config['llm_max_tokens'] ?? 1000,
                function($chunk) use ($streaming_buffer, &$tokens_used) {
                    return $this->processStreamingChunk($chunk, $streaming_buffer, $tokens_used);
                }
            );
        } catch (Exception $e) {
            $this->handleStreamingError($e, $streaming_buffer, $tokens_used);
        }

        // Fallback: if the upstream stream ended without an explicit [DONE],
        // persist whatever we have so far to avoid losing the response.
        if (!$this->has_finalized) {
            if ($streaming_buffer->hasContent()) {
                $this->finalizeStreaming($streaming_buffer, $tokens_used);
            } else {
                $this->has_finalized = true;
                $this->sendSSE(['type' => 'error', 'message' => 'Streaming ended with no content to save']);
                $this->sendSSE(['type' => 'close']);
            }
        }

        exit;
    }

    /**
     * Setup optimized SSE headers for streaming
     */
    private function setupSSEHeaders()
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');

        // Disable all output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('implicit_flush', 1);
        ob_implicit_flush(true);
    }

    /**
     * Process individual streaming chunks
     */
    private function processStreamingChunk($chunk, StreamingBuffer $buffer, &$tokens_used)
    {
        if ($chunk === '[DONE]') {
            $this->finalizeStreaming($buffer, $tokens_used);
            return;
        }

        if (strpos($chunk, '[USAGE:') === 0) {
            $tokens_used = intval(substr($chunk, 7, -1));
            return;
        }

        $buffer->append($chunk);
        $this->sendSSE(['type' => 'chunk', 'content' => $chunk]);
    }

    /**
     * Finalize streaming with atomic database commit
     */
    private function finalizeStreaming(StreamingBuffer $buffer, $tokens_used)
    {
        if ($this->has_finalized) {
            return;
        }

        $this->has_finalized = true;

        try {
            $raw_response = $this->buildRawResponse($buffer->getContent(), $tokens_used);
            $buffer->finalize($tokens_used, $raw_response);
            $this->sendSSE(['type' => 'done', 'tokens_used' => $tokens_used]);
            $this->sendSSE(['type' => 'close']);
        } catch (Exception $e) {
            $this->sendSSE(['type' => 'error', 'message' => 'Failed to save message: ' . $e->getMessage()]);
        }
    }

    /**
     * Build a minimal raw_response payload so streamed messages
     * are persisted with the same fidelity as non-streamed calls.
     */
    private function buildRawResponse($content, $tokens_used)
    {
        return [
            'streaming' => true,
            'model' => $this->model,
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content
                    ],
                    'finish_reason' => 'stop'
                ]
            ],
            'usage' => [
                'total_tokens' => $tokens_used
            ]
        ];
    }

    /**
     * Handle streaming errors with emergency recovery
     */
    private function handleStreamingError(Exception $e, StreamingBuffer $buffer, $tokens_used)
    {
        if ($this->has_finalized) {
            return;
        }

        try {
            $raw_response = [
                'streaming' => true,
                'model' => $this->model,
                'error' => $e->getMessage(),
                'partial_content' => $buffer->getContent(),
                'usage' => ['total_tokens' => $tokens_used]
            ];

            $buffer->emergencySave($e->getMessage(), $raw_response);
            $this->has_finalized = true;
            $this->sendSSE(['type' => 'error', 'message' => $e->getMessage()]);
            $this->sendSSE(['type' => 'close']);
        } catch (Exception $saveError) {
            $this->has_finalized = true;
            $this->sendSSE(['type' => 'error', 'message' => 'Critical error: ' . $e->getMessage()]);
            $this->sendSSE(['type' => 'close']);
        }
    }

    /**
     * Send Server-Sent Event with optimized delivery
     */
    private function sendSSE($data)
    {
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // Rate limiting for smooth rendering
        if (isset($data['type']) && $data['type'] === 'chunk') {
            usleep(2000); // 2ms delay for optimal rendering
        }
    }
}

/**
 * Streaming Buffer - In-memory content management with atomic commits
 */
class StreamingBuffer
{
    private $buffer = '';
    private $conversation_id;
    private $model;
    private $llm_service;
    private $start_time;

    public function __construct($conversation_id, $model, $llm_service)
    {
        $this->conversation_id = $conversation_id;
        $this->model = $model;
        $this->llm_service = $llm_service;
        $this->start_time = microtime(true);
    }

    /**
     * Append chunk to buffer and send to client
     */
    public function append($chunk)
    {
        $this->buffer .= $chunk;
    }

    /**
     * Atomic final commit to database
     */
    public function finalize($tokens_used, $raw_response = null)
    {
        $this->llm_service->addMessage(
            $this->conversation_id,
            'assistant',
            $this->buffer,
            null,
            $this->model,
            $tokens_used,
            $raw_response
        );
    }

    /**
     * Emergency save on error
     */
    public function emergencySave($error_message, $raw_response = null)
    {
        $emergency_content = $this->buffer . "\n\n[Streaming interrupted: {$error_message}]";

        $this->llm_service->addMessage(
            $this->conversation_id,
            'assistant',
            $emergency_content,
            null,
            $this->model,
            null,
            $raw_response
        );
    }

    /**
     * Get the current buffered content
     */
    public function getContent()
    {
        return $this->buffer;
    }

    /**
     * Check whether any content has been buffered
     */
    public function hasContent()
    {
        return strlen($this->buffer) > 0;
    }

    /**
     * Get buffer statistics for monitoring
     */
    public function getStats()
    {
        return [
            'buffer_size' => strlen($this->buffer),
            'chunks_count' => substr_count($this->buffer, ' '), // Rough estimate
            'duration' => microtime(true) - $this->start_time
        ];
    }
}
?>
