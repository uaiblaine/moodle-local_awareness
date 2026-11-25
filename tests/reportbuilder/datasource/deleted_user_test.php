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

declare(strict_types=1);

namespace local_awareness\reportbuilder\datasource;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/datasource_testcase.php');

use core_reportbuilder_generator;
use local_awareness\persistent\acknowledgement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The five report sources read nothing of a deleted account.
 *
 * delete_user() leaves the department, the institution and the old email (inside the username) on
 * the user row, and a custom report built on one of these sources would list them beside what the
 * person did with a notice. The row itself stays, because the report is about the notice.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(acknowledged_notices::class)]
#[CoversClass(dismissed_notices::class)]
#[CoversClass(all_notices::class)]
#[CoversClass(link_history::class)]
#[CoversClass(notice_views::class)]
final class deleted_user_test extends datasource_testcase {
    /**
     * The five sources.
     *
     * @return array[]
     */
    public static function sources(): array {
        return [
            'acknowledged notices' => [acknowledged_notices::class],
            'dismissed notices' => [dismissed_notices::class],
            'all notices' => [all_notices::class],
            'link history' => [link_history::class],
            'notice views' => [notice_views::class],
        ];
    }

    /**
     * Store one row of a source's own table that names a user.
     *
     * @param string $source The datasource class.
     * @param \stdClass $user The user the row is about: the author, for the notices themselves.
     */
    private function record(string $source, \stdClass $user): void {
        global $DB;

        // A notice is stamped with whoever creates it, which is the user all_notices joins.
        $this->setUser($user);
        $noticeid = (int) $this->getDataGenerator()->get_plugin_generator('local_awareness')
            ->create_notice(['reqack' => 1])->get('id');
        $this->setAdminUser();
        $now = time();

        if ($source === acknowledged_notices::class || $source === dismissed_notices::class) {
            $DB->insert_record('local_awareness_ack', (object) [
                'userid' => $user->id,
                'noticeid' => $noticeid,
                'username' => 'snapshot',
                'firstname' => 'Snap',
                'lastname' => 'Shot',
                'idnumber' => '',
                'noticetitle' => 'Notice',
                'action' => $source === acknowledged_notices::class
                    ? acknowledgement::ACTION_ACKNOWLEDGED
                    : acknowledgement::ACTION_DISMISSED,
                'timecreated' => $now,
            ]);
        } else if ($source === link_history::class) {
            $hlinkid = $DB->insert_record('local_awareness_hlinks', (object) [
                'noticeid' => $noticeid,
                'text' => 'Policy',
                'link' => 'https://example.com',
            ]);
            $DB->insert_record('local_awareness_hlinks_his', (object) [
                'hlinkid' => $hlinkid,
                'userid' => $user->id,
                'timecreated' => $now,
            ]);
        } else if ($source === notice_views::class) {
            $DB->insert_record('local_awareness_lastview', (object) [
                'noticeid' => $noticeid,
                'userid' => $user->id,
                'action' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * A deleted account's row stays in the report and shows none of the account's fields.
     *
     * The live account beside it is the control: its username and department are shown.
     *
     * @param string $source The datasource class.
     */
    #[DataProvider('sources')]
    public function test_a_deleted_account_shows_none_of_its_fields(string $source): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $live = $this->getDataGenerator()->create_user(['username' => 'live.reader', 'department' => 'Physics']);
        $gone = $this->getDataGenerator()->create_user([
            'username' => 'gone.reader',
            'email' => 'gone.reader@example.com',
            'department' => 'Forensics',
        ]);
        $this->record($source, $live);
        $this->record($source, $gone);
        delete_user($gone);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Deleted account', 'source' => $source, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:department']);

        $rows = array_map(
            static fn(array $row): array => array_map('strval', array_values($row)),
            $this->get_custom_report_content($report->get('id'))
        );
        $this->assertCount(2, $rows, 'the deleted account\'s row left the report');

        $usernames = array_column($rows, 0);
        $departments = array_column($rows, 1);
        sort($usernames);
        sort($departments);
        $this->assertSame(['', 'live.reader'], $usernames);
        $this->assertSame(['', 'Physics'], $departments);
    }
}
