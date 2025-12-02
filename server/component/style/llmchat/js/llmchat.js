/**
 * LLM Chat Component JavaScript
 * Handles real-time chat functionality, file uploads, and UI interactions
 * With smooth streaming and fluid UI updates
 */

(function($) {
    'use strict';

    class LlmChat {
        constructor(container) {
            this.container = $(container);
            this.userId = this.container.data('user-id');
            this.noConversationsMessage = this.container.data('no-conversations-message');
            this.currentConversationId = this.container.data('current-conversation-id') || null;
            this.eventSource = null;
            this.isStreaming = false;
            this.attachmentFileMap = {}; // Map attachmentId to file index
            this.streamBuffer = ''; // Buffer for accumulating streamed text
            this.renderTimeout = null; // Debounce timeout for rendering
            this.scrollRAF = null; // RequestAnimationFrame for smooth scrolling

            this.init();
        }

        init() {
            this.bindEvents();
            this.loadConversations();
            this.updateFileUploadVisibility();
        }

        bindEvents() {
            const self = this;

            // New conversation button
            this.container.on('click', '#new-conversation-btn', function(e) {
                self.showNewConversationModal();
            });

            // Conversation selection
            this.container.on('click', '.conversation-item', function(e) {
                if (!$(e.target).closest('.conversation-actions').length) {
                    const conversationId = $(this).data('conversation-id');
                    self.selectConversation(conversationId);
                }
            });

            // Delete conversation
            this.container.on('click', '.delete-conversation-btn', function(e) {
                e.stopPropagation();
                const conversationId = $(this).data('conversation-id');
                self.confirmDeleteConversation(conversationId);
            });

            // Message form submission
            this.container.on('submit', '#message-form', function(e) {
                e.preventDefault();
                self.sendMessage();
            });

            // File upload button
            this.container.on('click', '#attachment-btn', function(e) {
                e.preventDefault();
                $('#file-upload').click();
            });

            // File input change
            this.container.on('change', '#file-upload', function(e) {
                const files = e.target.files;
                self.handleFileSelection(files);
            });

            // Drag and drop on message input wrapper
            this.container.on('dragover', '#message-input-wrapper', function(e) {
                e.preventDefault();
                $(this).addClass('dragover');
            });

            this.container.on('dragleave', '#message-input-wrapper', function(e) {
                e.preventDefault();
                $(this).removeClass('dragover');
            });

            this.container.on('drop', '#message-input-wrapper', function(e) {
                e.preventDefault();
                $(this).removeClass('dragover');
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    self.handleFileSelection(files);
                }
            });

            // Remove attachment
            this.container.on('click', '.remove-attachment', function(e) {
                e.preventDefault();
                const attachmentId = $(this).data('attachment-id');
                self.removeAttachment(attachmentId);
            });

            // Character count
            this.container.on('input', '#message-input', function() {
                self.updateCharCount();
            });

            // Clear message button
            this.container.on('click', '#clear-message-btn', function() {
                self.clearMessageForm();
            });
        }

        loadConversations() {
            const self = this;
            const conversationsContainer = $('#conversations-list');

            // Make direct GET request to current page with action parameter
            const url = new URL(window.location);
            url.searchParams.set('action', 'get_conversations');

            $.ajax({
                url: url.toString(),
                method: 'GET',
                success: function(response) {
                    if (response.error) {
                        console.error('Failed to load conversations:', response.error);
                        return;
                    }
                    self.renderConversations(response.conversations || []);
                },
                error: function(xhr) {
                    console.error('Failed to load conversations:', xhr.responseJSON?.error);
                }
            });
        }

        selectConversation(conversationId) {
            this.currentConversationId = conversationId;
            $('#current-conversation-id').val(conversationId);

            // Update UI with smooth transition
            $('.conversation-item').removeClass('active');
            const selectedItem = $(`.conversation-item[data-conversation-id="${conversationId}"]`);
            selectedItem.addClass('active');

            // Load conversation messages
            this.loadConversationMessages(conversationId);

            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('conversation', conversationId);
            window.history.pushState({}, '', url);
        }

        loadConversationMessages(conversationId) {
            const self = this;
            const messagesContainer = $('#messages-container');

            // Make direct GET request to current page with action and conversation_id parameters
            const url = new URL(window.location);
            url.searchParams.set('action', 'get_conversation');
            url.searchParams.set('conversation_id', conversationId);

            $.ajax({
                url: url.toString(),
                method: 'GET',
                beforeSend: function() {
                    messagesContainer.addClass('loading').css('opacity', '0.6');
                },
                success: function(response) {
                    if (response.error) {
                        self.showError(response.error);
                        return;
                    }
                    if (response.conversation && response.messages) {
                        self.renderConversation(response.conversation, response.messages);
                    }
                },
                error: function(xhr) {
                    self.showError('Failed to load conversation: ' + (xhr.responseJSON?.error || 'Unknown error'));
                },
                complete: function() {
                    messagesContainer.removeClass('loading').css('opacity', '1');
                }
            });
        }

        renderConversations(conversations) {
            const conversationsContainer = $('#conversations-list');
            conversationsContainer.empty();

            if (conversations.length === 0) {
                conversationsContainer.html('<div class="no-conversations text-center text-muted py-3"><small>' + this.escapeHtml(this.noConversationsMessage) + '</small></div>');
                return;
            }

            conversations.forEach(conversation => {
                const isActive = this.currentConversationId == conversation.id;
                const conversationHtml = `
                    <div class="conversation-item ${isActive ? 'active' : ''}" data-conversation-id="${conversation.id}">
                        <div class="conversation-title">${this.escapeHtml(conversation.title)}</div>
                        <div class="conversation-meta">
                            <small class="text-muted">${this.formatDate(conversation.updated_at)}</small>
                        </div>
                        <div class="conversation-actions">
                            <button class="btn btn-sm btn-outline-danger delete-conversation-btn" data-conversation-id="${conversation.id}" title="Delete conversation">
                                <i class="fas fa-trash"></i>
                            </button>
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

        renderConversation(conversation, messages) {
            // Update conversation header
            $('.conversation-header h6').text(conversation.title);
            $('.conversation-header small').text('Model: ' + conversation.model);

            // Render messages
            const messagesContainer = $('#messages-container');
            messagesContainer.empty();

            if (messages.length === 0) {
                messagesContainer.html('<div class="no-messages"><p class="text-center text-muted">No messages yet. Send your first message!</p></div>');
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

        renderMessage(message) {
            const isUser = message.role === 'user';
            const avatarIcon = isUser ? 'fa-user' : 'fa-robot';
            const messageClass = isUser ? 'message-user' : 'message-assistant';

            let imageHtml = '';
            if (message.image_path) {
                imageHtml = `
                    <div class="message-image">
                        <img src="?file_path=${message.image_path}" alt="Uploaded image" class="img-fluid rounded">
                    </div>
                `;
            }

            const metaHtml = `
                <div class="message-meta">
                    <small class="text-muted">
                        ${this.formatTime(message.timestamp)}
                        ${message.tokens_used ? ' • ' + message.tokens_used + ' tokens' : ''}
                    </small>
                </div>
            `;

            return `
                <div class="message ${messageClass}">
                    <div class="message-avatar">
                        <i class="fas ${avatarIcon}"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-text">${this.formatMessage(message.content, message.formatted_content)}</div>
                        ${imageHtml}
                        ${metaHtml}
                    </div>
                </div>
            `;
        }

        sendMessage() {
            const form = $('#message-form');
            const formData = new FormData(form[0]);

            // Validate
            const message = formData.get('message').trim();
            if (!message) {
                this.showError('Please enter a message');
                return;
            }

            // Add action parameter for controller
            formData.append('action', 'send_message');

            const self = this;

            // Check if streaming is enabled
            const streamingData = this.container.data('streaming-enabled');
            const streamingEnabled = streamingData == '1' || streamingData == 1 || streamingData === true;

            // Add user message to UI immediately for better UX
            this.addUserMessage(message);

            // Check if streaming is enabled
            if (streamingEnabled) {
                // Use streaming approach
                this.sendStreamingMessage(formData);
            } else {
                // Use regular AJAX approach
                this.sendRegularMessage(formData);
            }
        }

        addUserMessage(message) {
            const messagesContainer = $('#messages-container');

            // Remove "no messages" placeholder if present
            messagesContainer.find('.no-messages').remove();

            const messageHtml = `
                <div class="message message-user message-new">
                    <div class="message-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-text">${this.escapeHtml(message)}</div>
                        <div class="message-meta">
                            <small class="text-muted">${this.formatTime(new Date())}</small>
                        </div>
                    </div>
                </div>
            `;
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
                    }

                    // Handle direct response
                    if (response.message) {
                        self.removeThinkingIndicator();
                        self.addAssistantMessage(response.message);

                        // Refresh conversations sidebar and update URL
                        self.loadConversations();
                        if (response.conversation_id) {
                            const url = new URL(window.location);
                            url.searchParams.set('conversation', response.conversation_id);
                            window.history.pushState({}, '', url);
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

        sendStreamingMessage(formData) {
            const self = this;

            // First, send preparation request to store message data
            formData.append('prepare_streaming', '1');

            // Check if test mode should be used
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

                    // Now start the streaming EventSource
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

        startStreaming(streamingUrl) {
            this.isStreaming = true;
            this.streamBuffer = '';
            const streamingIndicator = $('#streaming-indicator');
            streamingIndicator.fadeIn(200);

            // Show thinking indicator
            this.showAiThinking();

            // Start SSE
            this.eventSource = new EventSource(streamingUrl);

            const self = this;

            this.eventSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);

                    switch (data.type) {
                        case 'connected':
                            // Connection established - thinking indicator already shown
                            break;

                        case 'chunk':
                            if (data.content) {
                                self.appendStreamChunk(data.content);
                            }
                            break;

                        case 'done':
                            self.finishStreaming();
                            // Refresh conversation to get updated data with proper formatting
                            if (self.currentConversationId) {
                                // Small delay to ensure server has saved the message
                                setTimeout(() => {
                                    self.loadConversationMessages(self.currentConversationId);
                                    self.loadConversations();
                                }, 300);
                            }
                            break;

                        case 'error':
                            self.showError(data.message || 'Streaming error occurred');
                            self.finishStreaming();
                            break;

                        case 'close':
                            self.finishStreaming();
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
                }
            };
        }

        showAiThinking() {
            const messagesContainer = $('#messages-container');

            // Remove any existing thinking indicator first
            this.removeThinkingIndicator();

            // Add a temporary assistant message with thinking indicator
            const thinkingHtml = `
                <div class="message message-assistant message-thinking">
                    <div class="message-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-text">
                            <span class="thinking-dots">
                                <span class="dot"></span>
                                <span class="dot"></span>
                                <span class="dot"></span>
                            </span>
                        </div>
                    </div>
                </div>
            `;
            messagesContainer.append(thinkingHtml);
            this.smoothScrollToBottom();
        }

        removeThinkingIndicator() {
            $('#messages-container .message-thinking').remove();
        }

        appendStreamChunk(chunk) {
            // Accumulate the chunk in the buffer
            this.streamBuffer += chunk;

            // Find or create the streaming message element
            let lastMessage = $('#messages-container .message-assistant.streaming').last();

            if (lastMessage.length === 0) {
                // Remove thinking indicator and create new streaming message
                this.removeThinkingIndicator();

                const messageHtml = `
                    <div class="message message-assistant streaming">
                        <div class="message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="message-content">
                            <div class="message-text"></div>
                            <div class="message-meta">
                                <small class="text-muted">${this.formatTime(new Date())}</small>
                            </div>
                        </div>
                    </div>
                `;
                $('#messages-container').append(messageHtml);
                lastMessage = $('#messages-container .message-assistant.streaming').last();
            }

            // Update the message text with accumulated buffer
            // Use debounced rendering for better performance
            this.debouncedRenderStream(lastMessage);
        }

        debouncedRenderStream(messageElement) {
            const self = this;

            // Cancel any pending render
            if (this.renderTimeout) {
                cancelAnimationFrame(this.renderTimeout);
            }

            // Use requestAnimationFrame for smooth rendering
            this.renderTimeout = requestAnimationFrame(() => {
                const textDiv = messageElement.find('.message-text');

                // Parse markdown to HTML
                const formattedContent = self.parseMarkdown(self.streamBuffer);

                // Add typing cursor
                const contentWithCursor = formattedContent + '<span class="typing-cursor"></span>';

                // Update content
                textDiv.html(contentWithCursor);

                // Smooth scroll to keep message in view
                self.smoothScrollToBottom();
            });
        }

        parseMarkdown(text) {
            if (!text) return '';

            let html = text;

            // Escape HTML first for security
            html = html
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            // Code blocks (triple backticks) - must be before inline code
            html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function(match, lang, code) {
                return '<pre class="code-block"><code class="language-' + (lang || 'plaintext') + '">' + code.trim() + '</code></pre>';
            });

            // Inline code (single backticks)
            html = html.replace(/`([^`]+)`/g, '<code class="inline-code">$1</code>');

            // Bold text
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');

            // Italic text
            html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
            html = html.replace(/_([^_]+)_/g, '<em>$1</em>');

            // Headers
            html = html.replace(/^### (.*$)/gm, '<h5>$1</h5>');
            html = html.replace(/^## (.*$)/gm, '<h4>$1</h4>');
            html = html.replace(/^# (.*$)/gm, '<h3>$1</h3>');

            // Unordered lists
            html = html.replace(/^\s*[-*+]\s+(.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');

            // Ordered lists
            html = html.replace(/^\s*\d+\.\s+(.+)$/gm, '<li>$1</li>');

            // Links
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

            // Line breaks (double newline = paragraph)
            html = html.replace(/\n\n/g, '</p><p>');

            // Single line breaks
            html = html.replace(/\n/g, '<br>');

            // Wrap in paragraph
            if (!html.startsWith('<')) {
                html = '<p>' + html + '</p>';
            }

            return html;
        }

        finishStreaming() {
            this.isStreaming = false;
            this.streamBuffer = '';

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
            const streamingMessage = $('#messages-container .message-assistant.streaming');
            streamingMessage.removeClass('streaming');
            streamingMessage.find('.typing-cursor').remove();

            // Remove any thinking indicators that might be left
            this.removeThinkingIndicator();
        }

        addAssistantMessage(message) {
            const messagesContainer = $('#messages-container');

            const messageHtml = `
                <div class="message message-assistant message-new">
                    <div class="message-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-text">${this.parseMarkdown(message)}</div>
                        <div class="message-meta">
                            <small class="text-muted">${this.formatTime(new Date())}</small>
                        </div>
                    </div>
                </div>
            `;

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


        updateFileUploadVisibility() {
            const configuredModel = this.container.data('configured-model') || 'qwen3-vl-8b-instruct';
            const attachmentBtn = $('#attachment-btn');

            // Show attachment button for vision models
            const visionModels = ['internvl3-8b-instruct', 'qwen3-vl-8b-instruct'];
            if (visionModels.includes(configuredModel)) {
                attachmentBtn.show();
            } else {
                attachmentBtn.hide();
                $('#file-upload').val(''); // Clear file selection
                this.clearAttachments(); // Clear any existing attachments
            }
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

        clearMessageForm() {
            $('#message-input').val('');
            $('#file-upload').val('');
            this.clearAttachments();
            this.updateCharCount();
            this.updateFileUploadVisibility();
        }

        handleFileSelection(files) {
            const maxFiles = 5; // Maximum number of files

            if (files.length > maxFiles) {
                this.showError(`Maximum ${maxFiles} files allowed`);
                return;
            }

            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                if (file.size > 10 * 1024 * 1024) { // 10MB limit
                    this.showError(`File ${file.name} is too large. Maximum size is 10MB.`);
                    continue;
                }

                this.addAttachment(file, i);
            }

            // Update the actual file input with the selected files
            const fileInput = document.getElementById('file-upload');
            const dt = new DataTransfer();
            for (let i = 0; i < files.length; i++) {
                dt.items.add(files[i]);
            }
            fileInput.files = dt.files;
        }

        addAttachment(file, fileIndex) {
            const attachmentId = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            const attachmentsList = $('#attachments-list');
            const attachmentsContainer = $('#file-attachments');

            // Store mapping between attachmentId and file index
            this.attachmentFileMap[attachmentId] = fileIndex;

            // Check if file is an image
            const isImage = file.type.startsWith('image/');

            if (isImage) {
                // Create file reader for image preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    const attachmentHtml = `
                        <div class="attachment-item" data-attachment-id="${attachmentId}">
                            <div class="attachment-preview">
                                <img src="${e.target.result}" alt="${file.name}" class="img-thumbnail" style="max-width: 60px; max-height: 60px;">
                            </div>
                            <div class="attachment-info">
                                <small class="text-muted">${file.name}</small>
                                <br>
                                <small class="text-muted">${(file.size / 1024).toFixed(1)} KB</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-attachment" data-attachment-id="${attachmentId}" title="Remove">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    `;
                    attachmentsList.append(attachmentHtml);
                    attachmentsContainer.fadeIn(200);
                };
                reader.readAsDataURL(file);
            } else {
                // Show file icon for non-image files
                const fileIcon = this.getFileIcon(file.type);
                const attachmentHtml = `
                    <div class="attachment-item" data-attachment-id="${attachmentId}">
                        <div class="attachment-preview">
                            <div class="file-icon-preview">
                                <i class="${fileIcon} fa-2x text-muted"></i>
                            </div>
                        </div>
                        <div class="attachment-info">
                            <small class="text-muted">${file.name}</small>
                            <br>
                            <small class="text-muted">${(file.size / 1024).toFixed(1)} KB</small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-attachment" data-attachment-id="${attachmentId}" title="Remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                attachmentsList.append(attachmentHtml);
                attachmentsContainer.fadeIn(200);
            }
        }

        removeAttachment(attachmentId) {
            $(`.attachment-item[data-attachment-id="${attachmentId}"]`).fadeOut(200, function() {
                $(this).remove();

                // Hide container if no attachments left
                if ($('#attachments-list').children().length === 0) {
                    $('#file-attachments').fadeOut(200);
                }
            });

            // Update the file input to remove the file
            const fileInput = document.getElementById('file-upload');
            const fileIndexToRemove = this.attachmentFileMap[attachmentId];

            if (fileIndexToRemove !== undefined) {
                const dt = new DataTransfer();
                const files = Array.from(fileInput.files);
                const remainingFiles = files.filter((file, index) => index !== fileIndexToRemove);

                remainingFiles.forEach(file => dt.items.add(file));
                fileInput.files = dt.files;

                // Remove the mapping
                delete this.attachmentFileMap[attachmentId];
            }
        }

        clearAttachments() {
            $('#attachments-list').empty();
            $('#file-attachments').hide();
            this.attachmentFileMap = {}; // Clear the mapping
        }

        getFileIcon(mimeType) {
            const iconMap = {
                // Documents
                'application/pdf': 'fas fa-file-pdf',
                'application/msword': 'fas fa-file-word',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'fas fa-file-word',
                'application/vnd.ms-excel': 'fas fa-file-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'fas fa-file-excel',
                'application/vnd.ms-powerpoint': 'fas fa-file-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'fas fa-file-powerpoint',

                // Text files
                'text/plain': 'fas fa-file-alt',
                'text/csv': 'fas fa-file-csv',
                'application/json': 'fas fa-file-code',
                'application/xml': 'fas fa-file-code',
                'text/html': 'fas fa-file-code',

                // Archives
                'application/zip': 'fas fa-file-archive',
                'application/x-rar-compressed': 'fas fa-file-archive',
                'application/x-7z-compressed': 'fas fa-file-archive',

                // Audio/Video
                'audio/': 'fas fa-file-audio',
                'video/': 'fas fa-file-video',

                // Default
                'default': 'fas fa-file'
            };

            // Check for exact match first
            if (iconMap[mimeType]) {
                return iconMap[mimeType];
            }

            // Check for partial match (like audio/*, video/*)
            for (const [key, value] of Object.entries(iconMap)) {
                if (key.endsWith('/') && mimeType.startsWith(key)) {
                    return value;
                }
            }

            return iconMap.default;
        }

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

        scrollToBottom() {
            const container = $('#messages-container');
            container.scrollTop(container[0].scrollHeight);
        }

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

        showError(message) {
            // Remove any existing error alerts
            this.container.find('.alert-danger').remove();

            const errorHtml = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>${this.escapeHtml(message)}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;

            this.container.find('.llm-chat-description').after(errorHtml);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                this.container.find('.alert-danger').alert('close');
            }, 5000);
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

            if (diffDays === 0) {
                return date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            } else if (diffDays === 1) {
                return 'Yesterday';
            } else if (diffDays < 7) {
                return `${diffDays} days ago`;
            } else {
                return date.toLocaleDateString();
            }
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        $('.llm-chat-container').each(function() {
            new LlmChat(this);
        });
    });

})(jQuery);
