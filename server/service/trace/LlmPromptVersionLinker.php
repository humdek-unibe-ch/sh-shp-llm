<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/../LlmErrorReporter.php';
require_once __DIR__ . '/LlmTraceHints.php';

/**
 * Links a request trace to the prompt versions that were in effect.
 *
 * Request-scoped notes: the asset loader notes every loaded asset, a model
 * notes the section field its prompt comes from, the trace service calls
 * shareGlobalValues() before it builds the trace. resolveAll() turns the
 * notes into version ids and clears them:
 *  - system asset: llm_prompt_versions, owner type `system`, owner 0, slot =
 *    asset key, no language; the version with the text's hash, created on
 *    first use;
 *  - section field: the Prompt Lab version (owner type `style_field`) with
 *    the hash of the raw (not interpolated) field text of the user's
 *    language, else the default language. Resolve only: a CMS save creates
 *    the version; content written by SQL and never saved is not linked.
 * A template is linked only when its longest literal part (outside {{...}})
 * occurs in the sent messages. The links are informational: the trace proof
 * is its chunks and hash, so any failure here only means fewer links.
 */
class LlmPromptVersionLinker
{
    /** @var array<string, string> asset key => text */
    private static $assets = array();
    /** @var array<string, array{0:int, 1:string, 2:int}> section, field, language */
    private static $fields = array();

    /**
     * Declare the long Global Values as shared trace text. They are
     * configuration, not participant data, and can be very long (a module text
     * pulled in with {{woop_{{module_id}}_context}}): shared, a value is stored
     * once for all users and requests instead of inside the per-user text of
     * every request. Every language row is read, so the language the context
     * was built in does not matter. Core interpolation escapes a value
     * (newlines, control characters, addslashes), so the shared form is the
     * one core itself produces for the placeholder of the key.
     *
     * @param object $db BaseDb / PageDb
     */
    public static function shareGlobalValues($db)
    {
        // Process cache by content: a long-running worker decodes a big JSON once
        static $decoded = array();
        try {
            $rows = $db->query_db(
                'SELECT t.content FROM pages_fields_translation t
                 INNER JOIN pages p ON p.id = t.id_pages
                 INNER JOIN fields f ON f.id = t.id_fields
                 WHERE p.keyword = :page AND f.name = :field',
                array(':page' => SH_GLOBAL_VALUES, ':field' => PF_GLOBAL_VALUES)
            ) ?: array();
            foreach ($rows as $row) {
                $content = (string)$row['content'];
                $key = sha1($content);
                if (!isset($decoded[$key])) {
                    $texts = array();
                    $values = json_decode($content, true);
                    foreach (is_array($values) ? $values : array() as $name => $value) {
                        if (is_string($name) && $name !== '' && is_string($value)
                            && strlen($value) >= LLM_TRACE_SHARED_GLOBAL_VALUE_MIN_LENGTH) {
                            $texts[] = $db->replace_calced_values('{{' . $name . '}}', array($name => $value));
                        }
                    }
                    $decoded[$key] = $texts;
                }
                foreach ($decoded[$key] as $text) {
                    LlmTraceHints::share($text);
                }
            }
        } catch (\Throwable $e) {
            LlmErrorReporter::log('Sharing the Global Values of a request trace', $e);
        }
    }

    public static function noteAsset($key, $text)
    {
        if (is_string($key) && $key !== '' && is_string($text) && $text !== '') {
            self::$assets[$key] = $text;
        }
    }

    /**
     * @param int $section_id
     * @param string $field_name Style field holding the prompt (e.g. conversation_context)
     * @param int $id_languages Language the section was rendered in
     */
    public static function noteSectionField($section_id, $field_name, $id_languages)
    {
        if ((int)$section_id > 0 && is_string($field_name) && $field_name !== '') {
            self::$fields[(int)$section_id . '|' . $field_name] = array((int)$section_id, $field_name, max(1, (int)$id_languages));
        }
    }

    public static function reset()
    {
        self::$assets = array();
        self::$fields = array();
    }

    /**
     * @param object $db BaseDb / PageDb
     * @param array $messages Sent messages (only templates found in them are linked)
     * @return int[] llm_prompt_versions ids
     */
    public static function resolveAll($db, array $messages)
    {
        $assets = self::$assets;
        $fields = self::$fields;
        self::reset();
        $sent = self::sentText($messages);
        $ids = array();
        try {
            foreach ($assets as $key => $text) {
                if (self::wasSent($text, $sent)) {
                    $ids[] = self::resolveSystemVersion($db, $key, $text);
                }
            }
            foreach ($fields as $field) {
                $raw = self::rawSectionField($db, $field[0], $field[1], $field[2]);
                if ($raw !== null && self::wasSent($raw['content'], $sent)) {
                    $ids[] = self::resolveSectionFieldVersion($db, $field[0], $field[1], $raw);
                }
            }
        } catch (\Throwable $e) {
            LlmErrorReporter::log('Linking a request trace to prompt versions', $e);
            return array();
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The Prompt Lab version of a section field that holds its raw text: the
     * active version when it has that text, else the newest one that has it.
     *
     * @param array $raw Row of rawSectionField()
     * @return int|null
     */
    private static function resolveSectionFieldVersion($db, $section_id, $field_name, array $raw)
    {
        $version = $db->query_db_first(
            "SELECT v.id FROM llm_prompt_versions v
             INNER JOIN llm_prompt_locales l ON l.id = v.id_llm_prompt_locales
             INNER JOIN llm_prompt_entries e ON e.id = l.id_llm_prompt_entries
             INNER JOIN lookups o ON o.id = e.id_llm_prompt_owner_types
                AND o.type_code = 'llm_prompt_owner_types' AND o.lookup_code = :owner_type
             WHERE e.owner_id = :section AND e.prompt_slot = :slot AND l.id_languages = :lang
                AND v.template_hash = :hash
             ORDER BY (v.id = l.active_version_id) DESC, v.id DESC LIMIT 1",
            array(
                ':owner_type' => LLM_PROMPT_OWNER_STYLE_FIELD,
                ':section' => (int)$section_id,
                ':slot' => $field_name,
                ':lang' => (int)$raw['id_languages'],
                ':hash' => hash('sha256', $raw['content']),
            )
        );
        return !empty($version['id']) ? (int)$version['id'] : null;
    }

    private static function resolveSystemVersion($db, $key, $text)
    {
        // Process cache of versions that already existed when they were read
        // (versions are never deleted). One created here is not cached: the
        // trace savepoint around it may still roll back.
        static $known = array();
        $hash = hash('sha256', $text);
        $cache_key = spl_object_id($db) . '|' . $key . '|' . $hash;
        if (isset($known[$cache_key])) {
            return $known[$cache_key];
        }

        $owner_type = $db->query_db_first(
            "SELECT id FROM lookups WHERE type_code = 'llm_prompt_owner_types' AND lookup_code = :code",
            array(':code' => LLM_PROMPT_OWNER_SYSTEM)
        );
        if (empty($owner_type['id'])) {
            throw new RuntimeException('Prompt owner type "system" is missing (run the v1.7.0 migration)');
        }
        // Two requests may use a new asset at the same time: the unique
        // (owner type, owner, slot) key keeps one entry, both read it back
        $entry_params = array(':type' => (int)$owner_type['id'], ':slot' => $key);
        $db->execute_update_db(
            'INSERT IGNORE INTO llm_prompt_entries (id_llm_prompt_owner_types, owner_id, prompt_slot, title) VALUES (:type, 0, :slot, :title)',
            $entry_params + array(':title' => $key)
        );
        $entry = $db->query_db_first(
            'SELECT id FROM llm_prompt_entries WHERE id_llm_prompt_owner_types = :type AND owner_id = 0 AND prompt_slot = :slot',
            $entry_params
        );
        if (empty($entry['id'])) {
            throw new RuntimeException('System prompt entry could not be stored: ' . $key);
        }
        $entry_id = (int)$entry['id'];
        // id_languages NULL is not covered by the unique key: a race may add a
        // second locale, so always use the first one
        $locale = $db->query_db_first(
            'SELECT id, active_version_id, active_version_no FROM llm_prompt_locales
             WHERE id_llm_prompt_entries = :entry AND id_languages IS NULL ORDER BY id LIMIT 1',
            array(':entry' => $entry_id)
        );
        if (empty($locale['id'])) {
            $locale = array(
                'id' => (int)$db->insert('llm_prompt_locales', array('id_llm_prompt_entries' => $entry_id, 'active_version_no' => 0)),
                'active_version_id' => null,
                'active_version_no' => 0,
            );
        }
        $locale_id = (int)$locale['id'];
        // A concurrent first use of a changed asset may take the same next
        // version_no (unique per locale): INSERT IGNORE, then read the hash again
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $version = $db->query_db_first(
                'SELECT id FROM llm_prompt_versions WHERE id_llm_prompt_locales = :locale AND template_hash = :hash ORDER BY id LIMIT 1',
                array(':locale' => $locale_id, ':hash' => $hash)
            );
            if (!empty($version['id'])) {
                return $known[$cache_key] = (int)$version['id'];
            }
            $next = $db->query_db_first(
                'SELECT COALESCE(MAX(version_no), 0) + 1 AS next_no FROM llm_prompt_versions WHERE id_llm_prompt_locales = :locale',
                array(':locale' => $locale_id)
            );
            $version_no = (int)($next['next_no'] ?? 1);
            $inserted = $db->execute_update_db(
                'INSERT IGNORE INTO llm_prompt_versions
                    (id_llm_prompt_locales, version_no, template_raw, template_hash, change_note, based_on_version_id)
                 VALUES (:locale, :no, :raw, :hash, :note, :based_on)',
                array(
                    ':locale' => $locale_id,
                    ':no' => $version_no,
                    ':raw' => $text,
                    ':hash' => $hash,
                    ':note' => 'Built-in prompt asset, recorded on first use',
                    ':based_on' => !empty($locale['active_version_id']) ? (int)$locale['active_version_id'] : null,
                )
            );
            if ((int)$inserted !== 1) {
                continue;
            }
            $created = $db->query_db_first(
                'SELECT id FROM llm_prompt_versions WHERE id_llm_prompt_locales = :locale AND version_no = :no',
                array(':locale' => $locale_id, ':no' => $version_no)
            );
            $version_id = (int)($created['id'] ?? 0);
            $db->execute_update_db(
                'UPDATE llm_prompt_locales SET active_version_id = :version, active_version_no = :no WHERE id = :id',
                array(':version' => $version_id, ':no' => $version_no, ':id' => $locale_id)
            );
            return $version_id;
        }
        throw new RuntimeException('System prompt version could not be stored: ' . $key);
    }

    /**
     * @return array{id_languages:int, content:string}|null the
     *   row of the user's language, else of the default language
     */
    private static function rawSectionField($db, $section_id, $field_name, $id_languages)
    {
        $rows = $db->query_db(
            'SELECT t.id_languages, t.content FROM sections_fields_translation t
             INNER JOIN fields f ON f.id = t.id_fields
             WHERE t.id_sections = :section AND f.name = :field AND t.id_genders = 1 AND t.id_languages IN (1, :lang)',
            array(':section' => (int)$section_id, ':field' => $field_name, ':lang' => (int)$id_languages)
        ) ?: array();
        $by_language = array();
        foreach ($rows as $row) {
            $by_language[(int)$row['id_languages']] = array(
                'id_languages' => (int)$row['id_languages'],
                'content' => (string)$row['content'],
            );
        }
        foreach (array((int)$id_languages, 1) as $language) {
            if (isset($by_language[$language]) && trim($by_language[$language]['content']) !== '') {
                return $by_language[$language];
            }
        }
        return null;
    }

    /** Longest literal part of a template outside {{...}} occurs in the sent text. */
    private static function wasSent($template, $sent)
    {
        $longest = '';
        foreach (preg_split('/\{\{.*?\}\}/s', $template) as $part) {
            $part = trim($part);
            if (strlen($part) > strlen($longest)) {
                $longest = $part;
            }
        }
        return $longest !== '' && strpos($sent, $longest) !== false;
    }

    private static function sentText(array $messages)
    {
        $text = '';
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $text .= $content . "\n";
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $text .= $part['text'] . "\n";
                    }
                }
            }
        }
        return $text;
    }
}
?>
