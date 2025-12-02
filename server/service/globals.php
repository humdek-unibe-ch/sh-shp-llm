<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

// LLM Plugin Constants
define('LLM_PLUGIN_NAME', 'sh-shp-llm');
define('LLM_PLUGIN_VERSION', 'v1.0.0');

// Upload directories
define('LLM_UPLOAD_FOLDER', 'upload/llm');

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
define('LLM_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

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

// UI labels
define('LLM_DEFAULT_SUBMIT_LABEL', 'Send Message');
define('LLM_DEFAULT_NEW_CHAT_LABEL', 'New Conversation');
define('LLM_DEFAULT_DELETE_LABEL', 'Delete Chat');
define('LLM_DEFAULT_MODEL_LABEL', 'AI Model');

// Admin page keywords
define('PAGE_LLM_CONFIG', 'sh_module_llm');
define('PAGE_LLM_ADMIN_CONVERSATIONS', 'admin_llm_conversations');
define('PAGE_LLM_ADMIN_MESSAGES', 'admin_llm_messages');

?>
