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
 * What managenotice.php and editnotice.php do with a link naming a course that has been deleted.
 *
 * The pages run here as scripts, as in editnotice_orphan_test: redirect() throws
 * redirecterrordetected in a CLI process, so a redirect is read as that exception. A page that
 * still called get_course() on the missing id would end in a missing-record error instead.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class deleted_course_link_test extends \advanced_testcase {
    /**
     * Run one of the two pages as one request.
     *
     * @param string $page editnotice or managenotice.
     * @param array $params The request parameters.
     * @return array{0: string, 1: ?\moodle_exception} The page output, and the exception it ended with.
     */
    private function run_page(string $page, array $params): array {
        global $COURSE, $SITE;

        // A request starts on the site course. Left on the previous run's course, get_course() would
        // answer from it instead of reading the table.
        $COURSE = clone($SITE);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = $params;
        $_POST = [];

        $thrown = null;
        ob_start();
        try {
            // The fixture binds the globals the page reads and runs it here, in this method's scope.
            require(__DIR__ . "/fixtures/{$page}_request.php");
        } catch (\moodle_exception $e) {
            $thrown = $e;
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$output, $thrown];
    }

    /**
     * The parameters a link from a course's notice list carries, for each page.
     *
     * The editor's link is an Edit: the create page ends the process once it has printed.
     *
     * @param int $courseid The course the link names.
     * @param int $noticeid The course notice the Edit link names.
     * @return array Page => parameters.
     */
    private function links(int $courseid, int $noticeid): array {
        return [
            'managenotice' => ['courseid' => $courseid],
            'editnotice' => ['courseid' => $courseid, 'noticeid' => $noticeid, 'action' => 'edit'],
        ];
    }

    /**
     * A course that has been deleted, having first checked that its links open while it lives.
     *
     * The check is the control for every refusal below: the same links, for the same user, open
     * both pages until the course goes.
     *
     * @param \stdClass $user Who follows the links.
     * @return array The links, as links() gives them, to the deleted course.
     */
    private function deleted_course(\stdClass $user): array {
        global $DB;

        set_config('allow_update', 1, 'local_awareness');
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($course->id);
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice(['courseid' => $course->id]);
        $links = $this->links((int) $course->id, (int) $notice->get('id'));

        // What each page prints once it has opened: the notice list, and the editor.
        $markers = ['managenotice' => 'id="all_notices_table"', 'editnotice' => 'data-region="la-editor"'];
        $this->setUser($user);
        foreach ($links as $page => $params) {
            [$html, $thrown] = $this->run_page($page, $params);
            $this->assertNull(
                $thrown,
                "precondition: {$page}.php opens for the live course: " . ($thrown ? $thrown->getMessage() : '')
            );
            $this->assertStringContainsString($markers[$page], $html, "precondition: {$page}.php printed its page");
        }

        $this->setAdminUser();
        delete_course($course, false);
        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]), 'precondition: the course is gone');

        return $links;
    }

    /**
     * A user holding one capability at the site, through a role of their own.
     *
     * @param string $capability The capability.
     * @return \stdClass The user.
     */
    private function site_user_with(string $capability): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, (int) $user->id, \context_system::instance()->id);

        return $user;
    }

    /**
     * A site author following either link is sent to the site list rather than into an error.
     */
    public function test_a_site_author_is_sent_to_the_site_list(): void {
        $this->resetAfterTest();
        $author = $this->site_user_with('local/awareness:manage');
        $links = $this->deleted_course($author);

        $this->setUser($author);
        foreach ($links as $page => $params) {
            [, $thrown] = $this->run_page($page, $params);
            $this->assertInstanceOf(\moodle_exception::class, $thrown, "{$page}.php ended without a redirect");
            $this->assertSame('redirecterrordetected', $thrown->errorcode, "{$page}.php redirects: " . $thrown->getMessage());
        }
    }

    /**
     * Anyone the site list would not admit gets core's invalid course id error, on both pages.
     *
     * The user was the course's author while it lived, which the helper checks, so the error is the
     * course's deletion and not a user who never had access.
     */
    public function test_a_course_author_gets_the_invalid_course_error(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $links = $this->deleted_course($author);

        $this->setUser($author);
        foreach ($links as $page => $params) {
            [, $thrown] = $this->run_page($page, $params);
            $this->assertInstanceOf(\moodle_exception::class, $thrown, "{$page}.php ended without an error");
            $this->assertSame('invalidcourseid', $thrown->errorcode, "{$page}.php: " . $thrown->getMessage());
        }
    }

    /**
     * Each page gates the link with its own verb: a site reports reader reaches the list, not the editor.
     *
     * The site list opens to either verb and editnotice.php demands the manage verb, so the same
     * reader is redirected by one page and refused by the other.
     */
    public function test_each_page_gates_the_link_with_its_own_verb(): void {
        $this->resetAfterTest();
        $reader = $this->site_user_with('local/awareness:viewreports');
        $links = $this->deleted_course($reader);

        $this->setUser($reader);
        [, $thrown] = $this->run_page('managenotice', $links['managenotice']);
        $this->assertSame('redirecterrordetected', $thrown ? $thrown->errorcode : null, 'the reader is sent to the site list');

        [, $thrown] = $this->run_page('editnotice', $links['editnotice']);
        $this->assertSame('invalidcourseid', $thrown ? $thrown->errorcode : null, 'the reader may not open the editor');
    }
}
