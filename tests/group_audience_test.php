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

use core_table\local\filter\filter;
use core_table\local\filter\integer_filter;
use local_awareness\audience\rule_describer;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;
use local_awareness\table\all_notices;
use local_awareness\table\all_notices_filterset;

/**
 * A course notice aimed at groups: who receives it, and who may reach it.
 *
 * Receiving is membership. Reaching is core's separate-groups rule: a teacher without
 * moodle/site:accessallgroups neither sees nor changes a notice aimed only at groups they are not
 * in, and the four places that enforce it — the page resolver, the action methods, the file gate's
 * author branch and the manage list's query — are each pinned here against the same three notices,
 * with an editing teacher as the control that the capability opens all of them.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\helper
 * @covers     \local_awareness\table\all_notices
 * @covers     \local_awareness\audience\rule_describer
 */
final class group_audience_test extends \advanced_testcase {
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

    /** @var \stdClass A non-editing teacher in the red group, holding the course capability. */
    private \stdClass $teacher;

    /** @var \stdClass An editing teacher, holding the course capability and accessallgroups. */
    private \stdClass $editor;

    /** @var awareness A notice for the red group. */
    private awareness $forred;

    /** @var awareness A notice for the blue group. */
    private awareness $forblue;

    /** @var awareness A notice for everyone in the course. */
    private awareness $forall;

    /**
     * A separate-groups course, two groups, three students, two teachers, three notices.
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
        $this->editor = $generator->create_and_enrol($this->course, 'editingteacher');
        $generator->create_group_member(['groupid' => $this->red->id, 'userid' => $this->teacher->id]);
        foreach (['teacher', 'editingteacher'] as $shortname) {
            $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname]);
            assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, $context->id, true);
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
     * @param int[] $groups The groups, or none.
     * @return awareness
     */
    private function notice(string $title, array $groups): awareness {
        $data = (object) ['title' => $title, 'content' => '<p>' . $title . '</p>', 'filter_groups' => $groups];
        helper::create_new_notice($data, author_scope::course((int) $this->course->id));

        return awareness::get_record(['title' => $title]);
    }

    /**
     * The titles the current user is served on the course page.
     *
     * @return string[]
     */
    private function served(): array {
        $titles = array_map(static function (awareness $notice): string {
            return $notice->get('title');
        }, helper::retrieve_user_notices('/course/view.php', (int) $this->course->id));

        return array_values($titles);
    }

    /**
     * The titles the manage list would show the current user, and its total, for a scope.
     *
     * @param int|null $courseid The course list, or null for the site list.
     * @return array [titles, total]
     */
    private function listed(?int $courseid): array {
        $table = new all_notices('test', new \moodle_url('/local/awareness/managenotice.php'));
        $filterset = new all_notices_filterset();
        if ($courseid !== null) {
            $filterset->add_filter(new integer_filter('courseid', filter::JOINTYPE_ANY, [$courseid]));
        }
        $table->set_filterset($filterset);
        $table->query_db(all_notices::PER_PAGE, false);

        $titles = array_map(static function (awareness $notice): string {
            return $notice->get('title');
        }, $table->rawdata ?? []);

        return [array_values($titles), $table->get_total_rows()];
    }

    /**
     * Only the members of a named group receive its notice; a notice naming none reaches everyone.
     */
    public function test_only_members_of_a_named_group_receive_the_notice(): void {
        $this->setUser($this->red1);
        $this->assertEqualsCanonicalizing(['Red briefing', 'Everyone'], $this->served());

        $this->setUser($this->blue1);
        $this->assertEqualsCanonicalizing(['Blue briefing', 'Everyone'], $this->served());

        $this->setUser($this->loner);
        $this->assertSame(['Everyone'], $this->served());
    }

    /**
     * The write-path gate reads the same membership as delivery.
     */
    public function test_the_write_gate_reads_the_same_membership(): void {
        $this->setUser($this->red1);
        $this->assertTrue(helper::is_notice_available_to_user($this->forred));
        $this->assertFalse(helper::is_notice_available_to_user($this->forblue));
        $this->assertTrue(helper::is_notice_available_to_user($this->forall));
    }

    /**
     * A member of a group outsiders cannot see still receives its notice: delivery is membership.
     *
     * MEMBERS visibility is the strictest a notice can reach, and that is core's rule rather than
     * this plugin's: groups_create_group() and groups_update_group() force participation off for
     * OWN and NONE visibility (group/lib.php, identical on 4.5 and 5.2), and a group that cannot
     * participate is never offered for anything — the second half of this test is the control that
     * the scope refuses one, so nobody later "fixes" the picker into offering it.
     *
     * The non-member is the control that the group still means something.
     */
    public function test_a_member_of_a_group_outsiders_cannot_see_still_receives_its_notice(): void {
        $generator = $this->getDataGenerator();
        $quiet = $generator->create_group([
            'courseid' => $this->course->id,
            'name' => 'Quiet',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
        ]);
        $member = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $quiet->id, 'userid' => $member->id]);

        $this->setAdminUser();
        $notice = $this->notice('Quiet word', [(int) $quiet->id]);

        $this->setUser($member);
        $this->assertContains('Quiet word', $this->served());
        $this->assertTrue(helper::is_notice_available_to_user($notice));

        $this->setUser($this->loner);
        $this->assertNotContains('Quiet word', $this->served());

        // A group core will not let participate is not a target for anyone, the administrator included.
        $unreachable = $generator->create_group([
            'courseid' => $this->course->id,
            'name' => 'Sealed',
            'visibility' => GROUPS_VISIBILITY_NONE,
            'participation' => 1,
        ]);
        $this->assertSame(0, (int) $unreachable->participation, 'core forces participation off for this visibility');

        $this->setAdminUser();
        $refused = author_scope::course((int) $this->course->id)->apply(['filter_groups' => [(int) $unreachable->id]]);
        $this->assertSame([], $refused->criteria()['filter_groups']);
        $this->assertSame(['filter_groups' => author_scope::PROBLEM_OUTSIDE], $refused->problems());
    }

    /**
     * Membership is read the same way whoever is asking.
     *
     * groups_get_user_groups() runs its answer through the group visibility rules unless the caller
     * asks for hidden groups: a MEMBERS group counts as hidden (any visibility but ALL does), and
     * for someone ELSE's id it survives only while the asker is a member too. Delivery must not
     * turn on who resolved the user, so the flag is set — and this is what would notice if it were
     * unset: the same membership, asked by a stranger, comes back the same.
     */
    public function test_membership_reads_the_same_whoever_is_asking(): void {
        $generator = $this->getDataGenerator();
        $quiet = $generator->create_group([
            'courseid' => $this->course->id,
            'name' => 'Quiet',
            'visibility' => GROUPS_VISIBILITY_MEMBERS,
        ]);
        $member = $generator->create_and_enrol($this->course, 'student');
        $generator->create_group_member(['groupid' => $quiet->id, 'userid' => $member->id]);

        $this->setUser($member);
        $this->assertContains((int) $quiet->id, helper::user_group_ids((int) $this->course->id, (int) $member->id));

        // The same question, asked by someone who is in none of their groups and may not see them.
        $this->setUser($this->loner);
        $this->assertContains((int) $quiet->id, helper::user_group_ids((int) $this->course->id, (int) $member->id));
        $this->assertSame([], helper::user_group_ids((int) $this->course->id, (int) $this->loner->id));
    }

    /**
     * A teacher confined to their own group neither sees nor reaches a notice aimed at another.
     *
     * Four gates, one notice each way. The red notice and the untargeted one are the controls that
     * the gates refuse the blue notice for its groups and nothing else.
     */
    public function test_a_confined_author_neither_sees_nor_reaches_a_notice_aimed_at_another_group(): void {
        // A disabled red notice: its audience test fails, so only the author branch of the file gate can admit it.
        $this->setAdminUser();
        helper::disable_notice($this->forred);
        $this->forred->read();

        $this->setUser($this->teacher);
        $this->assertTrue(helper::may_reach_groups($this->forred));
        $this->assertFalse(helper::may_reach_groups($this->forblue));
        $this->assertTrue(helper::may_reach_groups($this->forall));

        // The pages: the same answer as a notice in someone else's course.
        $resolved = helper::resolve_notice_as_author($this->forred->get('id'), 'manage');
        $this->assertSame($this->forred->get('id'), $resolved->get('id'));
        try {
            helper::resolve_notice_as_author($this->forblue->get('id'), 'manage');
            $this->fail('a notice aimed at another group resolved for a confined author');
        } catch (\moodle_exception $e) {
            $this->assertSame('notification:noticedoesnotexist', $e->errorcode);
        }

        // The actions: refused naming the capability that would open it.
        helper::enable_notice($this->forred);
        $this->assertSame(1, (int) awareness::get_record(['id' => $this->forred->get('id')])->get('enabled'));
        try {
            helper::disable_notice($this->forblue);
            $this->fail('an action on a notice aimed at another group went through for a confined author');
        } catch (\required_capability_exception $e) {
            $this->assertSame(1, (int) awareness::get_record(['id' => $this->forblue->get('id')])->get('enabled'));
        }

        // The file gate's author branch, on the notices the audience branch cannot admit.
        helper::disable_notice($this->forred);
        $this->forred->read();
        $this->assertTrue(helper::may_serve_files_of($this->forred), 'its author may fetch a disabled notice\'s files');
        $this->assertFalse(helper::may_serve_files_of($this->forblue));

        // The list: excluded in the query, so the total agrees with the rows.
        [$titles, $total] = $this->listed((int) $this->course->id);
        $this->assertEqualsCanonicalizing(['Red briefing', 'Everyone'], $titles);
        $this->assertSame(2, $total);
    }

    /**
     * An author who may access all groups reaches every notice, and so does the site list.
     */
    public function test_an_author_who_may_access_all_groups_reaches_every_notice(): void {
        $this->setUser($this->editor);
        $this->assertTrue(helper::may_reach_groups($this->forblue));
        $resolved = helper::resolve_notice_as_author($this->forblue->get('id'), 'manage');
        $this->assertSame($this->forblue->get('id'), $resolved->get('id'));
        helper::disable_notice($this->forblue);
        $this->assertSame(0, (int) awareness::get_record(['id' => $this->forblue->get('id')])->get('enabled'));

        [$titles, $total] = $this->listed((int) $this->course->id);
        $this->assertEqualsCanonicalizing(['Red briefing', 'Blue briefing', 'Everyone'], $titles);
        $this->assertSame(3, $total);

        $this->setAdminUser();
        [$titles, $total] = $this->listed(null);
        $this->assertEqualsCanonicalizing(['Red briefing', 'Blue briefing', 'Everyone'], $titles);
        $this->assertSame(3, $total);
    }

    /**
     * A site notice naming groups reaches nobody, and stays in the administrator's list to be fixed.
     */
    public function test_a_site_notice_naming_groups_reaches_nobody(): void {
        $orphan = new awareness(0, (object) [
            'title' => 'Orphan',
            'content' => '<p>Orphan</p>',
            'filtervalues' => json_encode(['filter_groups' => [(int) $this->red->id]]),
        ]);
        $orphan->create();

        $this->assertFalse(helper::user_in_notice_groups($orphan, (int) $this->red1->id));

        $this->setAdminUser();
        [$titles] = $this->listed(null);
        $this->assertContains('Orphan', $titles);
    }

    /**
     * The estimate's chip names the groups, in name order.
     */
    public function test_the_estimate_chip_names_the_groups(): void {
        $this->assertSame(
            'Blue team, Red team',
            rule_describer::describe('filter_groups', [(int) $this->red->id, (int) $this->blue->id])
        );
        $this->assertSame('', rule_describer::describe('filter_groups', []));
    }
}
