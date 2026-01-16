<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmLanguageUtility.php';
require_once __DIR__ . '/LlmFileNamingService.php';

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
 * - Audio files saved with consistent naming convention
 * - Clean separation from LLM chat logic
 * 
 * File Naming Convention:
 * =======================
 * Audio files are saved as: {user_id}_{section_id}_{conversation_id}_audio_{timestamp}_{random}.{ext}
 * Example: 42_15_123_audio_1765876608_a1b2c3d4e5f6.webm
 * 
 * @see LlmFileNamingService for full naming documentation
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
     * Save and transcribe audio data to text
     * 
     * Saves the audio file with proper naming convention, then sends it to 
     * the Whisper model via GPUStack API for transcription.
     * 
     * @param array $audioFile The $_FILES['audio'] array
     * @param int $userId User ID
     * @param int $sectionId Section ID
     * @param int|null $conversationId Conversation ID (null if no active conversation)
     * @param string $model Whisper model to use (default: faster-whisper-large-v3)
     * @param string|null $language Language code for transcription (null = auto-detect)
     * @param bool $keepFile Whether to keep the audio file after transcription (default: true)
     * @return array Result with 'success', 'text', 'audio_file' info, and optionally 'error'
     */
    public function saveAndTranscribeAudio($audioFile, $userId, $sectionId, $conversationId = null, $model = null, $language = null, $keepFile = true)
    {
        // Validate audio file
        if (!isset($audioFile['tmp_name']) || !file_exists($audioFile['tmp_name'])) {
            return [
                'success' => false,
                'error' => 'Audio file not found'
            ];
        }
        
        // Validate file size
        $fileSize = $audioFile['size'] ?? filesize($audioFile['tmp_name']);
        
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
        
        // Determine file extension from MIME type
        $mimeType = $audioFile['type'] ?? mime_content_type($audioFile['tmp_name']) ?? 'audio/webm';
        $extension = LlmFileNamingService::getExtensionFromMimeType($mimeType) ?? 'webm';
        
        // Generate filename using naming service
        $filename = LlmFileNamingService::generateAudioFilename(
            $userId,
            $sectionId,
            $conversationId,
            $extension
        );
        
        // Build paths
        $relativePath = LlmFileNamingService::buildRelativePath($userId, $filename);
        $fullPath = LlmFileNamingService::buildFullPath($userId, $filename);
        
        // Ensure user directory exists
        try {
            LlmFileNamingService::ensureUserDirectoryExists($userId);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create upload directory: ' . $e->getMessage()
            ];
        }
        
        // Save the audio file
        if (!move_uploaded_file($audioFile['tmp_name'], $fullPath)) {
            // Try copy as fallback (in case tmp file was already moved)
            if (!copy($audioFile['tmp_name'], $fullPath)) {
                return [
                    'success' => false,
                    'error' => 'Failed to save audio file'
                ];
            }
        }
        
        // Set proper file permissions
        chmod($fullPath, 0644);
        
        // Log file info for debugging
        error_log("Speech-to-text: Saved audio file - Path: {$fullPath}, Size: {$fileSize} bytes");
        
        // Transcribe the saved audio file
        $result = $this->transcribeAudio($fullPath, $model, $language);
        
        // Add audio file info to result
        $result['audio_file'] = [
            'filename' => $filename,
            'path' => $relativePath,
            'url' => LlmFileNamingService::buildFileUrl($relativePath),
            'size' => $fileSize,
            'type' => $mimeType,
            'extension' => $extension,
            'user_id' => $userId,
            'section_id' => $sectionId,
            'conversation_id' => $conversationId
        ];
        
        // Optionally delete the file after transcription
        if (!$keepFile && file_exists($fullPath)) {
            @unlink($fullPath);
            $result['audio_file']['deleted'] = true;
        }
        
        return $result;
    }

    /**
     * Transcribe audio data to text from a file path
     * 
     * Sends audio data to the Whisper model via GPUStack API and returns
     * the transcribed text. This is the core transcription method.
     * 
     * @param string $audioFilePath Path to the audio file
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
        
        // Log file info for debugging
        error_log("Speech-to-text: Processing audio file - Path: {$audioFilePath}, Size: {$fileSize} bytes");
        
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
            // Detect MIME type from file or use default
            $mimeType = mime_content_type($audioFilePath) ?: 'audio/webm';
            $audioFile = new CURLFile($audioFilePath, $mimeType, 'audio.webm');

            $postData = [
                'file' => $audioFile,
                'model' => $model,
                'response_format' => 'json'
            ];

            // Add language if not auto-detect
            if ($language !== 'auto' && !empty($language)) {
                $postData['language'] = $language;
            }

            // Use dedicated multipart curl call instead of BaseModel::execute_curl_call
            // BaseModel doesn't properly handle multipart form data with CURLFile
            $response = $this->executeMultipartCurlCall(
                $apiUrl,
                $postData,
                $config['llm_api_key'],
                $config['llm_timeout']
            );

            if ($response === false) {
                return [
                    'success' => false,
                    'error' => 'Connection error: No response from speech recognition service'
                ];
            }

            // Parse JSON response
            $responseData = json_decode($response, true);
            if ($responseData === null) {
                error_log("Speech-to-text JSON parse error: " . json_last_error_msg() . " - Response: " . substr($response, 0, 500));
                return [
                    'success' => false,
                    'error' => 'Invalid response from speech recognition service'
                ];
            }

            // Check for API error response
            if (isset($responseData['error'])) {
                $errorMsg = is_array($responseData['error']) 
                    ? ($responseData['error']['message'] ?? json_encode($responseData['error']))
                    : $responseData['error'];
                error_log("Speech-to-text API error: " . $errorMsg . " - File size: {$fileSize} bytes");
                
                // Provide user-friendly error messages for common errors
                if (stripos($errorMsg, 'Payload Too Large') !== false || stripos($errorMsg, '413') !== false) {
                    return [
                        'success' => false,
                        'error' => 'Audio recording is too large. Please try a shorter recording (under 30 seconds).'
                    ];
                }
                
                return [
                    'success' => false,
                    'error' => 'Speech recognition service error: ' . $errorMsg
                ];
            }
            
            // Check for message field (some APIs use this for errors)
            if (isset($responseData['message']) && !isset($responseData['text'])) {
                $msg = $responseData['message'];
                if (stripos($msg, 'Payload Too Large') !== false || stripos($msg, 'too large') !== false) {
                    error_log("Speech-to-text: Payload too large error - File size: {$fileSize} bytes");
                    return [
                        'success' => false,
                        'error' => 'Audio recording is too large. Please try a shorter recording (under 30 seconds).'
                    ];
                }
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
     * Execute a multipart form data cURL call
     * 
     * This method is specifically designed for file uploads (like audio transcription)
     * where we need to send multipart/form-data with CURLFile objects.
     * The standard BaseModel::execute_curl_call doesn't properly handle this case.
     * 
     * @param string $url The API endpoint URL
     * @param array $postData Array containing form fields and CURLFile objects
     * @param string $apiKey The API key for authorization
     * @param int $timeout Request timeout in seconds
     * @return string|false Raw response string or false on failure
     */
    private function executeMultipartCurlCall($url, $postData, $apiKey, $timeout = 60)
    {
        try {
            $curl = curl_init();
            
            // Set curl options for multipart form data
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                // For multipart/form-data, pass array directly - curl will set Content-Type automatically
                CURLOPT_POSTFIELDS => $postData,
                // Only set Authorization header - let curl handle Content-Type for multipart
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey
                ]
            ]);
            
            // Skip SSL verification in debug mode
            if (defined('DEBUG') && DEBUG) {
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            }
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            
            curl_close($curl);
            
            // Log errors for debugging
            if ($response === false || !empty($curlError)) {
                error_log("Speech-to-text cURL error: " . $curlError);
                return false;
            }
            
            // Log non-2xx responses
            if ($httpCode < 200 || $httpCode >= 300) {
                error_log("Speech-to-text API returned HTTP {$httpCode}: " . substr($response, 0, 500));
                
                // Handle specific HTTP errors with better messages
                if ($httpCode === 413) {
                    // Get file size for error message
                    $fileSize = 0;
                    if (isset($postData['file']) && $postData['file'] instanceof CURLFile) {
                        $filePath = $postData['file']->getFilename();
                        if (file_exists($filePath)) {
                            $fileSize = filesize($filePath);
                        }
                    }
                    error_log("Speech-to-text: Payload Too Large - File size: {$fileSize} bytes. Server may have request size limits.");
                }
            }
            
            return $response;
            
        } catch (Exception $e) {
            error_log("Speech-to-text cURL exception: " . $e->getMessage());
            return false;
        }
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
