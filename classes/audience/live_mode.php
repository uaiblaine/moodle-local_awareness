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

namespace local_awareness\audience;

/**
 * Decides whether this site is small enough to estimate an audience interactively.
 *
 * One question, two consequences, and they are deliberately not separable: below the limit the
 * editor re-estimates as the author edits AND the web service answers during the request; above it
 * neither happens — the estimate runs only when asked for, in the background. Splitting them would
 * allow the worst combination, an editor that auto-fires a job it then has to wait on cron for.
 *
 * The estimate scans one row per user, so the user count is the cost proxy. It is an imperfect one
 * — a small site with seven rules and twenty competencies can cost more than a large site filtered
 * by one cohort — which is why the limit is a setting and not a constant.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_mode {
    /**
     * Default user count up to which the estimate stays interactive.
     *
     * Sized for the sites this plugin actually runs on: an author on a 200k-user site should never
     * see the editor fire an estimate on its own, and the previous default of 25000 was set from
     * reasoning rather than measurement.
     */
    public const LIMIT_DEFAULT = 1000;

    /**
     * The configured limit, or the default when the setting has never been stored.
     *
     * An unset setting means "not configured", not "disabled": reading it as 0 would turn the
     * interactive path off on any site whose upgrade had not yet applied the default. Only an
     * explicit 0 disables it.
     *
     * @return int Users, or 0 when interactive estimation is switched off entirely.
     */
    public static function limit(): int {
        $stored = get_config('local_awareness', 'audience_sync_limit');
        if ($stored === false || $stored === '') {
            return self::LIMIT_DEFAULT;
        }
        return (int) $stored;
    }

    /**
     * Whether the estimate may run interactively on this site.
     *
     * @return bool
     */
    public static function is_live(): bool {
        $limit = self::limit();
        if ($limit <= 0) {
            // Switched off, so the user count cannot change the answer and is not worth a scan.
            return false;
        }

        return self::user_count() <= $limit;
    }

    /**
     * The site's user count, cached.
     *
     * {user}.deleted carries no index, so this is a full scan — cheap on the sites that pass the
     * limit and emphatically not on the sites that fail it, where it would otherwise be paid on
     * every estimate only to reach the same "too large" answer. The time-to-live lives in
     * db/caches.php, which is what enforces it; a site does not change size within it in any way
     * that matters to this decision.
     *
     * Counted with deleted = 0 alone, which is wider than the population the estimate itself counts
     * (it also drops suspended, unconfirmed and guest users). That is deliberate: the cost being
     * predicted is the number of rows scanned, and the scan reads those rows before any of the
     * narrower conditions apply.
     *
     * @return int
     */
    private static function user_count(): int {
        global $DB;

        $cache = \cache::make('local_awareness', 'site_user_count');
        $count = $cache->get('count');
        if ($count !== false) {
            return (int) $count;
        }

        $count = $DB->count_records_select('user', 'deleted = 0');
        $cache->set('count', $count);

        return (int) $count;
    }

    /**
     * Forget the cached user count.
     *
     * For tests, which create users and then need the very next call to see them.
     *
     * @return void
     */
    public static function reset_cache(): void {
        \cache::make('local_awareness', 'site_user_count')->delete('count');
    }
}
