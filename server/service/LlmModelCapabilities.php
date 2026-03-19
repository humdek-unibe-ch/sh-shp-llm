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
            $content = $message['content'];
            // Multimodal content arrays can't be string-concatenated
            if (is_array($content)) {
                array_unshift($content, ['type' => 'text', 'text' => "[SYSTEM INSTRUCTION]\n"]);
                return ['role' => 'user', 'content' => $content];
            }
            return [
                'role' => 'user',
                'content' => "[SYSTEM INSTRUCTION]\n" . $content
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
                        'content' => self::mergeUserContent($pendingUserContent)
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
                'content' => self::mergeUserContent($pendingUserContent)
            ];
        }

        return $converted;
    }

    /* =========================================================================
     * FILE & MODEL UTILITY METHODS
     *
     * Consolidated from former global functions in globals.php.
     * Call via LlmModelCapabilities::isVisionModel($m), etc.
     * The old global functions still exist as thin wrappers for BC.
     * ========================================================================= */

    /**
     * Get the file type category based on extension
     *
     * @param string $extension File extension (without dot)
     * @return string File type category constant (LLM_FILE_TYPE_*)
     */
    public static function getFileTypeCategory($extension)
    {
        $extension = strtolower($extension);
        if (in_array($extension, LLM_ALLOWED_IMAGE_EXTENSIONS)) {
            return LLM_FILE_TYPE_IMAGE;
        }
        if (in_array($extension, LLM_ALLOWED_DOCUMENT_EXTENSIONS)) {
            return LLM_FILE_TYPE_DOCUMENT;
        }
        if (in_array($extension, LLM_ALLOWED_CODE_EXTENSIONS)) {
            return LLM_FILE_TYPE_CODE;
        }
        return LLM_FILE_TYPE_DOCUMENT;
    }

    /**
     * Check if a model supports vision/image processing
     *
     * @param string $model Model identifier
     * @return bool
     */
    public static function isVisionModel($model)
    {
        return in_array($model, LLM_VISION_MODELS);
    }

    /**
     * Check if a model is an embedding model (not suitable for chat)
     *
     * @param string $model Model identifier
     * @return bool
     */
    public static function isEmbeddingModel($model)
    {
        $lower = strtolower($model);
        foreach (LLM_EMBEDDING_MODEL_PATTERNS as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a model is a reranker model (not suitable for chat)
     *
     * @param string $model Model identifier
     * @return bool
     */
    public static function isRerankerModel($model)
    {
        $lower = strtolower($model);
        foreach (LLM_RERANKER_MODEL_PATTERNS as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a model is a non-chat model (audio, embedding, or reranker)
     *
     * @param string $model Model identifier
     * @return bool
     */
    public static function isNonChatModel($model)
    {
        return self::isEmbeddingModel($model)
            || self::isRerankerModel($model)
            || in_array($model, LLM_AUDIO_MODELS);
    }

    /**
     * Get model capabilities based on model identifier
     *
     * @param string $model Model identifier
     * @return array Array of capability constants
     */
    public static function getModelCapabilities($model)
    {
        $capabilities = [LLM_CAPABILITY_TEXT];

        if (self::isVisionModel($model)) {
            $capabilities[] = LLM_CAPABILITY_VISION;
        }
        if (strpos($model, 'coder') !== false || strpos($model, 'code') !== false) {
            $capabilities[] = LLM_CAPABILITY_CODE;
        }
        if (strpos($model, 'deepseek-r1') !== false || strpos($model, 'reasoning') !== false) {
            $capabilities[] = LLM_CAPABILITY_REASONING;
        }

        return $capabilities;
    }

    /**
     * Check if a model has a specific capability
     *
     * @param string $model Model identifier
     * @param string $capability Capability constant (LLM_CAPABILITY_*)
     * @return bool
     */
    public static function modelHasCapability($model, $capability)
    {
        return in_array($capability, self::getModelCapabilities($model));
    }

    /**
     * Validate MIME type against allowed types for extension
     *
     * @param string $extension File extension (without dot)
     * @param string $mimeType  Detected MIME type
     * @return bool
     */
    public static function validateMimeType($extension, $mimeType)
    {
        $extension = strtolower($extension);
        if (!isset(LLM_ALLOWED_MIME_TYPES[$extension])) {
            return false;
        }
        return in_array($mimeType, LLM_ALLOWED_MIME_TYPES[$extension]);
    }

    /* =========================================================================
     * INTERNAL HELPERS (message conversion)
     * ========================================================================= */

    /**
     * Merge an array of user content items that may be strings or multimodal arrays.
     * If all items are strings, joins with separator.
     * If any item is a multimodal array, builds a combined content-parts array.
     *
     * @param array $items Array of string or array content items
     * @return string|array Merged content
     */
    private static function mergeUserContent(array $items)
    {
        $hasArray = false;
        foreach ($items as $item) {
            if (is_array($item)) {
                $hasArray = true;
                break;
            }
        }

        if (!$hasArray) {
            return implode("\n\n---\n\n", $items);
        }

        // Build a single content-parts array
        $parts = [];
        foreach ($items as $i => $item) {
            if ($i > 0) {
                $parts[] = ['type' => 'text', 'text' => "\n\n---\n\n"];
            }
            if (is_array($item)) {
                foreach ($item as $part) {
                    $parts[] = $part;
                }
            } else {
                $parts[] = ['type' => 'text', 'text' => $item];
            }
        }
        return $parts;
    }
}
?>
