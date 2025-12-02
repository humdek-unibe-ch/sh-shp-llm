<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/LlmConversationsModel.php";
require_once __DIR__ . "/LlmConversationsView.php";

/**
 * The LLM conversations admin component - lists all user conversations
 */
class LlmConversationsComponent extends BaseComponent
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the LlmConversationsModel and
     * LlmConversationsView classes.
     *
     * @param array $services
     *  An associative array holding the different available services.
     * @param array $params
     *  The GET parameters
     */
    public function __construct($services, $params)
    {
        $model = new LlmConversationsModel($services);
        $view = new LlmConversationsView($model);
        parent::__construct($model, $view);
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/
}
?>