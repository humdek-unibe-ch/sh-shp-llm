<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";
require_once __DIR__ . "/../moduleLlmShared/LlmAdminLayoutHelper.php";
require_once __DIR__ . "/../../service/LlmCsrfService.php";

class ModuleLlmPromptsView extends BaseView
{
    /** @param ModuleLlmPromptsModel $model Prompts hub model. */
    public function __construct($model)
    {
        parent::__construct($model, null);
    }

    /** Render the prompts hub page with React mount point and layout. */
    public function output_content()
    {
        $menuItems = LlmAdminLayoutHelper::getMenuItems(
            $this->model->get_services(),
            LLM_PROMPTS_PAGE_KEYWORD
        );

        $config = $this->getReactConfig();

        ob_start();
        require __DIR__ . "/tpl/module_llm_prompts.php";
        $pageContent = ob_get_clean();

        include LlmAdminLayoutHelper::getLayoutTemplatePath();
    }

    /** @return array Empty array; the prompts hub is not available on mobile. */
    public function output_content_mobile()
    {
        return [];
    }

    /** @return array CSS file paths including admin layout and prompts hub styles. */
    public function get_css_includes($local = array())
    {
        if (empty($local)) {
            $git_version = shell_exec("git describe --tags");
            $version = $git_version ? rtrim($git_version) : 'dev';
            $local = array(
                __DIR__ . "/../../../css/ext/llm-admin-layout.css?v=" . $version,
                __DIR__ . "/../../../css/ext/llm-prompts.css?v=" . $version,
            );
        }
        return parent::get_css_includes($local);
    }

    /** @return array JS file paths including the prompts hub UMD bundle. */
    public function get_js_includes($local = array())
    {
        if (empty($local)) {
            $git_version = shell_exec("git describe --tags");
            $version = $git_version ? rtrim($git_version) : 'dev';
            $local = array(
                __DIR__ . "/../../../js/ext/llm-prompts.umd.js?v=" . $version,
            );
        }
        return parent::get_js_includes($local);
    }

    /** @return string JSON-encoded config for the React prompts hub app. */
    public function getReactConfig()
    {
        return json_encode([
            'csrfToken' => LlmCsrfService::getToken(),
            'pageId' => $this->model->get_services()->get_db()->fetch_page_id_by_keyword(LLM_PROMPTS_PAGE_KEYWORD),
            'pageUrl' => $this->model->get_link_url(LLM_PROMPTS_PAGE_KEYWORD),
            'basePath' => defined('BASE_PATH') ? BASE_PATH : '',
        ]);
    }
}
?>
