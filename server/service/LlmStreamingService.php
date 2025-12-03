<?php
/**
 * LLM Streaming Service
 * Handles Server-Sent Events streaming for real-time LLM responses
 */

class LlmStreamingService
{
    private $llm_service;

    public function __construct($llm_service)
    {
        $this->llm_service = $llm_service;
    }

    /**
     * Start streaming response using Server-Sent Events
     * Optimized for smooth, fluid streaming delivery
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

        // Ensure parameters are available for streaming
        $streaming_model = $model;
        $streaming_temperature = $temperature ?? 0.7;
        $streaming_max_tokens = $max_tokens ?? 1000;

        try {
            // Start streaming with callback
            $this->llm_service->streamLlmResponse(
                $messages,
                $streaming_model,
                $streaming_temperature,
                $streaming_max_tokens,
                function($chunk) use (&$full_response, &$tokens_used, $conversation_id, $streaming_model) {
                    if ($chunk === '[DONE]') {
                        // Streaming completed
                        $this->sendSSE(['type' => 'done', 'tokens_used' => $tokens_used]);

                        // Save the complete assistant message
                        try {
                            $message_id = $this->llm_service->addMessage(
                                $conversation_id,
                                'assistant',
                                $full_response,
                                null,
                                $streaming_model,
                                $tokens_used
                            );
                            error_log("Streaming completed. Saved message ID: $message_id for conversation: $conversation_id");
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

                    // Send chunk to client
                    $this->sendSSE([
                        'type' => 'chunk',
                        'content' => $chunk
                    ]);
                }
            );
        } catch (Exception $e) {
            error_log('Streaming failed: ' . $e->getMessage());
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
