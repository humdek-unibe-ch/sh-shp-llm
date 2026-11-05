<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for memory source types (plugin v1.8.0).
 *
 * Source types are the llmMemorySourceType lookups (lookup_code = source type,
 * lookup_value = label). A plugin registers its own source with one lookup row.
 * Every style selects its rules with the one field memory_rule_ids.
 *
 * No bootstrap, no database: the real LlmMemoryRuleService runs in a throw-away
 * tree with a stand-in BaseLlmService; the database returns fixed lookups. A
 * static part checks that v1.8.0.sql labels every source type this plugin installs.
 *
 * Run: php tests/LlmMemorySourceTypesTest.php
 */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_memory_source_types_test_' . getmypid();
$service_dir = $root . '/server/plugins/sh-shp-llm/server/service';
$real_server = str_replace('\\', '/', dirname(__DIR__)) . '/server';

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

foreach (array('service/LlmMemoryRuleService.php', 'service/globals.php', 'service/LlmModelCapabilities.php') as $file) {
    sandbox_write($root . '/server/plugins/sh-shp-llm/server/' . $file, file_get_contents($real_server . '/' . $file));
}
sandbox_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
<?php
class BaseLlmService
{
    protected $services;
    protected $db;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }
}
PHP
);
sandbox_write($service_dir . '/LlmPromptRegistryService.php', "<?php\nclass LlmPromptRegistryService { public function __construct(\$services) {} }\n");

class SourceTypesFakeDb
{
    /** @var int */
    public static $queries = 0;

    public function query_db($sql, $params = array())
    {
        self::$queries++;
        if (strpos($sql, 'FROM lookups') === false || ($params[':type'] ?? '') !== 'llmMemorySourceType'
            || stripos($sql, 'ORDER BY id') === false) {
            return array();
        }
        return array(
            array('lookup_code' => 'form_action_submit', 'lookup_value' => 'Form / survey submit'),
            array('lookup_code' => 'llm_chat_form_submit', 'lookup_value' => 'llmChat form submit'),
            array('lookup_code' => 'login', 'lookup_value' => 'Login'),
            array('lookup_code' => 'profile_name_change', 'lookup_value' => 'Profile name change'),
            array('lookup_code' => 'admin_manual', 'lookup_value' => 'Admin action'),
            array('lookup_code' => 'agentic_chat_end', 'lookup_value' => 'Agentic chat end'),
        );
    }
}

class SourceTypesFakeServices
{
    public function get_db()
    {
        return new SourceTypesFakeDb();
    }
}

require_once $service_dir . '/globals.php';
require_once $service_dir . '/LlmMemoryRuleService.php';

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    fwrite(STDOUT, ($condition ? "ok:   " : "FAIL: ") . $label . "\n");
    if (!$condition) {
        $failed++;
    }
}

$service = new LlmMemoryRuleService(new SourceTypesFakeServices());

$labels = $service->getSourceTypeLabels();
check('labels come from lookup_value, keyed by lookup_code', ($labels['form_action_submit'] ?? '') === 'Form / survey submit'
    && ($labels['agentic_chat_end'] ?? '') === 'Agentic chat end');
check('ordered by insert (id): the sources of this plugin first', array_keys($labels) === array('form_action_submit', 'llm_chat_form_submit', 'login', 'profile_name_change', 'admin_manual', 'agentic_chat_end'));

check('the one rule field of every style is memory_rule_ids', LLM_MEMORY_FIELD_RULE_IDS === 'memory_rule_ids');
check('the lookups are read once per service (Activity calls this per row)', SourceTypesFakeDb::$queries === 1);

// static: every source type this plugin inserts gets a label in v1.8.0.sql
$installed = array();
foreach (glob($real_server . '/db/v*.sql') as $file) {
    if (preg_match_all("/\\(\\s*'llmMemorySourceType'\\s*,\\s*'([^']+)'/", file_get_contents($file), $m)) {
        $installed = array_merge($installed, $m[1]);
    }
}
$v180 = (string)@file_get_contents($real_server . '/db/v1.8.0.sql');
foreach (array_unique($installed) as $code) {
    check("v1.8.0.sql labels $code", preg_match("/`?lookup_code`?\\s*=\\s*'" . preg_quote($code, '/') . "'/", $v180) === 1);
}

// static: llm_memory_rule_ids moves into memory_rule_ids, then the field is deleted
$sql_flat = preg_replace('/\s+/', ' ', str_replace("''", "'", $v180));
foreach (array('sections_fields_translation', 'styles_fields', 'fields_versions') as $table) {
    check("v1.8.0.sql moves the $table rows to memory_rule_ids", strpos($sql_flat,
        "`$table` SET `id_fields` = get_field_id('memory_rule_ids') WHERE `id_fields` = get_field_id('llm_memory_rule_ids')") !== false);
}
$delete_at = strpos($sql_flat, "DELETE FROM `fields` WHERE `name` = 'llm_memory_rule_ids';");
check('v1.8.0.sql deletes llm_memory_rule_ids after the moves',
    $delete_at !== false && $delete_at > strpos($sql_flat, "UPDATE IGNORE `styles_fields`"));

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
