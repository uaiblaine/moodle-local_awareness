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

namespace local_awareness\output;

/**
 * The audience panel template documents, and renders, the context the panel exports.
 *
 * The template's example context is what the Mustache lint renders and what a developer copies, so
 * a key the producer renamed long ago leaves the lint rendering a panel with an empty state line,
 * and nothing in the pipeline says so.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\output\audience_panel
 */
final class audience_panel_test extends \advanced_testcase {
    /**
     * The template's example context, decoded.
     *
     * @return array The example, under its 'audience' key.
     */
    private function example(): array {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/editor/audience_panel.mustache');

        $start = strpos($template, 'Example context (json):');
        $this->assertNotFalse($start, 'the template has no example context');
        $end = strpos($template, "\n}}", $start);
        $this->assertNotFalse($end, 'the example context has no end');

        $json = substr($template, $start + strlen('Example context (json):'), $end - $start - strlen('Example context (json):'));
        $example = json_decode(trim($json), true);
        $this->assertIsArray($example, 'the example context is not valid JSON');
        $this->assertArrayHasKey('audience', $example);

        return $example;
    }

    /**
     * The example carries exactly the keys the panel exports.
     */
    public function test_the_example_context_matches_what_the_panel_exports(): void {
        global $PAGE;

        $this->resetAfterTest();

        $exported = array_keys((new audience_panel(null))->export_for_template($PAGE->get_renderer('core'))['audience']);
        $documented = array_keys($this->example()['audience']);
        sort($exported);
        sort($documented);

        // Control: the export is not empty, so equality below cannot hold over two empty lists.
        $this->assertContains('initialstate_idle', $exported);
        $this->assertSame($exported, $documented);
    }

    /**
     * Rendering the example gives the state line its text.
     */
    public function test_the_example_context_renders_the_state_line(): void {
        global $OUTPUT;

        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('local_awareness/editor/audience_panel', $this->example());

        $this->assertSame(1, preg_match('/<span[^>]*data-slot="state"[^>]*>(.*?)<\/span>/s', $html, $state));
        $this->assertStringContainsString(get_string('audience:state:idle', 'local_awareness'), $state[1]);
    }
}
