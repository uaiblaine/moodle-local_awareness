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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the audience estimator.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(estimator::class)]
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

    public function test_audience_rules_in_returns_every_rule_answerable_about_a_user(): void {
        $criteria = [
            'cohorts' => [1],
            'filter_role' => [2],
            'pathmatch' => 'my/?',
            'filter_category' => [3],
            'filter_theme' => ['boost'],
        ];
        $this->assertSame(
            ['cohorts', 'filter_role', 'filter_category'],
            estimator::audience_rules_in($criteria)
        );
    }

    /**
     * Only the two rules that are properties of the page stay out of the count.
     *
     * The category, course, format and competency rules were context-only until an author pointed
     * out that they do bound who can ever see the notice. What remains genuinely uncountable is the
     * URL the page is served at and the theme it is served in — neither says anything about a user.
     */
    public function test_only_the_page_rules_are_context_only(): void {
        $criteria = [
            'pathmatch' => 'my/?',
            'filter_theme' => ['boost'],
            'filter_category' => [1],
            'filter_course' => [2],
            'filter_format' => ['topics'],
            'filter_competency_rules' => [['id' => 3, 'proficient' => 1, 'name' => 'x']],
        ];

        $keys = array_column(estimator::context_rules_in($criteria), 'key');
        $this->assertSame(['pathmatch', 'filter_theme'], $keys);
    }

    /**
     * A notice with nothing narrowed reaches the whole site, and says so.
     *
     * Zero was the old answer, and the editor rendered it as "— " beside an unchanged prompt, so
     * pressing Calculate reach on a fresh notice looked like a broken button rather than a notice
     * aimed at everyone. The control is the suspended user: the count has to be the ACTIVE
     * population, not simply every row in {user}.
     */
    public function test_estimate_with_no_rules_counts_every_active_user(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $generator->create_user();
        $generator->create_user();
        $generator->create_user(['suspended' => 1]);

        $expected = (int) $DB->count_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND confirmed = 1 AND username <> :guestname',
            ['guestname' => 'guest']
        );

        $result = (new estimator())->estimate([]);

        $this->assertFalse($result['has_audience_rules']);
        $this->assertSame($expected, $result['count']);
        $this->assertSame([], $result['breakdown']);
        // Control: the suspended user exists and is not in the number above.
        $this->assertSame(1, (int) $DB->count_records('user', ['suspended' => 1]));
    }

    /**
     * Page-only rules do not narrow the count, but no longer zero it either.
     */
    public function test_page_only_rules_leave_the_count_at_the_whole_site(): void {
        $this->getDataGenerator()->create_user();

        $everyone = (new estimator())->estimate([])['count'];
        $result = (new estimator())->estimate(['pathmatch' => 'my/?', 'filter_theme' => ['boost']]);

        $this->assertFalse($result['has_audience_rules']);
        $this->assertSame($everyone, $result['count']);
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
        // User u1 is in cohort AND has the teacher role; u2 in cohort only; u3 has role only.
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
        $u3->id; // Appease phpcs.

        $result = (new estimator())->estimate(['cohorts' => [$cohort->id]]);
        $this->assertSame(1, $result['count']);
    }

    /**
     * The course rule counts the people enrolled in that course, not the whole site.
     *
     * check_filters() only ever shows a course-targeted notice on a course page the user can enter,
     * so the reach is bounded by enrolment. The two controls matter more than the assertion: an
     * outsider proves the rule narrows at all, and a second enrolled user proves it is not simply
     * counting one row.
     */
    public function test_the_course_rule_counts_the_people_enrolled_in_it(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $elsewhere = $generator->create_course();

        $in1 = $generator->create_user();
        $in2 = $generator->create_user();
        $out = $generator->create_user();
        $generator->enrol_user($in1->id, $course->id);
        $generator->enrol_user($in2->id, $course->id);
        $generator->enrol_user($out->id, $elsewhere->id);

        $criteria = estimator::normalise(['filter_course' => [$course->id]]);
        $result = (new estimator())->estimate($criteria);

        $this->assertTrue($result['has_audience_rules']);
        $this->assertSame(2, $result['count']);
        // Control: the site holds more active users than the rule admits.
        $this->assertGreaterThan(2, (new estimator())->estimate([])['count']);
    }

    /**
     * A suspended enrolment, and one whose window has closed, are not reach.
     *
     * This is the half of get_enrolled_join($onlyactive = true) that is easiest to drop when
     * inlining it, and dropping it is invisible: the count merely reads high.
     */
    public function test_the_course_rule_ignores_inactive_enrolments(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $active = $generator->create_user();
        $suspended = $generator->create_user();
        $expired = $generator->create_user();

        $generator->enrol_user($active->id, $course->id);
        $generator->enrol_user($suspended->id, $course->id, null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->enrol_user($expired->id, $course->id, null, 'manual', time() - 200, time() - 100);

        $criteria = estimator::normalise(['filter_course' => [$course->id]]);

        $this->assertSame(1, (new estimator())->estimate($criteria)['count']);
        // Control: all three enrolments exist, so the rule is discriminating rather than missing.
        $this->assertSame(3, $DB->count_records('user_enrolments'));
    }

    /**
     * A hidden course is not reach for the people enrolled in it.
     *
     * can_access_course() refuses one to anyone without moodle/course:viewhiddencourses, which is
     * nobody in the population this counts by default.
     */
    public function test_the_course_rule_skips_hidden_courses(): void {
        $generator = $this->getDataGenerator();
        $visible = $generator->create_course();
        $hidden = $generator->create_course(['visible' => 0]);

        $seen = $generator->create_user();
        $unseen = $generator->create_user();
        $generator->enrol_user($seen->id, $visible->id);
        $generator->enrol_user($unseen->id, $hidden->id);

        $count = (new estimator())->estimate(
            estimator::normalise(['filter_course' => [$visible->id, $hidden->id]])
        )['count'];

        $this->assertSame(1, $count);
    }

    /**
     * The course count never claims more people than the per-user rule would admit.
     *
     * The bulk predicate models the enrolment branch of can_access_course() and not the viewer
     * branch, so it is a lower bound rather than an equality — stated in course_scope_sql() and
     * pinned here so the size of the gap cannot drift unnoticed. The gap in this fixture is exactly
     * the site admin, who can enter any course without being enrolled in it; asserting that, rather
     * than only the inequality, is what stops this from passing on a count of zero.
     */
    public function test_the_course_count_never_claims_more_than_the_rule_admits(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $enrolled = $generator->create_user();
        $outsider = $generator->create_user();
        $generator->enrol_user($enrolled->id, $course->id);
        $outsider->id; // Present in the population, admitted by neither side.

        $filters = ['filter_course' => [(int) $course->id]];

        $this->setAdminUser();
        $count = (new estimator())->estimate(estimator::normalise($filters))['count'];
        $admitted = $this->users_admitted_by_the_rule($filters, (int) $course->id);

        $this->assertSame(1, $count);
        $this->assertLessThanOrEqual($admitted, $count);
        $this->assertSame($admitted - 1, $count, 'the only divergence is the unenrolled site admin');
    }

    /**
     * The category rule reaches everyone enrolled anywhere inside the category.
     */
    public function test_the_category_rule_spans_the_courses_in_it(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $first = $generator->create_course(['category' => $category->id]);
        $second = $generator->create_course(['category' => $category->id]);
        $outside = $generator->create_course();

        $a = $generator->create_user();
        $b = $generator->create_user();
        $c = $generator->create_user();
        $generator->enrol_user($a->id, $first->id);
        $generator->enrol_user($b->id, $second->id);
        $generator->enrol_user($c->id, $outside->id);

        $count = (new estimator())->estimate(
            estimator::normalise(['filter_category' => [$category->id]])
        )['count'];

        $this->assertSame(2, $count);
    }

    /**
     * The format rule reaches the people enrolled in courses using that format.
     */
    public function test_the_format_rule_counts_by_course_format(): void {
        $generator = $this->getDataGenerator();
        $weekly = $generator->create_course(['format' => 'weeks']);
        $topics = $generator->create_course(['format' => 'topics']);

        $inweekly = $generator->create_user();
        $intopics = $generator->create_user();
        $generator->enrol_user($inweekly->id, $weekly->id);
        $generator->enrol_user($intopics->id, $topics->id);

        $count = (new estimator())->estimate(
            estimator::normalise(['filter_format' => ['weeks']])
        )['count'];

        $this->assertSame(1, $count);
    }

    /**
     * The site course is never reach for these rules, even though everyone is "enrolled" on it.
     *
     * get_enrolled_join() skips its enrolment join entirely for SITEID because core treats every
     * user as enrolled on the front page. Carrying that exemption into this count would report the
     * whole site for a category rule that names the front page's category — a rule check_filters()
     * can never satisfy, because it resolves a course only above id 1.
     */
    public function test_the_site_course_is_not_reach(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $onfrontpage = $generator->create_user();
        $generator->create_user();

        /*
         * The front-page enrolment is the whole test. Nobody normally holds one — which is why core
         * treats everyone as enrolled there — so without it the count is zero whether the site
         * course is excluded or not, and the assertion below would pass while proving nothing.
         *
         * It is written straight to the tables because the API refuses to build it: add_instance()
         * throws "Invalid request to add enrol instance to frontpage", and the generator silently
         * enrols nobody when it finds no instance. The rows still turn up on migrated sites, which
         * is the case this guards, and the row count below is what catches a setup that quietly
         * stopped creating them.
         */
        $enrolid = $DB->insert_record('enrol', (object) [
            'enrol' => 'manual',
            'status' => ENROL_INSTANCE_ENABLED,
            'courseid' => SITEID,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('user_enrolments', (object) [
            'enrolid' => $enrolid,
            'userid' => $onfrontpage->id,
            'status' => ENROL_USER_ACTIVE,
            'timestart' => time() - 100,
            'timeend' => 0,
            'modifierid' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->assertSame(1, $DB->count_records('user_enrolments'));

        $count = (new estimator())->estimate(
            estimator::normalise(['filter_course' => [SITEID]])
        )['count'];

        $this->assertSame(0, $count);
    }

    /**
     * A competency rule counts the people who hold that proficiency in a course they are in.
     */
    public function test_the_competency_rule_counts_proficient_users(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $proficient = $generator->create_user();
        $not = $generator->create_user();
        $generator->enrol_user($proficient->id, $course->id);
        $generator->enrol_user($not->id, $course->id);

        $competency = $this->create_competency();
        $this->record_proficiency($proficient->id, (int) $course->id, $competency, 1);
        $this->record_proficiency($not->id, (int) $course->id, $competency, 0);

        $criteria = estimator::normalise([
            'filter_competency_rules' => [['id' => $competency, 'proficient' => 1, 'name' => 'c']],
        ]);

        $this->assertSame(1, (new estimator())->estimate($criteria)['count']);

        // The mirror image: a rule written as "not proficient" admits the other user, and only them.
        $inverse = estimator::normalise([
            'filter_competency_rules' => [['id' => $competency, 'proficient' => 0, 'name' => 'c']],
        ]);
        $this->assertSame(1, (new estimator())->estimate($inverse)['count']);
    }

    /**
     * requireall demands every named competency, not merely one of them.
     */
    public function test_the_competency_rule_honours_require_all(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $both = $generator->create_user();
        $one = $generator->create_user();
        $generator->enrol_user($both->id, $course->id);
        $generator->enrol_user($one->id, $course->id);

        $first = $this->create_competency();
        $second = $this->create_competency();
        $this->record_proficiency($both->id, (int) $course->id, $first, 1);
        $this->record_proficiency($both->id, (int) $course->id, $second, 1);
        $this->record_proficiency($one->id, (int) $course->id, $first, 1);

        $rules = [
            ['id' => $first, 'proficient' => 1, 'name' => 'a'],
            ['id' => $second, 'proficient' => 1, 'name' => 'b'],
        ];

        $all = estimator::normalise([
            'filter_competency_rules' => $rules,
            'filter_competency_requireall' => 1,
        ]);
        $this->assertSame(1, (new estimator())->estimate($all)['count']);

        // Control: without requireall the same data still demands both, since each rule names
        // proficiency in its own right — so the discriminating input is the missing second record.
        $this->assertSame(1, (new estimator())->estimate(
            estimator::normalise(['filter_competency_rules' => $rules])
        )['count']);
    }

    /**
     * With the competency subsystem off, a competency rule reaches nobody rather than everybody.
     *
     * check_filters() returns false outright in that case. An estimate that ignored the switch
     * would report the unfiltered site for a notice nobody can receive.
     */
    public function test_a_competency_rule_reaches_nobody_when_competencies_are_off(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $competency = $this->create_competency();
        $this->record_proficiency($user->id, (int) $course->id, $competency, 1);

        $criteria = estimator::normalise([
            'filter_competency_rules' => [['id' => $competency, 'proficient' => 1, 'name' => 'c']],
        ]);

        // Control: the rule admits the user while the subsystem is on.
        $this->assertSame(1, (new estimator())->estimate($criteria)['count']);

        // The switch core_competency\api::is_enabled() actually reads — not $CFG->enablecompetencies.
        set_config('enabled', 0, 'core_competency');
        $this->assertSame(0, (new estimator())->estimate($criteria)['count']);
    }

    /**
     * Create a competency in a fresh framework and return its id.
     *
     * @return int
     */
    private function create_competency(): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $this->setAdminUser();
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $this->setUser(null);

        return (int) $competency->get('id');
    }

    /**
     * Record a user's proficiency for a competency in a course.
     *
     * Writes the row helper::get_user_competency_proficiency() reads through
     * core_competency\api::get_user_competency_in_course(), which is the state the notice rule and
     * the estimate both ask about.
     *
     * The grade is not decoration. user_competency_course::validate_proficiency() refuses a
     * proficiency without one and validate_grade() refuses a grade outside the competency's scale,
     * so it is read off that scale rather than guessed: the top item for proficient, the first for
     * not. Core's own tests create these rows with both fields left null, which is why they never
     * had to solve this.
     *
     * @param int $userid The user.
     * @param int $courseid The course the proficiency was earned in.
     * @param int $competencyid The competency.
     * @param int $proficiency 1 when proficient, 0 when not.
     * @return void
     */
    private function record_proficiency(int $userid, int $courseid, int $competencyid, int $proficiency): void {
        $scaleitems = (new \core_competency\competency($competencyid))->get_scale()->scale_items;

        $this->getDataGenerator()->get_plugin_generator('core_competency')->create_user_competency_course([
            'userid' => $userid,
            'courseid' => $courseid,
            'competencyid' => $competencyid,
            // PARAM_BOOL: persistent::validate() converts false to 0 for itself, but an int 0 is
            // compared as the string "0" against clean_param()'s "" and rejected.
            'proficiency' => (bool) $proficiency,
            'grade' => $proficiency ? count($scaleitems) : 1,
        ]);
    }

    /**
     * Count the users the per-user rule admits, over exactly the population the estimator counts.
     *
     * @param array $filters Filter values, in the shape stored in filtervalues.
     * @param int $courseid Course to judge the page context from; 0 for none.
     * @return int
     */
    private function users_admitted_by_the_rule(array $filters, int $courseid = 0): int {
        global $DB;

        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND confirmed = 1 AND username <> :guestname',
            ['guestname' => 'guest']
        );

        $filtervalues = json_encode($filters);
        $admitted = 0;
        foreach ($users as $user) {
            $this->setUser($user);
            if (helper::check_filters($filtervalues, $courseid)) {
                $admitted++;
            }
        }

        return $admitted;
    }

    /**
     * The breakdown chip for a role rule keeps the scope that rule was given.
     *
     * The editor renders one chip per audience rule beside the total. Isolating filter_role used to
     * drop filter_role_context and the course and category lists with it, so a rule meaning
     * "teachers of this one course" was counted as "teachers anywhere", and the chip disagreed with
     * the total sitting next to it — upward, and by the whole size of the site.
     */
    public function test_the_role_breakdown_keeps_the_scope_the_rule_was_given(): void {
        global $DB;

        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $listed = $this->getDataGenerator()->create_course();
        $unlisted = $this->getDataGenerator()->create_course();

        $inlisted = $this->getDataGenerator()->create_user();
        $elsewhere = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($inlisted->id, $listed->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($elsewhere->id, $unlisted->id, 'editingteacher');

        $criteria = estimator::normalise([
            'filter_role' => [$teacherroleid],
            'filter_role_context' => CONTEXT_COURSE,
            'filter_course' => [$listed->id],
        ]);

        $result = (new estimator())->estimate($criteria);

        $chips = [];
        foreach ($result['breakdown'] as $row) {
            $chips[$row['key']] = (int) $row['count'];
        }

        // Control: the combined count has always respected the scope. The chip is what drifted, so
        // asserting they agree is only meaningful while this stays at 1.
        $this->assertSame(1, (int) $result['count']);
        $this->assertSame(1, $chips['filter_role']);
    }

    /**
     * The bulk count agrees, user for user, with the per-user rule it mirrors.
     *
     * This class is a second implementation of the role rule — helper::check_filters() is the
     * first, and the two are kept in step by nothing but care. Rather than compare the two bodies,
     * this asks each of them about every user the count claims to cover and requires the same
     * answer, for an unscoped rule and for a course-scoped one.
     */
    public function test_the_bulk_count_agrees_with_the_per_user_rule(): void {
        global $DB;

        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $teacherhere = $this->getDataGenerator()->create_user();
        $teacherthere = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($teacherhere->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacherthere->id, $other->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        // Unscoped: holding the role anywhere counts, so both teachers do.
        $unscoped = ['filter_role' => [$teacherroleid]];
        $this->setAdminUser();
        $count = (int) (new estimator())->estimate(estimator::normalise($unscoped))['count'];
        $this->assertSame(2, $count, 'both teachers, whichever course they teach');
        $this->assertSame($this->users_admitted_by_the_rule($unscoped), $count);

        /*
         * Course-scoped. Everyone judged here is enrolled in $course, so the page-context block
         * accepts them all and the role rule is what separates them — without that the block would
         * reject first and both sides would agree on zero, which proves nothing.
         */
        $scoped = [
            'filter_role' => [$teacherroleid],
            'filter_role_context' => CONTEXT_COURSE,
            'filter_course' => [$course->id],
        ];
        $this->getDataGenerator()->enrol_user($teacherthere->id, $course->id);

        $this->setAdminUser();
        $count = (int) (new estimator())->estimate(estimator::normalise($scoped))['count'];
        $this->assertSame(1, $count, 'only the teacher of the listed course');
        $this->assertSame($this->users_admitted_by_the_rule($scoped, (int) $course->id), $count);
    }
}
