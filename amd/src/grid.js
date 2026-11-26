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
 * Week grid: draw a plan as weeks x days, move items by dragging or from the side panel.
 *
 * Every change goes to local_termplanner_update, which returns the plan's new state; the page is then redrawn
 * from that state, so what you see is always what is saved.
 *
 * @module     local_termplanner/grid
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

let root;
let planid;
let str = {};
let state = null;
let sel = null;
let confirmDelete = 0;
let toastTimer = null;

const DOW = ['day_mon', 'day_tue', 'day_wed', 'day_thu', 'day_fri'];

/**
 * Get a string, replacing {$a}.
 *
 * @param {String} key
 * @param {String|Number} a
 * @returns {String}
 */
const s = (key, a) => (str[key] || key).replace('{$a}', a === undefined ? '' : a);

/**
 * Make an element.
 *
 * @param {String} tag
 * @param {Object} attrs attributes; "text" sets textContent, "on" an object of event handlers
 * @param {Array} children
 * @returns {HTMLElement}
 */
const el = (tag, attrs = {}, children = []) => {
    const e = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
        if (v === null || v === undefined || v === false) {
            return;
        }
        if (k === 'text') {
            e.textContent = v;
        } else if (k === 'on') {
            Object.entries(v).forEach(([ev, fn]) => e.addEventListener(ev, fn));
        } else if (k === 'class') {
            e.className = v;
        } else {
            e.setAttribute(k, v === true ? '' : v);
        }
    });
    children.forEach(c => c && e.append(c));
    return e;
};

const region = name => root.querySelector(`[data-region="${name}"]`);

const toast = text => {
    const t = region('toast');
    t.textContent = text;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        t.hidden = true;
    }, 2200);
};

/**
 * Call a web service and redraw from the state it returns.
 *
 * @param {String} methodname
 * @param {Object} args
 * @param {String} message shown when it worked
 * @returns {Promise}
 */
const call = (methodname, args, message) => {
    root.classList.add('tp-busy');
    // Ajax.call gives a jQuery promise, which has no finally(): use a native one.
    return Promise.resolve(Ajax.call([{methodname, args}])[0]).then(r => {
        state = JSON.parse(r.state);
        render();
        if (r.error) {
            toast(r.error);
        } else if (message) {
            toast(message);
        }
        return state;
    }).catch(Notification.exception).finally(() => root.classList.remove('tp-busy'));
};

const update = (ops, message = s('grid_saved')) => call('local_termplanner_update', {planid, ops}, message);

const item = id => state.items.find(i => i.id === id);
const schoolDays = () => state.weeks.flatMap(w => w.days.filter(d => d.school).map(d => d.day));
const dayInfo = day => state.weeks.flatMap(w => w.days).find(d => d.day === day);

/**
 * Short label for a chip.
 *
 * @param {Object} it item
 * @returns {String}
 */
const chipLabel = it => {
    if (it.kind === 'text') {
        return it.text;
    }
    const m = it.short.match(/^CFA Section (.+)$/i);
    if (m) {
        return 'CFA ' + m[1];
    }
    if (it.kind === 'ws') {
        return it.short.replace(/^Worksheets\s*&\s*Notes\s*/i, 'WS&N ').replace(/Topic\s*/i, '') + ' ✓';
    }
    return (it.short || it.name).replace(/^Practice Problems Lecture\s*/i, 'PP L').replace(/\bLecture\s+/i, 'L');
};

const fullName = it => (it.kind === 'text' ? it.text : it.name);

/**
 * Where a drop lands: the chip under the pointer (go before it), or the end of the day.
 *
 * @param {DragEvent} e
 * @returns {Number}
 */
const dropBefore = e => {
    const chip = e.target.closest('.tp-chip[data-item]');
    return chip ? parseInt(chip.dataset.item, 10) : 0;
};

const chip = (it, mode = 'item') => {
    const isItem = mode === 'item';
    const modeLabel = {closes: s('grid_lastday'), check: s('grid_checkoff'), due: s('grid_due')};
    let label = isItem ? chipLabel(it) : `${chipLabel(it)} ${modeLabel[mode]}`;
    if (mode === 'due' && it.due) {
        // A due day on a weekend is shown on that week's Friday.
        const wd = new Date(it.due + 'T12:00:00').getDay();
        if (wd === 0 || wd === 6) {
            label += ' ' + (wd === 0 ? 'Sun' : 'Sat');
        }
    }
    // A div, not a button: Firefox will not drag buttons. Keyboard users get Enter/Space like a button.
    const c = el('div', {
        'role': 'button',
        'tabindex': '0',
        'class': `tp-chip tp-${isItem ? it.kind : 'dl tp-dl-' + mode}${it.flagged && isItem ? ' tp-bad' : ''}`
            + (sel && sel.type === 'item' && sel.id === it.id ? ' tp-sel' : ''),
        'draggable': 'true',
        'title': isItem ? fullName(it) : ({closes: s('closesday'), check: s('checkday'), due: s('dueday')})[mode] + ': ' + it.name,
        'data-item': isItem ? it.id : null,
        'style': isItem && it.level ? `margin-left:${it.level * 10}px` : null,
        'text': label,
        'on': {
            dragstart: e => {
                e.dataTransfer.setData('text/plain', JSON.stringify({mode, id: it.id}));
                e.dataTransfer.effectAllowed = 'move';
            },
            click: e => {
                e.stopPropagation();
                select({type: 'item', id: it.id});
            },
            keydown: e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    select({type: 'item', id: it.id});
                }
            },
        },
    });
    return c;
};

/**
 * Apply a drop of a chip onto a day.
 *
 * @param {Object} data {mode, id}
 * @param {String} day
 * @param {Number} before
 */
const dropOn = (data, day, before) => {
    const it = item(data.id);
    if (!it) {
        return;
    }
    if (data.mode === 'item') {
        if (it.day === day && !before) {
            return;
        }
        update([{op: 'move', itemid: it.id, day, before}]);
    } else {
        update([{op: 'set', itemid: it.id, field: data.mode, value: day}]);
    }
};

const renderGrid = () => {
    const box = region('grid');
    box.setAttribute('aria-busy', 'false');
    const grid = el('div', {'class': 'tp-grid', 'role': 'grid'});
    grid.append(el('div', {'class': 'tp-hd', 'role': 'columnheader', text: s('week', '')}));
    DOW.forEach(k => grid.append(el('div', {'class': 'tp-hd', 'role': 'columnheader', text: s(k)})));
    const byDay = {};
    state.items.forEach(it => (byDay[it.day] = byDay[it.day] || []).push(it));
    Object.values(byDay).forEach(list => list.sort((a, b) => a.sortorder - b.sortorder));
    const deadlines = {};
    const addDeadline = (day, it, mode) => (deadlines[day] = deadlines[day] || []).push([it, mode]);
    state.items.forEach(it => {
        if (it.kind === 'lab' && state.labevents) {
            if (it.closes) {
                addDeadline(it.closes, it, 'closes');
            }
            if (it.check) {
                addDeadline(it.check, it, 'check');
            }
        }
        if (it.due) {
            const d = new Date(it.due + 'T12:00:00');
            const back = {'0': 2, '6': 1}[d.getDay()] || 0;
            d.setDate(d.getDate() - back);
            addDeadline(d.toISOString().slice(0, 10), it, 'due');
        }
    });
    state.weeks.forEach(w => {
        if (w.number === null) {
            if (w.days.some(d => d.type !== 'outside')) {
                const labels = [...new Set(w.days.map(d => d.label).filter(Boolean))].join(', ');
                grid.append(el('div', {'class': 'tp-break', text: `${w.range} · ${labels || s('weekbreak')}`}));
            }
            return;
        }
        const wk = el('button', {
            'type': 'button',
            'class': 'tp-wk' + (sel && sel.type === 'week' && sel.n === w.number ? ' tp-sel' : ''),
            'on': {click: () => select({type: 'week', n: w.number})},
        }, [
            el('span', {'class': 'tp-wkn', text: s('week', w.number)}),
            el('span', {'class': 'tp-wkd', text: w.range}),
            el('span', {'class': 'tp-wkd', text: s('schooldays', w.schooldays)}),
            w.flags ? el('span', {'class': 'tp-flag', text: '⚠ ' + w.flags}) : null,
        ]);
        grid.append(el('div', {'class': 'tp-wkcell', 'id': 'tp-week' + w.number}, [wk]));
        w.days.forEach(d => {
            let offclass = '';
            if (!d.school) {
                offclass = d.type === 'finals' ? 'tp-finals' : 'tp-off';
            }
            const cls = ['tp-day', offclass,
                d.type === 'outside' ? 'tp-outside' : '', sel && sel.type === 'day' && sel.day === d.day ? 'tp-sel' : '',
                sel && sel.type === 'item' && d.school ? 'tp-target' : ''].filter(Boolean).join(' ');
            const head = d.school
                ? el('button', {'type': 'button', 'class': 'tp-date', 'title': s('grid_addto', d.short),
                    text: d.short.slice(4), 'on': {click: e => {
                        e.stopPropagation();
                        select({type: 'day', day: d.day});
                    }}})
                : el('span', {'class': 'tp-date', text: d.type === 'outside' ? '' : d.short.slice(4)});
            const cell = el('div', {'class': cls, 'data-day': d.day}, [
                el('div', {'class': 'tp-dayhead'}, [head, d.label ? el('span', {'class': 'tp-tag', text: d.label}) : null]),
            ]);
            (byDay[d.day] || []).forEach(it => cell.append(chip(it)));
            (deadlines[d.day] || []).forEach(([it, mode]) => cell.append(chip(it, mode)));
            if (d.school) {
                cell.addEventListener('dragover', e => {
                    e.preventDefault();
                    cell.classList.add('tp-over');
                });
                cell.addEventListener('dragleave', () => cell.classList.remove('tp-over'));
                cell.addEventListener('drop', e => {
                    e.preventDefault();
                    cell.classList.remove('tp-over');
                    let data;
                    try {
                        data = JSON.parse(e.dataTransfer.getData('text/plain'));
                    } catch (err) {
                        return;
                    }
                    dropOn(data, d.day, dropBefore(e));
                });
                cell.addEventListener('click', () => {
                    if (sel && sel.type === 'item') {
                        dropOn({mode: 'item', id: sel.id}, d.day, 0);
                    }
                });
            }
            grid.append(cell);
        });
    });
    box.replaceChildren(grid);
};

const renderChecks = () => {
    const box = region('checks');
    // Without the lab rules the panel only appears when something is actually wrong.
    box.closest('.card').hidden = !state.labrules && !state.problems.length;
    const head = el('h3', {'class': 'h6 mb-2', text: s('checks')});
    if (!state.problems.length) {
        box.replaceChildren(head, el('div', {'class': 'alert alert-success py-1 px-2 mb-0 small', text: s('checksok')}));
        return;
    }
    const list = el('ul', {'class': 'list-unstyled mb-0'});
    state.problems.forEach(p => list.append(el('li', {}, [el('button', {
        'type': 'button',
        'class': 'tp-problem alert alert-warning py-1 px-2 mb-1 small text-start w-100',
        text: p.message,
        'on': {click: () => select(p.itemid ? {type: 'item', id: p.itemid} : {type: 'week', n: p.week})},
    })])));
    box.replaceChildren(head, list);
};

const dateInput = (value, onchange, label, allowEmpty = false) => el('input', {
    'type': 'date', 'class': 'form-control form-control-sm', 'value': value || '', 'aria-label': label,
    'min': state.term.start, 'max': state.term.end,
    'on': {change: e => {
        if (e.target.value || allowEmpty) {
            onchange(e.target.value);
        }
    }},
});

const levelSelect = (value, onchange) => {
    const sel = el('select', {'class': 'form-select form-select-sm w-auto', 'aria-label': s('level'),
        'on': {change: e => onchange(parseInt(e.target.value, 10))}});
    [0, 1, 2, 3].forEach(l => sel.append(el('option', {'value': l, 'selected': l === value, text: '→'.repeat(l) || '0'})));
    return sel;
};

const field = (label, control) => el('div', {'class': 'mb-2'}, [el('div', {'class': 'small text-muted', text: label}), control]);

const btn = (text, onclick, cls = 'btn-outline-secondary') => el('button', {'type': 'button', 'class': `btn btn-sm ${cls}`,
    text, 'on': {click: onclick}});

/**
 * Move an item by a number of school days (or to the next week).
 *
 * @param {Object} it
 * @param {Number|String} k number of school days, or 'week'
 */
const shiftItem = (it, k) => {
    let target = null;
    if (k === 'week') {
        const numbered = state.weeks.filter(w => w.number !== null);
        const i = numbered.findIndex(w => w.days.some(d => d.day === it.day));
        const next = numbered[i + 1];
        if (next) {
            const here = numbered[i].days.filter(d => d.school).map(d => d.day);
            const there = next.days.filter(d => d.school).map(d => d.day);
            target = there[Math.min(Math.max(0, here.indexOf(it.day)), there.length - 1)];
        }
    } else {
        const all = schoolDays();
        target = all[all.indexOf(it.day) + k] || null;
    }
    if (target) {
        update([{op: 'move', itemid: it.id, day: target, before: 0}]);
    }
};

const renderItemPanel = (box, it) => {
    const info = dayInfo(it.day);
    const kids = [
        el('div', {'class': 'tp-kind-' + it.kind + ' tp-kindtag',
            text: it.kind === 'text' ? s('textline') : it.kind.toUpperCase()}),
        el('div', {'class': 'fw-bold mb-1', text: fullName(it)}),
        it.section ? el('div', {'class': 'small text-muted mb-2', text: it.section}) : null,
        field(s('day') + (info ? ' · ' + info.short : ''),
            dateInput(it.day, v => update([{op: 'move', itemid: it.id, day: v, before: 0}]), s('day'))),
        el('div', {'class': 'd-flex flex-wrap gap-1 mb-2'}, [
            btn(s('grid_shiftearlier'), () => shiftItem(it, -1)),
            btn(s('grid_shiftlater'), () => shiftItem(it, 1)),
            btn(s('grid_nextweek'), () => shiftItem(it, 'week')),
        ]),
        field(s('level'), levelSelect(it.level, v => update([{op: 'set', itemid: it.id, field: 'level', value: String(v)}]))),
    ];
    if (it.kind === 'text') {
        kids.push(field(s('textline'), el('input', {'type': 'text', 'class': 'form-control form-control-sm', 'value': it.text,
            'aria-label': s('textline'),
            'on': {change: e => update([{op: 'set', itemid: it.id, field: 'text', value: e.target.value}])}})));
    }
    if (it.modname === 'assign') {
        const due = dateInput(it.due, v => update([{op: 'set', itemid: it.id, field: 'due', value: v}]), s('dueday'), true);
        // Due days may be any day of the week, not only school days.
        due.removeAttribute('min');
        due.removeAttribute('max');
        kids.push(field(s('dueday'), due));
    }
    if (it.modname === 'quiz') {
        const timeInput = (value, name) => el('input', {'type': 'time', 'class': 'form-control form-control-sm',
            'value': value || '', 'aria-label': s(name === 'opentime' ? 'quizopen' : 'quizclose'),
            'on': {change: e => update([{op: 'set', itemid: it.id, field: name, value: e.target.value}])}});
        kids.push(field(s('quizopen'), timeInput(it.opentime, 'opentime')));
        kids.push(field(s('quizclose'), timeInput(it.closetime, 'closetime')));
    }
    if (it.kind === 'lab' && state.labevents) {
        kids.push(field(s('closesday'), dateInput(it.closes, v => update([{op: 'set', itemid: it.id, field: 'closes', value: v}]),
            s('closesday'), true)));
        kids.push(field(s('checkday'), dateInput(it.check, v => update([{op: 'set', itemid: it.id, field: 'check', value: v}]),
            s('checkday'), true)));
        kids.push(el('div', {'class': 'mb-2'}, [btn(s('resetdeadlines'), () => update([{op: 'reset', itemid: it.id}]))]));
    }
    kids.push(el('div', {'class': 'mt-2'}, [btn(confirmDelete === it.id ? s('grid_confirmdelete') : s('deleteitem'), () => {
        if (confirmDelete === it.id) {
            confirmDelete = 0;
            sel = null;
            update([{op: 'delete', itemid: it.id}]);
        } else {
            confirmDelete = it.id;
            renderDetail();
        }
    }, 'btn-outline-danger')]));
    box.replaceChildren(...kids.filter(Boolean));
};

const renderDayPanel = (box, day) => {
    const info = dayInfo(day);
    const actSel = el('select', {'class': 'form-select form-select-sm mb-1', 'aria-label': s('grid_activity')});
    actSel.append(el('option', {'value': 0, text: s('grid_activity') + '…'}));
    const groups = {};
    state.activities.forEach(a => (groups[a.section] = groups[a.section] || []).push(a));
    Object.entries(groups).forEach(([section, list]) => {
        const g = el('optgroup', {'label': section});
        list.forEach(a => g.append(el('option', {'value': a.cmid, text: a.name})));
        actSel.append(g);
    });
    const text = el('input', {'type': 'text', 'class': 'form-control form-control-sm mb-1', 'placeholder': s('grid_ortext'),
        'aria-label': s('grid_ortext')});
    let level = 0;
    const n = el('input', {'type': 'number', 'class': 'form-control form-control-sm d-inline-block tp-n', 'value': 1, 'min': 1,
        'max': 30, 'aria-label': s('grid_shiftdays')});
    const shift = sign => update([{op: 'shift', day, n: sign * Math.max(1, parseInt(n.value, 10) || 1)}]);
    box.replaceChildren(
        el('div', {'class': 'fw-bold mb-2', text: s('grid_addto', info ? info.short : day)}),
        actSel, text,
        el('div', {'class': 'd-flex gap-2 align-items-center mb-3'}, [
            levelSelect(0, v => {
                level = v;
            }),
            btn(s('additem'), () => {
                const ops = [];
                const cmid = parseInt(actSel.value, 10);
                if (cmid) {
                    ops.push({op: 'add', day, cmid, level});
                }
                if (text.value.trim()) {
                    ops.push({op: 'add', day, text: text.value.trim(), level});
                }
                if (ops.length) {
                    update(ops);
                }
            }, 'btn-primary'),
        ]),
        el('div', {'class': 'small text-muted mb-1', text: s('grid_shift')}),
        el('div', {'class': 'd-flex gap-1 align-items-center flex-wrap'}, [
            n, el('span', {'class': 'small', text: s('grid_shiftdays')}),
            btn(s('grid_earlier'), () => shift(-1)), btn(s('grid_later'), () => shift(1)),
        ]),
    );
};

const renderDetail = () => {
    const box = region('detail');
    if (sel && sel.type === 'item' && item(sel.id)) {
        renderItemPanel(box, item(sel.id));
    } else if (sel && sel.type === 'day') {
        renderDayPanel(box, sel.day);
    } else if (sel && sel.type === 'week') {
        const html = state.previews[sel.n];
        const preview = el('div', {'class': 'tp-preview small'});
        if (html) {
            // Built on the server from escaped text.
            preview.innerHTML = html;
        } else {
            preview.textContent = s('grid_noweekitems');
        }
        box.replaceChildren(el('div', {'class': 'fw-bold mb-1', text: `${state.prefix} W${sel.n} · ${s('preview')}`}), preview);
    } else {
        // Nothing selected: no panel (the hint is above the grid already).
        box.replaceChildren();
    }
    box.closest('.card').hidden = !sel;
};

const render = () => {
    renderGrid();
    renderChecks();
    renderDetail();
    // With neither checks nor a selection to show, the grid takes the full width.
    const side = root.querySelector('.tp-side');
    const empty = [...side.querySelectorAll('.card')].every(c => c.hidden);
    side.hidden = empty;
    root.querySelector('.tp-layout').classList.toggle('tp-noside', empty);
    region('pending').textContent = state.pending ? state.pending : '';
};

const select = what => {
    const same = sel && what && sel.type === what.type && (sel.id === what.id && sel.day === what.day && sel.n === what.n);
    sel = same ? null : what;
    confirmDelete = 0;
    render();
    if (sel && sel.type === 'week') {
        const w = root.querySelector('#tp-week' + sel.n);
        if (w) {
            w.scrollIntoView({block: 'nearest'});
        }
    }
};

/**
 * Start the grid.
 *
 * @param {String} rootid id of the grid's container
 */
export const init = rootid => {
    root = document.getElementById(rootid);
    planid = parseInt(root.dataset.planid, 10);
    str = JSON.parse(root.dataset.strings);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && sel) {
            select(null);
        }
    });
    call('local_termplanner_get_state', {planid});
};
