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
 * a notice that is switched off or aimed at someone else. Every refusal and every file miss is the
 * same bare `return false`, and serving a real file ends in send_stored_file(), which terminates
 * the process. So each case asks for the area's directory entry instead ({@see self::probe()}):
 * the callback returns null exactly when it got past every check and found the entry, which tells
 * an admission from a refusal without serving anything.
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
     * Ask the callback for the root directory entry of one item's file area.
     *
     * file_storage keeps a '.' record beside every stored file, and send_stored_file() returns
     * without output for a directory when 'dontdie' is set. So the callback returns null when it
     * passed every check and its own get_file() found the entry, and false for every refusal and
     * every miss. The entry exists wherever a file was stored.
     *
     * @param int $itemid The item id in the URL.
     * @param string $filearea The file area in the URL.
     * @param \context|null $context The context of the URL, the system context when null.
     * @param \stdClass|null $course The course the callback is given.
     * @return bool|null False when refused or missing, null when the entry was reached.
     */
    private function probe(int $itemid, string $filearea = 'content', ?\context $context = null, ?\stdClass $course = null) {
        return local_awareness_pluginfile(
            $course,
            null,
            $context ?? \context_system::instance(),
            $filearea,
            [$itemid, '.'],
            false,
            ['dontdie' => true]
        );
    }

    /**
     * Store a file for a notice at a chosen location, so the probe finds an entry there.
     *
     * @param awareness $notice The notice whose id is the item id.
     * @param string $filearea The file area.
     * @param \context $context The context.
     */
    private function store_file_at(awareness $notice, string $filearea, \context $context): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_awareness',
            'filearea' => $filearea,
            'itemid' => $notice->get('id'),
            'filepath' => '/',
            'filename' => 'policy.txt',
        ], 'the policy');
    }

    /**
     * A context other than the system context is refused.
     *
     * A file is stored at exactly the course-context location the request names, and the caller is
     * the admin, whom the audience gate admits: without the context check the callback would reach
     * that entry. The control is the same request in the system context.
     */
    public function test_a_non_system_context_is_refused(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $this->store_file_at($notice, 'content', $coursecontext);

        $this->assertNull($this->probe((int) $notice->get('id')), 'the control: the system context is served');
        $this->assertFalse($this->probe((int) $notice->get('id'), 'content', $coursecontext, $course));
    }

    /**
     * A file area this plugin does not own is refused.
     *
     * Built like the context case: a file is stored in the foreign area the request names, so only
     * the file-area check stands between the admin and that entry.
     */
    public function test_an_unknown_filearea_is_refused(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $this->store_file_at($notice, 'notafilearea', \context_system::instance());

        $this->assertNull($this->probe((int) $notice->get('id')), 'the control: the content area is served');
        $this->assertFalse($this->probe((int) $notice->get('id'), 'notafilearea'));
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

        $this->assertFalse($this->probe((int) $notice->get('id')));
    }

    /**
     * A plain user gets past the gate on an enabled notice.
     *
     * The control for the disabled case: same kind of user, same request.
     */
    public function test_a_plain_user_passes_the_gate_on_an_enabled_notice(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(has_capability('local/awareness:manage', \context_system::instance()));

        $this->assertNull($this->probe((int) $notice->get('id')));
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

        $this->assertNull($this->probe((int) $notice->get('id')));
    }

    /**
     * A user outside the notice's audience cannot fetch its attachments.
     *
     * The file URL carries a notice id and nothing else, so without an audience check any
     * authenticated user who guessed the id could read the attachments of a notice whose body
     * get_notices() withholds from them. The control is the cohort member below.
     */
    public function test_a_user_outside_the_audience_cannot_fetch_the_files(): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice_with_file(1);
        $cohort = $this->getDataGenerator()->create_cohort();
        $notice->set('cohorts', [(int) $cohort->id]);
        $notice->update();

        // In no cohort, holding nothing.
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse($this->probe((int) $notice->get('id')));
    }

    /**
     * A member of the targeted cohort gets past the gate.
     *
     * The control for the case above: same notice, same request, differing only in whether the
     * reader is in the cohort.
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

        $this->assertNull($this->probe((int) $notice->get('id')));
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
     * Asked at the seam the callback stands behind, and through the callback too for the two
     * refusals; the author's own file through the callback is the next test.
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
            $this->assertFalse($this->probe((int) $notices[$key]->get('id')), "the callback refuses {$key}");
        }
    }

    /**
     * The same course author reaches their own course's unpublished file through the real callback too.
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

        $this->assertNull($this->probe((int) $notices['mine']->get('id')));
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
