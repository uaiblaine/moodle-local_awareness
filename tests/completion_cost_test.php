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
 * What the course-completion rule costs on the page-generation path.
 *
 * This rule runs inside has_candidate_notices(), which every page load calls before any HTML is
 * sent, so a statement per notice here lands in the TTFB of every page and delays the paint. It is
 * the only rule in the plugin that does that; the rest of the per-notice work happens in the
 * asynchronous call, after the page is already on screen.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::has_candidate_notices
 */
final class completion_cost_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Reads consumed by the page-generation probe, with the caches already warm.
     *
     * @return int
     */
    private function probe_reads(): int {
        global $DB;

        // Warm everything this path touches, so what is left is the rule under test.
        helper::has_candidate_notices();

        $before = $DB->perf_get_reads();
        helper::has_candidate_notices();

        return $DB->perf_get_reads() - $before;
    }

    /**
     * Create notices requiring completion of the given courses.
     *
     * @param array $courseids One notice per entry, requiring that course.
     */
    private function notices_requiring(array $courseids): void {
        foreach ($courseids as $i => $courseid) {
            $notice = new awareness(0, (object) [
                'title' => 'Requires course ' . $i,
                'content' => '<p>Body</p>',
                'enabled' => 1,
                'reqcourse' => $courseid,
            ]);
            $notice->create();
        }
    }

    /**
     * Several notices requiring the same course cost no more than one does.
     *
     * Every notice pointed at the same course used to fetch that course again, so the page paid a
     * statement per notice for an answer that could not differ between them.
     */
    public function test_notices_sharing_a_required_course_do_not_each_cost_a_statement(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $this->notices_requiring([$course->id]);
        $one = $this->probe_reads();

        $this->notices_requiring(array_fill(0, 5, $course->id));
        $six = $this->probe_reads();

        $this->assertSame(
            $one,
            $six,
            'six notices requiring one course must cost what one notice requiring it costs'
        );
    }

    /**
     * Distinct required courses are resolved together rather than one statement at a time.
     */
    public function test_distinct_required_courses_are_resolved_in_one_go(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $courseids = [];
        for ($i = 0; $i < 6; $i++) {
            $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
            $courseids[] = $course->id;
        }

        $this->notices_requiring([$courseids[0]]);
        $one = $this->probe_reads();

        $this->notices_requiring(array_slice($courseids, 1));
        $six = $this->probe_reads();

        // The completion answer is still per course, so this cannot be flat; what it must not be is
        // a fetch of the course row per notice on top of it.
        $this->assertLessThan(
            $one * 6,
            $six,
            'resolving six required courses must cost less than repeating the one-course path six times'
        );
    }

    /**
     * The rule still does its job: a completed required course withholds the notice.
     *
     * Without this, every assertion above is satisfied by a rule that stopped running.
     */
    public function test_a_completed_required_course_still_withholds_the_notice(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $done = $this->getDataGenerator()->create_user();
        $pending = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($done->id, $course->id);
        $this->getDataGenerator()->enrol_user($pending->id, $course->id);

        $this->notices_requiring([$course->id]);

        $ccompletion = new \completion_completion(['course' => $course->id, 'userid' => $done->id]);
        $ccompletion->mark_complete(time());

        $this->setUser($pending);
        $this->assertCount(1, helper::retrieve_user_notices('/my/'), 'not completed, so still shown');

        $this->setUser($done);
        $this->assertSame([], helper::retrieve_user_notices('/my/'), 'completed, so withheld');
    }
}
