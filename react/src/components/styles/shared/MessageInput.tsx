/**
 * Message Input Component
 * =======================
 * 
 * Provides the message input area with:
 * - Text input with auto-resize
 * - File attachment button and drop zone
 * - File preview and management
 * - Send button
 * - Character count
 * 
 * Matches the functionality of the vanilla JS implementation.
 * 
 * @module components/MessageInput
 */

import React, { useState, useRef, useCallback, KeyboardEvent, DragEvent } from 'react';
import { Form, Button, Alert, Badge, CloseButton } from 'react-bootstrap';
import type { LlmChatConfig, SelectedFile, FileValidationResult } from '../../../types';
import { FILE_ERRORS, formatBytes } from '../../../types';
import {
  formatFileSize,
  getFileExtension,
  getFileIconByExtension,
  getExtensionBadgeClass,
  generateFileHash,
  truncateFileName,
  isImageExtension
} from '../../../utils/formatters';

/**
 * Props for MessageInput component
 */
interface MessageInputProps {
  /** Callback when message is sent */
  onSend: (message: string, files: SelectedFile[]) => void;
  /** Currently selected files */
  selectedFiles: SelectedFile[];
  /** Callback when files change */
  onFilesChange: (files: SelectedFile[]) => void;
  /** Whether input is disabled */
  disabled: boolean;
  /** Component configuration */
  config: LlmChatConfig;
}

/**
 * Message Input Component
 * 
 * Provides the complete message input UI including file attachments
 */
export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  selectedFiles,
  onFilesChange,
  disabled,
  config
}) => {
  // Local state
  const [message, setMessage] = useState('');
  const [isDragging, setIsDragging] = useState(false);
  const [fileError, setFileError] = useState<string | null>(null);
  
  // Refs
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const fileHashesRef = useRef<Set<string>>(new Set());
  const attachmentIdCounterRef = useRef(0);
  
  // File config
  const { fileConfig } = config;
  const maxLength = 4000; // Default max message length
  
  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    if (disabled) return;
    
    const trimmedMessage = message.trim();
    // Always require a message, even with file attachments
    if (!trimmedMessage) {
      setFileError('Please enter a message');
      return;
    }
    
    onSend(trimmedMessage, selectedFiles);
    setMessage('');
    setFileError(null);
    
    // Reset textarea height
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
  }, [message, selectedFiles, disabled, onSend]);
  
  /**
   * Handle Enter key for submission
   */
  const handleKeyDown = useCallback((e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e as unknown as React.FormEvent);
    }
  }, [handleSubmit]);
  
  /**
   * Auto-resize textarea - smoothly scales up to max height then scrolls
   */
  const adjustTextareaHeight = useCallback(() => {
    const textarea = textareaRef.current;
    if (textarea) {
      // Reset to auto to get proper scrollHeight
      textarea.style.height = 'auto';
      // Calculate new height (min 24px, max 120px for textarea itself)
      const newHeight = Math.min(Math.max(textarea.scrollHeight, 24), 120);
      textarea.style.height = `${newHeight}px`;
    }
  }, []);
  
  /**
   * Handle text input change
   */
  const handleInputChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    setMessage(e.target.value);
    adjustTextareaHeight();
    setFileError(null);
  }, [adjustTextareaHeight]);
  
  /**
   * Validate a file before adding
   */
  const validateFile = useCallback(async (file: File): Promise<FileValidationResult> => {
    // Check file size
    if (file.size > fileConfig.maxFileSize) {
      return {
        valid: false,
        error: FILE_ERRORS.fileTooLarge(file.name, fileConfig.maxFileSize)
      };
    }
    
    // Check for empty files
    if (file.size === 0) {
      return {
        valid: false,
        error: FILE_ERRORS.emptyFile(file.name)
      };
    }
    
    // Check file extension
    const extension = getFileExtension(file.name);
    if (!fileConfig.allowedExtensions.includes(extension)) {
      return {
        valid: false,
        error: FILE_ERRORS.invalidType(file.name, extension)
      };
    }
    
    // Generate file hash for duplicate detection
    const hash = await generateFileHash(file);
    
    // Check for duplicates
    if (fileHashesRef.current.has(hash)) {
      return {
        valid: false,
        error: FILE_ERRORS.duplicateFile(file.name)
      };
    }
    
    return { valid: true, hash };
  }, [fileConfig]);
  
  /**
   * Handle file selection
   */
  const handleFileSelection = useCallback(async (files: FileList | File[]) => {
    const fileArray = Array.from(files);
    setFileError(null);
    
    // Check total files limit
    const currentCount = selectedFiles.length;
    const newCount = fileArray.length;
    const totalCount = currentCount + newCount;
    
    if (totalCount > fileConfig.maxFilesPerMessage) {
      setFileError(FILE_ERRORS.maxFilesExceeded(fileConfig.maxFilesPerMessage));
      return;
    }
    
    // Validate and process each file
    const validFiles: SelectedFile[] = [];
    const errors: string[] = [];
    
    for (const file of fileArray) {
      const result = await validateFile(file);
      
      if (result.valid && result.hash) {
        const attachmentId = `attach_${++attachmentIdCounterRef.current}_${Date.now()}`;
        
        // Generate preview for images
        let previewUrl: string | undefined;
        const extension = getFileExtension(file.name);
        if (isImageExtension(extension, fileConfig.allowedImageExtensions)) {
          previewUrl = await readFileAsDataUrl(file);
        }
        
        validFiles.push({
          id: attachmentId,
          file,
          hash: result.hash,
          previewUrl
        });
        
        // Add hash to tracking set
        fileHashesRef.current.add(result.hash);
      } else if (result.error) {
        errors.push(result.error);
      }
    }
    
    // Show first error if any
    if (errors.length > 0) {
      setFileError(errors[0]);
    }
    
    // Add valid files
    if (validFiles.length > 0) {
      onFilesChange([...selectedFiles, ...validFiles]);
    }
  }, [selectedFiles, fileConfig, validateFile, onFilesChange]);
  
  /**
   * Read file as data URL for preview
   */
  const readFileAsDataUrl = useCallback((file: File): Promise<string> => {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (e) => resolve(e.target?.result as string);
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }, []);
  
  /**
   * Handle file input change
   */
  const handleFileInputChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (files && files.length > 0) {
      handleFileSelection(files);
    }
    // Reset input to allow selecting same file again
    e.target.value = '';
  }, [handleFileSelection]);
  
  /**
   * Handle attachment button click
   */
  const handleAttachmentClick = useCallback(() => {
    fileInputRef.current?.click();
  }, []);
  
  /**
   * Remove a file attachment
   */
  const handleRemoveFile = useCallback((attachmentId: string) => {
    const file = selectedFiles.find(f => f.id === attachmentId);
    if (file) {
      fileHashesRef.current.delete(file.hash);
    }
    onFilesChange(selectedFiles.filter(f => f.id !== attachmentId));
  }, [selectedFiles, onFilesChange]);
  
  /**
   * Clear all attachments
   */
  const handleClearForm = useCallback(() => {
    setMessage('');
    onFilesChange([]);
    fileHashesRef.current.clear();
    setFileError(null);
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
  }, [onFilesChange]);
  
  // Drag and drop handlers
  const handleDragOver = useCallback((e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    if (config.enableFileUploads && config.isVisionModel) {
      setIsDragging(true);
    }
  }, [config.enableFileUploads, config.isVisionModel]);

  const handleDragLeave = useCallback((e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setIsDragging(false);
  }, []);

  const handleDrop = useCallback((e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setIsDragging(false);

    if (!config.enableFileUploads || !config.isVisionModel) return;

    const files = e.dataTransfer.files;
    if (files.length > 0) {
      handleFileSelection(files);
    }
  }, [config.enableFileUploads, config.isVisionModel, handleFileSelection]);
  
  // Character count
  const charCount = message.length;
  const isNearLimit = charCount > maxLength * 0.9;
  
  return (
    <Form onSubmit={handleSubmit}>
      {/* File Error Alert */}
      {fileError && (
        <Alert variant="danger" dismissible onClose={() => setFileError(null)} className="mb-2">
          <small>
            <i className="fas fa-exclamation-circle mr-1"></i>
            {fileError}
          </small>
        </Alert>
      )}
      
      {/* File Attachments Preview */}
      {selectedFiles.length > 0 && (
        <div className="mb-2">
          <div className="d-flex flex-wrap gap-2">
            {selectedFiles.map((item) => (
              <AttachmentItem
                key={item.id}
                item={item}
                onRemove={handleRemoveFile}
                fileConfig={fileConfig}
              />
            ))}
          </div>
        </div>
      )}
      
      {/* Message Input Container */}
      <div
        className={`border rounded ${isDragging && config.isVisionModel ? 'border-primary bg-light' : 'border-secondary'}`}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
      >
        {/* Hidden File Input */}
        <Form.Control
          ref={fileInputRef}
          type="file"
          className="d-none"
          multiple
          accept={config.acceptedFileTypes || fileConfig.allowedExtensions.map(ext => `.${ext}`).join(',')}
          onChange={handleFileInputChange}
        />

        {/* Text Input */}
        <Form.Control
          ref={textareaRef}
          as="textarea"
          placeholder={disabled ? 'Streaming in progress...' : config.messagePlaceholder}
          value={message}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          disabled={disabled}
          maxLength={maxLength}
          rows={1}
          className="border-0 rounded-0"
          style={{ resize: 'none', minHeight: '44px', maxHeight: '120px' }}
        />
        
        {/* Action Buttons */}
        <div className="d-flex justify-content-between align-items-center p-2 border-top bg-light">
          {/* Left side - Attachment button */}
          <div>
            {config.enableFileUploads && config.isVisionModel && (
              <Button
                variant="outline-secondary"
                size="sm"
                onClick={handleAttachmentClick}
                disabled={disabled}
                title="Attach files"
              >
                <i className="fas fa-paperclip"></i>
              </Button>
            )}
            {config.enableFileUploads && !config.isVisionModel && (
              <Button variant="outline-secondary" size="sm" disabled title="Current model does not support image uploads">
                <i className="fas fa-paperclip text-muted"></i>
                <small className="text-muted ml-1">No vision</small>
              </Button>
            )}
          </div>

          {/* Character Count - Center */}
          <small className={isNearLimit ? 'text-warning' : 'text-muted'}>
            {charCount}/{maxLength}
          </small>

          {/* Right side - Clear and Send buttons */}
          <div className="d-flex gap-1">
            <Button
              variant="outline-secondary"
              size="sm"
              onClick={handleClearForm}
              disabled={disabled}
              title="Clear"
            >
              <i className="fas fa-times"></i>
            </Button>

            <Button
              type="submit"
              variant="primary"
              size="sm"
              disabled={disabled || !message.trim()}
              title="Send message"
            >
              {disabled ? (
                <i className="fas fa-spinner fa-spin"></i>
              ) : (
                <i className="fas fa-paper-plane"></i>
              )}
            </Button>
          </div>
        </div>
      </div>
    </Form>
  );
};

/**
 * Attachment Item Component
 * Displays a single file attachment with preview and remove button
 */
interface AttachmentItemProps {
  item: SelectedFile;
  onRemove: (id: string) => void;
  fileConfig: LlmChatConfig['fileConfig'];
}

const AttachmentItem: React.FC<AttachmentItemProps> = ({ item, onRemove, fileConfig }) => {
  const extension = getFileExtension(item.file.name);
  const isImage = isImageExtension(extension, fileConfig.allowedImageExtensions);
  const fileIcon = getFileIconByExtension(extension);
  const badgeClass = getExtensionBadgeClass(
    extension,
    fileConfig.allowedImageExtensions,
    fileConfig.allowedCodeExtensions
  );
  const truncatedName = truncateFileName(item.file.name);
  const fileSize = formatFileSize(item.file.size);
  
  return (
    <div className="d-flex align-items-center bg-light border rounded p-2 position-relative" style={{ minWidth: '160px', maxWidth: '220px' }}>
      <div className="mr-2 flex-shrink-0">
        {isImage && item.previewUrl ? (
          <img
            src={item.previewUrl}
            alt={item.file.name}
            style={{ width: '42px', height: '42px', objectFit: 'cover' }}
            className="rounded border"
          />
        ) : (
          <div className="d-flex align-items-center justify-content-center rounded border bg-white" style={{ width: '42px', height: '42px' }}>
            <i className={`${fileIcon} text-secondary`}></i>
          </div>
        )}
      </div>
      <div className="flex-grow-1 min-w-0">
        <div className="font-weight-medium text-truncate small" title={item.file.name}>
          {truncatedName}
        </div>
        <div className="d-flex align-items-center gap-1 mt-1">
          <Badge variant={badgeClass === 'badge-success' ? 'success' : badgeClass === 'badge-info' ? 'info' : 'secondary'} className="small">
            .{extension}
          </Badge>
          <small className="text-muted">{fileSize}</small>
        </div>
      </div>
      <CloseButton
        className="ml-1"
        onClick={() => onRemove(item.id)}
        title="Remove file"
        style={{ fontSize: '12px' }}
      />
    </div>
  );
};

export default MessageInput;
