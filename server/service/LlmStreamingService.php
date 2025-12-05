<?php
/**
 * LLM Streaming Service
 * Handles Server-Sent Events streaming for real-time LLM responses
 * 
 * Features:
 * - Periodic partial saves to prevent data loss on interruption
 * - Smooth SSE delivery with optimized buffering
 * - Recovery support for interrupted streams
 */

class LlmStreamingService
{
    private $llm_service;
    
    /** @var int Number of chunks between partial saves */
    const SAVE_INTERVAL_CHUNKS = 10;
    
    /** @var int Minimum characters before first save */
    const MIN_CHARS_BEFORE_SAVE = 50;

    public function __construct($llm_service)
    {
        $this->llm_service = $llm_service;
    }

    /**
     * Start streaming response using Server-Sent Events
     * Optimized for smooth, fluid streaming delivery with periodic saves
     */
    public function startStreamingResponse($conversation_id, $messages, $model, $is_new_conversation)
    {
        // Check if any content has already been sent
        if (headers_sent()) {
            error_log('Headers already sent, cannot start streaming');
            return;
        }

        // Set SSE headers for optimal streaming
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Cache-Control');

        // Disable all output buffering for immediate delivery
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Disable output buffering and compression at PHP level
        @ini_set('output_buffering', 'Off');
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('implicit_flush', 1);
        ob_implicit_flush(true);

        // Send initial connection event
        $this->sendSSE(['type' => 'connected', 'conversation_id' => $conversation_id]);

        $full_response = '';
        $tokens_used = 0;
        $chunk_count = 0;
        $streaming_message_id = null;
        $last_save_length = 0;

        // Ensure parameters are available for streaming
        $streaming_model = $model;
        $config = $this->llm_service->getLlmConfig();
        $streaming_temperature = $config['llm_temperature'] ?? 0.7;
        $streaming_max_tokens = $config['llm_max_tokens'] ?? 1000;

        try {
            // Start streaming with callback
            $this->llm_service->streamLlmResponse(
                $messages,
                $streaming_model,
                $streaming_temperature,
                $streaming_max_tokens,
                function($chunk) use (&$full_response, &$tokens_used, &$chunk_count, &$streaming_message_id, &$last_save_length, $conversation_id, $streaming_model) {
                    if ($chunk === '[DONE]') {
                        // Streaming completed - finalize the message
                        $this->sendSSE(['type' => 'done', 'tokens_used' => $tokens_used]);

                        try {
                            if ($streaming_message_id) {
                                // Update existing streaming message to mark as complete
                                $this->llm_service->updateStreamingMessage(
                                    $streaming_message_id,
                                    $full_response,
                                    $tokens_used,
                                    false // is_streaming = false (complete)
                                );
                                error_log("Streaming completed. Finalized message ID: $streaming_message_id for conversation: $conversation_id");
                            } else {
                                // Create new complete message (fallback if no partial saves occurred)
                                $message_id = $this->llm_service->addMessage(
                                    $conversation_id,
                                    'assistant',
                                    $full_response,
                                    null,
                                    $streaming_model,
                                    $tokens_used
                                );
                                error_log("Streaming completed (no partial saves). Saved message ID: $message_id for conversation: $conversation_id");
                            }
                        } catch (Exception $e) {
                            error_log('Failed to save streamed message: ' . $e->getMessage());
                            $this->sendSSE(['type' => 'error', 'message' => 'Failed to save message: ' . $e->getMessage()]);
                        }

                        // Close the connection
                        $this->sendSSE(['type' => 'close']);
                        return;
                    }

                    // Check for usage data
                    if (strpos($chunk, '[USAGE:') === 0) {
                        $usage_str = substr($chunk, 7, -1); // Remove '[USAGE:' and ']'
                        $tokens_used = intval($usage_str);
                        return;
                    }

                    // Accumulate the response
                    $full_response .= $chunk;
                    $chunk_count++;

                    // Send chunk to client immediately
                    $this->sendSSE([
                        'type' => 'chunk',
                        'content' => $chunk
                    ]);

                    // Periodic partial save to prevent data loss
                    // Save every SAVE_INTERVAL_CHUNKS chunks if we have enough content
                    $should_save = ($chunk_count % self::SAVE_INTERVAL_CHUNKS === 0) 
                                   && (strlen($full_response) >= self::MIN_CHARS_BEFORE_SAVE)
                                   && (strlen($full_response) > $last_save_length + 20); // Only save if meaningful content added
                    
                    if ($should_save) {
                        try {
                            if ($streaming_message_id) {
                                // Update existing streaming message
                                $this->llm_service->updateStreamingMessage(
                                    $streaming_message_id,
                                    $full_response,
                                    null, // tokens not known yet
                                    true  // is_streaming = true
                                );
                            } else {
                                // Create new streaming message (first partial save)
                                $streaming_message_id = $this->llm_service->addStreamingMessage(
                                    $conversation_id,
                                    $full_response,
                                    $streaming_model
                                );
                            }
                            $last_save_length = strlen($full_response);
                            error_log("Partial save at chunk $chunk_count, " . strlen($full_response) . " chars for conversation: $conversation_id");
                        } catch (Exception $e) {
                            // Log but don't interrupt streaming
                            error_log('Partial save failed (non-critical): ' . $e->getMessage());
                        }
                    }
                }
            );
        } catch (Exception $e) {
            error_log('Streaming failed: ' . $e->getMessage());
            
            // Try to save whatever we have so far
            if (!empty($full_response)) {
                try {
                    if ($streaming_message_id) {
                        $this->llm_service->updateStreamingMessage(
                            $streaming_message_id,
                            $full_response . "\n\n[Streaming interrupted: " . $e->getMessage() . "]",
                            $tokens_used,
                            false // Mark as complete
                        );
                    } else {
                        $this->llm_service->addMessage(
                            $conversation_id,
                            'assistant',
                            $full_response . "\n\n[Streaming interrupted: " . $e->getMessage() . "]",
                            null,
                            $streaming_model,
                            $tokens_used
                        );
                    }
                    error_log("Saved partial response on error for conversation: $conversation_id");
                } catch (Exception $saveError) {
                    error_log('Failed to save partial response on error: ' . $saveError->getMessage());
                }
            }
            
            $this->sendSSE(['type' => 'error', 'message' => $e->getMessage()]);
        }

        // Ensure connection is closed
        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit;
    }

    /**
     * Send Server-Sent Event
     * Optimized for smooth, low-latency delivery
     */
    private function sendSSE($data)
    {
        // Use JSON encoding with minimal whitespace for faster transmission
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        // Flush immediately for real-time delivery
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // Small delay to prevent overwhelming the client on rapid chunks
        // This helps with smooth rendering on the frontend
        if (isset($data['type']) && $data['type'] === 'chunk') {
            usleep(5000); // 5ms delay between chunks for smoother rendering
        }
    }
}
?>
