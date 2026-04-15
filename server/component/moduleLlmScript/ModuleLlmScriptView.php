<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";
require_once __DIR__ . "/../../../../../component/style/BaseStyleComponent.php";
require_once __DIR__ . "/../moduleLlmShared/LlmAdminLayoutHelper.php";

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

    /** Render the scripts admin page with admin layout and React mount point. */
    public function output_content()
    {
        $menuItems = LlmAdminLayoutHelper::getMenuItems(
            $this->model->get_services(),
            LLM_SCRIPTS_PAGE_KEYWORD
        );

        $config = $this->getReactConfig();
        $dataConfigBuilder = new BaseStyleComponent("dataConfigBuilder", array(
            "value" => "",
            "name" => "data_config"
        ));

        ob_start();
        require __DIR__ . "/tpl/module_llm_scripts.php";
        $pageContent = ob_get_clean();

        include LlmAdminLayoutHelper::getLayoutTemplatePath();
    }

    /** @return array Empty; scripts module not available on mobile. */
    public function output_content_mobile()
    {
        return [];
    }

    /** @return array CSS file paths for admin layout and scripts styles. */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $git_version = shell_exec("git describe --tags");
            $version = $git_version ? rtrim($git_version) : 'dev';
            $local = array(
                __DIR__ . "/../../../css/ext/llm-admin-layout.css?v=" . $version,
                __DIR__ . "/../../../css/ext/llm-scripts.css?v=" . $version,
            );
        }
        return parent::get_css_includes($local);
    }

    /** @return array JS file paths for the scripts UMD bundle. */
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
        $prompt_endpoint = $this->model->get_link_url(LLM_PROMPT_LAB_PAGE_KEYWORD);
        if (!$prompt_endpoint || strpos($prompt_endpoint, '[AjaxLlmPromptLab:class]') !== false || strpos($prompt_endpoint, '[dispatch:method]') !== false) {
            $prompt_endpoint = '/request/AjaxLlmPromptLab/dispatch';
        }

        return json_encode([
            'csrfToken' => $this->resolveCsrfToken(),
            'promptLabEndpoint' => $prompt_endpoint,
        ]);
    }

    /** @return string CSRF token from session. */
    private function resolveCsrfToken()
    {
        if (!empty($_SESSION['csrf_token'])) {
            return (string)$_SESSION['csrf_token'];
        }
        if (!empty($_SESSION['token'])) {
            return (string)$_SESSION['token'];
        }
        if (!empty($_SESSION['security_token'])) {
            return (string)$_SESSION['security_token'];
        }

        return '';
    }
}
?>
