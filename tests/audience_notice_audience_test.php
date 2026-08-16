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
use local_awareness\audience\notice_audience;
use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;
use local_awareness\task\estimate_audience as estimate_audience_task;

/**
 * Tests for the audience size stored against a saved notice.
 *
 * Coverage is declared in this docblock rather than with #[CoversClass]; moodle-cs on the 4.05 leg
 * cannot see attributes and reports every method as missing coverage information, which fails
 * phpcs under --max-warnings 0 while this plugin still supports 4.5.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\audience\notice_audience
 */
final class audience_notice_audience_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        live_mode::reset_cache();
    }

    /**
     * Build the form data a notice is saved from.
     *
     * @param array $overrides Fields to set on top of the minimum.
     * @return \stdClass
     */
    private function form_data(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'title' => 'Notice',
            'content' => 'Body',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => 0,
            'reqcourse' => 0,
            'enabled' => 1,
            'resetinterval' => 0,
            'timestart' => 0,
            'timeend' => 0,
            'forcelogout' => 0,
            'pathmatch' => '',
        ]);
    }

    /**
     * Saving a notice on a small site leaves its audience size stored and current.
     */
    public function test_saving_stores_the_audience_size(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');

        $state = helper::create_new_notice($this->form_data(['title' => 'Everyone']));

        $this->assertSame(notice_audience::STATE_CURRENT, $state);

        $notice = awareness::get_record(['title' => 'Everyone']);
        $this->assertNotNull($notice->get('audiencecount'));
        $this->assertGreaterThan(0, (int) $notice->get('audiencecomputed'));
        $this->assertSame(notice_audience::STATE_CURRENT, notice_audience::state_of($notice));
    }

    /**
     * The criteria a saved notice yields must hash the same as the form it was saved from.
     *
     * If these two ever drift, every notice is "stale" the instant it is saved and every save
     * recomputes — a silent, permanent cost rather than a visible failure.
     */
    public function test_a_saved_notice_hashes_to_what_it_was_saved_with(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');
        $cohort = $this->getDataGenerator()->create_cohort();

        helper::create_new_notice($this->form_data([
            'title' => 'Cohort notice',
            'cohorts' => [$cohort->id],
            'pathmatch' => '/my/',
            'filter_role' => [],
            'filter_category' => [],
        ]));

        $notice = awareness::get_record(['title' => 'Cohort notice']);
        $this->assertSame(notice_audience::STATE_CURRENT, notice_audience::state_of($notice));
        $this->assertSame(notice_audience::hash_for($notice), $notice->get('audiencehash'));
    }

    /**
     * Changing a filter makes the stored count stale rather than merely old.
     *
     * The control is the second save with no filter change: it must NOT recompute, which is what
     * keeps an edit to a title from costing a scan of every user on a large site.
     */
    public function test_changing_filters_marks_the_count_stale_and_leaving_them_does_not(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');
        // Updates are refused outright without this: allow_update defaults to 0 and gates the save.
        set_config('allow_update', 1, 'local_awareness');
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->getDataGenerator()->create_user()->id);

        helper::create_new_notice($this->form_data(['title' => 'Notice']));
        $notice = awareness::get_record(['title' => 'Notice']);
        $firsthash = $notice->get('audiencehash');
        $firstcomputed = (int) $notice->get('audiencecomputed');

        // A title-only edit changes nothing the count depends on.
        $state = helper::update_notice($notice, $this->form_data(['title' => 'Renamed', 'id' => $notice->get('id')]));
        $this->assertSame(notice_audience::STATE_CURRENT, $state);
        $reread = awareness::get_record(['id' => $notice->get('id')]);
        $this->assertSame($firsthash, $reread->get('audiencehash'));
        $this->assertSame($firstcomputed, (int) $reread->get('audiencecomputed'));

        /*
         * Now change a filter, but do it behind the save so the stored hash is left describing the
         * old criteria — which is exactly the state the column has to report honestly.
         */
        $reread->set('cohorts', [(string) $cohort->id]);
        $reread->update();
        $this->assertSame(notice_audience::STATE_STALE, notice_audience::state_of($reread));
    }

    /**
     * On a large site the estimate is queued, and the queued job knows which notice it is for.
     */
    public function test_a_large_site_queues_the_estimate_against_the_notice(): void {
        global $DB;

        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();

        $state = helper::create_new_notice($this->form_data(['title' => 'Queued']));

        $this->assertSame(notice_audience::STATE_PENDING, $state);

        $notice = awareness::get_record(['title' => 'Queued']);
        $this->assertNull($notice->get('audiencecount'), 'nothing is stored until the worker runs');
        $this->assertSame(notice_audience::STATE_PENDING, notice_audience::state_of($notice));

        $job = audience_job::get_record(['noticeid' => (int) $notice->get('id')]);
        $this->assertNotEmpty($job);
        $this->assertSame(1, $DB->count_records(
            'task_adhoc',
            ['classname' => '\\local_awareness\\task\\estimate_audience']
        ));
    }

    /**
     * Running the queued job writes the count back to the notice and notifies whoever asked.
     */
    public function test_the_queued_job_records_the_count_and_notifies(): void {
        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();

        helper::create_new_notice($this->form_data(['title' => 'Queued']));
        $notice = awareness::get_record(['title' => 'Queued']);
        $job = audience_job::get_record(['noticeid' => (int) $notice->get('id')]);

        $sink = $this->redirectMessages();
        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->execute();

        $updated = awareness::get_record(['id' => (int) $notice->get('id')]);
        $this->assertNotNull($updated->get('audiencecount'));
        $this->assertSame(notice_audience::STATE_CURRENT, notice_audience::state_of($updated));

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Queued', $messages[0]->subject);
        $sink->close();
    }

    /**
     * The inline path stores the same result without messaging anyone.
     *
     * Notifying about work the author watched finish would be noise; the assertion is paired with
     * the queued case above, which proves the notification exists at all.
     */
    public function test_the_inline_path_does_not_notify(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');

        $sink = $this->redirectMessages();
        helper::create_new_notice($this->form_data(['title' => 'Immediate']));

        $notice = awareness::get_record(['title' => 'Immediate']);
        $this->assertNotNull($notice->get('audiencecount'));
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /**
     * A forced recalculation runs even when the stored count is already current.
     *
     * The site's population moves under a notice whose filters never change, so "up to date" is not
     * a reason to refuse the author who explicitly asked.
     */
    public function test_a_forced_refresh_recomputes_a_current_count(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');

        helper::create_new_notice($this->form_data(['title' => 'Notice']));
        $notice = awareness::get_record(['title' => 'Notice']);
        $before = (int) $notice->get('audiencecount');

        $this->getDataGenerator()->create_user();
        live_mode::reset_cache();

        // Control: without force there is nothing to do, so the count does not move.
        notice_audience::refresh($notice);
        $unchanged = awareness::get_record(['id' => (int) $notice->get('id')]);
        $this->assertSame($before, (int) $unchanged->get('audiencecount'));

        notice_audience::refresh($unchanged, true);
        $after = awareness::get_record(['id' => (int) $notice->get('id')]);
        $this->assertSame($before + 1, (int) $after->get('audiencecount'));
    }

    /**
     * A job about an unsaved form has no notice to write back to, and must not invent one.
     */
    public function test_a_job_without_a_notice_records_nothing(): void {
        $job = new audience_job(0, (object) [
            'jobid' => audience_job::new_jobid(),
            'userid' => 2,
            'criteriahash' => str_repeat('a', 64),
            'criteria' => '[]',
            'status' => audience_job::STATUS_PENDING,
        ]);
        $job->create();

        estimate_audience_task::resolve($job);

        $this->assertNull(notice_audience::record($job));
        $this->assertSame(audience_job::STATUS_READY, $job->get('status'));
    }

    /**
     * A notice deleted while its estimate was queued leaves the worker with nothing to record.
     */
    public function test_a_deleted_notice_is_survived(): void {
        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();

        helper::create_new_notice($this->form_data(['title' => 'Doomed']));
        $notice = awareness::get_record(['title' => 'Doomed']);
        $job = audience_job::get_record(['noticeid' => (int) $notice->get('id')]);
        $notice->delete();

        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->execute();

        $resolved = audience_job::get_record(['jobid' => $job->get('jobid')]);
        $this->assertSame(audience_job::STATUS_READY, $resolved->get('status'));
    }

    /**
     * Counting an audience is not an authoring act, so it must not look like one.
     *
     * record() wrote through the persistent, and core\persistent::update() is final and stamps
     * timemodified unconditionally. In this plugin timemodified IS the "the author changed this"
     * signal — the first thing helper::must_reshow() reads, and the whole content of
     * reset_notice() — so every recalculation was a silent Reset: everyone who had already dealt
     * with the notice got it back.
     *
     * The timestamps are forced into the past deliberately. must_reshow() compares with a strict
     * `<`, so a bump landing in the same second as the last view is forgiven, and without this the
     * test is a coin flip that mostly passes.
     *
     * @covers \local_awareness\audience\notice_audience::record
     */
    public function test_recording_a_count_does_not_re_show_the_notice(): void {
        global $DB, $USER;

        set_config('audience_sync_limit', 100000, 'local_awareness');
        helper::create_new_notice($this->form_data(['title' => 'Counted']));
        $notice = awareness::get_record(['title' => 'Counted']);

        // Push the notice and the user's view of it into the past, so the comparison is real.
        $past = time() - 3600;
        $DB->set_field(awareness::TABLE, 'timemodified', $past, ['id' => $notice->get('id')]);
        $notice = awareness::get_record(['id' => $notice->get('id')]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        helper::acknowledge_notice($notice);
        $DB->set_field('local_awareness_lastview', 'timemodified', $past + 1, ['noticeid' => $notice->get('id')]);
        unset($USER->viewednotices);

        // Control: settled, so the notice is not on offer. Without this the assertion below is free.
        $this->assertArrayNotHasKey($notice->get('id'), helper::retrieve_user_notices('/my/'));

        $this->setAdminUser();
        notice_audience::refresh($notice, true);

        $this->setUser($user);
        unset($USER->viewednotices);

        $this->assertArrayNotHasKey(
            $notice->get('id'),
            helper::retrieve_user_notices('/my/'),
            'Recalculating the audience must not put the notice back in front of somebody who settled it.'
        );
        // And the count really was written, or nothing was exercised.
        $reread = awareness::get_record(['id' => $notice->get('id')]);
        $this->assertNotNull($reread->get('audiencecount'));
        $this->assertSame($past, (int) $reread->get('timemodified'));
    }

    /**
     * A job already promised to one notice must not be taken over by another.
     *
     * refresh() joins an in-flight job by criteria hash, and the hash names a set of filters rather
     * than a notice — two site-wide notices with no filters hash identically. attach() then
     * overwrote the job's owner, so the notice that raised it waited for a result that would never
     * be written to it, and stayed permanently uncounted.
     *
     * @covers \local_awareness\audience\notice_audience::refresh
     */
    public function test_a_second_notice_does_not_steal_an_in_flight_job(): void {
        // Over the limit, so the work is queued rather than resolved during the request.
        $this->getDataGenerator()->create_user();
        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();

        helper::create_new_notice($this->form_data(['title' => 'First']));
        $first = awareness::get_record(['title' => 'First']);
        $firstjob = audience_job::get_record(['noticeid' => $first->get('id')]);
        $this->assertNotFalse($firstjob, 'The first notice must own a queued job for this to mean anything.');

        // A second notice with identical criteria, so it hashes the same.
        helper::create_new_notice($this->form_data(['title' => 'Second']));
        $second = awareness::get_record(['title' => 'Second']);

        $reread = audience_job::get_record(['jobid' => $firstjob->get('jobid')]);
        $this->assertSame(
            (int) $first->get('id'),
            (int) $reread->get('noticeid'),
            'The first notice\'s job must still belong to the first notice.'
        );

        // Control: the second notice is not left without one either.
        $secondjob = audience_job::get_record(['noticeid' => $second->get('id')]);
        $this->assertNotFalse($secondjob, 'The second notice needs a job of its own, not none at all.');
        $this->assertNotSame($firstjob->get('jobid'), $secondjob->get('jobid'));
    }
}
