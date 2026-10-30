<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Source-level guards for two conventions of the plugin (v1.6.0). A rule
 * that only lives in a comment is opt-in; these make it break the build.
 *
 *  1. Transactions go through LlmTransaction. A transaction that is opened
 *     with $db->begin_transaction() has no frame, so work queued with
 *     LlmTransaction::afterCommit() inside it cannot be tied to its commit
 *     (it is dropped and reported), and its rollback would be the typeless
 *     global cache wipe of BaseDb::rollback(). Plugin code therefore never
 *     calls begin_transaction(), beginTransaction(), commit(), rollback()
 *     or rollBack() on a handle, and never sends START TRANSACTION /
 *     COMMIT / ROLLBACK / SAVEPOINT as SQL. Only LlmTransaction itself may.
 *
 *  2. Exceptions are logged through LlmErrorReporter. Raw
 *     error_log('...' . $e->getMessage()) is a second convention: it
 *     writes database driver text (which can quote a participant's answers)
 *     and has no common shape to search for. Only the reporter turns an
 *     exception into a log line.
 *
 * The scan reads PHP tokens, so comments and strings that merely mention a
 * call do not count. The scanner is checked against crafted snippets first,
 * so a broken scanner cannot pass the plugin by finding nothing.
 *
 * Run: php tests/LlmConventionsTest.php
 */

$GLOBALS['__checks'] = 0;
$GLOBALS['__fails'] = array();

function check($label, $cond)
{
    $GLOBALS['__checks']++;
    if (!$cond) {
        $GLOBALS['__fails'][] = $label;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

/* =========================================================================
 * Scanner
 * ========================================================================= */

/** Methods that start, end or abandon a database transaction. */
function transaction_methods()
{
    return array('begin_transaction', 'begintransaction', 'commit', 'rollback');
}

/** Variables a caught exception is conventionally held in. */
function exception_variables()
{
    return array('$e', '$t', '$ex', '$exception', '$throwable', '$abort', '$logexception');
}

/**
 * The tokens that matter (no whitespace, comments, inline HTML) with the
 * line each one is on: array(array(id|null, text, line), ...).
 */
function significant_tokens($code)
{
    $out = array();
    $line = 1;
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            list($id, $text) = $token;
            $line = $token[2];
            if (!in_array($id, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG), true)) {
                $out[] = array($id, $text, $line);
            }
            $line += substr_count($text, "\n");
        } else {
            $out[] = array(null, $token, $line);
        }
    }
    return $out;
}

/**
 * Direct transaction calls in $code: ->begin_transaction(), ->commit(),
 * ::rollback() on any class but LlmTransaction, and SQL statements that
 * control a transaction.
 *
 * @return array[] array(line, what)
 */
function find_direct_transactions($code)
{
    $tokens = significant_tokens($code);
    $found = array();
    $methods = transaction_methods();
    $arrows = array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        list($id, $text, $line) = $tokens[$i];
        if (in_array($id, $arrows, true) || $id === T_DOUBLE_COLON) {
            $name = isset($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING ? strtolower($tokens[$i + 1][1]) : '';
            $open = isset($tokens[$i + 2]) ? $tokens[$i + 2][1] : '';
            if ($name !== '' && $open === '(' && in_array($name, $methods, true)) {
                $owner = $i > 0 ? $tokens[$i - 1][1] : '';
                if ($id === T_DOUBLE_COLON && $owner === 'LlmTransaction') {
                    continue;
                }
                $found[] = array($line, ($id === T_DOUBLE_COLON ? '::' : '->') . $tokens[$i + 1][1] . '()');
            }
        } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            $sql = trim($text, " \t\r\n'\";");
            if (preg_match('/^(START\s+TRANSACTION|BEGIN(\s+WORK)?|COMMIT|ROLLBACK(\s+TO\s+SAVEPOINT\s+\w+)?|SAVEPOINT\s+\w+|RELEASE\s+SAVEPOINT\s+\w+)\s*;?$/i', $sql)) {
                $found[] = array($line, 'SQL "' . $sql . '"');
            }
        }
    }
    return $found;
}

/**
 * error_log() statements that format a caught exception (its message,
 * trace, file or line, or the exception variable itself).
 *
 * @return array[] array(line, what)
 */
function find_raw_exception_logs($code)
{
    $tokens = significant_tokens($code);
    $found = array();
    $getters = array('getmessage', 'gettrace', 'gettraceasstring', 'getfile', 'getline', 'getprevious', 'getcode');
    $arrows = array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if ($tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'error_log'
            || !isset($tokens[$i + 1]) || $tokens[$i + 1][1] !== '(') {
            continue;
        }
        // Not a call to somebody else's error_log() method
        if ($i > 0 && (in_array($tokens[$i - 1][0], $arrows, true) || $tokens[$i - 1][0] === T_DOUBLE_COLON
            || $tokens[$i - 1][0] === T_FUNCTION)) {
            continue;
        }
        $depth = 0;
        for ($j = $i + 1; $j < $n; $j++) {
            $text = $tokens[$j][1];
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if (in_array($tokens[$j][0], $arrows, true) && isset($tokens[$j + 1])
                && $tokens[$j + 1][0] === T_STRING && in_array(strtolower($tokens[$j + 1][1]), $getters, true)) {
                $found[] = array($tokens[$i][2], 'error_log() with ->' . $tokens[$j + 1][1] . '()');
                break;
            }
            if ($tokens[$j][0] === T_VARIABLE && in_array(strtolower($text), exception_variables(), true)) {
                $found[] = array($tokens[$i][2], 'error_log() with ' . $text);
                break;
            }
        }
    }
    return $found;
}

/* =========================================================================
 * The scanner itself
 * ========================================================================= */

function snippet($body)
{
    return "<?php\nclass X {\n function f() {\n" . $body . "\n }\n}\n";
}

foreach (array(
    '$this->db->begin_transaction();' => '->begin_transaction()',
    '$db->commit();' => '->commit()',
    '$this->db->rollback();' => '->rollback()',
    '$pdo->beginTransaction();' => '->beginTransaction()',
    '$pdo->rollBack();' => '->rollBack()',
    '$db?->commit();' => '->commit()',
    'SomeDb::commit();' => '::commit()',
    '$this->db->query_db(\'START TRANSACTION\');' => 'SQL "START TRANSACTION"',
    '$this->db->query_db("COMMIT;");' => 'SQL "COMMIT"',
    '$pdo->exec(\'SAVEPOINT level1\');' => 'SQL "SAVEPOINT level1"',
    '$pdo->exec(\'ROLLBACK TO SAVEPOINT level1\');' => 'SQL "ROLLBACK TO SAVEPOINT level1"',
) as $body => $what) {
    $hits = find_direct_transactions(snippet($body));
    check("scanner: flags $what in `$body`", count($hits) === 1 && $hits[0][1] === $what);
}
foreach (array(
    'LlmTransaction::begin($this->db);',
    'LlmTransaction::commit($this->db);',
    'LlmTransaction::rollback($this->db, array(1));',
    '// $this->db->commit();',
    '/* $this->db->begin_transaction(); */',
    '$text = \'then $db->commit() runs\';',
    '$text = "Rollback failed";',
    '$this->db->query_db(\'SELECT 1\');',
    '$x = $this->commit_message;',
    '$this->db->get_commit_id();',
) as $body) {
    check("scanner: ignores `$body`", find_direct_transactions(snippet($body)) === array());
}
check('scanner: reports the line of a hit',
    find_direct_transactions("<?php\n\n\n\$db->commit();\n") === array(array(4, '->commit()')));

foreach (array(
    // the exception variable, by its usual names ...
    "error_log('x: ' . \$e->getMessage());" => 'error_log() with $e',
    "error_log('x: ' . \$t);" => 'error_log() with $t',
    "error_log(get_class(\$exception) . ' happened');" => 'error_log() with $exception',
    "error_log('x: ' . \$ex->getTraceAsString());" => 'error_log() with $ex',
    // ... and, whatever the variable is called, its getters (also across lines)
    "error_log('x: ' . \$caught->getMessage());" => 'error_log() with ->getMessage()',
    "error_log(sprintf('%s', \$this->last->getTraceAsString()));" => 'error_log() with ->getTraceAsString()',
    "error_log('[LLM] a: '\n . implode('; ', array(\$x))\n . \$problem->getFile());" => 'error_log() with ->getFile()',
) as $body => $what) {
    $hits = find_raw_exception_logs(snippet($body));
    check("scanner: flags a raw exception log `" . str_replace("\n", ' ', $body) . "`",
        count($hits) === 1 && $hits[0][1] === $what);
}
foreach (array(
    "error_log('plain diagnostic message');",
    "error_log('rows: ' . count(\$rows) . ' for user ' . \$user_id);",
    "LlmErrorReporter::log('Context', \$e);",
    "\$this->error_log(\$e->getMessage());",
    "// error_log('x' . \$e->getMessage());",
    "\$text = 'error_log(' . \$e->getMessage();",
) as $body) {
    check("scanner: ignores `$body`", find_raw_exception_logs(snippet($body)) === array());
}
// "{$e->getMessage()}" inside a double-quoted string is parsed into tokens by PHP, so it is caught:
$hits = find_raw_exception_logs(snippet('error_log("x: {$e->getMessage()}");'));
check('scanner: an exception interpolated into a double-quoted string is flagged too', count($hits) === 1);

/* =========================================================================
 * The plugin
 * ========================================================================= */

$server = dirname(__DIR__) . '/server';
$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($server, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $files[] = str_replace('\\', '/', $file->getPathname());
    }
}
sort($files);
check('plugin: the server code was found (' . count($files) . ' PHP files)', count($files) > 50);

$transaction_class = str_replace('\\', '/', $server) . '/service/LlmTransaction.php';
$error_reporter = str_replace('\\', '/', $server) . '/service/LlmErrorReporter.php';
$direct = array();
$raw_logs = array();
foreach ($files as $path) {
    $code = (string)file_get_contents($path);
    $relative = substr($path, strlen(str_replace('\\', '/', dirname(__DIR__))) + 1);
    if ($path !== $transaction_class) {
        foreach (find_direct_transactions($code) as $hit) {
            $direct[] = $relative . ':' . $hit[0] . ' ' . $hit[1];
        }
    }
    if ($path !== $error_reporter) {
        foreach (find_raw_exception_logs($code) as $hit) {
            $raw_logs[] = $relative . ':' . $hit[0] . ' ' . $hit[1];
        }
    }
}

check('transactions: LlmTransaction is the only code that opens, commits or rolls back a transaction'
    . ($direct ? ' - found: ' . implode('; ', $direct) : ''), $direct === array());
check('transactions: LlmTransaction itself does call the handle (the scan sees it)',
    count(find_direct_transactions((string)file_get_contents($transaction_class))) >= 3);

check('logging: no error_log() formats a caught exception, LlmErrorReporter is the one convention'
    . ($raw_logs ? ' - found: ' . implode('; ', $raw_logs) : ''), $raw_logs === array());
check('logging: LlmErrorReporter itself does call error_log() (the scan sees it)',
    strpos((string)file_get_contents($error_reporter), 'error_log(') !== false);

/* =========================================================================
 * Summary
 * ========================================================================= */

echo "\n=================================================\n";
echo count($GLOBALS['__fails']) === 0
    ? 'All ' . $GLOBALS['__checks'] . " checks passed\n"
    : count($GLOBALS['__fails']) . " failure(s):\n - " . implode("\n - ", $GLOBALS['__fails']) . "\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
?>
