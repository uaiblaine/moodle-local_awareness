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
 * Notice view class.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class noticeview extends persistent {
    /** Table name for the persistent. */
    const TABLE = 'local_awareness_lastview';

    /**
     * Returns a list of properties.
     *
     * @return array[]
     */
    protected static function define_properties() {
        return [
            'userid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'noticeid' => [
                'type' => PARAM_INT,
                'null' => NULL_NOT_ALLOWED,
            ],
            'action' => [
                'type' => PARAM_RAW_TRIMMED,
                'null' => NULL_NOT_ALLOWED,
            ],
        ];
    }

    /**
     * Get cache instance.
     *
     * @return \cache
     */
    protected static function get_cache(): \cache {
        return \cache::make('local_awareness', 'notice_view');
    }

    /**
     * Purge related caches.
     *
     * @param string $key Cache key.
     */
    protected function purge_cache(string $key): void {
        self::get_cache()->delete($key);
    }

    /**
     * Forget a user's cached view records.
     *
     * The cache is MODE_APPLICATION and keyed by user id, so rows deleted outside the persistent
     * — the privacy erasure path does bulk deletes — leave the cached copy behind and the user's
     * viewing history survives their own erasure request.
     *
     * @param int $userid User id.
     */
    public static function purge_user_cache(int $userid): void {
        self::get_cache()->delete((string) $userid);
    }

    /**
     * Run after update.
     *
     * @param bool $result Result of update.
     */
    protected function after_update($result) {
        if ($result) {
            self::purge_cache($this->get('userid'));
        }
    }

    /**
     * Run after created.
     */
    protected function after_create() {
        self::purge_cache($this->get('userid'));
    }

    /**
     * Run after deleted.
     *
     * @param bool $result Result of delete.
     */
    protected function after_delete($result) {
        if ($result) {
            self::purge_cache($this->get('userid'));
        }
    }

    /**
     * Record the latest user interaction with the notice.
     *
     * @param int $noticeid notice id
     * @param int $userid user id
     * @param int $action user interaction
     *
     * @return persistent|false|noticeview
     */
    public static function add_notice_view(int $noticeid, int $userid, int $action) {
        $persistent = self::get_record(['noticeid' => $noticeid, 'userid' => $userid]);
        if (!empty($persistent)) {
            $persistent->set('action', $action);
            $persistent->update();
        } else {
            $data = new \stdClass();
            $data->noticeid = $noticeid;
            $data->userid = $userid;
            $data->action = $action;
            $persistent = new self(0, $data);
            $persistent = $persistent->create();
        }
        return $persistent;
    }


    /**
     * Delete views related to a notice.
     *
     * @param int $noticeid notice id
     */
    public static function delete_notice_view(int $noticeid) {
        global $DB;
        $DB->delete_records(static::TABLE, ['noticeid' => $noticeid]);
    }

    /**
     * Get all viewed notices of a user.
     * @return array
     */
    public static function get_user_viewed_notice_records(): array {
        global $USER, $DB;

        // Compared against false for the same reason as awareness::get_enabled_notices(): a user who
        // has never acted on a notice has an empty history, and a falsy test re-reads it every time.
        if (($result = self::get_cache()->get($USER->id)) === false) {
            $result = [];
            $sql = "SELECT sn.id, lv.timecreated, lv.action, lv.timemodified
                      FROM {local_awareness} sn
                      JOIN {local_awareness_lastview} lv ON sn.id = lv.noticeid
                     WHERE lv.userid = :userid AND sn.enabled = 1 AND sn.reqcourse = 0";
            $params = ['userid' => $USER->id];
            $records = $DB->get_records_sql($sql, $params);

            if (!empty($records)) {
                $result = $records;
            }

            self::get_cache()->set($USER->id, $result);
        }

        return $result;
    }
}
