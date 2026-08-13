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
 * @covers \local_awareness\external::dismiss_notice
 * @covers \local_awareness\external::acknowledge_notice
 * @covers \local_awareness\external::track_link
 * @covers \local_awareness\external::search_roles
 * @covers \local_awareness\helper::is_notice_available_to_user
 */
final class notice_external_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
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

        $result = external::dismiss_notice((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $this->count_acks($notice));
    }

    /**
     * A disabled notice was never shown, so an interaction with it must not be recorded.
     */
    public function test_dismissing_a_disabled_notice_records_nothing(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $notice = $this->create_notice(['enabled' => 0]);

        $result = external::dismiss_notice((int) $notice->get('id'));

        $this->assertFalse((bool) $result['status']);
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

        $this->setUser($outsider);
        $result = external::acknowledge_notice((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status']);
        $this->assertSame(0, $this->count_acks($notice));

        // Control: the cohort member is recorded, so the gate is what rejected the outsider.
        $this->setUser($member);
        $result = external::acknowledge_notice((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
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
        $result = external::dismiss_notice((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);

        // Nothing shared was written.
        $this->assertSame(0, $this->count_acks($notice));
        $this->assertSame(0, $DB->count_records('local_awareness_lastview'));

        // But the guest stops being shown it.
        $this->assertSame([], helper::retrieve_user_notices());
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
        external::dismiss_notice((int) $notice->get('id'));
        $this->assertSame([], helper::retrieve_user_notices());

        // A new guest arrives: same user id, new session.
        unset($USER->viewednotices);
        $this->assertCount(1, helper::retrieve_user_notices());
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
        $result = external::acknowledge_notice((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);
        $this->assertSame(0, $this->count_acks($notice));
        $this->assertSame(0, $DB->count_records('local_awareness_lastview'));
        $this->assertSame([], helper::retrieve_user_notices());
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
            'timeend' => time() - HOURSECS,
        ]);

        $result = external::acknowledge_notice((int) $notice->get('id'));

        $this->assertTrue((bool) $result['status']);
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

        $result = external::dismiss_notice((int) $notice->get('id'));

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

        // Control: a real link is recorded.
        $result = external::track_link((int) $link->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_hlinks_his'));

        $result = external::track_link((int) $link->get('id') + 1000);
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

        $result = external::track_link((int) $link->get('id'));

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
        $result = external::get_notices($url, (int) $course->id);
        $this->assertSame([], json_decode($result['notices'], true));

        // Control: the enrolled user does receive it, so the filter itself still works.
        $this->setUser($student);
        $result = external::get_notices($url, (int) $course->id);
        $this->assertCount(1, json_decode($result['notices'], true));
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
        $this->assertSame([], json_decode(external::get_notices($url, (int) $course->id)['notices'], true));

        // Control: the actively enrolled user still receives it.
        $this->setUser($active);
        $this->assertCount(1, json_decode(external::get_notices($url, (int) $course->id)['notices'], true));
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

        $this->setUser($outsider);
        $result = external::acknowledge_notice((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status']);
        $this->assertSame(0, $this->count_acks($notice));

        // Control: the role holder is recorded, so the role rule is what rejected the outsider and
        // the write path has not simply been switched off.
        $this->setUser($teacher);
        $result = external::acknowledge_notice((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
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

        // Holds the role, but in a course the rule does not name.
        $this->setUser($elsewhere);
        $result = external::dismiss_notice((int) $notice->get('id'));
        $this->assertFalse((bool) $result['status']);
        $this->assertSame(0, $DB->count_records('local_awareness_lastview'));

        // Control: the same role in the named course is accepted.
        $this->setUser($inlisted);
        $result = external::dismiss_notice((int) $notice->get('id'));
        $this->assertTrue((bool) $result['status']);
        $this->assertSame(1, $DB->count_records('local_awareness_lastview'));
    }

    /**
     * Role enumeration is limited to users who can manage notices.
     */
    public function test_search_roles_requires_the_manage_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        external::search_roles('', 0);
    }

    /**
     * Control for the capability test: a manager still gets results.
     */
    public function test_search_roles_returns_roles_for_a_manager(): void {
        $this->setAdminUser();

        $result = external::search_roles('', 0);
        $roles = json_decode($result['roles'], true);

        $this->assertNotEmpty($roles);
        $this->assertArrayHasKey('id', $roles[0]);
        $this->assertArrayHasKey('name', $roles[0]);
    }
}
