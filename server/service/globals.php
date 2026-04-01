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
define('LLM_MEMORY_PAGE_KEYWORD', 'moduleLlmMemory');
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

// LLM Memory constants
define('ACTION_JOB_TYPE_LLM_MEMORY_UPDATE', 'llm_memory_update');
define('TRANSACTION_BY_LLM_MEMORY', 'by_llm_memory');
define('LLM_MEMORY_DEFAULT_KEY', 'global');
define('LLM_MEMORY_DEFAULT_STORAGE_MODE', 'both');
define('LLM_MEMORY_DEFAULT_TABLE', 'llm_memory');
define('LLM_MEMORY_DEFAULT_HISTORY_TABLE', 'llm_memory_history');
define('LLM_PROMPT_OWNER_MEMORY_RULE', 'llm_memory_rule');
define('LLM_MEMORY_EXECUTION_DIRECT_MAPPING', 'direct_mapping');
define('LLM_MEMORY_EXECUTION_LLM_SUMMARIZE', 'llm_summarize');
define('LLM_MEMORY_SOURCE_FORM_ACTION', 'form_action_submit');
define('LLM_MEMORY_SOURCE_LLM_CHAT_FORM', 'llm_chat_form_submit');
define('LLM_MEMORY_SOURCE_LOGIN', 'login');
define('LLM_MEMORY_SOURCE_PROFILE_NAME', 'profile_name_change');
define('LLM_MEMORY_STATUS_APPLIED', 'applied');
define('LLM_MEMORY_STATUS_DUPLICATE', 'ignored_duplicate');
define('LLM_MEMORY_STATUS_STALE', 'ignored_stale');
define('LLM_MEMORY_STATUS_FAILED', 'failed');

/* =========================================================================
 * BACKWARD-COMPATIBLE WRAPPERS
 *
 * Real logic lives in LlmModelCapabilities (static utility class).
 * These thin global functions exist only for backward compatibility;
 * new code should call LlmModelCapabilities::method() directly.
 * ========================================================================= */

require_once __DIR__ . '/LlmModelCapabilities.php';

/** @deprecated Use LlmModelCapabilities::getFileTypeCategory() */
function llm_get_file_type_category($extension) {
    return LlmModelCapabilities::getFileTypeCategory($extension);
}

/** @deprecated Use LlmModelCapabilities::isVisionModel() */
function llm_is_vision_model($model) {
    return LlmModelCapabilities::isVisionModel($model);
}

/** @deprecated Use LlmModelCapabilities::isEmbeddingModel() */
function llm_is_embedding_model($model) {
    return LlmModelCapabilities::isEmbeddingModel($model);
}

/** @deprecated Use LlmModelCapabilities::isRerankerModel() */
function llm_is_reranker_model($model) {
    return LlmModelCapabilities::isRerankerModel($model);
}

/** @deprecated Use LlmModelCapabilities::isNonChatModel() */
function llm_is_non_chat_model($model) {
    return LlmModelCapabilities::isNonChatModel($model);
}

/** @deprecated Use LlmModelCapabilities::getModelCapabilities() */
function llm_get_model_capabilities($model) {
    return LlmModelCapabilities::getModelCapabilities($model);
}

/** @deprecated Use LlmModelCapabilities::modelHasCapability() */
function llm_model_has_capability($model, $capability) {
    return LlmModelCapabilities::modelHasCapability($model, $capability);
}

/** @deprecated Use LlmModelCapabilities::validateMimeType() */
function llm_validate_mime_type($extension, $mimeType) {
    return LlmModelCapabilities::validateMimeType($extension, $mimeType);
}

?>
