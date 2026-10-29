<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Error Reporter
 *
 * One place that turns an unexpected failure into (a) a server log line that
 * is useful for debugging and safe to keep, and (b) the generic text a client
 * may be shown. Exception text - SQL, file paths, provider answers - stays in
 * the log and never reaches the browser.
 *
 * The log line carries the context, the exception class, where it was thrown
 * and, when given, the conversation id. The exception message is included
 * unless it comes from the database driver: those messages can quote row
 * values (a participant's answers), so outside debug mode only the SQLSTATE
 * code is logged. Control characters are removed and the message is cut, so a
 * crafted message cannot forge log lines.
 *
 * @package LLM Plugin
 */
class LlmErrorReporter
{
    /** What clients are shown instead of exception text. */
    const GENERIC_MESSAGE = 'Something went wrong. Please try again.';

    /** Longest exception message written to the log. */
    const MAX_LOGGED_MESSAGE = 300;

    /**
     * Log an unexpected failure.
     *
     * @param string $context What was being done, e.g. "Interview turn"
     * @param Throwable $e The failure
     * @param int|string|null $conversation_id Conversation to correlate with
     */
    public static function log($context, $e, $conversation_id = null)
    {
        error_log(self::format($context, $e, $conversation_id));
    }

    /**
     * Log an unexpected failure and return the text a client may be shown.
     *
     * @param string $context What was being done
     * @param Throwable $e The failure
     * @param int|string|null $conversation_id Conversation to correlate with
     * @return string GENERIC_MESSAGE
     */
    public static function report($context, $e, $conversation_id = null)
    {
        self::log($context, $e, $conversation_id);
        return self::GENERIC_MESSAGE;
    }

    /**
     * The log line for a failure.
     *
     * @param string $context What was being done
     * @param Throwable $e The failure
     * @param int|string|null $conversation_id Conversation to correlate with
     * @return string
     */
    public static function format($context, $e, $conversation_id = null)
    {
        return sprintf(
            '[LLM] %s failed%s: %s at %s:%d',
            self::clean((string)$context),
            ($conversation_id !== null && $conversation_id !== '')
                ? ' (conversation ' . self::clean((string)$conversation_id) . ')' : '',
            self::describe($e),
            basename($e->getFile()),
            (int)$e->getLine()
        );
    }

    /**
     * What of a failure may be written down: its class and its message, or
     * only the SQLSTATE code for a database error (outside debug mode). Use
     * it wherever a failure goes into a record of its own, such as an audit
     * trail row, so that record is as safe as the log line.
     *
     * @param Throwable $e The failure
     * @return string e.g. "RuntimeException: Provider timed out"
     */
    public static function describe($e)
    {
        $debug = defined('DEBUG') && DEBUG;
        if ($e instanceof PDOException && !$debug) {
            $detail = 'database error ' . self::clean((string)$e->getCode());
        } else {
            $detail = self::clean($e->getMessage());
        }
        return get_class($e) . ': ' . $detail;
    }

    /**
     * One printable line of limited length.
     *
     * @param string $text
     * @return string
     */
    private static function clean($text)
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
        if (strlen($text) > self::MAX_LOGGED_MESSAGE) {
            $text = substr($text, 0, self::MAX_LOGGED_MESSAGE) . '...';
        }
        return $text;
    }
}
