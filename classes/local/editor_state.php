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
 * Reasons a notice that calls itself published can never actually be displayed.
 *
 * The editor's page head paints a "Live · being shown" badge from the enabled flag alone, which is
 * true about the flag and can be false about the world. A notice whose expiry date has passed, or
 * whose dates cannot both be satisfied, is enabled and unreachable at the same time — and nothing
 * on the page said so.
 *
 * These are the conditions the form itself cannot express, because each is a relationship BETWEEN
 * fields rather than a property of one. A required-field rule catches an empty title; nothing
 * catches a start date that falls after the expiry date.
 *
 * Deliberately pure: no $DB, no get_string, and $now passed in. The caller turns these keys into
 * sentences, and a test can pin every boundary without moving the clock.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class editor_state {
    /** The start date falls on or after the expiry date, so no instant satisfies the window. */
    public const WINDOW_INVERTED = 'window_inverted';

    /** The expiry date has passed. */
    public const WINDOW_EXPIRED = 'window_expired';

    /**
     * Why an enabled notice will never be shown to anybody.
     *
     * Mirrors window::is_open() exactly — a zero bound is unbounded on that side — and enumerates
     * the ways that predicate becomes permanently false. A disabled notice is not broken, it is
     * off, so it yields nothing.
     *
     * @param int $enabled 1 when the notice is published.
     * @param int $timestart Start of the display window, 0 for none.
     * @param int $timeend End of the display window, 0 for none.
     * @param int $now The instant to judge against.
     * @return array Zero or more of the WINDOW_* constants.
     */
    public static function window_problems(int $enabled, int $timestart, int $timeend, int $now): array {
        if ($enabled !== 1) {
            return [];
        }

        /*
         * No expiry is no longer a problem. window treats a zero bound as unbounded, so a notice
         * with a start and no end runs from that start onwards — which is what the author meant.
         * The WINDOW_OPEN_ENDED warning that used to live here existed only because the old
         * predicate compared now against a timeend of 0 and was false for every instant after 1970;
         * its cause is gone, so the warning went with it.
         *
         * Inversion and expiry are only meaningful against a real end. Testing timestart >= timeend
         * with timeend zero would call every open-ended notice inverted.
         */
        if ($timeend === 0) {
            return [];
        }

        // Inverted, including the equal case: the window is [start, end), so start == end is empty.
        if ($timestart >= $timeend) {
            return [self::WINDOW_INVERTED];
        }

        if (window::has_ended($timeend, $now)) {
            return [self::WINDOW_EXPIRED];
        }

        return [];
    }
}
