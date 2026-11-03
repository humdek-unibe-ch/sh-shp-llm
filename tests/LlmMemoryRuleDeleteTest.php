<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for deleting a memory rule (plugin v1.7.0, request traces).
 *
 * Request traces link the prompt versions that were sent, and prompt versions
 * are never deleted (the locale / version FKs are ON DELETE RESTRICT). So
 * deleting a rule archives its prompt entry instead of deleting it; the rule,
 * its key bindings and nothing else are removed.
 *
 * No bootstrap, no database: the real LlmMemoryRuleService runs in a throw-away
 * tree with a stand-in BaseLlmService and prompt registry; the database records
 * the statements it receives.
 *
 * Run: php tests/LlmMemoryRuleDeleteTest.php
 */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_memory_rule_delete_test_' . getmypid();
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

class RuleFakeDb
{
    /** @var array[] [kind, sql, params] */
    public $statements = array();

    public function get_lookup_id_by_code($type, $code)
    {
        return $type === 'llm_prompt_owner_types' && $code === 'llm_memory_rule' ? 42 : null;
    }

    public function query_db_first($sql, $params = array())
    {
        $this->statements[] = array('first', $sql, $params);
        if (strpos($sql, 'FROM llm_memory_rules') !== false) {
            return array('id' => 5, 'label' => 'Mood', 'enabled' => 1, 'llm_temperature' => null, 'llm_max_tokens' => null);
        }
        if (strpos($sql, 'FROM llm_prompt_entries') !== false) {
            return array('id' => 77);
        }
        return false;
    }

    public function query_db($sql, $params = array())
    {
        $this->statements[] = array('query', $sql, $params);
        return array();
    }

    public function execute_update_db($sql, $params = array())
    {
        $this->statements[] = array('update', $sql, $params);
        return 1;
    }

    public function remove_by_fk($table, $fk, $id)
    {
        $this->statements[] = array('remove', "$table.$fk", array($id));
        return true;
    }

    /** @return string[] statements touching llm_prompt_entries (except reads) */
    public function entryWrites()
    {
        $writes = array();
        foreach ($this->statements as $statement) {
            if (stripos($statement[1], 'llm_prompt_entries') !== false && !preg_match('/^\s*SELECT/i', $statement[1])) {
                $writes[] = preg_replace('/\s+/', ' ', trim($statement[1]));
            }
        }
        return $writes;
    }
}

class RuleFakeServices
{
    public $db;

    public function __construct()
    {
        $this->db = new RuleFakeDb();
    }

    public function get_db()
    {
        return $this->db;
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

$services = new RuleFakeServices();
$result = (new LlmMemoryRuleService($services))->deleteRule(5);
$writes = $services->db->entryWrites();

check('the rule is deleted', $result === true && in_array(array('remove', 'llm_memory_rules.id', array(5)), $services->db->statements, true));
check('memory_rule_delete_archives: the prompt entry is never deleted', count(array_filter($writes, function ($sql) {
    return stripos($sql, 'DELETE') !== false;
})) === 0);
check('memory_rule_delete_archives: the prompt entry is archived', count($writes) === 1
    && stripos($writes[0], 'UPDATE llm_prompt_entries SET archived_at') === 0);

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
