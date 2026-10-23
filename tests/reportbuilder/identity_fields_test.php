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

use core_external\external_api;
use core_reportbuilder\external\systemreports\retrieve;
use core_reportbuilder\system_report;
use core_reportbuilder\system_report_factory;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\systemreports\acknowledged_notice;
use local_awareness\reportbuilder\local\systemreports\course_notices;
use local_awareness\reportbuilder\local\systemreports\dismissed_notice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The identity fields the three system reports show, decided as core's own reports decide them.
 *
 * Every case reads a course notice's reports as a holder of local/awareness:viewreportscourse in
 * that course, so the decision is taken in the course (the notice's scope) and the capabilities
 * the cases grant are granted there only. Columns and filters are read from the built report, the
 * values through core's retrieve web service, which is how the page's table fetches them.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(acknowledged_notice::class)]
#[CoversClass(dismissed_notice::class)]
#[CoversClass(course_notices::class)]
final class identity_fields_test extends \advanced_testcase {
    /** @var \stdClass The course the notice belongs to. */
    private \stdClass $course;

    /** @var awareness The course notice the reports are read for. */
    private awareness $notice;

    /** @var \stdClass The user whose acknowledgement or dismissal is on record. */
    private \stdClass $student;

    /** @var int The role the reader holds in the course, to grant capabilities to. */
    private int $roleid;

    /** @var \stdClass The course reports holder who reads the reports. */
    private \stdClass $reader;

    /**
     * The three system reports, by class name.
     *
     * @return array
     */
    public static function report_provider(): array {
        return [
            'acknowledged' => [acknowledged_notice::class],
            'dismissed' => [dismissed_notice::class],
            'course' => [course_notices::class],
        ];
    }

    /**
     * A course notice, three custom profile fields, one row on record and a course reports holder logged in.
     *
     * 'team' is visible to everyone, 'badge' to teachers and 'secret' to nobody. Core decides a
     * custom field twice, and the reports take both decisions as they come: get_identity_fields()
     * asks is_visible() in the notice's context, and core's user entity makes the field's column
     * and filter available only where is_visible() holds in the system context for a user other
     * than the viewer ({@see \core_reportbuilder\local\helpers\user_profile_fields}): for
     * 'secret' that is moodle/user:viewalldetails at the site, and for 'badge'
     * moodle/site:viewuseridentity in the current course, the site's front page here.
     *
     * @param string $class The system report class, which decides the action the row records.
     */
    private function seed(string $class): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $generator->create_custom_profile_field([
            'shortname' => 'badge',
            'name' => 'Badge',
            'datatype' => 'text',
            'visible' => PROFILE_VISIBLE_TEACHERS,
        ]);
        $generator->create_custom_profile_field([
            'shortname' => 'team',
            'name' => 'Team',
            'datatype' => 'text',
            'visible' => PROFILE_VISIBLE_ALL,
        ]);
        $generator->create_custom_profile_field([
            'shortname' => 'secret',
            'name' => 'Secret',
            'datatype' => 'text',
            'visible' => PROFILE_VISIBLE_NONE,
        ]);

        $this->course = $generator->create_course();
        $this->notice = $generator->get_plugin_generator('local_awareness')->create_notice(['courseid' => $this->course->id]);
        $this->student = $generator->create_user([
            'username' => 'live.name',
            'idnumber' => 'LIVE-1',
            'email' => 'reader.one@example.com',
            'department' => 'Forensics',
            'profile_field_badge' => 'B-42',
            'profile_field_secret' => 'S-7',
            'profile_field_team' => 'Blue',
        ]);
        $this->record($class, $this->student);

        $this->reader = $generator->create_user();
        $this->roleid = $generator->create_role();
        $this->grant('local/awareness:viewreportscourse');
        role_assign($this->roleid, $this->reader->id, \context_course::instance($this->course->id)->id);
        $this->setUser($this->reader);
    }

    /**
     * Put a row on record for a user, as production writes it, with snapshot copies unlike the live fields.
     *
     * @param string $class The system report class: a dismissal for the dismissal report, else an acknowledgement.
     * @param \stdClass $user The user.
     */
    private function record(string $class, \stdClass $user): void {
        (new acknowledgement(0, (object) [
            'userid' => $user->id,
            'username' => 'snapshot.name',
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'idnumber' => 'SNAP-1',
            'noticeid' => $this->notice->get('id'),
            'noticetitle' => $this->notice->get('title'),
            'action' => $class === dismissed_notice::class
                ? acknowledgement::ACTION_DISMISSED
                : acknowledgement::ACTION_ACKNOWLEDGED,
        ]))->create();
    }

    /**
     * Grant the reader's role a capability in the course.
     *
     * @param string $capability The capability.
     */
    private function grant(string $capability): void {
        assign_capability($capability, CAP_ALLOW, $this->roleid, \context_course::instance($this->course->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Grant the reader a capability at the site, through a role of its own.
     *
     * @param string $capability The capability.
     */
    private function grant_at_site(string $capability): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->reader->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The report's parameters: the course for the course report, the notice for the other two.
     *
     * @param string $class The system report class.
     * @return array
     */
    private function parameters(string $class): array {
        return $class === course_notices::class
            ? ['courseid' => (int) $this->course->id]
            : ['noticeid' => (int) $this->notice->get('id')];
    }

    /**
     * Build the report as core's report web services build it, in the system context.
     *
     * The report manager caches an instance per report row and user, not per parameters or
     * capabilities, so the cache is reset before each build.
     *
     * @param string $class The system report class.
     * @return system_report
     */
    private function make_report(string $class): system_report {
        \core_reportbuilder\manager::reset_caches();

        return system_report_factory::create(
            $class,
            \context_system::instance(),
            'local_awareness',
            '',
            0,
            $this->parameters($class)
        );
    }

    /**
     * The identity columns and filters a report offers, in its order.
     *
     * @param string $class The system report class.
     * @return array The unique identifiers of the 'identity' entity, keyed columns and filters.
     */
    private function offered(string $class): array {
        $report = $this->make_report($class);
        $this->assertArrayHasKey('user:fullname', $report->get_columns(), 'the report lost its name column; the read is broken');
        $this->assertArrayHasKey('acknowledgement:timecreated', $report->get_columns(), 'the report lost its date column');
        $identity = static fn(array $keys): array => array_values(array_filter(
            $keys,
            static fn(string $key): bool => str_starts_with($key, 'identity:')
        ));

        return [
            'columns' => $identity(array_keys($report->get_columns())),
            'filters' => $identity(array_keys($report->get_filters())),
        ];
    }

    /**
     * The rows of a report through core's retrieve web service, each keyed by full name.
     *
     * @param string $class The system report class.
     * @return array Per full name, the row's identity values keyed by column identifier, then 'all', the whole row joined.
     */
    private function rows(string $class): array {
        $identity = $this->offered($class)['columns'];
        \core_reportbuilder\manager::reset_caches();
        $parameters = [];
        foreach ($this->parameters($class) as $name => $value) {
            $parameters[] = ['name' => $name, 'value' => (string) $value];
        }
        $result = retrieve::execute(
            $class,
            ['contextid' => \context_system::instance()->id],
            'local_awareness',
            '',
            0,
            $parameters,
            0,
            100
        );
        $result = external_api::clean_returnvalue(retrieve::execute_returns(), $result);

        // Columns: full name, then the identity fields in the report's order.
        $rows = [];
        foreach ($result['data']['rows'] as $row) {
            $values = array_combine($identity, array_slice($row['columns'], 1, count($identity)));
            $values['all'] = implode(' | ', $row['columns']);
            $rows[strip_tags($row['columns'][0])] = $values;
        }

        return $rows;
    }

    /**
     * The student's row.
     *
     * @param string $class The system report class.
     * @return array The row's identity values keyed by column identifier, and 'all'.
     */
    private function student_row(string $class): array {
        $rows = $this->rows($class);
        $this->assertCount(1, $rows, 'the report does not hold the one row on record');

        return reset($rows);
    }

    /**
     * The fields the site lists show to a reader holding moodle/site:viewuseridentity in the notice's course, and only then.
     *
     * The same reader and report before and after the grant: the first build is refused every
     * field and shows none of the values, the second shows the email, the department and the
     * custom field visible to everyone, with their values, as column and as filter.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_the_listed_fields_follow_viewuseridentity_in_the_notice_s_course(string $class): void {
        $this->seed($class);
        set_config('showuseridentity', 'email,department,profile_field_team');

        $none = ['columns' => [], 'filters' => []];
        $this->assertSame($none, $this->offered($class), 'identity fields are offered without the capability');
        $row = $this->student_row($class);
        foreach (['reader.one@example.com', 'Forensics', 'Blue'] as $value) {
            $this->assertStringNotContainsString($value, $row['all'], "the row shows {$value} without the capability");
        }

        $this->grant('moodle/site:viewuseridentity');
        $this->assertFalse(has_capability('moodle/site:viewuseridentity', \context_system::instance()));
        $listed = ['identity:email', 'identity:department', 'identity:profilefield_team'];
        $offered = ['columns' => $listed, 'filters' => $listed];
        $this->assertSame($offered, $this->offered($class), 'the capability held in the course is not read');
        $row = $this->student_row($class);
        $this->assertSame('reader.one@example.com', $row['identity:email']);
        $this->assertSame('Forensics', $row['identity:department']);
        $this->assertSame('Blue', $row['identity:profilefield_team']);
    }

    /**
     * A custom field visible to teachers shows with its value where core's report builder admits it.
     *
     * Held in the notice's course only, moodle/site:viewuseridentity passes get_identity_fields(),
     * and core's user entity still withholds the field (see seed()); held at the site as well, the
     * field is offered and its value shown. The email is the control throughout.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_a_field_visible_to_teachers_follows_core_s_report_builder(string $class): void {
        $this->seed($class);
        set_config('showuseridentity', 'email,profile_field_badge');
        $this->grant('moodle/site:viewuseridentity');

        $this->assertSame(['columns' => ['identity:email'], 'filters' => ['identity:email']], $this->offered($class));
        $this->assertStringNotContainsString('B-42', $this->student_row($class)['all']);

        $this->grant_at_site('moodle/site:viewuseridentity');
        $both = ['identity:email', 'identity:profilefield_badge'];
        $offered = ['columns' => $both, 'filters' => $both];
        $this->assertSame($offered, $this->offered($class), 'the field is withheld from a reader core admits');
        $this->assertSame('B-42', $this->student_row($class)['identity:profilefield_badge']);
    }

    /**
     * A field listed in hiddenuserfields shows only to a holder of moodle/course:viewhiddenuserfields in the course.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_a_hidden_field_needs_the_hidden_fields_capability(string $class): void {
        $this->seed($class);
        set_config('showuseridentity', 'email,department');
        set_config('hiddenuserfields', 'department');
        $this->grant('moodle/site:viewuseridentity');

        $this->assertSame(['identity:email'], $this->offered($class)['columns'], 'a hidden field is shown');
        $this->assertSame(['identity:email'], $this->offered($class)['filters'], 'a hidden field is offered as a filter');
        $this->assertStringNotContainsString('Forensics', $this->student_row($class)['all']);

        $this->grant('moodle/course:viewhiddenuserfields');
        $both = ['identity:email', 'identity:department'];
        $offered = ['columns' => $both, 'filters' => $both];
        $this->assertSame($offered, $this->offered($class), 'the hidden-fields capability is not read');
        $this->assertSame('Forensics', $this->student_row($class)['identity:department']);
    }

    /**
     * A custom profile field that profile_field_base::is_visible() refuses is not offered.
     *
     * 'secret' is visible to nobody, which comes down to moodle/user:viewalldetails (see seed());
     * granting it at the site is the control. 'team', visible to everyone, shows throughout.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_a_custom_field_is_visible_refuses_is_not_offered(string $class): void {
        $this->seed($class);
        set_config('showuseridentity', 'email,profile_field_secret,profile_field_team');
        $this->grant('moodle/site:viewuseridentity');

        $shown = ['identity:email', 'identity:profilefield_team'];
        $offered = ['columns' => $shown, 'filters' => $shown];
        $this->assertSame($offered, $this->offered($class), 'a field is_visible() refuses is offered');
        $row = $this->student_row($class);
        $this->assertSame('Blue', $row['identity:profilefield_team']);
        $this->assertStringNotContainsString('S-7', $row['all']);

        $this->grant_at_site('moodle/user:viewalldetails');
        $all = ['identity:email', 'identity:profilefield_secret', 'identity:profilefield_team'];
        $this->assertSame(['columns' => $all, 'filters' => $all], $this->offered($class), 'is_visible() is not what decides');
        $this->assertSame('S-7', $this->student_row($class)['identity:profilefield_secret']);
    }

    /**
     * With core's default setting the email is the one identity column shown.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_core_s_default_shows_the_email(string $class): void {
        global $CFG;

        $this->seed($class);
        $this->assertSame('email', $CFG->showuseridentity, 'precondition: the site keeps core\'s default');
        $this->grant('moodle/site:viewuseridentity');

        $this->assertSame(['columns' => ['identity:email'], 'filters' => ['identity:email']], $this->offered($class));
        $this->assertSame('reader.one@example.com', $this->student_row($class)['identity:email']);
    }

    /**
     * The username and idnumber are the live account's, under the identity rule; the row's copies are not offered.
     *
     * The row on record holds other copies ('snapshot.name', 'SNAP-1') than the account
     * ('live.name', 'LIVE-1'), so a value read from the copy would show.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_the_username_and_idnumber_are_the_live_identity_fields(string $class): void {
        $this->seed($class);
        set_config('showuseridentity', 'username,idnumber');
        $this->grant('moodle/site:viewuseridentity');

        $report = $this->make_report($class);
        foreach (['acknowledgement:username', 'acknowledgement:idnumber'] as $copy) {
            $this->assertArrayNotHasKey($copy, $report->get_columns(), "{$copy} is offered as a column");
            $this->assertArrayNotHasKey($copy, $report->get_filters(), "{$copy} is offered as a filter");
        }
        $listed = ['identity:username', 'identity:idnumber'];
        $this->assertSame(['columns' => $listed, 'filters' => $listed], $this->offered($class));
        $row = $this->student_row($class);
        $this->assertSame('live.name', $row['identity:username']);
        $this->assertSame('LIVE-1', $row['identity:idnumber']);
        $this->assertStringNotContainsString('snapshot.name', $row['all']);
        $this->assertStringNotContainsString('SNAP-1', $row['all']);
    }

    /**
     * A deleted user's row keeps its name and shows no identity data; a live user's row is the control.
     *
     * delete_user() keeps the department and puts the old email inside the username, so both would
     * show through a join that read a deleted account.
     *
     * @param string $class The system report class.
     */
    #[DataProvider('report_provider')]
    public function test_a_deleted_user_shows_no_identity_data(string $class): void {
        global $USER;

        $this->seed($class);
        set_config('showuseridentity', 'email,department,username');
        $this->grant('moodle/site:viewuseridentity');
        $reader = $USER;

        $this->setAdminUser();
        $gone = $this->getDataGenerator()->create_user([
            'firstname' => 'Gone',
            'lastname' => 'Reader',
            'email' => 'gone.reader@example.com',
            'department' => 'Forensics',
        ]);
        $this->record($class, $gone);
        delete_user($gone);
        $this->setUser($reader);

        $rows = $this->rows($class);
        $this->assertCount(2, $rows, 'the deleted user\'s row left the report');
        $live = $rows[fullname($this->student)];
        $this->assertSame('reader.one@example.com', $live['identity:email'], 'the control row shows no email');
        $this->assertSame('Forensics', $live['identity:department'], 'the control row shows no department');

        $deleted = $rows['Gone Reader'];
        foreach (['identity:email', 'identity:department', 'identity:username'] as $column) {
            $this->assertSame('', $deleted[$column], "the deleted user's {$column} is shown");
        }
        $this->assertStringNotContainsString('gone.reader@example.com', $deleted['all']);
    }
}
