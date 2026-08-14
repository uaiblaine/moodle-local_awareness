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
use local_awareness\persistent\noticeview;

/**
 * The caches are consulted, including when what they hold is nothing.
 *
 * Both caches guarded their lookup with a falsy test, and an empty array is falsy. The value was
 * stored, found, judged a miss, and the query ran again — on every call, for ever. The state it
 * broke in is the one nearly every site is in nearly all the time: the plugin installed with no
 * notice currently live, paying a query on every page load to be told so.
 *
 * These tests count statements rather than inspect the cache, because the cost is the point.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\awareness::get_enabled_notices
 * @covers \local_awareness\persistent\noticeview::get_user_viewed_notice_records
 */
final class cache_reuse_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Database reads consumed by a callable.
     *
     * @param callable $fn Thing to measure.
     * @return int
     */
    private function reads(callable $fn): int {
        global $DB;

        $before = $DB->perf_get_reads();
        $fn();

        return $DB->perf_get_reads() - $before;
    }

    /**
     * A site with no live notice stops paying for the answer after the first time.
     */
    public function test_an_empty_notice_list_is_cached(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $first = $this->reads(function () {
            awareness::get_enabled_notices();
        });
        $second = $this->reads(function () {
            awareness::get_enabled_notices();
        });
        $third = $this->reads(function () {
            awareness::get_enabled_notices();
        });

        $this->assertGreaterThan(0, $first, 'the first call has to actually ask the database');
        $this->assertSame(0, $second, 'an empty result is a result, and it was already fetched');
        $this->assertSame(0, $third);
    }

    /**
     * A user who has never interacted with a notice stops paying for that answer too.
     */
    public function test_an_empty_view_history_is_cached(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $first = $this->reads(function () {
            noticeview::get_user_viewed_notice_records();
        });
        $second = $this->reads(function () {
            noticeview::get_user_viewed_notice_records();
        });

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    /**
     * Caching nothing must not mean serving nothing once there is something.
     *
     * The counterpart to the two tests above: they would also pass if the caches were made to
     * always return an empty array, which would break the plugin entirely while looking fast.
     */
    public function test_a_notice_created_after_an_empty_answer_is_still_found(): void {
        $this->setAdminUser();

        // Ask first, so the empty answer is the one sitting in the cache.
        $this->assertSame([], awareness::get_enabled_notices());

        $data = new \stdClass();
        $data->title = 'Created after the empty answer';
        $data->content = '<p>Body</p>';
        helper::create_new_notice($data);

        $notices = awareness::get_enabled_notices();

        $this->assertCount(1, $notices);
        $this->assertSame('Created after the empty answer', reset($notices)->get('title'));
    }

    /**
     * Likewise for the view history: dismissing something must be visible immediately after.
     */
    public function test_a_view_recorded_after_an_empty_answer_is_still_found(): void {
        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Dismiss me';
        $data->content = '<p>Body</p>';
        helper::create_new_notice($data);
        $notices = awareness::get_enabled_notices();
        $notice = reset($notices);

        $this->setUser($this->getDataGenerator()->create_user());

        // Ask first, so the empty answer is the one sitting in the cache.
        $this->assertSame([], noticeview::get_user_viewed_notice_records());

        helper::dismiss_notice($notice);

        $this->assertCount(1, noticeview::get_user_viewed_notice_records());
    }
}
