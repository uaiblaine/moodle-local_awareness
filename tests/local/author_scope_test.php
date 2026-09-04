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

namespace local_awareness\local;

use local_awareness\audience\estimator;
use local_awareness\helper;

/**
 * Tests for the author scope: what a site-level and a course-level author may target.
 *
 * Every test here pairs the thing that must be dropped with a thing that must survive in the same
 * call. A test that only asserted the drop would pass against a scope that emptied every field,
 * and one that only asserted survival would pass against a scope that touched nothing — both
 * shapes this repository has shipped before.
 *
 * The course scope has no production caller yet: these tests build it by hand, which is the point.
 * The policy is pinned before anything is granted against it.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\author_scope
 * @covers \local_awareness\local\scope_result
 */
final class author_scope_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * An id no row of a table carries, proven rather than assumed.
     *
     * @param string $table Table name without braces.
     * @return int
     */
    private function missing_id(string $table): int {
        global $DB;

        $id = (int) $DB->get_field_sql('SELECT MAX(id) FROM {' . $table . '}') + 1000;
        $this->assertFalse($DB->record_exists($table, ['id' => $id]), "{$table} {$id} exists; the fixture is wrong");

        return $id;
    }

    /**
     * Create a competency inside a fresh framework.
     *
     * @return int The competency id.
     */
    private function create_competency(): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);

        return (int) $competency->get('id');
    }

    /**
     * The site scope refuses a value that names something the site does not have.
     *
     * One field per case, each submitted with a real value beside the fake one: the fake must go,
     * the real one must stay, and the page pattern in the same call must be untouched. Looped
     * rather than data-provided for the reason tests/local/collision_test.php records.
     */
    public function test_the_site_scope_refuses_what_the_site_does_not_have(): void {
        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_category();
        $roleid = (int) $this->getDataGenerator()->create_role();

        $cases = [
            'filter_course' => [[(int) $course->id, $this->missing_id('course')], [(int) $course->id]],
            'filter_category' => [[(int) $category->id, $this->missing_id('course_categories')], [(int) $category->id]],
            'filter_role' => [[$roleid, $this->missing_id('role')], [$roleid]],
            'filter_format' => [['topics', 'nosuchformat'], ['topics']],
            'filter_theme' => [['boost', 'nosuchtheme'], ['boost']],
            'filter_role_context' => [99, 0],
            'reqcourse' => [$this->missing_id('course'), 0],
        ];

        foreach ($cases as $field => [$submitted, $expected]) {
            $result = author_scope::site()->apply([$field => $submitted, 'pathmatch' => '/my/']);

            $this->assertSame([$field => author_scope::PROBLEM_MISSING], $result->problems(), $field);
            $this->assertSame($expected, $result->criteria()[$field], $field);
            $this->assertSame('/my/', $result->criteria()['pathmatch'], "{$field} disturbed a LEAVE field");
        }

        // A competency rule is kept or dropped by its competency; the switch beside it is untouched.
        $competencyid = $this->create_competency();
        $result = author_scope::site()->apply([
            'filter_competency_rules' => [
                ['id' => $competencyid, 'proficient' => 1],
                ['id' => $this->missing_id('competency'), 'proficient' => 0],
            ],
            'filter_competency_requireall' => 1,
        ]);

        $this->assertSame(['filter_competency_rules' => author_scope::PROBLEM_MISSING], $result->problems());
        $this->assertSame([$competencyid], array_column($result->criteria()['filter_competency_rules'], 'id'));
        $this->assertSame(1, $result->criteria()['filter_competency_requireall']);
    }

    /**
     * The site course is not a course a notice can target.
     *
     * check_filters() resolves a course only above SITEID and the estimator excludes it, so a
     * notice naming it would reach nobody; the picker never offers it. The real course in the same
     * list is the control.
     */
    public function test_the_site_course_is_refused_as_a_target(): void {
        $course = $this->getDataGenerator()->create_course();

        $result = author_scope::site()->apply(['filter_course' => [SITEID, (int) $course->id], 'reqcourse' => SITEID]);

        $this->assertSame(
            ['filter_course' => author_scope::PROBLEM_MISSING, 'reqcourse' => author_scope::PROBLEM_MISSING],
            $result->problems()
        );
        $this->assertSame([(int) $course->id], $result->criteria()['filter_course']);
        $this->assertSame(0, $result->criteria()['reqcourse']);
    }

    /**
     * A payload the site has nothing against comes back as it went in, and stays that way.
     *
     * The control for every refusal above: without it, a scope that emptied every field would pass
     * them all. Applying the scope's own output again must change nothing, or the write path and
     * the estimate — which each apply it once, to different copies — could disagree.
     */
    public function test_the_site_scope_passes_a_payload_it_has_nothing_against_unchanged(): void {
        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_category();
        $roleid = (int) $this->getDataGenerator()->create_role();
        $cohort = $this->getDataGenerator()->create_cohort();
        $competencyid = $this->create_competency();

        $criteria = [
            'filter_course' => [(int) $course->id],
            'filter_role_context' => CONTEXT_COURSE,
            'filter_category' => [(int) $category->id],
            'filter_format' => ['topics'],
            'filter_theme' => ['boost'],
            'reqcourse' => (int) $course->id,
            'cohorts' => [(int) $cohort->id],
            'filter_role' => [$roleid],
            'filter_competency_rules' => [['id' => $competencyid, 'proficient' => 1, 'name' => 'Listening']],
            'pathmatch' => '/course/%',
            'filter_competency_requireall' => 1,
        ];

        $result = author_scope::site()->apply($criteria);

        $this->assertTrue($result->is_clean(), 'a clean payload was reported: ' . implode(', ', $result->problem_fields()));
        $this->assertSame($criteria, $result->criteria());
        $this->assertSame($criteria, author_scope::site()->apply($result->criteria())->criteria());
    }

    /**
     * A cohort the author may not target is dropped without a word, in whichever shape it arrives.
     *
     * The one deliberate silence: the pickers only ever offer allowed cohorts, so a disallowed id is
     * a hand-made request, and reporting it would confirm the cohort exists. The persistent stores
     * cohorts as a comma-separated string, so that shape has to be read too.
     */
    public function test_a_cohort_that_cannot_be_targeted_is_dropped_without_a_word(): void {
        $cohort = $this->getDataGenerator()->create_cohort();
        $missing = $this->missing_id('cohort');

        foreach ([[(int) $cohort->id, $missing], $cohort->id . ',' . $missing] as $shape) {
            $result = author_scope::site()->apply(['cohorts' => $shape]);

            $this->assertTrue($result->is_clean(), 'cohorts must be narrowed silently');
            $this->assertSame([(int) $cohort->id], $result->criteria()['cohorts']);
        }
    }

    /**
     * A course scope writes the course and the role context, whatever was submitted for them.
     *
     * The page pattern in the same call proves the overwrite is targeted; the site scope keeping the
     * other course proves the overwrite is the course scope's doing and not a bug in the course list.
     */
    public function test_the_course_scope_forces_the_course_and_the_role_context(): void {
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $mine->id);

        $result = $scope->apply([
            'filter_course' => [(int) $other->id],
            'filter_role_context' => 0,
            'pathmatch' => '/mod/%',
        ]);

        $this->assertTrue($result->is_clean(), 'forcing a field is not a problem to report');
        $this->assertSame([(int) $mine->id], $result->criteria()['filter_course']);
        $this->assertSame(CONTEXT_COURSE, $result->criteria()['filter_role_context']);
        $this->assertSame('/mod/%', $result->criteria()['pathmatch']);

        // Forced even when nothing was submitted for them: an empty POST cannot reach the site.
        $result = $scope->apply([]);
        $this->assertSame([(int) $mine->id], $result->criteria()['filter_course']);
        $this->assertSame(CONTEXT_COURSE, $result->criteria()['filter_role_context']);

        // Control: the site scope keeps the other course.
        $result = author_scope::site()->apply(['filter_course' => [(int) $other->id], 'filter_role_context' => 0]);
        $this->assertSame([(int) $other->id], $result->criteria()['filter_course']);
        $this->assertSame(0, $result->criteria()['filter_role_context']);
    }

    /**
     * A course scope drops the fields that reach outside the course, and says so.
     *
     * Asserted in the same call as a page pattern that must survive, so an implementation that
     * returned an empty array reddens. An empty submission for these fields is not a problem.
     */
    public function test_the_course_scope_forbids_the_fields_that_reach_outside_it(): void {
        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_category();
        $scope = author_scope::course((int) $course->id);

        $result = $scope->apply([
            'filter_category' => [(int) $category->id],
            'filter_format' => ['topics'],
            'filter_theme' => ['boost'],
            'pathmatch' => '/mod/quiz/%',
        ]);

        $this->assertSame(
            [
                'filter_category' => author_scope::PROBLEM_FORBIDDEN,
                'filter_format' => author_scope::PROBLEM_FORBIDDEN,
                'filter_theme' => author_scope::PROBLEM_FORBIDDEN,
            ],
            $result->problems()
        );
        $this->assertSame([], $result->criteria()['filter_category']);
        $this->assertSame([], $result->criteria()['filter_format']);
        $this->assertSame([], $result->criteria()['filter_theme']);
        $this->assertSame('/mod/quiz/%', $result->criteria()['pathmatch']);

        $result = $scope->apply([
            'filter_category' => [],
            'filter_format' => [''],
            'filter_theme' => ['_qf__force_multiselect_submission'],
        ]);
        $this->assertTrue($result->is_clean(), 'an empty submission is not a problem');
    }

    /**
     * A course scope keeps only the cohorts the course enrols from.
     *
     * Not the cohorts visible from the course context — cohort_get_available_cohorts() answers
     * that, and it returns every visible cohort in the category ancestry plus every system cohort.
     * The wired cohort is the control; the site scope keeping both is the second.
     */
    public function test_the_course_scope_keeps_only_the_cohorts_the_course_enrols_from(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $wired = $this->getDataGenerator()->create_cohort();
        $unwired = $this->getDataGenerator()->create_cohort();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        enrol_get_plugin('cohort')->add_instance($course, ['customint1' => $wired->id, 'roleid' => $studentroleid]);

        $submitted = ['cohorts' => [(int) $wired->id, (int) $unwired->id]];

        $result = author_scope::course((int) $course->id)->apply($submitted);
        $this->assertTrue($result->is_clean(), 'cohorts are narrowed silently in every scope');
        $this->assertSame([(int) $wired->id], $result->criteria()['cohorts']);

        $this->assertSame($submitted['cohorts'], author_scope::site()->apply($submitted)->criteria()['cohorts']);
    }

    /**
     * A course scope keeps only the roles the site allows to be assigned in a course.
     *
     * A fact about the site, read from get_roles_for_contextlevels(CONTEXT_COURSE), not about the
     * caller — get_assignable_roles() would empty for a non-editing teacher. The site scope keeping
     * both roles is the control: both exist, and existence is all the site asks.
     */
    public function test_the_course_scope_keeps_only_roles_the_site_allows_in_a_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $incourse = (int) $this->getDataGenerator()->create_role();
        set_role_contextlevels($incourse, [CONTEXT_COURSE]);
        $systemonly = (int) $this->getDataGenerator()->create_role();
        set_role_contextlevels($systemonly, [CONTEXT_SYSTEM]);

        $submitted = ['filter_role' => [$incourse, $systemonly]];

        $result = author_scope::course((int) $course->id)->apply($submitted);
        $this->assertSame(['filter_role' => author_scope::PROBLEM_OUTSIDE], $result->problems());
        $this->assertSame([$incourse], $result->criteria()['filter_role']);

        $result = author_scope::site()->apply($submitted);
        $this->assertTrue($result->is_clean());
        $this->assertSame([$incourse, $systemonly], $result->criteria()['filter_role']);
    }

    /**
     * A course scope keeps only the competencies linked to the course.
     *
     * The switch beside the rules is untouched, and the site scope keeps both rules: both
     * competencies exist.
     */
    public function test_the_course_scope_keeps_only_competencies_linked_to_the_course(): void {
        set_config('enabled', 1, 'core_competency');
        $course = $this->getDataGenerator()->create_course();
        $linked = $this->create_competency();
        $unlinked = $this->create_competency();
        \core_competency\api::add_competency_to_course($course->id, $linked);

        $submitted = [
            'filter_competency_rules' => [
                ['id' => $linked, 'proficient' => 1],
                ['id' => $unlinked, 'proficient' => 1],
            ],
            'filter_competency_requireall' => 1,
        ];

        $result = author_scope::course((int) $course->id)->apply($submitted);
        $this->assertSame(['filter_competency_rules' => author_scope::PROBLEM_OUTSIDE], $result->problems());
        $this->assertSame([$linked], array_column($result->criteria()['filter_competency_rules'], 'id'));
        $this->assertSame(1, $result->criteria()['filter_competency_requireall']);

        $result = author_scope::site()->apply($submitted);
        $this->assertTrue($result->is_clean());
        $this->assertSame([$linked, $unlinked], array_column($result->criteria()['filter_competency_rules'], 'id'));
    }

    /**
     * A course scope lets the required course be itself or none, and nothing else.
     *
     * "Keep asking until they finish MY course" is legitimate; naming another course would make the
     * audience count a completion oracle over a course the author does not teach.
     */
    public function test_the_course_scope_restricts_the_required_course_to_itself_or_none(): void {
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $mine->id);

        foreach ([0, (int) $mine->id] as $allowed) {
            $result = $scope->apply(['reqcourse' => $allowed]);
            $this->assertTrue($result->is_clean(), "reqcourse {$allowed} is allowed in its own course");
            $this->assertSame($allowed, $result->criteria()['reqcourse']);
        }

        $result = $scope->apply(['reqcourse' => (int) $other->id]);
        $this->assertSame(['reqcourse' => author_scope::PROBLEM_OUTSIDE], $result->problems());
        $this->assertSame(0, $result->criteria()['reqcourse']);
    }

    /**
     * The rule table names exactly the fields the estimator knows, and every rule is applicable.
     *
     * Both directions: a field added to the estimator without a rule here, or a rule here for a
     * field the estimator does not count, reddens. The two modifiers are the only keys outside the
     * estimator's own lists, and they are listed by name.
     */
    public function test_the_rule_table_covers_exactly_the_fields_the_estimator_knows(): void {
        $known = array_merge(
            estimator::AUDIENCE_FIELDS,
            estimator::CONTEXT_FIELDS,
            ['filter_role_context', 'filter_competency_requireall']
        );
        sort($known);
        $ruled = array_keys(author_scope::RULES);
        sort($ruled);

        $this->assertSame($known, $ruled);

        $site = author_scope::site();
        $course = author_scope::course(2);
        foreach (array_keys(author_scope::RULES) as $field) {
            $this->assertContains(
                $site->rule_for($field),
                [author_scope::RULE_EXISTS, author_scope::RULE_RESTRICT, author_scope::RULE_LEAVE],
                $field
            );
            $this->assertContains(
                $course->rule_for($field),
                [author_scope::RULE_FORCE, author_scope::RULE_FORBID, author_scope::RULE_RESTRICT, author_scope::RULE_LEAVE],
                $field
            );
        }
    }

    /**
     * The site course is the site scope, not a course scope.
     */
    public function test_a_course_scope_needs_a_real_course(): void {
        $this->assertTrue(author_scope::site()->is_site());
        $this->assertFalse(author_scope::course(2)->is_site());
        $this->assertSame(2, author_scope::course(2)->get_courseid());

        $this->expectException(\coding_exception::class);
        author_scope::course(SITEID);
    }

    /**
     * A value submitted twice is kept once, in an id list and in a name list.
     *
     * The deduplication is what lets the bound below mean "distinct things", and nothing else in
     * the suite submits a duplicate.
     */
    public function test_a_repeated_value_is_kept_once(): void {
        $course = $this->getDataGenerator()->create_course();

        $result = author_scope::site()->apply([
            'filter_course' => [(int) $course->id, (int) $course->id],
            'filter_theme' => ['boost', 'boost'],
        ]);

        $this->assertTrue($result->is_clean());
        $this->assertSame([(int) $course->id], $result->criteria()['filter_course']);
        $this->assertSame(['boost'], $result->criteria()['filter_theme']);
    }

    /**
     * Every list is bounded, and cohorts are narrowed before they are bounded.
     *
     * The existence lookups bind one placeholder per id, so a hand-made list is cut before it
     * reaches the database: more real courses than the bound come back as the first bound of them,
     * in order. Cohorts are narrowed in PHP first, so a legitimate cohort sitting behind six
     * hundred ids that were going to be dropped anyway survives — the control that tells
     * narrow-then-bound from bound-then-narrow.
     */
    public function test_lists_are_bounded_and_cohorts_are_narrowed_first(): void {
        $cohort = $this->getDataGenerator()->create_cohort();
        $missing = $this->missing_id('cohort');
        $junk = range($missing, $missing + helper::CRITERIA_LIST_MAX + 100);

        $result = author_scope::site()->apply(['cohorts' => array_merge($junk, [(int) $cohort->id])]);
        $this->assertTrue($result->is_clean());
        $this->assertSame([(int) $cohort->id], $result->criteria()['cohorts']);

        $ids = $this->getDataGenerator()->get_plugin_generator('local_awareness')
            ->create_bare_courses(helper::CRITERIA_LIST_MAX + 50);
        $result = author_scope::site()->apply(['filter_course' => $ids]);
        $this->assertTrue($result->is_clean());
        $this->assertSame(array_slice($ids, 0, helper::CRITERIA_LIST_MAX), $result->criteria()['filter_course']);
    }

    /**
     * A stored notice resolves to the scope it was written under.
     *
     * Three rows in one test — no course, the site course, a real course — so an of() that always
     * answered either scope reddens, and the site course is pinned as the site rather than as a
     * course scope that no capability could ever be held in.
     */
    public function test_a_stored_notice_resolves_to_the_scope_it_was_written_under(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');

        $this->assertTrue(author_scope::of($generator->create_notice())->is_site(), 'no course is the site');
        $sitecourse = $generator->create_notice(['courseid' => SITEID]);
        $this->assertTrue(author_scope::of($sitecourse)->is_site(), 'the site course is the site');

        $scope = author_scope::of($generator->create_notice(['courseid' => $course->id]));
        $this->assertFalse($scope->is_site());
        $this->assertSame((int) $course->id, $scope->get_courseid());
        $this->assertSame(\context_course::instance($course->id)->id, $scope->context()->id);
    }

    /**
     * A scope knows the context its decisions are taken in.
     *
     * Against a real generated course, so the lookup is a real one; and both scopes in one test,
     * so an implementation returning either context unconditionally reddens.
     */
    public function test_the_scope_knows_the_context_it_is_decided_in(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->assertInstanceOf(\context_system::class, author_scope::site()->context());

        $context = author_scope::course((int) $course->id)->context();
        $this->assertSame(CONTEXT_COURSE, (int) $context->contextlevel);
        $this->assertSame((int) $course->id, (int) $context->instanceid);
    }
}
