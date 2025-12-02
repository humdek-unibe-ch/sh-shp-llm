<div class="conversations-header">
    <h5><?php echo htmlspecialchars($conversations_heading); ?></h5>
    <button type="button" class="btn btn-primary btn-sm" id="new-conversation-btn">
        <?php echo htmlspecialchars($new_chat_button_label); ?>
    </button>
</div>

<div class="conversations-list" id="conversations-list">
    <?php if (empty($conversations)): ?>
        <div class="no-conversations">
            <p><?php echo htmlspecialchars($no_conversations_message); ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($conversations as $conversation): ?>
            <div class="conversation-item <?php echo $conversation['id'] == $current_conversation_id ? 'active' : ''; ?>"
                 data-conversation-id="<?php echo $conversation['id']; ?>">
                <div class="conversation-title">
                    <?php echo htmlspecialchars($conversation['title']); ?>
                </div>
                <div class="conversation-meta">
                    <small class="text-muted">
                        <?php echo htmlspecialchars($conversation['model']); ?> •
                        <?php echo date('M j, H:i', strtotime($conversation['updated_at'])); ?>
                    </small>
                </div>
                <div class="conversation-actions">
                    <button type="button" class="btn btn-sm btn-outline-danger delete-conversation-btn"
                            data-conversation-id="<?php echo $conversation['id']; ?>">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
