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

use local_awareness\persistent\awareness;

/**
 * Deleting a course purges its notices, and nothing else, whoever is deleting and whatever the settings.
 *
 * The purge runs from the before_course_deleted hook, where there is no author to gate: the person
 * deleting the course holds no notice capability here, and allow_delete is left at its default of
 * off, because that setting says whether a human may press Delete, not whether a course may stop
 * existing. A purge that reused the gated delete verb passes neither condition. Every deletion is
 * asserted beside a survivor of the same kind — another course's notice, a site notice, their
 * files, their dependent rows — so a purge that deleted nothing or everything reddens.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\hook_callbacks::before_course_deleted
 * @covers \local_awareness\helper::purge_course_notices
 * @covers \local_awareness\helper::purge_notice
 */
final class course_purge_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Notices in two courses and on the site, each with an attachment and dependent rows.
     *
     * @param \stdClass $doomed The course that will be deleted.
     * @param \stdClass $control The course that stays.
     * @return awareness[] Keyed c1, c2 (doomed), d1 (control course), s1 (site).
     */
    private function seed(\stdClass $doomed, \stdClass $control): array {
        global $DB;

        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $notices = [
            'c1' => $generator->create_notice(['title' => 'c1', 'courseid' => $doomed->id]),
            'c2' => $generator->create_notice(['title' => 'c2', 'courseid' => $doomed->id]),
            'd1' => $generator->create_notice(['title' => 'd1', 'courseid' => $control->id]),
            's1' => $generator->create_notice(['title' => 's1']),
        ];
        $fs = get_file_storage();
        foreach ($notices as $key => $notice) {
            $id = (int) $notice->get('id');
            foreach (['content', 'bgimage'] as $area) {
                $fs->create_file_from_string([
                    'contextid' => \context_system::instance()->id,
                    'component' => 'local_awareness',
                    'filearea' => $area,
                    'itemid' => $id,
                    'filepath' => '/',
                    'filename' => "{$key}.txt",
                ], $key);
            }
            $DB->insert_record('local_awareness_ack', (object) [
                'noticeid' => $id, 'userid' => $user->id, 'action' => 1, 'timecreated' => time(),
            ]);
            $DB->insert_record('local_awareness_lastview', (object) [
                'noticeid' => $id,
                'userid' => $user->id,
                'action' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return $notices;
    }

    /**
     * How many stored files the notice still has, across both areas.
     *
     * @param awareness $notice The notice.
     * @return int
     */
    private function files_of(awareness $notice): int {
        $fs = get_file_storage();
        $count = 0;
        foreach (['content', 'bgimage'] as $area) {
            $count += count($fs->get_area_files(
                \context_system::instance()->id,
                'local_awareness',
                $area,
                (int) $notice->get('id'),
                'id',
                false
            ));
        }

        return $count;
    }

    /**
     * Deleting a course purges its notices, their files and their rows, and leaves every neighbour alone.
     */
    public function test_deleting_a_course_purges_its_notices_and_nothing_else(): void {
        global $DB;

        set_config('cleanup_deleted_notice', 1, 'local_awareness');
        $doomed = $this->getDataGenerator()->create_course();
        $control = $this->getDataGenerator()->create_course();
        $notices = $this->seed($doomed, $control);

        // Precondition: the purge must not lean on either of these.
        $this->assertEmpty(get_config('local_awareness', 'allow_delete'), 'allow_delete is at its default, off');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(has_capability('local/awareness:manage', \context_system::instance()));

        $sink = $this->redirectEvents();
        delete_course($doomed, false);
        $this->assertDebuggingNotCalled();

        foreach (['c1', 'c2'] as $key) {
            $id = (int) $notices[$key]->get('id');
            $this->assertFalse($DB->record_exists('local_awareness', ['id' => $id]), "{$key} is gone");
            $this->assertSame(0, $this->files_of($notices[$key]), "{$key}'s files are gone");
            $this->assertFalse(
                $DB->record_exists('local_awareness_ack', ['noticeid' => $id]),
                "{$key}'s acknowledgements are gone"
            );
            $this->assertFalse($DB->record_exists('local_awareness_lastview', ['noticeid' => $id]), "{$key}'s views are gone");
        }
        foreach (['d1', 's1'] as $key) {
            $id = (int) $notices[$key]->get('id');
            $this->assertTrue($DB->record_exists('local_awareness', ['id' => $id]), "{$key} survives");
            $this->assertSame(2, $this->files_of($notices[$key]), "{$key}'s files survive");
            $this->assertTrue($DB->record_exists('local_awareness_ack', ['noticeid' => $id]), "{$key}'s acknowledgements survive");
            $this->assertTrue($DB->record_exists('local_awareness_lastview', ['noticeid' => $id]), "{$key}'s views survive");
        }

        $deleted = array_values(array_filter($sink->get_events(), static function (\core\event\base $event): bool {
            return $event instanceof \local_awareness\event\awareness_deleted;
        }));
        $this->assertCount(2, $deleted, 'one deletion event per purged notice');
        $this->assertEqualsCanonicalizing(
            [(int) $notices['c1']->get('id'), (int) $notices['c2']->get('id')],
            array_map(static fn(\core\event\base $event): int => (int) $event->objectid, $deleted)
        );
    }

    /**
     * The purge honours cleanup_deleted_notice — the rows and files still go, the interaction history stays.
     */
    public function test_the_purge_honours_the_cleanup_setting_and_never_the_delete_setting(): void {
        global $DB;

        set_config('cleanup_deleted_notice', 0, 'local_awareness');
        $doomed = $this->getDataGenerator()->create_course();
        $control = $this->getDataGenerator()->create_course();
        $notices = $this->seed($doomed, $control);
        $this->assertEmpty(get_config('local_awareness', 'allow_delete'));
        $this->setUser($this->getDataGenerator()->create_user());

        delete_course($doomed, false);
        $this->assertDebuggingNotCalled();

        $id = (int) $notices['c1']->get('id');
        $this->assertFalse($DB->record_exists('local_awareness', ['id' => $id]), 'the row goes whatever the setting');
        $this->assertSame(0, $this->files_of($notices['c1']), 'the files go with the row, not with the cleanup');
        $this->assertTrue($DB->record_exists('local_awareness_ack', ['noticeid' => $id]), 'the history stays when cleanup is off');
        $this->assertTrue($DB->record_exists('local_awareness', ['id' => $notices['d1']->get('id')]));
    }

    /**
     * The purge, called directly, counts what it removed and touches no other course.
     */
    public function test_the_purge_counts_what_it_removed(): void {
        global $DB;

        $doomed = $this->getDataGenerator()->create_course();
        $control = $this->getDataGenerator()->create_course();
        $notices = $this->seed($doomed, $control);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame(2, helper::purge_course_notices((int) $doomed->id));
        $this->assertSame(0, helper::purge_course_notices((int) $doomed->id), 'nothing left to purge');
        $this->assertSame(0, helper::purge_course_notices(0), 'the site is not a course to purge');
        $this->assertSame(2, $DB->count_records('local_awareness'), 'd1 and s1 remain');
        $this->assertTrue($DB->record_exists('local_awareness', ['id' => $notices['s1']->get('id')]));
    }
}
