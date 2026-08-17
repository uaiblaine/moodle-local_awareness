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
 * local_awareness_pluginfile() carries the only gate standing between a direct file URL and the
 * attachments of a notice that is switched off — an unpublished announcement, a notice pulled
 * after a mistake — and nothing exercised it. Every refusal path is a bare `return false`, which
 * is exactly the shape that survives being deleted.
 *
 * Every case asserts false, so each one needs its own reason to believe the false is the gate's
 * and not an accident of setup. The positive case at the end is that proof: the same file, the
 * same disabled notice, a user holding local/awareness:manage — and the callback gets far enough
 * to serve, which no other case does.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::local_awareness_pluginfile
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
     * A plain user cannot fetch a file belonging to a DISABLED notice.
     *
     * This is the gate the finding is about. The control is
     * test_a_plain_user_may_fetch_a_file_of_an_enabled_notice() below: same user, same file, same
     * call, differing only in the notice's enabled flag. Without that pair, a false here would be
     * satisfied by the file simply not existing.
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
     * A plain user gets PAST the gate on an enabled notice.
     *
     * The control for the disabled case, and it has to be built sideways. A successful serve ends
     * in send_stored_file(), which writes the file and terminates the process, so the success path
     * cannot be asserted from inside a test. What can be asserted is how far the callback gets:
     * the file is deleted first, so an enabled notice falls out at the callback's own get_file()
     * miss — reaching a line that is BELOW the capability gate.
     *
     * The pair is what carries the meaning. Enabled with no file returns false from the bottom of
     * the function; disabled with a file returns false from the gate. Remove the gate and the
     * second one stops returning at all: it serves the file and exits, which is the defect.
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
     * The file URL carries a notice id and nothing else, so before this gate existed the
     * attachments of a cohort-targeted notice were readable by any authenticated user who guessed
     * the id — while the notice body itself was correctly withheld from them by get_notices(). The
     * plugin's own security model treats audience targeting as a confidentiality boundary; the
     * file callback did not.
     *
     * Reproduced rather than reasoned about: with the gate removed this case does not return
     * false, it reaches send_stored_file() and writes the attachment.
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
     * The control, and it has to be built sideways for the same reason as the enabled case: a
     * successful serve ends in send_stored_file(), which terminates the process. The file is
     * deleted first, so a user who IS in the audience falls out at the callback's own get_file()
     * miss — a line BELOW the gate. The pair is what carries the meaning: same notice, same file
     * name, differing only in whether the reader is in the cohort.
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
}
