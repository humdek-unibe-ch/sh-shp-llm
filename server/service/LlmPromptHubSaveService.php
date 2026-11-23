<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmTransaction.php';
require_once __DIR__ . '/LlmPromptCatalogService.php';
require_once __DIR__ . '/LlmPromptRegistryService.php';
require_once __DIR__ . '/LlmPromptExecutionProfileService.php';
require_once __DIR__ . '/LlmScriptService.php';
require_once __DIR__ . '/LlmMemoryRuleService.php';
require_once __DIR__ . '/exception/LlmPromptHubExceptions.php';

/**
 * Write side of the prompts hub: save a prompt text from the hub, or restore an old version of it.
 *
 * A save ends up exactly where the owner's own editor would put it, so the hub never creates a
 * second truth:
 *  - style_field:     the CMS field save (registry version + sections_fields_translation +
 *                     CMS field history), see LlmHooks::syncStyleFieldPromptOnCmsSave;
 *  - llm_script:      LlmScriptService::update_script() with every other column unchanged;
 *  - llm_memory_rule: LlmMemoryRuleService::syncPromptForRule(), the prompt half of updateRule()
 *                     (a prompt edit does not rewrite the rule's label, triggers or data config).
 * System prompts and agentic personas are read-only here.
 *
 * Nothing is overwritten silently. The editor sends the active version id and the hash of the
 * text it loaded; both are compared with what is stored when the write happens. The source row is
 * locked first (a locking read, so the checks below see the latest committed data and a second
 * save waits for this one), then the checks run, then the write; the whole save is one
 * LlmTransaction. saveAll() locks the rows of all its languages, in language order, before it
 * reads any of them, and checks all of them before it writes any. A save that finds the prompt
 * changed throws LlmPromptHubConflictException and writes nothing.
 *
 * The user's ACL is checked here again (LlmPromptCatalogService::getPrompt() answers editable per
 * prompt from the pages that hold it), whatever the controller already did: the hub is never a back
 * door to a prompt the user could not open elsewhere.
 *
 * @package LLM Plugin
 * @see LlmPromptCatalogService For the read side and the ACL rules
 */
class LlmPromptHubSaveService
{
    /** Longest change note: llm_prompt_versions.change_note is VARCHAR(255) */
    const MAX_CHANGE_NOTE_LENGTH = 255;

    /**
     * Longest prompt text accepted, in bytes. The columns are LONGTEXT; this only keeps a wrong
     * paste from filling the database.
     */
    const MAX_CONTENT_BYTES = 1000000;

    /** Most texts saveAll() takes in one request (the languages of one field) */
    const MAX_SAVE_ALL_ITEMS = 20;

    /** @var object SelfHelp services container */
    private $services;

    /** @var object SelfHelp database */
    private $db;

    /** @var LlmPromptCatalogService */
    private $catalog;

    /** @var LlmPromptRegistryService|null built on first use */
    private $registry;

    /** @var LlmPromptExecutionProfileService|null built on first use */
    private $profile_service;

    /** @var LlmScriptService|null built on first use */
    private $script_service;

    /** @var LlmMemoryRuleService|null built on first use */
    private $rule_service;

    /**
     * @param object $services SelfHelp services container
     * @param LlmPromptCatalogService|null $catalog
     * @param LlmPromptRegistryService|null $registry
     * @param LlmPromptExecutionProfileService|null $profile_service
     * @param LlmScriptService|null $script_service
     * @param LlmMemoryRuleService|null $rule_service
     */
    public function __construct($services, $catalog = null, $registry = null, $profile_service = null, $script_service = null, $rule_service = null)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->catalog = $catalog ?: new LlmPromptCatalogService($services);
        $this->registry = $registry;
        $this->profile_service = $profile_service;
        $this->script_service = $script_service;
        $this->rule_service = $rule_service;
    }

    /**
     * Save a new text for a prompt as a new version, live immediately.
     *
     * @param string $key Prompt key (LlmPromptCatalogService::parseKey())
     * @param string $content The new text; line endings are stored as "\n"
     * @param string|null $change_note Note for the version, at most 255 characters
     * @param int $expected_active_version_id Active version the editor loaded (0 = there was none)
     * @param int $user_id Session user
     * @param string|null $expected_content_hash sha256 of the text the editor loaded, as the catalog
     *                                           answered it; null skips this check
     * @return array{ok: bool, changed: bool, row: array} row is the fresh LlmPromptCatalogService::getPrompt()
     * @throws InvalidArgumentException Bad key, read-only source, or an invalid text, note or lock value
     * @throws LlmPromptHubNotFoundException The prompt does not exist or the user may not see it
     * @throws LlmPromptHubForbiddenException The user may not change it
     * @throws LlmPromptHubConflictException It was changed since the editor loaded it; nothing was written
     * @throws Throwable Any other failure; the transaction was rolled back
     */
    public function save($key, $content, $change_note, $expected_active_version_id, $user_id, $expected_content_hash = null)
    {
        $user_id = (int)$user_id;
        $item = $this->prepareItem($key, $content, $change_note, $expected_active_version_id, $expected_content_hash, $user_id);
        $saved = $this->writeItems(array($item), $user_id, false);

        return $saved[$key];
    }

    /**
     * Save several languages of one prompt (a CMS prompt field) together: every text is checked and
     * written like save() does it, in one transaction. Either all of them are saved or none: a
     * conflict or a failure in one language rolls back the others. All rows are locked before the
     * first one is read, in language order, so two requests on the same prompt never wait for each
     * other crosswise and the checks see what the saves we waited for committed.
     *
     * @param array[] $items Each {key, content, change_note?, expected_active_version_id,
     *                       expected_content_hash?}; the keys name the same owner and slot
     * @param int $user_id Session user
     * @return array{ok: bool, changed: bool, rows: array[]} rows: the fresh prompts, in the order of $items
     * @throws InvalidArgumentException No items, too many, a malformed item, keys of different prompts,
     *                                  a language twice
     * @throws LlmPromptHubConflictException The language it names (current.key) changed; nothing was written
     * @throws LlmPromptHubNotFoundException|LlmPromptHubForbiddenException See save()
     * @throws Throwable Any other failure; nothing was written
     */
    public function saveAll(array $items, $user_id)
    {
        if (empty($items) || count($items) > self::MAX_SAVE_ALL_ITEMS) {
            throw new InvalidArgumentException('Between 1 and ' . self::MAX_SAVE_ALL_ITEMS . ' texts can be saved together');
        }

        $field = null;
        $languages = array();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['key']) || !is_string($item['key'])) {
                throw new InvalidArgumentException('Malformed prompt key');
            }
            $parsed = LlmPromptCatalogService::parseKey($item['key']);
            $this->assertEditableSource($parsed);
            $owner = $parsed['sourceType'] . ':' . $parsed['ownerId'] . ':' . $parsed['slot'];
            if ($field !== null && $owner !== $field) {
                throw new InvalidArgumentException('Only the languages of one prompt can be saved together');
            }
            if (isset($languages[$parsed['languageId']])) {
                throw new InvalidArgumentException('A language can be saved only once');
            }
            $field = $owner;
            $languages[$parsed['languageId']] = $item;
        }
        // Always the same lock order, so two requests on the same field never wait for each other
        ksort($languages);

        $user_id = (int)$user_id;
        $prepared = array();
        foreach ($languages as $item) {
            $prepared[] = $this->prepareItem(
                $item['key'],
                $item['content'] ?? null,
                $item['change_note'] ?? null,
                $item['expected_active_version_id'] ?? -1,
                $item['expected_content_hash'] ?? null,
                $user_id
            );
        }
        $saved = $this->writeItems($prepared, $user_id, true);

        $rows = array();
        $changed = false;
        foreach ($items as $item) {
            $rows[] = $saved[$item['key']]['row'];
            $changed = $changed || $saved[$item['key']]['changed'];
        }
        return array('ok' => true, 'changed' => $changed, 'rows' => $rows);
    }

    /**
     * Make an old version the live text again. History is never rewritten: the old text is saved as
     * a new version with the note "Restored from vN".
     *
     * @param string $key
     * @param int $version_id Version of this prompt to restore
     * @param int $expected_active_version_id See save()
     * @param int $user_id Session user
     * @param string|null $expected_content_hash See save()
     * @return array See save()
     * @throws InvalidArgumentException
     * @throws LlmPromptHubNotFoundException
     * @throws LlmPromptHubForbiddenException Also when the version belongs to another prompt
     * @throws LlmPromptHubConflictException
     * @throws Throwable
     */
    public function restore($key, $version_id, $expected_active_version_id, $user_id, $expected_content_hash = null)
    {
        $parsed = LlmPromptCatalogService::parseKey($key);
        $this->assertEditableSource($parsed);
        if (!is_int($version_id) || $version_id <= 0) {
            throw new InvalidArgumentException('A version is required');
        }
        $user_id = (int)$user_id;

        $this->assertCanEdit($this->catalog->getPrompt($key, $user_id));
        // Only a version of this very prompt (same owner, same language stream) is accepted
        $version = $this->catalog->getVersion($key, $version_id, $user_id);
        if ($version === null) {
            throw new LlmPromptHubForbiddenException('That version does not belong to this prompt', 'wrong_version');
        }

        return $this->save(
            $key,
            $version['template_raw'],
            'Restored from v' . $version['version_no'],
            $expected_active_version_id,
            $user_id,
            $expected_content_hash
        );
    }

    // ------------------------------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------------------------------

    /**
     * System prompts and agentic personas are shown but never written from here.
     *
     * @param array $parsed
     * @throws InvalidArgumentException
     */
    private function assertEditableSource(array $parsed)
    {
        if (!in_array($parsed['sourceType'], array(
            LlmPromptCatalogService::SOURCE_STYLE_FIELD,
            LlmPromptCatalogService::SOURCE_SCRIPT,
            LlmPromptCatalogService::SOURCE_MEMORY_RULE,
        ), true)) {
            throw new InvalidArgumentException('This prompt is read-only');
        }
    }

    /**
     * @param array|null $row LlmPromptCatalogService::getPrompt()
     * @throws LlmPromptHubNotFoundException
     * @throws LlmPromptHubForbiddenException
     */
    private function assertCanEdit($row)
    {
        if ($row === null) {
            throw new LlmPromptHubNotFoundException();
        }
        if ($row['editable'] !== true) {
            throw new LlmPromptHubForbiddenException('You may not change this prompt', (string)$row['readOnlyReason']);
        }
    }

    /**
     * The optimistic lock: the editor saves on top of what it loaded, or not at all.
     *
     * @param array $current LlmPromptCatalogService::getPrompt(), read under the lock
     * @param int $expected_version_id
     * @param string|null $expected_hash
     * @throws LlmPromptHubConflictException
     */
    private function assertUnchangedSince(array $current, $expected_version_id, $expected_hash)
    {
        $active_version_id = (int)$current['activeVersionId'];
        $same_text = $expected_hash === null || hash_equals($current['contentHash'], $expected_hash);
        if ($active_version_id === $expected_version_id && $same_text) {
            return;
        }

        throw new LlmPromptHubConflictException('This prompt was changed by someone else', array(
            'version_id' => $active_version_id > 0 ? $active_version_id : null,
            'version_no' => $current['activeVersionNo'] !== null ? (int)$current['activeVersionNo'] : null,
            'content' => $current['content'],
            'content_hash' => $current['contentHash'],
            'updated_at' => $current['updatedAt'],
            'updated_by' => $current['updatedBy'],
        ));
    }

    /**
     * @param mixed $content
     * @return string The text with "\n" line endings
     * @throws InvalidArgumentException
     */
    private function normalizeContent($content)
    {
        if (!is_string($content)) {
            throw new InvalidArgumentException('The prompt text is required');
        }
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new InvalidArgumentException('The prompt text is too long');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('The prompt text is not valid UTF-8');
        }
        return str_replace(array("\r\n", "\r"), "\n", $content);
    }

    /**
     * @param mixed $change_note
     * @return string|null Trimmed note, null for none
     * @throws InvalidArgumentException
     */
    private function normalizeChangeNote($change_note)
    {
        if ($change_note === null) {
            return null;
        }
        if (!is_string($change_note) || !mb_check_encoding($change_note, 'UTF-8')) {
            throw new InvalidArgumentException('The change note is not valid text');
        }
        $change_note = trim($change_note);
        if (mb_strlen($change_note) > self::MAX_CHANGE_NOTE_LENGTH) {
            throw new InvalidArgumentException('The change note is too long (at most ' . self::MAX_CHANGE_NOTE_LENGTH . ' characters)');
        }
        return $change_note === '' ? null : $change_note;
    }

    /**
     * @param mixed $value
     * @return int
     * @throws InvalidArgumentException
     */
    private function normalizeExpectedVersionId($value)
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('expected_active_version_id is required');
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return string|null Lower case hex, null for "do not check"
     * @throws InvalidArgumentException
     */
    private function normalizeExpectedHash($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[0-9a-fA-F]{64}$/D', $value)) {
            throw new InvalidArgumentException('Malformed content hash');
        }
        return strtolower($value);
    }

    // ------------------------------------------------------------------------------------------
    // Locking and writing
    // ------------------------------------------------------------------------------------------

    /**
     * The part of a save that needs no lock: check the input, refuse what is refused anyway, find
     * the CMS field. A request that is refused must not lock anything, so this runs before the
     * transaction.
     *
     * @param string $key
     * @param mixed $content
     * @param mixed $change_note
     * @param mixed $expected_active_version_id
     * @param mixed $expected_content_hash
     * @param int $user_id
     * @return array {key, parsed, content, change_note, expected_version_id, expected_hash, field_id}
     * @throws InvalidArgumentException
     * @throws LlmPromptHubNotFoundException
     * @throws LlmPromptHubForbiddenException
     */
    private function prepareItem($key, $content, $change_note, $expected_active_version_id, $expected_content_hash, $user_id)
    {
        $parsed = LlmPromptCatalogService::parseKey($key);
        $this->assertEditableSource($parsed);
        $item = array(
            'key' => $key,
            'parsed' => $parsed,
            'content' => $this->normalizeContent($content),
            'change_note' => $this->normalizeChangeNote($change_note),
            'expected_version_id' => $this->normalizeExpectedVersionId($expected_active_version_id),
            'expected_hash' => $this->normalizeExpectedHash($expected_content_hash),
        );

        $this->assertCanEdit($this->catalog->getPrompt($key, $user_id));
        $item['field_id'] = $parsed['sourceType'] === LlmPromptCatalogService::SOURCE_STYLE_FIELD
            ? $this->findPromptFieldId($parsed['slot'])
            : 0;

        return $item;
    }

    /**
     * The locked part of a save, for one text or the languages of one prompt, in one transaction.
     * Every source row is locked first, then every text is checked against what is stored, and only
     * when all of them pass is anything written: a conflict in one language writes nothing and
     * cools no cache.
     *
     * @param array[] $items prepareItem() answers, in lock order
     * @param int $user_id
     * @param bool $name_language A conflict names its language in current.key (saveAll())
     * @return array[] key => {ok, changed, row}, the answer of save()
     * @throws LlmPromptHubNotFoundException
     * @throws LlmPromptHubForbiddenException
     * @throws LlmPromptHubConflictException
     * @throws Throwable Any other failure; the transaction was rolled back
     */
    private function writeItems(array $items, $user_id, $name_language)
    {
        // Cache types a rollback has to clear: none until something is written, so a refused or
        // conflicting save never cools the cache
        $cache_types = array();
        $saved = array();
        LlmTransaction::begin($this->db);
        try {
            // Lock every row before the first plain read: under REPEATABLE READ a plain read before
            // a lock would pin an older snapshot and the checks would miss the save we waited for
            $locked = array();
            foreach ($items as $i => $item) {
                $locked[$i] = $this->lockSource($item['parsed'], $item['field_id']);
            }

            $current = array();
            foreach ($items as $i => $item) {
                $current[$i] = $this->catalog->getPrompt($item['key'], $user_id);
                $this->assertCanEdit($current[$i]);
                try {
                    $this->assertUnchangedSince($current[$i], $item['expected_version_id'], $item['expected_hash']);
                } catch (LlmPromptHubConflictException $e) {
                    // saveAll(): the client has to know which language it was
                    throw $name_language
                        ? new LlmPromptHubConflictException($e->getMessage(), $e->getCurrent() + array('key' => $item['key']))
                        : $e;
                }
            }

            $cache_types = array($this->db->get_cache()::CACHE_TYPE_SECTIONS);
            $clear_sections = false;
            foreach ($items as $i => $item) {
                $parsed = $item['parsed'];
                switch ($parsed['sourceType']) {
                    case LlmPromptCatalogService::SOURCE_STYLE_FIELD:
                        $this->writeStyleField($parsed, $item['field_id'], $locked[$i], $item['content'], $item['change_note']);
                        $clear_sections = true;
                        break;
                    case LlmPromptCatalogService::SOURCE_SCRIPT:
                        $this->writeScript($parsed, $item['content'], $item['change_note']);
                        break;
                    default:
                        $this->writeMemoryRule($parsed, $item['content'], $item['change_note']);
                }

                // A write that answered "fine" but left another text behind (a swallowed failure in a
                // core helper, a column that cannot hold the text) must not be reported as saved
                $row = $this->catalog->getPrompt($item['key'], $user_id);
                if ($row === null || !hash_equals(hash('sha256', $item['content']), $row['contentHash'])) {
                    throw new RuntimeException('The saved prompt text could not be verified');
                }
                $saved[$item['key']] = array(
                    'ok' => true,
                    'changed' => $row['contentHash'] !== $current[$i]['contentHash']
                        || (int)$row['activeVersionId'] !== (int)$current[$i]['activeVersionId'],
                    'row' => $row,
                );
            }

            if ($clear_sections) {
                // What the runtime reads for a section field; cleared once the text is committed.
                // Scripts clear their own caches (LlmScriptService), memory rules have none.
                $db = $this->db;
                LlmTransaction::afterCommit($db, function () use ($db) {
                    $db->clear_cache($db->get_cache()::CACHE_TYPE_SECTIONS);
                });
            }
            LlmTransaction::commit($this->db);
        } catch (Throwable $e) {
            LlmTransaction::rollback($this->db, $cache_types);
            throw $e;
        }

        return $saved;
    }

    /**
     * Id of the llm_prompt field with this name.
     *
     * @param string $name Field name from a validated key
     * @return int
     * @throws LlmPromptHubNotFoundException
     */
    private function findPromptFieldId($name)
    {
        $row = $this->db->query_db_first(
            "SELECT f.id
             FROM fields f
             INNER JOIN fieldType ft ON ft.id = f.id_type AND ft.name = 'llm_prompt'
             WHERE f.name = :name",
            array(':name' => $name)
        );
        if (!is_array($row)) {
            throw new LlmPromptHubNotFoundException();
        }
        return (int)$row['id'];
    }

    /**
     * Lock the row the text lives in (the first statement of the transaction).
     *
     * @param array $parsed
     * @param int $field_id Field id of a style field prompt
     * @return array The locked row (style field: its stored meta)
     * @throws LlmPromptHubNotFoundException When the row is gone
     */
    private function lockSource(array $parsed, $field_id)
    {
        switch ($parsed['sourceType']) {
            case LlmPromptCatalogService::SOURCE_STYLE_FIELD:
                $row = $this->db->query_db_first(
                    'SELECT meta FROM sections_fields_translation
                     WHERE id_sections = :section AND id_fields = :field AND id_languages = :language AND id_genders = 1
                     FOR UPDATE',
                    array(':section' => $parsed['ownerId'], ':field' => $field_id, ':language' => $parsed['languageId'])
                );
                // A CMS language without text yet: the save creates the row. The catalog read
                // that follows still checks that the section offers this language for the field.
                if (!is_array($row)) {
                    $row = array('meta' => null);
                }
                break;
            case LlmPromptCatalogService::SOURCE_SCRIPT:
                $row = $this->db->query_db_first(
                    'SELECT id FROM llm_scripts WHERE id = :id FOR UPDATE',
                    array(':id' => $parsed['ownerId'])
                );
                break;
            default:
                $row = $this->db->query_db_first(
                    'SELECT id FROM llm_memory_rules WHERE id = :id FOR UPDATE',
                    array(':id' => $parsed['ownerId'])
                );
        }
        if (!is_array($row)) {
            throw new LlmPromptHubNotFoundException();
        }
        return $row;
    }

    /**
     * The CMS save of an llm_prompt field, step for step (LlmHooks::syncStyleFieldPromptOnCmsSave
     * and CmsModel::update_section_fields_db), without the CMS form's posted overrides.
     *
     * @param array $parsed
     * @param int $field_id
     * @param array $locked The locked sections_fields_translation row
     * @param string $content
     * @param string|null $change_note
     * @throws RuntimeException
     */
    private function writeStyleField(array $parsed, $field_id, array $locked, $content, $change_note)
    {
        $section_id = $parsed['ownerId'];
        $language_id = $parsed['languageId'];

        $descriptor = array(
            'owner_type' => LLM_PROMPT_OWNER_STYLE_FIELD,
            'owner_id' => $section_id,
            'prompt_slot' => $parsed['slot'],
            'id_languages' => $language_id,
            'title' => ucfirst(str_replace('_', ' ', $parsed['slot'])),
        );
        $profile_service = $this->profileService();
        $profile = $profile_service->resolveExecutionProfile($descriptor);
        $runtime_values = $profile_service->getStyleFieldValues(
            $section_id,
            $language_id,
            $profile_service->getCompanionFieldNames($profile)
        );

        $sync = $this->registry()->syncFieldSave(
            $descriptor,
            $field_id,
            $content,
            $this->metaWithChangeNote($locked['meta'], $change_note),
            $runtime_values
        );

        $update = array('content' => $content, 'meta' => $sync['meta_json']);
        $insert = array(
            'content' => $content,
            'meta' => $sync['meta_json'],
            'id_fields' => $field_id,
            'id_languages' => $language_id,
            'id_sections' => $section_id,
            'id_genders' => 1,
        );
        if ($this->db->insert('sections_fields_translation', $insert, $update) === false) {
            throw new RuntimeException('The prompt text could not be stored');
        }

        // The CMS field history; only the default gender is versioned
        $field_versions = $this->services->get_field_versions();
        if ($field_versions->is_versioned($field_id)
            && $field_versions->snapshot(RELATION_SECTION_FIELD, $section_id, $field_id, $language_id, $content, $sync['meta_json']) === false) {
            throw new RuntimeException('The CMS field history could not be written');
        }
    }

    /**
     * The script's own save with every other column as it is.
     *
     * @param array $parsed
     * @param string $content
     * @param string|null $change_note
     * @throws LlmPromptHubNotFoundException
     * @throws RuntimeException update_script() answers false instead of throwing
     */
    private function writeScript(array $parsed, $content, $change_note)
    {
        $scripts = $this->scriptService();
        $script = $scripts->fetch_script($parsed['ownerId']);
        if (!is_array($script)) {
            throw new LlmPromptHubNotFoundException();
        }

        $saved = $scripts->update_script(
            $script['id'],
            $script['name'],
            $content,
            $script['test_variables'],
            $script['async'],
            $script['data_config'],
            $script['model'],
            $script['temperature'],
            $script['max_tokens'],
            $script['refresh_sections'],
            $change_note,
            null,
            isset($script['combine_images']) ? $script['combine_images'] : null
        );
        if ($saved === false) {
            throw new RuntimeException('The script could not be saved');
        }
    }

    /**
     * The prompt half of the memory rule save: a new registry version, the rule row untouched.
     *
     * @param array $parsed
     * @param string $content
     * @param string|null $change_note
     * @throws LlmPromptHubNotFoundException
     */
    private function writeMemoryRule(array $parsed, $content, $change_note)
    {
        $rules = $this->ruleService();
        $rule = $rules->getRuleById($parsed['ownerId']);
        if (!is_array($rule)) {
            throw new LlmPromptHubNotFoundException();
        }
        $rules->syncPromptForRule($rule, $content, null, $change_note);
    }

    /**
     * The stored field meta with the change note the registry turns into the version's note.
     *
     * @param string|null $meta_json Stored sections_fields_translation.meta
     * @param string|null $change_note
     * @return string
     */
    private function metaWithChangeNote($meta_json, $change_note)
    {
        $meta = is_string($meta_json) && $meta_json !== '' ? json_decode($meta_json, true) : array();
        if (!is_array($meta)) {
            $meta = array();
        }
        if (!isset($meta[LLM_PROMPT_META_KEY]) || !is_array($meta[LLM_PROMPT_META_KEY])) {
            $meta[LLM_PROMPT_META_KEY] = array();
        }
        unset($meta[LLM_PROMPT_META_KEY]['pendingChangeNote']);
        if ($change_note !== null) {
            $meta[LLM_PROMPT_META_KEY]['pendingChangeNote'] = $change_note;
        }
        return json_encode($meta);
    }

    // ------------------------------------------------------------------------------------------
    // Collaborators (built on first use)
    // ------------------------------------------------------------------------------------------

    /**
     * @return LlmPromptRegistryService
     */
    private function registry()
    {
        if ($this->registry === null) {
            $this->registry = new LlmPromptRegistryService($this->services);
        }
        return $this->registry;
    }

    /**
     * @return LlmPromptExecutionProfileService
     */
    private function profileService()
    {
        if ($this->profile_service === null) {
            $this->profile_service = new LlmPromptExecutionProfileService($this->services);
        }
        return $this->profile_service;
    }

    /**
     * @return LlmScriptService
     */
    private function scriptService()
    {
        if ($this->script_service === null) {
            $this->script_service = new LlmScriptService($this->services);
        }
        return $this->script_service;
    }

    /**
     * @return LlmMemoryRuleService
     */
    private function ruleService()
    {
        if ($this->rule_service === null) {
            $this->rule_service = new LlmMemoryRuleService($this->services);
        }
        return $this->rule_service;
    }
}
?>
