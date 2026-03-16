<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmPromptVariableService.php';
require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/LlmService.php';

class LlmPromptRegistryService extends BaseLlmService
{
    /** @var Transaction */
    private $transaction;

    /** @var LlmPromptVariableService */
    private $variable_service;

    /** @var LlmPromptExecutionProfileService */
    private $profile_service;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->transaction = $services->get_transaction();
        $this->variable_service = new LlmPromptVariableService();
        $this->profile_service = new LlmPromptExecutionProfileService($services);
    }

    /**
     * Bootstrap prompt state for the UI, lazily creating the initial version
     * from current stored content if allowed and missing.
     *
     * @param array $descriptor
     * @param string $active_content
     * @param string|null $meta_json
     * @param bool $create_if_missing
     * @param array $runtime_values
     * @return array
     */
    public function bootstrapOwner($descriptor, $active_content = '', $meta_json = null, $create_if_missing = false, $runtime_values = array())
    {
        $entry = $this->findEntry($descriptor);
        $locale = null;
        $active_version = null;

        if (!$entry && $create_if_missing && ($active_content !== '' || !empty($runtime_values))) {
            $entry = $this->createEntry($descriptor);
        }

        if ($entry) {
            $locale = $this->findLocale($entry['id'], $descriptor['id_languages'] ?? null);
            if (!$locale && $create_if_missing) {
                $locale = $this->createLocale($entry['id'], $descriptor['id_languages'] ?? null);
            }

            if ($locale && empty($locale['active_version_id']) && $create_if_missing) {
                $sync = $this->ensureVersionSnapshot(
                    $descriptor,
                    $entry,
                    $locale,
                    $active_content,
                    $meta_json,
                    $runtime_values
                );
                $locale = $sync['locale'];
                $active_version = $sync['version'];
            } elseif ($locale && !empty($locale['active_version_id'])) {
                $active_version = $this->getVersion((int)$locale['active_version_id']);
            }
        }

        $versions = $locale ? $this->listVersions((int)$locale['id']) : array();
        $profile = $this->profile_service->resolveExecutionProfile($descriptor);
        $variables_schema = $this->getVariablesSchema($active_version, $active_content);
        $meta = $this->decodeMeta($meta_json);

        return array(
            'entry' => $entry,
            'locale' => $locale,
            'active_version' => $active_version,
            'versions' => $versions,
            'execution_profile' => $profile,
            'playground_runtime_type' => $this->profile_service->getPlaygroundRuntimeType($profile),
            'companion_field_names' => $this->profile_service->getCompanionFieldNames($profile),
            'variables_schema' => $variables_schema,
            'models' => $this->getAvailableModels(),
            'meta' => $meta
        );
    }

    /**
     * Create or update the immutable prompt version stream after a CMS or
     * script save.
     *
     * @param array $descriptor
     * @param string $active_content
     * @param string|null $meta_json
     * @param array $runtime_values
     * @return array
     */
    public function syncPromptSave($descriptor, $active_content, $meta_json = null, $runtime_values = array())
    {
        $entry = $this->findEntry($descriptor);
        if (!$entry) {
            $entry = $this->createEntry($descriptor);
        }

        $locale = $this->findLocale($entry['id'], $descriptor['id_languages'] ?? null);
        if (!$locale) {
            $locale = $this->createLocale($entry['id'], $descriptor['id_languages'] ?? null);
        }

        return $this->ensureVersionSnapshot(
            $descriptor,
            $entry,
            $locale,
            $active_content,
            $meta_json,
            $runtime_values,
            true
        );
    }

    /**
     * Sync a section field save and return the normalized field meta JSON.
     *
     * @param array $descriptor
     * @param int $field_id
     * @param string $content
     * @param string|null $meta_json
     * @param array $runtime_values
     * @return array
     */
    public function syncFieldSave($descriptor, $field_id, $content, $meta_json = null, $runtime_values = array())
    {
        $sync = $this->syncPromptSave($descriptor, $content, $meta_json, $runtime_values);
        $clean_meta = $this->buildFieldMeta(
            $meta_json,
            $sync['entry']['id'] ?? null,
            $sync['locale']['id'] ?? null,
            $sync['version']['id'] ?? null,
            $sync['locale']['active_version_no'] ?? null
        );

        return array(
            'sync' => $sync,
            'meta_json' => $clean_meta
        );
    }

    /**
     * Sync a script save and persist the registry link in llm_scripts.
     *
     * @param array $script_row
     * @param string|null $change_note
     * @param string|null $meta_json
     * @return array
     */
    public function syncScriptSave($script_row, $change_note = null, $meta_json = null)
    {
        $descriptor = array(
            'owner_type' => LLM_PROMPT_OWNER_SCRIPT,
            'owner_id' => (int)$script_row['id'],
            'prompt_slot' => 'script',
            'id_languages' => $script_row['id_languages'] ?? $this->getCurrentCmsLanguageId(),
            'title' => $script_row['name'] ?? ('Script ' . $script_row['id'])
        );

        $meta = $this->decodeMeta($meta_json);
        if (!isset($meta[LLM_PROMPT_META_KEY]) || !is_array($meta[LLM_PROMPT_META_KEY])) {
            $meta[LLM_PROMPT_META_KEY] = array();
        }
        if ($change_note !== null && $change_note !== '') {
            $meta[LLM_PROMPT_META_KEY]['pendingChangeNote'] = $change_note;
        }

        $sync = $this->syncPromptSave(
            $descriptor,
            $script_row['script'] ?? '',
            !empty($meta) ? json_encode($meta) : null,
            array(
                'name' => $script_row['name'] ?? '',
                'model' => $script_row['model'] ?? null,
                'temperature' => $script_row['temperature'] ?? null,
                'max_tokens' => $script_row['max_tokens'] ?? null,
                'data_config' => $script_row['data_config'] ?? null,
                'test_variables' => $script_row['test_variables'] ?? null
            )
        );

        if (!empty($sync['entry']['id'])) {
            $this->db->update_by_ids(
                LLM_TABLE_SCRIPTS,
                array('id_llm_prompt_entries' => $sync['entry']['id']),
                array('id' => (int)$script_row['id'])
            );
        }

        return $sync;
    }

    /**
     * List all immutable versions for a locale.
     *
     * @param int $locale_id
     * @return array
     */
    public function listVersions($locale_id)
    {
        return $this->db->query_db(
            "SELECT
                v.*,
                l.active_version_id,
                u_created.name AS created_user_name
             FROM llm_prompt_versions v
             INNER JOIN llm_prompt_locales l ON l.id = v.id_llm_prompt_locales
             LEFT JOIN users u_created ON u_created.id = v.id_users_created
             WHERE v.id_llm_prompt_locales = :locale_id
             ORDER BY v.version_no DESC",
            array(':locale_id' => $locale_id)
        );
    }

    /**
     * Get a single prompt version.
     *
     * @param int $version_id
     * @return array|null
     */
    public function getVersion($version_id)
    {
        return $this->db->query_db_first(
            "SELECT
                v.*,
                u_created.name AS created_user_name
             FROM llm_prompt_versions v
             LEFT JOIN users u_created ON u_created.id = v.id_users_created
             WHERE v.id = :id",
            array(':id' => $version_id)
        );
    }

    /**
     * Persist a fast summary row for a prompt lab run.
     *
     * @param array $payload
     * @return int|null
     */
    public function logPlaygroundRun($payload)
    {
        $run_mode = $payload['run_mode'] ?? LLM_PROMPT_RUN_MODE_PLAYGROUND;
        $lookup_id = $this->db->get_lookup_id_by_code('llm_prompt_run_modes', $run_mode);

        return $this->db->insert('llm_prompt_playground_runs', array(
            'id_llm_prompt_entries' => $payload['id_llm_prompt_entries'] ?? null,
            'id_llm_prompt_locales' => $payload['id_llm_prompt_locales'] ?? null,
            'id_llm_prompt_versions' => $payload['id_llm_prompt_versions'] ?? null,
            'id_llmConversations' => $payload['id_llmConversations'] ?? null,
            'id_llmMessages_request' => $payload['id_llmMessages_request'] ?? null,
            'id_llmMessages_response' => $payload['id_llmMessages_response'] ?? null,
            'id_lookups_run_mode' => $lookup_id ?: null,
            'comparison_group_id' => $payload['comparison_group_id'] ?? null,
            'variables_json' => $this->encodeJson($payload['variables_json'] ?? null),
            'config_snapshot_json' => $this->encodeJson($payload['config_snapshot_json'] ?? null),
            'id_users_created' => $_SESSION['id_user'] ?? null
        ));
    }

    /**
     * Return the latest CMS language from session.
     *
     * @return int
     */
    public function getCurrentCmsLanguageId()
    {
        return isset($_SESSION['cms_language']) ? (int)$_SESSION['cms_language'] : 1;
    }

    private function findEntry($descriptor)
    {
        $owner_type_id = $this->db->get_lookup_id_by_code(
            'llm_prompt_owner_types',
            $descriptor['owner_type'] ?? ''
        );

        if (!$owner_type_id) {
            return null;
        }

        return $this->db->query_db_first(
            "SELECT * FROM llm_prompt_entries
             WHERE id_llm_prompt_owner_types = :owner_type
               AND owner_id = :owner_id
               AND prompt_slot = :prompt_slot",
            array(
                ':owner_type' => $owner_type_id,
                ':owner_id' => (int)($descriptor['owner_id'] ?? 0),
                ':prompt_slot' => $descriptor['prompt_slot'] ?? ''
            )
        );
    }

    private function createEntry($descriptor)
    {
        $owner_type_id = $this->db->get_lookup_id_by_code(
            'llm_prompt_owner_types',
            $descriptor['owner_type'] ?? ''
        );

        $entry_id = $this->db->insert('llm_prompt_entries', array(
            'id_llm_prompt_owner_types' => $owner_type_id,
            'owner_id' => (int)($descriptor['owner_id'] ?? 0),
            'prompt_slot' => $descriptor['prompt_slot'] ?? '',
            'title' => $descriptor['title'] ?? $this->buildDefaultTitle($descriptor),
            'id_users_created' => $_SESSION['id_user'] ?? null,
            'id_users_updated' => $_SESSION['id_user'] ?? null
        ));

        $this->transaction->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_PLUGIN,
            $_SESSION['id_user'] ?? null,
            'llm_prompt_entries',
            $entry_id,
            false,
            'Prompt registry entry created'
        );

        return $this->db->select_by_uid('llm_prompt_entries', $entry_id);
    }

    private function findLocale($entry_id, $language_id)
    {
        $language_id = $language_id ?: null;

        if ($language_id === null) {
            return $this->db->query_db_first(
                "SELECT * FROM llm_prompt_locales
                 WHERE id_llm_prompt_entries = :entry_id
                   AND id_languages IS NULL",
                array(':entry_id' => $entry_id)
            );
        }

        return $this->db->query_db_first(
            "SELECT * FROM llm_prompt_locales
             WHERE id_llm_prompt_entries = :entry_id
               AND id_languages = :lang",
            array(':entry_id' => $entry_id, ':lang' => $language_id)
        );
    }

    private function createLocale($entry_id, $language_id)
    {
        $locale_id = $this->db->insert('llm_prompt_locales', array(
            'id_llm_prompt_entries' => $entry_id,
            'id_languages' => $language_id ?: null,
            'id_users_created' => $_SESSION['id_user'] ?? null,
            'id_users_updated' => $_SESSION['id_user'] ?? null
        ));

        $this->transaction->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_PLUGIN,
            $_SESSION['id_user'] ?? null,
            'llm_prompt_locales',
            $locale_id,
            false,
            'Prompt locale stream created'
        );

        return $this->db->select_by_uid('llm_prompt_locales', $locale_id);
    }

    private function ensureVersionSnapshot($descriptor, $entry, $locale, $active_content, $meta_json, $runtime_values, $allow_empty = false)
    {
        $active_content = is_string($active_content) ? $active_content : '';
        if (!$allow_empty && trim($active_content) === '' && empty($locale['active_version_id'])) {
            return array(
                'entry' => $entry,
                'locale' => $locale,
                'version' => null,
                'created' => false
            );
        }

        $profile = $this->profile_service->resolveExecutionProfile($descriptor);
        $config_snapshot = $this->profile_service->buildConfigSnapshot($profile, $runtime_values);
        $meta = $this->decodeMeta($meta_json);
        $prompt_meta = $meta[LLM_PROMPT_META_KEY] ?? array();
        $variables_schema = $prompt_meta['variablesSchema'] ?? $this->variable_service->buildAutoSchema($active_content);
        $tags = $prompt_meta['tags'] ?? array();
        $change_note = $prompt_meta['pendingChangeNote'] ?? null;
        $based_on_version_id = !empty($locale['active_version_id']) ? (int)$locale['active_version_id'] : null;
        $template_hash = hash('sha256', $active_content);
        $config_json = $this->encodeJson($config_snapshot);

        $active_version = !empty($locale['active_version_id'])
            ? $this->getVersion((int)$locale['active_version_id'])
            : null;

        if ($active_version
            && (string)$active_version['template_hash'] === $template_hash
            && (string)($active_version['config_json'] ?? '') === (string)$config_json) {
            $this->db->update_by_ids(
                'llm_prompt_locales',
                array(
                    'id_users_updated' => $_SESSION['id_user'] ?? null,
                    'updated_at' => date('Y-m-d H:i:s')
                ),
                array('id' => (int)$locale['id'])
            );

            return array(
                'entry' => $entry,
                'locale' => $this->findLocale($entry['id'], $descriptor['id_languages'] ?? null),
                'version' => $active_version,
                'created' => false
            );
        }

        $next_version_no = !empty($locale['active_version_no']) ? ((int)$locale['active_version_no'] + 1) : 1;
        $version_id = $this->db->insert('llm_prompt_versions', array(
            'id_llm_prompt_locales' => (int)$locale['id'],
            'version_no' => $next_version_no,
            'template_raw' => $active_content,
            'template_hash' => $template_hash,
            'config_json' => $config_json,
            'metadata_json' => $this->encodeJson(array(
                'descriptor' => $descriptor,
                'execution_profile' => $profile
            )),
            'variables_schema_json' => $this->encodeJson($variables_schema),
            'tags_json' => $this->encodeJson($tags),
            'change_note' => $change_note,
            'based_on_version_id' => $based_on_version_id,
            'id_users_created' => $_SESSION['id_user'] ?? null
        ));

        $this->db->update_by_ids(
            'llm_prompt_locales',
            array(
                'active_version_id' => $version_id,
                'active_version_no' => $next_version_no,
                'id_users_updated' => $_SESSION['id_user'] ?? null
            ),
            array('id' => (int)$locale['id'])
        );

        $this->db->update_by_ids(
            'llm_prompt_entries',
            array('id_users_updated' => $_SESSION['id_user'] ?? null),
            array('id' => (int)$entry['id'])
        );

        $this->transaction->add_transaction(
            transactionTypes_insert,
            TRANSACTION_BY_LLM_PLUGIN,
            $_SESSION['id_user'] ?? null,
            'llm_prompt_versions',
            $version_id,
            false,
            'Prompt version created'
        );

        return array(
            'entry' => $this->findEntry($descriptor),
            'locale' => $this->findLocale($entry['id'], $descriptor['id_languages'] ?? null),
            'version' => $this->getVersion($version_id),
            'created' => true
        );
    }

    private function buildDefaultTitle($descriptor)
    {
        if (!empty($descriptor['title'])) {
            return (string)$descriptor['title'];
        }

        if (($descriptor['owner_type'] ?? '') === LLM_PROMPT_OWNER_SCRIPT) {
            return 'Script prompt';
        }

        return ucfirst(str_replace('_', ' ', $descriptor['prompt_slot'] ?? 'Prompt'));
    }

    private function decodeMeta($meta_json)
    {
        if (!is_string($meta_json) || trim($meta_json) === '') {
            return array();
        }

        $decoded = json_decode($meta_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array();
        }

        return $decoded;
    }

    private function buildFieldMeta($meta_json, $entry_id, $locale_id, $version_id, $version_no)
    {
        $meta = $this->decodeMeta($meta_json);
        $prompt = $meta[LLM_PROMPT_META_KEY] ?? array();

        unset($prompt['pendingChangeNote']);

        $prompt['entryId'] = $entry_id;
        $prompt['localeId'] = $locale_id;
        $prompt['activeVersionId'] = $version_id;
        $prompt['activeVersionNo'] = $version_no;

        $meta[LLM_PROMPT_META_KEY] = $prompt;

        return json_encode($meta, JSON_UNESCAPED_SLASHES);
    }

    private function getVariablesSchema($active_version, $active_content)
    {
        if (!empty($active_version['variables_schema_json'])) {
            $decoded = json_decode($active_version['variables_schema_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->variable_service->buildAutoSchema($active_content);
    }

    private function getAvailableModels()
    {
        $service = new LlmService($this->services);
        return $service->getAvailableModels();
    }

    private function encodeJson($value)
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
?>
