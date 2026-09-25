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
 * One answer drives two behaviours that must not be separated: below the limit the editor
 * re-estimates as the author edits and the web service resolves the job during the request; above
 * it the estimate runs only when asked for, as an adhoc task. Split, the editor could auto-fire a
 * job it then has to wait on cron for.
 *
 * The estimate reads one row per user, so the user count is the cost proxy. It is an imperfect one
 * (a small site with many rules can cost more than a large site filtered by one cohort), which is
 * why the limit is a setting and not a constant.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class live_mode {
    /**
     * Default user count up to which the estimate stays interactive.
     *
     * Deliberately low, so the editor never fires an estimate on its own on a large site; a site
     * where the estimate is cheap can raise the setting.
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
     * Counting touches one entry per user: cheap on the sites that pass the limit, costly on the
     * large sites that fail it, where it would otherwise be paid on every estimate to reach the same
     * "too large" answer. The time-to-live is set in db/caches.php; a site does not change size
     * within it enough to change this decision.
     *
     * Counted with deleted = 0 alone, which is wider than the population the estimate counts (that
     * also drops suspended, unconfirmed and guest users). Deliberate: the cost being predicted is
     * the rows the estimate reads, and it reads them before the narrower conditions apply.
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
