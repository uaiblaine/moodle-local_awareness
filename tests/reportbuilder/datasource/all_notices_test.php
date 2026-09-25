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
use local_awareness\reportbuilder\datasource\all_notices;

/**
 * Unit tests for the all_notices datasource.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\reportbuilder\datasource\all_notices
 */
final class all_notices_test extends datasource_testcase {
    /**
     * Test default datasource.
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        // Two notices, one of them disabled: the report lists both.
        $now = time();
        $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'title' => 'Notice Alpha',
            'content' => 'Content A',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => 0,
            'reqcourse' => 0,
            'enabled' => 1,
            'resetinterval' => 0,
            'timestart' => 0,
            'timeend' => 0,
        ]);
        $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'title' => 'Notice Beta',
            'content' => 'Content B',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => 1,
            'reqcourse' => 0,
            'enabled' => 0,
            'resetinterval' => 0,
            'timestart' => 0,
            'timeend' => 0,
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

        $this->setAdminUser();

        $now = time();
        $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'title' => 'Notice Gamma',
            'content' => 'Content G',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => 0,
            'outsideclick' => 0,
            'reqcourse' => 0,
            'enabled' => 1,
            'resetinterval' => 86400,
            'timestart' => $now,
            'timeend' => $now + 3600,
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
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:insistence']);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
        $row = array_values(reset($content));
        $this->assertEquals('Notice Gamma', $row[0]);

        /*
         * The insistence column is derived in SQL from two columns and mapped to a label by a
         * callback. The fixture is Blocking rather than the default Informational, because a CASE
         * that always fell through to ELSE would still produce Informational.
         */
        $this->assertEquals(get_string('notice:insistence:blocking', 'local_awareness'), $row[2]);
    }

    /**
     * The reset interval renders as a duration, and a notice that never repeats renders empty.
     *
     * The repeating row proves the callback formats the value; the other pins that zero, meaning
     * the notice never repeats, renders empty.
     */
    public function test_the_reset_interval_renders_as_a_duration(): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        $now = time();
        foreach ([['Repeats daily', 86400], ['Never repeats', 0]] as [$title, $interval]) {
            $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
                'title' => $title,
                'content' => 'Content',
                'contentformat' => FORMAT_HTML,
                'cohorts' => '',
                'reqack' => 0,
                'reqcourse' => 0,
                'enabled' => 1,
                'resetinterval' => $interval,
                'timestart' => 0,
                'timeend' => 0,
            ]);
        }

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Reset interval rendering',
            'source'  => all_notices::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:resetinterval']);

        $cells = [];
        foreach ($this->get_custom_report_content($report->get('id')) as $row) {
            $row = array_values($row);
            $cells[$row[0]] = $row[1];
        }

        // Non-vacuity: both rows really came back, so the assertions below can fail.
        $this->assertCount(2, $cells);

        $this->assertSame('1 day', $cells['Repeats daily']);
        $this->assertSame('', $cells['Never repeats']);
    }

    /**
     * The content column renders like the modal does: filters applied, file URLs resolved.
     *
     * Content is stored as the author wrote it, @@PLUGINFILE@@ placeholders included, so the column
     * has to render it through its callback.
     *
     * The FORMAT_MOODLE row also guards the entity's field order: the callback takes the first field
     * as the content, so with the format first it would render the format number instead of text
     * with a <br>. DML returns every column as a string, so a wrong order does not throw.
     */
    public function test_the_content_column_renders_like_the_modal(): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        $now = time();
        $rows = [
            ['Html row', '<p>Read the <em>policy</em>.</p>', FORMAT_HTML],
            ['Plugin row', '<p><img src="@@PLUGINFILE@@/diagram.png" alt="d"></p>', FORMAT_HTML],
            ['Moodle row', "First line\nSecond line", FORMAT_MOODLE],
        ];
        foreach ($rows as [$title, $content, $format]) {
            $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
                'title' => $title,
                'content' => $content,
                'contentformat' => $format,
                'cohorts' => '',
                'reqack' => 0,
                'reqcourse' => 0,
                'enabled' => 1,
                'resetinterval' => 0,
                'timestart' => 0,
                'timeend' => 0,
            ]);
        }

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report    = $generator->create_report([
            'name'    => 'Content rendering',
            'source'  => all_notices::class,
            'default' => 0,
        ]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:title']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'notice:content']);

        $cells = [];
        foreach ($this->get_custom_report_content($report->get('id')) as $row) {
            $row = array_values($row);
            $cells[$row[0]] = $row[1];
        }

        // Non-vacuity: all three rows came back, so the assertions below can fail.
        $this->assertCount(3, $cells);

        // The placeholder is resolved, not emitted.
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $cells['Plugin row']);
        $this->assertStringContainsString('pluginfile.php', $cells['Plugin row']);

        // The filters ran: under FORMAT_MOODLE a newline becomes a break.
        $this->assertStringContainsString('<br', $cells['Moodle row']);
        $this->assertStringContainsString('First line', $cells['Moodle row']);

        // And the ordinary case still carries its markup through.
        $this->assertStringContainsString('<em>policy</em>', $cells['Html row']);
    }

    /**
     * Test filters.
     */
    public function test_datasource_filters(): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        $now = time();
        foreach (['Notice One', 'Notice Two', 'Notice Three'] as $title) {
            $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
                'title' => $title,
                'content' => '',
                'contentformat' => FORMAT_HTML,
                'cohorts' => '',
                'reqack' => 0,
                'reqcourse' => 0,
                'enabled' => 1,
                'resetinterval' => 0,
                'timestart' => 0,
                'timeend' => 0,
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
     * Exercise every column and aggregation the datasource offers.
     *
     * Not gated behind PHPUNIT_LONGTEST, which moodle-plugin-ci leaves false: gated, the column
     * and aggregation matrix would never run in CI.
     */
    public function test_stress_datasource(): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        $now = time();
        $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'title' => 'Stress notice',
            'content' => '',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => 0,
            'reqcourse' => 0,
            'enabled' => 1,
            'resetinterval' => 0,
            'timestart' => 0,
            'timeend' => 0,
        ]);

        $this->datasource_stress_test_columns(all_notices::class);
        $this->datasource_stress_test_columns_aggregation(all_notices::class);
        $this->datasource_stress_test_conditions(all_notices::class, 'notice:title');
    }

    /**
     * The reqcourse column reports a boolean, not the course id it stores.
     *
     * The column is TYPE_BOOLEAN over a stored course id, and Report Builder aggregates a boolean
     * column arithmetically, so the entity normalises it to 0 or 1 in SQL. The assertion goes
     * through an aggregation because that is the path the raw value reaches. The second notice,
     * which requires no course, is the control: without it a normalisation that returned 1 for
     * everything would pass.
     */
    public function test_the_reqcourse_column_aggregates_as_a_boolean(): void {
        $this->resetAfterTest();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->assertGreaterThan(1, (int) $course->id, 'the course id must be large enough to tell from a boolean');

        $now = time();
        foreach ([(int) $course->id, 0] as $i => $reqcourse) {
            $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
                'title' => 'Notice ' . $i,
                'content' => 'Content',
                'contentformat' => FORMAT_HTML,
                'cohorts' => '',
                'reqack' => 0,
                'reqcourse' => $reqcourse,
                'enabled' => 1,
                'resetinterval' => 0,
                'timestart' => 0,
                'timeend' => 0,
            ]);
        }

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name'    => 'Reqcourse aggregation',
            'source'  => all_notices::class,
            'default' => 0,
        ]);
        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'notice:reqcourse',
            'aggregation' => 'sum',
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $row = array_values(reset($content));

        $this->assertSame(
            '1',
            (string) $row[0],
            'summing the column must count the notices that require a course, not add up course ids'
        );
    }
}
