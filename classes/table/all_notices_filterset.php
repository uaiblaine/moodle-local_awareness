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
 * Every filter is optional: the unfiltered list is the page's normal state, and an absent courseid
 * means the site list.
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
     * values are questions asked of several columns at once, not stored values, and a word reads
     * better than a number in a URL.
     *
     * @return array Filter name => filter class.
     */
    public function get_optional_filters(): array {
        return [
            'name' => string_filter::class,
            'status' => string_filter::class,
            'validity' => string_filter::class,
            /*
             * The course the list is for, or absent for the site. It travels in the filterset, not
             * as a page parameter, because the dynamic-table web service rebuilds the table from the
             * filterset alone, and the context and capability check are decided from the scope.
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
