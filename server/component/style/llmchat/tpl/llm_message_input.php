<div class="border-top bg-white p-3">
    <form id="message-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="send_message">
        <input type="hidden" name="conversation_id" id="current-conversation-id" value="<?php echo $current_conversation_id ?: ''; ?>">
        <input type="hidden" name="model" value="<?php echo htmlspecialchars($configured_model); ?>">
        <input type="hidden" name="temperature" value="<?php echo htmlspecialchars($llm_temperature); ?>">
        <input type="hidden" name="max_tokens" value="<?php echo htmlspecialchars($llm_max_tokens); ?>">

        <!-- File Attachments Preview Container -->
        <div class="d-none" id="file-attachments">
            <div id="attachments-list"></div>
        </div>

        <!-- Message Input with File Upload -->
        <div class="mb-3">
            <div class="position-relative message-input-wrapper" id="message-input-wrapper">
                <textarea class="form-control pr-5" id="message-input" name="message"
                          rows="3"
                          placeholder="<?php echo htmlspecialchars($message_placeholder); ?>"
                          maxlength="4000"
                          data-placeholder="<?php echo htmlspecialchars($message_placeholder); ?>"></textarea>
                <button type="button" class="btn btn-light btn-sm position-absolute attachment-btn" id="attachment-btn"
                        title="<?php echo htmlspecialchars($upload_image_label); ?>"
                        style="top: 8px; right: 8px;">
                    <i class="fas fa-paperclip"></i>
                </button>
            </div>
            <!-- Hidden file input - accept configured based on model -->
            <input type="file" id="file-upload" name="uploaded_files[]" accept="*" style="display: none;" multiple>
            <small class="text-muted d-block mt-1" id="upload-help-text">
                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($upload_help_text); ?>
            </small>
        </div>

        <!-- Character Counter and Action Buttons Row -->
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div class="mb-2 mb-sm-0">
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
