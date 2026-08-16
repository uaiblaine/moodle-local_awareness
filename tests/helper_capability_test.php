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
 * Tests that every write entry point refuses a user without local/awareness:manage.
 *
 * check_manage_capability() is the plugin's only write gate and it had no negative test at all:
 * deleting the guard from any of its six call sites turned nothing red. The existing capability
 * tests all live in the external layer, so the helper — which editnotice.php and managenotice.php
 * call directly — was unguarded in the suite.
 *
 * Each case runs the SAME call twice: once as a plain user expecting the exception, once as a
 * capability holder expecting it to succeed. Without the positive half a test passes when the
 * call fails for any reason at all — a bad argument, a missing config — and would keep passing
 * after the capability check it was written for is deleted.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::check_manage_capability
 */
final class helper_capability_test extends \advanced_testcase {
    /**
     * Seed one notice, as admin, outside the code path under test.
     *
     * @return awareness
     */
    private function seed_notice(): awareness {
        $this->setAdminUser();
        helper::create_new_notice((object) [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'perpetual' => 1,
        ]);

        $notices = array_values(awareness::get_enabled_notices());
        return reset($notices);
    }

    /**
     * The six write entry points, each as a callable taking the seeded notice.
     *
     * @return array
     */
    public static function write_entry_point_provider(): array {
        $formdata = (object) [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'perpetual' => 1,
        ];

        return [
            'create_new_notice' => [
                static function (awareness $notice) use ($formdata): void {
                    helper::create_new_notice($formdata);
                },
            ],
            'update_notice' => [
                static function (awareness $notice) use ($formdata): void {
                    $data = clone $formdata;
                    $data->id = $notice->get('id');
                    helper::update_notice($notice, $data);
                },
            ],
            'reset_notice' => [
                static function (awareness $notice): void {
                    helper::reset_notice($notice);
                },
            ],
            'enable_notice' => [
                static function (awareness $notice): void {
                    helper::enable_notice($notice);
                },
            ],
            'disable_notice' => [
                static function (awareness $notice): void {
                    helper::disable_notice($notice);
                },
            ],
            'delete_notice' => [
                static function (awareness $notice): void {
                    helper::delete_notice($notice);
                },
            ],
        ];
    }

    /**
     * A user without the capability is refused by every write entry point.
     *
     * @dataProvider write_entry_point_provider
     * @param callable $write The write to attempt.
     */
    public function test_write_entry_points_reject_a_user_without_the_capability(callable $write): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);
        $write($notice);
    }

    /**
     * The same six calls succeed once the capability is granted.
     *
     * The control for the test above. It grants local/awareness:manage to an ordinary user with
     * assign_capability() rather than using setAdminUser(), so what is being proved is that THIS
     * capability is what the gate reads — a site admin passes every has_capability() check there
     * is and would prove nothing about which one is enforced.
     *
     * @dataProvider write_entry_point_provider
     * @param callable $write The write to attempt.
     */
    public function test_write_entry_points_accept_a_capability_holder(callable $write): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice();
        set_config('allow_update', 1, 'local_awareness');
        set_config('allow_delete', 1, 'local_awareness');

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/awareness:manage',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $write($notice);

        // Reaching here without required_capability_exception is the assertion; state it so the
        // case is not reported as risky and so the intent is explicit to a reader.
        $this->assertTrue(has_capability('local/awareness:manage', \context_system::instance()));
    }
}
