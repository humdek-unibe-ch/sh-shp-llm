<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Standalone test for the CMS link helper that the memory page and the prompts hub share
 * (plugin v1.10.0, step 4 = a pure move).
 *
 * `LlmMemoryAdminService::buildCmsSectionUrl()` became `LlmCmsLinkHelper::sectionUrl()`. This test
 * proves that nothing changed for the memory page:
 *  - the helper returns the same URL as a frozen copy of the old private method, in both CMS UIs,
 *    for a root section, a nested section, zero-padded core ids, and a router that throws;
 *  - the real `LlmMemoryAdminService::getSectionFieldSources()` (stub database and router) puts
 *    exactly that URL into `target_url`;
 *  - the old private method is gone, so there is no second copy that can drift.
 *
 * Only the service file is required: if it did not require the helper itself, the call below
 * would be a fatal error.
 *
 * Run: php tests/LlmCmsLinkHelperTest.php
 */

require_once __DIR__ . '/../server/service/globals.php';

// Core constant used by the link (server/service/globals.php of the core)
if (!defined('UPDATE')) {
    define('UPDATE', 'update');
}

require_once __DIR__ . '/../server/service/LlmMemoryAdminService.php';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$failed = 0;
$total = 0;

function check($label, $condition)
{
    global $failed, $total;
    $total++;
    if ($condition) {
        echo "ok:   $label\n";
    } else {
        echo "FAIL: $label\n";
        $failed++;
    }
}

/** The private method as it was in LlmMemoryAdminService before the move (frozen reference). */
function legacy_memory_section_url($services, $page_id, $root_section_id, $section_id)
{
    $selected_section_id = (int)$section_id === (int)$root_section_id ? null : (int)$section_id;

    try {
        $router = $services->get_router();
        if ($services->get_user_input()->is_new_ui_enabled()) {
            return $router->generate('cmsUpdate', array(
                'pid' => (int)$page_id,
                'sid' => (int)$root_section_id,
                'ssid' => $selected_section_id,
                'mode' => UPDATE,
                'type' => 'prop',
            ));
        }

        return $router->generate('cmsSelect', array(
            'pid' => (int)$page_id,
            'sid' => (int)$root_section_id,
            'ssid' => $selected_section_id,
        ));
    } catch (Exception $e) {
        return null;
    }
}

/** Router stand-in: the URL spells out every parameter, null included. */
class LinkFakeRouter
{
    public $throws = false;

    public function generate($name, $params = array())
    {
        if ($this->throws) {
            throw new Exception('no such route');
        }
        return '/route/' . $name . '?' . json_encode($params);
    }
}

class LinkFakeUserInput
{
    public $new_ui = false;

    public function is_new_ui_enabled()
    {
        return $this->new_ui;
    }
}

/** Database stand-in for the two statements getSectionFieldSources() runs. */
class LinkFakeDb
{
    public function get_cache()
    {
        return null;
    }

    public function query_db($sql, $params = array())
    {
        if (strpos($sql, 'WITH RECURSIVE') !== false) {
            // Section 111 sits in 100 which is attached to page 5; section 200 is on no page
            return array(
                array('section_id' => 111, 'root_section_id' => 100, 'page_id' => 5, 'page_keyword' => 'coaching', 'page_url' => '/coaching'),
                array('section_id' => 100, 'root_section_id' => 100, 'page_id' => 5, 'page_keyword' => 'coaching', 'page_url' => '/coaching'),
            );
        }
        return array(
            array('section_id' => 111, 'section_name' => 'Nested form', 'style_name' => 'formUserInputRecord', 'rule_ids' => '3,4'),
            array('section_id' => 100, 'section_name' => 'Top form', 'style_name' => 'llmChat', 'rule_ids' => '3'),
            array('section_id' => 200, 'section_name' => '', 'style_name' => 'llmChat', 'rule_ids' => '4'),
        );
    }
}

class LinkFakeServices
{
    public $router;
    public $user_input;
    public $db;

    public function __construct()
    {
        $this->router = new LinkFakeRouter();
        $this->user_input = new LinkFakeUserInput();
        $this->db = new LinkFakeDb();
    }

    public function get_router()
    {
        return $this->router;
    }

    public function get_user_input()
    {
        return $this->user_input;
    }

    public function get_db()
    {
        return $this->db;
    }
}

/** Rule service stand-in: the comma list of the field becomes ids. */
class LinkFakeRuleService
{
    public function normalizeRuleIds($raw)
    {
        return array_values(array_filter(array_map('intval', explode(',', (string)$raw))));
    }
}

// --- The helper against the frozen old method ----------------------------------------------------------------------------

check('the service file loads the helper itself', class_exists('LlmCmsLinkHelper', false));

$services = new LinkFakeServices();

$inputs = array(
    'a root section' => array(5, 100, 100),
    'a nested section' => array(5, 100, 111),
    'zero-padded core ids (strings), root' => array('0000000005', '0000000100', '0000000100'),
    'zero-padded core ids (strings), nested' => array('0000000005', '0000000100', '0000000111'),
);

foreach (array(false, true) as $new_ui) {
    $services->user_input->new_ui = $new_ui;
    $ui = $new_ui ? 'new CMS UI' : 'old CMS UI';
    foreach ($inputs as $label => $args) {
        $helper = LlmCmsLinkHelper::sectionUrl($services, $args[0], $args[1], $args[2]);
        $legacy = legacy_memory_section_url($services, $args[0], $args[1], $args[2]);
        check("$ui, $label: the helper returns the URL of the old private method", is_string($helper) && $helper === $legacy);
    }
}

$services->user_input->new_ui = false;
$old_root = LlmCmsLinkHelper::sectionUrl($services, 5, 100, 100);
$old_nested = LlmCmsLinkHelper::sectionUrl($services, 5, 100, 111);
check('old UI: the route is cmsSelect, a root section selects nothing deeper', strpos($old_root, '/route/cmsSelect?') === 0 && strpos($old_root, '"ssid":null') !== false);
check('old UI: a nested section is the selected child (ssid)', strpos($old_nested, '"ssid":111') !== false && strpos($old_nested, '"sid":100') !== false);
$services->user_input->new_ui = true;
$new_nested = LlmCmsLinkHelper::sectionUrl($services, 5, 100, 111);
check('new UI: the route is cmsUpdate in update mode with the properties tab',
    strpos($new_nested, '/route/cmsUpdate?') === 0 && strpos($new_nested, '"mode":"update"') !== false && strpos($new_nested, '"type":"prop"') !== false);

$services->router->throws = true;
check('a router that cannot generate the route gives null, as before',
    LlmCmsLinkHelper::sectionUrl($services, 5, 100, 111) === null && legacy_memory_section_url($services, 5, 100, 111) === null);
$services->router->throws = false;

// --- The real memory service uses the helper -----------------------------------------------------------------------------

$service = (new ReflectionClass('LlmMemoryAdminService'))->newInstanceWithoutConstructor();
(new ReflectionProperty('BaseLlmService', 'services'))->setValue($service, $services);
(new ReflectionProperty('BaseLlmService', 'db'))->setValue($service, $services->db);
(new ReflectionProperty('LlmMemoryAdminService', 'rule_service'))->setValue($service, new LinkFakeRuleService());
$collect = new ReflectionMethod('LlmMemoryAdminService', 'getSectionFieldSources');

foreach (array(false, true) as $new_ui) {
    $services->user_input->new_ui = $new_ui;
    $ui = $new_ui ? 'new CMS UI' : 'old CMS UI';
    $sources = $collect->invoke($service);
    $by_section = array();
    foreach ($sources as $source) {
        $by_section[$source['target_id']] = $source;
    }

    check("$ui: one source per section that selects rules", array_keys($by_section) === array(111, 100, 200));
    check("$ui: a nested section gets the helper's URL (same input, same URL)",
        $by_section[111]['target_url'] === LlmCmsLinkHelper::sectionUrl($services, 5, 100, 111)
        && $by_section[111]['target_url'] === legacy_memory_section_url($services, 5, 100, 111));
    check("$ui: a root section gets the helper's URL (same input, same URL)",
        $by_section[100]['target_url'] === LlmCmsLinkHelper::sectionUrl($services, 5, 100, 100)
        && $by_section[100]['target_url'] === legacy_memory_section_url($services, 5, 100, 100));
    check("$ui: a section on no page has no URL", $by_section[200]['target_url'] === null);
}

// --- No second copy ------------------------------------------------------------------------------------------------------

check('LlmMemoryAdminService no longer has its own buildCmsSectionUrl()', !(new ReflectionClass('LlmMemoryAdminService'))->hasMethod('buildCmsSectionUrl'));
check('the helper has no copy of the route building in the service file',
    strpos((string)file_get_contents(__DIR__ . '/../server/service/LlmMemoryAdminService.php'), "'cmsUpdate'") === false);

echo "\n$total checks, $failed failed\n";
exit($failed > 0 ? 1 : 0);
