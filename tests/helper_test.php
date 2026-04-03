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
 * Test cases
 * @package    local_awareness
 * Originally developed by Dmitrii Metelkin <dmitriim@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper
 */
final class helper_test extends \advanced_testcase {
    /**
     * Test a list of cohorts is built properly.
     */
    public function test_built_cohort_options(): void {
        $this->resetAfterTest(true);

        $expected = [];
        for ($i = 1; $i <= 50; $i++) {
            $cohort = $this->getDataGenerator()->create_cohort();
            $expected[$cohort->id] = $cohort->name;
        }

        $actual = helper::built_cohorts_options();

        foreach ($expected as $id => $name) {
            $this->assertSame($actual[$id], $name);
        }
    }

    /**
     * Test that we can have full HTML in a notice content.
     */
    public function test_can_have_html_in_notice_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_update', 1, 'local_awareness');

        $formdata = new \stdClass();
        $formdata->title = "What is Moodle?";
        $formdata->content = 'Moodle <iframe width="1280" height="720" src="https://www.youtube.com/embed/3ORsUGVNxGs"></iframe>';
        helper::create_new_notice($formdata);

        $allnotices = awareness::get_all_notices();
        $actual = reset($allnotices);
        $this->assertStringContainsString($formdata->content, $actual->get('content'));

        $formdata->title = 'Updated notice';
        $formdata->content = 'Updated  <iframe width="1280" height="720" src="https://www.youtube.com/embed/wop3FMhoLGs"></iframe>';
        $awareness = awareness::get_record(['id' => $actual->get('id')]);
        helper::update_notice($awareness, $formdata);

        $allnotices = awareness::get_all_notices();
        $actual = reset($allnotices);
        $this->assertStringContainsString($formdata->content, $actual->get('content'));

        // Test for some special UTF-8 characters. HTML reserved characters must be converted in the form.
        $formdata->content = '<p>Héllo 😃 world &amp; café</p>';
        $expected = '<p>H&eacute;llo &#128515; world &amp; caf&eacute;</p>';
        helper::update_notice($awareness, $formdata);

        $allnotices = awareness::get_all_notices();
        $actual = reset($allnotices);
        $this->assertStringContainsString($expected, $actual->get('content'));
    }

    /**
     * Test time interval format.
     */
    public function test_format_interval_time(): void {
        // The interval is 1 day(s) 2 hour(s) 3 minute(s) 4 second(s).
        $timeinterval = 93784;
        $formatedtime = helper::format_interval_time($timeinterval);
        // Assume the time format is '%a day(s), %h hour(s), %i minute(s) and %s second(s)'.
        $this->assertStringContainsString('1 day(s), 2 hour(s), 3 minute(s) and 4 second(s)', $formatedtime);
    }

    /**
     * Test cohorts options.
     */
    public function test_cohort_options(): void {
        $this->resetAfterTest();

        $options = helper::built_cohorts_options();
        $this->assertEquals(0, count($options));

        $this->getDataGenerator()->create_cohort();
        $options = helper::built_cohorts_options();
        $this->assertEquals(1, count($options));

        $this->getDataGenerator()->create_cohort();
        $options = helper::built_cohorts_options();
        $this->assertEquals(2, count($options));
    }

    /**
     * Test competency rule normalisation with JSON input, duplicates and invalid rows.
     */
    public function test_normalise_competency_rules_with_json_input(): void {
        $rawrules = json_encode([
            ['id' => '10', 'proficient' => 1, 'name' => '  Competency A  '],
            ['competencyid' => 11, 'proficient' => 0, 'name' => '<b>Competency B</b>'],
            ['id' => 10, 'proficient' => 0, 'name' => 'Duplicate should be ignored'],
            ['id' => 0, 'proficient' => 1, 'name' => 'Invalid ID'],
            'invalid-row',
        ]);

        $actual = helper::normalise_competency_rules($rawrules);

        $this->assertCount(2, $actual);
        $this->assertSame(10, $actual[0]['id']);
        $this->assertSame(1, $actual[0]['proficient']);
        $this->assertSame('Competency A', $actual[0]['name']);

        $this->assertSame(11, $actual[1]['id']);
        $this->assertSame(0, $actual[1]['proficient']);
        $this->assertSame('Competency B', $actual[1]['name']);
    }

    /**
     * Test competency rules are capped to a safe maximum amount.
     */
    public function test_normalise_competency_rules_cap_amount(): void {
        $rawrules = [];
        for ($i = 1; $i <= 30; $i++) {
            $rawrules[] = ['id' => $i, 'proficient' => 1, 'name' => 'Competency ' . $i];
        }

        $actual = helper::normalise_competency_rules($rawrules);

        $this->assertCount(25, $actual);
        $this->assertSame(1, $actual[0]['id']);
        $this->assertSame(25, $actual[24]['id']);
    }

    /**
     * Test requireall key alone does not accidentally activate filters.
     */
    public function test_check_filters_with_only_requireall_key(): void {
        $this->resetAfterTest();

        $filtervalues = json_encode([
            'filter_competency_requireall' => 1,
        ]);

        $this->assertTrue(helper::check_filters($filtervalues));
    }
}
