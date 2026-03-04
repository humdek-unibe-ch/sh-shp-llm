<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/formUserInput/FormUserInputModel.php";

/**
 * Model for LLM form styles (llmFormRecord and llmFormLog).
 * Extends the core FormUserInputModel with LLM-specific configuration.
 */
class LlmFormModel extends FormUserInputModel
{
    private $llm_enabled;
    private $llm_model;
    private $llm_temperature;
    private $llm_max_tokens;
    private $llm_context;
    private $llm_show_previous_result;
    private $llm_result_field_name;
    private $llm_result_meta_field_name;
    private $llm_result_placement;
    private $llm_result_panel;
    private $llm_result_title;
    private $llm_result_closable;
    private $llm_result_css;
    private $llm_result_css_mobile;
    private $llm_show_errors;
    private $llm_retry_enabled;
    private $llm_retry_label;
    private $llm_regenerate_enabled;
    private $llm_regenerate_label;
    private $llm_generating_text;

    /* Constructors ***********************************************************/

    /**
     * @param object $services
     * @param int $id
     * @param array $params
     * @param number $id_page
     * @param array $entry_record
     */
    public function __construct($services, $id, $params, $id_page, $entry_record)
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);

        $this->llm_enabled = $this->get_db_field('llm_enabled', '1');
        $this->llm_model = $this->get_db_field('llm_model', '');
        $this->llm_temperature = $this->get_db_field('llm_temperature', '1');
        $this->llm_max_tokens = $this->get_db_field('llm_max_tokens', '2048');
        $this->llm_context = $this->get_db_field('llm_context', '');
        $this->llm_show_previous_result = $this->get_db_field('llm_show_previous_result', '1');
        $this->llm_result_field_name = $this->get_db_field('llm_result_field_name', 'llm_result');
        $this->llm_result_meta_field_name = $this->get_db_field('llm_result_meta_field_name', 'llm_result_meta');
        $this->llm_result_placement = $this->get_db_field('llm_result_placement', 'bottom');
        $this->llm_result_panel = $this->get_db_field('llm_result_panel', 'card');
        $this->llm_result_title = $this->get_db_field('llm_result_title', 'Result');
        $this->llm_result_closable = $this->get_db_field('llm_result_closable', '1');
        $this->llm_result_css = $this->get_db_field('llm_result_css', '');
        $this->llm_result_css_mobile = $this->get_db_field('llm_result_css_mobile', '');
        $this->llm_show_errors = $this->get_db_field('llm_show_errors', '1');
        $this->llm_retry_enabled = $this->get_db_field('llm_retry_enabled', '1');
        $this->llm_retry_label = $this->get_db_field('llm_retry_label', 'Retry');
        $this->llm_regenerate_enabled = $this->get_db_field('llm_regenerate_enabled', '1');
        $this->llm_regenerate_label = $this->get_db_field('llm_regenerate_label', 'Regenerate');
        $this->llm_generating_text = $this->get_db_field('llm_generating_text', 'Generating response...');
    }

    /* Public Getters *********************************************************/

    public function isLlmEnabled()
    {
        return $this->llm_enabled === '1';
    }

    public function getLlmModel()
    {
        if (!empty($this->llm_model)) {
            return $this->llm_model;
        }
        return defined('LLM_DEFAULT_MODEL') ? LLM_DEFAULT_MODEL : 'qwen3-vl-8b-instruct';
    }

    public function getLlmTemperature()
    {
        return floatval($this->llm_temperature);
    }

    public function getLlmMaxTokens()
    {
        return intval($this->llm_max_tokens);
    }

    public function getLlmContext()
    {
        return $this->llm_context;
    }

    public function isShowPreviousResult()
    {
        return $this->llm_show_previous_result === '1';
    }

    public function getLlmResultFieldName()
    {
        return $this->llm_result_field_name;
    }

    public function getLlmResultMetaFieldName()
    {
        return $this->llm_result_meta_field_name;
    }

    public function getLlmResultPlacement()
    {
        return $this->llm_result_placement;
    }

    public function getLlmResultPanel()
    {
        return $this->llm_result_panel;
    }

    public function getLlmResultTitle()
    {
        return $this->llm_result_title;
    }

    public function isLlmResultClosable()
    {
        return $this->llm_result_closable === '1';
    }

    public function getLlmResultCss()
    {
        return $this->llm_result_css;
    }

    public function getLlmResultCssMobile()
    {
        return $this->llm_result_css_mobile;
    }

    public function isShowErrors()
    {
        return $this->llm_show_errors === '1';
    }

    public function isRetryEnabled()
    {
        return $this->llm_retry_enabled === '1';
    }

    public function getRetryLabel()
    {
        return $this->llm_retry_label;
    }

    public function isRegenerateEnabled()
    {
        return $this->llm_regenerate_enabled === '1';
    }

    public function getRegenerateLabel()
    {
        return $this->llm_regenerate_label;
    }

    public function getGeneratingText()
    {
        return $this->llm_generating_text;
    }

    /**
     * Get the previous LLM result from the stored record data.
     *
     * @return string|null The previous LLM result or null
     */
    public function getPreviousLlmResult()
    {
        if (!$this->isShowPreviousResult()) {
            return null;
        }
        $field_name = $this->getLlmResultFieldName();
        $entry_data = $this->get_entry_record_data();
        if (is_array($entry_data) && isset($entry_data[$field_name])) {
            return $entry_data[$field_name];
        }
        return null;
    }

    /**
     * Get the previous LLM result metadata.
     *
     * @return array|null Parsed metadata or null
     */
    public function getPreviousLlmMeta()
    {
        if (!$this->isShowPreviousResult()) {
            return null;
        }
        $field_name = $this->getLlmResultMetaFieldName();
        $entry_data = $this->get_entry_record_data();
        if (is_array($entry_data) && isset($entry_data[$field_name])) {
            $meta = json_decode($entry_data[$field_name], true);
            return is_array($meta) ? $meta : null;
        }
        return null;
    }

    /**
     * Get the user's selected language code.
     *
     * @return string Language code (e.g. 'en', 'de')
     */
    public function getUserLanguage()
    {
        $locale = $_SESSION['user_language_locale'] ?? 'en-GB';
        return substr($locale, 0, 2);
    }

    /**
     * Get all LLM-specific configuration as an array for the React frontend.
     *
     * @return array
     */
    public function getLlmConfig()
    {
        return [
            'llmEnabled' => $this->isLlmEnabled(),
            'llmModel' => $this->getLlmModel(),
            'llmTemperature' => $this->getLlmTemperature(),
            'llmMaxTokens' => $this->getLlmMaxTokens(),
            'llmResultPlacement' => $this->getLlmResultPlacement(),
            'llmResultPanel' => $this->getLlmResultPanel(),
            'llmResultTitle' => $this->getLlmResultTitle(),
            'llmResultClosable' => $this->isLlmResultClosable(),
            'llmResultCss' => $this->getLlmResultCss(),
            'llmResultCssMobile' => $this->getLlmResultCssMobile(),
            'llmShowErrors' => $this->isShowErrors(),
            'llmRetryEnabled' => $this->isRetryEnabled(),
            'llmRetryLabel' => $this->getRetryLabel(),
            'llmRegenerateEnabled' => $this->isRegenerateEnabled(),
            'llmRegenerateLabel' => $this->getRegenerateLabel(),
            'llmGeneratingText' => $this->getGeneratingText(),
            'llmShowPreviousResult' => $this->isShowPreviousResult(),
            'llmResultFieldName' => $this->getLlmResultFieldName(),
            'previousResult' => $this->getPreviousLlmResult(),
            'previousMeta' => $this->getPreviousLlmMeta(),
            'userLanguage' => $this->getUserLanguage(),
            'sectionId' => $this->section_id,
        ];
    }

    /**
     * Get the entry record data for the current user (record mode).
     *
     * @return array|null
     */
    private function get_entry_record_data()
    {
        if (isset($this->entry_record) && is_array($this->entry_record)) {
            return $this->entry_record;
        }
        return null;
    }

    /**
     * Get the section ID
     *
     * @return int Section ID
     */
    public function get_section_id()
    {
        return $this->section_id;
    }
}
?>
