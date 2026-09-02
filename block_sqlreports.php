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

        $rec      = $query->record();
        $rowlimit = max(1, min(100, (int) ($this->config->rowlimit ?? 10)));

        // On a course page, pass the current course id so a query with a pagecoursecolumn shows
        // only that course's rows. Off a course (Dashboard/site front page) the page course is
        // SITEID, which is not a real course scope — pass 0 so the page-course filter is skipped
        // and the viewer sees all rows their other filters/audience allow.
        $pagecourseid = (int) $this->page->course->id;
        if ($pagecourseid === (int) SITEID) {
            $pagecourseid = 0;
        }

        try {
            $rows = $query->fetch_rows_for_viewer($rowlimit, $pagecourseid);
        } catch (\moodle_exception $e) {
            // Misconfigured filter column (fails closed). Show a neutral message, never raw rows.
            $this->content->text = get_string('errdata', 'block_sqlreports');
            return $this->content;
        }

        $chartmeta = $rec->chartmeta ? json_decode($rec->chartmeta, true) : [];
        $haschart  = !empty($chartmeta['type']) && $chartmeta['type'] !== 'none';
        $mode      = $this->config->displaymode ?? 'auto';

        // Columns the instance is configured to hide, plus — when the page-course filter actually
        // applied (course page, not Dashboard) — the page-course column itself, which is constant
        // across every row here and so is pure noise in a block.
        $hide = (array) ($this->config->hidecolumns ?? []);
        if ($pagecourseid > 0 && ($pc = $query->pagecoursecolumn()) !== '') {
            $hide[] = $pc;
        }

        if ($rows && ($mode === 'chart' || ($mode === 'auto' && $haschart))) {
            $this->content->text = $this->render_chart($rows, $chartmeta, $query);
        } else {
            $this->content->text = $this->render_table($rows, $query, $hide);
        }

        if (!empty($rec->reportid)) {
            $url = new moodle_url('/reportbuilder/view.php', ['id' => (int) $rec->reportid]);
            $this->content->footer = html_writer::link($url, get_string('viewfull', 'block_sqlreports'));
        }

        return $this->content;
    }

    /**
     * Render the rows as a simple table.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param \report_sql\local\query $query
     * @param string[] $hide Output column names to omit from display.
     * @return string
     */
    protected function render_table(array $rows, \report_sql\local\query $query, array $hide = []): string {
        // Delegate to the shared inline renderer so the block table matches the filter embed and the
        // RB data report (same %%TIMESTAMP() date formatting, %%CASE() text-case, new-tab link fix).
        return \report_sql\output\embed_renderer::render_table($query, $rows, $hide);
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
        [$labels, $values] = \report_sql\local\query::chart_series(
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
