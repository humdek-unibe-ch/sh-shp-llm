<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

// LLM Admin Page Keyword
define('LLM_ADMIN_PAGE_KEYWORD', 'moduleLlmAdminConsole');

// LLM Plugin Constants
define('LLM_PLUGIN_NAME', 'sh-shp-llm');
define('LLM_PLUGIN_VERSION', 'v1.0.0');

// Transaction by lookup code
define('TRANSACTION_BY_LLM_PLUGIN', 'by_llm_plugin');

// Upload directories - relative to plugin root
define('LLM_UPLOAD_FOLDER', 'upload');

// Rate limiting
define('LLM_RATE_LIMIT_REQUESTS_PER_MINUTE', 10);
define('LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS', 3);
define('LLM_RATE_LIMIT_COOLDOWN_SECONDS', 60);

// Default values
define('LLM_DEFAULT_MODEL', 'qwen3-vl-8b-instruct');
define('LLM_DEFAULT_TEMPERATURE', 0.7);
define('LLM_DEFAULT_MAX_TOKENS', 2048);
define('LLM_DEFAULT_TIMEOUT', 30);
define('LLM_DEFAULT_CONVERSATION_LIMIT', 20);
define('LLM_DEFAULT_MESSAGE_LIMIT', 100);
define('LLM_ADMIN_DEFAULT_PAGE_SIZE', 25);

// API endpoints
define('LLM_API_CHAT_COMPLETIONS', '/chat/completions');
define('LLM_API_MODELS', '/models');

// SSE streaming
define('LLM_SSE_RETRY_MS', 3000);
define('LLM_SSE_HEARTBEAT_INTERVAL', 30);

// Transaction logging
define('TRANSACTION_BY_LLM_PLUGIN', 'by_llm_plugin');

// File upload limits
define('LLM_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('LLM_MAX_FILES_PER_MESSAGE', 5); // Maximum files per message

// Allowed file extensions by category
define('LLM_ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('LLM_ALLOWED_DOCUMENT_EXTENSIONS', ['pdf', 'txt', 'md', 'csv', 'json', 'xml']);
define('LLM_ALLOWED_CODE_EXTENSIONS', ['py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml']);
define('LLM_ALLOWED_EXTENSIONS', array_merge(
    LLM_ALLOWED_IMAGE_EXTENSIONS,
    LLM_ALLOWED_DOCUMENT_EXTENSIONS,
    LLM_ALLOWED_CODE_EXTENSIONS
));

// Allowed MIME types mapping (extension => allowed MIME types)
define('LLM_ALLOWED_MIME_TYPES', [
    // Images
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'webp' => ['image/webp'],
    // Documents
    'pdf' => ['application/pdf'],
    'txt' => ['text/plain'],
    'md' => ['text/plain', 'text/markdown'],
    'csv' => ['text/csv', 'text/plain', 'application/csv'],
    'json' => ['application/json', 'text/plain'],
    'xml' => ['application/xml', 'text/xml', 'text/plain'],
    // Code files
    'py' => ['text/x-python', 'text/plain', 'application/x-python-code'],
    'js' => ['application/javascript', 'text/javascript', 'text/plain'],
    'php' => ['application/x-php', 'text/x-php', 'text/plain'],
    'html' => ['text/html', 'text/plain'],
    'css' => ['text/css', 'text/plain'],
    'sql' => ['application/sql', 'text/plain', 'text/x-sql'],
    'sh' => ['application/x-sh', 'text/x-shellscript', 'text/plain'],
    'yaml' => ['application/x-yaml', 'text/yaml', 'text/plain'],
    'yml' => ['application/x-yaml', 'text/yaml', 'text/plain'],
]);

// File type categories for UI display
define('LLM_FILE_TYPE_IMAGE', 'image');
define('LLM_FILE_TYPE_DOCUMENT', 'document');
define('LLM_FILE_TYPE_CODE', 'code');

// Vision-capable models that can process images
// Add any model that supports image/vision inputs
define('LLM_VISION_MODELS', [
    'internvl3-8b-instruct', 
    'qwen3-vl-8b-instruct', 
]);

// Cache keys
define('LLM_CACHE_USER_CONVERSATIONS', 'llm_user_conversations');
define('LLM_CACHE_CONVERSATION_MESSAGES', 'llm_conversation_messages');
define('LLM_CACHE_RATE_LIMIT', 'llm_rate_limit');

// Supported model types
define('LLM_MODEL_TYPE_TEXT', 'text');
define('LLM_MODEL_TYPE_VISION', 'vision');
define('LLM_MODEL_TYPE_EMBEDDING', 'embedding');
define('LLM_MODEL_TYPE_RERANKER', 'reranker');
define('LLM_MODEL_TYPE_SPEECH', 'speech');
define('LLM_MODEL_TYPE_MULTIMODAL', 'multimodal'); // Text + Vision combined

// Model capability flags
define('LLM_CAPABILITY_VISION', 'vision'); // Can process images
define('LLM_CAPABILITY_TEXT', 'text'); // Can process text
define('LLM_CAPABILITY_CODE', 'code'); // Good at code generation
define('LLM_CAPABILITY_REASONING', 'reasoning'); // Advanced reasoning capabilities

// UI labels
define('LLM_DEFAULT_SUBMIT_LABEL', 'Send Message');
define('LLM_DEFAULT_NEW_CHAT_LABEL', 'New Conversation');
define('LLM_DEFAULT_DELETE_LABEL', 'Delete Chat');
define('LLM_DEFAULT_MODEL_LABEL', 'AI Model');

// File upload error codes
define('LLM_UPLOAD_ERROR_SIZE', 'file_too_large');
define('LLM_UPLOAD_ERROR_TYPE', 'invalid_file_type');
define('LLM_UPLOAD_ERROR_MIME', 'invalid_mime_type');
define('LLM_UPLOAD_ERROR_DUPLICATE', 'duplicate_file');
define('LLM_UPLOAD_ERROR_MAX_FILES', 'max_files_exceeded');
define('LLM_UPLOAD_ERROR_MOVE_FAILED', 'move_failed');
define('LLM_UPLOAD_ERROR_DIRECTORY', 'directory_creation_failed');

// Admin page keywords
define('PAGE_LLM_CONFIG', 'sh_module_llm');
define('PAGE_LLM_ADMIN_CONVERSATIONS', 'admin_llm_conversations');
define('PAGE_LLM_ADMIN_MESSAGES', 'admin_llm_messages');

/**
 * Get the file type category based on extension
 *
 * @param string $extension File extension (without dot)
 * @return string File type category constant
 */
function llm_get_file_type_category($extension) {
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
    return LLM_FILE_TYPE_DOCUMENT; // Default fallback
}

/**
 * Check if a model supports vision/image processing
 *
 * @param string $model Model identifier
 * @return bool True if model supports vision
 */
function llm_is_vision_model($model) {
    return in_array($model, LLM_VISION_MODELS);
}

/**
 * Get model capabilities based on model identifier
 *
 * @param string $model Model identifier
 * @return array Array of capability constants
 */
function llm_get_model_capabilities($model) {
    $capabilities = [LLM_CAPABILITY_TEXT]; // All models can handle text

    if (llm_is_vision_model($model)) {
        $capabilities[] = LLM_CAPABILITY_VISION;
    }

    // Add code capability for coding models
    if (strpos($model, 'coder') !== false || strpos($model, 'code') !== false) {
        $capabilities[] = LLM_CAPABILITY_CODE;
    }

    // Add reasoning capability for advanced models
    if (strpos($model, 'deepseek-r1') !== false || strpos($model, 'reasoning') !== false) {
        $capabilities[] = LLM_CAPABILITY_REASONING;
    }

    return $capabilities;
}

/**
 * Check if a model has a specific capability
 *
 * @param string $model Model identifier
 * @param string $capability Capability constant
 * @return bool True if model has the capability
 */
function llm_model_has_capability($model, $capability) {
    $capabilities = llm_get_model_capabilities($model);
    return in_array($capability, $capabilities);
}

/**
 * Validate MIME type against allowed types for extension
 *
 * @param string $extension File extension (without dot)
 * @param string $mimeType Detected MIME type
 * @return bool True if MIME type is valid for extension
 */
function llm_validate_mime_type($extension, $mimeType) {
    $extension = strtolower($extension);
    if (!isset(LLM_ALLOWED_MIME_TYPES[$extension])) {
        return false;
    }
    return in_array($mimeType, LLM_ALLOWED_MIME_TYPES[$extension]);
}

?>
