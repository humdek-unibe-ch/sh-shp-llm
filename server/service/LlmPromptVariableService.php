<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

class LlmPromptVariableService
{
    /**
     * Extract {{variable}} placeholders from a template.
     *
     * @param string $template
     * @return array
     */
    public function detectVariables($template)
    {
        if (!is_string($template) || $template === '') {
            return array();
        }

        if (!preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', $template, $matches)) {
            return array();
        }

        $variables = array();
        foreach (($matches[1] ?? array()) as $name) {
            $variables[] = (string)$name;
        }

        $variables = array_values(array_unique($variables));
        sort($variables);

        return $variables;
    }

    /**
     * Build a simple playground schema from detected variables.
     *
     * @param string $template
     * @return array
     */
    public function buildAutoSchema($template)
    {
        $variables = $this->detectVariables($template);
        $schema = array();

        foreach ($variables as $name) {
            $schema[] = array(
                'name' => $name,
                'type' => 'string',
                'required' => true,
                'description' => 'Auto-detected from prompt template'
            );
        }

        return $schema;
    }
}
?>
