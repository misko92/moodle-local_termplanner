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
 * A course's plan for one term.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\local;

use stdClass;

/**
 * Loads and changes a plan and its items.
 */
class plan {
    /** @var stdClass Plan record. */
    public stdClass $record;

    /** @var stdClass Course record. */
    public stdClass $course;

    /** @var schoolcal The plan's term. */
    public schoolcal $cal;

    /** @var stdClass[] Items keyed by id, sorted by day then sortorder. */
    public array $items = [];

    /** @var stdClass[]|null Course activities keyed by cmid, see activities(). */
    protected ?array $activities = null;

    /**
     * Constructor.
     *
     * @param stdClass $record plan record
     */
    public function __construct(stdClass $record) {
        global $DB;
        $this->record = $record;
        $this->course = get_course($record->courseid);
        $this->cal = schoolcal::load($record->termid);
        $this->reload();
    }

    /**
     * Load a plan.
     *
     * @param int $id
     * @return self
     */
    public static function load(int $id): self {
        global $DB;
        return new self($DB->get_record('local_termplanner_plan', ['id' => $id], '*', MUST_EXIST));
    }

    /**
     * Reload the items from the database.
     */
    public function reload(): void {
        global $DB;
        $this->items = $DB->get_records('local_termplanner_item', ['planid' => $this->record->id], 'day, sortorder, id');
    }

    /**
     * Group ids the plan publishes to; empty means the whole course.
     *
     * @return int[]
     */
    public function group_ids(): array {
        return array_values(array_filter(array_map('intval', explode(',', (string)$this->record->groupids))));
    }

    /**
     * What kind of activity a name is: cfa, lab, test, ws (worksheets & notes check-off) or other.
     *
     * @param string $name activity name
     * @return string
     */
    public static function kind_of(string $name): string {
        $name = trim(strip_tags($name));
        return match (true) {
            (bool)preg_match('/^CFA\b/i', $name) => 'cfa',
            (bool)preg_match('/^Lab\s+\d/i', $name) => 'lab',
            (bool)preg_match('/^Test\b/i', $name) => 'test',
            (bool)preg_match('/^Worksheets\s*&(amp;)?\s*Notes\b/i', $name) => 'ws',
            default => 'other',
        };
    }

    /**
     * Short name of an activity: the part before the first colon ("Lab 4.1", "CFA Section 4.3a").
     *
     * @param string $name activity name
     * @return string
     */
    public static function short_name(string $name): string {
        $pos = strpos($name, ':');
        return trim($pos === false ? $name : substr($name, 0, $pos));
    }

    /**
     * The course's activities in course order.
     *
     * Each entry: cmid, name, kind, short, section (number), sectionname, order (position in the course).
     *
     * @return stdClass[] keyed by cmid
     */
    public function activities(): array {
        if ($this->activities !== null) {
            return $this->activities;
        }
        $modinfo = get_fast_modinfo($this->course);
        $list = [];
        $order = 0;
        $add = function (\cm_info $cm, \section_info $topic) use (&$list, &$order): void {
            $name = format_string($cm->name, true, ['context' => $cm->context, 'escape' => false]);
            $list[$cm->id] = (object)[
                'cmid' => (int)$cm->id,
                'modname' => $cm->modname,
                'instance' => (int)$cm->instance,
                'name' => $name,
                'kind' => self::kind_of($name),
                'short' => self::short_name($name),
                'section' => (int)$topic->section,
                'sectionname' => get_section_name($this->course, $topic),
                'order' => $order++,
            ];
        };
        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->component) {
                continue; // Subsections are taken in place, below.
            }
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->cms[$cmid];
                if ($cm->deletioninprogress) {
                    continue;
                }
                if ($cm->modname === 'subsection') {
                    // Activities inside a subsection count at the subsection's place, under the parent topic.
                    $sub = $cm->get_delegated_section_info();
                    foreach ($sub ? ($modinfo->sections[$sub->section] ?? []) : [] as $subcmid) {
                        $subcm = $modinfo->cms[$subcmid];
                        if (!$subcm->deletioninprogress && $subcm->has_view()) {
                            $add($subcm, $section);
                        }
                    }
                    continue;
                }
                if ($cm->has_view()) {
                    $add($cm, $section);
                }
            }
        }
        return $this->activities = $list;
    }

    /**
     * Items of one numbered week, in order.
     *
     * @param int $number week number
     * @return stdClass[]
     */
    public function week_items(int $number): array {
        return array_values(array_filter($this->items, fn($it) => $this->cal->week_of($it->day) === $number));
    }

    /**
     * The usual lab deadlines for a lab done on a given day.
     *
     * Last day to do the lab: the middle school day of the following school week (the earlier one of two), one day later for each
     * other lab already closing that week. Check-off: two school days after that, or the week's last school day.
     *
     * @param string $labday day the lab is done, YYYY-MM-DD
     * @param int $otherlabs number of other labs already due that week
     * @return array [closes or null, check-off or null]
     */
    public function default_deadlines(string $labday, int $otherlabs = 0): array {
        $week = $this->cal->week_of($labday);
        if ($week === null) {
            return [null, null];
        }
        foreach ($this->cal->weeks() as $w) {
            if ($w['number'] !== null && $w['number'] > $week) {
                $days = $w['schooldays'];
                $n = count($days);
                $ci = min(max(0, $n - 2), intdiv($n - 1, 2) + $otherlabs);
                return [$days[$ci], $days[min($n - 1, $ci + 2)]];
            }
        }
        return [null, null];
    }

    /**
     * How many labs other than one already have deadlines in the week after a given day.
     *
     * @param string $labday
     * @param int $exceptid item to leave out
     * @return int
     */
    public function labs_due_week_after(string $labday, int $exceptid = 0): int {
        $week = $this->cal->week_of($labday);
        $count = 0;
        foreach ($this->items as $it) {
            if ($it->id != $exceptid && $it->checkday && $week !== null && $this->cal->week_of($it->checkday) === $week + 1) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Add an item at the end of a day.
     *
     * @param string $day YYYY-MM-DD
     * @param int $cmid course module, or 0 for a text line
     * @param string $text the text line
     * @param int $level indent level
     * @return stdClass the new item
     */
    public function add_item(string $day, int $cmid, string $text = '', int $level = 0): stdClass {
        global $DB;
        $max = 0;
        foreach ($this->items as $it) {
            if ($it->day === $day) {
                $max = max($max, $it->sortorder + 1);
            }
        }
        $item = (object)[
            'planid' => $this->record->id, 'day' => $day, 'sortorder' => $max, 'cmid' => $cmid,
            'text' => $cmid ? null : $text, 'level' => max(0, min(3, $level)), 'closesday' => null, 'checkday' => null,
            'dueday' => null, 'opentime' => null, 'closetime' => null,
        ];
        if ($cmid && $this->record->labevents && ($this->activities()[$cmid]->kind ?? '') === 'lab') {
            [$item->closesday, $item->checkday] = $this->default_deadlines($day, $this->labs_due_week_after($day));
        }
        $item->id = $DB->insert_record('local_termplanner_item', $item);
        $this->touch();
        $this->reload();
        return $item;
    }

    /**
     * Move an item one place up or down in its week (it may cross into the neighbouring day).
     *
     * @param int $itemid
     * @param int $dir -1 for up, 1 for down
     */
    public function move_item(int $itemid, int $dir): void {
        global $DB;
        $item = $this->items[$itemid] ?? null;
        if (!$item) {
            return;
        }
        $week = $this->cal->week_of($item->day);
        $list = $week === null ? [] : $this->week_items($week);
        $pos = array_search($itemid, array_map(fn($i) => (int)$i->id, $list));
        $other = $list[$pos + $dir] ?? null;
        if ($pos === false || !$other) {
            return;
        }
        // Swap places: the item takes the neighbour's day, then renumber both days in list order.
        $list[$pos] = $other;
        $list[$pos + $dir] = $item;
        $item->day = $other->day;
        $this->renumber($list);
    }

    /**
     * Save sortorder (and day) for a list of items, numbering each day from 0 in list order.
     *
     * @param stdClass[] $list
     */
    protected function renumber(array $list): void {
        global $DB;
        $next = [];
        foreach ($list as $it) {
            $it->sortorder = $next[$it->day] = ($next[$it->day] ?? -1) + 1;
            $DB->update_record(
                'local_termplanner_item',
                (object)['id' => $it->id, 'day' => $it->day, 'sortorder' => $it->sortorder]
            );
        }
        $this->touch();
        $this->reload();
    }

    /**
     * Change an item's fields.
     *
     * @param int $itemid
     * @param array $fields day, level, text, closesday, checkday, dueday, opentime, closetime
     */
    public function update_item(int $itemid, array $fields): void {
        global $DB;
        $item = $this->items[$itemid] ?? null;
        if (!$item) {
            return;
        }
        $update = ['id' => $itemid];
        foreach (['day', 'level', 'text', 'closesday', 'checkday', 'dueday', 'opentime', 'closetime'] as $f) {
            if (array_key_exists($f, $fields) && $fields[$f] != $item->$f) {
                $update[$f] = $fields[$f];
            }
        }
        if (isset($update['day'])) {
            // A moved item goes to the end of its new day.
            $update['sortorder'] = 1 + max([-1] + array_map(
                fn($i) => (int)$i->sortorder,
                array_filter($this->items, fn($i) => $i->day === $update['day'])
            ));
        }
        if (count($update) > 1) {
            $DB->update_record('local_termplanner_item', (object)$update);
            $this->touch();
            $this->reload();
        }
    }

    /**
     * Put an item on a day, before another item of that day (or at the end).
     *
     * @param int $itemid
     * @param string $day YYYY-MM-DD
     * @param int $beforeid item to go before, 0 for the end of the day
     */
    public function place_item(int $itemid, string $day, int $beforeid = 0): void {
        $item = $this->items[$itemid] ?? null;
        if (!$item || $beforeid === $itemid) {
            return;
        }
        $list = array_values(array_filter($this->items, fn($i) => $i->day === $day && (int)$i->id !== $itemid));
        $pos = count($list);
        foreach ($list as $k => $i) {
            if ((int)$i->id === $beforeid) {
                $pos = $k;
                break;
            }
        }
        $item->day = $day;
        array_splice($list, $pos, 0, [$item]);
        $this->renumber($list);
    }

    /**
     * Move everything planned on or after a day by a number of school days, deadlines and due days included
     * (a snow day).
     *
     * @param string $fromday YYYY-MM-DD
     * @param int $n school days, negative for earlier
     * @return bool false when something would leave the term (nothing is changed then)
     */
    public function shift_from(string $fromday, int $n): bool {
        global $DB;
        $updates = [];
        foreach ($this->items as $it) {
            $u = ['id' => $it->id];
            foreach (['day', 'closesday', 'checkday', 'dueday'] as $f) {
                if ($it->$f && $it->$f >= $fromday) {
                    $new = $this->cal->shift($it->$f, $n);
                    if ($new === null) {
                        return false;
                    }
                    $u[$f] = $new;
                }
            }
            if (count($u) > 1) {
                $updates[] = (object)$u;
            }
        }
        foreach ($updates as $u) {
            $DB->update_record('local_termplanner_item', $u);
        }
        $this->touch();
        $this->reload();
        // Keep each day's order intact after items from different days landed together.
        foreach (array_unique(array_map(fn($i) => $i->day, $this->items)) as $day) {
            $this->renumber(array_values(array_filter($this->items, fn($i) => $i->day === $day)));
        }
        return true;
    }

    /**
     * Copy another plan's items into this plan, week n to week n.
     *
     * Each item keeps its place in the week: the k-th school day of a week maps to the k-th school day of the same
     * week here (or the week's last day if this week is shorter). Weeks beyond this term's last week go to the last
     * week. Lab deadlines are reset to the usual pattern; due days keep their distance in school days.
     *
     * @param plan $source
     * @return array weeks that came out shorter: [week => [source days, target days]]
     */
    public function copy_from(plan $source): array {
        global $DB;
        $lastweek = 0;
        foreach ($this->cal->weeks() as $w) {
            $lastweek = $w['number'] ?? $lastweek;
        }
        $short = [];
        $sort = [];
        $labs = [];
        foreach ($source->items as $it) {
            $week = $source->cal->week_of($it->day);
            if ($week === null) {
                continue;
            }
            $srcdays = $source->cal->school_days($week);
            $target = min($week, $lastweek);
            $days = $this->cal->school_days($target);
            if (!$days) {
                continue;
            }
            if (count($days) < count($srcdays) || $target !== $week) {
                $short[$week] = [count($srcdays), $target !== $week ? 0 : count($days)];
            }
            $idx = max(0, (int)array_search($it->day, $srcdays, true));
            $day = $days[min($idx, count($days) - 1)];
            $sort[$day] = ($sort[$day] ?? -1) + 1;
            // A due day stays the same number of school days after the item.
            $due = null;
            if ($it->dueday) {
                $all = $source->cal->all_school_days();
                $gap = count(array_filter($all, fn($d) => $d > $it->day && $d <= $it->dueday));
                $due = $this->cal->shift($day, max(0, $gap)) ?? $this->cal->term->endday;
            }
            $id = $DB->insert_record('local_termplanner_item', ['planid' => $this->record->id, 'day' => $day,
                'sortorder' => $sort[$day], 'cmid' => $it->cmid, 'text' => $it->text, 'level' => $it->level,
                'closesday' => null, 'checkday' => null, 'dueday' => $due, 'opentime' => $it->opentime,
                'closetime' => $it->closetime]);
            if ($it->cmid && $this->record->labevents && (($this->activities()[$it->cmid]->kind ?? '') === 'lab')) {
                $labs[$id] = [$day, $it->closesday === null && $it->checkday !== null];
            }
        }
        $this->reload();
        foreach ($labs as $id => [$day, $checkonly]) {
            [$c, $k] = $this->default_deadlines($day, $this->labs_due_week_after($day, $id));
            $DB->update_record('local_termplanner_item', ['id' => $id, 'closesday' => $checkonly ? null : $c, 'checkday' => $k]);
            $this->reload();
        }
        $this->touch();
        ksort($short);
        return $short;
    }

    /**
     * Reset a lab's deadlines to the usual pattern.
     *
     * @param int $itemid
     */
    public function reset_deadlines(int $itemid): void {
        $item = $this->items[$itemid] ?? null;
        if (!$item || !$item->cmid) {
            return;
        }
        $closes = $item->closesday;
        [$c, $k] = $this->default_deadlines($item->day, $this->labs_due_week_after($item->day, $itemid));
        // A lab that only had a check-off date keeps having none for the last day.
        $this->update_item($itemid, ['closesday' => ($closes === null && $item->checkday) ? null : $c, 'checkday' => $k]);
    }

    /**
     * Delete an item.
     *
     * @param int $itemid
     */
    public function delete_item(int $itemid): void {
        global $DB;
        $DB->delete_records('local_termplanner_item', ['id' => $itemid, 'planid' => $this->record->id]);
        $this->touch();
        $this->reload();
    }

    /**
     * Mark the plan as changed.
     */
    public function touch(): void {
        global $DB;
        $this->record->timemodified = time();
        $DB->set_field('local_termplanner_plan', 'timemodified', $this->record->timemodified, ['id' => $this->record->id]);
    }

    /**
     * Delete a plan, its items and, optionally, the calendar events it published.
     *
     * @param int $planid
     * @param bool $withevents also delete the published events
     */
    public static function delete(int $planid, bool $withevents): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');
        if ($withevents) {
            foreach ($DB->get_records('local_termplanner_event', ['planid' => $planid]) as $e) {
                if ($DB->record_exists('event', ['id' => $e->eventid])) {
                    \calendar_event::load($e->eventid)->delete();
                }
            }
        }
        $DB->delete_records('local_termplanner_event', ['planid' => $planid]);
        $DB->delete_records('local_termplanner_item', ['planid' => $planid]);
        $DB->delete_records('local_termplanner_plan', ['id' => $planid]);
    }
}
