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
        $this->assertSame(['created' => 4, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0], $counts);
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
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 1], (new publisher($plan))->publish());
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
}
