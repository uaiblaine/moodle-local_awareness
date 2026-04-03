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
use local_awareness\reportbuilder\datasource\all_notices;

/**
 * Unit tests for the all_notices datasource.
 *
 * @package    local_awareness
 * @covers     \local_awareness\reportbuilder\datasource\all_notices
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class all_notices_test extends core_reportbuilder_testcase {
    /**
     * Test default datasource.
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();

        global $DB, $USER;
        $this->setAdminUser();

        // Insert two notices directly.
        $now = time();
        $DB->insert_record('local_awareness', (object)[
            'title'        => 'Notice Alpha',
            'content'      => 'Content A',
            'contentformat' => FORMAT_HTML,
            'cohorts'      => '',
            'reqack'       => 0,
            'reqcourse'    => 0,
            'enabled'      => 1,
            'resetinterval' => 0,
            'usermodified' => $USER->id,
            'timecreated'  => $now - 200,
            'timemodified' => $now - 200,
            'timestart'    => 0,
            'timeend'      => 0,
            'forcelogout'  => 0,
        ]);
        $DB->insert_record('local_awareness', (object)[
            'title'        => 'Notice Beta',
            'content'      => 'Content B',
            'contentformat' => FORMAT_HTML,
            'cohorts'      => '',
            'reqack'       => 1,
            'reqcourse'    => 0,
            'enabled'      => 0,
            'resetinterval' => 0,
            'usermodified' => $USER->id,
            'timecreated'  => $now - 100,
            'timemodified' => $now - 100,
            'timestart'    => 0,
            'timeend'      => 0,
            'forcelogout'  => 0,
        ]);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'All notices test',
            'source'  => all_notices::class,
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

        global $DB, $USER;
        $this->setAdminUser();

        $now = time();
        $DB->insert_record('local_awareness', (object)[
            'title'         => 'Notice Gamma',
            'content'       => 'Content G',
            'contentformat' => FORMAT_HTML,
            'cohorts'       => '',
            'reqack'        => 0,
            'reqcourse'     => 0,
            'enabled'       => 1,
            'resetinterval' => 86400,
            'usermodified'  => $USER->id,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'timestart'     => $now,
            'timeend'       => $now + 3600,
            'forcelogout'   => 0,
        ]);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'All notices columns test',
            'source'  => all_notices::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:resetinterval']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:forcelogout']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Notice Gamma', $row[0]);
    }

    /**
     * Test filters.
     */
    public function test_datasource_filters(): void {
        $this->resetAfterTest();

        global $DB, $USER;
        $this->setAdminUser();

        $now = time();
        foreach (['Notice One', 'Notice Two', 'Notice Three'] as $title) {
            $DB->insert_record('local_awareness', (object)[
                'title'         => $title,
                'content'       => '',
                'contentformat' => FORMAT_HTML,
                'cohorts'       => '',
                'reqack'        => 0,
                'reqcourse'     => 0,
                'enabled'       => 1,
                'resetinterval' => 0,
                'usermodified'  => $USER->id,
                'timecreated'   => $now,
                'timemodified'  => $now,
                'timestart'     => 0,
                'timeend'       => 0,
                'forcelogout'   => 0,
            ]);
        }

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'All notices filter test',
            'source'  => all_notices::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'notice:title_operator' => \core_reportbuilder\local\filters\text::IS_EQUAL_TO,
            'notice:title_value'    => 'Notice Two',
        ]);
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Notice Two', $row[0]);
    }

    /**
     * Stress test datasource — requires PHPUNIT_LONGTEST.
     */
    public function test_stress_datasource(): void {
        if (!PHPUNIT_LONGTEST) {
            $this->markTestSkipped('PHPUNIT_LONGTEST is not defined');
        }
        $this->resetAfterTest();

        global $DB, $USER;
        $this->setAdminUser();

        $now = time();
        $DB->insert_record('local_awareness', (object)[
            'title'         => 'Stress notice',
            'content'       => '',
            'contentformat' => FORMAT_HTML,
            'cohorts'       => '',
            'reqack'        => 0,
            'reqcourse'     => 0,
            'enabled'       => 1,
            'resetinterval' => 0,
            'usermodified'  => $USER->id,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'timestart'     => 0,
            'timeend'       => 0,
            'forcelogout'   => 0,
        ]);

        $this->datasource_stress_test_columns(all_notices::class);
        $this->datasource_stress_test_columns_aggregation(all_notices::class);
        $this->datasource_stress_test_conditions(all_notices::class, 'notice:title');
    }
}
