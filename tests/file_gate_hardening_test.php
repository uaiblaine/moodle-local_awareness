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

use local_awareness\persistent\awareness;

/**
 * The file gate's answer to the course rules of a site notice, to reports readers, and to file types.
 *
 * A site notice limited to a course or a category is shown only on that course's pages, so its
 * files are for people that course admits, not for anyone logged in who guesses its id. A reader of
 * the reports is offered the list's preview, so the author branch admits them as it admits an
 * author. And a file is rendered by the browser only when it is media or PDF: the content area
 * takes any type, and an HTML document served inline from the site's origin runs as the site.
 *
 * Coverage stays in this docblock rather than in #[CoversClass]: the moodle-cs release used with
 * Moodle 4.5 cannot see PHPUnit attributes and reports every test as missing coverage. Move to the
 * attribute when 4.5 support is dropped.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::may_serve_files_of
 * @covers \local_awareness\helper::serves_inline
 */
final class file_gate_hardening_test extends \advanced_testcase {
    /**
     * A live site notice with the given filters.
     *
     * @param array $filters The filtervalues.
     * @return awareness
     */
    private function site_notice(array $filters): awareness {
        return $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'filtervalues' => json_encode($filters),
        ]);
    }

    /**
     * A site notice limited to a course serves its files to that course's members only.
     *
     * The unlimited notice is the control that the outsider passes every other check.
     */
    public function test_a_site_notice_for_a_course_serves_that_course_only(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $outsider = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $everyone = $this->site_notice([]);
        $limited = $this->site_notice(['filter_course' => [(int) $course->id]]);

        $this->setUser($outsider);
        $this->assertTrue(helper::may_serve_files_of($everyone), 'the control: the outsider passes the rest of the gate');
        $this->assertFalse(helper::may_serve_files_of($limited));

        $this->setUser($member);
        $this->assertTrue(helper::may_serve_files_of($limited));
    }

    /**
     * A site notice limited to a category serves its files to members of a course in it.
     *
     * Both rules together are ANDed, as check_filters() reads them: a member of a listed course in
     * another category is refused.
     */
    public function test_a_site_notice_for_a_category_serves_its_courses_only(): void {
        $this->resetAfterTest();
        $inside = $this->getDataGenerator()->create_category();
        $elsewhere = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $inside->id]);
        $away = $this->getDataGenerator()->create_course(['category' => $elsewhere->id]);
        $member = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $awaymember = $this->getDataGenerator()->create_and_enrol($away, 'student');

        $this->setAdminUser();
        $category = $this->site_notice(['filter_category' => [(int) $inside->id]]);
        $both = $this->site_notice(['filter_category' => [(int) $inside->id], 'filter_course' => [(int) $away->id]]);

        $this->setUser($member);
        $this->assertTrue(helper::may_serve_files_of($category));
        $this->setUser($awaymember);
        $this->assertFalse(helper::may_serve_files_of($category));
        $this->assertFalse(helper::may_serve_files_of($both), 'a listed course outside the listed category');
    }

    /**
     * A reader of the reports is served a disabled notice's files, as an author is.
     *
     * A plain user is the control: the audience branch refuses a disabled notice.
     */
    public function test_a_reports_reader_is_served_an_unpublished_notice_s_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice(['enabled' => 0]);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(helper::may_serve_files_of($notice));

        $reader = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:viewreports', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $reader->id, \context_system::instance()->id);
        $this->setUser($reader);
        $this->assertTrue(helper::may_serve_files_of($notice));
    }

    /**
     * Only media and PDF render in the browser; every other type downloads.
     *
     * @dataProvider mimetypes
     * @param string $mimetype The stored MIME type.
     * @param bool $inline Whether it may be shown inline.
     */
    public function test_only_media_and_pdf_render_inline(string $mimetype, bool $inline): void {
        $this->assertSame($inline, helper::serves_inline($mimetype));
    }

    /**
     * MIME types and whether each may be shown inline.
     *
     * @return array
     */
    public static function mimetypes(): array {
        return [
            'png' => ['image/png', true],
            'jpeg' => ['image/jpeg', true],
            'mp4' => ['video/mp4', true],
            'mp3' => ['audio/mpeg', true],
            'pdf' => ['application/pdf', true],
            'svg' => ['image/svg+xml', false],
            'html' => ['text/html', false],
            'xhtml' => ['application/xhtml+xml', false],
            'xml' => ['text/xml', false],
            'plain' => ['text/plain', false],
            'none' => ['', false],
        ];
    }
}
