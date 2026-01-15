<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../../../../component/style/BaseStyleComponent.php";
require_once __DIR__ . "/../service/LlmService.php";

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

            // Transform models array to select format
            $items = array();
            foreach ($models as $model) {
                $items[] = array(
                    'value' => $model['id'],
                    'text' => $model['id']
                );
            }

            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 10,
                "live_search" => 1,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        } catch (Exception $e) {
            // Fallback in case of error
            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 10,
                "live_search" => 0,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => array(
                    array('value' => '', 'text' => 'Error loading models: ' . $e->getMessage())
                )
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
        // Define available positions for the floating button
        $positions = array(
            array('value' => 'bottom-right', 'text' => 'Bottom Right'),
            array('value' => 'bottom-left', 'text' => 'Bottom Left'),
            array('value' => 'top-right', 'text' => 'Top Right'),
            array('value' => 'top-left', 'text' => 'Top Left'),
            array('value' => 'bottom-center', 'text' => 'Bottom Center'),
            array('value' => 'top-center', 'text' => 'Top Center')
        );

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
            require_once __DIR__ . "/../service/LlmSpeechToTextService.php";
            $speechService = new LlmSpeechToTextService($this->services);
            $models = $speechService->getAvailableAudioModels();

            // Transform models array to select format
            $items = array(
                array('value' => '', 'text' => '-- Select Audio Model --')
            );
            foreach ($models as $model) {
                $items[] = array(
                    'value' => $model['id'],
                    'text' => $model['id']
                );
            }

            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 10,
                "live_search" => 1,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => $items
            ));
        } catch (Exception $e) {
            // Fallback in case of error
            return new BaseStyleComponent("select", array(
                "value" => $value,
                "name" => $name,
                "max" => 10,
                "live_search" => 0,
                "is_required" => 0,
                "disabled" => $disabled,
                "items" => array(
                    array('value' => '', 'text' => '-- Select Audio Model --'),
                    array('value' => 'faster-whisper-large-v3', 'text' => 'faster-whisper-large-v3')
                )
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

    /**
     * Build the LLM admin panel with quick links.
     */
    private function outputLlmPanel()
    {
        return new BaseStyleComponent("card", array(
            "type" => "secondary",
            "is_expanded" => true,
            "is_collapsible" => true,
            "title" => "LLM Panel",
            "children" => array(
                new BaseStyleComponent("button", array(
                    "label" => "LLM Conversations",
                    "url" => $this->get_link_url(LLM_ADMIN_PAGE_KEYWORD),
                    "type" => "secondary",
                    "css" => "btn-sm"
                ))
            )
        ));
    }

    /**
     * Add LLM panel into CMS field rendering.
     */
    public function outputFieldPanel($args)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);
        if ($field['name'] == 'llm_panel') {
            $panel = $this->outputLlmPanel();
            if ($panel && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $panel;
                $res->get_view()->set_children($children);
            }
        }
        return $res;
    }

    /**
     * Get the plugin version
     */
    public function get_plugin_db_version($plugin_name = 'llm')
    {
        return parent::get_plugin_db_version($plugin_name);
    }

}
?>