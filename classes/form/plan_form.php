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
 * Form for a plan's settings.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Plan settings: name, term, event name, where the week event goes, groups.
 *
 * Custom data: terms (id => name), groups (id => name), copyfrom (plan id => name, only for a new plan).
 */
class plan_form extends \moodleform {
    /**
     * Define the form.
     */
    protected function definition() {
        $m = $this->_form;
        $m->addElement('hidden', 'id');
        $m->setType('id', PARAM_INT);
        $m->addElement('hidden', 'planid');
        $m->setType('planid', PARAM_INT);
        $m->addElement('text', 'name', get_string('planname', 'local_termplanner'), ['size' => 40]);
        $m->setType('name', PARAM_TEXT);
        $m->addRule('name', null, 'required', null, 'client');
        $m->addElement('select', 'termid', get_string('term', 'local_termplanner'), $this->_customdata['terms']);
        $m->addElement('text', 'eventprefix', get_string('eventprefix', 'local_termplanner'), ['size' => 20]);
        $m->setType('eventprefix', PARAM_TEXT);
        $m->addRule('eventprefix', null, 'required', null, 'client');
        $m->addHelpButton('eventprefix', 'eventprefix', 'local_termplanner');
        $m->addElement('select', 'eventday', get_string('eventday', 'local_termplanner'), [
            'last' => get_string('eventday_last', 'local_termplanner'),
            'first' => get_string('eventday_first', 'local_termplanner'),
            'span' => get_string('eventday_span', 'local_termplanner'),
        ]);
        $m->addElement(
            'autocomplete',
            'groupids',
            get_string('groups', 'local_termplanner'),
            $this->_customdata['groups'],
            ['multiple' => true]
        );
        $m->addHelpButton('groupids', 'groups', 'local_termplanner');
        if (!empty($this->_customdata['copyfrom'])) {
            $m->addElement(
                'select',
                'copyfrom',
                get_string('copyfrom', 'local_termplanner'),
                [0 => get_string('copynone', 'local_termplanner')] + $this->_customdata['copyfrom']
            );
            $m->addHelpButton('copyfrom', 'copyfrom', 'local_termplanner');
        }
        $this->add_action_buttons();
    }
}
