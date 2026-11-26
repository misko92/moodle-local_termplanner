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

use local_termplanner\local\schoolcal;

/**
 * Tests for the school calendar.
 *
 * @package    local_termplanner
 * @category   test
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_termplanner\local\schoolcal
 */
final class schoolcal_test extends \advanced_testcase {
    /**
     * Tri 2 2026-27 as in the school's calendar sheet.
     *
     * @return schoolcal
     */
    protected function tri2(): schoolcal {
        [$days] = schoolcal::parse_special("2026-11-30 | noschool | Staff day\n2026-12-23..2027-01-01 | noschool | Winter break\n"
            . "2027-01-18 | noschool | MLK Day\n2027-02-11 | early | Early release\n2027-03-03..2027-03-04 | finals | Finals\n"
            . "2027-03-05 | noschool | Staff day");
        return new schoolcal((object)['startday' => '2026-11-30', 'endday' => '2027-03-05'], $days);
    }

    public function test_parse_special(): void {
        [$days, $errors] = schoolcal::parse_special("2026-12-23..2027-01-01 | noschool | Winter break\n# comment\nnonsense line");
        $this->assertCount(1, $errors);
        // Dec 23 (Wed) to Jan 1 (Fri): 8 weekdays, weekend days skipped.
        $this->assertCount(8, $days);
        $this->assertArrayNotHasKey('2026-12-26', $days);
        $this->assertSame('Winter break', $days['2027-01-01']->label);
        // Formatting joins the run back into one line.
        $this->assertSame('2026-12-23..2027-01-01 | noschool | Winter break', schoolcal::format_special($days));
    }

    public function test_weeks(): void {
        $cal = $this->tri2();
        $weeks = $cal->weeks();
        // 13 numbered weeks plus the winter break week without a number.
        $this->assertCount(13, array_filter($weeks, fn($w) => $w['number'] !== null));
        $this->assertNull($weeks[4]['number']);
        $this->assertSame(['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04'], $cal->school_days(1));
        $this->assertSame(['2026-12-21', '2026-12-22'], $cal->school_days(4));
        $this->assertSame(5, $cal->week_of('2027-01-04'));
        // Early release is a school day; finals are not.
        $this->assertTrue($cal->is_school_day('2027-02-11'));
        $this->assertFalse($cal->is_school_day('2027-03-03'));
        $this->assertSame(['2027-03-01', '2027-03-02'], $cal->school_days(13));
    }

    public function test_shift(): void {
        $cal = $this->tri2();
        // Tue Dec 22 + 1 school day skips the break and lands on Mon Jan 4.
        $this->assertSame('2027-01-04', $cal->shift('2026-12-22', 1));
        $this->assertSame('2027-01-19', $cal->shift('2027-01-15', 1));
        $this->assertNull($cal->shift('2027-03-02', 1));
    }
}
