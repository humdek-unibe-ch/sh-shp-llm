<!-- LLM Chat React App Container -->
<!-- Uses class instead of ID to support multiple instances on the same page -->
<div class="llm-chat-root"
     data-user-id="<?php echo $user_id; ?>"
     data-section-id="<?php echo $section_id; ?>"
     data-config="<?php echo htmlspecialchars($this->getReactConfig()); ?>">
     <!-- React app will be mounted here -->
</div>
