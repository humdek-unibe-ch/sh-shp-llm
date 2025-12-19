<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Context Service
 * 
 * Handles context building for LLM API calls.
 * Centralizes the logic for:
 * - Building context messages from configuration
 * - Applying structured response schema (always JSON output)
 * - Applying form mode context
 * - Applying floating mode context
 * - Applying strict conversation mode context
 * 
 * The service determines which context mode to apply based on
 * the model configuration (priority: structured response > form mode > floating mode > strict mode).
 */
class LlmContextService
{
    private $model;
    private $form_mode_service;
    private $floating_mode_service;
    private $strict_conversation_service;
    private $api_formatter_service;
    private $structured_response_service;
    private $progress_tracking_service;

    /**
     * Constructor
     * 
     * @param LlmChatModel $model The model instance for configuration
     * @param LlmFormModeService $form_mode_service Form mode service
     * @param LlmFloatingModeService $floating_mode_service Floating mode service
     * @param LlmStrictConversationService $strict_conversation_service Strict mode service
     * @param LlmApiFormatterService $api_formatter_service API formatter service
     * @param LlmStructuredResponseService $structured_response_service Structured response service
     * @param LlmProgressTrackingService $progress_tracking_service Progress tracking service (optional)
     */
    public function __construct(
        $model,
        $form_mode_service,
        $floating_mode_service,
        $strict_conversation_service,
        $api_formatter_service,
        $structured_response_service,
        $progress_tracking_service = null
    ) {
        $this->model = $model;
        $this->form_mode_service = $form_mode_service;
        $this->floating_mode_service = $floating_mode_service;
        $this->strict_conversation_service = $strict_conversation_service;
        $this->api_formatter_service = $api_formatter_service;
        $this->structured_response_service = $structured_response_service;
        $this->progress_tracking_service = $progress_tracking_service;
    }

    /**
     * Build the complete context messages based on configuration
     *
     * Priority order:
     * 1. Language-specific context adaptation (always applied first)
     * 2. Structured response mode (highest) - if enabled, ensures all responses are JSON
     * 3. Form mode - if enabled (legacy, use structured instead)
     * 4. Floating mode - if floating button is enabled
     * 5. Strict conversation mode - if enabled and has context
     * 6. Basic context - just the parsed conversation context
     *
     * @param int|null $conversation_id Optional conversation ID for progress tracking
     * @param int|null $section_id Optional section ID for progress tracking
     * @return array Context messages array
     */
    public function buildContextMessages($conversation_id = null, $section_id = null)
    {
        // Get base context from model configuration
        $context_messages = $this->model->getParsedConversationContext();

        // Apply language-specific context adaptation first
        $context_messages = $this->applyLanguageContext($context_messages);

        // Check if structured response mode is enabled (new approach)
        if ($this->model->isStructuredResponseEnabled()) {
            $include_progress = $this->model->isProgressTrackingEnabled();

            // Build progress data if progress tracking is enabled and we have the required IDs
            $progress_data = [];
            if ($include_progress && $this->progress_tracking_service && $conversation_id && $section_id) {
                $progress_data = $this->buildProgressData($conversation_id, $section_id);
            }

            $context_messages = $this->structured_response_service->buildStructuredResponseContext(
                $context_messages,
                $include_progress,
                $progress_data
            );

            // Apply additional modes on top of structured response
            if ($this->model->isFloatingButtonEnabled()) {
                return $this->floating_mode_service->buildFloatingModeContext($context_messages);
            }

            if ($this->model->shouldApplyStrictMode()) {
                return $this->strict_conversation_service->buildStrictModeContext(
                    $context_messages,
                    $this->model->getConversationContext()
                );
            }

            return $context_messages;
        }

        // Legacy: Apply context based on mode priority
        if ($this->model->isFormModeEnabled()) {
            return $this->form_mode_service->buildFormModeContext($context_messages);
        }

        if ($this->model->isFloatingButtonEnabled()) {
            return $this->floating_mode_service->buildFloatingModeContext($context_messages);
        }

        if ($this->model->shouldApplyStrictMode()) {
            return $this->strict_conversation_service->buildStrictModeContext(
                $context_messages,
                $this->model->getConversationContext()
            );
        }

        return $context_messages;
    }

    /**
     * Apply language-specific context adaptations
     *
     * Adds a separate, critical system message for language instructions at the very beginning.
     * This ensures language consistency and allows switching in form mode.
     *
     * @param array $context_messages Original context messages
     * @return array Modified context messages with language instruction as first entry
     */
    private function applyLanguageContext($context_messages)
    {
        // Get user's language preference
        $user_language = $this->model->getContextLanguage();

        require_once __DIR__ . '/LlmLanguageUtility.php';
        $language_name = LlmLanguageUtility::getLanguageName($user_language);
        $language_instruction = LlmLanguageUtility::generateLanguageInstruction($user_language);

        // Create a separate critical system message for language
        $language_context = [
            'role' => 'system',
            'content' => "CRITICAL LANGUAGE INSTRUCTION: " . $language_instruction . " Use {$language_name} and ONLY {$language_name} for all responses unless the user specifically requests to switch to a different language later in the conversation. This is your primary language rule that overrides any other instructions."
        ];

        // Add as the very first context message
        array_unshift($context_messages, $language_context);

        return $context_messages;
    }

    /**
     * Build progress data for structured response context
     *
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @return array Progress data array
     */
    private function buildProgressData($conversation_id, $section_id)
    {
        $context = $this->model->getConversationContext();
        $topics = $this->progress_tracking_service->extractTopicsFromContext($context);

        if (empty($topics)) {
            return [];
        }

        // Get confirmed topics
        $confirmed_topics = $this->progress_tracking_service->getConfirmedTopicIds($conversation_id, $section_id);

        // Get current progress
        $existing_progress = $this->progress_tracking_service->getConversationProgress($conversation_id, $section_id);
        $current_progress = $existing_progress ? (float)$existing_progress['progress_percentage'] : 0;

        // Get context language
        $context_language = $this->model->getContextLanguage();

        return [
            'topics' => $topics,
            'current_progress' => $current_progress,
            'context_language' => $context_language,
            'confirmed_topics' => $confirmed_topics
        ];
    }

    /**
     * Build API-ready messages array
     * 
     * Combines context messages with conversation messages and formats
     * them for the LLM API call.
     * 
     * @param array $conversation_messages Messages from the conversation
     * @param int|null $conversation_id Optional conversation ID for progress tracking
     * @param int|null $section_id Optional section ID for progress tracking
     * @return array Messages formatted for API
     */
    public function buildApiMessages($conversation_messages, $conversation_id = null, $section_id = null)
    {
        $context_messages = $this->buildContextMessages($conversation_id, $section_id);
        $model = $this->model->getConfiguredModel();
        
        return $this->api_formatter_service->convertToApiFormat(
            $conversation_messages,
            $model,
            $context_messages
        );
    }

    /**
     * Get the context messages for tracking/audit purposes
     * 
     * @return array Context messages
     */
    public function getContextForTracking()
    {
        return $this->buildContextMessages();
    }

    /**
     * Check if form mode is enabled
     * 
     * @return bool
     */
    public function isFormModeEnabled()
    {
        return $this->model->isFormModeEnabled();
    }

    /**
     * Check if streaming is enabled
     * 
     * @return bool
     */
    public function isStreamingEnabled()
    {
        return $this->model->isStreamingEnabled();
    }

    /**
     * Get the configured model
     * 
     * @return string
     */
    public function getConfiguredModel()
    {
        return $this->model->getConfiguredModel();
    }
}
?>

