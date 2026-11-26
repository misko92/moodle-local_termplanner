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
 * Upgrade steps.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_termplanner_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026112800) {
        // Week layout, lab events and assignment cut-off per plan; due days and quiz times per item.
        $table = new xmldb_table('local_termplanner_plan');
        $fields = [
            new xmldb_field('weekstyle', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'list', 'groupids'),
            new xmldb_field('labevents', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'weekstyle'),
            new xmldb_field('cutoffdays', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '2', 'labevents'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_termplanner_item');
        $fields = [
            new xmldb_field('dueday', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'checkday'),
            new xmldb_field('opentime', XMLDB_TYPE_CHAR, '5', null, null, null, null, 'dueday'),
            new xmldb_field('closetime', XMLDB_TYPE_CHAR, '5', null, null, null, null, 'opentime'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026112800, 'local', 'termplanner');
    }

    return true;
}
