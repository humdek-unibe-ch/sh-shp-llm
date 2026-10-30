<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';
require_once __DIR__ . '/LlmLanguageUtility.php';
require_once __DIR__ . '/LlmFileNamingService.php';
require_once __DIR__ . '/LlmService.php';
require_once __DIR__ . '/exception/LlmRateLimitException.php';
require_once __DIR__ . '/LlmErrorReporter.php';

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
 */
class LlmSpeechToTextService extends BaseLlmService
{
    /** HTTP status code from the last cURL call */
    private $lastHttpCode = 0;

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
        'audio/ogg',
        'audio/ogg;codecs=opus',
        'audio/aac',
        'audio/x-aac',
        'audio/x-hx-aac-adts',
        'audio/mp3',
        'audio/mpeg',
        'audio/mp4',
        'audio/m4a',
        'audio/flac'
    ];
    
    /**
     * Types libmagic reports for the recordings browsers make, judged by
     * the file content. Some audio-only containers are reported as video/*
     * or application/ogg.
     */
    const DETECTED_AUDIO_TYPES = [
        'audio/webm',
        'video/webm',
        'audio/ogg',
        'application/ogg',
        'video/ogg',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'video/mp4',
        'audio/aac',
        'audio/x-aac',
        'audio/x-hx-aac-adts',
        'audio/aac-adts',
        'audio/adts',
        'audio/flac',
        'audio/x-flac'
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
     *  (a refusal also carries 'error_type' = 'invalid_audio' | 'rate_limited', see failureStatus())
     */
    public function saveAndTranscribeAudio($audioFile, $userId, $sectionId, $conversationId = null, $model = null, $language = null, $keepFile = true)
    {
        // Validate audio file
        if (!isset($audioFile['tmp_name']) || !file_exists($audioFile['tmp_name'])) {
            return [
                'success' => false,
                'error_type' => 'invalid_audio',
                'error' => 'Audio file not found'
            ];
        }
        
        // Validate file size
        $fileSize = $audioFile['size'] ?? filesize($audioFile['tmp_name']);
        
        if ($fileSize > self::MAX_AUDIO_SIZE) {
            return [
                'success' => false,
                'error_type' => 'invalid_audio',
                'error' => 'Audio file too large. Maximum size is 25MB.'
            ];
        }
        
        if ($fileSize === 0) {
            return [
                'success' => false,
                'error_type' => 'invalid_audio',
                'error' => 'Audio file is empty'
            ];
        }
        
        // The content decides what the file is, not the type the client declared
        $mimeType = $this->detectAudioType($audioFile['tmp_name'], $audioFile['type'] ?? '');
        if ($mimeType === null) {
            return [
                'success' => false,
                'error_type' => 'invalid_audio',
                'error' => 'Invalid audio format. Supported: WebM/Opus, OGG/Opus, M4A/MP4, MP3, FLAC'
            ];
        }
        $extension = $this->mimeToExtension($mimeType);
        
        // Transcription calls a paid model: only the requests within the budget get that far
        try {
            (new LlmService($this->services))->consumeSpeechRateLimit($userId);
        } catch (LlmRateLimitException $e) {
            return [
                'success' => false,
                'error_type' => 'rate_limited',
                'error' => $e->getMessage(),
                'retry_after' => $e->getRetryAfter()
            ];
        }
        
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
            LlmErrorReporter::log('Speech-to-text upload directory', $e);
            return [
                'success' => false,
                'error' => 'Failed to save audio file'
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
        if (defined('DEBUG') && DEBUG) {
            error_log("Speech-to-text: Saved audio file - Path: {$fullPath}, Size: {$fileSize} bytes");
        }
        
        // Transcribe the saved audio file
        $result = $this->transcribeAudio($fullPath, $model, $language);
        
        // Add audio file info to result
        $result['audio_file'] = [
            'filename' => $filename,
            'path' => $relativePath,
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
        if (defined('DEBUG') && DEBUG) {
            error_log("Speech-to-text: Processing audio file - Path: {$audioFilePath}, Size: {$fileSize} bytes");
        }
        
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

        // Resolve target server + raw model identifier
        $resolvedModel = $this->resolveModelServer($model);
        $speechServer = $resolvedModel['server'];
        $rawModel = $resolvedModel['model'];
        if (empty($rawModel)) {
            $rawModel = self::DEFAULT_SPEECH_MODEL;
        }
        
        // Get language from session if not specified
        if ($language === null) {
            $language = $this->getUserLanguage();
        }
        
        try {
            $config = $this->getLlmConfig();

            // Build the API URL for audio transcriptions
            $apiUrl = rtrim($speechServer['base_url'], '/') . self::AUDIO_TRANSCRIPTIONS_ENDPOINT;

            // Forward uploaded audio directly to Whisper (no server-side re-encoding)
            $actualPath = $audioFilePath;

            // Prepare the multipart form data using cURL file
            $detectedMimeType = mime_content_type($actualPath) ?: 'audio/webm';
            $mimeType = $this->normalizeAudioMimeType($detectedMimeType, $actualPath);
            $curlFilename = 'audio.' . $this->mimeToExtension($mimeType);
            $audioFile = new CURLFile($actualPath, $mimeType, $curlFilename);
            error_log("Speech-to-text: Sending audio - Size: " . filesize($actualPath) . " bytes, MIME: {$mimeType}");

            $postData = [
                'file' => $audioFile,
                'model' => $rawModel,
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
                $speechServer['api_key'] ?? '',
                $config['llm_timeout']
            );

            if ($response === false) {
                return [
                    'success' => false,
                    'error' => 'Connection error: No response from speech recognition service'
                ];
            }

            // Check for HTTP error responses returned as plain text
            $httpCode = $this->lastHttpCode ?? 0;

            // Parse JSON response
            $responseData = json_decode($response, true);
            if ($responseData === null) {
                $trimmed = trim($response);

                // Non-2xx plain text is an error, not transcription
                if ($httpCode >= 400) {
                    if ($httpCode === 413 || stripos($trimmed, 'Payload Too Large') !== false) {
                        return [
                            'success' => false,
                            'error' => 'Audio recording is too large. Please try a shorter recording.'
                        ];
                    }
                    error_log("Speech-to-text API HTTP {$httpCode}: " . substr($trimmed, 0, 500));
                    return [
                        'success' => false,
                        'error' => 'Speech recognition service error (HTTP ' . $httpCode . ')'
                    ];
                }

                // Whisper APIs (especially faster-whisper) may return plain text
                // even when response_format=json is requested
                if (!empty($trimmed) && $trimmed[0] !== '<') {
                    return [
                        'success' => true,
                        'text' => $trimmed,
                        'language' => $language
                    ];
                }
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
                    'error' => 'Speech recognition service error'
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
            LlmErrorReporter::log('Speech-to-text transcription', $e);
            return [
                'success' => false,
                'error' => 'Speech recognition failed. Please try again.'
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
        $locale = $_SESSION['user_language_locale'] ?? 'en-GB';
        $lang = substr($locale, 0, 2);

        // Whisper supports more languages than the LLM plugin's standard set
        $whisper_supported = ['en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'ru', 'ja', 'zh', 'ko'];

        return in_array($lang, $whisper_supported) ? $lang : 'auto';
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

            $this->lastHttpCode = $httpCode;
            
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
            LlmErrorReporter::log('Speech-to-text cURL request', $e);
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
            // Use the unified model catalog path from LlmService so all dropdowns
            // and runtime model routing use the same source of truth.
            $llmService = new LlmService($this->services);
            $models = $llmService->getAvailableModels(null, 'audio');
            if (empty($models)) {
                return $this->getDefaultAudioModelList();
            }
            return $models;
        } catch (Exception $e) {
            LlmErrorReporter::log('Fetching the audio model list', $e);
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
     * The audio type of an uploaded file, judged by its content.
     *
     * The type a client declares is only a claim. A file whose content is
     * recognisably something else (HTML, a script, an image, an archive, ...)
     * is refused whatever it was declared as. Content libmagic cannot name
     * at all (application/octet-stream, e.g. a very short recording) is
     * accepted when the client declared a supported audio type.
     *
     * @param string $path Path of the uploaded file
     * @param string $declaredType Type the client declared for it
     * @return string|null Lower-case type without parameters, null when the file is not audio
     */
    public function detectAudioType($path, $declaredType = '')
    {
        $detected = @mime_content_type($path);
        if (!is_string($detected)) {
            return null;
        }
        $detected = strtolower(trim(explode(';', $detected)[0]));
        if (in_array($detected, self::DETECTED_AUDIO_TYPES, true)) {
            return $detected;
        }
        if ($detected === 'application/octet-stream' && $this->isValidAudioType((string)$declaredType)) {
            return strtolower(trim(explode(';', (string)$declaredType)[0]));
        }
        return null;
    }

    /**
     * HTTP status a refused request should be answered with.
     *
     * @param array $result Result of saveAndTranscribeAudio()
     * @return int|null 429 / 400 for a rate limit / unusable audio, null for
     *  anything else (the caller's own default applies)
     */
    public static function failureStatus(array $result)
    {
        $type = isset($result['error_type']) ? $result['error_type'] : '';
        if ($type === 'rate_limited') {
            return 429;
        }
        return $type === 'invalid_audio' ? 400 : null;
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

    /**
     * Map MIME type to file extension for CURLFile naming
     *
     * @param string $mimeType Audio MIME type
     * @return string File extension without dot
     */
    private function mimeToExtension($mimeType)
    {
        $map = [
            'audio/webm' => 'webm',
            'audio/webm;codecs=opus' => 'webm',
            'audio/mp3' => 'mp3',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/m4a' => 'm4a',
            'audio/aac' => 'm4a',
            'audio/x-aac' => 'm4a',
            'audio/x-hx-aac-adts' => 'm4a',
            'audio/aac-adts' => 'm4a',
            'audio/adts' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/ogg;codecs=opus' => 'ogg',
            'application/ogg' => 'ogg',
            'video/ogg' => 'ogg',
            'video/webm' => 'webm',
            'video/mp4' => 'm4a',
            'audio/flac' => 'flac',
            'audio/x-flac' => 'flac',
            'audio/3gpp' => 'm4a',
            'audio/amr' => 'amr',
        ];
        $base = explode(';', $mimeType)[0];
        return $map[$base] ?? 'webm';
    }

    /**
     * Normalize uncommon/codec-specific MIME values to OpenAI-compatible types.
     *
     * @param string $mimeType
     * @param string $filePath
     * @return string
     */
    private function normalizeAudioMimeType($mimeType, $filePath = '')
    {
        $base = strtolower(trim(explode(';', (string)$mimeType)[0]));

        $map = [
            'audio/x-hx-aac-adts' => 'audio/aac',
            'audio/aac-adts' => 'audio/aac',
            'audio/adts' => 'audio/aac',
            'audio/x-m4a' => 'audio/mp4',
            'video/mp4' => 'audio/mp4',
        ];

        if (isset($map[$base])) {
            return $map[$base];
        }

        if ($base === 'application/octet-stream' && !empty($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($ext === 'm4a' || $ext === 'aac') {
                return 'audio/mp4';
            }
            if ($ext === 'webm') {
                return 'audio/webm';
            }
            if ($ext === 'ogg') {
                return 'audio/ogg';
            }
        }

        return $base ?: 'audio/webm';
    }
}
?>
