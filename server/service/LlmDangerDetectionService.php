<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/prompt/LlmPromptAssetLoader.php';
require_once __DIR__ . '/LlmSafetyConfigService.php';

/**
 * LLM Danger Detection Service
 *
 * Provides danger word detection functionality for the LLM Chat plugin.
 * This is a critical safety feature that is always on. Keywords, notification
 * emails and the blocked message come from LlmSafetyConfigService (section value,
 * else the global LLM settings, else the built-in defaults). It:
 *
 * 1. Scans user messages for dangerous keywords before AI processing
 * 2. Blocks messages containing dangerous content
 * 3. Sends immediate email notifications via SelfHelp JobScheduler
 * 4. Logs all detections to the transactions table for audit
 * 5. Provides context injection text for LLM safety instructions
 *
 * @author SelfHelp Team
 */
class LlmDangerDetectionService extends BaseLlmService
{
    /**
     * Other words allowed between two words of a keyword phrase
     * ("kill really myself" still matches the keyword "kill myself")
     */
    const PHRASE_MAX_GAP_WORDS = 1;

    /**
     * @var object Model instance for configuration access
     */
    private $model;

    /**
     * @var LlmSafetyConfigService Resolves keywords, emails and the blocked message
     */
    private $safety_config;

    /**
     * @var array Cached parsed keywords
     */
    private $keywords_cache = null;
    private $prompt_assets;

    /**
     * Constructor
     *
     * @param object $services SelfHelp services container
     * @param object $model Style model (section id and raw danger fields)
     * @param object|null $safety_config Settings resolver; defaults to LlmSafetyConfigService for $model
     */
    public function __construct($services, $model, $safety_config = null)
    {
        parent::__construct($services);
        $this->model = $model;
        $this->safety_config = $safety_config ?: new LlmSafetyConfigService($services, $model);
        $this->prompt_assets = new LlmPromptAssetLoader();
    }

    /* Public Methods *********************************************************/

    /**
     * Check a message for danger keywords
     * 
     * This is the main entry point for danger detection. It:
     * 1. Scans the message for configured danger keywords
     * 2. If detected, blocks the conversation, logs the detection and sends notifications
     * 3. Returns a result array indicating if the message is safe
     * 
     * @param string $message The user message to check
     * @param int $user_id The user ID
     * @param int|null $conversation_id The conversation ID (may be null for new conversations)
     * @return array Detection result with keys:
     *               - 'safe' (bool): True if message is safe
     *               - 'message' (string|null): Safety message if blocked
     *               - 'detected_keywords' (array): List of detected keywords
     */
    public function checkMessage($message, $user_id, $conversation_id = null)
    {
        $detected = $this->detectKeywords($message);

        if (empty($detected)) {
            return [
                'safe' => true,
                'message' => null,
                'detected_keywords' => []
            ];
        }

        return $this->handleDetection($detected, $message, $user_id, $conversation_id);
    }

    /**
     * Scan a message for the configured danger keywords without any side effect
     * (no blocking, audit log or email), so callers that hold a database lock can
     * decide first and run handleDetection() after releasing it.
     *
     * @param string $message The user message to scan
     * @return array Detected keywords (empty when safe or when no keywords exist)
     */
    public function detectKeywords($message)
    {
        $keywords = $this->getKeywords();
        if (empty($keywords)) {
            return [];
        }

        return $this->scanMessage($message, $keywords);
    }

    /**
     * Apply the consequences of a keyword detection: block the conversation (if it
     * exists), write the audit log and send the alert emails.
     *
     * @param array $detected Keywords returned by detectKeywords()
     * @param string $message The user message that triggered the detection
     * @param int $user_id The user ID
     * @param int|null $conversation_id The conversation ID (may be null for new conversations)
     * @return array Blocked result, same shape as checkMessage() with 'safe' => false
     */
    public function handleDetection($detected, $message, $user_id, $conversation_id = null)
    {
        $section_id = $this->model->getSectionId();

        // Block the conversation if it exists
        if ($conversation_id) {
            $this->blockConversation($conversation_id, $detected, 'Automatic: Danger keywords detected');
        }

        // Log to transactions table
        $this->logDetection($detected, $message, $user_id, $conversation_id, $section_id);

        // Send email notifications
        $notification_sent = $this->sendNotifications($detected, $message, $user_id, $conversation_id, $section_id);

        // Return blocked result
        return [
            'safe' => false,
            'message' => $this->safety_config->getBlockedMessage(),
            'detected_keywords' => $detected,
            'notification_sent' => $notification_sent,
            'conversation_blocked' => ($conversation_id !== null)
        ];
    }

    /**
     * Markdown message shown to the participant when danger is detected
     * (section value, else the global LLM settings, else the built-in default).
     *
     * @return string Never empty
     */
    public function getBlockedMessage()
    {
        return $this->safety_config->getBlockedMessage();
    }

    /**
     * Get the critical safety instruction for LLM context injection
     * 
     * This provides a non-overridable safety instruction that is injected
     * into the LLM's system context. Even if the controller-level check
     * fails, this ensures the LLM will refuse to engage with dangerous content.
     * 
     * @return string|null The safety instruction, or null when no keywords exist
     */
    public function getCriticalSafetyContext()
    {
        $keywords = $this->getKeywords();
        if (empty($keywords)) {
            return null;
        }

        $keywords_str = implode(', ', $keywords);

        $template = $this->prompt_assets->load('core.danger_detection.critical_safety');
        return strtr($template, array('{{keywords_list}}' => $keywords_str));
    }

    /**
     * Get parsed danger keywords
     * 
     * @return array Array of lowercase keywords
     */
    public function getKeywords()
    {
        if ($this->keywords_cache !== null) {
            return $this->keywords_cache;
        }

        // Lowercased (UTF-8 aware, so "töten" matches "TÖTEN"), trimmed and deduplicated
        $this->keywords_cache = $this->safety_config->getKeywords();
        return $this->keywords_cache;
    }

    /**
     * Block a conversation due to danger detection
     * 
     * @param int $conversation_id Conversation ID to block
     * @param array $detected_keywords Keywords that were detected
     * @param string $reason Reason for blocking
     * @return bool True if blocked successfully
     */
    public function blockConversation($conversation_id, $detected_keywords, $reason)
    {
        try {
            $keywords_str = implode(', ', $detected_keywords);
            $full_reason = $reason . ': ' . $keywords_str;
            
            $result = $this->db->update_by_ids(
                'llmConversations',
                [
                    'blocked' => 1,
                    'blocked_reason' => $full_reason,
                    'blocked_at' => date('Y-m-d H:i:s'),
                    'blocked_by' => null // NULL indicates automatic blocking
                ],
                ['id' => $conversation_id]
            );
            
            if ($result) {
                $this->logInfo('Blocked conversation', ['conversation_id' => $conversation_id, 'keywords' => $keywords_str]);
            }
            
            return $result;
        } catch (Exception $e) {
            $this->logError('Failed to block conversation', $e);
            return false;
        }
    }

    /**
     * Check if one of the user's own conversations is blocked.
     *
     * The lookup is scoped to the acting user, so the answer cannot be used to
     * find out whether somebody else's conversation id is blocked. A database
     * error is never turned into "not blocked": it is logged and thrown, and the
     * caller decides how to stop (the chat answers with its generic error).
     *
     * @param int $conversation_id Conversation ID
     * @param int $user_id Acting user
     * @return bool True if the user's conversation is blocked
     * @throws Exception When the lookup fails
     */
    public function isConversationBlocked($conversation_id, $user_id)
    {
        if (!$conversation_id || !$user_id) {
            return false;
        }

        try {
            $conversation = $this->db->query_db_first(
                'SELECT blocked FROM llmConversations WHERE id = :id AND id_users = :id_users',
                ['id' => $conversation_id, 'id_users' => $user_id]
            );

            return ($conversation && $conversation['blocked'] == 1);
        } catch (Exception $e) {
            $this->logError('Failed to check if conversation is blocked', $e);
            throw $e;
        }
    }

    /**
     * Process safety detection from a parsed LLM response.
     *
     * Assesses safety, logs critical detections to the transaction table,
     * blocks conversations for critical/emergency levels, and sends
     * email notifications when intervention is required.
     *
     * @param LlmResponseService $response_service Response service for safety assessment
     * @param array $parsed_response Parsed structured response from LLM
     * @param int $conversation_id Conversation ID
     * @param int $user_id User ID
     * @param int $section_id Section ID
     */
    public function processSafetyDetection($response_service, $parsed_response, $conversation_id, $user_id, $section_id)
    {
        $safety = $response_service->assessSafety($parsed_response);

        if (!$safety['assessed']) {
            // No model-produced assessment for this turn: "unknown", not "safe".
            // Keyword detection (checkMessage) is the only check that ran, so leave a trace.
            $this->logSafetyUnavailable($user_id, $conversation_id, $section_id);
            return;
        }

        if ($safety['is_safe'] && $safety['danger_level'] === null) {
            return;
        }

        $detected_concerns = $safety['detected_concerns'];
        $is_critical = in_array($safety['danger_level'], ['critical', 'emergency']);

        if (!empty($detected_concerns) && $is_critical) {
            $this->logSafetyDetectionToTransaction($detected_concerns, $user_id, $conversation_id, $section_id, $safety);
        }

        if ($is_critical) {
            $reason = strtoupper($safety['danger_level']) . ': LLM detected danger: ' . implode(', ', $detected_concerns);
            $this->blockConversation($conversation_id, $detected_concerns, $reason);
        }

        $should_notify = $is_critical || $safety['requires_intervention'];
        if ($should_notify) {
            $this->sendNotifications(
                $detected_concerns,
                $safety['safety_message'] ?? 'Dangerous content detected by AI',
                $user_id,
                $conversation_id,
                $section_id
            );
        }
    }

    /**
     * Record that an LLM turn carried no safety assessment (the model omitted it or the
     * response had to be rebuilt from a failed validation), so admins can see the gap.
     *
     * @param int $user_id User ID
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     */
    private function logSafetyUnavailable($user_id, $conversation_id, $section_id)
    {
        $this->logWarning('Safety assessment unavailable for LLM response', [
            'conversation_id' => $conversation_id,
            'section_id' => $section_id
        ]);

        try {
            $this->services->get_transaction()->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_PLUGIN,
                $user_id,
                'llm_safety_unavailable',
                $section_id,
                false,
                json_encode([
                    'event' => 'llm_safety_unavailable',
                    'conversation_id' => $conversation_id,
                    'section_id' => $section_id,
                    'timestamp' => date('Y-m-d H:i:s')
                ], JSON_UNESCAPED_UNICODE)
            );
        } catch (Exception $e) {
            $this->logError('Failed to log safety-unavailable transaction', $e);
        }
    }

    /**
     * Log an LLM-detected safety concern to the transactions table.
     *
     * @param array $detected_concerns Concern categories detected by LLM
     * @param int $user_id User ID
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @param array $safety Full safety assessment result
     */
    private function logSafetyDetectionToTransaction($detected_concerns, $user_id, $conversation_id, $section_id, $safety)
    {
        try {
            $transaction = $this->services->get_transaction();

            $log_data = json_encode([
                'event' => 'llm_safety_detection',
                'detected_concerns' => $detected_concerns,
                'danger_level' => $safety['danger_level'],
                'safety_message' => $safety['safety_message'],
                'conversation_id' => $conversation_id,
                'section_id' => $section_id,
                'ai_detection' => true,
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);

            $transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_PLUGIN,
                $user_id,
                'llm_safety_detection',
                $section_id,
                false,
                $log_data
            );
        } catch (Exception $e) {
            $this->logError('Failed to log safety detection transaction', $e);
        }
    }

    /* Private Methods ********************************************************/

    /**
     * Scan a message for danger keywords
     * 
     * Single words use word-boundary matching to avoid false positives
     * (e.g. "skill" must not match "kill"). Phrases tolerate typos, see
     * phraseMatchesWithTypos(). Matching is UTF-8 aware and case-insensitive.
     * 
     * @param string $message The message to scan
     * @param array $keywords The keywords to look for
     * @return array Array of detected keywords
     */
    private function scanMessage($message, $keywords)
    {
        $detected = [];
        $message_lower = mb_strtolower($message, 'UTF-8');

        foreach ($keywords as $keyword) {
            if (strpos($keyword, ' ') !== false) {
                if ($this->phraseMatchesWithTypos($message_lower, $keyword)) {
                    $detected[] = $keyword;
                }
            } else {
                $pattern = '/\b' . preg_quote($keyword, '/') . '\b/iu';
                if (preg_match($pattern, $message_lower)) {
                    $detected[] = $keyword;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    /**
     * Check if a keyword phrase occurs in a message, tolerating typos.
     *
     * The exact phrase always matches. Otherwise every word of the phrase must match a
     * message word, in order, with at most PHRASE_MAX_GAP_WORDS other words in between.
     * A word matches when it is within a small edit distance of the keyword word (see
     * wordMatchesWithTypos()), so "kill myslef" still matches "kill myself".
     *
     * @param string $message Lowercased message
     * @param string $phrase Lowercased keyword phrase
     * @return bool True if the phrase matches (with or without typos)
     */
    private function phraseMatchesWithTypos($message, $phrase)
    {
        if (strpos($message, $phrase) !== false) {
            return true;
        }

        $phrase_words = $this->splitWords($phrase);
        if (count($phrase_words) < 2) {
            return false;
        }
        $message_words = $this->splitWords($message);
        $message_word_count = count($message_words);

        foreach ($message_words as $start => $word) {
            if (!$this->wordMatchesWithTypos($word, $phrase_words[0])) {
                continue;
            }

            $position = $start;
            $all_found = true;
            for ($i = 1; $i < count($phrase_words); $i++) {
                $found = false;
                $last_candidate = min($position + 1 + self::PHRASE_MAX_GAP_WORDS, $message_word_count - 1);
                for ($next = $position + 1; $next <= $last_candidate; $next++) {
                    if ($this->wordMatchesWithTypos($message_words[$next], $phrase_words[$i])) {
                        $position = $next;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $all_found = false;
                    break;
                }
            }

            if ($all_found) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split text into words (Unicode letters and digits).
     *
     * @param string $text Text to split
     * @return array List of words
     */
    private function splitWords($text)
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Check whether a message word equals a keyword word, allowing typos.
     *
     * Allowed edits scale with the keyword length: none up to 4 letters (short words
     * collide with ordinary ones, "kill" vs "will"), 1 from 5 letters, 2 from 9 letters.
     *
     * @param string $word Word from the message
     * @param string $target Word from the keyword phrase
     * @return bool True if the words match
     */
    private function wordMatchesWithTypos($word, $target)
    {
        if ($word === $target) {
            return true;
        }

        $target_length = mb_strlen($target, 'UTF-8');
        $max_edits = $target_length >= 9 ? 2 : ($target_length >= 5 ? 1 : 0);
        if ($max_edits === 0 || abs(mb_strlen($word, 'UTF-8') - $target_length) > $max_edits) {
            return false;
        }

        return $this->editDistance($word, $target) <= $max_edits;
    }

    /**
     * Edit distance between two UTF-8 strings: insertions, deletions, substitutions and
     * swaps of two neighbouring letters ("myslef" -> "myself" is 1 edit).
     *
     * @param string $a First string
     * @param string $b Second string
     * @return int Number of edits
     */
    private function editDistance($a, $b)
    {
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $rows = count($a);
        $cols = count($b);

        $d = [];
        for ($i = 0; $i <= $rows; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $cols; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $rows; $i++) {
            for ($j = 1; $j <= $cols; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$rows][$cols];
    }

    /**
     * Log danger detection to transactions table
     * 
     * @param array $detected_keywords Detected keywords
     * @param string $message Original user message
     * @param int $user_id User ID
     * @param int|null $conversation_id Conversation ID
     * @param int $section_id Section ID
     */
    private function logDetection($detected_keywords, $message, $user_id, $conversation_id, $section_id)
    {
        try {
            $transaction = $this->services->get_transaction();

            // Prepare verbal log with detection details (JSON format for searchability)
            $log_data = json_encode([
                'event' => 'danger_keyword_detected',
                'detected_keywords' => $detected_keywords,
                'user_message_excerpt' => mb_substr($message, 0, 200), // First 200 chars only for privacy
                'conversation_id' => $conversation_id,
                'section_id' => $section_id,
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);

            // Log to transactions table
            // Using 'llm_danger_detection' as virtual table name for easy filtering
            $transaction->add_transaction(
                transactionTypes_insert,
                TRANSACTION_BY_LLM_PLUGIN,
                $user_id,
                'llm_danger_detection', // Virtual table name for filtering
                $section_id, // Use section_id as entry_id for reference
                false,
                $log_data
            );
        } catch (Exception $e) {
            // Log error but don't fail the detection
            $this->logError('Failed to log transaction', $e);
        }
    }

    /**
     * Send email notifications for danger detection
     * 
     * Uses SelfHelp's JobScheduler with add_and_execute_job() for immediate delivery.
     * This method is public to allow the controller to send notifications when
     * the LLM detects danger through its structured response.
     * 
     * @param array $detected_keywords Detected keywords or concern categories
     * @param string $message Original user message or safety message from LLM
     * @param int $user_id User ID
     * @param int|null $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @return bool True if at least one notification was sent
     */
    public function sendNotifications($detected_keywords, $message, $user_id, $conversation_id, $section_id)
    {
        $emails = $this->safety_config->getNotificationEmails();

        if (empty($emails)) {
            return false;
        }

        // Debug logging for notification attempts
        if (defined('DEBUG') && DEBUG) {
            $this->logDebug('Sending notifications for keywords', ['keywords' => $detected_keywords]);
        }

        try {
            $job_scheduler = $this->services->get_job_scheduler();

            // Build email body
            $keywords_str = implode(', ', $detected_keywords);
            $message_excerpt = mb_substr($message, 0, 200);
            if (mb_strlen($message) > 200) {
                $message_excerpt .= '...';
            }
            $timestamp = date('Y-m-d H:i:s');

            $body = $this->buildEmailBody($keywords_str, $user_id, $conversation_id, $section_id, $timestamp, $message_excerpt);

            // Same sender the core uses for its own system emails
            $sender = $this->getSystemSenderAddress();

            // Send to each email address
            $sent = false;
            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->logWarning('Invalid email address skipped', ['email' => $email]);
                    continue;
                }

                $mail_data = [
                    'id_jobTypes' => $this->db->get_lookup_id_by_value(jobTypes, jobTypes_email),
                    'id_jobStatus' => $this->db->get_lookup_id_by_value(scheduledJobsStatus, scheduledJobsStatus_queued),
                    'date_to_be_executed' => date('Y-m-d H:i:s', time()), // Immediate execution
                    'from_email' => $sender,
                    'from_name' => 'SelfHelp Safety Alert',
                    'reply_to' => $sender,
                    'recipient_emails' => $email,
                    'subject' => '[SAFETY ALERT] Danger keyword detected in LLM conversation',
                    'body' => $body,
                    'is_html' => 1,
                    'description' => 'Danger keyword detection notification - Section: ' . $section_id
                ];

                // Schedule and immediately execute the email job
                if (defined('DEBUG') && DEBUG) {
                    $this->logDebug('Attempting to send email', ['email' => $email]);
                }
                $result = $job_scheduler->add_and_execute_job($mail_data, transactionBy_by_system);
                if ($result) {
                    $sent = true;
                    if (defined('DEBUG') && DEBUG) {
                        $this->logDebug('Email sent successfully', ['email' => $email]);
                    }
                } else {
                    $this->logWarning('Failed to send email - add_and_execute_job returned false', ['email' => $email]);
                }
            }

            if ($sent) {
                if (defined('DEBUG') && DEBUG) {
                    $this->logDebug('Email notifications completed successfully');
                }
            } else {
                $this->logWarning('No emails were sent successfully');
            }

            return $sent;
        } catch (Exception $e) {
            $this->logError('Exception while sending notifications', $e);
            return false;
        }
    }

    /**
     * Build email notification body
     *
     * The core mailer renders the body as Markdown -> HTML (safe mode off). The detected
     * terms and the message excerpt come from users or from the model, so they are
     * neutralised first (see escapeForAlert()); ids are cast to integers.
     * 
     * @param string $keywords_str Comma-separated detected keywords
     * @param int $user_id User ID
     * @param int|null $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @param string $timestamp Detection timestamp
     * @param string $message_excerpt Message excerpt (first 200 chars)
     * @return string Email body in markdown format
     */
    private function buildEmailBody($keywords_str, $user_id, $conversation_id, $section_id, $timestamp, $message_excerpt)
    {
        $keywords_str = $this->escapeForAlert($keywords_str);
        $message_excerpt = $this->escapeForAlert($message_excerpt);
        $user_id = (int) $user_id;
        $section_id = (int) $section_id;
        $conversation_str = $conversation_id ? (string) (int) $conversation_id : 'N/A (new conversation)';

        return <<<EOT
# SAFETY ALERT - Danger Word Detection

A potentially dangerous keyword was detected in an LLM conversation.

## Detection Details

| Field | Value |
|-------|-------|
| **Detected Keywords** | {$keywords_str} |
| **User ID** | {$user_id} |
| **Conversation ID** | {$conversation_str} |
| **Section ID** | {$section_id} |
| **Detection Time** | {$timestamp} |

## Message Excerpt

> {$message_excerpt}

---

*This notification was sent by the SelfHelp LLM plugin danger detection system.*

*Please review the conversation and take appropriate action if needed.*

*The user has been shown a safety message and the conversation was blocked.*
EOT;
    }

    /**
     * Neutralise untrusted text for the alert email: collapsed to one line and wrapped in a
     * Markdown code span, which the mailer HTML-escapes and which is never parsed for
     * links, emphasis, tags, headings or table cells.
     *
     * @param string $text Untrusted text
     * @return string Code span that renders the text literally
     */
    private function escapeForAlert($text)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        $text = str_replace('`', "'", $text);

        return '`' . ($text === '' ? '-' : $text) . '`';
    }

    /**
     * Sender address for safety alerts: the address the core system uses for its own
     * emails (CMS `email` page, "activate email" sender), or the core default
     * noreply@<host> when none is configured.
     *
     * @return string From / reply-to email address
     */
    private function getSystemSenderAddress()
    {
        $email_page = $this->db->fetch_page_info(SH_EMAIL);
        $from = isset($email_page[PF_EMAIL_ACTIVATE_EMAIL_ADDRESS]) ? trim($email_page[PF_EMAIL_ACTIVATE_EMAIL_ADDRESS]) : '';
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $from;
        }

        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            $host = 'localhost';
        }
        return 'noreply@' . $host;
    }
}
?>

