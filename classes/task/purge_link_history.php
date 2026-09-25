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

namespace local_awareness\task;

use local_awareness\persistent\linkhistory;

/**
 * Scheduled task that discards link-click history past its configured lifetime.
 *
 * local_awareness_hlinks_his gains a row every time a reader follows a link inside a notice. Apart
 * from this task, rows go only through linkhistory::delete_link_history() (a link edited out of a
 * notice, or a deleted notice with cleanup_deleted_notice on) and per-user privacy erasure.
 *
 * Repeat clicks by one reader are not throttled or collapsed: they are the quantity the report
 * counts. The lifetime is the linkhistory_lifetime setting, in days.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_link_history extends \core\task\scheduled_task {
    /** Longest one run may spend deleting, in seconds. */
    const MAX_RUNTIME = 300;

    /**
     * Name shown in the scheduled tasks admin screen.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string('task_purge_link_history', 'local_awareness');
    }

    /**
     * Delete every click record older than the configured lifetime.
     *
     * @return void
     * @throws \dml_exception
     */
    public function execute() {
        global $DB;

        $lifetime = (int) get_config('local_awareness', 'linkhistory_lifetime');
        if ($lifetime <= 0) {
            // Zero, the default, keeps everything; see settings.php.
            return;
        }

        $cutoff = time() - ($lifetime * DAYSECS);
        $started = time();

        /*
         * A day at a time rather than one statement: the span can be years the first time a
         * lifetime is set, and a single DELETE over that many rows holds locks for a long time.
         * Same shape as logstore_standard's cleanup_task, including the runtime ceiling: what this
         * run does not reach, the next run does.
         */
        while (
            $oldest = $DB->get_field_select(
                linkhistory::TABLE,
                'MIN(timecreated)',
                'timecreated < :cutoff',
                ['cutoff' => $cutoff]
            )
        ) {
            $batch = min($cutoff, (int) $oldest + DAYSECS);
            $DB->delete_records_select(
                linkhistory::TABLE,
                'timecreated < :batch',
                ['batch' => $batch]
            );

            if (time() > $started + self::MAX_RUNTIME) {
                break;
            }
        }
    }
}
