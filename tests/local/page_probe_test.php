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

namespace local_awareness\local;

use local_awareness\persistent\awareness;

/**
 * Tests for the page probe's admission rules.
 *
 * Cases are looped rather than fed through a data provider: Moodle 4.5 vendors PHPUnit 9.6, which
 * predates attribute metadata, and a docblock provider would run the method with no arguments.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\page_probe
 */
final class page_probe_test extends \advanced_testcase {
    /**
     * Build a notice carrying only the fields the probe reads.
     *
     * @param string|null $pathmatch The pathmatch pattern.
     * @param array|null $filters Decoded filter values, or null for none.
     * @return awareness
     */
    private function make_notice(?string $pathmatch, ?array $filters = null): awareness {
        return new awareness(0, (object) [
            'title' => 'Probe fixture',
            'content' => '<p>body</p>',
            'pathmatch' => $pathmatch,
            'filtervalues' => $filters === null ? '' : json_encode($filters),
        ]);
    }

    /**
     * The pathmatch rule admits when EITHER URL representation matches, and only rejects on both.
     */
    public function test_pathmatch_is_judged_against_both_url_forms(): void {
        $this->resetAfterTest();

        // Each case: pathmatch, client URL, local URL, expected admission.
        $cases = [
            'no rule admits everywhere' => ['', '/course/view.php?id=2', '/course/view.php?id=2', true],
            'match on both forms' => ['/my/%', '/my/index.php', '/my/index.php', true],
            'no match on either form' => ['/my/%', '/course/view.php?id=2', '/course/view.php?id=2', false],
            'subdirectory client form carries the pattern' => ['/moodle/my/%', '/moodle/my/index.php', '/my/index.php', true],
            'only the local form satisfies a landmark on a subdirectory site' =>
                ['MY', '/moodle/my/index.php', '/my/index.php', true],
            'client form only' => ['/user/profile.php%', '/user/profile.php?id=5', null, true],
            'local form only' => ['/user/profile.php%', null, '/user/profile.php?id=5', true],
            'no URL at all fails open' => ['/my/%', null, null, true],
        ];

        foreach ($cases as $label => [$pathmatch, $clienturl, $localurl, $expected]) {
            $probe = new page_probe($clienturl, $localurl, null, null);
            $this->assertSame($expected, $probe->admits($this->make_notice($pathmatch)), $label);
        }
    }

    /**
     * The landmark tokens go through the display path's own matcher, so they cannot drift from it.
     */
    public function test_landmark_tokens_follow_the_display_matcher(): void {
        $this->resetAfterTest();

        $cases = [
            'MY admits the dashboard' => ['MY', null, '/my/index.php', true],
            'MY rejects a course page' => ['MY', '/course/view.php?id=2', '/course/view.php?id=2', false],
            'FRONTPAGE admits the front page' => ['FRONTPAGE', null, '/', true],
            'MYCOURSES admits the course overview' => ['MYCOURSES', null, '/my/courses.php', true],
            'FRONTPAGE_MY admits both worlds' => ['FRONTPAGE_MY', null, '/my/index.php', true],
        ];

        foreach ($cases as $label => [$pathmatch, $clienturl, $localurl, $expected]) {
            $probe = new page_probe($clienturl, $localurl, null, null);
            $this->assertSame($expected, $probe->admits($this->make_notice($pathmatch)), $label);
        }
    }

    /**
     * Category, course and format filters reject only from a course page that contradicts them.
     */
    public function test_course_scoped_filters(): void {
        $this->resetAfterTest();

        $course = (object) ['id' => 7, 'category' => 3, 'format' => 'topics'];

        // Each case: filters, course object, expected admission.
        $cases = [
            'category filter, matching course' => [['filter_category' => [3]], $course, true],
            'category filter, other category' => [['filter_category' => [9]], $course, false],
            'category filter, not a course page' => [['filter_category' => [3]], null, false],
            'course filter, matching course' => [['filter_course' => [7]], $course, true],
            'course filter, other course' => [['filter_course' => [8]], $course, false],
            'course filter, not a course page' => [['filter_course' => [7]], null, false],
            'format filter, matching format' => [['filter_format' => ['topics']], $course, true],
            'format filter, other format' => [['filter_format' => ['weeks']], $course, false],
            'format filter, not a course page' => [['filter_format' => ['topics']], null, false],
            'string ids from the form still compare' => [['filter_course' => ['7']], $course, true],
        ];

        foreach ($cases as $label => [$filters, $pagecourse, $expected]) {
            $probe = new page_probe('/course/view.php?id=7', null, $pagecourse, null);
            $this->assertSame($expected, $probe->admits($this->make_notice(null, $filters)), $label);
        }
    }

    /**
     * A course record that does not carry the judged property is unevaluable, and admits.
     */
    public function test_missing_course_property_admits(): void {
        $this->resetAfterTest();

        // A narrow record such as a third-party set_course() might install: no category, no format.
        $narrow = (object) ['id' => 7];
        $probe = new page_probe('/course/view.php?id=7', null, $narrow, null);

        $this->assertTrue($probe->admits($this->make_notice(null, ['filter_category' => [3]])));
        $this->assertTrue($probe->admits($this->make_notice(null, ['filter_format' => ['topics']])));
        // The id is present, so the course filter still judges.
        $this->assertFalse($probe->admits($this->make_notice(null, ['filter_course' => [8]])));
    }

    /**
     * The theme filter judges only when the probe was given a trustworthy theme.
     */
    public function test_theme_filter(): void {
        $this->resetAfterTest();

        $judging = new page_probe('/my/', null, null, 'boost');
        $this->assertTrue($judging->admits($this->make_notice(null, ['filter_theme' => ['boost']])));
        $this->assertFalse($judging->admits($this->make_notice(null, ['filter_theme' => ['classic']])));

        // A null theme means "unknown or overrides enabled": the rule must not reject.
        $unjudged = new page_probe('/my/', null, null, null);
        $this->assertTrue($unjudged->admits($this->make_notice(null, ['filter_theme' => ['classic']])));
    }

    /**
     * Rules that cost queries — role, competency — always admit, whatever they say.
     */
    public function test_expensive_rules_always_admit(): void {
        $this->resetAfterTest();

        $probe = new page_probe('/my/', null, null, 'boost');

        $this->assertTrue($probe->admits($this->make_notice(null, ['filter_role' => [1, 2]])));
        $this->assertTrue($probe->admits($this->make_notice(null, [
            'filter_competency_rules' => [['id' => 1, 'proficient' => 1, 'name' => 'x']],
        ])));
    }

    /**
     * Malformed filter payloads leave the rules unapplied, exactly as check_filters() does.
     */
    public function test_malformed_filters_admit(): void {
        $this->resetAfterTest();

        $probe = new page_probe('/my/', null, null, null);

        $notice = new awareness(0, (object) [
            'title' => 'Probe fixture',
            'content' => '<p>body</p>',
            'pathmatch' => null,
            'filtervalues' => 'not json at all',
        ]);
        $this->assertTrue($probe->admits($notice));

        $scalar = new awareness(0, (object) [
            'title' => 'Probe fixture',
            'content' => '<p>body</p>',
            'pathmatch' => null,
            'filtervalues' => '123',
        ]);
        $this->assertTrue($probe->admits($scalar));
    }

    /**
     * A page that never called set_url() yields an unknown URL — quietly, and failing open.
     *
     * The has_set_url() guard is what this pins: without it, reading $PAGE->url on such a page
     * emits the core "did not call set_url" debugging notice on every affected render, which the
     * assertion below turns into a failure.
     */
    public function test_from_page_without_url_stays_quiet_and_admits(): void {
        $this->resetAfterTest();

        $page = new \moodle_page();
        $page->set_context(\context_system::instance());

        $probe = page_probe::from_page($page);
        $this->assertDebuggingNotCalled();

        // Under PHPUnit $FULLME is null as well, so no URL form exists: everything admits.
        $this->assertTrue($probe->admits($this->make_notice('/my/%')));
    }

    /**
     * The browser's URL form is captured from $FULLME, and it is decisive on its own.
     *
     * This pins the client-URL derivation itself: with the capture deleted, a page that never
     * called set_url() would offer no URL form at all and fail open — so the FALSE half below is
     * what catches that regression. The true half pins that a pattern authored against the
     * browser's form (a subdirectory install) is honoured.
     */
    public function test_from_page_captures_the_browser_url_form(): void {
        global $FULLME;
        $this->resetAfterTest();

        $previousfullme = $FULLME;
        $FULLME = 'https://www.example.com/moodle/user/profile.php?id=2';
        try {
            $page = new \moodle_page();
            $page->set_context(\context_system::instance());

            $probe = page_probe::from_page($page);

            $this->assertTrue($probe->admits($this->make_notice('/moodle/user/profile.php%')));
            $this->assertFalse($probe->admits($this->make_notice('/my/%')));
        } finally {
            $FULLME = $previousfullme;
        }
    }

    /**
     * A non-local page URL makes out_as_local_url() throw; the probe degrades to unknown.
     */
    public function test_from_page_with_nonlocal_url_admits(): void {
        $this->resetAfterTest();

        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url(new \moodle_url('https://elsewhere.invalid/x.php'));
        // Core's set_url() itself warns about the foreign wwwroot; that is the fixture, not the probe.
        $this->assertDebuggingCalled('Most probably incorrect set_page() url argument, it does not match the wwwroot!');

        $probe = page_probe::from_page($page);

        $this->assertTrue($probe->admits($this->make_notice('/my/%')));
    }

    /**
     * from_page() captures the course for real course pages only, and respects the theme guard.
     */
    public function test_from_page_course_and_theme_capture(): void {
        global $CFG;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();

        $page = new \moodle_page();
        $page->set_course($course);
        $page->set_url('/course/view.php', ['id' => (int) $course->id]);

        $probe = page_probe::from_page($page);
        // The page's own course satisfies its course filter; another course id does not.
        $this->assertTrue($probe->admits($this->make_notice(null, ['filter_course' => [(int) $course->id]])));
        $this->assertFalse($probe->admits($this->make_notice(null, ['filter_course' => [(int) $course->id + 999]])));

        // The site course is not a course page: a course-scoped notice cannot appear there.
        $sitepage = new \moodle_page();
        $sitepage->set_context(\context_system::instance());
        $sitepage->set_url('/my/index.php');
        $siteprobe = page_probe::from_page($sitepage);
        $this->assertFalse($siteprobe->admits($this->make_notice(null, ['filter_course' => [(int) $course->id]])));

        /*
         * With course or category themes enabled, this render's theme may differ from the one the
         * web service request will resolve, so the theme rule must not be judged: a notice for a
         * theme this page does not use still has to admit. EITHER override alone must disarm the
         * rule, so both halves of the guard are pinned separately.
         */
        $CFG->allowcoursethemes = 1;
        $CFG->allowcategorythemes = 0;
        $guarded = page_probe::from_page($sitepage);
        $this->assertTrue($guarded->admits($this->make_notice(null, ['filter_theme' => ['nosuchtheme']])));

        $CFG->allowcoursethemes = 0;
        $CFG->allowcategorythemes = 1;
        $guarded = page_probe::from_page($sitepage);
        $this->assertTrue($guarded->admits($this->make_notice(null, ['filter_theme' => ['nosuchtheme']])));

        // With overrides off the rule judges: the page's real theme admits, a foreign one rejects.
        $CFG->allowcoursethemes = 0;
        $CFG->allowcategorythemes = 0;
        $judging = page_probe::from_page($sitepage);
        $this->assertTrue($judging->admits($this->make_notice(null, ['filter_theme' => [$sitepage->theme->name]])));
        $this->assertFalse($judging->admits($this->make_notice(null, ['filter_theme' => ['nosuchtheme']])));
    }
}
