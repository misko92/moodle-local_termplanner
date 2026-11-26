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
 * English strings.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addactivity'] = 'Activity';
$string['additem'] = 'Add';
$string['addline'] = 'Or a line of text';
$string['addtoweek'] = 'Add to week {$a}';
$string['back'] = 'Back to plans';
$string['changessaved'] = 'Changes saved.';
$string['check_closebeforeopen'] = '{$a->name} closes before it opens.';
$string['check_deadlinebefore'] = '{$a->name}: a deadline ({$a->day}) is before the day students do the lab.';
$string['check_deadlinenotschool'] = '{$a->name}: deadline on {$a->day}, which is not a school day.';
$string['check_duebefore'] = '{$a->name} is due ({$a->day}) before the day it is planned.';
$string['check_labbeforecfa'] = '{$a->name} comes before {$a->cfas}. In the course those CFAs come first, so students may not be ready.';
$string['check_missing'] = 'An activity in this plan was deleted from the course.';
$string['check_nocheckday'] = '{$a->name} has no check-off date.';
$string['check_notschool'] = '{$a->name} is planned on {$a->day}, which is not a school day.';
$string['check_testbeforecfa'] = '{$a->name} comes before {$a->cfas}, which are part of its topic.';
$string['check_toomanydue'] = 'Week {$a->week} has deadlines for {$a->count} labs ({$a->labs}). The rule is at most 2.';
$string['check_toomanylabs'] = 'Week {$a->week} has {$a->count} labs ({$a->labs}). The rule is at most 2.';
$string['checkday'] = 'Check off by';
$string['checks'] = 'Checks';
$string['checksok'] = 'All checks pass: labs follow their CFAs, at most 2 labs and 2 lab deadlines a week, everything on school days.';
$string['closesday'] = 'Last day to do';
$string['confirmdeleteplan'] = 'Delete the plan "{$a}"?';
$string['copybeyond'] = 'Week {$a} is past the end of this term; its items went to the last week.';
$string['copydone'] = 'Copied {$a} items.';
$string['copyfrom'] = 'Copy from';
$string['copyfrom_help'] = 'Start this plan as a copy of another plan in this course. Week 1 goes to week 1, week 2 to week 2, and so on; each item keeps its place in the week (its 1st, 2nd, ... school day). Lab deadlines are reset to the usual dates.';
$string['copynone'] = 'Start empty';
$string['copyshort'] = 'Week {$a->week} has {$a->to} school days here but {$a->from} in the plan copied from. Check it.';
$string['cutoffdays'] = 'Assignment cut-off';
$string['cutoffdays_help'] = 'When the plan sets an assignment\'s due date, its cut-off date is set this many days later (23:59). 0 leaves no cut-off.';
$string['day'] = 'Day';
$string['day_fri'] = 'Fri';
$string['day_mon'] = 'Mon';
$string['day_sat'] = 'Sat';
$string['day_sun'] = 'Sun';
$string['day_thu'] = 'Thu';
$string['day_tue'] = 'Tue';
$string['day_wed'] = 'Wed';
$string['dayname_fri'] = 'Friday';
$string['dayname_mon'] = 'Monday';
$string['dayname_thu'] = 'Thursday';
$string['dayname_tue'] = 'Tuesday';
$string['dayname_wed'] = 'Wednesday';
$string['deleteevents'] = 'Also delete the {$a} calendar events this plan published';
$string['deleteitem'] = 'Remove';
$string['deleteplan'] = 'Delete plan';
$string['deleteterm'] = 'Delete term';
$string['deletetermused'] = 'This term is used by {$a} plan(s), so it cannot be deleted.';
$string['dueday'] = 'Due';
$string['dueday_help'] = 'Publishing sets the assignment\'s due date to this day at 23:59, with the cut-off from the plan settings.';
$string['editplan'] = 'Plan settings';
$string['editterm'] = 'Edit term';
$string['endday'] = 'Last day';
$string['event_check'] = '{$a} Deadline';
$string['event_check_desc'] = 'This is the LAST DAY to check off this lab for points.';
$string['event_closes'] = '{$a} Closes';
$string['event_closes_desc'] = 'This is the LAST DAY to do this lab.';
$string['eventday'] = 'Put each week\'s event on';
$string['eventday_first'] = 'the first school day of the week';
$string['eventday_last'] = 'the last school day of the week';
$string['eventday_span'] = 'the whole week (first to last school day)';
$string['eventprefix'] = 'Event name';
$string['eventprefix_help'] = 'Week events are named with this followed by the week number, for example "Chem A T2" gives "Chem A T2 W1", "Chem A T2 W2", ...';
$string['flag_due'] = '{$a} lab deadlines';
$string['flag_labs'] = '{$a} labs';
$string['grid_activity'] = 'Activity';
$string['grid_addto'] = 'Add to {$a}';
$string['grid_checkoff'] = 'check';
$string['grid_confirmdelete'] = 'Click again to remove';
$string['grid_due'] = 'due';
$string['grid_earlier'] = 'Earlier';
$string['grid_hint'] = 'Drag a chip to another day to move it. Click a chip to edit it, a date to add something to that day, or a week number to see what students get that week.';
$string['grid_lastday'] = 'last';
$string['grid_later'] = 'Later';
$string['grid_movehere'] = 'Move here';
$string['grid_nextweek'] = 'Next week ▶';
$string['grid_noweekitems'] = 'Nothing planned this week yet.';
$string['grid_ortext'] = 'or a line of text';
$string['grid_saved'] = 'Saved';
$string['grid_shift'] = 'Snow day? Move this day and everything after it, lab deadlines included';
$string['grid_shiftdays'] = 'School days';
$string['grid_shiftearlier'] = '◀ 1 school day';
$string['grid_shiftlater'] = '1 school day ▶';
$string['gridview'] = 'Week grid';
$string['groups'] = 'Groups';
$string['groups_help'] = 'Students in these groups see the plan\'s events; each group gets its own copy. Leave empty to show the events to everyone in the course.';
$string['labdeadlines'] = 'Lab deadlines';
$string['labevents'] = 'Lab deadline events';
$string['labevents_help'] = 'On: labs get a "last day to do" and a "check off by" date, published as "Lab x.y Closes" and "Lab x.y Deadline" events, and the week list adds "Check off ... with your teacher". Off: labs are listed by name only; use their assignment due dates instead.';
$string['level'] = 'Indent';
$string['line_checkofflab'] = 'Check off {$a} with your teacher';
$string['line_checkoffws'] = 'Check off {$a} with your teacher';
$string['line_deadline'] = '{$a->lab}: last day to do the lab {$a->closes}, check off by {$a->check}';
$string['line_deadlinecheck'] = '{$a->lab}: check off by {$a->check}';
$string['line_do'] = 'Do {$a}';
$string['line_docfa'] = 'Do CFA';
$string['line_docfas'] = 'Do CFA’s';
$string['line_dolab'] = 'Do {$a}';
$string['line_labdeadlines'] = '→ Lab deadlines';
$string['line_noschool'] = 'No school – {$a}';
$string['line_take'] = 'Take {$a}';
$string['listview'] = 'List view';
$string['movedown'] = 'Move down';
$string['moveup'] = 'Move up';
$string['newplan'] = 'New plan';
$string['newterm'] = 'New term';
$string['noplans'] = 'No plans yet. Make one for each group of students and term, for example "Chem A · Tri 2".';
$string['noterms'] = 'The school calendar has no terms yet. A site administrator adds them under Site administration › Plugins › Local plugins › School calendar.';
$string['notermsadmin'] = 'No terms yet. Add one for each trimester or semester.';
$string['planname'] = 'Plan name';
$string['plans'] = 'Plans';
$string['pluginname'] = 'Term planner';
$string['preview'] = 'What students see';
$string['privacy:metadata'] = 'The Term planner plugin stores course plans and the school calendar, but no personal data.';
$string['publish'] = 'Publish to calendar';
$string['publishaudience'] = 'Students who see them: {$a}';
$string['publishconfirm'] = 'Publish';
$string['publishcounts'] = '{$a->create} to create, {$a->update} to update, {$a->delete} to delete, {$a->same} unchanged';
$string['publishdates'] = 'Activity dates to set: {$a}';
$string['publishdone'] = 'Published: {$a->created} created, {$a->updated} updated, {$a->deleted} deleted, {$a->unchanged} unchanged; {$a->dates} activity dates set.';
$string['publishedon'] = 'Last published {$a}';
$string['publishintro'] = 'Publishing writes this plan\'s week events and lab deadline events to the course calendar. Events it published before are updated in place. Nothing else in the calendar is touched.';
$string['publishnever'] = 'Not published yet';
$string['quizclose'] = 'Closes at';
$string['quizopen'] = 'Opens at';
$string['quiztimes_help'] = 'Publishing sets the quiz to open and/or close at these times on the day it is planned.';
$string['resetdeadlines'] = 'Usual dates';
$string['save'] = 'Save changes';
$string['schoolcalendar'] = 'School calendar';
$string['schooldays'] = '{$a} school days';
$string['shiftoutside'] = 'That would move something past the end of the term, so nothing was moved.';
$string['specialdayerror'] = 'Line {$a->line} is not understood: {$a->text}';
$string['specialdays'] = 'Special days';
$string['specialdays_help'] = 'One per line: a day or a range, the type, and a label. Types: noschool, early (early release, still a school day), finals (no regular class). Weekends inside a range are skipped. Example:
2026-12-23..2027-01-01 | noschool | Winter break
2027-02-11 | early | Early release
2027-03-03..2027-03-04 | finals | Finals';
$string['startday'] = 'First day';
$string['term'] = 'Term';
$string['termname'] = 'Term name';
$string['termplanner:manage'] = 'Plan course weeks and publish them to the calendar';
$string['terms'] = 'Terms';
$string['textline'] = 'Text';
$string['type_early'] = 'Early release';
$string['type_finals'] = 'Finals';
$string['type_noschool'] = 'No school';
$string['week'] = 'Week {$a}';
$string['weekbreak'] = 'No school this week';
$string['weekstyle'] = 'Week event layout';
$string['weekstyle_days'] = 'By day: Monday, Tuesday, ... with the items under each day';
$string['weekstyle_list'] = 'One list for the whole week';
$string['wholecourse'] = 'everyone in the course';
