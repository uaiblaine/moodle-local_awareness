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

    /**
     * How many times a user clicked each link in a notice.
     *
     * Only tests call it, on purpose; do not delete it as dead code. Nothing the plugin ships shows a
     * per-user click count (link history reaches reports through the link_history datasource), but
     * purge_link_history_test::test_two_clicks_on_one_link_are_two_clicks() uses it to pin that two
     * clicks count as two.
     *
     * That is why helper::track_link() has no rate limit: a throttle of any window would record a
     * reader who clicked twice as one who clicked once, and this would stop being a click count.
     *
     * @param int $userid user ID.
     * @param int $noticeid notice ID.
     * @param int $linkid Link id, or 0 for every link of the notice.
     *
     * @return array Records keyed by link id, each with hlinkid, text, link and clickcount.
     */
    public static function count_clicked_links(int $userid, int $noticeid, int $linkid = 0) {
        global $DB;
        $params = [];
        if ($linkid > 0) {
            $wheresql = "WHERE h.userid = :userid AND l.noticeid = :noticeid AND h.hlinkid = :hlinkid";
            $params = ['hlinkid' => $linkid];
        } else {
            $wheresql = "WHERE h.userid = :userid AND l.noticeid = :noticeid";
        }
        // The aggregate must be aliased: PostgreSQL names an unaliased COUNT() 'count' while
        // MySQL/MariaDB names it 'COUNT(h.hlinkid)', so the consumer's property only exists on one.
        $sql = "SELECT h.hlinkid, l.text, l.link, COUNT(h.hlinkid) AS clickcount
                  FROM {local_awareness_hlinks_his} h
                  JOIN {local_awareness_hlinks} l on h.hlinkid = l.id
                  $wheresql
              GROUP BY h.hlinkid, l.text, l.link";

        $params = array_merge($params, ['userid' => $userid, 'noticeid' => $noticeid]);
        return $DB->get_records_sql($sql, $params);
    }
}
