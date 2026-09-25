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

use core_external\external_api;
use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * A notice aimed at groups, whose course is gone, confines nobody and stays in reach of the site.
 *
 * The manage list shows such an orphan to an administrator, who has to be able to preview, report
 * on and remove it. The course and its context are deleted directly, bypassing the
 * before_course_deleted purge, which is how an orphan arises at all.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\local\group_scope
 * @covers     \local_awareness\external\render_notice
 */
final class group_scope_orphan_test extends \advanced_testcase {
    /**
     * The group reach of an orphan admits, and the administrator still resolves and previews it.
     *
     * The control is the confined teacher refused while the course exists: the course's separate
     * groups were really in force, so what changes afterwards is the course being gone.
     *
     * @return void
     */
    public function test_a_notice_whose_course_is_gone_confines_nobody(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $red = $generator->create_group(['courseid' => $course->id, 'name' => 'Red team']);
        $blue = $generator->create_group(['courseid' => $course->id, 'name' => 'Blue team']);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $generator->create_group_member(['groupid' => $red->id, 'userid' => $teacher->id]);

        $this->setAdminUser();
        $data = (object) ['title' => 'Blue briefing', 'content' => '<p>Blue</p>', 'filter_groups' => [(int) $blue->id]];
        helper::create_new_notice($data, author_scope::course((int) $course->id));
        $notice = awareness::get_record(['title' => 'Blue briefing']);
        $noticeid = (int) $notice->get('id');
        $this->assertSame([(int) $blue->id], group_scope::targeted($notice), 'precondition: the notice is aimed at a group');

        $this->setUser($teacher);
        $this->assertFalse(helper::may_reach_groups($notice), 'the control: the course confines this teacher');

        $DB->delete_records('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => $course->id]);
        $DB->delete_records('course', ['id' => $course->id]);
        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(author_scope::of($notice)->exists(), 'precondition: the course is gone');

        $this->setAdminUser();
        $this->assertSame(NOGROUPS, group_scope::for_author(author_scope::of($notice))->groupmode());
        $this->assertTrue(helper::may_reach_groups($notice), 'a course that is gone confines nobody');
        $this->assertSame(
            $noticeid,
            (int) helper::resolve_notice_as_author($noticeid, 'manage')->get('id'),
            'the administrator still resolves the orphan, to disable or delete it'
        );

        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_awareness_render_notice', ['noticeid' => $noticeid], false);
        $this->assertFalse(
            $response['error'],
            'the preview of an orphan must not fail: ' . json_encode($response['exception'] ?? null)
        );
        $this->assertSame($noticeid, (int) $response['data']['id']);
    }
}
