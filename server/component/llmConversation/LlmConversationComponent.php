<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseComponent.php";
require_once __DIR__ . "/LlmConversationModel.php";
require_once __DIR__ . "/LlmConversationView.php";

/**
 * The LLM conversation admin component - shows individual conversation details
 */
class LlmConversationComponent extends BaseComponent
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the LlmConversationModel and
     * LlmConversationView classes.
     *
     * @param array $services
     *  An associative array holding the different available services.
     * @param array $params
     *  The GET parameters including conversation ID
     */
    public function __construct($services, $params)
    {
        $conversation_id = isset($params['id']) ? intval($params['id']) : null;
        $model = new LlmConversationModel($services, $conversation_id);
        $view = new LlmConversationView($model);
        parent::__construct($model, $view);
    }

    /* Private Methods *********************************************************/

    /* Public Methods *********************************************************/
}
?>