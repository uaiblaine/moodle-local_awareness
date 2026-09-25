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

namespace local_awareness\task;

/**
 * The retention task for link-click history, and the meaning of a click it must not change.
 *
 * The table gains a row per click, and the task discards rows past the configured lifetime.
 *
 * A reader can inflate their own count by calling the web service repeatedly, and that is
 * accepted: repeat clicks are the reported quantity, and a throttle would collapse a genuine
 * second click into the first. The last test pins that.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\task\purge_link_history
 */
final class purge_link_history_test extends \advanced_testcase {
    /**
     * Store one click, stamped a given age.
     *
     * @param int $hlinkid The link followed.
     * @param int $userid The reader.
     * @param int $ageseconds How long ago the click happened.
     * @return int The stored row id.
     */
    private function seed_click(int $hlinkid, int $userid, int $ageseconds): int {
        global $DB;

        return (int) $DB->insert_record('local_awareness_hlinks_his', (object) [
            'hlinkid' => $hlinkid,
            'userid' => $userid,
            'timecreated' => time() - $ageseconds,
        ]);
    }

    /**
     * A configured lifetime discards what is past it and keeps what is not.
     *
     * The survivor is the control: without it a task that emptied the table outright would pass.
     *
     * @return void
     */
    public function test_the_task_discards_only_what_is_past_the_lifetime(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('linkhistory_lifetime', 30, 'local_awareness');

        $old = $this->seed_click(1, 2, 40 * DAYSECS);
        $fresh = $this->seed_click(1, 2, 5 * DAYSECS);

        // Precondition: both rows really are stored, so "one is gone" means the task removed it.
        $this->assertSame(2, $DB->count_records('local_awareness_hlinks_his'));

        (new purge_link_history())->execute();

        $this->assertFalse($DB->record_exists('local_awareness_hlinks_his', ['id' => $old]));
        $this->assertTrue(
            $DB->record_exists('local_awareness_hlinks_his', ['id' => $fresh]),
            'the task deleted a click inside its lifetime'
        );
    }

    /**
     * The shipped default keeps everything.
     *
     * Zero is what an upgrade inherits, so a site that never touches the setting must not lose a
     * single historical click the first time cron runs.
     *
     * @return void
     */
    public function test_the_default_lifetime_discards_nothing(): void {
        global $DB;

        $this->resetAfterTest();

        $ancient = $this->seed_click(1, 2, 3650 * DAYSECS);

        ob_start();
        (new purge_link_history())->execute();
        $output = (string) ob_get_clean();

        $this->assertTrue(
            $DB->record_exists('local_awareness_hlinks_his', ['id' => $ancient]),
            'a ten-year-old click was discarded although the setting was never configured'
        );
        $this->assertSame('', $output);
    }

    /**
     * db/tasks.php declares the task, and its name resolves to a real language string.
     *
     * Reads db/tasks.php through load_default_scheduled_tasks_for_component(): the other loader
     * reads the {task_scheduled} rows installed when the test site was built, and so keeps passing
     * after the declaration is deleted.
     *
     * @return void
     */
    public function test_the_task_is_declared_and_named(): void {
        $tasks = \core\task\manager::load_default_scheduled_tasks_for_component('local_awareness');

        $classnames = array_map(static function (\core\task\scheduled_task $task): string {
            return get_class($task);
        }, $tasks);

        $this->assertContains(purge_link_history::class, $classnames);
        $this->assertSame('Purge old link-click history', (new purge_link_history())->get_name());
    }

    /**
     * Two clicks on one link are two clicks.
     *
     * Each click is one row of the link history report source, so a rate limit of any window would
     * turn a reader who clicked twice into one who clicked once.
     *
     * It goes through helper::track_link(), not the persistent, because the tests in
     * tests/persistent/linkhistory_test.php seed rows directly and would stay green through a guard
     * added to the write path.
     *
     * @return void
     */
    public function test_two_clicks_on_one_link_are_two_clicks(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('enabled', 1, 'local_awareness');

        $this->setAdminUser();
        $formdata = new \stdClass();
        $formdata->title = 'With a link';
        $formdata->content = '<p><a href="https://example.com/policy">Read the policy</a></p>';
        \local_awareness\helper::create_new_notice($formdata);

        $notice = \local_awareness\persistent\awareness::get_record(['title' => 'With a link']);
        $links = \local_awareness\persistent\noticelink::get_notice_link_records($notice->get('id'));
        $linkid = (int) array_key_first($links);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        \local_awareness\external\get_notices::execute('/my/');
        $this->assertTrue(
            \local_awareness\helper::was_notice_delivered($notice),
            'the notice was not served, so the clicks below would be refused for the wrong reason'
        );

        \local_awareness\helper::track_link($linkid);
        \local_awareness\helper::track_link($linkid);

        $this->assertSame(
            2,
            $DB->count_records('local_awareness_hlinks_his', ['hlinkid' => $linkid, 'userid' => $user->id]),
            'a second click on the same link was collapsed into the first'
        );
    }
}
