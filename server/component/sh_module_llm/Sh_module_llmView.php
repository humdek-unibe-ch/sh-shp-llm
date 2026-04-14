<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";
require_once __DIR__ . "/../moduleLlmShared/LlmAdminLayoutHelper.php";

/**
 * View for the LLM Settings module.
 * Renders the shared admin layout with the React settings app mounted inside.
 */
class Sh_module_llmView extends BaseView
{
    public function __construct($model)
    {
        parent::__construct($model, null);
    }

    public function output_content()
    {
        $menuItems = LlmAdminLayoutHelper::getMenuItems(
            $this->model->get_services(),
            PAGE_LLM_CONFIG
        );

        $config = $this->getReactConfig();

        ob_start();
        include __DIR__ . '/tpl/module_llm_settings.php';
        $pageContent = ob_get_clean();

        include LlmAdminLayoutHelper::getLayoutTemplatePath();
    }

    public function output_content_mobile()
    {
        return [];
    }

    public function get_css_includes($local = [])
    {
        if (empty($local)) {
            $version = $this->getVersion();
            $local = [
                __DIR__ . "/../../../css/ext/llm-admin-layout.css?v=" . $version,
                __DIR__ . "/../../../css/ext/llm-settings.css?v=" . $version,
            ];
        }
        return parent::get_css_includes($local);
    }

    public function get_js_includes($local = [])
    {
        if (empty($local)) {
            $version = $this->getVersion();
            $local = [
                __DIR__ . "/../../../js/ext/llm-settings.umd.js?v=" . $version,
            ];
        }
        return parent::get_js_includes($local);
    }

    public function getReactConfig()
    {
        return json_encode([
            'csrfToken' => $this->resolveCsrfToken(),
            'memoryPageUrl' => $this->model->get_link_url(LLM_MEMORY_PAGE_KEYWORD),
        ]);
    }

    private function resolveCsrfToken()
    {
        return $_SESSION['csrf_token']
            ?? $_SESSION['token']
            ?? $_SESSION['security_token']
            ?? '';
    }

    private function getVersion()
    {
        $git_version = shell_exec("git describe --tags");
        return $git_version ? rtrim($git_version) : 'dev';
    }
}
?>
