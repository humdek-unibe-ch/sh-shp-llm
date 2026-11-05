<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone tests for the built-in participant texts of the llmFormLogList
 * interview (plugin v1.9.0): "ss" instead of "ß" for a Swiss locale, and the
 * author's `llm_interview_labels` used exactly as written.
 *
 * No bootstrap, no database: the real model class runs with a bypassed
 * constructor and stubbed CMS fields.
 *
 * Run: php tests/LlmInterviewLabelsTest.php
 */

define('LLM_PATH', dirname(__DIR__) . '/server');
$core_server = dirname(LLM_PATH, 3);

foreach (array(
    'ENTRY_RECORD_ID' => 'record_id',
    'USER_INPUT_HISTORY_LOG_COL' => '_history_log',
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

// The core files end with stray newlines after their closing tags.
ob_start();
require_once $core_server . '/service/PageDb.php';
require_once $core_server . '/component/style/formField/FormFieldComponent.php';
require_once LLM_PATH . '/component/style/llmFormLogList/LlmFormLogListModel.php';
ob_end_clean();

$_SESSION = array('project' => 'test');

/** The real label logic with stubbed CMS fields and UI language. */
class LabelsModel extends LlmFormLogListModel
{
    private $stub_fields;
    private $stub_language;

    public function __construct($language, array $fields = array())
    {
        $this->stub_language = $language;
        $this->stub_fields = $fields;
    }

    public function get_db_field($name, $default = '')
    {
        return array_key_exists($name, $this->stub_fields) ? $this->stub_fields[$name] : $default;
    }

    public function getUserLanguage() { return $this->stub_language; }
}

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

/* ---- German outside Switzerland ------------------------------------------ */

$_SESSION['user_language_locale'] = 'de-DE';
$de = (new LabelsModel('de'))->getInterviewLabels();
check('German outside CH keeps ß', $de['close'] === 'Schließen');

/* ---- Swiss locale ---------------------------------------------------------- */

$_SESSION['user_language_locale'] = 'de-CH';
$l = (new LabelsModel('de'))->getInterviewLabels();
check('de-CH: no ß in any built-in text', !array_filter($l, function ($t) { return strpos($t, 'ß') !== false; }));
check('de-CH: Schliessen', $l['close'] === 'Schliessen');
check('de-CH: same keys as outside CH', array_keys($l) === array_keys($de));

/* ---- author labels win, as written ----------------------------------------- */

$l = (new LabelsModel('de', array(
    'llm_interview_labels' => json_encode(array('close' => 'Schließen', 'collectedHint' => 'Ihre Antworten erscheinen hier.')),
)))->getInterviewLabels();
check('author label wins over the built-in text', $l['collectedHint'] === 'Ihre Antworten erscheinen hier.');
check('author label is not respelled for de-CH', $l['close'] === 'Schließen');

/* ---- other languages are unaffected ---------------------------------------- */

$_SESSION['user_language_locale'] = 'fr-CH';
$l = (new LabelsModel('fr'))->getInterviewLabels();
check('French in CH is unchanged', $l['resume'] === 'Continuer');
$_SESSION['user_language_locale'] = 'en-GB';
$l = (new LabelsModel('en'))->getInterviewLabels();
check('English is unchanged', $l['close'] === 'Close');

echo "\n" . $GLOBALS['__checks'] . ' checks, ' . count($GLOBALS['__fails']) . " failures\n";
exit(count($GLOBALS['__fails']) === 0 ? 0 : 1);
