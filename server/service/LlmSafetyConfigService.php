<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

/**
 * LLM Safety Config Service
 *
 * Resolves the danger detection settings for one LLM section. Danger detection
 * is always on; its keywords, notification emails and blocked message are set
 * globally on the LLM settings page (sh_module_llm, "Safety"). A section value
 * replaces the global value; an empty section value uses it. When the global
 * value is empty too, the built-in defaults from globals.php apply.
 *
 * @author SelfHelp Team
 */
class LlmSafetyConfigService extends BaseLlmService
{
    /** @var object Style model (or prompt-lab runtime) with the raw danger getters */
    private $model;

    /** @var array|null Cached global settings (field name => value) */
    private $global_fields = null;

    /**
     * @param object $services SelfHelp services container
     * @param object $model Style model providing getDangerKeywords() etc. (any may be missing)
     */
    public function __construct($services, $model)
    {
        parent::__construct($services);
        $this->model = $model;
    }

    /**
     * Danger keywords: lowercased (UTF-8), trimmed, unique, non-empty.
     *
     * @return string[]
     */
    public function getKeywords()
    {
        $keywords = $this->parseKeywords($this->modelValue('getDangerKeywords', ''));
        if (empty($keywords)) {
            $keywords = $this->parseKeywords($this->globalValue('danger_keywords'));
        }
        if (empty($keywords)) {
            $keywords = $this->parseKeywords(LLM_DEFAULT_DANGER_KEYWORDS);
        }
        return $keywords;
    }

    /**
     * Email addresses to alert when danger is detected (may be empty).
     *
     * @return string[]
     */
    public function getNotificationEmails()
    {
        $emails = $this->modelValue('getDangerNotificationEmails', array());
        $emails = is_array($emails) ? $this->parseEmails(implode("\n", $emails)) : $this->parseEmails($emails);
        if (empty($emails)) {
            $emails = $this->parseEmails($this->globalValue('danger_notification_emails'));
        }
        return $emails;
    }

    /**
     * Markdown message shown to the participant when danger is detected. Never empty.
     *
     * @return string
     */
    public function getBlockedMessage()
    {
        $message = trim((string)$this->modelValue('getDangerBlockedMessage', ''));
        if ($message === '') {
            $message = trim($this->globalValue('danger_blocked_message'));
        }
        return $message !== '' ? $message : LLM_DEFAULT_DANGER_BLOCKED_MESSAGE;
    }

    /**
     * Global settings from the LLM settings page in the participant's language,
     * with the default language as fallback. Empty when the page cannot be read.
     *
     * @return array Field name => value
     */
    protected function loadGlobalFields()
    {
        try {
            $page = $this->db->query_db_first("SELECT id FROM pages WHERE keyword = ?", [PAGE_LLM_CONFIG]);
            if (!$page) {
                return array();
            }
            $default_language = $this->db->get_default_language();
            $fields = $this->db->query_db_first(
                'CALL get_page_fields(?, ?, ?, ?, ?)',
                [$page['id'], $_SESSION['user_language'] ?? $default_language, $default_language, '', '']
            );
            return is_array($fields) ? $fields : array();
        } catch (Throwable $e) {
            $this->logError('Failed to read the global danger detection settings', $e);
            return array();
        }
    }

    /* Private Methods ********************************************************/

    private function globalValue($name)
    {
        if ($this->global_fields === null) {
            $this->global_fields = $this->loadGlobalFields();
        }
        return (string)($this->global_fields[$name] ?? '');
    }

    private function modelValue($getter, $default)
    {
        if (!is_object($this->model) || !method_exists($this->model, $getter)) {
            return $default;
        }
        $value = $this->model->$getter();
        return $value === null ? $default : $value;
    }

    private function parseKeywords($raw)
    {
        $keywords = array_map(function ($keyword) {
            return mb_strtolower(trim($keyword), 'UTF-8');
        }, explode(',', (string)$raw));
        return array_values(array_unique(array_filter($keywords, 'strlen')));
    }

    private function parseEmails($raw)
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\n;]+/', (string)$raw)), 'strlen'));
    }
}
?>
