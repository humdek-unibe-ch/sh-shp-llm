<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";
require_once __DIR__ . "/../../../../../component/style/BaseStyleComponent.php";

/**
 * View for the LLM Scripts module.
 * Renders a React container, the core dataConfigBuilder modal, and passes
 * configuration as JSON. React communicates with the Controller via
 * window.location + ?action=
 */
class ModuleLlmScriptView extends BaseView
{
    public function __construct($model)
    {
        parent::__construct($model, null);
    }

    public function output_content()
    {
        $config = $this->getReactConfig();
        $dataConfigBuilder = new BaseStyleComponent("dataConfigBuilder", array(
            "value" => "",
            "name" => "data_config"
        ));
        require __DIR__ . "/tpl/module_llm_scripts.php";
    }

    public function output_content_mobile()
    {
        return;
    }

    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $git_version = shell_exec("git describe --tags");
            $version = $git_version ? rtrim($git_version) : 'dev';
            $local = array(
                __DIR__ . "/../../../css/ext/llm-scripts.css?v=" . $version,
            );
        }
        return parent::get_css_includes($local);
    }

    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $git_version = shell_exec("git describe --tags");
            $version = $git_version ? rtrim($git_version) : 'dev';
            $local = array(
                __DIR__ . "/../../../js/ext/llm-scripts.umd.js?v=" . $version,
            );
        }
        return parent::get_js_includes($local);
    }

    /**
     * Build React config passed to the client.
     * No URL needed - React uses window.location (same pattern as AdminConsole).
     */
    public function getReactConfig()
    {
        return json_encode([
            'csrfToken' => $_SESSION['csrf_token'] ?? '',
        ]);
    }
}
?>
