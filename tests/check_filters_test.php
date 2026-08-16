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

/**
 * Direct tests for the two page-targeting predicates.
 *
 * check_path_match() had no direct test at all — its only coverage came through two callers, one
 * of which (page_probe) reimplements the category/course/format logic in its own methods, so
 * tests passing there guarded a different copy of the rules. And four of check_filters()' six
 * branches — category, course, format and competency — had no case asserting false, which is the
 * direction that decides whether a notice targeted at one course stays out of every other.
 *
 * The pairing convention throughout: every false assertion is accompanied by the same call with
 * one input changed so it returns true. A false on its own is satisfied by any early return, and
 * these methods have several.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::check_path_match
 * @covers \local_awareness\helper::check_filters
 */
final class check_filters_test extends \advanced_testcase {
    /**
     * Path patterns, the URL they are tested against, and whether they should match.
     *
     * @return array
     */
    public static function path_match_provider(): array {
        return [
            'empty pattern admits everything' => ['', '/course/view.php?id=2', true],
            'suffix match' => ['/course/view.php', '/course/view.php?id=2', false],
            'exact tail match' => ['/mod/quiz/view.php', '/mod/quiz/view.php', true],
            'wildcard matches a tail' => ['/mod/%', '/mod/quiz/view.php', true],
            'wildcard does not match another tree' => ['/mod/%', '/course/view.php', false],
            'wildcard in the middle' => ['/mod/%/view.php', '/mod/quiz/view.php', true],
            'FRONTPAGE token on the front page' => ['FRONTPAGE', '/', true],
            'FRONTPAGE token with redirect guard' => ['FRONTPAGE', '/?redirect=0', true],
            'FRONTPAGE token off the front page' => ['FRONTPAGE', '/my/', false],
            'MY token on the dashboard' => ['MY', '/my/', true],
            'MY token is not my/courses' => ['MY', '/my/courses.php', false],
            'MYCOURSES token' => ['MYCOURSES', '/my/courses.php', true],
            'MYCOURSES token on the dashboard' => ['MYCOURSES', '/my/', false],
            'combined token covers both' => ['FRONTPAGE_MY', '/my/', true],
            'combined token covers the other' => ['FRONTPAGE_MY', '/', true],
            'combined token excludes a third' => ['FRONTPAGE_MY', '/course/view.php', false],
        ];
    }

    /**
     * The path predicate, called directly rather than through either caller.
     *
     * @dataProvider path_match_provider
     * @param string $pathmatch The stored pattern.
     * @param string $pageurl The local URL being tested.
     * @param bool $expected Whether the notice should show.
     */
    public function test_check_path_match(string $pathmatch, string $pageurl, bool $expected): void {
        $this->resetAfterTest();

        $this->assertSame($expected, helper::check_path_match($pathmatch, $pageurl));
    }

    /**
     * A pattern without a wildcard is anchored at the END only.
     *
     * Recorded because it is surprising and is its own audit finding: '/course/view.php' does not
     * match '/course/view.php?id=2', because the pattern gets a '$' appended and the query string
     * is part of the target. An author writing a plain path therefore has to know to add '%'.
     */
    public function test_a_plain_pattern_is_anchored_at_the_end(): void {
        $this->resetAfterTest();

        $this->assertFalse(helper::check_path_match('/course/view.php', '/course/view.php?id=2'));
        $this->assertTrue(helper::check_path_match('/course/view.php%', '/course/view.php?id=2'));
    }

    /**
     * Encode filters the way the notice form stores them.
     *
     * @param array $filters Raw filter array.
     * @return string JSON payload.
     */
    private function filters(array $filters): string {
        return json_encode($filters);
    }

    /**
     * Create a user, enrol them in the given courses, and log them in.
     *
     * The enrolment is not decoration. check_filters() resolves the course through
     * can_access_course($course, null, '', true), and that $onlyactive = true demands an ACTIVE
     * enrolment — so an un-enrolled user gets $course = null and every branch below returns false
     * for the "not on a course page" reason rather than the one under test. The positive controls
     * would fail and the negative ones would pass without exercising anything.
     *
     * @param array $courses Courses to enrol into.
     */
    private function login_enrolled_in(array $courses): void {
        $user = $this->getDataGenerator()->create_user();
        foreach ($courses as $course) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
        }
        $this->setUser($user);
    }

    /**
     * A category-targeted notice is refused off a course, and on a course of another category.
     */
    public function test_the_category_branch_rejects(): void {
        $this->resetAfterTest();

        $wanted = $this->getDataGenerator()->create_category();
        $other = $this->getDataGenerator()->create_category();
        $incourse = $this->getDataGenerator()->create_course(['category' => $wanted->id]);
        $outcourse = $this->getDataGenerator()->create_course(['category' => $other->id]);
        $this->login_enrolled_in([$incourse, $outcourse]);

        $payload = $this->filters(['filter_category' => [$wanted->id]]);

        // Control: on a course in the targeted category it shows.
        $this->assertTrue(helper::check_filters($payload, (int) $incourse->id));
        // Off a course entirely.
        $this->assertFalse(helper::check_filters($payload, 0));
        // On a course in a different category.
        $this->assertFalse(helper::check_filters($payload, (int) $outcourse->id));
    }

    /**
     * A course-targeted notice is refused off a course, and on a different course.
     */
    public function test_the_course_branch_rejects(): void {
        $this->resetAfterTest();

        $wanted = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->login_enrolled_in([$wanted, $other]);

        $payload = $this->filters(['filter_course' => [$wanted->id]]);

        $this->assertTrue(helper::check_filters($payload, (int) $wanted->id));
        $this->assertFalse(helper::check_filters($payload, 0));
        $this->assertFalse(helper::check_filters($payload, (int) $other->id));
    }

    /**
     * A format-targeted notice is refused off a course, and on a course of another format.
     */
    public function test_the_format_branch_rejects(): void {
        $this->resetAfterTest();

        $topics = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $weeks = $this->getDataGenerator()->create_course(['format' => 'weeks']);
        $this->login_enrolled_in([$topics, $weeks]);

        $payload = $this->filters(['filter_format' => ['topics']]);

        $this->assertTrue(helper::check_filters($payload, (int) $topics->id));
        $this->assertFalse(helper::check_filters($payload, 0));
        $this->assertFalse(helper::check_filters($payload, (int) $weeks->id));
    }

    /**
     * A competency-targeted notice is refused off a course.
     *
     * The competency subsystem is switched ON first. Without that the branch returns false at
     * is_competency_filter_enabled() and the test would pass while proving nothing about the
     * course requirement it is written for — the vacuous shape this suite has been bitten by.
     */
    public function test_the_competency_branch_rejects_off_a_course(): void {
        $this->resetAfterTest();

        /*
         * The competency subsystem is switched on through set_config('enabled', …,
         * 'core_competency'), not through $CFG->enablecompetencies: core_competency\api::is_enabled()
         * reads the plugin config, and setting the $CFG flag leaves it returning true — which would
         * take this test down the disabled branch and let it pass without ever reaching the course
         * requirement it is written for.
         */
        set_config('enabled', 1, 'core_competency');
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertTrue(helper::is_competency_filter_enabled());

        $payload = $this->filters([
            'filter_competency_rules' => [['competencyid' => 1, 'proficiency' => 1]],
        ]);

        $this->assertFalse(helper::check_filters($payload, 0));
    }

    /**
     * With competencies switched off, a competency-targeted notice never shows.
     */
    public function test_the_competency_branch_rejects_when_the_subsystem_is_off(): void {
        $this->resetAfterTest();

        set_config('enabled', 0, 'core_competency');
        $this->assertFalse(helper::is_competency_filter_enabled());

        $course = $this->getDataGenerator()->create_course();
        $this->login_enrolled_in([$course]);
        $payload = $this->filters([
            'filter_competency_rules' => [['competencyid' => 1, 'proficiency' => 1]],
        ]);

        $this->assertFalse(helper::check_filters($payload, (int) $course->id));
    }

    /**
     * An empty or absent filter payload admits everything.
     */
    public function test_an_empty_payload_admits_everything(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->login_enrolled_in([$course]);

        $this->assertTrue(helper::check_filters(null, (int) $course->id));
        $this->assertTrue(helper::check_filters('', (int) $course->id));
        $this->assertTrue(helper::check_filters('{}', (int) $course->id));
        $this->assertTrue(helper::check_filters($this->filters(['filter_course' => []]), (int) $course->id));
    }
}
