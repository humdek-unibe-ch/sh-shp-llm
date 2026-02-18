<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseModel.php";

/**
 * Model for the LLM Scripts module.
 * Minimal model - CRUD operations are handled via AjaxLlmScripts.
 */
class ModuleLlmScriptModel extends BaseModel
{
    public function __construct($services)
    {
        parent::__construct($services);
    }
}
?>
