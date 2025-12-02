-- add plugin entry in the plugin table
INSERT IGNORE INTO plugins (`name`, version)
VALUES ('llm', 'v1.0.0');

-- add page type sh_module_llm for configuration
INSERT IGNORE INTO `pageType` (`name`) VALUES ('sh_module_llm');

-- add LLM configuration fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_base_url', get_field_type_id('text'), '0'),
(NULL, 'llm_api_key', get_field_type_id('password'), '0'),
(NULL, 'llm_default_model', get_field_type_id('text'), '0'),
(NULL, 'llm_timeout', get_field_type_id('number'), '0'),
(NULL, 'llm_max_tokens', get_field_type_id('number'), '0'),
(NULL, 'llm_temperature', get_field_type_id('number'), '0'),
(NULL, 'llm_streaming_enabled', get_field_type_id('checkbox'), '0');

-- link fields to page type
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('title'), '', 'Page title'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_base_url'), 'http://localhost:8080', 'gpustack API endpoint URL'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_api_key'), '', 'API key for LLM service authentication'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_default_model'), 'qwen3-vl-8b-instruct', 'Default LLM model to use'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_timeout'), '30', 'Request timeout in seconds'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_max_tokens'), '2048', 'Maximum tokens per response'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_temperature'), '0.7', 'Response randomness (0.0-1.0)'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_streaming_enabled'), '1', 'Enable real-time response streaming');

-- set variables for parent pages
SET @id_page_modules = (SELECT id FROM pages WHERE keyword = 'sh_modules');
SET @id_page_admin = 0000000009; -- admin-link page ID

-- add page for LLM configuration (admin only)
INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'sh_module_llm', '/admin/module_llm', 'GET|POST', (SELECT id FROM actions WHERE `name` = 'backend'), NULL, @id_page_modules, 0, 200, NULL, (SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), (SELECT id FROM lookups WHERE type_code = "pageAccessTypes" AND lookup_code = "mobile_and_web"));

-- get the inserted page ID
SET @id_page_llm_config = (SELECT id FROM pages WHERE keyword = 'sh_module_llm');

-- set default values for LLM configuration fields
INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
(@id_page_llm_config, get_field_id('llm_base_url'), 'http://localhost:8080', 'gpustack API endpoint URL'),
(@id_page_llm_config, get_field_id('llm_api_key'), '', 'API key for LLM service authentication'),
(@id_page_llm_config, get_field_id('llm_default_model'), 'qwen3-vl-8b-instruct', 'Default LLM model to use'),
(@id_page_llm_config, get_field_id('llm_timeout'), '30', 'Request timeout in seconds'),
(@id_page_llm_config, get_field_id('llm_max_tokens'), '2048', 'Maximum tokens per response'),
(@id_page_llm_config, get_field_id('llm_temperature'), '0.7', 'Response randomness (0.0-1.0)'),
(@id_page_llm_config, get_field_id('llm_streaming_enabled'), '1', 'Enable real-time response streaming');

-- add translation for LLM config page
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES (@id_page_llm_config, get_field_id('title'), '0000000001', 'LLM Configuration'),
       (@id_page_llm_config, get_field_id('title'), '0000000002', 'LLM Konfiguration');

-- add admin permissions
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), @id_page_llm_config, '1', '0', '1', '0');

-- add LLM chat style
INSERT IGNORE INTO `styles` (`name`, `id_type`, id_group, description)
VALUES ('llmChat', (SELECT id FROM styleType WHERE `name` = 'component'), (select id from styleGroup where `name` = 'Form'), 'LLM Chat component for real-time conversations');

-- add new field type `select-llm-model` for LLM model selection
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-llm-model', '7');

-- add LLM chat style fields (internal - configurable in CMS)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'conversation_limit', get_field_type_id('number'), '0'),
(NULL, 'message_limit', get_field_type_id('number'), '0'),
(NULL, 'llm_model', get_field_type_id('select-llm-model'), '0'),
(NULL, 'llm_temperature', get_field_type_id('number'), '0'),
(NULL, 'llm_max_tokens', get_field_type_id('number'), '0'),
(NULL, 'llm_streaming_enabled', get_field_type_id('checkbox'), '0');

-- add LLM chat style fields (external - user visible labels)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'submit_button_label', get_field_type_id('text'), '1'),
(NULL, 'new_chat_button_label', get_field_type_id('text'), '1'),
(NULL, 'delete_chat_button_label', get_field_type_id('text'), '1'),
(NULL, 'chat_description', get_field_type_id('markdown-inline'), '1'),
(NULL, 'conversations_heading', get_field_type_id('text'), '1'),
(NULL, 'no_conversations_message', get_field_type_id('text'), '1'),
(NULL, 'select_conversation_heading', get_field_type_id('text'), '1'),
(NULL, 'select_conversation_description', get_field_type_id('text'), '1'),
(NULL, 'model_label_prefix', get_field_type_id('text'), '1'),
(NULL, 'no_messages_message', get_field_type_id('text'), '1'),
(NULL, 'tokens_used_suffix', get_field_type_id('text'), '1'),
(NULL, 'loading_text', get_field_type_id('text'), '1'),
(NULL, 'ai_thinking_text', get_field_type_id('text'), '1'),
(NULL, 'upload_image_label', get_field_type_id('text'), '1'),
(NULL, 'upload_help_text', get_field_type_id('text'), '1'),
(NULL, 'message_placeholder', get_field_type_id('text'), '1'),
(NULL, 'clear_button_label', get_field_type_id('text'), '1');

-- link fields to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('css'), NULL, 'Allows to assign CSS classes to the root item of the style.'),
(get_style_id('llmChat'), get_field_id('css_mobile'), NULL, 'Allows to assign CSS classes to the root item of the style for the mobile version.'),
(get_style_id('llmChat'), get_field_id('conversation_limit'), '20', 'Number of recent conversations to show in sidebar'),
(get_style_id('llmChat'), get_field_id('message_limit'), '100', 'Number of messages to load per conversation'),
(get_style_id('llmChat'), get_field_id('llm_model'), '', 'Select AI model from dropdown. Admin can configure multiple llmChat components with different models if needed.'),
(get_style_id('llmChat'), get_field_id('llm_temperature'), '0.7', 'Response randomness (0.0-1.0)'),
(get_style_id('llmChat'), get_field_id('llm_max_tokens'), '2048', 'Maximum tokens per response'),
(get_style_id('llmChat'), get_field_id('llm_streaming_enabled'), '1', 'Enable real-time streaming responses'),
(get_style_id('llmChat'), get_field_id('submit_button_label'), 'Send Message', 'Text for the send message button'),
(get_style_id('llmChat'), get_field_id('new_chat_button_label'), 'New Conversation', 'Text for the new conversation button'),
(get_style_id('llmChat'), get_field_id('delete_chat_button_label'), 'Delete Chat', 'Text for the delete conversation button'),
(get_style_id('llmChat'), get_field_id('chat_description'), 'Chat with AI assistant', 'Description text shown above the chat interface'),
(get_style_id('llmChat'), get_field_id('conversations_heading'), 'Conversations', 'Heading for the conversations sidebar'),
(get_style_id('llmChat'), get_field_id('no_conversations_message'), 'No conversations yet. Start a new chat!', 'Message shown when no conversations exist'),
(get_style_id('llmChat'), get_field_id('select_conversation_heading'), 'Select a conversation or start a new one', 'Heading shown when no conversation is selected'),
(get_style_id('llmChat'), get_field_id('select_conversation_description'), 'Choose from the sidebar or click "New Conversation" to begin chatting with AI.', 'Description shown when no conversation is selected'),
(get_style_id('llmChat'), get_field_id('model_label_prefix'), 'Model: ', 'Prefix text for model display in conversation header'),
(get_style_id('llmChat'), get_field_id('no_messages_message'), 'No messages yet. Send your first message!', 'Message shown when conversation has no messages'),
(get_style_id('llmChat'), get_field_id('tokens_used_suffix'), ' tokens', 'Suffix text for token count display'),
(get_style_id('llmChat'), get_field_id('loading_text'), 'Loading...', 'Text for screen readers during loading'),
(get_style_id('llmChat'), get_field_id('ai_thinking_text'), 'AI is thinking...', 'Text shown during AI response streaming'),
(get_style_id('llmChat'), get_field_id('upload_image_label'), 'Upload Image (Vision Models)', 'Label for image upload field'),
(get_style_id('llmChat'), get_field_id('upload_help_text'), 'Supported formats: JPG, PNG, GIF, WebP (max 10MB)', 'Help text for image upload'),
(get_style_id('llmChat'), get_field_id('message_placeholder'), 'Type your message here...', 'Placeholder text for message input'),
(get_style_id('llmChat'), get_field_id('clear_button_label'), 'Clear', 'Text for the clear message button');

-- create LLM conversations table
CREATE TABLE IF NOT EXISTS `llmConversations` (
    `id` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_users` int(10) UNSIGNED ZEROFILL NOT NULL,
    `title` varchar(255) DEFAULT 'New Conversation',
    `model` varchar(100) NOT NULL,
    `temperature` decimal(3,2) DEFAULT 0.7,
    `max_tokens` int DEFAULT 2048,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_created` (`id_users`, `created_at`),
    KEY `idx_user_updated` (`id_users`, `updated_at`),
    CONSTRAINT `fk_llmConversations_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- create LLM messages table
CREATE TABLE IF NOT EXISTS `llmMessages` (
    `id` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` int(10) UNSIGNED ZEROFILL NOT NULL,
    `role` enum('user','assistant','system') NOT NULL,
    `content` longtext NOT NULL,
    `image_path` varchar(500) DEFAULT NULL,
    `model` varchar(100) DEFAULT NULL,
    `tokens_used` int DEFAULT NULL,
    `timestamp` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation_time` (`id_llmConversations`, `timestamp`),
    CONSTRAINT `fk_llmMessages_llmConversations` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- add transaction logging for LLM plugin
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('transactionBy', 'by_llm_plugin', 'By LLM Plugin', 'Actions performed by the LLM plugin');

-- register hooks for LLM model field selection
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-model-edit', 'Output select LLM Model field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmModelEdit');

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-model-view', 'Output select LLM Model field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmModelView');

-- add admin pages for LLM management
INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'admin_llm_conversations', '/admin/llm/conversations', 'GET', (SELECT id FROM actions WHERE `name` = 'component'), NULL, @id_page_admin, 0, NULL, NULL, (SELECT id FROM pageType WHERE `name` = 'intern'), (SELECT id FROM lookups WHERE type_code = "pageAccessTypes" AND lookup_code = "mobile_and_web"));

-- get the inserted page IDs
SET @id_page_llm_conversations = (SELECT id FROM pages WHERE keyword = 'admin_llm_conversations');

INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'admin_llm_conversation', '/admin/llm/conversation/[i:id]', 'GET', (SELECT id FROM actions WHERE `name` = 'component'), NULL, @id_page_admin, 0, NULL, NULL, (SELECT id FROM pageType WHERE `name` = 'intern'), (SELECT id FROM lookups WHERE type_code = "pageAccessTypes" AND lookup_code = "mobile_and_web"));

SET @id_page_llm_conversation = (SELECT id FROM pages WHERE keyword = 'admin_llm_conversation');

-- add admin permissions for LLM pages
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), @id_page_llm_conversations, '1', '0', '0', '0'),
       ((SELECT id FROM `groups` WHERE `name` = 'admin'), @id_page_llm_conversation, '1', '0', '0', '0');

-- add page translations
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES (@id_page_llm_config, get_field_id('llm_base_url'), '0000000001', 'http://localhost:8080'),
       (@id_page_llm_config, get_field_id('llm_api_key'), '0000000001', ''),
       (@id_page_llm_config, get_field_id('llm_default_model'), '0000000001', 'qwen3-vl-8b-instruct'),
       (@id_page_llm_config, get_field_id('llm_timeout'), '0000000001', '30'),
       (@id_page_llm_config, get_field_id('llm_max_tokens'), '0000000001', '2048'),
       (@id_page_llm_config, get_field_id('llm_temperature'), '0000000001', '0.7'),
       (@id_page_llm_config, get_field_id('llm_streaming_enabled'), '0000000001', '1'),
       (@id_page_llm_conversations, get_field_id('title'), '0000000001', 'LLM Conversations'),
       (@id_page_llm_conversations, get_field_id('title'), '0000000002', 'LLM Konversationen'),
       (@id_page_llm_conversation, get_field_id('title'), '0000000001', 'LLM Conversation Details'),
       (@id_page_llm_conversation, get_field_id('title'), '0000000002', 'LLM Konversation Details');

-- Note: Field translations for style fields are handled per-instance when the style is added to sections
-- No global translations needed for style field defaults
