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

use core_reportbuilder\exception\report_access_exception;
use core_reportbuilder\system_report_factory;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\systemreports\acknowledged_notice;
use local_awareness\reportbuilder\local\systemreports\course_notices;

/**
 * A capability held in a course counts only for someone with a place in the course.
 *
 * Suspending or ending an enrolment leaves the role assignment, so has_capability() keeps answering
 * yes for an ex-tutor; the report web services and downloads core builds from client parameters ask
 * only helper::require_author(). Each case below keeps the role assignment and changes the
 * enrolment, with the active enrolment as the control, and a category manager who is enrolled
 * nowhere but holds moodle/course:view as the control that the rule is require_login()'s and not
 * "enrolled only".
 *
 * Coverage stays in this docblock rather than in #[CoversClass]: the moodle-cs release used with
 * Moodle 4.5 cannot see PHPUnit attributes and reports every test as missing coverage. Move to the
 * attribute when 4.5 support is dropped.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper
 * @covers \local_awareness\reportbuilder\local\systemreports\acknowledged_notice
 * @covers \local_awareness\reportbuilder\local\systemreports\course_notices
 */
final class course_place_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass A teacher holding the course capabilities. */
    private \stdClass $tutor;

    /** @var awareness A notice of the course. */
    private awareness $notice;

    /**
     * A course, a teacher with both course capabilities, and a notice.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->tutor = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $context = \context_course::instance($this->course->id);
        foreach (['local/awareness:managecourse', 'local/awareness:viewreportscourse'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $this->setAdminUser();
        helper::create_new_notice(
            (object) ['title' => 'Course notice', 'content' => '<p>Body</p>', 'reqack' => 1],
            author_scope::course((int) $this->course->id)
        );
        $this->notice = awareness::get_record(['title' => 'Course notice']);
    }

    /**
     * Change the tutor's enrolment in the course, keeping the role assignment.
     *
     * @param int $status ENROL_USER_ACTIVE or ENROL_USER_SUSPENDED.
     * @param int $timeend The enrolment's end, 0 for none.
     */
    private function enrolment(int $status, int $timeend = 0): void {
        $instance = enrol_get_instances((int) $this->course->id, true);
        $instance = reset($instance);
        enrol_get_plugin($instance->enrol)->update_user_enrol($instance, (int) $this->tutor->id, $status, 0, $timeend);
        $this->assertTrue(
            has_capability('local/awareness:viewreportscourse', \context_course::instance($this->course->id), $this->tutor),
            'the capability must survive the enrolment change, or nothing below tests the gap'
        );
    }

    /**
     * Whether the tutor may read the reports, by every route: the verb, the notice's report and the course report.
     *
     * @return bool[] Keyed by route.
     */
    private function routes(): array {
        $this->setUser($this->tutor);
        $scope = author_scope::course((int) $this->course->id);
        $routes = [
            'manage' => helper::require_author($scope, 'manage', false),
            'viewreports' => helper::require_author($scope, 'viewreports', false),
        ];
        $reports = [
            'notice report' => [acknowledged_notice::class, ['noticeid' => $this->notice->get('id')]],
            'course report' => [course_notices::class, ['courseid' => $this->course->id]],
        ];
        foreach ($reports as $name => [$class, $parameters]) {
            \core_reportbuilder\manager::reset_caches();
            try {
                system_report_factory::create($class, \context_system::instance(), 'local_awareness', '', 0, $parameters);
                $routes[$name] = true;
            } catch (report_access_exception $e) {
                $routes[$name] = false;
            }
        }

        return $routes;
    }

    /**
     * An active enrolment opens every route; a suspended one closes them all.
     */
    public function test_a_suspended_enrolment_closes_the_reports(): void {
        $open = ['manage' => true, 'viewreports' => true, 'notice report' => true, 'course report' => true];
        $closed = array_map(static fn() => false, $open);

        $this->assertSame($open, $this->routes(), 'the control: an active enrolment opens every route');
        $this->setAdminUser();
        $this->enrolment(ENROL_USER_SUSPENDED);
        $this->assertSame($closed, $this->routes());
    }

    /**
     * An enrolment that has ended closes them too.
     */
    public function test_an_ended_enrolment_closes_the_reports(): void {
        $this->enrolment(ENROL_USER_ACTIVE, time() - DAYSECS);
        $this->assertSame(
            ['manage' => false, 'viewreports' => false, 'notice report' => false, 'course report' => false],
            $this->routes()
        );
    }

    /**
     * Someone enrolled nowhere who may view the course keeps the authority of their role.
     *
     * A category manager holds moodle/course:view and local/awareness:manage there, the site
     * capability inherited down, and not at the system level: require_login() admits them, so this
     * does too.
     */
    public function test_a_category_manager_who_may_view_the_course_is_admitted(): void {
        $manager = $this->getDataGenerator()->create_user();
        $categorycontext = \context_coursecat::instance((int) $this->course->category);
        $this->getDataGenerator()->role_assign('manager', $manager->id, $categorycontext->id);
        $this->assertFalse(is_enrolled(\context_course::instance($this->course->id), $manager));

        $this->setUser($manager);
        $this->assertTrue(helper::require_author(author_scope::course((int) $this->course->id), 'viewreports', false));
        $this->assertFalse(helper::require_author(author_scope::site(), 'viewreports', false), 'not a site holder');
    }
}
