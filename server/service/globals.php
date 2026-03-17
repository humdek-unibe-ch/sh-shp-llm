<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

// LLM Admin Page Keyword
define('LLM_ADMIN_PAGE_KEYWORD', 'moduleLlmAdminConsole');

// Upload directories - relative to plugin root
define('LLM_UPLOAD_FOLDER', 'upload');

// Rate limiting
define('LLM_RATE_LIMIT_REQUESTS_PER_MINUTE', 10);
define('LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS', 3);
define('LLM_RATE_LIMIT_COOLDOWN_SECONDS', 60);

// Default values
define('LLM_DEFAULT_MODEL', 'qwen3-vl-8b-instruct');
define('LLM_DEFAULT_TEMPERATURE', 1);
define('LLM_DEFAULT_MAX_TOKENS', 2048);
define('LLM_DEFAULT_TIMEOUT', 30);
define('LLM_DEFAULT_CONVERSATION_LIMIT', 20);
define('LLM_DEFAULT_MESSAGE_LIMIT', 100);

// API endpoints
define('LLM_API_CHAT_COMPLETIONS', '/chat/completions');
define('LLM_API_MODELS', '/models');

// Transaction logging
define('TRANSACTION_BY_LLM_PLUGIN', 'by_llm_plugin');
define('TRANSACTION_BY_LLM_SCRIPT', 'by_llm_script');

// LLM Script constants
define('ACTION_JOB_TYPE_LLM_SCRIPT', 'llm_script');
define('LLM_TABLE_SCRIPTS', 'llm_scripts');
define('LLM_SCRIPTS_PAGE_KEYWORD', 'moduleLlmScript');
define('LLM_PROMPT_LAB_PAGE_KEYWORD', 'ajax_llm_prompt_lab');
define('LLM_PROMPT_OWNER_STYLE_FIELD', 'style_field');
define('LLM_PROMPT_OWNER_SCRIPT', 'llm_script');
define('LLM_PROMPT_RUN_MODE_PLAYGROUND', 'playground');
define('LLM_PROMPT_RUN_MODE_BUILDER', 'builder');
define('LLM_PROMPT_RUN_MODE_COMPARE', 'compare');
define('LLM_PROMPT_META_KEY', 'prompt');
define('LLM_PROMPT_RUN_MODE_DATASET_EVAL', 'dataset_eval');

define('LLM_EVAL_TYPE_PROGRAMMATIC', 'programmatic');
define('LLM_EVAL_TYPE_LLM_JUDGE', 'llm_judge');
define('LLM_EVAL_TYPE_HUMAN_REVIEW', 'human_review');

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

// Speech-to-text (Whisper) models
// Models capable of transcribing audio to text
define('LLM_AUDIO_MODELS', [
    'faster-whisper-large-v3',
    'whisper-large-v3',
    'whisper-medium',
    'whisper-small'
]);

// Embedding model name patterns (case-insensitive substring match)
// These models generate vector embeddings, not chat completions
define('LLM_EMBEDDING_MODEL_PATTERNS', [
    'embed',
    'embedding',
    'bge-',
    'e5-',
    'gte-',
    'nomic-embed',
    'jina-embed',
    'sentence-transformer',
    'instructor-',
    'text-embedding',
    'paraphrase-',
]);

// Reranker model name patterns (case-insensitive substring match)
// These models rerank search results, not generate text
define('LLM_RERANKER_MODEL_PATTERNS', [
    'rerank',
    'reranker',
    'cross-encoder',
    'jina-reranker',
    'bge-reranker',
]);

// Maximum audio file size (25MB - OpenAI API limit)
define('LLM_MAX_AUDIO_SIZE', 25 * 1024 * 1024);

// Cache keys
define('LLM_CACHE_USER_CONVERSATIONS', 'llm_user_conversations');
define('LLM_CACHE_CONVERSATION_MESSAGES', 'llm_conversation_messages');
define('LLM_CACHE_RATE_LIMIT', 'llm_rate_limit');

// Model capability flags
define('LLM_CAPABILITY_VISION', 'vision'); // Can process images
define('LLM_CAPABILITY_TEXT', 'text'); // Can process text
define('LLM_CAPABILITY_CODE', 'code'); // Good at code generation
define('LLM_CAPABILITY_REASONING', 'reasoning'); // Advanced reasoning capabilities

// UI labels
define('LLM_DEFAULT_SUBMIT_LABEL', 'Send Message');
define('LLM_DEFAULT_NEW_CHAT_LABEL', 'New Conversation');

// Admin page keywords
define('PAGE_LLM_CONFIG', 'sh_module_llm');

// LLM Form constants
define('TRANSACTION_BY_LLM_FORM', 'by_llm_form');
define('LLM_FORM_DEFAULT_RESULT_FIELD', 'llm_result');
define('LLM_FORM_DEFAULT_META_FIELD', 'llm_result_meta');

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
 * Check if a model is an embedding model (not suitable for chat)
 *
 * @param string $model Model identifier
 * @return bool True if model is an embedding model
 */
function llm_is_embedding_model($model) {
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
 * @return bool True if model is a reranker model
 */
function llm_is_reranker_model($model) {
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
 * @return bool True if model should be excluded from chat model lists
 */
function llm_is_non_chat_model($model) {
    return llm_is_embedding_model($model)
        || llm_is_reranker_model($model)
        || in_array($model, LLM_AUDIO_MODELS);
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
