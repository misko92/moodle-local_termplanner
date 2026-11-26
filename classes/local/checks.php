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
 * Planning rule checks.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\local;

/**
 * Checks a plan against the planning rules. The results are advice; nothing is blocked.
 *
 * - at most 2 labs done in a week, and deadlines for at most 2 labs in a week;
 * - a lab comes after the CFAs that come before it in the same topic of the course;
 * - a test comes after its topic's CFAs;
 * - every item and deadline is on a school day, and no deadline comes before the lab.
 */
class checks {
    /** @var int Most labs in one week. */
    public const MAX_LABS = 2;

    /**
     * Run all checks.
     *
     * @param plan $plan
     * @return array ['problems' => list of [message, itemid, week], 'weekflags' => [week => string[]], 'items' => [itemid => true]]
     */
    public static function run(plan $plan): array {
        $acts = $plan->activities();
        $cal = $plan->cal;
        $problems = [];
        $flagged = [];
        $weekflags = [];
        $labsbyweek = [];
        $duebyweek = [];
        // Position of an item in the plan: day, then order within the day.
        $pos = fn($it) => $it->day . '|' . str_pad((string)$it->sortorder, 4, '0', STR_PAD_LEFT);
        $byactivity = [];
        foreach ($plan->items as $it) {
            if ($it->cmid) {
                $byactivity[$it->cmid] = $it;
            }
        }
        $add = function (string $key, array $a, ?int $itemid, ?int $week) use (&$problems, &$flagged) {
            $message = get_string('check_' . $key, 'local_termplanner', (object)$a);
            $problems[] = ['message' => $message, 'itemid' => $itemid, 'week' => $week];
            if ($itemid) {
                $flagged[$itemid] = true;
            }
        };

        foreach ($plan->items as $it) {
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            $week = $cal->week_of($it->day);
            if ($it->cmid && !$act) {
                $add('missing', [], (int)$it->id, $week);
                continue;
            }
            if (!$cal->is_school_day($it->day)) {
                $add(
                    'notschool',
                    ['name' => $act->short ?? s(shorten_text((string)$it->text, 40)), 'day' => schoolcal::short($it->day)],
                    (int)$it->id,
                    $week
                );
            }
            if (!$act) {
                continue;
            }
            if ($act->kind === 'lab') {
                $labsbyweek[$week][] = $act->short;
                foreach (['closesday', 'checkday'] as $f) {
                    if (!$it->$f) {
                        continue;
                    }
                    $duebyweek[$cal->week_of($it->$f)][$act->short] = true;
                    if (!$cal->is_school_day($it->$f)) {
                        $add('deadlinenotschool', ['name' => $act->short, 'day' => schoolcal::short($it->$f)], (int)$it->id, $week);
                    }
                    if ($it->$f < $it->day) {
                        $add('deadlinebefore', ['name' => $act->short, 'day' => schoolcal::short($it->$f)], (int)$it->id, $week);
                    }
                }
                if (!$it->checkday) {
                    $add('nocheckday', ['name' => $act->short], (int)$it->id, $week);
                }
            }
            if ($act->kind === 'lab' || $act->kind === 'test') {
                // CFAs of the same topic that come earlier in the course but later in the plan.
                $late = [];
                foreach ($acts as $other) {
                    if (
                        $other->kind === 'cfa' && $other->section === $act->section && $other->order < $act->order
                            && isset($byactivity[$other->cmid]) && $pos($byactivity[$other->cmid]) > $pos($it)
                    ) {
                        $late[] = $other->short;
                    }
                }
                if ($late) {
                    $add(
                        $act->kind === 'lab' ? 'labbeforecfa' : 'testbeforecfa',
                        ['name' => $act->short, 'cfas' => implode(', ', $late)],
                        (int)$it->id,
                        $week
                    );
                }
            }
        }
        foreach ($labsbyweek as $week => $labs) {
            if ($week !== null && count($labs) > self::MAX_LABS) {
                $add('toomanylabs', ['week' => $week, 'count' => count($labs), 'labs' => implode(', ', $labs)], null, $week);
                $weekflags[$week][] = get_string('flag_labs', 'local_termplanner', count($labs));
            }
        }
        foreach ($duebyweek as $week => $labs) {
            if ($week !== null && count($labs) > self::MAX_LABS) {
                $a = ['week' => $week, 'count' => count($labs), 'labs' => implode(', ', array_keys($labs))];
                $add('toomanydue', $a, null, $week);
                $weekflags[$week][] = get_string('flag_due', 'local_termplanner', count($labs));
            }
        }
        return ['problems' => $problems, 'weekflags' => $weekflags, 'items' => $flagged];
    }
}
