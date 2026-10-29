<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmErrorReporter.php';

/**
 * The plugin's one way to open, commit and roll back a database transaction.
 *
 * Plugin code never calls $db->begin_transaction(), commit() or rollback()
 * itself (tests/LlmConventionsTest.php fails when it does). Going
 * through this class is what gives the two guarantees below to every
 * transaction, instead of only to the ones that remembered to ask.
 *
 * 1. Rollback without a global cache wipe.
 *    BaseDb::rollback() ends with Cache::clear_cache() *without a type*,
 *    which is apcu_clear_cache(): every cached page, style, lookup and ACL
 *    entry of the whole application is dropped. A rejected interview
 *    request or a failed approval is an expected outcome, so a participant
 *    (or anyone replaying a stale request) must not be able to cold-start
 *    the CMS by triggering it. rollback() rolls the PDO handle back
 *    directly and clears only the cache types the caller names: the ones
 *    reads inside the transaction may have filled with uncommitted rows
 *    (for example the user-input cache after a core write).
 *    Core code that is called from inside a transaction (UserInput::
 *    save_data() rolls back through BaseDb::rollback() when it fails) is
 *    not changed by this class.
 *
 * 2. Work that must only happen after a successful commit.
 *    A detached memory worker has its own database connection: it can
 *    neither be rolled back nor see uncommitted rows, so starting it
 *    inside a transaction updates memory even when the transaction fails
 *    afterwards. begin() opens a frame for the transaction; code that
 *    would otherwise start such work calls afterCommit(); the outermost
 *    commit() releases the queue, every rollback() drops the queue of the
 *    transaction it ends. Frames nest like the core's savepoints: the work
 *    queued inside an inner transaction waits for the outermost commit.
 *    Nothing has to be opted into - a transaction that is opened here has
 *    its frame.
 *
 * A transaction that was opened outside this class (core code, another
 * plugin) cannot be observed: whether it committed or rolled back is not
 * known to the plugin. afterCommit() inside such a transaction therefore
 * drops the task and says so in the server log - it never starts the work
 * early.
 *
 * State is per request (static).
 */
class LlmTransaction
{
    /**
     * Open transactions of this request, innermost last. Each frame holds
     * the tasks that wait for its commit.
     *
     * @var array[]
     */
    private static $frames = array();

    /** @var bool the shutdown guard is registered once per request */
    private static $guard_registered = false;

    /**
     * Start a transaction (a savepoint when one is already open).
     *
     * @param object $db BaseDb / PageDb
     * @throws \Throwable when the database refuses to start it
     */
    public static function begin($db)
    {
        $db->begin_transaction();
        self::$frames[] = array('tasks' => array());
        if (!self::$guard_registered) {
            self::$guard_registered = true;
            register_shutdown_function(array(__CLASS__, 'reportUnflushed'));
        }
    }

    /**
     * Commit the innermost transaction. When it was the outermost one, the
     * queued after-commit tasks run, in order. A failing task is logged and
     * does not stop the others - the commit is already durable and the
     * request must still answer.
     *
     * @param object $db BaseDb / PageDb
     * @return int number of after-commit tasks that ran (failed ones
     *   included); 0 for an inner transaction, whose tasks wait for the
     *   outermost commit
     * @throws \Throwable when the commit fails; the frame stays open until
     *   the caller's catch calls rollback()
     */
    public static function commit($db)
    {
        $db->commit();

        $frame = array_pop(self::$frames);
        if ($frame === null) {
            return 0;
        }
        if (!empty(self::$frames)) {
            $outer = count(self::$frames) - 1;
            self::$frames[$outer]['tasks'] = array_merge(self::$frames[$outer]['tasks'], $frame['tasks']);
            return 0;
        }
        if (empty($frame['tasks'])) {
            return 0;
        }
        if (self::inTransaction($db)) {
            // The commit only released a savepoint: a transaction opened outside
            // this class is still around it and may yet roll back.
            self::reportDropped(count($frame['tasks']),
                'a transaction that was not opened with LlmTransaction::begin() is still open');
            return 0;
        }
        return self::run($frame['tasks']);
    }

    /**
     * Roll the innermost transaction (or savepoint level) back and drop the
     * tasks that were waiting for its commit.
     *
     * Never throws: a failing rollback (lost connection) leaves nothing to
     * undo server-side and must not mask the error that led here.
     *
     * @param object $db BaseDb / PageDb
     * @param string[] $cache_types Core cache types (Cache::CACHE_TYPE_*)
     *   that code inside the transaction may have filled with uncommitted
     *   rows. Each one is cleared by type; an empty list clears nothing.
     * @return bool false when the rollback itself failed
     */
    public static function rollback($db, array $cache_types = array())
    {
        $frame = array_pop(self::$frames);
        if ($frame !== null && !empty($frame['tasks'])) {
            self::reportDropped(count($frame['tasks']), 'the transaction was rolled back');
        }

        $ok = true;
        try {
            $dbh = $db->get_dbh();
            // ExtendedPdo::rollBack() throws without an open transaction;
            // a catch that runs after commit() has nothing left to undo.
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
        } catch (\Throwable $t) {
            $ok = false;
            LlmErrorReporter::log('Transaction rollback', $t);
        }

        foreach ($cache_types as $type) {
            // clear_cache() without a type would be the global wipe this
            // helper exists to avoid.
            if (!is_string($type) || $type === '') {
                continue;
            }
            try {
                $db->get_cache()->clear_cache($type);
            } catch (\Throwable $t) {
                LlmErrorReporter::log('Cache clear (' . $type . ') after rollback', $t);
            }
        }
        return $ok;
    }

    /**
     * Run $task once the transaction it was queued in has committed.
     *
     * - Inside a transaction opened with begin(): queued; runs at the
     *   outermost commit() and is dropped by a rollback().
     * - No transaction open: runs immediately (an exception propagates to
     *   the caller).
     * - Inside a transaction opened elsewhere: dropped and logged, see the
     *   class comment - unless $data_committed says the data the task
     *   depends on was committed before that transaction began (a core
     *   form-action job runs inside JobScheduler::execute_job()'s own
     *   transaction, after UserInput::save_data() committed). Then it runs
     *   immediately.
     *
     * @param object $db BaseDb / PageDb the work depends on
     * @param callable $task
     * @param bool $data_committed the task's data is already committed
     * @return bool false when the task was dropped
     */
    public static function afterCommit($db, $task, $data_committed = false)
    {
        if (!empty(self::$frames)) {
            self::$frames[count(self::$frames) - 1]['tasks'][] = $task;
            return true;
        }
        if (!$data_committed && self::inTransaction($db)) {
            self::reportDropped(1,
                'it was queued inside a transaction that was not opened with LlmTransaction::begin()');
            return false;
        }
        call_user_func($task);
        return true;
    }

    /**
     * Shutdown guard: a request that ends inside an open transaction (fatal
     * error, exit before commit) never committed, so its queued tasks are
     * dropped - but not silently. Public only because
     * register_shutdown_function() needs a callable.
     */
    public static function reportUnflushed()
    {
        if (!empty(self::$frames)) {
            $pending = 0;
            foreach (self::$frames as $frame) {
                $pending += count($frame['tasks']);
            }
            error_log('[LLM] The request ended inside ' . count(self::$frames) . ' open transaction(s)'
                . ($pending > 0
                    ? ': ' . $pending . ' after-commit task(s) were never released'
                    : ''));
        }
        self::$frames = array();
    }

    /**
     * @param object $db BaseDb / PageDb
     * @return bool true while the connection has a transaction open
     */
    private static function inTransaction($db)
    {
        try {
            return (bool)$db->get_dbh()->inTransaction();
        } catch (\Throwable $t) {
            return false;
        }
    }

    /**
     * @param callable[] $tasks
     * @return int number of tasks that ran (including failed ones)
     */
    private static function run(array $tasks)
    {
        foreach ($tasks as $task) {
            try {
                call_user_func($task);
            } catch (\Throwable $t) {
                LlmErrorReporter::log('After-commit task', $t);
            }
        }
        return count($tasks);
    }

    /**
     * @param int $count
     * @param string $why
     */
    private static function reportDropped($count, $why)
    {
        error_log('[LLM] ' . (int)$count . ' after-commit task(s) dropped: ' . $why);
    }
}
?>
