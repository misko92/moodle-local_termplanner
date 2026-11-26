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
 * Week grid: plan a term by dragging items between school days.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_termplanner\local\plan;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$plan = plan::load($id);
$course = $plan->course;
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/termplanner:manage', $context);

$url = new moodle_url('/local/termplanner/grid.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($plan->record->name) . ' · ' . get_string('pluginname', 'local_termplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add(
    get_string('pluginname', 'local_termplanner'),
    new moodle_url('/local/termplanner/index.php', ['id' => $course->id])
);
$PAGE->navbar->add(format_string($plan->record->name));

$keys = ['week', 'schooldays', 'weekbreak', 'day_mon', 'day_tue', 'day_wed', 'day_thu', 'day_fri', 'closesday', 'checkday',
    'level', 'day', 'textline', 'deleteitem', 'resetdeadlines', 'checks', 'checksok', 'preview', 'additem',
    'grid_activity', 'grid_addto', 'grid_checkoff', 'grid_confirmdelete', 'grid_earlier', 'grid_hint', 'grid_lastday',
    'grid_later', 'grid_movehere', 'grid_nextweek', 'grid_noweekitems', 'grid_ortext', 'grid_saved', 'grid_shift',
    'grid_shiftdays', 'grid_shiftearlier', 'grid_shiftlater', 'dueday', 'grid_due', 'quizopen', 'quizclose', 'grid_search', 'grid_matches'];
$strings = [];
foreach ($keys as $k) {
    $strings[$k] = get_string($k, 'local_termplanner');
}

$all = groups_get_all_groups($course->id);
$names = array_map(fn($g) => format_string($all[$g]->name ?? '?'), $plan->group_ids());
$PAGE->requires->js_call_amd('local_termplanner/grid', 'init', ['local-termplanner-grid']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_termplanner/grid', [
    'planid' => $id,
    'title' => format_string($plan->record->name),
    'term' => format_string($plan->cal->term->name),
    'audience' => $names ? implode(', ', $names) : get_string('wholecourse', 'local_termplanner'),
    'published' => $plan->record->timepublished
        ? get_string(
            'publishedon',
            'local_termplanner',
            userdate($plan->record->timepublished, get_string('strftimedatetimeshort', 'langconfig'))
        )
        : get_string('publishnever', 'local_termplanner'),
    'strings' => json_encode($strings),
    'publishurl' => (new moodle_url(
        '/local/termplanner/plan.php',
        ['id' => $id, 'action' => 'publish', 'return' => 'grid']
    ))->out(false),
    'listurl' => (new moodle_url('/local/termplanner/plan.php', ['id' => $id]))->out(false),
    'settingsurl' => (new moodle_url('/local/termplanner/index.php', ['id' => $course->id, 'planid' => $id]))->out(false),
    'backurl' => (new moodle_url('/local/termplanner/index.php', ['id' => $course->id]))->out(false),
]);
echo $OUTPUT->footer();
