<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for LlmPromptLabAccessService (plugin v1.6.0).
 *
 * The service answers "where does this section, version, dataset, run or case
 * really live?" so the Prompt Lab can authorize requests without believing the
 * browser. Its statements run against in-memory SQLite tables shaped like the
 * MySQL ones (only the columns the service reads): the real SQL is executed,
 * not pattern-matched. The MySQL dialect itself is covered by the migration and
 * browser runs against a real database.
 *
 * Run: php tests/LlmPromptLabAccessServiceTest.php
 */

require_once __DIR__ . '/../server/service/LlmPromptLabAccessService.php';

// A warning is a failure here: a failed lookup (false) must not leak into a foreach or an array access
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

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

/**
 * Core query helpers in miniature: rows as associative arrays, "first row or false",
 * and false (not an exception) when a statement fails.
 */
class SqliteDb
{
    public $pdo;
    /** @var bool Behave like the core helper after a PDOException */
    public $fail = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (array(
            'CREATE TABLE pages_sections (id_pages INTEGER, id_sections INTEGER)',
            'CREATE TABLE sections_navigation (parent INTEGER, child INTEGER, id_pages INTEGER)',
            'CREATE TABLE sections_hierarchy (parent INTEGER, child INTEGER)',
            'CREATE TABLE lookups (id INTEGER PRIMARY KEY, type_code TEXT, lookup_code TEXT)',
            'CREATE TABLE llm_prompt_entries (id INTEGER PRIMARY KEY, id_llm_prompt_owner_types INTEGER, owner_id INTEGER, prompt_slot TEXT)',
            'CREATE TABLE llm_prompt_locales (id INTEGER PRIMARY KEY, id_llm_prompt_entries INTEGER)',
            'CREATE TABLE llm_prompt_versions (id INTEGER PRIMARY KEY, id_llm_prompt_locales INTEGER)',
            'CREATE TABLE llm_eval_datasets (id INTEGER PRIMARY KEY, owner_type_scope TEXT, owner_id_scope INTEGER)',
            'CREATE TABLE llm_eval_runs (id INTEGER PRIMARY KEY, id_llm_eval_datasets INTEGER)',
            'CREATE TABLE llm_eval_run_cases (id INTEGER PRIMARY KEY, id_llm_eval_runs INTEGER, id_llm_eval_cases INTEGER)',
            'CREATE TABLE llm_eval_dataset_case_links (id INTEGER PRIMARY KEY, id_llm_eval_datasets INTEGER, id_llm_eval_cases INTEGER)',
        ) as $ddl) {
            $this->pdo->exec($ddl);
        }
    }

    public function query_db($sql, $params = array())
    {
        if ($this->fail) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    public function query_db_first($sql, $params = array())
    {
        $rows = $this->query_db($sql, $params);
        return (is_array($rows) && !empty($rows)) ? $rows[0] : false;
    }
}

class FakeServices
{
    public $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function get_db()
    {
        return $this->db;
    }
}

$db = new SqliteDb();
$pdo = $db->pdo;
$service = new LlmPromptLabAccessService(new FakeServices($db));

function seed($pdo, $table, array $rows)
{
    foreach ($rows as $row) {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)");
        $stmt->execute(array_values($row));
    }
}

// --- Section tree ---------------------------------------------------------------------------------

// Page 10 shows root section 100; 101 > 102 > 103 sit below it
seed($pdo, 'pages_sections', array(array('id_pages' => 10, 'id_sections' => 100)));
seed($pdo, 'sections_hierarchy', array(
    array('parent' => 100, 'child' => 101),
    array('parent' => 101, 'child' => 102),
    array('parent' => 102, 'child' => 103),
));

// Section 200 is shown on two pages (inserted in descending order on purpose)
seed($pdo, 'pages_sections', array(
    array('id_pages' => 21, 'id_sections' => 200),
    array('id_pages' => 20, 'id_sections' => 200),
));

// Section 301 has two parents, each on its own page
seed($pdo, 'pages_sections', array(
    array('id_pages' => 31, 'id_sections' => 310),
    array('id_pages' => 30, 'id_sections' => 300),
));
seed($pdo, 'sections_hierarchy', array(
    array('parent' => 300, 'child' => 301),
    array('parent' => 310, 'child' => 301),
));

// Navigation: 401 is a child of a navigation section on page 40, 402 sits below 401;
// 501 is a navigation child whose row carries no page
seed($pdo, 'sections_navigation', array(
    array('parent' => 400, 'child' => 401, 'id_pages' => 40),
    array('parent' => 500, 'child' => 501, 'id_pages' => null),
));
seed($pdo, 'sections_hierarchy', array(array('parent' => 401, 'child' => 402)));

// Cycle: 700 and 701 contain each other, page 70 shows 700
seed($pdo, 'pages_sections', array(array('id_pages' => 70, 'id_sections' => 700)));
seed($pdo, 'sections_hierarchy', array(
    array('parent' => 700, 'child' => 701),
    array('parent' => 701, 'child' => 700),
));

// Chain: page 80 shows 1000, d_k = 1000 + k sits k levels below it
seed($pdo, 'pages_sections', array(array('id_pages' => 80, 'id_sections' => 1000)));
for ($k = 1; $k <= 70; $k++) {
    seed($pdo, 'sections_hierarchy', array(array('parent' => 1000 + $k - 1, 'child' => 1000 + $k)));
}

check('a section on a page is found on that page', $service->hostPagesForSection(100) === array(10));
check('a section three levels below a page section is found on that page', $service->hostPagesForSection(103) === array(10));
check('a section shown on two pages returns both, ascending and without duplicates',
    $service->hostPagesForSection(200) === array(20, 21));
check('a section with two parents returns the page of each parent',
    $service->hostPagesForSection(301) === array(30, 31));
check('a navigation child belongs to the page of its navigation row',
    $service->hostPagesForSection(401) === array(40));
check('a section below a navigation child belongs to that page too',
    $service->hostPagesForSection(402) === array(40));
check('a navigation row without a page grants nothing', $service->hostPagesForSection(501) === array());
check('a section no page shows is an orphan', $service->hostPagesForSection(600) === array());
check('a cycle terminates and still finds the page (from the page section)', $service->hostPagesForSection(700) === array(70));
check('a cycle terminates and still finds the page (from the member below it)', $service->hostPagesForSection(701) === array(70));
check('a page-bearing ancestor 63 levels up is still found', $service->hostPagesForSection(1063) === array(80));
check('an ancestor 64 levels up is beyond the depth limit and not followed',
    $service->hostPagesForSection(1064) === array());
check('the depth limit is 64 levels', LlmPromptLabAccessService::MAX_HIERARCHY_DEPTH === 64);
check('section id 0 has no pages', $service->hostPagesForSection(0) === array());
check('a negative section id has no pages', $service->hostPagesForSection(-3) === array());
check('a non-numeric section id has no pages', $service->hostPagesForSection('abc') === array());
check('a numeric string id is accepted', $service->hostPagesForSection('103') === array(10));

$db->fail = true;
check('a failed lookup reads as "no pages" (deny), not as a row', $service->hostPagesForSection(103) === array());
$db->fail = false;
check('the service works again after a failed lookup', $service->hostPagesForSection(103) === array(10));

// --- Prompt versions ------------------------------------------------------------------------------

seed($pdo, 'lookups', array(
    array('id' => 1, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'style_field'),
    array('id' => 2, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_script'),
    array('id' => 3, 'type_code' => 'llm_prompt_owner_types', 'lookup_code' => 'llm_memory_rule'),
));
// Entry, locale and version ids differ on purpose, so a wrong join cannot look right
seed($pdo, 'llm_prompt_entries', array(
    array('id' => 31, 'id_llm_prompt_owner_types' => 1, 'owner_id' => 12, 'prompt_slot' => 'system'),
    array('id' => 32, 'id_llm_prompt_owner_types' => 2, 'owner_id' => 5, 'prompt_slot' => 'script'),
    array('id' => 33, 'id_llm_prompt_owner_types' => 3, 'owner_id' => 9, 'prompt_slot' => 'memory'),
));
seed($pdo, 'llm_prompt_locales', array(
    array('id' => 41, 'id_llm_prompt_entries' => 31),
    array('id' => 42, 'id_llm_prompt_entries' => 32),
    array('id' => 43, 'id_llm_prompt_entries' => 33),
    array('id' => 44, 'id_llm_prompt_entries' => 31),
));
seed($pdo, 'llm_prompt_versions', array(
    array('id' => 1, 'id_llm_prompt_locales' => 41),
    array('id' => 2, 'id_llm_prompt_locales' => 42),
    array('id' => 3, 'id_llm_prompt_locales' => 43),
    array('id' => 4, 'id_llm_prompt_locales' => 44),
));

check('a version of a style field resolves to that section',
    $service->versionOwner(1) === array('owner_type' => 'style_field', 'owner_id' => 12));
check('another language of the same prompt resolves to the same owner',
    $service->versionOwner(4) === array('owner_type' => 'style_field', 'owner_id' => 12));
check('a script version resolves to its script',
    $service->versionOwner(2) === array('owner_type' => 'llm_script', 'owner_id' => 5));
check('a memory rule version resolves to its rule',
    $service->versionOwner(3) === array('owner_type' => 'llm_memory_rule', 'owner_id' => 9));
check('an unknown version has no owner', $service->versionOwner(999) === null);
check('version id 0 has no owner', $service->versionOwner(0) === null);
$db->fail = true;
check('a failed version lookup reads as "unknown version"', $service->versionOwner(1) === null);
$db->fail = false;

// --- Datasets, runs, run cases, cases -------------------------------------------------------------

seed($pdo, 'llm_eval_datasets', array(
    array('id' => 1, 'owner_type_scope' => 'style_field', 'owner_id_scope' => 12),
    array('id' => 2, 'owner_type_scope' => 'llm_script', 'owner_id_scope' => 5),
    array('id' => 3, 'owner_type_scope' => null, 'owner_id_scope' => null),
    array('id' => 4, 'owner_type_scope' => 'style_field', 'owner_id_scope' => null),
));
// runs 11 / 12 belong to datasets 1 / 2; their cases 21 / 22 to those runs
seed($pdo, 'llm_eval_runs', array(
    array('id' => 11, 'id_llm_eval_datasets' => 1),
    array('id' => 12, 'id_llm_eval_datasets' => 2),
));
seed($pdo, 'llm_eval_run_cases', array(
    array('id' => 21, 'id_llm_eval_runs' => 11, 'id_llm_eval_cases' => 7),
    array('id' => 22, 'id_llm_eval_runs' => 12, 'id_llm_eval_cases' => 8),
));
seed($pdo, 'llm_eval_dataset_case_links', array(
    array('id' => 1, 'id_llm_eval_datasets' => 1, 'id_llm_eval_cases' => 7),
    array('id' => 2, 'id_llm_eval_datasets' => 3, 'id_llm_eval_cases' => 7),
    array('id' => 3, 'id_llm_eval_datasets' => 2, 'id_llm_eval_cases' => 8),
));

check('a scoped dataset reports its owner',
    $service->datasetScope(1) === array('owner_type_scope' => 'style_field', 'owner_id_scope' => 12));
check('a script dataset reports its script',
    $service->datasetScope(2) === array('owner_type_scope' => 'llm_script', 'owner_id_scope' => 5));
check('a global dataset has an empty scope (shared)',
    $service->datasetScope(3) === array('owner_type_scope' => '', 'owner_id_scope' => 0));
check('a type-wide dataset has a type and no id',
    $service->datasetScope(4) === array('owner_type_scope' => 'style_field', 'owner_id_scope' => 0));
check('an unknown dataset is null (not global)', $service->datasetScope(99) === null);
$db->fail = true;
check('a failed dataset lookup reads as "unknown dataset"', $service->datasetScope(1) === null);
$db->fail = false;

check('a run resolves to its dataset', $service->runDatasetId(11) === 1 && $service->runDatasetId(12) === 2);
check('an unknown run resolves to 0', $service->runDatasetId(99) === 0);
check('a run case resolves to the dataset of its run',
    $service->runCaseDatasetId(21) === 1 && $service->runCaseDatasetId(22) === 2);
check('an unknown run case resolves to 0', $service->runCaseDatasetId(99) === 0);

$linked = $service->caseDatasetIds(7);
sort($linked);
check('a case lists every dataset it is linked to', $linked === array(1, 3));
check('a case linked once lists that dataset', $service->caseDatasetIds(8) === array(2));
check('an unlinked or unknown case lists nothing (orphan)', $service->caseDatasetIds(77) === array());
$db->fail = true;
check('a failed case lookup reads as "no datasets" (deny)', $service->caseDatasetIds(7) === array());
check('a failed run lookup reads as unknown run', $service->runDatasetId(11) === 0);
$db->fail = false;

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
