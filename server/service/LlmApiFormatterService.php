<?php
/**
 * LLM API Formatter Service
 * Handles message formatting and API conversion for LLM chat
 */

// Include utility classes
require_once __DIR__ . "/LlmFileUtility.php";

class LlmApiFormatterService
{
    private $model;

    /**
     * Constructor
     * 
     * @param string|null $model The model to use for determining capabilities
     */
    public function __construct($model = null)
    {
        $this->model = $model;
    }

    /**
     * Set the model for this formatter
     * 
     * @param string $model The model identifier
     */
    public function setModel($model)
    {
        $this->model = $model;
    }

    /**
     * Convert messages to OpenAI API format
     * Handles multimodal content for vision models (images, documents)
     *
     * @param array $messages Array of message objects from database
     * @param string|null $model Optional model override
     * @param array|null $context_messages Optional context/system messages to prepend
     * @return array Messages formatted for OpenAI-compatible API
     */
    public function convertToApiFormat($messages, $model = null, $context_messages = null)
    {
        $api_messages = [];
        $configuredModel = $model ?? $this->model ?? $this->getConfiguredModel();
        $isVisionModel = llm_is_vision_model($configuredModel);

        // Prepend context messages if provided
        if (!empty($context_messages) && is_array($context_messages)) {
            foreach ($context_messages as $ctx_msg) {
                if (isset($ctx_msg['role']) && isset($ctx_msg['content'])) {
                    $api_messages[] = [
                        'role' => $ctx_msg['role'],
                        'content' => $ctx_msg['content']
                    ];
                }
            }
        }

        foreach ($messages as $index => $message) {
            // Skip empty assistant messages (failed previous attempts)
            if ($message['role'] === 'assistant' && empty(trim($message['content'] ?? ''))) {
                continue;
            }
            
            // Skip user messages with no content (shouldn't happen, but just in case)
            if ($message['role'] === 'user' && empty(trim($message['content'] ?? ''))) {
                continue;
            }
            
            $api_message = [
                'role' => $message['role'],
                'content' => $message['content']
            ];

            // Handle attachments for multimodal content
            $attachments = null;
            if (!empty($message['attachments'])) {
                // Attachments stored as JSON
                $decoded = json_decode($message['attachments'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $attachments = $decoded;
                }
            }

            if (!empty($attachments)) {
                $contentParts = [];

                // Add text content first
                if (!empty($message['content'])) {
                    $contentParts[] = [
                        'type' => 'text',
                        'text' => $message['content']
                    ];
                }

                // Add each attachment based on type
                foreach ($attachments as $attachment) {
                    $attachmentContent = $this->formatAttachmentForApi($attachment, $isVisionModel);
                    if ($attachmentContent) {
                        $contentParts[] = $attachmentContent;
                    }
                }

                // Only use multimodal format if we have attachments
                if (count($contentParts) > 1 || (count($contentParts) === 1 && $contentParts[0]['type'] !== 'text')) {
                    $api_message['content'] = $contentParts;
                }
            }

            $api_messages[] = $api_message;
        }

        return $api_messages;
    }

    /**
     * Format an attachment for API request
     *
     * @param array $attachment Attachment info array
     * @param bool $isVisionModel Whether the model supports vision
     * @return array|null Formatted content part for API
     */
    private function formatAttachmentForApi($attachment, $isVisionModel)
    {
        $path = $attachment['path'] ?? '';
        // Go up 2 levels from server/plugins/sh-shp-llm/server/service/ to reach plugin root
        $fullPath = __DIR__ . "/../../{$path}";
        $originalName = $attachment['original_name'] ?? basename($path);

        if (!file_exists($fullPath)) {
            // Return a note about the missing attachment instead of null
            return [
                'type' => 'text',
                'text' => "\n[Attachment not found: {$originalName}]\n"
            ];
        }

        $isImage = $attachment['is_image'] ?? $this->isImagePath($path);

        if ($isImage) {
            if ($isVisionModel) {
                // Encode image as base64 data URL for vision models
                return $this->encodeImageForApi($fullPath, $attachment);
            } else {
                // For non-vision models, still try to send the image as base64
                // Many modern APIs can handle images even without explicit vision support
                // If the API can't handle it, it will simply ignore the image
                $encoded = $this->encodeImageForApi($fullPath, $attachment);
                if ($encoded) {
                    return $encoded;
                }
                // Fallback: just mention the image was attached
                return [
                    'type' => 'text',
                    'text' => "\n[Image attached: {$originalName}]\n"
                ];
            }
        } else {
            // For documents, include file content as text
            return $this->encodeDocumentForApi($fullPath, $attachment);
        }
    }

    /**
     * Maximum image dimension (width or height) in pixels
     * Images larger than this will be resized to fit within these bounds
     * Vision models work well with images up to 768-1024px
     */
    const MAX_IMAGE_DIMENSION = 768;

    /**
     * Maximum image file size in bytes (10MB) - original file limit
     * Files larger than this are rejected outright
     */
    const MAX_IMAGE_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Target maximum base64 size after resizing (~300KB binary = ~400KB base64)
     * This keeps images under 1MB total and reduces token count
     */
    const TARGET_BASE64_SIZE = 400000;

    /**
     * Maximum allowed output size in bytes (~750KB = ~1MB base64)
     * Images will be progressively compressed to fit under this limit
     */
    const MAX_OUTPUT_SIZE = 750000;

    /**
     * JPEG quality for resized images (0-100)
     * 70% provides good balance of quality and size
     */
    const RESIZE_JPEG_QUALITY = 70;

    /**
     * Encode image file as base64 data URL for vision API
     * 
     * ALWAYS resizes and optimizes images for best LLM performance.
     * Vision models work well with smaller images and this reduces token count.
     * Uses GD library for image processing.
     *
     * @param string $fullPath Full path to image file
     * @param array $attachment Attachment info
     * @return array|null Image content part for API
     */
    private function encodeImageForApi($fullPath, $attachment)
    {
        $originalName = $attachment['original_name'] ?? basename($fullPath);
        
        // Check file size before reading
        $fileSize = filesize($fullPath);
        if ($fileSize === false) {
            return [
                'type' => 'text',
                'text' => "\n[Could not read image: {$originalName}]\n"
            ];
        }

        // Reject extremely large files
        if ($fileSize > self::MAX_IMAGE_FILE_SIZE) {
            $sizeMb = round($fileSize / (1024 * 1024), 2);
            $maxMb = round(self::MAX_IMAGE_FILE_SIZE / (1024 * 1024), 1);
            $this->logWarning("Image file too large: {$originalName} ({$sizeMb}MB > {$maxMb}MB limit)");
            return [
                'type' => 'text',
                'text' => "\n[Image file too large: {$originalName} ({$sizeMb}MB). Maximum file size is {$maxMb}MB.]\n"
            ];
        }

        $mimeType = $attachment['type'] ?? LlmFileUtility::detectMimeType($fullPath) ?? 'image/jpeg';
        
        // ALWAYS optimize images for LLM - smaller is better for vision models
        $imageData = $this->getOptimizedImageData($fullPath, $mimeType, $originalName);
        
        if ($imageData === false || $imageData === null) {
            return [
                'type' => 'text',
                'text' => "\n[Could not process image: {$originalName}]\n"
            ];
        }

        $base64Data = base64_encode($imageData);
        
        // Log optimization results
        $originalSizeKb = round($fileSize / 1024, 1);
        $finalSizeKb = round(strlen($imageData) / 1024, 1);
        $reduction = round((1 - $finalSizeKb / $originalSizeKb) * 100, 1);
        $this->logDebug("Image optimized for API: {$originalName} ({$originalSizeKb}KB -> {$finalSizeKb}KB, -{$reduction}%)");

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:image/jpeg;base64,{$base64Data}"
            ]
        ];
    }

    /**
     * Get optimized image data for API
     * 
     * ALWAYS resizes and optimizes images for vision models.
     * Vision models work well with smaller images (768px max dimension).
     * Ensures output is under 1MB for optimal token usage.
     *
     * @param string $fullPath Full path to image file
     * @param string $mimeType Original MIME type
     * @param string $originalName Original filename for logging
     * @return string|false Optimized image data or false on failure
     */
    private function getOptimizedImageData($fullPath, $mimeType, $originalName)
    {
        // Check if GD is available
        if (!function_exists('imagecreatefromstring')) {
            // GD not available, return original file with warning
            $this->logWarning("GD library not available, using original image (may cause token overflow): {$originalName}");
            return file_get_contents($fullPath);
        }

        $imageData = file_get_contents($fullPath);
        if ($imageData === false) {
            return false;
        }

        // Get image dimensions
        $imageInfo = getimagesizefromstring($imageData);
        if ($imageInfo === false) {
            // Not a valid image
            $this->logError("Invalid image data: {$originalName}");
            return false;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // ALWAYS resize to max dimension for optimal LLM processing
        $newWidth = $width;
        $newHeight = $height;
        
        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) {
            $ratio = min(self::MAX_IMAGE_DIMENSION / $width, self::MAX_IMAGE_DIMENSION / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
        }

        // Create image resource from string
        $srcImage = @imagecreatefromstring($imageData);
        if ($srcImage === false) {
            $this->logError("Could not create image from data: {$originalName}");
            return false;
        }

        // Create destination image
        $dstImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($dstImage === false) {
            imagedestroy($srcImage);
            return false;
        }

        // Fill with white background (removes transparency, reduces size)
        $white = imagecolorallocate($dstImage, 255, 255, 255);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $white);

        // Resize with high quality resampling
        $success = imagecopyresampled(
            $dstImage, $srcImage,
            0, 0, 0, 0,
            $newWidth, $newHeight, $width, $height
        );

        imagedestroy($srcImage);

        if (!$success) {
            imagedestroy($dstImage);
            return false;
        }

        // Progressive quality reduction to ensure output is under MAX_OUTPUT_SIZE
        $quality = self::RESIZE_JPEG_QUALITY;
        $resizedData = null;
        
        while ($quality >= 30) {
            ob_start();
            imagejpeg($dstImage, null, $quality);
            $resizedData = ob_get_clean();
            
            if (strlen($resizedData) <= self::MAX_OUTPUT_SIZE) {
                break; // Size is acceptable
            }
            
            $quality -= 10; // Try lower quality
        }

        imagedestroy($dstImage);

        // If still too large after quality reduction, resize dimensions further
        if ($resizedData && strlen($resizedData) > self::MAX_OUTPUT_SIZE) {
            $this->logWarning("Image still too large after quality reduction, reducing dimensions further: {$originalName}");
            
            // Reduce dimensions by 50%
            $smallerWidth = (int)($newWidth * 0.5);
            $smallerHeight = (int)($newHeight * 0.5);
            
            $srcImage = @imagecreatefromstring($imageData);
            if ($srcImage !== false) {
                $dstImage = imagecreatetruecolor($smallerWidth, $smallerHeight);
                if ($dstImage !== false) {
                    $white = imagecolorallocate($dstImage, 255, 255, 255);
                    imagefilledrectangle($dstImage, 0, 0, $smallerWidth, $smallerHeight, $white);
                    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $smallerWidth, $smallerHeight, $width, $height);
                    imagedestroy($srcImage);
                    
                    ob_start();
                    imagejpeg($dstImage, null, 60);
                    $resizedData = ob_get_clean();
                    imagedestroy($dstImage);
                }
            }
        }

        return $resizedData ?: false;
    }


    /**
     * Encode document file content for API
     * Reads text-based documents and includes their content in the message
     *
     * @param string $fullPath Full path to document file
     * @param array $attachment Attachment info
     * @return array|null Text content part for API
     */
    private function encodeDocumentForApi($fullPath, $attachment)
    {
        $originalName = $attachment['original_name'] ?? basename($fullPath);
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        // For text-based files, include the content
        $textExtensions = ['txt', 'md', 'csv', 'json', 'xml', 'py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml'];

        if (in_array($extension, $textExtensions)) {
            $content = file_get_contents($fullPath);
            if ($content !== false) {
                // Limit content size to prevent API issues (max 50KB of text per file)
                $maxTextSize = 50 * 1024;
                if (strlen($content) > $maxTextSize) {
                    $content = substr($content, 0, $maxTextSize) . "\n\n[Content truncated due to size limit...]";
                }

                return [
                    'type' => 'text',
                    'text' => "\n\n--- File: {$originalName} ---\n```{$extension}\n{$content}\n```\n--- End of file ---\n"
                ];
            }
        }

        // For binary files like PDF, just note the attachment
        return [
            'type' => 'text',
            'text' => "\n[Attached file: {$originalName}]\n"
        ];
    }

    /**
     * Check if a path is an image based on extension
     *
     * @param string $path File path
     * @return bool True if path is an image
     */
    private function isImagePath($path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, LLM_ALLOWED_IMAGE_EXTENSIONS);
    }


    /**
     * Get configured model from services
     *
     * @return string Configured model name
     */
    private function getConfiguredModel()
    {
        // This would need to be injected or accessed via services
        // For now, return a default
        return 'qwen3-vl-8b-instruct';
    }
}
?>
