<?php
// Check if floating button mode is enabled to determine initial visibility
$enable_floating = $this->model->isFloatingButtonEnabled();
$container_class = 'llm-chat-root';
// When floating button is enabled, the container content is hidden initially
// and only the floating button is shown. This prevents UI jumping on load.
if ($enable_floating) {
    $container_class .= ' llm-floating-mode';
}
?>
<!-- LLM Chat React App Container -->
<!-- Uses class instead of ID to support multiple instances on the same page -->
<!-- llm-floating-mode class hides regular chat content when floating button is enabled -->
<div class="<?php echo $container_class; ?>"
     data-user-id="<?php echo $user_id; ?>"
     data-section-id="<?php echo $section_id; ?>"
     data-enable-floating="<?php echo $enable_floating ? '1' : '0'; ?>"
     data-config="<?php echo htmlspecialchars($this->getReactConfig()); ?>">
     <!-- React app will be mounted here -->
</div>
