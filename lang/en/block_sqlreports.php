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
 * Strings for block_sqlreports.
 *
 * @package   block_sqlreports
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['blocktitle'] = 'Custom block title';
$string['choosereport'] = 'Choose a report…';
$string['displaymode'] = 'Display as';
$string['download'] = 'Download';
$string['downloadpng'] = 'PNG image';
$string['downloadsvg'] = 'SVG vector';
$string['errdata'] = 'This report cannot be displayed here.';
$string['expandchart'] = 'Expand chart';
$string['exitfullscreen'] = 'Exit fullscreen';
$string['fullscreen'] = 'Fullscreen';
$string['modeauto'] = 'Automatic (chart if configured, else table)';
$string['modechart'] = 'Chart';
$string['modetable'] = 'Table';
$string['norows'] = 'No data to show.';
$string['notconfigured'] = 'Edit this block and choose a report to display.';
$string['pluginname'] = 'SQL report';
$string['report'] = 'Report';
$string['report_help'] = 'The published report to show in this block. Only published reports are listed. Each viewer still sees only the rows the report itself allows them to see (for example, only the courses they teach). On a course page the block is limited to that course; the "View full report" link opens the complete report without that course limit.';
$string['sqlreports:addinstance'] = 'Add a new SQL report block';
$string['sqlreports:myaddinstance'] = 'Add a new SQL report block to the Dashboard';
$string['rowlimit'] = 'Maximum rows';
$string['showfull'] = 'Show "View full report" link';
$string['showfull_help'] = 'When enabled, the block shows a "View full report" link that opens the complete report. On a course page the block itself is limited to that course, but the full report is not. Turn this off to hide the link and keep viewers within the block\'s course-scoped view.';
$string['viewfull'] = 'View full report';
$string['viewfull_title'] = 'Opens the complete report with every row you are allowed to see. Unlike this block, it is not limited to the current course.';
