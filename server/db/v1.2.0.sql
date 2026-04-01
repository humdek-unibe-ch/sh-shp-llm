-- =====================================================
-- LLM Plugin v1.2.0 - Global User Memory Redesign
-- =====================================================
-- Dedicated memory page, normalized memory rules, and
-- module-level defaults on sh_module_llm.
-- =====================================================

START TRANSACTION;

UPDATE plugins SET version = 'v1.2.0' WHERE `name` = 'llm';

-- =====================================================
-- LOOKUPS
-- =====================================================

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmMemoryStorageMode', 'memory_storage_record', 'record', 'Write only to current memory table (fast reads, no history)'),
('llmMemoryStorageMode', 'memory_storage_log', 'log', 'Append only to history table (effective memory resolved as latest valid row)'),
('llmMemoryStorageMode', 'memory_storage_both', 'both', 'Update current memory table and append history row (recommended default)');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmMemorySourceType', 'form_action_submit', 'form_action_submit', 'Triggered by form action after UserInput::save_data()'),
('llmMemorySourceType', 'llm_chat_form_submit', 'llm_chat_form_submit', 'Triggered by llmChat form submission when data saving is disabled'),
('llmMemorySourceType', 'login', 'login', 'Triggered after successful user login'),
('llmMemorySourceType', 'profile_name_change', 'profile_name_change', 'Triggered after successful user profile name change'),
('llmMemorySourceType', 'admin_manual', 'admin_manual', 'Triggered by manual admin memory action');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmMemoryUpdateStatus', 'memory_status_applied', 'applied', 'Memory update was applied successfully'),
('llmMemoryUpdateStatus', 'memory_status_ignored_duplicate', 'ignored_duplicate', 'Memory update ignored due to duplicate dedupe key'),
('llmMemoryUpdateStatus', 'memory_status_ignored_stale', 'ignored_stale', 'Memory update ignored due to stale event ordering'),
('llmMemoryUpdateStatus', 'memory_status_failed', 'failed', 'Memory update failed (LLM or validation error)');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES
('llmMemoryExecutionMode', 'memory_exec_direct_mapping', 'direct_mapping', 'Direct field-to-memory mapping without LLM call'),
('llmMemoryExecutionMode', 'memory_exec_llm_summarize', 'llm_summarize', 'LLM-based summarization and extraction');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('transactionBy', 'by_llm_memory', 'By LLM Memory', 'Actions performed by LLM memory update system');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('llm_prompt_owner_types', 'llm_memory_rule', 'llm_memory_rule', 'Prompt owner is a normalized memory rule');

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('llm_eval_execution_profiles', 'memory_runtime', 'memory_runtime', 'Memory rule runtime profile');

-- =====================================================
-- FIELD TYPES / MODULE DEFAULTS
-- =====================================================

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-llm-memory-storage-mode', '13');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_memory_enabled', get_field_type_id('checkbox'), '0'),
(NULL, 'llm_memory_key', get_field_type_id('text'), '0'),
(NULL, 'llm_memory_storage_mode', get_field_type_id('select-llm-memory-storage-mode'), '0'),
(NULL, 'llm_memory_table_name', get_field_type_id('text'), '0'),
(NULL, 'llm_memory_history_table_name', get_field_type_id('text'), '0');

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_memory_enabled'), '0', 'Enable the global user memory system.'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_memory_key'), 'global', 'Default memory key.'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_memory_storage_mode'), 'memory_storage_both', 'Storage mode for memory updates.'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_memory_table_name'), 'llm_memory', 'Name of the current effective memory dataTable.'),
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_memory_history_table_name'), 'llm_memory_history', 'Name of the memory history dataTable.');

INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_memory_enabled'), '0', 'Enable global user memory system.'),
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_memory_key'), 'global', 'Default memory key.'),
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_memory_storage_mode'), 'memory_storage_both', 'Storage mode.'),
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_memory_table_name'), 'llm_memory', 'Current memory table name.'),
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_memory_history_table_name'), 'llm_memory_history', 'History memory table name.');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'memory_rule_keys', get_field_type_id('text'), '0');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES
(get_style_id('llmChat'), get_field_id('memory_rule_keys'), '', 'Comma-separated memory rule keys to trigger on form submission when data saving is disabled. Used only as a fallback when form-action triggers are not available.');

-- =====================================================
-- NORMALIZED MEMORY RULE TABLE
-- =====================================================

CREATE TABLE IF NOT EXISTS `llm_memory_rules` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `rule_key` VARCHAR(128) NOT NULL,
    `label` VARCHAR(255) DEFAULT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `memory_key` VARCHAR(128) NOT NULL DEFAULT 'global',
    `source_type` VARCHAR(64) NOT NULL,
    `source_match_json` LONGTEXT DEFAULT NULL,
    `trigger_types_json` LONGTEXT DEFAULT NULL,
    `storage_mode_override` VARCHAR(32) DEFAULT NULL,
    `execution_mode` VARCHAR(64) NOT NULL DEFAULT 'llm_summarize',
    `field_mapping_json` LONGTEXT DEFAULT NULL,
    `data_config_json` LONGTEXT DEFAULT NULL,
    `llm_model` VARCHAR(255) DEFAULT NULL,
    `llm_temperature` VARCHAR(32) DEFAULT NULL,
    `llm_max_tokens` INT DEFAULT NULL,
    `refresh_sections_json` LONGTEXT DEFAULT NULL,
    `usage_tags_json` LONGTEXT DEFAULT NULL,
    `id_users_created` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `id_users_updated` INT(10) UNSIGNED ZEROFILL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_llm_memory_rule_key` (`rule_key`),
    KEY `idx_llm_memory_rule_source_type` (`source_type`),
    KEY `idx_llm_memory_rule_enabled` (`enabled`),
    CONSTRAINT `fk_llm_memory_rules_user_created` FOREIGN KEY (`id_users_created`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_llm_memory_rules_user_updated` FOREIGN KEY (`id_users_updated`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DEDICATED MEMORY PAGE
-- =====================================================

INSERT IGNORE INTO `pages` (`id`, `keyword`, `url`, `protocol`, `id_actions`, `id_navigation_section`, `parent`, `is_headless`, `nav_position`, `footer_position`, `id_type`, `id_pageAccessTypes`)
VALUES (NULL, 'moduleLlmMemory', '/admin/llm_memory', 'GET|POST', (SELECT id FROM actions WHERE `name` = 'component' LIMIT 1), NULL, NULL, '0', NULL, NULL, '0000000001', (SELECT id FROM lookups WHERE type_code = 'pageAccessTypes' AND lookup_code = 'mobile_and_web'));
SET @id_page_llm_memory = (SELECT id FROM pages WHERE keyword = 'moduleLlmMemory');

INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_llm_memory, get_field_id('title'), '0000000001', 'LLM Memory');
INSERT IGNORE INTO `pages_fields_translation` (`id_pages`, `id_fields`, `id_languages`, `content`) VALUES (@id_page_llm_memory, get_field_id('title'), '0000000002', 'LLM Memory');
INSERT IGNORE INTO `acl_groups` (`id_groups`, `id_pages`, `acl_select`, `acl_insert`, `acl_update`, `acl_delete`) VALUES ('0000000001', @id_page_llm_memory, '1', '1', '1', '1');

-- =====================================================
-- FIELD HOOKS / JOB HOOKS
-- =====================================================

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-memory-storage-mode-edit', 'Output select LLM memory storage mode field - edit mode', 'CmsView', 'create_field_form_item', 'LlmHooks', 'outputFieldMemoryStorageModeEdit', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'field-llm-memory-storage-mode-view', 'Output select LLM memory storage mode field - view mode', 'CmsView', 'create_field_item', 'LlmHooks', 'outputFieldMemoryStorageModeView', 5);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-execute-memory-task', 'Execute LLM memory update when job_type is llm_memory_update', 'Task', 'execute_task', 'LlmHooks', 'execute_memory_task', 12);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-memory-jobConfig-schema', 'Add llm_memory_update option to jobConfig JSON schema', 'JobConfigView', 'get_json_schema', 'LlmHooks', 'get_memory_json_schema', 12);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-memory-get-task-config', 'Build task config for LLM memory update jobs', 'UserInput', 'get_task_config', 'LlmHooks', 'get_memory_task_config', 12);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-memory-get-job-type', 'Return jobTypes_task for llm_memory_update job type', 'UserInput', 'get_job_type', 'LlmHooks', 'get_memory_job_type', 12);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-memory-on-login', 'Trigger memory update after successful user login', 'Login', 'update_timestamp', 'LlmHooks', 'onLoginMemoryTrigger', 20);

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-memory-on-profile-name', 'Trigger memory update after successful profile name change', 'ProfileModel', 'change_user_name', 'LlmHooks', 'onProfileNameChangeMemoryTrigger', 20);

COMMIT;
