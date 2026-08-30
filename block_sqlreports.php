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

/**
 * Block that renders a report_sql report inline.
 *
 * @package   block_sqlreports
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_sql\local\query;

/**
 * Surfaces one published report (table or chart) on course pages, the site front page, or the
 * Dashboard. The report's per-user / teacher-course row filters are applied by the shared fetch
 * path, so one shared block instance shows each viewer only their own rows.
 */
class block_sqlreports extends block_base {

    /**
     * Set the default block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_sqlreports');
    }

    /**
     * Allow several instances on a page (e.g. one per report).
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return true;
    }

    /**
     * This block stores per-instance config (chosen report, display mode, …).
     *
     * @return bool
     */
    public function instance_allow_config(): bool {
        return true;
    }

    /**
     * Where the block may be added.
     *
     * @return array<string, bool>
     */
    public function applicable_formats(): array {
        return [
            'course-view' => true,
            'site'        => true,
            'my'          => true,
        ];
    }

    /**
     * Override the title with the configured custom title, or the chosen report's name.
     */
    public function specialization(): void {
        if (!empty($this->config->title)) {
            $this->title = format_string($this->config->title);
            return;
        }
        if (!empty($this->config->queryid)) {
            $menu = query::published_menu();
            if (isset($menu[(int) $this->config->queryid])) {
                $this->title = $menu[(int) $this->config->queryid];
            }
        }
    }

    /**
     * Build the block body.
     *
     * @return stdClass|null
     */
    public function get_content(): ?stdClass {
        global $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text   = '';
        $this->content->footer = '';

        $queryid = (int) ($this->config->queryid ?? 0);
        if (!$queryid) {
            // Unconfigured: prompt only while editing, otherwise stay empty so the block hides.
            if ($this->page->user_is_editing()) {
                $this->content->text = get_string('notconfigured', 'block_sqlreports');
            }
            return $this->content;
        }

        try {
            $query = query::get($queryid);
        } catch (\dml_missing_record_exception $e) {
            return $this->content;
        }

        // Honour the same access as the RB report viewer. No access → empty content → block hides.
        if (!$query->current_user_can_view_report()) {
            return $this->content;
        }

        $rec = $query->record();

        // Configured row limit: one of 5/10/25/50/100, or 0 = All (fetch caps internally at 5000).
        $configlimit = (int) ($this->config->rowlimit ?? 0);

        // "Show all" toggle: a viewer can expand this block instance to the full set without leaving
        // the page (so the page-course scope below is preserved). The link carries this block's id so
        // multiple SQL report blocks on one page expand independently.
        $blockid  = isset($this->instance->id) ? (int) $this->instance->id : 0;
        $expanded = $blockid > 0 && optional_param('sqlreportsall', 0, PARAM_INT) === $blockid;

        // Effective fetch limit: 0 (all) when expanded or configured to All, else the configured cap.
        $fetchlimit = ($expanded || $configlimit === 0) ? 0 : $configlimit;

        // On a course page, pass the current course id so a query with a pagecoursecolumn shows
        // only that course's rows. Off a course (Dashboard/site front page) the page course is
        // SITEID, which is not a real course scope — pass 0 so the page-course filter is skipped
        // and the viewer sees all rows their other filters/audience allow.
        $pagecourseid = (int) $this->page->course->id;
        if ($pagecourseid === SITEID) {
            $pagecourseid = 0;
        }

        try {
            $rows  = $query->fetch_rows_for_viewer($fetchlimit, $pagecourseid);
            $total = $query->count_rows_for_viewer($pagecourseid);
        } catch (\moodle_exception $e) {
            // Misconfigured filter column (fails closed). Show a neutral message, never raw rows.
            $this->content->text = get_string('errdata', 'block_sqlreports');
            return $this->content;
        }

        $chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
        $haschart  = !empty($chartmeta['type']) && $chartmeta['type'] !== 'none';
        $mode      = $this->config->displaymode ?? 'auto';
        $ischart   = $rows && ($mode === 'chart' || ($mode === 'auto' && $haschart));

        if ($ischart) {
            $this->content->text = $this->render_chart($rows, $chartmeta, $query);
        } else {
            $this->content->text = $this->render_table($rows, $query);
        }

        $this->content->footer = $this->build_footer($rec, $rows, $total, $expanded, $fetchlimit, $ischart);

        return $this->content;
    }

    /**
     * Build the block footer: a row-count line, an optional Show all / Show fewer toggle, and the
     * optional "View full report" link.
     *
     * The toggle re-renders the same page with a ?sqlreportsall=<blockid> param, so the page-course
     * scope is preserved (unlike "View full report", which opens the un-scoped RB report). It is
     * offered for the table view only — a chart aggregates every fetched row, so paging it is
     * meaningless.
     *
     * @param \stdClass $rec Query record (for reportid).
     * @param array<int, array<string, mixed>> $rows The rows actually rendered.
     * @param int $total Total rows the viewer may see (accurate, un-capped count).
     * @param bool $expanded Whether the viewer has expanded this block to the full set.
     * @param int $fetchlimit Effective fetch limit used (0 = all).
     * @param bool $ischart Whether the block rendered a chart (suppresses the paging toggle).
     * @return string Footer HTML.
     */
    protected function build_footer(\stdClass $rec, array $rows, int $total, bool $expanded,
            int $fetchlimit, bool $ischart): string {
        $shown = count($rows);

        // Accurate label: "Showing N of M" while limited, plain "M rows" when everything is shown.
        $countstr = ($shown < $total)
            ? get_string('rowcountpartial', 'block_sqlreports', (object) ['shown' => $shown, 'total' => $total])
            : get_string('rowcount', 'block_sqlreports', $total);
        $footer = html_writer::div($countstr, 'sqlreports-rowcount');

        // Show all / Show fewer toggle (table view only).
        if (!$ischart) {
            $url = new moodle_url($this->page->url);
            if ($expanded) {
                // Only worth a "Show fewer" when a smaller configured limit exists to return to.
                if ($fetchlimit === 0 && (int) ($this->config->rowlimit ?? 0) !== 0) {
                    $url->remove_params('sqlreportsall');
                    $footer .= html_writer::div(
                        html_writer::link($url, get_string('showfewer', 'block_sqlreports')),
                        'sqlreports-showtoggle'
                    );
                }
            } else if ($fetchlimit > 0 && $total > $shown && isset($this->instance->id)) {
                $url->param('sqlreportsall', (int) $this->instance->id);
                $footer .= html_writer::div(
                    html_writer::link($url, get_string('showall', 'block_sqlreports', $total)),
                    'sqlreports-showtoggle'
                );
            }
        }

        $showfull = (bool) ($this->config->showfull ?? 1);
        if ($showfull && !empty($rec->reportid)) {
            $fullurl = new moodle_url('/reportbuilder/view.php', ['id' => (int) $rec->reportid]);
            $footer .= html_writer::link(
                $fullurl,
                get_string('viewfull', 'block_sqlreports'),
                ['title' => get_string('viewfull_title', 'block_sqlreports')]
            );
        }
        return $footer;
    }

    /**
     * Render the rows as a simple table.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string
     */
    protected function render_table(array $rows, \report_sql\local\query $query): string {
        // Delegate to the shared inline renderer so the block table matches the filter embed and the
        // RB data report (same %%TIMESTAMP() date formatting, %%CASE() text-case, new-tab link fix).
        return \report_sql\output\embed_renderer::render_table($query, $rows);
    }

    /**
     * Render the rows as a chart, using the report's saved chart config.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $chartmeta
     * @param \report_sql\local\query $query The bound query (for %%CASE%% display transforms).
     * @return string
     */
    protected function render_chart(array $rows, array $chartmeta, \report_sql\local\query $query): string {
        $xcol = (string) ($chartmeta['xcol'] ?? '');
        $ycol = (string) ($chartmeta['ycol'] ?? '');
        if ($xcol === '' || $ycol === '') {
            return $this->render_table($rows, $query);
        }

        // Apply the x-column's %%CASE%% transform to the labels, matching chart.php / the RB chart
        // report — the transform is display-only (the stored value is raw), so the block must apply
        // it too or its labels differ from every other surface.
        [$labels, $values] = \report_sql\local\chart_presenter::chart_series(
            $rows, $xcol, $ycol, $query->column_textcase($xcol), $query->column_dateformat($xcol));
        $type = (string) $chartmeta['type'];

        // Category/legend label font size, saved with the chart config. Clamped to the same range as
        // the edit form and the RB chart report so the block matches those surfaces on republish.
        $labelsize = max(11, min(48, (int) ($chartmeta['labelsize'] ?? 16)));

        // Render the figure through the shared inline renderer — the same path filter_sqlreports
        // uses — so the block always mirrors any change to chart display (image + optional "Show data
        // table"). Server-side SVG: no JavaScript, no Chart.js; the <img> holds a base64 data URI and
        // cannot execute script.
        $out = \report_sql\output\embed_renderer::render_chart($query, $rows, $chartmeta, $this->title);

        // A chart is cramped in a narrow block region. Offer an in-page "Expand" that opens a modal
        // showing a larger copy of the same SVG (rendered here, no client-side charting library).
        $large = \report_sql\local\chart_svg::render(
            $type,
            $labels,
            $values,
            '',
            ['width' => 900, 'height' => 560, 'labelsize' => $labelsize,
                'multicolour' => !empty($chartmeta['multicolour'])]
        );
        $this->page->requires->js_call_amd('block_sqlreports/expand', 'init');
        $out .= html_writer::tag(
            'button',
            get_string('expandchart', 'block_sqlreports'),
            [
                'type'                => 'button',
                'class'               => 'btn btn-link btn-sm p-0 mt-1',
                'data-rsblock-expand' => '1',
                'data-chart-src'      => 'data:image/svg+xml;base64,' . base64_encode($large),
                'data-title'          => $this->title,
            ]
        );

        return $out;
    }
}
