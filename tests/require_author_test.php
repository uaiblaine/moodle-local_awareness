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

use local_awareness\local\author_scope;

/**
 * Tests for the one gate every author-side request passes through.
 *
 * The course branch is exercised against a hand-built course scope and the course capability,
 * which no page can grant yet: the point is that the policy — who may act on a course's notice —
 * is settled and pinned before anything is wired to it. Every test pairs a refusal with a pass in
 * the same call, so neither an always-allow nor an always-deny seam survives.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::require_author
 */
final class require_author_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A fresh user holding one capability in one context, logged in.
     *
     * @param string $capability The capability.
     * @param \context $context Where it is granted.
     * @return \stdClass The user.
     */
    private function user_with(string $capability, \context $context): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        return $user;
    }

    /**
     * A course author may act in their course, and nowhere else.
     *
     * Their own course is the control for the two refusals; the exception at the end is the
     * control for the boolean form.
     */
    public function test_a_course_author_may_act_in_their_course_and_nowhere_else(): void {
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->user_with('local/awareness:managecourse', \context_course::instance($mine->id));

        $this->assertTrue(helper::require_author(author_scope::course((int) $mine->id), 'manage', false));
        $this->assertFalse(helper::require_author(author_scope::course((int) $other->id), 'manage', false));
        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false));

        $this->expectException(\required_capability_exception::class);
        helper::require_author(author_scope::site(), 'manage');
    }

    /**
     * A site manager may act everywhere: the site capability inherits into every course.
     *
     * The second user is what pins WHERE the site capability is read. Holding it in one course
     * only, they pass for that course and fail for the site — which a seam that checked the site
     * capability at the system context whatever the scope would get backwards.
     */
    public function test_a_site_manager_may_act_everywhere(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->user_with('local/awareness:manage', \context_system::instance());

        $this->assertTrue(helper::require_author(author_scope::site(), 'manage', false));
        $this->assertTrue(helper::require_author(author_scope::course((int) $course->id), 'manage', false));

        $this->user_with('local/awareness:manage', \context_course::instance($course->id));
        $this->assertTrue(helper::require_author(author_scope::course((int) $course->id), 'manage', false));
        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false));
    }

    /**
     * The course capability never opens the site scope, wherever it was granted.
     *
     * has_capability() does not enforce a capability's declared context level, so an administrator
     * can assign managecourse to a role at the system context. The seam must still read it only
     * for a course scope: a site verb answers to the site capability alone.
     */
    public function test_the_course_capability_never_opens_the_site_scope(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->user_with('local/awareness:managecourse', \context_system::instance());

        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false));
        // Control: the same grant, inherited into a course, does open that course.
        $this->assertTrue(helper::require_author(author_scope::course((int) $course->id), 'manage', false));
    }

    /**
     * A user holding nothing is refused everywhere, in both forms.
     */
    public function test_a_user_holding_nothing_is_refused_everywhere(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false));
        $this->assertFalse(helper::require_author(author_scope::course((int) $course->id), 'manage', false));

        $this->expectException(\required_capability_exception::class);
        helper::require_author(author_scope::course((int) $course->id), 'manage');
    }

    /**
     * The reports verb reads its own capability, at the site and in a course.
     *
     * Three users in one test: the site reports capability opens every scope and no manage verb;
     * the course reports capability opens the reports of its own course only, and nothing else;
     * and managecourse opens no report at all. Each refusal sits beside a pass for the same user,
     * so a map that pointed the course reports verb at managecourse, or at nothing, reddens.
     */
    public function test_the_reports_verb_reads_its_own_capability(): void {
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $mine->id);

        $this->user_with('local/awareness:viewreports', \context_system::instance());
        $this->assertTrue(helper::require_author(author_scope::site(), 'viewreports', false));
        $this->assertTrue(helper::require_author($scope, 'viewreports', false), 'the site capability inherits into a course');
        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false), 'viewreports must not grant manage');

        $this->user_with('local/awareness:viewreportscourse', \context_course::instance($mine->id));
        $this->assertTrue(helper::require_author($scope, 'viewreports', false), 'the course reports capability opens its course');
        $this->assertFalse(helper::require_author(author_scope::course((int) $other->id), 'viewreports', false), 'and no other');
        $this->assertFalse(helper::require_author(author_scope::site(), 'viewreports', false), 'nor the site');
        $this->assertFalse(helper::require_author($scope, 'manage', false), 'viewreportscourse must not open manage');

        $this->user_with('local/awareness:managecourse', \context_course::instance($mine->id));
        $this->assertTrue(helper::require_author($scope, 'manage', false));
        $this->assertFalse(helper::require_author($scope, 'viewreports', false), 'managecourse must not open the reports');
    }

    /**
     * A scope whose course is gone refuses a course author, not fatally, and leaves the site manager a way out.
     *
     * The course and its context are deleted behind the plugin's back, the way a deletion that
     * ran with the plugin uninstalled leaves things. The course author who passed a moment before
     * is the control: the refusal has to come from the course being gone, and it has to be a
     * refusal — without the existence check ahead of the context, this test errors on a missing
     * record instead of asserting anything. The site manager still passes, at the system context,
     * so an orphan can be disabled or deleted rather than sitting in the table for ever.
     */
    public function test_a_scope_whose_course_is_gone_refuses_the_author_and_not_the_site_manager(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $course->id);
        $author = $this->user_with('local/awareness:managecourse', \context_course::instance($course->id));
        $this->assertTrue(helper::require_author($scope, 'manage', false), 'the author passes while the course exists');
        $this->assertTrue($scope->exists());

        $DB->delete_records('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $course->id]);
        $DB->delete_records('course', ['id' => $course->id]);
        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($scope->exists());
        $this->setUser($author);
        $this->assertFalse(helper::require_author($scope, 'manage', false), 'the author is refused once the course is gone');
        $this->assertFalse(helper::require_author($scope, 'viewreports', false));

        $this->user_with('local/awareness:manage', \context_system::instance());
        $this->assertTrue(helper::require_author($scope, 'manage', false), 'the site capability may still act on an orphan');

        $this->setUser($author);
        $this->expectException(\required_capability_exception::class);
        helper::require_author($scope, 'manage');
    }

    /**
     * A verb the map does not know is a coding error, not a silent refusal.
     */
    public function test_an_unknown_verb_is_a_coding_error(): void {
        $this->setAdminUser();

        $this->expectException(\coding_exception::class);
        helper::require_author(author_scope::site(), 'publish');
    }
}
