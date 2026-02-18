<!-- Core Data Config Builder: hidden wrapper, modal relocated to body by React on mount -->
<div id="data-config-builder-wrapper" style="display:none !important;">
    <?php $dataConfigBuilder->output_content(); ?>
</div>

<!-- LLM Scripts React App Container -->
<div id="llm-scripts-root"
     data-config="<?php echo htmlspecialchars($config); ?>">
</div>
