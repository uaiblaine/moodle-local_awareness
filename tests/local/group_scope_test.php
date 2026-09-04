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

namespace local_awareness\local;

use local_awareness\persistent\awareness;

/**
 * Which groups an author may target, decided as core decides it.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\local\group_scope
 */
final class group_scope_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass A participation group the teacher is in. */
    private \stdClass $red;

    /** @var \stdClass A participation group the teacher is not in. */
    private \stdClass $blue;

    /** @var \stdClass A non-participation group the teacher is in. */
    private \stdClass $staff;

    /** @var \stdClass A non-editing teacher: no moodle/site:accessallgroups. */
    private \stdClass $teacher;

    /** @var \stdClass An editing teacher: holds moodle/site:accessallgroups. */
    private \stdClass $editor;

    /**
     * A course in separate groups mode, three groups, two teachers.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $this->red = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Red team']);
        $this->blue = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Blue team']);
        $this->staff = $generator->create_group(['courseid' => $this->course->id, 'name' => 'Staff', 'participation' => 0]);

        $this->teacher = $generator->create_and_enrol($this->course, 'teacher');
        $this->editor = $generator->create_and_enrol($this->course, 'editingteacher');
        $generator->create_group_member(['groupid' => $this->red->id, 'userid' => $this->teacher->id]);
        $generator->create_group_member(['groupid' => $this->staff->id, 'userid' => $this->teacher->id]);
    }

    /**
     * Switch the course's group mode and read it back through the scope.
     *
     * @param int $groupmode NOGROUPS, SEPARATEGROUPS or VISIBLEGROUPS.
     * @return void
     */
    private function set_groupmode(int $groupmode): void {
        global $DB;

        $DB->set_field('course', 'groupmode', $groupmode, ['id' => $this->course->id]);
        $this->course = get_course($this->course->id);
    }

    /**
     * The site scope has no course, and so no groups at all.
     */
    public function test_the_site_scope_has_no_groups(): void {
        $this->setAdminUser();
        $scope = group_scope::for_author(author_scope::site());

        $this->assertFalse($scope->applies());
        $this->assertFalse($scope->offered());
        $this->assertFalse($scope->is_restricted());
        $this->assertSame(NOGROUPS, $scope->groupmode());
        $this->assertSame([], $scope->allowed_ids());
        $this->assertSame([], $scope->options());
        /*
         * It confines nobody: separation is a course's mode, and the site has no course. What stops
         * a site notice naming groups is author_scope's rule table, on the way in; this class is
         * asked who may REACH one that somehow exists, and hiding it from everyone would leave the
         * row nobody could fix. narrow() is the other question, and it still offers nothing.
         */
        $this->assertTrue($scope->admits([]), 'no group named is everyone\'s to reach');
        $this->assertTrue($scope->admits([(int) $this->red->id]));
        $this->assertSame([], $scope->narrow([(int) $this->red->id]));
    }

    /**
     * Separate groups confine a teacher without accessallgroups to their own participation groups.
     *
     * The staff group is the control for the participation flag: the teacher is in it, and it is
     * still not offered, exactly as core's activity pickers leave it out.
     */
    public function test_separate_groups_confine_an_author_to_their_own_participation_groups(): void {
        $this->setUser($this->teacher);
        $scope = group_scope::for_author(author_scope::course((int) $this->course->id));

        $this->assertTrue($scope->applies());
        $this->assertTrue($scope->is_restricted());
        $this->assertTrue($scope->offered());
        $this->assertSame([(int) $this->red->id], $scope->allowed_ids());
        $this->assertSame([(int) $this->red->id => 'Red team'], $scope->options());

        $this->assertTrue($scope->admits([(int) $this->red->id]));
        $this->assertFalse($scope->admits([(int) $this->red->id, (int) $this->blue->id]));
        $this->assertFalse($scope->admits([(int) $this->staff->id]));
        $this->assertSame([(int) $this->red->id], $scope->narrow([(int) $this->blue->id, (int) $this->red->id]));

        /*
         * A group that is gone confines nobody, so the notices naming it stay reachable and can be
         * fixed. The pair is what carries the meaning: the same call refuses the live group beside
         * it, so this is a deleted group being skipped rather than the rule going quiet.
         */
        $gone = $this->getDataGenerator()->create_group(['courseid' => $this->course->id, 'name' => 'Gone']);
        $goneid = (int) $gone->id;
        groups_delete_group($gone);
        $this->assertTrue($scope->admits([$goneid]));
        $this->assertFalse($scope->admits([$goneid, (int) $this->blue->id]));
    }

    /**
     * Visible groups, or the capability to access all groups, open every participation group.
     */
    public function test_visible_groups_and_access_all_groups_open_every_participation_group(): void {
        $every = [(int) $this->blue->id, (int) $this->red->id];

        // The editing teacher under separate groups: the capability is what opens it.
        $this->setUser($this->editor);
        $scope = group_scope::for_author(author_scope::course((int) $this->course->id));
        $this->assertFalse($scope->is_restricted());
        $this->assertEqualsCanonicalizing($every, $scope->allowed_ids());
        $this->assertTrue($scope->admits($every));
        /*
         * The staff group cannot be picked — it does not participate — but a notice that somehow
         * names it is still theirs to open and fix. Whoever confines nobody is kept from nothing:
         * the restricted teacher above is the control, refused the same group.
         */
        $this->assertNotContains((int) $this->staff->id, $scope->allowed_ids());
        $this->assertTrue($scope->admits([(int) $this->staff->id]));

        // The same non-editing teacher under visible groups: the mode is what opens it.
        $this->set_groupmode(VISIBLEGROUPS);
        $this->setUser($this->teacher);
        $scope = group_scope::for_author(author_scope::course((int) $this->course->id));
        $this->assertFalse($scope->is_restricted());
        $this->assertTrue($scope->offered());
        $this->assertEqualsCanonicalizing($every, $scope->allowed_ids());
        $this->assertArrayNotHasKey((int) $this->staff->id, $scope->options(), 'participation still decides');
    }

    /**
     * The group mode switched off still offers every group, and confines nobody.
     *
     * The mode governs how ACTIVITIES separate participants; it does not decide whether a course
     * has groups or whether a notice may address one. This is the shape of a real site: on the dev
     * server the three courses with the most groups — 300, 30 and 9 — all sit at NOGROUPS, and an
     * earlier version of offered() read the mode and hid the picker on every one of them. The
     * teacher here is the one the mode WOULD confine if it were separate, which is what makes the
     * first assertion mean something.
     */
    public function test_the_group_mode_switched_off_still_offers_every_group(): void {
        $this->set_groupmode(NOGROUPS);
        $this->setUser($this->teacher);
        $scope = group_scope::for_author(author_scope::course((int) $this->course->id));

        $this->assertTrue($scope->offered(), 'a course with groups offers them whatever its mode');
        $this->assertFalse($scope->is_restricted());
        $this->assertEqualsCanonicalizing([(int) $this->blue->id, (int) $this->red->id], $scope->allowed_ids());
        $this->assertTrue($scope->admits([(int) $this->blue->id]));
    }

    /**
     * A course with no groups at all offers no picker, whatever its mode.
     *
     * The other half of the rule, and the control for the test above: what decides the picker is
     * whether there is anything to pick.
     */
    public function test_a_course_with_no_groups_offers_no_picker(): void {
        $empty = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS]);
        $this->setAdminUser();

        $scope = group_scope::for_author(author_scope::course((int) $empty->id));
        $this->assertTrue($scope->applies());
        $this->assertSame([], $scope->allowed_ids());
        $this->assertFalse($scope->offered());
    }

    /**
     * The stored groups are read from the one JSON key, as positive distinct ids, and junk reads as none.
     */
    public function test_the_stored_groups_are_read_from_the_notice(): void {
        $notice = new awareness(0, (object) [
            'title' => 'Briefing',
            'content' => '<p>Body</p>',
            'courseid' => (int) $this->course->id,
            'filtervalues' => json_encode(['filter_groups' => ['7', 7, 0, -1, 'x', 3]]),
        ]);

        $this->assertSame([7, 3], group_scope::targeted($notice));
        $this->assertSame([], group_scope::decode(null));
        $this->assertSame([], group_scope::decode('[1'));
        $this->assertSame([], group_scope::decode(json_encode(['filter_groups' => 'not a list'])));
        $this->assertSame([], group_scope::decode(json_encode(['filter_course' => [3]])));
    }
}
