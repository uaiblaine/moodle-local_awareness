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

namespace local_awareness\persistent;

use core\persistent;

/**
 * One click by a user on a tracked link of a notice.
 *
 * A repeated click is a row of its own; helper::track_link() says why it is not throttled.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linkhistory extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_awareness_hlinks_his';

    /**
     * Returns a list of properties.
     * @return array[]
     */
    protected static function define_properties() {
        return [
            'hlinkid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'userid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
        ];
    }

    /**
     * Delete history of the links
     *
     * @param array $linkids array of link ids
     */
    public static function delete_link_history(array $linkids) {
        global $DB;
        if (!empty($linkids)) {
            [$linkidssql, $param] = $DB->get_in_or_equal($linkids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(static::TABLE, " hlinkid $linkidssql", $param);
        }
    }
}
