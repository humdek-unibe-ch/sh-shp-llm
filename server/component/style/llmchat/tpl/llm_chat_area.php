<div class="chat-messages" id="chat-messages">
    <?php if (!$conversation): ?>
        <div class="no-conversation-selected">
            <div class="text-center text-muted">
                <i class="fas fa-comments fa-3x mb-3"></i>
                <h5><?php echo htmlspecialchars($select_conversation_heading); ?></h5>
                <p><?php echo htmlspecialchars($select_conversation_description); ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="conversation-header">
            <h6><?php echo htmlspecialchars($conversation['title']); ?></h6>
            <small class="text-muted"><?php echo htmlspecialchars($model_label_prefix); ?><?php echo htmlspecialchars($conversation['model']); ?></small>
        </div>

        <div class="messages-container" id="messages-container">
            <?php if (empty($messages)): ?>
                <div class="no-messages">
                    <p class="text-center text-muted"><?php echo htmlspecialchars($no_messages_message); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <div class="message <?php echo $message['role'] === 'user' ? 'message-user' : 'message-assistant'; ?>">
                        <div class="message-avatar">
                            <?php if ($message['role'] === 'user'): ?>
                                <i class="fas fa-user"></i>
                            <?php else: ?>
                                <i class="fas fa-robot"></i>
                            <?php endif; ?>
                        </div>
                        <div class="message-content">
                            <div class="message-text">
                                <?php echo $message['formatted_content']; ?>
                            </div>
                            <?php if (!empty($message['image_path'])): ?>
                                <div class="message-image">
                                    <img src="?file_path=<?php echo htmlspecialchars($message['image_path']); ?>"
                                         alt="Uploaded image" class="img-fluid rounded">
                                </div>
                            <?php endif; ?>
                            <div class="message-meta">
                                <small class="text-muted">
                                    <?php echo date('H:i', strtotime($message['timestamp'])); ?>
                                    <?php if (!empty($message['tokens_used'])): ?>
                                        • <?php echo $message['tokens_used']; ?><?php echo htmlspecialchars($tokens_used_suffix); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Streaming indicator -->
        <div class="streaming-indicator" id="streaming-indicator" style="display: none;">
            <div class="d-flex align-items-center">
                <i class="fas fa-spinner fa-spin me-2 text-primary" role="status" aria-hidden="true"></i>
                <small class="text-muted"><?php echo htmlspecialchars($ai_thinking_text); ?></small>
            </div>
        </div>
    <?php endif; ?>
</div>
