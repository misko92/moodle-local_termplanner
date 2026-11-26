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
 * Everything the week grid needs about a plan, as one array.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\local;

/**
 * Builds the grid's state: weeks and days, items, unused activities, checks, week previews and publish status.
 */
class state {
    /**
     * Build the state of a plan.
     *
     * @param plan $plan
     * @return array
     */
    public static function build(plan $plan): array {
        $acts = $plan->activities();
        $publisher = new publisher($plan);
        $check = checks::run($plan);
        $used = [];
        $items = [];
        foreach ($plan->items as $it) {
            $act = $it->cmid ? ($acts[$it->cmid] ?? null) : null;
            $used[(int)$it->cmid] = true;
            $items[] = [
                'id' => (int)$it->id,
                'day' => $it->day,
                'sortorder' => (int)$it->sortorder,
                'cmid' => (int)$it->cmid,
                'kind' => $it->cmid ? ($act->kind ?? 'missing') : 'text',
                'name' => $act->name ?? '',
                'short' => $act->short ?? '',
                'section' => $act->sectionname ?? '',
                'text' => (string)$it->text,
                'level' => (int)$it->level,
                'closes' => $it->closesday,
                'check' => $it->checkday,
                'modname' => $act->modname ?? '',
                'due' => $it->dueday,
                'opentime' => $it->opentime,
                'closetime' => $it->closetime,
                'flagged' => !empty($check['items'][$it->id]),
            ];
        }
        $activities = [];
        foreach ($acts as $a) {
            if (empty($used[$a->cmid])) {
                $activities[] = ['cmid' => $a->cmid, 'name' => $a->name, 'kind' => $a->kind, 'section' => $a->sectionname];
            }
        }
        $weeks = [];
        $previews = [];
        $term = $plan->cal->term;
        foreach ($plan->cal->weeks() as $w) {
            $days = [];
            foreach ($w['days'] as $d) {
                $days[] = ['day' => $d['day'], 'short' => schoolcal::short($d['day']), 'school' => $d['school'],
                    'type' => $d['type'], 'label' => $d['type'] === 'school' || $d['type'] === 'outside' ? ''
                        : ($d['label'] ?: get_string('type_' . $d['type'], 'local_termplanner'))];
            }
            $weeks[] = [
                'number' => $w['number'],
                'range' => substr(schoolcal::short($w['monday']), 4) . ' – '
                    . substr(schoolcal::short(schoolcal::add_days($w['monday'], 4)), 4),
                'schooldays' => count($w['schooldays']),
                'days' => $days,
                'flags' => $w['number'] ? implode(', ', $check['weekflags'][$w['number']] ?? []) : '',
            ];
            if ($w['number']) {
                $previews[$w['number']] = publisher::lines_to_html($publisher->week_lines($w['number']));
            }
        }
        $diff = $publisher->diff();
        return [
            'planid' => (int)$plan->record->id,
            'term' => ['start' => $term->startday, 'end' => $term->endday],
            'weeks' => $weeks,
            'items' => $items,
            'activities' => $activities,
            'problems' => $check['problems'],
            'previews' => $previews,
            'pending' => count($diff['create']) + count($diff['update']) + count($diff['delete']) + count($diff['dates']),
            'prefix' => $plan->record->eventprefix,
            'labevents' => (bool)$plan->record->labevents,
            'labrules' => (bool)$plan->record->labrules,
            'cutoffdays' => (int)$plan->record->cutoffdays,
        ];
    }
}
