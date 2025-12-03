/**
 * LLM Chat Component JavaScript
 * ===================================================================
 *
 * A comprehensive chat interface component for interacting with Large Language Models.
 * Provides both real-time streaming and traditional AJAX communication modes.
 *
 * ARCHITECTURAL OVERVIEW:
 * =======================
 * - Real-time streaming via Server-Sent Events (EventSource)
 * - Progressive message rendering with debounced updates
 * - File attachment support for vision-enabled models
 * - Conversation management with persistent state
 * - Markdown parsing and rich text rendering
 * - Responsive Bootstrap-based UI
 *
 * KEY FEATURES:
 * =============
 * - Dual communication modes: Streaming (SSE) and AJAX polling
 * - File upload with drag-and-drop and preview functionality
 * - Comprehensive file validation (type, size, MIME, duplicates)
 * - Conversation CRUD operations (Create, Read, Update, Delete)
 * - Real-time typing indicators and smooth scrolling
 * - Error handling with user-friendly notifications
 * - Mobile-responsive design
 * - Markdown support for rich text formatting
 *
 * TECHNICAL IMPLEMENTATION:
 * ========================
 * - Class-based architecture with encapsulated state management
 * - Event-driven UI updates using jQuery event delegation
 * - Debounced rendering for performance optimization
 * - Progressive enhancement (works without JavaScript streaming)
 * - Security-conscious input validation and output escaping
 *
 * DATA FLOW:
 * ==========
 * 1. User input → Validation → API call (streaming/non-streaming)
 * 2. Response processing → Progressive UI updates → Final rendering
 * 3. Error handling → User notification → State cleanup
 *
 * DEPENDENCIES:
 * =============
 * - jQuery 3.x
 * - Bootstrap 4/5
 * - Font Awesome icons
 * - Server-sent events support (for streaming)
 *
 * @class LlmChat
 * @param {HTMLElement} container - The DOM container element for the chat interface
 */

(function($) {
    'use strict';

    // ===== File Upload Configuration - Defaults =====
    // These can be overridden by data attributes on the container
    const DEFAULT_FILE_CONFIG = {
        maxFileSize: 10 * 1024 * 1024, // 10MB
        maxFilesPerMessage: 5,
        allowedImageExtensions: ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        allowedDocumentExtensions: ['pdf', 'txt', 'md', 'csv', 'json', 'xml'],
        allowedCodeExtensions: ['py', 'js', 'php', 'html', 'css', 'sql', 'sh', 'yaml', 'yml'],
        visionModels: ['internvl3-8b-instruct', 'qwen3-vl-8b-instruct']
    };

    // Working config - will be updated from container data attributes
    let FILE_CONFIG = { ...DEFAULT_FILE_CONFIG };

    // Combine all allowed extensions
    FILE_CONFIG.allowedExtensions = [
        ...FILE_CONFIG.allowedImageExtensions,
        ...FILE_CONFIG.allowedDocumentExtensions,
        ...FILE_CONFIG.allowedCodeExtensions
    ];

    /**
     * Initialize file config from container data attributes
     * @param {jQuery} container - The chat container element
     */
    function initFileConfigFromContainer(container) {
        const maxFileSize = container.data('max-file-size');
        const maxFiles = container.data('max-files');
        const allowedExtensions = container.data('allowed-extensions');

        if (maxFileSize) {
            FILE_CONFIG.maxFileSize = parseInt(maxFileSize, 10);
        }
        if (maxFiles) {
            FILE_CONFIG.maxFilesPerMessage = parseInt(maxFiles, 10);
        }
        if (allowedExtensions) {
            FILE_CONFIG.allowedExtensions = allowedExtensions.split(',').map(ext => ext.trim().toLowerCase());
        }
    }

    // ===== File Upload Error Messages =====
    const FILE_ERRORS = {
        fileTooLarge: (fileName, maxSize) => `File "${fileName}" exceeds maximum size of ${formatBytes(maxSize)}`,
        invalidType: (fileName, extension) => `File type ".${extension}" is not allowed`,
        duplicateFile: (fileName) => `File "${fileName}" is already attached`,
        maxFilesExceeded: (max) => `Maximum ${max} files allowed per message`,
        emptyFile: (fileName) => `File "${fileName}" is empty`,
        uploadFailed: (fileName) => `Failed to upload "${fileName}"`
    };

    /**
     * Format bytes to human-readable size
     * @param {number} bytes - Size in bytes
     * @returns {string} Formatted size string
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    /**
     * Main LLM Chat Component Class
     * Manages the entire chat interface lifecycle and interactions
     */
    class LlmChat {
        /**
         * Constructor - Initialize the LLM Chat component
         * Sets up all necessary properties and event handlers
         *
         * @param {HTMLElement} container - The DOM element containing the chat interface
         */
        constructor(container) {
            // Core DOM and configuration properties
            this.container = $(container);
            this.userId = this.container.data('user-id');
            this.noConversationsMessage = this.container.data('no-conversations-message');
            this.currentConversationId = this.container.data('current-conversation-id') || null;
            this.configuredModel = this.container.data('configured-model') || 'qwen3-vl-8b-instruct';
            this.enableConversationsList = this.container.data('enable-conversations-list') === '1';
            this.enableFileUploads = this.container.attr('data-enable-file-uploads') === '1';
            this.acceptedFileTypes = this.container.data('accepted-file-types') || '';

            // Initialize file config from container data attributes
            initFileConfigFromContainer(this.container);

            // Streaming and real-time properties
            this.eventSource = null;                    // EventSource object for SSE streaming
            this.isStreaming = false;                   // Flag to track if currently streaming
            this.streamBuffer = '';                     // Buffer for accumulating streamed text chunks
            this.renderTimeout = null;                  // Debounce timeout for smooth rendering
            this.scrollRAF = null;                      // RequestAnimationFrame ID for smooth scrolling

            // File upload state management
            this.selectedFiles = [];                    // Array of selected File objects
            this.fileHashes = new Set();                // Set of file content hashes for duplicate detection
            this.attachmentIdCounter = 0;               // Counter for unique attachment IDs
            this.isUploading = false;                   // Flag to prevent concurrent uploads

            this.init();
        }

        // ===== HTML Generation Helpers =====
        // These methods generate consistent HTML for different UI elements
        // Used throughout the component to maintain visual consistency

        /**
         * Generate avatar HTML snippet for user/assistant messages
         * Creates a circular avatar with appropriate icon and styling
         *
         * @param {string} role - Message sender role ('user' or 'assistant')
         * @param {boolean} isRightAligned - Whether avatar should be right-aligned (for user messages)
         * @param {string} additionalClasses - Additional CSS classes to apply
         * @returns {string} Complete avatar HTML snippet
         */
        generateAvatar(role, isRightAligned = false, additionalClasses = '') {
            const icon = role === 'user' ? 'fa-user' : 'fa-robot';
            const bgClass = role === 'user' ? 'bg-primary' : 'bg-success';
            const marginClass = isRightAligned ? 'ml-3' : 'mr-3';
            return `<div class="rounded-circle d-flex align-items-center justify-content-center ${marginClass} flex-shrink-0 ${bgClass} ${additionalClasses}" style="width: 38px; height: 38px;"><i class="fas ${icon}"></i></div>`;
        }

        /**
         * Generate message meta information HTML
         * Shows timestamp and token usage for messages
         *
         * @param {string} timestamp - ISO timestamp string or Date object
         * @param {number|null} tokensUsed - Number of tokens used (null for user messages)
         * @param {string} tokensSuffix - Suffix text for token display (e.g., ' tokens')
         * @param {boolean} isUser - Whether message is from user (affects styling)
         * @returns {string} Meta information HTML snippet
         */
        generateMessageMeta(timestamp, tokensUsed, tokensSuffix, isUser = false) {
            const timeStr = this.formatTime(timestamp);
            const textClass = isUser ? 'text-white-50' : 'text-muted';
            const tokensStr = tokensUsed ? ` • ${tokensUsed}${tokensSuffix}` : '';

            return `<div class="mt-2"><small class="${textClass}">${timeStr}${tokensStr}</small></div>`;
        }

        /**
         * Generate complete user message HTML
         * Creates a right-aligned message bubble with user avatar and content
         *
         * @param {string} content - The text content of the user's message
         * @param {string} timestamp - Optional timestamp (defaults to current time)
         * @returns {string} Complete HTML for user message display
         */
        generateUserMessage(content, timestamp = null) {
            const time = timestamp || new Date();
            return `
                <div class="d-flex mb-3 justify-content-end">
                    ${this.generateAvatar('user', true)}
                    <div class="llm-message-content bg-primary text-white p-3 rounded border">
                        <div class="mb-2">${this.escapeHtml(content)}</div>
                        ${this.generateMessageMeta(time, null, '', true)}
                    </div>
                </div>
            `;
        }

        /**
         * Generate complete assistant message HTML
         * Creates a left-aligned message bubble with assistant avatar, content, and optional image
         *
         * @param {string} content - The text content of the assistant's message
         * @param {string} timestamp - Optional timestamp (defaults to current time)
         * @param {number|null} tokensUsed - Number of tokens used in generation
         * @param {string} tokensSuffix - Suffix for token display (e.g., ' tokens')
         * @param {string|null} imagePath - Optional path to attached image for vision responses
         * @returns {string} Complete HTML for assistant message display
         */
        generateAssistantMessage(content, timestamp = null, tokensUsed = null, tokensSuffix = '', imagePath = null) {
            const time = timestamp || new Date();
            const imageHtml = imagePath ? `<div class="mt-3"><img src="?file_path=${imagePath}" alt="Uploaded image" class="img-fluid rounded"></div>` : '';

            return `
                <div class="d-flex mb-3 justify-content-start">
                    ${this.generateAvatar('assistant')}
                    <div class="llm-message-content bg-light p-3 rounded border">
                        <div class="mb-2">${content}</div>
                        ${imageHtml}
                        ${this.generateMessageMeta(time, tokensUsed, tokensSuffix, false)}
                    </div>
                </div>
            `;
        }

        /**
         * Generate thinking indicator HTML
         * Shows a loading animation while the AI is processing the user's message
         * Displays before streaming begins or for regular AJAX responses
         *
         * @returns {string} HTML for the thinking indicator with spinner
         */
        generateThinkingIndicator() {
            return `
                <div class="d-flex mb-3 justify-content-start">
                    ${this.generateAvatar('assistant')}
                    <div class="llm-message-content bg-light p-3 rounded border">
                        <div class="d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm text-primary mr-2" role="status">
                                <span class="sr-only">Loading...</span>
                            </div>
                            <small class="text-muted">AI is thinking...</small>
                        </div>
                    </div>
                </div>
            `;
        }

        /**
         * Generate streaming message HTML container
         * Creates the initial message structure for real-time streaming responses
         * The content gets updated dynamically as chunks arrive via EventSource
         *
         * @returns {string} HTML structure for streaming message with typing cursor
         */
        generateStreamingMessage() {
            return `
                <div class="d-flex mb-3 justify-content-start streaming">
                    ${this.generateAvatar('assistant')}
                    <div class="llm-message-content bg-light p-3 rounded border">
                        <div class="mb-2"></div>
                        ${this.generateMessageMeta(new Date(), null, '', false)}
                    </div>
                </div>
            `;
        }

        // ===== API Call Helpers =====
        // Centralized methods for backend communication
        // Handle authentication, error handling, and response processing

        /**
         * Make AJAX request with consistent error handling
         * Provides standardized error handling and response processing for all API calls
         *
         * @param {Object} options - jQuery AJAX options (url, method, data, etc.)
         * @returns {jqXHR} jQuery XMLHttpRequest object for chaining
         */
        makeApiRequest(options) {
            const defaultOptions = {
                method: 'GET',
                dataType: 'json',
                error: (xhr) => {
                    const error = xhr.responseJSON?.error || 'Request failed';
                    console.error('API Error:', error);
                    this.showError(error);
                }
            };

            return $.ajax(Object.assign(defaultOptions, options));
        }

        /**
         * Load user conversations via API
         * Fetches all conversations for the current user from the backend
         * Used to populate the conversations sidebar
         *
         * @returns {Promise<Array>} Promise that resolves with array of conversation objects
         */
        loadConversationsApi() {
            return new Promise((resolve, reject) => {
                const url = new URL(window.location);
                url.searchParams.set('action', 'get_conversations');

                this.makeApiRequest({
                    url: url.toString(),
                    success: (response) => {
                        if (response.error) {
                            reject(new Error(response.error));
                        } else {
                            resolve(response.conversations || []);
                        }
                    },
                    error: reject
                });
            });
        }

        /**
         * Load conversation messages via API
         * Fetches all messages for a specific conversation including conversation metadata
         * Used when switching between conversations or loading the current conversation
         *
         * @param {string|number} conversationId - Unique identifier of the conversation
         * @returns {Promise<Object>} Promise that resolves with {conversation, messages} object
         */
        loadConversationMessagesApi(conversationId) {
            return new Promise((resolve, reject) => {
                const url = new URL(window.location);
                url.searchParams.set('action', 'get_conversation');
                url.searchParams.set('conversation_id', conversationId);

                this.makeApiRequest({
                    url: url.toString(),
                    success: (response) => {
                        if (response.error) {
                            reject(new Error(response.error));
                        } else if (response.conversation && response.messages) {
                            resolve({ conversation: response.conversation, messages: response.messages });
                        } else {
                            reject(new Error('Invalid response format'));
                        }
                    },
                    error: reject
                });
            });
        }

        /**
         * Initialize the chat component
         * Sets up event handlers, loads initial data, and configures UI state
         * Called automatically in constructor
         */
        init() {
            this.bindEvents();                    // Set up all event listeners
            if (this.enableConversationsList) {
                this.loadConversations();        // Load user's conversations only if enabled
            } else {
                this.loadCurrentConversation();  // Load current/last conversation for single mode
            }
            this.updateFileUploadVisibility();   // Show/hide file upload based on model
        }

        /**
         * Bind all event handlers for user interactions
         * Sets up click handlers, form submissions, drag-and-drop, and input events
         * Uses event delegation for dynamic elements
         */
        bindEvents() {
            const self = this;

            // ===== Conversation Management Events =====
            // Handle creating and selecting conversations (only if conversations list is enabled)

            if (this.enableConversationsList) {
                // New conversation button - opens modal to create new conversation
                this.container.on('click', '#new-conversation-btn', function(e) {
                    self.showNewConversationModal();
                });

                // Conversation selection - switches to selected conversation
                // Ignores clicks on delete buttons within conversation cards
                this.container.on('click', '.card[data-conversation-id]', function(e) {
                    if (!$(e.target).closest('button').length) {
                        const conversationId = $(this).data('conversation-id');
                        self.selectConversation(conversationId);
                    }
                });

                // Delete conversation
                this.container.on('click', '.card[data-conversation-id] .btn-outline-danger', function(e) {
                    e.stopPropagation();
                    const conversationId = $(this).data('conversation-id');
                    self.confirmDeleteConversation(conversationId);
                });
            }

            // ===== Message Input Events =====
            // Handle message sending and file attachments

            // Message form submission - sends user message to AI
            this.container.on('submit', '#message-form', function(e) {
                e.preventDefault();
                self.sendMessage();
            });

            // File upload functionality - only bind if enabled
            if (this.enableFileUploads) {

                // File upload button - triggers hidden file input
                this.container.on('click', '#attachment-btn', function(e) {
                    e.preventDefault();
                    self.container.find('#file-upload').click();
                });

                // File input change - processes selected files for upload
                this.container.on('change', '#file-upload', function(e) {
                    const files = e.target.files;
                    self.handleFileSelection(files);
                });

                // ===== File Drag & Drop Events =====
                // Handle drag-and-drop file uploads on message input area

                // Drag over - show visual feedback
                this.container.on('dragover', '.message-input-wrapper', function(e) {
                    e.preventDefault();
                    $(this).addClass('drag-over');
                });

                // Drag leave - remove visual feedback
                this.container.on('dragleave', '.message-input-wrapper', function(e) {
                    e.preventDefault();
                    $(this).removeClass('drag-over');
                });

                // File drop - process dropped files
                this.container.on('drop', '.message-input-wrapper', function(e) {
                    e.preventDefault();
                    $(this).removeClass('drag-over');
                    const files = e.originalEvent.dataTransfer.files;
                    if (files.length > 0) {
                        self.handleFileSelection(files);
                    }
                });

                // ===== Attachment Management Events =====
                // Handle removing attachments and input interactions

                // Remove attachment - deletes file from upload list
                this.container.on('click', '.remove-attachment', function(e) {
                    e.preventDefault();
                    const attachmentId = $(this).data('attachment-id');
                    self.removeAttachment(attachmentId);
                });
            } // End file uploads conditional block

            // Character count - updates counter as user types
            this.container.on('input', '#message-input', function() {
                self.updateCharCount();
            });

            // Clear message button - resets form and clears attachments
            this.container.on('click', '#clear-message-btn', function() {
                self.clearMessageForm();
            });
        }

        /**
         * Load and display user conversations
         * Fetches conversations from API and renders them in the sidebar
         * Called during initialization and after conversation creation/deletion
         */
        loadConversations() {
            const conversationsContainer = $('#conversations-list');

            this.loadConversationsApi()
                .then(conversations => {
                    this.renderConversations(conversations);
                })
                .catch(error => {
                    console.error('Failed to load conversations:', error.message);
                });
        }

        /**
         * Load current conversation for single conversation mode
         * When conversations list is disabled, load the current/last conversation
         */
        loadCurrentConversation() {
            if (this.currentConversationId) {
                // Load the specific conversation
                this.loadConversationMessages(this.currentConversationId);
            } else {
                // Try to load the last conversation
                this.loadConversationsApi()
                    .then(conversations => {
                        if (conversations.length > 0) {
                            // Load the most recent conversation
                            const lastConversation = conversations[0];
                            this.currentConversationId = lastConversation.id;
                            $('#current-conversation-id').val(lastConversation.id);
                            this.loadConversationMessages(lastConversation.id);
                        } else {
                            // No conversations exist, show empty state
                            this.renderEmptyChat();
                        }
                    })
                    .catch(error => {
                        console.error('Failed to load conversations:', error.message);
                        this.renderEmptyChat();
                    });
            }
        }

        /**
         * Render empty chat state when no conversations exist
         */
        renderEmptyChat() {
            const messagesContainer = $('#messages-container');
            messagesContainer.html('<div class="d-flex align-items-center justify-content-center h-100"><p class="text-center text-muted">No messages yet. Send your first message!</p></div>');
        }

        /**
         * Select and switch to a conversation
         * Updates UI state, loads conversation messages, and updates browser URL
         *
         * @param {string|number} conversationId - ID of conversation to select
         */
        selectConversation(conversationId) {
            this.currentConversationId = conversationId;
            $('#current-conversation-id').val(conversationId);

            // Update UI with smooth transition - highlight selected conversation
            $('.card[data-conversation-id]').removeClass('border-primary bg-light');
            const selectedItem = $(`.card[data-conversation-id="${conversationId}"]`);
            selectedItem.addClass('border-primary bg-light');

            // Load conversation messages and show loading state
            this.loadConversationMessages(conversationId);

            // Update URL without page reload for bookmarkable links
            const url = new URL(window.location);
            url.searchParams.set('conversation', conversationId);
            window.history.pushState({}, '', url);
        }

        /**
         * Load and display messages for a conversation
         * Shows loading state, fetches messages from API, and renders them
         *
         * @param {string|number} conversationId - ID of conversation to load messages for
         */
        loadConversationMessages(conversationId) {
            const messagesContainer = $('#messages-container');
            messagesContainer.css('opacity', '0.6'); // Visual loading indicator

            this.loadConversationMessagesApi(conversationId)
                .then(({ conversation, messages }) => {
                    this.renderConversation(conversation, messages);
                })
                .catch(error => {
                    this.showError(error.message);
                })
                .finally(() => {
                    messagesContainer.css('opacity', '1'); // Remove loading state
                });
        }

        /**
         * Render conversations list in sidebar
         * Creates conversation cards with titles, timestamps, and delete buttons
         * Handles empty state when no conversations exist
         *
         * @param {Array} conversations - Array of conversation objects from API
         */
        renderConversations(conversations) {
            const conversationsContainer = $('#conversations-list');
            conversationsContainer.empty();

            if (conversations.length === 0) {
                // Show empty state message when no conversations exist
                conversationsContainer.html('<div class="text-center text-muted py-3"><small>' + this.escapeHtml(this.noConversationsMessage) + '</small></div>');
                return;
            }

            conversations.forEach(conversation => {
                const isActive = this.currentConversationId == conversation.id;
                const conversationHtml = `
                    <div class="card mb-2 position-relative ${isActive ? 'border-primary bg-light' : ''}" data-conversation-id="${conversation.id}" style="cursor: pointer;">
                        <div class="card-body py-2 px-3">
                            <div class="font-weight-bold mb-1">${this.escapeHtml(conversation.title)}</div>
                            <div class="small text-muted">${this.formatDate(conversation.updated_at)}</div>
                            <div class="position-absolute opacity-0" style="top: 8px; right: 8px;">
                                <button class="btn btn-sm btn-outline-danger" data-conversation-id="${conversation.id}" title="Delete conversation">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                conversationsContainer.append(conversationHtml);
            });

            // Automatically select the current conversation if one is set and conversations exist
            if (this.currentConversationId && conversations.length > 0) {
                const currentConversationExists = conversations.some(conv => conv.id == this.currentConversationId);
                if (currentConversationExists) {
                    this.selectConversation(this.currentConversationId);
                } else {
                    // If the current conversation doesn't exist, select the first (most recent) one
                    this.selectConversation(conversations[0].id);
                }
            } else if (!this.currentConversationId && conversations.length > 0) {
                // If no conversation is selected but conversations exist, select the first (most recent) one
                this.selectConversation(conversations[0].id);
            }
        }

        /**
         * Render a conversation with all its messages
         * Updates the conversation header and displays all messages in chronological order
         *
         * @param {Object} conversation - Conversation metadata (title, model, etc.)
         * @param {Array} messages - Array of message objects to display
         */
        renderConversation(conversation, messages) {
            // Update conversation header with title and model information
            $('.card-header h6').text(conversation.title);
            $('.card-header small').text('Model: ' + conversation.model);

            // Clear and prepare messages container
            const messagesContainer = $('#messages-container');
            messagesContainer.empty();

            if (messages.length === 0) {
                messagesContainer.html('<div class="d-flex align-items-center justify-content-center h-100"><p class="text-center text-muted">No messages yet. Send your first message!</p></div>');
                return;
            }

            messages.forEach((message, index) => {
                const messageHtml = this.renderMessage(message);
                const $message = $(messageHtml);
                // Add staggered animation delay
                $message.css('animation-delay', (index * 0.05) + 's');
                messagesContainer.append($message);
            });

            // Smooth scroll to bottom
            this.smoothScrollToBottom();
        }

        /**
         * Render attachments for a message
         * Handles both single files and multiple file arrays
         * Matches the PHP template styling for consistency
         *
         * @param {Object} message - The message object
         * @returns {string} HTML for attachments
         */
        renderAttachments(message) {
            // Get file count from attachments field only
            let fileCount = 0;

            if (message.attachments) {
                try {
                    const parsed = JSON.parse(message.attachments);
                    fileCount = Array.isArray(parsed) ? parsed.length : (parsed ? 1 : 0);
                } catch (e) {
                    // If parsing fails, no attachments
                    fileCount = 0;
                }
            }

            // Don't display anything if no files
            if (fileCount === 0) {
                return '';
            }

            // Just show file count, don't try to load or display files
            const fileText = fileCount === 1 ? '1 file' : `${fileCount} files`;
            return `<div class="message-attachments-count">${fileText} attached</div>`;
        }

        renderMessage(message) {
            const isUser = message.role === 'user';
            const attachmentsHtml = this.renderAttachments(message);

            return `
                <div class="d-flex mb-3 ${isUser ? 'justify-content-end' : 'justify-content-start'}">
                    ${this.generateAvatar(isUser ? 'user' : 'assistant')}
                    <div class="llm-message-content ${isUser ? 'bg-primary text-white' : 'bg-light'} p-3 rounded border">
                        <div class="mb-2">${this.formatMessage(message.content, message.formatted_content)}</div>
                        ${attachmentsHtml}
                        ${this.generateMessageMeta(message.timestamp, message.tokens_used, ' tokens', isUser)}
                    </div>
                </div>
            `;
        }

        /**
         * Send user message to AI
         * Main entry point for message sending - validates input, determines sending method,
         * and routes to either streaming or regular AJAX based on configuration
         */
        sendMessage() {
            // Prevent concurrent messages during streaming or uploading
            if (this.isStreaming) {
                this.showError('Please wait for the current response to complete');
                return;
            }

            if (this.isUploading) {
                this.showError('Please wait for file upload to complete');
                return;
            }

            const form = $('#message-form');
            const formData = new FormData(form[0]);

            // Validate message content - allow empty message only if files are attached
            const message = formData.get('message').trim();
            const hasFiles = this.selectedFiles.length > 0;

            if (!message && !hasFiles) {
                this.showError('Please enter a message or attach a file');
                return;
            }

            // Add selected files to FormData from our tracking array
            this.selectedFiles.forEach((item, index) => {
                formData.append('uploaded_files[]', item.file, item.file.name);
            });

            // Add action parameter for backend controller routing
            formData.append('action', 'send_message');

            // Check if real-time streaming is enabled for this chat instance
            const streamingData = this.container.data('streaming-enabled');
            const streamingEnabled = streamingData == '1' || streamingData == 1 || streamingData === true;

            // Add user message to UI immediately for better UX feedback
            this.addUserMessage(message, this.selectedFiles.length);

            // Route to appropriate sending method based on streaming capability
            if (streamingEnabled) {
                // Use EventSource streaming for real-time responses
                this.sendStreamingMessage(formData);
            } else {
                // Use traditional AJAX polling for responses
                this.sendRegularMessage(formData);
            }
        }

        /**
         * Add user message to the chat UI
         * Shows message content and attachment indicators
         *
         * @param {string} message - Message text content
         * @param {number} fileCount - Number of attached files (optional)
         */
        addUserMessage(message, fileCount = 0) {
            const messagesContainer = $('#messages-container');

            // Remove "no messages" placeholder if present
            messagesContainer.find('.text-center.text-muted').remove();

            // Generate message HTML with optional file attachment indicator
            let messageHtml = this.generateUserMessage(message);

            // Add file attachment indicator if files are attached
            if (fileCount > 0) {
                const attachmentText = fileCount === 1 ? '1 file attached' : `${fileCount} files attached`;
                const attachmentIndicator = `<div class="attachment-indicator mt-1"><small class="text-white-50"><i class="fas fa-paperclip mr-1"></i>${attachmentText}</small></div>`;

                // Insert attachment indicator before the timestamp
                messageHtml = messageHtml.replace(
                    '</div>\n                        <div class="mt-2"><small',
                    `${attachmentIndicator}</div>\n                        <div class="mt-2"><small`
                );
            }

            messagesContainer.append(messageHtml);
            this.smoothScrollToBottom();

            // Clear form immediately after showing user message
            this.clearMessageForm();
        }

        sendRegularMessage(formData) {
            const self = this;

            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function() {
                    self.setLoadingState(true);
                    self.showAiThinking();
                },
                success: function(response) {
                    if (response.error) {
                        self.removeThinkingIndicator();
                        self.showError(response.error);
                        return;
                    }

                    // Update current conversation if new
                    if (response.conversation_id && (!self.currentConversationId || response.is_new_conversation)) {
                        self.currentConversationId = response.conversation_id;
                        $('#current-conversation-id').val(response.conversation_id);

                        // Update URL for new conversations to ensure bookmarkable links
                        const url = new URL(window.location);
                        url.searchParams.set('conversation', response.conversation_id);
                        window.history.pushState({}, '', url);
                    }

                    // Handle direct response
                    if (response.message) {
                        self.removeThinkingIndicator();
                        self.addAssistantMessage(response.message);

                        // Refresh conversations sidebar (only if conversations list is enabled)
                        if (self.enableConversationsList) {
                            self.loadConversations();
                        }
                    }
                },
                error: function(xhr) {
                    self.removeThinkingIndicator();
                    const error = xhr.responseJSON?.error || 'Failed to send message';
                    self.showError(error);
                },
                complete: function() {
                    self.setLoadingState(false);
                }
            });
        }

        /**
         * Send message using streaming approach
         * Prepares the message data first, then establishes EventSource connection for real-time updates
         *
         * @param {FormData} formData - Form data containing message and attachments
         */
        sendStreamingMessage(formData) {
            const self = this;

            // First, send preparation request to store message data and get conversation ready
            formData.append('prepare_streaming', '1');

            // Check if test mode should be used (for development/testing)
            var isTestMode = window.location.search.includes('test=1');

            $.ajax({
                url: window.location.pathname + (isTestMode ? '?test=1' : ''),
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.error) {
                        self.showError(response.error);
                        return;
                    }

                    // Now start the streaming EventSource connection
                    const streamingUrl = new URL(window.location.href);
                    streamingUrl.searchParams.set('streaming', '1');
                    streamingUrl.searchParams.set('conversation', self.currentConversationId);

                    self.startStreaming(streamingUrl.toString());
                },
                error: function(xhr) {
                    self.showError('Failed to prepare streaming: ' + (xhr.responseJSON?.error || 'Unknown error'));
                }
            });
        }

        /**
         * Start real-time streaming using EventSource (Server-Sent Events)
         * Establishes SSE connection and handles incoming message chunks
         * Manages UI state during streaming (disabled inputs, indicators)
         *
         * @param {string} streamingUrl - URL for the EventSource connection
         */
        startStreaming(streamingUrl) {
            this.isStreaming = true;
            this.streamBuffer = '';
            const streamingIndicator = $('#streaming-indicator');
            streamingIndicator.fadeIn(200);

            // Disable input controls during streaming to prevent concurrent messages
            this.setStreamingState(true);

            // Show initial thinking indicator before streaming starts
            this.showAiThinking();

            // Establish Server-Sent Events connection
            this.eventSource = new EventSource(streamingUrl);

            const self = this;

            // Handle incoming Server-Sent Events messages
            this.eventSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);

                    // Process different types of streaming events
                    switch (data.type) {
                        case 'connected':
                            // Connection established - thinking indicator already shown
                            break;

                        case 'chunk':
                            // Receive and display incremental content chunks
                            if (data.content) {
                                self.appendStreamChunk(data.content);
                            }
                            break;

                        case 'done':
                            // Streaming completed successfully
                            self.finishStreaming();
                            // Convert temporary streaming message to final message format
                            self.convertStreamingToFinalMessage();
                            // Re-enable input controls
                            self.setStreamingState(false);
                            // Refresh conversations sidebar (only if conversations list is enabled)
                            if (self.enableConversationsList) {
                                self.loadConversations();
                            }
                            // Update URL with current conversation ID before page refresh
                            const url = new URL(window.location);
                            url.searchParams.set('conversation', self.currentConversationId);
                            window.history.pushState({}, '', url);
                            // Small delay before page refresh to allow UI updates to complete
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                            break;

                        case 'error':
                            // Handle streaming errors
                            self.showError(data.message || 'Streaming error occurred');
                            self.finishStreaming();
                            self.setStreamingState(false);
                            break;

                        case 'close':
                            // Connection closed normally
                            self.finishStreaming();
                            self.setStreamingState(false);
                            break;
                    }
                } catch (e) {
                    console.error('Error parsing SSE data:', e);
                }
            };

            this.eventSource.onerror = function(event) {
                if (self.isStreaming) {
                    self.showError('Streaming connection lost');
                    self.finishStreaming();
                    self.setStreamingState(false);
                }
            };
        }

        showAiThinking() {
            const messagesContainer = $('#messages-container');

            // Remove any existing thinking indicator first
            this.removeThinkingIndicator();

            // Add a temporary assistant message with thinking indicator
            const thinkingHtml = this.generateThinkingIndicator();
            messagesContainer.append(thinkingHtml);
            this.smoothScrollToBottom();
        }

        removeThinkingIndicator() {
            $('#messages-container .d-flex.mb-3').has('.spinner-border').remove();
        }

        /**
         * Append a chunk of streamed content to the message
         * Accumulates chunks in buffer and triggers debounced rendering for smooth updates
         *
         * @param {string} chunk - Text chunk received from streaming response
         */
        appendStreamChunk(chunk) {
            // Accumulate the chunk in the buffer for complete message processing
            this.streamBuffer += chunk;

            // Find or create the streaming message element in DOM
            let streamingMessage = $('#messages-container .streaming');

            if (streamingMessage.length === 0) {
                // First chunk - remove thinking indicator and create streaming message container
                this.removeThinkingIndicator();

                const messageHtml = this.generateStreamingMessage();
                $('#messages-container').append(messageHtml);
                streamingMessage = $('#messages-container .streaming');
            }

            // Update the message text with accumulated buffer
            // Use debounced rendering for better performance and smooth visual updates
            this.debouncedRenderStream(streamingMessage);
        }

        /**
         * Debounced rendering of streaming content
         * Uses requestAnimationFrame for smooth updates and prevents excessive DOM updates
         * Processes markdown and adds typing cursor animation
         *
         * @param {jQuery} messageElement - The streaming message element to update
         */
        debouncedRenderStream(messageElement) {
            const self = this;

            // Cancel any pending render to prevent overlapping updates
            if (this.renderTimeout) {
                cancelAnimationFrame(this.renderTimeout);
            }

            // Use requestAnimationFrame for smooth, browser-optimized rendering
            this.renderTimeout = requestAnimationFrame(() => {
                // Double-check that streaming message still exists (might have ended)
                const currentStreamingMessage = $('#messages-container .streaming');
                if (currentStreamingMessage.length === 0) {
                    return; // Streaming might have ended
                }

                const textDiv = currentStreamingMessage.find('.mb-2');

                // Parse markdown to HTML for rich text formatting
                const formattedContent = self.parseMarkdown(self.streamBuffer);

                // Add animated typing cursor to simulate live typing
                const contentWithCursor = formattedContent + '<span class="border-left border-primary ml-1" style="height: 1.2em; animation: blink 1s infinite;"></span>';

                // Update content with smooth visual feedback
                textDiv.html(contentWithCursor);

                // Smooth scroll to keep latest content in view
                self.smoothScrollToBottom();
            });
        }

        /**
         * Parse markdown text to HTML
         * Converts common markdown syntax to HTML for rich text display
         * Includes security measures and proper ordering of replacements
         *
         * @param {string} text - Raw markdown text to parse
         * @returns {string} HTML formatted text
         */
        parseMarkdown(text) {
            if (!text) return '';

            let html = text;

            // ===== Security First =====
            // Escape HTML entities to prevent XSS in user-generated content
            html = html
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            // ===== Block Elements (processed first) =====
            // Code blocks (triple backticks) - must be before inline code
            html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function(match, lang, code) {
                return '<pre class="code-block"><code class="language-' + (lang || 'plaintext') + '">' + code.trim() + '</code></pre>';
            });

            // ===== Inline Elements =====
            // Inline code (single backticks)
            html = html.replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>');

            // Bold text (both ** and __ syntax)
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');

            // Italic text (both * and _ syntax)
            html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
            html = html.replace(/_([^_]+)_/g, '<em>$1</em>');

            // ===== Headers =====
            html = html.replace(/^### (.*$)/gm, '<h5>$1</h5>');
            html = html.replace(/^## (.*$)/gm, '<h4>$1</h4>');
            html = html.replace(/^# (.*$)/gm, '<h3>$1</h3>');

            // ===== Lists =====
            // Unordered lists (-, *, + markers)
            html = html.replace(/^\s*[-*+]\s+(.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');

            // Ordered lists (numbered)
            html = html.replace(/^\s*\d+\.\s+(.+)$/gm, '<li>$1</li>');

            // ===== Links =====
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

            // ===== Text Formatting =====
            // Line breaks (double newline = paragraph break)
            html = html.replace(/\n\n/g, '</p><p>');

            // Single line breaks
            html = html.replace(/\n/g, '<br>');

            // Wrap plain text in paragraph tags
            if (!html.startsWith('<')) {
                html = '<p>' + html + '</p>';
            }

            return html;
        }

        finishStreaming() {
            this.isStreaming = false;

            // Clear any pending renders
            if (this.renderTimeout) {
                cancelAnimationFrame(this.renderTimeout);
                this.renderTimeout = null;
            }

            $('#streaming-indicator').fadeOut(200);

            if (this.eventSource) {
                this.eventSource.close();
                this.eventSource = null;
            }

            // Remove streaming class and typing cursor from messages
            const streamingMessage = $('#messages-container .streaming');
            streamingMessage.removeClass('streaming');
            streamingMessage.find('.border-left.border-primary').remove();

            // Remove any thinking indicators that might be left
            this.removeThinkingIndicator();
        }

        convertStreamingToFinalMessage() {
            // Find the streaming message
            const streamingMessage = $('#messages-container .streaming');
            if (!streamingMessage.length) return;

            // Get the final content from the stream buffer
            const finalContent = this.parseMarkdown(this.streamBuffer);

            // Update the message content (remove cursor and add final content)
            const contentDiv = streamingMessage.find('.mb-2');
            if (contentDiv.length) {
                contentDiv.html(finalContent);
            }

            // Clear the stream buffer
            this.streamBuffer = '';
        }

        addAssistantMessage(message) {
            const messagesContainer = $('#messages-container');

            const messageHtml = this.generateAssistantMessage(this.parseMarkdown(message));
            messagesContainer.append(messageHtml);
            this.smoothScrollToBottom();
        }

        smoothScrollToBottom() {
            const self = this;

            // Cancel any pending scroll
            if (this.scrollRAF) {
                cancelAnimationFrame(this.scrollRAF);
            }

            this.scrollRAF = requestAnimationFrame(() => {
                const container = $('#messages-container');
                if (container.length) {
                    container.stop().animate({
                        scrollTop: container[0].scrollHeight
                    }, {
                        duration: 150,
                        easing: 'swing'
                    });
                }
            });
        }

        showNewConversationModal() {
            // Get field labels from data attributes
            const newConversationTitle = this.container.data('new-conversation-title-label') || 'New Conversation';
            const conversationTitleLabel = this.container.data('conversation-title-label') || 'Conversation Title (optional)';
            const cancelButtonLabel = this.container.data('cancel-button-label') || 'Cancel';
            const createButtonLabel = this.container.data('create-button-label') || 'Create Conversation';

            const modalHtml = `
                <div class="modal fade" id="newConversationModal" tabindex="-1" aria-labelledby="newConversationModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="newConversationModalLabel">${this.escapeHtml(newConversationTitle)}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="new-conversation-form">
                                    <div class="mb-3">
                                        <label for="conversation-title" class="form-label">${this.escapeHtml(conversationTitleLabel)}</label>
                                        <input type="text" class="form-control" id="conversation-title" name="title" placeholder="Enter conversation title (optional)">
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${this.escapeHtml(cancelButtonLabel)}</button>
                                <button type="button" class="btn btn-primary" id="create-conversation-btn">${this.escapeHtml(createButtonLabel)}</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('newConversationModal'));

            // Bind create button
            $('#create-conversation-btn').on('click', () => {
                this.createNewConversation();
                modal.hide();
            });

            modal.show();

            // Remove modal from DOM when hidden
            $('#newConversationModal').on('hidden.bs.modal', function() {
                $(this).remove();
            });
        }

        createNewConversation() {
            const title = $('#conversation-title').val().trim();
            const model = this.container.data('configured-model') || 'qwen3-vl-8b-instruct';

            // Generate better title if not provided
            let finalTitle = title;
            if (!finalTitle.trim()) {
                const now = new Date();
                finalTitle = 'Conversation ' + now.toLocaleDateString() + ' ' + now.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            }

            const self = this;

            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: {
                    action: 'new_conversation',
                    title: finalTitle,
                    model: model
                },
                success: function(response) {
                    if (response.error) {
                        self.showError(response.error);
                        return;
                    }
                    if (response.conversation_id) {
                        // Navigate to the new conversation
                        const url = new URL(window.location);
                        url.searchParams.set('conversation', response.conversation_id);
                        window.location.href = url.toString();
                    }
                },
                error: function(xhr) {
                    self.showError('Failed to create conversation: ' + (xhr.responseJSON?.error || 'Unknown error'));
                }
            });
        }

        confirmDeleteConversation(conversationId) {
            // Get field labels from data attributes
            const deleteConfirmationTitle = this.container.data('delete-confirmation-title') || 'Delete Conversation';
            const deleteConfirmationMessage = this.container.data('delete-confirmation-message') || 'Are you sure you want to delete this conversation? This action cannot be undone.';

            // Use the existing CMS confirmation modal directly
            $.confirm({
                title: deleteConfirmationTitle,
                content: deleteConfirmationMessage,
                type: 'red',
                buttons: {
                    confirm: () => {
                        this.deleteConversation(conversationId);
                    },
                    cancel: function () {

                    }
                }
            });
        }

        deleteConversation(conversationId) {
            const self = this;

            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: {
                    action: 'delete_conversation',
                    conversation_id: conversationId
                },
                success: function(response) {
                    if (response.error) {
                        self.showError(response.error);
                        return;
                    }

                    // Refresh the entire page to load latest data
                    window.location.reload();
                },
                error: function(xhr) {
                    self.showError('Failed to delete conversation: ' + (xhr.responseJSON?.error || 'Unknown error'));
                }
            });
        }


        /**
         * Update file upload visibility based on AI model capabilities
         * Shows attachment button for all models - vision models can process images,
         * while text models will receive document content as text
         */
        updateFileUploadVisibility() {
            const configuredModel = this.configuredModel;
            const attachmentBtn = this.container.find('#attachment-btn');
            const isVisionModel = FILE_CONFIG.visionModels.includes(configuredModel);

            // Always show attachment button - all models can handle files
            // Vision models process images directly, text models get file content as text
            attachmentBtn.show();

            // Update the file input accept attribute based on model type
            const fileInput = this.container.find('#file-upload');
            if (isVisionModel) {
                // Vision models: accept all file types
                fileInput.attr('accept', '*');
            } else {
                // Text-only models: prefer text-based files
                const textExtensions = [...FILE_CONFIG.allowedDocumentExtensions, ...FILE_CONFIG.allowedCodeExtensions];
                fileInput.attr('accept', textExtensions.map(ext => '.' + ext).join(','));
            }
        }

        /**
         * Check if current model supports vision/image processing
         * @returns {boolean} True if model can process images
         */
        isVisionModel() {
            return FILE_CONFIG.visionModels.includes(this.configuredModel);
        }

        updateCharCount() {
            const input = $('#message-input');
            const counter = $('#char-count');
            const length = input.val().length;
            const maxLength = input.attr('maxlength');

            counter.text(`${length}/${maxLength} characters`);

            if (length > maxLength * 0.9) {
                counter.removeClass('text-muted').addClass('text-warning');
            } else {
                counter.removeClass('text-warning').addClass('text-muted');
            }
        }

        /**
         * Clear the message form and all attachments
         * Resets the form to initial state
         */
        clearMessageForm() {
            this.container.find('#message-input').val('');
            this.container.find('#file-upload').val('');

            // Clear file tracking
            this.selectedFiles = [];
            this.fileHashes.clear();

            // Clear UI
            this.clearAttachments();
            this.updateCharCount();
            this.updateFileUploadVisibility();
        }

        /**
         * Handle file selection from drag-and-drop or file input
         * Performs comprehensive validation including type, size, and duplicate detection
         *
         * @param {FileList} files - List of selected files
         */
        handleFileSelection(files) {
            const self = this;
            const filesToAdd = Array.from(files);
            const errors = [];
            const validFiles = [];

            // Check total files limit
            const currentCount = this.selectedFiles.length;
            const newCount = filesToAdd.length;
            const totalCount = currentCount + newCount;

            if (totalCount > FILE_CONFIG.maxFilesPerMessage) {
                this.showError(FILE_ERRORS.maxFilesExceeded(FILE_CONFIG.maxFilesPerMessage));
                return;
            }

            // Process each file with validation
            const validationPromises = filesToAdd.map(file => this.validateFile(file));

            Promise.all(validationPromises).then(results => {
                results.forEach((result, index) => {
                    if (result.valid) {
                        validFiles.push({
                            file: filesToAdd[index],
                            hash: result.hash
                        });
                    } else {
                        errors.push(result.error);
                    }
                });

                // Show first error if any
                if (errors.length > 0) {
                    this.showError(errors[0]);
                }

                // Add valid files
                validFiles.forEach(item => {
                    this.addAttachment(item.file, item.hash);
                });
            });
        }

        /**
         * Validate a single file before adding
         * Checks extension, size, and duplicates using content hash
         *
         * @param {File} file - File to validate
         * @returns {Promise<Object>} Validation result with valid flag, hash, and error message
         */
        async validateFile(file) {
            // Check file size
            if (file.size > FILE_CONFIG.maxFileSize) {
                return {
                    valid: false,
                    error: FILE_ERRORS.fileTooLarge(file.name, FILE_CONFIG.maxFileSize)
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
            const extension = file.name.split('.').pop().toLowerCase();
            if (!FILE_CONFIG.allowedExtensions.includes(extension)) {
                return {
                    valid: false,
                    error: FILE_ERRORS.invalidType(file.name, extension)
                };
            }

            // Generate file hash for duplicate detection
            const hash = await this.generateFileHash(file);

            // Check for duplicates
            if (this.fileHashes.has(hash)) {
                return {
                    valid: false,
                    error: FILE_ERRORS.duplicateFile(file.name)
                };
            }

            return { valid: true, hash: hash };
        }

        /**
         * Generate a simple hash from file content for duplicate detection
         * Uses first 1KB + last 1KB + file size for performance
         *
         * @param {File} file - File to hash
         * @returns {Promise<string>} Hash string
         */
        async generateFileHash(file) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                const chunkSize = 1024; // 1KB

                reader.onload = function(e) {
                    // Simple hash: combine file size, name, and content sample
                    const content = e.target.result;
                    let hash = file.size.toString() + '_' + file.name.length;

                    // Add hash from content
                    if (content.length > 0) {
                        let sum = 0;
                        for (let i = 0; i < Math.min(content.length, 100); i++) {
                            sum += content.charCodeAt(i);
                        }
                        hash += '_' + sum.toString(36);
                    }

                    resolve(hash);
                };

                reader.onerror = function() {
                    // Fallback hash if reading fails
                    resolve(file.size + '_' + file.name + '_' + Date.now());
                };

                // Read only first chunk for performance
                const slice = file.slice(0, chunkSize);
                reader.readAsText(slice);
            });
        }

        /**
         * Add a file as an attachment with preview
         * Creates visual attachment UI with image preview or file type icon
         * Tracks file in selectedFiles array for form submission
         *
         * @param {File} file - The file to add as attachment
         * @param {string} hash - File hash for duplicate tracking
         */
        addAttachment(file, hash) {
            const attachmentId = 'attach_' + (++this.attachmentIdCounter) + '_' + Date.now();
            const attachmentsList = this.container.find('#attachments-list');
            const attachmentsContainer = this.container.find('#file-attachments');

            // Store file in selectedFiles array
            this.selectedFiles.push({
                id: attachmentId,
                file: file,
                hash: hash
            });

            // Track hash for duplicate detection
            this.fileHashes.add(hash);

            // Get file extension and type info
            const extension = file.name.split('.').pop().toLowerCase();
            const isImage = FILE_CONFIG.allowedImageExtensions.includes(extension);
            const fileIcon = this.getFileIconByExtension(extension);
            const fileSizeStr = formatBytes(file.size);

            if (isImage) {
                // Create file reader for image preview
                const reader = new FileReader();
                const self = this;

                reader.onload = function(e) {
                    const attachmentHtml = self.createAttachmentHtml(
                        attachmentId,
                        file.name,
                        fileSizeStr,
                        e.target.result,
                        null,
                        extension
                    );
                    attachmentsList.append(attachmentHtml);
                    self.showAttachmentsContainer();
                };

                reader.onerror = function() {
                    // Fallback to icon if image read fails
                    const attachmentHtml = self.createAttachmentHtml(
                        attachmentId,
                        file.name,
                        fileSizeStr,
                        null,
                        fileIcon,
                        extension
                    );
                    attachmentsList.append(attachmentHtml);
                    self.showAttachmentsContainer();
                };

                reader.readAsDataURL(file);
            } else {
                // Show file icon for non-image files
                const attachmentHtml = this.createAttachmentHtml(
                    attachmentId,
                    file.name,
                    fileSizeStr,
                    null,
                    fileIcon,
                    extension
                );
                attachmentsList.append(attachmentHtml);
                this.showAttachmentsContainer();
            }
        }

        /**
         * Create HTML for attachment item
         *
         * @param {string} attachmentId - Unique attachment ID
         * @param {string} fileName - Original file name
         * @param {string} fileSize - Formatted file size
         * @param {string|null} imageData - Base64 image data for preview
         * @param {string|null} fileIcon - Font Awesome icon class
         * @param {string} extension - File extension
         * @returns {string} HTML string for attachment
         */
        createAttachmentHtml(attachmentId, fileName, fileSize, imageData, fileIcon, extension) {
            const truncatedName = fileName.length > 20 ? fileName.substr(0, 17) + '...' : fileName;
            const extBadgeClass = this.getExtensionBadgeClass(extension);

            let previewHtml;
            if (imageData) {
                previewHtml = `<img src="${imageData}" alt="${this.escapeHtml(fileName)}" class="attachment-thumbnail">`;
            } else {
                previewHtml = `<div class="attachment-icon"><i class="${fileIcon}"></i></div>`;
            }

            return `
                <div class="attachment-item" data-attachment-id="${attachmentId}">
                    <div class="attachment-preview">
                        ${previewHtml}
                    </div>
                    <div class="attachment-info">
                        <span class="attachment-name" title="${this.escapeHtml(fileName)}">${this.escapeHtml(truncatedName)}</span>
                        <span class="attachment-meta">
                            <span class="badge ${extBadgeClass}">.${extension}</span>
                            <span class="attachment-size">${fileSize}</span>
                        </span>
                    </div>
                    <button type="button" class="btn btn-sm btn-link remove-attachment text-danger" data-attachment-id="${attachmentId}" title="Remove file">
                        <i class="fas fa-times-circle"></i>
                    </button>
                </div>
            `;
        }

        /**
         * Show the attachments container with animation
         */
        showAttachmentsContainer() {
            const container = this.container.find('#file-attachments');
            if (!container.is(':visible')) {
                container.removeClass('d-none').hide().fadeIn(200);
            }
        }

        /**
         * Get CSS class for extension badge based on file type
         *
         * @param {string} extension - File extension
         * @returns {string} Badge CSS class
         */
        getExtensionBadgeClass(extension) {
            if (FILE_CONFIG.allowedImageExtensions.includes(extension)) {
                return 'badge-success';
            }
            if (FILE_CONFIG.allowedCodeExtensions.includes(extension)) {
                return 'badge-primary';
            }
            return 'badge-secondary';
        }

        /**
         * Remove an attachment from the UI and tracking arrays
         *
         * @param {string} attachmentId - Unique ID of the attachment to remove
         */
        removeAttachment(attachmentId) {
            const self = this;

            // Find and remove from selectedFiles array
            const fileIndex = this.selectedFiles.findIndex(item => item.id === attachmentId);
            if (fileIndex !== -1) {
                const removedFile = this.selectedFiles[fileIndex];
                // Remove hash from tracking
                this.fileHashes.delete(removedFile.hash);
                // Remove from array
                this.selectedFiles.splice(fileIndex, 1);
            }

            // Remove from UI with animation
            const $item = $(`.attachment-item[data-attachment-id="${attachmentId}"]`);
            $item.addClass('attachment-removing').fadeOut(200, function() {
                $(this).remove();

                // Hide container if no attachments left
                if (self.container.find('#attachments-list').children().length === 0) {
                    self.container.find('#file-attachments').fadeOut(200, function() {
                        $(this).addClass('d-none');
                    });
                }
            });
        }

        /**
         * Clear all attachments from UI and tracking
         */
        clearAttachments() {
            this.container.find('#attachments-list').empty();
            this.container.find('#file-attachments').hide().addClass('d-none');
            this.selectedFiles = [];
            this.fileHashes.clear();
        }

        /**
         * Get Font Awesome icon class based on file extension
         *
         * @param {string} extension - File extension (without dot)
         * @returns {string} Font Awesome icon class
         */
        getFileIconByExtension(extension) {
            const iconMap = {
                // Images
                'jpg': 'fas fa-file-image text-success',
                'jpeg': 'fas fa-file-image text-success',
                'png': 'fas fa-file-image text-success',
                'gif': 'fas fa-file-image text-success',
                'webp': 'fas fa-file-image text-success',

                // Documents
                'pdf': 'fas fa-file-pdf text-danger',
                'txt': 'fas fa-file-alt text-secondary',
                'md': 'fas fa-file-alt text-info',
                'csv': 'fas fa-file-csv text-success',

                // Code files
                'py': 'fab fa-python text-primary',
                'js': 'fab fa-js-square text-warning',
                'php': 'fab fa-php text-purple',
                'html': 'fab fa-html5 text-danger',
                'css': 'fab fa-css3-alt text-primary',
                'json': 'fas fa-file-code text-warning',
                'xml': 'fas fa-file-code text-info',
                'sql': 'fas fa-database text-primary',
                'sh': 'fas fa-terminal text-dark',
                'yaml': 'fas fa-file-code text-purple',
                'yml': 'fas fa-file-code text-purple'
            };

            return iconMap[extension.toLowerCase()] || 'fas fa-file text-secondary';
        }

        /**
         * Get Font Awesome icon class based on MIME type (legacy support)
         *
         * @param {string} mimeType - MIME type string
         * @returns {string} Font Awesome icon class
         */
        getFileIcon(mimeType) {
            const iconMap = {
                // Documents
                'application/pdf': 'fas fa-file-pdf text-danger',
                'application/msword': 'fas fa-file-word text-primary',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'fas fa-file-word text-primary',
                'application/vnd.ms-excel': 'fas fa-file-excel text-success',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'fas fa-file-excel text-success',

                // Text files
                'text/plain': 'fas fa-file-alt text-secondary',
                'text/csv': 'fas fa-file-csv text-success',
                'application/json': 'fas fa-file-code text-warning',
                'application/xml': 'fas fa-file-code text-info',
                'text/html': 'fab fa-html5 text-danger',

                // Default
                'default': 'fas fa-file text-secondary'
            };

            // Check for exact match first
            if (iconMap[mimeType]) {
                return iconMap[mimeType];
            }

            // Check for partial match (like image/*, audio/*)
            if (mimeType.startsWith('image/')) return 'fas fa-file-image text-success';
            if (mimeType.startsWith('audio/')) return 'fas fa-file-audio text-purple';
            if (mimeType.startsWith('video/')) return 'fas fa-file-video text-danger';
            if (mimeType.startsWith('text/')) return 'fas fa-file-alt text-secondary';

            return iconMap.default;
        }

        /**
         * Set loading state for message form
         * Updates UI to show loading spinner and disables form during message sending
         *
         * @param {boolean} loading - Whether to show loading state
         */
        setLoadingState(loading) {
            const form = $('#message-form');
            const submitBtn = $('#send-message-btn');

            if (loading) {
                form.addClass('loading');
                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Sending...');
            } else {
                form.removeClass('loading');
                submitBtn.prop('disabled', false).html('<i class="fas fa-paper-plane"></i> Send Message');
            }
        }

        /**
         * Set streaming state for UI controls
         * Disables/enables input controls during real-time streaming to prevent user confusion
         *
         * @param {boolean} streaming - Whether streaming is active
         */
        setStreamingState(streaming) {
            const messageInput = this.container.find('#message-input');
            const submitBtn = this.container.find('#send-message-btn');
            const attachmentBtn = this.container.find('#attachment-btn');

            if (streaming) {
                // Disable all input controls during streaming
                messageInput.prop('disabled', true).attr('placeholder', 'Streaming in progress...');
                submitBtn.prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin me-2"></i>Streaming...');
                attachmentBtn.prop('disabled', true);
            } else {
                // Re-enable controls when streaming completes
                messageInput.prop('disabled', false).attr('placeholder', this.container.data('message-placeholder') || 'Type your message...');
                submitBtn.prop('disabled', false).html('<i class="fas fa-paper-plane"></i> Send Message');
                attachmentBtn.prop('disabled', false);
            }
        }

        scrollToBottom() {
            const container = $('#messages-container');
            container.scrollTop(container[0].scrollHeight);
        }

        /**
         * Format message content for display
         * Handles both pre-formatted HTML from AJAX responses and raw text from initial load
         *
         * @param {string} text - Raw message text
         * @param {string} formattedContent - Pre-formatted HTML content (from AJAX responses)
         * @returns {string} Formatted message content
         */
        formatMessage(text, formattedContent) {
            // If we have pre-formatted content from the backend (AJAX responses),
            // use it directly since it's already parsed HTML
            if (formattedContent !== undefined && formattedContent !== null) {
                return formattedContent;
            }

            // For initial page load, content is already parsed in PHP template
            // Just return as-is since it's already formatted HTML
            return text;
        }

        formatTime(timestamp) {
            const date = new Date(timestamp);
            return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }

        /**
         * Display error message to user
         * Shows dismissible Bootstrap alert with error icon and auto-hide after 5 seconds
         * Positions error near the input area for better visibility
         *
         * @param {string} message - Error message to display
         */
        showError(message) {
            // Remove any existing error alerts
            this.container.find('.llm-error-alert').remove();

            const alertId = 'error-' + Date.now();
            const errorHtml = `
                <div class="alert alert-danger alert-dismissible fade show llm-error-alert mb-2" role="alert" id="${alertId}">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span>${this.escapeHtml(message)}</span>
                        <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                </div>
            `;

            // Insert error above the message input form for better visibility
            const messageForm = this.container.find('#message-form');
            if (messageForm.length) {
                messageForm.before(errorHtml);
            } else {
                // Fallback to after description
                this.container.find('.bg-primary').after(errorHtml);
            }

            // Scroll error into view
            const $alert = $('#' + alertId);
            if ($alert.length) {
                $alert[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            // Auto-remove after 6 seconds with fade animation
            setTimeout(() => {
                $alert.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 6000);
        }

        /**
         * Display success message to user
         *
         * @param {string} message - Success message to display
         */
        showSuccess(message) {
            // Remove any existing success alerts
            this.container.find('.llm-success-alert').remove();

            const alertId = 'success-' + Date.now();
            const successHtml = `
                <div class="alert alert-success alert-dismissible fade show llm-success-alert mb-2" role="alert" id="${alertId}">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span>${this.escapeHtml(message)}</span>
                        <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                </div>
            `;

            const messageForm = this.container.find('#message-form');
            if (messageForm.length) {
                messageForm.before(successHtml);
            }

            // Auto-remove after 4 seconds
            setTimeout(() => {
                $('#' + alertId).fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Format date for human-readable display
         * Returns relative time for recent dates, absolute date for older ones
         *
         * @param {string} dateString - ISO date string to format
         * @returns {string} Human-readable date/time string
         */
        formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

            if (diffDays === 0) {
                // Today - show just time
                return date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            } else if (diffDays === 1) {
                // Yesterday
                return 'Yesterday';
            } else if (diffDays < 7) {
                // Within last week - show relative days
                return `${diffDays} days ago`;
            } else {
                // Older - show full date
                return date.toLocaleDateString();
            }
        }
    }

    // ===== Component Initialization =====
    // Automatically initialize all LLM chat components on page load
    // Uses jQuery ready event to ensure DOM is loaded before initialization

    /**
     * Document ready initialization
     * Finds all elements with class 'llm-chat-container' and initializes LlmChat instances
     * This allows multiple chat components on the same page
     */
    $(document).ready(function() {
        $('.llm-chat-container').each(function() {
            new LlmChat(this);
        });
    });

})(jQuery);
