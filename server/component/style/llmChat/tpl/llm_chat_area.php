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
                    <?php
                    // Parse attachments from attachments field (contains full metadata as JSON)
                    $attachments = [];
                    if (!empty($message['attachments'])) {
                        $decoded = json_decode($message['attachments'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $attachments = $decoded;
                        }
                    }
                    $hasAttachments = !empty($attachments);
                    $attachmentCount = count($attachments);
                    $isUser = $message['role'] === 'user';
                    ?>
                    <div class="d-flex mb-3 <?php echo $isUser ? 'justify-content-end' : 'justify-content-start'; ?>">
                        <div class="rounded-circle d-flex align-items-center justify-content-center mr-3 flex-shrink-0 <?php echo $isUser ? 'bg-primary' : 'bg-success'; ?>" style="width: 38px; height: 38px;">
                            <?php if ($isUser): ?>
                                <i class="fas fa-user text-white"></i>
                            <?php else: ?>
                                <i class="fas fa-robot text-white"></i>
                            <?php endif; ?>
                        </div>
                        <div class="llm-message-content <?php echo $isUser ? 'bg-primary text-white' : 'bg-light'; ?> p-3 rounded border">
                            <div class="mb-2">
                                <?php echo $message['formatted_content']; ?>
                            </div>

                            <?php if ($hasAttachments): ?>
                                <div class="message-attachments">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <?php
                                        $isImage = isset($attachment['is_image']) ? $attachment['is_image'] :
                                            (isset($attachment['type']) && strpos($attachment['type'], 'image/') === 0);
                                        $filePath = $attachment['path'] ?? $attachment['url'] ?? '';
                                        $fileUrl = strpos($filePath, '?file_path=') === 0 ? $filePath : '?file_path=' . $filePath;
                                        $originalName = $attachment['original_name'] ?? basename($filePath);
                                        $fileSize = isset($attachment['size']) ? $this->formatFileSize($attachment['size']) : '';
                                        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                                        ?>
                                        <?php if ($isImage): ?>
                                            <div class="attachment-display-image mb-2">
                                                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" rel="noopener">
                                                    <img src="<?php echo htmlspecialchars($fileUrl); ?>"
                                                         alt="<?php echo htmlspecialchars($originalName); ?>"
                                                         class="img-fluid rounded"
                                                         style="max-width: 300px; max-height: 200px;">
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="attachment-display-file mb-2 p-2 border rounded bg-white">
                                                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" rel="noopener" class="d-flex align-items-center text-decoration-none">
                                                    <i class="fas fa-file mr-2 text-secondary"></i>
                                                    <span class="text-dark">
                                                        <?php echo htmlspecialchars($originalName); ?>
                                                        <?php if ($fileSize): ?>
                                                            <small class="text-muted">(<?php echo $fileSize; ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="mt-2">
                                <small class="<?php echo $isUser ? 'text-white-50' : 'text-muted'; ?>">
                                    <?php echo date('H:i', strtotime($message['timestamp'])); ?>
                                    <?php if (!empty($message['tokens_used'])): ?>
                                        • <?php echo $message['tokens_used']; ?><?php echo htmlspecialchars($tokens_used_suffix); ?>
                                    <?php endif; ?>
                                    <?php if ($hasAttachments): ?>
                                        • <i class="fas fa-paperclip"></i> <?php echo count($attachments); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
