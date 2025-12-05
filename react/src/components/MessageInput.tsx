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
import type { LlmChatConfig, SelectedFile, FileValidationResult } from '../types';
import { FILE_ERRORS, formatBytes } from '../types';
import {
  formatFileSize,
  getFileExtension,
  getFileIconByExtension,
  getExtensionBadgeClass,
  generateFileHash,
  truncateFileName,
  isImageExtension
} from '../utils/formatters';

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
    <form id="message-form" onSubmit={handleSubmit} className="message-form-modern">
      {/* File Error Alert */}
      {fileError && (
        <div className="alert alert-danger alert-dismissible fade show mb-2" role="alert">
          <small>
            <i className="fas fa-exclamation-circle mr-1"></i>
            {fileError}
          </small>
          <button
            type="button"
            className="close"
            onClick={() => setFileError(null)}
            aria-label="Close"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      )}
      
      {/* File Attachments Preview */}
      {selectedFiles.length > 0 && (
        <div id="file-attachments" className="mb-2">
          <div id="attachments-list" className="d-flex flex-wrap gap-2">
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
      
      {/* Modern Message Input Container */}
      <div
        className={`message-input-container ${isDragging && config.isVisionModel ? 'drag-over' : ''}`}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
      >
        {/* Textarea Area - Scrolls internally */}
        <div className="message-input-textarea-wrapper">
          {/* Hidden File Input */}
          <input
            ref={fileInputRef}
            type="file"
            id="file-upload"
            className="d-none"
            multiple
            accept={config.acceptedFileTypes || fileConfig.allowedExtensions.map(ext => `.${ext}`).join(',')}
            onChange={handleFileInputChange}
          />
          
          {/* Text Input */}
          <textarea
            ref={textareaRef}
            id="message-input"
            name="message"
            className="message-input-textarea"
            placeholder={disabled ? 'Streaming in progress...' : config.messagePlaceholder}
            value={message}
            onChange={handleInputChange}
            onKeyDown={handleKeyDown}
            disabled={disabled}
            maxLength={maxLength}
            rows={1}
          />
        </div>
        
        {/* Fixed Button Container - Always at bottom */}
        <div className="message-input-actions">
          {/* Left side - Attachment button */}
          <div className="message-input-actions-left">
            {config.enableFileUploads && config.isVisionModel && (
              <button
                type="button"
                className="message-action-btn attachment-btn"
                onClick={handleAttachmentClick}
                disabled={disabled}
                title="Attach files"
              >
                <i className="fas fa-paperclip"></i>
              </button>
            )}
            {config.enableFileUploads && !config.isVisionModel && (
              <div className="message-action-btn attachment-btn disabled" title="Current model does not support image uploads">
                <i className="fas fa-paperclip text-muted"></i>
                <small className="text-muted ml-1">No vision</small>
              </div>
            )}
          </div>
          
          {/* Character Count - Center */}
          <div className="message-input-char-count">
            <small className={isNearLimit ? 'text-warning' : 'text-muted'}>
              {charCount}/{maxLength}
            </small>
          </div>
          
          {/* Right side - Clear and Send buttons */}
          <div className="message-input-actions-right">
            <button
              type="button"
              className="message-action-btn clear-btn"
              onClick={handleClearForm}
              disabled={disabled}
              title="Clear"
            >
              <i className="fas fa-times"></i>
            </button>
            
            <button
              type="submit"
              className="message-action-btn send-btn"
              disabled={disabled || !message.trim()}
              title="Send message"
            >
              {disabled ? (
                <i className="fas fa-spinner fa-spin"></i>
              ) : (
                <i className="fas fa-paper-plane"></i>
              )}
            </button>
          </div>
        </div>
      </div>
    </form>
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
    <div className="attachment-item" data-attachment-id={item.id}>
      <div className="attachment-preview">
        {isImage && item.previewUrl ? (
          <img
            src={item.previewUrl}
            alt={item.file.name}
            className="attachment-thumbnail"
          />
        ) : (
          <div className="attachment-icon">
            <i className={fileIcon}></i>
          </div>
        )}
      </div>
      <div className="attachment-info">
        <span className="attachment-name" title={item.file.name}>
          {truncatedName}
        </span>
        <span className="attachment-meta">
          <span className={`badge ${badgeClass}`}>.{extension}</span>
          <span className="attachment-size">{fileSize}</span>
        </span>
      </div>
      <button
        type="button"
        className="btn btn-sm btn-link remove-attachment text-danger"
        onClick={() => onRemove(item.id)}
        title="Remove file"
      >
        <i className="fas fa-times-circle"></i>
      </button>
    </div>
  );
};

export default MessageInput;
