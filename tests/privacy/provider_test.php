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

namespace local_awareness\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_awareness\persistent\noticeview;

/**
 * Tests for the privacy provider's request and userlist implementations.
 *
 * The audit closed four findings here — the audience-jobs table missing from the provider
 * entirely, and the contextlist, the userlist and the erasure each looking only at
 * local_awareness_lastview — and nothing guarded any of them afterwards. Core's own
 * compliance test checks that a table carrying a userid is DECLARED; it never calls
 * export_user_data() or any delete method, so every one of those repairs could have been
 * reverted with the suite green.
 *
 * Each table therefore gets its own case, seeded ALONE. Seeding a user with all four rows
 * at once would pass just as happily against the lastview-only SQL the findings describe,
 * because lastview would carry the whole assertion.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /**
     * Every user-linked table, and a closure seeding exactly one row of it for a user.
     *
     * @return array
     */
    public static function table_provider(): array {
        return [
            'lastview' => ['local_awareness_lastview'],
            'acknowledgement' => ['local_awareness_ack'],
            'link click history' => ['local_awareness_hlinks_his'],
            'audience job' => ['local_awareness_audience_jobs'],
        ];
    }

    /**
     * Write one row into the named table for the given user, and nothing into any other.
     *
     * @param string $table One of the four user-linked table names.
     * @param int $userid User to attribute the row to.
     */
    private function seed_one(string $table, int $userid): void {
        global $DB;

        switch ($table) {
            case 'local_awareness_lastview':
                $DB->insert_record('local_awareness_lastview', (object) [
                    'noticeid' => 7,
                    'userid' => $userid,
                    'action' => '1',
                    'timecreated' => time(),
                    'timemodified' => time(),
                ]);
                break;
            case 'local_awareness_ack':
                $DB->insert_record('local_awareness_ack', (object) [
                    'userid' => $userid,
                    'username' => 'someone',
                    'firstname' => 'Some',
                    'lastname' => 'One',
                    'idnumber' => 'ID1',
                    'noticeid' => 7,
                    'noticetitle' => 'Policy update',
                    'action' => 1,
                    'timecreated' => time(),
                ]);
                break;
            case 'local_awareness_hlinks_his':
                $DB->insert_record('local_awareness_hlinks_his', (object) [
                    'hlinkid' => 3,
                    'userid' => $userid,
                    'timecreated' => time(),
                ]);
                break;
            case 'local_awareness_audience_jobs':
                $DB->insert_record('local_awareness_audience_jobs', (object) [
                    'jobid' => 'job-' . $userid,
                    'userid' => $userid,
                    'noticeid' => 7,
                    'criteriahash' => str_repeat('a', 64),
                    'criteria' => '[]',
                    'status' => 'ready',
                    'timecreated' => time(),
                ]);
                break;
            default:
                throw new \coding_exception('Unknown table ' . $table);
        }
    }

    /**
     * Count this plugin's rows held for one user across all four tables.
     *
     * @param int $userid User id.
     * @return int Total rows.
     */
    private function row_count(int $userid): int {
        global $DB;

        $total = 0;
        foreach (array_column(self::table_provider(), 0) as $table) {
            $total += $DB->count_records($table, ['userid' => $userid]);
        }
        return $total;
    }

    /**
     * A row in any single user-linked table is enough to put the user's context in the list.
     *
     * @dataProvider table_provider
     * @param string $table The table seeded on its own.
     */
    public function test_get_contexts_for_userid_sees_every_table(string $table): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $untouched = $this->getDataGenerator()->create_user();

        // The precondition the whole case rests on: before seeding, the user has no context.
        // Without this a provider that returned every user context unconditionally would pass.
        $this->assertCount(0, provider::get_contexts_for_userid((int) $user->id)->get_contextids());

        $this->seed_one($table, (int) $user->id);

        $contextids = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
        $this->assertEquals(
            [\context_user::instance($user->id)->id],
            array_map('intval', $contextids),
            "a row in {$table} alone must yield the user context"
        );

        // Control: a user with no rows at all stays out, so the assertion above is about the row.
        $this->assertCount(0, provider::get_contexts_for_userid((int) $untouched->id)->get_contextids());
    }

    /**
     * The userlist path reaches every table too — it is what makes delete_data_for_users run.
     *
     * @dataProvider table_provider
     * @param string $table The table seeded on its own.
     */
    public function test_get_users_in_context_sees_every_table(string $table): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);

        $empty = new userlist($context, 'local_awareness');
        provider::get_users_in_context($empty);
        $this->assertEmpty($empty->get_userids());

        $this->seed_one($table, (int) $user->id);

        $userlist = new userlist($context, 'local_awareness');
        provider::get_users_in_context($userlist);
        $this->assertEquals(
            [(int) $user->id],
            array_map('intval', $userlist->get_userids()),
            "a row in {$table} alone must put the user in the userlist"
        );
    }

    /**
     * A context that is not a user context yields nobody.
     */
    public function test_get_users_in_context_ignores_non_user_contexts(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->seed_one('local_awareness_lastview', (int) $user->id);

        // Control: the same seeded row IS found through the user context, so an empty result
        // below means the context check rejected it rather than the seeding having failed.
        $userlist = new userlist(\context_user::instance($user->id), 'local_awareness');
        provider::get_users_in_context($userlist);
        $this->assertCount(1, $userlist->get_userids());

        $systemlist = new userlist(\context_system::instance(), 'local_awareness');
        provider::get_users_in_context($systemlist);
        $this->assertEmpty($systemlist->get_userids());
    }

    /**
     * Export ships all four tables, and only the requesting user's rows.
     */
    public function test_export_user_data_ships_every_table(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach (array_column(self::table_provider(), 0) as $table) {
            $this->seed_one($table, (int) $user->id);
            $this->seed_one($table, (int) $other->id);
        }

        $context = \context_user::instance($user->id);
        provider::export_user_data(new approved_contextlist(
            $user,
            'local_awareness',
            [$context->id]
        ));

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([get_string('pluginname', 'local_awareness')]);
        foreach (['lastview', 'acknowledgement', 'linktracking', 'audiencejobs'] as $key) {
            $this->assertNotEmpty($data->$key, "export is missing the {$key} rows");
            foreach ($data->$key as $row) {
                $this->assertEquals(
                    (int) $user->id,
                    (int) $row->userid,
                    "export leaked another user's {$key} row"
                );
            }
        }
    }

    /**
     * Erasure by context removes every table's rows and leaves other users alone.
     */
    public function test_delete_data_for_all_users_in_context_clears_every_table(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        foreach (array_column(self::table_provider(), 0) as $table) {
            $this->seed_one($table, (int) $user->id);
            $this->seed_one($table, (int) $bystander->id);
        }
        $this->assertSame(4, $this->row_count((int) $user->id));

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));

        $this->assertSame(0, $this->row_count((int) $user->id));
        // Control: erasure that deleted the whole table would pass the line above.
        $this->assertSame(4, $this->row_count((int) $bystander->id));
    }

    /**
     * A non-user context must not trigger erasure.
     */
    public function test_delete_data_for_all_users_in_context_ignores_non_user_contexts(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        foreach (array_column(self::table_provider(), 0) as $table) {
            $this->seed_one($table, (int) $user->id);
        }

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(4, $this->row_count((int) $user->id));
    }

    /**
     * Erasure through the contextlist path clears every table.
     */
    public function test_delete_data_for_user_clears_every_table(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        foreach (array_column(self::table_provider(), 0) as $table) {
            $this->seed_one($table, (int) $user->id);
            $this->seed_one($table, (int) $bystander->id);
        }

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_awareness',
            [\context_user::instance($user->id)->id]
        ));

        $this->assertSame(0, $this->row_count((int) $user->id));
        $this->assertSame(4, $this->row_count((int) $bystander->id));
    }

    /**
     * Erasure through the approved userlist clears every table.
     */
    public function test_delete_data_for_users_clears_every_table(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        foreach (array_column(self::table_provider(), 0) as $table) {
            $this->seed_one($table, (int) $user->id);
            $this->seed_one($table, (int) $bystander->id);
        }

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($user->id),
            'local_awareness',
            [(int) $user->id]
        ));

        $this->assertSame(0, $this->row_count((int) $user->id));
        $this->assertSame(4, $this->row_count((int) $bystander->id));
    }

    /**
     * A userlist the data-request approver did NOT approve must delete nothing.
     *
     * The context of a user context names exactly one user, so it is tempting to take the
     * userid from the context and ignore the list. That reads the same in the passing case
     * and inverts the meaning of approval in this one: the approver withheld consent and the
     * rows go anyway.
     */
    public function test_delete_data_for_users_respects_an_empty_approved_list(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        foreach (array_column(self::table_provider(), 0) as $table) {
            $this->seed_one($table, (int) $user->id);
        }

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($user->id),
            'local_awareness',
            []
        ));

        $this->assertSame(4, $this->row_count((int) $user->id));
    }

    /**
     * Erasure drops the user's MUC view cache, not only their database rows.
     *
     * The view records are read through a MODE_APPLICATION cache keyed by user id. A bulk
     * $DB->delete_records() does not notify it, so without an explicit purge the deleted
     * user's viewing history stays readable from the cache for the rest of its life.
     */
    public function test_erasure_purges_the_view_cache(): void {
        global $USER;

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        noticeview::add_notice_view(7, (int) $user->id, 1);

        // Populate the cache through the read path, and prove it really is populated —
        // otherwise the assertion after erasure is satisfied by a cache that was never warm.
        noticeview::get_user_viewed_notice_records();
        $cache = \cache::make('local_awareness', 'notice_view');
        $this->assertNotFalse($cache->get((string) $USER->id));

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));

        $this->assertFalse($cache->get((string) $USER->id));
    }
}
