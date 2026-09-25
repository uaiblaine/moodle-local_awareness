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

namespace local_awareness\table;

use local_awareness\local\author_scope;
use local_awareness\local\group_scope;
use local_awareness\persistent\awareness;

/**
 * The manage list's group-reach exclusion: where it applies, and what it costs.
 *
 * The exclusion itself, a confined teacher against a course list, is pinned beside the other three
 * reach gates in tests/group_audience_test.php. These tests pin the list's own side: that the
 * exclusion does not depend on the filterset, that it reads no course a viewer cannot be confined
 * in, and that a notice whose course is gone does not break the list.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\table\all_notices
 */
final class all_notices_group_reach_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A user holding the site manage capability through a system role that grants nothing else.
     *
     * Without moodle/site:accessallgroups, so a separate-groups course confines them to their own
     * groups.
     *
     * @return \stdClass
     */
    private function site_author(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $system = \context_system::instance();
        assign_capability('local/awareness:manage', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);

        return $user;
    }

    /**
     * A course notice aimed at the given groups.
     *
     * @param string $title The title.
     * @param int $courseid The course.
     * @param int[] $groupids The groups, or none for everyone in the course.
     * @return awareness
     */
    private function course_notice(string $title, int $courseid, array $groupids): awareness {
        $record = ['title' => $title, 'courseid' => $courseid];
        if ($groupids !== []) {
            $record['filtervalues'] = json_encode([group_scope::FIELD => $groupids]);
        }

        return $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice($record);
    }

    /**
     * The titles the site list shows the current user.
     *
     * @param bool $withfilterset Whether the table is given a filterset, as the page and the AJAX
     *                            refresh give it, or none at all.
     * @return string[] Sorted.
     */
    private function site_titles(bool $withfilterset): array {
        $table = new all_notices('reach', new \moodle_url('/local/awareness/managenotice.php'));
        if ($withfilterset) {
            $table->set_filterset(new all_notices_filterset());
        }
        $table->query_db(all_notices::PER_PAGE, false);

        $titles = array_map(static fn(awareness $notice): string => $notice->get('title'), $table->rawdata ?? []);
        sort($titles);

        return $titles;
    }

    /**
     * Run the exclusion for the site list, counting the database reads it makes.
     *
     * @return array [the sql fragment, how many ids it excludes, reads]
     */
    private function exclusion_for_site(): array {
        global $DB;

        $table = new all_notices('reach', new \moodle_url('/local/awareness/managenotice.php'));
        $table->set_filterset(new all_notices_filterset());
        $method = new \ReflectionMethod(all_notices::class, 'unreachable_notices_sql');
        $method->setAccessible(true);

        // Once to load what every request loads anyway (the viewer's access data), then measured.
        $method->invoke($table, author_scope::site());
        $before = $DB->perf_get_reads();
        [$sql, $params] = $method->invoke($table, author_scope::site());

        return [$sql, count($params), $DB->perf_get_reads() - $before];
    }

    /**
     * A table given no filterset still keeps a viewer away from a notice aimed at another group.
     *
     * The administrator's list is the precondition that the blue notice exists to be excluded, and
     * the same viewer's list with a filterset is the control that the exclusion applies to them.
     */
    public function test_group_reach_applies_without_a_filterset(): void {
        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $red = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Red']);
        $blue = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Blue']);
        $author = $this->site_author();
        $this->getDataGenerator()->enrol_user($author->id, $course->id, 'student');
        $this->getDataGenerator()->create_group_member(['groupid' => $red->id, 'userid' => $author->id]);

        $this->setAdminUser();
        $this->course_notice('for red', (int) $course->id, [(int) $red->id]);
        $this->course_notice('for blue', (int) $course->id, [(int) $blue->id]);
        $this->course_notice('for all', (int) $course->id, []);
        $this->assertSame(['for all', 'for blue', 'for red'], $this->site_titles(false), 'precondition: all three exist');

        $this->setUser($author);
        $this->assertSame(['for all', 'for red'], $this->site_titles(true), 'the control: this viewer is confined');
        $this->assertSame(['for all', 'for red'], $this->site_titles(false), 'and stays confined without a filterset');
    }

    /**
     * A viewer who may access all groups is decided without reading any course.
     *
     * Five separate-groups courses each hold a notice aimed at a group. For the administrator the
     * exclusion costs its one query, whatever the number of courses. The confined viewer is the
     * control that the query does find the five candidates, and that they would be excluded.
     */
    public function test_a_viewer_who_may_access_all_groups_costs_one_query(): void {
        $this->setAdminUser();
        for ($i = 0; $i < 5; $i++) {
            $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS]);
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            $this->course_notice('notice ' . $i, (int) $course->id, [(int) $group->id]);
        }

        [$sql, , $reads] = $this->exclusion_for_site();
        $this->assertSame('', $sql, 'the administrator is kept from nothing');
        $this->assertLessThanOrEqual(2, $reads, 'and no course is read to decide it');

        $this->setUser($this->site_author());
        [$sql, $excluded] = $this->exclusion_for_site();
        $this->assertNotSame('', $sql);
        $this->assertSame(5, $excluded, 'the control: a confined viewer is kept from all five');
    }

    /**
     * A course that confines nobody is not read, even for a viewer who could be confined.
     *
     * Groups mode off in five courses, each with a notice aimed at a group the viewer is not in.
     * Switching one course to separate groups is the control: the same viewer is then kept from
     * that course's notice, so the others were spared by the mode and not by the viewer.
     */
    public function test_a_course_that_separates_nobody_is_not_read(): void {
        global $DB;

        $this->setAdminUser();
        $courses = [];
        for ($i = 0; $i < 5; $i++) {
            $course = $this->getDataGenerator()->create_course(['groupmode' => NOGROUPS]);
            $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
            $this->course_notice('notice ' . $i, (int) $course->id, [(int) $group->id]);
            $courses[] = $course;
        }

        $this->setUser($this->site_author());
        [$sql, , $reads] = $this->exclusion_for_site();
        $this->assertSame('', $sql, 'groups mode off confines nobody');
        $this->assertLessThanOrEqual(2, $reads, 'and none of the five courses is read to decide it');

        $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $courses[0]->id]);
        [, $excluded] = $this->exclusion_for_site();
        $this->assertSame(1, $excluded, 'the control: a separate-groups course does confine this viewer');
    }

    /**
     * A notice aimed at a group, whose course is gone, stays in the list and does not break it.
     *
     * A missing course confines nobody, so the notice is listed for an administrator to delete, as
     * helper::require_author() lets the site capability do. The live separate-groups notice beside
     * it is listed too.
     */
    public function test_a_notice_whose_course_is_gone_does_not_break_the_list(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->course_notice('live', (int) $course->id, [(int) $group->id]);

        $goneid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {course}') + 100;
        $orphan = $this->course_notice('orphan', $goneid, [(int) $group->id]);
        $this->assertFalse($DB->record_exists('course', ['id' => $goneid]), 'precondition: the course is gone');
        $this->assertNotSame([], group_scope::targeted($orphan), 'precondition: the notice is aimed at a group');

        $this->assertSame(['live', 'orphan'], $this->site_titles(true));

        $this->setUser($this->site_author());
        $this->assertSame(['orphan'], $this->site_titles(true), 'nor is a confined viewer kept from it');
    }
}
