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
 * - Post-stream safety assessment
 */

require_once __DIR__ . '/LlmResponseService.php';

class LlmStreamingService
{
    private $llm_service;
    private $conversation_id;
    private $model;
    private $has_finalized = false;
    private $sent_context = null;
    private $progress_data = null;
    
    /** @var LlmResponseService|null Response service for safety parsing */
    private $response_service = null;
    
    /** @var LlmDangerDetectionService|null Danger detection for notifications */
    private $danger_detection_service = null;
    
    /** @var object|null Model instance for configuration */
    private $config_model = null;

    public function __construct($llm_service)
    {
        $this->llm_service = $llm_service;
    }
    
    /**
     * Set optional services for safety detection
     * 
     * @param LlmResponseService $response_service Response parsing service
     * @param LlmDangerDetectionService $danger_detection_service Notification service
     * @param object $model Model instance for configuration
     */
    public function setSafetyServices($response_service, $danger_detection_service, $model)
    {
        $this->response_service = $response_service;
        $this->danger_detection_service = $danger_detection_service;
        $this->config_model = $model;
    }
    
    /**
     * Get the provider instance from LlmService
     * 
     * @return LlmProviderInterface
     */
    private function getProvider()
    {
        return $this->llm_service->getProvider();
    }

    /**
     * Start streaming response using Server-Sent Events
     * Industry-standard implementation with zero partial saves
     *
     * @param int $conversation_id The conversation ID
     * @param array $messages The formatted API messages
     * @param string $model The model to use
     * @param bool $is_new_conversation Whether this is a new conversation
     * @param array|null $sent_context Context messages that were sent (for tracking)
     * @param array|null $progress_data Progress data to include in final response
     */
    public function startStreamingResponse($conversation_id, $messages, $model, $is_new_conversation, $sent_context = null, $progress_data = null)
    {
        $this->conversation_id = $conversation_id;
        $this->model = $model;
        $this->has_finalized = false;
        $this->sent_context = $sent_context;
        $this->progress_data = $progress_data;

        // Validate headers
        if (headers_sent()) {
            return;
        }

        $this->setupSSEHeaders();
        $this->sendSSE(['type' => 'connected', 'conversation_id' => $conversation_id]);

        $streaming_buffer = new StreamingBuffer($conversation_id, $model, $this->llm_service, $sent_context);
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
            // Log the error for debugging
            error_log('Streaming error: ' . $e->getMessage());
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
            $content = $buffer->getContent();
            $raw_response = $this->buildRawResponse($content, $tokens_used);
            $buffer->finalize($tokens_used, $raw_response);

            $done_data = ['type' => 'done', 'tokens_used' => $tokens_used];
            if ($this->progress_data !== null) {
                $done_data['progress'] = $this->progress_data;
            }
            
            // Parse and check safety from completed response
            $safety_result = $this->processStreamedResponseSafety($content);
            if ($safety_result !== null) {
                $done_data['safety'] = $safety_result;
            }

            $this->sendSSE($done_data);
            $this->sendSSE(['type' => 'close']);
        } catch (Exception $e) {
            $this->sendSSE(['type' => 'error', 'message' => 'Failed to save message: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Process safety from completed streamed response
     * 
     * Parses the completed response to check for safety concerns.
     * Sends notifications and blocks conversation if needed.
     * 
     * @param string $content Completed response content
     * @return array|null Safety data to include in done event, or null if safe
     */
    private function processStreamedResponseSafety($content)
    {
        // Skip if no response service configured
        if (!$this->response_service) {
            return null;
        }
        
        // Parse the response
        $parsed = $this->response_service->parseResponse($content);
        if (!$parsed['valid'] || !isset($parsed['data'])) {
            return null;
        }
        
        // Assess safety
        $safety = $this->response_service->assessSafety($parsed['data']);
        
        // If safe and no concerns, nothing to return
        if ($safety['is_safe'] && $safety['danger_level'] === null) {
            return null;
        }
        
        // Handle intervention if needed
        if ($safety['requires_intervention'] && $this->danger_detection_service && $this->config_model) {
            try {
                // Get user ID from conversation
                $conversation = $this->llm_service->db->query_db_first(
                    'SELECT id_users FROM llmConversations WHERE id = :id',
                    ['id' => $this->conversation_id]
                );
                $user_id = $conversation ? $conversation['id_users'] : 0;
                $section_id = method_exists($this->config_model, 'getSectionId') 
                    ? $this->config_model->getSectionId() 
                    : 0;
                
                // Send notifications
                $this->danger_detection_service->sendNotifications(
                    $safety['detected_concerns'],
                    $safety['safety_message'] ?? 'Dangerous content detected by AI',
                    $user_id,
                    $this->conversation_id,
                    $section_id
                );
                
                // Block conversation if emergency
                if ($safety['danger_level'] === 'emergency') {
                    $reason = 'LLM detected emergency danger: ' . implode(', ', $safety['detected_concerns']);
                    $this->danger_detection_service->blockConversation(
                        $this->conversation_id, 
                        $safety['detected_concerns'], 
                        $reason
                    );
                }
            } catch (Exception $e) {
                error_log('LLM Streaming: Failed to process safety intervention: ' . $e->getMessage());
            }
        }
        
        // Return safety data for frontend
        return $safety;
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
            // Convert technical error messages to user-friendly ones for display
            $userFriendlyMessage = $this->getUserFriendlyErrorMessage($e->getMessage());

            $raw_response = [
                'streaming' => true,
                'model' => $this->model,
                'error' => $e->getMessage(), // Keep original error for debugging
                'partial_content' => $buffer->getContent(),
                'usage' => ['total_tokens' => $tokens_used]
            ];

            $buffer->emergencySave($userFriendlyMessage, $raw_response);
            $this->has_finalized = true;
            $this->sendSSE(['type' => 'error', 'message' => $userFriendlyMessage]);
            $this->sendSSE(['type' => 'close']);
        } catch (Exception $saveError) {
            $this->has_finalized = true;
            $this->sendSSE(['type' => 'error', 'message' => 'Critical error: ' . $e->getMessage()]);
            $this->sendSSE(['type' => 'close']);
        }
    }

    /**
     * Convert technical error messages to user-friendly ones
     */
    private function getUserFriendlyErrorMessage($errorMessage)
    {
        if (strpos($errorMessage, 'HTTP 403') !== false || strpos($errorMessage, 'Access denied') !== false) {
            return 'Access denied. Please check your API permissions.';
        } elseif (strpos($errorMessage, 'HTTP 401') !== false || strpos($errorMessage, 'Authentication failed') !== false) {
            return 'Authentication failed. Please check your API key.';
        } elseif (strpos($errorMessage, 'HTTP 429') !== false || strpos($errorMessage, 'Too many requests') !== false) {
            return 'Too many requests. Please wait and try again.';
        } elseif (strpos($errorMessage, 'HTTP 5') !== false || strpos($errorMessage, 'Server error') !== false) {
            return 'Server error. Please try again later.';
        } elseif (strpos($errorMessage, 'HTTP 4') !== false) {
            return 'Request error. Please check your input and try again.';
        }

        // Return original message if it doesn't match known patterns
        return $errorMessage;
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
    private $sent_context;

    public function __construct($conversation_id, $model, $llm_service, $sent_context = null)
    {
        $this->conversation_id = $conversation_id;
        $this->model = $model;
        $this->llm_service = $llm_service;
        $this->start_time = microtime(true);
        $this->sent_context = $sent_context;
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
    public function finalize($tokens_used, $raw_response = null, $reasoning = null)
    {
        $this->llm_service->addMessage(
            $this->conversation_id,
            'assistant',
            $this->buffer,
            null,
            $this->model,
            $tokens_used,
            $raw_response,
            $this->sent_context,
            $reasoning
        );
    }

    /**
     * Emergency save on error
     */
    public function emergencySave($error_message, $raw_response = null, $reasoning = null)
    {
        $emergency_content = $this->buffer . "\n\n[Streaming interrupted: {$error_message}]";

        $this->llm_service->addMessage(
            $this->conversation_id,
            'assistant',
            $emergency_content,
            null,
            $this->model,
            null,
            $raw_response,
            $this->sent_context,
            $reasoning
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
