<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Progress Tracking Service
 */

// Include utility classes
require_once __DIR__ . "/LlmLanguageUtility.php";

/**
 * Handles progress calculation for context coverage in LLM conversations.
 * 
 * IMPORTANT: Progress is tracked based on USER QUESTIONS ONLY, not AI responses.
 * This ensures that the AI mentioning topics doesn't count as "coverage" - only
 * when the USER explicitly asks about a topic does it count.
 * 
 * The progress tracking system works as follows:
 * 1. Context is parsed to extract "topics" using explicit [TOPIC] markers
 * 2. Each USER message is analyzed to determine which topics were asked about
 * 3. Progress is calculated as a percentage of topics the user has explored
 * 4. Progress is ALWAYS incremental - can only increase, never decrease
 * 
 * Topic Definition Format (in conversation context):
 * 
 * [TOPIC:id="arsenal" name="Arsenal FC" keywords="arsenal,gunners,emirates"]
 * Content about Arsenal...
 * [/TOPIC]
 * 
 * Or simplified format:
 * [TOPIC: Arsenal FC | arsenal, gunners, emirates]
 * 
 * Coverage Calculation:
 * - A topic is "covered" when the USER asks about it (keyword match in user message)
 * - Depth: Multiple user questions about the same topic increase coverage depth
 * - The algorithm ensures monotonic progress (only increases)
 * 
 * @author SelfHelp Team
 */
class LlmProgressTrackingService
{
    private $services;
    private $db;

    /**
     * Constructor
     * 
     * @param object $services The services object
     */
    public function __construct($services)
    {
        $this->services = $services;
        $this->db = $services->get_db();
    }

    /**
     * Extract topics from conversation context
     * 
     * Supports multiple formats:
     * 
     * 1. Explicit TOPIC markers (RECOMMENDED):
     *    [TOPIC: Topic Name | keyword1, keyword2, keyword3]
     *    or
     *    [TOPIC:id="topic_id" name="Topic Name" keywords="kw1,kw2,kw3"]
     * 
     * 2. YAML-like format in a TOPICS section:
     *    ## TRACKABLE_TOPICS
     *    - name: Arsenal FC
     *      keywords: arsenal, gunners, emirates
     *    - name: Chelsea FC
     *      keywords: chelsea, blues, stamford bridge
     * 
     * 3. Fallback: Bold text extraction (less reliable)
     * 
     * NOTE: Context may be in Markdown OR HTML format (converted by SelfHelp).
     * We handle both cases by also checking for HTML tags.
     * 
     * @param string $context Raw conversation context
     * @return array Array of topic objects
     */
    public function extractTopicsFromContext($context)
    {
        if (empty($context)) {
            return [];
        }

        $topics = [];

        // Method 1: Look for explicit [TOPIC: ...] markers (RECOMMENDED)
        $topics = $this->extractExplicitTopicMarkers($context);
        
        if (!empty($topics)) {
            return $topics;
        }

        // Method 2: Look for TRACKABLE_TOPICS section (Markdown format)
        $topics = $this->extractFromTrackableTopicsSection($context);
        
        if (!empty($topics)) {
            return $topics;
        }

        // Method 3: Look for TRACKABLE_TOPICS section (HTML format)
        // SelfHelp converts markdown to HTML, so we need to handle this case
        $topics = $this->extractFromHtmlTrackableTopicsSection($context);
        
        if (!empty($topics)) {
            return $topics;
        }

        // Method 4: Look for plain text TRACKABLE_TOPICS section
        // Handle case where TRACKABLE_TOPICS is just plain text without markdown/HTML heading
        $topics = $this->extractFromPlainTextTrackableTopicsSection($context);
        
        if (!empty($topics)) {
            return $topics;
        }

        // Method 5: Fallback to markdown extraction (less reliable)
        // Only use if no explicit topics found
        $topics = $this->extractTopicsFromMarkdownFallback($context);

        return $topics;
    }

    /**
     * Extract topics from explicit [TOPIC: ...] markers
     * 
     * Supported formats:
     * [TOPIC: Topic Name | keyword1, keyword2, keyword3]
     * [TOPIC:id="myid" name="Topic Name" keywords="kw1,kw2"]
     * 
     * @param string $context Context string
     * @return array Array of topics
     */
    private function extractExplicitTopicMarkers($context)
    {
        $topics = [];

        // Format 1: Simple format [TOPIC: Name | keywords]
        // Example: [TOPIC: Arsenal FC | arsenal, gunners, emirates stadium]
        if (preg_match_all('/\[TOPIC:\s*([^|\]]+)\s*\|\s*([^\]]+)\]/i', $context, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim($match[1]);
                $keywordsStr = trim($match[2]);
                $keywords = array_map('trim', explode(',', $keywordsStr));
                
                // Add the name itself as a keyword
                $keywords[] = strtolower($name);
                $keywords = array_unique(array_filter($keywords));
                
                $topics[] = [
                    'id' => $this->generateTopicId($name),
                    'title' => $name,
                    'keywords' => $keywords,
                    'weight' => 5,
                    'content' => $name
                ];
            }
        }

        // Format 2: Attribute format [TOPIC:id="..." name="..." keywords="..."]
        if (preg_match_all('/\[TOPIC:id="([^"]+)"\s+name="([^"]+)"\s+keywords="([^"]+)"\]/i', $context, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $id = trim($match[1]);
                $name = trim($match[2]);
                $keywordsStr = trim($match[3]);
                $keywords = array_map('trim', explode(',', $keywordsStr));
                
                // Add the name itself as a keyword
                $keywords[] = strtolower($name);
                $keywords = array_unique(array_filter($keywords));
                
                $topics[] = [
                    'id' => 'topic_' . $id,
                    'title' => $name,
                    'keywords' => $keywords,
                    'weight' => 5,
                    'content' => $name
                ];
            }
        }

        return $topics;
    }

    /**
     * Extract topics from a TRACKABLE_TOPICS section
     * 
     * Supports multiple formats:
     * 
     * Format 1 (YAML-like):
     * ## TRACKABLE_TOPICS
     * - name: Arsenal FC
     *   keywords: arsenal, gunners, emirates
     * 
     * Format 2 (Inline):
     * ## TRACKABLE_TOPICS
     * - Arsenal FC: arsenal, gunners, emirates
     * - Chelsea FC: chelsea, blues, stamford bridge
     * 
     * @param string $context Context string
     * @return array Array of topics
     */
    private function extractFromTrackableTopicsSection($context)
    {
        $topics = [];

        // Find TRACKABLE_TOPICS section - be more flexible with heading levels
        // Match # TRACKABLE_TOPICS, ## TRACKABLE_TOPICS, ### TRACKABLE_TOPICS
        if (preg_match('/#{1,3}\s*TRACKABLE_TOPICS\s*\r?\n([\s\S]*?)(?=\r?\n#{1,3}\s|\Z)/i', $context, $sectionMatch)) {
            $section = $sectionMatch[1];
            
            // Format 1: YAML-like entries
            // - name: Topic Name
            //   keywords: kw1, kw2, kw3
            // Allow for \r\n or \n line endings and flexible whitespace
            if (preg_match_all('/^-\s*name:\s*(.+?)[\r\n]+\s*keywords:\s*(.+?)(?=[\r\n]|$)/im', $section, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = trim($match[1]);
                    $keywordsStr = trim($match[2]);
                    $keywords = array_map('trim', explode(',', $keywordsStr));
                    
                    // Add the name itself as a keyword (lowercase)
                    $keywords[] = strtolower($name);
                    $keywords = array_unique(array_filter($keywords));
                    
                    $topics[] = [
                        'id' => $this->generateTopicId($name),
                        'title' => $name,
                        'keywords' => array_values($keywords),
                        'weight' => 5,
                        'content' => $name
                    ];
                }
            }
            
            // Format 2: Inline format (simpler)
            // - Topic Name: keyword1, keyword2, keyword3
            if (empty($topics)) {
                if (preg_match_all('/^-\s*([^:\r\n]+):\s*([^\r\n]+)/m', $section, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $name = trim($match[1]);
                        $keywordsStr = trim($match[2]);
                        
                        // Skip if this looks like YAML format (name: or keywords:)
                        if (strtolower($name) === 'name' || strtolower($name) === 'keywords') {
                            continue;
                        }
                        
                        $keywords = array_map('trim', explode(',', $keywordsStr));
                        $keywords[] = strtolower($name);
                        $keywords = array_unique(array_filter($keywords));
                        
                        $topics[] = [
                            'id' => $this->generateTopicId($name),
                            'title' => $name,
                            'keywords' => array_values($keywords),
                            'weight' => 5,
                            'content' => $name
                        ];
                    }
                }
            }
        }

        return $topics;
    }

    /**
     * Extract topics from HTML-formatted TRACKABLE_TOPICS section
     * 
     * SelfHelp converts Markdown to HTML, so the context might look like:
     * <h2>TRACKABLE_TOPICS</h2>
     * <p>name: Arsenal FC keywords: arsenal, gunners, emirates</p>
     * 
     * Or with list items:
     * <h2>TRACKABLE_TOPICS</h2>
     * <ul><li>name: Arsenal FC keywords: arsenal, gunners</li></ul>
     * 
     * @param string $context Context string (HTML)
     * @return array Array of topics
     */
    private function extractFromHtmlTrackableTopicsSection($context)
    {
        $topics = [];

        // Check if this looks like HTML content
        if (strpos($context, '<h') === false && strpos($context, '<p') === false) {
            return $topics;
        }

        // Find TRACKABLE_TOPICS section in HTML
        // Pattern: <h1|h2|h3>TRACKABLE_TOPICS</h1|h2|h3> followed by content until next heading or end
        if (preg_match('/<h[1-3][^>]*>\s*TRACKABLE_TOPICS\s*<\/h[1-3]>([\s\S]*?)(?=<h[1-3]|$)/i', $context, $sectionMatch)) {
            $section = $sectionMatch[1];
            
            // Method 1: Extract from <p> tags containing "name:" and "keywords:"
            // Pattern: <p>name: Arsenal FC keywords: arsenal, gunners</p>
            if (preg_match_all('/<p[^>]*>([^<]*name:\s*[^<]+)<\/p>/i', $section, $pMatches)) {
                foreach ($pMatches[1] as $pContent) {
                    if (preg_match('/name:\s*(.+?)\s*keywords:\s*(.+)/i', $pContent, $match)) {
                        $name = trim($match[1]);
                        $keywordsStr = trim($match[2]);
                        
                        $keywords = array_map('trim', explode(',', $keywordsStr));
                        $keywords[] = strtolower($name);
                        $keywords = array_unique(array_filter($keywords));
                        
                        if (!empty($name) && count($keywords) > 1) {
                            $topics[] = [
                                'id' => $this->generateTopicId($name),
                                'title' => $name,
                                'keywords' => array_values($keywords),
                                'weight' => 5,
                                'content' => $name
                            ];
                        }
                    }
                }
            }
            
            // Method 2: Strip HTML and look for patterns in plain text
            if (empty($topics)) {
                $plainSection = strip_tags($section);
                // Normalize whitespace
                $plainSection = preg_replace('/\s+/', ' ', $plainSection);
                
                // Look for patterns like "name: Arsenal FC keywords: arsenal, gunners"
                if (preg_match_all('/name:\s*([^:]+?)\s*keywords:\s*([^:]+?)(?=\s*name:|$)/is', $plainSection, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $name = trim($match[1]);
                        $keywordsStr = trim($match[2]);
                        
                        // Clean up the name (remove any trailing "keywords" word)
                        $name = preg_replace('/\s*keywords\s*$/i', '', $name);
                        
                        $keywords = array_map('trim', explode(',', $keywordsStr));
                        $keywords[] = strtolower($name);
                        $keywords = array_unique(array_filter($keywords));
                        
                        if (!empty($name) && count($keywords) > 1) {
                            $topics[] = [
                                'id' => $this->generateTopicId($name),
                                'title' => $name,
                                'keywords' => array_values($keywords),
                                'weight' => 5,
                                'content' => $name
                            ];
                        }
                    }
                }
            }
            
            // Method 3: Extract from list items: <li>name: X keywords: Y</li>
            if (empty($topics) && preg_match_all('/<li[^>]*>([^<]+)<\/li>/i', $section, $liMatches)) {
                foreach ($liMatches[1] as $liContent) {
                    if (preg_match('/name:\s*(.+?)\s*keywords:\s*(.+)/i', $liContent, $match)) {
                        $name = trim($match[1]);
                        $keywordsStr = trim($match[2]);
                        
                        $keywords = array_map('trim', explode(',', $keywordsStr));
                        $keywords[] = strtolower($name);
                        $keywords = array_unique(array_filter($keywords));
                        
                        if (!empty($name) && count($keywords) > 1) {
                            $topics[] = [
                                'id' => $this->generateTopicId($name),
                                'title' => $name,
                                'keywords' => array_values($keywords),
                                'weight' => 5,
                                'content' => $name
                            ];
                        }
                    }
                }
            }
        }

        return $topics;
    }

    /**
     * Extract topics from plain text TRACKABLE_TOPICS section
     * 
     * Handles case where TRACKABLE_TOPICS is just plain text without markdown/HTML heading.
     * Example:
     * TRACKABLE_TOPICS
     * name: Arsenal FC keywords: arsenal, gunners
     * name: Chelsea FC keywords: chelsea, blues
     * 
     * @param string $context Context string
     * @return array Array of topics
     */
    private function extractFromPlainTextTrackableTopicsSection($context)
    {
        $topics = [];

        // Look for TRACKABLE_TOPICS as plain text (not markdown heading, not HTML)
        // Match: "TRACKABLE_TOPICS" followed by content until another all-caps heading or end
        if (preg_match('/(?:^|\n)\s*TRACKABLE_TOPICS\s*\n([\s\S]*?)(?=\n\s*[A-Z][A-Z\s_]+\n|$)/i', $context, $sectionMatch)) {
            $section = $sectionMatch[1];
            
            // Look for "name: X keywords: Y" patterns
            if (preg_match_all('/name:\s*([^\n]+?)\s*keywords:\s*([^\n]+)/i', $section, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $name = trim($match[1]);
                    $keywordsStr = trim($match[2]);
                    
                    $keywords = array_map('trim', explode(',', $keywordsStr));
                    $keywords[] = strtolower($name);
                    $keywords = array_unique(array_filter($keywords));
                    
                    if (!empty($name) && count($keywords) > 1) {
                        $topics[] = [
                            'id' => $this->generateTopicId($name),
                            'title' => $name,
                            'keywords' => array_values($keywords),
                            'weight' => 5,
                            'content' => $name
                        ];
                    }
                }
            }
        }

        return $topics;
    }

    /**
     * Fallback: Extract topics from markdown (less reliable)
     * 
     * This is used only when no explicit topic markers are found.
     * It extracts from bold text but is less precise.
     * 
     * @param string $content Markdown content
     * @return array Array of topics
     */
    private function extractTopicsFromMarkdownFallback($content)
    {
        $topics = [];

        // Only extract from bold text that looks like a proper topic
        // Pattern: **Name** - Description (common in list formats)
        if (preg_match_all('/\*\*([^*]+)\*\*\s*[-–]\s*([^,\n]+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = trim($match[1]);
                $description = trim($match[2]);
                
                // Skip if too short or looks like a heading
                if (strlen($name) < 3 || strlen($name) > 50) {
                    continue;
                }
                
                // Extract keywords from name and description
                $keywords = $this->extractKeywords($name . ' ' . $description);
                $keywords[] = strtolower($name);
                $keywords = array_unique($keywords);
                
                $topics[] = [
                    'id' => $this->generateTopicId($name),
                    'title' => $name,
                    'keywords' => $keywords,
                    'weight' => 5,
                    'content' => $name . ' - ' . $description
                ];
            }
        }

        return $topics;
    }

    /**
     * Calculate progress for a conversation
     * 
     * CONFIRMATION-BASED PROGRESS:
     * Progress is tracked through EXPLICIT USER CONFIRMATION, not keyword detection.
     * Topics are marked as covered when the user explicitly confirms understanding.
     * 
     * Progress is ALWAYS monotonically increasing - it can only go up.
     * 
     * @param int $conversation_id Conversation ID
     * @param array $topics Array of topics from context
     * @param array $messages Array of conversation messages (unused in confirmation mode, kept for API compatibility)
     * @param float $previous_progress Previous progress value (ensures monotonic increase)
     * @param int|null $section_id Section ID for looking up confirmed topics
     * @return array Progress data including percentage and topic coverage
     */
    public function calculateProgress($conversation_id, $topics, $messages, $previous_progress = 0, $section_id = null)
    {
        if (empty($topics)) {
            return [
                'percentage' => 0, // No topics defined = 0% (can't track without topics)
                'topics_total' => 0,
                'topics_covered' => 0,
                'topic_coverage' => [],
                'is_complete' => false,
                'message' => 'No trackable topics defined in context. Use [TOPIC: Name | keywords] format.'
            ];
        }

        $totalTopics = count($topics);
        
        // Get existing progress from database (includes confirmed topics)
        $existingProgress = null;
        if ($section_id !== null) {
            $existingProgress = $this->getConversationProgress($conversation_id, $section_id);
        }
        
        // Get confirmed topics from database
        $confirmedTopicIds = [];
        $existingCoverage = [];
        if ($existingProgress && !empty($existingProgress['topic_coverage'])) {
            $existingCoverage = json_decode($existingProgress['topic_coverage'], true) ?: [];
            foreach ($existingCoverage as $topicId => $topicData) {
                if (!empty($topicData['is_covered'])) {
                    $confirmedTopicIds[] = $topicId;
                }
            }
        }

        // Build topic coverage array, merging with existing confirmed data
        $topicCoverage = [];
        $coveredTopics = 0;

        foreach ($topics as $topic) {
            $topicId = $topic['id'];
            $isConfirmed = in_array($topicId, $confirmedTopicIds);
            
            // Use existing coverage data if available, otherwise create new entry
            if (isset($existingCoverage[$topicId])) {
                $topicCoverage[$topicId] = $existingCoverage[$topicId];
                // Ensure title is up to date
                $topicCoverage[$topicId]['title'] = $topic['title'];
            } else {
                $topicCoverage[$topicId] = [
                    'id' => $topicId,
                    'title' => $topic['title'],
                    'coverage' => $isConfirmed ? 100 : 0,
                    'depth' => $isConfirmed ? 1 : 0,
                    'matches' => [],
                    'is_covered' => $isConfirmed
                ];
            }

            if ($topicCoverage[$topicId]['is_covered']) {
                $coveredTopics++;
            }
        }

        // Calculate percentage based on confirmed topics
        $rawPercentage = $totalTopics > 0 ? round(($coveredTopics / $totalTopics) * 100, 1) : 0;

        // CRITICAL: Ensure progress is monotonically increasing
        $percentage = max($rawPercentage, $previous_progress);

        return [
            'percentage' => $percentage,
            'topics_total' => $totalTopics,
            'topics_covered' => $coveredTopics,
            'topic_coverage' => $topicCoverage,
            'is_complete' => $percentage >= 100
        ];
    }



    /**
     * Get or create progress record for a conversation
     * 
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @return array|null Progress record or null
     */
    public function getConversationProgress($conversation_id, $section_id)
    {
        return $this->db->query_db_first(
            "SELECT * FROM llmConversationProgress 
             WHERE id_llmConversations = ? AND id_sections = ?",
            [$conversation_id, $section_id]
        );
    }

    /**
     * Update progress for a conversation
     * 
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @param float $percentage Progress percentage
     * @param array $topic_coverage Topic coverage data
     * @return bool Success
     */
    public function updateConversationProgress($conversation_id, $section_id, $percentage, $topic_coverage)
    {
        $existing = $this->getConversationProgress($conversation_id, $section_id);

        // Ensure monotonic increase
        if ($existing) {
            $percentage = max($percentage, (float)$existing['progress_percentage']);
        }

        $data = [
            'id_llmConversations' => $conversation_id,
            'id_sections' => $section_id,
            'progress_percentage' => $percentage,
            'topic_coverage' => json_encode($topic_coverage)
        ];

        if ($existing) {
            return $this->db->update_by_ids(
                'llmConversationProgress',
                $data,
                ['id' => $existing['id']]
            );
        } else {
            return $this->db->insert('llmConversationProgress', $data) > 0;
        }
    }

    /**
     * Build progress tracking context instructions
     * 
     * These instructions are added to the system context to help the AI
     * understand and report on progress.
     * 
     * IMPORTANT: Progress is now tracked through EXPLICIT USER CONFIRMATION.
     * Instead of guessing from keywords, the LLM asks the user to confirm
     * when they feel they understand a topic. This is more accurate and
     * gives users control over their learning pace.
     * 
     * @param array $topics Array of topics
     * @param float $current_progress Current progress percentage
     * @param string $context_language Language code for confirmation questions (e.g., 'de', 'en', 'fr')
     * @param array $confirmed_topics Array of topic IDs that user has confirmed as understood
     * @return string Context instructions
     */
    public function buildProgressTrackingContext($topics, $current_progress, $context_language = 'en', $confirmed_topics = [])
    {
        if (empty($topics)) {
            return '';
        }

        // Build topic list with confirmation status
        $topicListItems = [];
        $uncoveredTopics = [];
        foreach ($topics as $topic) {
            $isConfirmed = in_array($topic['id'], $confirmed_topics);
            $status = $isConfirmed ? '✓' : '○';
            $topicListItems[] = "- [{$status}] {$topic['title']}";
            if (!$isConfirmed) {
                $uncoveredTopics[] = $topic['title'];
            }
        }

        $topicListStr = implode("\n", $topicListItems);
        $uncoveredStr = !empty($uncoveredTopics) ? implode(", ", array_slice($uncoveredTopics, 0, 3)) : 'None';

        // Get language-specific confirmation prompts
        $confirmationPrompts = LlmLanguageUtility::getConfirmationPrompts($context_language);

        return <<<EOT

PROGRESS TRACKING INSTRUCTIONS:
You are guiding the user through the following topics/content:

{$topicListStr}

Legend: [✓] = Confirmed by user, [○] = Not yet confirmed
Current progress: {$current_progress}%
Topics remaining: {$uncoveredStr}

CONFIRMATION-BASED PROGRESS (IMPORTANT):
Progress is tracked through EXPLICIT USER CONFIRMATION, not keyword detection.
After discussing a topic sufficiently, ask the user to confirm their understanding.

Use this confirmation approach:
1. After covering a topic, ask: "{$confirmationPrompts['question']}"
2. Present a simple form with options:
   - "{$confirmationPrompts['yes']}" (marks topic as covered)
   - "{$confirmationPrompts['partial']}" (continue explaining)
   - "{$confirmationPrompts['no']}" (restart topic explanation)
3. Only mark progress when user explicitly confirms understanding
4. If user says they understand in free text, acknowledge and update progress

Guidelines:
1. Help the user explore each topic naturally through conversation
2. When a topic is sufficiently discussed, ask for confirmation
3. NEVER assume understanding - always ask for confirmation
4. Celebrate milestones (25%, 50%, 75%, 100%)
5. If the user asks about progress, show which topics are confirmed vs pending
6. Do NOT force topics - follow the user's interests
7. ALL confirmation questions must be in the SAME LANGUAGE as the context

Language for this session: {$context_language}
EOT;
    }


    /**
     * Mark a topic as confirmed by the user
     * 
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @param string $topic_id Topic ID to mark as confirmed
     * @param array $all_topics All topics from context (to ensure proper percentage calculation)
     * @return bool Success
     */
    public function confirmTopic($conversation_id, $section_id, $topic_id, $all_topics = [])
    {
        $existing = $this->getConversationProgress($conversation_id, $section_id);
        
        $topic_coverage = [];
        if ($existing && !empty($existing['topic_coverage'])) {
            $topic_coverage = json_decode($existing['topic_coverage'], true) ?: [];
        }

        // Initialize all topics in coverage if provided
        // This ensures percentage is calculated correctly based on total topics
        foreach ($all_topics as $topic) {
            $tid = $topic['id'];
            if (!isset($topic_coverage[$tid])) {
                $topic_coverage[$tid] = [
                    'id' => $tid,
                    'title' => $topic['title'],
                    'is_covered' => false,
                    'coverage' => 0,
                    'depth' => 0,
                    'matches' => []
                ];
            }
        }

        // Mark the specific topic as confirmed
        if (!isset($topic_coverage[$topic_id])) {
            $topic_coverage[$topic_id] = [
                'id' => $topic_id,
                'title' => $topic_id, // Will be overwritten if topic found in all_topics
                'is_covered' => true,
                'confirmed_at' => date('Y-m-d H:i:s'),
                'coverage' => 100,
                'depth' => 1,
                'matches' => []
            ];
        } else {
            $topic_coverage[$topic_id]['is_covered'] = true;
            $topic_coverage[$topic_id]['confirmed_at'] = date('Y-m-d H:i:s');
            $topic_coverage[$topic_id]['coverage'] = 100;
            $topic_coverage[$topic_id]['depth'] = max(1, ($topic_coverage[$topic_id]['depth'] ?? 0) + 1);
        }

        // Calculate total topics (use all_topics count if provided, otherwise count from coverage)
        $total_topics = !empty($all_topics) ? count($all_topics) : count($topic_coverage);
        
        // Count confirmed topics
        $confirmed_count = 0;
        foreach ($topic_coverage as $topic) {
            if (!empty($topic['is_covered'])) {
                $confirmed_count++;
            }
        }
        
        $percentage = $total_topics > 0 ? round(($confirmed_count / $total_topics) * 100, 1) : 0;

        return $this->updateConversationProgress($conversation_id, $section_id, $percentage, $topic_coverage);
    }

    /**
     * Get list of confirmed topic IDs for a conversation
     * 
     * @param int $conversation_id Conversation ID
     * @param int $section_id Section ID
     * @return array Array of confirmed topic IDs
     */
    public function getConfirmedTopicIds($conversation_id, $section_id)
    {
        $progress = $this->getConversationProgress($conversation_id, $section_id);
        
        if (!$progress || empty($progress['topic_coverage'])) {
            return [];
        }

        $topic_coverage = json_decode($progress['topic_coverage'], true) ?: [];
        $confirmed = [];
        
        foreach ($topic_coverage as $topic_id => $topic) {
            if (!empty($topic['is_covered'])) {
                $confirmed[] = $topic_id;
            }
        }

        return $confirmed;
    }

    /**
     * Generate a unique topic ID from content
     * 
     * @param string $content Content to hash
     * @return string Topic ID
     */
    private function generateTopicId($content)
    {
        return 'topic_' . substr(md5(strtolower(trim($content))), 0, 8);
    }


    /**
     * Extract keywords from text
     * 
     * @param string $text Text to extract keywords from
     * @return array Array of keywords
     */
    private function extractKeywords($text)
    {
        // Convert to lowercase and remove punctuation
        $text = strtolower(preg_replace('/[^\w\s]/', '', $text));
        
        // Split into words
        $words = preg_split('/\s+/', $text);
        
        // Filter out common stop words and short words
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 
                      'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
                      'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
                      'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'this',
                      'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it', 'we', 'they',
                      'what', 'which', 'who', 'whom', 'when', 'where', 'why', 'how', 'all',
                      'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some', 'such',
                      'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very'];
        
        $keywords = array_filter($words, function($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        // Return unique keywords
        return array_values(array_unique($keywords));
    }

    /**
     * Debug method to help troubleshoot topic extraction
     * 
     * @param string $context The context to analyze
     * @return array Debug information
     */
    public function debugTopicExtraction($context)
    {
        $debug = [
            'context_length' => strlen($context),
            'context_preview' => substr($context, 0, 500),
            'has_trackable_topics_section' => false,
            'has_trackable_topics_section_html' => false,
            'has_topic_markers' => false,
            'is_html_content' => false,
            'extraction_method' => 'none',
            'topics_found' => 0,
            'topics' => []
        ];

        if (empty($context)) {
            $debug['error'] = 'Context is empty';
            return $debug;
        }

        // Check if content is HTML
        if (strpos($context, '<h') !== false || strpos($context, '<p') !== false) {
            $debug['is_html_content'] = true;
        }

        // Check for TRACKABLE_TOPICS section (Markdown format)
        if (preg_match('/#{1,3}\s*TRACKABLE_TOPICS/i', $context)) {
            $debug['has_trackable_topics_section'] = true;
        }

        // Check for TRACKABLE_TOPICS section (HTML format)
        if (preg_match('/<h[1-3][^>]*>\s*TRACKABLE_TOPICS\s*<\/h[1-3]>/i', $context)) {
            $debug['has_trackable_topics_section_html'] = true;
        }

        // Check for TRACKABLE_TOPICS section (plain text format)
        if (preg_match('/(?:^|\n)\s*TRACKABLE_TOPICS\s*\n/i', $context)) {
            $debug['has_trackable_topics_section_plain'] = true;
        }

        // Check for [TOPIC: ...] markers
        if (preg_match('/\[TOPIC:/i', $context)) {
            $debug['has_topic_markers'] = true;
        }

        // Try each extraction method and report
        $topics = $this->extractExplicitTopicMarkers($context);
        if (!empty($topics)) {
            $debug['extraction_method'] = 'explicit_markers';
            $debug['topics'] = $topics;
            $debug['topics_found'] = count($topics);
            return $debug;
        }

        $topics = $this->extractFromTrackableTopicsSection($context);
        if (!empty($topics)) {
            $debug['extraction_method'] = 'trackable_topics_section';
            $debug['topics'] = $topics;
            $debug['topics_found'] = count($topics);
            return $debug;
        }

        // Try HTML extraction
        $topics = $this->extractFromHtmlTrackableTopicsSection($context);
        if (!empty($topics)) {
            $debug['extraction_method'] = 'html_trackable_topics_section';
            $debug['topics'] = $topics;
            $debug['topics_found'] = count($topics);
            return $debug;
        }

        // Try plain text extraction
        $topics = $this->extractFromPlainTextTrackableTopicsSection($context);
        if (!empty($topics)) {
            $debug['extraction_method'] = 'plain_text_trackable_topics_section';
            $debug['topics'] = $topics;
            $debug['topics_found'] = count($topics);
            return $debug;
        }

        $topics = $this->extractTopicsFromMarkdownFallback($context);
        if (!empty($topics)) {
            $debug['extraction_method'] = 'markdown_fallback';
            $debug['topics'] = $topics;
            $debug['topics_found'] = count($topics);
            return $debug;
        }

        $debug['error'] = 'No topics could be extracted from context. Context appears to be ' . 
                          ($debug['is_html_content'] ? 'HTML' : 'plain text/Markdown') . 
                          '. Has TRACKABLE_TOPICS heading: ' . 
                          ($debug['has_trackable_topics_section_html'] ? 'Yes (HTML)' : 
                           ($debug['has_trackable_topics_section'] ? 'Yes (Markdown)' : 'No'));
        return $debug;
    }

}
?>

