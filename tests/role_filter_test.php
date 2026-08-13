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
 * Characterisation tests for the role rule inside filtervalues.
 *
 * Written against the behaviour as it stood before the rule was extracted from check_filters(),
 * and kept afterwards so the extraction has to reproduce it rather than merely look similar.
 *
 * Two traps shape every test here, and both make a careless test pass while exercising nothing:
 *
 * - filter_category and filter_course appear TWICE in check_filters() with different meanings.
 *   They first scope the role query (a page-independent question about the user), and then act as
 *   page-context filters in their own right. A scenario whose two meanings contradict each other —
 *   a course list naming one course and a category list naming a category that course is not in —
 *   is rejected by the later blocks before the role rule is ever reached.
 * - The course-context blocks need $course, which check_filters() only resolves through
 *   can_access_course($course, null, '', true). Without an ACTIVE enrolment that call returns
 *   false, $course becomes null, and the course block rejects first. Every test below that names a
 *   course therefore enrols the user in it, and every test that also names a category puts that
 *   course in it.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::check_filters
 */
final class role_filter_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Resolve a role id by shortname.
     *
     * @param string $shortname Role shortname.
     * @return int
     */
    private function role_id(string $shortname): int {
        global $DB;

        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Encode a filtervalues payload.
     *
     * @param array $filters Filter values as the notice form stores them.
     * @return string
     */
    private function filters(array $filters): string {
        return json_encode($filters);
    }

    /**
     * With no role context set, an assignment anywhere at all counts.
     */
    public function test_role_in_any_context_matches_when_no_role_context_is_set(): void {
        $teacherroleid = $this->role_id('editingteacher');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $plain = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $filters = $this->filters(['filter_role' => [$teacherroleid]]);

        $this->setUser($teacher);
        $this->assertTrue(helper::check_filters($filters));

        $this->setUser($plain);
        $this->assertFalse(helper::check_filters($filters));
    }

    /**
     * A system role context looks only at system-level assignments.
     */
    public function test_system_role_context_ignores_course_level_assignments(): void {
        $teacherroleid = $this->role_id('editingteacher');
        $course = $this->getDataGenerator()->create_course();
        $incourse = $this->getDataGenerator()->create_user();
        $atsystem = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($incourse->id, $course->id, 'editingteacher');
        role_assign($teacherroleid, $atsystem->id, \context_system::instance()->id);

        $filters = $this->filters([
            'filter_role' => [$teacherroleid],
            'filter_role_context' => CONTEXT_SYSTEM,
        ]);

        // The same role, held in a course, is invisible to a system-scoped rule.
        $this->setUser($incourse);
        $this->assertFalse(helper::check_filters($filters));

        // Control: held at the system context, it matches.
        $this->setUser($atsystem);
        $this->assertTrue(helper::check_filters($filters));
    }

    /**
     * Moodle's implicit default user role is added only for site-wide role contexts.
     *
     * It lives in $CFG, not in {role_assignments}, so the query cannot see it. check_filters()
     * appends it by hand — but only when the rule is unscoped or system-scoped. A notice targeted
     * at the default role with a course or category context therefore reaches nobody through this
     * mechanism, which is behaviour worth pinning rather than a rule anyone stated.
     */
    public function test_default_user_role_is_added_only_for_site_wide_role_contexts(): void {
        global $CFG;

        $this->setUser($this->getDataGenerator()->create_user());
        $defaultroleid = (int) $CFG->defaultuserroleid;
        $this->assertGreaterThan(0, $defaultroleid, 'the site must have a default user role');

        $this->assertTrue(helper::check_filters($this->filters([
            'filter_role' => [$defaultroleid],
        ])));

        $this->assertTrue(helper::check_filters($this->filters([
            'filter_role' => [$defaultroleid],
            'filter_role_context' => CONTEXT_SYSTEM,
        ])));

        $this->assertFalse(helper::check_filters($this->filters([
            'filter_role' => [$defaultroleid],
            'filter_role_context' => CONTEXT_COURSE,
        ])));

        $this->assertFalse(helper::check_filters($this->filters([
            'filter_role' => [$defaultroleid],
            'filter_role_context' => CONTEXT_COURSECAT,
        ])));
    }

    /**
     * A course role context takes the UNION of the course list and the category list.
     *
     * The two lists are joined with OR, so holding the role in any course of a listed category
     * satisfies the rule even when that course is not the one named in the course list. Nothing
     * says so anywhere; it follows only from the implode(" OR ") that builds the predicate.
     *
     * The setup exists to let both meanings of the two lists agree. The current course sits in the
     * listed category and is the one named in the course list, so the page-context blocks accept
     * it; the role itself is held in a DIFFERENT course of that same category, which only the
     * category arm of the union can reach.
     */
    public function test_course_role_context_unions_the_course_list_with_the_category_list(): void {
        $teacherroleid = $this->role_id('editingteacher');

        $category = $this->getDataGenerator()->create_category();
        $othercategory = $this->getDataGenerator()->create_category();

        $currentcourse = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $siblingcourse = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $outsidecourse = $this->getDataGenerator()->create_course(['category' => $othercategory->id]);

        $reachable = $this->getDataGenerator()->create_user();
        $unreachable = $this->getDataGenerator()->create_user();

        // Both are on the current page; neither holds the role in the course being viewed.
        $this->getDataGenerator()->enrol_user($reachable->id, $currentcourse->id);
        $this->getDataGenerator()->enrol_user($unreachable->id, $currentcourse->id);

        // One holds it in a sibling course of the listed category, the other outside it.
        $this->getDataGenerator()->enrol_user($reachable->id, $siblingcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($unreachable->id, $outsidecourse->id, 'editingteacher');

        $filters = $this->filters([
            'filter_role' => [$teacherroleid],
            'filter_role_context' => CONTEXT_COURSE,
            'filter_course' => [$currentcourse->id],
            'filter_category' => [$category->id],
        ]);

        // Matched through the category arm, despite holding no role in the course named.
        $this->setUser($reachable);
        $this->assertTrue(helper::check_filters($filters, '', (int) $currentcourse->id));

        // Control: the same role in a course outside the listed category reaches neither arm.
        $this->setUser($unreachable);
        $this->assertFalse(helper::check_filters($filters, '', (int) $currentcourse->id));
    }

    /**
     * A category role context reads the category list and never the course list.
     *
     * The branch that builds the query for CONTEXT_COURSECAT consults filter_category only. A
     * course list may be present — it still has to be, for the page-context blocks — and has no
     * effect on which contexts are searched. The contrast with CONTEXT_COURSE is what shows it:
     * the very same category-level assignment matches under one and not the other.
     */
    public function test_category_role_context_reads_the_category_list_and_not_the_course_list(): void {
        $teacherroleid = $this->role_id('editingteacher');

        $category = $this->getDataGenerator()->create_category();
        $othercategory = $this->getDataGenerator()->create_category();
        $currentcourse = $this->getDataGenerator()->create_course(['category' => $category->id]);

        $user = $this->getDataGenerator()->create_user();
        $elsewhere = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $currentcourse->id);
        $this->getDataGenerator()->enrol_user($elsewhere->id, $currentcourse->id);
        role_assign($teacherroleid, $user->id, \context_coursecat::instance($category->id)->id);
        role_assign($teacherroleid, $elsewhere->id, \context_coursecat::instance($othercategory->id)->id);

        $this->setUser($user);

        $base = [
            'filter_role' => [$teacherroleid],
            'filter_course' => [$currentcourse->id],
            'filter_category' => [$category->id],
        ];

        // The assignment lives at the category, so a category-scoped rule finds it.
        $this->assertTrue(helper::check_filters(
            $this->filters($base + ['filter_role_context' => CONTEXT_COURSECAT]),
            '',
            (int) $currentcourse->id
        ));

        // A course-scoped rule searches course contexts only and cannot see it, even though the
        // course list names a course inside that very category.
        $this->assertFalse(helper::check_filters(
            $this->filters($base + ['filter_role_context' => CONTEXT_COURSE]),
            '',
            (int) $currentcourse->id
        ));

        /*
         * And the category list is what scopes the search. Varying the filters cannot show this —
         * filter_category also drives the page-context block, so changing it rejects there first,
         * before the role rule runs. What varies instead is where the assignment lives: this user
         * holds the same role at a category the list does not name, on the same page, under the
         * identical filters that just returned true.
         */
        $this->setUser($elsewhere);
        $this->assertFalse(helper::check_filters(
            $this->filters($base + ['filter_role_context' => CONTEXT_COURSECAT]),
            '',
            (int) $currentcourse->id
        ));
    }
}
