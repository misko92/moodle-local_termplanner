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

namespace local_termplanner;

use local_termplanner\local\checks;
use local_termplanner\local\plan;
use local_termplanner\local\publisher;
use local_termplanner\local\schoolcal;

/**
 * Tests for plans, checks and publishing.
 *
 * @package    local_termplanner
 * @category   test
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_termplanner\local\plan
 * @covers     \local_termplanner\local\checks
 * @covers     \local_termplanner\local\publisher
 */
final class plan_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;

    /** @var int[] cmids by short name */
    protected array $cm = [];

    /**
     * A course with one topic: two CFAs, a lab, a test; and a plan on a 3-week term.
     *
     * @return plan
     */
    protected function make_plan(): plan {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 2]);
        $names = ['CFA Section 1.1: Units', 'CFA Section 1.2: Accuracy & Precision', 'Lab 1.1: Measuring', 'Test 01: Measurement'];
        foreach ($names as $name) {
            $q = $gen->create_module('quiz', ['course' => $this->course->id, 'section' => 1, 'name' => $name]);
            $this->cm[plan::short_name($name)] = (int)$q->cmid;
        }
        $termid = $DB->insert_record(
            'local_termplanner_term',
            ['name' => 'T', 'startday' => '2027-01-04', 'endday' => '2027-01-22']
        );
        $DB->insert_record(
            'local_termplanner_day',
            ['termid' => $termid, 'day' => '2027-01-18', 'type' => 'noschool', 'label' => 'MLK']
        );
        $planid = $DB->insert_record('local_termplanner_plan', ['courseid' => $this->course->id, 'termid' => $termid, 'name' => 'P',
            'eventprefix' => 'Chem T', 'eventday' => 'last', 'groupids' => '']);
        return plan::load($planid);
    }

    public function test_activities_and_kinds(): void {
        $plan = $this->make_plan();
        $acts = $plan->activities();
        $this->assertSame('lab', $acts[$this->cm['Lab 1.1']]->kind);
        $this->assertSame('cfa', $acts[$this->cm['CFA Section 1.2']]->kind);
        // Names are kept unescaped; output escapes them once.
        $this->assertSame('CFA Section 1.2: Accuracy & Precision', $acts[$this->cm['CFA Section 1.2']]->name);
        $this->assertSame('ws', plan::kind_of('Worksheets & Notes 01: Matter'));
    }

    public function test_default_deadlines(): void {
        $plan = $this->make_plan();
        // A lab on Fri Jan 8: the next week has 5 days; last day Wed, check-off Fri.
        $this->assertSame(['2027-01-13', '2027-01-15'], $plan->default_deadlines('2027-01-08'));
        // A second lab due that week moves a day later.
        $this->assertSame(['2027-01-14', '2027-01-15'], $plan->default_deadlines('2027-01-08', 1));
        // After MLK Day the week is Tue-Fri.
        $this->assertSame(['2027-01-20', '2027-01-22'], $plan->default_deadlines('2027-01-15'));
        // Nothing after the last week.
        $this->assertSame([null, null], $plan->default_deadlines('2027-01-22'));
        // Adding a lab fills in the usual dates.
        $item = $plan->add_item('2027-01-07', $this->cm['Lab 1.1']);
        $this->assertSame('2027-01-13', $item->closesday);
    }

    public function test_move_item(): void {
        $plan = $this->make_plan();
        $a = $plan->add_item('2027-01-04', $this->cm['CFA Section 1.1']);
        $b = $plan->add_item('2027-01-05', $this->cm['CFA Section 1.2']);
        // Moving b up crosses into Monday and puts it before a.
        $plan->move_item($b->id, -1);
        $list = $plan->week_items(1);
        $this->assertSame([$b->id, $a->id], array_map(fn($i) => (int)$i->id, $list));
        $this->assertSame('2027-01-04', $list[0]->day);
    }

    public function test_checks(): void {
        $plan = $this->make_plan();
        // The lab before its CFAs, and its check-off on MLK Day.
        $lab = $plan->add_item('2027-01-04', $this->cm['Lab 1.1']);
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.2']);
        $plan->update_item($lab->id, ['checkday' => '2027-01-18']);
        $result = checks::run($plan);
        $messages = implode("\n", array_column($result['problems'], 'message'));
        $this->assertStringContainsString('Lab 1.1 comes before CFA Section 1.1, CFA Section 1.2', $messages);
        $this->assertStringContainsString('not a school day', $messages);
        $this->assertArrayHasKey($lab->id, $result['items']);
        // Fixed: lab after the CFAs, check-off on a school day.
        $plan->update_item($lab->id, ['day' => '2027-01-06', 'checkday' => '2027-01-15']);
        $this->assertSame([], checks::run($plan)['problems']);
    }

    public function test_week_lines(): void {
        $plan = $this->make_plan();
        $plan->add_item('2027-01-04', 0, 'Read sections 1 and 2');
        $plan->add_item('2027-01-04', 0, 'Watch the videos', 1);
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.2']);
        $lab = $plan->add_item('2027-01-06', $this->cm['Lab 1.1']);
        $plan->add_item('2027-01-08', $this->cm['Test 01']);
        $lines = (new publisher($plan))->week_lines(1);
        $this->assertSame([
            [0, 'Read sections 1 and 2'], [1, 'Watch the videos'],
            [0, 'Do CFA’s'], [1, 'CFA Section 1.1: Units'], [1, 'CFA Section 1.2: Accuracy & Precision'],
            [0, 'Do Lab 1.1: Measuring'], [1, 'Check off Lab 1.1 with your teacher'],
            [0, 'Take Test 01: Measurement'],
        ], $lines);
        // The lab's deadlines show in week 2.
        $this->assertSame(
            [[0, '→ Lab deadlines'], [1, 'Lab 1.1: last day to do the lab Wed 1/13, check off by Fri 1/15']],
            (new publisher($plan))->week_lines(2)
        );
        $html = publisher::lines_to_html($lines);
        $this->assertStringContainsString('<li>Read sections 1 and 2<ul><li>Watch the videos</li></ul></li>', $html);
        $this->assertStringContainsString('Accuracy &amp; Precision', $html);
    }

    public function test_publish_updates_in_place(): void {
        global $DB;
        $plan = $this->make_plan();
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $lab = $plan->add_item('2027-01-07', $this->cm['Lab 1.1']);
        $counts = (new publisher($plan))->publish();
        // Week 1 event, the lab's two deadline events, and a week 2 event that lists the deadlines.
        $this->assertSame(['created' => 4, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'dates' => 0], $counts);
        $week = $DB->get_record_select(
            'event',
            'courseid = ? AND ' . $DB->sql_compare_text('name') . ' = ?',
            [$this->course->id, 'Chem T W1']
        );
        $this->assertSame('course', $week->eventtype);
        // Week 1 is Jan 4-8: the event sits on Friday.
        $this->assertSame('2027-01-08', \core_date::strftime('%Y-%m-%d', (int)$week->timestart, \core_date::get_server_timezone()));

        // Moving the lab to week 2 updates weeks 1 and 2 and both deadlines (now after MLK Day),
        // and adds a week 3 event for the deadlines.
        $plan->update_item($lab->id, ['day' => '2027-01-12']);
        $plan->reset_deadlines($lab->id);
        $counts = (new publisher($plan))->publish();
        $this->assertSame(1, $counts['created']);
        $this->assertSame(4, $counts['updated']);
        $this->assertSame($week->id, $DB->get_field('event', 'id', ['id' => $week->id]));

        // Removing the lab deletes the week 2 and 3 events and both deadlines; publishing again changes nothing.
        $plan->delete_item($lab->id);
        $counts = (new publisher($plan))->publish();
        $this->assertSame(4, $counts['deleted']);
        $this->assertSame(
            ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 1, 'dates' => 0],
            (new publisher($plan))->publish()
        );
        $this->assertSame(1, $DB->count_records('event', ['courseid' => $this->course->id]));
    }

    public function test_publish_to_groups(): void {
        global $DB;
        $plan = $this->make_plan();
        $g1 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $g2 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $DB->set_field('local_termplanner_plan', 'groupids', $g1->id . ',' . $g2->id, ['id' => $plan->record->id]);
        $plan = plan::load($plan->record->id);
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        (new publisher($plan))->publish();
        $events = $DB->get_records_select(
            'event',
            'courseid = ? AND ' . $DB->sql_compare_text('name') . ' = ?',
            [$this->course->id, 'Chem T W1']
        );
        $this->assertCount(2, $events);
        foreach ($events as $e) {
            $this->assertSame('group', $e->eventtype);
        }
        $this->assertEqualsCanonicalizing([$g1->id, $g2->id], array_column($events, 'groupid'));
    }

    public function test_place_item(): void {
        $plan = $this->make_plan();
        $a = $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $b = $plan->add_item('2027-01-05', $this->cm['CFA Section 1.2']);
        $c = $plan->add_item('2027-01-07', 0, 'Read');
        // Drop the text line before b on Tuesday.
        $plan->place_item($c->id, '2027-01-05', $b->id);
        $day = array_values(array_filter($plan->items, fn($i) => $i->day === '2027-01-05'));
        $this->assertSame([$a->id, $c->id, $b->id], array_map(fn($i) => (int)$i->id, $day));
        // Without "before" it goes to the end of the day.
        $plan->place_item($a->id, '2027-01-05');
        $day = array_values(array_filter($plan->items, fn($i) => $i->day === '2027-01-05'));
        $this->assertSame([$c->id, $b->id, $a->id], array_map(fn($i) => (int)$i->id, $day));
    }

    public function test_shift_from(): void {
        $plan = $this->make_plan();
        $a = $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $lab = $plan->add_item('2027-01-08', $this->cm['Lab 1.1']);
        // Snow day on Thursday Jan 7: everything from then on moves one school day later.
        $this->assertTrue($plan->shift_from('2027-01-07', 1));
        $this->assertSame('2027-01-05', $plan->items[$a->id]->day);
        $this->assertSame('2027-01-11', $plan->items[$lab->id]->day);
        // Deadlines move too: Wed 1/13 -> Thu 1/14, Fri 1/15 -> Tue 1/19 (Mon 1/18 is MLK Day).
        $this->assertSame('2027-01-14', $plan->items[$lab->id]->closesday);
        $this->assertSame('2027-01-19', $plan->items[$lab->id]->checkday);
        // Nothing moves when something would leave the term.
        $this->assertFalse($plan->shift_from('2027-01-04', 30));
        $this->assertSame('2027-01-05', $plan->items[$a->id]->day);
    }

    public function test_copy_from(): void {
        global $DB;
        $source = $this->make_plan();
        $source->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $source->add_item('2027-01-08', $this->cm['Lab 1.1']);
        $source->add_item('2027-01-22', 0, 'Review');
        // A second term two weeks long, with a holiday on the Monday of week 1.
        $termid = $DB->insert_record(
            'local_termplanner_term',
            ['name' => 'U', 'startday' => '2027-03-08', 'endday' => '2027-03-19']
        );
        $DB->insert_record(
            'local_termplanner_day',
            ['termid' => $termid, 'day' => '2027-03-08', 'type' => 'noschool', 'label' => 'X']
        );
        $planid = $DB->insert_record('local_termplanner_plan', ['courseid' => $this->course->id, 'termid' => $termid,
            'name' => 'Q', 'eventprefix' => 'Q', 'eventday' => 'last', 'groupids' => '']);
        $target = plan::load($planid);
        $short = $target->copy_from($source);
        $days = array_values(array_map(fn($i) => $i->day, $target->items));
        sort($days);
        // Tue (2nd school day of week 1) -> Wed 3/10 (2nd school day there); Fri -> Fri 3/12 (last day).
        // Week 3 does not exist here, so its line goes to week 2: Fri 1/22 was the 4th school day of an
        // MLK week, so it lands on the 4th school day, Thu 3/18.
        $this->assertSame(['2027-03-10', '2027-03-12', '2027-03-18'], $days);
        $this->assertSame([1 => [5, 4], 3 => [4, 0]], $short);
        // The lab got the usual deadlines in week 2.
        $lab = array_values(array_filter($target->items, fn($i) => (int)$i->cmid === $this->cm['Lab 1.1']))[0];
        $this->assertSame(['2027-03-17', '2027-03-19'], [$lab->closesday, $lab->checkday]);
    }

    public function test_external_update(): void {
        $plan = $this->make_plan();
        $this->setAdminUser();
        $item = $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $result = \local_termplanner\external\update::execute($plan->record->id, [
            ['op' => 'add', 'day' => '2027-01-06', 'text' => 'Read section 2', 'level' => 1],
            ['op' => 'move', 'itemid' => $item->id, 'day' => '2027-01-07'],
            ['op' => 'move', 'itemid' => $item->id, 'day' => '2031-01-01'],
        ]);
        $state = json_decode($result['state'], true);
        $this->assertSame('', $result['error']);
        $this->assertCount(2, $state['items']);
        $moved = array_values(array_filter($state['items'], fn($i) => $i['id'] === (int)$item->id))[0];
        // The move outside the term was ignored.
        $this->assertSame('2027-01-07', $moved['day']);
        $this->assertSame(3, count(array_filter($state['weeks'], fn($w) => $w['number'] !== null)));
        $this->assertStringContainsString('Read section 2', $state['previews'][1]);
        // Someone without the capability is refused.
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        \local_termplanner\external\get_state::execute($plan->record->id);
    }

    public function test_days_layout_without_lab_events(): void {
        global $DB;
        $plan = $this->make_plan();
        $DB->update_record('local_termplanner_plan', ['id' => $plan->record->id, 'weekstyle' => 'days', 'labevents' => 0]);
        $plan = plan::load($plan->record->id);
        $plan->add_item('2027-01-19', 0, 'Start Lecture 18');
        $lab = $plan->add_item('2027-01-20', $this->cm['Lab 1.1']);
        // Without lab events a lab gets no deadlines.
        $this->assertNull($lab->closesday);
        $this->assertSame([
            [0, 'Monday'], [1, 'No school – MLK'],
            [0, 'Tuesday'], [1, 'Start Lecture 18'],
            [0, 'Wednesday'], [1, 'Lab 1.1: Measuring'],
        ], (new publisher($plan))->week_lines(3));
        // No lab events either.
        $this->assertSame([], array_filter(
            (new publisher($plan))->wanted(),
            fn($k) => !str_starts_with($k, 'week:'),
            ARRAY_FILTER_USE_KEY
        ));
    }

    public function test_publish_sets_activity_dates(): void {
        global $DB;
        $plan = $this->make_plan();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id,
            'name' => 'Practice Problems Lecture 18']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'name' => 'Topic 05 Test']);
        $plan = plan::load($plan->record->id);
        $a = $plan->add_item('2027-01-05', (int)$assign->cmid);
        $q = $plan->add_item('2027-01-07', (int)$quiz->cmid);
        $plan->update_item($a->id, ['dueday' => '2027-01-10']);
        $plan->update_item($q->id, ['opentime' => '09:00', 'closetime' => '10:45']);
        $pub = new publisher($plan);
        $this->assertCount(2, $pub->diff()['dates']);
        $this->assertSame(2, $pub->publish()['dates']);
        $tz = \core_date::get_server_timezone();
        $rec = $DB->get_record('assign', ['id' => $assign->id]);
        $this->assertSame('2027-01-10 23:59', \core_date::strftime('%Y-%m-%d %H:%M', (int)$rec->duedate, $tz));
        // Cut-off two days later by default.
        $this->assertSame('2027-01-12 23:59', \core_date::strftime('%Y-%m-%d %H:%M', (int)$rec->cutoffdate, $tz));
        // The assignment's own "is due" event follows.
        $this->assertSame((int)$rec->duedate, (int)$DB->get_field(
            'event',
            'timestart',
            ['modulename' => 'assign', 'instance' => $assign->id, 'eventtype' => 'due']
        ));
        $rec = $DB->get_record('quiz', ['id' => $quiz->id]);
        $this->assertSame('2027-01-07 09:00', \core_date::strftime('%Y-%m-%d %H:%M', (int)$rec->timeopen, $tz));
        $this->assertSame('2027-01-07 10:45', \core_date::strftime('%Y-%m-%d %H:%M', (int)$rec->timeclose, $tz));
        // Nothing left to do.
        $this->assertSame([], (new publisher($plan))->diff()['dates']);
        // A snow day moves the due day too.
        $plan->shift_from('2027-01-05', 1);
        $this->assertSame('2027-01-11', $plan->items[$a->id]->dueday);
    }

    public function test_lab_rules_can_be_switched_off(): void {
        global $DB;
        $plan = $this->make_plan();
        // A lab before its CFAs breaks the lab rules...
        $plan->add_item('2027-01-04', $this->cm['Lab 1.1']);
        $plan->add_item('2027-01-05', $this->cm['CFA Section 1.1']);
        $this->assertNotEmpty(checks::run($plan)['problems']);
        // ...which a plan without them does not check.
        $DB->set_field('local_termplanner_plan', 'labrules', 0, ['id' => $plan->record->id]);
        $this->assertSame([], checks::run(plan::load($plan->record->id))['problems']);
    }
}
