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
                            <div class="conversation-messages" style="max-height: 600px; overflow-y: auto;">
                                <?php foreach ($messages as $message): ?>
                                    <?php
                                    $is_user = $message['role'] === 'user';
                                    $message_class = $is_user ? 'message-user' : 'message-assistant';
                                    $avatar_class = $is_user ? 'bg-primary' : 'bg-secondary';
                                    $avatar_icon = $is_user ? 'fa-user' : 'fa-robot';
                                    ?>
                                    <div class="message mb-4 <?php echo $message_class; ?>">
                                        <div class="d-flex">
                                            <!-- Avatar -->
                                            <div class="flex-shrink-0 me-3">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center <?php echo $avatar_class; ?> text-white" style="width: 40px; height: 40px;">
                                                    <i class="fas <?php echo $avatar_icon; ?>"></i>
                                                </div>
                                            </div>

                                            <!-- Message content -->
                                            <div class="flex-grow-1">
                                                <div class="bg-light rounded p-3 mb-2" style="max-width: 80%; <?php echo $is_user ? 'margin-left: auto;' : ''; ?>">
                                                    <!-- Message text -->
                                                    <div class="message-text mb-2">
                                                        <?php echo nl2br(htmlspecialchars($message['content'])); ?>
                                                    </div>

                                                    <!-- Image attachment -->
                                                    <?php if (!empty($message['image_path'])): ?>
                                                        <div class="message-image">
                                                            <small class="text-muted">Image: </small>
                                                            <a href="?file_path=<?php echo htmlspecialchars($message['image_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary ms-2">
                                                                <i class="fas fa-image"></i> View Image
                                                            </a>
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
