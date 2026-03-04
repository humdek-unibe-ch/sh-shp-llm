<?php
/**
 * Template for LLM Form styles (llmFormRecord and llmFormLog).
 * Renders the standard form and an LLM React container.
 */
$llm_config = $this->getLlmReactConfig();
$section_id = $this->model->get_section_id();
$form_name = $this->model->get_db_field('name', '');
$placement = $this->model->getLlmResultPlacement();
?>
<!-- LLM Form Container -->
<div class="llm-form-root"
     data-section-id="<?php echo $section_id; ?>"
     data-form-name="<?php echo htmlspecialchars($form_name, ENT_QUOTES, 'UTF-8'); ?>"
     data-placement="<?php echo htmlspecialchars($placement, ENT_QUOTES, 'UTF-8'); ?>"
     data-llm-config="<?php echo $llm_config; ?>">

    <!-- Standard SelfHelp form rendered by parent -->
    <div class="llm-form-content">
        <?php parent::output_content(); ?>
    </div>

    <!-- React LLM Result Panel mounts here -->
    <div class="llm-result-container" data-section-id="<?php echo $section_id; ?>"></div>
</div>
