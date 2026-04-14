<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseView.php";
require_once __DIR__ . "/../moduleLlmShared/LlmAdminLayoutHelper.php";

/**
 * The view class for the LLM admin console component.
 * Renders the comprehensive admin interface for managing conversations.
 */
class ModuleLlmAdminConsoleView extends BaseView
{
    /* Constructors ***********************************************************/

    /**
     * The constructor.
     *
     * @param object $model
     *  The model instance of the component.
     */
    public function __construct($model)
    {
        parent::__construct($model, null);
    }

    public function output_content()
    {
        $menuItems = LlmAdminLayoutHelper::getMenuItems(
            $this->model->get_services(),
            LLM_ADMIN_PAGE_KEYWORD
        );

        $config = $this->getReactConfig();

        ob_start();
        include __DIR__ . '/tpl/module_llm_admin_console.php';
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
                    __DIR__ . "/../../../css/ext/llm-admin.css?v=" . $version,
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
                __DIR__ . "/../../../js/ext/llm-admin.umd.js?v=" . $version,
            );
        }
        return parent::get_js_includes($local);
    }

    /**
     * Build React config passed to the client.
     */
    public function getReactConfig()
    {
        return json_encode([
            'pageSize' => $this->model->getAdminPageSize(),
            'refreshInterval' => $this->model->getRefreshInterval(),
            'defaultView' => $this->model->getDefaultView(),
            'showFilters' => $this->model->getShowFilters(),
            'labels' => $this->model->getLabels(),
            'csrfToken' => $_SESSION['csrf_token'] ?? '',
        ]);
    }
}

