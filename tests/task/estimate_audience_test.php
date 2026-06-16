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

use local_awareness\audience\estimator;
use local_awareness\persistent\audience_job;

/**
 * Tests for the estimate_audience ad-hoc task.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers     \local_awareness\task\estimate_audience
 */
final class estimate_audience_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create and persist a pending audience job for the given criteria.
     *
     * @param array $criteria Raw audience criteria.
     * @return audience_job The persisted pending job.
     */
    private function create_pending_job(array $criteria): audience_job {
        global $USER;
        $normalised = estimator::normalise($criteria);
        $job = new audience_job(0, (object) [
            'jobid' => audience_job::new_jobid(),
            'userid' => (int) $USER->id,
            'criteriahash' => estimator::hash($normalised),
            'criteria' => json_encode($normalised),
            'status' => audience_job::STATUS_PENDING,
        ]);
        $job->create();
        return $job;
    }

    public function test_execute_resolves_pending_job_to_ready(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();
        cohort_add_member($cohort->id, $generator->create_user()->id);
        cohort_add_member($cohort->id, $generator->create_user()->id);

        $job = $this->create_pending_job(['cohorts' => [$cohort->id]]);

        $task = new estimate_audience();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->execute();

        $reloaded = audience_job::get_record(['jobid' => $job->get('jobid')]);
        $this->assertSame(audience_job::STATUS_READY, $reloaded->get('status'));
        $this->assertSame(2, (int) $reloaded->get('resultcount'));
        $this->assertNotNull($reloaded->get('timecompleted'));
    }

    public function test_execute_is_idempotent_on_already_completed_job(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();

        $job = $this->create_pending_job(['cohorts' => [$cohort->id]]);
        $job->set('status', audience_job::STATUS_READY);
        $job->set('resultcount', 99);
        $job->set('timecompleted', time() - 60);
        $job->update();

        $task = new estimate_audience();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->execute();

        $reloaded = audience_job::get_record(['jobid' => $job->get('jobid')]);
        $this->assertSame(
            99,
            (int) $reloaded->get('resultcount'),
            'Already-ready job must not be recomputed.'
        );
    }

    public function test_execute_with_unknown_jobid_does_not_throw(): void {
        $task = new estimate_audience();
        $task->set_custom_data(['jobid' => 'does-not-exist-' . time()]);
        $task->execute();
        $this->assertTrue(true, 'execute() must swallow unknown jobid silently.');
    }
}
