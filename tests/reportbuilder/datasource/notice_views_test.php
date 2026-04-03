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

global $CFG;
require_once("{$CFG->dirroot}/reportbuilder/tests/helpers.php");

use core_reportbuilder_testcase;
use core_reportbuilder_generator;
use local_awareness\persistent\acknowledgement as acknowledgement_persistent;
use local_awareness\reportbuilder\datasource\notice_views;

/**
 * Unit tests for the notice_views datasource.
 *
 * @package    local_awareness
 * @covers     \local_awareness\reportbuilder\datasource\notice_views
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notice_views_test extends core_reportbuilder_testcase {
    /**
     * Insert a notice and return its ID.
     */
    private function create_notice(string $title = 'View notice'): int {
        global $DB, $USER;
        return (int) $DB->insert_record('local_awareness', (object)[
            'title'         => $title,
            'content'       => '',
            'contentformat' => FORMAT_HTML,
            'cohorts'       => '',
            'reqack'        => 0,
            'reqcourse'     => 0,
            'enabled'       => 1,
            'resetinterval' => 0,
            'usermodified'  => $USER->id,
            'timecreated'   => time(),
            'timemodified'  => time(),
            'timestart'     => 0,
            'timeend'       => 0,
            'forcelogout'   => 0,
        ]);
    }

    /**
     * Insert a row into local_awareness_lastview.
     */
    private function create_view(int $noticeid, int|string $userid, int $action = 0): void {
        global $DB;
        $userid = (int)$userid;
        $now = time();
        $DB->insert_record('local_awareness_lastview', (object)[
            'noticeid'     => $noticeid,
            'userid'       => $userid,
            'action'       => $action,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Test default datasource.
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $noticeid = $this->create_notice();

        $this->create_view($noticeid, $user1->id, acknowledgement_persistent::ACTION_DISMISSED);
        $this->create_view($noticeid, $user2->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Notice views test',
            'source'  => notice_views::class,
            'default' => 1,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(2, $content);
    }

    /**
     * Test non-default columns.
     */
    public function test_datasource_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $noticeid = $this->create_notice('View Column Notice');
        $this->create_view($noticeid, $user->id, acknowledgement_persistent::ACTION_DISMISSED);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Notice views columns',
            'source'  => notice_views::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'noticeview:action']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'noticeview:timecreated']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('View Column Notice', $row[0]);
    }

    /**
     * Test filters.
     */
    public function test_datasource_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $notice1 = $this->create_notice('View Filter A');
        $notice2 = $this->create_notice('View Filter B');

        $this->create_view($notice1, $user->id, acknowledgement_persistent::ACTION_DISMISSED);
        $this->create_view($notice2, $user->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Notice views filter',
            'source'  => notice_views::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'noticeview:action']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'noticeview:action']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'noticeview:action_operator' => \core_reportbuilder\local\filters\select::EQUAL_TO,
            'noticeview:action_value'    => acknowledgement_persistent::ACTION_ACKNOWLEDGED,
        ]);
        $this->assertCount(1, $content);
    }

    /**
     * Stress test datasource — requires PHPUNIT_LONGTEST.
     */
    public function test_stress_datasource(): void {
        if (!PHPUNIT_LONGTEST) {
            $this->markTestSkipped('PHPUNIT_LONGTEST is not defined');
        }
        $this->resetAfterTest();
        $this->setAdminUser();

        $user     = $this->getDataGenerator()->create_user();
        $noticeid = $this->create_notice('Stress View');
        $this->create_view($noticeid, $user->id);

        $this->datasource_stress_test_columns(notice_views::class);
        $this->datasource_stress_test_columns_aggregation(notice_views::class);
        $this->datasource_stress_test_conditions(notice_views::class, 'notice:title');
    }
}
