<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/style/formUserInputLogList/FormUserInputLogListModel.php";
require_once __DIR__ . "/../../../service/LlmService.php";
require_once __DIR__ . "/../../../service/LlmLanguageUtility.php";

/**
 * Model for the llmFormLogList interview style.
 * Extends the core formUserInputLogList model so the list, quick actions,
 * history and record-selection semantics stay identical; adds the
 * interview configuration getters used by LlmFormSchemaService and the
 * interview runtime. The `llm_interview_context` and
 * `llm_conversation_scope` values are kept as the raw CMS templates from
 * fetch_section_fields - draft placeholders are resolved server-side at
 * scope-build time, while all other fields keep the core interpolation.
 */
class LlmFormLogListModel extends FormUserInputLogListModel
{
    /**
     * Raw (uninterpolated) section field contents keyed by field name, for
     * fields that must keep draft placeholders intact.
     */
    private $raw_fields = array();

    /**
     * Immutable authorized entry record captured at construction; rebuilds
     * always start from base_entry + draft, never from the mutable
     * entry_record (which already contains earlier draft overlays).
     */
    private $base_entry = array();

    /**
     * Parent/loop entry values this style was instantiated with (for
     * example the row of an enclosing `loop`). Server-trusted, never client
     * input. Every authorized record row is layered on top of it, so loop
     * variables survive the interview's base-entry rebuilds.
     */
    private $context_entry = array();

    /**
     * True only while the interview approval drives the inherited write
     * path; suppresses redirect_at_end and gates the row-id capture.
     */
    private $interview_approval = false;

    /**
     * @param object $services
     * @param int $id
     * @param array $params
     * @param number $id_page
     * @param array $entry_record
     */
    public function __construct($services, $id, $params, $id_page, $entry_record)
    {
        parent::__construct($services, $id, $params, $id_page, $entry_record);
        foreach ($this->db->fetch_section_fields($this->section_id) as $field) {
            $this->raw_fields[$field['name']] = $field['content'];
        }
        $this->context_entry = is_array($entry_record) ? $entry_record : array();
        $this->base_entry = is_array($this->entry_record) ? $this->entry_record : array();
    }

    /* LLM model parameters (same fallback chain as LlmFormModel) ************/

    /**
     * @return string Scoped model identifier; module default, then
     *  LLM_DEFAULT_MODEL.
     */
    public function getLlmModel()
    {
        $model = $this->get_db_field('llm_model', '');
        if (!empty($model)) {
            return $model;
        }
        try {
            $llmService = new LlmService($this->services);
            $defaults = $llmService->getModuleDefaults();
            if (!empty($defaults['model'])) {
                return $defaults['model'];
            }
        } catch (Exception $e) {
            // fall through to constant
        }
        return defined('LLM_DEFAULT_MODEL') ? LLM_DEFAULT_MODEL : 'gpt-oss-120b';
    }

    /** @return float LLM temperature value (0.0-2.0). */
    public function getLlmTemperature()
    {
        $temperature = $this->get_db_field('llm_temperature', '1');
        if ($temperature !== '' && $temperature !== null) {
            return floatval($temperature);
        }
        try {
            $llmService = new LlmService($this->services);
            $defaults = $llmService->getModuleDefaults();
            if ($defaults['temperature'] !== '') {
                return floatval($defaults['temperature']);
            }
        } catch (Exception $e) {
            // fall through
        }
        return floatval(defined('LLM_DEFAULT_TEMPERATURE') ? LLM_DEFAULT_TEMPERATURE : 1);
    }

    /** @return int Maximum token count per interview LLM response. */
    public function getLlmMaxTokens()
    {
        $max_tokens = $this->get_db_field('llm_max_tokens', '2048');
        if ($max_tokens !== '' && $max_tokens !== null) {
            return intval($max_tokens);
        }
        try {
            $llmService = new LlmService($this->services);
            $defaults = $llmService->getModuleDefaults();
            if ($defaults['max_tokens'] !== '') {
                return intval($defaults['max_tokens']);
            }
        } catch (Exception $e) {
            // fall through
        }
        return intval(defined('LLM_DEFAULT_MAX_TOKENS') ? LLM_DEFAULT_MAX_TOKENS : 2048);
    }

    /** @return string Reasoning effort; empty = provider default. */
    public function getLlmReasoningEffort()
    {
        $effort = $this->get_db_field('llm_reasoning_effort', 'default');
        if ($effort !== '' && $effort !== null) {
            return (string)$effort;
        }
        try {
            $llmService = new LlmService($this->services);
            $defaults = $llmService->getModuleDefaults();
            if (isset($defaults['reasoning_effort']) && $defaults['reasoning_effort'] !== '') {
                return (string)$defaults['reasoning_effort'];
            }
        } catch (Exception $e) {
            // fall through
        }
        return defined('LLM_REASONING_EFFORT_DEFAULT') ? LLM_REASONING_EFFORT_DEFAULT : '';
    }

    /* Interview configuration **********************************************/

    /**
     * Raw (uninterpolated) CMS field content. Protected seam: the
     * PromptLab preview model overrides it to substitute playground
     * values (edited prompt draft, data_config) for the stored ones.
     *
     * @return mixed raw content, or null when unset
     */
    protected function rawFieldValue($key)
    {
        return isset($this->raw_fields[$key]) ? $this->raw_fields[$key] : null;
    }

    /**
     * Raw `llm_interview_context` prompt template, with `{{field}}` draft
     * placeholders still intact for server-side resolution.
     *
     * @return string
     */
    public function getInterviewContextTemplate()
    {
        return (string)$this->rawFieldValue('llm_interview_context');
    }

    /**
     * Raw `llm_conversation_scope` template (e.g. `module:{{module_id}}`),
     * uninterpolated; resolved server-side when the interview opens.
     *
     * @return string
     */
    public function getConversationScopeTemplate()
    {
        return (string)$this->rawFieldValue('llm_conversation_scope');
    }

    /**
     * Field names referenced by `{{name}}` placeholders in the scope
     * template. They are scope-bound and therefore immutable for both LLM
     * and manual patches.
     *
     * @return array
     */
    public function getScopeBoundFieldNames()
    {
        $template = $this->getConversationScopeTemplate();
        if ($template === '') {
            return array();
        }
        if (!preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $template, $m)) {
            return array();
        }
        return array_values(array_unique($m[1]));
    }

    /**
     * All child field names, including fields whose branch currently
     * fails its condition: read-only metadata used to blank excluded
     * tokens in author templates. The children are already loaded; this
     * walk never instantiates excluded branches.
     *
     * @return array
     */
    public function getInterviewFieldNames()
    {
        $names = array();
        $this->collectFieldNames($this->children, $names);
        return array_values(array_unique($names));
    }

    private function collectFieldNames($children, array &$names)
    {
        foreach ($children as $child) {
            if (!is_object($child) || !method_exists($child, 'get_style_instance')) {
                continue;
            }
            $style = $child->get_style_instance();
            if (is_a($style, 'FormFieldComponent')) {
                $names[] = $style->get_view()->get_name_base();
                continue;
            }
            if (method_exists($child, 'get_children')) {
                $this->collectFieldNames($child->get_children(), $names);
            }
        }
    }

    /** @return bool Include the edit-record history summary in the prompt. */
    public function getInterviewIncludeHistory()
    {
        return $this->get_db_field('llm_interview_include_history', '0') === '1';
    }

    /**
     * Plain-text CMS value for React text nodes. The CMS stores `text` /
     * `textarea` fields HTML-escaped (`&` -> `&amp;`) and the core views
     * echo them raw, but React escapes by itself - so a label such as
     * `Approve & Save` would otherwise show as `Approve &amp; Save`.
     * Decoding runs to a fixed point so values double-escaped by an older
     * CMS save heal as well.
     *
     * @param string $key Field name
     * @param string $default Value when the field is unset
     * @return string
     */
    public function cmsText($key, $default = '')
    {
        $value = (string)$this->get_db_field($key, $default);
        for ($i = 0; $i < 4; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        return $value;
    }

    /**
     * @return bool Append the "answer in the participant's UI language"
     *  rule to the prompt (`llm_use_user_language`, on by default to match
     *  pre-1.6.0 behaviour; uncheck so the author context alone decides).
     */
    public function useUserLanguage()
    {
        return $this->get_db_field('llm_use_user_language', '1') === '1';
    }

    /**
     * @return string edit|always|never - when pressing the approve button
     *  first shows the confirmation dialog: `edit` (default) only when a
     *  saved entry is revised (what changed, before and after), `always`
     *  also for new entries (the record that will be saved), `never` saves
     *  with one click.
     */
    public function getInterviewConfirm()
    {
        $mode = (string)$this->get_db_field('llm_interview_confirm', 'edit');
        return in_array($mode, array('edit', 'always', 'never'), true) ? $mode : 'edit';
    }

    /** @return string stepper|cards|list|none */
    public function getInterviewProgressLayout()
    {
        return (string)$this->get_db_field('llm_interview_progress_layout', 'stepper');
    }

    /** @return string continue|new_on_open|new_after_save */
    public function getInterviewChatRetention()
    {
        return (string)$this->get_db_field('llm_interview_chat_retention', 'continue');
    }

    /** @return bool Participants may also edit the native controls. */
    public function isInterviewAllowManualEdit()
    {
        return $this->get_db_field('llm_interview_allow_manual_edit', '0') === '1';
    }

    /**
     * @return array Explicit allowlist of hidden field names the LLM may patch.
     */
    public function getInterviewWritableHiddenFields()
    {
        $names = $this->get_db_field('llm_interview_writable_hidden_fields', array());
        if (!is_array($names)) {
            $decoded = json_decode((string)$names, true);
            $names = is_array($decoded) ? $decoded : array();
        }
        $out = array();
        foreach ($names as $n) {
            if (is_string($n) && $n !== '') {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** @return string right|left|top|bottom - chat panel position relative to the form. */
    public function getInterviewChatPosition()
    {
        return (string)$this->get_db_field('llm_interview_chat_position', 'right');
    }

    /** @return string */
    public function getInterviewTitle()
    {
        return $this->cmsText('llm_interview_title', '');
    }

    /** @return string */
    public function getInterviewSubtitle()
    {
        return $this->cmsText('llm_interview_subtitle', '');
    }

    /** @return string */
    public function getInterviewInputPlaceholder()
    {
        return $this->cmsText('llm_interview_input_placeholder', 'Type your answer...');
    }

    /** @return string */
    public function getInterviewSendLabel()
    {
        return $this->cmsText('llm_interview_send_label', 'Send');
    }

    /** @return string */
    public function getInterviewApproveLabel()
    {
        return $this->cmsText('llm_interview_approve_label', 'Approve & Save');
    }

    /**
     * Built-in participant-facing strings. `{name}` placeholders are
     * filled client-side. English, German and French are built in; other
     * languages use English. The translatable `llm_interview_labels` field
     * overlays any key.
     *
     * @param string $language Two-letter UI language code
     * @return array key => text
     */
    private static function defaultInterviewLabels($language)
    {
        $en = array(
            'launcherIcon' => 'fa-flag',
            'launcherTitle' => 'Your next step',
            'launcherText' => 'Our coach guides you through a few questions. Nothing is saved until you approve.',
            'resumeText' => 'You have an unfinished draft: {done} of {total} steps done.',
            'resume' => 'Continue',
            'restart' => 'Start over',
            'restartConfirm' => 'Start over with a fresh draft? Your current answers are discarded.',
            'minimize' => 'Minimize',
            'close' => 'Close',
            'back' => 'Back',
            'thinking' => 'Thinking…',
            'coachTitle' => 'Coach',
            'coachStatus' => 'Online',
            'choose' => 'Choose an answer:',
            'mic' => 'Speak',
            'micStop' => 'Stop',
            'micBusy' => 'Transcribing…',
            'retry' => 'Try again',
            'errorEmpty' => 'Please enter a message.',
            'clearInput' => 'Clear',
            'readyToSave' => 'All set. Review your answers and save when you are ready.',
            'collectedTitle' => 'Your answers',
            'progressLabel' => 'Progress',
            'collectedHint' => 'Your answers appear here while you chat.',
            'editingHint' => 'You are editing a saved entry. Changed answers are marked.',
            'editedBadge' => 'Edited',
            'valueBefore' => 'Before',
            'valueAfter' => 'Now',
            'valueEmpty' => 'empty',
            'notAnswered' => 'Not answered yet',
            'required' => 'Required',
            'stepDone' => 'completed',
            'stepActive' => 'current question',
            'stepPending' => 'pending',
            'approveHint' => 'Answer the questions to be able to save.',
            'noChanges' => 'Nothing to save yet. Tell the coach what you would like to change.',
            'reviewTitleNew' => 'Save this entry?',
            'reviewTitleEdit' => 'Save your changes?',
            'reviewValues' => 'These values will be saved',
            'reviewChanges' => 'These changes will be saved',
            'reviewUnchanged' => 'Unchanged answers: {count}',
            'savedTitle' => 'Saved',
            'savedText' => 'Your answers were saved.',
            'unsupportedFiles' => 'File upload fields are not supported here: {fields}',
            'errorGeneric' => 'Something went wrong. Please try again.',
            'errorBusy' => 'The coach could not answer. Please try again.',
            'errorRate' => 'Too many requests. Please wait a moment.',
            'errorConflict' => 'Your draft was updated from another window.',
            'errorInvalid' => 'Some values are invalid. Please review the highlighted fields.',
            'errorSave' => 'Saving failed. Please check your answers and try again.',
        );
        if ($language === 'de') {
            return array(
                'launcherTitle' => 'Dein nächster Schritt',
                'launcherText' => 'Unser Coach führt dich durch ein paar Fragen. Gespeichert wird erst, wenn du bestätigst.',
                'resumeText' => 'Du hast einen offenen Entwurf: {done} von {total} Schritten erledigt.',
                'resume' => 'Fortsetzen',
                'restart' => 'Neu beginnen',
                'restartConfirm' => 'Mit einem neuen Entwurf beginnen? Deine bisherigen Antworten werden verworfen.',
                'minimize' => 'Minimieren',
                'close' => 'Schließen',
                'back' => 'Zurück',
                'thinking' => 'Denkt nach…',
                'coachTitle' => 'Coach',
                'coachStatus' => 'Online',
                'choose' => 'Wähle eine Antwort:',
                'mic' => 'Sprechen',
                'micStop' => 'Stopp',
                'micBusy' => 'Wird transkribiert…',
                'retry' => 'Erneut versuchen',
                'errorEmpty' => 'Bitte gib eine Nachricht ein.',
                'clearInput' => 'Leeren',
                'readyToSave' => 'Alles beantwortet. Prüfe deine Antworten und speichere, wenn du bereit bist.',
                'collectedTitle' => 'Deine Antworten',
                'progressLabel' => 'Fortschritt',
                'collectedHint' => 'Deine Antworten erscheinen hier, während du schreibst.',
                'editingHint' => 'Du bearbeitest einen gespeicherten Eintrag. Geänderte Antworten sind markiert.',
                'editedBadge' => 'Geändert',
                'valueBefore' => 'Vorher',
                'valueAfter' => 'Jetzt',
                'valueEmpty' => 'leer',
                'notAnswered' => 'Noch nicht beantwortet',
                'required' => 'Pflicht',
                'stepDone' => 'erledigt',
                'stepActive' => 'aktuelle Frage',
                'stepPending' => 'offen',
                'approveHint' => 'Beantworte die Fragen, um speichern zu können.',
                'noChanges' => 'Noch nichts zu speichern. Sag dem Coach, was du ändern möchtest.',
                'reviewTitleNew' => 'Diesen Eintrag speichern?',
                'reviewTitleEdit' => 'Deine Änderungen speichern?',
                'reviewValues' => 'Diese Werte werden gespeichert',
                'reviewChanges' => 'Diese Änderungen werden gespeichert',
                'reviewUnchanged' => 'Unveränderte Antworten: {count}',
                'savedTitle' => 'Gespeichert',
                'savedText' => 'Deine Antworten wurden gespeichert.',
                'unsupportedFiles' => 'Datei-Uploads werden hier nicht unterstützt: {fields}',
                'errorGeneric' => 'Etwas ist schiefgelaufen. Bitte versuche es erneut.',
                'errorBusy' => 'Der Coach konnte nicht antworten. Bitte versuche es erneut.',
                'errorRate' => 'Zu viele Anfragen. Bitte warte einen Moment.',
                'errorConflict' => 'Dein Entwurf wurde in einem anderen Fenster aktualisiert.',
                'errorInvalid' => 'Einige Angaben sind ungültig. Bitte prüfe die markierten Felder.',
                'errorSave' => 'Speichern fehlgeschlagen. Bitte prüfe deine Antworten und versuche es erneut.',
            ) + $en;
        }
        if ($language === 'fr') {
            return array(
                'launcherTitle' => 'Votre prochaine étape',
                'launcherText' => 'Notre coach vous guide à travers quelques questions. Rien n\'est enregistré avant votre confirmation.',
                'resumeText' => 'Vous avez un brouillon en cours : {done} sur {total} étapes terminées.',
                'resume' => 'Continuer',
                'restart' => 'Recommencer',
                'restartConfirm' => 'Recommencer avec un nouveau brouillon ? Vos réponses actuelles seront supprimées.',
                'minimize' => 'Réduire',
                'close' => 'Fermer',
                'back' => 'Retour',
                'thinking' => 'Réflexion en cours…',
                'coachTitle' => 'Coach',
                'coachStatus' => 'En ligne',
                'choose' => 'Choisissez une réponse :',
                'mic' => 'Parler',
                'micStop' => 'Arrêter',
                'micBusy' => 'Transcription en cours…',
                'retry' => 'Réessayer',
                'errorEmpty' => 'Veuillez saisir un message.',
                'clearInput' => 'Effacer',
                'readyToSave' => 'Tout est rempli. Vérifiez vos réponses et enregistrez quand vous êtes prêt.',
                'collectedTitle' => 'Vos réponses',
                'progressLabel' => 'Progression',
                'collectedHint' => 'Vos réponses apparaissent ici pendant la discussion.',
                'editingHint' => 'Vous modifiez une entrée enregistrée. Les réponses modifiées sont marquées.',
                'editedBadge' => 'Modifié',
                'valueBefore' => 'Avant',
                'valueAfter' => 'Maintenant',
                'valueEmpty' => 'vide',
                'notAnswered' => 'Pas encore répondu',
                'required' => 'Obligatoire',
                'stepDone' => 'terminée',
                'stepActive' => 'question en cours',
                'stepPending' => 'en attente',
                'approveHint' => 'Répondez aux questions pour pouvoir enregistrer.',
                'noChanges' => 'Rien à enregistrer pour l\'instant. Dites au coach ce que vous souhaitez modifier.',
                'reviewTitleNew' => 'Enregistrer cette entrée ?',
                'reviewTitleEdit' => 'Enregistrer vos modifications ?',
                'reviewValues' => 'Ces valeurs seront enregistrées',
                'reviewChanges' => 'Ces modifications seront enregistrées',
                'reviewUnchanged' => 'Réponses inchangées : {count}',
                'savedTitle' => 'Enregistré',
                'savedText' => 'Vos réponses ont été enregistrées.',
                'unsupportedFiles' => 'Les champs de téléversement de fichiers ne sont pas pris en charge ici : {fields}',
                'errorGeneric' => 'Une erreur s\'est produite. Veuillez réessayer.',
                'errorBusy' => 'Le coach n\'a pas pu répondre. Veuillez réessayer.',
                'errorRate' => 'Trop de requêtes. Veuillez patienter un instant.',
                'errorConflict' => 'Votre brouillon a été mis à jour depuis une autre fenêtre.',
                'errorInvalid' => 'Certaines valeurs sont invalides. Vérifiez les champs signalés.',
                'errorSave' => 'L\'enregistrement a échoué. Vérifiez vos réponses et réessayez.',
            ) + $en;
        }
        return $en;
    }

    /**
     * Participant-facing strings: built-in defaults for the UI language,
     * overlaid by the translatable `llm_interview_labels` JSON field.
     *
     * @return array key => text
     */
    public function getInterviewLabels()
    {
        $labels = self::defaultInterviewLabels($this->getUserLanguage());
        $custom = $this->get_db_field('llm_interview_labels', '');
        if (!is_array($custom)) {
            $custom = json_decode((string)$custom, true);
        }
        if (is_array($custom)) {
            foreach ($custom as $key => $text) {
                if (is_string($key) && is_scalar($text) && (string)$text !== '') {
                    $labels[$key] = (string)$text;
                }
            }
        }
        return $labels;
    }

    /**
     * Bootstrap-neutral default bubble appearance (the author themes it
     * through the shared `llm_chat_appearance` JSON field).
     *
     * @return array Per side: bg, text, border, icon (FontAwesome),
     *  iconMobile (Ionic), iconImage (custom URL).
     */
    public static function getDefaultInterviewAppearance()
    {
        return array(
            'user' => array(
                'bg' => '#007bff',
                'text' => '#ffffff',
                'border' => '#007bff',
                'icon' => 'fa-user',
                'iconMobile' => 'person-circle',
                'iconImage' => '',
            ),
            'ai' => array(
                'bg' => '#f1f3f5',
                'text' => '#212529',
                'border' => '#dee2e6',
                'icon' => 'fa-robot',
                'iconMobile' => 'chatbubble-ellipses',
                'iconImage' => '',
            ),
        );
    }

    /**
     * Chat bubble appearance, same JSON contract as llmChat's
     * `llm_chat_appearance`: partial overrides are merged per side and key
     * over the defaults; an explicit empty string clears a key (for
     * example `iconImage`, to fall back to the icon class).
     *
     * @return array ['user' => [...], 'ai' => [...]]
     */
    public function getInterviewChatAppearance()
    {
        $defaults = self::getDefaultInterviewAppearance();
        $raw = $this->get_db_field('llm_chat_appearance', '');
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $out = array();
        foreach ($defaults as $role => $default_entry) {
            $override = isset($decoded[$role]) && is_array($decoded[$role]) ? $decoded[$role] : array();
            $merged = $default_entry;
            foreach ($default_entry as $key => $_default) {
                if (isset($override[$key]) && is_scalar($override[$key])) {
                    $merged[$key] = (string)$override[$key];
                }
            }
            if ($merged['iconImage'] !== '') {
                $merged['iconImage'] = $this->normalizeIconUrl($merged['iconImage']);
            }
            $out[$role] = $merged;
        }
        return $out;
    }

    /**
     * Absolute and data URLs pass through; root-relative paths get the
     * BASE_PATH prefix so they resolve on sub-directory installs.
     */
    private function normalizeIconUrl($url)
    {
        if (preg_match('~^(https?:|data:|blob:)~i', $url)) {
            return $url;
        }
        if ($url[0] === '/') {
            $base = defined('BASE_PATH') ? BASE_PATH : '';
            return rtrim($base, '/') . $url;
        }
        return $url;
    }

    /** @return bool Speech-to-text needs the flag and a configured audio model. */
    public function isInterviewSpeechEnabled()
    {
        return $this->get_db_field('enable_speech_to_text', '0') === '1'
            && $this->getInterviewSpeechModel() !== '';
    }

    /** @return string Whisper model identifier used for the transcription. */
    public function getInterviewSpeechModel()
    {
        return trim((string)$this->get_db_field('speech_to_text_model', ''));
    }

    /**
     * Get the section ID.
     *
     * @return int Section ID
     */
    public function get_section_id()
    {
        return $this->section_id;
    }

    /**
     * Server-only: replace the immutable base entry with the freshly
     * authorized record so new threads seed from the live row rather than
     * the entry captured for the initial URL render. The parent/loop
     * context is kept underneath: a create scope (empty record) still sees
     * the loop variables, and a saved row wins over a context value of the
     * same name.
     */
    public function setInterviewBaseEntry(array $entry)
    {
        $merged = array_merge($this->context_entry, $entry);
        $this->base_entry = $merged;
        $this->set_entry_record($merged);
    }

    /**
     * @return array Parent/loop entry values the style was instantiated with.
     */
    public function getInterviewContextEntry()
    {
        return $this->context_entry;
    }

    /**
     * Server-only: enabled while the interview approval runs the
     * inherited write path. Suppresses redirect_at_end so the approval
     * answers JSON instead of header()+die() - including on validation
     * failure, where the core would otherwise redirect before the caller
     * could roll back.
     */
    public function setInterviewApprovalMode($on)
    {
        $this->interview_approval = (bool)$on;
    }

    /**
     * @return bool Whether an interview approval write is in flight.
     */
    public function isInterviewApprovalMode()
    {
        return $this->interview_approval;
    }

    /**
     * redirect_at_end resolves empty during an approval write; every other
     * field delegates to the core unchanged.
     */
    public function get_db_field($key, $default = "")
    {
        if ($this->interview_approval && $key === 'redirect_at_end') {
            return '';
        }
        return parent::get_db_field($key, $default);
    }

    /**
     * Record id used to capture the exact created/updated dataRows id in
     * the approval-only save/update wrappers.
     */
    private $approved_record_id = null;

    /**
     * Same as the core list form: in page mode a deep-linked record
     * (`?record=`) shows the form only, never the list underneath; with
     * `show_form_in_modal` the interview is a real modal and the list stays
     * on the page behind it.
     *
     * @return bool
     */
    public function should_show_list()
    {
        if ($this->is_record_access_denied() || !$this->get_db_field('show_list', 1)) {
            return false;
        }
        if ($this->isInterviewModal()) {
            return true;
        }
        return $this->get_url_record_raw() === null;
    }

    /** @return bool The interview opens in a real modal window (`show_form_in_modal`). */
    public function isInterviewModal()
    {
        return (bool)(int)$this->get_db_field('show_form_in_modal', 0);
    }

    /**
     * CMS save hook: keep the data table display name in sync with the
     * form `name`. The core CMS only does this for its own form styles
     * (hard-coded in CmsUpdateController), so plugin styles do it here.
     *
     * @param object $model CMS model
     * @param array $data Posted fields: name => language => gender => [content]
     */
    public function cms_post_update_callback($model, $data)
    {
        parent::cms_post_update_callback($model, $data);
        if (!isset($data['name']) || !is_array($data['name'])) {
            return;
        }
        foreach ($data['name'] as $lang_entries) {
            if (!is_array($lang_entries)) {
                continue;
            }
            foreach ($lang_entries as $gender_entry) {
                if (is_array($gender_entry) && isset($gender_entry['content'])
                    && trim((string)$gender_entry['content']) !== ''
                ) {
                    $this->set_dataTables_displayName((int)$this->section_id, (string)$gender_entry['content']);
                    return;
                }
            }
        }
    }

    /**
     * Approval-only wrappers: capture the exact row id returned by the
     * inherited save/update so the runtime can bind the conversation,
     * audit messages and the approval receipt to it.
     */
    public function save_user_input($user_input)
    {
        $res = parent::save_user_input($user_input);
        if ($this->interview_approval && $res !== false && (int)$res > 0) {
            $this->approved_record_id = (int)$res;
        }
        return $res;
    }

    public function update_user_input($user_input, $record_id)
    {
        $res = parent::update_user_input($user_input, $record_id);
        if ($this->interview_approval && $res !== false && (int)$res > 0) {
            $this->approved_record_id = (int)$res;
        }
        return $res;
    }

    /**
     * @return int|null The dataRows id produced by the approval write.
     */
    public function get_approved_record_id()
    {
        return $this->approved_record_id;
    }

    /**
     * Alias used by the interview runtime for the configured model id.
     *
     * @return string
     */
    public function getConfiguredModel()
    {
        return $this->getLlmModel();
    }

    /**
     * Two-letter code of the participant's UI language.
     *
     * @return string
     */
    public function getUserLanguage()
    {
        return LlmLanguageUtility::getUserLanguageCode('en');
    }

    /* Interview draft handling **********************************************/

    /**
     * Rebuild the child tree against the authorized entry record overlaid
     * with the current draft values. Children are cleared and re-created
     * through the existing loadChildren() path so each child condition
     * (including conditionalContainer/conditionFailed branches) is
     * recomputed fresh against the draft. No rows are written and the
     * component/model are not reinstantiated.
     *
     * @param array $draft name => value map of accepted draft values
     */
    public function rebuildInterviewChildren(array $draft = array())
    {
        if ($this->is_cms_page()) {
            return;
        }
        $this->rebuildInterviewChildrenForDraft($draft);
    }

    /**
     * The rebuild itself, without the CMS guard: protected so the
     * read-only PromptLab preview model can run the same eligibility
     * calculation inside a CMS session.
     */
    protected function rebuildInterviewChildrenForDraft(array $draft = array())
    {
        $entry = $this->base_entry;
        if ($draft) {
            $entry = array_merge($entry, $draft);
        }
        $this->set_entry_record($entry);
        $this->children = array();
        $this->calcRawCondition();
        $condition = $this->get_condition_result();
        if (!isset($condition['result']) || $condition['result']) {
            $this->loadChildren();
        }
    }

    /**
     * Recompute the root condition from the raw stored condition field -
     * not db_fields['condition'], which initialize() already interpolated
     * against the original entry record. The raw JSON runs through the
     * same calc_dynamic_values pipeline the core uses (entry record,
     * globals and data_config external bindings) so conditions referencing
     * external values resolve exactly as at first render.
     *
     * @return array condition_result
     */
    private function calcRawCondition()
    {
        $raw = trim((string)$this->rawFieldValue('condition'));
        $condition = null;
        if ($raw !== '') {
            $resolved = $this->calc_dynamic_values(
                array('name' => 'condition', 'content' => $raw),
                $this->resolvedDataConfig()
            );
            $decoded = json_decode($resolved, true);
            if (is_array($decoded)) {
                $condition = $decoded;
            }
        }
        $this->condition_result = $this->services->get_condition()
            ->compute_condition($condition, null, $this->get_db_field('id'));
        return $this->condition_result;
    }

    /**
     * Resolve the raw data_config field through the same interpolation
     * pipeline set_db_fields() applies (entry -> globals -> @vars ->
     * global values -> decode). Kept private: only the interview
     * re-resolution paths need it.
     *
     * @return array|null Decoded data_config, or null when absent/invalid
     */
    private function resolvedDataConfig()
    {
        $raw = $this->rawFieldValue('data_config');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $entry = is_array($this->entry_record) ? $this->entry_record : array();
        $cfg = $this->get_entry_value($entry, $raw);
        $global_vars = $this->get_global_vars();
        if (strpos($cfg, '__language__') !== false) {
            $global_vars['__language__'] = $this->db->get_user_language_id($_SESSION['id_user']);
        }
        $cfg = $this->db->replace_calced_values($cfg, $global_vars);
        $cfg = str_replace('@user_code', $this->db->get_user_code(), $cfg);
        $cfg = str_replace('@project', isset($_SESSION['project']) ? $_SESSION['project'] : '', $cfg);
        $cfg = str_replace('@user_email', $this->db->fetch_user_email(), $cfg);
        $cfg = str_replace('@user', $this->db->fetch_user_name(), $cfg);
        $global_values = $this->db->get_global_values();
        if ($global_values) {
            $cfg = $this->db->replace_calced_values($cfg, $global_values);
        }
        $decoded = json_decode($cfg, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Resolve the interview context/scope template through the core
     * interpolation pipeline: entry placeholders from the given
     * draft-overlaid map (inactive fields masked out by the caller),
     * then globals and data_config external bindings - identical to how
     * every other section field resolves.
     *
     * @param string $template Raw CMS template
     * @param array|null $interp_entry Entry values used for {{name}}
     *  substitution; defaults to the current entry record
     * @return string Resolved template
     */
    public function resolveInterviewTemplate($template, array $interp_entry = null)
    {
        if (!is_string($template) || trim($template) === '') {
            return '';
        }
        $previous = $this->entry_record;
        if ($interp_entry !== null) {
            // Loop/parent variables stay resolvable (e.g. a scope variable
            // that is not a form field); explicit entries override them.
            $this->entry_record = array_merge($this->context_entry, $interp_entry);
        }
        $resolved = $this->calc_dynamic_values(
            array('name' => 'llm_interview_context', 'content' => $template),
            $this->resolvedDataConfig()
        );
        $this->entry_record = $previous;
        return $resolved;
    }
}
?>
