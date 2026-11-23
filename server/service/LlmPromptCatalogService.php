<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmPromptLabAccessService.php';
require_once __DIR__ . '/LlmCmsLinkHelper.php';
require_once __DIR__ . '/LlmErrorReporter.php';
require_once __DIR__ . '/prompt/LlmPromptAssetRegistry.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';

/**
 * Read side of the prompts hub (plugin v1.10.0): every LLM prompt of the site in one list.
 *
 * Sources:
 *  - style_field      `llm_prompt` CMS fields, found in sections_fields_translation. The prompt
 *                     registry is only joined in: its entries are created lazily (on the first
 *                     Prompt Lab or CMS save), so most sections have content and no entry.
 *  - llm_script       llm_scripts.script
 *  - llm_memory_rule  the instructions of a memory rule; they live only in the registry
 *  - agentic_persona  the description of an agentic chat persona (link only)
 *  - system           the built-in prompt templates under assets/prompts (read only)
 *
 * The hub is never a back door: a row is listed only when the user may select the page that
 * owns it (a page that shows the section, the scripts page, the memory page, the agentic chat
 * settings page), and it is editable only with update access on that page and on the hub page.
 * Registry entries whose section, script or rule is gone are orphans, listed (read only) only
 * on request.
 *
 * Reads only. Every dynamic value in a statement is a bound parameter; the few identifiers that
 * cannot be bound come from fixed lists in this class.
 */
class LlmPromptCatalogService
{
    const SOURCE_STYLE_FIELD = 'style_field';
    const SOURCE_SCRIPT = 'llm_script';
    const SOURCE_MEMORY_RULE = 'llm_memory_rule';
    const SOURCE_PERSONA = 'agentic_persona';
    const SOURCE_SYSTEM = 'system';

    /** Registry slots the script and memory rule services use. */
    const SLOT_SCRIPT = 'script';
    const SLOT_MEMORY_RULE = 'memory_rule';

    /** Scripts and memory rules keep one language-neutral stream, under language id 1. */
    const LANGUAGE_NEUTRAL = 1;

    const READ_ONLY_SYSTEM = 'system_prompt';
    const READ_ONLY_PERSONA = 'persona_link_only';
    const READ_ONLY_NO_ACCESS = 'no_access';
    const READ_ONLY_ORPHANED = 'orphaned';

    const DEFAULT_LIMIT = 200;
    const MAX_LIMIT = 500;
    const MAX_QUERY_LENGTH = 200;
    const MAX_FILTER_LENGTH = 100;
    const MAX_MATCHES = 3;
    const SNIPPET_RADIUS = 60;
    const PREVIEW_LENGTH = 200;
    /** Longest IN (...) list sent in one statement. */
    const IN_CHUNK = 500;

    /** Agentic chat plugin: where its personas live (its constants are not loaded without the plugin). */
    const PERSONA_PLUGIN = 'llm_agentic_chat';
    const PERSONA_PAGE = 'sh_module_llm_agentic_chat';
    const PERSONA_FIELD = 'agentic_chat_personas';

    const DEFAULT_PLUGIN = 'LLM';

    /**
     * The languages of a section field (`s`, `f`, `l`, `sft` and `sf` = styles_fields joined): one
     * that has a text, or one the CMS offers for the field (a translatable field, display = 1: every
     * site language; a property, display = 0: language 1 only).
     */
    const CMS_LANGUAGE_CONDITION = '(sft.id_sections IS NOT NULL
        OR (sf.id_fields IS NOT NULL AND ((f.display = 1 AND l.id > 1) OR (f.display = 0 AND l.id = 1))))';

    /** Style name => plugin label. The one place that knows which plugin a style belongs to. */
    const STYLE_PLUGINS = array(
        'llmChat' => 'LLM',
        'llmFormRecord' => 'LLM',
        'llmFormLog' => 'LLM',
        'llmFormLogList' => 'LLM',
        'llmResponse' => 'LLM',
        'agenticChat' => 'Agentic chat',
        'therapyChat' => 'Therapy chat',
        'therapistDashboard' => 'Therapy chat',
    );

    /** @var object SelfHelp services container */
    private $services;

    /** @var object Core database handle */
    private $db;

    /** @var object Core ACL */
    private $acl;

    /** @var LlmPromptLabAccessService */
    private $access_service;

    /** @var LlmPromptAssetLoader */
    private $asset_loader;

    /** @var array<int, int[]> section id => host page ids (per request) */
    private $host_pages = array();

    /** @var array<string, int> page keyword => page id, 0 when there is no such page (per request) */
    private $page_ids = array();

    /** @var array<string, bool> user:page:mode => allowed (per request) */
    private $access_memo = array();

    /** @var array<int, string> page id => keyword (per request) */
    private $page_keywords = array();

    /** @var array<int, string> prompt version id => template_raw (per request) */
    private $version_contents = array();

    /**
     * @param object $services SelfHelp services container
     * @param LlmPromptLabAccessService|null $access_service Host page lookups (default: real)
     * @param LlmPromptAssetLoader|null $asset_loader System prompt files (default: real)
     */
    public function __construct($services, $access_service = null, $asset_loader = null)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->acl = $services->get_acl();
        $this->access_service = $access_service ?: new LlmPromptLabAccessService($services);
        $this->asset_loader = $asset_loader ?: new LlmPromptAssetLoader();
    }

    /**
     * The source types in the order the list sorts them.
     *
     * @return string[]
     */
    public static function sourceTypes()
    {
        return array(
            self::SOURCE_STYLE_FIELD,
            self::SOURCE_SCRIPT,
            self::SOURCE_MEMORY_RULE,
            self::SOURCE_PERSONA,
            self::SOURCE_SYSTEM,
        );
    }

    /**
     * Parse and validate a row key. The browser sends keys back; nothing else about a row
     * (label, url, type) is ever believed.
     *
     * Format `<sourceType>:<ownerId>:<slot>:<languageId>`. Scripts and memory rules are
     * language neutral (language id 1, fixed slot); system prompts and personas have owner 0
     * and language 0, and carry the asset key or persona key as slot.
     *
     * @param mixed $key
     * @return array{sourceType: string, ownerId: int, slot: string, languageId: int}
     * @throws InvalidArgumentException When the key is malformed
     */
    public static function parseKey($key)
    {
        if (!is_string($key) || strlen($key) > 300 || !preg_match(
            '/^(style_field|llm_script|llm_memory_rule|agentic_persona|system):([0-9]{1,10}):([A-Za-z0-9_.\-]{1,128}):([0-9]{1,10})$/D',
            $key,
            $m
        )) {
            throw new InvalidArgumentException('Malformed prompt key');
        }

        $parsed = array(
            'sourceType' => $m[1],
            'ownerId' => (int)$m[2],
            'slot' => $m[3],
            'languageId' => (int)$m[4],
        );

        switch ($parsed['sourceType']) {
            case self::SOURCE_STYLE_FIELD:
                $valid = $parsed['ownerId'] > 0 && $parsed['languageId'] > 0
                    && preg_match('/^[A-Za-z0-9_]{1,64}$/', $parsed['slot']) === 1;
                break;
            case self::SOURCE_SCRIPT:
                $valid = $parsed['ownerId'] > 0 && $parsed['languageId'] === self::LANGUAGE_NEUTRAL
                    && $parsed['slot'] === self::SLOT_SCRIPT;
                break;
            case self::SOURCE_MEMORY_RULE:
                $valid = $parsed['ownerId'] > 0 && $parsed['languageId'] === self::LANGUAGE_NEUTRAL
                    && $parsed['slot'] === self::SLOT_MEMORY_RULE;
                break;
            default:
                $valid = $parsed['ownerId'] === 0 && $parsed['languageId'] === 0;
        }
        if (!$valid) {
            throw new InvalidArgumentException('Malformed prompt key');
        }

        return $parsed;
    }

    /**
     * @param string $source_type
     * @param int $owner_id
     * @param string $slot
     * @param int $language_id
     * @return string Canonical key (what parseKey() accepts)
     */
    public static function buildKey($source_type, $owner_id, $slot, $language_id)
    {
        return $source_type . ':' . (int)$owner_id . ':' . $slot . ':' . (int)$language_id;
    }

    /**
     * Prompts the user may see, filtered, sorted and cut to one page.
     *
     * Rows carry a preview, never the full content (that is getPrompt()). Facets list the values
     * a filter can take for the current search text, whatever the other filters say, so the
     * filter boxes stay usable after one of them is set.
     *
     * @param array $filters q, sourceType, plugin, style, pageId, languageId, includeOrphaned, limit, offset
     * @param int $user_id
     * @return array{rows: array[], total: int, facets: array}
     * @throws InvalidArgumentException On a filter value that is not valid
     */
    public function listPrompts(array $filters, $user_id)
    {
        $f = $this->normalizeFilters($filters);
        $records = $this->collect((int)$user_id, $f['q'], null, $f['includeOrphaned']);
        $this->attachPages($records);

        $facets = $this->buildFacets($records);

        $records = array_values(array_filter($records, function ($record) use ($f) {
            return $this->matchesFilters($record, $f);
        }));

        $sort_keys = array();
        foreach ($records as $i => $record) {
            $sort_keys[$i] = $this->sortKey($record);
        }
        $order = array_keys($records);
        usort($order, function ($a, $b) use ($sort_keys) {
            return $this->compareSortKeys($sort_keys[$a], $sort_keys[$b]);
        });

        $total = count($order);
        $shown = array_slice($order, $f['offset'], $f['limit']);
        $this->loadStyleFieldContents($records, $shown);

        $rows = array();
        foreach ($shown as $i) {
            $rows[] = $this->toRow($records[$i], $f['q']);
        }

        return array('rows' => $rows, 'total' => $total, 'facets' => $facets);
    }

    /**
     * One prompt with its full content and version list.
     *
     * @param string $key
     * @param int $user_id
     * @return array|null The row plus content, contentHash, versions[] (and meta for style fields);
     *                    null when there is no such prompt or the user may not see it.
     * @throws InvalidArgumentException When the key is malformed
     */
    public function getPrompt($key, $user_id)
    {
        $record = $this->findRecord($key, (int)$user_id);
        if ($record === null) {
            return null;
        }
        $records = array($record);
        $this->attachPages($records);
        $record = $records[0];

        $row = $this->toRow($record, '');
        $row['content'] = $record['content'];
        $row['contentHash'] = hash('sha256', $record['content']);
        $row['versions'] = $this->listVersions($record['stream']);
        if ($record['sourceType'] === self::SOURCE_STYLE_FIELD) {
            $row['meta'] = $this->decodeMeta($record['meta'] ?? null);
            $row['languages'] = $this->fieldLanguages($record['ownerId'], $record['slot']);
        }

        return $row;
    }

    /**
     * The languages a section's prompt field has in the CMS, with the key of each, so the editor can
     * show and save all of them side by side.
     *
     * @param int $section_id
     * @param string $field_name
     * @return array<int, array{id: int, code: string, key: string}> Ordered by language id
     */
    private function fieldLanguages($section_id, $field_name)
    {
        $languages = array();
        foreach ($this->rows(
            "SELECT l.id, l.locale
             FROM sections s
             INNER JOIN fields f ON f.name = :field
             INNER JOIN fieldType ft ON ft.id = f.id_type AND ft.name = :field_type
             INNER JOIN languages l
             LEFT JOIN sections_fields_translation sft ON sft.id_sections = s.id AND sft.id_fields = f.id
                 AND sft.id_languages = l.id AND sft.id_genders = :gender
             LEFT JOIN styles_fields sf ON sf.id_styles = s.id_styles AND sf.id_fields = f.id
             WHERE s.id = :section AND " . self::CMS_LANGUAGE_CONDITION . '
             ORDER BY l.id',
            array(':field' => $field_name, ':field_type' => 'llm_prompt', ':gender' => 1, ':section' => (int)$section_id)
        ) as $r) {
            $language_id = (int)$r['id'];
            $languages[] = array(
                'id' => $language_id,
                'code' => (string)$r['locale'],
                'key' => self::buildKey(self::SOURCE_STYLE_FIELD, $section_id, $field_name, $language_id),
            );
        }
        return $languages;
    }

    /**
     * Content of one version of a prompt, only if the version belongs to the prompt's own stream.
     *
     * @param string $key
     * @param int $version_id
     * @param int $user_id
     * @return array{version_no: int, template_raw: string, created_at: ?string, change_note: ?string}|null
     * @throws InvalidArgumentException When the key is malformed
     */
    public function getVersion($key, $version_id, $user_id)
    {
        $record = $this->findRecord($key, (int)$user_id);
        if ($record === null || $record['stream'] === null || (int)$version_id <= 0) {
            return null;
        }

        $row = $this->db->query_db_first(
            'SELECT v.version_no, v.template_raw, v.created_at, v.change_note
             FROM llm_prompt_versions v
             WHERE v.id = :version_id AND v.id_llm_prompt_locales = :locale_id',
            array(
                ':version_id' => (int)$version_id,
                ':locale_id' => (int)$record['stream']['locale_id'],
            )
        );
        if (!is_array($row)) {
            return null;
        }

        return array(
            'version_no' => (int)$row['version_no'],
            'template_raw' => $this->clean($row['template_raw']),
            'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
            'change_note' => $row['change_note'] !== null ? $this->clean($row['change_note']) : null,
        );
    }

    // ------------------------------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------------------------------

    /**
     * @param array $filters
     * @return array{q: string, sourceType: string, plugin: string, style: string, pageId: int,
     *               languageId: int, hasLanguage: bool, includeOrphaned: bool, limit: int, offset: int}
     * @throws InvalidArgumentException
     */
    private function normalizeFilters(array $filters)
    {
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : '';

        $source_type = $filters['sourceType'] ?? '';
        if ($source_type === null) {
            $source_type = '';
        }
        if ($source_type !== '' && (!is_string($source_type) || !in_array($source_type, self::sourceTypes(), true))) {
            throw new InvalidArgumentException('Unknown source type');
        }

        $has_language = isset($filters['languageId']) && $filters['languageId'] !== '' && $filters['languageId'] !== null;

        return array(
            'q' => mb_substr($q, 0, self::MAX_QUERY_LENGTH),
            'sourceType' => $source_type,
            'plugin' => $this->filterString($filters, 'plugin'),
            'style' => $this->filterString($filters, 'style'),
            'pageId' => $this->filterInt($filters, 'pageId', 0),
            'languageId' => $this->filterInt($filters, 'languageId', 0),
            'hasLanguage' => $has_language,
            'includeOrphaned' => in_array($filters['includeOrphaned'] ?? false, array(true, 1, '1', 'true'), true),
            'limit' => max(1, min(self::MAX_LIMIT, $this->filterInt($filters, 'limit', self::DEFAULT_LIMIT))),
            'offset' => $this->filterInt($filters, 'offset', 0),
        );
    }

    private function filterString(array $filters, $name)
    {
        $value = $filters[$name] ?? '';
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value) || mb_strlen($value) > self::MAX_FILTER_LENGTH) {
            throw new InvalidArgumentException('Invalid filter: ' . $name);
        }
        return $value;
    }

    private function filterInt(array $filters, $name, $default)
    {
        $value = $filters[$name] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,9}$/', $value)) {
            return (int)$value;
        }
        throw new InvalidArgumentException('Invalid filter: ' . $name);
    }

    private function matchesFilters(array $record, array $f)
    {
        if ($f['sourceType'] !== '' && $record['sourceType'] !== $f['sourceType']) {
            return false;
        }
        if ($f['plugin'] !== '' && $record['plugin'] !== $f['plugin']) {
            return false;
        }
        if ($f['style'] !== '' && ($record['styleName'] ?? null) !== $f['style']) {
            return false;
        }
        if ($f['hasLanguage'] && $record['languageId'] !== $f['languageId']) {
            return false;
        }
        if ($f['pageId'] > 0 && !in_array($f['pageId'], $record['hostPageIds'], true)) {
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Collecting prompts from the sources
    // ------------------------------------------------------------------------------------------

    /**
     * All prompts of all sources the user may see, as internal records (full content included).
     *
     * @param int $user_id
     * @param string $q Search text ('' = none). Applies to the content and to the title.
     * @param array|null $only Parsed key: collect only the prompt it names
     * @param bool $include_orphaned
     * @return array[]
     */
    private function collect($user_id, $q, $only, $include_orphaned)
    {
        $hub_page = $this->pageId(LLM_PROMPTS_PAGE_KEYWORD);
        if (!$this->allowed($hub_page, 'select', $user_id)) {
            return array();
        }

        $ctx = array(
            'user_id' => $user_id,
            'hub_update' => $this->allowed($hub_page, 'update', $user_id),
            'q' => $q,
            'only' => $only,
            'include_orphaned' => $include_orphaned,
        );
        $streams = $this->loadStreams($only);

        $records = array_merge(
            $this->collectStyleFields($ctx, $streams),
            $this->collectScripts($ctx, $streams),
            $this->collectMemoryRules($ctx, $streams),
            $this->collectPersonas($ctx),
            $this->collectSystemPrompts($ctx)
        );
        if ($include_orphaned) {
            $records = array_merge($records, $this->collectRegistryOrphans($ctx, $streams));
        }

        return $records;
    }

    /**
     * The one record a key names, orphans included, or null when it does not exist or the user may not see it.
     *
     * @param string $key
     * @param int $user_id
     * @return array|null Internal record
     * @throws InvalidArgumentException When the key is malformed
     */
    private function findRecord($key, $user_id)
    {
        $parsed = self::parseKey($key);
        $canonical = self::buildKey($parsed['sourceType'], $parsed['ownerId'], $parsed['slot'], $parsed['languageId']);

        foreach ($this->collect($user_id, '', $parsed, true) as $record) {
            if ($record['key'] === $canonical) {
                return $record;
            }
        }
        return null;
    }

    /**
     * Prompts of `llm_prompt` fields. One row per (section, field, language).
     */
    private function collectStyleFields(array $ctx, array $streams)
    {
        $only = $ctx['only'];
        if ($only !== null && $only['sourceType'] !== self::SOURCE_STYLE_FIELD) {
            return array();
        }

        $params = array(':field_type' => 'llm_prompt', ':gender' => 1);
        if ($only === null) {
            // The list does not read the texts: it needs to know whether there is one, and the rows
            // it shows get theirs afterwards (loadStyleFieldContents)
            $sql = "SELECT sft.id_sections, sft.id_fields, sft.id_languages, COALESCE(LENGTH(sft.content), 0) AS content_bytes,
                    f.name AS field_name, s.name AS section_name, st.name AS style_name,
                    l.locale AS language_code
                FROM sections_fields_translation sft
                INNER JOIN fields f ON f.id = sft.id_fields
                INNER JOIN fieldType ft ON ft.id = f.id_type AND ft.name = :field_type
                INNER JOIN sections s ON s.id = sft.id_sections
                INNER JOIN styles st ON st.id = s.id_styles
                LEFT JOIN languages l ON l.id = sft.id_languages
                WHERE sft.id_genders = :gender";
        } else {
            // One prompt: also a CMS language of the field that has no text yet (no row), so
            // every language the CMS shows for the field can be opened and filled in here
            $sql = "SELECT s.id AS id_sections, f.id AS id_fields, l.id AS id_languages, sft.content, sft.meta,
                    f.name AS field_name, s.name AS section_name, st.name AS style_name,
                    l.locale AS language_code
                FROM sections s
                INNER JOIN styles st ON st.id = s.id_styles
                INNER JOIN fields f ON f.name = :only_field
                INNER JOIN fieldType ft ON ft.id = f.id_type AND ft.name = :field_type
                INNER JOIN languages l ON l.id = :only_language
                LEFT JOIN sections_fields_translation sft ON sft.id_sections = s.id AND sft.id_fields = f.id
                    AND sft.id_languages = l.id AND sft.id_genders = :gender
                LEFT JOIN styles_fields sf ON sf.id_styles = st.id AND sf.id_fields = f.id
                WHERE s.id = :only_section AND " . self::CMS_LANGUAGE_CONDITION;
            $params[':only_section'] = $only['ownerId'];
            $params[':only_field'] = $only['slot'];
            $params[':only_language'] = $only['languageId'];
        }
        if ($ctx['q'] !== '') {
            $sql .= " AND sft.content LIKE :q ESCAPE '!'";
            $params[':q'] = $this->likePattern($ctx['q']);
        }

        $found = $this->rows($sql, $params);
        $this->loadHostPages(array_column($found, 'id_sections'));

        $records = array();
        foreach ($found as $r) {
            $section_id = (int)$r['id_sections'];
            $field = (string)$r['field_name'];
            $language_id = (int)$r['id_languages'];
            $content = $only !== null ? $this->clean($r['content']) : null;
            $stream = $this->pickStream($streams, self::SOURCE_STYLE_FIELD, $section_id, $field, $language_id);

            // Empty fields are noise in the list, unless somebody once versioned them
            if ($only === null && (int)$r['content_bytes'] === 0 && $stream === null) {
                continue;
            }

            $hosts = $this->hostPages($section_id);
            $orphaned = empty($hosts);
            $visible = array();
            $can_update = false;
            foreach ($hosts as $page_id) {
                if ($this->allowed($page_id, 'select', $ctx['user_id'])) {
                    $visible[] = $page_id;
                }
                if ($this->allowed($page_id, 'update', $ctx['user_id'])) {
                    $can_update = true;
                }
            }
            if ($orphaned ? !$ctx['include_orphaned'] : empty($visible)) {
                continue;
            }

            $editable = !$orphaned && $ctx['hub_update'] && $can_update;
            $style_name = (string)$r['style_name'];
            $title = $this->humanize($field);

            $records[] = array(
                'key' => self::buildKey(self::SOURCE_STYLE_FIELD, $section_id, $field, $language_id),
                'sourceType' => self::SOURCE_STYLE_FIELD,
                'ownerId' => $section_id,
                'slot' => $field,
                'languageId' => $language_id,
                'languageCode' => $r['language_code'] !== null ? (string)$r['language_code'] : null,
                'plugin' => self::STYLE_PLUGINS[$style_name] ?? $style_name,
                'styleName' => $style_name,
                'title' => $title,
                'ownerLabel' => $this->sectionLabel($r['section_name'], $section_id, $style_name),
                'content' => $content,
                'meta' => $only !== null ? ($r['meta'] ?? null) : null,
                'stream' => $stream,
                'hostPageIds' => $visible,
                'orphaned' => $orphaned,
                'editable' => $editable,
                'readOnlyReason' => $orphaned ? self::READ_ONLY_ORPHANED : ($editable ? null : self::READ_ONLY_NO_ACCESS),
                'goTo' => array('type' => 'section', 'sectionId' => $section_id),
                'updatedFallback' => null,
            );
        }

        return $records;
    }

    /**
     * Prompts of LLM scripts (llm_scripts.script).
     */
    private function collectScripts(array $ctx, array $streams)
    {
        $only = $ctx['only'];
        if ($only !== null && $only['sourceType'] !== self::SOURCE_SCRIPT) {
            return array();
        }
        $page_id = $this->pageId(LLM_SCRIPTS_PAGE_KEYWORD);
        if (!$this->allowed($page_id, 'select', $ctx['user_id'])) {
            return array();
        }
        $editable = $ctx['hub_update'] && $this->allowed($page_id, 'update', $ctx['user_id']);

        $where = array();
        $params = array();
        if ($ctx['q'] !== '') {
            $where[] = "(script LIKE :q_script ESCAPE '!' OR name LIKE :q_name ESCAPE '!')";
            $params[':q_script'] = $this->likePattern($ctx['q']);
            $params[':q_name'] = $this->likePattern($ctx['q']);
        }
        if ($only !== null) {
            $where[] = 'id = :only_id';
            $params[':only_id'] = $only['ownerId'];
        }

        $records = array();
        foreach ($this->rows(
            'SELECT id, name, script, updated_at FROM llm_scripts' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params
        ) as $r) {
            $script_id = (int)$r['id'];
            $title = trim($this->clean($r['name']));
            $records[] = array(
                'key' => self::buildKey(self::SOURCE_SCRIPT, $script_id, self::SLOT_SCRIPT, self::LANGUAGE_NEUTRAL),
                'sourceType' => self::SOURCE_SCRIPT,
                'ownerId' => $script_id,
                'slot' => self::SLOT_SCRIPT,
                'languageId' => null,
                'languageCode' => null,
                'plugin' => self::DEFAULT_PLUGIN,
                'styleName' => null,
                'title' => $title !== '' ? $title : 'Script #' . $script_id,
                'ownerLabel' => 'LLM script #' . $script_id,
                'content' => $this->clean($r['script']),
                'meta' => null,
                'stream' => $this->pickStream($streams, self::SOURCE_SCRIPT, $script_id, self::SLOT_SCRIPT, null),
                'hostPageIds' => array(),
                'orphaned' => false,
                'editable' => $editable,
                'readOnlyReason' => $editable ? null : self::READ_ONLY_NO_ACCESS,
                'goTo' => array('type' => 'page', 'page' => LLM_SCRIPTS_PAGE_KEYWORD, 'query' => '?sid=' . $script_id),
                'updatedFallback' => $r['updated_at'] !== null ? (string)$r['updated_at'] : null,
            );
        }

        return $records;
    }

    /**
     * Prompts of memory rules: the rule instructions, which live only in the registry.
     */
    private function collectMemoryRules(array $ctx, array $streams)
    {
        $only = $ctx['only'];
        if ($only !== null && $only['sourceType'] !== self::SOURCE_MEMORY_RULE) {
            return array();
        }
        $page_id = $this->pageId(LLM_MEMORY_PAGE_KEYWORD);
        if (!$this->allowed($page_id, 'select', $ctx['user_id'])) {
            return array();
        }
        $editable = $ctx['hub_update'] && $this->allowed($page_id, 'update', $ctx['user_id']);

        $sql = 'SELECT id, label, enabled, updated_at FROM llm_memory_rules';
        $params = array();
        if ($only !== null) {
            $sql .= ' WHERE id = :only_id';
            $params[':only_id'] = $only['ownerId'];
        }
        $rules = $this->rows($sql, $params);

        $rule_streams = array();
        $version_ids = array();
        foreach ($rules as $r) {
            $stream = $this->pickStream($streams, self::SOURCE_MEMORY_RULE, (int)$r['id'], self::SLOT_MEMORY_RULE, null);
            $rule_streams[(int)$r['id']] = $stream;
            if ($stream !== null && $stream['active_version_id'] !== null) {
                $version_ids[] = $stream['active_version_id'];
            }
        }
        $this->loadVersionContents($version_ids);

        $records = array();
        foreach ($rules as $r) {
            $rule_id = (int)$r['id'];
            $stream = $rule_streams[$rule_id];
            $content = $stream !== null && $stream['active_version_id'] !== null
                ? ($this->version_contents[$stream['active_version_id']] ?? '')
                : '';
            $label = trim($this->clean($r['label']));
            $title = $label !== '' ? $label : 'Memory rule #' . $rule_id;
            if ($ctx['q'] !== '' && !$this->hitsQuery($ctx['q'], $content, $title)) {
                continue;
            }

            $records[] = array(
                'key' => self::buildKey(self::SOURCE_MEMORY_RULE, $rule_id, self::SLOT_MEMORY_RULE, self::LANGUAGE_NEUTRAL),
                'sourceType' => self::SOURCE_MEMORY_RULE,
                'ownerId' => $rule_id,
                'slot' => self::SLOT_MEMORY_RULE,
                'languageId' => null,
                'languageCode' => null,
                'plugin' => self::DEFAULT_PLUGIN,
                'styleName' => null,
                'title' => $title,
                'ownerLabel' => 'Memory rule #' . $rule_id . ((int)$r['enabled'] === 1 ? '' : ' (disabled)'),
                'content' => $content,
                'meta' => null,
                'stream' => $stream,
                'hostPageIds' => array(),
                'orphaned' => false,
                'editable' => $editable,
                'readOnlyReason' => $editable ? null : self::READ_ONLY_NO_ACCESS,
                'goTo' => array('type' => 'page', 'page' => LLM_MEMORY_PAGE_KEYWORD, 'query' => '?tab=rules&rule=' . $rule_id),
                'updatedFallback' => $r['updated_at'] !== null ? (string)$r['updated_at'] : null,
            );
        }

        return $records;
    }

    /**
     * Personas of the agentic chat plugin: link only. Nothing is read from the plugin's own tables;
     * the persona library is a page field of the plugin's settings page.
     */
    private function collectPersonas(array $ctx)
    {
        $only = $ctx['only'];
        if ($only !== null && $only['sourceType'] !== self::SOURCE_PERSONA) {
            return array();
        }

        $page_id = $this->pageId(self::PERSONA_PAGE);
        if ($page_id <= 0 || !$this->allowed($page_id, 'select', $ctx['user_id'])) {
            return array();
        }
        if (!$this->db->query_db_first('SELECT p.name FROM plugins p WHERE p.name = :name', array(':name' => self::PERSONA_PLUGIN))) {
            return array();
        }

        $row = $this->db->query_db_first(
            'SELECT pft.content
             FROM pages_fields_translation pft
             INNER JOIN fields f ON f.id = pft.id_fields
             WHERE pft.id_pages = :page_id AND f.name = :field AND pft.id_languages = :language_id',
            array(':page_id' => $page_id, ':field' => self::PERSONA_FIELD, ':language_id' => self::LANGUAGE_NEUTRAL)
        );
        if (!is_array($row) || trim((string)$row['content']) === '') {
            return array();
        }

        // Anything but a list of objects is a corrupted setting: no rows, no warning
        $items = json_decode((string)$row['content'], true);
        if (!is_array($items)) {
            return array();
        }

        $records = array();
        $seen = array();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['name']) || !is_scalar($item['name']) || trim((string)$item['name']) === '') {
                continue;
            }
            $name = trim($this->clean($item['name']));
            $key = $this->personaKey($item, $name, $seen);
            if ($only !== null && $only['slot'] !== $key) {
                continue;
            }
            $description = isset($item['description']) && is_scalar($item['description'])
                ? (string)$item['description']
                : (isset($item['instructions']) && is_scalar($item['instructions']) ? (string)$item['instructions'] : '');
            $content = $this->clean($description);
            if ($ctx['q'] !== '' && !$this->hitsQuery($ctx['q'], $content, $name)) {
                continue;
            }

            $records[] = array(
                'key' => self::buildKey(self::SOURCE_PERSONA, 0, $key, 0),
                'sourceType' => self::SOURCE_PERSONA,
                'ownerId' => 0,
                'slot' => $key,
                'languageId' => null,
                'languageCode' => null,
                'plugin' => 'Agentic chat',
                'styleName' => null,
                'title' => $name,
                'ownerLabel' => 'Agentic chat persona' . (array_key_exists('enabled', $item) && !$item['enabled'] ? ' (disabled)' : ''),
                'content' => $content,
                'meta' => null,
                'stream' => null,
                'hostPageIds' => array(),
                'orphaned' => false,
                'editable' => false,
                'readOnlyReason' => self::READ_ONLY_PERSONA,
                'goTo' => array('type' => 'page', 'page' => self::PERSONA_PAGE, 'query' => ''),
                'updatedFallback' => null,
            );
        }

        return $records;
    }

    /**
     * The built-in prompt templates (assets/prompts). Read only; the hub page ACL is the only gate.
     */
    private function collectSystemPrompts(array $ctx)
    {
        $only = $ctx['only'];
        if ($only !== null && $only['sourceType'] !== self::SOURCE_SYSTEM) {
            return array();
        }

        $records = array();
        foreach (LlmPromptAssetRegistry::getMap() as $asset_key => $relative_path) {
            if ($only !== null && $only['slot'] !== $asset_key) {
                continue;
            }
            try {
                $content = $this->clean($this->asset_loader->load($asset_key));
            } catch (Exception $e) {
                // A missing file is a broken install; the rest of the list must still work
                LlmErrorReporter::log('Loading a system prompt for the prompts hub', $e);
                continue;
            }
            $path = 'assets/prompts/' . ltrim((string)$relative_path, '/\\');
            if ($ctx['q'] !== '' && !$this->hitsQuery($ctx['q'], $content)) {
                continue;
            }

            $records[] = array(
                'key' => self::buildKey(self::SOURCE_SYSTEM, 0, $asset_key, 0),
                'sourceType' => self::SOURCE_SYSTEM,
                'ownerId' => 0,
                'slot' => $asset_key,
                'languageId' => null,
                'languageCode' => null,
                'plugin' => self::DEFAULT_PLUGIN,
                'styleName' => null,
                'title' => $asset_key,
                'ownerLabel' => $path,
                'assetPath' => $path,
                'content' => $content,
                'meta' => null,
                'stream' => null,
                'hostPageIds' => array(),
                'orphaned' => false,
                'editable' => false,
                'readOnlyReason' => self::READ_ONLY_SYSTEM,
                'goTo' => null,
                'updatedFallback' => null,
            );
        }

        return $records;
    }

    /**
     * Registry entries whose section, script or memory rule no longer exists. Their versions are
     * all that is left of the prompt. Listed read only, and only on request.
     */
    private function collectRegistryOrphans(array $ctx, array $streams)
    {
        $only = $ctx['only'];
        $candidates = array();

        foreach (array(
            self::SOURCE_STYLE_FIELD => 'sections',
            self::SOURCE_SCRIPT => 'llm_scripts',
            self::SOURCE_MEMORY_RULE => 'llm_memory_rules',
        ) as $type => $table) {
            if (($only !== null && $only['sourceType'] !== $type) || empty($streams[$type])) {
                continue;
            }
            $owner_ids = array_keys($streams[$type]);
            foreach (array_diff($owner_ids, $this->existingIds($table, $owner_ids)) as $owner_id) {
                foreach ($streams[$type][$owner_id] as $slot => $list) {
                    if ($type === self::SOURCE_STYLE_FIELD) {
                        foreach ($list as $stream) {
                            // A style field stream always belongs to a language
                            if ($stream['language_id'] !== null && $stream['language_id'] > 0) {
                                $candidates[] = array($type, $owner_id, $slot, $stream['language_id'], $stream);
                            }
                        }
                    } elseif ($slot === ($type === self::SOURCE_SCRIPT ? self::SLOT_SCRIPT : self::SLOT_MEMORY_RULE)) {
                        $candidates[] = array($type, $owner_id, $slot, self::LANGUAGE_NEUTRAL, $this->pickAgnosticStream($list));
                    }
                }
            }
        }

        $version_ids = array();
        foreach ($candidates as $candidate) {
            if ($candidate[4]['active_version_id'] !== null) {
                $version_ids[] = $candidate[4]['active_version_id'];
            }
        }
        $this->loadVersionContents($version_ids);

        // The registry knows the language id only: the locale code comes from the languages table
        $style_language_ids = array();
        foreach ($candidates as $candidate) {
            if ($candidate[0] === self::SOURCE_STYLE_FIELD) {
                $style_language_ids[] = $candidate[3];
            }
        }
        $language_codes = $style_language_ids ? $this->languageCodes($style_language_ids) : array();

        $records = array();
        foreach ($candidates as $candidate) {
            list($type, $owner_id, $slot, $language_id, $stream) = $candidate;
            $content = $stream['active_version_id'] !== null ? ($this->version_contents[$stream['active_version_id']] ?? '') : '';
            $is_style = $type === self::SOURCE_STYLE_FIELD;
            $entry_title = trim($this->clean($stream['entry_title']));
            $title = $is_style ? $this->humanize($slot) : ($entry_title !== '' ? $entry_title : ($type === self::SOURCE_SCRIPT ? 'Script #' : 'Memory rule #') . $owner_id);
            if ($ctx['q'] !== '' && !$this->hitsQuery($ctx['q'], $content, $title, $slot)) {
                continue;
            }

            $records[] = array(
                'key' => self::buildKey($type, $owner_id, $slot, $language_id),
                'sourceType' => $type,
                'ownerId' => $owner_id,
                'slot' => $slot,
                'languageId' => $is_style ? $language_id : null,
                'languageCode' => $is_style && isset($language_codes[$language_id]) ? $language_codes[$language_id] : null,
                'plugin' => self::DEFAULT_PLUGIN,
                'styleName' => null,
                'title' => $title,
                'ownerLabel' => 'Deleted ' . ($is_style ? 'section' : ($type === self::SOURCE_SCRIPT ? 'script' : 'memory rule')) . ' #' . $owner_id,
                'content' => $content,
                'meta' => null,
                'stream' => $stream,
                'hostPageIds' => array(),
                'orphaned' => true,
                'editable' => false,
                'readOnlyReason' => self::READ_ONLY_ORPHANED,
                'goTo' => null,
                'updatedFallback' => null,
            );
        }

        return $records;
    }

    // ------------------------------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------------------------------

    /**
     * Registry streams (one per entry and language) of the three registry-backed owner types.
     *
     * @param array|null $only Parsed key: load only the streams of that owner and slot
     * @return array<string, array<int, array<string, array[]>>> type => owner id => slot => streams
     */
    private function loadStreams($only)
    {
        $types = array(self::SOURCE_STYLE_FIELD, self::SOURCE_SCRIPT, self::SOURCE_MEMORY_RULE);
        if ($only !== null) {
            if (!in_array($only['sourceType'], $types, true)) {
                return array();
            }
            $types = array($only['sourceType']);
        }

        $params = array(':lookup_type' => 'llm_prompt_owner_types');
        $marks = array();
        foreach ($types as $i => $type) {
            $marks[] = ':type' . $i;
            $params[':type' . $i] = $type;
        }
        $sql = "SELECT ot.lookup_code AS owner_type, e.id AS entry_id, e.owner_id, e.prompt_slot, e.title AS entry_title,
                    l.id AS locale_id, l.id_languages AS language_id, l.active_version_id, l.active_version_no,
                    v.created_at AS version_created_at, u.name AS author
                FROM llm_prompt_entries e
                INNER JOIN lookups ot ON ot.id = e.id_llm_prompt_owner_types AND ot.type_code = :lookup_type
                INNER JOIN llm_prompt_locales l ON l.id_llm_prompt_entries = e.id
                LEFT JOIN llm_prompt_versions v ON v.id = l.active_version_id
                LEFT JOIN users u ON u.id = v.id_users_created
                WHERE ot.lookup_code IN (" . implode(', ', $marks) . ')';
        if ($only !== null) {
            $sql .= ' AND e.owner_id = :only_owner AND e.prompt_slot = :only_slot';
            $params[':only_owner'] = $only['ownerId'];
            $params[':only_slot'] = $only['slot'];
        }

        $streams = array();
        foreach ($this->rows($sql, $params) as $r) {
            $active_id = $r['active_version_id'] !== null ? (int)$r['active_version_id'] : 0;
            $streams[(string)$r['owner_type']][(int)$r['owner_id']][(string)$r['prompt_slot']][] = array(
                'entry_id' => (int)$r['entry_id'],
                'entry_title' => $r['entry_title'],
                'locale_id' => (int)$r['locale_id'],
                'language_id' => $r['language_id'] !== null ? (int)$r['language_id'] : null,
                'active_version_id' => $active_id > 0 ? $active_id : null,
                'active_version_no' => $active_id > 0 ? (int)$r['active_version_no'] : null,
                'updated_at' => $r['version_created_at'] !== null ? (string)$r['version_created_at'] : null,
                'author' => $r['author'] !== null ? (string)$r['author'] : null,
            );
        }

        return $streams;
    }

    /**
     * @param array $streams loadStreams()
     * @param int|null $language_id Null for the language-neutral owners (scripts, memory rules)
     * @return array|null
     */
    private function pickStream(array $streams, $type, $owner_id, $slot, $language_id)
    {
        $list = $streams[$type][$owner_id][$slot] ?? array();
        if (empty($list)) {
            return null;
        }
        if ($language_id === null) {
            return $this->pickAgnosticStream($list);
        }
        foreach ($list as $stream) {
            if ($stream['language_id'] === (int)$language_id) {
                return $stream;
            }
        }
        return null;
    }

    /**
     * The stream a language-neutral owner shows. The same choice
     * LlmPromptRegistryService::resolveLocale() makes, minus its write: the neutral stream (language
     * id 1), else an older stream without language, else the one with the most versions. The registry
     * moves that stream to language 1 the next time the owner is saved.
     *
     * @param array[] $list Streams of one entry
     * @return array
     */
    private function pickAgnosticStream(array $list)
    {
        foreach ($list as $stream) {
            if ($stream['language_id'] === self::LANGUAGE_NEUTRAL) {
                return $stream;
            }
        }
        foreach ($list as $stream) {
            if ($stream['language_id'] === null) {
                return $stream;
            }
        }
        usort($list, function ($a, $b) {
            $by_versions = ((int)$b['active_version_no']) <=> ((int)$a['active_version_no']);
            return $by_versions !== 0 ? $by_versions : $b['locale_id'] <=> $a['locale_id'];
        });
        return $list[0];
    }

    /**
     * Fill the version text cache for the given prompt version ids.
     *
     * @param int[] $version_ids
     */
    private function loadVersionContents(array $version_ids)
    {
        $missing = array_values(array_diff(array_unique(array_map('intval', $version_ids)), array_keys($this->version_contents)));
        foreach (array_chunk($missing, self::IN_CHUNK) as $chunk) {
            list($in, $params) = $this->inList('version', $chunk);
            foreach ($this->rows("SELECT id, template_raw FROM llm_prompt_versions WHERE id IN ({$in})", $params) as $r) {
                $this->version_contents[(int)$r['id']] = $this->clean($r['template_raw']);
            }
        }
    }

    /**
     * Versions of one stream, newest first, without their text.
     *
     * @param array|null $stream
     * @return array[]
     */
    private function listVersions($stream)
    {
        if ($stream === null) {
            return array();
        }

        $versions = array();
        foreach ($this->rows(
            'SELECT v.id, v.version_no, v.created_at, v.change_note, u.name AS author
             FROM llm_prompt_versions v
             LEFT JOIN users u ON u.id = v.id_users_created
             WHERE v.id_llm_prompt_locales = :locale_id
             ORDER BY v.version_no DESC',
            array(':locale_id' => (int)$stream['locale_id'])
        ) as $r) {
            $versions[] = array(
                'id' => (int)$r['id'],
                'no' => (int)$r['version_no'],
                'created_at' => $r['created_at'] !== null ? (string)$r['created_at'] : null,
                'author' => $r['author'] !== null ? $this->clean($r['author']) : null,
                'change_note' => $r['change_note'] !== null ? $this->clean($r['change_note']) : null,
            );
        }
        return $versions;
    }

    /**
     * Locale codes of languages.
     *
     * @param int[] $ids Language ids
     * @return array<int, string> language id => locale code (ids that do not exist are missing)
     */
    private function languageCodes(array $ids)
    {
        $codes = array();
        foreach (array_chunk(array_unique(array_map('intval', $ids)), self::IN_CHUNK) as $chunk) {
            list($in, $params) = $this->inList('id', $chunk);
            foreach ($this->rows("SELECT id, locale FROM languages WHERE id IN ({$in})", $params) as $r) {
                $codes[(int)$r['id']] = (string)$r['locale'];
            }
        }
        return $codes;
    }

    /**
     * Which of the given ids are rows of a table. The table name comes from a fixed list.
     *
     * @param string $table sections, llm_scripts or llm_memory_rules
     * @param int[] $ids
     * @return int[]
     */
    private function existingIds($table, array $ids)
    {
        if (!in_array($table, array('sections', 'llm_scripts', 'llm_memory_rules'), true)) {
            throw new InvalidArgumentException('Unknown table');
        }

        $found = array();
        foreach (array_chunk(array_map('intval', $ids), self::IN_CHUNK) as $chunk) {
            list($in, $params) = $this->inList('id', $chunk);
            foreach ($this->rows("SELECT id FROM {$table} WHERE id IN ({$in})", $params) as $r) {
                $found[] = (int)$r['id'];
            }
        }
        return $found;
    }

    // ------------------------------------------------------------------------------------------
    // Pages and access
    // ------------------------------------------------------------------------------------------

    /**
     * @param int $section_id
     * @return int[] Pages that show the section (all of them, whatever the user may access)
     */
    private function hostPages($section_id)
    {
        if (!isset($this->host_pages[$section_id])) {
            $this->host_pages[$section_id] = array_map('intval', $this->access_service->hostPagesForSection($section_id));
        }
        return $this->host_pages[$section_id];
    }

    /**
     * Look up the pages of many sections in one walk, so hostPages() answers from memory.
     *
     * @param array $section_ids
     */
    private function loadHostPages(array $section_ids)
    {
        $missing = array();
        foreach ($section_ids as $id) {
            if (!isset($this->host_pages[(int)$id])) {
                $missing[(int)$id] = (int)$id;
            }
        }
        if (empty($missing)) {
            return;
        }

        foreach ($this->access_service->hostPagesForSections(array_values($missing)) as $id => $pages) {
            $this->host_pages[$id] = array_map('intval', $pages);
        }
    }

    /**
     * Read the text of the style fields in the rows a list shows. The list reads no text for the
     * rest: of all the prompts of a site, a page shows at most MAX_LIMIT.
     *
     * @param array[] $records All records of the list (by reference)
     * @param int[] $shown Indexes of the records on the page
     */
    private function loadStyleFieldContents(array &$records, array $shown)
    {
        $sections = array();
        foreach ($shown as $i) {
            if ($records[$i]['content'] === null) {
                $sections[$records[$i]['ownerId']] = $records[$i]['ownerId'];
            }
        }

        $contents = array();
        foreach (array_chunk(array_values($sections), self::IN_CHUNK) as $chunk) {
            list($in, $params) = $this->inList('section', $chunk);
            $params[':field_type'] = 'llm_prompt';
            $params[':gender'] = 1;
            foreach ($this->rows(
                "SELECT sft.id_sections, sft.id_languages, sft.content, f.name AS field_name
                 FROM sections_fields_translation sft
                 INNER JOIN fields f ON f.id = sft.id_fields
                 INNER JOIN fieldType ft ON ft.id = f.id_type AND ft.name = :field_type
                 WHERE sft.id_genders = :gender AND sft.id_sections IN ({$in})",
                $params
            ) as $r) {
                $key = self::buildKey(self::SOURCE_STYLE_FIELD, (int)$r['id_sections'], (string)$r['field_name'], (int)$r['id_languages']);
                $contents[$key] = $this->clean($r['content']);
            }
        }

        foreach ($shown as $i) {
            if ($records[$i]['content'] === null) {
                $records[$i]['content'] = $contents[$records[$i]['key']] ?? '';
            }
        }
    }

    /**
     * @param string $keyword
     * @return int Page id, 0 when the page does not exist
     */
    private function pageId($keyword)
    {
        if (!isset($this->page_ids[$keyword])) {
            $id = $this->db->fetch_page_id_by_keyword($keyword);
            $this->page_ids[$keyword] = $id ? (int)$id : 0;
        }
        return $this->page_ids[$keyword];
    }

    /**
     * @param int $page_id
     * @param string $mode select or update
     * @param int $user_id
     * @return bool
     */
    private function allowed($page_id, $mode, $user_id)
    {
        $page_id = (int)$page_id;
        if ($page_id <= 0 || !in_array($mode, array('select', 'update'), true)) {
            return false;
        }
        $memo_key = $user_id . ':' . $page_id . ':' . $mode;
        if (!isset($this->access_memo[$memo_key])) {
            $this->access_memo[$memo_key] = $mode === 'select'
                ? (bool)$this->acl->has_access_select($user_id, $page_id)
                : (bool)$this->acl->has_access_update($user_id, $page_id);
        }
        return $this->access_memo[$memo_key];
    }

    /**
     * Give style field records the labels of their pages: hostPages [{id, label}] and pageLabel.
     *
     * @param array[] $records
     */
    private function attachPages(array &$records)
    {
        $page_ids = array();
        foreach ($records as $record) {
            foreach ($record['hostPageIds'] as $page_id) {
                $page_ids[$page_id] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($page_ids), array_keys($this->page_keywords)));
        foreach (array_chunk($missing, self::IN_CHUNK) as $chunk) {
            list($in, $params) = $this->inList('page', $chunk);
            foreach ($this->rows("SELECT id, keyword FROM pages WHERE id IN ({$in})", $params) as $r) {
                $this->page_keywords[(int)$r['id']] = (string)$r['keyword'];
            }
        }

        foreach ($records as $i => $record) {
            $pages = array();
            foreach ($record['hostPageIds'] as $page_id) {
                $pages[] = array('id' => $page_id, 'label' => $this->page_keywords[$page_id] ?? 'Page #' . $page_id);
            }
            $records[$i]['hostPages'] = $pages;
            $records[$i]['pageLabel'] = $pages ? $pages[0]['label'] : null;
        }
    }

    /**
     * URL of the page that shows the prompt in its own editor, or null.
     *
     * @param array $record
     * @return string|null
     */
    private function goToUrl(array $record)
    {
        $goto = $record['goTo'] ?? null;
        if ($goto === null || $record['orphaned']) {
            return null;
        }

        if ($goto['type'] === 'page') {
            $base = $this->services->get_router()->get_link_url($goto['page']);
            return $base ? $base . $goto['query'] : null;
        }

        // A section is opened on the first page that shows it and that the user may select
        foreach ($record['hostPageIds'] as $page_id) {
            $root = LlmCmsLinkHelper::rootSectionOnPage($this->db, $goto['sectionId'], $page_id);
            if ($root !== null) {
                $url = LlmCmsLinkHelper::sectionUrl($this->services, $page_id, $root, $goto['sectionId']);
                if ($url) {
                    return $url;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------------------------------

    /**
     * @param array $record Internal record (attachPages() already applied)
     * @param string $q Search text for the snippets
     * @return array One row of the list
     */
    private function toRow(array $record, $q)
    {
        $stream = $record['stream'];
        $content = $record['content'];

        return array(
            'key' => $record['key'],
            'sourceType' => $record['sourceType'],
            'plugin' => $record['plugin'],
            'title' => $record['title'],
            'ownerLabel' => $record['ownerLabel'],
            'pageLabel' => $record['pageLabel'],
            'languageId' => $record['languageId'],
            'languageCode' => $record['languageCode'],
            'preview' => $this->preview($content),
            'contentLength' => mb_strlen($content),
            'activeVersionNo' => $stream !== null ? $stream['active_version_no'] : null,
            'activeVersionId' => $stream !== null ? $stream['active_version_id'] : null,
            'updatedAt' => $stream !== null && $stream['updated_at'] !== null ? $stream['updated_at'] : $record['updatedFallback'],
            'updatedBy' => $stream !== null ? $stream['author'] : null,
            'editable' => $record['editable'],
            'readOnlyReason' => $record['readOnlyReason'],
            'orphaned' => $record['orphaned'],
            'goToUrl' => $this->goToUrl($record),
            'matches' => $q === '' ? array() : $this->snippets($content, $q),
            'ownerId' => $record['ownerId'],
            'promptSlot' => $record['slot'],
            'styleName' => $record['styleName'],
            'assetPath' => $record['assetPath'] ?? null,
            'hostPages' => $record['hostPages'],
        );
    }

    /**
     * Filter values over the records, for the current search text.
     *
     * @param array[] $records
     * @return array{plugins: string[], styles: string[], languages: array[], sourceTypes: string[], pages: array[]}
     */
    private function buildFacets(array $records)
    {
        $plugins = array();
        $styles = array();
        $languages = array();
        $source_types = array();
        $pages = array();
        foreach ($records as $record) {
            $plugins[$record['plugin']] = true;
            $source_types[$record['sourceType']] = true;
            if ($record['styleName'] !== null && $record['styleName'] !== '') {
                $styles[$record['styleName']] = true;
            }
            if ($record['languageId'] !== null) {
                $languages[$record['languageId']] = $record['languageCode'] !== null ? $record['languageCode'] : (string)$record['languageId'];
            }
            foreach ($record['hostPages'] as $page) {
                $pages[$page['id']] = $page['label'];
            }
        }

        $plugins = array_keys($plugins);
        $styles = array_keys($styles);
        natcasesort($plugins);
        natcasesort($styles);
        natcasesort($languages);
        natcasesort($pages);

        $language_list = array();
        foreach ($languages as $id => $code) {
            $language_list[] = array('id' => $id, 'code' => $code);
        }
        $page_list = array();
        foreach ($pages as $id => $label) {
            $page_list[] = array('id' => $id, 'label' => $label);
        }

        return array(
            'plugins' => array_values($plugins),
            'styles' => array_values($styles),
            'languages' => $language_list,
            'sourceTypes' => array_values(array_intersect(self::sourceTypes(), array_keys($source_types))),
            'pages' => $page_list,
        );
    }

    /**
     * Sort key: source type order, then page label (rows without one last), title, language.
     * Ties end on the key, so the order never changes between requests.
     */
    private function sortKey(array $record)
    {
        $page = $record['pageLabel'];
        return array(
            (int)array_search($record['sourceType'], self::sourceTypes(), true),
            $page === null ? 1 : 0,
            mb_strtolower((string)$page),
            mb_strtolower($record['title']),
            (int)$record['languageId'],
            $record['key'],
        );
    }

    private function compareSortKeys(array $a, array $b)
    {
        foreach ($a as $i => $part) {
            $order = is_int($part) ? $part <=> $b[$i] : strcmp($part, $b[$i]);
            if ($order !== 0) {
                return $order;
            }
        }
        return 0;
    }

    // ------------------------------------------------------------------------------------------
    // Text helpers
    // ------------------------------------------------------------------------------------------

    /**
     * Plain-text start of a prompt: tags and Markdown markers removed, whitespace collapsed.
     *
     * @param string $content
     * @return string
     */
    private function preview($content)
    {
        // A tag starts with a letter or a slash; "a < b and c > d" is text
        $text = preg_replace('/<\/?[A-Za-z][^<>\n]{0,200}>/u', ' ', $content);
        $text = preg_replace('/^[ \t]{0,3}(?:#{1,6}[ \t]+|>[ \t]?|[-*+][ \t]+|[0-9]+[.)][ \t]+)/mu', '', (string)$text);
        $text = str_replace(array('**', '__', '`'), '', (string)$text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, self::PREVIEW_LENGTH);
    }

    /**
     * Up to three places where the text occurs (case-insensitive), with a little text around each.
     *
     * @param string $content
     * @param string $q
     * @return array<int, array{line: int, text: string}>
     */
    private function snippets($content, $q)
    {
        $matches = array();
        $q_length = mb_strlen($q);
        $total = mb_strlen($content);
        $offset = 0;

        while ($q_length > 0 && count($matches) < self::MAX_MATCHES) {
            $pos = mb_stripos($content, $q, $offset);
            if ($pos === false) {
                break;
            }
            $start = max(0, $pos - self::SNIPPET_RADIUS);
            $end = min($total, $pos + $q_length + self::SNIPPET_RADIUS);
            $text = trim((string)preg_replace('/\s+/u', ' ', mb_substr($content, $start, $end - $start)));

            $matches[] = array(
                'line' => substr_count(mb_substr($content, 0, $pos), "\n") + 1,
                'text' => ($start > 0 ? '…' : '') . $text . ($end < $total ? '…' : ''),
            );
            // The next hit starts after this snippet, so two snippets never show the same text
            $offset = max($pos + $q_length, $end);
        }

        return $matches;
    }

    /**
     * Whether any of the texts contains the search text, the way a case-insensitive LIKE would.
     *
     * @param string $q
     * @param string ...$texts
     * @return bool
     */
    private function hitsQuery($q, ...$texts)
    {
        foreach ($texts as $text) {
            if (mb_stripos((string)$text, $q) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * LIKE pattern for a literal search text. The escape character is "!" because a backslash means
     * different things to MySQL string literals and to LIKE.
     */
    private function likePattern($q)
    {
        return '%' . str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $q) . '%';
    }

    private function humanize($slot)
    {
        return ucfirst(str_replace('_', ' ', $slot));
    }

    private function sectionLabel($name, $section_id, $style_name)
    {
        $name = trim($this->clean($name));
        return ($name !== '' ? $name : 'Section') . ' (section ' . $section_id . ', style ' . $style_name . ')';
    }

    /**
     * Stable key of a persona: its own key, else made from its name. A key already taken in the
     * list gets a number, like the agentic chat plugin does.
     *
     * @param array $item Persona from the setting
     * @param string $name
     * @param array $seen Keys handed out so far (by reference)
     * @return string
     */
    private function personaKey(array $item, $name, array &$seen)
    {
        $key = '';
        foreach (array(isset($item['key']) && is_scalar($item['key']) ? (string)$item['key'] : '', $name) as $source) {
            $key = trim((string)preg_replace('/[^a-z0-9_-]+/', '_', strtolower($source)), '_-');
            if ($key !== '') {
                break;
            }
        }
        $key = $key !== '' ? substr($key, 0, 100) : 'persona';

        $unique = $key;
        for ($i = 2; isset($seen[$unique]); $i++) {
            $unique = $key . '_' . $i;
        }
        $seen[$unique] = true;
        return $unique;
    }

    /**
     * @param mixed $meta Raw sections_fields_translation.meta
     * @return array|null
     */
    private function decodeMeta($meta)
    {
        if (!is_string($meta) || $meta === '') {
            return null;
        }
        $decoded = json_decode($meta, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Text safe to put into JSON: bytes that are not valid UTF-8 are replaced.
     *
     * @param mixed $text
     * @return string
     */
    private function clean($text)
    {
        return mb_scrub((string)$text, 'UTF-8');
    }

    // ------------------------------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------------------------------

    /**
     * Run a query. A failed statement is an error here (the core helper answers false), because a
     * prompt list that silently misses a source would look like prompts that were deleted.
     *
     * @param string $sql
     * @param array $params
     * @return array[]
     * @throws RuntimeException
     */
    private function rows($sql, array $params = array())
    {
        $result = $this->db->query_db($sql, $params);
        if (!is_array($result)) {
            throw new RuntimeException('A prompt catalog query failed');
        }
        return $result;
    }

    /**
     * @param string $prefix Parameter name prefix
     * @param int[] $values
     * @return array{0: string, 1: array} Placeholder list and its parameters
     */
    private function inList($prefix, array $values)
    {
        $marks = array();
        $params = array();
        foreach (array_values($values) as $i => $value) {
            $marks[] = ':' . $prefix . $i;
            $params[':' . $prefix . $i] = (int)$value;
        }
        return array(implode(', ', $marks), $params);
    }
}
?>
