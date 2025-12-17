<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>

<?php
/**
 * Service for handling strict conversation mode functionality.
 *
 * When strict conversation mode is enabled, this service enhances the system context
 * with enforcement instructions that guide the LLM to stay within defined topics.
 * 
 * Design Philosophy:
 * - Rather than making a separate LLM call to analyze each message (expensive and slow),
 *   we embed enforcement instructions directly into the conversation context.
 * - The LLM itself determines relevance and handles redirection naturally.
 * - This approach is more efficient, maintains conversation flow, and leverages
 *   the LLM's understanding of context.
 */
class LlmStrictConversationService
{
    private $llm_service;
    
    /** @var float Confidence threshold for relevance (used if pre-check is enabled) */
    private const RELEVANCE_THRESHOLD = 0.7;

    public function __construct($llm_service)
    {
        $this->llm_service = $llm_service;
    }

    /**
     * Build context messages with strict mode enforcement
     * 
     * This is the primary method for strict conversation mode. It takes the original
     * context and enhances it with enforcement instructions that guide the LLM to:
     * - Only respond to topics within the defined context
     * - Politely redirect off-topic questions
     * - Maintain focus on the conversation's purpose
     *
     * @param array $originalContext The original parsed conversation context messages
     * @param string $rawContext The raw conversation context string for topic extraction
     * @return array Enhanced context messages with enforcement instructions
     */
    public function buildStrictModeContext(array $originalContext, string $rawContext): array
    {
        if (empty($rawContext)) {
            return $originalContext;
        }

        // Extract key topics from context for the enforcement message
        $topics = $this->extractKeyTopics($rawContext);
        $topicList = !empty($topics) ? implode(', ', array_slice($topics, 0, 5)) : 'the defined subject matter';

        // Create the enforcement system message
        $enforcementMessage = [
            'role' => 'system',
            'content' => $this->buildEnforcementPrompt($rawContext, $topicList)
        ];

        // Prepend enforcement to original context
        return array_merge([$enforcementMessage], $originalContext);
    }

    /**
     * Build the enforcement prompt that instructs the LLM to stay on topic
     *
     * @param string $context The conversation context
     * @param string $topicList Comma-separated list of key topics
     * @return string The enforcement prompt
     */
    private function buildEnforcementPrompt(string $context, string $topicList): string
    {
        return <<<PROMPT
## STRICT CONVERSATION MODE ENABLED

You are operating in **strict conversation mode**. You must ONLY discuss topics directly related to the following context:

---
{$context}
---

### Key Topics: {$topicList}

### Rules:
1. **Stay On Topic**: Only answer questions and provide information related to the topics above.
2. **Polite Redirection**: If a user asks about ANY unrelated topic, respond with a brief, friendly message like:
   - "I'm here to help you with {$topicList}. Is there something specific about these topics I can assist you with?"
   - "That's outside my focus area for this conversation. I'm specialized in discussing {$topicList}. What would you like to know about that?"
3. **No Exceptions**: Do not provide information about unrelated subjects, even if the request seems harmless.
4. **Natural Flow**: When redirecting, be warm and helpful, not robotic or dismissive.

Remember: Your purpose in this conversation is specifically defined by the context above. Stay focused and helpful within that scope.
PROMPT;
    }

    /**
     * Extract key topics from the conversation context
     * Used to provide specific topic references in redirection messages
     *
     * @param string $context The raw conversation context
     * @return array Array of extracted topic keywords
     */
    private function extractKeyTopics(string $context): array
    {
        $topics = [];
        $lowerContext = strtolower($context);

        // Common topic patterns to look for
        $topicPatterns = [
            'anxiety' => ['anxiety', 'anxious', 'panic', 'worry'],
            'depression' => ['depression', 'depressed', 'mood'],
            'stress management' => ['stress', 'coping', 'relaxation'],
            'mindfulness' => ['mindfulness', 'meditation', 'breathing'],
            'mental health' => ['mental health', 'wellbeing', 'wellness'],
            'therapy' => ['therapy', 'therapist', 'counseling'],
            'self-care' => ['self-care', 'self care', 'healthy habits'],
            'sleep' => ['sleep', 'insomnia', 'rest'],
            'relationships' => ['relationships', 'communication', 'social'],
        ];

        foreach ($topicPatterns as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerContext, $keyword) !== false) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        // Also extract from markdown headings if present
        if (preg_match_all('/^#+\s*(.+)$/m', $context, $matches)) {
            foreach ($matches[1] as $heading) {
                $cleanHeading = trim(strtolower($heading));
                if (strlen($cleanHeading) > 3 && strlen($cleanHeading) < 40) {
                    $topics[] = $cleanHeading;
                }
            }
        }

        return array_unique($topics);
    }

    /**
     * Optional: Pre-check message relevance using LLM
     * 
     * Note: This method is provided for cases where you need to know BEFORE
     * generating a response whether a message is on-topic (e.g., for logging,
     * analytics, or special handling). For most use cases, the embedded
     * enforcement approach via buildStrictModeContext() is preferred.
     *
     * @param string $userMessage The user's message
     * @param string $conversationContext The configured conversation context
     * @param string $model The LLM model to use for analysis
     * @return array Analysis result with 'is_on_topic' boolean and 'confidence' score
     */
    public function analyzeMessageRelevance(string $userMessage, string $conversationContext, string $model = 'qwen3-vl-8b-instruct'): array
    {
        if (empty($conversationContext)) {
            return ['is_on_topic' => true, 'confidence' => 1.0];
        }

        $analysisPrompt = $this->createAnalysisPrompt($userMessage, $conversationContext);

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a relevance classifier. Respond ONLY with a JSON object: {"relevant": true/false, "confidence": 0.0-1.0}. No other text.'
            ],
            [
                'role' => 'user',
                'content' => $analysisPrompt
            ]
        ];

        try {
            $response = $this->llm_service->callLlmApi($messages, $model, 0.0, 50);

            if (is_array($response) && isset($response['choices'][0]['message']['content'])) {
                return $this->parseJsonResponse($response['choices'][0]['message']['content']);
            }

            return ['is_on_topic' => true, 'confidence' => 0.5];

        } catch (Exception $e) {
            // Fail-open: allow message if analysis fails
            error_log('LlmtrictConversationService: Analysis failed - ' . $e->getMessage());
            return ['is_on_topic' => true, 'confidence' => 0.5];
        }
    }

    /**
     * Create the analysis prompt for relevance checking
     *
     * @param string $userMessage The user's message
     * @param string $conversationContext The conversation context
     * @return string The analysis prompt
     */
    private function createAnalysisPrompt(string $userMessage, string $conversationContext): string
    {
        return <<<PROMPT
Determine if this user message is relevant to the conversation context.

CONTEXT:
{$conversationContext}

USER MESSAGE:
"{$userMessage}"

Is this message related to the topics in the context? Consider semantic meaning, not just keywords.
PROMPT;
    }

    /**
     * Parse JSON response from relevance analysis
     *
     * @param string $llmResponse The LLM's response
     * @return array Analysis result
     */
    private function parseJsonResponse(string $llmResponse): array
    {
        // Try to extract JSON from response
        $response = trim($llmResponse);
        
        // Handle potential markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $response = $matches[1];
        }
        
        // Try direct JSON parse
        $parsed = json_decode($response, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return [
                'is_on_topic' => isset($parsed['relevant']) ? (bool)$parsed['relevant'] : true,
                'confidence' => isset($parsed['confidence']) ? min(1.0, max(0.0, (float)$parsed['confidence'])) : 0.5
            ];
        }

        // Fallback: look for boolean indicators in text
        $lowerResponse = strtolower($response);
        $isRelevant = true;
        
        // Check for explicit "not relevant" or "false" patterns
        if (preg_match('/\b(not[_\s]?relevant|irrelevant|off[_\s]?topic|unrelated)\b/i', $lowerResponse) ||
            preg_match('/"relevant"\s*:\s*false/i', $lowerResponse)) {
            $isRelevant = false;
        }

        // Extract confidence if present
        $confidence = 0.5;
        if (preg_match('/(\d+\.?\d*)\s*%?/', $response, $matches)) {
            $value = (float)$matches[1];
            $confidence = $value > 1 ? $value / 100 : $value;
            $confidence = min(1.0, max(0.0, $confidence));
        }

        return [
            'is_on_topic' => $isRelevant,
            'confidence' => $confidence
        ];
    }

    /**
     * Generate context enforcement instructions for the LLM
     * 
     * @deprecated Use buildStrictModeContext() instead for better integration
     * @param string $conversationContext The configured conversation context
     * @return string Context enforcement instructions
     */
    public function generateContextEnforcementInstructions(string $conversationContext): string
    {
        $topics = $this->extractKeyTopics($conversationContext);
        $topicList = !empty($topics) ? implode(', ', array_slice($topics, 0, 5)) : 'the defined topics';
        
        return $this->buildEnforcementPrompt($conversationContext, $topicList);
    }
}
?>