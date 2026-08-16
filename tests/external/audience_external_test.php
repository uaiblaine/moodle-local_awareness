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

use local_awareness\audience\live_mode;
use local_awareness\persistent\audience_job;
use local_awareness\task\estimate_audience as estimate_audience_task;

/**
 * Tests for the audience-estimate external functions.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external::estimate_audience
 * @covers \local_awareness\external::get_estimate
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

    /**
     * Above the inline limit the estimate still goes to cron.
     *
     * The limit is set to 0 rather than relying on the site being large, which no test site is.
     */
    public function test_estimate_audience_enqueues_job_and_returns_pending_status(): void {
        global $DB;
        $this->setAdminUser();
        set_config('audience_sync_limit', 0, 'local_awareness');
        $cohort = $this->getDataGenerator()->create_cohort();

        $response = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));
        $this->assertNotEmpty($response['jobid']);
        $this->assertSame('pending', $response['status']);
        $this->assertFalse($response['reused']);

        $job = audience_job::get_record(['jobid' => $response['jobid']]);
        $this->assertNotEmpty($job);
        $this->assertSame('pending', $job->get('status'));

        // Task is queued.
        $tasks = $DB->get_records(
            'task_adhoc',
            ['classname' => '\\local_awareness\\task\\estimate_audience']
        );
        $this->assertCount(1, $tasks);
    }

    /**
     * A completed job is reused. Pinned to the queued path, which is the lifecycle it describes —
     * inline resolution would complete the job before the task ever ran.
     */
    public function test_estimate_audience_reuses_recent_completed_job(): void {
        $this->setAdminUser();
        set_config('audience_sync_limit', 0, 'local_awareness');
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
        set_config('audience_sync_limit', 0, 'local_awareness');
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

    /**
     * On a site under the limit the answer is ready before the response is written.
     *
     * The empty adhoc queue is the half that matters: a job that came back ready because cron
     * happened to run would satisfy the status assertion alone.
     */
    public function test_estimate_audience_answers_inline_on_a_small_site(): void {
        global $DB;

        $this->setAdminUser();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->getDataGenerator()->create_user()->id);

        $response = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));

        $this->assertSame('ready', $response['status']);
        $this->assertFalse($response['reused']);
        $this->assertSame(0, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\local_awareness\\task\\estimate_audience']
        ));

        $ready = external::get_estimate($response['jobid']);
        $this->assertSame(1, (int) $ready['count']);
    }

    /**
     * A site over the limit queues the estimate rather than answering during the request.
     *
     * The limit is crossed with a real user count rather than by setting it to 0, so this exercises
     * the comparison and not the disabled short-circuit beside it.
     */
    public function test_estimate_audience_queues_when_the_site_is_over_the_limit(): void {
        global $DB;

        $this->setAdminUser();
        $cohort = $this->getDataGenerator()->create_cohort();
        $this->getDataGenerator()->create_user();

        // Admin plus the new user already exceed a limit of one.
        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();
        $this->assertGreaterThan(1, $DB->count_records_select('user', 'deleted = 0'));

        $response = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));

        $this->assertSame('pending', $response['status']);
        $this->assertSame(1, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\local_awareness\\task\\estimate_audience']
        ));

        // Control: the same site under a limit it does meet answers inline, so the difference is
        // the limit and not something else about this fixture.
        set_config('audience_sync_limit', 100000, 'local_awareness');
        live_mode::reset_cache();
        $other = external::estimate_audience(json_encode(['cohorts' => [$cohort->id], 'pathmatch' => '/my/']));
        $this->assertSame('ready', $other['status']);
    }

    /**
     * A second request for criteria already queued joins that job instead of queueing another.
     *
     * The editor re-estimates on every form change, so without this a burst of edits left a burst
     * of identical adhoc tasks behind. Only the queued path can produce the collision, so the
     * inline path is switched off here.
     */
    public function test_estimate_audience_joins_a_job_already_in_flight(): void {
        global $DB;

        $this->setAdminUser();
        set_config('audience_sync_limit', 0, 'local_awareness');
        $cohort = $this->getDataGenerator()->create_cohort();

        $first = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));
        $second = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));

        $this->assertSame($first['jobid'], $second['jobid']);
        $this->assertTrue($second['reused']);
        $this->assertSame('pending', $second['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_audience_jobs'));
        $this->assertSame(1, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\local_awareness\\task\\estimate_audience']
        ));

        // Control: different criteria are not merged into it, so the dedup is by criteria and not
        // simply "one job at a time".
        $other = external::estimate_audience(json_encode(['cohorts' => [$cohort->id], 'pathmatch' => '/my/']));
        $this->assertNotSame($first['jobid'], $other['jobid']);
        $this->assertSame(2, $DB->count_records('local_awareness_audience_jobs'));
    }

    /**
     * A stale queued job is not joined — a new one is started instead.
     */
    public function test_estimate_audience_does_not_join_a_job_past_its_window(): void {
        global $DB;

        $this->setAdminUser();
        set_config('audience_sync_limit', 0, 'local_awareness');
        $cohort = $this->getDataGenerator()->create_cohort();

        $first = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));

        // Age it past the window the client would still be polling within.
        $DB->set_field(
            'local_awareness_audience_jobs',
            'timecreated',
            time() - audience_job::PENDING_WINDOW - 60,
            ['jobid' => $first['jobid']]
        );

        $second = external::estimate_audience(json_encode(['cohorts' => [$cohort->id]]));

        $this->assertNotSame($first['jobid'], $second['jobid']);
        $this->assertFalse($second['reused']);
    }

    /**
     * The chips name the categories and courses a rule points at, rather than their ids.
     */
    public function test_get_estimate_describes_rule_values_by_name(): void {
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category(['name' => 'Engineering school']);
        $course = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'fullname' => 'Thermodynamics 101',
        ]);

        $response = external::estimate_audience(json_encode([
            'filter_category' => [$category->id],
            'filter_course' => [$course->id],
            'pathmatch' => '/my/',
        ]));
        $ready = external::get_estimate($response['jobid']);

        $breakdown = json_decode($ready['breakdown'], true);
        $display = array_column($breakdown, 'display', 'key');
        $this->assertSame('Engineering school', $display['filter_category']);
        $this->assertSame('Thermodynamics 101', $display['filter_course']);

        $context = json_decode($ready['context_only_filters'], true);
        $this->assertSame('/my/', array_column($context, 'display', 'key')['pathmatch']);
    }

    /**
     * The names are resolved when the job is read, never stored on it.
     *
     * Jobs are shared between callers by criteria hash, so a name written into the stored result
     * would be served to the next reader in the language of the first. This asserts the mechanism
     * rather than the symptom, which would need a second language pack installed to observe.
     */
    public function test_the_stored_job_carries_no_resolved_names(): void {
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category(['name' => 'Engineering school']);

        $response = external::estimate_audience(json_encode(['filter_category' => [$category->id]]));

        $stored = audience_job::get_record(['jobid' => $response['jobid']])->get('breakdown');
        $this->assertStringNotContainsString('Engineering school', (string) $stored);
        $this->assertStringNotContainsString('display', (string) $stored);

        // Control: the name does reach the caller, so its absence above is about storage and not
        // about the rule having been dropped.
        $ready = external::get_estimate($response['jobid']);
        $this->assertStringContainsString('Engineering school', $ready['breakdown']);
    }

    public function test_get_estimate_with_unknown_jobid_returns_error(): void {
        $this->setAdminUser();
        $response = external::get_estimate('00000000-0000-4000-8000-000000000000');
        $this->assertSame('error', $response['status']);
    }

    /**
     * The page-only rules travel back as restrictions, and leave the count at the whole site.
     *
     * pathmatch and the theme are the only two rules that say nothing about a user. The category
     * rule used to sit here too, and moved to the count when it turned out to bound reach through
     * enrolment; a notice carrying nothing but these two still reaches everybody.
     */
    public function test_get_estimate_returns_context_only_filters(): void {
        $this->setAdminUser();
        $this->getDataGenerator()->create_user();

        $req = external::estimate_audience(json_encode(['pathmatch' => 'my/?', 'filter_theme' => ['boost']]));

        $ready = external::get_estimate($req['jobid']);
        $this->assertSame('ready', $ready['status']);
        $this->assertFalse($ready['has_audience_rules']);
        $this->assertGreaterThan(0, (int) $ready['count']);
        $context = json_decode($ready['context_only_filters'], true);
        $this->assertCount(2, $context);
        $keys = array_column($context, 'key');
        $this->assertContains('pathmatch', $keys);
        $this->assertContains('filter_theme', $keys);
    }

    /**
     * The estimate must not answer "how many people are in this cohort?" for a cohort nobody offered.
     *
     * The predicate is a bare `cohortid IN (…)` with no visibility join, so an id typed into a
     * hand-made request used to come back with a population size — a membership oracle for any
     * cohort on the site, including ones in categories the caller cannot see.
     *
     * Both cohorts carry a member, and the visible one is the control: a change that simply dropped
     * every cohort would satisfy the first assertion alone while breaking the feature.
     */
    public function test_estimate_audience_ignores_a_cohort_the_caller_may_not_see(): void {
        $visible = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);
        $category = $this->getDataGenerator()->create_category();
        $hidden = $this->getDataGenerator()->create_cohort([
            'contextid' => \context_coursecat::instance($category->id)->id,
        ]);
        cohort_add_member($visible->id, $this->getDataGenerator()->create_user()->id);
        cohort_add_member($hidden->id, $this->getDataGenerator()->create_user()->id);

        // Holds the plugin's capability site-wide, but may not view cohorts in that category.
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:manage', CAP_ALLOW, $roleid, \context_system::instance()->id);
        assign_capability('moodle/cohort:view', CAP_PROHIBIT, $roleid, \context_system::instance()->id);
        role_assign($roleid, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $hiddenonly = external::estimate_audience(json_encode(['cohorts' => [$hidden->id]]));
        $result = external::get_estimate($hiddenonly['jobid']);

        /*
         * With the cohort dropped there is no audience rule left, so the estimate falls back to the
         * whole site rather than reporting the hidden cohort's single member.
         */
        $this->assertFalse($result['has_audience_rules']);

        // Control: the cohort this caller CAN see still narrows the estimate to its one member.
        $visibleonly = external::estimate_audience(json_encode(['cohorts' => [$visible->id]]));
        $control = external::get_estimate($visibleonly['jobid']);
        $this->assertTrue($control['has_audience_rules']);
        $this->assertSame(1, (int) $control['count']);
    }
}
