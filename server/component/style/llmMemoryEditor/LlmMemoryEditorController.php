<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../../service/LlmCsrfService.php";
require_once __DIR__ . "/LlmMemoryEditorModel.php";
require_once __DIR__ . "/LlmMemoryEditorView.php";

/**
 * The controller of the llmMemoryEditor style: the AJAX actions add_item,
 * edit_item and delete_item (a POST with `__llm_memory_action`).
 *
 * The request is only checked here (addressed to this section, CSRF token, field
 * types); the rules - own memory only, the CMS checkboxes, the version check, what
 * a valid item is, the write - are the model's. The answer is JSON: the status, a
 * message and, whenever the participant's view is out of date or has changed, the
 * markup of the body of that memory key to show instead of the old one.
 *
 * The request carries no user id: the memory is the session user's. It names the
 * memory key it changes; the model refuses a key the section is not configured with.
 */
class LlmMemoryEditorController extends BaseController
{
    use LlmJsonResponseTrait;

    /* Constants **************************************************************/

    /** HTTP status of each outcome of LlmMemoryEditorModel::applyUserEdit(). */
    const HTTP_STATUS = array(
        LlmMemoryEditorModel::STATUS_OK => 200,
        LlmMemoryEditorModel::STATUS_UNCHANGED => 200,
        LlmMemoryEditorModel::STATUS_FORBIDDEN => 403,
        LlmMemoryEditorModel::STATUS_NOT_FOUND => 404,
        LlmMemoryEditorModel::STATUS_CONFLICT => 409,
        LlmMemoryEditorModel::STATUS_READ_ONLY => 409,
        LlmMemoryEditorModel::STATUS_INVALID => 422,
        LlmMemoryEditorModel::STATUS_FAILED => 500,
    );

    /** Outcomes after which the participant's view is stale or has changed: the answer carries the new body. */
    const RENDER_STATUSES = array(
        LlmMemoryEditorModel::STATUS_OK,
        LlmMemoryEditorModel::STATUS_UNCHANGED,
        LlmMemoryEditorModel::STATUS_NOT_FOUND,
        LlmMemoryEditorModel::STATUS_CONFLICT,
        LlmMemoryEditorModel::STATUS_READ_ONLY,
    );

    /* Constructors ***********************************************************/

    /**
     * Every llmMemoryEditor of a page builds a controller for the same request, and a
     * JSON answer ends it: each controller acts only on a request addressed to its own
     * section (`section_id`).
     *
     * @param object $model The model of the llmMemoryEditor section
     */
    public function __construct($model)
    {
        parent::__construct($model);

        $action = $_POST['__llm_memory_action'] ?? '';
        if (!is_string($action) || !isset(LlmMemoryEditorModel::ACTION_FLAGS[$action])
            || !isset($_POST['section_id']) || !is_scalar($_POST['section_id'])
            || (int)$_POST['section_id'] !== $model->getSectionId()) {
            return;
        }

        $this->handleAction($action);
    }

    /* Private Methods ********************************************************/

    /** Check the request, let the model apply it and answer. Ends the request. */
    private function handleAction($action)
    {
        if (!LlmCsrfService::verifyRequest()) {
            $this->respond(LlmMemoryEditorModel::STATUS_FORBIDDEN, 'Security check failed. Please reload the page and try again.');
        }

        $input = array();
        foreach (array('memory_key', 'memory_version', 'item_id', 'section', 'text') as $field) {
            if (isset($_POST[$field])) {
                if (!is_string($_POST[$field])) {
                    $this->respond(LlmMemoryEditorModel::STATUS_INVALID, LlmMemoryEditorModel::MSG_INVALID);
                }
                $input[$field] = $_POST[$field];
            }
        }
        if (!isset($input['memory_version'], $input['memory_key'])) {
            $this->respond(LlmMemoryEditorModel::STATUS_INVALID, LlmMemoryEditorModel::MSG_INVALID);
        }

        $result = $this->model->applyUserEdit($action, $input['memory_key'], $input['memory_version'], $input);
        $this->respond($result['status'], $result['message'], strtolower(trim($input['memory_key'])));
    }

    /**
     * Send the JSON answer for an outcome. Ends the request.
     *
     * @param string $status A LlmMemoryEditorModel::STATUS_* value
     * @param string $message The participant-facing message ('' when ok)
     * @param string $memory_key The key of the request: its body is rendered again (only for a key of
     *   the section; any other key was refused as forbidden, which renders nothing)
     */
    private function respond($status, $message, $memory_key = '')
    {
        $success = $status === LlmMemoryEditorModel::STATUS_OK || $status === LlmMemoryEditorModel::STATUS_UNCHANGED;
        $response = array('success' => $success, 'status' => $status, 'message' => $message);
        if (in_array($status, self::RENDER_STATUSES, true) && in_array($memory_key, $this->model->getMemoryKeys(), true)) {
            $view = new LlmMemoryEditorView($this->model);
            $response['html'] = $view->render_body($memory_key, $message, $status === LlmMemoryEditorModel::STATUS_CONFLICT ? 'warning' : 'danger');
        }
        $this->sendJsonResponse($response, self::HTTP_STATUS[$status]);
    }
}
