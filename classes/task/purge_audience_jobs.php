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

use local_awareness\persistent\audience_job;

/**
 * Scheduled task that discards spent audience-estimate jobs.
 *
 * Every click of "Calculate reach" in the notice editor writes a row to
 * local_awareness_audience_jobs, and nothing ever removed one. The table therefore grew
 * without bound, carrying a userid, the criteria JSON and timestamps for as long as the
 * site lived.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_audience_jobs extends \core\task\scheduled_task {
    /**
     * How long a job is kept, in seconds.
     *
     * A completed job stops being reusable after audience_job::DEDUP_WINDOW (5 minutes) and the
     * editor's poller gives up long before that, so a day is already generous. It is deliberately
     * not tighter: a job whose ad-hoc task is merely queued behind a slow cron must still be there
     * when the task finally runs.
     */
    const RETENTION = DAYSECS;

    /**
     * Name shown in the scheduled tasks admin screen.
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string('task_purge_audience_jobs', 'local_awareness');
    }

    /**
     * Delete every audience-estimate job older than the retention window.
     *
     * @return void
     * @throws \dml_exception
     */
    public function execute() {
        global $DB;

        $cutoff = time() - self::RETENTION;

        // Deleted by timecreated, not timecompleted: a job that errored or was never picked up by
        // its ad-hoc task has no completion time and would otherwise be kept for ever.
        $count = $DB->count_records_select(
            audience_job::TABLE,
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        if ($count === 0) {
            return;
        }

        $DB->delete_records_select(
            audience_job::TABLE,
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        mtrace("local_awareness: purged {$count} audience-estimate job(s) older than " . self::RETENTION . 's');
    }
}
