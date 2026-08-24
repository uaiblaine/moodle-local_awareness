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
 * local_awareness_hlinks_his gains a row every time a reader follows a link inside a notice, and
 * nothing time-based ever removed one. The only deletions are linkhistory::delete_link_history(),
 * reached when an author edits a link out of a notice or deletes the notice — and that second path
 * sits behind the cleanup_deleted_notice setting, which ships off — plus privacy erasure, which is
 * per user and only on request. A site therefore kept every click for its whole life.
 *
 * This is the retention half of audit finding M7. The OTHER half of that finding — a reader
 * inflating their own count by posting to the web service in a loop — is deliberately not
 * addressed, and not because it is hard: repeat clicks are the reported quantity. Every throttle
 * considered would have collapsed a genuine second click into the first, which is a worse outcome
 * than the one it prevents.
 *
 * Modelled on logstore_standard's cleanup_task, including its default: zero means keep everything,
 * so an upgrade never silently discards a site's existing history.
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
            // Zero is "keep for ever", which is the shipped default and core's own for logs.
            return;
        }

        $cutoff = time() - ($lifetime * DAYSECS);
        $started = time();

        /*
         * A day at a time rather than one statement. The span can be years the first time an admin
         * sets a lifetime, and a single DELETE over millions of rows holds locks for minutes. Same
         * shape as logstore_standard's cleanup_task, including the runtime ceiling: what this run
         * does not reach, the next run does.
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
