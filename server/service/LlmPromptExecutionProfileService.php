<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

class LlmPromptExecutionProfileService extends BaseLlmService
{
    /**
     * Resolve the runtime execution profile for a prompt owner.
     *
     * @param array $descriptor
     * @return string
     */
    public function resolveExecutionProfile($descriptor)
    {
        $owner_type = $descriptor['owner_type'] ?? '';
        $prompt_slot = $descriptor['prompt_slot'] ?? '';

        if ($owner_type === LLM_PROMPT_OWNER_SCRIPT || $prompt_slot === 'script') {
            return 'script_runtime';
        }

        $slot_profile = $this->resolveExecutionProfileByPromptSlot($descriptor);
        if (is_string($slot_profile) && $slot_profile !== '') {
            return $slot_profile;
        }

        if ($prompt_slot === 'conversation_context') {
            $style_name = $this->resolveSectionStyleName((int)($descriptor['owner_id'] ?? 0));
            return $this->resolveConversationContextExecutionProfile($descriptor, $style_name);
        }

        if ($prompt_slot === 'llm_context') {
            return 'form_runtime';
        }

        return $this->resolveExecutionProfileFallback($descriptor);
    }

    /**
     * Return the companion fields needed to preview runtime behavior.
     *
     * @param string $profile
     * @return array
     */
    public function getCompanionFieldNames($profile)
    {
        $core = $this->getCoreCompanionFieldNames($profile);
        if (!empty($core)) {
            return $core;
        }

        return $this->getExtendedCompanionFieldNames($profile);
    }

    /**
     * Classify the playground runtime mode for a profile.
     *
     * @param string $profile
     * @return string One of chat|form|script|none
     */
    public function getPlaygroundRuntimeType($profile)
    {
        if ($profile === 'chat_runtime') {
            return 'chat';
        }
        if ($profile === 'form_runtime') {
            return 'form';
        }
        if ($profile === 'script_runtime') {
            return 'script';
        }

        $extended = (string)$this->getExtendedPlaygroundRuntimeType($profile);
        if (in_array($extended, array('chat', 'form', 'script'), true)) {
            return $extended;
        }

        return 'none';
    }

    /**
     * Return whether this profile should run through chat playground flow.
     *
     * @param string $profile
     * @return bool
     */
    public function isChatLikeExecutionProfile($profile)
    {
        if ($this->getPlaygroundRuntimeType($profile) === 'chat') {
            return true;
        }

        return (bool)$this->isExtendedChatLikeExecutionProfile($profile);
    }

    /**
     * Resolve the default user message for a chat-like playground profile.
     *
     * @param string $profile
     * @return string
     */
    public function resolveDefaultChatPromptForProfile($profile)
    {
        if ($profile === 'chat_runtime') {
            return 'Test this prompt in playground mode.';
        }

        $extended = trim((string)$this->resolveExtendedDefaultChatPromptForProfile($profile));
        if ($extended !== '') {
            return $extended;
        }

        return 'Test this prompt in playground mode.';
    }

    /**
     * Resolve execution profile from prompt-slot specific extension mappings.
     *
     * @param array $descriptor
     * @return string
     */
    public function resolveExecutionProfileByPromptSlot($descriptor)
    {
        return '';
    }

    /**
     * Resolve profile for conversation_context owners.
     *
     * @param array $descriptor
     * @return string
     */
    public function resolveConversationContextExecutionProfile($descriptor, $style_name = '')
    {
        return 'chat_runtime';
    }

    /**
     * Resolve fallback profile when no core mapping applies.
     *
     * @param array $descriptor
     * @return string
     */
    public function resolveExecutionProfileFallback($descriptor)
    {
        return 'text_only';
    }

    /**
     * Return core companion fields for known base profiles.
     *
     * @param string $profile
     * @return array
     */
    public function getCoreCompanionFieldNames($profile)
    {
        if ($profile === 'chat_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'strict_conversation_mode',
                'enable_form_mode',
                'enable_progress_tracking',
                'progress_bar_label',
                'progress_complete_message',
                'progress_show_topics',
                'enable_danger_detection',
                'danger_keywords',
                'danger_notification_emails',
                'danger_blocked_message',
                'enable_floating_button',
                'enable_media_rendering',
                'allowed_media_domains'
            );
        }

        if ($profile === 'form_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens'
            );
        }

        if ($profile === 'script_runtime') {
            return array(
                'name',
                'model',
                'temperature',
                'max_tokens',
                'data_config',
                'test_variables'
            );
        }

        return array();
    }

    /**
     * Return companion fields for plugin-extended profiles.
     *
     * @param string $profile
     * @return array
     */
    public function getExtendedCompanionFieldNames($profile)
    {
        return array();
    }

    /**
     * Return runtime-type classification for plugin-extended profiles.
     *
     * @param string $profile
     * @return string
     */
    public function getExtendedPlaygroundRuntimeType($profile)
    {
        return 'none';
    }

    /**
     * Return chat-like hint for plugin-extended profiles.
     *
     * @param string $profile
     * @return bool
     */
    public function isExtendedChatLikeExecutionProfile($profile)
    {
        return false;
    }

    /**
     * Return profile-specific default chat prompt for extensions.
     *
     * @param string $profile
     * @return string
     */
    public function resolveExtendedDefaultChatPromptForProfile($profile)
    {
        return '';
    }

    /**
     * Fetch runtime values for a style-backed prompt owner.
     *
     * The CMS stores non-display fields in language 1. Display fields may be
     * translated. This query first checks the requested language, then falls
     * back to language 1, then to the style default.
     *
     * @param int $section_id
     * @param int|null $language_id
     * @param array $field_names
     * @return array
     */
    public function getStyleFieldValues($section_id, $language_id, $field_names)
    {
        $field_names = array_values(array_filter(array_unique($field_names)));
        if (empty($field_names)) {
            return array();
        }

        $params = array(
            ':sid' => $section_id,
            ':lang' => $language_id ?: 1,
            ':fallback_lang' => 1,
            ':gender' => 1
        );

        $placeholders = array();
        foreach ($field_names as $index => $field_name) {
            $key = ':field_' . $index;
            $params[$key] = $field_name;
            $placeholders[] = $key;
        }

        $sql = "SELECT
                f.name,
                st.name AS style_name,
                s.name AS section_name,
                COALESCE(
                    (SELECT sft.content
                     FROM sections_fields_translation sft
                     WHERE sft.id_sections = s.id
                       AND sft.id_fields = f.id
                       AND sft.id_languages = :lang
                       AND sft.id_genders = :gender
                     LIMIT 1),
                    (SELECT sft.content
                     FROM sections_fields_translation sft
                     WHERE sft.id_sections = s.id
                       AND sft.id_fields = f.id
                       AND sft.id_languages = :fallback_lang
                       AND sft.id_genders = :gender
                     LIMIT 1),
                    sf.default_value
                ) AS content
            FROM sections s
            INNER JOIN styles st ON st.id = s.id_styles
            INNER JOIN styles_fields sf ON sf.id_styles = st.id
            INNER JOIN fields f ON f.id = sf.id_fields
            WHERE s.id = :sid
              AND f.name IN (" . implode(',', $placeholders) . ")";

        $rows = $this->db->query_db($sql, $params);
        $result = array();

        foreach ($rows as $row) {
            $result[$row['name']] = $row['content'];
            if (!isset($result['__style_name'])) {
                $result['__style_name'] = $row['style_name'] ?? '';
            }
            if (!isset($result['__section_name'])) {
                $result['__section_name'] = $row['section_name'] ?? '';
            }
        }

        return $result;
    }

    /**
     * Build a config snapshot that is versioned together with the prompt text.
     *
     * Runtime authority remains in the existing owner fields/columns; this is
     * only a reproducible snapshot for version history and playground reruns.
     *
     * @param string $profile
     * @param array $runtime_values
     * @return array
     */
    public function buildConfigSnapshot($profile, $runtime_values)
    {
        if ($profile === 'script_runtime') {
            return array(
                'model' => $runtime_values['model'] ?? null,
                'temperature' => $this->normalizeNumber($runtime_values['temperature'] ?? null),
                'max_tokens' => $this->normalizeInt($runtime_values['max_tokens'] ?? null),
                'data_config' => $this->decodeJsonValue($runtime_values['data_config'] ?? null),
                'test_variables' => $this->decodeJsonValue($runtime_values['test_variables'] ?? null)
            );
        }

        $snapshot = array(
            'model' => $runtime_values['llm_model'] ?? null,
            'temperature' => $this->normalizeNumber($runtime_values['llm_temperature'] ?? null),
            'max_tokens' => $this->normalizeInt($runtime_values['llm_max_tokens'] ?? null)
        );

        if ($profile === 'chat_runtime') {
            $snapshot['strict_conversation_mode'] = $this->toBoolString($runtime_values['strict_conversation_mode'] ?? null);
            $snapshot['enable_form_mode'] = $this->toBoolString($runtime_values['enable_form_mode'] ?? null);
            $snapshot['enable_progress_tracking'] = $this->toBoolString($runtime_values['enable_progress_tracking'] ?? null);
            $snapshot['enable_danger_detection'] = $this->toBoolString($runtime_values['enable_danger_detection'] ?? null);
            $snapshot['danger_keywords'] = $runtime_values['danger_keywords'] ?? '';
            $snapshot['enable_floating_button'] = $this->toBoolString($runtime_values['enable_floating_button'] ?? null);
            $snapshot['enable_media_rendering'] = $this->toBoolString($runtime_values['enable_media_rendering'] ?? null);
        }

        $extended = $this->getExtendedConfigSnapshotFields($profile, $runtime_values);
        if (is_array($extended) && !empty($extended)) {
            $snapshot = array_merge($snapshot, $extended);
        }

        return $snapshot;
    }

    /**
     * Return additional config snapshot fields for plugin-extended profiles.
     *
     * @param string $profile
     * @param array $runtime_values
     * @return array
     */
    public function getExtendedConfigSnapshotFields($profile, $runtime_values)
    {
        return array();
    }

    public function resolveSectionStyleName($section_id)
    {
        if ($section_id <= 0) {
            return '';
        }

        $row = $this->db->query_db_first(
            "SELECT st.name
             FROM sections s
             INNER JOIN styles st ON st.id = s.id_styles
             WHERE s.id = :sid
             LIMIT 1",
            array(':sid' => $section_id)
        );

        return strtolower((string)($row['name'] ?? ''));
    }

    private function normalizeNumber($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float)$value;
    }

    private function normalizeInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function toBoolString($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ((string)$value === '1' || $value === true) ? '1' : '0';
    }

    private function decodeJsonValue($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }
}
?>
