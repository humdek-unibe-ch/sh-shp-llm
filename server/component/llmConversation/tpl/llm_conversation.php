<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">

            <!-- Header with navigation -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1>Conversation Details</h1>
                    <?php if ($conversation): ?>
                        <p class="text-muted mb-0">
                            User: <strong><?php echo htmlspecialchars($this->model->getUserName()); ?></strong> |
                            Model: <strong><?php echo htmlspecialchars($conversation['model']); ?></strong> |
                            Created: <strong><?php echo $this->model->formatTimestamp($conversation['created_at']); ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div>
                    <a href="/admin/llm/conversations" class="btn btn-secondary me-2">Back to List</a>
                    <a href="/admin" class="btn btn-outline-secondary">Admin Home</a>
                </div>
            </div>

            <?php if (!$this->model->conversationExists()): ?>
                <div class="alert alert-danger">Conversation not found or access denied.</div>
            <?php else: ?>
                <!-- Conversation stats -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $this->model->getMessageCount(); ?></h5>
                                <p class="card-text text-muted">Messages</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($conversation['temperature']); ?></h5>
                                <p class="card-text text-muted">Temperature</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($conversation['max_tokens']); ?></h5>
                                <p class="card-text text-muted">Max Tokens</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $this->model->getTotalTokens(); ?></h5>
                                <p class="card-text text-muted">Total Tokens</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Conversation Messages</h5>
                    </div>
                    <div class="card-body">

                        <?php if (empty($messages)): ?>
                            <div class="alert alert-info">No messages in this conversation yet.</div>
                        <?php else: ?>
                            <div style="max-height: 600px; overflow-y: auto;">
                                <?php foreach ($messages as $message): ?>
                                    <?php
                                    $is_user = $message['role'] === 'user';
                                    $avatar_class = $is_user ? 'bg-primary' : 'bg-secondary';
                                    $avatar_icon = $is_user ? 'fa-user' : 'fa-robot';
                                    ?>
                                    <div class="mb-4 <?php echo $is_user ? 'text-right' : 'text-left'; ?>">
                                        <div class="d-flex <?php echo $is_user ? 'flex-row-reverse' : 'flex-row'; ?>">
                                            <!-- Avatar -->
                                            <div class="flex-shrink-0 mr-3">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center <?php echo $avatar_class; ?> text-white" style="width: 40px; height: 40px;">
                                                    <i class="fas <?php echo $avatar_icon; ?>"></i>
                                                </div>
                                            </div>

                                            <!-- Message content -->
                                            <div class="flex-grow-1" style="max-width: 80%;">
                                                <div class="bg-light rounded p-3 mb-2">
                                                    <!-- Message text -->
                                                    <div class="mb-2">
                                                        <?php echo nl2br(htmlspecialchars($message['content'])); ?>
                                                    </div>

                                                    <!-- Attachment count display -->
                                                    <?php
                                                    $attachmentCount = 0;
                                                    if (!empty($message['attachments'])) {
                                                        $decoded = json_decode($message['attachments'], true);
                                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                                            $attachmentCount = count($decoded);
                                                        }
                                                    }
                                                    if ($attachmentCount > 0):
                                                    ?>
                                                        <div class="mt-2">
                                                            <small class="text-muted">
                                                                <i class="fas fa-paperclip"></i>
                                                                <?php echo $attachmentCount === 1 ? '1 file attached' : $attachmentCount . ' files attached'; ?>
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Message metadata -->
                                                <div class="small text-muted">
                                                    <strong><?php echo ucfirst($message['role']); ?></strong> •
                                                    <?php echo $this->model->formatTimestamp($message['timestamp']); ?>

                                                    <?php if (!empty($message['model'])): ?>
                                                        • Model: <?php echo htmlspecialchars($message['model']); ?>
                                                    <?php endif; ?>

                                                    <?php if (!empty($message['tokens_used'])): ?>
                                                        • <?php echo $message['tokens_used']; ?> tokens
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
