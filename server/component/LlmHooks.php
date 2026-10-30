<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../../../../component/style/BaseStyleComponent.php";
require_once __DIR__ . "/../service/LlmService.php";
require_once __DIR__ . "/../service/LlmScriptService.php";
require_once __DIR__ . "/../service/LlmPromptRegistryService.php";
require_once __DIR__ . "/../service/LlmPromptExecutionProfileService.php";
require_once __DIR__ . "/../service/LlmMemoryConfigService.php";
require_once __DIR__ . "/../service/LlmMemoryTriggerService.php";
require_once __DIR__ . "/../service/LlmMemoryRuleService.php";
require_once __DIR__ . "/../service/LlmMemorySubmissionContextService.php";
require_once __DIR__ . "/../service/LlmCsrfService.php";
require_once __DIR__ . "/../service/LlmTransaction.php";
require_once __DIR__ . "/../service/LlmErrorReporter.php";
require_once __DIR__ . "/../service/LlmApiKeysConfigService.php";

/**
 * The class to define the hooks for the LLM plugin.
 */
class LlmHooks extends BaseHooks
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the hooks.
     * @param object $services
     *  The service handler instance which holds all services
     * @param object $params
     *  Various params
     */
    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);
    }

    /* Private Methods *********************************************************/

    /**
     * Output select LLM Model field
     * @param string $value
     * Value of the field
     * @param string $name
     * The name of the fields
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return instance of BaseStyleComponent -> select style
     */
    private function outputSelectLlmModelField($value, $name, $disabled)
    {
        try {
            $llmService = new LlmService($this->services);
            $models = $llmService->getAvailableModels();
            $normalizedValue = $llmService->normalizeModelIdentifier($value);

            $items = array();
            $itemValues = array();
            foreach ($models as $model) {
                $itemId = $model['id'] ?? '';
                $items[] = array(
                    'value' => $itemId,
                    'text' => $itemId
                );
                $itemValues[$itemId] = true;
            }

            // ----------------------------------------------------------------
            // v1.3.0 defensive fix for the "saved model not selected on
            // reload" bug seen in two production deployments.
            //
            // Causes observed in the wild:
            //   - The configured LLM server is briefly unreachable and
            //     `getAvailableModels()` returns an empty array, so the
            //     dropdown has no item to match the saved value against.
            //   - The legacy single-server setup stored a raw model id
            //     (e.g. "qwen3-vl-8b-instruct") and the live server now
            //     returns scoped ids (e.g. "GPUStack :: qwen3-vl-...").
            //     `normalizeModelIdentifier()` cannot rewrite the legacy
            //     value because the lookup happens against the live list,
            //     so the option still has no matching <option> element.
            //   - Conversely, an admin manually saved a scoped value while
            //     the live list temporarily exposed only the raw form.
            //
            // Resolution: ALWAYS make the saved value selectable by adding
            // it to the items array if it is missing, and also add the
            // raw form (when scoped) so the matching select widget can
            // pick whichever exists in `value`. This guarantees the
            // dropdown renders the saved value as the active option even
            // if the model list is empty or stale.
            // ----------------------------------------------------------------
            $candidateValues = array();
            if (!empty($normalizedValue)) {
                $candidateValues[] = $normalizedValue;
            }
            if (!empty($value) && $value !== $normalizedValue) {
                $candidateValues[] = $value;
            }
            foreach ($candidateValues as $candidate) {
                if ($candidate !== '' && !isset($itemValues[$candidate])) {
                    array_unshift($items, array(
                        'value' => $candidate,
                        'text' => $candidate . ' (current)'
                    ));
                    $itemValues[$candidate] = true;
                }
            }

            // Pick whichever form of the saved value actually exists in
            // the items array so the <option selected> attribute matches.
            $selectedValue = '';
            if (!empty($normalizedValue) && isset($itemValues[$normalizedValue])) {
                $selectedValue = $normalizedValue;
            } elseif (!empty($value) && isset($itemValues[$value])) {
                $selectedValue = $value;
            }

            return new BaseStyleComponent("select", array(
                "value" => $selectedValue,
                "name" => $name,
                "max" => 8,
                "live_search" => 1,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        } catch (Exception $e) {
            // Fallback in case of error: still surface the saved value as
            // a single selectable item so the admin sees what was saved
            // even if the model list cannot be fetched.
            $items = array();
            if (!empty($value)) {
                $items[] = array('value' => $value, 'text' => $value . ' (current)');
            }
            $items[] = array('value' => '', 'text' => 'Error loading models: ' . $e->getMessage());
            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 8,
                "live_search" => 0,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        }
    }

    /**
     * Return a BaseStyleComponent object
     * @param object $args
     * Params passed to the method
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return a BaseStyleComponent object
     */
    private function returnSelectLlmModelField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] == 'llm_model' || $field['name'] == 'llm_default_model') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectLlmModelField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /**
     * Output select floating button position field
     * @param string $value
     * Value of the field
     * @param string $name
     * The name of the fields
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return instance of BaseStyleComponent -> select style
     */
    private function outputSelectFloatingPositionField($value, $name, $disabled)
    {
        $positions = $this->getFloatingButtonPositionsFromLookups();

        return new BaseStyleComponent("select", array(
            "value" => $value ?: 'bottom-right',
            "name" => $name,
            "max" => 10,
            "live_search" => 0,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $positions
        ));
    }

    /**
     * Load floating button positions from the shared lookups table.
     * Falls back to a static default set if the DB lookup fails.
     *
     * @return array Array of ['value' => ..., 'text' => ...] items
     */
    private function getFloatingButtonPositionsFromLookups()
    {
        try {
            $lookups = $this->db->query_db(
                "SELECT lookup_code, lookup_value FROM lookups WHERE type_code = ? ORDER BY lookup_value",
                array('floatingButtonPositions')
            );
            if (!empty($lookups)) {
                $positions = array();
                foreach ($lookups as $row) {
                    $positions[] = array('value' => $row['lookup_code'], 'text' => $row['lookup_value']);
                }
                return $positions;
            }
        } catch (Exception $e) {
            // fall through to defaults
        }

        return array(
            array('value' => 'bottom-right', 'text' => 'Bottom Right'),
            array('value' => 'bottom-left', 'text' => 'Bottom Left'),
            array('value' => 'top-right', 'text' => 'Top Right'),
            array('value' => 'top-left', 'text' => 'Top Left'),
            array('value' => 'bottom-center', 'text' => 'Bottom Center'),
            array('value' => 'top-center', 'text' => 'Top Center')
        );
    }

    /**
     * Return a BaseStyleComponent object for floating position field
     * 
     * This hook is triggered for any field with type 'select-floating-button-position'.
     * The hook name 'field-floating-button-position-edit' matches the field type
     * 'select-floating-button-position' following SelfHelp's hook naming convention.
     * 
     * @param object $args Params passed to the method
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return a BaseStyleComponent object
     */
    private function returnSelectFloatingPositionField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        // This hook is triggered for all fields with type 'select-floating-button-position'
        // Check field name to ensure we're processing the right field
        if ($field['name'] === 'floating_button_position') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectFloatingPositionField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /**
     * Build select for reasoning effort CMS field.
     *
     * @param object $args
     * @param int $disabled
     * @return object
     */
    private function returnSelectLlmReasoningEffortField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (($field['name'] ?? '') !== 'llm_reasoning_effort') {
            return $res;
        }

        require_once __DIR__ . '/../service/LlmModelCapabilities.php';

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = new BaseStyleComponent("select", array(
            "value" => $field['content'] ?? '',
            "name" => $field_name_prefix . "[content]",
            "max" => 10,
            "live_search" => 0,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => LlmModelCapabilities::getReasoningEffortSelectItems($this->db)
        ));

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /**
     * Build the multi select of memory rules for the shared select-llm-memory-rules CMS widget.
     * Field name picks the source-type filter:
     * - llm_memory_rule_ids (forms / surveys / LogList) → form_action_submit
     * - memory_rule_ids (llmChat fallback) → llm_chat_form_submit
     * Disabled rules stay visible so saved ids are not lost.
     *
     * @param object $args
     * @param int $disabled
     * @return object
     */
    private function returnSelectLlmMemoryRulesField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        $field_name = $field['name'] ?? '';
        if ($field_name === LLM_MEMORY_FIELD_RULE_IDS) {
            $source_type = LLM_MEMORY_SOURCE_FORM_ACTION;
        } elseif ($field_name === LLM_MEMORY_FIELD_CHAT_RULE_IDS) {
            $source_type = LLM_MEMORY_SOURCE_LLM_CHAT_FORM;
        } else {
            return $res;
        }

        $config_service = new LlmMemoryConfigService($this->services);
        $items = array();
        foreach ($config_service->getRules() as $rule) {
            if (($rule['source_type'] ?? '') !== $source_type) {
                continue;
            }
            $label = trim((string)($rule['label'] ?? '')) ?: ('Rule #' . (int)($rule['id'] ?? 0));
            $items[] = array(
                "value" => (string)(int)($rule['id'] ?? 0),
                "text" => $label . (!empty($rule['enabled']) ? '' : ' (disabled)')
            );
        }

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = new BaseStyleComponent("select", array(
            // an empty value must stay [''] (not []): the select view only defines "selected" inside its value loop
            "value" => array_map('trim', explode(',', (string)($field['content'] ?? ''))),
            "name" => $field_name_prefix . "[content]",
            "max" => 10,
            "live_search" => 1,
            "is_multiple" => 1,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $items
        ));

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /**
     * Memory rule ids selected on a form / SurveyJS section for a finished submission.
     * Cheap exits first: most sections have no selection and must cost no more than one field read.
     *
     * @param object $model The model of the section the participant submitted
     * @return int[] Rule ids; empty when nothing is selected, memory is off or the user is a guest
     */
    private function getSubmissionMemoryRuleIds($model)
    {
        $raw = trim((string)$model->get_db_field(LLM_MEMORY_FIELD_RULE_IDS));
        $user_id = (int)($_SESSION['id_user'] ?? 0);
        $section_id = (int)$model->get_db_field('id');
        // memory is stored per user: the shared guest user must not collect one memory for everybody
        if ($raw === '' || $user_id <= 0 || $user_id === GUEST_USER_ID) {
            (new LlmMemoryTriggerService($this->services))->appendWorkerLogPublic(
                'getSubmissionMemoryRuleIds empty',
                array(
                    'section_id' => $section_id,
                    'user_id' => $user_id,
                    'raw' => $raw,
                    'guest' => ($user_id === GUEST_USER_ID),
                )
            );
            return array();
        }
        if (!(new LlmMemoryConfigService($this->services))->isMemoryEnabled()) {
            return array();
        }
        $ids = (new LlmMemoryRuleService($this->services))->normalizeRuleIds($raw);
        (new LlmMemoryTriggerService($this->services))->appendWorkerLogPublic(
            'getSubmissionMemoryRuleIds resolved',
            array(
                'section_id' => $section_id,
                'user_id' => $user_id,
                'raw' => $raw,
                'rule_ids' => $ids,
            )
        );
        return $ids;
    }

    /**
     * Queue the selected rules for a finished submission as one scheduled job
     * (scheduleRuleJob), listed under Scheduled Jobs. Running it checks every rule
     * (missing or disabled rules are skipped) and starts one detached background
     * worker per rule and memory key, so the participant never waits for the LLM.
     *
     * @param int[] $rule_ids
     * @param array $context Snapshot from LlmMemorySubmissionContextService
     * @param int|string $record_id The saved record id
     * @param string $trigger_type finished, or deleted for an entry that was deleted
     * @param int|null $user_id Whose memory is updated; null for the session user
     */
    private function queueSubmissionMemoryRules($rule_ids, $context, $record_id, $trigger_type = actionTriggerTypes_finished, $user_id = null)
    {
        $trigger_service = new LlmMemoryTriggerService($this->services);
        $normalized = $trigger_service->normalizeFormActionPayload(array(
            'form_fields'  => $context['fields'],
            'form_name'    => $context['form_name'],
            'table_name'   => $context['table_name'],
            'trigger_type' => $trigger_type,
            'record_id'    => $record_id,
        ), $user_id !== null ? (int)$user_id : (int)$_SESSION['id_user']);
        $normalized['readable_text'] = $context['readable_text'];
        $trigger_service->scheduleRuleJob(
            $rule_ids,
            $normalized,
            true,
            'LLM Memory Update (form: ' . $context['form_name']
                . ($trigger_type === actionTriggerTypes_deleted ? ', entry deleted' : '') . ')'
        );
    }

    /**
     * Build a lookup-backed select for one of the `llm_interview_*` option
     * fields (`llmFormLogList` style). One hook pair serves all four
     * fields: the lookup family is chosen by field name and any other
     * field falls through to the default renderer.
     *
     * @param object $args
     * @param int $disabled
     * @return object
     */
    private function returnSelectLlmInterviewField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        // name => expected custom field type + lookup family; both must
        // match so a same-named field of another type gets no extra control
        static $field_map = array(
            'llm_interview_progress_layout' => array('select-llm-interview-progress-layout', LLM_LOOKUP_INTERVIEW_PROGRESS_LAYOUT),
            'llm_interview_chat_retention'  => array('select-llm-interview-retention', LLM_LOOKUP_INTERVIEW_RETENTION),
            'llm_interview_chat_position'   => array('select-llm-interview-chat-position', LLM_LOOKUP_INTERVIEW_CHAT_POSITION),
            'llm_interview_confirm'         => array('select-llm-interview-confirm', LLM_LOOKUP_INTERVIEW_CONFIRM),
        );
        $field_name = $field['name'] ?? '';
        $spec = $field_map[$field_name] ?? null;
        if ($spec === null || (string)($field['type'] ?? '') !== $spec[0]) {
            return $res;
        }

        $items = array();
        foreach ($this->db->get_lookups($spec[1]) as $option) {
            $items[] = array(
                "value" => (string)($option['lookup_code'] ?? ''),
                "text" => (string)($option['lookup_value'] ?? '')
            );
        }

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = new BaseStyleComponent("select", array(
            "value" => $field['content'] ?? '',
            "name" => $field_name_prefix . "[content]",
            "max" => 10,
            "live_search" => 0,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $items
        ));

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /* Public Methods *********************************************************/

    /**
     * Return a BaseStyleComponent object for edit mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldLlmModelEdit($args)
    {
        return $this->returnSelectLlmModelField($args, 0);
    }

    /**
     * Return a BaseStyleComponent object for view mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldLlmModelView($args)
    {
        return $this->returnSelectLlmModelField($args, 1);
    }

    /**
     * Return a BaseStyleComponent object for floating position edit mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFloatingPositionEdit($args)
    {
        return $this->returnSelectFloatingPositionField($args, 0);
    }

    /**
     * Return a BaseStyleComponent object for floating position view mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFloatingPositionView($args)
    {
        return $this->returnSelectFloatingPositionField($args, 1);
    }

    /**
     * CMS dropdown for llm_reasoning_effort (edit).
     *
     * @param object $args
     * @return object
     */
    public function outputFieldLlmReasoningEffortEdit($args)
    {
        return $this->returnSelectLlmReasoningEffortField($args, 0);
    }

    /**
     * CMS dropdown for llm_reasoning_effort (view).
     *
     * @param object $args
     * @return object
     */
    public function outputFieldLlmReasoningEffortView($args)
    {
        return $this->returnSelectLlmReasoningEffortField($args, 1);
    }

    /**
     * CMS multi select of memory rules for llm_memory_rule_ids / memory_rule_ids (edit).
     *
     * @param object $args
     * @return object
     */
    public function outputFieldLlmMemoryRulesEdit($args)
    {
        return $this->returnSelectLlmMemoryRulesField($args, 0);
    }

    /**
     * CMS multi select of memory rules for llm_memory_rule_ids / memory_rule_ids (view).
     *
     * @param object $args
     * @return object
     */
    public function outputFieldLlmMemoryRulesView($args)
    {
        return $this->returnSelectLlmMemoryRulesField($args, 1);
    }

    /**
     * CMS select for the llm_interview_* option fields (edit).
     *
     * @param object $args
     * @return object
     */
    public function outputFieldLlmInterviewSelectEdit($args)
    {
        return $this->returnSelectLlmInterviewField($args, 0);
    }

    /**
     * CMS select for the llm_interview_* option fields (view).
     *
     * @param object $args
     * @return object
     */
    public function outputFieldLlmInterviewSelectView($args)
    {
        return $this->returnSelectLlmInterviewField($args, 1);
    }

    /**
     * Output select audio model field for speech-to-text
     * @param string $value
     * Value of the field
     * @param string $name
     * The name of the fields
     * @param int $disabled 0 or 1
     * If the field is in edit mode or view mode (disabled)
     * @return object
     * Return instance of BaseStyleComponent -> select style
     */
    private function outputSelectAudioModelField($value, $name, $disabled)
    {
        try {
            $llmService = new LlmService($this->services);
            $models = $llmService->getAvailableModels(null, 'audio');
            $normalizedValue = $llmService->normalizeModelIdentifier($value);

            // Transform models array to select format
            $items = array(
                array('value' => '', 'text' => '-- Select Audio Model --')
            );
            $itemValues = array('' => true);
            foreach ($models as $model) {
                $itemId = $model['id'] ?? '';
                $items[] = array(
                    'value' => $itemId,
                    'text' => $itemId
                );
                $itemValues[$itemId] = true;
            }

            // v1.3.0 defensive fix (mirrors outputSelectLlmModelField):
            // surface the saved value as a selectable item even if the
            // live audio-model list is empty / stale, so the dropdown
            // correctly highlights the saved value on reload.
            $candidateValues = array();
            if (!empty($normalizedValue)) {
                $candidateValues[] = $normalizedValue;
            }
            if (!empty($value) && $value !== $normalizedValue) {
                $candidateValues[] = $value;
            }
            foreach ($candidateValues as $candidate) {
                if ($candidate !== '' && !isset($itemValues[$candidate])) {
                    $items[] = array(
                        'value' => $candidate,
                        'text' => $candidate . ' (current)'
                    );
                    $itemValues[$candidate] = true;
                }
            }

            $selectedValue = '';
            if (!empty($normalizedValue) && isset($itemValues[$normalizedValue])) {
                $selectedValue = $normalizedValue;
            } elseif (!empty($value) && isset($itemValues[$value])) {
                $selectedValue = $value;
            }

            return new BaseStyleComponent("select", array(
                "value" => $selectedValue,
                "name" => $name,
                "max" => 8,
                "live_search" => 1,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        } catch (Exception $e) {
            // Fallback in case of error: keep the saved value selectable
            // so the admin sees what was configured.
            $items = array(
                array('value' => '', 'text' => '-- Select Audio Model --'),
            );
            if (!empty($value)) {
                $items[] = array('value' => $value, 'text' => $value . ' (current)');
            }
            $items[] = array('value' => 'faster-whisper-large-v3', 'text' => 'faster-whisper-large-v3');
            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 8,
                "live_search" => 0,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        }
    }

    /**
     * Return a BaseStyleComponent object for audio model field
     * 
     * This hook is triggered for any field with type 'select-audio-model'.
     * The hook name 'field-audio-model-edit' matches the field type
     * 'select-audio-model' following SelfHelp's hook naming convention.
     * 
     * @param object $args Params passed to the method
     * @param int $disabled 0 or 1 - If the field is in edit mode or view mode (disabled)
     * @return object Return a BaseStyleComponent object
     */
    private function returnSelectAudioModelField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] === 'speech_to_text_model') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectAudioModelField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /**
     * Return a BaseStyleComponent object for audio model edit mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldAudioModelEdit($args)
    {
        return $this->returnSelectAudioModelField($args, 0);
    }

    /**
     * Return a BaseStyleComponent object for audio model view mode
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldAudioModelView($args)
    {
        return $this->returnSelectAudioModelField($args, 1);
    }

    /* =========================================================================
     * LLM SCRIPT JOB INTEGRATION HOOKS
     * ========================================================================= */

    /**
     * Execute LLM script task when job_type is llm_script.
     * Hook on Task::execute_task (priority 11, coexists with R Serve at 10).
     *
     * When the script has async=1, spawns a background PHP process so the
     * form submission returns immediately. The background worker executes the
     * LLM API call, saves results, and triggers section refresh events.
     *
     * @param array $args Hook arguments including task_info, user, etc.
     * @return bool Success
     */
    public function execute_llm_task($args)
    {
        if ($args['task_info']['config']['type'] == ACTION_JOB_TYPE_LLM_SCRIPT) {
            $scriptService = new LlmScriptService($this->services);
            $script_info = $scriptService->fetch_script(
                $args['task_info']['config'][ACTION_JOB_TYPE_LLM_SCRIPT]
            );

            if (!$script_info) {
                $this->transaction->add_transaction(
                    transactionTypes_insert,
                    TRANSACTION_BY_LLM_SCRIPT,
                    null, null, null, false,
                    "The LLM script was not found; " . json_encode($args)
                );
                return false;
            }

            if (!empty($script_info['async'])) {
                return $this->execute_llm_script_async($args, $script_info);
            }

            return $this->execute_llm_script_from_job($args, $script_info);
        } else {
            return $this->execute_private_method($args);
        }
    }

    /**
     * Spawn a background PHP process to execute the LLM script asynchronously.
     * Returns true immediately so the form submission completes without waiting.
     *
     * @param array $args Hook arguments
     * @param array $script_info Script record from DB
     * @return bool Always true (job marked as done; real work happens in background)
     */
    private function execute_llm_script_async($args, $script_info)
    {
        $form_values = $this->user_input->get_form_values(
            $args['task_info']['config']['form_data']['form_fields']
        );
        $linked_record_id = $this->resolveLlmScriptLinkedRecordId($args);

        // Persist survey/form images to the LLM upload folder before writing the
        // async args file so base64 never lands in the temp JSON payload.
        if (is_array($form_values) && !empty($form_values)) {
            require_once __DIR__ . '/../service/LlmScriptImageService.php';
            $combineImages = !empty($script_info['combine_images']);
            $apiMax = defined('LLM_MAX_FILES_PER_MESSAGE') ? (int)LLM_MAX_FILES_PER_MESSAGE : 4;
            $sourceMax = $combineImages
                ? (defined('LLM_MAX_SCRIPT_SOURCE_IMAGES') ? (int)LLM_MAX_SCRIPT_SOURCE_IMAGES : 12)
                : $apiMax;
            $imageService = new LlmScriptImageService($sourceMax);
            $imageResult = $imageService->materializeVariables(
                $form_values,
                $args['user']['id_users'],
                $script_info['id'] ?? 0
            );
            $form_values = $imageResult['variables'];
        }

        $worker_args = [
            'script_id' => $script_info['id'],
            'form_values' => $form_values,
            'id_users' => $args['user']['id_users'],
            'id_scheduledJobs' => $args['user']['id_scheduledJobs'],
            'http_host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'linked_record_id' => $linked_record_id,
        ];

        $tmp_file = tempnam(sys_get_temp_dir(), 'llm_async_');
        if (!$tmp_file || file_put_contents($tmp_file, json_encode($worker_args)) === false) {
            error_log('LLM async: failed to write temp args file, falling back to sync');
            return $this->execute_llm_script_from_job($args, $script_info);
        }

        $worker_script = realpath(__DIR__ . '/../service/llm_async_worker.php');
        if (!$worker_script) {
            error_log('LLM async: worker script not found, falling back to sync');
            @unlink($tmp_file);
            return $this->execute_llm_script_from_job($args, $script_info);
        }

        $php_bin = BaseLlmService::resolvePhpCliBinary();

        $is_absolute = ($php_bin[0] === '/' || (strlen($php_bin) > 1 && $php_bin[1] === ':'));
        if ($is_absolute && !file_exists($php_bin)) {
            error_log('LLM async: PHP CLI binary not found at: ' . $php_bin . ', falling back to sync');
            @unlink($tmp_file);
            return $this->execute_llm_script_from_job($args, $script_info);
        }
        $php_flags = '-d apc.enable_cli=1';
        $log_file = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'llm_async_worker.log';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" '
                . '"' . $php_bin . '" '
                . $php_flags . ' '
                . '"' . $worker_script . '" '
                . '"' . $tmp_file . '"'
                . ' >> "' . $log_file . '" 2>&1';
        } else {
            $cmd = escapeshellarg($php_bin)
                . ' ' . $php_flags
                . ' ' . escapeshellarg($worker_script)
                . ' ' . escapeshellarg($tmp_file)
                . ' >> ' . escapeshellarg($log_file) . ' 2>&1 &';
        }

        error_log('LLM async: spawning command: ' . $cmd);

        $handle = popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
        } else {
            error_log('LLM async: popen failed, falling back to sync');
            @unlink($tmp_file);
            return $this->execute_llm_script_from_job($args, $script_info);
        }

        if (defined('DEBUG') && DEBUG) {
            error_log('LLM async: spawned background worker for script '
                . $script_info['name'] . ' (user=' . $args['user']['id_users'] . ')');
        }

        return true;
    }

    /**
     * Internal: execute an LLM script from a scheduled job (synchronous).
     *
     * @param array $args Hook arguments
     * @param array|null $script_info Pre-fetched script record (avoids double fetch)
     */
    private function execute_llm_script_from_job($args, $script_info = null)
    {
        $scriptService = new LlmScriptService($this->services);
        $linked_record_id = $this->resolveLlmScriptLinkedRecordId($args);

        if (!$script_info) {
            $script_info = $scriptService->fetch_script(
                $args['task_info']['config'][ACTION_JOB_TYPE_LLM_SCRIPT]
            );
        }

        if (!$script_info) {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_SCRIPT,
                null, null, null, false,
                "The LLM script was not found; " . json_encode($args)
            );
            return false;
        }

        $form_values = $this->user_input->get_form_values($args['task_info']['config']['form_data']['form_fields']);
        $data_config = $script_info['data_config'] ? json_decode($script_info['data_config'], true) : null;

        $result = $scriptService->execute_llm_script(
            $script_info['script'],
            $data_config,
            $form_values,
            $args['user']['id_users'],
            $script_info['model'],
            $script_info['temperature'] !== null ? floatval($script_info['temperature']) : null,
            $script_info['max_tokens'] !== null ? intval($script_info['max_tokens']) : null,
            $script_info['id'],
            $script_info['name']
        );

        $save_success = $scriptService->save_llm_results(
            $result,
            $args['user']['id_users'],
            $args['user']['id_scheduledJobs'],
            $script_info['generated_id'],
            $linked_record_id
        );

        if ($save_success && $script_info['refresh_sections']) {
            $section_ids = json_decode($script_info['refresh_sections'], true);
            if (is_array($section_ids) && !empty($section_ids)) {
                $scriptService->insert_refresh_event(
                    $args['user']['id_users'],
                    $section_ids,
                    'llm_script_completed',
                    json_encode(array('generated_id' => $script_info['generated_id']))
                );
            }
        }

        return $save_success;
    }

    /**
     * Resolve the source record id for a record-triggered LLM script job.
     *
     * @param array $args Hook arguments
     * @return int|null
     */
    private function resolveLlmScriptLinkedRecordId($args)
    {
        $form_data = $args['task_info']['config']['form_data'] ?? array();
        $raw_record_id = $form_data['record_id'] ?? ($form_data['form_fields'][ENTRY_RECORD_ID] ?? null);

        if ($raw_record_id === null || $raw_record_id === '') {
            return null;
        }

        if (!is_numeric($raw_record_id)) {
            return null;
        }

        $record_id = (int)$raw_record_id;
        return $record_id > 0 ? $record_id : null;
    }

    /**
     * Add llm_script option to the jobConfig JSON schema.
     * Hook on JobConfigView::get_json_schema (priority 11).
     */
    public function get_json_schema($args)
    {
        $llm_scripts = $this->db->fetch_table_as_select_values(LLM_TABLE_SCRIPTS, 'id', array('generated_id', 'name'));
        $enum_titles = array();
        $enum = array();
        foreach ($llm_scripts as $value) {
            $enum_titles[] = $value['text'];
            $enum[] = $value['value'];
        }

        $res = (string) $this->execute_private_method($args);
        $res = json_decode($res, true);

        $llm_script_field = array(
            "type" => "string",
            "options" => array(
                "grid_columns" => 12,
                "enum_titles" => $enum_titles,
                "dependencies" => array(
                    "job_type" => array(ACTION_JOB_TYPE_LLM_SCRIPT)
                )
            ),
            "title" => "LLM script",
            "description" => "Select LLM script to execute",
            "enum" => $enum
        );

        $res['definitions']['job_ref']['properties']['job_type']['enum'][] = ACTION_JOB_TYPE_LLM_SCRIPT;
        $res['definitions']['job_ref']['properties']['job_type']['options']['enum_titles'][] = "LLM script";
        $res['definitions']['job_ref']['properties'][ACTION_JOB_TYPE_LLM_SCRIPT] = $llm_script_field;

        return json_encode($res);
    }

    /**
     * Build task config for LLM script jobs.
     * Hook on UserInput::get_task_config (priority 11).
     */
    public function get_task_config($args)
    {
        $job = $args['job'];
        if ($job['job_type'] == ACTION_JOB_TYPE_LLM_SCRIPT) {
            $script_name = '';
            $script_id = isset($job[ACTION_JOB_TYPE_LLM_SCRIPT]) ? $job[ACTION_JOB_TYPE_LLM_SCRIPT] : null;
            if ($script_id) {
                $scriptService = new LlmScriptService($this->services);
                $script_info = $scriptService->fetch_script($script_id);
                if ($script_info) {
                    $script_name = $script_info['name'];
                }
            }
            $description = isset($job['job_name']) && $job['job_name']
                ? $job['job_name']
                : 'LLM Script' . ($script_name ? ': ' . $script_name : '')
                    . ' (form: ' . $args['form_data']['form_name'] . ')';
            return array(
                "type" => $job[ACTION_JOB_TYPE],
                "description" => $description,
                ACTION_JOB_TYPE_LLM_SCRIPT => $job[ACTION_JOB_TYPE_LLM_SCRIPT],
                "form_data" => $args['form_data'],
                "id_users" => $_SESSION['id_user'] ?? null
            );
        } else {
            return $this->execute_private_method($args);
        }
    }

    /**
     * Return jobTypes_task for llm_script job type.
     * Hook on UserInput::get_job_type (priority 11).
     */
    public function get_job_type($args)
    {
        $res = $this->execute_private_method($args);
        if ($args['job']['job_type'] == ACTION_JOB_TYPE_LLM_SCRIPT) {
            return jobTypes_task;
        }
        return $res;
    }

    /**
     * Add moduleLlmScriptMode to sensible pages list.
     * Hook on Router::get_sensible_pages.
     */
    public function get_sensible_pages($args)
    {
        $res = $this->execute_private_method($args);
        $res[] = LLM_SCRIPTS_PAGE_KEYWORD;
        $res[] = LLM_MEMORY_PAGE_KEYWORD;
        return $res;
    }

    /**
     * Get the plugin version
     */
    public function get_plugin_db_version($plugin_name = 'llm')
    {
        return parent::get_plugin_db_version($plugin_name);
    }

    /* LLM Form Field Hooks ***************************************************/

    /**
     * Output select LLM result placement field (edit mode).
     */
    /**
     * Build a CMS select component for LLM result placement (top/bottom/left/right).
     *
     * @param string $value    Current placement value.
     * @param string $name     Form field name attribute.
     * @param int    $disabled 1 to disable editing, 0 for editable.
     * @return BaseStyleComponent Select component.
     */
    private function outputSelectLlmResultPlacementField($value, $name, $disabled)
    {
        $placements = [
            ['value' => 'top', 'text' => 'Top'],
            ['value' => 'bottom', 'text' => 'Bottom'],
            ['value' => 'left', 'text' => 'Left'],
            ['value' => 'right', 'text' => 'Right'],
        ];
        return new BaseStyleComponent("select", array(
            "value" => $value,
            "name" => $name,
            "is_required" => false,
            "disabled" => $disabled,
            "items" => $placements
        ));
    }

    /**
     * Hook handler that injects the result-placement select into the CMS field output.
     *
     * @param array $args     Hook arguments with field metadata.
     * @param int   $disabled 1 for view mode, 0 for edit mode.
     * @return mixed Modified component tree with placement select appended.
     */
    private function returnSelectLlmResultPlacementField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (!isset($field['type']) || $field['type'] !== 'select-llm-result-placement') {
            return $res;
        }

        $value = isset($field['content']) && $field['content'] !== '' ? $field['content'] : 'bottom';
        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = $this->outputSelectLlmResultPlacementField($value, $field_name_prefix . "[content]", $disabled);

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /** @return mixed CMS edit-mode output for the LLM result placement field. */
    public function outputFieldLlmResultPlacementEdit($args)
    {
        return $this->returnSelectLlmResultPlacementField($args, 0);
    }

    /** @return mixed CMS view-mode output for the LLM result placement field. */
    public function outputFieldLlmResultPlacementView($args)
    {
        return $this->returnSelectLlmResultPlacementField($args, 1);
    }

    /**
     * Output select LLM result panel type field (edit mode).
     */
    /**
     * Build a CMS select component for LLM result panel type (default/card/modal/collapse).
     *
     * @param string $value    Current panel value.
     * @param string $name     Form field name attribute.
     * @param int    $disabled 1 to disable editing, 0 for editable.
     * @return BaseStyleComponent Select component.
     */
    private function outputSelectLlmResultPanelField($value, $name, $disabled)
    {
        $panels = [
            ['value' => 'default', 'text' => 'Default (Inline)'],
            ['value' => 'card', 'text' => 'Card'],
            ['value' => 'modal', 'text' => 'Modal'],
            ['value' => 'collapse', 'text' => 'Collapse'],
        ];
        return new BaseStyleComponent("select", array(
            "value" => $value,
            "name" => $name,
            "is_required" => false,
            "disabled" => $disabled,
            "items" => $panels
        ));
    }

    /**
     * Hook handler that injects the result-panel select into the CMS field output.
     *
     * @param array $args     Hook arguments with field metadata.
     * @param int   $disabled 1 for view mode, 0 for edit mode.
     * @return mixed Modified component tree with panel select appended.
     */
    private function returnSelectLlmResultPanelField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (!isset($field['type']) || $field['type'] !== 'select-llm-result-panel') {
            return $res;
        }

        $value = isset($field['content']) && $field['content'] !== '' ? $field['content'] : 'card';
        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $selectField = $this->outputSelectLlmResultPanelField($value, $field_name_prefix . "[content]", $disabled);

        if ($selectField && $res) {
            $children = $res->get_view()->get_children();
            $children[] = $selectField;
            $res->get_view()->set_children($children);
        }

        return $res;
    }

    /** @return mixed CMS edit-mode output for the LLM result panel field. */
    public function outputFieldLlmResultPanelEdit($args)
    {
        return $this->returnSelectLlmResultPanelField($args, 0);
    }

    /** @return mixed CMS view-mode output for the LLM result panel field. */
    public function outputFieldLlmResultPanelView($args)
    {
        return $this->returnSelectLlmResultPanelField($args, 1);
    }

    /* Prompt Registry Field Hooks ********************************************/

    /**
     * Render the React-based prompt field shell for CMS.
     *
     * @param array $args
     * @param bool $disabled
     * @return object
     */
    private function returnLlmPromptField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if (($field['type'] ?? '') !== 'llm_prompt') {
            return $res;
        }

        $cms_model = $this->get_private_property(array(
            'hookedClassInstance' => $args['hookedClassInstance'],
            'propertyName' => 'model'
        ));

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $uid = 'llm_prompt_' . md5($field_name_prefix);
        $endpoint = $this->get_link_url(LLM_PROMPT_LAB_PAGE_KEYWORD);
        if (!$endpoint || strpos($endpoint, '[AjaxLlmPromptLab:class]') !== false || strpos($endpoint, '[dispatch:method]') !== false) {
            $endpoint = '/request/AjaxLlmPromptLab/dispatch';
        }

        $templateComponent = new BaseStyleComponent("template", array(
            "path" => __DIR__ . "/tpl_prompt_field.php",
            "items" => array(
                "uid" => $uid,
                "fieldNamePrefix" => $field_name_prefix,
                "inputName" => $field_name_prefix . "[content]",
                "metaInputName" => $field_name_prefix . "[meta]",
                "jsonConfig" => json_encode(array(
                    'endpoint' => $endpoint,
                    'csrfToken' => LlmCsrfService::getToken(),
                    'disabled' => $disabled ? 1 : 0,
                    'ownerType' => LLM_PROMPT_OWNER_STYLE_FIELD,
                    'ownerId' => $cms_model->get_active_section_id(),
                    'pageId' => $cms_model->get_active_page_id(),
                    'promptSlot' => $field['name'] ?? '',
                    'languageId' => $field['id_language'] ?? 1,
                    'title' => $field['label'] ?? ucfirst(str_replace('_', ' ', $field['name'] ?? 'Prompt')),
                    'styleName' => $field['style_name'] ?? '',
                    'sectionName' => $field['section_name'] ?? ''
                ), JSON_UNESCAPED_SLASHES),
                "contentValue" => $field['content'] ?? '',
                "metaValue" => array_key_exists('meta', $field) ? $field['meta'] : '',
                "disabled" => $disabled,
                "fieldId" => $field['id'] ?? '',
                "fieldType" => $field['type'] ?? '',
                "fieldRelation" => $field['relation'] ?? '',
            )
        ));

        if ($templateComponent && $res) {
            $res->get_view()->set_children(array($templateComponent));
        }

        return $res;
    }


    /**
     * Output custom prompt field in edit mode.
     *
     * @param array $args
     * @return object
     */
    public function outputFieldLlmPromptEdit($args)
    {
        return $this->returnLlmPromptField($args, false);
    }

    /**
     * Output custom prompt field in view mode.
     *
     * @param array $args
     * @return object
     */
    public function outputFieldLlmPromptView($args)
    {
        return $this->returnLlmPromptField($args, true);
    }

    /**
     * Hook on CmsModel::update_db. Before the normal CMS field save it
     * validates, merges and encrypts the API keys list (the editor only knows
     * servers without keys); after the save it syncs the prompt version
     * history.
     *
     * @param array $args
     * @return mixed
     */
    public function syncPromptVersionOnCmsSave($args)
    {
        $args = $this->protectApiKeysBeforeCmsSave($args);
        if ($args === false) {
            return false;
        }

        $res = $this->execute_private_method($args);

        if ($res !== true) {
            return $res;
        }

        $field_id = (int)$this->get_param_by_name($args, 'id');
        if ($field_id <= 0) {
            return $res;
        }

        $field_info = $this->services->get_db()->query_db_first(
            "SELECT f.name, ft.name AS type
             FROM fields f
             INNER JOIN fieldType ft ON ft.id = f.id_type
             WHERE f.id = :id",
            array(':id' => $field_id)
        );

        if (!$field_info) {
            return $res;
        }

        $field_type = $field_info['type'] ?? '';
        if ($field_type === 'llm_prompt') {
            return $this->syncStyleFieldPromptOnCmsSave($args, $res, $field_id, $field_info);
        }

        return $res;
    }

    /**
     * The CMS editor of the `llm_api_keys` field posts the list it was given:
     * servers without their keys (LlmApiKeysConfigService::toClient). Merge it
     * with the stored list so keys are kept, new keys are encrypted and
     * nothing invalid is stored.
     *
     * @param array $args update_db hook arguments
     * @return array|false The arguments to continue with (content replaced by
     *                     the merged JSON), or false to store nothing
     */
    private function protectApiKeysBeforeCmsSave($args)
    {
        if ($this->get_param_by_name($args, 'relation') !== RELATION_PAGE_FIELD) {
            return $args;
        }

        $field_id = (int)$this->get_param_by_name($args, 'id');
        $field = $this->services->get_db()->query_db_first(
            "SELECT name FROM fields WHERE id = :id",
            array(':id' => $field_id)
        );
        if (!$field || $field['name'] !== 'llm_api_keys') {
            return $args;
        }

        $stored = $this->services->get_db()->query_db_first(
            "SELECT content FROM pages_fields_translation
             WHERE id_pages = :page AND id_fields = :field AND id_languages = :lang",
            array(
                ':page' => $args['hookedClassInstance']->get_active_page_id(),
                ':field' => $field_id,
                ':lang' => (int)$this->get_param_by_name($args, 'id_language')
            )
        );

        $errors = array();
        $merged = LlmApiKeysConfigService::prepareForSave(
            (string)$this->get_param_by_name($args, 'content'),
            $stored['content'] ?? '',
            $errors
        );
        if ($merged === null) {
            error_log('[LLM] The API keys were not saved: ' . implode(' ', $errors));
            return false;
        }

        $args['content'] = $merged;
        return $args;
    }

    /**
     * Sync a normal llm_prompt field save into prompt-lab version history.
     *
     * @param array $args
     * @param mixed $res
     * @param int $field_id
     * @param array $field_info
     * @return mixed
     */
    private function syncStyleFieldPromptOnCmsSave($args, $res, $field_id, $field_info)
    {
        $relation = $this->get_param_by_name($args, 'relation');
        if ($relation !== RELATION_SECTION_FIELD) {
            return $res;
        }

        $cms_model = $args['hookedClassInstance'];
        $section_id = $cms_model->get_active_section_id();
        if (!$section_id) {
            return $res;
        }

        $registry = new LlmPromptRegistryService($this->services);
        $profile_service = new LlmPromptExecutionProfileService($this->services);
        $descriptor = array(
            'owner_type' => LLM_PROMPT_OWNER_STYLE_FIELD,
            'owner_id' => $section_id,
            'prompt_slot' => $field_info['name'],
            'id_languages' => (int)$this->get_param_by_name($args, 'id_language'),
            'title' => ucfirst(str_replace('_', ' ', $field_info['name']))
        );

        $profile = $profile_service->resolveExecutionProfile($descriptor);
        $runtime_values = $profile_service->getStyleFieldValues(
            $section_id,
            $descriptor['id_languages'],
            $profile_service->getCompanionFieldNames($profile)
        );

        foreach ($this->getPostedRuntimeOverrides($profile_service->getCompanionFieldNames($profile)) as $key => $value) {
            $runtime_values[$key] = $value;
        }

        $content = (string)$this->get_param_by_name($args, 'content');
        $meta = $args['meta'] ?? null;
        $sync = $registry->syncFieldSave($descriptor, $field_id, $content, $meta, $runtime_values);

        $cms_model->update_section_fields_db(
            $field_id,
            $descriptor['id_languages'],
            (int)$this->get_param_by_name($args, 'id_gender'),
            $content,
            $section_id,
            $sync['meta_json']
        );

        return $res;
    }

    /**
     * Ensure prompt field CSS is loaded on CMS pages.
     *
     * @param array $args
     * @return array
     */
    public function addCmsPromptCssIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $css_file = __DIR__ . "/../../css/ext/llm-prompt-field.css";
        if (file_exists($css_file) && !in_array($css_file, $res, true)) {
            $res[] = $css_file;
        }

        return $res;
    }

    /**
     * Ensure prompt field JS is loaded on CMS pages.
     *
     * @param array $args
     * @return array
     */
    public function addCmsPromptJsIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $js_file = __DIR__ . "/../../js/ext/llm-prompt-field.umd.js";
        if (file_exists($js_file) && !in_array($js_file, $res, true)) {
            $res[] = $js_file;
        }

        return $res;
    }

    /**
     * Read unsaved companion field values from the current CMS POST payload.
     *
     * @param array $field_names
     * @return array
     */
    private function getPostedRuntimeOverrides($field_names)
    {
        $result = array();
        $posted_fields = $_POST['fields'] ?? array();
        if (!is_array($posted_fields)) {
            return $result;
        }

        foreach ($field_names as $field_name) {
            if (!isset($posted_fields[$field_name]) || !is_array($posted_fields[$field_name])) {
                continue;
            }

            foreach ($posted_fields[$field_name] as $by_language) {
                if (!is_array($by_language)) {
                    continue;
                }

                foreach ($by_language as $by_gender) {
                    if (!is_array($by_gender)) {
                        continue;
                    }

                    if (array_key_exists('content', $by_gender)) {
                        $result[$field_name] = $by_gender['content'];
                        break 2;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Persist CMS field content without re-entering CmsModel::update_db hooks.
     *
     * @param object $cms_model
     * @param int $field_id
     * @param int $language_id
     * @param int $gender_id
     * @param string $relation
     * @param string $content
     * @param string|null $meta
     * @return void
     */
    private function persistCmsFieldContent($cms_model, $field_id, $language_id, $gender_id, $relation, $content, $meta = null)
    {
        if ($relation === RELATION_SECTION_FIELD) {
            $cms_model->update_section_fields_db($field_id, $language_id, $gender_id, $content, null, $meta);
            return;
        }

        if ($relation === RELATION_PAGE_FIELD) {
            $insert = array(
                'content' => $content,
                'id_fields' => $field_id,
                'id_languages' => $language_id,
                'id_pages' => $cms_model->get_active_page_id()
            );
            $update = array('content' => $content);
            $this->db->insert('pages_fields_translation', $insert, $update);
        }
    }

    /* LLM API Keys Manager Hooks *********************************************/

    /**
     * Hook handler for the llm_api_keys field in CMS.
     * Uses the `template` BaseStyleComponent to render a PHP template.
     * Replaces the default field children so the raw JSON is never shown.
     *
     * @param array $args Hook arguments
     * @param bool $disabled Whether the field is read-only
     * @return object BaseStyleComponent
     */
    private function returnLlmApiKeysField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] !== 'llm_api_keys') {
            return $res;
        }

        // Never hand the stored keys to the browser: servers without keys.
        $json_value = LlmApiKeysConfigService::toClientJson($field['content'] ?? '');

        $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
        $inputName = $field_name_prefix . "[content]";
        $uid = 'llm_api_keys_' . md5($inputName);

        $templateComponent = new BaseStyleComponent("template", array(
            "path" => __DIR__ . "/tpl_api_keys_manager.php",
            "items" => array(
                "uid" => $uid,
                "fieldNamePrefix" => $field_name_prefix,
                "inputName" => $inputName,
                "jsonValue" => $json_value,
                "disabled" => $disabled,
                // Each server change is stored right away through the settings endpoint
                // (same validation, ACL and CSRF as the settings page)
                "saveUrl" => $disabled ? '' : (string)$this->get_link_url(PAGE_LLM_CONFIG),
                "csrfToken" => $disabled ? '' : LlmCsrfService::getToken(),
                "fieldId" => $field['id'] ?? '',
                "fieldType" => $field['type'] ?? '',
                "fieldRelation" => $field['relation'] ?? '',
                "fieldMeta" => array_key_exists('meta', $field) ? $field['meta'] : null
            )
        ));

        if ($templateComponent && $res) {
            $res->get_view()->set_children(array($templateComponent));
        }

        return $res;
    }

    /**
     * Output custom LLM API keys manager in edit mode
     * @param array $args Hook arguments
     * @return object BaseStyleComponent
     */
    public function outputFieldLlmApiKeysEdit($args)
    {
        return $this->returnLlmApiKeysField($args, false);
    }

    /**
     * Output custom LLM API keys manager in view mode
     * @param array $args Hook arguments
     * @return object BaseStyleComponent
     */
    public function outputFieldLlmApiKeysView($args)
    {
        return $this->returnLlmApiKeysField($args, true);
    }

    /**
     * Ensure API keys manager CSS is loaded on CMS pages.
     * Needed in DEBUG mode where plugin ext assets are not auto-collected.
     *
     * @param array $args Hook arguments
     * @return array
     */
    public function addCmsApiKeysCssIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $css_file = __DIR__ . "/../../css/ext/llm-apikeys.css";
        if (file_exists($css_file) && !in_array($css_file, $res, true)) {
            $res[] = $css_file;
        }

        return $res;
    }

    /**
     * Ensure API keys manager JS is loaded on CMS pages.
     * Needed in DEBUG mode where plugin ext assets are not auto-collected.
     *
     * @param array $args Hook arguments
     * @return array
     */
    public function addCmsApiKeysJsIncludes($args)
    {
        $res = $this->execute_private_method($args);
        if (!is_array($res)) {
            $res = array();
        }

        $js_file = __DIR__ . "/../../js/ext/llm-apikeys.umd.js";
        if (file_exists($js_file) && !in_array($js_file, $res, true)) {
            $res[] = $js_file;
        }

        return $res;
    }

    /**
     * Output select memory storage mode field.
     */
    /**
     * Build a CMS select component for the memory storage mode, loading options from lookups.
     *
     * @param string $value    Current storage mode lookup code.
     * @param string $name     Form field name attribute.
     * @param int    $disabled 1 to disable editing, 0 for editable.
     * @return BaseStyleComponent Select component with storage mode options.
     */
    private function outputSelectMemoryStorageModeField($value, $name, $disabled)
    {
        try {
            $lookups = $this->db->query_db(
                "SELECT lookup_code, lookup_value, lookup_description FROM lookups WHERE type_code = ? ORDER BY lookup_value",
                array('llmMemoryStorageMode')
            );
            $items = array();
            foreach ($lookups as $row) {
                $items[] = array(
                    'value' => $row['lookup_code'],
                    'text' => $row['lookup_value'] . ' - ' . $row['lookup_description']
                );
            }
        } catch (Exception $e) {
            $items = array(
                array('value' => 'memory_storage_both', 'text' => 'both'),
                array('value' => 'memory_storage_record', 'text' => 'record'),
                array('value' => 'memory_storage_log', 'text' => 'log'),
            );
        }

        return new BaseStyleComponent("select", array(
            "value" => $value ?: 'memory_storage_both',
            "name" => $name,
            "is_required" => 0,
            "disabled" => $disabled,
            "items" => $items
        ));
    }

    /**
     * Hook handler that injects the memory storage mode select into the CMS field output.
     *
     * @param array $args     Hook arguments with field metadata.
     * @param int   $disabled 1 for view mode, 0 for edit mode.
     * @return mixed Modified component tree with storage mode select appended.
     */
    private function returnSelectMemoryStorageModeField($args, $disabled)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);

        if ($field['name'] === 'llm_memory_storage_mode') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $selectField = $this->outputSelectMemoryStorageModeField($field['content'], $field_name_prefix . "[content]", $disabled);

            if ($selectField && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $selectField;
                $res->get_view()->set_children($children);
            }
        }

        return $res;
    }

    /** @return mixed CMS edit-mode output for the memory storage mode field. */
    public function outputFieldMemoryStorageModeEdit($args)
    {
        return $this->returnSelectMemoryStorageModeField($args, 0);
    }

    /** @return mixed CMS view-mode output for the memory storage mode field. */
    public function outputFieldMemoryStorageModeView($args)
    {
        return $this->returnSelectMemoryStorageModeField($args, 1);
    }

    /* =========================================================================
     * LLM MEMORY JOB INTEGRATION HOOKS
     * ========================================================================= */

    /**
     * Execute LLM memory update task when job_type is llm_memory_update.
     * Hook on Task::execute_task (priority 12).
     *
     * @param array $args Hook arguments
     * @return bool
     */
    public function execute_memory_task($args)
    {
        if (($args['task_info']['config']['type'] ?? '') !== ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
            return $this->execute_private_method($args);
        }

        $config = $args['task_info']['config'];
        $user_id = $args['user']['id_users'] ?? null;

        if (!$user_id) {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_MEMORY,
                null, null, null, false,
                "LLM Memory task: No user ID in job args; " . json_encode($args)
            );
            return false;
        }

        // UserInput::save_data builds $form_data with the table name in
        // `form_name` and the inserted/updated record id inside
        // `form_fields[ENTRY_RECORD_ID]`. Older code paths still occasionally
        // populate `table_name` / `record_id` at the top level, so accept both.
        $form_fields = $config['form_data']['form_fields'] ?? [];
        $form_table = (string)(
            $config['form_data']['table_name']
            ?? $config['form_data']['form_name']
            // jobs from LlmMemoryTriggerService::scheduleRuleJob() carry no form_data
            ?? $config['normalized_payload']['match_criteria']['table_name']
            ?? ''
        );
        $record_id = $config['form_data']['record_id']
            ?? ($form_fields[ENTRY_RECORD_ID] ?? null);

        if ($form_table !== '') {
            $memory_config = new LlmMemoryConfigService($this->services);
            $blocked_tables = [
                $memory_config->getCurrentTableName(),
                $memory_config->getHistoryTableName(),
            ];
            if (in_array($form_table, $blocked_tables, true)) {
                $this->transaction->add_transaction(
                    transactionTypes_insert,
                    TRANSACTION_BY_LLM_MEMORY,
                    $user_id, null, null, false,
                    "LLM Memory task BLOCKED: form table is a memory table (infinite loop prevention); table=" . $form_table
                );
                return true;
            }
        }

        $rule_ids = $config['memory_rule_id'] ?? $config['memory_rule_ids'] ?? [];
        if (!is_array($rule_ids)) {
            $rule_ids = array_filter(array_map('trim', explode(',', (string)$rule_ids)));
        }
        $rule_ids = array_values(array_filter(array_map('intval', $rule_ids)));

        $run_async = !empty($config['run_async']);

        $trigger_service = new LlmMemoryTriggerService($this->services);
        if (isset($config['normalized_payload']) && is_array($config['normalized_payload'])) {
            // Scheduled by LlmMemoryTriggerService::scheduleRuleJob() with the
            // payload its trigger already built (style rules, chat forms).
            $normalized = $config['normalized_payload'];
            $normalized['user_id'] = $user_id;
        } else {
            $normalized = $trigger_service->normalizeFormActionPayload([
                'form_fields'  => $form_fields,
                'form_name'    => $config['form_data']['form_name'] ?? $form_table,
                'table_name'   => $form_table,
                'trigger_type' => $config['trigger_type'] ?? 'finished',
                'record_id'    => $record_id,
            ], $user_id);
        }
        // The update reports its result on this job (Scheduled Jobs).
        $normalized['scheduled_job_id'] = (int)($args['task_info']['id'] ?? 0);

        if (!empty($config['memory_key_override'])) {
            $normalized['memory_key_override'] = $config['memory_key_override'];
        }
        if (!empty($config['force_storage_mode'])) {
            $normalized['force_storage_mode'] = $config['force_storage_mode'];
        }

        if (!empty($rule_ids)) {
            // Core runs this task inside JobScheduler::execute_job()'s own
            // transaction, but only after UserInput::save_data() committed
            // the submission (or later, from the cron queue), so the update
            // may run now. Inside an LlmTransaction (form interview
            // approval) it still waits for that commit.
            $dispatched = $trigger_service->dispatchForRuleIds($rule_ids, $normalized, $run_async, [], true);
        } else {
            $this->transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_MEMORY,
                null,
                null,
                null,
                false,
                "LLM Memory task skipped: no explicit rule configured; " . json_encode($config)
            );
            return true;
        }

        if (defined('DEBUG') && DEBUG) {
            error_log('LLM Memory task: dispatched rules: ' . implode(', ', $dispatched) . ' for user ' . $user_id);
        }

        return true;
    }

    /**
     * Add llm_memory_update option to jobConfig JSON schema.
     * Hook on JobConfigView::get_json_schema (priority 12).
     */
    public function get_memory_json_schema($args)
    {
        $res = (string)$this->execute_private_method($args);
        $res = json_decode($res, true);

        $config_service = new LlmMemoryConfigService($this->services);
        $rules = $config_service->getRules();

        $rule_titles = array();
        $rule_ids = array();
        foreach ($rules as $rule) {
            if (($rule['source_type'] ?? '') !== LLM_MEMORY_SOURCE_FORM_ACTION) {
                continue;
            }
            $label = trim((string)($rule['label'] ?? '')) ?: ('Rule #' . (int)($rule['id'] ?? 0));
            $rule_titles[] = $label . (!empty($rule['enabled']) ? '' : ' (disabled)');
            $rule_ids[] = (string)(int)($rule['id'] ?? 0);
        }
        $default_rule_id = !empty($rule_ids) ? $rule_ids[0] : '';

        $dep = array(
            "job_type" => array(ACTION_JOB_TYPE_LLM_MEMORY_UPDATE)
        );

        $memory_rule_field = array(
            "type" => "string",
            "enum" => $rule_ids,
            "options" => array(
                "grid_columns" => 12,
                "dependencies" => $dep,
                "enum_titles" => $rule_titles
            ),
            "default" => $default_rule_id,
            "title" => "Memory Rule",
            "description" => "Choose which memory rule this action should run."
        );

        $run_async_field = array(
            "type" => "boolean",
            "format" => "checkbox",
            "default" => true,
            "options" => array("grid_columns" => 12, "dependencies" => $dep),
            "title" => "Run async",
            "description" => "When checked, LLM summarization runs in a background worker."
        );

        $res['definitions']['job_ref']['properties']['job_type']['enum'][] = ACTION_JOB_TYPE_LLM_MEMORY_UPDATE;
        $res['definitions']['job_ref']['properties']['job_type']['options']['enum_titles'][] = "LLM Memory Update";
        $res['definitions']['job_ref']['properties']['memory_rule_id'] = $memory_rule_field;
        $res['definitions']['job_ref']['properties']['run_async'] = $run_async_field;

        return json_encode($res);
    }

    /**
     * Build task config for LLM memory update jobs.
     * Hook on UserInput::get_task_config (priority 12).
     */
    public function get_memory_task_config($args)
    {
        $job = $args['job'];
        if (($job['job_type'] ?? '') !== ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
            return $this->execute_private_method($args);
        }

        $description = !empty($job['job_name'])
            ? $job['job_name']
            : 'LLM Memory Update (form: ' . ($args['form_data']['form_name'] ?? 'unknown') . ')';

        return array(
            "type" => $job[ACTION_JOB_TYPE],
            "description" => $description,
            "memory_rule_id" => (string)($job['memory_rule_id'] ?? ''),
            "memory_key_override" => $job['memory_key_override'] ?? '',
            "force_storage_mode" => $job['force_storage_mode'] ?? '',
            "run_async" => !array_key_exists('run_async', $job) || !empty($job['run_async']),
            "trigger_type" => $job['trigger_type'] ?? 'finished',
            "form_data" => $args['form_data'],
            "id_users" => $_SESSION['id_user'] ?? null
        );
    }

    /**
     * Return jobTypes_task for llm_memory_update job type.
     * Hook on UserInput::get_job_type (priority 12).
     */
    public function get_memory_job_type($args)
    {
        $res = $this->execute_private_method($args);
        if (($args['job']['job_type'] ?? '') === ACTION_JOB_TYPE_LLM_MEMORY_UPDATE) {
            return jobTypes_task;
        }
        return $res;
    }

    /* =========================================================================
     * LOGIN / PROFILE MEMORY TRIGGER HOOKS
     * ========================================================================= */

    /**
     * Trigger memory update after successful login.
     * Hook on Login::update_timestamp (hook_overwrite_return, priority 20).
     * Wraps the original method: runs it first, then dispatches memory update
     * only if the user session exists (indicating successful login).
     */
    public function onLoginMemoryTrigger($args)
    {
        $user_id = $_SESSION['id_user'] ?? null;
        $res = $this->execute_private_method($args);
        if ($res === false) {
            return $res;
        }

        try {
            if (!$user_id) {
                return $res;
            }

            $config_service = new LlmMemoryConfigService($this->services);
            if (!$config_service->isMemoryEnabled()) {
                return $res;
            }

            $trigger_service = new LlmMemoryTriggerService($this->services, $config_service);
            $login_at = date('Y-m-d H:i:s');
            $profile = $this->buildLoginMemoryProfile($user_id);
            $normalized = $trigger_service->normalizeLoginPayload($user_id, $profile, $login_at);
            $trigger_service->dispatchMemoryUpdate($normalized);
        } catch (Exception $e) {
            LlmErrorReporter::log('Memory login trigger', $e);
        }

        return $res;
    }

    /**
     * Build a stable profile snapshot for login-triggered memory updates.
     * Excludes the system-provided last_login field because the rule prompt
     * should infer prior login timing from memory/history instead.
     *
     * @param int $user_id
     * @return array
     */
    private function buildLoginMemoryProfile($user_id)
    {
        $profile = array();

        try {
            $user = $this->db->query_db_first(
                "SELECT * FROM view_users WHERE id = :id LIMIT 1",
                array(':id' => $user_id)
            );

            if (!$user) {
                $user = $this->db->query_db_first(
                    "SELECT id, name, email, blocked FROM users WHERE id = :id LIMIT 1",
                    array(':id' => $user_id)
                );
            }

            if (!is_array($user)) {
                return $profile;
            }

            $profile = array(
                'user_name' => (string)($user['name'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'user_code' => (string)($user['code'] ?? ''),
                'status' => (string)($user['status'] ?? ''),
                'status_description' => (string)($user['description'] ?? ''),
                'groups' => (string)($user['groups'] ?? ''),
                'user_type' => (string)($user['user_type'] ?? ''),
                'user_type_code' => (string)($user['user_type_code'] ?? ''),
                'blocked' => isset($user['blocked']) ? (string)$user['blocked'] : '',
                'user_activity_count' => isset($user['user_activity']) ? (string)$user['user_activity'] : '',
                'distinct_url_count' => isset($user['ac']) ? (string)$user['ac'] : '',
            );

            $profile_snapshot = $user;
            unset($profile_snapshot['last_login']);
            $profile['profile_json'] = json_encode($profile_snapshot, JSON_UNESCAPED_SLASHES);
        } catch (Exception $e) {
            LlmErrorReporter::log('Memory login profile', $e);
        }

        return $profile;
    }

    /**
     * Trigger memory update after profile name change.
     * Hook on ProfileModel::change_user_name (hook_overwrite_return, priority 20).
     * Captures the old name before running the original method and dispatches
     * only after a confirmed successful rename.
     */
    public function onProfileNameChangeMemoryTrigger($args)
    {
        $old_name = '';
        try {
            $old_name = $this->db->fetch_user_name() ?: '';
        } catch (Exception $e) {
            // best-effort only
        }

        $res = $this->execute_private_method($args);
        if ($res !== true) {
            return $res;
        }

        try {
            $config_service = new LlmMemoryConfigService($this->services);
            if (!$config_service->isMemoryEnabled()) {
                return $res;
            }

            $user_id = (int)($_SESSION['id_user'] ?? 0);
            if ($user_id <= 0) {
                return $res;
            }

            $new_name = (string)($args['original_parameters'][0] ?? '');

            $trigger_service = new LlmMemoryTriggerService($this->services, $config_service);
            $normalized = $trigger_service->normalizeProfileNamePayload($user_id, $old_name, $new_name);
            $trigger_service->dispatchMemoryUpdate($normalized);
        } catch (Exception $e) {
            LlmErrorReporter::log('Memory profile name change trigger', $e);
        }

        return $res;
    }

    /* =========================================================================
     * FORM / SURVEYJS MEMORY RULE HOOKS
     * ========================================================================= */

    /**
     * Run the memory rules selected on a form after a successful finished save.
     * Hook on FormUserInputModel::save_user_input and ::update_user_input
     * (hook_overwrite_return, priority 20). The original save runs first and is
     * never changed; the rules are queued afterwards, only for a saved `finished`
     * submission, and a failure here is logged without touching the save result.
     * Rules attached through form actions keep running next to these.
     */
    public function onFormSavedMemoryRules($args)
    {
        $res = $this->execute_private_method($args);
        $user_input = (array)($args['user_input'] ?? array());
        if (!$res || ($user_input['trigger_type'] ?? '') !== actionTriggerTypes_finished) {
            return $res;
        }

        try {
            $model = $args['hookedClassInstance'];
            $rule_ids = $this->getSubmissionMemoryRuleIds($model);
            if (!empty($rule_ids)) {
                $context = (new LlmMemorySubmissionContextService())->buildFormContext($model, $user_input, $res);
                $this->queueSubmissionMemoryRules($rule_ids, $context, $res);
            }
        } catch (\Throwable $e) {
            LlmErrorReporter::log('Memory form rule trigger', $e);
        }

        return $res;
    }

    /**
     * Run the memory rules selected on a form after one of its entries was
     * deleted (status changed to deleted). Only the rules whose trigger types
     * include "deleted" run ("Also run when an entry is deleted" in the rule
     * editor). The memory prompt gets the entry's last values and is told the
     * participant deleted it; whether the memory forgets it or keeps it as
     * history is up to the rule's own instructions.
     * Hook on FormUserInputModel::delete_user_input (hook_overwrite_return,
     * priority 20). The entry is read before the delete runs; the delete
     * itself is never changed and a failure here is only logged.
     */
    public function onFormDeletedMemoryRules($args)
    {
        $snapshot = null;
        $rule_ids = array();
        $record_id = (int)($args['record_id'] ?? 0);
        $model = $args['hookedClassInstance'] ?? null;
        try {
            if ($record_id > 0 && is_object($model)) {
                $rule_service = new LlmMemoryRuleService($this->services);
                foreach ($this->getSubmissionMemoryRuleIds($model) as $rule_id) {
                    $rule = $rule_service->getRuleById($rule_id);
                    if ($rule && in_array(actionTriggerTypes_deleted, (array)($rule['trigger_types'] ?? array()), true)) {
                        $rule_ids[] = (int)$rule_id;
                    }
                }
                if (!empty($rule_ids)) {
                    $snapshot = $model->get_form_entry_record($record_id, $model->get_db_field('own_entries_only', 1));
                }
            }
        } catch (\Throwable $e) {
            LlmErrorReporter::log('Memory form delete trigger', $e);
        }

        $res = $this->execute_private_method($args);
        if (!$res || empty($rule_ids) || !is_array($snapshot) || empty($snapshot)) {
            return $res;
        }

        try {
            // The memory of the entry's owner learns about the delete, not the
            // deleter's (with own_entries_only off they can differ).
            $owner_id = (int)($snapshot['id_users'] ?? 0);
            if ($owner_id <= 0 || $owner_id === GUEST_USER_ID) {
                return $res;
            }
            // the stored row carries bookkeeping columns next to the answers
            foreach (array(ENTRY_RECORD_ID, 'entry_date', 'id_users', 'user_name', 'user_code',
                'id_actionTriggerTypes', 'triggerType', '_history_log') as $system_key) {
                unset($snapshot[$system_key]);
            }
            $context = (new LlmMemorySubmissionContextService())->buildFormContext($model, $snapshot, $record_id);
            $this->queueSubmissionMemoryRules($rule_ids, $context, $record_id, actionTriggerTypes_deleted, $owner_id);
        } catch (\Throwable $e) {
            LlmErrorReporter::log('Memory form delete trigger', $e);
        }

        return $res;
    }

    /**
     * Run the memory rules selected on a SurveyJS section after a successful finished save.
     * Hook on SurveyJSModel::save_survey (hook_overwrite_return, priority 20); same
     * behavior as onFormSavedMemoryRules. The hooked model is the section the
     * participant submitted, so its own rule selection is used, even when several
     * sections show the same survey.
     */
    public function onSurveySavedMemoryRules($args)
    {
        $res = $this->execute_private_method($args);
        $data = (array)($args['data'] ?? array());
        if (!$res || ($data['trigger_type'] ?? '') !== actionTriggerTypes_finished) {
            return $res;
        }

        try {
            $model = $args['hookedClassInstance'];
            $rule_ids = $this->getSubmissionMemoryRuleIds($model);
            if (!empty($rule_ids)) {
                $context = (new LlmMemorySubmissionContextService())->buildSurveyContext($model, $data, $res);
                if ($context) {
                    $this->queueSubmissionMemoryRules($rule_ids, $context, $res);
                }
            }
        } catch (\Throwable $e) {
            LlmErrorReporter::log('Memory survey rule trigger', $e);
        }

        return $res;
    }

    /**
     * Clean LLM-owned user data when core user input cleanup runs.
     * Hook on UserModel::clean_user_data (hook_overwrite_return, priority 20).
     *
     * Core cleanup already removes user activity, scheduled jobs, and all dataRows.
     * This hook removes the remaining LLM-specific user artifacts that live
     * outside the generic dataRows tables.
     */
    public function onCleanUserDataLlmCleanup($args)
    {
        $uid = (int)($args['uid'] ?? 0);
        $res = $this->execute_private_method($args);
        if ($res === false || $uid <= 0) {
            return $res;
        }

        try {
            LlmTransaction::begin($this->db);

            $cleanup_ok = true;
            $cleanup_ok &= $this->db->remove_by_fk('refresh_events', 'id_users', $uid);
            $cleanup_ok &= $this->db->remove_by_fk('llmConversations', 'id_users', $uid);

            if (!$cleanup_ok) {
                LlmTransaction::rollback($this->db);
                error_log('LLM cleanup after clean_user_data failed for user ' . $uid);
                return false;
            }

            LlmTransaction::commit($this->db);
            return true;
        } catch (\Throwable $e) {
            LlmTransaction::rollback($this->db);
            LlmErrorReporter::log('Cleanup after clean_user_data for user ' . $uid, $e);
            return false;
        }
    }


}
?>
