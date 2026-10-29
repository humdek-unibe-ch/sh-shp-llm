<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/LlmFormSchemaService.php';

/**
 * Typed control-flow abort for interview operations; converted into a
 * structured result at the public method boundary.
 */
final class LlmFormInterviewAbort extends Exception
{
    private $error_type;
    private $error_data;

    public function __construct($type, $message, array $data = array())
    {
        parent::__construct($message);
        $this->error_type = $type;
        $this->error_data = $data;
    }

    public function errorType()
    {
        return $this->error_type;
    }

    public function errorData()
    {
        return $this->error_data;
    }

    /**
     * A schema carrying _errors (duplicate/reserved/invalid field names)
     * is a CMS authoring problem: fail closed everywhere rather than
     * silently dropping fields from the catalog or an approval.
     */
    public static function requireValidSchema(array $schema)
    {
        if (!empty($schema[LlmFormSchemaService::ERRORS_KEY])) {
            // The field-level details are for the CMS author, not the participant
            error_log('[LLM] Interview form misconfigured: '
                . implode('; ', (array)$schema[LlmFormSchemaService::ERRORS_KEY]));
            throw new LlmFormInterviewAbort(
                'misconfigured',
                'This interview is not set up correctly. Please contact the site administrator.'
            );
        }
    }
}
?>
