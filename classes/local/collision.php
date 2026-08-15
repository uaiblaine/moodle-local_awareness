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
 * Repeating notices competing for the same pages.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_awareness\local;

use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * Finds repeating notices that would compete with each other for the same pages.
 *
 * Notices are shown one at a time, and a repeating notice keeps coming back. Two of them aimed at
 * the same pages therefore take turns interrupting the same people indefinitely, which is rarely
 * what the author meant and is invisible while editing either one on its own. Nothing here blocks
 * anything: it exists so the author is told.
 *
 * Only the PAGE REACH is compared, not the audience. Two notices aimed at the same pages but at
 * disjoint cohorts never actually meet, so this over-reports — deliberately, because the alternative
 * is computing audience overlap while someone types, and a warning that is occasionally unnecessary
 * costs less than one that is occasionally absent.
 */
class collision {
    /**
     * Whether two page-reach patterns can fire on the same page.
     *
     * Exact overlap of two patterns is not decidable in general, so this answers the cases that
     * occur in practice and errs towards saying yes:
     *
     * - an empty pattern, or one made only of wildcards, places no restriction at all;
     * - identical patterns, ignoring case;
     * - the FRONTPAGE / MY / MYCOURSES tokens, whose overlap is invisible in the strings and is
     *   settled by asking the display path's own matcher about each landmark page;
     * - a wildcard pattern against a page the other pattern certainly reaches.
     *
     * Two unrelated literal paths are reported as not overlapping, which is right, and two exotic
     * wildcards that meet only on a page neither obviously names may be missed.
     *
     * @param string|null $a First pathmatch pattern.
     * @param string|null $b Second pathmatch pattern.
     * @return bool
     * @throws \coding_exception
     */
    public static function pathmatch_overlaps(?string $a, ?string $b): bool {
        $a = trim((string) $a);
        $b = trim((string) $b);

        // No restriction on either side means it reaches wherever the other one does.
        if ($a === '' || $b === '' || trim($a, '%') === '' || trim($b, '%') === '') {
            return true;
        }

        if (strcasecmp($a, $b) === 0) {
            return true;
        }

        /*
         * Judged by check_path_match() rather than by comparing strings, so this cannot drift from
         * what the display path actually does with the same patterns.
         */
        foreach (['/', '/my/', '/my/courses.php'] as $landmark) {
            if (helper::check_path_match($a, $landmark) && helper::check_path_match($b, $landmark)) {
                return true;
            }
        }

        // Only meaningful for wildcards: does one pattern reach a page the other certainly reaches?
        if (strpos($a, '%') !== false && helper::check_path_match($a, str_replace('%', '', $b))) {
            return true;
        }
        if (strpos($b, '%') !== false && helper::check_path_match($b, str_replace('%', '', $a))) {
            return true;
        }

        return false;
    }

    /**
     * Enabled repeating notices, other than the one given, whose page reach overlaps it.
     *
     * Returns nothing when the notice itself does not repeat: a notice shown once takes its turn
     * and leaves, so it competes with nobody.
     *
     * @param int $noticeid Id of the notice being checked; 0 while it is still being created.
     * @param string|null $pathmatch Its page reach.
     * @param int $resetinterval Its repeat interval; zero means it does not repeat.
     * @return awareness[] Clashing notices, keyed by id.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function clashes_for(int $noticeid, ?string $pathmatch, int $resetinterval): array {
        if ($resetinterval <= 0) {
            return [];
        }

        $clashes = [];
        foreach (self::enabled_repeating_notices() as $other) {
            if ((int) $other->get('id') === $noticeid) {
                continue;
            }
            if (self::pathmatch_overlaps($pathmatch, $other->get('pathmatch'))) {
                $clashes[(int) $other->get('id')] = $other;
            }
        }

        return $clashes;
    }

    /**
     * Which of the given notices clash, resolved in one pass for a whole listing.
     *
     * @param awareness[] $notices Notices being listed.
     * @return array Map of notice id to the titles it clashes with; ids with no clash are absent.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function clash_titles_for(array $notices): array {
        $repeating = self::enabled_repeating_notices();
        if (count($repeating) < 2) {
            return [];
        }

        $map = [];
        foreach ($notices as $notice) {
            if ($notice->get('resetinterval') <= 0 || !$notice->get('enabled')) {
                continue;
            }
            $titles = [];
            foreach ($repeating as $other) {
                if ((int) $other->get('id') === (int) $notice->get('id')) {
                    continue;
                }
                if (self::pathmatch_overlaps($notice->get('pathmatch'), $other->get('pathmatch'))) {
                    $titles[] = $other->get('title');
                }
            }
            if (!empty($titles)) {
                $map[(int) $notice->get('id')] = $titles;
            }
        }

        return $map;
    }

    /**
     * The ids of every notice that competes with at least one other.
     *
     * Whether two notices clash is decided by comparing page-reach patterns through
     * check_path_match(), which no database can be asked to do — so the "competing" filter on the
     * manage list resolves the set here, once, and the list narrows with `id IN (...)`. Bounded by
     * the number of enabled repeating notices, and it keeps the predicate inside the SQL, which is
     * what keeps pagination honest: filtering after the query would fetch a page of 25 and show 9.
     *
     * Shares enabled_repeating_notices() and pathmatch_overlaps() with clash_titles_for(), so the
     * filter and the badge cannot disagree about what a clash is.
     *
     * @return array List of notice ids, empty when nothing competes.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function clashing_ids(): array {
        $repeating = self::enabled_repeating_notices();
        if (count($repeating) < 2) {
            return [];
        }

        $ids = [];
        foreach ($repeating as $notice) {
            foreach ($repeating as $other) {
                if ((int) $other->get('id') === (int) $notice->get('id')) {
                    continue;
                }
                if (self::pathmatch_overlaps($notice->get('pathmatch'), $other->get('pathmatch'))) {
                    $ids[] = (int) $notice->get('id');
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Every enabled notice that repeats.
     *
     * Read straight from the table rather than through the enabled-notices cache, which also
     * applies the scheduling window: a notice scheduled for next week still competes for the same
     * pages, and the author needs to be told before it starts rather than after.
     *
     * @return awareness[] Keyed by id.
     * @throws \dml_exception
     */
    private static function enabled_repeating_notices(): array {
        return awareness::get_records_select('enabled = ? AND resetinterval > ?', [1, 0], 'id');
    }
}
