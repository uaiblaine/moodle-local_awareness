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

use local_awareness\local\page_probe;
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
     * The probe is a page-aware superset of the display path.
     *
     * This deliberately replaces the previous contract, which pinned the probe as page-INDEPENDENT.
     * The probe may now say "no" for a page — that is the whole point of the footer-hook redesign —
     * but only from the cheap page rules, and only when given a page: without one it must keep the
     * old page-independent answer, and with one it must still admit every notice the display path
     * would show on that page. The display path itself is pinned unchanged in both directions.
     */
    public function test_has_candidate_notices_is_a_page_aware_superset_of_display(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Dashboard only';
        $data->content = '<p>Only on the dashboard.</p>';
        $data->pathmatch = '/my/%';
        helper::create_new_notice($data);

        $this->setUser($user);

        // Without a page the probe answers the page-independent question, as before.
        $this->assertTrue(helper::has_candidate_notices());

        // With a page it narrows: nothing can appear on a course page...
        $oncourse = new page_probe('/course/view.php?id=2', '/course/view.php?id=2', null, null);
        $this->assertFalse(helper::has_candidate_notices($oncourse));

        // ...while the target page still admits, so the module still loads where it must.
        $ondashboard = new page_probe('/my/', '/my/index.php', null, null);
        $this->assertTrue(helper::has_candidate_notices($ondashboard));

        // A probe that knows nothing about the page must fail open and keep loading the module.
        $unknown = new page_probe(null, null, null, null);
        $this->assertTrue(helper::has_candidate_notices($unknown));

        // The display path judges the page, and does so in both directions, exactly as before.
        $this->assertCount(1, helper::retrieve_user_notices('/my/'));
        $this->assertSame([], helper::retrieve_user_notices('/'));
    }

    /**
     * The allow_update setting must gate the write, not merely the form that leads to it.
     *
     * editnotice.php consulted it only in `case 'edit'`, which decides whether to DISPLAY the form,
     * and its save branch runs before that switch — so a POST updated the notice with the setting
     * off. The control below is what makes this test non-vacuous: an identical update WITH the
     * setting on has to land, or the refusal above would prove nothing about the setting.
     *
     * @covers \local_awareness\helper::update_notice
     */
    public function test_update_notice_obeys_the_allow_update_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $formdata = new \stdClass();
        $formdata->title = 'Original title';
        $formdata->content = '<p>Body</p>';
        helper::create_new_notice($formdata);

        $notice = awareness::get_record(['title' => 'Original title']);
        $this->assertNotFalse($notice, 'The notice must exist before the update can be judged.');

        // Setting off — which is the shipped default, asserted rather than assumed.
        $this->assertEmpty(get_config('local_awareness', 'allow_update'));

        $formdata->title = 'Renamed with the setting off';
        helper::update_notice($notice, $formdata);

        $reread = awareness::get_record(['id' => $notice->get('id')]);
        $this->assertSame('Original title', $reread->get('title'));

        // Control: the same update, with the setting on, must go through.
        set_config('allow_update', 1, 'local_awareness');
        $formdata->title = 'Renamed with the setting on';
        helper::update_notice($reread, $formdata);

        $reread = awareness::get_record(['id' => $notice->get('id')]);
        $this->assertSame('Renamed with the setting on', $reread->get('title'));
    }

    /**
     * A cohort the author may not see must not survive the save.
     *
     * The estimator counts members with a bare `cohortid IN (…)`, so an id that reached the POST
     * without being offered still yields a population size — a membership oracle for any cohort on
     * the site. The visible cohort in the same call is the control: without it, a change that
     * dropped every cohort would pass this test.
     *
     * @covers \local_awareness\helper::allowed_cohorts
     */
    public function test_a_cohort_the_user_cannot_see_is_dropped_on_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $visible = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);
        $category = $this->getDataGenerator()->create_category();
        $hidden = $this->getDataGenerator()->create_cohort([
            'contextid' => \context_coursecat::instance($category->id)->id,
        ]);

        /*
         * A manager who holds the plugin's capability at system level but cannot view cohorts in
         * that category — which is what makes the id unofferable to them, and the request forgeable.
         */
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:manage', CAP_ALLOW, $roleid, \context_system::instance()->id);
        assign_capability('moodle/cohort:view', CAP_PROHIBIT, $roleid, \context_system::instance()->id);
        role_assign($roleid, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $formdata = new \stdClass();
        $formdata->title = 'Targeted notice';
        $formdata->content = '<p>Body</p>';
        $formdata->cohorts = [$visible->id, $hidden->id];
        helper::create_new_notice($formdata);

        $stored = array_map('intval', awareness::get_record(['title' => 'Targeted notice'])->get('cohorts'));

        $this->assertNotContains((int) $hidden->id, $stored);
        // Control: the cohort this user CAN see has to survive, or nothing was proven.
        $this->assertContains((int) $visible->id, $stored);
    }
}
