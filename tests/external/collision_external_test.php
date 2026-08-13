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
 * The web service behind the editor's live collision warning.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external::check_collision
 */
final class collision_external_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create a repeating notice.
     *
     * @param string $title Title.
     * @param string $pathmatch Page reach.
     * @return awareness
     */
    private function repeating(string $title, string $pathmatch): awareness {
        $notice = new awareness(0, (object) [
            'title' => $title,
            'content' => '<p>' . $title . '</p>',
            'pathmatch' => $pathmatch,
            'resetinterval' => DAYSECS,
            'enabled' => 1,
        ]);
        $notice->create();

        return $notice;
    }

    /**
     * It names the notices a new one would compete with.
     */
    public function test_it_names_the_competing_notices(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');
        $this->repeating('Somewhere else', '/user/profile.php');

        $result = external::check_collision(0, '/my/%', true);

        $this->assertSame(['Dashboard rival'], $result['titles']);
    }

    /**
     * A notice that is not set to repeat competes with nobody.
     */
    public function test_a_notice_that_does_not_repeat_reports_nothing(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');

        // Control: the same page reach with repeats on does report, so the empty result below
        // comes from the repeat flag and not from an empty site.
        $this->assertSame(['Dashboard rival'], external::check_collision(0, '/my/%', true)['titles']);

        $this->assertSame([], external::check_collision(0, '/my/%', false)['titles']);
    }

    /**
     * Enumerating notices is limited to users who can manage them.
     *
     * The reply names notices the caller may have no other way of seeing, so the gate is the point
     * rather than a formality.
     */
    public function test_it_requires_the_manage_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        external::check_collision(0, '/my/%', true);
    }

    /**
     * The declared return shape survives cleaning, so the titles actually reach the client.
     *
     * clean_returnvalue() silently drops anything the returns description does not declare, so a
     * structure that disagreed with the payload would strip it and the editor would stay silent.
     */
    public function test_the_declared_return_shape_carries_the_titles(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');
        $_POST['sesskey'] = sesskey();

        $response = \core_external\external_api::call_external_function(
            'local_awareness_check_collision',
            ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true],
            false
        );

        $this->assertFalse($response['error']);
        $this->assertSame(['Dashboard rival'], $response['data']['titles']);
    }
}
