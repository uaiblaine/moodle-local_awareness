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
 * Tests for the audience-job retention task.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\task\purge_audience_jobs
 */
final class purge_audience_jobs_test extends \advanced_testcase {
    /**
     * Create a job and backdate it.
     *
     * @param string $jobid Job identifier.
     * @param int $ageseconds How long ago the job was created.
     * @param string $status Job status.
     * @return void
     */
    private function seed_job(string $jobid, int $ageseconds, string $status = audience_job::STATUS_READY): void {
        global $DB;

        $job = new audience_job(0, (object) [
            'jobid' => $jobid,
            'userid' => 2,
            'criteriahash' => sha1($jobid),
            'criteria' => '{}',
            'status' => $status,
        ]);
        $job->create();

        $DB->set_field(audience_job::TABLE, 'timecreated', time() - $ageseconds, ['jobid' => $jobid]);
    }

    /**
     * Jobs past the retention window go; anything inside it stays.
     *
     * The surviving row is the control. Without it a task that simply truncated the table — or one
     * whose WHERE clause was dropped — would pass this test.
     */
    public function test_only_jobs_past_the_retention_window_are_deleted(): void {
        global $DB;

        $this->resetAfterTest();

        $this->seed_job('oldjob', purge_audience_jobs::RETENTION + HOURSECS);
        $this->seed_job('freshjob', MINSECS);

        ob_start();
        (new purge_audience_jobs())->execute();
        $output = (string) ob_get_clean();

        $this->assertFalse($DB->record_exists(audience_job::TABLE, ['jobid' => 'oldjob']));
        $this->assertTrue($DB->record_exists(audience_job::TABLE, ['jobid' => 'freshjob']));
        $this->assertStringContainsString('purged 1', $output);
    }

    /**
     * A job that never completed is still purged once it is old enough.
     *
     * These are the ones that accumulate silently: an ad-hoc task that never ran leaves a pending
     * row with no completion time, so a retention rule keyed on timecompleted would keep it for
     * ever.
     */
    public function test_a_stale_pending_job_is_purged_too(): void {
        global $DB;

        $this->resetAfterTest();

        $this->seed_job('stuckjob', purge_audience_jobs::RETENTION + HOURSECS, audience_job::STATUS_PENDING);

        ob_start();
        (new purge_audience_jobs())->execute();
        ob_get_clean();

        $this->assertFalse($DB->record_exists(audience_job::TABLE, ['jobid' => 'stuckjob']));
    }

    /**
     * With nothing to purge the task is silent, so cron output stays readable.
     */
    public function test_the_task_says_nothing_when_there_is_nothing_to_purge(): void {
        $this->resetAfterTest();

        $this->seed_job('freshjob', MINSECS);

        ob_start();
        (new purge_audience_jobs())->execute();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    /**
     * db/tasks.php declares the task, and its name resolves to a real language string.
     *
     * A scheduled task with no db/tasks.php entry never runs at all, and a missing
     * task_<classname> string makes the admin screen throw.
     *
     * Reads the FILE via load_default_scheduled_tasks_for_component(), deliberately.
     * load_scheduled_tasks_for_component() looks at the {task_scheduled} table instead, which
     * holds whatever was installed when the test site was last built — so it keeps passing after
     * the declaration is deleted from db/tasks.php, which is the regression worth catching.
     */
    public function test_the_task_is_declared_and_named(): void {
        $tasks = \core\task\manager::load_default_scheduled_tasks_for_component('local_awareness');

        $classnames = array_map(static function (\core\task\scheduled_task $task): string {
            return get_class($task);
        }, $tasks);

        $this->assertContains(purge_audience_jobs::class, $classnames);
        $this->assertSame('Purge spent audience estimate jobs', (new purge_audience_jobs())->get_name());
    }
}
