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

use local_awareness\external\acknowledge_notice;
use local_awareness\external\dismiss_notice;
use local_awareness\external\get_notices;
use local_awareness\external\search_roles;
use local_awareness\external\track_link;
use local_awareness\helper;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\awareness;
use local_awareness\persistent\noticelink;

/**
 * Tests for the notice-interaction external functions.
 *
 * Each test that asserts nothing was recorded is paired with a control that must record, so a
 * regression that disables the write path entirely cannot make these pass.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external\dismiss_notice
 * @covers \local_awareness\external\acknowledge_notice
 * @covers \local_awareness\external\track_link
 * @covers \local_awareness\external\get_notices
 * @covers \local_awareness\external\search_roles
 * @covers \local_awareness\helper::is_notice_available_to_user
 */
final class notice_external_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        /*
         * Delivery requires the plugin's 'enabled' setting, which defaults to off, so it is a
         * precondition of every case here. That it gates the reader-facing web services is asserted
         * in test_the_site_switch_gates_every_delivery_web_service().
         */
        set_config('enabled', 1, 'local_awareness');
    }

    /**
     * Create a notice that requires acknowledgement.
     *
     * @param array $overrides Property overrides.
     * @return awareness
     */
    private function create_notice(array $overrides = []): awareness {
        $notice = new awareness(0, (object) array_merge([
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'reqack' => 1,
            'enabled' => 1,
        ], $overrides));
        $notice->create();

        return $notice;
    }

    /**
     * Serve a notice to the current session through the real read path.
     *
     * The write gate refuses a notice that select_for_display() never handed over, the only record
     * that the page-dependent rules ran. So this calls get_notices rather than setting the session
     * marker by hand, and asserts the notice was delivered.
     *
     * $notice is only checked, not selected: the queue hands over its head, so a test needing one
     * notice among several arranges that at its own call site.
     *
     * @param awareness $notice The notice expected to be delivered.
     * @param string $url The page the reader is on.
     * @param int $courseid The course the request came from, or 0.
     * @return void
     */
    private function deliver(awareness $notice, string $url = '/my/', int $courseid = 0): void {
        global $USER;

        get_notices::execute($url, $courseid);

        $this->assertTrue(
            helper::was_notice_delivered($notice),
            'the read path did not serve this notice, so the write below would prove nothing'
        );
        $this->assertNotEmpty($USER->awarenessshown ?? []);
    }

    /**
     * Count acknowledgement rows for a notice.
     *
     * @param awareness $notice Notice.
     * @return int
     */
    private function count_acks(awareness $notice): int {
        global $DB;

        return $DB->count_records('local_awareness_ack', ['noticeid' => $notice->get('id')]);
    }

    /**
     * An enabled notice that applies to the user is recorded — the control for every
     * "nothing was recorded" assertion below.
     */
    public function test_dismissing_an_applicable_notice_is_recorded(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice();
        $this->deliver($notice);

        $result = dismiss_notice::execute((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $this->count_acks($notice));
    }

    /**
     * A disabled notice was never shown, so an interaction with it must not be recorded.
     */
    public function test_dismissing_a_disabled_notice_records_nothing(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice();

        /*
         * Delivered while enabled, then disabled: a notice created disabled is never delivered, so
         * the delivery check alone would refuse it and the enabled clause of
         * is_notice_available_to_user() would go untested.
         */
        $this->deliver($notice);
        $notice->set('enabled', 0);
        $notice->update();

        $result = dismiss_notice::execute((int) $notice->get('id'));

        $this->assertFalse((bool) $result['status'], 'a notice disabled after delivery was still recorded');
        $this->assertSame(0, $this->count_acks($notice));
    }

    /**
     * A notice targeted at a cohort must not be acknowledgeable by a user outside it.
     */
    public function test_acknowledging_a_notice_for_another_cohort_records_nothing(): void {
        $cohort = $this->getDataGenerator()->create_cohort();
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $member->id);

        $notice = $this->create_notice(['cohorts' => (string) $cohort->id]);

        /*
         * The outsider is never delivered the notice, so the delivery check alone would refuse them
         * and the cohort clause of is_notice_available_to_user() would go untested. So membership is
         * removed from a user who was delivered it: the delivery marker survives in the session and
         * only the audience answer changes, as for a user removed from a cohort while the modal is
         * open.
         */
        $this->setUser($member);
        $this->deliver($notice);
        cohort_remove_member($cohort->id, $member->id);

        $result = acknowledge_notice::execute((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status'], 'a user removed from the cohort was still recorded');
        $this->assertSame(0, $this->count_acks($notice));

        // Control: put them back, and the same session records.
        cohort_add_member($cohort->id, $member->id);
        $result = acknowledge_notice::execute((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $this->count_acks($notice));

        // And the outsider, who was never served it, is refused too.
        $this->setUser($outsider);
        $this->assertFalse((bool) acknowledge_notice::execute((int) $notice->get('id'))['status']);
        $this->assertSame(1, $this->count_acks($notice));
    }

    /**
     * A guest's dismissal is remembered for their session but never written to a shared table.
     *
     * Persisting it would hide the notice from every later guest, because all guest sessions share
     * one user id. Recording nothing would reopen the modal on every page load, because
     * retrieve_user_notices() suppresses a notice only by finding it in $USER->viewednotices.
     */
    public function test_a_guest_dismissal_is_session_scoped_and_not_persisted(): void {
        global $DB;

        // Uses reqack = 0, a notice that only requires dismissal: a reqack notice deliberately
        // keeps reappearing until acknowledged, for every user, so it cannot show this.
        $notice = $this->create_notice(['reqack' => 0]);

        $this->setGuestUser();
        $this->deliver($notice);
        $result = dismiss_notice::execute((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);

        // Nothing shared was written.
        $this->assertSame(0, $this->count_acks($notice));
        $this->assertSame(0, $DB->count_records('local_awareness_lastview'));

        // But the guest stops being shown it.
        $this->assertSame([], helper::retrieve_user_notices('/my/'));
    }

    /**
     * Control for the test above: a fresh guest session still gets the notice.
     *
     * Proves the suppression is session state, not something that leaked into shared storage.
     */
    public function test_a_later_guest_session_still_receives_the_notice(): void {
        global $USER;

        $notice = $this->create_notice(['reqack' => 0]);

        $this->setGuestUser();
        $this->deliver($notice);
        dismiss_notice::execute((int) $notice->get('id'));
        $this->assertSame([], helper::retrieve_user_notices('/my/'));

        /*
         * A new guest arrives: same user id, new session. Both markers go, because both are
         * session state — the viewed marker that suppresses, and the delivery marker the write
         * gate reads.
         */
        unset($USER->viewednotices, $USER->awarenessshown);
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
    }

    /**
     * A guest acknowledging a notice that requires acknowledgement also stops seeing it.
     *
     * With reqack the modal refuses the backdrop and Escape, leaving Accept and Not now as the
     * guest's only exits, so Accept must stop the notice reappearing too.
     */
    public function test_a_guest_acknowledgement_suppresses_the_notice_for_the_session(): void {
        global $DB;

        $notice = $this->create_notice();

        $this->setGuestUser();
        $this->deliver($notice);
        $result = acknowledge_notice::execute((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);
        $this->assertSame(0, $this->count_acks($notice));
        $this->assertSame(0, $DB->count_records('local_awareness_lastview'));
        $this->assertSame([], helper::retrieve_user_notices('/my/'));
    }

    /**
     * An Accept that lands after the notice expired must still be recorded.
     *
     * The window governs display. Discarding a genuine acknowledgement because the notice expired
     * while the modal was open would lose the record this plugin exists to keep.
     */
    public function test_acknowledging_an_expired_notice_is_still_recorded(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice([
            'timestart' => time() - DAYSECS,
            'timeend' => time() + HOURSECS,
        ]);

        /*
         * Delivered while live, then expired, as for a modal left open across the expiry. A notice
         * created already expired is never delivered, so the delivery gate would refuse it before
         * the window rule is reached.
         */
        $this->deliver($notice);
        $notice->set('timeend', time() - HOURSECS);
        $notice->update();

        $result = acknowledge_notice::execute((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status'], 'an Accept was discarded because the notice expired');
        $this->assertSame(1, $this->count_acks($notice));
    }

    /**
     * A notice whose start date has not arrived cannot be pre-dismissed.
     *
     * The counterpart to the test above: the lower bound of the window is enforced, so a
     * scheduled notice cannot be cleared before anyone was ever shown it.
     */
    public function test_dismissing_a_notice_that_has_not_started_records_nothing(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice([
            'timestart' => time() + DAYSECS,
            'timeend' => time() + (2 * DAYSECS),
        ]);

        $result = dismiss_notice::execute((int) $notice->get('id'));

        $this->assertFalse((bool) $result['status']);
        $this->assertSame(0, $this->count_acks($notice));
    }

    /**
     * A link id that belongs to no notice must not create a click record.
     */
    public function test_tracking_an_unknown_link_records_nothing(): void {
        global $DB;

        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice();
        $link = noticelink::create_new_link((object) [
            'noticeid' => $notice->get('id'),
            'text' => 'the policy',
            'link' => 'https://example.com/policy',
        ]);

        // Control: a real link on a delivered notice is recorded.
        $this->deliver($notice);
        $result = track_link::execute((int) $link->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_hlinks_his'));

        $result = track_link::execute((int) $link->get('id') + 1000);
        $this->assertFalse((bool) $result['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_hlinks_his'));
    }

    /**
     * A click on a link belonging to a disabled notice must not be recorded either.
     */
    public function test_tracking_a_link_of_a_disabled_notice_records_nothing(): void {
        global $DB;

        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice(['enabled' => 0]);
        $link = noticelink::create_new_link((object) [
            'noticeid' => $notice->get('id'),
            'text' => 'the policy',
            'link' => 'https://example.com/policy',
        ]);

        $result = track_link::execute((int) $link->get('id'));

        $this->assertFalse((bool) $result['status']);
        $this->assertSame(0, $DB->count_records('local_awareness_hlinks_his'));
    }

    /**
     * A course-targeted notice must not be reachable by naming a course the user cannot enter.
     *
     * get_notices() takes the course id from the browser and check_filters() uses it to decide
     * that a course-scoped notice applies, so without a server-side access check any user could
     * claim any course's context and pull that notice's content.
     */
    public function test_get_notices_ignores_a_course_the_user_cannot_access(): void {
        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Course only';
        $data->content = '<p>Only inside this course.</p>';
        $data->filter_course = [$course->id];
        helper::create_new_notice($data);

        $url = '/course/view.php?id=' . $course->id;

        $this->setUser($outsider);
        $result = get_notices::execute($url, (int) $course->id);
        $this->assertSame([], $result['notices']);

        // Control: the enrolled user does receive it, so the filter itself still works.
        $this->setUser($student);
        $result = get_notices::execute($url, (int) $course->id);
        $this->assertCount(1, $result['notices']);
    }

    /**
     * A suspended participant is no longer in the course, so its notices stop reaching them.
     *
     * can_access_course() defaults $onlyactive to false, which accepts any enrolment row at all;
     * the plugin passes true. Without it a suspended user keeps receiving the course's notices.
     */
    public function test_get_notices_ignores_a_course_the_user_is_only_suspended_in(): void {
        $course = $this->getDataGenerator()->create_course();
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($active->id, $course->id);
        $this->getDataGenerator()->enrol_user(
            $suspended->id,
            $course->id,
            null,
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Course only';
        $data->content = '<p>Only inside this course.</p>';
        $data->filter_course = [$course->id];
        helper::create_new_notice($data);

        $url = '/course/view.php?id=' . $course->id;

        $this->setUser($suspended);
        $this->assertSame([], get_notices::execute($url, (int) $course->id)['notices']);

        // Control: the actively enrolled user still receives it.
        $this->setUser($active);
        $this->assertCount(1, get_notices::execute($url, (int) $course->id)['notices']);
    }

    /**
     * A notice targeted at a role must never reach a user who does not hold it.
     *
     * get_notices() returns rendered content, so an empty page URL must not skip the page rules:
     * that would hand any authenticated caller the text of every role-, category-, course-,
     * format-, theme- and competency-targeted notice. The last assertion pins the refusal.
     */
    public function test_a_role_targeted_notice_is_not_disclosed_to_a_user_without_the_role(): void {
        global $DB;

        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Teachers only';
        $data->content = '<p>Only teachers may read this.</p>';
        $data->filter_role = [$teacherroleid];
        helper::create_new_notice($data);

        // Control: the role holder does receive it, so the role rule is what rejects the outsider
        // below rather than the notice being invisible to everyone.
        $this->setUser($teacher);
        $this->assertCount(1, get_notices::execute('/my/')['notices']);

        $this->setUser($outsider);
        $this->assertSame([], get_notices::execute('/my/')['notices']);

        // And the outsider cannot get a different answer by declining to say where they are.
        $this->expectException(\invalid_parameter_exception::class);
        get_notices::execute('');
    }

    /**
     * Leaving pageurl out altogether is rejected by the parameter structure.
     *
     * Only a call through call_external_function() can omit the key; execute() itself declares no
     * default for it. This test cannot tell which layer refused, because the parameter structure and
     * the empty-string guard raise the same exception; the test below pins the declaration itself.
     */
    public function test_get_notices_rejects_an_omitted_pageurl(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $_POST['sesskey'] = sesskey();

        $this->create_notice();

        $omitted = \core_external\external_api::call_external_function(
            'local_awareness_getnotices',
            ['courseid' => 0],
            false
        );

        $this->assertTrue($omitted['error']);
        $this->assertSame('invalidparameter', $omitted['exception']->errorcode);

        // Control: the same call with a page URL — what the plugin's own JS always sends — is
        // answered, so the rejection above is the missing key and not a broken registration.
        $supplied = \core_external\external_api::call_external_function(
            'local_awareness_getnotices',
            ['pageurl' => '/my/', 'courseid' => 0],
            false
        );

        $this->assertFalse($supplied['error']);
        $this->assertCount(1, $supplied['data']['notices']);
    }

    /**
     * The page URL is declared VALUE_REQUIRED, so the key cannot simply be left out.
     *
     * Asserted against the parameter structure directly: every route through get_notices() also
     * meets the empty-string guard inside the method, which raises the same
     * invalid_parameter_exception, so an end-to-end test would still pass with the parameter back
     * at VALUE_DEFAULT.
     */
    public function test_get_notices_parameters_declares_the_page_url_required(): void {
        $this->expectException(\invalid_parameter_exception::class);

        \core_external\external_api::validate_parameters(
            get_notices::execute_parameters(),
            ['courseid' => 0]
        );
    }

    /**
     * A notice targeted at a role must not be acknowledgeable by someone who does not hold it.
     *
     * The role rule is stored in filtervalues beside the page-context rules, but it does not depend
     * on the page, so the write gate must evaluate it; otherwise anyone could put a row in the
     * acknowledgement report for a notice meant for teachers.
     */
    public function test_acknowledging_a_role_targeted_notice_records_nothing_without_the_role(): void {
        global $DB;

        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $course = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Teachers only';
        $data->content = '<p>Teachers must confirm this.</p>';
        $data->filter_role = [$teacherroleid];
        helper::create_new_notice($data);

        $notice = awareness::get_record(['title' => 'Teachers only']);

        /*
         * The role is removed from a user who was served the notice: an outsider is never
         * delivered it, so the delivery check alone would refuse them and the role rule would go
         * untested.
         */
        $this->setUser($teacher);
        $this->deliver($notice);
        role_unassign($teacherroleid, $teacher->id, \context_course::instance($course->id)->id);

        $result = acknowledge_notice::execute((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status'], 'a user whose role was removed was still recorded');
        $this->assertSame(0, $this->count_acks($notice));

        // Control: give the role back, and the same session records.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $result = acknowledge_notice::execute((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $this->count_acks($notice));

        // And the outsider, never served it, is refused.
        $this->setUser($outsider);
        $this->assertFalse((bool) acknowledge_notice::execute((int) $notice->get('id'))['status']);
        $this->assertSame(1, $this->count_acks($notice));
    }

    /**
     * A course-scoped role rule keeps its scope on the write path.
     *
     * filter_course has two jobs. As a page-context filter it says which course the reader must be
     * in, and that cannot be enforced on a write. But it also narrows which contexts the role
     * assignment is looked for in, and that part is page-independent and must survive. Passing
     * only filter_role to the check would widen "teacher in this one course" into "teacher
     * anywhere on the site", which is why the whole filters array is handed over.
     */
    public function test_a_course_scoped_role_rule_keeps_its_scope_on_the_write_path(): void {
        global $DB;

        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $listed = $this->getDataGenerator()->create_course();
        $unlisted = $this->getDataGenerator()->create_course();

        $inlisted = $this->getDataGenerator()->create_user();
        $elsewhere = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($inlisted->id, $listed->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($elsewhere->id, $unlisted->id, 'editingteacher');

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Teachers of one course';
        $data->content = '<p>Scoped to a single course.</p>';
        $data->filter_role = [$teacherroleid];
        $data->filter_role_context = CONTEXT_COURSE;
        $data->filter_course = [$listed->id];
        helper::create_new_notice($data);

        $notice = awareness::get_record(['title' => 'Teachers of one course']);

        /*
         * Delivered from inside the named course — check_filters() re-resolves that course id
         * through can_access_course(), so the delivery is the page-dependent half doing its job.
         * The scoped role is then removed, which is the only thing that changes.
         */
        $this->setUser($inlisted);
        $this->deliver($notice, '/course/view.php?id=' . $listed->id, (int) $listed->id);
        role_unassign($teacherroleid, $inlisted->id, \context_course::instance($listed->id)->id);

        $result = dismiss_notice::execute((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status'], 'the scoped role rule did not reject the write');
        $this->assertSame(0, $DB->count_records('local_awareness_lastview'));

        // Control: the same role back in the named course is accepted.
        $this->getDataGenerator()->enrol_user($inlisted->id, $listed->id, 'editingteacher');
        $result = dismiss_notice::execute((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_lastview'));

        // Holds the role, but only in a course the rule does not name: never served it.
        $this->setUser($elsewhere);
        $this->assertFalse((bool) dismiss_notice::execute((int) $notice->get('id'))['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_lastview'));
    }

    /**
     * Role enumeration is limited to users who can manage notices.
     */
    public function test_search_roles_requires_the_manage_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        search_roles::execute('', 0);
    }

    /**
     * Control for the capability test: a manager still gets results.
     */
    public function test_search_roles_returns_roles_for_a_manager(): void {
        $this->setAdminUser();

        $result = search_roles::execute('', 0);
        $roles = json_decode($result['roles'], true);

        $this->assertNotEmpty($roles);
        $this->assertArrayHasKey('id', $roles[0]);
        $this->assertArrayHasKey('name', $roles[0]);
    }

    /**
     * Repeated dismissals of a reqack notice write one row, not one per refusal.
     *
     * A notice requiring acknowledgement is shown again to a user who dismissed it, so the
     * dismissal path runs on every page load until they accept; one row per refusal would list the
     * same person once per page load in the dismissed report.
     *
     * The control is the second user: the dedupe is per person, not one row per notice, and a guard
     * keyed on the notice alone would pass the first assertion and fail the second.
     */
    public function test_repeated_dismissals_write_one_row_per_user(): void {
        global $DB;

        $notice = $this->create_notice(['reqack' => 1]);

        $first = $this->getDataGenerator()->create_user();
        $this->setUser($first);
        helper::dismiss_notice($notice);
        helper::dismiss_notice($notice);
        helper::dismiss_notice($notice);

        $this->assertSame(1, $DB->count_records('local_awareness_ack', [
            'noticeid' => $notice->get('id'),
            'userid' => $first->id,
            'action' => acknowledgement::ACTION_DISMISSED,
        ]));

        // Control: a different reader still gets their own row.
        $second = $this->getDataGenerator()->create_user();
        $this->setUser($second);
        helper::dismiss_notice($notice);

        $this->assertSame(2, $DB->count_records('local_awareness_ack', [
            'noticeid' => $notice->get('id'),
            'action' => acknowledgement::ACTION_DISMISSED,
        ]));
    }

    /**
     * A standard role is findable by the label the picker actually shows.
     *
     * Standard roles store an empty role.name and take their label from the language pack through
     * role_get_name(), so a LIKE over name and shortname cannot find "Non-editing teacher", nor,
     * under a translated pack, any standard role by its label. The autocomplete does no client-side
     * filtering, so what the search misses cannot be selected.
     */
    public function test_search_roles_finds_a_standard_role_by_its_displayed_label(): void {
        $this->setAdminUser();

        $names = role_get_names(null, ROLENAME_ORIGINAL);
        $teacher = null;
        foreach ($names as $role) {
            if ($role->shortname === 'teacher') {
                $teacher = $role;
                break;
            }
        }
        $this->assertNotNull($teacher, 'the non-editing teacher role must exist for this test to mean anything');
        $this->assertSame('', (string) $teacher->name, 'a standard role stores no name — that is the premise');

        $found = json_decode(search_roles::execute($teacher->localname, 0)['roles'], true);

        $this->assertContains(
            (int) $teacher->id,
            array_map('intval', array_column($found, 'id')),
            'searching a standard role by its displayed label must find it'
        );
    }

    /**
     * A custom role is findable by the text its author typed, ampersand included.
     *
     * role_get_name() runs the stored name through format_string(), which entity-escapes "&".
     * Matching only the formatted label would make "R&D coordinator" reachable solely by typing
     * the literal "R&amp;D", which nobody does — while the picker displays "R&D coordinator".
     */
    public function test_search_roles_finds_a_custom_role_by_its_unescaped_name(): void {
        $this->setAdminUser();

        $roleid = create_role('R&D coordinator', 'rdcoord', 'Coordinates R&D');

        $found = json_decode(search_roles::execute('R&D', 0)['roles'], true);

        $this->assertContains((int) $roleid, array_map('intval', array_column($found, 'id')));
    }

    /**
     * A query that matches nothing returns nothing.
     *
     * The control that stops the two tests above passing against a function that ignores its
     * query and returns every role.
     */
    public function test_search_roles_returns_nothing_for_an_unmatched_query(): void {
        $this->setAdminUser();

        $found = json_decode(search_roles::execute('zzzznosuchrolezzzz', 0)['roles'], true);

        $this->assertSame([], $found);
    }

    /**
     * The notices payload carries exactly what the modal reads, and nothing else.
     *
     * Targeting metadata (pathmatch, filtervalues, cohorts, the schedule, the author's user id) must
     * not reach readers. Two gates, both asserted: the key set notice_payload::build() produces, and
     * core's clean_returnvalue(), which strips any key the declared structure does not name.
     */
    public function test_get_notices_payload_is_limited_to_what_the_modal_reads(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        // Targeting metadata deliberately present, so its absence below is an exclusion, not a null.
        $this->create_notice([
            'pathmatch' => '/my/%',
            'resetinterval' => 3600,
        ]);

        $notices = get_notices::execute('/my/')['notices'];
        $this->assertCount(1, $notices);
        $payload = reset($notices);

        $expected = [
            'animation',
            'bgimageurl',
            'content',
            'id',
            'insistence',
            'modal_height',
            'modal_width',
            'position',
            'slides',
            'template',
            'title',
            'videohtml',
        ];
        $actual = array_keys($payload);
        sort($actual);
        $this->assertSame($expected, $actual);

        // What the modal reads is really there, so the trim cannot pass by shipping nothing.
        $this->assertSame('Policy update', $payload['title']);
        $this->assertStringContainsString('Read the policy.', $payload['content']);

        /*
         * Core enforces the declaration too, which the exact-set assertion above cannot show: it
         * only sees what notice_payload::build() chose to build. An undeclared field handed to
         * clean_returnvalue() must not come back.
         */
        $leaky = $payload;
        $leaky['pathmatch'] = '/secret/%';
        $cleaned = \core_external\external_api::clean_returnvalue(
            get_notices::execute_returns(),
            ['status' => true, 'notices' => [$leaky]]
        );

        $this->assertArrayNotHasKey(
            'pathmatch',
            $cleaned['notices'][0],
            'core must strip a field the returns declaration does not name'
        );
        // Control: the declared fields survive the same call, so the assertion above is not
        // satisfied by clean_returnvalue() having discarded everything. Sorted, because core
        // returns them in declaration order rather than the order they were handed over in.
        $survived = array_keys($cleaned['notices'][0]);
        sort($survived);
        $this->assertSame($expected, $survived);
    }

    /**
     * With the site switch off, none of the four reader-facing services does anything.
     *
     * The switch is the only way an admin can stop this plugin talking to users, so it must gate
     * each web service and not only the footer hook, or a direct POST could still read, dismiss,
     * acknowledge and click-track a notice.
     *
     * Each half of the pair runs the same call — switch off, then switch on — so a failure to
     * write cannot be mistaken for the fixture being wrong.
     */
    public function test_the_site_switch_gates_every_delivery_web_service(): void {
        global $DB;

        $notice = $this->create_notice();
        $link = noticelink::create_new_link((object) [
            'noticeid' => $notice->get('id'),
            'text' => 'the policy',
            'link' => 'https://example.com/policy',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        set_config('enabled', 0, 'local_awareness');

        $off = get_notices::execute('/my/', 0);
        $this->assertSame([], $off['notices'], 'no notice may be served while off');

        dismiss_notice::execute((int) $notice->get('id'));
        acknowledge_notice::execute((int) $notice->get('id'));
        track_link::execute((int) $link->get('id'));

        $this->assertSame(0, $DB->count_records('local_awareness_ack', ['noticeid' => $notice->get('id')]));
        $this->assertSame(0, $DB->count_records('local_awareness_lastview', ['noticeid' => $notice->get('id')]));
        $this->assertSame(0, $DB->count_records('local_awareness_hlinks_his', ['hlinkid' => $link->get('id')]));

        // Control: with the switch on, the same four calls all take effect.
        set_config('enabled', 1, 'local_awareness');

        $on = get_notices::execute('/my/', 0);
        $this->assertCount(1, $on['notices'], 'the fixture notice is deliverable');

        acknowledge_notice::execute((int) $notice->get('id'));
        track_link::execute((int) $link->get('id'));

        $this->assertSame(1, $DB->count_records('local_awareness_ack', ['noticeid' => $notice->get('id')]));
        $this->assertSame(1, $DB->count_records('local_awareness_hlinks_his', ['hlinkid' => $link->get('id')]));
    }

    /**
     * A notice requiring a course obeys its reset interval like any other.
     *
     * reqcourse is an audience rule, not a re-show rule: the recorded view of such a notice is
     * kept, so resetinterval decides when it returns.
     *
     * The control is the second half: with the interval elapsed the notice does return, so the
     * suppression asserted first is the interval and not the notice having stopped being
     * deliverable.
     */
    public function test_a_reqcourse_notice_obeys_its_reset_interval(): void {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();
        $notice = $this->create_notice(['reqack' => 0, 'reqcourse' => $course->id, 'resetinterval' => WEEKSECS]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertCount(1, get_notices::execute('/my/', 0)['notices']);

        dismiss_notice::execute((int) $notice->get('id'));

        // A fresh session: the in-request memo is gone, so the answer comes from the database.
        unset($USER->viewednotices);
        \local_awareness\persistent\noticeview::purge_user_cache((int) $user->id);

        $this->assertSame(
            [],
            get_notices::execute('/my/', 0)['notices'],
            'within the reset interval the notice must stay dismissed'
        );

        // Control: push the recorded view back beyond the interval and it returns.
        $DB->set_field(
            'local_awareness_lastview',
            'timemodified',
            time() - WEEKSECS - HOURSECS,
            ['noticeid' => $notice->get('id'), 'userid' => $user->id]
        );
        unset($USER->viewednotices);
        \local_awareness\persistent\noticeview::purge_user_cache((int) $user->id);

        $this->assertCount(
            1,
            get_notices::execute('/my/', 0)['notices'],
            'past the reset interval the notice must return'
        );
    }

    /**
     * An acknowledged reqcourse notice is not put back in front of the user.
     *
     * Were the recorded view discarded, the accepted notice would be shown again, and a second
     * Accept could not clear it: check_if_already_acknowledged_by_user() finds the existing lastview
     * row and returns early without recording anything.
     *
     * The control is the first count, which proves the acknowledgement was recorded, so the empty
     * second list is not a notice that simply stopped being deliverable.
     */
    public function test_an_acknowledged_reqcourse_notice_is_not_shown_again(): void {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();
        $notice = $this->create_notice(['reqack' => 1, 'reqcourse' => $course->id, 'resetinterval' => 0]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertCount(1, get_notices::execute('/my/', 0)['notices']);

        acknowledge_notice::execute((int) $notice->get('id'));

        $this->assertSame(
            1,
            $DB->count_records('local_awareness_ack', [
                'noticeid' => $notice->get('id'),
                'userid' => $user->id,
                'action' => acknowledgement::ACTION_ACKNOWLEDGED,
            ]),
            'the acknowledgement must be recorded — the control for the assertion below'
        );

        // A fresh session: the answer now comes from the database rather than the request memo.
        unset($USER->viewednotices);
        \local_awareness\persistent\noticeview::purge_user_cache((int) $user->id);

        $this->assertSame(
            [],
            get_notices::execute('/my/', 0)['notices'],
            'an accepted notice must not come back at the next session'
        );
    }

    /**
     * A user who is in the audience but was never served the notice cannot record against it.
     *
     * The only test in this file that isolates the delivery half of may_act_on_notice(). The user
     * passes is_notice_available_to_user() (enabled, live, no cohort or role rule), but the notice
     * targets one course, a page-dependent rule that is only evaluated on the read path. Without
     * the delivery check, an acknowledgement posted from anywhere would enter the compliance report
     * as consent given after display.
     *
     * Same user and same audience answer on both halves below; only the delivery differs.
     */
    public function test_a_notice_never_served_cannot_be_acknowledged(): void {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Course targeted';
        $data->content = '<p>Only for one course.</p>';
        $data->reqack = 1;
        $data->filter_course = [$course->id];
        helper::create_new_notice($data);
        $notice = awareness::get_record(['title' => 'Course targeted']);

        $this->setUser($user);

        /*
         * Precondition, and the whole point: the audience test says yes. If this ever goes false
         * the test below would pass for the wrong reason — the audience half rejecting, not the
         * delivery half.
         */
        $this->assertTrue(
            helper::is_notice_available_to_user($notice),
            'the audience half already refuses this user, so the delivery half would not be isolated'
        );

        $result = acknowledge_notice::execute((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status'], 'a notice that was never served was acknowledged anyway');
        $this->assertSame(0, $this->count_acks($notice));

        /*
         * Control: served from inside the course it targets — which is where check_filters()
         * re-resolves the course through can_access_course() — and the same call now records.
         */
        $this->deliver($notice, '/course/view.php?id=' . $course->id, (int) $course->id);
        $result = acknowledge_notice::execute((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $this->count_acks($notice));

        // And the marker is session state: a new session cannot act on it again.
        unset($USER->awarenessshown);
        $this->assertFalse(
            (bool) dismiss_notice::execute((int) $notice->get('id'))['status'],
            'a replaced session could still write without a fresh delivery'
        );
    }
}
