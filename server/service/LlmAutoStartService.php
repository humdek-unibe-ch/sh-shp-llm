<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM Auto-Start Service
 *
 * Generates context-aware auto-start messages by analyzing conversation context.
 * Extracted from LlmChatModel to follow single-responsibility principle.
 */
class LlmAutoStartService
{
    private static $topicPatterns = [
        'anxiety' => ['anxiety', 'anxious', 'worry', 'panic', 'stress', 'fear', 'nervous'],
        'depression' => ['depression', 'depressed', 'mood', 'sadness', 'hopeless'],
        'therapy' => ['therapy', 'therapist', 'counseling', 'treatment', 'cognitive behavioral'],
        'coping' => ['coping', 'coping skills', 'strategies', 'techniques', 'tools'],
        'mindfulness' => ['mindfulness', 'meditation', 'breathing', 'relaxation'],
        'sleep' => ['sleep', 'insomnia', 'rest', 'tired', 'fatigue'],
        'relationships' => ['relationships', 'social', 'friends', 'family', 'communication'],
        'self-care' => ['self-care', 'wellness', 'healthy habits', 'routine'],
        'education' => ['learn', 'understand', 'knowledge', 'information', 'module', 'course'],
        'health' => ['health', 'wellbeing', 'mental health', 'physical health']
    ];

    /**
     * Generate a context-aware auto-start message.
     *
     * @param string $context Raw conversation context
     * @param string $fallbackMessage Default message if no context
     * @return string Auto-start message
     */
    public static function generateAutoStartMessage($context, $fallbackMessage)
    {
        $context = trim($context);
        if (empty($context)) {
            return $fallbackMessage;
        }

        $topics = self::extractTopicsFromContext($context);
        if (empty($topics)) {
            return $fallbackMessage;
        }

        $topicList = implode(', ', array_slice($topics, 0, 3));
        if (count($topics) > 3) {
            $topicList .= '...';
        }

        $anxietyTopics = ['anxiety', 'panic', 'worry', 'stress', 'fear'];
        $educationTopics = ['learn', 'understand', 'education', 'module', 'course'];
        $healthTopics = ['health', 'wellbeing', 'mental health', 'therapy', 'treatment'];

        if (!empty(array_intersect($topics, $anxietyTopics))) {
            return "Hello! I'm here to support you on your journey to better understand and manage anxiety. We can explore topics like {$topicList}. What specific area would you like to focus on today?";
        } elseif (!empty(array_intersect($topics, $educationTopics))) {
            return "Hi there! I'm excited to help you learn about {$topicList}. This educational module covers these important topics. Which one interests you most, or shall we start from the beginning?";
        } elseif (!empty(array_intersect($topics, $healthTopics))) {
            return "Welcome! I'm here to provide helpful information about {$topicList}. Let's work through this together. What questions do you have, or would you like me to explain any specific topic?";
        } else {
            return "Hello! I'm here to help you with {$topicList}. What would you like to explore first, or do you have any specific questions about these topics?";
        }
    }

    /**
     * Extract key topics from conversation context using keyword analysis.
     *
     * @param string $context Raw context content
     * @return array Array of topic keywords
     */
    public static function extractTopicsFromContext($context)
    {
        $topics = [];

        if (substr($context, 0, 1) === '[') {
            $parsed = json_decode($context, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                foreach ($parsed as $item) {
                    if (isset($item['content'])) {
                        $topics = array_merge($topics, self::extractTopicsFromText($item['content']));
                    }
                }
            }
        } else {
            $topics = self::extractTopicsFromText($context);
        }

        return array_unique(array_filter($topics));
    }

    /**
     * @param string $text
     * @return array
     */
    private static function extractTopicsFromText($text)
    {
        $topics = [];
        $lowerText = strtolower($text);

        foreach (self::$topicPatterns as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($lowerText, $keyword) !== false) {
                    $topics[] = $topic;
                    break;
                }
            }
        }

        if (preg_match_all('/(?:^|\n)#+\s*([^\n]+)/m', $text, $matches) && isset($matches[1])) {
            foreach ($matches[1] as $heading) {
                $cleanHeading = trim($heading, '#* ');
                if (strlen($cleanHeading) > 3 && strlen($cleanHeading) < 50) {
                    $topics[] = $cleanHeading;
                }
            }
        }

        if (preg_match_all('/(?:^|\n)[•\-\*]\s*([^\n]+)/m', $text, $matches) && isset($matches[1])) {
            foreach ($matches[1] as $item) {
                $cleanItem = trim($item);
                if (strlen($cleanItem) > 5 && strlen($cleanItem) < 40 && !preg_match('/^(what|how|why|when)/i', $cleanItem)) {
                    $topics[] = $cleanItem;
                }
            }
        }

        return array_slice($topics, 0, 10);
    }
}
?>
