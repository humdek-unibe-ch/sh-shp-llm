<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../../../component/style/StyleModel.php";
require_once __DIR__ . "/../../../service/LlmMemoryItems.php";
require_once __DIR__ . "/../../../service/LlmMemoryConfigService.php";
require_once __DIR__ . "/../../../service/LlmMemoryStorageService.php";
require_once __DIR__ . "/../../../service/LlmTransaction.php";
require_once __DIR__ . "/../../../service/LlmErrorReporter.php";

/**
 * The model of the llmMemoryEditor style: the logged-in participant's own memory,
 * shown as a list of items that the participant can add to, edit and delete
 * (doc/structured-memory-design.md, section 5).
 *
 * A change is made only through LlmMemoryItems::applyOps() and saved through
 * LlmMemoryStorageService::saveMemoryUpdate() (current row updated in place, one
 * history row appended, source_type user_edit). No rule runs and no LLM is called.
 *
 * Nothing in here reads a user id from the request: the memory is the one of the
 * session user. A request names the memory key it changes, and it must be one of the
 * keys the section is configured with (field memory_keys); each key is its own memory
 * (own row, own version), so the keys of one editor are saved independently.
 */
class LlmMemoryEditorModel extends StyleModel
{
    /* Constants **************************************************************/

    /** Outcomes of applyUserEdit(). */
    const STATUS_OK = 'ok';
    const STATUS_UNCHANGED = 'unchanged';
    const STATUS_FORBIDDEN = 'forbidden';
    const STATUS_CONFLICT = 'conflict';
    const STATUS_READ_ONLY = 'read_only';
    const STATUS_NOT_FOUND = 'not_found';
    const STATUS_INVALID = 'invalid';
    const STATUS_FAILED = 'failed';

    /** The actions of the editor and the CMS checkbox that allows each. */
    const ACTION_FLAGS = array(
        'add_item' => 'allow_add',
        'edit_item' => 'allow_edit',
        'delete_item' => 'allow_delete',
    );

    /** Participant-facing messages (fixed English, like the other errors of the plugin). */
    const MSG_FORBIDDEN = 'This action is not allowed.';
    const MSG_CONFLICT = 'Your memory was changed in the meantime. It has been reloaded, please try again.';
    const MSG_READ_ONLY = 'This memory has not been converted to a list of items yet, so it cannot be changed here.';
    const MSG_NOT_FOUND = 'This item no longer exists.';
    const MSG_EMPTY_TEXT = 'Please enter some text.';
    const MSG_INVALID = 'This change cannot be applied.';
    const MSG_FAILED = 'Your change could not be saved. Please try again.';

    /* Private Properties *****************************************************/

    /** @var LlmMemoryConfigService|null */
    private $config_service = null;

    /** @var LlmMemoryStorageService|null */
    private $storage_service = null;

    /** @var array|null Field memory_labels, decoded: label key => label */
    private $labels = null;

    /* Public Methods *********************************************************/

    /** @return int The section id of this style (a request addresses its section by it) */
    public function getSectionId()
    {
        return (int)$this->section_id;
    }

    /**
     * The id of the participant whose memory this is: the session user.
     *
     * @return int 0 for no session and for the guest account (all guests share
     *   one account, so there is no memory that belongs to a person)
     */
    public function getUserId()
    {
        $user_id = (int)($_SESSION['id_user'] ?? 0);
        if ($user_id <= 0 || (defined('GUEST_USER_ID') && $user_id === (int)GUEST_USER_ID)) {
            return 0;
        }
        return $user_id;
    }

    /**
     * The memory keys this section shows: its field memory_keys (comma-separated, from the CMS multi
     * select), else the module default key. Lower case like the keys of the memory rules, in the
     * order the field lists them, each once.
     *
     * @return string[]
     */
    public function getMemoryKeys()
    {
        $keys = array();
        foreach (explode(',', (string)$this->get_db_field('memory_keys', '')) as $key) {
            $key = strtolower(trim($key));
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return !empty($keys) ? $keys : array(strtolower($this->configService()->getDefaultMemoryKey()));
    }

    /**
     * The sections of a memory key as stored in the key registry (base labels). These are what the
     * memory is saved with (memory_text headings, item sections); [] = no sections, a flat list.
     *
     * @param string $memory_key
     * @return array List of ['code', 'label', 'hint']
     */
    public function getSections($memory_key)
    {
        return $this->configService()->getKeySections($memory_key);
    }

    /**
     * The sections of a memory key as the participant sees them: the label translated through the
     * field memory_labels ("<key>.<code>", else "<code>"), else the label of the key registry.
     *
     * @param string $memory_key
     * @return array List of ['code', 'label', 'hint']
     */
    public function getDisplaySections($memory_key)
    {
        $labels = $this->getLabels();
        $sections = $this->getSections($memory_key);
        foreach ($sections as $index => $section) {
            // label keys are compared in lower case, like memory keys
            $code = strtolower($section['code']);
            $sections[$index]['label'] = $labels[$memory_key . '.' . $code] ?? $labels[$code] ?? $section['label'];
        }
        return $sections;
    }

    /**
     * The heading of a memory key for the participant: memory_labels["<key>"], else the label of
     * the key registry, else the key.
     *
     * @param string $memory_key
     * @return string
     */
    public function getKeyLabel($memory_key)
    {
        $labels = $this->getLabels();
        if (isset($labels[$memory_key])) {
            return $labels[$memory_key];
        }
        $key = $this->configService()->getMemoryKeys()[$memory_key] ?? null;
        return $key !== null && $key['label'] !== '' ? $key['label'] : $memory_key;
    }

    /**
     * Does the CMS allow this action (the allow_add / allow_edit / allow_delete checkbox)?
     *
     * @param string $action add_item, edit_item or delete_item
     * @return bool
     */
    public function isActionAllowed($action)
    {
        return isset(self::ACTION_FLAGS[$action])
            && (int)$this->get_db_field(self::ACTION_FLAGS[$action], '1') !== 0;
    }

    /**
     * The stored memory of the participant under one key, read fresh.
     *
     * @param string $memory_key One of getMemoryKeys()
     * @return array LlmMemoryItems::fromRow() (kind, items, legacy_text, legacy_json) and `version`:
     *   what a client must send back with a change (memory_version of the row; the id of the
     *   latest history row when only history is stored, which has no memory_version; '' when
     *   there is no memory yet)
     */
    public function loadMemory($memory_key)
    {
        $user_id = $this->getUserId();
        $row = $user_id > 0 ? $this->storageService()->getEffectiveMemory($user_id, $memory_key) : null;

        $memory = LlmMemoryItems::fromRow($row);
        $memory['version'] = is_array($row)
            ? (string)(($row['memory_version'] ?? '') !== '' ? $row['memory_version'] : ($row['record_id'] ?? ''))
            : '';
        $memory['event_at'] = is_array($row) ? (string)($row['event_at'] ?? '') : '';
        return $memory;
    }

    /**
     * Add, edit or delete one item of the participant's memory under one key.
     *
     * Refused for a guest, for an action the section does not allow and for a key the section is
     * not configured with. The memory is
     * read again and must still be the one the client saw (`$client_version`); the change
     * is applied to its current items, so items it does not name stay exactly as they
     * are. Current row and history row are written in one transaction.
     *
     * @param string $action add_item, edit_item or delete_item
     * @param string $memory_key The memory key the change is for (from the request)
     * @param string $client_version The memory version the client was showing
     * @param array $input item_id (edit, delete), section (add), text (add, edit)
     * @return array ['status' => STATUS_*, 'message' => participant-facing text ('' when ok)]
     */
    public function applyUserEdit($action, $memory_key, $client_version, array $input)
    {
        $user_id = $this->getUserId();
        $memory_key = strtolower(trim((string)$memory_key));
        if ($user_id <= 0 || !$this->isActionAllowed($action) || !in_array($memory_key, $this->getMemoryKeys(), true)) {
            return $this->result(self::STATUS_FORBIDDEN, self::MSG_FORBIDDEN);
        }

        $sections = $this->getSections($memory_key);
        $memory = $this->loadMemory($memory_key);
        if ($memory['kind'] === 'legacy') {
            return $this->result(self::STATUS_READ_ONLY, self::MSG_READ_ONLY);
        }
        if ((string)$client_version !== $memory['version']) {
            return $this->result(self::STATUS_CONFLICT, self::MSG_CONFLICT);
        }

        $item_id = (string)($input['item_id'] ?? '');
        $section = (string)($input['section'] ?? '');
        $text = $input['text'] ?? '';
        if ($action === 'add_item') {
            $op = array('op' => 'add', 'section' => $section, 'text' => $text);
        } elseif ($action === 'edit_item') {
            $op = array('op' => 'update', 'id' => $item_id, 'text' => $text);
        } else {
            $op = array('op' => 'delete', 'id' => $item_id);
        }

        // the section the change is in, for the history row
        $affected_section = $section;
        if ($action !== 'add_item') {
            foreach ($memory['items'] as $item) {
                if ($item['id'] === $item_id) {
                    $affected_section = $item['section'];
                    break;
                }
            }
        }

        $applied = LlmMemoryItems::applyOps($memory['items'], array($op), $sections, LlmMemoryItems::SOURCE_USER);
        if ($applied['applied'] === 0) {
            return $this->resultForSkipped($applied['skipped'][0]['reason'] ?? 'invalid_op');
        }

        $item_ids = array_column($applied['items'], 'id');
        if ($action === 'add_item') {
            $item_id = (string)(array_values(array_diff($item_ids, array_column($memory['items'], 'id')))[0] ?? '');
        }

        $saved = $this->saveItems($user_id, $memory_key, $applied['items'], $sections, array(
            'action' => $action,
            'item_id' => $item_id,
            'section' => $affected_section,
            'event_at' => $memory['event_at'],
        ));
        return $saved
            ? $this->result(self::STATUS_OK, '')
            : $this->result(self::STATUS_FAILED, self::MSG_FAILED);
    }

    /* Protected Methods ******************************************************/

    /** @return LlmMemoryConfigService */
    protected function configService()
    {
        if ($this->config_service === null) {
            $this->config_service = new LlmMemoryConfigService($this->services);
        }
        return $this->config_service;
    }

    /* Private Methods ********************************************************/

    /**
     * The field memory_labels (translatable JSON): label key => label. A value that is not a JSON
     * object, and entries that are not non-empty text, are ignored.
     *
     * @return array<string, string>
     */
    private function getLabels()
    {
        if ($this->labels === null) {
            $this->labels = array();
            $raw = $this->get_db_field('memory_labels', '');
            $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
            foreach (is_array($decoded) ? $decoded : array() as $key => $label) {
                if (is_string($key) && is_scalar($label) && trim((string)$label) !== '') {
                    $this->labels[strtolower(trim($key))] = trim((string)$label);
                }
            }
        }
        return $this->labels;
    }

    /** @return LlmMemoryStorageService */
    private function storageService()
    {
        if ($this->storage_service === null) {
            $this->storage_service = new LlmMemoryStorageService($this->services, $this->configService());
        }
        return $this->storage_service;
    }

    /**
     * Write the items as the new memory (current row + history row) in one transaction.
     *
     * @param array $change action, item_id and section of the change, for the history row; event_at
     *   of the memory before the change ('' when there was none)
     * @return bool False when nothing was saved (the failure is logged)
     */
    private function saveItems($user_id, $memory_key, array $items, array $sections, array $change)
    {
        $target = 'the memory';
        foreach ($sections as $section) {
            if ($change['section'] !== '' && $section['code'] === $change['section']) {
                $target = '"' . ($section['label'] !== '' ? $section['label'] : $section['code']) . '"';
            }
        }
        if ($target === 'the memory' && $change['section'] !== '') {
            $target = '"' . $change['section'] . '"';
        }
        $verbs = array('add_item' => 'added an item to', 'edit_item' => 'edited an item in', 'delete_item' => 'deleted an item from');

        $memory_data = array(
            'memory_text' => LlmMemoryItems::renderText($items, $sections),
            'memory_object' => LlmMemoryItems::toMemoryObject($items),
            'flat_fields' => array(),
            'change_summary' => 'Participant ' . $verbs[$change['action']] . ' ' . $target,
        );
        $metadata = array(
            'rule_id' => 0,
            'source_type' => LLM_MEMORY_SOURCE_USER_EDIT,
            'source_ref' => json_encode(array(
                'action' => $change['action'],
                'section_id' => $this->getSectionId(),
                'item_id' => $change['item_id'],
            )),
            'payload_json' => array('action' => $change['action'], 'item_id' => $change['item_id'], 'section' => $change['section']),
            // keep the event time of the memory as it was: the worker drops a rule update whose event is
            // older than the stored one (isNewerEvent), and a queued change request must not be lost to
            // an edit; the worker applies its operations to the memory as stored, edit included
            'event_at' => $change['event_at'] !== '' ? $change['event_at'] : date('Y-m-d H:i:s'),
            'dedupe_key' => '',
        );

        $db = $this->services->get_db();
        try {
            LlmTransaction::begin($db);
            if (!$this->storageService()->saveMemoryUpdate($user_id, $memory_key, $memory_data, $metadata, $this->configService()->getStorageMode())) {
                throw new RuntimeException('The memory could not be saved');
            }
            LlmTransaction::commit($db);
            return true;
        } catch (Throwable $e) {
            // the user-input cache may hold the uncommitted rows the writes read back
            LlmTransaction::rollback($db, array($db->get_cache()::CACHE_TYPE_USER_INPUT));
            LlmErrorReporter::log('llmMemoryEditor save', $e);
            return false;
        }
    }

    /** @return array The outcome for an operation LlmMemoryItems::applyOps() skipped, by its reason */
    private function resultForSkipped($reason)
    {
        switch ($reason) {
            case 'unchanged':
                return $this->result(self::STATUS_UNCHANGED, '');
            case 'unknown_id':
                return $this->result(self::STATUS_NOT_FOUND, self::MSG_NOT_FOUND);
            case 'empty_text':
                return $this->result(self::STATUS_INVALID, self::MSG_EMPTY_TEXT);
            case 'text_too_long':
                return $this->result(self::STATUS_INVALID, 'The text is too long (at most ' . LlmMemoryItems::MAX_TEXT_LENGTH . ' characters).');
            default:
                return $this->result(self::STATUS_INVALID, self::MSG_INVALID);
        }
    }

    /** @return array */
    private function result($status, $message)
    {
        return array('status' => $status, 'message' => $message);
    }
}
