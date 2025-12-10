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
                "is_required" => 1,
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
                "is_required" => 1,
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
}
?>
