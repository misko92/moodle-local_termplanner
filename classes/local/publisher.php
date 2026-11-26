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
 * Publishes a plan to the course calendar.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\local;

use stdClass;

/**
 * Builds the week events and lab deadline events of a plan and keeps the calendar in step with it.
 *
 * Every event the plan creates is recorded with a key (week:N, closes:ITEM, check:ITEM, plus :gGROUP),
 * so publishing again updates those events in place, creates missing ones and deletes ones no longer needed.
 * Events the plan did not create are never touched.
 */
class publisher {
    /** @var plan */
    protected plan $plan;

    /**
     * Constructor.
     *
     * @param plan $plan
     */
    public function __construct(plan $plan) {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');
        $this->plan = $plan;
    }

    /**
     * The lines students see for one week, as [level, text] pairs.
     *
     * @param int $week week number
     * @return array[]
     */
    public function week_lines(int $week): array {
        $acts = $this->plan->activities();
        $items = $this->plan->week_items($week);
        $lines = [];
        $cfas = [];
        $flushcfas = function () use (&$cfas, &$lines) {
            if ($cfas) {
                $lines[] = [$cfas[0][0], get_string(count($cfas) > 1 ? 'line_docfas' : 'line_docfa', 'local_termplanner')];
                foreach ($cfas as [$lvl, $name]) {
                    $lines[] = [$lvl + 1, $name];
                }
                $cfas = [];
            }
        };
        foreach ($items as $it) {
            $lvl = (int)$it->level;
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            if ($it->cmid && !$act) {
                continue;
            }
            if ($act && $act->kind === 'cfa') {
                if ($cfas && $cfas[0][0] !== $lvl) {
                    $flushcfas();
                }
                $cfas[] = [$lvl, $act->name];
                continue;
            }
            $flushcfas();
            if (!$act) {
                $lines[] = [$lvl, (string)$it->text];
            } else if ($act->kind === 'lab') {
                $lines[] = [$lvl, get_string('line_dolab', 'local_termplanner', $act->name)];
                $lines[] = [$lvl + 1, get_string('line_checkofflab', 'local_termplanner', $act->short)];
            } else if ($act->kind === 'test') {
                $lines[] = [$lvl, get_string('line_take', 'local_termplanner', $act->name)];
            } else if ($act->kind === 'ws') {
                $lines[] = [$lvl, get_string('line_checkoffws', 'local_termplanner', $act->name)];
            } else {
                $lines[] = [$lvl, get_string('line_do', 'local_termplanner', $act->name)];
            }
        }
        $flushcfas();
        // Lab deadlines that fall in this week.
        $due = [];
        foreach ($this->plan->items as $it) {
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            if (!$act || $act->kind !== 'lab') {
                continue;
            }
            $cal = $this->plan->cal;
            $closesnow = $it->closesday && $cal->week_of($it->closesday) === $week;
            $checknow = $it->checkday && $cal->week_of($it->checkday) === $week;
            if ($closesnow || $checknow) {
                $a = (object)['lab' => $act->short, 'closes' => $it->closesday ? schoolcal::short($it->closesday) : '',
                    'check' => $it->checkday ? schoolcal::short($it->checkday) : ''];
                $due[($it->checkday ?? $it->closesday) . $act->order] = get_string(
                    $it->closesday ? 'line_deadline' : 'line_deadlinecheck',
                    'local_termplanner',
                    $a
                );
            }
        }
        if ($due) {
            ksort($due);
            $lines[] = [0, get_string('line_labdeadlines', 'local_termplanner')];
            foreach ($due as $text) {
                $lines[] = [1, $text];
            }
        }
        return $lines;
    }

    /**
     * Turn [level, text] lines into nested HTML lists.
     *
     * @param array[] $lines
     * @return string
     */
    public static function lines_to_html(array $lines): string {
        if (!$lines) {
            return '';
        }
        $html = '';
        $depth = -1;
        foreach ($lines as [$lvl, $text]) {
            $lvl = min($lvl, $depth + 1);
            if ($lvl > $depth) {
                $html .= str_repeat('<ul>', $lvl - $depth);
            } else {
                $html .= '</li>' . str_repeat('</ul></li>', $depth - $lvl);
            }
            $html .= '<li>' . s($text);
            $depth = $lvl;
        }
        return $html . '</li>' . str_repeat('</ul></li>', $depth) . '</ul>';
    }

    /**
     * A timestamp for a day and time in the site's timezone.
     *
     * @param string $day YYYY-MM-DD
     * @param int $hour
     * @param int $minute
     * @return int
     */
    protected static function time(string $day, int $hour, int $minute): int {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        return make_timestamp($y, $m, $d, $hour, $minute, 0, \core_date::get_server_timezone());
    }

    /**
     * The events the plan should have, keyed by event key.
     *
     * @return stdClass[]
     */
    public function wanted(): array {
        $plan = $this->plan;
        $acts = $plan->activities();
        $base = [];
        foreach ($plan->cal->weeks() as $w) {
            // A week gets an event when it has anything to show, even if that is only lab deadlines.
            $lines = $w['number'] === null ? [] : $this->week_lines($w['number']);
            if (!$lines) {
                continue;
            }
            // Finals days count here: the week's event may sit on the last day of finals.
            $days = array_column(array_filter($w['days'], fn($d) => $d['school'] || $d['type'] === 'finals'), 'day');
            $first = reset($days);
            $last = end($days);
            $e = (object)['name' => trim($plan->record->eventprefix) . ' W' . $w['number'],
                'description' => self::lines_to_html($lines), 'timeduration' => 0];
            if ($plan->record->eventday === 'first') {
                $e->timestart = self::time($first, 0, 0);
            } else if ($plan->record->eventday === 'span') {
                $e->timestart = self::time($first, 0, 0);
                $e->timeduration = self::time($last, 23, 59) - $e->timestart;
            } else {
                $e->timestart = self::time($last, 0, 0);
            }
            $base['week:' . $w['number']] = $e;
        }
        foreach ($plan->items as $it) {
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            if (!$act || $act->kind !== 'lab') {
                continue;
            }
            if ($it->closesday) {
                $base['closes:' . $it->id] = (object)['name' => get_string('event_closes', 'local_termplanner', $act->short),
                    'description' => '<p>' . get_string('event_closes_desc', 'local_termplanner') . '</p>',
                    'timestart' => self::time($it->closesday, 23, 59), 'timeduration' => 0];
            }
            if ($it->checkday) {
                $base['check:' . $it->id] = (object)['name' => get_string('event_check', 'local_termplanner', $act->short),
                    'description' => '<p>' . get_string('event_check_desc', 'local_termplanner') . '</p>',
                    'timestart' => self::time($it->checkday, 23, 59), 'timeduration' => 0];
            }
        }
        // One copy per group, or one course event when the plan has no groups.
        $groups = $plan->group_ids() ?: [0];
        $wanted = [];
        foreach ($base as $key => $e) {
            foreach ($groups as $g) {
                $copy = clone $e;
                $copy->groupid = $g;
                $wanted[$key . ($g ? ':g' . $g : '')] = $copy;
            }
        }
        return $wanted;
    }

    /**
     * Compare the wanted events with what the plan published before.
     *
     * @return array ['create' => keys, 'update' => keys, 'delete' => keys, 'same' => keys, 'wanted' => events]
     */
    public function diff(): array {
        global $DB;
        $wanted = $this->wanted();
        $tracked = $DB->get_records('local_termplanner_event', ['planid' => $this->plan->record->id], '', 'eventkey, id, eventid');
        $out = ['create' => [], 'update' => [], 'delete' => [], 'same' => [], 'wanted' => $wanted];
        foreach ($wanted as $key => $e) {
            $ev = isset($tracked[$key]) ? $DB->get_record('event', ['id' => $tracked[$key]->eventid]) : null;
            if (!$ev) {
                $out['create'][] = $key;
            } else if (
                $ev->name !== $e->name || $ev->description !== $e->description || (int)$ev->timestart !== $e->timestart
                    || (int)$ev->timeduration !== $e->timeduration || (int)$ev->groupid !== $e->groupid
            ) {
                $out['update'][] = $key;
            } else {
                $out['same'][] = $key;
            }
        }
        foreach ($tracked as $key => $t) {
            if (!isset($wanted[$key])) {
                $out['delete'][] = $key;
            }
        }
        return $out;
    }

    /**
     * Publish: create, update and delete the plan's events.
     *
     * @return array counts: created, updated, deleted, unchanged
     */
    public function publish(): array {
        global $DB;
        $d = $this->diff();
        $plan = $this->plan;
        $tracked = $DB->get_records('local_termplanner_event', ['planid' => $plan->record->id], '', 'eventkey, id, eventid');
        foreach ($d['delete'] as $key) {
            if ($DB->record_exists('event', ['id' => $tracked[$key]->eventid])) {
                \calendar_event::load($tracked[$key]->eventid)->delete();
            }
            $DB->delete_records('local_termplanner_event', ['id' => $tracked[$key]->id]);
        }
        foreach ($d['update'] as $key) {
            $e = $d['wanted'][$key];
            $data = (object)['name' => $e->name, 'description' => $e->description, 'format' => FORMAT_HTML,
                'timestart' => $e->timestart, 'timeduration' => $e->timeduration, 'groupid' => $e->groupid,
                'eventtype' => $e->groupid ? 'group' : 'course'];
            \calendar_event::load($tracked[$key]->eventid)->update($data, false);
        }
        foreach ($d['create'] as $key) {
            $e = $d['wanted'][$key];
            $event = \calendar_event::create((object)[
                'name' => $e->name, 'description' => $e->description, 'format' => FORMAT_HTML,
                'courseid' => $plan->course->id, 'groupid' => $e->groupid, 'userid' => 0,
                'modulename' => '', 'instance' => 0, 'eventtype' => $e->groupid ? 'group' : 'course',
                'timestart' => $e->timestart, 'timeduration' => $e->timeduration, 'visible' => 1,
            ], false);
            if (isset($tracked[$key])) {
                $DB->set_field('local_termplanner_event', 'eventid', $event->id, ['id' => $tracked[$key]->id]);
            } else {
                $DB->insert_record(
                    'local_termplanner_event',
                    ['planid' => $plan->record->id, 'eventid' => $event->id, 'eventkey' => $key]
                );
            }
        }
        $DB->set_field('local_termplanner_plan', 'timepublished', time(), ['id' => $plan->record->id]);
        return ['created' => count($d['create']), 'updated' => count($d['update']), 'deleted' => count($d['delete']),
            'unchanged' => count($d['same'])];
    }
}
