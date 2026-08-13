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

/**
 * The display queue: one notice at a time, and what earns a place at the front of it.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::select_for_display
 */
final class display_queue_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create a notice.
     *
     * @param string $title Title, used to identify it in assertions.
     * @param int $resetinterval Seconds; above zero makes the notice a repeating one.
     * @param array $extra Further property overrides.
     * @return awareness
     */
    private function notice(string $title, int $resetinterval = 0, array $extra = []): awareness {
        $notice = new awareness(0, (object) array_merge([
            'title' => $title,
            'content' => '<p>' . $title . '</p>',
            'enabled' => 1,
            'resetinterval' => $resetinterval,
        ], $extra));
        $notice->create();

        return $notice;
    }

    /**
     * Titles of the notices the queue would show right now, in order.
     *
     * @return string[]
     */
    private function on_screen(): array {
        $selected = helper::select_for_display(helper::retrieve_user_notices('/my/'));

        return array_values(array_map(function (awareness $n): string {
            return $n->get('title');
        }, $selected));
    }

    /**
     * Three ordinary notices arrive one at a time, oldest first, as the user deals with each.
     */
    public function test_ordinary_notices_are_shown_one_at_a_time(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $first = $this->notice('First');
        $this->notice('Second');
        $this->notice('Third');

        // Control: all three genuinely apply to this user. Without it, a queue that returned one
        // notice because the other two were filtered out would look identical.
        $this->assertCount(3, helper::retrieve_user_notices('/my/'));

        $this->assertSame(['First'], $this->on_screen());

        helper::dismiss_notice($first);
        $this->assertSame(['Second'], $this->on_screen());
    }

    /**
     * Two repeating notices meeting the user for the first time are shown together.
     */
    public function test_repeating_notices_in_their_first_occurrence_are_shown_together(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->notice('Repeat A', DAYSECS);
        $this->notice('Repeat B', DAYSECS);
        $this->notice('Ordinary');

        $this->assertSame(['Repeat A', 'Repeat B'], $this->on_screen());
    }

    /**
     * A repeating notice coming round again waits for the rest of the queue to clear.
     */
    public function test_a_returning_repeat_goes_behind_everything_else(): void {
        global $USER;

        $this->setUser($this->getDataGenerator()->create_user());
        $repeat = $this->notice('Repeat', 1);
        $this->notice('Ordinary');

        // First occurrence: it has priority, and is shown before the older ordinary notice.
        $this->assertSame(['Repeat'], $this->on_screen());

        // The user deals with it, and its interval elapses so it falls due again.
        helper::dismiss_notice($repeat);
        $USER->viewednotices[$repeat->get('id')]['timeviewed'] = time() - 100;

        // Both apply again, but the repeat now yields.
        $this->assertCount(2, helper::retrieve_user_notices('/my/'));
        $this->assertSame(['Ordinary'], $this->on_screen());
    }

    /**
     * A notice the user ignores stops holding the slot after its first appearance.
     *
     * Nothing is recorded when someone navigates away from a modal, so the queue has to remember
     * the hand-over itself. Without that, an ignored notice is for ever in its first occurrence and
     * every other notice waits behind it.
     */
    public function test_an_ignored_notice_yields_the_slot_on_the_next_page(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->notice('Ignored');
        $this->notice('Next in line');

        $this->assertSame(['Ignored'], $this->on_screen());

        // Second page load. The user did nothing at all: no dismissal, no acknowledgement.
        $this->assertSame(['Next in line'], $this->on_screen());
    }

    /**
     * An acknowledgement the user closes without accepting also yields.
     *
     * It comes back by a different route from a repeating notice — reqack survives a dismissal —
     * but it would hold the slot the same way, so it is demoted on the same rule.
     */
    public function test_an_unaccepted_acknowledgement_yields_the_slot(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $reqack = $this->notice('Must accept', 0, ['reqack' => 1]);
        $this->notice('Ordinary');

        $this->assertSame(['Must accept'], $this->on_screen());

        // Closed without accepting: it stays applicable, which is the point of reqack.
        helper::dismiss_notice($reqack);
        $applicable = helper::retrieve_user_notices('/my/');
        $this->assertArrayHasKey($reqack->get('id'), $applicable, 'reqack survives a dismissal');

        $this->assertSame(['Ordinary'], $this->on_screen());
    }

    /**
     * The queue never invents work: with nothing applicable it selects nothing.
     */
    public function test_nothing_applicable_selects_nothing(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], helper::select_for_display([]));
    }
}
