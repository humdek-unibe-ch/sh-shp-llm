<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../service/LlmCsrfService.php";
require_once __DIR__ . "/../../service/LlmErrorReporter.php";
require_once __DIR__ . "/../../service/LlmPromptCatalogService.php";
require_once __DIR__ . "/../../service/LlmPromptHubSaveService.php";
require_once __DIR__ . "/ModuleLlmPromptsModel.php";

/**
 * Controller for the LLM Prompts hub module.
 *
 * Handles the AJAX requests of the React prompts hub. Every action is
 * ACL-checked against the hub page first; on top of that the services check
 * the ACL of the page a prompt belongs to (CMS page of the section, Scripts
 * page, Memory page), so the hub is never a back door to a prompt the user
 * could not open elsewhere.
 *
 * @package LLM Plugin
 * @see LlmPromptCatalogService For the read side
 * @see LlmPromptHubSaveService For the write side
 */
class ModuleLlmPromptsController extends BaseController
{
    use LlmJsonResponseTrait;

    /** @var object SelfHelp ACL service for permission checks */
    private $acl;

    /** @var int|null Resolved page ID for ACL lookups */
    private $page_id;

    /**
     * @param ModuleLlmPromptsModel $model Model instance providing services access
     */
    public function __construct($model)
    {
        parent::__construct($model);
        $services = $model->get_services();
        $this->acl = $services->get_acl();
        $this->page_id = $services->get_db()->fetch_page_id_by_keyword(LLM_PROMPTS_PAGE_KEYWORD);
        $this->handleRequest();
    }

    /**
     * Dispatch incoming request to the appropriate handler.
     *
     * All actions require ACL permission on the hub page. Reads need 'select';
     * mutations need 'update' and, on top of that, POST with the session CSRF
     * token (requireMutation()).
     */
    private function handleRequest()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if (!$action) {
            return;
        }

        switch ($action) {
            case 'list':
                $this->requireAccess('select');
                $this->handleList();
                break;
            case 'get':
                $this->requireAccess('select');
                $this->handleGet();
                break;
            case 'get_version':
                $this->requireAccess('select');
                $this->handleGetVersion();
                break;
            case 'save':
                $this->requireMutation('update');
                $this->handleSave();
                break;
            case 'save_all':
                $this->requireMutation('update');
                $this->handleSaveAll();
                break;
            case 'restore':
                $this->requireMutation('update');
                $this->handleRestore();
                break;
            default:
                $this->sendJsonResponse(['error' => 'Unknown action'], 400);
                break;
        }
    }

    /**
     * list: prompts the user may see, filtered (q, sourceType, plugin, style, pageId, languageId,
     * includeOrphaned, limit, offset) and cut to one page, with the facet values for the filters.
     */
    private function handleList()
    {
        $filters = array();
        foreach (array('q', 'sourceType', 'plugin', 'style', 'pageId', 'languageId', 'includeOrphaned', 'limit', 'offset') as $name) {
            if (isset($_GET[$name]) && is_string($_GET[$name])) {
                $filters[$name] = $_GET[$name];
            }
        }

        try {
            $result = $this->catalog()->listPrompts($filters, (int)$_SESSION['id_user']);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 400);
            return;
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => LlmErrorReporter::report('Listing prompts', $e)], 500);
            return;
        }

        $this->sendJsonResponse($result);
    }

    /**
     * get: one prompt with its full content and version list. A prompt the user may not see is
     * answered like one that does not exist.
     */
    private function handleGet()
    {
        try {
            $row = $this->catalog()->getPrompt($this->stringParam('key'), (int)$_SESSION['id_user']);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 400);
            return;
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => LlmErrorReporter::report('Loading a prompt', $e)], 500);
            return;
        }

        if ($row === null) {
            $this->sendJsonResponse(['error' => 'Prompt not found'], 404);
            return;
        }
        $this->sendJsonResponse($row);
    }

    /**
     * get_version: the text of one version, only if it belongs to the prompt named by the key.
     */
    private function handleGetVersion()
    {
        $version_id = $this->stringParam('version_id');
        try {
            $version = $this->catalog()->getVersion(
                $this->stringParam('key'),
                ctype_digit($version_id) ? (int)$version_id : 0,
                (int)$_SESSION['id_user']
            );
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 400);
            return;
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => LlmErrorReporter::report('Loading a prompt version', $e)], 500);
            return;
        }

        if ($version === null) {
            $this->sendJsonResponse(['error' => 'Version not found'], 404);
            return;
        }
        $this->sendJsonResponse($version);
    }

    /**
     * save: the text of a prompt as a new version, live at once. The request carries the active
     * version and the text hash the editor loaded; a prompt that changed since is a 409 with what
     * is live now, and nothing is written.
     */
    private function handleSave()
    {
        $this->sendWriteResult('Saving a prompt', function ($service) {
            return $service->save(
                $this->postString('key'),
                $this->postValue('content'),
                $this->postValue('change_note'),
                $this->postId('expected_active_version_id'),
                (int)$_SESSION['id_user'],
                $this->postValue('expected_content_hash')
            );
        });
    }

    /**
     * save_all: several languages of one prompt saved together, all or none. `items` is a JSON
     * list of {key, content, change_note?, expected_active_version_id, expected_content_hash};
     * a 409 names the language that changed in current.key.
     */
    private function handleSaveAll()
    {
        $items = json_decode($this->postString('items'), true);
        if (!is_array($items) || !array_is_list($items)) {
            $this->sendJsonResponse(['error' => 'items must be a JSON list'], 400);
            return;
        }
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                $this->sendJsonResponse(['error' => 'items must be a JSON list'], 400);
                return;
            }
            // Only plain strings reach the service, like the fields of a single save
            $clean = array();
            foreach (array('key', 'content', 'change_note', 'expected_active_version_id', 'expected_content_hash') as $name) {
                if (isset($item[$name]) && is_string($item[$name])) {
                    $clean[$name] = $item[$name];
                }
            }
            $clean['expected_active_version_id'] = isset($clean['expected_active_version_id']) && ctype_digit($clean['expected_active_version_id'])
                ? (int)$clean['expected_active_version_id']
                : -1;
            $items[$i] = $clean;
        }

        $this->sendWriteResult('Saving the languages of a prompt', function ($service) use ($items) {
            return $service->saveAll($items, (int)$_SESSION['id_user']);
        });
    }

    /**
     * restore: an old version of a prompt as a new version (history is never rewritten).
     */
    private function handleRestore()
    {
        $this->sendWriteResult('Restoring a prompt version', function ($service) {
            return $service->restore(
                $this->postString('key'),
                $this->postId('version_id'),
                $this->postId('expected_active_version_id'),
                (int)$_SESSION['id_user'],
                $this->postValue('expected_content_hash')
            );
        });
    }

    /**
     * Run a write of the save service and answer it: the fresh prompt, or the status of what went
     * wrong. Only the generic text of an unexpected failure reaches the client (details: the log).
     *
     * @param string $context What is being done, for the log line
     * @param callable $call Receives the service, returns its answer
     */
    private function sendWriteResult($context, $call)
    {
        try {
            $result = $call(new LlmPromptHubSaveService($this->model->get_services()));
        } catch (LlmPromptHubConflictException $e) {
            $this->sendJsonResponse(['error' => 'conflict', 'message' => $e->getMessage(), 'current' => $e->getCurrent()], 409);
            return;
        } catch (LlmPromptHubForbiddenException $e) {
            $this->sendJsonResponse(['error' => $e->getMessage(), 'reason' => $e->getReason()], 403);
            return;
        } catch (LlmPromptHubNotFoundException $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 404);
            return;
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 400);
            return;
        } catch (Throwable $e) {
            $this->sendJsonResponse(['error' => LlmErrorReporter::report($context, $e)], 500);
            return;
        }

        $this->sendJsonResponse($result);
    }

    /**
     * @return LlmPromptCatalogService
     */
    private function catalog()
    {
        return new LlmPromptCatalogService($this->model->get_services());
    }

    /**
     * A request parameter that must be a plain string; anything else (missing, an array) reads as ''.
     *
     * @param string $name
     * @return string
     */
    private function stringParam($name)
    {
        return isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : '';
    }

    /**
     * A POST field that must be a plain string; anything else (missing, an array) reads as ''.
     *
     * @param string $name
     * @return string
     */
    private function postString($name)
    {
        $value = $this->postValue($name);
        return $value !== null ? $value : '';
    }

    /**
     * A POST field as the plain string it was sent as; null when it is missing or not a string,
     * so "no text" and "empty text" stay different.
     *
     * @param string $name
     * @return string|null
     */
    private function postValue($name)
    {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? $_POST[$name] : null;
    }

    /**
     * A POST field that must be digits only (a database id); -1 for anything else, which the
     * service refuses.
     *
     * @param string $name
     * @return int
     */
    private function postId($name)
    {
        $value = $this->postValue($name);
        return ($value !== null && ctype_digit($value)) ? (int)$value : -1;
    }

    /**
     * Halt with 403 JSON response if the current user lacks the specified ACL mode on the hub page.
     *
     * @param string $mode ACL mode ('select', 'update').
     * @return void Exits on failure.
     */
    private function requireAccess($mode)
    {
        if (!$this->checkAccess($mode)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            exit;
        }
    }

    /**
     * Access check for an action that changes data: the ACL mode, then POST
     * only with the session CSRF token (POST field or X-CSRF-Token header,
     * which the hub's JSON/FormData requests send). Sends 403 / 405 and exits otherwise.
     *
     * @param string $mode ACL mode ('update').
     * @return void Exits on failure.
     */
    private function requireMutation($mode)
    {
        $this->requireAccess($mode);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
            exit;
        }
        if (!LlmCsrfService::verifyRequest()) {
            $this->sendJsonResponse(['error' => 'Invalid CSRF token. Reload the page and try again.'], 403);
            exit;
        }
    }

    /**
     * Check whether the current user has the specified ACL mode on the hub page.
     *
     * @param string $mode ACL mode ('select', 'update').
     * @return bool True if access is granted.
     */
    private function checkAccess($mode)
    {
        if (!$this->page_id || !isset($_SESSION['id_user'])) {
            return false;
        }
        $method = 'has_access_' . $mode;
        return $this->acl->$method($_SESSION['id_user'], $this->page_id);
    }
}
?>
