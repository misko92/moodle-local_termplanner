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

    /** @var string|null language for student-facing text (null = the current language) */
    protected ?string $lang = null;

    /** Spanish variants filter_multilang should match (es_mx has no parent language in Moodle). */
    const SPANISH = ['es_mx', 'es'];

    /**
     * A copy of this publisher that writes student-facing text in another language.
     *
     * @param string|null $lang language code, or null for the current language
     * @return static
     */
    public function in_language(?string $lang): static {
        $copy = clone $this;
        $copy->lang = $lang;
        return $copy;
    }

    /**
     * A plugin string in this publisher's language.
     *
     * @param string $id
     * @param mixed $a
     * @return string
     */
    protected function str(string $id, $a = null): string {
        return get_string_manager()->get_string($id, 'local_termplanner', $a, $this->lang);
    }

    /**
     * An activity's name in this publisher's language.
     *
     * @param \stdClass $act from plan::activities()
     * @return string
     */
    protected function actname(\stdClass $act): string {
        return $this->lang === null ? $act->name : plan::name_in($act->raw, $this->lang);
    }

    /**
     * An activity's short name ("Lab 1.1" / "Laboratorio 1.1") in this publisher's language.
     *
     * @param \stdClass $act
     * @return string
     */
    protected function actshort(\stdClass $act): string {
        return $this->lang === null ? $act->short : plan::short_name($this->actname($act));
    }

    /**
     * The lines students see for one week, as [level, text] pairs.
     *
     * In the "list" layout the week's items follow each other; in the "days" layout each school day gets a heading
     * (and days without school say so). Lab deadlines that fall in the week come last, when the plan uses them.
     *
     * @param int $week week number
     * @return array[]
     */
    public function week_lines(int $week): array {
        $items = $this->plan->week_items($week);
        $lines = [];
        if ($this->plan->record->weekstyle === 'days') {
            $weekinfo = null;
            foreach ($this->plan->cal->weeks() as $w) {
                if ($w['number'] === $week) {
                    $weekinfo = $w;
                }
            }
            $names = ['dayname_mon', 'dayname_tue', 'dayname_wed', 'dayname_thu', 'dayname_fri'];
            foreach ($weekinfo['days'] ?? [] as $i => $d) {
                if (!$d['inside']) {
                    continue;
                }
                $dayname = $this->str($names[$i]);
                $dayitems = array_values(array_filter($items, fn($it) => $it->day === $d['day']));
                if (!$d['school']) {
                    $lines[] = [0, $dayname];
                    $label = $d['label'] ?: $this->str('type_' . $d['type']);
                    $lines[] = [1, $d['type'] === 'noschool' ? $this->str('line_noschool', $label) : $label];
                    continue;
                }
                if ($dayitems) {
                    $lines[] = [0, $dayname];
                    $lines = array_merge($lines, $this->item_lines($dayitems, 1));
                }
            }
        } else {
            $lines = $this->item_lines($items, 0);
        }
        return array_merge($lines, $this->deadline_lines($week));
    }

    /**
     * Lines for a run of items.
     *
     * @param \stdClass[] $items
     * @param int $offset added to every item's indent level
     * @return array[]
     */
    protected function item_lines(array $items, int $offset): array {
        $acts = $this->plan->activities();
        $labevents = !empty($this->plan->record->labevents);
        $lines = [];
        $cfas = [];
        $flushcfas = function () use (&$cfas, &$lines) {
            if ($cfas) {
                $lines[] = [$cfas[0][0], $this->str(count($cfas) > 1 ? 'line_docfas' : 'line_docfa')];
                foreach ($cfas as [$lvl, $name]) {
                    $lines[] = [$lvl + 1, $name];
                }
                $cfas = [];
            }
        };
        foreach ($items as $it) {
            $lvl = (int)$it->level + $offset;
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            if ($it->cmid && !$act) {
                continue;
            }
            if ($act && $act->kind === 'cfa') {
                if ($cfas && $cfas[0][0] !== $lvl) {
                    $flushcfas();
                }
                $cfas[] = [$lvl, $this->actname($act)];
                continue;
            }
            $flushcfas();
            if (!$act) {
                $lines[] = [$lvl, (string)$it->text];
            } else if ($act->kind === 'lab' && $labevents) {
                $lines[] = [$lvl, $this->str('line_dolab', $this->actname($act))];
                $lines[] = [$lvl + 1, $this->str('line_checkofflab', $this->actshort($act))];
            } else if ($act->kind === 'test') {
                $lines[] = [$lvl, $this->str('line_take', $this->actname($act))];
            } else if ($act->kind === 'ws') {
                $lines[] = [$lvl, $this->str('line_checkoffws', $this->actname($act))];
            } else {
                // Anything else (slides, videos, practice problems, a lab without deadline events) is listed by name.
                $lines[] = [$lvl, $this->actname($act)];
            }
        }
        $flushcfas();
        return $lines;
    }

    /**
     * The "Lab deadlines" lines for labs due in a week, when the plan uses lab deadline events.
     *
     * @param int $week
     * @return array[]
     */
    protected function deadline_lines(int $week): array {
        if (empty($this->plan->record->labevents)) {
            return [];
        }
        $acts = $this->plan->activities();
        $cal = $this->plan->cal;
        $due = [];
        foreach ($this->plan->items as $it) {
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            if (!$act || $act->kind !== 'lab') {
                continue;
            }
            $closesnow = $it->closesday && $cal->week_of($it->closesday) === $week;
            $checknow = $it->checkday && $cal->week_of($it->checkday) === $week;
            if ($closesnow || $checknow) {
                $a = (object)['lab' => $this->actshort($act),
                    'closes' => $it->closesday ? schoolcal::short($it->closesday, $this->lang) : '',
                    'check' => $it->checkday ? schoolcal::short($it->checkday, $this->lang) : ''];
                $due[($it->checkday ?? $it->closesday) . $act->order] = $this->str(
                    $it->closesday ? 'line_deadline' : 'line_deadlinecheck',
                    $a
                );
            }
        }
        if (!$due) {
            return [];
        }
        ksort($due);
        $lines = [[0, $this->str('line_labdeadlines')]];
        foreach ($due as $text) {
            $lines[] = [1, $text];
        }
        return $lines;
    }

    /**
     * Turn [level, text] lines into nested HTML lists.
     *
     * @param array[] $lines
     * @param array[]|null $spanish the same lines in Spanish: each line then shows in the reader's language
     * @return string
     */
    public static function lines_to_html(array $lines, ?array $spanish = null): string {
        if (!$lines) {
            return '';
        }
        // Spanish lines are used only when they line up with the English ones (same count and levels).
        $bilingual = $spanish !== null && count($spanish) === count($lines)
            && array_column($spanish, 0) === array_column($lines, 0) && $spanish !== $lines;
        $html = '';
        $depth = -1;
        foreach ($lines as $i => [$lvl, $text]) {
            $lvl = min($lvl, $depth + 1);
            if ($lvl > $depth) {
                $html .= str_repeat('<ul>', $lvl - $depth);
            } else {
                $html .= '</li>' . str_repeat('</ul></li>', $depth - $lvl);
            }
            $html .= '<li>' . ($bilingual ? self::multilang($text, $spanish[$i][1]) : s($text));
            $depth = $lvl;
        }
        return $html . '</li>' . str_repeat('</ul></li>', $depth) . '</ul>';
    }

    /**
     * English and Spanish text as filter_multilang spans (plain text, escaped); the same text once when equal.
     *
     * @param string $en
     * @param string $es
     * @return string HTML
     */
    public static function multilang(string $en, string $es): string {
        if ($en === $es) {
            return s($en);
        }
        $out = '<span lang="en" class="multilang">' . s($en) . '</span>';
        foreach (self::SPANISH as $code) {
            $out .= '<span lang="' . $code . '" class="multilang">' . s($es) . '</span>';
        }
        return $out;
    }

    /**
     * A timestamp for a day and time in the site's timezone.
     *
     * @param string $day YYYY-MM-DD
     * @param int $hour
     * @param int $minute
     * @return int
     */
    public static function time(string $day, int $hour, int $minute): int {
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
            $lines = $w['number'] === null ? [] : $this->in_language('en')->week_lines($w['number']);
            if (!$lines) {
                continue;
            }
            $spanish = $this->in_language('es_mx')->week_lines($w['number']);
            // Finals days count here: the week's event may sit on the last day of finals.
            $days = array_column(array_filter($w['days'], fn($d) => $d['school'] || $d['type'] === 'finals'), 'day');
            $first = reset($days);
            $last = end($days);
            $e = (object)['name' => trim($plan->record->eventprefix) . ' W' . $w['number'],
                'description' => self::lines_to_html($lines, $spanish), 'timeduration' => 0];
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
            if (!$act || $act->kind !== 'lab' || empty($plan->record->labevents)) {
                continue;
            }
            if ($it->closesday) {
                $en = $this->in_language('en');
                $es = $this->in_language('es_mx');
                $base['closes:' . $it->id] = (object)[
                    'name' => self::multilang(
                        $en->str('event_closes', $en->actshort($act)),
                        $es->str('event_closes', $es->actshort($act))
                    ),
                    'description' => '<p>' . self::multilang($en->str('event_closes_desc'), $es->str('event_closes_desc')) . '</p>',
                    'timestart' => self::time($it->closesday, 23, 59), 'timeduration' => 0];
            }
            if ($it->checkday) {
                $en = $this->in_language('en');
                $es = $this->in_language('es_mx');
                $base['check:' . $it->id] = (object)[
                    'name' => self::multilang(
                        $en->str('event_check', $en->actshort($act)),
                        $es->str('event_check', $es->actshort($act))
                    ),
                    'description' => '<p>' . self::multilang($en->str('event_check_desc'), $es->str('event_check_desc')) . '</p>',
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
     * Activity dates the plan sets: assignment due and cut-off dates, quiz open and close times.
     *
     * Only activities with a due day (assignments) or a time (quizzes) in the plan are touched.
     *
     * @return array[] one per activity that would change: cmid, modname, instance, name, fields => [field => value]
     */
    public function activity_dates(): array {
        global $DB;
        $plan = $this->plan;
        $acts = $plan->activities();
        $changes = [];
        foreach ($plan->items as $it) {
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            if (!$act) {
                continue;
            }
            $want = [];
            if ($act->modname === 'assign' && $it->dueday) {
                $want['duedate'] = self::time($it->dueday, 23, 59);
                $cutoff = (int)$plan->record->cutoffdays;
                $want['cutoffdate'] = $cutoff > 0 ? self::time(schoolcal::add_days($it->dueday, $cutoff), 23, 59) : 0;
            } else if ($act->modname === 'quiz' && ($it->opentime || $it->closetime)) {
                foreach (['timeopen' => $it->opentime, 'timeclose' => $it->closetime] as $field => $hm) {
                    if ($hm && preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
                        $want[$field] = self::time($it->day, (int)$m[1], (int)$m[2]);
                    }
                }
            }
            if (!$want) {
                continue;
            }
            $current = $DB->get_record($act->modname, ['id' => $act->instance], 'id, ' . implode(', ', array_keys($want)));
            if (!$current) {
                continue;
            }
            $fields = array_filter($want, fn($v, $k) => (int)$current->$k !== $v, ARRAY_FILTER_USE_BOTH);
            if ($fields) {
                $changes[] = ['cmid' => $act->cmid, 'modname' => $act->modname, 'instance' => $act->instance,
                    'name' => $act->name, 'fields' => $fields];
            }
        }
        return $changes;
    }

    /**
     * Write activity dates, and let each activity update its own calendar events.
     *
     * @param array[] $changes from activity_dates()
     */
    protected function apply_activity_dates(array $changes): void {
        global $CFG, $DB;
        if (!$changes) {
            return;
        }
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $course = $this->plan->course;
        foreach ($changes as $c) {
            $DB->update_record($c['modname'], (object)(['id' => $c['instance'], 'timemodified' => time()] + $c['fields']));
            if ($c['modname'] === 'assign') {
                $cm = get_coursemodule_from_id('assign', $c['cmid'], $course->id, false, MUST_EXIST);
                $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
                $assign->update_calendar($cm->id);
            } else {
                quiz_update_events($DB->get_record('quiz', ['id' => $c['instance']], '*', MUST_EXIST));
            }
        }
        rebuild_course_cache($course->id, true);
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
        $out = ['create' => [], 'update' => [], 'delete' => [], 'same' => [], 'wanted' => $wanted,
            'dates' => $this->activity_dates()];
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
     * @return array counts: created, updated, deleted, unchanged, dates (activities whose dates were set)
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
        $this->apply_activity_dates($d['dates']);
        $DB->set_field('local_termplanner_plan', 'timepublished', time(), ['id' => $plan->record->id]);
        return ['created' => count($d['create']), 'updated' => count($d['update']), 'deleted' => count($d['delete']),
            'unchanged' => count($d['same']), 'dates' => count($d['dates'])];
    }
}
