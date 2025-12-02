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
     data-cancel-delete-button-label="<?php echo htmlspecialchars($cancel_delete_button_label); ?>">
    <div class="llm-chat-description"><?php echo htmlspecialchars($chat_description); ?></div>

    <div class="llm-chat-layout">
        <!-- Conversations sidebar -->
        <div class="llm-conversations-sidebar">
            <?php include __DIR__ . '/llm_conversations_sidebar.php'; ?>
        </div>

        <!-- Main chat area -->
        <div class="llm-chat-main">
            <?php include __DIR__ . '/llm_chat_area.php'; ?>
            <?php include __DIR__ . '/llm_message_input.php'; ?>
        </div>
    </div>
</div>
