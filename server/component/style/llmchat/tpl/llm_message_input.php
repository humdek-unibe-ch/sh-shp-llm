<div class="message-input-container">
    <form id="message-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="send_message">
        <input type="hidden" name="conversation_id" id="current-conversation-id" value="<?php echo $current_conversation_id ?: ''; ?>">
        <input type="hidden" name="model" value="<?php echo htmlspecialchars($configured_model); ?>">
        <input type="hidden" name="temperature" value="<?php echo htmlspecialchars($llm_temperature); ?>">
        <input type="hidden" name="max_tokens" value="<?php echo htmlspecialchars($llm_max_tokens); ?>">

        <!-- File Upload for Vision Models -->
        <div class="mb-2" id="file-upload-container" style="display: none;">
            <label for="file-upload" class="form-label"><?php echo htmlspecialchars($upload_image_label); ?></label>
            <input type="file" class="form-control form-control-sm" id="file-upload" name="image"
                   accept="image/*">
            <div class="form-text">
                <?php echo htmlspecialchars($upload_help_text); ?>
            </div>
        </div>

        <!-- Message Input -->
        <div class="mb-2">
            <textarea class="form-control" id="message-input" name="message"
                      rows="3" placeholder="<?php echo htmlspecialchars($message_placeholder); ?>"
                      maxlength="4000"></textarea>
            <div class="form-text">
                <small class="text-muted" id="char-count">0/4000 characters</small>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between">
            <div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="clear-message-btn">
                    <i class="fas fa-times"></i> <?php echo htmlspecialchars($clear_button_label); ?>
                </button>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" id="send-message-btn">
                    <i class="fas fa-paper-plane"></i>
                    <?php echo htmlspecialchars($submit_button_label); ?>
                </button>
            </div>
        </div>
    </form>
</div>
