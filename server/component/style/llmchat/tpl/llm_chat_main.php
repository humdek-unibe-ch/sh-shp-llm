<div class="llm-chat-container" data-user-id="<?php echo $user_id; ?>" data-no-conversations-message="<?php echo htmlspecialchars($no_conversations_message); ?>">
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
