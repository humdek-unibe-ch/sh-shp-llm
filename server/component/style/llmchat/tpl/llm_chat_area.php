<div class="d-flex flex-column h-100" id="chat-messages">
    <?php if (!$conversation): ?>
        <div class="d-flex align-items-center justify-content-center flex-column h-100 p-5">
            <div class="text-center text-muted">
                <i class="fas fa-comments fa-4x mb-4"></i>
                <h5><?php echo htmlspecialchars($select_conversation_heading); ?></h5>
                <p><?php echo htmlspecialchars($select_conversation_description); ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="p-3 border-bottom bg-white">
            <h6 class="mb-1"><?php echo htmlspecialchars($conversation['title']); ?></h6>
            <small class="text-muted"><?php echo htmlspecialchars($model_label_prefix); ?><?php echo htmlspecialchars($conversation['model']); ?></small>
        </div>

        <div class="flex-fill p-3 overflow-auto" id="messages-container">
            <?php if (empty($messages)): ?>
                <div class="d-flex align-items-center justify-content-center h-100">
                    <p class="text-center text-muted"><?php echo htmlspecialchars($no_messages_message); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <div class="d-flex mb-3 <?php echo $message['role'] === 'user' ? 'justify-content-end' : 'justify-content-start'; ?>">
                        <div class="rounded-circle d-flex align-items-center justify-content-center mr-3 flex-shrink-0 <?php echo $message['role'] === 'user' ? 'bg-primary' : 'bg-success'; ?>" style="width: 38px; height: 38px;">
                            <?php if ($message['role'] === 'user'): ?>
                                <i class="fas fa-user"></i>
                            <?php else: ?>
                                <i class="fas fa-robot"></i>
                            <?php endif; ?>
                        </div>
                        <div class="llm-message-content bg-white p-3 rounded border">
                            <div class="mb-2">
                                <?php echo $message['formatted_content']; ?>
                            </div>
                            <?php if (!empty($message['image_path'])): ?>
                                <div class="mt-3">
                                    <img src="?file_path=<?php echo htmlspecialchars($message['image_path']); ?>"
                                         alt="Uploaded image" class="img-fluid rounded">
                                </div>
                            <?php endif; ?>
                            <div class="mt-2">
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
        <div class="d-none p-3 border-top bg-light" id="streaming-indicator">
            <div class="d-flex align-items-center">
                <i class="fas fa-spinner fa-spin mr-2 text-primary" role="status" aria-hidden="true"></i>
                <small class="text-muted"><?php echo htmlspecialchars($ai_thinking_text); ?></small>
            </div>
        </div>
    <?php endif; ?>
</div>
