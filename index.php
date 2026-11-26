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
 * A course's plans: list, create, edit settings, delete.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_termplanner\form\plan_form;
use local_termplanner\local\plan;
use local_termplanner\local\schoolcal;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$planid = optional_param('planid', -1, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/termplanner:manage', $context);

$url = new moodle_url('/local/termplanner/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('pluginname', 'local_termplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

if ($delete) {
    $plan = $DB->get_record('local_termplanner_plan', ['id' => $delete, 'courseid' => $course->id], '*', MUST_EXIST);
    if ($confirm && confirm_sesskey()) {
        plan::delete($plan->id, optional_param('withevents', 0, PARAM_BOOL));
        redirect($url);
    }
    $events = $DB->count_records('local_termplanner_event', ['planid' => $plan->id]);
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deleteplan', 'local_termplanner'));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::tag('p', get_string('confirmdeleteplan', 'local_termplanner', format_string($plan->name)));
    foreach (['id' => $course->id, 'delete' => $plan->id, 'confirm' => 1, 'sesskey' => sesskey()] as $k => $v) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    if ($events) {
        $label = ' ' . get_string('deleteevents', 'local_termplanner', $events);
        echo html_writer::div(html_writer::checkbox('withevents', 1, false, $label), 'mb-3');
    }
    echo html_writer::tag(
        'button',
        get_string('deleteplan', 'local_termplanner'),
        ['type' => 'submit', 'class' => 'btn btn-danger me-2']
    );
    echo html_writer::link($url, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

$terms = $DB->get_records_menu('local_termplanner_term', null, 'startday DESC', 'id, name');
if ($planid >= 0 && $terms) {
    $groups = [];
    foreach (groups_get_all_groups($course->id) as $g) {
        $groups[$g->id] = format_string($g->name);
    }
    $form = new plan_form(
        new moodle_url($url, ['planid' => $planid]),
        ['terms' => array_map('format_string', $terms), 'groups' => $groups]
    );
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $rec = (object)['courseid' => $course->id, 'termid' => $data->termid, 'name' => $data->name,
            'eventprefix' => trim($data->eventprefix), 'eventday' => $data->eventday,
            'groupids' => implode(',', array_map('intval', array_intersect($data->groupids ?? [], array_keys($groups)))),
            'timemodified' => time()];
        if ($planid) {
            $DB->get_record('local_termplanner_plan', ['id' => $planid, 'courseid' => $course->id], 'id', MUST_EXIST);
            $rec->id = $planid;
            $DB->update_record('local_termplanner_plan', $rec);
        } else {
            $rec->timecreated = $rec->timemodified;
            $planid = $DB->insert_record('local_termplanner_plan', $rec);
        }
        redirect(new moodle_url('/local/termplanner/plan.php', ['id' => $planid]));
    }
    if ($planid) {
        $rec = $DB->get_record('local_termplanner_plan', ['id' => $planid, 'courseid' => $course->id], '*', MUST_EXIST);
        $rec->planid = $rec->id;
        $rec->id = $course->id;
        $rec->groupids = array_filter(explode(',', (string)$rec->groupids));
        $form->set_data($rec);
    } else {
        $form->set_data(['id' => $course->id, 'planid' => 0]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($planid ? 'editplan' : 'newplan', 'local_termplanner'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_termplanner'));
if (!$terms) {
    echo $OUTPUT->notification(get_string('noterms', 'local_termplanner'), 'info');
    echo $OUTPUT->footer();
    exit;
}
echo $OUTPUT->single_button(new moodle_url($url, ['planid' => 0]), get_string('newplan', 'local_termplanner'), 'get');
$plans = $DB->get_records('local_termplanner_plan', ['courseid' => $course->id], 'name');
if (!$plans) {
    echo $OUTPUT->notification(get_string('noplans', 'local_termplanner'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('planname', 'local_termplanner'), get_string('term', 'local_termplanner'),
        get_string('groups', 'local_termplanner'), get_string('publish', 'local_termplanner'), ''];
    $allgroups = groups_get_all_groups($course->id);
    foreach ($plans as $p) {
        $gnames = array_map(fn($g) => format_string($allgroups[$g]->name ?? '?'), array_filter(explode(',', (string)$p->groupids)));
        $table->data[] = [
            html_writer::link(new moodle_url('/local/termplanner/plan.php', ['id' => $p->id]), format_string($p->name)),
            format_string($terms[$p->termid] ?? ''),
            $gnames ? implode(', ', $gnames) : get_string('wholecourse', 'local_termplanner'),
            $p->timepublished ? userdate($p->timepublished, get_string('strftimedatetimeshort', 'langconfig'))
                : get_string('publishnever', 'local_termplanner'),
            html_writer::link(new moodle_url($url, ['planid' => $p->id]), get_string('editplan', 'local_termplanner')) . ' · '
                . html_writer::link(new moodle_url($url, ['delete' => $p->id]), get_string('deleteplan', 'local_termplanner')),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
