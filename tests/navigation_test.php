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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/awareness/lib.php');

/**
 * The course navigation entry: shown to the people who may author the course's notices, and never for the site.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::local_awareness_extend_navigation_course
 */
final class navigation_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Call the callback the way settings navigation does, and return what it added.
     *
     * @param \stdClass $course The course handed to the callback.
     * @return \navigation_node|false The node added, or false.
     */
    private function node_for(\stdClass $course) {
        $navigation = new \navigation_node('Course');
        local_awareness_extend_navigation_course($navigation, $course, \context_course::instance($course->id));

        return $navigation->get('localawareness');
    }

    /**
     * A fresh user holding one capability in the course, logged in.
     *
     * @param string $capability The capability.
     * @param \stdClass $course The course.
     * @return \stdClass The user.
     */
    private function user_with(string $capability, \stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($course->id);
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        return $user;
    }

    /**
     * The entry appears for either course capability, carries the course, and appears for nobody else.
     *
     * The reports capability opens the list read-only, so it opens the entry too; a plain enrolled
     * user sees nothing. The holders are deliberately NOT enrolled: the entry is about the
     * capability, and require_login() on the page is a gate of its own.
     */
    public function test_the_entry_is_for_the_people_who_may_author_the_course_s_notices(): void {
        $course = $this->getDataGenerator()->create_course();

        $this->user_with('local/awareness:managecourse', $course);
        $node = $this->node_for($course);
        $this->assertNotFalse($node, 'a manage holder gets the entry');
        $this->assertSame(get_string('coursenotices', 'local_awareness'), $node->text);
        $this->assertSame((int) $course->id, (int) $node->action->get_param('courseid'));
        $this->assertStringContainsString('/local/awareness/managenotice.php', $node->action->out(false));

        $this->user_with('local/awareness:viewreportscourse', $course);
        $this->assertNotFalse($this->node_for($course), 'the reports capability opens the list read-only, so it gets the entry');

        $plain = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($plain->id, $course->id);
        $this->setUser($plain);
        $this->assertFalse($this->node_for($course), 'an enrolled user without the capability sees nothing');
    }

    /**
     * The site course is handed to this callback by core, and it must return before building a scope.
     *
     * settings_navigation's CONTEXT_MODULE branch reaches the course callbacks with course id 1 for
     * every activity on the front page. author_scope::course() refuses the site course by design,
     * so a callback that asked it would fatal on pages every logged-in user can reach. The site
     * manager is the control: they would get an entry for any real course.
     */
    public function test_the_site_course_gets_no_entry_and_no_exception(): void {
        global $SITE;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->assertNotFalse($this->node_for($course), 'the control: a real course gets an entry for the site manager');

        $this->assertFalse($this->node_for($SITE), 'the site course gets nothing, and nothing throws');
    }
}
