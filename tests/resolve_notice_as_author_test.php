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

/**
 * A page names a notice; the resolver answers only for the notices the caller may act on.
 *
 * Two refusals, one answer: a notice that does not exist and a notice outside the caller's
 * authority are the same "no such notice", so a course author cannot learn by trying whether an id
 * names a notice in someone else's course. The author's own notice coming back, in the same test, is
 * the control that keeps the single answer from being "refuse everything".
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::resolve_notice_as_author
 */
final class resolve_notice_as_author_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A missing notice and a notice outside the caller's authority are the same refusal; their own comes back.
     */
    public function test_a_foreign_notice_is_refused_exactly_as_a_missing_one(): void {
        global $DB;

        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $own = $generator->create_notice(['courseid' => $mine->id]);
        $theirs = $generator->create_notice(['courseid' => $other->id]);
        $site = $generator->create_notice();
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_awareness}') + 1000;

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, \context_course::instance($mine->id)->id, true);
        role_assign($roleid, $user->id, \context_course::instance($mine->id)->id);
        $this->setUser($user);

        $this->assertNull(helper::resolve_notice_as_author(0, 'manage'), 'no id is a new notice');
        $resolved = helper::resolve_notice_as_author((int) $own->get('id'), 'manage');
        $this->assertSame((int) $own->get('id'), (int) $resolved->get('id'));

        $answers = [];
        foreach (['theirs' => (int) $theirs->get('id'), 'site' => (int) $site->get('id'), 'missing' => $missing] as $key => $id) {
            try {
                helper::resolve_notice_as_author($id, 'manage');
                $answers[$key] = 'resolved';
            } catch (\moodle_exception $e) {
                $answers[$key] = $e->errorcode;
            }
        }
        $this->assertSame(
            [
                'theirs' => 'notification:noticedoesnotexist',
                'site' => 'notification:noticedoesnotexist',
                'missing' => 'notification:noticedoesnotexist',
            ],
            $answers,
            'one answer for all three, so an id cannot be probed across scopes'
        );

        // The reports verb reads its own capability: the manage holder is refused the reports of their own notice.
        $this->expectException(\moodle_exception::class);
        helper::resolve_notice_as_author((int) $own->get('id'), 'viewreports');
    }
}
