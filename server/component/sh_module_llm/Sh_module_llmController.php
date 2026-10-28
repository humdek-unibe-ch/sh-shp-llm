<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../service/LlmService.php";
require_once __DIR__ . "/../../service/LlmCsrfService.php";
require_once __DIR__ . "/../../service/LlmSecretService.php";

/**
 * Controller for the LLM Settings module.
 * Handles API requests from the React settings UI.
 */
class Sh_module_llmController extends BaseController
{
    use LlmJsonResponseTrait;

    /** @var object ACL service */
    private $acl;

    /** @var int|null Settings page ID for ACL */
    private $pageId;

    public function __construct($model)
    {
        parent::__construct($model);
        $services = $model->get_services();
        $this->acl = $services->get_acl();
        $this->pageId = $services->get_db()->fetch_page_id_by_keyword(PAGE_LLM_CONFIG);
        $this->handleRequest();
    }

    /** Route the AJAX request to the appropriate config handler based on 'action'. */
    private function handleRequest()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if (!$action) {
            return;
        }

        switch ($action) {
            case 'get_config':
                $this->requireAccess('select');
                $this->handleGetConfig();
                break;
            case 'save_config':
                $this->requireAccess('update');
                $this->requirePostWithCsrf();
                $this->handleSaveConfig();
                break;
            case 'protect_keys':
                $this->requireAccess('update');
                $this->requirePostWithCsrf();
                $this->handleProtectKeys();
                break;
            case 'models':
                $this->requireAccess('select');
                $this->handleModels();
                break;
            default:
                $this->sendJsonResponse(['error' => 'Unknown action'], 400);
                break;
        }
    }

    /**
     * Enforce ACL access for the given mode; sends 403 and exits on failure.
     *
     * @param string $mode ACL mode (select, update, etc.).
     */
    private function requireAccess($mode)
    {
        if (!$this->checkAccess($mode)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            exit;
        }
    }

    /**
     * Changes are POST only and carry the session CSRF token (header
     * X-CSRF-Token); sends 405 / 403 and exits otherwise.
     */
    private function requirePostWithCsrf()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        if (!LlmCsrfService::verifyRequest()) {
            $this->sendJsonResponse(['error' => 'Invalid CSRF token. Reload the page and try again.'], 403);
        }
    }

    /**
     * @param string $mode ACL mode.
     * @return bool Whether current user has access.
     */
    private function checkAccess($mode)
    {
        if (!$this->pageId || !isset($_SESSION['id_user'])) {
            return false;
        }
        $method = 'has_access_' . $mode;
        return $this->acl->$method($_SESSION['id_user'], $this->pageId);
    }

    /** Return structured LLM settings and ACL flags as JSON. */
    private function handleGetConfig()
    {
        try {
            $settings = $this->model->getStructuredSettings();
            $this->sendJsonResponse([
                'settings' => $settings,
                'acl' => [
                    'select' => $this->checkAccess('select'),
                    'update' => $this->checkAccess('update'),
                ],
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate and persist whitelisted config fields from the JSON request
     * body. Every posted field is validated first; when one is not valid
     * nothing is saved and the messages are returned per field (422).
     */
    private function handleSaveConfig()
    {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['fields']) || !is_array($data['fields'])) {
                $this->sendJsonResponse(['error' => 'No fields provided'], 400);
                return;
            }

            $allowedFields = [
                'llm_api_keys', 'llm_default_model', 'llm_temperature',
                'llm_max_tokens', 'llm_reasoning_effort', 'llm_timeout',
                'llm_memory_enabled',
                'llm_memory_storage_mode',
            ];

            $posted = [];
            foreach ($data['fields'] as $name => $value) {
                if (in_array($name, $allowedFields, true)) {
                    $posted[$name] = $value;
                }
            }

            $validation = $this->model->validateSettings($posted);
            if (!empty($validation['errors'])) {
                $this->sendJsonResponse([
                    'success' => false,
                    'error' => 'Some settings are not valid. Nothing was saved.',
                    'errors' => $validation['errors'],
                ], 422);
                return;
            }

            $saved = [];
            $failed = [];
            $styleDefaultFields = ['llm_default_model', 'llm_temperature', 'llm_max_tokens', 'llm_reasoning_effort'];
            $shouldSyncStyleDefaults = false;

            foreach ($validation['values'] as $name => $value) {
                $ok = $this->model->saveSetting($name, $value);
                if ($ok) {
                    $saved[] = $name;
                    if (in_array($name, $styleDefaultFields, true)) {
                        $shouldSyncStyleDefaults = true;
                    }
                } else {
                    $failed[] = $name;
                }
            }

            if ($shouldSyncStyleDefaults) {
                $this->model->syncStyleFieldDefaultsFromModuleSettings();
            }

            if (!empty($saved)) {
                $this->logSettingsChange('LLM settings updated: ' . implode(', ', $saved));
            }

            $this->sendJsonResponse([
                'success' => empty($failed),
                'saved' => $saved,
                'failed' => $failed,
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /** Encrypt the API keys that are still stored as plain text. */
    private function handleProtectKeys()
    {
        if (!LlmSecretService::isEncryptionAvailable()) {
            $this->sendJsonResponse([
                'error' => 'No master secret is configured. Set the environment variable '
                    . LlmSecretService::MASTER_KEY_NAME . ' (at least 16 characters) first.',
            ], 409);
            return;
        }

        try {
            $converted = $this->model->protectStoredApiKeys();
            if ($converted > 0) {
                $this->logSettingsChange('LLM API keys encrypted: ' . $converted);
            }
            $this->sendJsonResponse(['success' => true, 'converted' => $converted]);
        } catch (Exception $e) {
            error_log('[LLM] Encrypting the stored API keys failed: ' . $e->getMessage());
            $this->sendJsonResponse(['error' => 'The API keys could not be encrypted.'], 500);
        }
    }

    /**
     * Audit trail for settings changes: which settings, never their values.
     *
     * @param string $details
     */
    private function logSettingsChange($details)
    {
        try {
            $this->model->get_services()->get_transaction()->add_transaction(
                transactionTypes_update,
                TRANSACTION_BY_LLM_PLUGIN,
                $_SESSION['id_user'] ?? null,
                'pages_fields_translation',
                $this->pageId,
                false,
                $details
            );
        } catch (Exception $e) {
            error_log('[LLM] Settings audit log failed: ' . $e->getMessage());
        }
    }

    /** Return the list of available LLM models as JSON. */
    private function handleModels()
    {
        try {
            $llmService = new LlmService($this->model->get_services());
            $models = $llmService->getAvailableModels();
            $this->sendJsonResponse(['models' => $models]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>
