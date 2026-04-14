<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";
require_once __DIR__ . "/../../service/LlmService.php";

/**
 * Model for the LLM Settings module.
 * Reads configuration from the sh_module_llm page fields and exposes
 * them as structured data for the React settings UI.
 */
class Sh_module_llmModel extends BaseModel
{
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

        $langId = $_SESSION['user_language'] ?? 2;
        $defaultLangId = $_SESSION['language'] ?? 2;

        $result = $this->db->query_db_first(
            "CALL get_page_fields(:id_page, :id_languages, :id_default_languages, '', '')",
            [
                'id_page' => $this->configPageId,
                'id_languages' => $langId,
                'id_default_languages' => $defaultLangId,
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
        $langId = $_SESSION['user_language'] ?? 2;
        $fieldId = $this->db->query_db_first(
            "SELECT id FROM fields WHERE name = ?",
            [$fieldName]
        );

        if (!$fieldId) {
            return false;
        }

        $fid = $fieldId['id'];
        $existing = $this->db->query_db_first(
            "SELECT id_pages FROM pages_fields_translation WHERE id_pages = ? AND id_fields = ? AND id_languages = ?",
            [$this->configPageId, $fid, $langId]
        );

        if ($existing) {
            $this->db->execute_update_db(
                "UPDATE pages_fields_translation SET content = ? WHERE id_pages = ? AND id_fields = ? AND id_languages = ?",
                [$value, $this->configPageId, $fid, $langId]
            );
        } else {
            $this->db->execute_update_db(
                "INSERT INTO pages_fields_translation (id_pages, id_fields, id_languages, content) VALUES (?, ?, ?, ?)",
                [$this->configPageId, $fid, $langId, $value]
            );
        }

        $this->pageFields = null;
        return true;
    }
}
