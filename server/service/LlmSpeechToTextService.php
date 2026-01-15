<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmLanguageUtility.php';

/**
 * LLM Speech-to-Text Service
 * 
 * Handles speech-to-text transcription using Whisper models via GPUStack API.
 * This service is completely separate from the chat/LLM functionality and
 * only provides voice-to-text conversion for easier message input.
 * 
 * Key Features:
 * - Real-time audio transcription using Whisper models
 * - Automatic language detection based on user session
 * - No permanent storage of audio data (privacy-first)
 * - Clean separation from LLM chat logic
 * 
 * @package LLM Plugin
 * @version 1.0.0
 */
class LlmSpeechToTextService extends BaseLlmService
{
    /**
     * Default Whisper model for speech recognition
     */
    const DEFAULT_SPEECH_MODEL = 'faster-whisper-large-v3';
    
    /**
     * OpenAI-compatible audio transcriptions endpoint
     */
    const AUDIO_TRANSCRIPTIONS_ENDPOINT = '/audio/transcriptions';
    
    /**
     * Supported audio MIME types
     */
    const SUPPORTED_AUDIO_TYPES = [
        'audio/webm',
        'audio/webm;codecs=opus',
        'audio/wav',
        'audio/mp3',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/flac'
    ];
    
    /**
     * Maximum audio file size (25MB - OpenAI limit)
     */
    const MAX_AUDIO_SIZE = 25 * 1024 * 1024;

    /* =========================================================================
     * CONSTRUCTOR
     * ========================================================================= */

    /**
     * Constructor
     *
     * @param object $services SelfHelp services container
     */
    public function __construct($services)
    {
        parent::__construct($services);
    }


    /* =========================================================================
     * PUBLIC METHODS
     * ========================================================================= */

    /**
     * Transcribe audio data to text
     * 
     * Sends audio data to the Whisper model via GPUStack API and returns
     * the transcribed text. No data is stored permanently.
     * 
     * @param string $audioFilePath Path to the uploaded audio file
     * @param string $model Whisper model to use (default: faster-whisper-large-v3)
     * @param string|null $language Language code for transcription (null = auto-detect)
     * @return array Result with 'success', 'text', and optionally 'error'
     */
    public function transcribeAudio($audioFilePath, $model = null, $language = null)
    {
        // Validate audio file exists
        if (!file_exists($audioFilePath)) {
            return [
                'success' => false,
                'error' => 'Audio file not found'
            ];
        }
        
        // Validate file size
        $fileSize = filesize($audioFilePath);
        if ($fileSize > self::MAX_AUDIO_SIZE) {
            return [
                'success' => false,
                'error' => 'Audio file too large. Maximum size is 25MB.'
            ];
        }
        
        if ($fileSize === 0) {
            return [
                'success' => false,
                'error' => 'Audio file is empty'
            ];
        }
        
        // Use default model if not specified
        if (empty($model)) {
            $model = self::DEFAULT_SPEECH_MODEL;
        }
        
        // Get language from session if not specified
        if ($language === null) {
            $language = $this->getUserLanguage();
        }
        
        try {
            $config = $this->getLlmConfig();
            
            // Build the API URL for audio transcriptions
            $apiUrl = rtrim($config['llm_base_url'], '/') . self::AUDIO_TRANSCRIPTIONS_ENDPOINT;
            
            // Prepare the multipart form data using cURL file
            $audioFile = new CURLFile($audioFilePath, 'audio/webm', 'audio.webm');
            
            $postData = [
                'file' => $audioFile,
                'model' => $model,
                'response_format' => 'json'
            ];
            
            // Add language if not auto-detect
            if ($language !== 'auto' && !empty($language)) {
                $postData['language'] = $language;
            }
            
            // Execute the API call
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int)($config['llm_timeout'] ?? 60),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $config['llm_api_key']
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            curl_close($ch);
            
            // Check for cURL errors
            if ($curlError) {
                error_log("Speech-to-text cURL error: " . $curlError);
                return [
                    'success' => false,
                    'error' => 'Connection error: ' . $curlError
                ];
            }
            
            // Check HTTP status code
            if ($httpCode !== 200) {
                error_log("Speech-to-text API error: HTTP $httpCode - $response");
                return [
                    'success' => false,
                    'error' => "API error (HTTP $httpCode)"
                ];
            }
            
            // Parse the response
            $responseData = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Speech-to-text JSON parse error: " . json_last_error_msg());
                return [
                    'success' => false,
                    'error' => 'Invalid response from speech recognition service'
                ];
            }
            
            // Extract transcribed text
            $text = $responseData['text'] ?? '';
            
            if (empty($text)) {
                return [
                    'success' => true,
                    'text' => '',
                    'message' => 'No speech detected in audio'
                ];
            }
            
            return [
                'success' => true,
                'text' => trim($text),
                'language' => $responseData['language'] ?? $language
            ];
            
        } catch (Exception $e) {
            error_log("Speech-to-text exception: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Speech recognition failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get user's language from session
     * 
     * Extracts the language code from the user's session locale setting.
     * Uses the same logic as other LLM services for consistency.
     * 
     * @return string Language code (e.g., 'en', 'de', 'fr') or 'auto'
     */
    public function getUserLanguage()
    {
        // Get language from session locale (e.g., "de-CH" -> "de", "en-GB" -> "en")
        $locale = $_SESSION['user_language_locale'] ?? 'en-GB';
        $lang = substr($locale, 0, 2);
        
        // Validate it's a supported language for Whisper
        // Whisper supports many languages, but we limit to common ones for consistency
        $supported = ['en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'ru', 'ja', 'zh', 'ko'];
        
        return in_array($lang, $supported) ? $lang : 'auto';
    }
    
    /**
     * Get available audio models from the API
     * 
     * Fetches the list of available Whisper models from the configured API.
     * Filters to show only audio/speech models.
     * 
     * @return array Array of audio model information
     */
    public function getAvailableAudioModels()
    {
        try {
            $config = $this->getLlmConfig();
            
            $data = [
                'URL' => rtrim($config['llm_base_url'], '/') . LLM_API_MODELS,
                'request_type' => 'GET',
                'header' => [
                    'Authorization: Bearer ' . $config['llm_api_key']
                ],
                'timeout' => $config['llm_timeout']
            ];
            
            $response = BaseModel::execute_curl_call($data);
            
            if (!$response || !is_array($response) || empty($response['data'])) {
                return $this->getDefaultAudioModelList();
            }
            
            // Filter for audio/whisper models
            $audioModels = array_filter($response['data'], function($model) {
                $id = strtolower($model['id'] ?? '');
                return strpos($id, 'whisper') !== false 
                    || strpos($id, 'speech') !== false
                    || strpos($id, 'audio') !== false;
            });
            
            // If no audio models found, return default list
            if (empty($audioModels)) {
                return $this->getDefaultAudioModelList();
            }
            
            return array_values($audioModels);
            
        } catch (Exception $e) {
            error_log("Error fetching audio models: " . $e->getMessage());
            return $this->getDefaultAudioModelList();
        }
    }
    
    /**
     * Get default audio model list
     * 
     * Returns a static list of common Whisper models as fallback.
     * 
     * @return array Array of default audio models
     */
    private function getDefaultAudioModelList()
    {
        return [
            ['id' => 'faster-whisper-large-v3', 'name' => 'Faster Whisper Large V3'],
            ['id' => 'whisper-large-v3', 'name' => 'Whisper Large V3'],
            ['id' => 'whisper-medium', 'name' => 'Whisper Medium'],
            ['id' => 'whisper-small', 'name' => 'Whisper Small']
        ];
    }
    
    /**
     * Validate audio file type
     * 
     * Checks if the uploaded file has a supported audio MIME type.
     * 
     * @param string $mimeType MIME type of the uploaded file
     * @return bool True if supported, false otherwise
     */
    public function isValidAudioType($mimeType)
    {
        // Normalize MIME type (remove parameters like codecs)
        $baseMimeType = explode(';', $mimeType)[0];
        
        return in_array($mimeType, self::SUPPORTED_AUDIO_TYPES) 
            || in_array($baseMimeType, self::SUPPORTED_AUDIO_TYPES);
    }
}
?>
