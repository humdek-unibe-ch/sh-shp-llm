<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . "/../../../../../../component/style/formUserInputLogList/FormUserInputLogListController.php";
require_once __DIR__ . "/../../LlmJsonResponseTrait.php";
require_once __DIR__ . "/../../../service/LlmFormInterviewService.php";
require_once __DIR__ . "/../../../service/LlmSpeechToTextService.php";
require_once __DIR__ . "/../../../service/LlmCsrfService.php";
require_once __DIR__ . "/LlmFormLogListView.php";

/**
 * Controller for llmFormLogList.
 *
 * Inherits the full core LogList controller behavior (quick actions,
 * deletes, normal form writes) and routes the interview JSON contract:
 *
 *   POST __llm_interview_action = open | turn | update_draft | reset | approve
 *                                 | transcribe (multipart audio -> text)
 *   GET  __llm_interview_action = state   (read-only)
 *
 * Requests without an exact marker + matching section delegate to the
 * parent unchanged. Every interview request requires a logged-in session
 * (guest excluded), page select ACL and a satisfied root condition; every
 * mutation additionally requires the session CSRF token.
 */
class LlmFormLogListController extends FormUserInputLogListController
{
    use LlmJsonResponseTrait;

    const ACTION_PARAM = '__llm_interview_action';
    const SECTION_PARAM = 'section_id';

    private $interview_service;
    private $approval_prep = null;

    /**
     * @param LlmFormLogListModel $model
     * @param object|null $interview_service Injectable runtime (tests/DI)
     * @param callable|null $native_renderer Optional name => HTML renderer
     */
    public function __construct($model, $interview_service = null, $native_renderer = null)
    {
        // Wire BaseController without auto-executing; routing happens below.
        FormUserInputController::__construct($model, false);
        $marker = $this->interviewMarker($model);
        if (!$marker['present'] || !$marker['ours']) {
            // No marker, or a marker addressed to another section: a
            // crafted __form_name post for this style must not bypass
            // the interview approval in either case. Everything else
            // keeps the inherited behavior untouched.
            $this->guardStandalonePost($model);
            $this->execute();
            return;
        }
        $this->interview_service = $interview_service !== null
            ? $interview_service
            : new LlmFormInterviewService($model->get_services());
        $this->interview_service->setNativeRenderer(
            is_callable($native_renderer)
                ? $native_renderer
                : $this->defaultNativeRenderer($model)
        );
        if ($marker['action'] === null) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array(
                    'type' => 'bad_request',
                    'message' => 'Unknown interview action',
                ),
            ), 400);
        }
        $this->dispatchInterview($model, $marker['action']);
    }

    /**
     * Approval write branch: complete the scoped conversation inside the
     * outer transaction, then answer JSON before the parent's redirect.
     */
    protected function after_successful_write()
    {
        if ($this->approval_prep === null) {
            parent::after_successful_write();
            return;
        }
        $db = $this->model->get_services()->get_db();
        try {
            $result = $this->interview_service->completeApproval(
                $this->model, $this->approval_prep, $this->model->get_approved_record_id()
            );
        } catch (Throwable $t) {
            $result = array('ok' => false, 'error' => array(
                'type' => 'approval_failed',
                'message' => 'The approval could not be completed',
            ));
        }
        if (!empty($result['ok'])) {
            $db->commit();
            $this->sendJsonResponse(array(
                'success' => true,
                'approval' => $result['approval'],
                'state' => $result['state'],
            ));
        }
        $db->rollback();
        $this->sendJsonResponse(array(
            'success' => false,
            'error' => isset($result['error']) ? $result['error'] : array(
                'type' => 'approval_failed',
                'message' => 'The approval could not be completed',
            ),
        ), 500);
    }

    /* Interview dispatch *****************************************************/

    /**
     * Marker detection without consuming the request: an interview marker
     * counts as ours only when section_id is an exact scalar integer
     * equal to this component's section.
     *
     * @return array {present, ours, action|null}
     */
    private function interviewMarker($model)
    {
        $raw = isset($_POST[self::ACTION_PARAM]) ? $_POST[self::ACTION_PARAM]
            : (isset($_GET[self::ACTION_PARAM]) ? $_GET[self::ACTION_PARAM] : null);
        if ($raw === null) {
            return array('present' => false, 'ours' => false, 'action' => null);
        }
        $section_raw = isset($_POST[self::SECTION_PARAM]) ? $_POST[self::SECTION_PARAM]
            : (isset($_GET[self::SECTION_PARAM]) ? $_GET[self::SECTION_PARAM] : null);
        $ours = is_scalar($section_raw)
            && preg_match('/^\d+$/', (string)$section_raw) === 1
            && (int)$section_raw === (int)$model->get_section_id();
        $action = is_scalar($raw) ? strtolower(trim((string)$raw)) : '';
        $allowed = array('open', 'turn', 'update_draft', 'reset', 'approve', 'state', 'transcribe');
        return array(
            'present' => true,
            'ours' => $ours,
            'action' => in_array($action, $allowed, true) ? $action : null,
        );
    }

    /**
     * A plain __form_name post for this style is always a bypass attempt:
     * interview forms are written only through the approve path. Quick
     * actions and deletes keep the inherited behavior.
     */
    private function guardStandalonePost($model)
    {
        if (!isset($_POST['__form_name'])
            || $_POST['__form_name'] !== $model->get_db_field('name')
        ) {
            return;
        }
        // Quick actions need a non-empty action id on a positive record;
        // deletes need a positive record id. A mere hidden key in the
        // post never exempts an ordinary save. Ids go through the core's
        // own normalize_post_id(), so this guard and the inherited
        // execute() always read the same value from the same post.
        if (defined('QUICK_ACTION_ID') && defined('SELECTED_RECORD_ID')) {
            $action_raw = isset($_POST[QUICK_ACTION_ID]) ? $_POST[QUICK_ACTION_ID] : '';
            if (is_array($action_raw) && isset($action_raw['value'])) {
                $action_raw = $action_raw['value'];
            }
            $record_raw = isset($_POST[SELECTED_RECORD_ID]) ? $_POST[SELECTED_RECORD_ID] : 0;
            if (trim((string)$action_raw) !== '' && $this->normalize_post_id($record_raw) > 0) {
                return;
            }
        }
        if (defined('DELETE_RECORD_ID')) {
            $delete_raw = isset($_POST[DELETE_RECORD_ID]) ? $_POST[DELETE_RECORD_ID] : 0;
            if ($this->normalize_post_id($delete_raw) > 0) {
                return;
            }
        }
        $this->sendJsonResponse(array(
            'success' => false,
            'error' => array(
                'type' => 'bad_request',
                'message' => 'This form is saved through the interview approval',
            ),
        ), 400);
    }

    private function dispatchInterview($model, $action)
    {
        $is_mutation = $action !== 'state';
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        if ($is_mutation && $method !== 'POST') {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Interview mutations require POST'),
            ), 405);
        }
        if (!$is_mutation && $method !== 'GET') {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'State is read-only'),
            ), 405);
        }
        $actor = isset($_SESSION['id_user']) ? intval($_SESSION['id_user']) : 0;
        if ($actor <= 0 || (defined('GUEST_USER_ID') && $actor === (int)GUEST_USER_ID)) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'unauthenticated', 'message' => 'Not authenticated'),
            ), 401);
        }
        // Page select ACL: the participant must be allowed to view this
        // page before any interview read or write.
        $acl = $model->get_services()->get_acl();
        if (is_object($acl) && method_exists($acl, 'has_access_select')
            && !$acl->has_access_select($actor, (int)$model->get_id_page())
        ) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'forbidden', 'message' => 'This form is not available'),
            ), 403);
        }
        $condition = $model->get_condition_result();
        if (is_array($condition) && isset($condition['result']) && !$condition['result']) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'forbidden', 'message' => 'This form is not available'),
            ), 403);
        }
        if ($is_mutation && !$this->verifyCsrf()) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'csrf', 'message' => 'Invalid CSRF token'),
            ), 403);
        }

        $raw_record = $this->strictParamInt('record_id');
        if ($raw_record === false) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Invalid record_id'),
            ), 400);
        }
        $record_id = $raw_record > 0 ? $raw_record : null;
        switch ($action) {
            case 'open':
                // `resume` = the browser restores a session that was still
                // open before a reload, so a fresh-chat retention keeps it.
                $result = $this->interview_service->open(
                    $model, $record_id,
                    $this->requireOpenToken(),
                    false, 0,
                    !empty($_POST['resume'])
                );
                break;
            case 'state':
                // conversation_id is optional: omit to resolve the latest
                // usable thread for the record/create scope.
                $conversation_id = $this->strictParamInt('conversation_id');
                if ($conversation_id === false) {
                    $this->sendJsonResponse(array(
                        'success' => false,
                        'error' => array(
                            'type' => 'bad_request',
                            'message' => 'Invalid conversation_id',
                        ),
                    ), 400);
                }
                $result = $this->interview_service->read(
                    $model,
                    $record_id,
                    ($conversation_id !== null && $conversation_id > 0)
                        ? $conversation_id
                        : null
                );
                // Resume peek (`peek=1`): "no thread yet" is a normal answer,
                // not an error, so a fresh page load leaves no 404 in the
                // browser console.
                if (isset($_GET['peek']) && $_GET['peek'] === '1'
                    && is_array($result) && empty($result['ok'])
                    && isset($result['error']['type']) && $result['error']['type'] === 'not_found'
                ) {
                    $this->sendJsonResponse(array('success' => true, 'state' => null));
                }
                break;
            case 'update_draft':
                $result = $this->interview_service->updateDraft(
                    $model, $record_id, $this->requireIdParam('conversation_id'),
                    $this->requireRevisionParam(),
                    $this->paramPatch('patch'),
                    $this->paramPatch('native_patch')
                );
                break;
            case 'turn':
                $result = $this->interview_service->turn(
                    $model, $record_id, $this->requireIdParam('conversation_id'),
                    $this->requireRevisionParam(),
                    isset($_POST['message']) ? (string)$_POST['message'] : '',
                    $this->paramPatch('patch'),
                    isset($_POST['request_id']) ? (string)$_POST['request_id'] : '',
                    !empty($_POST['start']),
                    $this->paramPatch('native_patch')
                );
                break;
            case 'reset':
                // Explicit reset: open_token is required and the target
                // conversation must be named; it is CAS-abandoned.
                $result = $this->interview_service->open(
                    $model, $record_id,
                    $this->requireOpenToken(),
                    true,
                    $this->requireIdParam('conversation_id')
                );
                break;
            case 'approve':
                $this->handleApprove($model, $record_id);
                return;
            case 'transcribe':
                $this->handleTranscribe($model, $actor);
                return;
        }
        $this->sendInterviewResult($result);
    }

    /**
     * Speech-to-text for the interview composer: transcribes one uploaded
     * recording with the configured Whisper model and returns only the
     * text. Same gates as every interview mutation (login, page ACL, root
     * condition, CSRF) plus the CMS flag, upload and type/size checks.
     * The audio is deleted right after transcription - the interview
     * keeps no recordings.
     */
    private function handleTranscribe($model, $actor)
    {
        if (!$model->isInterviewSpeechEnabled()) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Speech-to-text is not enabled'),
            ), 400);
        }
        $upload = isset($_FILES['audio']) && is_array($_FILES['audio']) ? $_FILES['audio'] : null;
        if ($upload === null
            || !isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || $upload['error'] !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'])
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'No audio file provided'),
            ), 400);
        }
        $speech = new LlmSpeechToTextService($model->get_services());
        if ((int)$upload['size'] <= 0 || (int)$upload['size'] > LLM_MAX_AUDIO_SIZE
            || !$speech->isValidAudioType(isset($upload['type']) ? (string)$upload['type'] : '')
        ) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Unsupported or oversized audio'),
            ), 400);
        }
        try {
            $result = $speech->saveAndTranscribeAudio(
                $upload, $actor, (int)$model->get_section_id(), null,
                $model->getInterviewSpeechModel(), $speech->getUserLanguage(), false
            );
        } catch (Throwable $t) {
            error_log('Interview speech transcription error: ' . $t->getMessage());
            $result = array('success' => false);
        }
        if (!empty($result['success'])) {
            $this->sendJsonResponse(array(
                'success' => true,
                'text' => isset($result['text']) ? trim((string)$result['text']) : '',
            ));
        }
        if (isset($result['error'])) {
            error_log('Interview speech transcription failed: ' . (is_string($result['error']) ? $result['error'] : json_encode($result['error'])));
        }
        $this->sendJsonResponse(array(
            'success' => false,
            'error' => array('type' => 'transcription', 'message' => 'Speech transcription failed'),
        ), 502);
    }

    /**
     * Approval: outer transaction locks the scoped conversation, then the
     * inherited execute() runs the verified native write path (authorize,
     * GUMP, save/update, history, memory, form actions). redirect_at_end
     * is suppressed through the model approval flag so execute() cannot
     * header()+die() before the JSON answer - including on validation
     * failure. after_successful_write completes the scope inside the
     * same transaction.
     */
    private function handleApprove($model, $record_id)
    {
        $conversation_id = $this->requireIdParam('conversation_id');
        $revision = $this->requireRevisionParam();
        $request_token = isset($_POST['request_token']) ? (string)$_POST['request_token'] : '';
        if (trim($request_token) === '') {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Missing request_token'),
            ), 400);
        }
        $patch = $this->paramPatch('patch');
        $native_patch = $this->paramPatch('native_patch');
        $db = $model->get_services()->get_db();
        try {
            $db->begin_transaction();
            $prep = $this->interview_service->prepareApproval(
                $model, $record_id, $conversation_id, $revision, $request_token,
                $patch, $native_patch
            );
            if (empty($prep['ok'])) {
                $db->rollback();
                $this->sendInterviewResult($prep, 409);
            }
            if (!empty($prep['already_completed'])) {
                $db->commit();
                $this->sendJsonResponse(array(
                    'success' => true,
                    'approval' => $prep['receipt'],
                    'state' => $prep['state'],
                    'idempotent' => true,
                ));
            }
            $this->approval_prep = $prep;
            $_POST = $prep['post'];
            $model->setInterviewApprovalMode(true);
            try {
                $this->execute();
            } catch (Throwable $t) {
                $db->rollback();
                $this->sendJsonResponse(array(
                    'success' => false,
                    'error' => array(
                        'type' => 'approval_failed',
                        'message' => 'The approval write failed',
                    ),
                ), 500);
            }
            if ($this->fail || !$this->success) {
                $db->rollback();
                $this->sendJsonResponse(array(
                    'success' => false,
                    'error' => array(
                        'type' => 'validation',
                        'message' => 'Form validation failed',
                    ),
                    'form_errors' => $this->error_msgs,
                ), 422);
            }
            // Success path answers JSON inside after_successful_write().
            $db->rollback();
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array(
                    'type' => 'approval_failed',
                    'message' => 'The approval could not be completed',
                ),
            ), 500);
        } catch (Throwable $t) {
            try {
                $db->rollback();
            } catch (Throwable $rollback_e) {
            }
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array(
                    'type' => 'approval_failed',
                    'message' => 'The approval could not be completed',
                ),
            ), 500);
        }
    }

    /* Request helpers ********************************************************/

    /**
     * Strict CSRF (LlmCsrfService): the session token must exist and the
     * posted token must match it exactly; no "missing session" bypass.
     */
    private function verifyCsrf()
    {
        return LlmCsrfService::isValid(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '');
    }

    /**
     * Strict integer request parameter: only plain digit strings (or
     * ints) are accepted - arrays, signs, decimals, scientific notation
     * and trailing data are rejected.
     *
     * @return int|null|false int value, null when absent, false when malformed
     */
    private function strictParamInt($name)
    {
        foreach (array($_POST, $_GET) as $source) {
            if (!isset($source[$name])) {
                continue;
            }
            $raw = $source[$name];
            if (!is_scalar($raw) || preg_match('/^\d+$/', (string)$raw) !== 1) {
                return false;
            }
            return (int)$raw;
        }
        return null;
    }

    private function requireIdParam($name)
    {
        $value = $this->strictParamInt($name);
        if ($value === false || $value === null || $value <= 0) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => "Invalid or missing $name"),
            ), 400);
        }
        return $value;
    }

    private function requireOpenToken()
    {
        $token = isset($_POST['open_token']) ? trim((string)$_POST['open_token']) : '';
        if ($token === '') {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Missing open_token'),
            ), 400);
        }
        return $token;
    }

    private function requireRevisionParam()
    {
        $value = $this->strictParamInt('revision');
        if ($value === false || $value === null) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => 'Invalid or missing revision'),
            ), 400);
        }
        return $value;
    }

    /**
     * Patch envelope: JSON map (string or nested POST array); a malformed
     * JSON string is a 400, never a silently dropped patch.
     */
    private function paramPatch($key = 'patch')
    {
        $raw = isset($_POST[$key]) ? $_POST[$key] : null;
        if ($raw === null) {
            return array();
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $this->sendJsonResponse(array(
                    'success' => false,
                    'error' => array('type' => 'bad_request', 'message' => "Invalid $key envelope"),
                ), 400);
            }
            return $decoded;
        }
        if (!is_array($raw)) {
            $this->sendJsonResponse(array(
                'success' => false,
                'error' => array('type' => 'bad_request', 'message' => "Invalid $key envelope"),
            ), 400);
        }
        return $raw;
    }

    /**
     * Default native fragment renderer: a lightweight view instance
     * (never a second controller) rendering one original field component.
     */
    private function defaultNativeRenderer($model)
    {
        return function ($name) use ($model) {
            static $view = null;
            if ($view === null) {
                $view = new LlmFormLogListView($model, null);
            }
            return $view->renderNativeFieldFragment($name);
        };
    }

    private function sendInterviewResult($result, $error_status = 400)
    {
        if (is_array($result) && !empty($result['ok'])) {
            $this->sendJsonResponse(array('success' => true) + $result);
        }
        $type = isset($result['error']['type']) ? $result['error']['type'] : '';
        if ($type === 'unauthenticated') {
            $status = 401;
        } else if ($type === 'forbidden' || $type === 'danger_detected') {
            $status = 403;
        } else if ($type === 'not_found') {
            $status = 404;
        } else if ($type === 'conflict') {
            $status = 409;
        } else if ($type === 'validation') {
            $status = 422;
        } else if ($type === 'rate_limited') {
            $status = 429;
        } else {
            $status = $error_status;
        }
        $payload = array(
            'success' => false,
            'error' => isset($result['error']) ? $result['error'] : array(
                'type' => 'error', 'message' => 'Interview request failed',
            ),
            'state' => isset($result['state']) ? $result['state'] : null,
            'field_errors' => isset($result['field_errors'])
                ? $result['field_errors'] : null,
        );
        if ($type === 'danger_detected') {
            $payload['blocked'] = true;
            $payload['safety_message'] = isset($result['safety_message'])
                ? $result['safety_message']
                : (isset($result['error']['message']) ? $result['error']['message'] : null);
            if (isset($result['detected_keywords'])) {
                $payload['detected_keywords'] = $result['detected_keywords'];
            }
        }
        $this->sendJsonResponse($payload, $status);
    }
}
?>
