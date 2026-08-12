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
     * @return array{0: int, 1: int, 2: int} Notice id, link id, user id.
     */
    private function seed_clicks(int $clicks): array {
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

        return [(int) $notice->get('id'), (int) $link->get('id'), (int) $user->id];
    }

    /**
     * The aggregate must be exposed under an explicit alias.
     *
     * An unaliased COUNT() is named 'count' by PostgreSQL and 'COUNT(h.hlinkid)' by
     * MySQL/MariaDB, so the property the report reads existed on one driver only and the
     * link-click column rendered empty on the other. Asserting the alias — and the value —
     * fails on every driver if the alias is dropped again.
     *
     * @covers \local_awareness\persistent\linkhistory::count_clicked_links
     */
    public function test_count_clicked_links_exposes_an_aliased_count(): void {
        $this->resetAfterTest();

        [$noticeid, $linkid, $userid] = $this->seed_clicks(3);

        $counts = linkhistory::count_clicked_links($userid, $noticeid);

        $this->assertCount(1, $counts);
        $row = reset($counts);
        // Uses property_exists() rather than assertObjectHasProperty(): the latter only exists
        // from PHPUnit 10.1, and Moodle 4.5 — inside the supported range — ships PHPUnit 9.6.
        $this->assertTrue(property_exists($row, 'clickcount'));
        $this->assertEquals(3, $row->clickcount);
        $this->assertEquals($linkid, $row->hlinkid);
        $this->assertSame('the policy', $row->text);
    }

    /**
     * The same alias must be present when the query is narrowed to a single link.
     *
     * @covers \local_awareness\persistent\linkhistory::count_clicked_links
     */
    public function test_count_clicked_links_exposes_an_aliased_count_for_one_link(): void {
        $this->resetAfterTest();

        [$noticeid, $linkid, $userid] = $this->seed_clicks(2);

        $counts = linkhistory::count_clicked_links($userid, $noticeid, $linkid);

        $this->assertArrayHasKey($linkid, $counts);
        $this->assertTrue(property_exists($counts[$linkid], 'clickcount'));
        $this->assertEquals(2, $counts[$linkid]->clickcount);
    }

    /**
     * A user who never clicked gets no rows — the control that proves the counts above
     * come from the seeded clicks rather than from the join returning everything.
     *
     * @covers \local_awareness\persistent\linkhistory::count_clicked_links
     */
    public function test_count_clicked_links_is_scoped_to_the_user(): void {
        $this->resetAfterTest();

        [$noticeid, , $userid] = $this->seed_clicks(2);
        $other = $this->getDataGenerator()->create_user();

        $this->assertNotEmpty(linkhistory::count_clicked_links($userid, $noticeid));
        $this->assertEmpty(linkhistory::count_clicked_links((int) $other->id, $noticeid));
    }
}
