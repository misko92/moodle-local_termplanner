<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Web services used by the week grid.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_termplanner_get_state' => [
        'classname' => \local_termplanner\external\get_state::class,
        'description' => 'A plan\'s weeks, items, checks and previews for the week grid.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/termplanner:manage',
    ],
    'local_termplanner_update' => [
        'classname' => \local_termplanner\external\update::class,
        'description' => 'Move, change, add or remove items of a plan and return its new state.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/termplanner:manage',
    ],
];
