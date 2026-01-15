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

import React, { useState, useRef, useCallback, useEffect, KeyboardEvent, DragEvent } from 'react';
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
  
  // Speech-to-text state
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessingSpeech, setIsProcessingSpeech] = useState(false);
  const [speechError, setSpeechError] = useState<string | null>(null);
  
  // Refs
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const fileHashesRef = useRef<Set<string>>(new Set());
  const attachmentIdCounterRef = useRef(0);
  
  // Speech-to-text refs
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  
  // Maximum recording duration (60 seconds) to prevent payload too large errors
  const MAX_RECORDING_DURATION_MS = 60000;
  
  // File config
  const { fileConfig } = config;
  const maxLength = 4000; // Default max message length
  
  // Check if speech-to-text is available
  const isSpeechAvailable = config.enableSpeechToText &&
    config.speechToTextModel &&
    typeof navigator !== 'undefined' &&
    navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function';


  // Cleanup audio stream and timeout on unmount
  useEffect(() => {
    return () => {
      if (audioStreamRef.current) {
        audioStreamRef.current.getTracks().forEach(track => track.stop());
      }
      if (recordingTimeoutRef.current) {
        clearTimeout(recordingTimeoutRef.current);
      }
    };
  }, []);
  
  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    if (disabled) return;
    
    const trimmedMessage = message.trim();
    // Always require a message, even with file attachments
    if (!trimmedMessage) {
      setFileError(config.emptyMessageError);
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

  // ===== Speech-to-Text Handlers =====

  /**
   * Start recording audio from the microphone
   */
  const handleStartRecording = useCallback(async () => {
    if (!isSpeechAvailable || isRecording) return;

    setSpeechError(null);

    try {
      // Request microphone permission
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          sampleRate: 16000
        }
      });

      audioStreamRef.current = stream;
      audioChunksRef.current = [];

      // Create MediaRecorder with WebM/Opus format (widely supported)
      // Use lower bitrate to reduce file size and avoid "Payload Too Large" errors
      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : 'audio/mp4';

      // Configure MediaRecorder with lower bitrate for smaller file sizes
      // 16kbps is sufficient for speech recognition
      const mediaRecorder = new MediaRecorder(stream, { 
        mimeType,
        audioBitsPerSecond: 16000 // 16 kbps - optimized for speech
      });
      mediaRecorderRef.current = mediaRecorder;

      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      mediaRecorder.onstop = async () => {
        // Process the recorded audio
        if (audioChunksRef.current.length > 0) {
          const audioBlob = new Blob(audioChunksRef.current, { type: mimeType });
          await processAudioBlob(audioBlob);
        }
        
        // Cleanup
        audioChunksRef.current = [];
      };

      // Start recording
      mediaRecorder.start();
      setIsRecording(true);
      
      // Auto-stop recording after max duration to prevent payload too large errors
      recordingTimeoutRef.current = setTimeout(() => {
        if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
          console.log('Auto-stopping recording after max duration');
          handleStopRecording();
        }
      }, MAX_RECORDING_DURATION_MS);

    } catch (error: unknown) {
      console.error('Failed to start recording:', error);
      const errorMessage = error instanceof Error ? error.message : 'Unknown error';
      
      if (errorMessage.includes('Permission denied') || errorMessage.includes('NotAllowedError')) {
        setSpeechError('Microphone access denied. Please allow microphone access in your browser settings.');
      } else {
        setSpeechError('Failed to start recording: ' + errorMessage);
      }
    }
  }, [isSpeechAvailable, isRecording]);

  /**
   * Stop recording and process the audio
   */
  const handleStopRecording = useCallback(() => {
    if (!isRecording || !mediaRecorderRef.current) return;

    // Clear the auto-stop timeout
    if (recordingTimeoutRef.current) {
      clearTimeout(recordingTimeoutRef.current);
      recordingTimeoutRef.current = null;
    }

    // Stop the MediaRecorder (this triggers onstop which processes the audio)
    if (mediaRecorderRef.current.state === 'recording') {
      mediaRecorderRef.current.stop();
    }

    // Stop all tracks in the stream
    if (audioStreamRef.current) {
      audioStreamRef.current.getTracks().forEach(track => track.stop());
      audioStreamRef.current = null;
    }

    setIsRecording(false);
  }, [isRecording]);

  /**
   * Process the recorded audio blob and send to server for transcription
   */
  const processAudioBlob = useCallback(async (audioBlob: Blob) => {
    if (audioBlob.size === 0) {
      setSpeechError('No audio recorded');
      return;
    }

    setIsProcessingSpeech(true);
    setSpeechError(null);

    try {
      // Create form data for the API request
      const formData = new FormData();
      formData.append('audio', audioBlob, 'recording.webm');
      formData.append('action', 'speech_transcribe');
      formData.append('section_id', config.sectionId?.toString() || '0');

      // Send to the server for transcription
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.success && result.text) {
        // ALWAYS append transcribed text at the end - simple and reliable
        const textarea = textareaRef.current;
        const transcribedText = result.text.trim();
        
        // Add space before if there's existing text that doesn't end with space/newline
        const spaceBefore = message.length > 0 && !message.endsWith(' ') && !message.endsWith('\n') ? ' ' : '';
        
        const newText = message + spaceBefore + transcribedText;
        setMessage(newText);
        
        // Move cursor to the end after state update
        setTimeout(() => {
          if (textarea) {
            textarea.focus();
            const endPos = newText.length;
            textarea.setSelectionRange(endPos, endPos);
          }
          adjustTextareaHeight();
        }, 0);
      } else if (result.success && !result.text) {
        setSpeechError('No speech detected. Please try again.');
      } else {
        setSpeechError(result.error || 'Speech transcription failed');
      }

    } catch (error: unknown) {
      console.error('Speech processing error:', error);
      const errorMessage = error instanceof Error ? error.message : 'Unknown error';
      setSpeechError('Speech processing failed: ' + errorMessage);
    } finally {
      setIsProcessingSpeech(false);
    }
  }, [config.sectionId, message, adjustTextareaHeight]);

  /**
   * Toggle recording state
   */
  const handleMicrophoneClick = useCallback(() => {
    if (isRecording) {
      handleStopRecording();
    } else {
      handleStartRecording();
    }
  }, [isRecording, handleStartRecording, handleStopRecording]);
  
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
      {speechError && (
        <Alert variant="warning" dismissible onClose={() => setSpeechError(null)} className="mb-2">
          <small>
            <i className="fas fa-microphone-slash mr-1"></i>
            {speechError}
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
        className={`message-input-container p-1 border rounded ${isDragging && config.isVisionModel ? 'border-primary bg-light' : ''}`}
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
          placeholder={config.messagePlaceholder}
          value={message}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          disabled={disabled}
          maxLength={maxLength}
          rows={1}
          className="border-0 rounded-0 message-input-textarea"
          style={{ resize: 'none', minHeight: '44px', maxHeight: '120px' }}
        />
        
        {/* Action Buttons */}
        <div className="d-flex justify-content-between align-items-center p-2 border-top bg-light message-input-actions">
          {/* Left side - Attachment and Microphone buttons */}
          <div className="d-flex align-items-center">
            {config.enableFileUploads && config.isVisionModel && (
              <Button
                variant="outline-secondary"
                size="sm"
                onClick={handleAttachmentClick}
                disabled={disabled || isRecording}
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
            {isSpeechAvailable && (
                <Button
                  variant={isRecording ? 'danger' : 'outline-secondary'}
                  size="sm"
                  onClick={handleMicrophoneClick}
                  disabled={disabled || isProcessingSpeech}
                  title={isRecording ? 'Stop recording' : 'Start voice input'}
                  className={`message-action-btn ml-1 ${isRecording ? 'speech-recording-active' : ''}`}
                >
                  {isProcessingSpeech ? (
                    <i className="fas fa-spinner fa-spin"></i>
                  ) : isRecording ? (
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
              disabled={disabled || !message.trim()}
              title={config.sendMessageTitle}
              className="message-action-btn message-send-btn"
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

const AttachmentItem: React.FC<AttachmentItemProps & { config: LlmChatConfig }> = ({ item, onRemove, fileConfig, config }) => {
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

export default MessageInput;
