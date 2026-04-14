<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../service/LlmService.php";

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
                $this->handleSaveConfig();
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

    private function requireAccess($mode)
    {
        if (!$this->checkAccess($mode)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            exit;
        }
    }

    private function checkAccess($mode)
    {
        if (!$this->pageId || !isset($_SESSION['id_user'])) {
            return false;
        }
        $method = 'has_access_' . $mode;
        return $this->acl->$method($_SESSION['id_user'], $this->pageId);
    }

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

    private function handleSaveConfig()
    {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['fields'])) {
                $this->sendJsonResponse(['error' => 'No fields provided'], 400);
                return;
            }

            $allowedFields = [
                'llm_api_keys', 'llm_default_model', 'llm_temperature',
                'llm_max_tokens', 'llm_timeout',
                'llm_memory_enabled', 'llm_memory_key',
                'llm_memory_storage_mode', 'llm_memory_table_name',
                'llm_memory_history_table_name',
            ];

            $saved = [];
            foreach ($data['fields'] as $name => $value) {
                if (!in_array($name, $allowedFields, true)) {
                    continue;
                }
                $ok = $this->model->saveSetting($name, (string)$value);
                if ($ok) {
                    $saved[] = $name;
                }
            }

            $this->sendJsonResponse([
                'success' => true,
                'saved' => $saved,
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

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
