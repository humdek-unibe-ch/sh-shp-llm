<div class="p-3 border-bottom bg-white">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><?php echo htmlspecialchars($conversations_heading); ?></h5>
        <button type="button" class="btn btn-primary btn-sm" id="new-conversation-btn">
            <?php echo htmlspecialchars($new_chat_button_label); ?>
        </button>
    </div>
</div>

<div class="flex-fill overflow-auto p-2" id="conversations-list">
    <?php if (empty($conversations)): ?>
        <div class="text-center text-muted p-4">
            <p><?php echo htmlspecialchars($no_conversations_message); ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($conversations as $conversation): ?>
            <div class="card mb-2 position-relative <?php echo $conversation['id'] == $current_conversation_id ? 'border-primary bg-light' : ''; ?>"
                 data-conversation-id="<?php echo $conversation['id']; ?>" style="cursor: pointer;">
                <div class="card-body py-2 px-3">
                    <div class="font-weight-bold mb-1"><?php echo htmlspecialchars($conversation['title']); ?></div>
                    <div class="small text-muted">
                        <?php echo htmlspecialchars($conversation['model']); ?> •
                        <?php echo date('M j, H:i', strtotime($conversation['updated_at'])); ?>
                    </div>
                    <div class="position-absolute opacity-0" style="top: 8px; right: 8px;">
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                data-conversation-id="<?php echo $conversation['id']; ?>" title="Delete conversation">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
