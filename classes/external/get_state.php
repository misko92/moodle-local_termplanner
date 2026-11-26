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
 * Web service: a plan's state for the week grid.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_termplanner\local\plan;
use local_termplanner\local\state;

/**
 * Returns the plan's state as JSON.
 */
class get_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['planid' => new external_value(PARAM_INT, 'Plan id')]);
    }

    /**
     * Load a plan the user may manage.
     *
     * @param int $planid
     * @return plan
     */
    public static function load_plan(int $planid): plan {
        global $DB;
        $record = $DB->get_record('local_termplanner_plan', ['id' => $planid], '*', MUST_EXIST);
        $context = \context_course::instance($record->courseid);
        self::validate_context($context);
        require_capability('local/termplanner:manage', $context);
        return new plan($record);
    }

    /**
     * Get the state.
     *
     * @param int $planid
     * @return array
     */
    public static function execute(int $planid): array {
        ['planid' => $planid] = self::validate_parameters(self::execute_parameters(), ['planid' => $planid]);
        return ['state' => json_encode(state::build(self::load_plan($planid))), 'error' => ''];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'state' => new external_value(PARAM_RAW, 'Plan state, JSON'),
            'error' => new external_value(PARAM_TEXT, 'Why a change was not made, or empty'),
        ]);
    }
}
