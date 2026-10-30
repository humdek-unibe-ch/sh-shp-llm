<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for LlmDangerDetectionService (plugin v1.6.0, audit H1 / H8 and
 * the phrase-matching fix):
 *
 *  - keyword matching: single words are exact and bounded ("skill" is not "kill"),
 *    phrases tolerate typos ("kill myslef"), everything is UTF-8 aware;
 *  - detectKeywords() has no side effect, handleDetection() applies them;
 *  - the alert email neutralises user text and uses the system sender address;
 *  - an LLM turn without a safety assessment is "unknown", logged, never "safe".
 *
 * No bootstrap, no database: the real service runs in a throw-away tree where
 * small stand-ins replace BaseLlmService, the prompt asset loader, the model,
 * the services container, the database and the job scheduler.
 *
 * Run: php tests/LlmDangerDetectionServiceTest.php
 */

require_once __DIR__ . '/../server/service/globals.php';

foreach (array(
    'jobTypes' => 'jobTypes',
    'jobTypes_email' => 'email',
    'scheduledJobsStatus' => 'scheduledJobsStatus',
    'scheduledJobsStatus_queued' => 'queued',
    'transactionBy_by_system' => 'by_system',
    'transactionTypes_insert' => 'insert',
    'TRANSACTION_BY_LLM_PLUGIN' => 'by_llm_plugin',
    'SH_EMAIL' => 'email',
    'PF_EMAIL_ACTIVATE_EMAIL_ADDRESS' => 'email_activate_address',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_danger_detection_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_danger_detection_test_' . getmypid();
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

sandbox_write($service_dir . '/LlmDangerDetectionService.php', file_get_contents($real_server . '/service/LlmDangerDetectionService.php'));
sandbox_write($service_dir . '/LlmSafetyConfigService.php', file_get_contents($real_server . '/service/LlmSafetyConfigService.php'));

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

    protected function logInfo($message, $context = array())
    {
    }

    protected function logDebug($message, $context = array())
    {
    }

    protected function logWarning($message, $context = array())
    {
        $GLOBALS['T']['warnings'][] = $message;
    }

    protected function logError($message, $exception = null)
    {
        $GLOBALS['T']['errors'][] = $message;
    }
}
PHP
);

sandbox_write($service_dir . '/prompt/LlmPromptAssetLoader.php', <<<'PHP'
<?php
class LlmPromptAssetLoader
{
    public function load($key)
    {
        return 'Refuse these topics: {{keywords_list}}';
    }
}
PHP
);

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class FakeModel
{
    public $keywords = '';
    public $emails = array();

    public function getDangerKeywords()
    {
        return $this->keywords;
    }

    public function getDangerNotificationEmails()
    {
        return $this->emails;
    }

    public function getSectionId()
    {
        return 31;
    }

    public function getDangerBlockedMessage()
    {
        return 'Please contact a professional.';
    }
}

class FakeDb
{
    public $updates = array();
    public $email_page = array();
    /** @var array[] llmConversations rows the blocked lookup can see */
    public $conversations = array();
    /** @var array[] [sql, params] of every query_db_first() call */
    public $queries = array();
    /** @var Throwable|null thrown by query_db_first() */
    public $fail = null;

    public function update_by_ids($table, $set, $where)
    {
        $this->updates[] = array($table, $set, $where);
        return true;
    }

    /**
     * Understands only the blocked lookup: it answers the way the SQL it was
     * given would, so a missing `id_users` condition shows up as a foreign row.
     */
    public function query_db_first($sql, $params = array())
    {
        $this->queries[] = array($sql, $params);
        if ($this->fail !== null) {
            throw $this->fail;
        }
        $scoped = strpos($sql, 'id_users = :id_users') !== false;
        foreach ($this->conversations as $row) {
            if ((int)$row['id'] !== (int)$params['id']) {
                continue;
            }
            if ($scoped && (int)$row['id_users'] !== (int)$params['id_users']) {
                continue;
            }
            return array('blocked' => $row['blocked']);
        }
        return false;
    }

    public function get_lookup_id_by_value($type, $value)
    {
        return 1;
    }

    public function fetch_page_info($keyword)
    {
        return $this->email_page;
    }
}

class FakeTransaction
{
    public $calls = array();

    public function add_transaction()
    {
        $this->calls[] = func_get_args();
        return true;
    }

    /** Transactions written for one virtual table name. */
    public function forTable($table)
    {
        return array_values(array_filter($this->calls, function ($call) use ($table) {
            return $call[3] === $table;
        }));
    }
}

class FakeJobScheduler
{
    public $jobs = array();

    public function add_and_execute_job($mail_data, $by)
    {
        $this->jobs[] = $mail_data;
        return true;
    }
}

class FakeServices
{
    public $db;
    public $transaction;
    public $scheduler;

    public function __construct()
    {
        $this->db = new FakeDb();
        $this->transaction = new FakeTransaction();
        $this->scheduler = new FakeJobScheduler();
    }

    public function get_db()
    {
        return $this->db;
    }

    public function get_transaction()
    {
        return $this->transaction;
    }

    public function get_job_scheduler()
    {
        return $this->scheduler;
    }
}

class FakeResponseService
{
    public $safety;

    public function assessSafety($parsed_response)
    {
        return $this->safety;
    }
}

require_once $service_dir . '/LlmDangerDetectionService.php';

/** The real settings resolver with an empty global settings page: the section values decide. */
class SectionOnlySafetyConfig extends LlmSafetyConfigService
{
    protected function loadGlobalFields()
    {
        return array();
    }
}

/** No keywords anywhere (not even the built-in defaults). */
class NoKeywordsSafetyConfig
{
    public function getKeywords()
    {
        return array();
    }

    public function getNotificationEmails()
    {
        return array('safety@example.org');
    }

    public function getBlockedMessage()
    {
        return 'Please contact a professional.';
    }
}

/* =========================================================================
 * Helpers
 * ========================================================================= */

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

/** @return array [service, services, model] */
function make($keywords, $emails = array())
{
    $GLOBALS['T'] = array('warnings' => array(), 'errors' => array());
    $services = new FakeServices();
    $model = new FakeModel();
    $model->keywords = $keywords;
    $model->emails = $emails;
    return array(new LlmDangerDetectionService($services, $model, new SectionOnlySafetyConfig($services, $model)), $services, $model);
}

function detect($keywords, $message)
{
    list($service) = make($keywords);
    return $service->detectKeywords($message);
}

function hits($keywords, $message, $keyword)
{
    return in_array($keyword, detect($keywords, $message), true);
}

/* =========================================================================
 * Keyword list
 * ========================================================================= */

list($service) = make(" TÖTEN, KILL , kill,, Suicide ,kill myself");
check('keywords are trimmed, lowercased (UTF-8), de-duplicated and re-indexed',
    $service->getKeywords() === array('töten', 'kill', 'suicide', 'kill myself'));
$services = new FakeServices();
$service = new LlmDangerDetectionService($services, new FakeModel(), new NoKeywordsSafetyConfig());
check('no_keywords_anywhere_is_noop', $service->getKeywords() === array() && $service->detectKeywords('kill') === array()
    && $service->checkMessage('kill', 7, 55)['safe'] === true && $services->scheduler->jobs === array());
list($service) = make('');
check('an empty section keyword list uses the built-in defaults', in_array('kill myself', $service->getKeywords(), true));

/* =========================================================================
 * Single words: exact, bounded, UTF-8
 * ========================================================================= */

check('a single word matches', hits('kill', 'I want to kill', 'kill'));
check('a single word matches in any case', hits('kill', 'KILL IT', 'kill'));
check('a single word matches next to punctuation', hits('kill', 'kill, then stop.', 'kill'));
check('"skill" is not "kill"', !hits('kill', 'a useful skill', 'kill'));
check('"killer" is not "kill" (single words stay exact)', !hits('kill', 'a killer whale', 'kill'));
check('a single UTF-8 word matches in upper case', hits('töten', 'ich will TÖTEN', 'töten'));
check('a single UTF-8 word is bounded too', !hits('töten', 'ich will lötöten', 'töten'));
check('a single non-latin word matches', hits('самоубийство', 'Я думаю о САМОУБИЙСТВО', 'самоубийство'));

/* =========================================================================
 * Phrases: exact, typos, gap words
 * ========================================================================= */

$phrase = 'kill myself';
check('an exact phrase matches', hits($phrase, 'i think i will kill myself tonight', $phrase));
check('a phrase matches in any case', hits($phrase, 'KILL MYSELF', $phrase));
check('a transposition typo is tolerated ("myslef")', hits($phrase, 'i will kill myslef', $phrase));
check('a substitution typo is tolerated ("mysalf")', hits($phrase, 'kill mysalf', $phrase));
check('a missing letter is tolerated ("mysef")', hits($phrase, 'kill mysef', $phrase));
check('an extra letter is tolerated ("myselff")', hits($phrase, 'kill myselff', $phrase));
check('one other word between the phrase words is tolerated', hits($phrase, 'kill really myself', $phrase));
check('two other words between the phrase words are not', !hits($phrase, 'kill really truly myself', $phrase));
check('the word order matters', !hits($phrase, 'myself kill', $phrase));
check('punctuation between the words does not matter', hits($phrase, 'kill... myself!', $phrase));
check('short words must be exact ("kil" is not "kill")', !hits($phrase, 'kil myself', $phrase));
check('short words must be exact ("will" is not "kill")', !hits($phrase, 'will myself', $phrase));
check('a phrase is not found in unrelated text', !hits($phrase, 'I like my shelf and my skill', $phrase));
check('only one word of the phrase is not enough', !hits($phrase, 'I will kill the process', $phrase));

$long = 'selbstmord begehen';
check('two typos are tolerated in long words ("selbtsmrod")', hits($long, 'ich will selbtsmrod begehen', $long));
check('one typo in a long word ("selbstmrod")', hits($long, 'selbstmrod begehen', $long));
check('three typos in a long word are too many', !hits($long, 'selbtsmrdo begehen', $long));

$de = 'mich umbringen';
check('a UTF-8 phrase matches in upper case', hits($de, 'ICH WILL MICH UMBRINGEN', $de));
check('a UTF-8 phrase tolerates a typo ("umbringn")', hits($de, 'ich will mich umbringn', $de));
check('a phrase with umlauts: "für immer schlafen"', hits('für immer schlafen', 'ich will FÜR IMMER SCHLAFEN', 'für immer schlafen'));
check('a phrase with umlauts tolerates a typo ("schlfaen")', hits('für immer schlafen', 'ich will für immer schlfaen', 'für immer schlafen'));
check('an empty message detects nothing', detect('kill, kill myself', '') === array() && detect('kill, kill myself', "  \n ") === array());
check('several keywords are all reported once', detect('kill, kill myself, hurt myself', 'kill myslef and hurt myself') === array('kill', 'kill myself', 'hurt myself'));

/* =========================================================================
 * detectKeywords has no side effect, handleDetection applies them
 * ========================================================================= */

list($service, $services, $model) = make('kill', array('safety@example.org'));
$found = $service->detectKeywords('I will kill it');
check('detectKeywords finds the keyword', $found === array('kill'));
check('detectKeywords blocks, logs and mails nothing', $services->db->updates === array() && $services->transaction->calls === array()
    && $services->scheduler->jobs === array());

list($service) = make('kill myself');
check('detects_without_enable_flag', $service->detectKeywords('I want to kill myself') === array('kill myself'));

list($service, $services, $model) = make('kill', array('safety@example.org', 'not-an-email', 'second@example.org'));
$result = $service->checkMessage('I will kill it', 7, 55);
check('checkMessage reports the message as unsafe with the blocked text', $result['safe'] === false
    && $result['message'] === 'Please contact a professional.' && $result['detected_keywords'] === array('kill'));
check('checkMessage blocks the conversation (automatic: blocked_by null)', count($services->db->updates) === 1
    && $services->db->updates[0][0] === 'llmConversations' && $services->db->updates[0][1]['blocked'] === 1
    && $services->db->updates[0][1]['blocked_by'] === null && $services->db->updates[0][2] === array('id' => 55));
check('checkMessage writes one audit entry', count($services->transaction->forTable('llm_danger_detection')) === 1);
check('checkMessage mails every valid address and skips the invalid one', count($services->scheduler->jobs) === 2
    && $services->scheduler->jobs[0]['recipient_emails'] === 'safety@example.org'
    && $services->scheduler->jobs[1]['recipient_emails'] === 'second@example.org' && $result['notification_sent'] === true);
list($service, $services) = make('kill');
$result = $service->checkMessage('a harmless message', 7, 55);
check('a harmless message is safe and changes nothing', $result['safe'] === true && $services->db->updates === array()
    && $services->transaction->calls === array());
list($service, $services) = make('kill', array('safety@example.org'));
$service->checkMessage('I will kill it', 7, null);
check('a new conversation (no id yet) is not blocked but still logged and mailed', $services->db->updates === array()
    && count($services->transaction->forTable('llm_danger_detection')) === 1 && count($services->scheduler->jobs) === 1);

/* =========================================================================
 * Alert email: sender and neutralised text
 * ========================================================================= */

$_SERVER['HTTP_HOST'] = 'cms.example.org:8443';
list($service, $services) = make('kill', array('safety@example.org'));
$services->db->email_page = array('email_activate_address' => 'noreply-core@uni.example');
$service->sendNotifications(array('kill'), 'I will kill it', 7, 55, 31);
$job = $services->scheduler->jobs[0];
check('the sender is the address configured for core system emails', $job['from_email'] === 'noreply-core@uni.example'
    && $job['reply_to'] === 'noreply-core@uni.example');

list($service, $services) = make('kill', array('safety@example.org'));
$service->sendNotifications(array('kill'), 'I will kill it', 7, 55, 31);
check('without a configured sender it is noreply@<host> (port removed)', $services->scheduler->jobs[0]['from_email'] === 'noreply@cms.example.org');

list($service, $services) = make('kill', array('safety@example.org'));
$services->db->email_page = array('email_activate_address' => 'not an address');
$_SERVER['HTTP_HOST'] = "evil.example\r\nBcc: attacker@example.org";
$service->sendNotifications(array('kill'), 'I will kill it', 7, 55, 31);
check('an invalid configured sender and a hostile host fall back to noreply@localhost', $services->scheduler->jobs[0]['from_email'] === 'noreply@localhost');
$_SERVER['HTTP_HOST'] = 'cms.example.org';

$hostile = "<script>alert(1)</script> [click](http://evil.example) **bold** `code` | cell\n# Heading\n\n> quote";
list($service, $services) = make('kill', array('safety@example.org'));
$service->sendNotifications(array('kill', '<b>x</b>'), $hostile, "7; DROP TABLE users", 55, "31 OR 1=1");
$body = $services->scheduler->jobs[0]['body'];
check('the message excerpt is one code span on one line', preg_match('/^> `[^`\r\n]*`\r?$/m', $body) === 1);  // the body keeps the source file's line endings
check('a backtick in the message cannot close the code span', strpos($body, "`code`") === false && strpos($body, "'code'") !== false);
check('the hostile text sits inside the code span, never as its own line', strpos($body, "\n# Heading") === false
    && strpos($body, "\n> quote") === false);
check('the keywords are a code span too', strpos($body, '| `kill, <b>x</b>` |') !== false);
check('ids are cast to integers', strpos($body, '| **User ID** | 7 |') !== false && strpos($body, '| **Section ID** | 31 |') !== false
    && strpos($body, 'DROP TABLE') === false && strpos($body, '1=1') === false);
check('the alert goes out as HTML mail', $services->scheduler->jobs[0]['is_html'] === 1);

list($service, $services) = make('kill', array('safety@example.org'));
$service->sendNotifications(array('kill'), str_repeat('ä', 250), 7, null, 31);
$body = $services->scheduler->jobs[0]['body'];
check('the excerpt is cut after 200 characters (UTF-8 safe) and marked', strpos($body, str_repeat('ä', 200) . '...') !== false
    && strpos($body, str_repeat('ä', 201)) === false);
check('a new conversation is labelled as such', strpos($body, 'N/A (new conversation)') !== false);

list($service, $services) = make('kill', array());
check('no notification addresses: nothing is sent', $service->sendNotifications(array('kill'), 'x', 7, 55, 31) === false
    && $services->scheduler->jobs === array());

/* =========================================================================
 * An LLM turn without a safety assessment is "unknown", never "safe"
 * ========================================================================= */

$unassessed = array('assessed' => false, 'is_safe' => null, 'danger_level' => null, 'detected_concerns' => array(),
    'requires_intervention' => false, 'safety_message' => null);

list($service, $services, $model) = make('kill', array('safety@example.org'));
$response = new FakeResponseService();
$response->safety = $unassessed;
$service->processSafetyDetection($response, array(), 55, 7, 31);
check('an unassessed turn leaves a trace for admins', count($services->transaction->forTable('llm_safety_unavailable')) === 1
    && count($GLOBALS['T']['warnings']) === 1);
check('an unassessed turn neither blocks nor mails', $services->db->updates === array() && $services->scheduler->jobs === array());

check('safety_unavailable_logged_always (no switch exists anymore)', !method_exists($service, 'isEnabled'));

$safe = array('assessed' => true, 'is_safe' => true, 'danger_level' => null, 'detected_concerns' => array(),
    'requires_intervention' => false, 'safety_message' => null);
list($service, $services) = make('kill', array('safety@example.org'));
$response->safety = $safe;
$service->processSafetyDetection($response, array(), 55, 7, 31);
check('an assessed, safe turn changes nothing', $services->transaction->calls === array() && $services->db->updates === array()
    && $services->scheduler->jobs === array());

$critical = array('assessed' => true, 'is_safe' => false, 'danger_level' => 'critical', 'detected_concerns' => array('self_harm'),
    'requires_intervention' => true, 'safety_message' => 'Reach out for help');
list($service, $services) = make('kill', array('safety@example.org'));
$response->safety = $critical;
$service->processSafetyDetection($response, array(), 55, 7, 31);
check('a critical assessment blocks the conversation', count($services->db->updates) === 1
    && strpos($services->db->updates[0][1]['blocked_reason'], 'CRITICAL') !== false);
check('critical_llm_safety_notifies_without_flag: a critical assessment is logged and mailed', count($services->transaction->forTable('llm_safety_detection')) === 1
    && count($services->scheduler->jobs) === 1);

$intervention = array('assessed' => true, 'is_safe' => false, 'danger_level' => 'moderate', 'detected_concerns' => array('distress'),
    'requires_intervention' => true, 'safety_message' => 'Check in with the participant');
list($service, $services) = make('kill', array('safety@example.org'));
$response->safety = $intervention;
$service->processSafetyDetection($response, array(), 55, 7, 31);
check('an intervention below critical mails without blocking', $services->db->updates === array() && count($services->scheduler->jobs) === 1);

/* =========================================================================
 * Blocked lookup: scoped to the acting user, never fails open
 * ========================================================================= */

list($service, $services) = make('kill');
$services->db->conversations = array(
    array('id' => 55, 'id_users' => 7, 'blocked' => 1),
    array('id' => 56, 'id_users' => 7, 'blocked' => 0),
    array('id' => 57, 'id_users' => 8, 'blocked' => 1),
);
check('own blocked conversation is blocked', $service->isConversationBlocked(55, 7) === true);
check('own open conversation is not blocked', $service->isConversationBlocked(56, 7) === false);
check('somebody else\'s blocked conversation is not reported (no oracle)', $service->isConversationBlocked(57, 7) === false);
check('the owner still sees it as blocked', $service->isConversationBlocked(57, 8) === true);
check('an unknown conversation is not blocked', $service->isConversationBlocked(99, 7) === false);
$services->db->queries = array();
check('no conversation id: false without a query', $service->isConversationBlocked(0, 7) === false
    && $service->isConversationBlocked(null, 7) === false && $services->db->queries === array());
check('no acting user: false without a query', $service->isConversationBlocked(55, 0) === false
    && $service->isConversationBlocked(55, null) === false && $services->db->queries === array());
$service->isConversationBlocked(55, 7);
check('the lookup is parameterized and scoped by id_users',
    count($services->db->queries) === 1
    && strpos($services->db->queries[0][0], 'id_users = :id_users') !== false
    && strpos($services->db->queries[0][0], '55') === false
    && $services->db->queries[0][1] === array('id' => 55, 'id_users' => 7));

$services->db->fail = new PDOException('server has gone away');
$thrown = null;
$result = null;
try {
    $result = $service->isConversationBlocked(55, 7);
} catch (Throwable $t) {
    $thrown = $t;
}
check('a database error is not turned into "not blocked"', $result === null && $thrown instanceof PDOException);
check('a database error is logged', in_array('Failed to check if conversation is blocked', $GLOBALS['T']['errors'], true));
$services->db->fail = null;
check('the service works again once the database does', $service->isConversationBlocked(55, 7) === true);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
