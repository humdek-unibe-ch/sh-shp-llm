<?php
/**
 * LLM API Formatter Service
 * Handles message formatting and API conversion for LLM chat
 */

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
     * @return array Messages formatted for OpenAI-compatible API
     */
    public function convertToApiFormat($messages, $model = null)
    {
        $api_messages = [];
        $configuredModel = $model ?? $this->model ?? $this->getConfiguredModel();
        $isVisionModel = llm_is_vision_model($configuredModel);

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
        // Go up 3 levels from server/plugins/sh-shp-llm/server/service/ to reach plugin root
        $fullPath = __DIR__ . "/../../../{$path}";
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
     * Encode image file as base64 data URL for vision API
     *
     * @param string $fullPath Full path to image file
     * @param array $attachment Attachment info
     * @return array|null Image content part for API
     */
    private function encodeImageForApi($fullPath, $attachment)
    {
        $imageData = file_get_contents($fullPath);
        if ($imageData === false) {
            return null;
        }

        $mimeType = $attachment['type'] ?? $this->detectMimeType($fullPath) ?? 'image/jpeg';
        $base64Data = base64_encode($imageData);

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:{$mimeType};base64,{$base64Data}"
            ]
        ];
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
