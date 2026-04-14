<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../LlmJsonResponseTrait.php";
require_once __DIR__ . "/ModuleLlmMemoryModel.php";
require_once __DIR__ . "/../../service/LlmMemoryAdminService.php";
require_once __DIR__ . "/../../service/LlmMemoryConfigService.php";
require_once __DIR__ . "/../../service/LlmMemoryRuleService.php";
require_once __DIR__ . "/../sh_module_llm/Sh_module_llmModel.php";

class ModuleLlmMemoryController extends BaseController
{
    use LlmJsonResponseTrait;

    /** @var object */
    private $acl;

    /** @var int|null */
    private $page_id;

    public function __construct($model)
    {
        parent::__construct($model);
        $services = $model->get_services();
        $this->acl = $services->get_acl();
        $this->page_id = $services->get_db()->fetch_page_id_by_keyword(LLM_MEMORY_PAGE_KEYWORD);
        $this->handleRequest();
    }

    private function handleRequest()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if (!$action) {
            return;
        }

        switch ($action) {
            case 'overview':
            case 'memory_overview':
                $this->requireAccess('select');
                $this->handleOverview();
                break;
            case 'rules_list':
                $this->requireAccess('select');
                $this->handleRulesList();
                break;
            case 'rules_bootstrap':
                $this->requireAccess('select');
                $this->handleRulesBootstrap();
                break;
            case 'rule_get':
                $this->requireAccess('select');
                $this->handleRuleGet();
                break;
            case 'rule_create':
                $this->requireAccess('insert');
                $this->handleRuleCreate();
                break;
            case 'rule_update':
                $this->requireAccess('update');
                $this->handleRuleUpdate();
                break;
            case 'rule_delete':
                $this->requireAccess('delete');
                $this->handleRuleDelete();
                break;
            case 'rule_duplicate':
                $this->requireAccess('insert');
                $this->handleRuleDuplicate();
                break;
            case 'sources':
                $this->requireAccess('select');
                $this->handleSources();
                break;
            case 'memory_config_get':
                $this->requireAccess('select');
                $this->handleMemoryConfigGet();
                break;
            case 'memory_config_save':
                $this->requireAccess('update');
                $this->handleMemoryConfigSave();
                break;
            case 'memory_keys':
                $this->requireAccess('select');
                $this->handleMemoryKeys();
                break;
            case 'memory_activity':
                $this->requireAccess('select');
                $this->handleMemoryActivity();
                break;
            case 'memory_key_delete':
                $this->requireAccess('delete');
                $this->handleMemoryKeyDelete();
                break;
            case 'memory_users':
                $this->requireAccess('select');
                $this->handleMemoryUsers();
                break;
            case 'memory_user_detail':
                $this->requireAccess('select');
                $this->handleMemoryUserDetail();
                break;
            case 'memory_user_history':
                $this->requireAccess('select');
                $this->handleMemoryUserHistory();
                break;
            case 'memory_rerun_rule':
                $this->requireAccess('update');
                $this->handleMemoryRerunRule();
                break;
            case 'memory_rebuild':
                $this->requireAccess('update');
                $this->handleMemoryRebuild();
                break;
            case 'memory_edit':
                $this->requireAccess('update');
                $this->handleMemoryEdit();
                break;
            case 'memory_delete':
                $this->requireAccess('delete');
                $this->handleMemoryDelete();
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
        if (!$this->page_id || !isset($_SESSION['id_user'])) {
            return false;
        }
        $method = 'has_access_' . $mode;
        return $this->acl->$method($_SESSION['id_user'], $this->page_id);
    }

    private function getAdminService()
    {
        return new LlmMemoryAdminService($this->model->get_services());
    }

    private function getRuleService()
    {
        return new LlmMemoryRuleService($this->model->get_services());
    }

    private function getSettingsModel()
    {
        return new Sh_module_llmModel($this->model->get_services());
    }

    private function handleOverview()
    {
        try {
            $admin = $this->getAdminService();
            $this->sendJsonResponse($admin->getMemoryOverview());
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleRulesList()
    {
        try {
            $rules = $this->getRuleService()->listRules();
            $sources = $this->getAdminService()->getRuleUsageCounts();
            foreach ($rules as &$rule) {
                $rule['sources_count'] = (int)($sources[$rule['key']] ?? 0);
            }
            unset($rule);
            $this->sendJsonResponse(['rules' => $rules]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleRuleGet()
    {
        $rule_id = (int)($_GET['rule_id'] ?? 0);
        if ($rule_id <= 0) {
            $this->sendJsonResponse(['error' => 'rule_id required'], 400);
            return;
        }

        try {
            $service = $this->getRuleService();
            $rule = $service->getRuleById($rule_id);
            if (!$rule) {
                $this->sendJsonResponse(['error' => 'Rule not found'], 404);
                return;
            }
            $rule['prompt_template'] = $service->getActivePromptTemplate($rule);
            $rule['prompt_meta_json'] = $service->getActivePromptMetaJson($rule);
            $this->sendJsonResponse([
                'rule' => $rule,
                'prompt_bootstrap' => $service->getPromptBootstrap($rule, $rule['prompt_template'], $rule['prompt_meta_json'])
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleRulesBootstrap()
    {
        try {
            $rule_service = $this->getRuleService();
            $rules = $rule_service->listRules();
            $sources = $this->getAdminService()->getRuleUsageCounts();
            foreach ($rules as &$rule) {
                $rule['sources_count'] = (int)($sources[$rule['key']] ?? 0);
            }
            unset($rule);

            $this->sendJsonResponse(array(
                'rules' => $rules,
                'editor' => $rule_service->getEditorBootstrap($this->getSettingsModel()),
            ));
        } catch (Exception $e) {
            $this->sendJsonResponse(array('error' => $e->getMessage()), 500);
        }
    }

    private function handleRuleCreate()
    {
        try {
            $payload = $this->decodeJsonPost('rule_json', array());
            $prompt_template = (string)($_POST['prompt_template'] ?? '');
            $prompt_meta_json = (string)($_POST['prompt_meta_json'] ?? '{}');
            $prompt_change_note = (string)($_POST['prompt_change_note'] ?? '');
            $service = $this->getRuleService();
            $rule = $service->createRule($payload, $prompt_template, $prompt_meta_json, $prompt_change_note);
            $rule['prompt_template'] = $service->getActivePromptTemplate($rule);
            $rule['prompt_meta_json'] = $service->getActivePromptMetaJson($rule);
            $this->sendJsonResponse([
                'rule' => $rule,
                'prompt_bootstrap' => $service->getPromptBootstrap($rule, $rule['prompt_template'], $rule['prompt_meta_json'])
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleRuleUpdate()
    {
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        if ($rule_id <= 0) {
            $this->sendJsonResponse(['error' => 'rule_id required'], 400);
            return;
        }

        try {
            $payload = $this->decodeJsonPost('rule_json', array());
            $prompt_template = array_key_exists('prompt_template', $_POST) ? (string)$_POST['prompt_template'] : null;
            $prompt_meta_json = array_key_exists('prompt_meta_json', $_POST) ? (string)$_POST['prompt_meta_json'] : null;
            $prompt_change_note = (string)($_POST['prompt_change_note'] ?? '');
            $service = $this->getRuleService();
            $rule = $service->updateRule($rule_id, $payload, $prompt_template, $prompt_meta_json, $prompt_change_note);
            $rule['prompt_template'] = $service->getActivePromptTemplate($rule);
            $rule['prompt_meta_json'] = $service->getActivePromptMetaJson($rule);
            $this->sendJsonResponse([
                'rule' => $rule,
                'prompt_bootstrap' => $service->getPromptBootstrap($rule, $rule['prompt_template'], $rule['prompt_meta_json'])
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleRuleDelete()
    {
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        if ($rule_id <= 0) {
            $this->sendJsonResponse(['error' => 'rule_id required'], 400);
            return;
        }

        try {
            $deleted = $this->getRuleService()->deleteRule($rule_id);
            $this->sendJsonResponse(['deleted' => $deleted]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleRuleDuplicate()
    {
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        if ($rule_id <= 0) {
            $this->sendJsonResponse(['error' => 'rule_id required'], 400);
            return;
        }

        try {
            $service = $this->getRuleService();
            $rule = $service->duplicateRule($rule_id);
            $rule['prompt_template'] = $service->getActivePromptTemplate($rule);
            $rule['prompt_meta_json'] = $service->getActivePromptMetaJson($rule);
            $this->sendJsonResponse(['rule' => $rule]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSources()
    {
        try {
            $this->sendJsonResponse(['sources' => $this->getAdminService()->getWriteSources()]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryConfigGet()
    {
        try {
            $settings = $this->getSettingsModel()->getStructuredSettings();
            $this->sendJsonResponse([
                'settings' => $settings['memory'] ?? ['label' => 'Memory Configuration', 'fields' => []],
                'acl' => [
                    'select' => $this->checkAccess('select'),
                    'update' => $this->checkAccess('update'),
                ],
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryConfigSave()
    {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['fields'])) {
                $this->sendJsonResponse(['error' => 'No fields provided'], 400);
                return;
            }

            $allowedFields = [
                'llm_memory_enabled',
                'llm_memory_storage_mode',
            ];

            $model = $this->getSettingsModel();
            $saved = [];
            foreach ($data['fields'] as $name => $value) {
                if (!in_array($name, $allowedFields, true)) {
                    continue;
                }

                if ($model->saveSetting($name, (string)$value)) {
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

    private function handleMemoryKeys()
    {
        try {
            $this->sendJsonResponse([
                'keys' => $this->getRuleService()->listMemoryKeysWithUsage(),
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryActivity()
    {
        try {
            $limit = min((int)($_GET['limit'] ?? 25), 100);
            $this->sendJsonResponse([
                'items' => $this->getAdminService()->getRecentActivity($limit),
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryKeyDelete()
    {
        $key_code = (string)($_POST['key_code'] ?? '');
        if (trim($key_code) === '') {
            $this->sendJsonResponse(['error' => 'key_code required'], 400);
            return;
        }

        try {
            $deleted = $this->getRuleService()->deleteMemoryKey($key_code);
            $this->sendJsonResponse(['deleted' => $deleted]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryUsers()
    {
        $page = (int)($_GET['page'] ?? 1);
        $per_page = min((int)($_GET['per_page'] ?? 25), 100);
        $search = $_GET['q'] ?? '';

        try {
            $this->sendJsonResponse($this->getAdminService()->getMemoryUserList($page, $per_page, $search));
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryUserDetail()
    {
        $user_id = (int)($_GET['user_id'] ?? 0);
        $memory_key = $_GET['memory_key'] ?? null;
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'user_id required'], 400);
            return;
        }

        try {
            $admin = $this->getAdminService();
            $this->sendJsonResponse([
                'memory' => $admin->getUserMemory($user_id, $memory_key),
                'memory_keys' => $admin->getUserMemoryKeys($user_id),
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryUserHistory()
    {
        $user_id = (int)($_GET['user_id'] ?? 0);
        $memory_key = $_GET['memory_key'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'user_id required'], 400);
            return;
        }

        try {
            $history = $this->getAdminService()->getUserMemoryHistory($user_id, $memory_key, $limit, 0);
            $this->sendJsonResponse(['history' => $history]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryRerunRule()
    {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $rule_key = (string)($_POST['rule_key'] ?? '');
        if (!$user_id || $rule_key === '') {
            $this->sendJsonResponse(['error' => 'user_id and rule_key required'], 400);
            return;
        }

        try {
            $manual_payload = $this->decodeJsonPost('manual_payload_json', array());
            $success = $this->getAdminService()->reRunRuleForUser($user_id, $rule_key, $manual_payload);
            $this->sendJsonResponse(['success' => $success]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryRebuild()
    {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'user_id required'], 400);
            return;
        }

        try {
            $rebuilt = $this->getAdminService()->rebuildUserMemory($user_id);
            $this->sendJsonResponse(['rebuilt_keys' => $rebuilt]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryEdit()
    {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $memory_key = (string)($_POST['memory_key'] ?? '');
        if (!$user_id || $memory_key === '') {
            $this->sendJsonResponse(['error' => 'user_id and memory_key required'], 400);
            return;
        }

        try {
            $memory_object = json_decode((string)($_POST['memory_json'] ?? '{}'), true);
            if (!is_array($memory_object)) {
                $memory_object = array();
            }
            $memory_data = array(
                'memory_text' => (string)($_POST['memory_text'] ?? ''),
                'memory_object' => $memory_object,
                'flat_fields' => $memory_object,
                'change_summary' => (string)($_POST['change_summary'] ?? 'Memory edited by admin.'),
            );
            $success = $this->getAdminService()->editUserMemory($user_id, $memory_key, $memory_data);
            $this->sendJsonResponse(['success' => $success]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMemoryDelete()
    {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $memory_key = (string)($_POST['memory_key'] ?? '');
        if (!$user_id || $memory_key === '') {
            $this->sendJsonResponse(['error' => 'user_id and memory_key required'], 400);
            return;
        }

        try {
            $success = $this->getAdminService()->deleteUserMemory($user_id, $memory_key);
            $this->sendJsonResponse(['success' => $success]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function decodeJsonPost($key, $fallback)
    {
        $raw = $_POST[$key] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            return $fallback;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}
?>
