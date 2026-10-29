<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test: every Prompt Lab execution profile and dataset case type the
 * code can use is installed by a plugin migration.
 *
 * A dataset stores its execution profile as a row of the `lookups` table (type
 * `llm_eval_execution_profiles`), each case its type (`llm_eval_case_types`). A
 * code the plugin resolves but no migration inserts makes "Create dataset" fail
 * with "Invalid execution profile" - `interview_runtime` did exactly that.
 *
 * Static: reads server/db/v*.sql and the PHP / TypeScript sources, no database.
 *
 * Run: php tests/LlmExecutionProfileLookupsTest.php
 */

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

$root = dirname(__DIR__);

/**
 * @param string $type Lookup type_code
 * @return array code => migration file that inserts it
 */
function installedLookups($root, $type)
{
    $codes = array();
    foreach (glob($root . '/server/db/v*.sql') as $file) {
        $pattern = "/\\(\\s*'" . preg_quote($type, '/') . "'\\s*,\\s*'([^']+)'\\s*,/";
        if (preg_match_all($pattern, file_get_contents($file), $m)) {
            foreach ($m[1] as $code) {
                $codes[$code] = basename($file);
            }
        }
    }
    return $codes;
}

/**
 * @param string $dir Directory to scan recursively
 * @param string $extensions_regex e.g. 'php' or 'tsx?'
 * @return string[] File paths
 */
function sourceFiles($dir, $extensions_regex)
{
    $files = array();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && preg_match('/\.(' . $extensions_regex . ')$/', $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

/**
 * @param string[] $files Files to read
 * @param string $literal_regex Regex for the code inside quotes (one capture group)
 * @return array code => first file that uses it
 */
function usedLiterals(array $files, $literal_regex)
{
    $used = array();
    foreach ($files as $file) {
        if (preg_match_all("/['\"](" . $literal_regex . ")['\"]/", file_get_contents($file), $m)) {
            foreach ($m[1] as $code) {
                if (!isset($used[$code])) {
                    $used[$code] = basename($file);
                }
            }
        }
    }
    return $used;
}

/* =========================================================================
 * Execution profiles
 * ========================================================================= */

$profiles = installedLookups($root, 'llm_eval_execution_profiles');
check('the migrations install execution profiles at all', count($profiles) >= 5);

$used = usedLiterals(
    array_merge(sourceFiles($root . '/server', 'php'), sourceFiles($root . '/react/src', 'tsx?')),
    '[a-z]+_runtime|text_only'
);
check('the sources name execution profiles', count($used) >= 5);
foreach ($used as $code => $file) {
    check("execution profile '$code' (used in $file) is installed by a migration", isset($profiles[$code]));
}
check("the profile of the interview prompt is among the profiles the code uses", isset($used['interview_runtime']));

// A database may already have run an earlier v1.6.0: replaying the file must not fail on its lookup rows
$v160 = file_get_contents($root . '/server/db/v1.6.0.sql');
check(
    'v1.6.0 inserts lookups only with INSERT IGNORE (a replay must not fail)',
    preg_match('/INSERT\s+IGNORE\s+INTO\s+lookups\b/i', $v160) === 1 && preg_match('/INSERT\s+INTO\s+lookups\b/i', $v160) === 0
);

/* =========================================================================
 * Dataset case types
 * ========================================================================= */

$case_types = installedLookups($root, 'llm_eval_case_types');
check('the migrations install case types at all', count($case_types) >= 4);

$used_cases = usedLiterals(
    array(
        $root . '/server/service/LlmDatasetService.php',
        $root . '/server/service/LlmDatasetIngestionService.php',
    ),
    '[a-z]+(?:_[a-z]+)*_case'
);
check('the dataset services name case types', count($used_cases) >= 4);
foreach ($used_cases as $code => $file) {
    check("case type '$code' (used in $file) is installed by a migration", isset($case_types[$code]));
}

echo "\n$total checks, $failed failures\n";
exit($failed === 0 ? 0 : 1);
