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
 * Web service: change a plan from the week grid.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_termplanner\local\schoolcal;
use local_termplanner\local\state;

/**
 * Applies a list of changes to a plan and returns its new state.
 *
 * Operations: move (itemid, day, before), set (itemid, field = day|level|text|closes|check, value), delete (itemid),
 * add (day, cmid or text, level), reset (itemid: usual lab deadlines), shift (day, n: everything from that day on).
 */
class update extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'planid' => new external_value(PARAM_INT, 'Plan id'),
            'ops' => new external_multiple_structure(new external_single_structure([
                'op' => new external_value(PARAM_ALPHA, 'move, set, delete, add, reset or shift'),
                'itemid' => new external_value(PARAM_INT, 'Item', VALUE_DEFAULT, 0),
                'day' => new external_value(PARAM_RAW_TRIMMED, 'YYYY-MM-DD', VALUE_DEFAULT, ''),
                'before' => new external_value(PARAM_INT, 'Item to go before, 0 for the end of the day', VALUE_DEFAULT, 0),
                'field' => new external_value(PARAM_ALPHA, 'Field to set', VALUE_DEFAULT, ''),
                'value' => new external_value(PARAM_TEXT, 'New value', VALUE_DEFAULT, ''),
                'cmid' => new external_value(PARAM_INT, 'Activity to add', VALUE_DEFAULT, 0),
                'text' => new external_value(PARAM_TEXT, 'Text line to add', VALUE_DEFAULT, ''),
                'level' => new external_value(PARAM_INT, 'Indent level', VALUE_DEFAULT, 0),
                'n' => new external_value(PARAM_INT, 'School days to shift', VALUE_DEFAULT, 0),
            ])),
        ]);
    }

    /**
     * Apply the changes.
     *
     * @param int $planid
     * @param array $ops
     * @return array
     */
    public static function execute(int $planid, array $ops): array {
        ['planid' => $planid, 'ops' => $ops] = self::validate_parameters(
            self::execute_parameters(),
            ['planid' => $planid, 'ops' => $ops]
        );
        $plan = get_state::load_plan($planid);
        $term = $plan->cal->term;
        $inside = fn(string $d) => schoolcal::valid_day($d) && $d >= $term->startday && $d <= $term->endday;
        $error = '';
        foreach ($ops as $o) {
            $has = isset($plan->items[$o['itemid']]);
            switch ($o['op']) {
                case 'move':
                    if ($has && $inside($o['day'])) {
                        $plan->place_item($o['itemid'], $o['day'], $o['before']);
                    }
                    break;
                case 'set':
                    if (!$has) {
                        break;
                    }
                    $v = $o['value'];
                    if ($o['field'] === 'level') {
                        $plan->update_item($o['itemid'], ['level' => max(0, min(3, (int)$v))]);
                    } else if ($o['field'] === 'text' && !$plan->items[$o['itemid']]->cmid) {
                        $plan->update_item($o['itemid'], ['text' => $v]);
                    } else if ($o['field'] === 'day' && $inside($v)) {
                        $plan->update_item($o['itemid'], ['day' => $v]);
                    } else if (in_array($o['field'], ['closes', 'check'], true) && ($v === '' || $inside($v))) {
                        $plan->update_item($o['itemid'], [$o['field'] . 'day' => $v === '' ? null : $v]);
                    }
                    break;
                case 'delete':
                    if ($has) {
                        $plan->delete_item($o['itemid']);
                    }
                    break;
                case 'add':
                    if ($inside($o['day']) && ($o['cmid'] ? isset($plan->activities()[$o['cmid']]) : trim($o['text']) !== '')) {
                        $plan->add_item($o['day'], $o['cmid'], trim($o['text']), $o['level']);
                    }
                    break;
                case 'reset':
                    if ($has) {
                        $plan->reset_deadlines($o['itemid']);
                    }
                    break;
                case 'shift':
                    if ($inside($o['day']) && $o['n'] && !$plan->shift_from($o['day'], $o['n'])) {
                        $error = get_string('shiftoutside', 'local_termplanner');
                    }
                    break;
            }
        }
        return ['state' => json_encode(state::build($plan)), 'error' => $error];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_state::execute_returns();
    }
}
