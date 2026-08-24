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
 * Links history class.
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
     * NO PRODUCTION CALLER, AND THAT IS DELIBERATE — DO NOT DELETE IT AS DEAD CODE.
     *
     * Nothing this plugin ships displays a click count: the two system reports are the acknowledged
     * and dismissed ones, and link history is a report-builder datasource that exists only once an
     * administrator has built a report from it. So every caller is a test, and a dead-code sweep
     * reads that as an unused method.
     *
     * It is the measurement audit finding M7's refusal is pinned against.
     * purge_link_history_test::test_two_clicks_on_one_link_are_two_clicks() calls this through the
     * real write path to assert that two clicks count as two — which is what forbids the rate limit
     * M7 asked for, because a throttle of any window would turn a reader who clicked twice into one
     * who clicked once and stop this being a click count at all. Delete the method and that
     * guarantee goes with it, silently, leaving the suite green.
     *
     * The same shape once nearly cost a sibling plugin its staleness detection: a private method
     * with no resolvable caller that was in fact load-bearing. Grep the bare name before believing
     * any tool that calls this unused.
     *
     * @param int $userid user ID.
     * @param int $noticeid notice ID.
     * @param int $linkid Link id.
     *
     * @return array
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
