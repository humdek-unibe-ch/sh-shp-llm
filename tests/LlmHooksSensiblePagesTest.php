<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for LlmHooks::get_sensible_pages (hook on Router::get_sensible_pages).
 *
 * A "sensible page" is one where the core warns an editor when someone else opened the same page
 * in the last minutes (BaseView -> Router::get_other_users_editing_this_page). The pages that
 * edit prompts must be on the list: the scripts, the memory rules and the prompts hub (v1.10.0).
 * The hook adds them to whatever the hook chain below it returned.
 *
 * No bootstrap, no database: the real hook class is copied into a throw-away tree that mirrors its
 * relative require_once targets; the core base class and the services it loads are stand-ins.
 *
 * Run: php tests/LlmHooksSensiblePagesTest.php
 */

require_once __DIR__ . '/../server/service/globals.php';

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    if ($condition) {
        echo "ok:   $label\n";
    } else {
        echo "FAIL: $label\n";
        $failed++;
    }
}

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_hooks_sensible_pages_test_' . getmypid();
$plugin_server = $root . '/server/plugins/sh-shp-llm/server';

function sandbox_write($path, $contents)
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

function sandbox_remove($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? sandbox_remove($path) : unlink($path);
    }
    rmdir($dir);
}

register_shutdown_function(function () use ($root) {
    sandbox_remove($root);
});

// The real hook class, byte for byte
sandbox_write($plugin_server . '/component/LlmHooks.php', file_get_contents(dirname(__DIR__) . '/server/component/LlmHooks.php'));

// Stand-ins: the base class answers what the hook chain below the hook returned
sandbox_write($root . '/server/component/BaseHooks.php', <<<'PHP'
<?php
class BaseHooks
{
    protected $services;

    public function __construct($services, $params = array())
    {
        $this->services = $services;
    }

    protected function execute_private_method($args = array())
    {
        return $args['chain_result'];
    }
}
PHP
);
sandbox_write($root . '/server/component/style/BaseStyleComponent.php', "<?php\nclass BaseStyleComponent {}\n");
foreach (array(
    'LlmService', 'LlmScriptService', 'LlmPromptRegistryService', 'LlmPromptExecutionProfileService',
    'LlmMemoryConfigService', 'LlmMemoryTriggerService', 'LlmMemoryRuleService', 'LlmMemorySubmissionContextService',
    'LlmCsrfService', 'LlmTransaction', 'LlmFileUtility', 'LlmErrorReporter', 'LlmApiKeysConfigService',
) as $service) {
    sandbox_write($plugin_server . '/service/' . $service . '.php', "<?php\n");
}

require_once $plugin_server . '/component/LlmHooks.php';

/* =========================================================================
 * Checks
 * ========================================================================= */

$hooks = new LlmHooks(new stdClass());
$pages = $hooks->get_sensible_pages(array('chain_result' => array('some_core_page')));

check('what the hook chain below returned is kept', $pages[0] === 'some_core_page');
check('the scripts page is a sensible page', in_array(LLM_SCRIPTS_PAGE_KEYWORD, $pages, true));
check('the memory page is a sensible page', in_array(LLM_MEMORY_PAGE_KEYWORD, $pages, true));
check('the prompts hub is a sensible page', in_array(LLM_PROMPTS_PAGE_KEYWORD, $pages, true));
check('each page is listed once', count($pages) === 4 && count(array_unique($pages)) === 4);

echo "\n$total checks, $failed failed\n";
exit($failed > 0 ? 1 : 0);
