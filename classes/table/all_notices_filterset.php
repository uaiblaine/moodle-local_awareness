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

namespace local_awareness\table;

use core_table\local\filter\filterset;
use core_table\local\filter\integer_filter;
use core_table\local\filter\string_filter;

/**
 * Filters accepted by the notice list.
 *
 * All three are optional: the unfiltered list is the page's normal state, and the web service
 * rejects an absent required filter outright.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class all_notices_filterset extends filterset {
    /** @var string Notices that are enabled. */
    public const STATUS_LIVE = 'live';

    /** @var string Notices that are saved but switched off. */
    public const STATUS_DRAFT = 'draft';

    /** @var string Notices competing with another repeating notice for the same pages. */
    public const STATUS_CLASH = 'clash';

    /** @var string No start and no end date. */
    public const VALIDITY_PERMANENT = 'permanent';

    /** @var string Inside its window right now. */
    public const VALIDITY_CURRENT = 'current';

    /** @var string Window starts in the future. */
    public const VALIDITY_SCHEDULED = 'scheduled';

    /** @var string Window has closed. */
    public const VALIDITY_EXPIRED = 'expired';

    /**
     * Filters that must be present.
     *
     * @return array Always empty: every filter on this table is optional.
     */
    public function get_required_filters(): array {
        return [];
    }

    /**
     * Filters the table understands.
     *
     * status and validity are string filters over a fixed vocabulary rather than integers: the
     * values are not stored columns but questions asked of several of them at once, and a word
     * survives being read in a URL or a test far better than a magic number does.
     *
     * @return array Filter name => filter class.
     */
    public function get_optional_filters(): array {
        return [
            'name' => string_filter::class,
            'status' => string_filter::class,
            'validity' => string_filter::class,
            /*
             * The course the list is for, or absent for the site. It travels in the filterset and
             * not as a page parameter because the dynamic-table web service rebuilds the table from
             * the filterset alone: the scope has to be wherever the context and the capability are
             * decided from, or a refresh over AJAX would decide them for a different list.
             */
            'courseid' => integer_filter::class,
        ];
    }

    /**
     * The status values this filterset accepts.
     *
     * @return array List of valid status strings.
     */
    public static function status_values(): array {
        return [self::STATUS_LIVE, self::STATUS_DRAFT, self::STATUS_CLASH];
    }

    /**
     * The validity values this filterset accepts.
     *
     * @return array List of valid validity strings.
     */
    public static function validity_values(): array {
        return [
            self::VALIDITY_PERMANENT,
            self::VALIDITY_CURRENT,
            self::VALIDITY_SCHEDULED,
            self::VALIDITY_EXPIRED,
        ];
    }
}
