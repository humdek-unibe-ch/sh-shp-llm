-- =====================================================
-- LLM Plugin v1.7.0 - request traces
-- =====================================================
--
-- Requires v1.6.0 (run server/db/v1.6.0.sql first).
--
-- This migration:
--   1. Request traces (doc/request-traces.md): every LLM call stores a small,
--      hash-verified trace (llmRequestTraces + deduplicated llmTraceChunks)
--      next to its message, and the prompt versions that were in effect
--      (llmTracePromptVersions).
--   2. Adds llmMessages.trace_locked (a referenced history row is immutable)
--      and llm_prompt_entries.archived_at (prompt entries are archived, never
--      deleted); prompt locale / version deletes become RESTRICT.
--   3. Registers the `system` prompt owner type (built-in prompt assets).
--   4. Adds the `llm_trace_store_blobs` setting (default off: sent_context /
--      request_payload are written only when a trace could not be verified;
--      on = dual write).
--   5. Registers the Login::delete_user hook that removes a deleted user's
--      LLM upload files.
--   6. Bumps the plugin version LAST so a failed DDL statement can never
--      mark v1.7.0 as installed.
--
-- BREAKING: run this migration before deploying the v1.7.0 code
-- (LlmService::updateMessage reads llmMessages.trace_locked).
-- NOTE: MySQL DDL is not rolled back by the surrounding transaction; the
-- script is idempotent so re-running after a partial failure is safe.
-- NOTE: fields, lookups and hooks are cached - clear the SelfHelp cache
-- (Admin > Cache > clear all) after running this migration.
-- =====================================================

START TRANSACTION;

-- =====================================================
-- 1. Request traces
-- =====================================================
-- Every LLM call stores what was sent as an ordered recipe: a message that
-- equals a stored llmMessages row is a reference ({"m": id}), anything else
-- is text chunks. Chunks are stored once per (owner, sha256): owner 0 =
-- shared code/config text (schema, safety, mode texts), owner = id_users =
-- text that may hold participant data (deleted with the user).
-- request_hash proves a rebuild equals the request that was sent.

CREATE TABLE IF NOT EXISTS `llmRequestTraces` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `id_llmMessages` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `format_version` TINYINT UNSIGNED NOT NULL,
    `plugin_version` VARCHAR(16) NOT NULL,
    `provider` VARCHAR(32) NOT NULL,
    `params_json` TEXT NOT NULL COMMENT 'Adapted payload without messages / system',
    `recipe_json` MEDIUMTEXT NOT NULL COMMENT 'Ordered messages: {"m": id} or {"role": ..., "c": [sha256, ...]}',
    `request_hash` CHAR(64) NOT NULL,
    `verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Self-check at write time: rebuild from the DB matched request_hash',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_trace_message` (`id_llmMessages`),
    CONSTRAINT `fk_llmRequestTraces_message` FOREIGN KEY (`id_llmMessages`) REFERENCES `llmMessages` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `llmTraceChunks` (
    `id` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `owner` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = shared code/config text, else id_users',
    `id_users` INT(10) UNSIGNED ZEROFILL DEFAULT NULL COMMENT 'Set for per-user chunks so they are deleted with the user',
    `sha256` CHAR(64) NOT NULL,
    `content` LONGTEXT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_chunk_owner_sha` (`owner`, `sha256`),
    KEY `idx_chunk_user` (`id_users`),
    CONSTRAINT `fk_llmTraceChunks_user` FOREIGN KEY (`id_users`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `llmTracePromptVersions` (
    `id_llmRequestTraces` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `id_llm_prompt_versions` INT(10) UNSIGNED ZEROFILL NOT NULL,
    PRIMARY KEY (`id_llmRequestTraces`, `id_llm_prompt_versions`),
    KEY `idx_trace_prompt_version` (`id_llm_prompt_versions`),
    CONSTRAINT `fk_llmTracePromptVersions_trace` FOREIGN KEY (`id_llmRequestTraces`) REFERENCES `llmRequestTraces` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_llmTracePromptVersions_version` FOREIGN KEY (`id_llm_prompt_versions`) REFERENCES `llm_prompt_versions` (`id`) ON DELETE RESTRICT
);

CALL add_table_column('llmMessages', 'trace_locked',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a request trace references this row; content and attachments are immutable'");

CALL add_table_column('llm_prompt_entries', 'archived_at',
    "TIMESTAMP NULL DEFAULT NULL COMMENT 'Set instead of deleting: request traces keep pointing at the versions'");

-- Prompt versions must outlive their entry: deleting an entry or a locale
-- that has versions fails instead of cascading. Helpers drop the old CASCADE
-- FKs (no-op if already gone) before adding RESTRICT.
CALL drop_foreign_key('llm_prompt_versions', 'fk_llm_prompt_versions_locale');
ALTER TABLE `llm_prompt_versions` ADD CONSTRAINT `fk_llm_prompt_versions_locale`
    FOREIGN KEY (`id_llm_prompt_locales`) REFERENCES `llm_prompt_locales` (`id`) ON DELETE RESTRICT;
CALL drop_foreign_key('llm_prompt_locales', 'fk_llm_prompt_locales_entry');
ALTER TABLE `llm_prompt_locales` ADD CONSTRAINT `fk_llm_prompt_locales_entry`
    FOREIGN KEY (`id_llm_prompt_entries`) REFERENCES `llm_prompt_entries` (`id`) ON DELETE RESTRICT;

INSERT IGNORE INTO lookups (type_code, lookup_code, lookup_value, lookup_description)
VALUES ('llm_prompt_owner_types', 'system', 'system', 'Prompt owner is a built-in system prompt asset (read-only, owner_id 0, slot = asset key)');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'llm_trace_store_blobs', get_field_type_id('checkbox'), '0');

INSERT IGNORE INTO `pageType_fields` (`id_pageType`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pageType WHERE `name` = 'sh_module_llm'), get_field_id('llm_trace_store_blobs'), '0', 'Also store the full context and request payload of every LLM call next to its request trace (dual write). Off: they are stored only when the trace of a call could not be verified.');

INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pages WHERE keyword = 'sh_module_llm'), get_field_id('llm_trace_store_blobs'), '0', 'Also store the full context and request payload of every LLM call (dual write). Off: they are stored only when the trace of a call could not be verified.');

-- Deleting a user in the CMS (Login::delete_user) cascades the LLM rows; this
-- hook also removes the user's uploaded files and their LLM-ready images.
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`, `priority`)
VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return'), 'llm-delete-user-files', 'Remove the LLM uploads of a deleted user', 'Login', 'delete_user', 'LlmHooks', 'onDeleteUserLlmFiles', 20);

-- Version bump last: a failed DDL statement above must never mark v1.7.0
-- as installed (DDL commits implicitly, so ordering is the only guard).
UPDATE plugins SET version = 'v1.7.0' WHERE `name` = 'llm';

COMMIT;
