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
 * School calendar: terms and their special days (site administration).
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_termplanner\form\term_form;
use local_termplanner\local\schoolcal;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$edit = optional_param('edit', -1, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);

admin_externalpage_setup('local_termplanner_calendar');
$url = new moodle_url('/local/termplanner/calendar.php');
$PAGE->set_url($url);

if ($delete && confirm_sesskey()) {
    if (!$DB->record_exists('local_termplanner_plan', ['termid' => $delete])) {
        $DB->delete_records('local_termplanner_day', ['termid' => $delete]);
        $DB->delete_records('local_termplanner_term', ['id' => $delete]);
    }
    redirect($url);
}

if ($edit >= 0) {
    $form = new term_form(new moodle_url($url, ['edit' => $edit]));
    if ($form->is_cancelled()) {
        redirect($url);
    }
    if ($data = $form->get_data()) {
        $now = time();
        $rec = (object)['name' => $data->name, 'startday' => $data->startday, 'endday' => $data->endday, 'timemodified' => $now];
        if ($edit) {
            $rec->id = $edit;
            $DB->update_record('local_termplanner_term', $rec);
        } else {
            $rec->timecreated = $now;
            $rec->id = $DB->insert_record('local_termplanner_term', $rec);
        }
        [$days] = schoolcal::parse_special($data->special);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_termplanner_day', ['termid' => $rec->id]);
        foreach ($days as $d) {
            $d->termid = $rec->id;
            $DB->insert_record('local_termplanner_day', $d);
        }
        $transaction->allow_commit();
        redirect($url, get_string('changessaved', 'local_termplanner'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($edit) {
        $term = $DB->get_record('local_termplanner_term', ['id' => $edit], '*', MUST_EXIST);
        $term->special = schoolcal::format_special($DB->get_records('local_termplanner_day', ['termid' => $edit], 'day'));
        $form->set_data($term);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($edit ? 'editterm' : 'newterm', 'local_termplanner'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('schoolcalendar', 'local_termplanner'));
echo $OUTPUT->single_button(new moodle_url($url, ['edit' => 0]), get_string('newterm', 'local_termplanner'), 'get');

$terms = $DB->get_records('local_termplanner_term', null, 'startday DESC');
if (!$terms) {
    echo $OUTPUT->notification(get_string('notermsadmin', 'local_termplanner'), 'info');
}
foreach ($terms as $term) {
    $cal = schoolcal::load($term->id);
    $used = $DB->count_records('local_termplanner_plan', ['termid' => $term->id]);
    $title = format_string($term->name) . ' · ' . schoolcal::short($term->startday) . ' – ' . schoolcal::short($term->endday);
    echo $OUTPUT->heading($title, 3);
    $links = [html_writer::link(new moodle_url($url, ['edit' => $term->id]), get_string('editterm', 'local_termplanner'))];
    $links[] = $used ? get_string('deletetermused', 'local_termplanner', $used)
        : html_writer::link(
            new moodle_url($url, ['delete' => $term->id, 'sesskey' => sesskey()]),
            get_string('deleteterm', 'local_termplanner')
        );
    echo html_writer::div(implode(' · ', $links), 'mb-2');
    $table = new html_table();
    $table->head = [get_string('week', 'local_termplanner', ''), get_string('day_mon', 'local_termplanner'),
        get_string('day_tue', 'local_termplanner'), get_string('day_wed', 'local_termplanner'),
        get_string('day_thu', 'local_termplanner'), get_string('day_fri', 'local_termplanner')];
    $table->attributes['class'] = 'generaltable table-sm';
    foreach ($cal->weeks() as $w) {
        $row = [$w['number'] ? get_string('week', 'local_termplanner', $w['number']) . '<br><small>'
            . get_string('schooldays', 'local_termplanner', count($w['schooldays'])) . '</small>'
            : get_string('weekbreak', 'local_termplanner')];
        foreach ($w['days'] as $d) {
            $text = substr(schoolcal::short($d['day']), 4);
            if (!in_array($d['type'], ['school', 'outside'], true)) {
                $text .= '<br><small><strong>' . get_string('type_' . $d['type'], 'local_termplanner') . '</strong>'
                    . ($d['label'] ? ' · ' . s($d['label']) : '') . '</small>';
            }
            $cell = new html_table_cell($d['type'] === 'outside' ? '' : $text);
            if (!$d['school'] && $d['type'] !== 'outside') {
                $cell->attributes['class'] = 'table-secondary';
            }
            $row[] = $cell;
        }
        $table->data[] = $row;
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
