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
 * School calendar: terms, their special days, and the school weeks they make.
 *
 * @package    local_termplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_termplanner\local;

use stdClass;

/**
 * Works out school days and numbered school weeks for a term.
 *
 * Days are handled as 'YYYY-MM-DD' strings throughout, so no timezone can shift them.
 * A week runs Monday to Friday. Weeks without a single school day (a break) get no number.
 */
class schoolcal {
    /** @var string[] Special day types. */
    public const TYPES = ['noschool', 'early', 'finals'];

    /** @var stdClass The term record. */
    public stdClass $term;

    /** @var stdClass[] Special days keyed by YYYY-MM-DD. */
    public array $special = [];

    /** @var array|null Cached weeks. */
    protected ?array $weeks = null;

    /**
     * Constructor.
     *
     * @param stdClass $term term record
     * @param stdClass[] $special special day records (day, type, label)
     */
    public function __construct(stdClass $term, array $special) {
        $this->term = $term;
        foreach ($special as $s) {
            $this->special[$s->day] = $s;
        }
    }

    /**
     * Load a term and its special days.
     *
     * @param int $termid
     * @return self
     */
    public static function load(int $termid): self {
        global $DB;
        $term = $DB->get_record('local_termplanner_term', ['id' => $termid], '*', MUST_EXIST);
        return new self($term, $DB->get_records('local_termplanner_day', ['termid' => $termid], 'day'));
    }

    /**
     * Add a number of calendar days to a day.
     *
     * @param string $day YYYY-MM-DD
     * @param int $n days to add (may be negative)
     * @return string YYYY-MM-DD
     */
    public static function add_days(string $day, int $n): string {
        $d = new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC'));
        return $d->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    /**
     * ISO weekday, 1 = Monday ... 7 = Sunday.
     *
     * @param string $day YYYY-MM-DD
     * @return int
     */
    public static function weekday(string $day): int {
        return (int)(new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC')))->format('N');
    }

    /**
     * Whether a string is a valid YYYY-MM-DD day.
     *
     * @param string $day
     * @return bool
     */
    public static function valid_day(string $day): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Short label for a day, e.g. "Wed 12/9".
     *
     * @param string $day YYYY-MM-DD
     * @return string
     */
    public static function short(string $day): string {
        $d = new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC'));
        $names = [1 => 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        return get_string('day_' . $names[(int)$d->format('N')], 'local_termplanner') . ' ' . $d->format('n/j');
    }

    /**
     * Whether students have class on this day (early release counts; finals do not).
     *
     * @param string $day YYYY-MM-DD
     * @return bool
     */
    public function is_school_day(string $day): bool {
        if ($day < $this->term->startday || $day > $this->term->endday || self::weekday($day) > 5) {
            return false;
        }
        $s = $this->special[$day] ?? null;
        return !$s || $s->type === 'early';
    }

    /**
     * The numbered school weeks of the term, with breaks in between.
     *
     * Each entry: number (null for a week without school), monday, days (5 weekday entries with
     * day, school, type, label) and schooldays (list of YYYY-MM-DD).
     *
     * @return array[]
     */
    public function weeks(): array {
        if ($this->weeks !== null) {
            return $this->weeks;
        }
        $monday = self::add_days($this->term->startday, 1 - self::weekday($this->term->startday));
        $weeks = [];
        $n = 0;
        for ($mon = $monday; $mon <= $this->term->endday; $mon = self::add_days($mon, 7)) {
            $days = [];
            $school = [];
            for ($i = 0; $i < 5; $i++) {
                $day = self::add_days($mon, $i);
                $s = $this->special[$day] ?? null;
                $inside = $day >= $this->term->startday && $day <= $this->term->endday;
                $isschool = $this->is_school_day($day);
                $days[] = [
                    'day' => $day,
                    'inside' => $inside,
                    'school' => $isschool,
                    'type' => $s->type ?? ($inside ? 'school' : 'outside'),
                    'label' => $s->label ?? '',
                ];
                if ($isschool) {
                    $school[] = $day;
                }
            }
            $weeks[] = [
                'number' => $school ? ++$n : null,
                'monday' => $mon,
                'days' => $days,
                'schooldays' => $school,
            ];
        }
        return $this->weeks = $weeks;
    }

    /**
     * The week number a day falls in, or null when it is outside any numbered week.
     *
     * @param string $day YYYY-MM-DD
     * @return int|null
     */
    public function week_of(string $day): ?int {
        foreach ($this->weeks() as $w) {
            if ($day >= $w['monday'] && $day <= self::add_days($w['monday'], 4)) {
                return $w['number'];
            }
        }
        return null;
    }

    /**
     * The school days of a numbered week.
     *
     * @param int $number
     * @return string[]
     */
    public function school_days(int $number): array {
        foreach ($this->weeks() as $w) {
            if ($w['number'] === $number) {
                return $w['schooldays'];
            }
        }
        return [];
    }

    /**
     * All school days of the term, in order.
     *
     * @return string[]
     */
    public function all_school_days(): array {
        $all = [];
        foreach ($this->weeks() as $w) {
            $all = array_merge($all, $w['schooldays']);
        }
        return $all;
    }

    /**
     * Move a day by a number of school days (skipping weekends and days without school).
     *
     * @param string $day YYYY-MM-DD
     * @param int $n school days to move, negative for earlier
     * @return string|null the new day, or null when it would leave the term
     */
    public function shift(string $day, int $n): ?string {
        $all = $this->all_school_days();
        $pos = array_search($day, $all, true);
        if ($pos === false) {
            // Start from the next school day after a non-school day.
            $pos = count(array_filter($all, fn($d) => $d < $day)) - ($n > 0 ? 1 : 0);
        }
        $new = $pos + $n;
        return ($new >= 0 && $new < count($all)) ? $all[$new] : null;
    }

    /**
     * Parse special days typed one per line: "2026-12-23..2027-01-01 | noschool | Winter break".
     *
     * Ranges include both ends; weekends in a range are skipped.
     *
     * @param string $text
     * @return array [stdClass[] days keyed by day, string[] errors]
     */
    public static function parse_special(string $text): array {
        $days = [];
        $errors = [];
        foreach (preg_split('/\R/', $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $range = explode('..', $parts[0]);
            $from = trim($range[0]);
            $to = trim($range[1] ?? $from);
            $type = \core_text::strtolower($parts[1] ?? 'noschool');
            if (!self::valid_day($from) || !self::valid_day($to) || $to < $from || !in_array($type, self::TYPES, true)) {
                $errors[] = get_string('specialdayerror', 'local_termplanner', ['line' => $i + 1, 'text' => s($line)]);
                continue;
            }
            for ($d = $from; $d <= $to; $d = self::add_days($d, 1)) {
                if (self::weekday($d) <= 5) {
                    $days[$d] = (object)['day' => $d, 'type' => $type, 'label' => $parts[2] ?? ''];
                }
            }
        }
        ksort($days);
        return [$days, $errors];
    }

    /**
     * Turn special days back into editable text, joining runs of the same type and label.
     *
     * @param stdClass[] $special day records
     * @return string
     */
    public static function format_special(array $special): string {
        $lines = [];
        $run = null;
        foreach ($special as $s) {
            $next = $run ? self::add_days($run->to, self::weekday($run->to) == 5 ? 3 : 1) : null;
            if ($run && $s->day === $next && $s->type === $run->type && (string)$s->label === (string)$run->label) {
                $run->to = $s->day;
                continue;
            }
            if ($run) {
                $lines[] = $run;
            }
            $run = (object)['from' => $s->day, 'to' => $s->day, 'type' => $s->type, 'label' => (string)$s->label];
        }
        if ($run) {
            $lines[] = $run;
        }
        return implode("\n", array_map(fn($r) => ($r->from === $r->to ? $r->from : $r->from . '..' . $r->to)
            . ' | ' . $r->type . ($r->label !== '' ? ' | ' . $r->label : ''), $lines));
    }
}
