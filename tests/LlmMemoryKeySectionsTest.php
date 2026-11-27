<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the sections of a memory key (plugin v1.10.0, structured
 * memory): they live on the key in the key registry (llm_memory_keys.sections_json)
 * and are optional - a key without sections is one flat list of items.
 *
 *  - LlmSettingsValidator::parseKeySections(): what the Keys tab may store and
 *    what a stored value is read as;
 *  - LlmMemoryConfigService::getKeySections(): the sections of the key from the
 *    registry, none for a key without them, an unknown key or a stored value that
 *    cannot be read (never fatal);
 *  - LlmMemoryRuleService::updateMemoryKey(): label, description and sections of a
 *    key are saved validated; invalid sections and unknown keys are refused.
 *
 * The real classes run. Only the database is replaced: the key registry rows of
 * tests/support/LlmMemoryTestStore.php, plus a record of the UPDATE statement.
 *
 * Run: php tests/LlmMemoryKeySectionsTest.php
 */

require_once __DIR__ . '/support/LlmMemoryTestStore.php';

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

/** The test database, plus the UPDATE of llm_memory_keys applied to its rows. */
class KeySectionsDb extends MemoryTestDb
{
    public $executed = array();

    public function query_db_first($sql, $arguments = array(), $fetch_style = PDO::FETCH_ASSOC)
    {
        if (strpos($sql, 'SELECT id FROM llm_memory_keys WHERE key_code') !== false) {
            foreach ($this->memory_keys as $index => $row) {
                if ($row['key_code'] === $arguments[':code']) {
                    return array('id' => $index + 1);
                }
            }
            return false;
        }
        return parent::query_db_first($sql, $arguments, $fetch_style);
    }

    public function execute_update_db($sql, $arguments = array())
    {
        $this->executed[] = array($sql, $arguments);
        foreach ($this->memory_keys as $index => $row) {
            if ($row['key_code'] === $arguments[':code']) {
                $this->memory_keys[$index] = array_merge($row, array(
                    'label' => $arguments[':label'],
                    'description' => $arguments[':description'],
                    'sections_json' => $arguments[':sections'],
                ));
            }
        }
        return 1;
    }
}

function keys_world($keys)
{
    $store = new MemoryTestStore();
    $db = new KeySectionsDb($store);
    $services = new MemoryTestServices($db, new MemoryTestUserInput($store));
    $config = new MemoryTestConfig($services, $keys);
    return (object)array('db' => $db, 'config' => $config, 'rules' => new MemoryTestRuleService($services));
}

$pcg = MemoryTestConfig::PCG_SECTIONS;

/* ------------------------------------------------------------------ parsing */

foreach (array('empty string' => '', 'blanks' => " \n\t ", 'JSON null' => 'null', 'null' => null, 'an empty list' => '[]', 'an empty array' => array()) as $label => $raw) {
    check("parse: $label means no sections, no error", LlmSettingsValidator::parseKeySections($raw) === array('sections' => array(), 'error' => ''));
}

$parsed = LlmSettingsValidator::parseKeySections(json_encode($pcg));
check('parse: sections keep code, label and hint in order', $parsed === array('sections' => $pcg, 'error' => ''));
check('parse: a decoded list is read the same way', LlmSettingsValidator::parseKeySections($pcg) === $parsed);

$minimal = LlmSettingsValidator::parseKeySections('[{"code": "notes"}, {"code": "plan_2", "label": "  My   plan\n", "hint": "  Steps  "}]');
check('parse: label defaults to the code, hint to empty', $minimal['sections'][0] === array('code' => 'notes', 'label' => 'notes', 'hint' => ''));
check('parse: label is one trimmed line, hint is trimmed', $minimal['sections'][1] === array('code' => 'plan_2', 'label' => 'My plan', 'hint' => 'Steps'));
check('parse: unicode labels are kept', LlmSettingsValidator::parseKeySections('[{"code": "a", "label": "Gefühle & Ziele"}]')['sections'][0]['label'] === 'Gefühle & Ziele');
check('parse: a code of 40 characters, digits, "_" and "-" is valid', LlmSettingsValidator::parseKeySections('[{"code": "' . str_repeat('a', 37) . '_-1"}]')['error'] === '');

$invalid = array(
    'invalid JSON' => '{oops',
    'a JSON object' => '{"code": "x"}',
    'a JSON string' => '"global"',
    'a number' => 5,
    'a section without code' => '[{"label": "x"}]',
    'a code with a blank' => '[{"code": "a b"}]',
    'a code of 41 characters' => '[{"code": "' . str_repeat('a', 41) . '"}]',
    'a code used twice' => '[{"code": "a"}, {"code": "a"}]',
    'a label that is no text' => '[{"code": "a", "label": 5}]',
    'a hint that is no text' => '[{"code": "a", "hint": ["x"]}]',
    'too many sections' => json_encode(array_map(function ($i) { return array('code' => 's' . $i); }, range(1, LlmSettingsValidator::KEY_SECTIONS_MAX_COUNT + 1))),
    'too long' => '[{"code": "a", "hint": "' . str_repeat('x', LlmSettingsValidator::KEY_SECTIONS_MAX_LENGTH) . '"}]',
);
foreach ($invalid as $label => $raw) {
    $result = LlmSettingsValidator::parseKeySections($raw);
    check("parse: $label is an error and gives no sections", $result['sections'] === array() && $result['error'] !== '');
}
check('parse: the error of a twice used code names it', strpos(LlmSettingsValidator::parseKeySections('[{"code": "a"}, {"code": "a"}]')['error'], '"a"') !== false);

/* ------------------------------------------------------- sections of a key */

$w = keys_world(array('global' => $pcg, 'coach' => array(array('code' => 'notes', 'label' => 'Notes', 'hint' => '')), 'flat' => array(), 'broken' => '{oops'));
check('registry: the sections of the key', $w->config->getKeySections('global') === $pcg);
check('registry: another key has its own', array_column($w->config->getKeySections('coach'), 'code') === array('notes'));
check('registry: a key without sections has none (one flat list)', $w->config->getKeySections('flat') === array());
check('registry: a key the registry does not know has none', $w->config->getKeySections('unknown') === array() && $w->config->getKeySections('') === array());
check('registry: stored sections that cannot be read count as none', $w->config->getKeySections('broken') === array());
check('registry: the key is matched trimmed and in lower case (as the rules store it)', $w->config->getKeySections(' Global ') === $pcg);
check('registry: the keys carry their sections', array_keys($w->config->getMemoryKeys()) === array('global', 'coach', 'flat', 'broken')
    && $w->config->getMemoryKeys()['coach']['sections'][0]['code'] === 'notes');
check('registry: nothing unexpected reached the database', $w->db->unexpected === array());

/* -------------------------------------------------------------- key update */

$w = keys_world(array('global' => $pcg, 'coach' => array()));
$key = $w->rules->updateMemoryKey('coach', '  My   coach ', ' Notes of the coach ', '[{"code": "plan", "label": "Plan", "hint": "Next steps"}]');
check('update: the key comes back with its sections', $key['code'] === 'coach' && $key['label'] === 'My coach' && $key['description'] === 'Notes of the coach'
    && $key['sections'] === array(array('code' => 'plan', 'label' => 'Plan', 'hint' => 'Next steps')));
check('update: the sections are stored as the parsed JSON', $w->db->memory_keys[1]['sections_json'] === '[{"code":"plan","label":"Plan","hint":"Next steps"}]');
check('update: the statement binds every value', strpos($w->db->executed[0][0], ':sections') !== false && $w->db->executed[0][1][':code'] === 'coach');

$key = $w->rules->updateMemoryKey('Coach', '', '', '');
check('update: no sections are stored as NULL, a blank label is built from the code', $w->db->memory_keys[1]['sections_json'] === null && $key['sections'] === array() && $key['label'] === 'Coach');

$executed = count($w->db->executed);
foreach (array('invalid sections' => array('coach', '[{"code": "a b"}]'), 'an unknown key' => array('nope', ''), 'a blank key' => array('  ', '')) as $label => $args) {
    $thrown = false;
    try {
        $w->rules->updateMemoryKey($args[0], 'x', '', $args[1]);
    } catch (Exception $e) {
        $thrown = true;
    }
    check("update: $label is refused and nothing is written", $thrown && count($w->db->executed) === $executed);
}

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
