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
 * Edit one plan, week by week, and publish it to the calendar.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_termplanner\local\checks;
use local_termplanner\local\plan;
use local_termplanner\local\publisher;
use local_termplanner\local\schoolcal;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$return = optional_param('return', '', PARAM_ALPHA);

$plan = plan::load($id);
$course = $plan->course;
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/termplanner:manage', $context);

$url = new moodle_url('/local/termplanner/plan.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($plan->record->name) . ' · ' . get_string('pluginname', 'local_termplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add(
    get_string('pluginname', 'local_termplanner'),
    new moodle_url('/local/termplanner/index.php', ['id' => $course->id])
);
$PAGE->navbar->add(format_string($plan->record->name));

$publisher = new publisher($plan);
$audience = function () use ($plan, $course): string {
    $all = groups_get_all_groups($course->id);
    $names = array_map(fn($g) => format_string($all[$g]->name ?? '?'), $plan->group_ids());
    return $names ? implode(', ', $names) : get_string('wholecourse', 'local_termplanner');
};

// Publish: confirm, then write.
if ($action === 'publish') {
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        $counts = $publisher->publish();
        redirect(
            $return === 'grid' ? new moodle_url('/local/termplanner/grid.php', ['id' => $id]) : $url,
            get_string('publishdone', 'local_termplanner', (object)$counts),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $d = $publisher->diff();
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('publish', 'local_termplanner') . ': ' . format_string($plan->record->name));
    echo html_writer::tag('p', get_string('publishintro', 'local_termplanner'));
    echo html_writer::tag('p', get_string('publishaudience', 'local_termplanner', $audience()));
    echo html_writer::tag('p', html_writer::tag('strong', get_string('publishcounts', 'local_termplanner', (object)[
        'create' => count($d['create']), 'update' => count($d['update']),
        'delete' => count($d['delete']), 'same' => count($d['same'])])));
    $problems = checks::run($plan)['problems'];
    if ($problems) {
        echo $OUTPUT->notification(implode('<br>', array_column($problems, 'message')), 'warning');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    foreach (['action' => 'publish', 'confirm' => 1, 'return' => $return, 'sesskey' => sesskey()] as $k => $v) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
    }
    echo html_writer::tag(
        'button',
        get_string('publishconfirm', 'local_termplanner'),
        ['type' => 'submit', 'class' => 'btn btn-primary me-2']
    );
    $cancel = $return === 'grid' ? new moodle_url('/local/termplanner/grid.php', ['id' => $id]) : $url;
    echo html_writer::link($cancel, get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

// Saving the editor: first every row's fields, then the button that was pressed.
if (data_submitted() && confirm_sesskey()) {
    $errors = [];
    $days = optional_param_array('day', [], PARAM_RAW_TRIMMED);
    $levels = optional_param_array('level', [], PARAM_INT);
    $texts = optional_param_array('text', [], PARAM_TEXT);
    $closes = optional_param_array('closes', [], PARAM_RAW_TRIMMED);
    $checks = optional_param_array('check', [], PARAM_RAW_TRIMMED);
    $dayok = fn(string $d) => schoolcal::valid_day($d) && $d >= $plan->cal->term->startday && $d <= $plan->cal->term->endday;
    foreach ($plan->items as $itemid => $item) {
        $fields = [];
        if (isset($days[$itemid])) {
            if ($dayok($days[$itemid])) {
                $fields['day'] = $days[$itemid];
            } else {
                $errors[] = s($days[$itemid]);
            }
        }
        if (isset($levels[$itemid])) {
            $fields['level'] = max(0, min(3, $levels[$itemid]));
        }
        if (isset($texts[$itemid]) && !$item->cmid) {
            $fields['text'] = $texts[$itemid];
        }
        foreach (['closesday' => $closes, 'checkday' => $checks] as $f => $values) {
            if (!array_key_exists($itemid, $values)) {
                continue;
            }
            if ($values[$itemid] === '') {
                $fields[$f] = null;
            } else if ($dayok($values[$itemid])) {
                $fields[$f] = $values[$itemid];
            } else {
                $errors[] = s($values[$itemid]);
            }
        }
        $plan->update_item($itemid, $fields);
    }
    $itemweek = function (int $itemid) use ($plan): string {
        $item = $plan->items[$itemid] ?? null;
        $week = $item ? $plan->cal->week_of($item->day) : null;
        return $week ? 'week' . $week : '';
    };
    $anchor = '';
    foreach (['up', 'down', 'del', 'reset'] as $b) {
        if ($v = optional_param($b, 0, PARAM_INT)) {
            $anchor = $itemweek($v);
        }
    }
    if ($v = optional_param('up', 0, PARAM_INT)) {
        $plan->move_item($v, -1);
    } else if ($v = optional_param('down', 0, PARAM_INT)) {
        $plan->move_item($v, 1);
    } else if ($v = optional_param('del', 0, PARAM_INT)) {
        $plan->delete_item($v);
    } else if ($v = optional_param('reset', 0, PARAM_INT)) {
        $plan->reset_deadlines($v);
    } else if ($week = optional_param('add', 0, PARAM_INT)) {
        $anchor = 'week' . $week;
        $addday = optional_param_array('addday', [], PARAM_RAW_TRIMMED)[$week] ?? '';
        $addcm = optional_param_array('addcm', [], PARAM_INT)[$week] ?? 0;
        $addtext = trim(optional_param_array('addtext', [], PARAM_TEXT)[$week] ?? '');
        $addlevel = optional_param_array('addlevel', [], PARAM_INT)[$week] ?? 0;
        if (in_array($addday, $plan->cal->school_days($week), true)) {
            if ($addcm && isset($plan->activities()[$addcm])) {
                $plan->add_item($addday, $addcm, '', $addlevel);
            }
            if ($addtext !== '') {
                $plan->add_item($addday, 0, $addtext, $addlevel);
            }
        }
    } else if (optional_param('publish', '', PARAM_RAW) !== '') {
        redirect(new moodle_url($url, ['action' => 'publish']));
    }
    if ($errors) {
        \core\notification::warning(get_string('invaliddate', 'error') . ': ' . implode(', ', $errors));
    }
    redirect(new moodle_url($url, [], $anchor ?: null));
}

// Build the editor.
$check = checks::run($plan);
$acts = $plan->activities();
$used = array_flip(array_map(fn($i) => (int)$i->cmid, $plan->items));
$options = [];
foreach ($acts as $a) {
    if (isset($used[$a->cmid])) {
        continue;
    }
    $options[$a->section]['label'] ??= $a->sectionname;
    $options[$a->section]['options'][] = ['value' => $a->cmid, 'label' => $a->name];
}
$options = array_values($options);
$kinds = ['cfa' => 'CFA', 'lab' => 'Lab', 'test' => 'Test', 'ws' => 'WS', 'other' => '·'];
$term = $plan->cal->term;
$weeks = [];
foreach ($plan->cal->weeks() as $w) {
    $range = substr(schoolcal::short($w['monday']), 4) . ' – ' . substr(schoolcal::short(schoolcal::add_days($w['monday'], 4)), 4);
    if ($w['number'] === null) {
        if ($w['monday'] >= $term->startday || schoolcal::add_days($w['monday'], 4) >= $term->startday) {
            $labels = array_unique(array_filter(array_column($w['days'], 'label')));
            $label = implode(', ', $labels) ?: get_string('weekbreak', 'local_termplanner');
            $weeks[] = ['isbreak' => true, 'range' => $range, 'label' => $label];
        }
        continue;
    }
    $notes = [];
    foreach ($w['days'] as $d) {
        if (!in_array($d['type'], ['school', 'outside'], true)) {
            $label = $d['label'] ?: get_string('type_' . $d['type'], 'local_termplanner');
            $notes[] = substr(schoolcal::short($d['day']), 0, 3) . ' ' . $label;
        }
    }
    $items = [];
    $list = $plan->week_items($w['number']);
    foreach ($list as $i => $it) {
        $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
        $kind = $it->cmid ? ($act->kind ?? 'other') : 'text';
        $items[] = [
            'id' => $it->id,
            'istext' => !$it->cmid,
            'text' => $it->text,
            'name' => $act->name ?? ($it->cmid ? '?' : ''),
            'kind' => $kind,
            'kindlabel' => $kinds[$kind] ?? '',
            'day' => $it->day,
            'dayname' => substr(schoolcal::short($it->day), 0, 3),
            'levels' => array_map(fn($l) => ['value' => $l, 'label' => str_repeat('→', $l) ?: '0',
                'selected' => $l == $it->level], [0, 1, 2, 3]),
            'indent' => (int)$it->level,
            'islab' => $kind === 'lab',
            'closes' => $it->closesday,
            'check' => $it->checkday,
            'flagged' => !empty($check['items'][$it->id]),
            'first' => $i === 0,
            'last' => $i === count($list) - 1,
        ];
    }
    $weeks[] = [
        'number' => $w['number'],
        'range' => $range,
        'schooldays' => get_string('schooldays', 'local_termplanner', count($w['schooldays'])),
        'notes' => implode(' · ', $notes),
        'flags' => implode(', ', $check['weekflags'][$w['number']] ?? []),
        'items' => $items,
        'hasitems' => (bool)$items,
        'adddays' => array_map(fn($d) => ['value' => $d, 'label' => schoolcal::short($d)], $w['schooldays']),
        'activities' => $options,
        'preview' => publisher::lines_to_html($publisher->week_lines($w['number'])),
    ];
}
$problems = array_map(
    fn($p) => ['message' => $p['message'], 'anchor' => $p['week'] ? 'week' . $p['week'] : ''],
    $check['problems']
);
$diff = $publisher->diff();
$pending = count($diff['create']) + count($diff['update']) + count($diff['delete']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_termplanner/plan', [
    'title' => format_string($plan->record->name),
    'term' => format_string($term->name),
    'audience' => $audience(),
    'published' => $plan->record->timepublished
        ? get_string(
            'publishedon',
            'local_termplanner',
            userdate($plan->record->timepublished, get_string('strftimedatetimeshort', 'langconfig'))
        )
        : get_string('publishnever', 'local_termplanner'),
    'pending' => $pending,
    'settingsurl' => (new moodle_url(
        '/local/termplanner/index.php',
        ['id' => $course->id, 'planid' => $plan->record->id]
    ))->out(false),
    'backurl' => (new moodle_url('/local/termplanner/index.php', ['id' => $course->id]))->out(false),
    'gridurl' => (new moodle_url('/local/termplanner/grid.php', ['id' => $plan->record->id]))->out(false),
    'action' => $url->out(false),
    'sesskey' => sesskey(),
    'min' => $term->startday,
    'max' => $term->endday,
    'problems' => $problems,
    'ok' => !$problems,
    'weeks' => $weeks,
]);
echo $OUTPUT->footer();
