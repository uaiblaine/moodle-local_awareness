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

namespace local_awareness;

use local_awareness\persistent\audience_job;
use local_awareness\task\estimate_audience as estimate_audience_task;

/**
 * Tests for the audience-estimate external functions.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers     \local_awareness\external::estimate_audience
 * @covers     \local_awareness\external::get_estimate
 */
final class audience_external_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_estimate_audience_requires_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        external::estimate_audience(json_encode(['cohorts' => [1]]));
    }

    public function test_estimate_audience_enqueues_job_and_returns_pending_status(): void {
        global $DB;
        $this->setAdminUser();
        $cohort = $this->getDataGenerator()->create_cohort();

        $response = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));
        $this->assertNotEmpty($response['jobid']);
        $this->assertSame('pending', $response['status']);
        $this->assertFalse($response['reused']);

        $job = audience_job::get_record(['jobid' => $response['jobid']]);
        $this->assertNotEmpty($job);
        $this->assertSame('pending', $job->get('status'));

        // Task is queued.
        $tasks = $DB->get_records('task_adhoc',
            ['classname' => '\\local_awareness\\task\\estimate_audience']);
        $this->assertCount(1, $tasks);
    }

    public function test_estimate_audience_reuses_recent_completed_job(): void {
        $this->setAdminUser();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->getDataGenerator()->create_user()->id);

        $first = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));

        // Run the queued task to mark the first job ready.
        $job = audience_job::get_record(['jobid' => $first['jobid']]);
        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->execute();

        // Second call within the dedup window should reuse.
        $second = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));
        $this->assertSame($first['jobid'], $second['jobid']);
        $this->assertTrue($second['reused']);
        $this->assertSame('ready', $second['status']);
    }

    public function test_get_estimate_returns_pending_then_ready(): void {
        $this->setAdminUser();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->getDataGenerator()->create_user()->id);

        $req = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));
        $pending = external::get_estimate($req['jobid']);
        $this->assertSame('pending', $pending['status']);
        $this->assertNull($pending['count']);

        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $req['jobid']]);
        $task->execute();

        $ready = external::get_estimate($req['jobid']);
        $this->assertSame('ready', $ready['status']);
        $this->assertSame(1, (int) $ready['count']);
        $this->assertNotEmpty($ready['breakdown']);
        $this->assertTrue($ready['has_audience_rules']);
    }

    public function test_get_estimate_with_unknown_jobid_returns_error(): void {
        $this->setAdminUser();
        $response = external::get_estimate('00000000-0000-4000-8000-000000000000');
        $this->assertSame('error', $response['status']);
    }

    public function test_get_estimate_returns_context_only_filters(): void {
        $this->setAdminUser();
        $req = external::estimate_audience(json_encode(['pathmatch' => 'my/?', 'filter_category' => [3]]));

        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $req['jobid']]);
        $task->execute();

        $ready = external::get_estimate($req['jobid']);
        $this->assertSame('ready', $ready['status']);
        $this->assertFalse($ready['has_audience_rules']);
        $this->assertSame(0, (int) $ready['count']);
        $context = json_decode($ready['context_only_filters'], true);
        $this->assertCount(2, $context);
        $keys = array_column($context, 'key');
        $this->assertContains('pathmatch', $keys);
        $this->assertContains('filter_category', $keys);
    }
}
