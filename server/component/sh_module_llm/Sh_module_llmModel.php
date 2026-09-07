<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";
require_once __DIR__ . "/../../service/LlmService.php";
require_once __DIR__ . "/../../service/LlmModelCapabilities.php";

/**
 * Model for the LLM Settings module.
 * Reads configuration from the sh_module_llm page fields and exposes
 * them as structured data for the React settings UI.
 */
class Sh_module_llmModel extends BaseModel
{
    /** @var int Canonical language ID used for global plugin config storage */
    private const CONFIG_LANGUAGE_ID = 1;

    /** @var int|null The page ID of sh_module_llm */
    private $configPageId;

    /** @var array|null Cached page fields */
    private $pageFields;

    public function __construct($services)
    {
        parent::__construct($services);
        $this->configPageId = $this->db->fetch_page_id_by_keyword(PAGE_LLM_CONFIG);
    }

    /**
     * @return int|null Page ID of the sh_module_llm page
     */
    public function getConfigPageId()
    {
        return $this->configPageId;
    }

    /**
     * Load all page fields for the config page via stored procedure.
     * @return array Associative array of field_name => value
     */
    public function getPageFields()
    {
        if ($this->pageFields !== null) {
            return $this->pageFields;
        }

        $result = $this->db->query_db_first(
            "CALL get_page_fields(:id_page, :id_languages, :id_default_languages, '', '')",
            [
                'id_page' => $this->configPageId,
                'id_languages' => self::CONFIG_LANGUAGE_ID,
                'id_default_languages' => self::CONFIG_LANGUAGE_ID,
            ]
        );

        $this->pageFields = $result ?: [];
        return $this->pageFields;
    }

    /**
     * Get all settings organized by group with metadata for the React form.
     * @return array
     */
    public function getStructuredSettings()
    {
        $fields = $this->getPageFields();

        return [
            'api' => [
                'label' => 'API Configuration',
                'fields' => [
                    $this->buildField('llm_api_keys', $fields, 'json', 'API Keys',
                        'Configure one or more LLM API servers. Each server needs a name, base URL, and API key.'),
                ],
            ],
            'model_defaults' => [
                'label' => 'Model Defaults',
                'fields' => [
                    $this->buildField('llm_default_model', $fields, 'select-llm-model', 'Default Model',
                        'Default LLM model used when a component does not specify one.'),
                    $this->buildField('llm_temperature', $fields, 'text', 'Temperature',
                        'Controls randomness (0-2). Lower values produce more deterministic output.'),
                    $this->buildField('llm_max_tokens', $fields, 'number', 'Max Tokens',
                        'Maximum number of tokens to generate per response.'),
                    $this->buildField('llm_reasoning_effort', $fields, 'select', 'Reasoning Effort',
                        'Thinking depth for OpenAI / Anthropic reasoning models. Default leaves the provider default (usually medium/high). GPUStack ignores this until models support it.',
                        LlmModelCapabilities::getReasoningEffortModuleOptions($this->db)),
                    $this->buildField('llm_timeout', $fields, 'number', 'Timeout (seconds)',
                        'Request timeout in seconds for LLM API calls.'),
                ],
            ],
            'memory' => [
                'label' => 'Memory Configuration',
                'fields' => [
                    $this->buildField('llm_memory_enabled', $fields, 'checkbox', 'Enable Memory System',
                        'Enable the global user memory system for personalized AI responses.'),
                    $this->buildField('llm_memory_storage_mode', $fields, 'select', 'Storage Mode',
                        'How memory updates are persisted.',
                        $this->getStorageModeOptions()),
                ],
            ],
        ];
    }

    /**
     * Build a structured field descriptor for the settings UI.
     *
     * @param string      $name    Field name key.
     * @param array       $fields  All page field values.
     * @param string      $type    Field type (text, number, select, etc.).
     * @param string      $label   Human-readable label.
     * @param string      $help    Help text description.
     * @param array|null  $options Select options for dropdown fields.
     * @return array Structured field array for the React settings form.
     */
    private function buildField($name, $fields, $type, $label, $help, $options = null)
    {
        $field = [
            'name' => $name,
            'type' => $type,
            'label' => $label,
            'help' => $help,
            'value' => $fields[$name] ?? '',
        ];
        if ($options !== null) {
            $field['options'] = $options;
        }
        return $field;
    }

    /** @return array Select options for the memory storage mode dropdown, loaded from lookups. */
    private function getStorageModeOptions()
    {
        try {
            $lookups = $this->db->query_db(
                "SELECT lookup_code, lookup_value, lookup_description FROM lookups WHERE type_code = ? ORDER BY lookup_value",
                ['llmMemoryStorageMode']
            );
            $options = [];
            foreach ($lookups as $row) {
                $options[] = [
                    'value' => $row['lookup_code'],
                    'label' => $row['lookup_value'] . ' - ' . $row['lookup_description'],
                ];
            }
            return $options;
        } catch (Exception $e) {
            return [
                ['value' => 'memory_storage_both', 'label' => 'both'],
                ['value' => 'memory_storage_record', 'label' => 'record'],
                ['value' => 'memory_storage_log', 'label' => 'log'],
            ];
        }
    }

    /**
     * Save a single setting value back to pages_fields_translation.
     *
     * @param string $fieldName
     * @param string $value
     * @return bool
     */
    public function saveSetting($fieldName, $value)
    {
        $fid = $this->resolveFieldId($fieldName);
        if (!$fid) {
            return false;
        }

        // Ensure the config page is wired to this field (needed for get_page_fields)
        $this->ensurePageFieldLink($fid, $fieldName);

        $existing = $this->db->query_db_first(
            "SELECT id_pages FROM pages_fields_translation WHERE id_pages = ? AND id_fields = ? AND id_languages = ?",
            [$this->configPageId, $fid, self::CONFIG_LANGUAGE_ID]
        );

        if ($existing) {
            $this->db->execute_update_db(
                "UPDATE pages_fields_translation SET content = ? WHERE id_pages = ? AND id_fields = ? AND id_languages = ?",
                [$value, $this->configPageId, $fid, self::CONFIG_LANGUAGE_ID]
            );
        } else {
            $this->db->execute_update_db(
                "INSERT INTO pages_fields_translation (id_pages, id_fields, id_languages, content) VALUES (?, ?, ?, ?)",
                [$this->configPageId, $fid, self::CONFIG_LANGUAGE_ID, $value]
            );
        }

        $this->pageFields = null;

        if ($fieldName === 'llm_api_keys') {
            try {
                $llmService = new LlmService($this->services);
                $llmService->clearAvailableModelsCache();
            } catch (Exception $e) {
                // Non-fatal: next models fetch will refresh within TTL
            }
        }

        return true;
    }

    /**
     * Push module model/temperature/max_tokens into styles_fields.default_value
     * for LLM styles so new CMS sections are prefilled from current settings.
     * Existing section content is left untouched.
     *
     * @return void
     */
    public function syncStyleFieldDefaultsFromModuleSettings()
    {
        $fields = $this->getPageFields();
        $map = [
            'llm_model' => trim((string)($fields['llm_default_model'] ?? '')),
            'llm_temperature' => trim((string)($fields['llm_temperature'] ?? '')),
            'llm_max_tokens' => trim((string)($fields['llm_max_tokens'] ?? '')),
            'llm_reasoning_effort' => trim((string)($fields['llm_reasoning_effort'] ?? '')),
        ];

        $styles = ['llmChat', 'llmFormRecord', 'llmFormLog'];
        foreach ($styles as $styleName) {
            foreach ($map as $fieldName => $value) {
                if ($value === '') {
                    continue;
                }
                $this->db->execute_update_db(
                    "UPDATE styles_fields sf
                     INNER JOIN styles s ON s.id = sf.id_styles
                     INNER JOIN fields f ON f.id = sf.id_fields
                     SET sf.default_value = ?
                     WHERE s.name = ? AND f.name = ?",
                    [$value, $styleName, $fieldName]
                );
            }
        }
    }

    /**
     * Resolve a fields.id by name, self-healing llm_default_model when missing
     * (legacy installs where the v1.0.0 field insert failed).
     *
     * @param string $fieldName
     * @return int|null
     */
    private function resolveFieldId($fieldName)
    {
        $fieldId = $this->db->query_db_first(
            "SELECT id FROM fields WHERE name = ?",
            [$fieldName]
        );
        if ($fieldId) {
            return (int)$fieldId['id'];
        }

        if ($fieldName !== 'llm_default_model') {
            return null;
        }

        return $this->ensureDefaultModelFieldExists();
    }

    /**
     * Create select-llm-model field type + llm_default_model field if absent.
     *
     * @return int|null New or existing field id
     */
    private function ensureDefaultModelFieldExists()
    {
        $type = $this->db->query_db_first(
            "SELECT id FROM fieldType WHERE name = ?",
            ['select-llm-model']
        );
        if (!$type) {
            $this->db->execute_update_db(
                "INSERT INTO fieldType (name, position) VALUES (?, ?)",
                ['select-llm-model', 7]
            );
            $type = $this->db->query_db_first(
                "SELECT id FROM fieldType WHERE name = ?",
                ['select-llm-model']
            );
        }
        if (!$type) {
            return null;
        }

        $this->db->execute_update_db(
            "INSERT IGNORE INTO fields (name, id_type, display) VALUES (?, ?, ?)",
            ['llm_default_model', $type['id'], 0]
        );

        $field = $this->db->query_db_first(
            "SELECT id FROM fields WHERE name = ?",
            ['llm_default_model']
        );
        return $field ? (int)$field['id'] : null;
    }

    /**
     * Ensure pages_fields (and pageType_fields) link exists for a config field.
     *
     * @param int $fieldId
     * @param string $fieldName
     * @return void
     */
    private function ensurePageFieldLink($fieldId, $fieldName)
    {
        if (!$this->configPageId || !$fieldId) {
            return;
        }

        $defaults = [
            'llm_default_model' => 'gpt-oss-120b',
            'llm_temperature' => '1',
            'llm_max_tokens' => '2048',
            'llm_timeout' => '30',
        ];
        $defaultValue = $defaults[$fieldName] ?? '';
        $help = $fieldName === 'llm_default_model'
            ? 'Default LLM model to use'
            : '';

        $existing = $this->db->query_db_first(
            "SELECT id_pages FROM pages_fields WHERE id_pages = ? AND id_fields = ?",
            [$this->configPageId, $fieldId]
        );
        if (!$existing) {
            $this->db->execute_update_db(
                "INSERT INTO pages_fields (id_pages, id_fields, default_value, help) VALUES (?, ?, ?, ?)",
                [$this->configPageId, $fieldId, $defaultValue, $help]
            );
        }

        $pageType = $this->db->query_db_first(
            "SELECT id FROM pageType WHERE name = ?",
            ['sh_module_llm']
        );
        if ($pageType) {
            $ptExisting = $this->db->query_db_first(
                "SELECT id_pageType FROM pageType_fields WHERE id_pageType = ? AND id_fields = ?",
                [$pageType['id'], $fieldId]
            );
            if (!$ptExisting) {
                $this->db->execute_update_db(
                    "INSERT INTO pageType_fields (id_pageType, id_fields, default_value, help) VALUES (?, ?, ?, ?)",
                    [$pageType['id'], $fieldId, $defaultValue, $help]
                );
            }
        }
    }
}
