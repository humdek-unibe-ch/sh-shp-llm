<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../../service/LlmScriptService.php";
require_once __DIR__ . "/../../service/LlmService.php";

/**
 * Controller for the LLM Scripts module.
 * Handles AJAX-style requests from the React ScriptsManager component.
 * Follows the same pattern as ModuleLlmAdminConsoleController.
 */
class ModuleLlmScriptController extends BaseController
{
    /** @var LlmScriptService */
    private $scriptService;

    /** @var LlmService */
    private $llmService;

    /** @var object ACL service */
    private $acl;

    /** @var int|null Page ID for ACL checks */
    private $page_id;

    public function __construct($model)
    {
        parent::__construct($model);
        $services = $model->get_services();
        $this->scriptService = new LlmScriptService($services);
        $this->llmService = new LlmService($services);
        $this->acl = $services->get_acl();
        $this->page_id = $services->get_db()->fetch_page_id_by_keyword(LLM_SCRIPTS_PAGE_KEYWORD);
        $this->handleRequest();
    }

    private function handleRequest()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;

        if (!$action) {
            return;
        }

        switch ($action) {
            case 'list':
                $this->handleList();
                break;
            case 'get':
                $this->handleGet();
                break;
            case 'create':
                $this->handleCreate();
                break;
            case 'update':
                $this->handleUpdate();
                break;
            case 'delete':
                $this->handleDelete();
                break;
            case 'test':
                $this->handleTest();
                break;
            case 'config':
                $this->handleConfig();
                break;
            case 'models':
                $this->handleModels();
                break;
            case 'sections':
                $this->handleSections();
                break;
            default:
                $this->sendJsonResponse(['error' => 'Unknown action: ' . $action], 400);
                break;
        }
    }

    /**
     * Check ACL permission for the current user on the scripts page.
     * @param string $mode select|insert|update|delete
     * @return bool
     */
    private function checkAccess($mode)
    {
        if (!$this->page_id || !isset($_SESSION['id_user'])) {
            return false;
        }
        $method = 'has_access_' . $mode;
        return $this->acl->$method($_SESSION['id_user'], $this->page_id);
    }

    private function handleList()
    {
        if (!$this->checkAccess('select')) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }
        try {
            $scripts = $this->scriptService->get_scripts();
            $this->sendJsonResponse(['scripts' => $scripts]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGet()
    {
        if (!$this->checkAccess('select')) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }
        $sid = intval($_GET['sid'] ?? $_POST['sid'] ?? 0);
        if ($sid <= 0) {
            $this->sendJsonResponse(['error' => 'Invalid script ID'], 400);
            return;
        }
        try {
            $script = $this->scriptService->fetch_script($sid);
            if (!$script) {
                $this->sendJsonResponse(['error' => 'Script not found'], 404);
                return;
            }
            $this->sendJsonResponse(['script' => $script]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleCreate()
    {
        if (!$this->checkAccess('insert')) {
            $this->sendJsonResponse(['error' => 'Access denied: no insert permission'], 403);
            return;
        }
        try {
            $sid = $this->scriptService->insert_new_script();
            if ($sid) {
                $script = $this->scriptService->fetch_script($sid);
                $this->sendJsonResponse(['script' => $script]);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to create script'], 500);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleUpdate()
    {
        if (!$this->checkAccess('update')) {
            $this->sendJsonResponse(['error' => 'Access denied: no update permission'], 403);
            return;
        }
        $sid = intval($_POST['sid'] ?? 0);
        if ($sid <= 0) {
            $this->sendJsonResponse(['error' => 'Invalid script ID'], 400);
            return;
        }
        try {
            $name = $_POST['name'] ?? '';
            $script = $_POST['script'] ?? '';
            $test_variables = $_POST['test_variables'] ?? '';
            $async = intval($_POST['async'] ?? 0);
            $data_config = $_POST['data_config'] ?? '';
            $model = (!empty($_POST['model'])) ? $_POST['model'] : null;
            $temperature = ($_POST['temperature'] ?? '') !== '' ? floatval($_POST['temperature']) : null;
            $max_tokens = ($_POST['max_tokens'] ?? '') !== '' ? intval($_POST['max_tokens']) : null;
            $refresh_sections = $_POST['refresh_sections'] ?? null;

            $res = $this->scriptService->update_script(
                $sid, $name, $script, $test_variables,
                $async, $data_config, $model, $temperature, $max_tokens, $refresh_sections
            );

            if ($res) {
                $updated = $this->scriptService->fetch_script($sid);
                $this->sendJsonResponse(['script' => $updated]);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to update script'], 500);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDelete()
    {
        if (!$this->checkAccess('delete')) {
            $this->sendJsonResponse(['error' => 'Access denied: no delete permission'], 403);
            return;
        }
        $sid = intval($_POST['sid'] ?? 0);
        if ($sid <= 0) {
            $this->sendJsonResponse(['error' => 'Invalid script ID'], 400);
            return;
        }
        try {
            $res = $this->scriptService->delete_script($sid);
            if ($res) {
                $this->sendJsonResponse(['success' => true]);
            } else {
                $this->sendJsonResponse(['error' => 'Failed to delete script'], 500);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleTest()
    {
        if (!$this->checkAccess('update')) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }
        try {
            $script_content = $_POST['script'] ?? '';
            $script_name = $_POST['script_name'] ?? 'Unnamed Script';
            $sid = isset($_POST['sid']) ? intval($_POST['sid']) : null;
            $test_variables = [];
            if (!empty($_POST['test_variables'])) {
                $decoded = json_decode($_POST['test_variables'], true);
                if (is_array($decoded)) {
                    $test_variables = $decoded;
                }
            }
            $data_config = [];
            if (!empty($_POST['data_config'])) {
                $decoded = json_decode($_POST['data_config'], true);
                if (is_array($decoded)) {
                    $data_config = $decoded;
                }
            }

            $model = (!empty($_POST['model'])) ? $_POST['model'] : null;
            $temperature = ($_POST['temperature'] ?? '') !== '' ? floatval($_POST['temperature']) : null;
            $max_tokens = ($_POST['max_tokens'] ?? '') !== '' ? intval($_POST['max_tokens']) : null;

            $result = $this->scriptService->execute_llm_script(
                $script_content, $data_config, $test_variables, null,
                $model, $temperature, $max_tokens
            );

            $this->scriptService->log_script_execution(
                $sid, $script_name, $result, $model, $temperature, $max_tokens
            );

            $this->sendJsonResponse($result);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return LLM configuration defaults (model, temperature, max_tokens)
     * and ACL permissions for the current user on this page.
     */
    private function handleConfig()
    {
        try {
            $config = $this->scriptService->get_llm_defaults();
            $config['acl'] = [
                'select' => $this->checkAccess('select'),
                'insert' => $this->checkAccess('insert'),
                'update' => $this->checkAccess('update'),
                'delete' => $this->checkAccess('delete'),
            ];
            $this->sendJsonResponse($config);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return available LLM models from the API.
     */
    private function handleModels()
    {
        try {
            $models = $this->llmService->getAvailableModels();
            $this->sendJsonResponse(['models' => $models]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return all sections for the refresh sections selector.
     */
    private function handleSections()
    {
        try {
            $db = $this->model->get_services()->get_db();
            $sections = $db->query_db(
                "SELECT s.id, s.name FROM sections s ORDER BY s.name ASC"
            );
            $this->sendJsonResponse(['sections' => $sections ?: []]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function sendJsonResponse($data, $status_code = 200)
    {
        if (!headers_sent()) {
            http_response_code($status_code);
            header('Content-Type: application/json');
        }

        $this->model->get_services()->get_router()->log_user_activity();

        echo json_encode($data);

        if (function_exists('uopz_allow_exit')) {
            uopz_allow_exit(true);
        }
        exit;
    }
}
?>
