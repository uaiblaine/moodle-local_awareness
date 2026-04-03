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
use local_awareness\reportbuilder\datasource\acknowledged_notices;

/**
 * Unit tests for the acknowledged_notices datasource.
 *
 * @package    local_awareness
 * @covers     \local_awareness\reportbuilder\datasource\acknowledged_notices
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acknowledged_notices_test extends core_reportbuilder_testcase {
    /**
     * Insert a notice and return its ID.
     */
    private function create_notice(string $title = 'Test notice'): int {
        global $DB, $USER;
        return (int) $DB->insert_record('local_awareness', (object)[
            'title'         => $title,
            'content'       => '',
            'contentformat' => FORMAT_HTML,
            'cohorts'       => '',
            'reqack'        => 1,
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
     * Insert a row into local_awareness_ack.
     */
    private function create_ack(int $noticeid, int|string $userid, int $action): void {
        global $DB;
        $userid = (int)$userid;
        $user = \core_user::get_user($userid);
        $DB->insert_record('local_awareness_ack', (object)[
            'userid'      => $userid,
            'noticeid'    => $noticeid,
            'username'    => $user->username,
            'firstname'   => $user->firstname,
            'lastname'    => $user->lastname,
            'idnumber'    => $user->idnumber,
            'noticetitle' => 'Test notice',
            'action'      => $action,
            'timecreated' => time(),
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

        // One acknowledged, one dismissed — only acknowledged should appear.
        $this->create_ack($noticeid, $user1->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);
        $this->create_ack($noticeid, $user2->id, acknowledgement_persistent::ACTION_DISMISSED);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Acknowledged notices test',
            'source'  => acknowledged_notices::class,
            'default' => 1,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
    }

    /**
     * Test non-default columns.
     */
    public function test_datasource_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'John', 'lastname' => 'Doe']);
        $noticeid = $this->create_notice('Column Test Notice');
        $this->create_ack($noticeid, $user->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Acknowledged columns test',
            'source'  => acknowledged_notices::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullname']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'acknowledgement:action']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Column Test Notice', $row[2]);
    }

    /**
     * Test filters.
     */
    public function test_datasource_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $notice1 = $this->create_notice('Filter Notice A');
        $notice2 = $this->create_notice('Filter Notice B');

        $this->create_ack($notice1, $user1->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);
        $this->create_ack($notice2, $user2->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Acknowledged filter test',
            'source'  => acknowledged_notices::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'notice:title_operator' => \core_reportbuilder\local\filters\text::IS_EQUAL_TO,
            'notice:title_value'    => 'Filter Notice A',
        ]);
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Filter Notice A', $row[0]);
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
        $noticeid = $this->create_notice('Stress Notice');
        $this->create_ack($noticeid, $user->id, acknowledgement_persistent::ACTION_ACKNOWLEDGED);

        $this->datasource_stress_test_columns(acknowledged_notices::class);
        $this->datasource_stress_test_columns_aggregation(acknowledged_notices::class);
        $this->datasource_stress_test_conditions(acknowledged_notices::class, 'notice:title');
    }
}
