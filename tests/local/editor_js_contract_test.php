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

use local_awareness\external\check_collision;
use local_awareness\form\notice_form;
use local_awareness\output\editor_page;

/**
 * The notice editor's modules agree with the page about its scope, and speak the author's language.
 *
 * Nothing in the pipeline compares a JavaScript module with the PHP that renders its page, so the
 * contracts between them are read here: which element carries the scope, what a failed estimate
 * says, and the licence every module ships under.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\output\editor_page
 */
final class editor_js_contract_test extends \advanced_testcase {
    /** @var array The editor modules that send the scope with a web-service call. */
    private const SCOPED_MODULES = [
        'audience_estimator.js',
        'collision_warning.js',
        'course_search.js',
        'editor_preview.js',
        'role_search.js',
    ];

    /**
     * Read one file from the plugin root.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string The file contents.
     */
    private function read(string $relative): string {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path, "{$relative} has been renamed, so this test is now blind, not passing.");

        return (string) file_get_contents($path);
    }

    /**
     * Every editor module sends the course the page rendered, never the one in the URL.
     *
     * editnotice.php lets a notice's own scope win over the courseid in its URL, so a course notice
     * opened without one is edited as a course notice. A module reading the URL would send the site
     * scope while the others send the course, and the preview would be refused or answered for the
     * wrong audience.
     */
    public function test_the_editor_modules_read_the_scope_the_page_rendered(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice([
            'courseid' => (int) $course->id,
        ]);

        // The force: the URL carries no courseid, and the page still resolves the course.
        $scope = author_scope::for_request($notice, 0);
        $this->assertSame((int) $course->id, $scope->get_courseid());

        $output = $PAGE->get_renderer('core');
        $html = $output->render_from_template(
            'local_awareness/editor/shell',
            (new editor_page($notice, '', $scope))->export_for_template($output)
        );
        $this->assertMatchesRegularExpression(
            '/data-region="la-editor"[^>]*data-courseid="' . $course->id . '"/',
            $html,
            'the editor root no longer carries the scope the page resolved'
        );

        // The one reader of that attribute.
        $reader = $this->read('amd/src/editor_scope.js');
        $this->assertStringContainsString('[data-region="la-editor"]', $reader);
        $this->assertStringContainsString("getAttribute('data-courseid')", $reader);

        foreach (self::SCOPED_MODULES as $module) {
            $source = $this->read('amd/src/' . $module);
            $this->assertStringContainsString(
                'local_awareness/editor_scope',
                $source,
                "{$module} must read the scope through local_awareness/editor_scope"
            );
            $this->assertStringContainsString('courseid: ', $source, "{$module} no longer sends a courseid");
            $this->assertStringNotContainsString('window.location', $source, "{$module} reads the scope from the URL");
            $this->assertStringNotContainsString('data-courseid', $source, "{$module} keeps its own copy of the reader");
        }

        $this->assertStringNotContainsString(
            'URLSearchParams',
            $this->read('amd/build/editor_preview.min.js'),
            'amd/build/editor_preview.min.js predates the scope reader: rebuild it.'
        );
    }

    /**
     * The collision warning sends every parameter check_collision reads, the notice's window included.
     *
     * Without the window the editor warns about the rivals of a notice whose end has passed, which
     * the save and the list do not. The request is read from the module, and the fields it reads
     * the window from are read from the form as each scope renders it.
     */
    public function test_the_collision_warning_sends_the_notice_window(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->read('amd/src/collision_warning.js');
        $this->assertSame(
            1,
            preg_match("/methodname: 'local_awareness_check_collision',\s*args: \{([^}]*)\}\s*\}\]\)/", $source, $args),
            'the check_collision request is gone, so the scan below would pass blind'
        );
        preg_match_all('/^\s*([a-z]+): /m', $args[1], $sent);
        $declared = check_collision::execute_parameters()->keys;
        $this->assertEqualsCanonicalizing(array_keys($declared), $sent[1], 'the request and check_collision disagree');

        $this->assertSame(1, preg_match('/var DATE_PARTS = \[([^\]]*)\]/', $source, $list), 'DATE_PARTS is gone');
        preg_match_all("/'([a-z]+)'/", $list[1], $parts);
        $this->assertEqualsCanonicalizing(array_keys($declared['timeend']->keys), $parts[1]);
        $this->assertStringContainsString("perpetual: '#id_perpetual'", $source);
        $this->assertStringContainsString("timeend: '#id_timeend_'", $source);

        // Changing either asks again, as the repeat interval does.
        $this->assertStringContainsString("perpetualfield.addEventListener('change', schedule)", $source);
        $this->assertMatchesRegularExpression(
            "/DATE_PARTS\.forEach\(function\(part\) \{[^}]*SELECTORS\.timeend \+ part[^}]*addEventListener\('change', schedule\)/",
            $source
        );

        // The form's editors read the page URL while rendering.
        $PAGE->set_url(new \moodle_url('/local/awareness/editnotice.php'));
        $course = $this->getDataGenerator()->create_course();
        foreach ([author_scope::site(), author_scope::course((int) $course->id)] as $scope) {
            $html = (new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => $scope]))->render();
            $where = $scope->is_site() ? 'the site form' : 'the course form';

            // Perpetual unless the select says No, which selectyesno posts as 0.
            $this->assertSame(1, preg_match('/<select[^>]*id="id_perpetual"[^>]*>(.*?)<\/select>/s', $html, $select), $where);
            $this->assertStringContainsString('value="0"', $select[1], $where);
            foreach ($parts[1] as $part) {
                $this->assertStringContainsString('id="id_timeend_' . $part . '"', $html, $where);
            }
        }

        $this->assertStringContainsString(
            '#id_timeend_',
            $this->read('amd/build/collision_warning.min.js'),
            'amd/build/collision_warning.min.js predates the window: rebuild it.'
        );
    }

    /**
     * A failed estimate is described in the author's language.
     *
     * The text substituted into audience:state:error is shown in the panel, so a hard-coded English
     * fallback reaches an author working in any language.
     */
    public function test_the_estimator_has_no_english_fallback_text(): void {
        $source = $this->read('amd/src/audience_estimator.js');

        // Calls only: the lookbehind skips the function's own declaration.
        preg_match_all('/(?<!function )handleError\(([^;]*)\);/', $source, $calls);
        $this->assertGreaterThanOrEqual(3, count($calls[1]), 'the scan found implausibly few error paths');
        foreach ($calls[1] as $argument) {
            $this->assertDoesNotMatchRegularExpression(
                "/['\"][A-Za-z]/",
                $argument,
                "handleError({$argument}) shows a literal the language packs do not carry"
            );
        }

        preg_match('/function failureText\(err\) \{(.*?)\n    \}/s', $source, $body);
        $this->assertNotEmpty($body, 'failureText() is gone, so the scan below would pass blind');
        $this->assertDoesNotMatchRegularExpression(
            "/['\"][A-Za-z]/",
            $body[1],
            'failureText() falls back to a literal the language packs do not carry'
        );

        // The fallback it uses instead is fetched, and exists.
        $this->assertStringContainsString("'audience:state:error_noanswer'", $source);
        $this->assertTrue(get_string_manager()->string_exists('audience:state:error_noanswer', 'local_awareness'));
    }

    /**
     * Every module the plugin ships carries the GPL header.
     */
    public function test_every_module_carries_the_licence_header(): void {
        $modules = glob(dirname(__DIR__, 2) . '/amd/src/*.js');
        $this->assertGreaterThan(10, count($modules), 'the module sweep found implausibly few files');

        $missing = [];
        foreach ($modules as $path) {
            $head = (string) file_get_contents($path, false, null, 0, 600);
            if (
                !str_starts_with($head, '// This file is part of Moodle - http://moodle.org/')
                || !str_contains($head, 'GNU General Public License')
            ) {
                $missing[] = basename($path);
            }
        }

        $this->assertSame([], $missing, 'these modules ship without the licence header');
    }
}
