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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/awareness/lib.php');

/**
 * Tests for the plugin's file-serving callback.
 *
 * local_awareness_pluginfile() is the only gate between a direct file URL and the attachments of
 * a notice that is switched off or aimed at someone else. Every refusal is a bare `return false`,
 * and a successful serve ends in send_stored_file(), which terminates the process.
 * So a refusal by helper::may_serve_files_of() is asserted with the file in place, where a missing
 * gate would serve it instead of returning, and an admission either asks may_serve_files_of()
 * directly or deletes the file first, so the callback falls out at its own get_file() miss.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::local_awareness_pluginfile
 * @covers \local_awareness\helper::may_serve_files_of
 */
final class lib_test extends \advanced_testcase {
    /**
     * Create a notice and store one file in its content area.
     *
     * @param int $enabled 1 for a live notice, 0 for a disabled one.
     * @return awareness The stored notice.
     */
    private function seed_notice_with_file(int $enabled): awareness {
        $this->setAdminUser();
        helper::create_new_notice((object) [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'perpetual' => 1,
        ]);

        $notices = array_values(awareness::get_enabled_notices());
        $notice = reset($notices);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_awareness',
            'filearea' => 'content',
            'itemid' => $notice->get('id'),
            'filepath' => '/',
            'filename' => 'policy.txt',
        ], 'the policy');

        if (!$enabled) {
            $notice->set('enabled', 0);
            $notice->update();
        }

        return $notice;
    }

    /**
     * Grant local/awareness:manage to an ordinary user and log them in.
     *
     * assign_capability() rather than setAdminUser(): an admin satisfies every capability check
     * on the site, so it could not show which one this gate reads.
     */
    private function login_as_manager(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/awareness:manage',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
    }

    /**
     * A context other than the system context is refused.
     */
    public function test_a_non_system_context_is_refused(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $course = $this->getDataGenerator()->create_course();

        $this->assertFalse(local_awareness_pluginfile(
            $course,
            null,
            \context_course::instance($course->id),
            'content',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * A file area this plugin does not own is refused.
     */
    public function test_an_unknown_filearea_is_refused(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'notafilearea',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * An itemid matching no notice is refused.
     */
    public function test_an_unknown_notice_is_refused(): void {
        $this->resetAfterTest();

        $this->seed_notice_with_file(1);

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [99999, 'policy.txt'],
            false
        ));
    }

    /**
     * A plain user cannot fetch a file belonging to a disabled notice.
     *
     * The control is test_a_plain_user_passes_the_gate_on_an_enabled_notice() below: same kind of
     * user, same call, differing in the notice's enabled flag.
     */
    public function test_a_plain_user_cannot_fetch_a_file_of_a_disabled_notice(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(0);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * A plain user gets past the gate on an enabled notice.
     *
     * The control for the disabled case. The file is deleted first, so an enabled notice falls out
     * at the callback's own get_file() miss, below the gate. That false cannot be told apart from
     * the gate's; what the pair proves is the disabled case, which with the gate removed would
     * serve its file and exit instead of returning false.
     */
    public function test_a_plain_user_passes_the_gate_on_an_enabled_notice(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(has_capability('local/awareness:manage', \context_system::instance()));

        get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $notice->get('id'),
            '/',
            'policy.txt'
        )->delete();

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * A manager reaches past the gate on a disabled notice.
     */
    public function test_a_manager_passes_the_gate_on_a_disabled_notice(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(0);
        $this->login_as_manager();

        $this->assertTrue(has_capability('local/awareness:manage', \context_system::instance()));
        $this->assertFalse((bool) $notice->get('enabled'));

        // Deleting the stored file makes the callback fall out at its get_file() miss instead of
        // calling send_stored_file(), which would terminate the test process.
        get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $notice->get('id'),
            '/',
            'policy.txt'
        )->delete();

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * A user outside the notice's audience cannot fetch its attachments.
     *
     * The file URL carries a notice id and nothing else, so without an audience check any
     * authenticated user who guessed the id could read the attachments of a notice whose body
     * get_notices() withholds from them. The file is in place: with the gate removed this case
     * reaches send_stored_file() instead of returning false.
     */
    public function test_a_user_outside_the_audience_cannot_fetch_the_files(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $cohort = $this->getDataGenerator()->create_cohort();
        $notice->set('cohorts', [(int) $cohort->id]);
        $notice->update();

        // In no cohort, holding nothing.
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * A member of the targeted cohort gets past the gate.
     *
     * The control, built like the enabled case: the file is deleted first, so a user who is in the
     * audience falls out at the callback's own get_file() miss, below the gate. Same notice, same
     * file name, differing only in whether the reader is in the cohort.
     */
    public function test_a_member_of_the_targeted_cohort_passes_the_gate(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $cohort = $this->getDataGenerator()->create_cohort();
        $notice->set('cohorts', [(int) $cohort->id]);
        $notice->update();

        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);
        $this->setUser($user);

        get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $notice->get('id'),
            '/',
            'policy.txt'
        )->delete();

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [$notice->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * Three unpublished notices, one per scope, each with an attachment.
     *
     * @param \stdClass $mine The author's course.
     * @param \stdClass $other Another course.
     * @return awareness[] Keyed mine, theirs, site.
     */
    private function unpublished_notices_with_files(\stdClass $mine, \stdClass $other): array {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $notices = [
            'mine' => $generator->create_notice(['enabled' => 0, 'courseid' => $mine->id]),
            'theirs' => $generator->create_notice(['enabled' => 0, 'courseid' => $other->id]),
            'site' => $generator->create_notice(['enabled' => 0]),
        ];
        foreach ($notices as $key => $notice) {
            get_file_storage()->create_file_from_string([
                'contextid' => \context_system::instance()->id,
                'component' => 'local_awareness',
                'filearea' => 'content',
                'itemid' => $notice->get('id'),
                'filepath' => '/',
                'filename' => 'policy.txt',
            ], "the policy of {$key}");
        }

        return $notices;
    }

    /**
     * The manager bypass is decided in the notice's own scope: a course author reaches only their course's files.
     *
     * Asked at the seam the callback stands behind, so no file has to be served; and asked through
     * the callback too, with the file in place, for the two refusals — a refusal that were not one
     * would reach send_stored_file() rather than return the strict false asserted.
     */
    public function test_a_course_author_reaches_only_their_own_course_s_unpublished_files(): void {
        $this->resetAfterTest();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $notices = $this->unpublished_notices_with_files($mine, $other);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, \context_course::instance($mine->id)->id, true);
        role_assign($roleid, $user->id, \context_course::instance($mine->id)->id);
        $this->setUser($user);

        $this->assertTrue(helper::may_serve_files_of($notices['mine']), 'their own course\'s unpublished file');
        $this->assertFalse(helper::may_serve_files_of($notices['theirs']), 'another course\'s unpublished file is refused');
        $this->assertFalse(helper::may_serve_files_of($notices['site']), 'a site notice\'s unpublished file is refused');

        foreach (['theirs', 'site'] as $key) {
            $this->assertFalse(local_awareness_pluginfile(
                null,
                null,
                \context_system::instance(),
                'content',
                [$notices[$key]->get('id'), 'policy.txt'],
                false
            ), "the callback refuses {$key} with the file in place");
        }
    }

    /**
     * The same course author reaches their own course's unpublished file through the real callback too.
     *
     * With the file deleted first, the callback falls out at its get_file() miss below the gate;
     * with the gate refusing, it would fall out above it — the same false, which is why the seam
     * carries the positive assertion and this only keeps the file's own discipline of proving each
     * scope's path through local_awareness_pluginfile().
     */
    public function test_a_course_author_s_own_file_passes_the_real_callback(): void {
        $this->resetAfterTest();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $notices = $this->unpublished_notices_with_files($mine, $other);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, \context_course::instance($mine->id)->id, true);
        role_assign($roleid, $user->id, \context_course::instance($mine->id)->id);
        $this->setUser($user);

        get_file_storage()->get_file(
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $notices['mine']->get('id'),
            '/',
            'policy.txt'
        )->delete();

        $this->assertFalse(local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [$notices['mine']->get('id'), 'policy.txt'],
            false
        ));
    }

    /**
     * A published course notice's files are for people in the course.
     *
     * Two authenticated users, neither an author, neither in a cohort or role the notice names —
     * so the audience legs admit both — and only the enrolled one is served. The site notice beside
     * it is the control: it admits both, so the refusal is the course's and not the audience's.
     */
    public function test_a_published_course_notice_s_files_need_access_to_its_course(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $coursenotice = $generator->create_notice(['courseid' => $course->id]);
        $sitenotice = $generator->create_notice();

        $enrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id);
        $outsider = $this->getDataGenerator()->create_user();

        $this->setUser($enrolled);
        $this->assertTrue(helper::may_serve_files_of($coursenotice), 'an enrolled user is served the course notice\'s files');
        $this->assertTrue(helper::may_serve_files_of($sitenotice));

        $this->setUser($outsider);
        $this->assertFalse(helper::may_serve_files_of($coursenotice), 'a user outside the course is refused its notice\'s files');
        $this->assertTrue(
            helper::may_serve_files_of($sitenotice),
            'and is served the site notice\'s, so the refusal is the course\'s'
        );
    }

    /**
     * A site manager, inheriting down, reaches every unpublished notice's files; a plain user none.
     */
    public function test_a_site_manager_reaches_every_unpublished_file_and_a_plain_user_none(): void {
        $this->resetAfterTest();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $notices = $this->unpublished_notices_with_files($mine, $other);

        $this->login_as_manager();
        foreach ($notices as $key => $notice) {
            $this->assertTrue(helper::may_serve_files_of($notice), "the site manager reaches {$key}");
        }

        $this->setUser($this->getDataGenerator()->create_user());
        foreach ($notices as $key => $notice) {
            $this->assertFalse(helper::may_serve_files_of($notice), "a plain user is refused {$key}, unpublished");
        }
    }
}
