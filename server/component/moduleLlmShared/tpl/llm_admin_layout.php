<?php
/**
 * Shared admin layout for all LLM module pages.
 *
 * Expected variables:
 *   $menuItems  - array from LlmAdminLayoutHelper::getMenuItems()
 *   $pageContent - string of HTML to render in the content area
 */
?>
<div class="llm-admin-wrapper">
    <nav class="llm-admin-sidebar" aria-label="LLM Administration">
        <div class="llm-admin-sidebar-header">
            <i class="fa fa-robot mr-2"></i>
            <span>LLM</span>
        </div>
        <ul class="llm-admin-nav">
            <?php foreach ($menuItems as $item): ?>
            <li class="llm-admin-nav-item<?php echo $item['active'] ? ' active' : ''; ?><?php echo !$item['hasAccess'] ? ' disabled' : ''; ?>">
                <?php if ($item['hasAccess']): ?>
                <a href="<?php echo htmlspecialchars($item['url']); ?>" class="llm-admin-nav-link">
                    <i class="fa <?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
                <?php else: ?>
                <span class="llm-admin-nav-link" title="You do not have permission to access this section">
                    <i class="fa <?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['label']); ?></span>
                    <i class="fa fa-lock llm-admin-lock-icon"></i>
                </span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <main class="llm-admin-content">
        <?php echo $pageContent; ?>
    </main>
</div>
