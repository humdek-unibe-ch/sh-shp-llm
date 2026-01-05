<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmResponseService.php';

/**
 * LLM Context Service
 * 
 * Handles context building for LLM API calls.
 * Centralizes the logic for:
 * - Building context messages from configuration
 * - Applying unified response schema (always JSON output with safety detection)
 * - Applying form mode context
 * - Applying floating mode context
 * - Applying strict conversation mode context
 * 
 * All responses use the unified JSON schema from LlmResponseService.
 * Safety detection is handled by the LLM via the schema's safety field.
 */
class LlmContextService
{
    private $model;
    private $form_mode_service;
    private $floating_mode_service;
    private $strict_conversation_service;
    private $api_formatter_service;
    private $response_service;
    private $progress_tracking_service;

    /**
     * Constructor
     * 
     * @param LlmChatModel $model The model instance for configuration
     * @param LlmFormModeService $form_mode_service Form mode service
     * @param LlmFloatingModeService $floating_mode_service Floating mode service
     * @param LlmStrictConversationService $strict_conversation_service Strict mode service
     * @param LlmApiFormatterService $api_formatter_service API formatter service
     * @param LlmProgressTrackingService $progress_tracking_service Progress tracking service (optional)
     */
    public function __construct(
        $model,
        $form_mode_service,
        $floating_mode_service,
        $strict_conversation_service,
        $api_formatter_service,
        $progress_tracking_service = null
    ) {
        $this->model = $model;
        $this->form_mode_service = $form_mode_service;
        $this->floating_mode_service = $floating_mode_service;
        $this->strict_conversation_service = $strict_conversation_service;
        $this->api_formatter_service = $api_formatter_service;
        $this->progress_tracking_service = $progress_tracking_service;
        
        // Initialize unified response service
        $this->response_service = new LlmResponseService($model);
    }

    /**
     * Build the complete context messages based on configuration
     *
     * Priority order:
     * 1. Unified response schema with safety detection (ALWAYS applied)
     * 2. Language-specific context adaptation
     * 3. Floating mode - if floating button is enabled
     * 4. Strict conversation mode - if enabled and has context
     * 5. Form mode context - if enabled
     * 6. Basic context - the parsed conversation context
     *
     * @param int|null $conversation_id Optional conversation ID for progress tracking
     * @param int|null $section_id Optional section ID for progress tracking
     * @return array Context messages array
     */
    public function buildContextMessages($conversation_id = null, $section_id = null)
    {
        // Get base context from model configuration
        $context_messages = $this->model->getParsedConversationContext();

        // Apply language-specific context adaptation
        $context_messages = $this->applyLanguageContext($context_messages);

        // Build progress data if progress tracking is enabled
        $include_progress = $this->model->isProgressTrackingEnabled();
        $progress_data = [];
        if ($include_progress && $this->progress_tracking_service && $conversation_id && $section_id) {
            $progress_data = $this->buildProgressData($conversation_id, $section_id);
        }

        // Build danger detection config
        $danger_config = $this->buildDangerConfig();

        // ALWAYS apply unified response schema with safety detection
        // This is now mandatory - all responses must be structured JSON
        $context_messages = $this->response_service->buildResponseContext(
            $context_messages,
            $include_progress,
            $progress_data,
            $danger_config
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

        if ($this->model->isFormModeEnabled()) {
            return $this->form_mode_service->buildFormModeContext($context_messages);
        }

        return $context_messages;
    }

    /**
     * Build danger detection configuration
     *
     * Returns the danger detection settings from the model configuration
     * to be passed to the response service for LLM-based safety detection.
     *
     * @return array Danger config with 'enabled' and 'keywords' keys
     */
    private function buildDangerConfig()
    {
        // Check if model has danger detection methods
        if (!method_exists($this->model, 'isDangerDetectionEnabled')) {
            return ['enabled' => false, 'keywords' => []];
        }

        $enabled = $this->model->isDangerDetectionEnabled();
        if (!$enabled) {
            return ['enabled' => false, 'keywords' => []];
        }

        $keywords_str = $this->model->getDangerKeywords();
        if (empty($keywords_str)) {
            return ['enabled' => true, 'keywords' => []];
        }

        // Parse comma-separated keywords
        $keywords = array_map('trim', explode(',', $keywords_str));
        $keywords = array_filter($keywords);
        $keywords = array_unique($keywords);

        return [
            'enabled' => true,
            'keywords' => array_values($keywords)
        ];
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

