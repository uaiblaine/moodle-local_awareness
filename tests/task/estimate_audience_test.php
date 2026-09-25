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
use local_awareness\audience\live_mode;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;

/**
 * Tests for the estimate_audience ad-hoc task.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\task\estimate_audience
 */
final class estimate_audience_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
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

    /**
     * The task computes a pending job's count and marks it ready.
     *
     * @return void
     */
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

    /**
     * Running the task again over a job already marked ready changes nothing.
     *
     * An adhoc task can be retried, so a second run must not recompute a stored answer or move a
     * job backwards out of its completed state.
     *
     * @return void
     */
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
        // The task reports through mtrace(); capturing it keeps the test from being marked
        // risky and lets the skip be asserted rather than inferred from the count alone.
        ob_start();
        $task->execute();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('already in status ready', $output);

        $reloaded = audience_job::get_record(['jobid' => $job->get('jobid')]);
        $this->assertSame(
            99,
            (int) $reloaded->get('resultcount'),
            'Already-ready job must not be recomputed.'
        );
    }

    /**
     * The task is named by its language string, not by the name core derives from the class.
     *
     * @return void
     */
    public function test_the_task_is_named_by_its_language_string(): void {
        $name = (new estimate_audience())->get_name();

        $this->assertSame(get_string('task_estimate_audience', 'local_awareness'), $name);
        // The class-derived name core falls back to, which the string must be replacing.
        $this->assertNotSame('Estimate audience', $name);
    }

    /**
     * A course author is told when their estimate is ready, and pointed at their course's list.
     *
     * message_send() refuses a recipient for whom the provider is not listed, and core lists a
     * provider only to users holding its capability somewhere; the author here holds managecourse
     * in one course and nothing at the site. The providers are synced from db/messages.php first,
     * so the file as it stands is what is judged. A site notice saved by the administrator is the
     * control that the site list is still the link for the site.
     *
     * @return void
     */
    public function test_a_course_author_is_told_and_pointed_at_the_course_list(): void {
        global $CFG;
        require_once($CFG->libdir . '/messagelib.php');

        message_update_providers('local_awareness');
        set_config('audience_sync_limit', 0, 'local_awareness');
        live_mode::reset_cache();

        $course = $this->getDataGenerator()->create_course();
        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id);
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($course->id);
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $author->id, $context->id);
        $this->assertFalse(has_capability('local/awareness:manage', \context_system::instance(), $author));

        $this->setUser($author);
        $data = (object) ['title' => 'Lab safety', 'content' => '<p>Goggles.</p>'];
        helper::create_new_notice($data, author_scope::course((int) $course->id));
        $this->setAdminUser();
        helper::create_new_notice((object) ['title' => 'Site policy', 'content' => '<p>Read it.</p>']);

        $providers = array_filter(message_get_providers_for_user((int) $author->id), static function ($provider): bool {
            return $provider->component === 'local_awareness' && $provider->name === 'audience_estimate_ready';
        });
        $this->assertCount(1, $providers, 'the course author can see and configure the provider');

        $sink = $this->redirectMessages();
        foreach (['Lab safety', 'Site policy'] as $title) {
            $notice = awareness::get_record(['title' => $title]);
            $job = audience_job::get_record(['noticeid' => (int) $notice->get('id')]);
            $this->assertSame(audience_job::STATUS_PENDING, $job->get('status'), "{$title}: the estimate was queued");
            $task = new estimate_audience();
            $task->set_custom_data(['jobid' => $job->get('jobid')]);
            $task->execute();
        }
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(2, $messages);
        $bytitle = [];
        foreach ($messages as $message) {
            $bytitle[str_contains($message->subject, 'Lab safety') ? 'course' : 'site'] = $message;
        }
        $this->assertSame((int) $author->id, (int) $bytitle['course']->useridto);
        $this->assertSame(
            (new \moodle_url('/local/awareness/managenotice.php', ['courseid' => $course->id]))->out(false),
            $bytitle['course']->contexturl
        );
        $this->assertSame(get_string('coursenotices', 'local_awareness'), $bytitle['course']->contexturlname);
        $this->assertSame((new \moodle_url('/local/awareness/managenotice.php'))->out(false), $bytitle['site']->contexturl);
    }

    /**
     * The message carries the title in its plain spelling: filtered in the notice's context, never escaped.
     *
     * The message is FORMAT_PLAIN, so nothing downstream filters or unescapes it: a multilang title
     * sent raw shows both languages. The multilang filter is switched on for strings, and the
     * control is that format_string() really drops the other language here. A bare ampersand is
     * the escaping fixture, because tag-shaped input is stripped alike in both spellings. The
     * course notice sits in a course that switches the filter off, so its title keeps both
     * languages only when it is formatted in its own context.
     *
     * @return void
     */
    public function test_the_message_carries_the_title_filtered_and_unescaped(): void {
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        set_config('audience_sync_limit', 0, 'local_awareness');
        live_mode::reset_cache();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        filter_set_local_state('multilang', \context_course::instance($course->id)->id, TEXTFILTER_OFF);

        $title = '<span lang="en" class="multilang">Rock & roll</span><span lang="pt_br" class="multilang">Samba</span>';
        helper::create_new_notice((object) ['title' => $title, 'content' => '<p>Site.</p>']);
        $data = (object) ['title' => $title, 'content' => '<p>Course.</p>'];
        helper::create_new_notice($data, author_scope::course((int) $course->id));

        $this->assertSame(
            'Rock & roll',
            format_string($title, true, ['context' => \context_system::instance(), 'escape' => false]),
            'precondition: the multilang filter runs on strings at the site'
        );

        $sink = $this->redirectMessages();
        foreach (awareness::get_records() as $notice) {
            $job = audience_job::get_record(['noticeid' => (int) $notice->get('id')]);
            $this->assertSame(audience_job::STATUS_PENDING, $job->get('status'), 'the estimate was queued');
            $task = new estimate_audience();
            $task->set_custom_data(['jobid' => $job->get('jobid')]);
            $task->execute();
        }
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(2, $messages);
        $byscope = [];
        foreach ($messages as $message) {
            $byscope[str_contains($message->contexturl, 'courseid=') ? 'course' : 'site'] = $message;
        }

        $site = $byscope['site'];
        $expected = get_string('message:audience_ready:subject', 'local_awareness', (object) ['title' => 'Rock & roll']);
        $this->assertSame($expected, $site->subject);
        $this->assertSame($expected, $site->smallmessage);
        $this->assertStringContainsString('"Rock & roll"', $site->fullmessage);
        $this->assertStringNotContainsString('Samba', $site->fullmessage);

        $this->assertSame(
            get_string('message:audience_ready:subject', 'local_awareness', (object) ['title' => 'Rock & rollSamba']),
            $byscope['course']->subject,
            'the course notice is formatted in its course, where the filter is off'
        );
    }

    /**
     * A queued estimate that fails is recorded on its job, stores no count and tells nobody.
     *
     * No stored criteria make the real estimator throw, so it is replaced in core's DI container,
     * which is where resolve() takes it from. A second notice counted by the real estimator in the
     * same run is the control that the author is told when an estimate succeeds.
     *
     * @return void
     */
    public function test_a_failed_queued_estimate_is_recorded_and_not_announced(): void {
        set_config('audience_sync_limit', 0, 'local_awareness');
        live_mode::reset_cache();
        $this->setAdminUser();
        helper::create_new_notice((object) ['title' => 'Failing', 'content' => '<p>One.</p>']);
        helper::create_new_notice((object) ['title' => 'Counted', 'content' => '<p>Two.</p>']);

        $failing = $this->createStub(estimator::class);
        $failing->method('estimate')->willThrowException(new \dml_read_exception('estimate failed'));

        $sink = $this->redirectMessages();
        foreach (['Failing' => $failing, 'Counted' => new estimator()] as $title => $estimator) {
            \core\di::set(estimator::class, $estimator);
            $notice = awareness::get_record(['title' => $title]);
            $job = audience_job::get_record(['noticeid' => (int) $notice->get('id')]);
            $this->assertSame(audience_job::STATUS_PENDING, $job->get('status'), "{$title}: the estimate was queued");
            $task = new estimate_audience();
            $task->set_custom_data(['jobid' => $job->get('jobid')]);
            $task->execute();
        }
        $messages = $sink->get_messages();
        $sink->close();

        $failed = awareness::get_record(['title' => 'Failing']);
        $failedjob = audience_job::get_record(['noticeid' => (int) $failed->get('id')]);
        $this->assertSame(audience_job::STATUS_ERROR, $failedjob->get('status'));
        $this->assertNotEmpty($failedjob->get('errormsg'));
        $this->assertNull($failed->get('audiencecount'));
        $this->assertNotNull(awareness::get_record(['title' => 'Counted'])->get('audiencecount'));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Counted', reset($messages)->subject);
    }

    /**
     * A task whose job has been purged reports and returns instead of throwing.
     *
     * A throwing adhoc task is retried for ever, so a permanent failure has to end quietly with a
     * trace rather than by raising.
     *
     * @return void
     */
    public function test_execute_with_unknown_jobid_does_not_throw(): void {
        $jobid = 'does-not-exist-' . time();

        // Precondition: the job really does not exist, so the task takes its not-found path.
        $this->assertFalse(audience_job::get_record(['jobid' => $jobid]));

        $task = new estimate_audience();
        $task->set_custom_data(['jobid' => $jobid]);
        ob_start();
        $task->execute();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('not found', $output);
    }
}
