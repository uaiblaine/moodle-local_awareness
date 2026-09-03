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
     * The reports verb reads its own capability, and a course author has none for it yet.
     *
     * Reading reports is the personal-data verb and is deliberately not folded into manage; a
     * course-level reports capability is a decision for the foundation PR, and until it exists
     * only the site capability, inherited, opens a course's reports.
     */
    public function test_the_reports_verb_reads_its_own_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $course->id);

        $this->user_with('local/awareness:viewreports', \context_system::instance());
        $this->assertTrue(helper::require_author(author_scope::site(), 'viewreports', false));
        $this->assertTrue(helper::require_author($scope, 'viewreports', false));
        $this->assertFalse(helper::require_author(author_scope::site(), 'manage', false), 'viewreports must not grant manage');

        $this->user_with('local/awareness:managecourse', \context_course::instance($course->id));
        $this->assertTrue(helper::require_author($scope, 'manage', false));
        $this->assertFalse(helper::require_author($scope, 'viewreports', false), 'managecourse must not open the reports');
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
