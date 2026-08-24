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
 * "Has this user accepted this notice" is a question with an expiry date.
 *
 * Before helper::acceptance_is_current() the only available answer was "a row exists in
 * {local_awareness_ack}", and that answer can only ever become MORE true: nothing deletes the row,
 * and the acknowledge path deliberately writes a fresh one after the notice is edited or its reset
 * interval elapses. An author who sets a reset interval is saying the opposite — that acceptance
 * expires and must be given again — so the row-exists answer contradicts the setting it was most
 * likely to be asked about.
 *
 * Every test here fixes its timestamps at explicit offsets rather than letting them fall where
 * time() happens to land. interaction_is_stale() compares with a strict less-than, so a fixture
 * that creates a notice and accepts it in the same second is deciding the outcome by a race.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper
 */
final class acceptance_state_test extends \advanced_testcase {
    /**
     * Create a notice whose last edit is far enough in the past to be out of the way.
     *
     * @param array $record Fields to override.
     * @return awareness The stored notice, with timemodified 1000 seconds ago.
     */
    private function aged_notice(array $record = []): awareness {
        global $DB;

        $notice = $this->getDataGenerator()
            ->get_plugin_generator('local_awareness')
            ->create_notice($record);

        $DB->set_field('local_awareness', 'timemodified', time() - 1000, ['id' => $notice->get('id')]);

        return new awareness((int) $notice->get('id'));
    }

    /**
     * Put an acceptance on record at a chosen moment.
     *
     * Written through the persistent, as the generator does, so the row is one production could
     * have written; only the timestamp is then moved, which is the single thing under test.
     *
     * @param awareness $notice The notice accepted.
     * @param int $userid The user accepting.
     * @param int $secondsago How long ago the acceptance was given.
     * @param int $action acknowledgement::ACTION_ACKNOWLEDGED or ACTION_DISMISSED.
     * @return int The id of the row created.
     */
    private function record_action(awareness $notice, int $userid, int $secondsago, int $action): int {
        global $DB;

        $row = new acknowledgement(0, (object) [
            'userid' => $userid,
            'username' => 'u' . $userid,
            // NULL_ALLOWED without a default still means required: core\persistent demands the
            // key be present, and only then permits it to be null.
            'firstname' => 'First' . $userid,
            'lastname' => 'Last' . $userid,
            'idnumber' => '',
            'noticeid' => $notice->get('id'),
            'noticetitle' => $notice->get('title'),
            'action' => $action,
        ]);
        $row->create();

        $id = (int) $row->get('id');
        $DB->set_field('local_awareness_ack', 'timecreated', time() - $secondsago, ['id' => $id]);

        return $id;
    }

    /**
     * An acceptance stops counting once the author edits the notice.
     *
     * The control is the first assertion: it proves the acceptance was on record and readable, so
     * the false below is the edit taking effect rather than the row never having been found.
     *
     * @return void
     */
    public function test_an_acceptance_expires_when_the_notice_is_edited(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $notice = $this->aged_notice();

        $this->record_action($notice, (int) $user->id, 500, acknowledgement::ACTION_ACKNOWLEDGED);

        $this->assertTrue(
            helper::acceptance_is_current($notice, (int) $user->id),
            'the acceptance is newer than the notice, so it must count — the control for the assertion below'
        );

        // The author edits the notice: its timemodified moves past the acceptance.
        $DB->set_field('local_awareness', 'timemodified', time() - 100, ['id' => $notice->get('id')]);

        $this->assertFalse(
            helper::acceptance_is_current(new awareness((int) $notice->get('id')), (int) $user->id),
            'an acceptance given before the current text was written must not count as consent to it'
        );
    }

    /**
     * An acceptance stops counting once the reset interval elapses.
     *
     * @return void
     */
    public function test_an_acceptance_expires_when_the_reset_interval_elapses(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $notice = $this->aged_notice(['resetinterval' => 300]);

        $this->record_action($notice, (int) $user->id, 100, acknowledgement::ACTION_ACKNOWLEDGED);
        $this->assertTrue(
            helper::acceptance_is_current($notice, (int) $user->id),
            'inside the reset interval the acceptance stands — the control for the assertion below'
        );

        $other = $this->getDataGenerator()->create_user();
        $this->record_action($notice, (int) $other->id, 500, acknowledgement::ACTION_ACKNOWLEDGED);
        $this->assertFalse(
            helper::acceptance_is_current($notice, (int) $other->id),
            'past the reset interval the acceptance has expired and must be given again'
        );
    }

    /**
     * A dismissal is not an acceptance, however recent.
     *
     * Both actions live in the same table and are told apart only by the action column. A caller
     * gating access on consent must not be satisfied by a refusal — and the control proves the
     * refusal really was recorded, so the false below is the action check rather than an empty
     * table.
     *
     * @return void
     */
    public function test_a_dismissal_is_never_read_as_an_acceptance(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $notice = $this->aged_notice(['reqack' => 1]);

        $this->record_action($notice, (int) $user->id, 10, acknowledgement::ACTION_DISMISSED);

        $this->assertSame(
            1,
            $DB->count_records('local_awareness_ack', [
                'noticeid' => $notice->get('id'),
                'userid' => $user->id,
                'action' => acknowledgement::ACTION_DISMISSED,
            ]),
            'the dismissal must be on record — the control for the assertion below'
        );

        $this->assertFalse(
            helper::acceptance_is_current($notice, (int) $user->id),
            'a refusal must never satisfy a question about consent'
        );
    }

    /**
     * The predicate answers for the user it is asked about, and touches nothing.
     *
     * check_if_already_acknowledged_by_user() writes its answer into $USER->viewednotices while
     * taking $userid as a parameter, so asking it about anybody else corrupts the viewing user's
     * session. This one is asked about another user from inside a live session and must leave that
     * session exactly as it found it.
     *
     * @return void
     */
    public function test_the_predicate_is_side_effect_free_and_answers_for_another_user(): void {
        global $USER;

        $this->resetAfterTest();
        $viewer = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $notice = $this->aged_notice();

        $this->record_action($notice, (int) $subject->id, 100, acknowledgement::ACTION_ACKNOWLEDGED);

        $this->setUser($viewer);
        $USER->viewednotices = [];

        $this->assertTrue(
            helper::acceptance_is_current($notice, (int) $subject->id),
            'the subject accepted, so the answer about the subject is yes'
        );
        $this->assertSame([], $USER->viewednotices, 'asking about another user must not write into this session');

        $this->assertFalse(
            helper::acceptance_is_current($notice, (int) $viewer->id),
            'the viewer never accepted, so the answer about the viewer is no'
        );
        $this->assertSame([], $USER->viewednotices, 'a negative answer must not write into this session either');
    }

    /**
     * Several acceptance rows for one notice resolve to one current state, read from the newest.
     *
     * The duplicates are intended: the acknowledge path deliberately does not deduplicate, because
     * a second acceptance after an edit or a reset is a different fact from the first. What must
     * not happen is the oldest row keeping the answer alive for ever.
     *
     * @return void
     */
    public function test_repeat_acceptances_resolve_to_the_newest(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $notice = $this->aged_notice(['resetinterval' => 300]);

        $this->record_action($notice, (int) $user->id, 900, acknowledgement::ACTION_ACKNOWLEDGED);
        $this->record_action($notice, (int) $user->id, 100, acknowledgement::ACTION_ACKNOWLEDGED);

        $this->assertSame(
            2,
            $DB->count_records('local_awareness_ack', [
                'noticeid' => $notice->get('id'),
                'userid' => $user->id,
                'action' => acknowledgement::ACTION_ACKNOWLEDGED,
            ]),
            'both rows must exist — without them this test says nothing about which one is read'
        );

        $this->assertTrue(
            helper::acceptance_is_current($notice, (int) $user->id),
            'the newest acceptance is inside the reset interval, so acceptance stands'
        );

        // Move the newest one out of the window; the stale older row must not rescue it.
        $DB->set_field_select(
            'local_awareness_ack',
            'timecreated',
            time() - 800,
            'noticeid = :noticeid AND userid = :userid AND timecreated > :cutoff',
            [
                'noticeid' => $notice->get('id'),
                'userid' => $user->id,
                'cutoff' => time() - 500,
            ]
        );

        $this->assertFalse(
            helper::acceptance_is_current($notice, (int) $user->id),
            'with every acceptance expired the answer must be no, however many rows there are'
        );
    }
}
