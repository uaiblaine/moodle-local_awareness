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

use core_external\external_api;
use core_reportbuilder\external\systemreports\retrieve;
use local_awareness\form\notice_form;
use local_awareness\local\author_scope;
use local_awareness\local\collision;
use local_awareness\output\manage_page;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\systemreports\acknowledged_notice;
use local_awareness\reportbuilder\local\systemreports\course_notices;
use local_awareness\reportbuilder\local\systemreports\dismissed_notice;
use local_awareness\table\all_notices;

/**
 * What separate groups keep a confined author from beyond the notices aimed at other groups.
 *
 * A teacher without moodle/site:accessallgroups in a separate groups course reads a notice for the
 * whole course, but may not publish one or change one, because the whole course holds the groups
 * they are kept from; reads the report rows of their own groups' members only, whatever notice the
 * rows belong to; and is told about a rival aimed at another group without its title, and without
 * it in the list's counts. An editing teacher, who may access all groups, is the control throughout.
 *
 * Coverage stays in this docblock rather than in #[CoversClass]: the moodle-cs release used with
 * Moodle 4.5 cannot see PHPUnit attributes and reports every test as missing coverage. Move to the
 * attribute when 4.5 support is dropped.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\group_scope
 * @covers \local_awareness\local\author_scope
 * @covers \local_awareness\helper
 * @covers \local_awareness\form\notice_form
 * @covers \local_awareness\table\all_notices
 * @covers \local_awareness\local\collision
 * @covers \local_awareness\output\manage_page
 * @covers \local_awareness\reportbuilder\local\systemreports\acknowledged_notice
 * @covers \local_awareness\reportbuilder\local\systemreports\course_notices
 * @covers \local_awareness\reportbuilder\local\systemreports\dismissed_notice
 */
final class separate_groups_confinement_test extends \advanced_testcase {
    /** @var \stdClass The course, in separate groups mode. */
    private \stdClass $course;

    /** @var \stdClass A group. */
    private \stdClass $red;

    /** @var \stdClass The other group. */
    private \stdClass $blue;

    /** @var \stdClass A student in the red group. */
    private \stdClass $red1;

    /** @var \stdClass A student in the blue group. */
    private \stdClass $blue1;

    /** @var \stdClass A student in no group. */
    private \stdClass $loner;

    /** @var \stdClass A non-editing teacher in the red group, holding both course capabilities. */
    private \stdClass $teacher;

    /** @var \stdClass A non-editing teacher in no group, holding both course capabilities. */
    private \stdClass $drifter;

    /** @var \stdClass An editing teacher, holding both course capabilities and accessallgroups. */
    private \stdClass $editor;

    /** @var awareness A notice for the red group. */
    private awareness $forred;

    /** @var awareness A notice for the blue group. */
    private awareness $forblue;

    /** @var awareness A notice for everyone in the course. */
    private awareness $forall;

    /**
     * A separate-groups course, two groups, three students, three teachers, three notices.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $this->red = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Red team']);
        $this->blue = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Blue team']);

        $this->red1 = $generator->create_and_enrol($this->course, 'student');
        $this->blue1 = $generator->create_and_enrol($this->course, 'student');
        $this->loner = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $this->red->id, 'userid' => $this->red1->id]);
        $generator->create_group_member(['groupid' => $this->blue->id, 'userid' => $this->blue1->id]);

        $context = \context_course::instance($this->course->id);
        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
        $this->drifter = $generator->create_and_enrol($this->course, 'teacher');
        $this->editor = $generator->create_and_enrol($this->course, 'editingteacher');
        $generator->create_group_member(['groupid' => $this->red->id, 'userid' => $this->teacher->id]);
        foreach (['teacher', 'editingteacher'] as $shortname) {
            $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname]);
            foreach (['local/awareness:managecourse', 'local/awareness:viewreportscourse'] as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
            }
        }
        accesslib_clear_all_caches_for_unit_testing();

        $this->setAdminUser();
        $this->forred = $this->notice('Red briefing', [(int) $this->red->id]);
        $this->forblue = $this->notice('Blue briefing', [(int) $this->blue->id]);
        $this->forall = $this->notice('Everyone', []);
    }

    /**
     * A course notice saved through the real path, aimed at the given groups.
     *
     * @param string $title The title, unique in the test.
     * @param int[]|null $groups The groups, none, or null to leave the field out of the submission.
     * @return awareness
     */
    private function notice(string $title, ?array $groups): awareness {
        $data = (object) ['title' => $title, 'content' => '<p>' . $title . '</p>', 'enabled' => 1];
        if ($groups !== null) {
            $data->filter_groups = $groups;
        }
        helper::create_new_notice($data, author_scope::course((int) $this->course->id));

        return awareness::get_record(['title' => $title]);
    }

    /**
     * The form's validation of a course notice, as the current user would submit it.
     *
     * @param array $fields The submitted fields besides the body.
     * @return array The errors, keyed by element.
     */
    private function validate(array $fields): array {
        $form = new notice_form(null, [
            'persistent' => null,
            'id' => 0,
            'scope' => author_scope::course((int) $this->course->id),
        ]);
        $method = new \ReflectionMethod($form, 'extra_validation');
        $errors = [];
        $body = ['content' => ['text' => '<p>Body</p>', 'format' => FORMAT_HTML]];

        return $method->invokeArgs($form, [(object) ($fields + $body), [], &$errors]);
    }

    /**
     * A confined author may address their own groups, and not the whole course.
     *
     * Refused on the save path, with or without the field in the submission, and by the form on
     * the field, which a confined author is given even with no group to offer. The editing teacher
     * saving the same empty selection is the control that the empty list itself is legal.
     */
    public function test_a_confined_author_may_not_address_the_whole_course(): void {
        $own = get_string('scope:problem:filter_groups:own', 'local_awareness');

        $this->setUser($this->teacher);
        $this->assertSame('Red only', $this->notice('Red only', [(int) $this->red->id])->get('title'));
        foreach (['an empty list' => [], 'no field' => null] as $case => $groups) {
            try {
                $this->notice("Course-wide {$case}", $groups);
                $this->fail("a confined author saved a notice for the whole course with {$case}");
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('filter_groups', $e->getMessage());
            }
        }
        $this->assertSame(['filter_groups' => $own], $this->validate(['filter_groups' => []]));
        $this->assertSame(['filter_groups' => $own], $this->validate([]));
        $this->assertSame([], $this->validate(['filter_groups' => [(int) $this->red->id]]));

        // In no group at all, nothing is theirs to address, and the field still carries the refusal.
        $this->setUser($this->drifter);
        $this->assertSame(['filter_groups' => $own], $this->validate([]));
        $form = new notice_form(null, [
            'persistent' => null,
            'id' => 0,
            'scope' => author_scope::course((int) $this->course->id),
        ]);
        $mform = (new \ReflectionProperty($form, '_form'))->getValue($form);
        $this->assertTrue($mform->elementExists('filter_groups'), 'the refusal needs a field to be shown on');
        $this->expectException(\invalid_parameter_exception::class);
        $this->notice('Drifter notice', []);
    }

    /**
     * The control: an author who may access all groups addresses the whole course.
     */
    public function test_an_author_who_may_access_all_groups_addresses_the_whole_course(): void {
        $this->setUser($this->editor);
        $this->assertSame([], \local_awareness\local\group_scope::targeted($this->notice('Editor course-wide', [])));
        $this->assertSame([], $this->validate(['filter_groups' => []]));
        $this->assertFalse(author_scope::course((int) $this->course->id)->addresses_unreachable_groups([]));
    }

    /**
     * A confined author reads a notice for the whole course and changes none of it.
     *
     * Every write is refused: the editor page's resolver, the five action methods and the list's
     * row actions; reading is not, so the reports and the preview still resolve. The notice for
     * their own group is the control that the same author writes there.
     */
    public function test_a_confined_author_reads_a_course_wide_notice_and_changes_nothing(): void {
        $this->setUser($this->teacher);
        $this->assertTrue(helper::may_reach_groups($this->forall));
        $this->assertFalse(helper::may_write_groups($this->forall));
        $this->assertTrue(helper::may_write_groups($this->forred));

        $this->assertSame(
            (int) $this->forall->get('id'),
            (int) helper::resolve_notice_as_author((int) $this->forall->get('id'), 'viewreports')->get('id')
        );
        try {
            helper::resolve_notice_as_author((int) $this->forall->get('id'), 'manage');
            $this->fail('the editor resolved a course-wide notice for a confined author');
        } catch (\moodle_exception $e) {
            $this->assertSame('notification:noticedoesnotexist', $e->errorcode);
        }

        $writes = [
            'disable' => fn() => helper::disable_notice($this->forall),
            'enable' => fn() => helper::enable_notice($this->forall),
            'reset' => fn() => helper::reset_notice($this->forall),
            'update' => fn() => helper::update_notice($this->forall, (object) ['title' => 'Hijacked']),
            'delete' => fn() => helper::delete_notice($this->forall),
        ];
        foreach ($writes as $name => $write) {
            try {
                $write();
                $this->fail("{$name} went through on a course-wide notice for a confined author");
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string('moodle/site:accessallgroups'), $e->a);
            }
        }
        $stored = awareness::get_record(['id' => $this->forall->get('id')]);
        $this->assertSame('Everyone', $stored->get('title'));
        $this->assertSame(1, (int) $stored->get('enabled'));

        // The control: their own group's notice is theirs to switch.
        helper::disable_notice($this->forred);
        $this->assertSame(0, (int) awareness::get_record(['id' => $this->forred->get('id')])->get('enabled'));

        // The list offers the course-wide row its preview and no verb; their own row keeps its verbs.
        $table = new all_notices('test', new \moodle_url('/local/awareness/managenotice.php'));
        $actions = new \ReflectionMethod(all_notices::class, 'col_actions');
        $disable = get_string('notice:disable', 'local_awareness');
        $this->assertStringNotContainsString('action=disable', $actions->invoke($table, $this->forall));
        $preview = get_string('notice:preview', 'local_awareness');
        $this->assertStringContainsString($preview, $actions->invoke($table, $this->forall));
        $this->assertStringNotContainsString($disable, $actions->invoke($table, $this->forall));
        $enable = get_string('notice:enable', 'local_awareness');
        $this->assertStringContainsString($enable, $actions->invoke($table, $this->forred->read()));
    }

    /**
     * The control: an author who may access all groups changes the course-wide notice.
     */
    public function test_an_author_who_may_access_all_groups_changes_a_course_wide_notice(): void {
        $this->setUser($this->editor);
        $this->assertTrue(helper::may_write_groups($this->forall));
        helper::disable_notice($this->forall);
        $this->assertSame(0, (int) awareness::get_record(['id' => $this->forall->get('id')])->get('enabled'));
    }

    /**
     * The reports list a confined reader only the members of their own groups.
     *
     * Two course-wide notices, one acknowledged and one dismissed by a red student, a blue student
     * and a student in no group: the red teacher reads the red student's rows and no other, in both
     * notices' reports and in the course report; the editing teacher reads them all.
     */
    public function test_the_reports_list_a_confined_reader_their_own_groups_only(): void {
        $this->setAdminUser();
        $dismissed = $this->notice('Everyone, dismissed', []);
        foreach ([$this->red1, $this->blue1, $this->loner] as $student) {
            $this->record($this->forall, $student);
            $this->record($dismissed, $student, acknowledgement::ACTION_DISMISSED);
        }
        $all = [fullname($this->blue1), fullname($this->loner), fullname($this->red1)];
        sort($all);

        $this->setUser($this->teacher);
        $bynotice = ['noticeid' => $this->forall->get('id')];
        $bydismissal = ['noticeid' => $dismissed->get('id')];
        $bycourse = ['courseid' => $this->course->id];

        $this->setUser($this->teacher);
        $this->assertSame([fullname($this->red1)], $this->names(acknowledged_notice::class, $bynotice));
        $this->assertSame([fullname($this->red1)], $this->names(dismissed_notice::class, $bydismissal));
        $this->assertSame([fullname($this->red1), fullname($this->red1)], $this->names(course_notices::class, $bycourse));

        $this->setUser($this->drifter);
        $this->assertSame([], $this->names(acknowledged_notice::class, $bynotice));

        $this->setUser($this->editor);
        $this->assertSame($all, $this->names(acknowledged_notice::class, $bynotice));
        $this->assertSame($all, $this->names(dismissed_notice::class, $bydismissal));
        $this->assertCount(6, $this->names(course_notices::class, $bycourse));
    }

    /**
     * A rival aimed at another group is described to a confined author, not named, and not counted.
     */
    public function test_a_rival_for_another_group_is_described_and_not_counted(): void {
        $scope = author_scope::course((int) $this->course->id);

        $redacted = get_string('collision:redacted:group', 'local_awareness');

        $this->setUser($this->teacher);
        $this->assertSame($redacted, collision::visible_title($this->forblue, $scope));
        $this->assertSame('Red briefing', collision::visible_title($this->forred, $scope));
        $this->assertSame('Everyone', collision::visible_title($this->forall, $scope));
        $this->assertSame(2, $this->listed_count());

        $this->setUser($this->editor);
        $this->assertSame('Blue briefing', collision::visible_title($this->forblue, $scope));
        $this->assertSame(3, $this->listed_count());
    }

    /**
     * Live plus draft, as the manage page's counts strip shows them for the course.
     *
     * @return int
     */
    private function listed_count(): int {
        $page = new manage_page('', '', [], author_scope::course((int) $this->course->id));
        $stats = (new \ReflectionMethod(manage_page::class, 'stats'))->invoke($page);

        return (int) $stats[0]['value'] + (int) $stats[1]['value'];
    }

    /**
     * Put an acknowledgement on record, as production writes it.
     *
     * @param awareness $notice The notice.
     * @param \stdClass $user The user.
     * @param int $action acknowledgement::ACTION_ACKNOWLEDGED or ACTION_DISMISSED.
     */
    private function record(awareness $notice, \stdClass $user, int $action = acknowledgement::ACTION_ACKNOWLEDGED): void {
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
     * The full names a system report lists, through core's retrieve web service, sorted.
     *
     * @param string $class The system report class.
     * @param array $parameters The report's parameters.
     * @return string[]
     */
    private function names(string $class, array $parameters): array {
        \core_reportbuilder\manager::reset_caches();
        $result = retrieve::execute(
            $class,
            ['contextid' => \context_system::instance()->id],
            'local_awareness',
            '',
            0,
            array_map(
                static fn($name, $value) => ['name' => $name, 'value' => (string) $value],
                array_keys($parameters),
                $parameters
            ),
            0,
            100
        );
        $result = external_api::clean_returnvalue(retrieve::execute_returns(), $result);
        $names = array_map(static fn(array $row): string => strip_tags($row['columns'][0]), $result['data']['rows']);
        sort($names);

        return $names;
    }
}
