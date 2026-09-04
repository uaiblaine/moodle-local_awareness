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

use local_awareness\audience\estimator;
use local_awareness\persistent\awareness;

/**
 * A required course that no longer exists withholds its notice, on every path that reads the rule.
 *
 * "Show until they have completed course X" was read as "show unless X is recorded complete", and
 * a deleted course records nothing: the display path skipped the course it could not find and left
 * the notice in, the write-path gate fell through to true, and the estimator's NOT EXISTS over
 * {course_completions} went vacuously true because deleting a course purges those rows. So the one
 * rule meant to narrow the audience widened it to everyone, for ever, the day its course was
 * deleted — in a plugin whose every other rule withholds a notice it cannot evaluate.
 *
 * Every test keeps a second notice, whose course still exists and is incomplete, beside the one
 * under test, and reads the notice under test BEFORE the deletion as well. The withholding has to
 * be caused by the deletion, not by the notice never having been eligible — a rule that stopped
 * running altogether would otherwise pass the "withheld" half of each test.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::retrieve_user_notices
 * @covers \local_awareness\helper::is_notice_available_to_user
 * @covers \local_awareness\audience\estimator::estimate
 */
final class reqcourse_missing_course_test extends \advanced_testcase {
    /** @var \stdClass The course that will be deleted. */
    private \stdClass $doomed;

    /** @var \stdClass The course that stays, and that nobody completes. */
    private \stdClass $control;

    /** @var awareness The notice requiring the doomed course. */
    private awareness $doomednotice;

    /** @var awareness The notice requiring the control course. */
    private awareness $controlnotice;

    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $this->doomed = $generator->create_course(['enablecompletion' => 1]);
        $this->control = $generator->create_course(['enablecompletion' => 1]);
        $this->doomednotice = $this->notice_requiring('Requires the doomed course', (int) $this->doomed->id);
        $this->controlnotice = $this->notice_requiring('Requires the control course', (int) $this->control->id);
    }

    /**
     * An enabled notice requiring completion of the given course.
     *
     * @param string $title The title.
     * @param int $courseid The required course.
     * @return awareness
     */
    private function notice_requiring(string $title, int $courseid): awareness {
        $notice = new awareness(0, (object) [
            'title' => $title,
            'content' => '<p>Body</p>',
            'enabled' => 1,
            'reqcourse' => $courseid,
        ]);
        $notice->create();

        return $notice;
    }

    /**
     * Delete the doomed course, and prove the deletion did what the SQL path's failure depended on.
     */
    private function delete_doomed_course(): void {
        global $DB;

        delete_course($this->doomed, false);

        $this->assertFalse($DB->record_exists('course', ['id' => $this->doomed->id]), 'the course is gone');
        $this->assertFalse(
            $DB->record_exists('course_completions', ['course' => $this->doomed->id]),
            'deleting the course purged its completion rows, which is what made a NOT EXISTS over them vacuously true'
        );
    }

    /**
     * The ids of the given notices, sorted, so two lists compare regardless of order.
     *
     * @param array $notices The notices.
     * @return int[]
     */
    private function ids(array $notices): array {
        $ids = array_map(
            static function (awareness $notice): int {
                return (int) $notice->get('id');
            },
            array_values($notices)
        );
        sort($ids);

        return $ids;
    }

    /**
     * The display path withholds a notice whose required course was deleted.
     *
     * The control notice, requiring a live course the user has not completed, keeps showing in the
     * same call; and both showed before the deletion.
     */
    public function test_the_display_path_withholds_a_notice_whose_course_was_deleted(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->doomed->id);
        $this->getDataGenerator()->enrol_user($user->id, $this->control->id);
        $this->setUser($user);

        $this->assertSame(
            $this->ids([$this->doomednotice, $this->controlnotice]),
            $this->ids(helper::retrieve_user_notices('/my/')),
            'both notices show while both courses exist'
        );

        $this->delete_doomed_course();

        $this->assertSame(
            $this->ids([$this->controlnotice]),
            $this->ids(helper::retrieve_user_notices('/my/')),
            'the notice requiring the deleted course is withheld; the one requiring a live, incomplete course still shows'
        );
    }

    /**
     * The write-path gate refuses a notice whose required course was deleted.
     */
    public function test_the_write_gate_refuses_a_notice_whose_course_was_deleted(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->doomed->id);
        $this->getDataGenerator()->enrol_user($user->id, $this->control->id);
        $this->setUser($user);

        $this->assertTrue(helper::is_notice_available_to_user($this->doomednotice), 'available while its course exists');

        $this->delete_doomed_course();

        $this->assertFalse(helper::is_notice_available_to_user($this->doomednotice), 'refused once its course is gone');
        $this->assertTrue(helper::is_notice_available_to_user($this->controlnotice), 'a live, incomplete course still admits');
    }

    /**
     * The estimate counts nobody for a deleted required course.
     *
     * One of the two cohort members completed the doomed course before the deletion, so the count
     * before it is one — proof the predicate discriminates — and the purge of that completion row
     * is exactly what used to turn the count into two.
     */
    public function test_the_estimate_counts_nobody_for_a_deleted_course(): void {
        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();
        $done = $generator->create_user();
        $pending = $generator->create_user();
        cohort_add_member($cohort->id, $done->id);
        cohort_add_member($cohort->id, $pending->id);
        $generator->enrol_user($done->id, $this->doomed->id);
        $generator->enrol_user($pending->id, $this->doomed->id);

        $completion = new \completion_completion(['course' => $this->doomed->id, 'userid' => $done->id]);
        $completion->mark_complete(time());

        $count = static function (int $courseid) use ($cohort): int {
            $criteria = estimator::normalise(['cohorts' => [$cohort->id], 'reqcourse' => $courseid]);

            return (int) (new estimator())->estimate($criteria)['count'];
        };

        $this->assertSame(1, $count((int) $this->doomed->id), 'one of the two has not completed the course');

        $this->delete_doomed_course();

        $this->assertSame(0, $count((int) $this->doomed->id), 'nobody, once the course is gone');
        $this->assertSame(2, $count((int) $this->control->id), 'both, for a live course neither has completed');
    }
}
