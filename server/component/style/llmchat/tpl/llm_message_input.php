<div class="border-top bg-white p-3">
    <form id="message-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="send_message">
        <input type="hidden" name="conversation_id" id="current-conversation-id" value="<?php echo $current_conversation_id ?: ''; ?>">
        <input type="hidden" name="model" value="<?php echo htmlspecialchars($configured_model); ?>">
        <input type="hidden" name="temperature" value="<?php echo htmlspecialchars($llm_temperature); ?>">
        <input type="hidden" name="max_tokens" value="<?php echo htmlspecialchars($llm_max_tokens); ?>">

        <!-- File Attachments Preview -->
        <div class="mb-3 d-none" id="file-attachments">
            <div class="d-flex flex-wrap gap-2" id="attachments-list"></div>
        </div>

        <!-- Message Input with File Upload -->
        <div class="mb-3">
            <div class="position-relative" id="message-input-wrapper">
                <textarea class="form-control pr-5" id="message-input" name="message"
                          rows="3" placeholder="<?php echo htmlspecialchars($message_placeholder); ?>"
                          maxlength="4000"></textarea>
                <button type="button" class="btn btn-light btn-sm position-absolute attachment-btn" id="attachment-btn"
                        title="Attach file" style="top: 8px; right: 8px; display: none;">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            <!-- Hidden file input -->
            <input type="file" id="file-upload" name="image" accept="*" style="display: none;" multiple>
        </div>

        <!-- Character Counter and Action Buttons Row -->
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted" id="char-count">0/4000 characters</small>
            </div>
            <div>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="clear-message-btn">
                    <i class="fas fa-times"></i> <?php echo htmlspecialchars($clear_button_label); ?>
                </button>
                <button type="submit" class="btn btn-primary btn-sm" id="send-message-btn">
                    <i class="fas fa-paper-plane"></i> <?php echo htmlspecialchars($submit_button_label); ?>
                </button>
            </div>
        </div>
    </form>
</div>
