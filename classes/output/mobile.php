<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace block_sqlreports\output;

use core_reportbuilder\permission;
use report_sql\local\query;
use moodle_url;

/**
 * Mobile app output for block_sqlreports.
 *
 * @package   block_sqlreports
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Render the configured report as a table for the Moodle Mobile app.
     *
     * Reuses the same data + access path as the web block ({@see \block_sqlreports::get_content}):
     * the report's own view gate and per-viewer row filtering, so the app sees exactly the rows the
     * web block would show the same user. Chart display modes fall back to the table on mobile;
     * interactive charts are a later follow-up.
     *
     * @param array $args Arguments from tool_mobile_get_content (expects 'blockid').
     * @return array Templates + javascript for the app.
     */
    public static function mobile_block_view(array $args): array {
        global $OUTPUT, $CFG;
        require_once($CFG->libdir . '/blocklib.php');

        $args = (object) $args;

        $data = [
            'title'   => '',
            'hasrows' => false,
            'headers' => [],
            'rows'    => [],
            'norows'  => get_string('norows', 'block_sqlreports'),
            'error'   => '',
            'rowcount' => '',
            'fullurl' => '',
            'viewfull' => get_string('viewfull', 'block_sqlreports'),
        ];

        // Load the addressed block, failing closed on a missing/invalid id or a block that is not
        // one of ours (blockid is untyped web-service input, and block_instance_by_id returns false
        // when no such block exists).
        $blockid = (int) ($args->blockid ?? 0);
        $blockinstance = $blockid ? block_instance_by_id($blockid) : false;
        if (!$blockinstance || $blockinstance->instance->blockname !== 'sqlreports') {
            return self::wrap($OUTPUT, $data);
        }

        // The web block is only ever drawn after core's block manager has checked the viewer can see
        // this context; the app dispatches straight to us, so re-apply that gate here. Without it a
        // viewer could pull a block's rows from a context they cannot otherwise reach.
        if (!has_capability('moodle/block:view', $blockinstance->context)) {
            return self::wrap($OUTPUT, $data);
        }

        $config = $blockinstance->config ?? new \stdClass();
        $data['title'] = (string) ($blockinstance->title ?? '');

        // Honour the block's per-instance role restriction, exactly as the web block does
        // ({@see \block_sqlreports::user_has_display_role}). The web path applies it; the app must
        // too, or an admin-configured visibility limit is silently bypassed on mobile.
        if (!self::user_has_display_role($blockinstance->context, $config)) {
            return self::wrap($OUTPUT, $data);
        }

        $queryid = (int) ($config->queryid ?? 0);
        if (!$queryid) {
            // Unconfigured block: return an empty view so the app shows nothing.
            return self::wrap($OUTPUT, $data);
        }

        try {
            $query = query::get($queryid);
        } catch (\dml_missing_record_exception $e) {
            return self::wrap($OUTPUT, $data);
        }

        // Same view gate as the web block / RB report viewer. No access → empty view.
        if (!$query->current_user_can_view_report()) {
            return self::wrap($OUTPUT, $data);
        }

        // Resolve the host page course id from the block context, mirroring the web block: a block
        // outside any course (Dashboard/system) has no course context, so pass 0 to skip the
        // page-course filter. On a course page pass that course id.
        $coursecontext = $blockinstance->context->get_course_context(false);
        $pagecourseid = $coursecontext ? (int) $coursecontext->instanceid : 0;

        // Configured row limit: 0 = All (fetch caps internally at 5000). The app has no in-place
        // expand, so it just honours the configured limit.
        $rowlimit = (int) ($config->rowlimit ?? 0);

        try {
            $rows  = $query->fetch_rows_for_viewer($rowlimit, $pagecourseid);
            $total = $query->count_rows_for_viewer($pagecourseid);
        } catch (\moodle_exception $e) {
            // Misconfigured filter column (fails closed). Neutral message, never raw rows.
            $data['error'] = get_string('errdata', 'block_sqlreports');
            return self::wrap($OUTPUT, $data);
        }

        if ($rows) {
            $data['hasrows'] = true;
            $data['headers'] = array_map(
                static fn($h): array => ['label' => (string) $h],
                array_keys($rows[0])
            );
            foreach ($rows as $row) {
                $cells = array_map(
                    // Send plain-text cells: html_to_text strips author HTML so nothing unsafe reaches
                    // the app, and the value is HTML-escaped again by Mustache on render.
                    static fn($v): array => ['value' => trim(html_to_text((string) $v, 0, false))],
                    array_values($row)
                );
                $data['rows'][] = ['cells' => $cells];
            }
            // Accurate label: "Showing N of M" while limited, plain "M rows" when all are shown.
            $shown = count($rows);
            $data['rowcount'] = ($shown < $total)
                ? get_string('rowcountpartial', 'block_sqlreports', (object) ['shown' => $shown, 'total' => $total])
                : get_string('rowcount', 'block_sqlreports', $total);
        }

        // Offer a link to the full RB report (opened in the app's in-app browser).
        $rec = $query->record();
        $showfull = (bool) ($config->showfull ?? 0);
        if ($showfull && !empty($rec->reportid)) {
            $url = new moodle_url('/reportbuilder/view.php', ['id' => (int) $rec->reportid]);
            $data['fullurl'] = $url->out(false);
        }

        return self::wrap($OUTPUT, $data);
    }

    /**
     * Whether the current viewer passes the block's per-instance role restriction.
     *
     * Mirrors {@see \block_sqlreports::user_has_display_role}: empty config means no restriction;
     * otherwise the viewer must hold one of the configured roles in the block's context or a parent.
     * Site administrators always pass.
     *
     * @param \context $context The block instance context.
     * @param \stdClass $config The block instance config.
     * @return bool
     */
    protected static function user_has_display_role(\context $context, \stdClass $config): bool {
        global $USER;

        $wanted = array_filter(array_map('intval', (array) ($config->roles ?? [])));
        if (!$wanted) {
            return true;
        }
        if (is_siteadmin()) {
            return true;
        }

        // Role lookup walks up the context tree, so a course-level role is honoured here.
        foreach (get_user_roles($context, $USER->id, true) as $ra) {
            if (in_array((int) $ra->roleid, $wanted, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wrap the view data in the app response envelope.
     *
     * @param \core_renderer $output
     * @param array $data Template context.
     * @return array
     */
    protected static function wrap(\core_renderer $output, array $data): array {
        return [
            'templates' => [
                [
                    'id'   => 'main',
                    'html' => $output->render_from_template('block_sqlreports/mobile_view_page', $data),
                ],
            ],
            'javascript' => '',
            'otherdata'  => [],
            'files'      => [],
        ];
    }
}
