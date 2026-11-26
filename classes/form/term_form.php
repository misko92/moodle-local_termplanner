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
 * Form for a school term and its special days.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_termplanner\local\schoolcal;

/**
 * Term form: name, first and last day (YYYY-MM-DD), special days as text.
 */
class term_form extends \moodleform {
    /**
     * Define the form.
     */
    protected function definition() {
        $m = $this->_form;
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('text', 'name', get_string('termname', 'local_termplanner'), ['size' => 40]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        foreach (['startday', 'endday'] as $f) {
            $m->addElement('text', $f, get_string($f, 'local_termplanner'), ['type' => 'date']);
            $m->setType($f, PARAM_RAW_TRIMMED);
            $m->addRule($f, null, 'required', null, 'client');
        }
        $m->addElement(
            'textarea',
            'special',
            get_string('specialdays', 'local_termplanner'),
            ['rows' => 14, 'cols' => 70, 'class' => 'text-monospace']
        );
        $m->setType('special', PARAM_RAW);
        $m->addHelpButton('special', 'specialdays', 'local_termplanner');
        $this->add_action_buttons();
    }

    /**
     * Validate days and special-day lines.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['startday', 'endday'] as $f) {
            if (!schoolcal::valid_day($data[$f])) {
                $errors[$f] = get_string('invaliddate', 'error');
            }
        }
        if (empty($errors) && $data['endday'] < $data['startday']) {
            $errors['endday'] = get_string('invaliddate', 'error');
        }
        [, $lineerrors] = schoolcal::parse_special($data['special'] ?? '');
        if ($lineerrors) {
            $errors['special'] = implode('<br>', $lineerrors);
        }
        return $errors;
    }
}
