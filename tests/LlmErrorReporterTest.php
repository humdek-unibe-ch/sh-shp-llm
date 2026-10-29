<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmErrorReporter (plugin v1.6.0): the one place that
 * turns an unexpected failure into a log line and the generic text a client
 * may see.
 *
 *  - the client text is always the generic one, never the exception message;
 *  - the log line names the context, class, location and conversation;
 *  - database driver messages (they can quote row values) are not logged
 *    outside debug mode, only their SQLSTATE code;
 *  - control characters and length cannot be used to forge log lines.
 *
 * Run: php tests/LlmErrorReporterTest.php
 */

require_once dirname(__DIR__) . '/server/service/LlmErrorReporter.php';

$log_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_error_reporter_test_' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $log_file);
register_shutdown_function(function () use ($log_file) {
    if (is_file($log_file)) {
        unlink($log_file);
    }
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

function logged()
{
    global $log_file;
    return is_file($log_file) ? (string)file_get_contents($log_file) : '';
}

function resetLog()
{
    global $log_file;
    file_put_contents($log_file, '');
}

/** A PDOException the way PDO throws it: the code is the SQLSTATE string. */
function driverError($message, $sqlstate)
{
    $e = new PDOException($message);
    $property = new ReflectionProperty('Exception', 'code');
    $property->setAccessible(true);
    $property->setValue($e, $sqlstate);
    return $e;
}

/* =========================================================================
 * report(): generic text for the client, details in the log
 * ========================================================================= */

resetLog();
$result = LlmErrorReporter::report('Interview turn', new RuntimeException('cannot open /srv/app/secret.php'));
check('report() returns the generic message', $result === LlmErrorReporter::GENERIC_MESSAGE);
check('the generic message does not echo the failure', strpos($result, 'secret') === false);
$line = logged();
check('report() writes exactly one log line', substr_count(trim($line), "\n") === 0 && $line !== '');
check('the log line names the context and the class', strpos($line, '[LLM] Interview turn failed') !== false
    && strpos($line, 'RuntimeException') !== false);
check('the log line carries the exception message', strpos($line, 'cannot open /srv/app/secret.php') !== false);
check('the log line names where it happened (file and line, no path)', strpos($line, 'at LlmErrorReporterTest.php:') !== false
    && strpos($line, dirname(__FILE__)) === false);

resetLog();
LlmErrorReporter::log('Interview open', new LogicException('x'), 1234);
check('log() adds the conversation id', strpos(logged(), 'Interview open failed (conversation 1234): LogicException: x') !== false);
resetLog();
LlmErrorReporter::log('Interview open', new LogicException('x'));
check('log() without a conversation id says nothing about one', strpos(logged(), 'conversation') === false);
resetLog();
LlmErrorReporter::log('Interview open', new LogicException('x'), '');
check('log() with an empty conversation id says nothing about one', strpos(logged(), 'conversation') === false);
resetLog();
LlmErrorReporter::log('Interview open', new LogicException('x'), 0);
check('conversation id 0 is still shown (it is a value, not "absent")', strpos(logged(), '(conversation 0)') !== false);

resetLog();
LlmErrorReporter::log('Interview open', new TypeError('Argument #1 must be of type int'));
check('an Error (not only an Exception) can be logged', strpos(logged(), 'TypeError: Argument #1 must be of type int') !== false);

/* =========================================================================
 * Database driver messages can quote row values
 * ========================================================================= */

resetLog();
LlmErrorReporter::log('Interview approval',
    driverError("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'jane.doe@example.org' for key 'email'", '23000'));
$line = logged();
check('a driver message is not logged outside debug mode', strpos($line, 'jane.doe@example.org') === false
    && stripos($line, 'Duplicate entry') === false);
check('the SQLSTATE code is logged instead', strpos($line, 'PDOException: database error 23000') !== false);

/* =========================================================================
 * describe(): the same rules for a record of its own (an audit trail row)
 * ========================================================================= */

check('describe() gives class and message', LlmErrorReporter::describe(new RuntimeException('Provider timed out')) === 'RuntimeException: Provider timed out');
$described = LlmErrorReporter::describe(driverError("SQLSTATE[23000]: Duplicate entry 'jane.doe@example.org' for key 'email'", '23000'));
check('describe() keeps only the SQLSTATE code of a database error', $described === 'PDOException: database error 23000');
check('describe() cannot span lines', strpos(LlmErrorReporter::describe(new RuntimeException("a\r\nb\nc")), "\n") === false
    && LlmErrorReporter::describe(new RuntimeException("a\r\nb\nc")) === 'RuntimeException: a b c');
check('describe() cuts a long message', strlen(LlmErrorReporter::describe(new RuntimeException(str_repeat('B', 5000)))) < 350);
check('a log line is built from describe()', strpos(LlmErrorReporter::format('Ctx', new LogicException('why')), 'Ctx failed: LogicException: why at ') !== false);

/* =========================================================================
 * Log lines cannot be forged
 * ========================================================================= */

resetLog();
LlmErrorReporter::log('Interview turn', new RuntimeException("first\r\n[LLM] Settings save failed: forged\n\tindented"), "7\n[LLM] forged");
$line = logged();
// (PHP ends each log line with the platform newline, hence the trim)
check('a message with line breaks stays on one log line', substr_count(trim($line), "\n") === 0 && strpos(trim($line), "\r") === false
    && strpos($line, 'first [LLM] Settings save failed: forged indented') !== false);
check('a conversation id with line breaks stays on one log line', strpos($line, "(conversation 7 [LLM] forged)") !== false);

resetLog();
LlmErrorReporter::log('Interview turn', new RuntimeException("bell\x07 and nul\x00 and del\x7f"));
check('control characters are removed from the message', preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', trim(logged())) !== 1);

resetLog();
LlmErrorReporter::log('Interview turn', new RuntimeException(str_repeat('A', 5000)));
$line = logged();
check('a long message is cut', strlen($line) < 700 && strpos($line, str_repeat('A', LlmErrorReporter::MAX_LOGGED_MESSAGE) . '...') !== false
    && strpos($line, str_repeat('A', LlmErrorReporter::MAX_LOGGED_MESSAGE + 1)) === false);

resetLog();
LlmErrorReporter::log('Interview turn', new RuntimeException(''));
check('an empty message still logs the class and place', strpos(logged(), 'RuntimeException:  at LlmErrorReporterTest.php:') !== false
    || strpos(logged(), 'RuntimeException: ') !== false);

resetLog();
LlmErrorReporter::log("Interview\nturn", new RuntimeException('x'));
check('the context cannot break the line either', substr_count(trim(logged()), "\n") === 0);

/* =========================================================================
 * Debug mode keeps the driver message (development only)
 * ========================================================================= */

$script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_error_reporter_debug_' . getmypid() . '.php';
file_put_contents($script, '<?php
define("DEBUG", true);
require_once ' . var_export(dirname(__DIR__) . '/server/service/LlmErrorReporter.php', true) . ';
echo LlmErrorReporter::format("Interview approval", new PDOException("Duplicate entry for key email"), 5);
');
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
unlink($script);
check('in debug mode the driver message is logged', strpos($out, 'PDOException: Duplicate entry for key email') !== false
    && strpos($out, '(conversation 5)') !== false);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
