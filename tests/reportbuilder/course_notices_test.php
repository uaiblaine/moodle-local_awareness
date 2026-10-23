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
use core_reportbuilder\exception\report_access_exception;
use core_reportbuilder\external\systemreports\retrieve;
use core_reportbuilder\system_report_factory;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\systemreports\course_notices;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The course report: who may open it, and which rows it holds.
 *
 * The gate is asked through system_report_factory::create(), which runs can_view() as core's report
 * web services do; the rows through core's retrieve web service, which is how the page's table
 * fetches them.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\local_awareness\reportbuilder\local\systemreports\course_notices::class)]
final class course_notices_test extends \advanced_testcase {
    /**
     * Build the report for a course id, as core's report web services build it.
     *
     * The report manager caches an instance per report row and user, not per parameters, so the
     * cache is reset before each build.
     *
     * @param int $courseid The course id handed to the report.
     * @param \context|null $context The context it is created in; the system context by default.
     * @return \core_reportbuilder\system_report
     */
    private function make_report(int $courseid, ?\context $context = null): \core_reportbuilder\system_report {
        \core_reportbuilder\manager::reset_caches();

        return system_report_factory::create(
            course_notices::class,
            $context ?? \context_system::instance(),
            'local_awareness',
            '',
            0,
            ['courseid' => $courseid]
        );
    }

    /**
     * The rows of a course's report, through core's retrieve web service.
     *
     * @param \stdClass $course The course.
     * @return array The notice title and the action of each row, as "title: action", sorted.
     */
    private function rows(\stdClass $course): array {
        \core_reportbuilder\manager::reset_caches();
        $result = retrieve::execute(
            course_notices::class,
            ['contextid' => \context_course::instance($course->id)->id],
            'local_awareness',
            '',
            0,
            [['name' => 'courseid', 'value' => (string) $course->id]],
            0,
            100
        );
        $result = external_api::clean_returnvalue(retrieve::execute_returns(), $result);
        $this->assertSame(count($result['data']['rows']), (int) $result['data']['totalrowcount'], 'the pager counts other rows');

        // Columns: full name, notice title, action, date. The username and ID number columns are identity
        // fields, and the default showuseridentity (email) lists neither.
        $rows = array_map(
            static fn(array $row): string => strip_tags($row['columns'][1]) . ': ' . $row['columns'][2],
            $result['data']['rows']
        );
        sort($rows);

        return $rows;
    }

    /**
     * Put an acknowledgement or a dismissal on record, as production writes it.
     *
     * @param awareness $notice The notice.
     * @param \stdClass $user The user.
     * @param int $action acknowledgement::ACTION_ACKNOWLEDGED or ACTION_DISMISSED.
     */
    private function record(awareness $notice, \stdClass $user, int $action): void {
        (new acknowledgement(0, (object) [
            'userid' => $user->id,
            'username' => $user->username,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'idnumber' => '',
            'noticeid' => $notice->get('id'),
            'noticetitle' => $notice->get('title'),
            'action' => $action,
        ]))->create();
    }

    /**
     * A user holding one capability in a course, logged in.
     *
     * @param string $capability The capability.
     * @param \stdClass $course The course.
     * @return \stdClass The user.
     */
    private function user_with(string $capability, \stdClass $course): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($course->id);
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        return $user;
    }

    /**
     * The report is decided in the course its own parameter names.
     *
     * The course reports holder opens their course's report and is refused another course's, the
     * site course's and a course that does not exist, including another course's requested through
     * their own course's context. Holding the manage capability alone is refused as well: it is
     * the publisher, not the reader of the report.
     */
    public function test_the_report_is_decided_in_the_course_s_own_scope(): void {
        $this->resetAfterTest();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->user_with('local/awareness:viewreportscourse', $mine);
        $this->assertInstanceOf(course_notices::class, $this->make_report((int) $mine->id), 'the holder reads their own course');

        $refused = 0;
        $requests = [
            [(int) $other->id, null],
            [SITEID, null],
            [(int) $other->id + 1000, null],
            [(int) $other->id, \context_course::instance($mine->id)],
        ];
        foreach ($requests as [$courseid, $context]) {
            try {
                $this->make_report($courseid, $context);
            } catch (report_access_exception $e) {
                $refused++;
            }
        }
        $this->assertSame(4, $refused, 'another course, the site course, a missing course and another course behind mine');

        $this->user_with('local/awareness:managecourse', $mine);
        $this->expectException(report_access_exception::class);
        $this->make_report((int) $mine->id);
    }

    /**
     * The rows are both actions on the course's own notices, and nothing of another course or the site.
     *
     * The other course's report is the control: its row exists, so its absence from the first
     * report is the scope, not an empty table.
     */
    public function test_the_rows_are_both_actions_on_the_course_s_own_notices(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $ours = $generator->create_notice(['courseid' => $mine->id, 'title' => 'Ours']);
        $theirs = $generator->create_notice(['courseid' => $other->id, 'title' => 'Theirs']);
        $site = $generator->create_notice(['title' => 'Site wide']);

        $users = [];
        for ($i = 0; $i < 4; $i++) {
            $users[] = $this->getDataGenerator()->create_user();
        }
        $this->record($ours, $users[0], acknowledgement::ACTION_ACKNOWLEDGED);
        $this->record($ours, $users[1], acknowledgement::ACTION_DISMISSED);
        $this->record($theirs, $users[2], acknowledgement::ACTION_ACKNOWLEDGED);
        $this->record($site, $users[3], acknowledgement::ACTION_ACKNOWLEDGED);

        $acknowledged = get_string('report_ack:action_acknowledged', 'local_awareness');
        $dismissed = get_string('report_ack:action_dismissed', 'local_awareness');
        $this->assertSame(["Ours: {$acknowledged}", "Ours: {$dismissed}"], $this->rows($mine));
        $this->assertSame(["Theirs: {$acknowledged}"], $this->rows($other));

        // A course with no notice at all: an empty list of ids, which must not become an empty IN.
        $this->assertSame([], $this->rows($this->getDataGenerator()->create_course()));
    }

    /**
     * A confined author does not read the rows of a notice aimed at a group they may not reach.
     *
     * The course is in separate groups mode. The non-editing teacher belongs to the red group only;
     * the editing teacher, who may access all groups, is the control and reads both notices' rows.
     */
    public function test_a_confined_author_reads_only_the_notices_they_may_reach(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $red = $generator->create_group(['courseid' => $course->id, 'name' => 'Red team']);
        $blue = $generator->create_group(['courseid' => $course->id, 'name' => 'Blue team']);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $editor = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_group_member(['groupid' => $red->id, 'userid' => $teacher->id]);

        $context = \context_course::instance($course->id);
        foreach (['teacher', 'editingteacher'] as $shortname) {
            $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname]);
            assign_capability('local/awareness:viewreportscourse', CAP_ALLOW, $roleid, $context->id, true);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $this->setAdminUser();
        $scope = author_scope::course((int) $course->id);
        foreach (['Red briefing' => $red, 'Blue briefing' => $blue] as $title => $group) {
            helper::create_new_notice(
                (object) ['title' => $title, 'content' => '<p>' . $title . '</p>', 'filter_groups' => [(int) $group->id]],
                $scope
            );
            $this->record(awareness::get_record(['title' => $title]), $student, acknowledgement::ACTION_ACKNOWLEDGED);
        }

        $acknowledged = get_string('report_ack:action_acknowledged', 'local_awareness');
        $this->setUser($teacher);
        $this->assertSame(["Red briefing: {$acknowledged}"], $this->rows($course));

        $this->setUser($editor);
        $this->assertSame(["Blue briefing: {$acknowledged}", "Red briefing: {$acknowledged}"], $this->rows($course));
    }

    /**
     * The username and idnumber copies follow core's identity rule in the course.
     *
     * With both fields listed, a course reports holder without moodle/site:viewuseridentity is
     * offered neither; granted the capability in the course, the same holder is offered both.
     *
     * @return void
     */
    public function test_identity_columns_follow_the_course_s_identity_rule(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email,username,idnumber');
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $user = $this->user_with('local/awareness:viewreportscourse', $course);
        $identity = ['acknowledgement:idnumber', 'acknowledgement:username'];
        $offered = static function (\core_reportbuilder\system_report $report) use ($identity): array {
            return [
                array_values(array_intersect($identity, array_keys($report->get_columns()))),
                array_values(array_intersect($identity, array_keys($report->get_filters()))),
            ];
        };

        $report = $this->make_report($course->id);
        $this->assertArrayHasKey('notice:title', $report->get_columns(), 'the report lost its title column; the read is broken');
        $this->assertSame([[], []], $offered($report), 'identity fields are offered to a viewer who may not see them');

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/site:viewuseridentity', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([$identity, $identity], $offered($this->make_report($course->id)), 'the course capability is not read');
    }
}
