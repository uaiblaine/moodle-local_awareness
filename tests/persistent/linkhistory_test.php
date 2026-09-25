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

namespace local_awareness\persistent;

/**
 * Tests for the hyperlink click history persistent.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\linkhistory
 */
final class linkhistory_test extends \advanced_testcase {
    /**
     * Seed a notice with one hyperlink and a number of recorded clicks by one user.
     *
     * @param int $clicks How many click rows to record.
     * @return int The link id.
     */
    private function seed_clicks(int $clicks): int {
        $user = $this->getDataGenerator()->create_user();

        $notice = new awareness(0, (object) [
            'title' => 'Policy update',
            'content' => '<p>Read <a href="https://example.com/policy">the policy</a>.</p>',
        ]);
        $notice->create();

        $link = noticelink::create_new_link((object) [
            'noticeid' => $notice->get('id'),
            'text' => 'the policy',
            'link' => 'https://example.com/policy',
        ]);

        for ($i = 0; $i < $clicks; $i++) {
            (new linkhistory(0, (object) [
                'hlinkid' => $link->get('id'),
                'userid' => $user->id,
            ]))->create();
        }

        return (int) $link->get('id');
    }

    /**
     * Deleting the history of some links takes every click on them and none on any other link.
     *
     * The untouched link is the control: a delete that ignored its list and emptied the table
     * would pass the first assertion alone.
     */
    public function test_deleting_link_history_takes_only_the_named_links(): void {
        global $DB;

        $this->resetAfterTest();

        $doomed = $this->seed_clicks(3);
        $kept = $this->seed_clicks(2);

        linkhistory::delete_link_history([$doomed]);

        $this->assertSame(0, $DB->count_records(linkhistory::TABLE, ['hlinkid' => $doomed]));
        $this->assertSame(2, $DB->count_records(linkhistory::TABLE, ['hlinkid' => $kept]));
    }

    /**
     * An empty list deletes nothing, rather than reaching get_in_or_equal() with no values.
     */
    public function test_deleting_the_history_of_no_links_deletes_nothing(): void {
        global $DB;

        $this->resetAfterTest();

        $this->seed_clicks(2);

        linkhistory::delete_link_history([]);

        $this->assertSame(2, $DB->count_records(linkhistory::TABLE));
    }
}
