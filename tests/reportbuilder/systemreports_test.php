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

namespace local_awareness\reportbuilder;

use core_reportbuilder\exception\report_access_exception;
use core_reportbuilder\system_report_factory;
use local_awareness\helper;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\systemreports\acknowledged_notice;
use local_awareness\reportbuilder\local\systemreports\dismissed_notice;

/**
 * Tests the capability gate on both system reports.
 *
 * local/awareness:viewreports had ZERO coverage anywhere in the plugin — the capability existed,
 * was declared in db/access.php, was enforced at four points, and no test mentioned it. It is
 * also the capability separating "may publish notices" from "may see who acknowledged them",
 * which is the distinction that makes the acknowledgement report a compliance record rather than
 * an admin convenience.
 *
 * Both reports get the same pair, because they enforce it in two separate can_view() methods:
 * a fix applied to one and forgotten in the other is exactly the failure a shared test misses.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\reportbuilder\local\systemreports\acknowledged_notice
 * @covers \local_awareness\reportbuilder\local\systemreports\dismissed_notice
 */
final class systemreports_test extends \advanced_testcase {
    /**
     * The two system reports, by class name.
     *
     * @return array
     */
    public static function report_provider(): array {
        return [
            'acknowledged' => [acknowledged_notice::class],
            'dismissed' => [dismissed_notice::class],
        ];
    }

    /**
     * Create one notice to point the report at.
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
     * Build the report for a notice.
     *
     * system_report_factory::create() applies can_view() itself and throws report_access_exception
     * when it is false, so this call IS the enforcement point — the same one a user reaching
     * report/acknowledged_systemreport.php goes through. can_view() is protected and cannot be
     * asserted directly; the exception is the observable behaviour and the better thing to pin.
     *
     * @param string $class The system report class.
     * @param awareness $notice The notice the report is scoped to.
     * @return \core_reportbuilder\system_report
     */
    private function make_report(string $class, awareness $notice) {
        return system_report_factory::create(
            $class,
            \context_system::instance(),
            'local_awareness',
            '',
            0,
            ['noticeid' => $notice->get('id')]
        );
    }

    /**
     * A user holding local/awareness:manage but NOT viewreports cannot view either report.
     *
     * The pairing is the point. Holding manage is the realistic case — the person who publishes
     * notices — and if can_view() ever read the manage capability instead, every "plain user"
     * test would still pass while the separation the capability exists for had quietly gone.
     *
     * @dataProvider report_provider
     * @param string $class The system report class.
     */
    public function test_manage_alone_does_not_grant_the_report(string $class): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice();

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

        $this->assertTrue(has_capability('local/awareness:manage', \context_system::instance()));
        $this->assertFalse(has_capability('local/awareness:viewreports', \context_system::instance()));

        $this->expectException(report_access_exception::class);
        $this->make_report($class, $notice);
    }

    /**
     * A user with no capabilities at all cannot view either report.
     *
     * @dataProvider report_provider
     * @param string $class The system report class.
     */
    public function test_a_plain_user_cannot_view_the_report(string $class): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(report_access_exception::class);
        $this->make_report($class, $notice);
    }

    /**
     * Granting viewreports — and nothing else — is enough.
     *
     * The control for both refusals: same user, same report, one capability added.
     *
     * @dataProvider report_provider
     * @param string $class The system report class.
     */
    public function test_viewreports_alone_grants_the_report(string $class): void {
        $this->resetAfterTest();

        $notice = $this->seed_notice();

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/awareness:viewreports',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $this->assertFalse(has_capability('local/awareness:manage', \context_system::instance()));
        $this->assertInstanceOf($class, $this->make_report($class, $notice));
    }
}
