<?php
/**
 * Backward-compatibility alias — redirects to the consolidated
 * ModuleLlmScriptComponent. Kept so existing database page registrations
 * for "moduleLlmScriptMode" continue to resolve without error.
 */
require_once __DIR__ . "/../moduleLlmScript/ModuleLlmScriptComponent.php";

class ModuleLlmScriptModeComponent extends ModuleLlmScriptComponent
{
}
?>
