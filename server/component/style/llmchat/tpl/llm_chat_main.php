<div class="llm-chat-container"
     data-user-id="<?php echo $user_id; ?>"
     data-no-conversations-message="<?php echo htmlspecialchars($no_conversations_message); ?>"
     data-configured-model="<?php echo htmlspecialchars($configured_model); ?>"
     data-current-conversation-id="<?php echo htmlspecialchars($current_conversation_id ?? ''); ?>"
     data-streaming-enabled="<?php echo $this->model->isStreamingEnabled() ? '1' : '0'; ?>"
    data-new-conversation-title-label="<?php echo htmlspecialchars($new_conversation_title_label); ?>"
    data-conversation-title-label="<?php echo htmlspecialchars($conversation_title_label); ?>"
    data-cancel-button-label="<?php echo htmlspecialchars($cancel_button_label); ?>"
    data-create-button-label="<?php echo htmlspecialchars($create_button_label); ?>"
    data-delete-confirmation-title="<?php echo htmlspecialchars($delete_confirmation_title); ?>"
    data-delete-confirmation-message="<?php echo htmlspecialchars($delete_confirmation_message); ?>"
    data-confirm-delete-button-label="<?php echo htmlspecialchars($confirm_delete_button_label); ?>"
    data-cancel-delete-button-label="<?php echo htmlspecialchars($cancel_delete_button_label); ?>"
    data-message-placeholder="<?php echo htmlspecialchars($message_placeholder); ?>"
    data-max-file-size="<?php echo LLM_MAX_FILE_SIZE; ?>"
    data-max-files="<?php echo LLM_MAX_FILES_PER_MESSAGE; ?>"
    data-allowed-extensions="<?php echo htmlspecialchars(implode(',', LLM_ALLOWED_EXTENSIONS)); ?>"
    data-enable-conversations-list="<?php echo $this->model->isConversationsListEnabled() ? '1' : '0'; ?>">
    <div class="bg-primary text-white p-3 text-center">
        <h5 class="mb-0"><?php echo htmlspecialchars($conversation_name); ?></h5>
        <small><?php echo htmlspecialchars($chat_description); ?></small>
    </div>

    <div class="container-fluid">
        <div class="row">
            <?php if ($this->model->isConversationsListEnabled()): ?>
            <!-- Conversations sidebar -->
            <div class="col-md-4 col-lg-3 border-right bg-light d-flex flex-column">
                <?php include __DIR__ . '/llm_conversations_sidebar.php'; ?>
            </div>

            <!-- Main chat area -->
            <div class="col-md-8 col-lg-9 d-flex flex-column">
                <?php include __DIR__ . '/llm_chat_area.php'; ?>
                <?php include __DIR__ . '/llm_message_input.php'; ?>
            </div>
            <?php else: ?>
            <!-- Full-width chat area when conversations list is disabled -->
            <div class="col-12 d-flex flex-column">
                <?php include __DIR__ . '/llm_chat_area.php'; ?>
                <?php include __DIR__ . '/llm_message_input.php'; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
