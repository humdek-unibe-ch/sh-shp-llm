<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Model Capabilities Utility
 * 
 * Defines which models support which message roles and provides
 * utility functions to convert messages for model compatibility.
 * 
 * Role Support:
 * - Some models support: system, user, assistant (full support)
 * - Some models support: user, assistant only (no system role)
 * 
 * When a model doesn't support the system role, system messages
 * are converted to user messages with a special prefix to maintain
 * the instructional intent.
 */
class LlmModelCapabilities
{
    /**
     * Models that support full role set (system + user + assistant)
     * These models can receive system instructions as a separate role
     */
    const MODELS_WITH_SYSTEM_ROLE = [
        'gpt-oss-120b',
        'qwen3-coder-30b-a3b-instruct',
        'qwen3-vl-8b-instruct',
        'deepseek-r1-0528-qwen3-8b',
        'apertus-8b-instruct-2509',
        'minimax-m2',
    ];

    /**
     * Models that only support user + assistant roles (no system)
     * System messages will be converted to user messages for these
     */
    const MODELS_WITHOUT_SYSTEM_ROLE = [
        'internvl3-8b-instruct',
        'medgemma-4b-it',
        'olmocr-2-7b-1025-fp8',
    ];

    /**
     * Non-chat models (embedding, reranker, speech-to-text)
     * These don't use the chat completion format at all
     */
    const NON_CHAT_MODELS = [
        'bge-m3',
        'qwen3-embedding-0.6b',
        'jina-reranker-v2-base-multilingual',
        'granite-embedding-107m-multilingual',
        'faster-whisper-large-v3',
    ];

    /**
     * Check if a model supports the system role
     * 
     * @param string $model Model identifier
     * @return bool True if model supports system role
     */
    public static function supportsSystemRole($model)
    {
        // If explicitly listed as not supporting system role
        if (in_array($model, self::MODELS_WITHOUT_SYSTEM_ROLE)) {
            return false;
        }
        
        // If explicitly listed as supporting system role
        if (in_array($model, self::MODELS_WITH_SYSTEM_ROLE)) {
            return true;
        }
        
        // Default: assume system role is NOT supported for safety
        // This ensures we don't break unknown models
        return false;
    }

    /**
     * Check if a model is a chat model (vs embedding/reranker/speech)
     * 
     * @param string $model Model identifier
     * @return bool True if model uses chat completion format
     */
    public static function isChatModel($model)
    {
        return !in_array($model, self::NON_CHAT_MODELS);
    }

    /**
     * Get the appropriate role for a system message based on model
     * 
     * @param string $model Model identifier
     * @return string 'system' if supported, 'user' otherwise
     */
    public static function getSystemRoleForModel($model)
    {
        return self::supportsSystemRole($model) ? 'system' : 'user';
    }

    /**
     * Convert a single message for model compatibility
     * 
     * If the model doesn't support system role, converts system messages
     * to user messages with a prefix indicating they are instructions.
     * 
     * @param array $message Message with 'role' and 'content' keys
     * @param string $model Model identifier
     * @return array Converted message
     */
    public static function convertMessageForModel($message, $model)
    {
        if (!isset($message['role']) || !isset($message['content'])) {
            return $message;
        }

        // If it's a system message and model doesn't support system role
        if ($message['role'] === 'system' && !self::supportsSystemRole($model)) {
            return [
                'role' => 'user',
                'content' => "[SYSTEM INSTRUCTION]\n" . $message['content']
            ];
        }

        return $message;
    }

    /**
     * Convert an array of messages for model compatibility
     * 
     * Processes all messages and converts system messages to user messages
     * if the model doesn't support the system role. Also handles merging
     * consecutive user messages to avoid API errors.
     * 
     * @param array $messages Array of messages
     * @param string $model Model identifier
     * @return array Converted messages array
     */
    public static function convertMessagesForModel($messages, $model)
    {
        if (empty($messages) || !is_array($messages)) {
            return $messages;
        }

        // If model supports system role, return as-is
        if (self::supportsSystemRole($model)) {
            return $messages;
        }

        $converted = [];
        $lastRole = null;
        $pendingUserContent = [];

        foreach ($messages as $message) {
            $convertedMessage = self::convertMessageForModel($message, $model);
            $currentRole = $convertedMessage['role'];
            $currentContent = $convertedMessage['content'];

            // If current is user and last was also user, merge them
            if ($currentRole === 'user' && $lastRole === 'user') {
                $pendingUserContent[] = $currentContent;
            } else {
                // Flush any pending user content
                if (!empty($pendingUserContent)) {
                    $converted[] = [
                        'role' => 'user',
                        'content' => implode("\n\n---\n\n", $pendingUserContent)
                    ];
                    $pendingUserContent = [];
                }

                if ($currentRole === 'user') {
                    $pendingUserContent[] = $currentContent;
                } else {
                    $converted[] = $convertedMessage;
                }
            }

            $lastRole = $currentRole;
        }

        // Flush any remaining pending user content
        if (!empty($pendingUserContent)) {
            $converted[] = [
                'role' => 'user',
                'content' => implode("\n\n---\n\n", $pendingUserContent)
            ];
        }

        return $converted;
    }

    /**
     * Build a context message with the appropriate role for the model
     * 
     * Helper function for services that need to create system-like messages.
     * 
     * @param string $content Message content
     * @param string $model Model identifier
     * @return array Message array with appropriate role
     */
    public static function buildContextMessage($content, $model)
    {
        $role = self::getSystemRoleForModel($model);
        
        if ($role === 'user') {
            return [
                'role' => 'user',
                'content' => "[SYSTEM INSTRUCTION]\n" . $content
            ];
        }

        return [
            'role' => 'system',
            'content' => $content
        ];
    }

    /**
     * Get list of all supported chat models
     * 
     * @return array List of model identifiers
     */
    public static function getAllChatModels()
    {
        return array_merge(
            self::MODELS_WITH_SYSTEM_ROLE,
            self::MODELS_WITHOUT_SYSTEM_ROLE
        );
    }

    /**
     * Get model capability information for debugging
     * 
     * @param string $model Model identifier
     * @return array Capability information
     */
    public static function getModelInfo($model)
    {
        return [
            'model' => $model,
            'is_chat_model' => self::isChatModel($model),
            'supports_system_role' => self::supportsSystemRole($model),
            'system_role_maps_to' => self::getSystemRoleForModel($model),
            'category' => self::getModelCategory($model)
        ];
    }

    /**
     * Get the category of a model
     * 
     * @param string $model Model identifier
     * @return string Category: 'full_support', 'no_system', 'non_chat', or 'unknown'
     */
    private static function getModelCategory($model)
    {
        if (in_array($model, self::MODELS_WITH_SYSTEM_ROLE)) {
            return 'full_support';
        }
        if (in_array($model, self::MODELS_WITHOUT_SYSTEM_ROLE)) {
            return 'no_system';
        }
        if (in_array($model, self::NON_CHAT_MODELS)) {
            return 'non_chat';
        }
        return 'unknown';
    }
}
?>
