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

use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\awareness;

/**
 * Which authoring actions expire a recorded acceptance, and which do not.
 *
 * The answer is "every one that saves the notice", and that is not obvious from any of their names.
 * core\persistent::update() is final and stamps timemodified unconditionally, and
 * interaction_is_stale() compares every recorded interaction against that column - so reset,
 * disable and enable each expire every acceptance on the notice, whatever their labels suggest.
 *
 * This is pinned rather than described because the coupling is invisible at each call site: none of
 * helper::reset_notice(), enable_notice() or disable_notice() mentions acknowledgements, and the
 * column they move is three files away from the predicate that reads it.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::acceptance_is_current
 */
final class consent_expiry_test extends \advanced_testcase {
    /**
     * A notice whose last edit is safely in the past, with one acceptance on record.
     *
     * Timestamps are fixed at explicit offsets rather than left to fall where time() lands:
     * interaction_is_stale() compares with a strict less-than, so a fixture that saves and accepts
     * in the same second decides its own outcome by a race.
     *
     * @param int $userid The user accepting.
     * @param int $enabled Whether the notice starts enabled.
     * @return awareness The notice, with timemodified 1000 seconds ago and an acceptance at 500.
     */
    private function accepted_notice(int $userid, int $enabled = 1): awareness {
        global $DB;

        $notice = $this->getDataGenerator()
            ->get_plugin_generator('local_awareness')
            ->create_notice(['title' => 'Policy', 'reqack' => 1, 'enabled' => $enabled]);

        $row = new acknowledgement(0, (object) [
            'userid' => $userid,
            'username' => 'u' . $userid,
            'firstname' => 'First',
            'lastname' => 'Last',
            'idnumber' => '',
            'noticeid' => $notice->get('id'),
            'noticetitle' => $notice->get('title'),
            'action' => acknowledgement::ACTION_ACKNOWLEDGED,
        ]);
        $row->create();

        $DB->set_field('local_awareness', 'timemodified', time() - 1000, ['id' => $notice->get('id')]);
        $DB->set_field('local_awareness_ack', 'timecreated', time() - 500, ['id' => $row->get('id')]);

        return new awareness((int) $notice->get('id'));
    }

    /**
     * Nothing happening leaves the acceptance standing.
     *
     * The control for both tests below: without it, a predicate hardwired to false would satisfy
     * them while proving nothing about the actions they name.
     *
     * @return void
     */
    public function test_an_untouched_notice_keeps_its_acceptance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $notice = $this->accepted_notice((int) $user->id);

        $this->assertTrue(
            helper::acceptance_is_current($notice, (int) $user->id),
            'an acceptance newer than the notice must count while nobody touches the notice'
        );
    }

    /**
     * Reset expires every acceptance, which is what it is for.
     *
     * @return void
     */
    public function test_reset_expires_the_acceptance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $notice = $this->accepted_notice((int) $user->id);
        helper::reset_notice($notice);

        $this->assertFalse(
            helper::acceptance_is_current(new awareness((int) $notice->get('id')), (int) $user->id),
            'reset asks everyone again, so the acceptance it supersedes must stop counting'
        );
    }

    /**
     * Disabling and re-enabling expires it too - and nothing in either name says so.
     *
     * This is the surprising one. An administrator hiding a notice for a week and putting it back
     * has changed no word of it, yet every acceptance on record stops counting as current. The
     * re-display half of that is deliberate and documented; the consent half arrived with
     * acceptance_is_current(), which reads the same column.
     *
     * @return void
     */
    public function test_disabling_expires_the_acceptance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $notice = $this->accepted_notice((int) $user->id);
        helper::disable_notice($notice);

        $this->assertFalse(
            helper::acceptance_is_current(new awareness((int) $notice->get('id')), (int) $user->id),
            'hiding a notice expires consent, because it saves the notice like any other write'
        );
    }

    /**
     * ...and so does enabling, on its own.
     *
     * The notice starts DISABLED so that enable_notice() is the only write in the test. Pairing the
     * two verbs in one scenario - disable, then enable - proves nothing about the second: the first
     * has already moved timemodified, so the assertion passes with enable_notice() saving nothing
     * at all. That version of this test survived exactly that mutation.
     *
     * @return void
     */
    public function test_enabling_expires_the_acceptance_on_its_own(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $notice = $this->accepted_notice((int) $user->id, 0);

        $this->assertTrue(
            helper::acceptance_is_current($notice, (int) $user->id),
            'the acceptance stands before the only write in this test - the control'
        );

        helper::enable_notice($notice);

        $this->assertFalse(
            helper::acceptance_is_current(new awareness((int) $notice->get('id')), (int) $user->id),
            'un-hiding a notice expires consent too, and nothing in the name says so'
        );
    }

    /**
     * The rows are never deleted; only their standing changes.
     *
     * Worth pinning separately, because "the acceptance expired" and "the acceptance is gone" are
     * very different answers to a compliance question, and the reports read the rows directly.
     *
     * @return void
     */
    public function test_expiring_an_acceptance_does_not_delete_it(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();

        $notice = $this->accepted_notice((int) $user->id);
        helper::reset_notice($notice);

        $this->assertSame(
            1,
            $DB->count_records('local_awareness_ack', [
                'noticeid' => $notice->get('id'),
                'userid' => $user->id,
                'action' => acknowledgement::ACTION_ACKNOWLEDGED,
            ]),
            'the compliance row survives; what changes is whether it still speaks for the notice'
        );
    }
}
