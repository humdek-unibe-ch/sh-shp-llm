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
        $owner_id = (int)($descriptor['owner_id'] ?? 0);

        if ($owner_type === LLM_PROMPT_OWNER_SCRIPT || $prompt_slot === 'script') {
            return 'script_runtime';
        }

        if ($prompt_slot === 'therapy_draft_context') {
            return 'therapy_draft_runtime';
        }

        if ($prompt_slot === 'therapy_summary_context') {
            return 'therapy_summary_runtime';
        }

        if ($prompt_slot === 'therapy_auto_start_context') {
            return 'therapy_chat_runtime';
        }

        if ($prompt_slot === 'conversation_context') {
            $style_name = $this->resolveSectionStyleName($owner_id);
            if ($style_name === 'therapychat') {
                return 'therapy_chat_runtime';
            }
            if ($style_name === 'therapistdashboard') {
                return 'therapy_draft_runtime';
            }
            return 'chat_runtime';
        }

        if ($prompt_slot === 'llm_context') {
            return 'form_runtime';
        }

        return 'text_only';
    }

    /**
     * Return the companion fields needed to preview runtime behavior.
     *
     * @param string $profile
     * @return array
     */
    public function getCompanionFieldNames($profile)
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

        if ($profile === 'therapy_chat_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'therapy_enable_ai',
                'therapy_chat_default_mode',
                'enable_danger_detection',
                'danger_keywords',
                'danger_notification_emails',
                'danger_blocked_message',
                'enable_speech_to_text',
                'speech_to_text_model',
                'speech_to_text_language'
            );
        }

        if ($profile === 'therapy_draft_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'conversation_context',
                'therapy_draft_context'
            );
        }

        if ($profile === 'therapy_summary_runtime') {
            return array(
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'therapy_summary_context'
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

        if ($profile === 'chat_runtime' || $profile === 'therapy_chat_runtime') {
            $snapshot['strict_conversation_mode'] = $this->toBoolString($runtime_values['strict_conversation_mode'] ?? null);
            $snapshot['enable_form_mode'] = $this->toBoolString($runtime_values['enable_form_mode'] ?? null);
            $snapshot['enable_progress_tracking'] = $this->toBoolString($runtime_values['enable_progress_tracking'] ?? null);
            $snapshot['enable_danger_detection'] = $this->toBoolString($runtime_values['enable_danger_detection'] ?? null);
            $snapshot['danger_keywords'] = $runtime_values['danger_keywords'] ?? '';
            $snapshot['enable_floating_button'] = $this->toBoolString($runtime_values['enable_floating_button'] ?? null);
            $snapshot['enable_media_rendering'] = $this->toBoolString($runtime_values['enable_media_rendering'] ?? null);
        }

        return $snapshot;
    }

    private function resolveSectionStyleName($section_id)
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
