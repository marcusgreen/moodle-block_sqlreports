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
 * Instance configuration form for block_sqlreports.
 *
 * @package   block_sqlreports
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use report_sql\local\query;

/**
 * Per-instance settings: which report to show, how to render it, and how many rows.
 */
class block_sqlreports_edit_form extends block_edit_form {

    /**
     * Define the block-specific config fields.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform): void {
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        $menu = ['' => get_string('choosereport', 'block_sqlreports')] + query::published_menu();
        $mform->addElement('select', 'config_queryid', get_string('report', 'block_sqlreports'), $menu);
        $mform->setType('config_queryid', PARAM_INT);
        $mform->addHelpButton('config_queryid', 'report', 'block_sqlreports');

        $mform->addElement('select', 'config_displaymode', get_string('displaymode', 'block_sqlreports'), [
            'auto'  => get_string('modeauto', 'block_sqlreports'),
            'table' => get_string('modetable', 'block_sqlreports'),
            'chart' => get_string('modechart', 'block_sqlreports'),
        ]);
        $mform->setType('config_displaymode', PARAM_ALPHA);
        $mform->setDefault('config_displaymode', 'auto');

        // 0 = All (fetch caps internally at 5000). Named options keep the block's initial render small
        // while letting a viewer expand via the "Show all" toggle in the block itself.
        $rowlimits = [
            5   => 5,
            10  => 10,
            25  => 25,
            50  => 50,
            100 => 100,
            0   => get_string('rowsall', 'block_sqlreports'),
        ];
        $mform->addElement('select', 'config_rowlimit', get_string('rowlimit', 'block_sqlreports'), $rowlimits);
        $mform->setType('config_rowlimit', PARAM_INT);
        $mform->setDefault('config_rowlimit', 0);
        $mform->addHelpButton('config_rowlimit', 'rowlimit', 'block_sqlreports');

        $mform->addElement('advcheckbox', 'config_showfull', get_string('showfull', 'block_sqlreports'));
        $mform->setType('config_showfull', PARAM_BOOL);
        $mform->setDefault('config_showfull', 0);
        $mform->addHelpButton('config_showfull', 'showfull', 'block_sqlreports');

        // Columns to hide from the rendered table. Options come from the currently-bound query, so
        // the report must be chosen and saved once before its columns can be picked.
        $queryid = (int) ($this->block->config->queryid ?? 0);
        $cols = [];
        if ($queryid) {
            try {
                $cols = array_keys(query::get($queryid)->columns_meta());
            } catch (\Throwable $e) {
                $cols = [];
            }
        }
        if ($cols) {
            $sel = $mform->addElement(
                'select',
                'config_hidecolumns',
                get_string('hidecolumns', 'block_sqlreports'),
                array_combine($cols, $cols)
            );
            $sel->setMultiple(true);
            $mform->setType('config_hidecolumns', PARAM_TEXT);
            $mform->addHelpButton('config_hidecolumns', 'hidecolumns', 'block_sqlreports');
        } else {
            $mform->addElement(
                'static',
                'hidecolumnsnote',
                get_string('hidecolumns', 'block_sqlreports'),
                get_string($queryid ? 'hidecolumnsnone' : 'hidecolumnspickfirst', 'block_sqlreports')
            );
        }

        $mform->addElement('text', 'config_title', get_string('blocktitle', 'block_sqlreports'));
        $mform->setType('config_title', PARAM_TEXT);
    }
}
