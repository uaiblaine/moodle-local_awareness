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
use local_awareness\reportbuilder\datasource\link_history;

/**
 * Unit tests for the link_history datasource.
 *
 * @package    local_awareness
 * @covers     \local_awareness\reportbuilder\datasource\link_history
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_history_test extends core_reportbuilder_testcase {
    /**
     * Insert a notice, a hyperlink for it, and a click event.
     *
     * @return array{noticeid: int, hlinkid: int, lhhid: int}
     */
    private function create_link_click(int|string $userid, string $text = 'Click me', string $url = 'https://example.com'): array {
        global $DB, $USER;

        $userid = (int)$userid;

        $noticeid = (int) $DB->insert_record('local_awareness', (object)[
            'title'         => 'Link notice',
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

        $hlinkid = (int) $DB->insert_record('local_awareness_hlinks', (object)[
            'noticeid' => $noticeid,
            'text'     => $text,
            'link'     => $url,
        ]);

        $lhhid = (int) $DB->insert_record('local_awareness_hlinks_his', (object)[
            'hlinkid'     => $hlinkid,
            'userid'      => $userid,
            'timecreated' => time(),
        ]);

        return ['noticeid' => $noticeid, 'hlinkid' => $hlinkid, 'lhhid' => $lhhid];
    }

    /**
     * Test default datasource.
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $this->create_link_click($user1->id, 'Link A');
        $this->create_link_click($user2->id, 'Link B');

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Link history test',
            'source'  => link_history::class,
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
        $this->create_link_click($user->id, 'Specific Link', 'https://moodle.org');

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Link history columns',
            'source'  => link_history::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'linkhistory:linktext']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'linkhistory:linkurl']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Specific Link', $row[0]);
        $this->assertEquals('https://moodle.org', $row[1]);
    }

    /**
     * Test filters.
     */
    public function test_datasource_filters(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $this->create_link_click($user->id, 'Alpha Link');
        $this->create_link_click($user->id, 'Beta Link');

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Link history filter',
            'source'  => link_history::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'linkhistory:linktext']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'linkhistory:linktext']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'linkhistory:linktext_operator' => \core_reportbuilder\local\filters\text::IS_EQUAL_TO,
            'linkhistory:linktext_value'    => 'Alpha Link',
        ]);
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Alpha Link', $row[0]);
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

        $user = $this->getDataGenerator()->create_user();
        $this->create_link_click($user->id, 'Stress Link');

        $this->datasource_stress_test_columns(link_history::class);
        $this->datasource_stress_test_columns_aggregation(link_history::class);
        $this->datasource_stress_test_conditions(link_history::class, 'notice:title');
    }
}
