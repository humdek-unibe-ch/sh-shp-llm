<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * Links into the CMS admin that several LLM admin pages share.
 *
 * The CMS identifies a section on a page by the root section attached to the page
 * (`sid`) plus, when the section sits deeper in the tree, the selected child
 * (`ssid`). The memory page and the prompts hub both need these URLs.
 */
class LlmCmsLinkHelper
{
    /** Longest section chain walked up from a section to the root section of a page. */
    const MAX_HIERARCHY_DEPTH = 20;

    /**
     * CMS admin URL that opens a section on a page, in the CMS UI the current admin uses.
     *
     * @param object $services SelfHelp services container
     * @param int $page_id Page the section is edited on
     * @param int $root_section_id Section attached to that page
     * @param int $section_id Section to open (equal to the root section for the root itself)
     * @return string|null Null when the route cannot be generated
     */
    public static function sectionUrl($services, $page_id, $root_section_id, $section_id)
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
                    'type' => 'prop'
                ));
            }

            return $router->generate('cmsSelect', array(
                'pid' => (int)$page_id,
                'sid' => (int)$root_section_id,
                'ssid' => $selected_section_id
            ));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * The section attached to a page that contains a section: the section itself or the
     * nearest ancestor listed in pages_sections for that page.
     *
     * Sections that reach a page only through a navigation section have no such root;
     * the CMS cannot open them by this route.
     *
     * @param object $db Core database handle
     * @param int $section_id
     * @param int $page_id
     * @return int|null Null when the page does not attach the section or one of its ancestors.
     */
    public static function rootSectionOnPage($db, $section_id, $page_id)
    {
        $row = $db->query_db_first(
            "WITH RECURSIVE ancestors (id, depth) AS (
                SELECT s.id, 0
                FROM sections s
                WHERE s.id = :section_id
                UNION ALL
                SELECT sh.parent, a.depth + 1
                FROM sections_hierarchy sh
                INNER JOIN ancestors a ON sh.child = a.id
                WHERE a.depth < " . (int)self::MAX_HIERARCHY_DEPTH . "
            )
            SELECT a.id AS root_section_id
            FROM ancestors a
            INNER JOIN pages_sections ps ON ps.id_sections = a.id
            WHERE ps.id_pages = :page_id
            ORDER BY a.depth ASC
            LIMIT 1",
            array(
                ':section_id' => (int)$section_id,
                ':page_id' => (int)$page_id
            )
        );

        return ($row && isset($row['root_section_id'])) ? (int)$row['root_section_id'] : null;
    }
}
?>
