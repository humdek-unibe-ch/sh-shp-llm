<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/../../../../component/style/formField/FormFieldModel.php';

/**
 * Server-side form schema for llmFormLogList interviews (v1.6.0).
 *
 * build($form_model) walks the resolved child component tree (same
 * get_children() -> get_model() traversal as
 * LlmMemorySubmissionContextService) and returns an ordered map
 *   name => {name, section_id, style, input_type, label, placeholder,
 *            required, hidden, interview, llm_writable, manual_writable,
 *            native_only, options, constraints, initial_value}
 * from the currently eligible tree: children whose own or an ancestor's
 * condition evaluated false are absent (not merely flagged), and hidden
 * inputs / d-none / display:none styling on the field or an ancestor
 * container marks the field hidden (hidden fields still hold data but are
 * no interview questions). Author problems (duplicate names, reserved
 * names) are collected under the `_errors` key.
 *
 * Field definitions the model receives are therefore already scoped; the
 * writable flags are the authoritative write contract:
 *   - llm_writable: visible fields plus allowlisted hidden fields;
 *   - manual_writable: visible fields, only when
 *     llm_interview_allow_manual_edit is enabled;
 *   - native_only: unsupported/custom controls keep their native widgets
 *     and are never writable through the interview draft;
 *   - scope-bound (pinned) fields - a declared URL parameter (extra_params)
 *     with a value whose name is also a form field - are hidden context:
 *     never asked, immutable for both origins, seeded with the URL value
 *     for a new draft (a saved record keeps its own value).
 */
class LlmFormSchemaService
{
    const ORIGIN_LLM = 'llm';
    const ORIGIN_MANUAL = 'manual';

    /** Server-only origin for native fallback values; never client-selected. */
    const ORIGIN_NATIVE = 'native';

    /** Key under which author diagnostics are appended to the schema map. */
    const ERRORS_KEY = '_errors';

    /** Styles the interview can read/write natively. */
    private static $SUPPORTED_STYLES = array(
        'input', 'textarea', 'select', 'radio', 'checkbox', 'slider'
    );

    /** input type_input values supported as LLM-writable scalars. */
    private static $SUPPORTED_INPUT_TYPES = array(
        'text', 'email', 'number', 'url', 'tel', 'date', 'time',
        'datetime-local', 'month', 'week', 'hidden', 'checkbox'
    );

    /**
     * Schemas already built, per form model: {key, schema}. Weak keys, so
     * an entry goes away with its model.
     *
     * @var WeakMap|null
     */
    private static $built = null;

    /**
     * Names that can never be interview fields: record bookkeeping,
     * injected form metadata and every `_`-prefixed internal key.
     *
     * @return array
     */
    private static function reservedNames()
    {
        $names = array(
            'id', 'record_id', 'selected_record_id', 'delete_record_id',
            'id_users', '_history_log', '__form_name', 'ajax', 'is_log',
            'mobile', 'action', 'section_id', 'record', 'sid',
            'redirect_at_end', 'url_cancel', 'quick_action_id',
            'user_name', 'user_code',
            'id_actionTriggerTypes', 'triggerType', 'trigger_type'
        );
        if (defined('ENTRY_RECORD_ID')) $names[] = ENTRY_RECORD_ID;
        if (defined('SELECTED_RECORD_ID')) $names[] = SELECTED_RECORD_ID;
        if (defined('DELETE_RECORD_ID')) $names[] = DELETE_RECORD_ID;
        if (defined('QUICK_ACTION_ID')) $names[] = QUICK_ACTION_ID;
        if (defined('USER_INPUT_HISTORY_LOG_COL')) $names[] = USER_INPUT_HISTORY_LOG_COL;
        $reserved = array();
        foreach ($names as $n) {
            $reserved[$n] = true;
        }
        return $reserved;
    }

    /* =========================================================================
     * SCHEMA EXTRACTION
     * ========================================================================= */

    /**
     * Build the ordered name => definition map for the form model's
     * currently eligible child tree.
     *
     * The schema depends only on the loaded child components and the
     * three model settings read below, and every child reload creates new
     * component objects. An unchanged tree and settings therefore reuse
     * the previous result instead of walking the tree again.
     *
     * @param object $form_model LlmFormLogListModel (or compatible model
     *  exposing get_children() and the llm_interview_* config getters)
     * @return array Schema map, optionally with a self::ERRORS_KEY list
     */
    public function build($form_model)
    {
        $allowlist = $this->callList($form_model, 'getInterviewWritableHiddenFields');
        $manual = $this->callBool($form_model, 'isInterviewAllowManualEdit');
        $scope_bound = $this->callList($form_model, 'getScopeBoundValues');
        $key = null;
        if (is_object($form_model)) {
            // === on arrays compares child objects by identity.
            $key = array(
                method_exists($form_model, 'get_children') ? $form_model->get_children() : null,
                $allowlist, $manual, $scope_bound
            );
            if (self::$built === null) {
                self::$built = new WeakMap();
            }
            if (isset(self::$built[$form_model]) && self::$built[$form_model]['key'] === $key) {
                return self::$built[$form_model]['schema'];
            }
        }
        $schema = array();
        $errors = array();
        $this->collectFields($form_model, $schema, $errors, false, $allowlist, $manual, $scope_bound);
        if ($errors) {
            $schema[self::ERRORS_KEY] = $errors;
        }
        if ($key !== null) {
            self::$built[$form_model] = array('key' => $key, 'schema' => $schema);
        }
        return $schema;
    }

    private function collectFields($model, array &$schema, array &$errors,
        $ancestor_hidden, array $allowlist, $manual_enabled, array $scope_bound)
    {
        if (!is_object($model) || !method_exists($model, 'get_children')) {
            return;
        }
        foreach ((array)$model->get_children() as $child) {
            if (!is_object($child) || !method_exists($child, 'get_model')) {
                continue;
            }
            // ACL-hidden branches expose nothing to the interview.
            if (method_exists($child, 'has_access') && !$child->has_access()) {
                continue;
            }
            $child_model = $child->get_model();
            if (!is_object($child_model)) {
                continue;
            }
            // Condition-false fields are absent from the schema entirely.
            $condition = method_exists($child_model, 'get_condition_result')
                ? $child_model->get_condition_result()
                : array('result' => true);
            if (is_array($condition) && isset($condition['result']) && !$condition['result']) {
                continue;
            }
            $hidden = $ancestor_hidden || $this->isHiddenModel($child_model);
            if (!($child_model instanceof FormFieldModel)) {
                // containers keep recursing; excluded branches never load
                // children so nothing inside them is solicited
                $this->collectFields($child_model, $schema, $errors,
                    $hidden, $allowlist, $manual_enabled, $scope_bound);
                continue;
            }
            $this->addFieldDef($child, $child_model, $schema, $errors,
                $hidden, $allowlist, $manual_enabled, $scope_bound);
        }
    }

    private function addFieldDef($child, $field_model, array &$schema,
        array &$errors, $hidden, array $allowlist, $manual_enabled, array $scope_bound)
    {
        $name = trim((string)$field_model->get_db_field('name'));
        if ($name === '') {
            return;
        }
        $style = (string)$field_model->get_style_name();
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            // Bracket/dot names would collide with PHP's name[] POST parsing.
            $errors[] = "Field name '$name' is not a safe identifier for the interview";
            return;
        }
        if (isset($schema[$name])) {
            $errors[] = "Duplicate field name '$name' in style '$style' - only the first definition is used";
            return;
        }
        if ($this->isReservedName($name)) {
            $errors[] = "Field name '$name' is reserved and cannot be an interview field";
            return;
        }
        $input_type = $this->resolveInputType($field_model, $style);
        $native_only = $input_type === null;
        if ($native_only) {
            $raw_type = $style === 'input'
                ? strtolower(trim((string)$field_model->get_db_field('type_input', 'text')))
                : '';
            $input_type = $raw_type !== '' ? $raw_type : $style;
        }
        $pinned_value = array_key_exists($name, $scope_bound) ? (string)$scope_bound[$name] : null;
        $scope_bound = $pinned_value !== null;
        // A pinned field is context fixed by the URL, never a question.
        $hidden = $hidden || $scope_bound;
        // The question prompt the author wrote for the control. A select has
        // no placeholder field; its `alt` (the empty option's text) plays that role.
        $prompt = (string)$field_model->get_db_field('placeholder', '');
        if ($prompt === '' && $style === 'select') {
            $prompt = (string)$field_model->get_db_field('alt', '');
        }
        $hidden_allowed = $hidden && in_array($name, $allowlist, true) && !$scope_bound;
        $section_id = method_exists($child, 'get_id_section')
            ? (int)$child->get_id_section()
            : (int)$field_model->get_db_field('id', 0);
        $schema[$name] = array(
            'name' => $name,
            'section_id' => $section_id,
            'style' => $style,
            'input_type' => $input_type,
            'label' => $this->fieldLabel($field_model),
            // Shown as the quiet text of a question that has no answer yet.
            'placeholder' => $this->plainText($prompt),
            'required' => (bool)(int)$field_model->get_db_field('is_required', 0),
            'hidden' => $hidden,
            'interview' => !$hidden && !$native_only,
            'llm_writable' => !$native_only && !$scope_bound && (!$hidden || $hidden_allowed),
            'manual_writable' => (bool)$manual_enabled && !$hidden && !$native_only && !$scope_bound,
            'native_only' => $native_only,
            'scope_bound' => $scope_bound,
            'options' => $this->fieldOptions($field_model, $input_type),
            'constraints' => $this->fieldConstraints($field_model, $input_type),
            'initial_value' => $this->fieldInitialValue($field_model, $input_type, $pinned_value)
        );
    }

    /**
     * @return string|null Interview input type, or null for native-only
     *  controls (unsupported style or unsupported input type_input).
     */
    private function resolveInputType($field_model, $style)
    {
        if (!in_array($style, self::$SUPPORTED_STYLES, true)) {
            return null;
        }
        if ($style === 'select') {
            return $field_model->get_db_field('is_multiple', 0) ? 'select-multiple' : 'select';
        }
        if ($style === 'input') {
            $type = strtolower(trim((string)$field_model->get_db_field('type_input', 'text')));
            return in_array($type, self::$SUPPORTED_INPUT_TYPES, true) ? $type : null;
        }
        return $style;
    }

    private function fieldLabel($field_model)
    {
        $label = $this->plainText($field_model->get_db_field('label', ''));
        if ($label === '') {
            $label = $this->plainText($field_model->get_db_field('placeholder', ''));
        }
        return $label;
    }

    /**
     * Native choice list for select/radio and slider legend labels as
     * [{value, label}]; null for scalar controls.
     */
    private function fieldOptions($field_model, $input_type)
    {
        if ($input_type === 'slider') {
            $min = (int)$field_model->get_db_field('min', 0);
            $labels = $field_model->get_db_field('labels', array());
            $options = array();
            foreach ((is_array($labels) ? $labels : array()) as $i => $label) {
                if (is_scalar($label)) {
                    $options[] = array('value' => (string)($min + (int)$i), 'label' => $this->plainText($label));
                }
            }
            return $options ? $options : null;
        }
        if ($input_type !== 'select' && $input_type !== 'select-multiple' && $input_type !== 'radio') {
            return null;
        }
        $items = $field_model->get_db_field('items', array());
        $options = array();
        foreach ((is_array($items) ? $items : array()) as $item) {
            if (is_array($item) && isset($item['value']) && is_scalar($item['value'])) {
                $options[] = array(
                    'value' => (string)$item['value'],
                    'label' => isset($item['text']) ? $this->plainText($item['text']) : (string)$item['value']
                );
            } elseif (is_scalar($item)) {
                $options[] = array('value' => (string)$item, 'label' => (string)$item);
            }
        }
        return $options ? $options : null;
    }

    private function fieldConstraints($field_model, $input_type)
    {
        $constraints = array();
        $min = $field_model->get_db_field('min', '');
        $max = $field_model->get_db_field('max', '');
        if ($min !== '' && $min !== null && is_numeric($min)) {
            $constraints['min'] = $min + 0;
        }
        if ($max !== '' && $max !== null && is_numeric($max)) {
            $constraints['max'] = $max + 0;
        }
        if ($input_type === 'checkbox') {
            $checked = $field_model->get_db_field('checkbox_value', '1');
            $constraints['checked_value'] = $checked === '' || $checked === null ? '1' : (string)$checked;
        }
        return $constraints;
    }

    /**
     * Current entry-record value, then the URL value of a pinned field,
     * then the native CMS `value` default: the saved row wins for edits,
     * the URL (pinned field) or the configured default seeds new drafts.
     * Multiple-select values arrive JSON-encoded (see SelectView) and are
     * decoded to native arrays. Raw `value` is read via get_db_field_full so
     * explicit 0/false survive get_db_field's loose-default handling.
     */
    private function fieldInitialValue($field_model, $input_type, $pinned_value = null)
    {
        $entry = method_exists($field_model, 'get_form_field_value')
            ? $field_model->get_form_field_value()
            : null;
        if ($entry !== null) {
            return $this->normalizeInitialValue($input_type, $entry);
        }
        if ($pinned_value !== null) {
            return $this->normalizeInitialValue($input_type, $pinned_value);
        }
        $full = method_exists($field_model, 'get_db_field_full')
            ? $field_model->get_db_field_full('value')
            : '';
        $default = is_array($full) && array_key_exists('content', $full)
            ? $full['content']
            : $field_model->get_db_field('value', '');
        if ($default === '' || $default === null) {
            return null;
        }
        if ($input_type === 'checkbox') {
            $checked = $this->fieldConstraints($field_model, $input_type);
            return ((string)$default === (string)$checked['checked_value']
                || $default === '1' || $default === 1 || $default === true)
                ? $checked['checked_value'] : 0;
        }
        return $this->normalizeInitialValue($input_type, $default);
    }

    /**
     * Decode JSON-encoded multiple-select values (native storage format,
     * html_entity_decode + json_decode like SelectView) into arrays; other
     * values pass through unchanged.
     */
    private function normalizeInitialValue($input_type, $value)
    {
        if ($input_type !== 'select-multiple' || is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }
        return $value;
    }

    /* =========================================================================
     * PATCH NORMALIZATION + VALIDATION
     * ========================================================================= */

    /**
     * Normalize a typed field patch against the schema.
     *
     * @param array $schema build() result
     * @param array $patch name => raw value map; a null value clears the field
     * @param string $origin self::ORIGIN_LLM, self::ORIGIN_MANUAL or
     *  self::ORIGIN_NATIVE (server-only fallback writes; may fill
     *  native_only fields and manual-writable supported fields but never
     *  hidden, scope-bound or upload controls)
     * @return array ['values' => name => normalized value, 'errors' => name => message]
     */
    public function normalizePatch(array $schema, array $patch, $origin)
    {
        $values = array();
        $errors = array();
        if ($origin !== self::ORIGIN_LLM
            && $origin !== self::ORIGIN_MANUAL
            && $origin !== self::ORIGIN_NATIVE
        ) {
            $errors[self::ERRORS_KEY] = "Unknown patch origin '$origin'";
            return array('values' => $values, 'errors' => $errors);
        }
        foreach ($patch as $name => $raw) {
            $def = isset($schema[$name]) && is_array($schema[$name]) ? $schema[$name] : null;
            if ($def === null || !isset($def['name'])) {
                $errors[$name] = 'Unknown or excluded field';
                continue;
            }
            if ($origin === self::ORIGIN_LLM && empty($def['llm_writable'])) {
                // The model often echoes the read-only facts it was shown
                // (scope-bound or hidden values). Repeating the value the
                // server already holds changes nothing and is ignored; only
                // an attempt to change it is an error.
                if (!$this->repeatsInitialValue($def, $raw)) {
                    $errors[$name] = 'Field is not writable by the interview';
                }
                continue;
            }
            if ($origin === self::ORIGIN_MANUAL && empty($def['manual_writable'])) {
                $errors[$name] = 'Field is not editable manually';
                continue;
            }
            if ($origin === self::ORIGIN_NATIVE) {
                if (!empty($def['scope_bound'])) {
                    $errors[$name] = 'Field is bound to the conversation scope';
                    continue;
                }
                if (!empty($def['hidden'])) {
                    $errors[$name] = 'Hidden fields cannot be written through native controls';
                    continue;
                }
                if ($this->isUploadField($def)) {
                    $errors[$name] = 'Upload fields go through the native upload control only';
                    continue;
                }
                if (!empty($def['native_only'])) {
                    if ($raw === null || is_scalar($raw)
                        || (is_array($raw) && array_is_list($raw)
                            && !array_filter($raw, function ($v) { return !is_scalar($v); }))
                    ) {
                        $values[$name] = $raw;
                    } else {
                        $errors[$name] = 'Native fallback expects a scalar or flat list';
                    }
                    continue;
                }
                if (empty($def['manual_writable'])) {
                    $errors[$name] = 'Field is not editable through the native fallback';
                    continue;
                }
            }
            if ($raw === null) {
                $values[$name] = null;
                continue;
            }
            $res = $this->normalizeValue($def, $raw);
            if ($res['error'] !== null) {
                $errors[$name] = $res['error'];
            } else {
                $values[$name] = $res['value'];
            }
        }
        return array('values' => $values, 'errors' => $errors);
    }

    /**
     * Whether a patch value for a non-writable field merely repeats the
     * value the server already holds (empty counts as "no value").
     */
    private function repeatsInitialValue(array $def, $raw)
    {
        if ($raw === null) {
            return false;
        }
        $initial = array_key_exists('initial_value', $def) ? $def['initial_value'] : null;
        if (is_array($initial) || is_array($raw)) {
            return json_encode($initial) === json_encode($raw);
        }
        if (!is_scalar($raw)) {
            return false;
        }
        return trim((string)$initial) === trim((string)$raw);
    }

    /**
     * Validate a full or partial draft value map against the schema.
     * Shapes/ranges/choices are checked for every provided value; with
     * $requireAll every visible required field must hold a valid value
     * (a required checkbox must be checked - native semantics). Hidden and
     * native-only fields are not required-checked: their values are
     * server-controlled or validated by the native save path.
     *
     * @param array $schema
     * @param array $values name => draft value map
     * @param bool $requireAll
     * @return array name => error message
     */
    public function validateValues(array $schema, array $values, $requireAll = false)
    {
        $errors = array();
        foreach ($values as $name => $value) {
            $def = isset($schema[$name]) && is_array($schema[$name]) ? $schema[$name] : null;
            if ($def === null || !isset($def['name'])) {
                $errors[$name] = 'Unknown or excluded field';
                continue;
            }
            if (!empty($def['native_only']) || $value === null) {
                continue;
            }
            $res = $this->normalizeValue($def, $value);
            if ($res['error'] !== null) {
                $errors[$name] = $res['error'];
            }
        }
        if ($requireAll) {
            foreach ($schema as $name => $def) {
                if ($name === self::ERRORS_KEY || !is_array($def)
                    || empty($def['required'])
                ) {
                    continue;
                }
                if ($this->valueMissing($def, $values) && !isset($errors[$name])) {
                    if (!empty($def['native_only'])) {
                        $errors[$name] = 'Required field must be provided through its native control';
                    } elseif (!empty($def['hidden'])) {
                        $errors[$name] = 'Required hidden field has no value - check the interview configuration';
                    } else {
                        $errors[$name] = 'Required field is missing';
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * Whether the draft holds a value at all: key present and not
     * null/''/[] - explicit 0/false count as provided.
     */
    private function hasValue(array $def, array $values)
    {
        $name = $def['name'];
        if (!array_key_exists($name, $values) || $values[$name] === null || $values[$name] === '') {
            return false;
        }
        if (is_array($values[$name])) {
            return count($values[$name]) > 0;
        }
        return true;
    }

    /**
     * Required completeness: no value, or a checkbox not equal to the
     * configured checked value (native required semantics).
     */
    private function valueMissing(array $def, array $values)
    {
        if (!$this->hasValue($def, $values)) {
            return true;
        }
        if ($def['input_type'] === 'checkbox') {
            $checked = isset($def['constraints']['checked_value']) ? $def['constraints']['checked_value'] : '1';
            return (string)$values[$def['name']] !== (string)$checked;
        }
        return false;
    }

    /* =========================================================================
     * DETERMINISTIC PROGRESS
     * ========================================================================= */

    /**
     * Progress over the visible interview fields, derived from value
     * validity - never from LLM topic tracking. The active field must be a
     * visible interview field; otherwise the first required incomplete
     * field becomes active, then the first incomplete optional field.
     *
     * @param array $schema
     * @param array $values name => current draft values
     * @param string|null $active Requested active field name
     * @return array {fields: name => {label, status, required}, total,
     *   done, required_total, required_done, active, ready_for_approval}
     */
    public function progress(array $schema, array $values, $active = null)
    {
        $fields = array();
        $value_errors = $this->validateValues($schema, $values, false);
        $first_pending_required = null;
        $first_pending = null;
        foreach ($schema as $name => $def) {
            if ($name === self::ERRORS_KEY || !is_array($def)
                || empty($def['interview'])
            ) {
                continue;
            }
            // required fields are done only when required-satisfied;
            // optional fields are done once they hold any value (0/false
            // included)
            $complete = !empty($def['required'])
                ? !$this->valueMissing($def, $values)
                : $this->hasValue($def, $values);
            $invalid = isset($value_errors[$name]);
            $status = ($complete && !$invalid) ? 'done' : 'pending';
            if ($status === 'pending') {
                if ($first_pending === null) {
                    $first_pending = $name;
                }
                if (!empty($def['required']) && $first_pending_required === null) {
                    $first_pending_required = $name;
                }
            }
            $fields[$name] = array(
                'label' => $def['label'],
                'status' => $status,
                'required' => !empty($def['required'])
            );
        }
        $resolved_active = (is_string($active) && isset($fields[$active])
            && $fields[$active]['status'] === 'pending')
            ? $active
            : ($first_pending_required !== null ? $first_pending_required : $first_pending);
        if ($resolved_active !== null) {
            $fields[$resolved_active]['status'] = 'active';
        }
        $required = array_filter($fields, function ($f) { return $f['required']; });
        $done = array_filter($fields, function ($f) { return $f['status'] === 'done'; });
        $req_errors = $this->validateValues($schema, $values, true);
        return array(
            'fields' => $fields,
            'total' => count($fields),
            'done' => count($done),
            'required_total' => count($required),
            'required_done' => count(array_filter($required, function ($f) { return $f['status'] === 'done'; })),
            'active' => $resolved_active,
            'ready_for_approval' => count($req_errors) === 0
        );
    }

    /* =========================================================================
     * INTERNALS
     * ========================================================================= */

    /**
     * @return array ['value' => normalized, 'error' => null|string]
     */
    private function normalizeValue(array $def, $raw)
    {
        $type = $def['input_type'];
        $constraints = isset($def['constraints']) && is_array($def['constraints']) ? $def['constraints'] : array();

        // A blank scalar means "empty" for optional fields; required checks
        // treat it as missing. Checkboxes keep their native 0/checked_value
        // normalization instead.
        if ($raw === '' && $type !== 'checkbox') {
            return array(
                'value' => $type === 'select-multiple' ? array() : '',
                'error' => null
            );
        }

        if ($type === 'checkbox') {
            $checked = isset($constraints['checked_value']) ? (string)$constraints['checked_value'] : '1';
            $truthy = array(true, 1, '1', 'on', 'true', 'yes', $checked);
            $falsy = array(false, 0, '0', 'off', 'false', 'no', '');
            if (in_array($raw, $truthy, true) || (is_string($raw) && $raw === $checked)) {
                return array('value' => $checked, 'error' => null);
            }
            if (in_array($raw, $falsy, true)) {
                return array('value' => 0, 'error' => null);
            }
            return array('value' => null, 'error' => 'Checkbox expects a boolean value');
        }

        if ($type === 'select' || $type === 'radio') {
            if (!is_scalar($raw) || is_bool($raw)) {
                return array('value' => null, 'error' => 'Expected a single option value');
            }
            $val = (string)$raw;
            foreach ((array)$def['options'] as $opt) {
                if ((string)$opt['value'] === $val) {
                    return array('value' => $opt['value'], 'error' => null);
                }
            }
            return array('value' => null, 'error' => 'Value is not an allowed option');
        }

        if ($type === 'select-multiple') {
            if (!is_array($raw) || ($raw !== array() && !array_is_list($raw))) {
                return array('value' => null, 'error' => 'Expected a flat list of option values');
            }
            $allowed = array();
            foreach ((array)$def['options'] as $opt) {
                $allowed[(string)$opt['value']] = $opt['value'];
            }
            $out = array();
            foreach ($raw as $v) {
                if (!is_scalar($v) || is_bool($v) || !isset($allowed[(string)$v])) {
                    return array('value' => null, 'error' => 'List contains a value that is not an allowed option');
                }
                if (!in_array($allowed[(string)$v], $out, true)) {
                    $out[] = $allowed[(string)$v];
                }
            }
            if (isset($constraints['max']) && count($out) > (int)$constraints['max']) {
                return array('value' => null, 'error' => 'More options selected than allowed');
            }
            return array('value' => $out, 'error' => null);
        }

        if ($type === 'slider' || $type === 'number' || $type === 'range') {
            if (!is_scalar($raw) || is_bool($raw) || !is_numeric($raw)) {
                return array('value' => null, 'error' => 'Expected a numeric value');
            }
            $num = $raw + 0;
            if ($type === 'slider' && (float)$num !== (float)(int)$num) {
                return array('value' => null, 'error' => 'Expected an integer value');
            }
            if (isset($constraints['min']) && $num < $constraints['min']) {
                return array('value' => null, 'error' => 'Value is below the minimum');
            }
            if (isset($constraints['max']) && $num > $constraints['max']) {
                return array('value' => null, 'error' => 'Value is above the maximum');
            }
            return array(
                'value' => $type === 'slider' ? (int)$num : $num,
                'error' => null
            );
        }

        if (!is_scalar($raw) || is_bool($raw)) {
            return array('value' => null, 'error' => 'Expected a scalar value');
        }
        $str = (string)$raw;

        switch ($type) {
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)
                    || !checkdate((int)substr($str, 5, 2), (int)substr($str, 8, 2), (int)substr($str, 0, 4))) {
                    return array('value' => null, 'error' => 'Expected a YYYY-MM-DD date');
                }
                break;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $str)) {
                    return array('value' => null, 'error' => 'Expected an HH:MM time');
                }
                break;
            case 'datetime-local':
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $str, $m)
                    || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    return array('value' => null, 'error' => 'Expected a YYYY-MM-DDTHH:MM datetime');
                }
                break;
            case 'month':
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $str)) {
                    return array('value' => null, 'error' => 'Expected a YYYY-MM month');
                }
                break;
            case 'week':
                if (!preg_match('/^\d{4}-W(0[1-9]|[1-4]\d|5[0-3])$/', $str)) {
                    return array('value' => null, 'error' => 'Expected a YYYY-Www week');
                }
                break;
            case 'email':
                if (!filter_var($str, FILTER_VALIDATE_EMAIL)) {
                    return array('value' => null, 'error' => 'Expected a valid email address');
                }
                break;
            case 'url':
                if (!filter_var($str, FILTER_VALIDATE_URL)) {
                    return array('value' => null, 'error' => 'Expected a valid URL');
                }
                break;
        }
        // text-ish types carry character-length constraints
        if (isset($constraints['min']) && mb_strlen($str, 'UTF-8') < $constraints['min']) {
            return array('value' => null, 'error' => 'Value is shorter than the minimum length');
        }
        if (isset($constraints['max']) && mb_strlen($str, 'UTF-8') > $constraints['max']) {
            return array('value' => null, 'error' => 'Value is longer than the maximum length');
        }
        return array('value' => $str, 'error' => null);
    }

    private function isReservedName($name)
    {
        $reserved = self::reservedNames();
        return isset($reserved[$name]) || (isset($name[0]) && $name[0] === '_');
    }

    /**
     * Upload-style controls: values are stored ids/paths produced by the
     * native widget workflow and must never be patched with raw
     * filenames/paths.
     */
    private function isUploadField(array $def)
    {
        return $def['input_type'] === 'file'
            || in_array($def['style'], array('file', 'upload', 'input-file'), true);
    }

    /**
     * Hidden means an explicit hidden input or `d-none` / `display:none`
     * on the element's CMS css field (whole-token check, so responsive
     * classes like d-md-none do not qualify).
     */
    private function isHiddenModel($model)
    {
        if (!method_exists($model, 'get_db_field')) {
            return false;
        }
        $style = method_exists($model, 'get_style_name') ? $model->get_style_name() : '';
        if ($style === 'input'
            && strtolower(trim((string)$model->get_db_field('type_input', 'text'))) === 'hidden'
        ) {
            return true;
        }
        $css = (string)$model->get_db_field('css', '');
        if ($css === '') {
            return false;
        }
        if (preg_match('/(^|\s)d-none(\s|$)/', $css)) {
            return true;
        }
        return (bool)preg_match('/display\s*:\s*none/i', $css);
    }

    private function callList($model, $method)
    {
        if (!is_object($model) || !method_exists($model, $method)) {
            return array();
        }
        $res = $model->$method();
        return is_array($res) ? $res : array();
    }

    private function callBool($model, $method)
    {
        if (!is_object($model) || !method_exists($model, $method)) {
            return false;
        }
        return (bool)$model->$method();
    }

    private function plainText($html)
    {
        return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8'));
    }
}
?>
