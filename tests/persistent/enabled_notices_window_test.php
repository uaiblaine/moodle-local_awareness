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

use local_awareness\local\window;

/**
 * The cached prefilter query, measured against the display decision on a real database.
 *
 * tests/local/window_test.php pins the same invariant in pure logic, but it evaluates the
 * prefilter's MEANING in PHP — a hand-written model that could drift from the SQL it stands for
 * without either file failing. This runs the actual query.
 *
 * The invariant: get_enabled_notices() may return notices that window::is_open() then rejects, but
 * it must never DROP one that is_open() accepts. The query is cached in a MODE_APPLICATION store
 * with no TTL and purged only when a notice is written, so a row it drops is not merely late — it
 * never appears at all, for as long as nobody edits a notice.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\awareness::get_enabled_notices
 */
final class enabled_notices_window_test extends \advanced_testcase {
    /**
     * Every window shape, seeded and then read back through the real query.
     *
     * @return void
     */
    public function test_the_cached_query_never_drops_a_notice_that_should_display(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $shapes = [
            'both unbounded' => [0, 0],
            'end only, in the future' => [0, $now + 3600],
            'end only, in the past' => [0, $now - 3600],
            'start only, in the past' => [$now - 3600, 0],
            'start only, in the future' => [$now + 3600, 0],
            'both, inside' => [$now - 3600, $now + 3600],
            'both, in the future' => [$now + 1800, $now + 3600],
            'both, in the past' => [$now - 3600, $now - 1800],
        ];

        $seeded = [];
        foreach ($shapes as $name => [$timestart, $timeend]) {
            $notice = new awareness(0, (object) [
                'title' => $name,
                'content' => '<p>Body.</p>',
                'enabled' => 1,
                'timestart' => $timestart,
                'timeend' => $timeend,
            ]);
            $notice->create();
            $seeded[$name] = [$timestart, $timeend];
        }

        $returned = [];
        foreach (awareness::get_enabled_notices() as $record) {
            $returned[$record->get('title')] = true;
        }

        // Non-vacuity: the query answered at all, and did not answer with everything.
        $this->assertNotEmpty($returned);
        $this->assertLessThan(count($seeded), count($returned), 'the query filtered nothing');

        $looser = 0;
        foreach ($seeded as $name => [$timestart, $timeend]) {
            $open = window::is_open($timestart, $timeend, $now);
            $present = isset($returned[$name]);

            if ($open) {
                $this->assertTrue($present, "'{$name}' should display but the cached query dropped it");
            }
            if (!$open && $present) {
                $looser++;
            }
        }

        /*
         * The prefilter must be STRICTLY looser, not merely a superset. A query identical to
         * is_open() also satisfies the implication above, and it is the thing a later edit is most
         * likely to "tidy" this into — at which point a notice whose start passes while the cache
         * is warm stops appearing entirely.
         */
        $this->assertGreaterThan(0, $looser, 'the cached query is as strict as the display decision');
    }
}
