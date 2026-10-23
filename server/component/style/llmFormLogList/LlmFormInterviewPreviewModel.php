<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/LlmFormLogListModel.php";

/**
 * Read-only llmFormLogList model for Prompt Lab interview previews.
 *
 * Instantiated model-only (no component/controller) through
 * createIsolated(): the pinned params resolve a fresh "new record" URL
 * state, so an unrelated ?record=...&sid=... on the playground ajax
 * request can never preload a participant row (params shadow $_GET).
 * rebuildInterviewChildren still recomputes the eligibility tree for the
 * runtime calculation. The overrides map stands in for the CMS field
 * values the playground overrides (edited draft prompt, model
 * parameters, interview flags). Participant writes are disabled
 * outright.
 */
class LlmFormInterviewPreviewModel extends LlmFormLogListModel
{
    /** @var array Field-name => value overrides (raw CMS field names). */
    private $preview_overrides = array();

    /**
     * Preview model with the URL record state pinned to "new": the
     * parent constructor resolves $params before $_GET, so these values
     * shadow any record/sid query params on the ajax request.
     *
     * @param array $variables Test draft / external context seeded as
     *  the entry record.
     */
    public static function createIsolated($services, $section_id, $id_page, array $variables)
    {
        return new self(
            $services,
            $section_id,
            array(
                self::URL_RECORD_PARAM => 'new',
                self::URL_SECTION_PARAM => (int)$section_id,
            ),
            $id_page,
            $variables
        );
    }

    /**
     * @param array $overrides Raw CMS field values to replace, keyed by
     *  field name (e.g. llm_interview_context, llm_model, data_config).
     */
    public function setInterviewPreviewOverrides(array $overrides)
    {
        $this->preview_overrides = $overrides;
    }

    public function getInterviewContextTemplate()
    {
        if (array_key_exists('llm_interview_context', $this->preview_overrides)) {
            return (string)$this->preview_overrides['llm_interview_context'];
        }
        return parent::getInterviewContextTemplate();
    }

    public function getConversationScopeTemplate()
    {
        if (array_key_exists('llm_conversation_scope', $this->preview_overrides)) {
            return (string)$this->preview_overrides['llm_conversation_scope'];
        }
        return parent::getConversationScopeTemplate();
    }

    public function get_db_field($key, $default = "")
    {
        if (array_key_exists($key, $this->preview_overrides)) {
            return $this->preview_overrides[$key];
        }
        return parent::get_db_field($key, $default);
    }

    /**
     * Raw CMS field reads go through the playground overrides first, so
     * data_config/scope/context/condition resolve against the edited
     * descriptor exactly like interpolated get_db_field values do.
     */
    protected function rawFieldValue($key)
    {
        if (array_key_exists($key, $this->preview_overrides)) {
            return $this->preview_overrides[$key];
        }
        return parent::rawFieldValue($key);
    }

    /**
     * The preview always evaluates the runtime path: the playground runs
     * inside a CMS session but never renders CMS chrome, and
     * participant writes stay disabled via the save/update guards.
     */
    public function is_cms_page()
    {
        return false;
    }

    /**
     * Runtime calculation only: the preview must rebuild the eligibility
     * tree even though the Prompt Lab runs inside a CMS session.
     */
    public function rebuildInterviewChildren(array $draft = array())
    {
        $this->rebuildInterviewChildrenForDraft($draft);
    }

    public function save_user_input($user_input)
    {
        throw new Exception('Interview preview model is read-only');
    }

    public function update_user_input($user_input, $record_id)
    {
        throw new Exception('Interview preview model is read-only');
    }
}
?>
