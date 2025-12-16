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
 * - Applying form mode context
 * - Applying floating mode context
 * - Applying strict conversation mode context
 * 
 * The service determines which context mode to apply based on
 * the model configuration (priority: form mode > floating mode > strict mode).
 */
class LlmContextService
{
    private $model;
    private $form_mode_service;
    private $floating_mode_service;
    private $strict_conversation_service;
    private $api_formatter_service;

    /**
     * Constructor
     * 
     * @param LlmchatModel $model The model instance for configuration
     * @param LlmFormModeService $form_mode_service Form mode service
     * @param LlmFloatingModeService $floating_mode_service Floating mode service
     * @param StrictConversationService $strict_conversation_service Strict mode service
     * @param LlmApiFormatterService $api_formatter_service API formatter service
     */
    public function __construct(
        $model,
        $form_mode_service,
        $floating_mode_service,
        $strict_conversation_service,
        $api_formatter_service
    ) {
        $this->model = $model;
        $this->form_mode_service = $form_mode_service;
        $this->floating_mode_service = $floating_mode_service;
        $this->strict_conversation_service = $strict_conversation_service;
        $this->api_formatter_service = $api_formatter_service;
    }

    /**
     * Build the complete context messages based on configuration
     * 
     * Priority order:
     * 1. Form mode (highest) - if enabled
     * 2. Floating mode - if floating button is enabled
     * 3. Strict conversation mode - if enabled and has context
     * 4. Basic context - just the parsed conversation context
     * 
     * @return array Context messages array
     */
    public function buildContextMessages()
    {
        // Get base context from model configuration
        $context_messages = $this->model->getParsedConversationContext();

        // Apply context based on mode priority
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
     * Build API-ready messages array
     * 
     * Combines context messages with conversation messages and formats
     * them for the LLM API call.
     * 
     * @param array $conversation_messages Messages from the conversation
     * @return array Messages formatted for API
     */
    public function buildApiMessages($conversation_messages)
    {
        $context_messages = $this->buildContextMessages();
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

