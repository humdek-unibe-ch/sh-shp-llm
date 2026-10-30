<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the speech-to-text upload funnel (plugin v1.6.0).
 *
 * Both endpoints that accept a recording (chat speech_transcribe and the
 * interview transcribe action) end in LlmSpeechToTextService::
 * saveAndTranscribeAudio(). Each recording costs a call to a paid Whisper
 * model and leaves a file on disk, so the funnel
 *
 *  - judges the file by its content: what the client declared is only a
 *    claim (HTML, images, archives, executables, ... are refused whatever
 *    they are called; content libmagic cannot name is accepted when the
 *    declared type is a supported audio type);
 *  - derives the stored extension from the detected type, never from the
 *    declared one;
 *  - spends from a per-user speech budget (6 per minute) only for
 *    recordings that would otherwise be transcribed, and refuses the rest
 *    before anything is stored or sent;
 *  - labels every refusal ('invalid_audio' / 'rate_limited') so the
 *    controllers answer 400 / 429.
 *
 * No bootstrap, no database, no network: the real speech service, naming
 * service, LlmService and globals.php run in a throw-away tree where small
 * stand-ins replace BaseLlmService, the provider registry and the cache; the
 * Whisper call itself (transcribeAudio) is a recording stub.
 *
 * Run: php tests/LlmSpeechToTextServiceTest.php
 */

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
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'llm_speech_test.log');

/* =========================================================================
 * Sandbox tree
 * ========================================================================= */

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/llm_speech_test_' . getmypid();
$plugin = $root . '/server/plugins/sh-shp-llm';
$service_dir = $plugin . '/server/service';
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
    'service/LlmSpeechToTextService.php',
    'service/LlmFileNamingService.php',
    'service/LlmLanguageUtility.php',
    'service/LlmService.php',
    'service/LlmErrorReporter.php',
    'service/globals.php',
    'service/LlmModelCapabilities.php',
    'service/validation/LlmValidator.php',
    'service/exception/LlmException.php',
    'service/exception/LlmValidationException.php',
    'service/exception/LlmRateLimitException.php',
    'service/exception/LlmApiException.php',
) as $file) {
    sandbox_write($plugin . '/server/' . $file, file_get_contents($real_server . '/' . $file));
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
        return array();
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
}

class FakeServices
{
    public $cache;

    public function __construct()
    {
        $this->cache = new FakeCache();
    }

    public function get_db()
    {
        return new stdClass();
    }
}

require_once $service_dir . '/globals.php';
require_once $service_dir . '/LlmSpeechToTextService.php';

/** The real funnel with the paid Whisper call replaced by a recording stub. */
class SpeechUnderTest extends LlmSpeechToTextService
{
    public $transcribed = array();
    public $transcribe_result = array('success' => true, 'text' => 'hello world');

    public function transcribeAudio($audioFilePath, $model = null, $language = null)
    {
        $this->transcribed[] = $audioFilePath;
        return $this->transcribe_result;
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
        fwrite(STDOUT, "ok:   $label\n");
    } else {
        fwrite(STDOUT, "FAIL: $label\n");
        $failed++;
    }
}

/** Recordings and other files as the content-type sniffer sees them. */
function sample($name)
{
    switch ($name) {
        case 'webm':
            return "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02" . str_repeat("\x00", 64);
        case 'ogg':
            return "OggS\x00\x02" . str_repeat("\x00", 20) . "\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00" . str_repeat("\x00", 40);
        case 'm4a':
            return "\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00M4A mp42isom\x00\x00\x00\x00" . str_repeat("\x00", 64);
        case 'mp4':
            return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", 64);
        case 'mp3':
            return "\xFF\xFB\x90\x44" . str_repeat("\x00", 200);
        case 'flac':
            return "fLaC\x00\x00\x00\x22" . str_repeat("\x00", 60);
        case 'aac':
            return "\xFF\xF1\x50\x80\x02\x1F\xFC" . str_repeat("\x21", 200);
        case 'wav':
            return "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xac\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00";
        case 'unknown':
            return "\x01\x02\x03\x04" . str_repeat("\x00", 60);
        case 'html':
            return '<!DOCTYPE html><html><body><script>alert(document.cookie)</script></body></html>';
        case 'svg':
            return '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        case 'php':
            return '<?php system($_GET["c"]); ?>';
        case 'text':
            return "just some text\n";
        case 'png':
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        case 'gif':
            return base64_decode('R0lGODlhAQABAAAAACw=');
        case 'zip':
            return "PK\x05\x06" . str_repeat("\x00", 18);
        case 'pdf':
            return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
        case 'exe':
            return 'MZ' . str_repeat("\x00", 62) . "\x40\x00\x00\x00" . str_repeat("\x00", 60);
    }
    throw new Exception('unknown sample ' . $name);
}

$upload_seq = 0;

/** A $_FILES['audio'] entry for the given bytes and declared type. */
function upload($bytes, $declared = 'audio/webm', $size = null)
{
    global $root, $upload_seq;
    $dir = $root . '/incoming';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir . '/php' . (++$upload_seq) . '.tmp';
    file_put_contents($path, $bytes);
    return array(
        'name' => 'recording.webm',
        'type' => $declared,
        'tmp_name' => $path,
        'error' => 0,
        'size' => $size === null ? strlen($bytes) : $size,
    );
}

function sniffed($name, $declared = 'audio/webm')
{
    global $speech;
    $file = upload(sample($name), $declared);
    return $speech->detectAudioType($file['tmp_name'], $declared);
}

/** Files stored for a user. */
function stored($user_id)
{
    global $plugin;
    $dir = $plugin . '/' . LLM_UPLOAD_FOLDER . '/' . (int)$user_id;
    return is_dir($dir) ? array_values(array_diff(scandir($dir), array('.', '..'))) : array();
}

$services = new FakeServices();
$speech = new SpeechUnderTest($services);

/* =========================================================================
 * The sniffer is the one that knows what a file is
 * ========================================================================= */

// What libmagic makes of the recorder formats (the expectation is the type, not the container name)
check('webm recording is audio (reported as video/webm)', sniffed('webm') === 'video/webm');
check('ogg/opus recording is audio', sniffed('ogg', 'audio/ogg;codecs=opus') === 'audio/ogg');
check('m4a recording is audio', sniffed('m4a', 'audio/mp4') === 'audio/x-m4a');
check('mp4 audio (reported as video/mp4) is audio', sniffed('mp4', 'audio/mp4') === 'video/mp4');
check('mp3 is audio', sniffed('mp3', 'audio/mpeg') === 'audio/mpeg');
check('flac is audio', sniffed('flac', 'audio/flac') === 'audio/flac');
check('aac (adts) is audio', sniffed('aac', 'audio/aac') === 'audio/x-hx-aac-adts');

// The content decides, the declared type is only a claim
check('audio content is audio whatever it was declared as', sniffed('ogg', 'text/html') === 'audio/ogg' && sniffed('webm', '') === 'video/webm');
foreach (array('html', 'svg', 'png', 'gif', 'zip', 'pdf', 'exe', 'text', 'wav') as $name) {
    check("$name declared as audio/webm is not audio", sniffed($name, 'audio/webm') === null);
    check("$name declared as audio/ogg;codecs=opus is not audio", sniffed($name, 'audio/ogg;codecs=opus') === null);
}
check('php source declared as audio/webm is not audio', sniffed('php', 'audio/webm') === null);
check('a missing file is not audio', $speech->detectAudioType($root . '/incoming/nothing.webm', 'audio/webm') === null);
check('a directory is not audio', $speech->detectAudioType($root . '/incoming', 'audio/webm') === null);

// Content libmagic cannot name: only with a supported audio type declared (e.g. a very short recording)
check('unnamed content declared as audio/webm is accepted as the declared type', sniffed('unknown', 'audio/webm') === 'audio/webm');
check('...with the codec parameter stripped', sniffed('unknown', 'audio/webm;codecs=opus') === 'audio/webm');
check('unnamed content declared as audio/mp4 is accepted', sniffed('unknown', 'audio/mp4') === 'audio/mp4');
check('unnamed content declared as text/html is refused', sniffed('unknown', 'text/html') === null);
check('unnamed content declared as image/png is refused', sniffed('unknown', 'image/png') === null);
check('unnamed content declared as application/octet-stream is refused', sniffed('unknown', 'application/octet-stream') === null);
check('unnamed content with nothing declared is refused', sniffed('unknown', '') === null);

/* =========================================================================
 * Which status a refusal gets
 * ========================================================================= */

check('rate limited -> 429', LlmSpeechToTextService::failureStatus(array('success' => false, 'error_type' => 'rate_limited')) === 429);
check('invalid audio -> 400', LlmSpeechToTextService::failureStatus(array('success' => false, 'error_type' => 'invalid_audio')) === 400);
check('transcription trouble -> the caller\'s own default', LlmSpeechToTextService::failureStatus(array('success' => false, 'error' => 'Speech recognition service error')) === null);
check('an empty result -> the caller\'s own default', LlmSpeechToTextService::failureStatus(array()) === null);
check('an unknown error type -> the caller\'s own default', LlmSpeechToTextService::failureStatus(array('error_type' => 'whatever')) === null);

/* =========================================================================
 * A good recording is stored under its detected type and transcribed
 * ========================================================================= */

$result = $speech->saveAndTranscribeAudio(upload(sample('webm'), 'audio/webm;codecs=opus'), 7, 42, null, null, 'en', true);
check('a webm recording is transcribed', $result['success'] === true && $result['text'] === 'hello world');
check('...exactly once', count($speech->transcribed) === 1);
check('...stored with the extension of what it is', $result['audio_file']['extension'] === 'webm' && substr($result['audio_file']['filename'], -5) === '.webm');
check('...and the detected type is reported', $result['audio_file']['type'] === 'video/webm');
check('...kept on disk (keepFile = true)', count(stored(7)) === 1 && file_exists($speech->transcribed[0]));
check('...and the budget was charged', $services->cache->rate['speech_7']['requests'] === 1);

$result = $speech->saveAndTranscribeAudio(upload(sample('webm'), 'audio/webm'), 7, 42, null, null, 'en', false);
check('keepFile = false removes the recording again', $result['success'] === true && !empty($result['audio_file']['deleted']) && count(stored(7)) === 1);

// The extension follows the content, not the declared type
$before = count(stored(7));
$result = $speech->saveAndTranscribeAudio(upload(sample('ogg'), 'audio/webm'), 7, 42, null, null, 'en', true);
check('an ogg recording declared as webm is stored as .ogg', $result['success'] === true && $result['audio_file']['extension'] === 'ogg' && substr($result['audio_file']['filename'], -4) === '.ogg');
$result = $speech->saveAndTranscribeAudio(upload(sample('m4a'), 'audio/webm'), 7, 42, null, null, 'en', true);
check('an m4a recording declared as webm is stored as .m4a', $result['success'] === true && $result['audio_file']['extension'] === 'm4a');
$result = $speech->saveAndTranscribeAudio(upload(sample('flac'), 'audio/webm'), 7, 42, null, null, 'en', true);
check('a flac recording declared as webm is stored as .flac', $result['success'] === true && $result['audio_file']['extension'] === 'flac');
$result = $speech->saveAndTranscribeAudio(upload(sample('unknown'), 'audio/webm'), 7, 42, null, null, 'en', true);
check('unnamed content declared as webm is stored as .webm', $result['success'] === true && $result['audio_file']['extension'] === 'webm');
check('...and only the four stored files were added', count(stored(7)) === $before + 4);

// Whatever the client names the file, the stored name is built from numbers and the detected type
$hostile = upload(sample('webm'), 'audio/webm');
$hostile['name'] = '../../../evil.php';
$result = $speech->saveAndTranscribeAudio($hostile, 8, 42, null, null, 'en', true);
check('the client file name has no say in the stored name', $result['success'] === true
    && preg_match('/^8_section_42_conv_0_audio_\d+_[a-f0-9]+\.webm$/', $result['audio_file']['filename']) === 1
    && count(stored(8)) === 1);

/* =========================================================================
 * Refusals: nothing stored, nothing sent, nothing charged
 * ========================================================================= */

// one good recording first, so that "nothing charged" is a statement about a budget that exists
$speech->saveAndTranscribeAudio(upload(sample('webm')), 9, 42, null, null, 'en', true);
$files_before = count(stored(9));
$sent_before = count($speech->transcribed);

$refused = array(
    'html declared as webm' => upload(sample('html'), 'audio/webm'),
    'svg declared as webm' => upload(sample('svg'), 'audio/webm'),
    'png declared as webm' => upload(sample('png'), 'audio/webm'),
    'zip declared as webm' => upload(sample('zip'), 'audio/webm'),
    'exe declared as webm' => upload(sample('exe'), 'audio/webm'),
    'php declared as webm' => upload(sample('php'), 'audio/webm'),
    'unnamed content declared as html' => upload(sample('unknown'), 'text/html'),
);
foreach ($refused as $label => $file) {
    $r = $speech->saveAndTranscribeAudio($file, 9, 42, null, null, 'en', true);
    check("$label: refused as invalid audio", $r['success'] === false && $r['error_type'] === 'invalid_audio'
        && $r['error'] === 'Invalid audio format. Supported: WebM/Opus, OGG/Opus, M4A/MP4, MP3, FLAC');
}

$missing = upload(sample('webm'));
unlink($missing['tmp_name']);
$r = $speech->saveAndTranscribeAudio($missing, 9, 42, null, null, 'en', true);
check('a file that is not there is invalid audio', $r['success'] === false && $r['error_type'] === 'invalid_audio');
$r = $speech->saveAndTranscribeAudio(array('name' => 'x', 'error' => 0), 9, 42, null, null, 'en', true);
check('an upload without tmp_name is invalid audio', $r['success'] === false && $r['error_type'] === 'invalid_audio');
$r = $speech->saveAndTranscribeAudio(upload('', 'audio/webm'), 9, 42, null, null, 'en', true);
check('an empty file is invalid audio', $r['success'] === false && $r['error_type'] === 'invalid_audio' && $r['error'] === 'Audio file is empty');
$r = $speech->saveAndTranscribeAudio(upload(sample('webm'), 'audio/webm', LLM_MAX_AUDIO_SIZE + 1), 9, 42, null, null, 'en', true);
check('an oversized file is invalid audio', $r['success'] === false && $r['error_type'] === 'invalid_audio' && strpos($r['error'], 'too large') !== false);

check('refused files were not stored', count(stored(9)) === $files_before);
check('refused files were not sent to the model', count($speech->transcribed) === $sent_before);
check('refused files did not spend the budget', $services->cache->rate['speech_9']['requests'] === 1);

$r = $speech->saveAndTranscribeAudio(upload(sample('webm'), 'audio/webm', LLM_MAX_AUDIO_SIZE), 9, 42, null, null, 'en', false);
check('a file of exactly the maximum size is accepted (and charged)', $r['success'] === true
    && $services->cache->rate['speech_9']['requests'] === 2);

/* =========================================================================
 * The speech budget
 * ========================================================================= */

$limit = LLM_RATE_LIMIT_SPEECH_REQUESTS_PER_MINUTE;
$speech = new SpeechUnderTest($services);

for ($i = 1; $i <= $limit; $i++) {
    $r = $speech->saveAndTranscribeAudio(upload(sample('webm')), 20, 42, null, null, 'en', false);
    if ($r['success'] !== true) {
        break;
    }
}
check("the first $limit recordings of a user within the minute are transcribed", $i === $limit + 1 && count($speech->transcribed) === $limit);

$files_before = count(stored(20));
$over = $speech->saveAndTranscribeAudio(upload(sample('webm')), 20, 42, null, null, 'en', true);
check('the next one is refused as rate limited', $over['success'] === false && $over['error_type'] === 'rate_limited');
check('...with a message for the participant and a retry hint', strpos($over['error'], 'voice recordings') !== false
    && $over['retry_after'] === LLM_RATE_LIMIT_COOLDOWN_SECONDS);
check('...nothing was stored or sent to the model', count(stored(20)) === $files_before && count($speech->transcribed) === $limit);
check('...and it maps to HTTP 429', LlmSpeechToTextService::failureStatus($over) === 429);
check('a refused request is not counted', $services->cache->rate['speech_20']['requests'] === $limit);

$bad = $speech->saveAndTranscribeAudio(upload(sample('html')), 20, 42, null, null, 'en', true);
check('a user over budget sending junk is told it is junk (checked before the budget)', $bad['error_type'] === 'invalid_audio');

$other = $speech->saveAndTranscribeAudio(upload(sample('webm')), 21, 42, null, null, 'en', false);
check('another user is not affected', $other['success'] === true);

// junk does not eat the budget of a user that then sends real recordings
for ($i = 0; $i < 20; $i++) {
    $speech->saveAndTranscribeAudio(upload(sample('html')), 30, 42, null, null, 'en', false);
}
check('junk requests leave the budget untouched', !isset($services->cache->rate['speech_30']));
$ok = 0;
for ($i = 0; $i < $limit; $i++) {
    $r = $speech->saveAndTranscribeAudio(upload(sample('webm')), 30, 42, null, null, 'en', false);
    $ok += $r['success'] === true ? 1 : 0;
}
check("...so all $limit real recordings of that user still go through", $ok === $limit);

// a failing transcription still counts: the model call was made
$speech->transcribe_result = array('success' => false, 'error' => 'Speech recognition service error');
$r = $speech->saveAndTranscribeAudio(upload(sample('webm')), 40, 42, null, null, 'en', false);
check('a failed transcription is no invalid-audio refusal (caller default status)', $r['success'] === false
    && !isset($r['error_type']) && LlmSpeechToTextService::failureStatus($r) === null);
check('...but the model was called, so it is charged', $services->cache->rate['speech_40']['requests'] === 1);

fwrite(STDOUT, "\n$total checks, $failed failed\n");
exit($failed === 0 ? 0 : 1);
