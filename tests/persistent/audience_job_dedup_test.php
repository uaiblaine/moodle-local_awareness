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

/**
 * The dedup predicates on audience_job::find_reusable(), one negative control each.
 *
 * find_reusable() answers "has this exact question already been answered, recently enough to hand
 * back". Three conditions carry that: the criteria hash, the READY status, and a timecompleted
 * inside DEDUP_WINDOW. Only the hash had coverage — the other two could each be deleted with the
 * whole suite green, and deleting either is a real defect: without the status test a job that
 * ERRORED is served as an answer, and without the window test a count from any point in the site's
 * history is served as current.
 *
 * Driven directly rather than through external::estimate_audience() on purpose. The end-to-end path
 * runs through live_mode, cohort visibility, criteria normalisation and the adhoc task, any of which
 * can make a case pass or fail for a reason that has nothing to do with the predicate under test.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\audience_job::find_reusable
 */
final class audience_job_dedup_test extends \advanced_testcase {
    /**
     * Store one job row.
     *
     * @param string $hash The criteria hash.
     * @param string $status One of the audience_job STATUS_* constants.
     * @param int|null $completedago Seconds ago the job completed, or null for never.
     * @return audience_job The stored job.
     */
    private function store_job(string $hash, string $status, ?int $completedago): audience_job {
        $job = new audience_job(0, (object) [
            'jobid' => audience_job::new_jobid(),
            'userid' => 2,
            'criteriahash' => $hash,
            'criteria' => '{}',
            'status' => $status,
            'timecompleted' => $completedago === null ? null : time() - $completedago,
        ]);
        $job->create();

        return $job;
    }

    /**
     * A ready job inside the window is reused — the control for both negatives below.
     *
     * Without this, "not reused" would be satisfied by a find_reusable() that never returns
     * anything at all, which is exactly what deleting the method's body would produce.
     *
     * @return void
     */
    public function test_a_fresh_ready_job_is_reused(): void {
        $this->resetAfterTest();

        $stored = $this->store_job('samehash', audience_job::STATUS_READY, 10);

        $found = audience_job::find_reusable('samehash');

        $this->assertNotFalse($found, 'a ready job completed ten seconds ago was not reused');
        $this->assertSame((int) $stored->get('id'), (int) $found->get('id'));
    }

    /**
     * A ready job older than DEDUP_WINDOW is NOT reused.
     *
     * @return void
     */
    public function test_a_ready_job_past_the_window_is_not_reused(): void {
        $this->resetAfterTest();

        $aged = $this->store_job('agedhash', audience_job::STATUS_READY, audience_job::DEDUP_WINDOW + 60);

        // Precondition: the row really is stored and really is ready, so "not found" means the age.
        $this->assertSame(audience_job::STATUS_READY, $aged->get('status'));
        $this->assertNotFalse(audience_job::get_record(['id' => $aged->get('id')]));

        $this->assertFalse(audience_job::find_reusable('agedhash'), 'a stale count was served as current');
    }

    /**
     * An errored job is NOT reused, however recent it is.
     *
     * @return void
     */
    public function test_an_errored_job_is_not_reused(): void {
        $this->resetAfterTest();

        $failed = $this->store_job('errorhash', audience_job::STATUS_ERROR, 10);

        // Precondition: stored, recent, and errored — so "not found" can only be the status.
        $this->assertSame(audience_job::STATUS_ERROR, $failed->get('status'));
        $this->assertNotFalse(audience_job::get_record(['id' => $failed->get('id')]));

        $this->assertFalse(audience_job::find_reusable('errorhash'), 'a failed job was served as an answer');

        /*
         * And the same row flipped to ready IS reused. That is what proves the refusal above came
         * from the status rather than from anything else about the row — the age, the hash and the
         * id are all unchanged across the flip.
         */
        $failed->set('status', audience_job::STATUS_READY);
        $failed->update();
        $this->assertNotFalse(audience_job::find_reusable('errorhash'), 'the status flip changed nothing');
    }

    /**
     * A job that never completed is NOT reused, even with a ready status.
     *
     * @return void
     */
    public function test_a_job_with_no_completion_time_is_not_reused(): void {
        $this->resetAfterTest();

        $this->store_job('nullhash', audience_job::STATUS_READY, null);

        $this->assertFalse(audience_job::find_reusable('nullhash'), 'a job with no completion time was reused');
    }
}
