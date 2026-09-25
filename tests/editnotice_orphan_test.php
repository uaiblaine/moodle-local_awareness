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
 * What editnotice.php does with a course notice whose course is gone.
 *
 * The site list shows such an orphan to an administrator, and its menu links to this page. The page
 * runs here as a script: redirect() throws redirecterrordetected in a CLI process, so a redirect is
 * read as that exception, and a confirmation box as the captured output. The course and its context
 * are deleted directly, bypassing the before_course_deleted purge, which is how an orphan arises.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class editnotice_orphan_test extends \advanced_testcase {
    /**
     * A course notice, created as the administrator in its course's scope.
     *
     * @param \stdClass $course The course.
     * @param string $title The notice title.
     * @return awareness
     */
    private function course_notice(\stdClass $course, string $title): awareness {
        $data = (object) ['title' => $title, 'content' => '<p>' . $title . '</p>'];
        helper::create_new_notice($data, author_scope::course((int) $course->id));

        return awareness::get_record(['title' => $title]);
    }

    /**
     * Delete a course's row and context the way no purge sees it.
     *
     * @param \stdClass $course The course.
     */
    private function delete_course_directly(\stdClass $course): void {
        global $DB;

        $DB->delete_records('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $course->id]);
        $DB->delete_records('course', ['id' => $course->id]);
        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * An orphan the administrator can resolve, and whose course-scoped form cannot be built.
     *
     * The second precondition is the force this suite is about: were the page to build the form for
     * the orphan, it would throw.
     *
     * @return awareness
     */
    private function orphan(): awareness {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $notice = $this->course_notice($course, 'Orphan & notice');
        $this->delete_course_directly($course);

        $this->assertFalse(author_scope::of($notice)->exists(), 'precondition: the course is gone');
        $this->assertSame(
            (int) $notice->get('id'),
            (int) helper::resolve_notice_as_author((int) $notice->get('id'), 'manage')->get('id'),
            'precondition: the administrator resolves the orphan'
        );

        $PAGE->set_url('/local/awareness/editnotice.php');
        $customdata = ['persistent' => $notice, 'id' => (int) $notice->get('id'), 'scope' => author_scope::of($notice)];
        $thrown = null;
        try {
            new notice_form(null, $customdata);
        } catch (\dml_missing_record_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'precondition: the course-scoped form cannot be built for the orphan');

        return $notice;
    }

    /**
     * Run editnotice.php as one request.
     *
     * @param array $params The request parameters.
     * @param string $method GET or POST.
     * @return array{0: string, 1: ?\moodle_exception} The page output, and the exception it ended with.
     */
    private function run_page(array $params, string $method = 'GET'): array {
        global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

        // Each run is a request of its own: a page already printed cannot be set up again.
        $PAGE = new \moodle_page();
        $OUTPUT = new \bootstrap_renderer();
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $method === 'GET' ? $params : [];
        $_POST = $method === 'POST' ? $params : [];

        $thrown = null;
        ob_start();
        try {
            require($CFG->dirroot . '/local/awareness/editnotice.php');
        } catch (\moodle_exception $e) {
            $thrown = $e;
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$output, $thrown];
    }

    /**
     * The action URL of the single button whose label is given.
     *
     * @param string $html The page output.
     * @param string $label The button label.
     * @return \moodle_url
     */
    private function button_url(string $html, string $label): \moodle_url {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $forms = $xpath->query('//form[.//button[normalize-space(.)="' . $label . '"]'
            . ' or .//input[@type="submit" and @value="' . $label . '"]]');
        $this->assertSame(1, $forms->length, "exactly one form carries the {$label} button");
        $form = $forms->item(0);

        $url = new \moodle_url($form->getAttribute('action'));
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $hidden) {
            $url->param($hidden->getAttribute('name'), $hidden->getAttribute('value'));
        }

        return $url;
    }

    /**
     * The administrator is asked to confirm deleting an orphan, and returns to the site list.
     *
     * The control is a live course notice, whose confirmation returns to its course's list: the
     * course id in that URL is what the orphan's must not carry, because the course list cannot
     * open without its course.
     */
    public function test_an_administrator_is_asked_to_confirm_deleting_an_orphan(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_delete', 1, 'local_awareness');

        $live = $this->getDataGenerator()->create_course();
        $control = $this->course_notice($live, 'Live notice');
        [$html, $thrown] = $this->run_page([
            'noticeid' => (int) $control->get('id'),
            'action' => 'unconfirmeddelete',
            'sesskey' => sesskey(),
        ]);
        $this->assertNull($thrown, 'the control page renders');
        $this->assertEquals($live->id, $this->button_url($html, get_string('cancel'))->param('courseid'));

        $notice = $this->orphan();
        [$html, $thrown] = $this->run_page([
            'noticeid' => (int) $notice->get('id'),
            'action' => 'unconfirmeddelete',
            'sesskey' => sesskey(),
        ]);

        $this->assertNull($thrown, 'the confirmation renders: ' . ($thrown ? $thrown->getMessage() : ''));
        $this->assertStringContainsString(
            get_string('confirmation:deletenotice', 'local_awareness', 'Orphan &amp; notice'),
            $html
        );
        $cancel = $this->button_url($html, get_string('cancel'));
        $this->assertStringEndsWith('/local/awareness/managenotice.php', $cancel->out_omit_querystring());
        $this->assertNull($cancel->param('courseid'), 'the orphan returns to the site list');
        $confirm = $this->button_url($html, get_string('delete'));
        $this->assertSame('confirmeddelete', $confirm->param('action'));
        $this->assertNull($confirm->param('courseid'));
    }

    /**
     * Confirming deletes the orphan; a notice beside it is the control that only the named one went.
     */
    public function test_an_administrator_deletes_an_orphan(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_delete', 1, 'local_awareness');

        $notice = $this->orphan();
        $neighbour = new awareness(0, (object) ['title' => 'Site notice', 'content' => '<p>Site</p>']);
        $neighbour->create();

        [, $thrown] = $this->run_page([
            'noticeid' => (int) $notice->get('id'),
            'action' => 'confirmeddelete',
            'sesskey' => sesskey(),
        ], 'POST');

        $this->assertInstanceOf(\moodle_exception::class, $thrown);
        $this->assertSame('redirecterrordetected', $thrown->errorcode, 'the page redirects: ' . $thrown->getMessage());
        $this->assertFalse(awareness::record_exists((int) $notice->get('id')), 'the orphan is deleted');
        $this->assertTrue(awareness::record_exists((int) $neighbour->get('id')));
    }

    /**
     * The orphan is not opened for editing: the page redirects rather than build its form.
     *
     * Editing is allowed on the site, so the redirect is the orphan's refusal and not the setting's.
     */
    public function test_an_orphan_is_not_opened_for_editing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_update', 1, 'local_awareness');

        $notice = $this->orphan();
        $before = $notice->to_record();

        [$html, $thrown] = $this->run_page(['noticeid' => (int) $notice->get('id'), 'action' => 'edit']);

        $this->assertInstanceOf(\moodle_exception::class, $thrown, 'the page ended without a redirect');
        $this->assertSame('redirecterrordetected', $thrown->errorcode, 'the page redirects: ' . $thrown->getMessage());
        $this->assertStringNotContainsString('mform', $html, 'no form was printed');
        $this->assertEquals($before, (new awareness((int) $notice->get('id')))->to_record(), 'the orphan is untouched');
    }
}
