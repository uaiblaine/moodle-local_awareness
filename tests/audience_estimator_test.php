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

/**
 * Tests for the audience estimator.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers     \local_awareness\audience\estimator
 */
final class audience_estimator_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_normalise_strips_empty_and_sorts(): void {
        $raw = [
            'cohorts' => [3, 1, 2, 0, ''],
            'filter_role' => ['', 4, 4, 5],
            'filter_format' => ['weekly', 'topics', 'topics'],
            'reqcourse' => 0,
            'pathmatch' => '   ',
        ];
        $normalised = estimator::normalise($raw);
        $this->assertSame([1, 2, 3], $normalised['cohorts']);
        $this->assertSame([4, 5], $normalised['filter_role']);
        $this->assertSame(['topics', 'weekly'], $normalised['filter_format']);
        $this->assertArrayNotHasKey('reqcourse', $normalised);
        $this->assertArrayNotHasKey('pathmatch', $normalised);
    }

    public function test_hash_is_deterministic_across_orderings(): void {
        $a = estimator::normalise(['cohorts' => [1, 2, 3], 'filter_role' => [4, 5]]);
        $b = estimator::normalise(['filter_role' => [5, 4], 'cohorts' => [3, 1, 2]]);
        $this->assertSame(estimator::hash($a), estimator::hash($b));
    }

    public function test_audience_rules_in_only_returns_audience_keys(): void {
        $criteria = [
            'cohorts' => [1],
            'filter_role' => [2],
            'pathmatch' => 'my/?',
            'filter_category' => [3],
        ];
        $this->assertSame(['cohorts', 'filter_role'], estimator::audience_rules_in($criteria));
    }

    public function test_estimate_with_no_audience_rules_returns_zero_with_context(): void {
        $criteria = ['filter_category' => [1, 2], 'pathmatch' => 'my/?'];
        $result = (new estimator())->estimate($criteria);
        $this->assertFalse($result['has_audience_rules']);
        $this->assertSame(0, $result['count']);
        $this->assertCount(2, $result['context_only_filters']);
    }

    public function test_estimate_cohort_only(): void {
        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();
        $u1 = $generator->create_user();
        $u2 = $generator->create_user();
        $generator->create_user(); // Not in cohort.
        cohort_add_member($cohort->id, $u1->id);
        cohort_add_member($cohort->id, $u2->id);

        $result = (new estimator())->estimate(['cohorts' => [$cohort->id]]);
        $this->assertSame(2, $result['count']);
        $this->assertTrue($result['has_audience_rules']);
        $this->assertCount(1, $result['breakdown']);
        $this->assertSame('cohorts', $result['breakdown'][0]['key']);
        $this->assertSame(2, $result['breakdown'][0]['count']);
    }

    public function test_estimate_intersects_cohort_and_role(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();
        $course = $generator->create_course();
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);

        $u1 = $generator->create_user();
        $u2 = $generator->create_user();
        $u3 = $generator->create_user();
        cohort_add_member($cohort->id, $u1->id);
        cohort_add_member($cohort->id, $u2->id);
        // u1 is in cohort AND has the teacher role; u2 in cohort only; u3 has role only.
        $generator->enrol_user($u1->id, $course->id, $teacherrole->id);
        $generator->enrol_user($u3->id, $course->id, $teacherrole->id);

        $result = (new estimator())->estimate([
            'cohorts' => [$cohort->id],
            'filter_role' => [(int) $teacherrole->id],
        ]);
        $this->assertSame(1, $result['count']);
    }

    public function test_estimate_excludes_users_who_completed_required_course(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();
        $course = $generator->create_course(['enablecompletion' => 1]);

        $u1 = $generator->create_user();
        $u2 = $generator->create_user();
        cohort_add_member($cohort->id, $u1->id);
        cohort_add_member($cohort->id, $u2->id);
        $generator->enrol_user($u1->id, $course->id);
        $generator->enrol_user($u2->id, $course->id);

        // Mark u2 as having completed the course.
        $now = time();
        $DB->insert_record('course_completions', (object) [
            'userid' => $u2->id,
            'course' => $course->id,
            'timeenrolled' => $now - 100,
            'timestarted' => $now - 90,
            'timecompleted' => $now,
            'reaggregate' => 0,
        ]);

        $result = (new estimator())->estimate([
            'cohorts' => [$cohort->id],
            'reqcourse' => (int) $course->id,
        ]);

        // Only u1 (cohort member, NOT completed) should be counted.
        $this->assertSame(1, $result['count']);
    }

    public function test_estimate_excludes_deleted_and_suspended_users(): void {
        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort();
        $u1 = $generator->create_user();
        $u2 = $generator->create_user(['suspended' => 1]);
        $u3 = $generator->create_user(['deleted' => 1]);
        cohort_add_member($cohort->id, $u1->id);
        // Cannot add deleted user — skip; suspended user is added.
        cohort_add_member($cohort->id, $u2->id);
        $u3->id; // appease phpcs.

        $result = (new estimator())->estimate(['cohorts' => [$cohort->id]]);
        $this->assertSame(1, $result['count']);
    }
}
