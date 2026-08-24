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
         * Delivery requires the site switch, which defaults to off. Every case in this file
         * exercises the reader-facing web services, so the switch being on is their precondition
         * rather than part of what they assert — and it is stated once, here, instead of being
         * scattered. That the switch really does gate these four functions is asserted separately,
         * in test_the_site_switch_gates_every_delivery_web_service(), so turning it on for the rest
         * of the file cannot hide the behaviour.
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
     * Serve a notice to the current session through the REAL read path.
     *
     * The write gate now requires that select_for_display() actually handed this notice over, and
     * that marker is the only record that the page-dependent rules ran. Setting it by hand would
     * make every test below assert against a fiction, so this goes through get_notices and then
     * asserts the marker really appeared — a delivery that silently failed would otherwise turn
     * each caller into a test of nothing.
     *
     * It takes the URL because that is what the page-dependent rules are evaluated against, and it
     * deliberately does NOT accept a notice to single out: select_for_display() hands over the head
     * of the queue, so a test that needs one specific notice among several must not pretend it can
     * name one. Those tests say so at their own call site.
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
         * Delivered while enabled, then disabled. A notice created disabled is never served at all,
         * so the delivery half would reject it on its own and the enabled clause in
         * is_notice_available_to_user() would keep passing with its test deleted.
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
         * The outsider is never delivered the notice, so the delivery half alone would reject them
         * and the cohort clause would go untested — the whole suite would stay green with
         * is_notice_available_to_user()'s cohort block deleted.
         *
         * So the cohort membership is taken away from someone who WAS delivered it. The delivery
         * marker survives in the session; only the audience answer changes. That isolates the
         * clause, and it is the realistic shape too: a user removed from a cohort while their
         * modal is open.
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
     * Both halves matter and they pull in opposite directions. Persisting it would hide the
     * notice from every later guest, because all guest sessions share one user id. Recording
     * nothing at all is worse: retrieve_user_notices() suppresses a notice solely by finding it
     * in $USER->viewednotices, so the modal would reopen on every page load — and for a notice
     * with reqack the JS blocks both the backdrop and Escape, leaving no way out.
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
     * This is the path that matters most: with reqack the modal blocks the backdrop and Escape,
     * so a guest with no working Accept has no way out of it at all.
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
         * Delivered while live, then expired under the reader — which is exactly the modal left
         * open across the expiry. A notice created already-expired can never be delivered at all,
         * so building the fixture that way would test the delivery gate and never reach the
         * window rule this test is about.
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
     * This is the disclosure the empty-pageurl bypass produced, and the one worth pinning: the
     * same user and the same notice returned zero results with a page URL and the full rendered
     * body without one. get_notices() returns content, not metadata, so the bypass published the
     * text of every role-, category-, course-, format-, theme- and competency-targeted notice on
     * the site to any authenticated caller.
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
     * This is the shape the defect actually took. pageurl was VALUE_DEFAULT '', so a web service
     * client could simply omit the key; retrieve_user_notices() read the empty string as "apply no
     * page rules" and answered with content. Only a call through call_external_function()
     * exercises that layer — invoking the method directly cannot, because PHP fills the default in
     * before the web service layer is ever consulted.
     *
     * The narrow half of this lives in the test below: this one cannot tell which layer refused,
     * because the parameter structure and the empty-string guard raise the same exception.
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
     * Asserted against the parameter structure directly and on purpose. Every route through
     * get_notices() also meets the empty-string guard inside the method, which raises the very
     * same invalid_parameter_exception — so an end-to-end test passes just as happily with the
     * parameter back to VALUE_DEFAULT, and proves nothing about this declaration. Verified by
     * mutation: reverting it to VALUE_DEFAULT leaves every other test in this file green.
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
     * The role rule lives in filtervalues alongside the page-context rules, so the write gate used
     * to skip all of them together and anyone in the right cohort could confirm a notice meant for
     * teachers. Acknowledgement reporting is the reason this plugin exists, so a row from someone
     * the notice never targeted is not a cosmetic problem.
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
         * The role is taken away from someone who WAS served the notice, rather than pointing an
         * outsider at it. An outsider is never delivered it, so the delivery half alone would
         * reject them and the role rule would go untested — user_matches_role_filter() could be
         * deleted with this file green.
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
     * in, and that cannot be enforced on a write. But it ALSO narrows which contexts the role
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
     * Repeated dismissals of a reqack notice write ONE row, not one per refusal.
     *
     * A notice requiring acknowledgement is deliberately shown again to a user who dismissed it,
     * so the dismissal path runs on every page load until they accept. Each run used to insert
     * another acknowledgement row, and the dismissed report — headed "List of users who dismissed
     * the notice" — listed the same person once per refusal. The compliance record it exists to
     * provide counted page loads.
     *
     * The control is the second user: the dedupe is per person, not a global "one row per
     * notice", and a guard keyed on the notice alone would pass the first assertion and fail the
     * second.
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
     * Standard roles ship with an EMPTY role.name and take their label from the language pack
     * through role_get_name(), so the old LIKE over name and shortname could not reach it. Four of
     * the eight standard roles were unfindable in English — "Non-editing teacher", "Course
     * creator", "Authenticated user", "Authenticated user on site home" — and under a translated
     * pack none of them was findable at all. The autocomplete does no client-side filtering: it
     * sends the typed string and renders the answer verbatim, so what this misses cannot be
     * selected.
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
     * The record used to be serialised whole, shipping pathmatch, filtervalues, cohorts, the
     * scheduling window, resetinterval, the timestamps and the author's user id to every user the
     * notice was displayed to.
     *
     * Two gates now, and this asserts both. The first is the allowlist in execute(); the second is
     * core, because the payload is a declared structure rather than a PARAM_RAW JSON string. While
     * it was a string clean_returnvalue() had nothing to look inside, so a key added to the loop
     * reached the browser whether or not anyone had thought about it — audit finding WS-01. The
     * second half of this test is the one that proves the framework is now doing the work: it
     * hands core a payload carrying a field nobody declared and shows it does not survive.
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
            'bgimageurl',
            'content',
            'id',
            'insistence',
            'modal_height',
            'modal_width',
            'title',
        ];
        $actual = array_keys($payload);
        sort($actual);
        $this->assertSame($expected, $actual);

        // What the modal reads is really there, so the trim cannot pass by shipping nothing.
        $this->assertSame('Policy update', $payload['title']);
        $this->assertStringContainsString('Read the policy.', $payload['content']);

        /*
         * And core enforces it, which is the half the exact-set assertion above cannot show: it
         * only ever sees what execute() chose to build. Hand clean_returnvalue() a payload with an
         * undeclared field and it must not come back. Revert the returns declaration to
         * PARAM_RAW and this assertion fails while everything above it still passes.
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
     * The switch is the only way an admin can stop this plugin talking to users, and it used to
     * reach the footer hook alone: the JS was never injected, but every web service stayed
     * answerable to a direct POST, so a notice could still be read, dismissed, acknowledged and
     * click-tracked on a site whose administrator had switched the plugin off.
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
     * reqcourse is an AUDIENCE rule — six places in this plugin already treat it as one. A single
     * SQL clause read it as "re-show for ever" instead, discarding the recorded view of any such
     * notice, so resetinterval had no effect on it and it came back at the start of every session
     * however the author had configured it.
     *
     * The control is the second half: with the interval elapsed the notice DOES return, so the
     * suppression asserted first is the interval doing its job rather than the notice having
     * quietly stopped being deliverable.
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
     * The second half of the same defect, and the sharper one. Because the recorded view was
     * discarded, the notice was re-shown after it had been accepted — and pressing Accept the
     * second time recorded NOTHING, because check_if_already_acknowledged_by_user() reads the
     * lastview table directly, found the row this query had thrown away, and returned early. The
     * user was shown a notice they had already accepted, by a button that could not clear it.
     *
     * The assertion is on being re-shown rather than on the second Accept, because that is the
     * observable half: dropping the clause stops the situation arising rather than changing what
     * acknowledge_notice() does once it has. The control is the first count, which proves the
     * acknowledgement really was recorded — without it an empty second list would be satisfied by
     * a notice that had simply stopped being deliverable.
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
     * A user who is in the audience but was never SERVED the notice cannot record against it.
     *
     * This is audit findings M6 and M8, and it is the only test that isolates the delivery half of
     * may_act_on_notice() — every other test in this file delivers first and then changes some
     * audience fact, so deleting the delivery requirement would leave all of them green.
     *
     * The shape matters. The user passes is_notice_available_to_user() completely: the notice is
     * enabled, live, has no cohort and no role rule. What they are missing is the PAGE-dependent
     * half — the notice is targeted at one course, and that rule can only ever be evaluated on the
     * read path, against a page. Before this gate they could post an acknowledgement from anywhere
     * and it would land in the compliance report as consent given after display, indistinguishable
     * from a real one. The report is the reason this plugin exists.
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
