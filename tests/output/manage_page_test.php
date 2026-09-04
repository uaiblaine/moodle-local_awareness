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

namespace local_awareness\output;

use local_awareness\local\author_scope;

/**
 * The manage page's numbers and buttons follow the scope of the list they sit above.
 *
 * The stats have no WHERE of the table's to hide behind: they count on their own, so a scope
 * forgotten here shows a course author the site's totals. Two courses and the site are seeded with
 * the same shape, so every course number has a site number to differ from.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\output\manage_page
 */
final class manage_page_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Export the page for the given scope, as the current user.
     *
     * @param author_scope $scope The scope.
     * @return array
     */
    private function export(author_scope $scope): array {
        global $PAGE;

        $page = new manage_page('<table></table>', '/create', [], $scope);

        return (array) $page->export_for_template($PAGE->get_renderer('local_awareness'));
    }

    /**
     * The stat with the given label, as a number.
     *
     * @param array $export The export.
     * @param string $label The stat's label.
     * @return int
     */
    private function stat(array $export, string $label): int {
        foreach ($export['stats'] as $stat) {
            if ($stat['label'] === $label) {
                return (int) str_replace(',', '', $stat['value']);
            }
        }
        $this->fail("no stat labelled {$label}");
    }

    /**
     * The live, draft and competing counts are the course's on a course page and the site's on the site page.
     */
    public function test_the_numbers_follow_the_scope(): void {
        $this->setAdminUser();
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        foreach ([$mine->id, $other->id, 0] as $courseid) {
            // Two live competing notices and one draft, per scope.
            $generator->create_notice(['courseid' => $courseid, 'pathmatch' => '/my/%', 'resetinterval' => WEEKSECS]);
            $generator->create_notice(['courseid' => $courseid, 'pathmatch' => '/my/%', 'resetinterval' => WEEKSECS]);
            $generator->create_notice(['courseid' => $courseid, 'enabled' => 0]);
        }

        $course = $this->export(author_scope::course((int) $mine->id));
        $this->assertSame(2, $this->stat($course, get_string('manage:stat:live', 'local_awareness')));
        $this->assertSame(1, $this->stat($course, get_string('manage:stat:draft', 'local_awareness')));
        $this->assertSame(
            2,
            $this->stat($course, get_string('manage:stat:clash', 'local_awareness')),
            'the course\'s two competing notices'
        );
        $this->assertSame(get_string('manage:lede:course', 'local_awareness'), $course['lede']);

        $site = $this->export(author_scope::site());
        $this->assertSame(6, $this->stat($site, get_string('manage:stat:live', 'local_awareness')));
        $this->assertSame(3, $this->stat($site, get_string('manage:stat:draft', 'local_awareness')));
        $this->assertSame(
            6,
            $this->stat($site, get_string('manage:stat:clash', 'local_awareness')),
            'the site sees every competing notice'
        );
        $this->assertSame(get_string('manage:lede', 'local_awareness'), $site['lede']);
    }

    /**
     * The create button is an author's; a reports-only viewer gets none.
     */
    public function test_the_create_button_is_for_authors(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $scope = author_scope::course((int) $course->id);

        $reader = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:viewreportscourse', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $reader->id, $context->id);
        $this->setUser($reader);
        $this->assertSame('', $this->export($scope)['createurl'], 'a reader is offered nothing to create');

        $author = $this->getDataGenerator()->create_user();
        $authorrole = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $authorrole, $context->id, true);
        role_assign($authorrole, $author->id, $context->id);
        $this->setUser($author);
        $this->assertSame('/create', $this->export($scope)['createurl'], 'an author is: the control');
    }
}
