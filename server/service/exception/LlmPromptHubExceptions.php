<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Outcomes of a prompts hub write (LlmPromptHubSaveService) that the caller has to tell apart from
 * a failure. ModuleLlmPromptsController maps each one to an HTTP status: conflict 409, forbidden
 * 403, not found 404. A bad request is the built-in InvalidArgumentException (400); anything else
 * is an unexpected failure (500, generic text, details in the log).
 *
 * @package LLM Plugin
 */

/**
 * The prompt is not what the editor loaded: its active version or its text changed in the
 * meantime (a CMS save, a Prompt Lab save, another hub user). Nothing was written.
 */
class LlmPromptHubConflictException extends Exception
{
    /** @var array What is live now: version_id, version_no, content, content_hash, updated_at, updated_by */
    private $current;

    /**
     * @param string $message
     * @param array $current The live state the editor has to reload or rebase on
     */
    public function __construct($message, array $current)
    {
        parent::__construct($message, 409);
        $this->current = $current;
    }

    /**
     * @return array
     */
    public function getCurrent()
    {
        return $this->current;
    }
}

/**
 * The user may not change this prompt: no update access on the page of its owner, an orphaned
 * section, or a version that belongs to another prompt.
 */
class LlmPromptHubForbiddenException extends Exception
{
    /** @var string Short code the UI can show: no_access, orphaned, wrong_version */
    private $reason;

    /**
     * @param string $message
     * @param string $reason
     */
    public function __construct($message, $reason = '')
    {
        parent::__construct($message, 403);
        $this->reason = (string)$reason;
    }

    /**
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }
}

/**
 * There is no such prompt, or the user may not see it. Both answer the same, so the hub does not
 * tell a user which prompts exist behind pages they cannot open.
 */
class LlmPromptHubNotFoundException extends Exception
{
    /**
     * @param string $message
     */
    public function __construct($message = 'Prompt not found')
    {
        parent::__construct($message, 404);
    }
}
?>
