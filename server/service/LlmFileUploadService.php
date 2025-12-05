<?php
/**
 * LLM File Upload Service
 * Handles file upload validation, processing, and management for LLM chat
 */

class LlmFileUploadService
{
    private $llm_service;

    public function __construct($llm_service)
    {
        $this->llm_service = $llm_service;
    }

    /**
     * Handle file uploads for messages
     * Saves files to the upload directory with conversation/message structure
     * Includes comprehensive validation for file type, size, MIME type, and duplicates
     *
     * @param int $conversationId The conversation ID
     * @return array|null Array of uploaded file information or null if no files
     * @throws Exception When validation fails
     */
    public function handleFileUploads($conversationId)
    {
        // Check for files uploaded via FormData (uploaded_files[])
        if (!empty($_FILES['uploaded_files'])) {
            $files = $_FILES['uploaded_files'];
        } elseif (!empty($_FILES)) {
            // Fallback for direct file uploads
            $files = $_FILES;
        } else {
            return null;
        }

        $uploadedFiles = [];
        $processedHashes = []; // Track file hashes for duplicate detection
        $fileCount = 0;

        // Handle both single file and multiple files array
        if (isset($files['name']) && is_array($files['name'])) {
            // Multiple files
            $totalFiles = count($files['name']);

            // Check maximum files limit
            if ($totalFiles > LLM_MAX_FILES_PER_MESSAGE) {
                throw new Exception('Maximum ' . LLM_MAX_FILES_PER_MESSAGE . ' files allowed per message');
            }

            for ($i = 0; $i < $totalFiles; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK && !empty($files['name'][$i])) {
                    $file = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i]
                    ];
                    $processedFile = $this->processUploadedFile($file, $conversationId, $processedHashes);
                    if ($processedFile) {
                        $uploadedFiles[] = $processedFile;
                        $processedHashes[] = $processedFile['hash'];
                        $fileCount++;
                    }
                } elseif ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    // Throw exception for critical upload errors so user sees the message
                    $errorMessage = $this->getUploadErrorMessage($files['error'][$i]);
                    $fileName = $files['name'][$i] ?? 'unknown';

                    // For size-related errors, provide helpful message
                    if ($files['error'][$i] === UPLOAD_ERR_INI_SIZE || $files['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
                        throw new Exception("File \"{$fileName}\" is too large. Please reduce the file size or contact administrator to increase upload limits.");
                    }
                    throw new Exception("File upload error for \"{$fileName}\": {$errorMessage}");
                }
            }
        } elseif (isset($files['name']) && !empty($files['name'])) {
            // Single file
            if ($files['error'] === UPLOAD_ERR_OK) {
                $processedFile = $this->processUploadedFile($files, $conversationId, $processedHashes);
                if ($processedFile) {
                    $uploadedFiles[] = $processedFile;
                }
            } elseif ($files['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new Exception('File upload error: ' . $this->getUploadErrorMessage($files['error']));
            }
        }

        return empty($uploadedFiles) ? null : $uploadedFiles;
    }

    /**
     * Process and validate a single uploaded file
     * Performs comprehensive validation including MIME type checking and duplicate detection
     *
     * @param array $file The file array from $_FILES
     * @param int $conversationId The conversation ID
     * @param array $processedHashes Array of already processed file hashes for duplicate detection
     * @return array|null File information array or null if file should be skipped
     * @throws Exception When validation fails critically
     */
    private function processUploadedFile($file, $conversationId, $processedHashes = [])
    {
        // Sanitize filename to prevent path traversal and invalid characters
        $originalName = $this->sanitizeFileName($file['name']);

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error for "' . $originalName . '": ' . $this->getUploadErrorMessage($file['error']));
        }

        // Validate file size
        if ($file['size'] > LLM_MAX_FILE_SIZE) {
            throw new Exception('File "' . $originalName . '" exceeds maximum limit of ' . $this->formatFileSize(LLM_MAX_FILE_SIZE));
        }

        // Validate file size is not empty
        if ($file['size'] === 0) {
            throw new Exception('File "' . $originalName . '" is empty');
        }

        // Validate file extension
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (empty($extension)) {
            throw new Exception('File "' . $originalName . '" has no extension');
        }

        if (!in_array($extension, LLM_ALLOWED_EXTENSIONS)) {
            throw new Exception('File type ".' . $extension . '" not allowed. Allowed types: ' . implode(', ', LLM_ALLOWED_EXTENSIONS));
        }

        // Validate MIME type using finfo (more reliable than browser-provided type)
        $detectedMimeType = $this->detectMimeType($file['tmp_name']);
        if (!llm_validate_mime_type($extension, $detectedMimeType)) {
            // Allow if browser-reported type matches (some systems have different finfo databases)
            if (!llm_validate_mime_type($extension, $file['type'])) {
                throw new Exception('File "' . $originalName . '" has invalid content type. Expected type for .' . $extension . ' but got ' . $detectedMimeType);
            }
        }

        // Check for duplicate files using content hash
        $fileHash = md5_file($file['tmp_name']);
        if (in_array($fileHash, $processedHashes)) {
            // Skip duplicate files silently
            return null;
        }

        // Determine file type category
        $fileCategory = llm_get_file_type_category($extension);

        // Generate secure filename with conversation context
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        $secureFileName = "temp_{$conversationId}_{$timestamp}_{$random}.{$extension}";
        $relativePath = LLM_UPLOAD_FOLDER . "/{$conversationId}/{$secureFileName}";
        // Go up 3 levels from server/plugins/sh-shp-llm/server/service/ to reach plugin root
        $fullPath = __DIR__ . "/../../../{$relativePath}";

        // Create directory with proper permissions if it doesn't exist
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }

        // Move uploaded file securely
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new Exception('Failed to save file "' . $originalName . '"');
        }

        // Set proper file permissions
        chmod($fullPath, 0644);

        // Return comprehensive file info
        return [
            'original_name' => $originalName,
            'filename' => $secureFileName,
            'path' => $relativePath,
            'size' => $file['size'],
            'type' => $detectedMimeType ?: $file['type'],
            'extension' => $extension,
            'category' => $fileCategory,
            'hash' => $fileHash,
            'url' => "?file_path={$relativePath}",
            'is_image' => $fileCategory === LLM_FILE_TYPE_IMAGE
        ];
    }

    /**
     * Update file names to include message ID after message is saved
     *
     * @param int $conversationId The conversation ID
     * @param int $messageId The message ID
     * @param array $uploadedFiles Array of uploaded file information
     */
    public function updateFileNamesWithMessageId($conversationId, $messageId, $uploadedFiles)
    {
        foreach ($uploadedFiles as $file) {
            // Extract current filename parts
            // Go up 3 levels from server/plugins/sh-shp-llm/server/service/ to reach plugin root
            $currentPath = __DIR__ . "/../../../{$file['path']}";
            $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);

            // Create new filename with message ID
            $newFileName = "conv_{$conversationId}_msg_{$messageId}_" . bin2hex(random_bytes(8)) . ".{$extension}";
            $newRelativePath = LLM_UPLOAD_FOLDER . "/{$conversationId}/{$newFileName}";
            $newFullPath = __DIR__ . "/../../../{$newRelativePath}";

            // Rename the file
            if (file_exists($currentPath) && rename($currentPath, $newFullPath)) {
                // Update the file info with new path
                $file['filename'] = $newFileName;
                $file['path'] = $newRelativePath;
                $file['url'] = "?file_path={$newRelativePath}";
            }
        }

        // Update the message in database with corrected file attachments
        $attachmentsJson = json_encode($uploadedFiles);
        $this->llm_service->updateMessage($messageId, ['attachments' => $attachmentsJson]);
    }

    /**
     * Sanitize filename to prevent security issues
     * Removes path traversal attempts and invalid characters
     *
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    private function sanitizeFileName($filename)
    {
        // Remove path components (directory traversal prevention)
        $filename = basename($filename);

        // Remove null bytes
        $filename = str_replace("\0", '', $filename);

        // Replace potentially dangerous characters
        $filename = preg_replace('/[^\w\-\.\s]/', '_', $filename);

        // Collapse multiple underscores/spaces
        $filename = preg_replace('/[\s_]+/', '_', $filename);

        // Trim and limit length
        $filename = trim($filename, '._');
        if (strlen($filename) > 255) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 250 - strlen($ext)) . '.' . $ext;
        }

        return $filename ?: 'unnamed_file';
    }

    /**
     * Detect MIME type using finfo
     * Falls back to file extension-based detection if finfo fails
     *
     * @param string $filePath Path to the file
     * @return string|null Detected MIME type
     */
    private function detectMimeType($filePath)
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Try finfo first (most reliable)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);

            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Try mime_content_type as fallback
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        return null;
    }

    /**
     * Format file size for human-readable display
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size
     */
    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }

    /**
     * Get human-readable upload error message
     *
     * @param int $errorCode The upload error code
     * @return string Human-readable error message
     */
    private function getUploadErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }
}
?>
