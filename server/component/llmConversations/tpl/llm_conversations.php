<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1>LLM Conversations</h1>
                    <p class="text-muted mb-0">Total: <?php echo $total_conversations; ?> conversations</p>
                </div>
                <a href="/admin" class="btn btn-secondary">Back to Admin</a>
            </div>

            <!-- Conversations table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">All User Conversations</h5>
                </div>
                <div class="card-body">

                    <?php if (empty($conversations)): ?>
                        <div class="alert alert-info">No conversations found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="conversations-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Title</th>
                                        <th>Model</th>
                                        <th>Messages</th>
                                        <th>Created</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($conversations as $conversation): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($conversation['id']); ?></td>
                                            <td><?php echo htmlspecialchars($conversation['user_name']); ?></td>
                                            <td><?php echo htmlspecialchars($conversation['title']); ?></td>
                                            <td><?php echo htmlspecialchars($conversation['model']); ?></td>
                                            <td><?php echo $this->model->getMessageCount($conversation['id']); ?></td>
                                            <td><?php echo $this->model->formatTimestamp($conversation['created_at']); ?></td>
                                            <td><?php echo $this->model->formatTimestamp($conversation['updated_at']); ?></td>
                                            <td>
                                                <a href="/admin/llm/conversation?id=<?php echo $conversation['id']; ?>" class="btn btn-sm btn-primary">View Messages</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Conversations pagination" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php
                                    $prev_page = $current_page - 1;
                                    $prev_disabled = $current_page <= 1 ? 'disabled' : '';
                                    ?>
                                    <li class="page-item <?php echo $prev_disabled; ?>">
                                        <a class="page-link" href="?page=<?php echo $prev_page; ?>" <?php echo $prev_disabled ? 'tabindex="-1"' : ''; ?>>Previous</a>
                                    </li>

                                    <?php
                                    $start_page = max(1, $current_page - 2);
                                    $end_page = min($total_pages, $current_page + 2);
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                        $active = $i === $current_page ? 'active' : '';
                                    ?>
                                        <li class="page-item <?php echo $active; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php
                                    $next_page = $current_page + 1;
                                    $next_disabled = $current_page >= $total_pages ? 'disabled' : '';
                                    ?>
                                    <li class="page-item <?php echo $next_disabled; ?>">
                                        <a class="page-link" href="?page=<?php echo $next_page; ?>" <?php echo $next_disabled ? 'tabindex="-1"' : ''; ?>>Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>
