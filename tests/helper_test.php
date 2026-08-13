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
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
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
     * A notice outliving its cohort must not make the manage page fatal.
     *
     * cohort_get_all_cohorts() returns only the cohorts visible to the caller, and a cohort can
     * simply be deleted, so the id stored on the notice may be absent from the options. An
     * unguarded array lookup raised a TypeError and took the whole notice list down with it.
     *
     * @covers \local_awareness\helper::get_cohort_name
     */
    public function test_get_cohort_name_survives_a_missing_cohort(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort = $this->getDataGenerator()->create_cohort();

        // Control: while the cohort exists its name is returned.
        $this->assertSame($cohort->name, helper::get_cohort_name((int) $cohort->id));

        cohort_delete_cohort($cohort);

        $this->assertSame('-', helper::get_cohort_name((int) $cohort->id));
    }

    /**
     * Saving a notice must not wrap its content in a whole HTML document.
     *
     * update_hyperlinks() parses the content with DOMDocument to stamp each anchor with its link
     * id. Without LIBXML_HTML_NOIMPLIED|NODEFDTD, saveHTML() returns a complete document, so the
     * stored row carried a doctype and <html>/<body> that then rendered nested inside the page.
     *
     * @covers \local_awareness\helper::create_new_notice
     */
    public function test_saved_content_is_a_fragment_not_a_document(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $formdata = new \stdClass();
        $formdata->title = 'Policy update';
        $formdata->content = '<p>Read <a href="https://example.com/policy">the policy</a>.</p>';
        helper::create_new_notice($formdata);

        $notices = awareness::get_all_notices();
        $stored = reset($notices)->get('content');

        $this->assertStringNotContainsStringIgnoringCase('<!DOCTYPE', $stored);
        $this->assertStringNotContainsStringIgnoringCase('<html', $stored);
        $this->assertStringNotContainsStringIgnoringCase('<body', $stored);

        // Control: the anchor was still processed, so the parse really ran.
        $this->assertStringContainsString('data-linkid=', $stored);
    }

    /**
     * Filters and file URLs are resolved when the notice is rendered, not when it is saved.
     *
     * Baking them into storage froze a multilang notice into the author's language for every
     * reader, and wrote absolute /pluginfile.php URLs that break when wwwroot changes.
     *
     * @covers \local_awareness\helper::render_content
     */
    public function test_file_urls_are_resolved_at_render_time_not_at_save_time(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $formdata = new \stdClass();
        $formdata->title = 'Policy update';
        $formdata->content = '<p><img src="@@PLUGINFILE@@/diagram.png" alt="Diagram"></p>';
        helper::create_new_notice($formdata);

        $notices = awareness::get_all_notices();
        $notice = reset($notices);

        // Storage keeps the placeholder.
        $this->assertStringContainsString('@@PLUGINFILE@@', $notice->get('content'));
        $this->assertStringNotContainsString('/pluginfile.php/', $notice->get('content'));

        // Rendering resolves it.
        $rendered = helper::render_content($notice);
        $this->assertStringNotContainsString('@@PLUGINFILE@@', $rendered);
        $this->assertStringContainsString('/pluginfile.php/', $rendered);
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

    /**
     * The display path refuses to answer without a page to judge against.
     *
     * The page rules used to be skipped whenever the URL was empty, which made the definitive
     * filtering optional at the caller's discretion. Refusing is what keeps it mandatory.
     */
    public function test_retrieve_user_notices_requires_a_page_url(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\coding_exception::class);
        helper::retrieve_user_notices('   ');
    }

    /**
     * The navigation probe deliberately ignores the page rules, and the display path applies them.
     *
     * Both halves are load-bearing and pull in opposite directions. If the probe ever narrowed to
     * what the user can actually see right now, a notice restricted to one path would stop loading
     * the JS module anywhere — including on the very path it targets — and could never be shown.
     * If the display path ever widened to match the probe, the notice would appear everywhere.
     */
    public function test_has_candidate_notices_ignores_the_page_rules_that_display_applies(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Dashboard only';
        $data->content = '<p>Only on the dashboard.</p>';
        $data->pathmatch = '/my/%';
        helper::create_new_notice($data);

        $this->setUser($user);

        // The probe says yes from anywhere: that is what keeps the module loading.
        $this->assertTrue(helper::has_candidate_notices());

        // The display path judges the page, and does so in both directions.
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
        $this->assertSame([], helper::retrieve_user_notices('/'));
    }
}
