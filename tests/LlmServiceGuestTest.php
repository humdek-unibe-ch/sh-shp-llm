<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the guest isolation in LlmService (plugin v1.6.0, audit H3).
 *
 * Every anonymous visitor is the same SelfHelp user (GUEST_USER_ID), so rows owned
 * by that id belong to "all guests". The service therefore scopes them to the
 * visitor's browser session:
 *
 *  - a guest sees, loads, updates, deletes and continues only the conversations
 *    its own session started; a new guest starts with an empty list;
 *  - rate limits count per guest session (requests, concurrent conversations),
 *    plus a site-wide ceiling for all guests together;
 *  - logged-in users are not affected.
 *
 * No bootstrap, no database: the real LlmService, validator, exceptions and
 * globals.php run in a throw-away tree where small stand-ins replace
 * BaseLlmService, the provider registry, the cache manager and the database.
 * Two browsers are two $_SESSION arrays with their own session_id().
 *
 * Run: php tests/LlmServiceGuestTest.php
 */

// session_id() can only be changed while no output has been sent; the results are written to STDOUT directly
ob_start();

// An uncaught exception is a failed test, not a hung or silent run
set_exception_handler(function ($e) {
    fwrite(STDOUT, 'FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});

foreach (array(
    'GUEST_USER_ID' => 1,
    'transactionTypes_insert' => 'insert',
    'transactionTypes_update' => 'update',
    'transactionTypes_delete' => 'delete',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_service_guest_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_service_guest_test_' . getmypid();
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

// Real files, byte for byte
foreach (array(
    'service/LlmService.php',
    'service/globals.php',
    'service/LlmModelCapabilities.php',
    'service/validation/LlmValidator.php',
    'service/exception/LlmException.php',
    'service/exception/LlmValidationException.php',
    'service/exception/LlmRateLimitException.php',
    'service/exception/LlmApiException.php',
) as $file) {
    sandbox_write($root . '/server/plugins/sh-shp-llm/server/' . $file, file_get_contents($real_server . '/' . $file));
}

sandbox_write($service_dir . '/base/BaseLlmService.php', <<<'PHP'
<?php
class BaseLlmService
{
    protected $services;
    protected $db;
    protected $cacheManager;

    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
        $this->cacheManager = $services->cache;
    }

    protected function getLlmConfig()
    {
        return array(
            'llm_base_url' => 'https://api.example.org/v1',
            'llm_default_model' => 'model-1',
            'llm_temperature' => 1,
            'llm_max_tokens' => 2048,
        );
    }

    protected function normalizeModelIdentifierForStorage($model, $config = null)
    {
        return $model;
    }

    protected function parseScopedModelId($value)
    {
        return array('model' => $value);
    }

    protected function logTransaction($operation, $table, $record_id, $user_id, $details = '')
    {
    }

    protected function logError($message, $exception = null)
    {
    }
}
PHP
);

sandbox_write($service_dir . '/provider/LlmProviderRegistry.php', <<<'PHP'
<?php
class LlmProviderRegistry
{
    public static function getProviderForUrl($url)
    {
        return null;
    }
}
PHP
);

/* =========================================================================
 * Stand-ins
 * ========================================================================= */

class FakeCache
{
    public $rate = array();
    /** @var array per-user list cache: "prefix|user|params" => data */
    public $store = array();
    public $gets = 0;
    public $sets = 0;

    public function getRateLimitData($bucket)
    {
        return isset($this->rate[$bucket]) ? $this->rate[$bucket] : null;
    }

    public function setRateLimitData($bucket, $data)
    {
        $this->rate[$bucket] = $data;
    }

    public function shouldResetRateLimit($data)
    {
        return false;
    }

    public function initRateLimitData()
    {
        return array('requests' => 0, 'conversations' => array());
    }

    public function clearUserCache($user_id)
    {
        foreach (array_keys($this->store) as $key) {
            if (strpos($key, '|' . $user_id . '|') !== false) {
                unset($this->store[$key]);
            }
        }
    }

    public function clearConversationMessageCache($conversation_id)
    {
    }

    public function get($prefix, $id, array $params = array())
    {
        $this->gets++;
        $key = $prefix . '|' . $id . '|' . json_encode($params);
        return array_key_exists($key, $this->store) ? $this->store[$key] : false;
    }

    public function set($prefix, $id, $data, array $params = array())
    {
        $this->sets++;
        $this->store[$prefix . '|' . $id . '|' . json_encode($params)] = $data;
    }
}

class FakeDb
{
    /** @var array[] id => row */
    public $rows = array();
    public $queries = 0;
    private $next_id = 100;

    public function insert($table, $data)
    {
        $id = $this->next_id++;
        $this->rows[$id] = array_merge(array('id' => $id, 'deleted' => 0, 'blocked' => 0), $data);
        return $id;
    }

    public function get_lookup_id_by_value($type, $value)
    {
        return 5;
    }

    public function query_db($sql, $params = array())
    {
        $this->queries++;
        $guest_ids = array();
        foreach ($params as $key => $value) {
            if (strpos($key, ':guest_id_') === 0) {
                $guest_ids[] = $value;
            }
        }
        $result = array();
        foreach ($this->rows as $row) {
            if ((int)$row['id_users'] !== (int)$params[':id_user'] || $row['deleted']) {
                continue;
            }
            if (strpos($sql, 'id IN (') !== false && !in_array($row['id'], $guest_ids, true)) {
                continue;
            }
            $result[] = $row;
        }
        return $result;
    }

    public function query_db_first($sql, $params = array())
    {
        $this->queries++;
        $row = isset($this->rows[(int)$params[0]]) ? $this->rows[(int)$params[0]] : null;
        if (!$row || (int)$row['id_users'] !== (int)$params[1] || $row['deleted']) {
            return false;
        }
        // optional third parameter: "AND id_sections = ?"
        if (isset($params[2]) && (int)$row['id_sections'] !== (int)$params[2]) {
            return false;
        }
        return $row;
    }

    public function update_by_ids($table, $set, $where)
    {
        if ($table === 'llmConversations' && isset($this->rows[$where['id']])) {
            $this->rows[$where['id']] = array_merge($this->rows[$where['id']], $set);
        }
        return true;
    }
}

class FakeServices
{
    public $db;
    public $cache;

    public function __construct()
    {
        $this->db = new FakeDb();
        $this->cache = new FakeCache();
    }

    public function get_db()
    {
        return $this->db;
    }
}

require_once $root . '/server/plugins/sh-shp-llm/server/service/globals.php';
require_once $service_dir . '/LlmService.php';

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
        fwrite(STDOUT, "ok:   $label\n");
    } else {
        fwrite(STDOUT, "FAIL: $label\n");
        $failed++;
    }
}

$sessions = array();
$current_session = null;

/** Switch to another browser: its own $_SESSION and session id. */
function browser($name)
{
    global $sessions, $current_session;
    if ($current_session !== null) {
        $sessions[$current_session] = $_SESSION;
    }
    $current_session = $name;
    $_SESSION = isset($sessions[$name]) ? $sessions[$name] : array();
    session_id('sess' . md5($name));
}

function ids($rows)
{
    $ids = array_map(function ($row) {
        return (int)$row['id'];
    }, $rows);
    sort($ids);
    return $ids;
}

function throwsLlm(callable $call, $code = null)
{
    try {
        $call();
    } catch (LlmException $e) {
        return $code === null || $e->getCode() === $code;
    }
    return false;
}

$services = new FakeServices();
$service = new LlmService($services);
$GUEST = GUEST_USER_ID;

/* =========================================================================
 * Who is a guest
 * ========================================================================= */

check('the guest account is recognised (int and numeric string)', $service->isGuestUser(1) && $service->isGuestUser('1'));
check('other users, null and 0 are not guests', !$service->isGuestUser(7) && !$service->isGuestUser(null) && !$service->isGuestUser(0));

/* =========================================================================
 * Conversations belong to the browser session that started them
 * ========================================================================= */

browser('A');
$a1 = $service->createConversation($GUEST, 'A first', 'model-1', null, null, 9);
$a2 = $service->createConversation($GUEST, 'A second', 'model-1', null, null, 9);
browser('B');
$b1 = $service->createConversation($GUEST, 'B first', 'model-1', null, null, 9);

browser('A');
check('guest A sees exactly its own conversations', ids($service->getUserConversations($GUEST)) === ids(array(array('id' => $a1), array('id' => $a2))));
browser('B');
check('guest B sees exactly its own conversation', ids($service->getUserConversations($GUEST)) === array($b1));
browser('C');
$queries_before = $services->db->queries;
check('a new guest starts with an empty list', $service->getUserConversations($GUEST) === array());
check('...without even querying the database', $services->db->queries === $queries_before);

check('a guest cannot load another guest\'s conversation', $service->getConversation($b1, $GUEST) === null);
browser('A');
check('guest A cannot load guest B\'s conversation', $service->getConversation($b1, $GUEST) === null);
check('guest A can load its own conversation', $service->getConversation($a1, $GUEST) !== null && $service->getConversation($a1, $GUEST)['id'] === $a1);
check('the section filter still applies for the owner', $service->getConversation($a1, $GUEST, 9) !== null && $service->getConversation($a1, $GUEST, 10) === null);

check('guest A cannot rename guest B\'s conversation (404)', throwsLlm(function () use ($service, $b1, $GUEST) {
    $service->updateConversation($b1, $GUEST, array('title' => 'hijacked'));
}, 404) && $services->db->rows[$b1]['title'] === 'B first');
check('guest A cannot delete guest B\'s conversation (404)', throwsLlm(function () use ($service, $b1, $GUEST) {
    $service->deleteConversation($b1, $GUEST);
}, 404) && $services->db->rows[$b1]['deleted'] === 0);
check('guest A cannot continue guest B\'s conversation (404)', throwsLlm(function () use ($service, $b1, $GUEST) {
    $service->resolveConversation($GUEST, $b1, array('conversations' => array()), 'model-1', null, null, 9);
}, 404));

check('guest A can rename its own conversation', $service->updateConversation($a1, $GUEST, array('title' => 'Renamed')) === true
    && $services->db->rows[$a1]['title'] === 'Renamed');
$resolved = $service->resolveConversation($GUEST, $a1, array('conversations' => array()), 'model-1', null, null, 9);
check('guest A can continue its own conversation', $resolved['conversation_id'] === $a1 && $resolved['is_new'] === false);
$resolved = $service->resolveConversation($GUEST, null, array('conversations' => array()), 'model-1', null, null, 9);
check('a new thread of guest A is registered for guest A only', $resolved['is_new'] === true
    && in_array($resolved['conversation_id'], ids($service->getUserConversations($GUEST)), true));
browser('B');
check('...and stays invisible to guest B', !in_array($resolved['conversation_id'], ids($service->getUserConversations($GUEST)), true));

check('guest B can delete its own conversation', $service->deleteConversation($b1, $GUEST) === true && $services->db->rows[$b1]['deleted'] === 1);
check('...and then has an empty list again', $service->getUserConversations($GUEST) === array());
check('guest lists never read or fill the per-user cache that all guests would share', $services->cache->gets === 0 && $services->cache->sets === 0);

browser('A');
check('a session without cookie state (empty $_SESSION) loses the list: it starts new', (function () use ($service, $GUEST) {
    $keep = $_SESSION;
    $_SESSION = array();
    $empty = $service->getUserConversations($GUEST) === array();
    $_SESSION = $keep;
    return $empty;
})());

// The session list is capped
browser('Cap');
$created = array();
for ($i = 0; $i < LlmService::GUEST_SESSION_MAX_CONVERSATIONS + 5; $i++) {
    $created[] = $service->createConversation($GUEST, 'c' . $i, 'model-1', null, null, 9);
}
check('a guest session remembers at most ' . LlmService::GUEST_SESSION_MAX_CONVERSATIONS . ' conversations',
    count($_SESSION[LlmService::GUEST_SESSION_KEY]) === LlmService::GUEST_SESSION_MAX_CONVERSATIONS
    && $service->getConversation($created[0], $GUEST) === null
    && $service->getConversation(end($created), $GUEST) !== null);

/* =========================================================================
 * Logged-in users are not affected
 * ========================================================================= */

browser('Member');
$m1 = $service->createConversation(7, 'Member chat', 'model-1', null, null, 9);
check('a logged-in user\'s conversation is not registered in the session', !isset($_SESSION[LlmService::GUEST_SESSION_KEY]));
check('a logged-in user lists its conversations without any session list', ids($service->getUserConversations(7)) === array($m1));
check('...and that list is cached per user', $services->cache->sets === 1);
$m2 = $service->createConversation(7, 'Second member chat', 'model-1', null, null, 9);
check('...and refreshed when the user starts another conversation', ids($service->getUserConversations(7)) === array($m1, $m2));
browser('Other');
check('the same user in another browser still sees them', ids($service->getUserConversations(7)) === array($m1, $m2)
    && $service->getConversation($m1, 7) !== null);
check('a guest cannot load a member\'s conversation', $service->getConversation($m1, $GUEST) === null);
check('a member cannot load a guest conversation', $service->getConversation($a1, 7) === null);

/* =========================================================================
 * Rate limits
 * ========================================================================= */

$per_minute = LLM_RATE_LIMIT_REQUESTS_PER_MINUTE;
$concurrent = LLM_RATE_LIMIT_CONCURRENT_CONVERSATIONS;
$global = LLM_RATE_LIMIT_GUEST_GLOBAL_REQUESTS_PER_MINUTE;

browser('R1');
for ($i = 0; $i < $per_minute; $i++) {
    $service->updateRateLimit($GUEST, $service->checkRateLimit($GUEST));
}
check('a guest session is limited to ' . $per_minute . ' requests per minute', throwsLlm(function () use ($service, $GUEST) {
    $service->checkRateLimit($GUEST);
}));
browser('R2');
check('...while another guest session is not affected', $service->checkRateLimit($GUEST)['requests'] === 0);

browser('R3');
for ($i = 0; $i < $concurrent; $i++) {
    $service->updateRateLimit($GUEST, $service->checkRateLimit($GUEST), 900 + $i);
}
check('a guest session is limited to ' . $concurrent . ' concurrent conversations', throwsLlm(function () use ($service, $GUEST) {
    $service->checkRateLimit($GUEST);
}));
browser('R4');
check('...while another guest session is not affected by that either', is_array($service->checkRateLimit($GUEST)));

// All guests together share one budget. 9 requests per session never trip a session limit.
$requests_so_far = $services->cache->rate['guest_global']['requests'];
$session = 0;
while ($requests_so_far < $global) {
    browser('G' . $session++);
    for ($i = 0; $i < $per_minute - 1 && $requests_so_far < $global; $i++) {
        $service->updateRateLimit($GUEST, $service->checkRateLimit($GUEST));
        $requests_so_far++;
    }
}
browser('Fresh');
$busy = null;
try {
    $service->checkRateLimit($GUEST);
} catch (LlmRateLimitException $e) {
    $busy = $e;
}
check('after ' . $global . ' guest requests in a minute even a fresh guest is told to wait', $busy !== null
    && strpos($busy->getMessage(), 'busy') !== false);
check('a logged-in user is not held back by the guest ceiling', is_array($service->checkRateLimit(7)));
$service->updateRateLimit(7, null);
check('a logged-in user does not draw from the guest budget', $services->cache->rate['guest_global']['requests'] === $global);
check('a logged-in user\'s bucket is its user id', isset($services->cache->rate[7]) && $services->cache->rate[7]['requests'] === 1);

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
