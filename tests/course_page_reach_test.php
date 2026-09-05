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

use local_awareness\form\notice_form;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;

/**
 * Where a course notice fires: its course's main page, and the author is not asked.
 *
 * The free URL pattern described a reach a course notice does not have — it cannot leave its
 * course, so every honest answer was a subset of one page — and the field is gone from the course
 * form. The scope writes the page instead, which is why these tests read the stored value rather
 * than a submitted one.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\local\author_scope
 * @covers     \local_awareness\helper
 * @covers     \local_awareness\form\notice_form
 */
final class course_page_reach_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass A student enrolled in it. */
    private \stdClass $reader;

    /**
     * A course, a reader in it, and an enabled course notice saved through the real path.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->reader = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $this->setAdminUser();
        $data = (object) ['title' => 'Dome closed', 'content' => '<p>Serviced on Friday.</p>', 'enabled' => 1];
        helper::create_new_notice($data, author_scope::course((int) $this->course->id));
    }

    /**
     * The titles the reader is served on a page of their course.
     *
     * @param string $path The page, as the browser reports it: path plus query.
     * @return string[]
     */
    private function served(string $path): array {
        return array_values(array_map(static function (awareness $notice): string {
            return $notice->get('title');
        }, helper::retrieve_user_notices($path, (int) $this->course->id)));
    }

    /**
     * The saved notice carries the course's main page as its reach, whatever was submitted.
     */
    public function test_the_scope_writes_the_page_and_a_submitted_pattern_does_not_survive(): void {
        $stored = awareness::get_record(['title' => 'Dome closed']);
        $this->assertSame(author_scope::COURSE_PATHMATCH, $stored->get('pathmatch'));

        // A hand-made request naming another page is overwritten, not obeyed.
        $forged = author_scope::course((int) $this->course->id)
            ->apply(['pathmatch' => '/mod/quiz/view.php'])
            ->criteria()['pathmatch'];
        $this->assertSame(author_scope::COURSE_PATHMATCH, $forged);

        // The site scope still writes nothing of its own: there the pattern is the author's.
        $this->assertSame(
            '/mod/quiz/view.php',
            author_scope::site()->apply(['pathmatch' => '/mod/quiz/view.php'])->criteria()['pathmatch']
        );
    }

    /**
     * The notice fires on the course's main page and on no other page of the same course.
     *
     * The page arrives as path plus query, which is what makes the stored pattern need its
     * wildcard; the activity page is the control that the pattern is doing the narrowing rather
     * than the course rule, since both pages belong to the course the notice is confined to.
     */
    public function test_the_notice_fires_on_the_course_main_page_and_nowhere_else_in_the_course(): void {
        $this->setUser($this->reader);

        $this->assertSame(['Dome closed'], $this->served('/course/view.php?id=' . $this->course->id));
        $this->assertSame([], $this->served('/mod/forum/view.php?id=99'));
        $this->assertSame([], $this->served('/user/profile.php'));
    }

    /**
     * The collision check compares the reach the save would store, not the one the client sent.
     *
     * A course author's editor has no page-reach field, so it sends nothing and the server asks the
     * scope. The rival here is the fixture that can tell the two apart: it reaches the course's main
     * page and nothing else, so it overlaps the scope's answer and misses the pattern the client
     * claims. Sending an empty pattern instead would prove nothing — empty overlaps everything.
     */
    public function test_the_collision_check_compares_the_scope_s_reach(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $generator->create_notice([
            'title' => 'Course page rival',
            'pathmatch' => author_scope::COURSE_PATHMATCH,
            'resetinterval' => WEEKSECS,
            'courseid' => (int) $this->course->id,
        ]);

        // The client claims a reach that does NOT overlap the course's main page.
        $incourse = \local_awareness\external\check_collision::execute(0, '/my/%', true, (int) $this->course->id);
        $this->assertSame(['Course page rival'], $incourse['titles']);

        /*
         * The control, at the site, where the same claimed reach IS the author's: there the rival's
         * course page is out of reach and nothing is reported. So the assertion above can only be
         * the scope having replaced the pattern.
         */
        $atsite = \local_awareness\external\check_collision::execute(0, '/my/%', true, 0);
        $this->assertSame([], $atsite['titles']);
    }

    /**
     * The course form offers no page-reach field, and the site form still does.
     */
    public function test_the_course_form_has_no_display_restrictions_section(): void {
        global $PAGE;

        $this->setAdminUser();
        $PAGE->set_url('/local/awareness/editnotice.php');

        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);

        $course = new notice_form(null, [
            'persistent' => null,
            'id' => 0,
            'scope' => author_scope::course((int) $this->course->id),
        ]);
        $coursemform = $property->getValue($course);
        $this->assertFalse($coursemform->elementExists('pathmatch'));
        $this->assertFalse($coursemform->elementExists('header_filters'));

        // The control: the same form at the site keeps both, so the absence above is the scope's doing.
        $site = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $sitemform = $property->getValue($site);
        $this->assertTrue($sitemform->elementExists('pathmatch'));
        $this->assertTrue($sitemform->elementExists('header_filters'));
    }
}
