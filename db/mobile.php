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
 * Moodle Mobile app addon definition for block_sqlreports.
 *
 * Declares a CoreBlockDelegate handler so the app renders the report block inline wherever it
 * draws the blocks region (Dashboard, course). The named method is dispatched by the app through
 * tool_mobile_get_content, so it needs no separate db/services.php entry.
 *
 * @package   block_sqlreports
 * @copyright 2026 Marcus Green
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'block_sqlreports' => [
        'handlers' => [
            'view' => [
                'delegate' => 'CoreBlockDelegate',
                'method'   => 'mobile_block_view',
                'offlinefunctions' => [
                    'mobile_block_view' => [],
                ],
            ],
        ],
    ],
];
