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

namespace local_awareness\local;

/**
 * A notice's scheduling window: one truth table, three projections.
 *
 * A zero bound is unbounded on that side, the convention core uses for enrolments and the one
 * audience\estimator uses. A perpetual notice is simply "neither bound set":
 *
 *   timestart | timeend | open when
 *   ----------+---------+-----------------
 *       0     |    0    | always
 *       0     |    Y    | now <  Y
 *       X     |    0    | now >= X
 *       X     |    Y    | X <= now < Y
 *
 * The window is half-open, so at exactly timeend the notice is closed: that instant is the one the
 * author asked it to stop at, and a notice outliving its own expiry is the harder thing to explain.
 *
 * Three projections rather than one predicate, and not merely because SQL cannot run PHP. The query
 * is cached: awareness::get_enabled_notices() stores its rows in a MODE_APPLICATION cache with no
 * TTL, purged only by a write to a notice. So the query may carry only conditions that are monotone
 * in time. A condition that turns from true to false is safe, because nothing brings the row back.
 * A condition that turns from false to true is not, because no write happens at that instant and
 * the stale cache never notices.
 *
 * `now < timeend` is monotone, so the query carries it. `now >= timestart` is a transition into
 * visibility, so the query must not carry it, and therefore returns notices whose start is still in
 * the future. is_open() applies the lower bound against a live clock instead.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class window {
    /**
     * Whether the window is open at a given instant: the display decision.
     *
     * @param int $timestart Start of the window, 0 for unbounded.
     * @param int $timeend End of the window, 0 for unbounded.
     * @param int $now The instant to judge against.
     * @return bool
     */
    public static function is_open(int $timestart, int $timeend, int $now): bool {
        return self::has_started($timestart, $now) && !self::has_ended($timeend, $now);
    }

    /**
     * Whether the lower bound has been reached.
     *
     * The write paths use this alone, without the upper bound, for the reason given in
     * {@see \local_awareness\helper::is_notice_available_to_user()}.
     *
     * @param int $timestart Start of the window, 0 for unbounded.
     * @param int $now The instant to judge against.
     * @return bool
     */
    public static function has_started(int $timestart, int $now): bool {
        return $timestart === 0 || $now >= $timestart;
    }

    /**
     * Whether the upper bound has passed. Half-open, so timeend itself is already closed.
     *
     * @param int $timeend End of the window, 0 for unbounded.
     * @param int $now The instant to judge against.
     * @return bool
     */
    public static function has_ended(int $timeend, int $now): bool {
        return $timeend !== 0 && $now >= $timeend;
    }

    /**
     * The half of is_open() a cached query is allowed to carry, as SQL.
     *
     * A superset of is_open(), never an equivalent: it omits the lower bound for the reason in the
     * class docblock. Callers must still run is_open() on what comes back, and window_test pins
     * that superset relation over every shape.
     *
     * @param string $prefix Unique prefix for this statement's placeholder names.
     * @param int $now The instant to judge against.
     * @param string $alias Table alias including its dot, or an empty string.
     * @return array Two elements: the SQL fragment, and the parameters it names.
     */
    public static function open_prefilter_sql(string $prefix, int $now, string $alias = ''): array {
        $end = $prefix . 'end';

        return ["({$alias}timeend = 0 OR {$alias}timeend > :{$end})", [$end => $now]];
    }
}
