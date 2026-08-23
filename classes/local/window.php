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
 * A zero bound is UNBOUNDED on that side — the convention core uses for enrolments and the one
 * audience\estimator already uses in this plugin. Perpetual stops being a special case and becomes
 * simply "neither bound set":
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
 * Three projections rather than one predicate, and NOT merely because SQL cannot run PHP. The query
 * is CACHED: awareness::get_enabled_notices() stores its rows in a MODE_APPLICATION cache with no
 * TTL, purged only by a write to a notice. So the query may carry only conditions that are MONOTONE
 * in time. A condition that turns from true to false is safe, because nothing brings the row back.
 * A condition that turns from false to TRUE is not, because no write happens at that instant and
 * the stale cache never notices.
 *
 * `now < timeend` is monotone, so the query carries it. `now >= timestart` is a transition INTO
 * visibility, so the query must NOT carry it, and therefore returns notices whose start is still in
 * the future. is_open() applies the lower bound against a live clock instead. That asymmetry is the
 * reason the two predicates were allowed to drift apart in the first place, so it is written down
 * here rather than left to be rediscovered.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class window {
    /**
     * Whether the window is open at a given instant. This is the DISPLAY decision.
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
     * The write paths use this alone, deliberately dropping the upper bound: refusing a genuine
     * Accept because the notice expired while the modal was open would lose the very record this
     * plugin exists to keep. See helper::is_notice_available_to_user().
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
     * The half of is_open() a CACHED query is allowed to carry, as SQL.
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

    /**
     * is_open() as SQL, for callers NOT reading through the enabled-notices cache.
     *
     * Two placeholder names for one value, not one name used twice: fix_sql_params() counts
     * placeholder OCCURRENCES against the parameter array and throws duplicateparaminsql when a
     * name appears more often than the array explains.
     *
     * @param string $prefix Unique prefix for this statement's placeholder names.
     * @param int $now The instant to judge against.
     * @param string $alias Table alias including its dot, or an empty string.
     * @return array Two elements: the SQL fragment, and the parameters it names.
     */
    public static function open_sql(string $prefix, int $now, string $alias = ''): array {
        $start = $prefix . 'start';
        $end = $prefix . 'end';
        $sql = "({$alias}timestart = 0 OR {$alias}timestart <= :{$start})"
            . " AND ({$alias}timeend = 0 OR {$alias}timeend > :{$end})";

        return [$sql, [$start => $now, $end => $now]];
    }
}
