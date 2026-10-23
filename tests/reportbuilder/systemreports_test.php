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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the capability gate on both system reports.
 *
 * local/awareness:viewreports separates "may publish notices" from "may see who acknowledged
 * them". Each case runs against both reports, because each enforces the gate in its own
 * can_view() method.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_awareness\reportbuilder\local\systemreports\acknowledged_notice::class)]
#[CoversClass(\local_awareness\reportbuilder\local\systemreports\dismissed_notice::class)]
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
     * system_report_factory::create() runs can_view() while validating the report and throws
     * report_access_exception when it is false, the same path report/acknowledged_systemreport.php
     * takes. can_view() is protected, so the exception is what the tests assert.
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
     * Holding manage is the realistic case, the person who publishes notices: a can_view() that
     * checked manage instead of viewreports would still refuse a plain user.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
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
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
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
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
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

    /**
     * The download name identifies the notice, so two notices do not export the same filename.
     *
     * core_reportbuilder\manager caches report instances under "reportid:userid", without the
     * parameters, and both notices resolve to the same report persistent. Without the
     * reset_caches() between the two builds the second create() returns the first instance.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_the_download_name_identifies_the_notice(string $class): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $first = $this->seed_notice();
        $second = new awareness(0, (object) [
            'title' => 'Second policy',
            'content' => '<p>Another one.</p>',
        ]);
        $second->create();

        // Precondition: two distinct notices, or "the names differ" would be meaningless.
        $this->assertNotEquals($first->get('id'), $second->get('id'));

        $firstname = $this->make_report($class, $first)->get_downloadfilename();
        \core_reportbuilder\manager::reset_caches();
        $secondname = $this->make_report($class, $second)->get_downloadfilename();

        $this->assertNotSame($firstname, $secondname, 'both notices export under the same filename');
        $this->assertStringContainsString((string) $first->get('id'), $firstname);
        $this->assertStringContainsString((string) $second->get('id'), $secondname);
        $this->assertStringContainsString('Policy update', $firstname);
        $this->assertStringContainsString('Second policy', $secondname);
    }

    /**
     * A title carrying an ampersand reaches the filename in the plain spelling.
     *
     * The sink is a Content-Disposition header, not HTML. With the escaped spelling the title
     * becomes "A &amp; B" and clean_filename() then strips the ampersand, leaving the literal word
     * "amp;" in the name of a compliance export.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_an_ampersand_in_the_title_does_not_become_amp_in_the_filename(string $class): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = new awareness(0, (object) [
            'title' => 'Safety & Conduct',
            'content' => '<p>Read it.</p>',
        ]);
        $notice->create();

        $name = $this->make_report($class, $notice)->get_downloadfilename();

        $this->assertStringNotContainsString('amp;', $name);
        $this->assertStringContainsString('Safety', $name);

        // Precondition: the fixture really does carry the character under test.
        $this->assertStringContainsString('&', $notice->get('title'));
    }

    /**
     * The report is decided in the notice's own scope, read from its own parameter.
     *
     * A course reports holder reads their own course's notice and is refused another course's and
     * the site's, including a site notice requested through their own course's context
     * ({@see acknowledged_notice::can_view()} gives the reason).
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_the_report_is_decided_in_the_notice_s_own_scope(string $class): void {
        $this->resetAfterTest();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $notices = [
            'mine' => $generator->create_notice(['courseid' => $mine->id]),
            'theirs' => $generator->create_notice(['courseid' => $other->id]),
            'site' => $generator->create_notice(),
        ];

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:viewreportscourse', CAP_ALLOW, $roleid, \context_course::instance($mine->id)->id, true);
        role_assign($roleid, $user->id, \context_course::instance($mine->id)->id);
        $this->setUser($user);

        $this->assertInstanceOf($class, $this->make_report($class, $notices['mine']), 'the course reports holder reads their own');

        /*
         * Reset before each build: manager::get_report_from_persistent() caches the instance per
         * report row and user, not per parameters, and production builds one report per request
         * where this test builds several.
         */
        $refused = 0;
        foreach (['theirs', 'site'] as $key) {
            \core_reportbuilder\manager::reset_caches();
            try {
                $this->make_report($class, $notices[$key]);
            } catch (report_access_exception $e) {
                $refused++;
            }
        }
        \core_reportbuilder\manager::reset_caches();
        try {
            system_report_factory::create(
                $class,
                \context_course::instance($mine->id),
                'local_awareness',
                '',
                0,
                ['noticeid' => $notices['site']->get('id')]
            );
        } catch (report_access_exception $e) {
            $refused++;
        }
        $this->assertSame(
            3,
            $refused,
            'another course\'s notice, a site notice, and a site notice behind the course\'s context are all refused'
        );
    }

    /**
     * The snapshot identity columns and filters a report offers.
     *
     * @param \core_reportbuilder\system_report $report The report.
     * @return array Two sorted lists of unique identifiers, keyed columns and filters.
     */
    private function identity_offered(\core_reportbuilder\system_report $report): array {
        $identity = ['acknowledgement:username', 'acknowledgement:idnumber'];
        $columns = array_values(array_intersect($identity, array_keys($report->get_columns())));
        $filters = array_values(array_intersect($identity, array_keys($report->get_filters())));
        sort($columns);
        sort($filters);
        $this->assertArrayHasKey('user:fullname', $report->get_columns(), 'the report lost its name column; the read is broken');

        return ['columns' => $columns, 'filters' => $filters];
    }

    /**
     * The username and idnumber copies are offered only when the site lists them as identity fields.
     *
     * The same viewer and report with showuseridentity at core's default (email) and then listing
     * both fields: the second build is the control proving the viewer could see them.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_identity_columns_follow_showuseridentity(string $class): void {
        $this->resetAfterTest();
        $notice = $this->seed_notice();

        set_config('showuseridentity', 'email');
        $this->assertSame(
            ['columns' => [], 'filters' => []],
            $this->identity_offered($this->make_report($class, $notice)),
            'an identity field the site does not list is offered'
        );

        set_config('showuseridentity', 'email,username,idnumber');
        \core_reportbuilder\manager::reset_caches();
        $both = ['acknowledgement:idnumber', 'acknowledgement:username'];
        $this->assertSame(
            ['columns' => $both, 'filters' => $both],
            $this->identity_offered($this->make_report($class, $notice)),
            'the identity fields the site lists are not offered to an administrator'
        );
    }

    /**
     * Seeing them takes moodle/site:viewuseridentity in the notice's own scope.
     *
     * A course reports holder reads a course notice's report with the fields listed: without the
     * capability nothing is offered; granted in that course only, both are, so the decision is
     * taken in the course and not in the system context the report is built in.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_identity_columns_need_viewuseridentity_in_the_notice_s_scope(string $class): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email,username,idnumber');

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $this->setAdminUser();
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice(['courseid' => $course->id]);

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:viewreportscourse', CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $user->id, $coursecontext->id);
        $this->setUser($user);

        $this->assertFalse(has_capability('moodle/site:viewuseridentity', $coursecontext));
        $this->assertSame(
            ['columns' => [], 'filters' => []],
            $this->identity_offered($this->make_report($class, $notice)),
            'identity fields are offered to a viewer who may not see them'
        );

        assign_capability('moodle/site:viewuseridentity', CAP_ALLOW, $roleid, $coursecontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        \core_reportbuilder\manager::reset_caches();
        $this->assertFalse(has_capability('moodle/site:viewuseridentity', \context_system::instance()));
        $both = ['acknowledgement:idnumber', 'acknowledgement:username'];
        $this->assertSame(
            ['columns' => $both, 'filters' => $both],
            $this->identity_offered($this->make_report($class, $notice)),
            'the capability held in the notice\'s course is not read'
        );
    }
}
