-- add plugin entry in the plugin table
INSERT IGNORE INTO plugins (`name`, version)
VALUES ('llm', 'v1.0.0');

-- Uncomment the following line to upgrade the database to utf8mb4,
-- which is required for storing emojis and other extended Unicode characters:
-- ALTER DATABASE sb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


-- add page type sh_module_llm for configuration
INSERT IGNORE INTO `pageType` (`name`) VALUES ('sh_module_llm');

-- add LLM configuration fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_base_url', get_field_type_id('text'), '0'),
(NULL, 'llm_api_key', get_field_type_id('password'), '0'),
(NULL, 'llm_default_model', get_field_type_id('select-llm-model'), '0'),
(NULL, 'llm_timeout', get_field_type_id('number'), '0'),
(NULL, 'llm_max_tokens', get_field_type_id('number'), '0'),
(NULL, 'llm_temperature', get_field_type_id('text'), '0'),
(NULL, 'llm_streaming_enabled', get_field_type_id('checkbox'), '0');

-- link fields to page type
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('title'), '', 'Page title'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_base_url'), 'https://gpustack.unibe.ch/v1', 'gpustack API endpoint URL'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_api_key'), '', 'API key for LLM service authentication'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_default_model'), 'qwen3-vl-8b-instruct', 'Default LLM model to use'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_timeout'), '30', 'Request timeout in seconds'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_max_tokens'), '2048', 'The maximum number of tokens to generate. The total length of input tokens and generated tokens is limited by the models context length.'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_temperature'), '1', 'Controls randomness (0-2): Lowering results in less random completions. As the temperature approaches zero, the model will become deterministic and repetitive.'),
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
(@id_page_llm_config, get_field_id('llm_base_url'), 'https://gpustack.unibe.ch/v1', 'gpustack API endpoint URL'),
(@id_page_llm_config, get_field_id('llm_api_key'), '', 'API key for LLM service authentication'),
(@id_page_llm_config, get_field_id('llm_default_model'), 'qwen3-vl-8b-instruct', 'Default LLM model to use'),
(@id_page_llm_config, get_field_id('llm_timeout'), '30', 'Request timeout in seconds'),
(@id_page_llm_config, get_field_id('llm_max_tokens'), '2048', 'The maximum number of tokens to generate. The total length of input tokens and generated tokens is limited by the models context length.'),
(@id_page_llm_config, get_field_id('llm_temperature'), '1', 'Controls randomness (0-2): Lowering results in less random completions. As the temperature approaches zero, the model will become deterministic and repetitive.'),
(@id_page_llm_config, get_field_id('llm_streaming_enabled'), '1', 'Enable real-time response streaming');

-- add translation for LLM config page
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES (@id_page_llm_config, get_field_id('title'), '0000000003', 'LLM Configuration'),
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
(NULL, 'llm_temperature', get_field_type_id('text'), '0'),
(NULL, 'llm_max_tokens', get_field_type_id('number'), '0'),
(NULL, 'llm_streaming_enabled', get_field_type_id('checkbox'), '0'),
(NULL, 'enable_conversations_list', get_field_type_id('checkbox'), '0'),
(NULL, 'enable_file_uploads', get_field_type_id('checkbox'), '0'),
(NULL, 'enable_full_page_reload', get_field_type_id('checkbox'), '0');

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
(NULL, 'clear_button_label', get_field_type_id('text'), '1'),
(NULL, 'new_conversation_title_label', get_field_type_id('text'), '1'),
(NULL, 'conversation_title_label', get_field_type_id('text'), '1'),
(NULL, 'conversation_name', get_field_type_id('text'), '1'),
(NULL, 'cancel_button_label', get_field_type_id('text'), '1'),
(NULL, 'create_button_label', get_field_type_id('text'), '1'),
(NULL, 'delete_confirmation_title', get_field_type_id('text'), '1'),
(NULL, 'delete_confirmation_message', get_field_type_id('text'), '1'),
(NULL, 'confirm_delete_button_label', get_field_type_id('text'), '1'),
(NULL, 'cancel_delete_button_label', get_field_type_id('text'), '1'),
(NULL, 'empty_message_error', get_field_type_id('text'), '1'),
(NULL, 'streaming_active_error', get_field_type_id('text'), '1'),
(NULL, 'default_chat_title', get_field_type_id('text'), '1'),
(NULL, 'delete_button_title', get_field_type_id('text'), '1'),
(NULL, 'conversation_title_placeholder', get_field_type_id('text'), '1'),
(NULL, 'single_file_attached_text', get_field_type_id('text'), '1'),
(NULL, 'multiple_files_attached_text', get_field_type_id('text'), '1'),
(NULL, 'empty_state_title', get_field_type_id('text'), '1'),
(NULL, 'empty_state_description', get_field_type_id('text'), '1'),
(NULL, 'loading_messages_text', get_field_type_id('text'), '1'),
(NULL, 'streaming_in_progress_placeholder', get_field_type_id('text'), '1'),
(NULL, 'attach_files_title', get_field_type_id('text'), '1'),
(NULL, 'no_vision_support_title', get_field_type_id('text'), '1'),
(NULL, 'no_vision_support_text', get_field_type_id('text'), '1'),
(NULL, 'send_message_title', get_field_type_id('text'), '1'),
(NULL, 'remove_file_title', get_field_type_id('text'), '1');

-- link fields to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('css'), NULL, 'Allows to assign CSS classes to the root item of the style.'),
(get_style_id('llmChat'), get_field_id('css_mobile'), NULL, 'Allows to assign CSS classes to the root item of the style for the mobile version.'),
(get_style_id('llmChat'), get_field_id('conversation_limit'), '20', 'Number of recent conversations to show in sidebar'),
(get_style_id('llmChat'), get_field_id('message_limit'), '100', 'Number of messages to load per conversation'),
(get_style_id('llmChat'), get_field_id('llm_model'), '', 'Select AI model from dropdown. Admin can configure multiple llmChat components with different models if needed.'),
(get_style_id('llmChat'), get_field_id('llm_temperature'), '1', 'Controls randomness (0-2): Lowering results in less random completions. As the temperature approaches zero, the model will become deterministic and repetitive.'),
(get_style_id('llmChat'), get_field_id('llm_max_tokens'), '2048', 'The maximum number of tokens to generate. The total length of input tokens and generated tokens is limited by the models context length.'),
(get_style_id('llmChat'), get_field_id('llm_streaming_enabled'), '1', 'Enable real-time streaming responses'),
(get_style_id('llmChat'), get_field_id('enable_conversations_list'), '0', 'Enable conversations list on the left side. When disabled, only one conversation is allowed.'),
(get_style_id('llmChat'), get_field_id('enable_file_uploads'), '0', 'Enable file upload functionality. When enabled, users can attach files to their messages. File types accepted depend on the selected AI model.'),
(get_style_id('llmChat'), get_field_id('enable_full_page_reload'), '0', 'When enabled, full page reloads after streaming completion instead of React component refresh. Use this if you need to reload other page elements after chat interaction.'),
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
(get_style_id('llmChat'), get_field_id('clear_button_label'), 'Clear', 'Text for the clear message button'),
(get_style_id('llmChat'), get_field_id('new_conversation_title_label'), 'New Conversation', 'Title for the new conversation modal'),
(get_style_id('llmChat'), get_field_id('conversation_title_label'), 'Conversation Title (optional)', 'Label for the conversation title input field'),
(get_style_id('llmChat'), get_field_id('conversation_name'), 'My Chat', 'Name for this conversation instance, shown in the interface'),
(get_style_id('llmChat'), get_field_id('cancel_button_label'), 'Cancel', 'Text for the cancel button in modals'),
(get_style_id('llmChat'), get_field_id('create_button_label'), 'Create Conversation', 'Text for the create conversation button'),
(get_style_id('llmChat'), get_field_id('delete_confirmation_title'), 'Delete Conversation', 'Title for the delete confirmation modal'),
(get_style_id('llmChat'), get_field_id('delete_confirmation_message'), 'Are you sure you want to delete this conversation? This action cannot be undone.', 'Message shown in the delete confirmation modal'),
(get_style_id('llmChat'), get_field_id('confirm_delete_button_label'), 'Delete', 'Text for the confirm delete button'),
(get_style_id('llmChat'), get_field_id('cancel_delete_button_label'), 'Cancel', 'Text for the cancel delete button'),
(get_style_id('llmChat'), get_field_id('empty_message_error'), 'Please enter a message', 'Error message when user tries to send empty message'),
(get_style_id('llmChat'), get_field_id('streaming_active_error'), 'Please wait for the current response to complete', 'Error message when streaming is active'),
(get_style_id('llmChat'), get_field_id('default_chat_title'), 'AI Chat', 'Default title for conversations'),
(get_style_id('llmChat'), get_field_id('delete_button_title'), 'Delete conversation', 'Tooltip/title for delete conversation button'),
(get_style_id('llmChat'), get_field_id('conversation_title_placeholder'), 'Enter conversation title (optional)', 'Placeholder text for conversation title input'),
(get_style_id('llmChat'), get_field_id('single_file_attached_text'), '1 file attached', 'Text shown when single file is attached'),
(get_style_id('llmChat'), get_field_id('multiple_files_attached_text'), '{count} files attached', 'Text shown when multiple files are attached (use {count} placeholder)'),
(get_style_id('llmChat'), get_field_id('empty_state_title'), 'Start a conversation', 'Title shown when no messages exist'),
(get_style_id('llmChat'), get_field_id('empty_state_description'), 'Send a message to start chatting with the AI assistant.', 'Description shown when no messages exist'),
(get_style_id('llmChat'), get_field_id('loading_messages_text'), 'Loading messages...', 'Text shown while loading messages'),
(get_style_id('llmChat'), get_field_id('streaming_in_progress_placeholder'), 'Streaming in progress...', 'Placeholder text when streaming is active'),
(get_style_id('llmChat'), get_field_id('attach_files_title'), 'Attach files', 'Tooltip/title for attach files button'),
(get_style_id('llmChat'), get_field_id('no_vision_support_title'), 'Current model does not support image uploads', 'Tooltip when vision model is not selected'),
(get_style_id('llmChat'), get_field_id('no_vision_support_text'), 'No vision', 'Text shown when vision model is not selected'),
(get_style_id('llmChat'), get_field_id('send_message_title'), 'Send message', 'Tooltip/title for send message button'),
(get_style_id('llmChat'), get_field_id('remove_file_title'), 'Remove file', 'Tooltip/title for remove file button');

-- Add conversation context field (user visible, translatable, supports markdown/JSON)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'conversation_context', get_field_type_id('markdown'), '1');

-- Link conversation_context to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('conversation_context'), '', 'System context/instructions sent to AI at the start of each conversation. Supports markdown or JSON format.\n\n**Markdown format:**\n```\nYou are a helpful assistant...\n```\n\n**JSON format (for multiple system messages):**\n```json\n[{"role": "system", "content": "You are..."}]\n```\n\nThis context defines how the AI should behave and what information it has access to. The context is tracked with each message for debugging purposes.');

-- Add auto-start conversation fields (user visible, translatable)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'auto_start_conversation', get_field_type_id('checkbox'), '0'),
(NULL, 'auto_start_message', get_field_type_id('markdown'), '1');

-- Link auto-start fields to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('auto_start_conversation'), '0', 'Automatically start a conversation when no active conversation exists. The AI will analyze the conversation context and send an intelligent, topic-specific initial message to engage the user.'),
(get_style_id('llmChat'), get_field_id('auto_start_message'), 'Hello! I''m here to help you. What would you like to talk about?', 'Fallback message used when auto-starting conversations. When conversation context is configured, the system automatically generates a more engaging, context-aware message based on the topics and themes in your context. This field serves as a fallback for generic greetings.');

-- Add strict conversation mode field (user visible, translatable)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'strict_conversation_mode', get_field_type_id('checkbox'), '0');

-- Link strict conversation mode to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('strict_conversation_mode'), '0', 'When enabled, the AI will only respond to questions and topics that fit within the defined conversation context. If users ask about unrelated topics, the AI will politely redirect them back to the appropriate context with a brief description of what topics are available.');

-- Add form mode field (user visible, translatable)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'enable_form_mode', get_field_type_id('checkbox'), '0');

-- Link form mode to llmchat style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('enable_form_mode'), '0', 'When enabled, the LLM returns only JSON Schema-formatted forms instead of text responses. Text input is disabled and users interact exclusively through rendered form controls (radio buttons, checkboxes, dropdowns). Form submissions are displayed as readable user messages.');

-- create LLM conversations table
CREATE TABLE IF NOT EXISTS `llmConversations` (
    `id` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_users` int(10) UNSIGNED ZEROFILL NOT NULL,
    `id_sections` int(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `title` varchar(255) DEFAULT 'New Conversation',
    `model` varchar(100) NOT NULL,
    `temperature` decimal(3,2) DEFAULT 1,
    `max_tokens` int DEFAULT 2048,
    `deleted` TINYINT(1) DEFAULT 0 NOT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_created` (`id_users`, `created_at`),
    KEY `idx_user_updated` (`id_users`, `updated_at`),
    KEY `idx_section` (`id_sections`),
    KEY `idx_deleted` (`deleted`),
    CONSTRAINT `fk_llmConversations_users` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE,
CONSTRAINT `fk_llmConversations_sections` FOREIGN KEY (`id_sections`) REFERENCES `sections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- create LLM messages table - Industry Standard Schema
-- NOTE: Removed is_streaming and last_chunk_at fields - no longer needed with event-driven streaming
CREATE TABLE IF NOT EXISTS `llmMessages` (
    `id` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmConversations` int(10) UNSIGNED ZEROFILL NOT NULL,
    `role` enum('user','assistant','system') NOT NULL,
    `content` longtext NOT NULL,
    `attachments` longtext DEFAULT NULL, -- JSON array of attachment metadata
    `model` varchar(100) DEFAULT NULL,
    `tokens_used` int DEFAULT NULL,
    `raw_response` longtext DEFAULT NULL, -- Raw API response data (JSON)
    `sent_context` longtext DEFAULT NULL, -- JSON snapshot of context sent with this message for debugging/audit
    `deleted` TINYINT(1) DEFAULT 0 NOT NULL,
    `timestamp` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conversation_time` (`id_llmConversations`, `timestamp`),
    KEY `idx_deleted` (`deleted`),
CONSTRAINT `fk_llmMessages_llmConversations` FOREIGN KEY (`id_llmConversations`) REFERENCES `llmConversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- add transaction logging for LLM plugin
INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('transactionBy', 'by_llm_plugin', 'By LLM Plugin', 'Actions performed by the LLM plugin');

-- register hooks for LLM model field selection
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-model-edit', 'Output select LLM Model field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldLlmModelEdit');

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-model-view', 'Output select LLM Model field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldLlmModelView');


-- add page type for admin page
INSERT IGNORE INTO pageType (`name`) VALUES ('sh_llm_admin');

-- add LLM admin console configuration fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'admin_page_size', get_field_type_id('number'), '0'),
(NULL, 'admin_refresh_interval', get_field_type_id('number'), '0'),
(NULL, 'admin_default_view', get_field_type_id('select'), '0'),
(NULL, 'admin_show_filters', get_field_type_id('checkbox'), '0');

-- link admin console fields to page type
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_llm_admin'), get_field_id('title'), 'LLM Admin Console', 'Page title'),
((SELECT id FROM pageType WHERE `name` = 'sh_llm_admin'), get_field_id('admin_page_size'), '50', 'Number of conversations/messages to display per page'),
((SELECT id FROM pageType WHERE `name` = 'sh_llm_admin'), get_field_id('admin_refresh_interval'), '300', 'Auto-refresh interval in seconds (0 = disabled)'),
((SELECT id FROM pageType WHERE `name` = 'sh_llm_admin'), get_field_id('admin_default_view'), 'conversations', 'Default view mode: conversations or messages'),
((SELECT id FROM pageType WHERE `name` = 'sh_llm_admin'), get_field_id('admin_show_filters'), '1', 'Show filter panel by default');

-- add panel field for quick links
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES (NULL, 'llm_panel', get_field_type_id('panel'), '0');
INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`)
VALUES ((SELECT id FROM pageType WHERE `name` = 'sh_module_llm' LIMIT 1), get_field_id('llm_panel'), NULL, 'LLM panel with quick admin links');

-- register hooks for LLM panel
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES (
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 1),
    'field-llm_panel-edit',
    'Output LLM panel in edit mode',
    'CmsView',
    'create_field_form_item',
    'LlmHooks',
    'outputFieldPanel'
);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`)
VALUES (
    (SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 1),
    'field-llm_panel-view',
    'Output LLM panel in view mode',
    'CmsView',
    'create_field_item',
    'LlmHooks',
    'outputFieldPanel'
);


-- create admin page for conversations/messages
SET @id_page_modules = (SELECT id FROM pages WHERE keyword = 'sh_modules');

INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (
    NULL,
    'moduleLlmAdminConsole',
    '/admin/module_llm/conversations',
    'GET|POST',
    (SELECT id FROM actions WHERE `name` = 'component' LIMIT 1),
    NULL,
    @id_page_modules,
    0,
    NULL,
    NULL,
    (SELECT id FROM pageType WHERE `name` = 'sh_llm_admin' LIMIT 1),
    (SELECT id FROM lookups WHERE type_code = "pageAccessTypes" AND lookup_code = "mobile_and_web")
);

SET @id_page_llm_admin = (SELECT id FROM pages WHERE keyword = 'moduleLlmAdminConsole');

-- set default values for admin console page fields
INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
(@id_page_llm_admin, get_field_id('admin_page_size'), '50', 'Number of conversations/messages to display per page'),
(@id_page_llm_admin, get_field_id('admin_refresh_interval'), '300', 'Auto-refresh interval in seconds (0 = disabled)'),
(@id_page_llm_admin, get_field_id('admin_default_view'), 'conversations', 'Default view mode: conversations or messages'),
(@id_page_llm_admin, get_field_id('admin_show_filters'), '1', 'Show filter panel by default');

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES
(@id_page_llm_admin, get_field_id('title'), '0000000003', 'LLM Conversations'),
(@id_page_llm_admin, get_field_id('title'), '0000000002', 'LLM Konversationen'),
(@id_page_llm_admin, get_field_id('admin_page_size'), '0000000001', '50'),
(@id_page_llm_admin, get_field_id('admin_refresh_interval'), '0000000001', '300'),
(@id_page_llm_admin, get_field_id('admin_default_view'), '0000000001', 'conversations'),
(@id_page_llm_admin, get_field_id('admin_show_filters'), '0000000001', '1');

INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`)
VALUES ((SELECT id FROM `groups` WHERE `name` = 'admin'), @id_page_llm_admin, '1', '0', '0', '0');


-- add page translations
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`)
VALUES (@id_page_llm_config, get_field_id('llm_base_url'), '0000000001', 'https://gpustack.unibe.ch/v1'),
       (@id_page_llm_config, get_field_id('llm_api_key'), '0000000001', ''),
       (@id_page_llm_config, get_field_id('llm_default_model'), '0000000001', 'qwen3-vl-8b-instruct'),
       (@id_page_llm_config, get_field_id('llm_timeout'), '0000000001', '30'),
       (@id_page_llm_config, get_field_id('llm_max_tokens'), '0000000001', '2048'),
       (@id_page_llm_config, get_field_id('llm_temperature'), '0000000001', '1'),
       (@id_page_llm_config, get_field_id('llm_streaming_enabled'), '0000000001', '1');