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

use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;

/**
 * A notice belongs to the scope it was written under, and every verb answers to that scope.
 *
 * Nothing in production constructs a course scope yet — these tests build one by hand, the way
 * the scope's own tests do — so what is pinned here is the policy the course editor will be wired
 * to: ownership is set by the scope at creation and never by the submission, it does not change on
 * update, and update, enable, disable, reset and delete are decided in the notice's own scope, so
 * a course author reaches their course's notices and nobody else's while a site manager, whose
 * capability inherits down, reaches them all — within the course's rules.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::create_new_notice
 * @covers \local_awareness\helper::update_notice
 * @covers \local_awareness\helper::delete_notice
 * @covers \local_awareness\helper::enable_notice
 * @covers \local_awareness\helper::disable_notice
 * @covers \local_awareness\helper::reset_notice
 */
final class course_ownership_test extends \advanced_testcase {
    /** @var \stdClass The course the author writes for. */
    private \stdClass $mine;

    /** @var \stdClass Another course, the control. */
    private \stdClass $other;

    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('allow_update', 1, 'local_awareness');
        set_config('allow_delete', 1, 'local_awareness');

        $this->mine = $this->getDataGenerator()->create_course();
        $this->other = $this->getDataGenerator()->create_course();
    }

    /**
     * A fresh user holding one capability in one context, logged in.
     *
     * @param string $capability The capability.
     * @param \context $context Where it is granted.
     * @return \stdClass The user.
     */
    private function user_with(string $capability, \context $context): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        $this->setUser($user);

        return $user;
    }

    /**
     * A minimal form submission.
     *
     * @param string $title The title.
     * @return \stdClass
     */
    private function submission(string $title = 'Course notice'): \stdClass {
        return (object) [
            'title' => $title,
            'content' => '<p>Body</p>',
            'perpetual' => 1,
        ];
    }

    /**
     * The one notice with the given title, re-read from the table.
     *
     * @param string $title The title.
     * @return awareness
     */
    private function stored(string $title): awareness {
        $notices = awareness::get_records(['title' => $title]);
        $this->assertCount(1, $notices, "expected exactly one notice titled '{$title}'");

        return reset($notices);
    }

    /**
     * Creating as a course author pins the course on the row and forces its filter — and only there.
     *
     * The submission names the OTHER course as its courseid, which must count for nothing: the scope
     * decides the owner. The same author is refused the other course and the site, which is what
     * makes the pass meaningful.
     */
    public function test_creating_as_a_course_author_pins_the_course_and_forces_its_filter(): void {
        $this->user_with('local/awareness:managecourse', \context_course::instance($this->mine->id));

        $data = $this->submission('Mine');
        $data->courseid = $this->other->id;
        helper::create_new_notice($data, author_scope::course((int) $this->mine->id));

        $notice = $this->stored('Mine');
        $this->assertSame((int) $this->mine->id, (int) $notice->get('courseid'), 'the scope owns the notice, not the submission');
        $filters = json_decode((string) $notice->get('filtervalues'), true);
        $this->assertSame([(int) $this->mine->id], array_map('intval', $filters['filter_course']), 'the course is forced');
        $this->assertSame(CONTEXT_COURSE, (int) $filters['filter_role_context']);

        $refused = 0;
        foreach ([author_scope::course((int) $this->other->id), author_scope::site(), null] as $scope) {
            try {
                helper::create_new_notice($this->submission('Elsewhere'), $scope);
            } catch (\required_capability_exception $e) {
                $refused++;
            }
        }
        $this->assertSame(3, $refused, 'the other course, the site, and the default scope all refuse a course author');
        $this->assertEmpty(awareness::get_records(['title' => 'Elsewhere']));
    }

    /**
     * Updating keeps the owner whatever was posted, while the rest of the update lands.
     */
    public function test_updating_keeps_the_owner_whatever_was_posted(): void {
        $this->user_with('local/awareness:manage', \context_system::instance());
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')
            ->create_notice(['title' => 'Before', 'courseid' => $this->mine->id]);

        $data = $this->submission('After');
        $data->id = $notice->get('id');
        $data->courseid = $this->other->id;
        helper::update_notice($notice, $data);

        $stored = $this->stored('After');
        $this->assertSame((int) $notice->get('id'), (int) $stored->get('id'), 'the update landed on the same row');
        $this->assertSame((int) $this->mine->id, (int) $stored->get('courseid'), 'ownership does not move on update');
    }

    /**
     * Every verb answers to the notice's own scope.
     *
     * A course author acts on their course's notice and is refused the other course's and the site's,
     * verb by verb, with the refused notices asserted untouched.
     */
    public function test_every_verb_answers_to_the_notice_s_own_scope(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $this->user_with('local/awareness:managecourse', \context_course::instance($this->mine->id));

        $verbs = [
            'update' => function (awareness $notice): void {
                $data = $this->submission('Renamed ' . $notice->get('id'));
                $data->id = $notice->get('id');
                helper::update_notice($notice, $data);
            },
            'disable' => static function (awareness $notice): void {
                helper::disable_notice($notice);
            },
            'enable' => static function (awareness $notice): void {
                helper::enable_notice($notice);
            },
            'reset' => static function (awareness $notice): void {
                helper::reset_notice($notice);
            },
            'delete' => static function (awareness $notice): void {
                helper::delete_notice($notice);
            },
        ];

        $mineids = [];
        foreach ($verbs as $verb => $act) {
            $mine = $generator->create_notice(['title' => "mine {$verb}", 'courseid' => $this->mine->id]);
            $mineids[$verb] = (int) $mine->get('id');
            $theirs = $generator->create_notice(['title' => "theirs {$verb}", 'courseid' => $this->other->id]);
            $site = $generator->create_notice(['title' => "site {$verb}"]);

            $act($mine);

            foreach ([$theirs, $site] as $foreign) {
                try {
                    $act($foreign);
                    $this->fail("{$verb} on '{$foreign->get('title')}' was not refused");
                } catch (\required_capability_exception $e) {
                    $this->assertEquals(
                        $foreign->to_record(),
                        awareness::get_record(['id' => $foreign->get('id')])->to_record(),
                        "{$verb} touched '{$foreign->get('title')}' despite refusing"
                    );
                }
            }
        }

        // The verbs on the author's own notices really ran: the rename landed, the disable stuck,
        // the delete is gone. Without this, a seam refusing everyone passes the refusals above.
        $this->assertSame('Renamed ' . $mineids['update'], awareness::get_record(['id' => $mineids['update']])->get('title'));
        $this->assertSame(0, (int) awareness::get_record(['id' => $mineids['disable']])->get('enabled'));
        $this->assertFalse(awareness::get_record(['id' => $mineids['delete']]), 'the delete on the author\'s own notice ran');
    }

    /**
     * A site manager acts on a course notice, inheriting down, and inside the course's rules.
     *
     * The notice's scope governs, not the actor's: a category filter is forbidden under a course
     * scope, so it is refused even for the site manager, while a plain edit lands.
     */
    public function test_a_site_manager_acts_on_a_course_notice_within_its_scope(): void {
        $this->user_with('local/awareness:manage', \context_system::instance());
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')
            ->create_notice(['title' => 'Course notice', 'courseid' => $this->mine->id]);

        helper::disable_notice($notice);
        $this->assertSame(0, (int) awareness::get_record(['id' => $notice->get('id')])->get('enabled'));

        $data = $this->submission('Course notice');
        $data->id = $notice->get('id');
        $data->filter_category = [(int) $this->mine->category];
        $this->expectException(\invalid_parameter_exception::class);
        helper::update_notice($notice, $data);
    }
}
