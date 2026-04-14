<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";
require_once __DIR__ . "/../moduleLlmShared/LlmAdminLayoutHelper.php";

class ModuleLlmMemoryView extends BaseView
{
    public function __construct($model)
    {
        parent::__construct($model, null);
    }

    public function output_content()
    {
        $menuItems = LlmAdminLayoutHelper::getMenuItems(
            $this->model->get_services(),
            LLM_MEMORY_PAGE_KEYWORD
        );

        $config = $this->getReactConfig();

        ob_start();
        require __DIR__ . "/tpl/module_llm_memory.php";
        $pageContent = ob_get_clean();

        include LlmAdminLayoutHelper::getLayoutTemplatePath();
    }

    public function output_content_mobile()
    {
        return [];
    }

    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $git_version = shell_exec("git describe --tags");
            $version = $git_version ? rtrim($git_version) : 'dev';
            $local = array(
                __DIR__ . "/../../../css/ext/llm-admin-layout.css?v=" . $version,
                __DIR__ . "/../../../css/ext/llm-memory.css?v=" . $version,
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
                __DIR__ . "/../../../js/ext/llm-memory.umd.js?v=" . $version,
            );
        }
        return parent::get_js_includes($local);
    }

    public function getReactConfig()
    {
        $prompt_endpoint = $this->model->get_link_url(LLM_PROMPT_LAB_PAGE_KEYWORD);
        if (!$prompt_endpoint || strpos($prompt_endpoint, '[AjaxLlmPromptLab:class]') !== false || strpos($prompt_endpoint, '[dispatch:method]') !== false) {
            $prompt_endpoint = '/request/AjaxLlmPromptLab/dispatch';
        }

        return json_encode([
            'csrfToken' => $this->resolveCsrfToken(),
            'promptLabEndpoint' => $prompt_endpoint,
            'pageId' => $this->model->get_services()->get_db()->fetch_page_id_by_keyword(LLM_MEMORY_PAGE_KEYWORD),
            'pageUrl' => $this->model->get_link_url(LLM_MEMORY_PAGE_KEYWORD),
        ]);
    }

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
