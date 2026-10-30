/**
 * Message Input Component
 * =======================
 * 
 * Provides the message input area with:
 * - Text input with auto-resize
 * - File attachment button and drop zone
 * - File preview and management
 * - Speech-to-text input via microphone
 * - Send button
 * - Character count
 * 
 * Matches the functionality of the vanilla JS implementation.
 * 
 * @module components/MessageInput
 */

import React, {
  useState,
  useRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  forwardRef,
  KeyboardEvent,
  DragEvent,
} from 'react';
import { Form, Button, Alert, Badge, CloseButton } from 'react-bootstrap';
import type { LlmChatConfig, SelectedFile, FileValidationResult } from '../../../types';
import { FILE_ERRORS } from '../../../types';
import {
  formatFileSize,
  getFileExtension,
  getFileIconByExtension,
  getExtensionBadgeClass,
  generateFileHash,
  truncateFileName,
  isImageExtension
} from '../../../utils/formatters';
import { useSpeechToText } from '../../../hooks/useSpeechToText';

/**
 * The slice of the chat configuration the input reads - other surfaces
 * (the form interview) pass just this instead of a full LlmChatConfig.
 */
export type MessageInputConfig = Pick<
  LlmChatConfig,
  | 'enableSpeechToText'
  | 'speechToTextModel'
  | 'sectionId'
  | 'fileConfig'
  | 'emptyMessageError'
  | 'enableFileUploads'
  | 'isVisionModel'
  | 'isFloatingMode'
  | 'enableFormMode'
  | 'formModeActiveTitle'
  | 'formModeActiveDescription'
  | 'messagePlaceholder'
  | 'acceptedFileTypes'
  | 'attachFilesTitle'
  | 'noVisionSupportTitle'
  | 'noVisionSupportText'
  | 'clearButtonLabel'
  | 'sendMessageTitle'
  | 'removeFileTitle'
>;

/** Imperative handle for surfaces that drive the text box. */
export interface MessageInputHandle {
  /** Focus the text box without scrolling the page. */
  focus: () => void;
  /** Put text back (a send that never reached the server); only when the box is empty. */
  restore: (text: string) => void;
  /** Replace the box contents (e.g. floating shortcut prefills) and focus for editing. */
  prefill: (text: string) => void;
}

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
  config: MessageInputConfig;
  /** Block sending while something is busy, but keep the text box editable (no focus loss). */
  sendDisabled?: boolean;
  /** Placeholder override (defaults to config.messagePlaceholder). */
  placeholder?: string;
  /** Focus the text box when this value changes (never steals focus from another input). */
  focusSignal?: unknown;
  /** Focus the text box on mount. */
  autoFocus?: boolean;
  /** Speech transport override (other plugin surfaces expose their own contract). */
  speech?: { endpoint?: string; requestFields?: Record<string, string> };
  /** Tooltips of the microphone button. */
  micLabels?: { start: string; stop: string; busy: string };
  /**
   * Look of the composer. `bar` (default): text box above a tinted tool bar.
   * `field`: one rounded field - the text grows with what is typed and the
   * tools sit on a quiet row underneath (themed by the --llm-composer-* custom
   * properties, see LlmChat.css).
   */
  variant?: 'bar' | 'field';
}

/**
 * Grow the text box with its content, up to the CSS `max-height` (so a theme
 * can change the cap); beyond that it scrolls inside. A cap that is not a
 * pixel length (none, %) leaves the height unlimited.
 */
function fitTextarea(textarea: HTMLTextAreaElement): void {
  textarea.style.height = 'auto';
  const maxHeight = getComputedStyle(textarea).maxHeight;
  const cap = maxHeight.endsWith('px') ? parseFloat(maxHeight) : Infinity;
  textarea.style.height = `${Math.min(Math.max(textarea.scrollHeight, 24), cap)}px`;
}

/**
 * Message Input Component
 * 
 * Provides the complete message input UI including file attachments
 */
export const MessageInput = forwardRef<MessageInputHandle, MessageInputProps>(function MessageInput({
  onSend,
  selectedFiles,
  onFilesChange,
  disabled,
  config,
  sendDisabled = false,
  placeholder,
  focusSignal,
  autoFocus = false,
  speech: speechOptions,
  micLabels,
  variant = 'bar',
}, ref) {
  // Local state
  const [message, setMessage] = useState('');
  const [isDragging, setIsDragging] = useState(false);
  const [fileError, setFileError] = useState<string | null>(null);
  
  // Refs
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const fileHashesRef = useRef<Set<string>>(new Set());
  const attachmentIdCounterRef = useRef(0);
  
  // Ref to always have current message value (avoids stale closure issues)
  const messageRef = useRef<string>(message);
  
  // Keep messageRef in sync with message state
  useEffect(() => {
    messageRef.current = message;
  }, [message]);
  
  /**
   * Safely append transcribed text to the message textarea.
   * NEVER overwrites existing text - always inserts at cursor position.
   */
  const appendTranscribedText = useCallback((transcribedText: string) => {
    const textarea = textareaRef.current;
    if (!textarea) return;
    
    const currentMessage = messageRef.current;
    const cursorPos = textarea.selectionStart ?? currentMessage.length;
    
    const textBefore = currentMessage.substring(0, cursorPos);
    const textAfter = currentMessage.substring(cursorPos);
    
    const needsSpaceBefore = textBefore.length > 0 && !/[\s]$/.test(textBefore);
    const spaceBefore = needsSpaceBefore ? ' ' : '';
    const trailingSpace = ' ';
    const newMessage = textBefore + spaceBefore + transcribedText + trailingSpace + textAfter;
    const newCursorPos = textBefore.length + spaceBefore.length + transcribedText.length + trailingSpace.length;
    
    setMessage(newMessage);
    messageRef.current = newMessage;
    
    requestAnimationFrame(() => {
      if (textarea) {
        textarea.focus();
        textarea.setSelectionRange(newCursorPos, newCursorPos);
        fitTextarea(textarea);
      }
    });
  }, []);
  
  // Speech-to-text hook
  const speech = useSpeechToText({
    enabled: config.enableSpeechToText,
    model: config.speechToTextModel || '',
    sectionId: config.sectionId,
    onTranscription: appendTranscribedText,
    endpoint: speechOptions?.endpoint,
    requestFields: speechOptions?.requestFields,
  });
  
  // File config
  const { fileConfig } = config;
  const maxLength = 4000; // Default max message length
  
  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    if (disabled || sendDisabled) return;
    
    const trimmedMessage = message.trim();
    // Always require a message, even with file attachments
    if (!trimmedMessage) {
      setFileError(config.emptyMessageError);
      return;
    }
    
    onSend(trimmedMessage, selectedFiles);
    setMessage('');
    setFileError(null);
    
    // Clear file hashes so user can attach the same file again in future messages
    fileHashesRef.current.clear();
    
    // Reset textarea height
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
  }, [message, selectedFiles, disabled, sendDisabled, onSend, config.emptyMessageError]);
  
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
    if (textareaRef.current) {
      fitTextarea(textareaRef.current);
    }
  }, []);
  
  useImperativeHandle(ref, () => ({
    focus: () => textareaRef.current?.focus({ preventScroll: true }),
    restore: (text: string) => {
      setMessage((current) => (current === '' ? text : current));
      requestAnimationFrame(adjustTextareaHeight);
    },
    prefill: (text: string) => {
      const next = text ?? '';
      setMessage(next);
      messageRef.current = next;
      requestAnimationFrame(() => {
        adjustTextareaHeight();
        const textarea = textareaRef.current;
        if (!textarea) return;
        textarea.focus({ preventScroll: true });
        const pos = next.length;
        textarea.setSelectionRange(pos, pos);
      });
    },
  }), [adjustTextareaHeight]);

  // Focus on mount / when the signal changes - but never take the cursor
  // away from another editable control the participant is working in.
  useEffect(() => {
    if (!autoFocus && focusSignal === undefined) return;
    const el = textareaRef.current;
    if (!el || disabled) return;
    const active = document.activeElement as HTMLElement | null;
    const editing =
      !!active &&
      active !== el &&
      (active.tagName === 'INPUT' ||
        active.tagName === 'TEXTAREA' ||
        active.tagName === 'SELECT' ||
        active.isContentEditable);
    if (!editing) {
      el.focus({ preventScroll: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusSignal, disabled]);

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
  const field = variant === 'field';

  // In form mode, hide the text input entirely
  if (config.enableFormMode) {
    return (
      <div className={`form-mode-input-disabled text-center ${config.isFloatingMode ? 'py-1' : 'py-4'} ${config.isFloatingMode ? 'px-2' : 'px-3'} bg-light rounded border`}>
        <i className="fas fa-list-ul fa-2x text-muted mb-2"></i>
        <p className="text-muted mb-0 small">
          <strong>{config.formModeActiveTitle}</strong><br />
          {config.formModeActiveDescription}
        </p>
      </div>
    );
  }
  
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
      
      {/* Speech Error Alert */}
      {speech.error && (
        <Alert variant="warning" dismissible onClose={speech.clearError} className="mb-2">
          <small>
            <i className="fas fa-microphone-slash mr-1"></i>
            {speech.error}
          </small>
        </Alert>
      )}
      
      {/* File Attachments Preview */}
      {selectedFiles.length > 0 && (
        <div className="mb-2">
          <div className="d-flex flex-wrap attachment-preview-list">
            {selectedFiles.map((item) => (
              <AttachmentItem
                key={item.id}
                item={item}
                onRemove={handleRemoveFile}
                fileConfig={fileConfig}
                config={config}
              />
            ))}
          </div>
        </div>
      )}
      
      {/* Message Input Container */}
      <div
        className={`message-input-container ${field ? 'is-field' : 'p-1 border rounded'} ${isDragging && config.isVisionModel ? 'border-primary bg-light' : ''}`}
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
          placeholder={placeholder ?? config.messagePlaceholder}
          value={message}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          disabled={disabled}
          maxLength={maxLength}
          rows={1}
          className="border-0 rounded-0 message-input-textarea llm-slim-scroll"
        />
        
        {/* Action Buttons */}
        <div className={`message-input-actions ${field ? '' : 'd-flex justify-content-between align-items-center p-2 border-top bg-light'}`}>
          {/* Left side - Attachment and Microphone buttons */}
          <div className="d-flex align-items-center">
            {config.enableFileUploads && config.isVisionModel && (
              <Button
                variant="outline-secondary"
                size="sm"
                onClick={handleAttachmentClick}
                disabled={disabled || speech.isRecording}
                title={config.attachFilesTitle}
                className="message-action-btn"
              >
                <i className="fas fa-paperclip"></i>
              </Button>
            )}
            {config.enableFileUploads && !config.isVisionModel && (
              <Button variant="outline-secondary" size="sm" disabled title={config.noVisionSupportTitle}>
                <i className="fas fa-paperclip text-muted"></i>
                <small className="text-muted ml-1">{config.noVisionSupportText}</small>
              </Button>
            )}
            
            {/* Speech-to-Text Microphone Button */}
            {speech.isAvailable && (
                <Button
                  variant={speech.isRecording ? 'danger' : 'outline-secondary'}
                  size="sm"
                  onClick={speech.toggleRecording}
                  disabled={disabled || speech.isProcessing}
                  title={
                    speech.isProcessing
                      ? (micLabels?.busy ?? 'Transcribing...')
                      : speech.isRecording
                        ? (micLabels?.stop ?? 'Stop recording')
                        : (micLabels?.start ?? 'Start voice input')
                  }
                  className={`message-action-btn ml-1 ${speech.isRecording ? 'speech-recording-active' : ''}`}
                >
                  {speech.isProcessing ? (
                    <i className="fas fa-spinner fa-spin"></i>
                  ) : speech.isRecording ? (
                    <i className="fas fa-stop"></i>
                  ) : (
                    <i className="fas fa-microphone"></i>
                  )}
                </Button>
              )}
          </div>

          {/* Character Count - Center */}
          <small className={isNearLimit ? 'text-warning' : 'text-muted'}>
            {charCount}/{maxLength}
          </small>

          {/* Right side - Clear and Send buttons */}
          <div className="d-flex gap-2 flex-wrap justify-content-end">
            <Button
              variant="outline-secondary"
              size="sm"
              onClick={handleClearForm}
              disabled={disabled}
              title={config.clearButtonLabel}
              className="message-action-btn message-clear-btn mr-1"
            >
              <i className="fas fa-times"></i>
            </Button>

            <Button
              type="submit"
              variant="primary"
              size="sm"
              disabled={disabled || sendDisabled || !message.trim()}
              title={config.sendMessageTitle}
              className="message-action-btn message-send-btn"
            >
              {disabled || sendDisabled ? (
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
});

/**
 * Attachment Item Component
 * Displays a single file attachment with preview and remove button
 */
interface AttachmentItemProps {
  item: SelectedFile;
  onRemove: (id: string) => void;
  fileConfig: LlmChatConfig['fileConfig'];
}

/** AttachmentItem React component. */
const AttachmentItem: React.FC<AttachmentItemProps & { config: MessageInputConfig }> = ({ item, onRemove, fileConfig, config }) => {
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
        title={config.removeFileTitle}
        style={{ fontSize: '12px' }}
      />
    </div>
  );
};

/** Default export for this module. */
export default MessageInput;
