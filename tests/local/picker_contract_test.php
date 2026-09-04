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

/**
 * Source contracts for the competency picker's message rendering and its label plumbing.
 *
 * Nothing in the pipeline reads a JS string literal. phpcs reads PHP, the mustache lint reads
 * structure, stylelint reads CSS, and eslint has no opinion about which sink a translated string
 * lands in — so these rules are enforced here or not at all, the same argument that produced
 * bootstrap_compat_test and criteria_contract_test.
 *
 * The defect class: the picker builds its messages from language strings the server renders into
 * data-* attributes, and it used to concatenate them into innerHTML. A translator's ampersand then
 * renders wrong and an angle bracket swallows the rest of the fragment. Two of core's own sinks —
 * Modal.setTitle(), which ends in jQuery .html(), and Notification.addNotification(), which renders
 * through a triple stash — take HTML and cannot be handed a text node, so those are escaped once
 * on the way in instead.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\form\notice_form
 */
final class picker_contract_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Read one AMD source file, with comments stripped.
     *
     * Comments are removed because these assertions are about what the code DOES. A docblock that
     * quotes the very literal an assertion forbids would otherwise fail the test for explaining
     * itself, which is how a rule gets weakened to keep a prose sentence.
     *
     * @param string $module Module file name, e.g. "notice_form.js".
     * @return string File contents with block and line comments removed.
     */
    private function amd_code(string $module): string {
        $path = $this->plugin_root() . '/amd/src/' . $module;
        $this->assertFileExists($path, "amd/src/{$module} has been renamed — this test is now blind, not passing.");

        $source = file_get_contents($path);
        $source = preg_replace('!/\*.*?\*/!s', '', $source);

        return preg_replace('!^\s*//.*$!m', '', $source);
    }

    /**
     * No user-facing message is built by concatenating a label into innerHTML.
     *
     * Scoped to assignment lines, and paired with a non-vacuity guard: a scan that found no
     * innerHTML at all would satisfy the rule while proving nothing.
     *
     * @return void
     */
    public function test_no_label_is_concatenated_into_innerhtml(): void {
        $code = $this->amd_code('notice_form.js');

        $assignments = [];
        $offenders = [];
        foreach (explode("\n", $code) as $line) {
            if (!str_contains($line, 'innerHTML')) {
                continue;
            }
            $assignments[] = trim($line);
            if (str_contains($line, '+') || preg_match('/innerHTML\s*=\s*\'.*[A-Za-z]{3}/', $line)) {
                $offenders[] = trim($line);
            }
        }

        $this->assertNotEmpty($assignments, 'no innerHTML use found at all — the scan is broken, not the code');
        $this->assertSame([], $offenders, 'a message is being concatenated into innerHTML instead of written as text');
    }

    /**
     * The two core sinks that render raw HTML receive escaped text.
     *
     * Modal.setTitle() ends in jQuery .html() and notification_base.mustache renders
     * {{{ message }}} — identical on 4.5 and 5.2 — so neither can take a text node. Both are fed
     * language strings here, so both are escaped exactly once.
     *
     * @return void
     */
    public function test_the_raw_html_sinks_are_escaped(): void {
        $code = $this->amd_code('notice_form.js');

        foreach (['Notification.addNotification', 'ModalSaveCancel.create'] as $sink) {
            $this->assertStringContainsString(
                $sink,
                $code,
                "{$sink} is gone from the module — this assertion is now blind, not passing."
            );
        }

        $bare = [];
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/^\s*(message|title):\s*labels\./', $line)) {
                $bare[] = trim($line);
            }
        }

        $this->assertSame([], $bare, 'a label reaches a raw-HTML sink without being escaped');
        $this->assertStringContainsString('var escapeText =', $code, 'the escape helper is gone');
    }

    /**
     * Every data-* label the module reads is rendered by the form that owns the container.
     *
     * The map lives in JS and the markup lives in PHP, and nothing else checks that they agree, so
     * a label added on one side and forgotten on the other reaches the user as the English
     * fallback baked into the module.
     *
     * Two flags are excluded deliberately — data-initialized and data-awareness-bound — because
     * they are the module's own idempotency markers, written and read by the JS. The server side
     * is the form OR a Mustache template: the picker's own rows carry their competency id and
     * index from local_awareness/competency_picker_items, not from the form.
     *
     * @return void
     */
    public function test_every_label_the_module_reads_is_rendered_by_the_form(): void {
        $code = $this->amd_code('notice_form.js');

        $rendered = file_get_contents($this->plugin_root() . '/classes/form/notice_form.php');
        foreach (glob($this->plugin_root() . '/templates/*.mustache') as $template) {
            $rendered .= file_get_contents($template);
        }

        preg_match_all("/getAttribute\('(data-[a-z-]+)'\)/", $code, $found);
        $internal = ['data-initialized', 'data-awareness-bound'];
        $read = array_values(array_unique(array_diff($found[1], $internal)));
        sort($read);

        $this->assertGreaterThan(5, count($read), 'the module reads implausibly few data-* attributes');

        $missing = [];
        foreach ($read as $attribute) {
            if (!str_contains($rendered, $attribute . '="')) {
                $missing[] = $attribute;
            }
        }

        $this->assertSame([], $missing, 'the module reads these attributes but nothing on the server renders them');
    }

    /**
     * Every message label the form renders is fetched from a language pack, not written inline.
     *
     * @return void
     */
    public function test_the_form_renders_its_labels_from_language_strings(): void {
        $php = file_get_contents($this->plugin_root() . '/classes/form/notice_form.php');

        $start = strpos($php, 'awareness-competency-filter');
        $this->assertNotFalse($start, 'the competency container is gone — this assertion is now blind.');
        $block = substr($php, $start, 2200);

        /*
         * EVERY data-* attribute in the block is enumerated first, and only then is its value form
         * checked. An earlier draft matched the well-formed shape directly, so an attribute written
         * as a hardcoded literal did not appear in the results at all and passed unexamined — the
         * scan could only see the cases that were already correct. Mutation-checked: hardcoding one
         * label now fails this test.
         */
        preg_match_all('/(data-[a-z-]+)="([^"]{0,12})/', $block, $found);
        $this->assertGreaterThan(5, count($found[1]), 'implausibly few data-* attributes in the container markup');

        $inline = [];
        foreach ($found[1] as $index => $attribute) {
            if ($attribute === 'data-contextid' || $attribute === 'data-courseid') {
                continue;
            }
            if (!str_starts_with($found[2][$index], '\' . s(get')) {
                $inline[] = $attribute;
            }
        }

        $this->assertSame([], $inline, 'these attributes carry something other than an escaped language string');
    }

    /**
     * An empty framework list under a course scope does not blame the site.
     *
     * The picker filters the frameworks down to those holding a competency LINKED TO THE COURSE, so
     * an empty list under a course scope almost always means the course has none — while the
     * site-wide string says there are no frameworks at all, which sends the author looking for
     * something the site already has. Reported from the browser on a site with two frameworks and
     * no course linked to either; nothing in the pipeline reads which label a branch picks, so the
     * rule is enforced here or not at all.
     *
     * @return void
     */
    public function test_an_empty_framework_list_in_a_course_names_the_course(): void {
        $code = $this->amd_code('notice_form.js');

        $uses = [];
        foreach (explode("\n", $code) as $line) {
            if (str_contains($line, 'labels.noFrameworks')) {
                $uses[] = trim($line);
            }
        }

        $this->assertNotEmpty($uses, 'the empty-framework message is gone — this assertion is now blind.');
        foreach ($uses as $line) {
            $this->assertStringContainsString(
                'noCourseLinked',
                $line,
                'the site-wide message is used without the course case beside it'
            );
        }
        $this->assertStringContainsString(
            "getAttribute('data-picker-nocourselinked')",
            $code,
            'the course message is not read from the form'
        );
    }

    /**
     * The preview modal chain reports a failure instead of dying silently.
     *
     * @return void
     */
    public function test_the_preview_chain_is_terminated(): void {
        $code = $this->amd_code('preview.js');

        // The real notice dialogue, since the preview started rendering layouts; not a plain cancel modal.
        $this->assertStringContainsString('ModalNotice.create', $code, 'the preview modal is gone — assertion blind.');
        $this->assertStringContainsString('.catch(', $code, 'the preview chain has no rejection path');
        $this->assertStringContainsString('core/notification', $code, 'core/notification is not required');
    }

    /**
     * Every selector the dialogue declares is used, and every one it uses is declared.
     *
     * The block is sliced before it is swept: a naive scan of the file picks up the ATTRIBUTE map
     * beside it, which sits at the same indent and is read under a different name.
     *
     * @return void
     */
    public function test_no_declared_selector_is_dead(): void {
        $code = $this->amd_code('modal_notice.js');

        $start = strpos($code, 'var SELECTORS = {');
        $this->assertNotFalse($start, 'SELECTORS is gone from the dialogue — this assertion is now blind.');
        $rest = substr($code, $start);
        $end = strpos($rest, '};');
        $this->assertNotFalse($end, 'the SELECTORS block is unterminated — the sweep would read past it.');
        $block = substr($rest, 0, $end);

        preg_match_all('/^\s+([A-Z_]+):/m', $block, $found);
        $this->assertGreaterThan(3, count($found[1]), 'the SELECTORS slice found implausibly few entries');

        $dead = [];
        foreach ($found[1] as $key) {
            if (substr_count($code, 'SELECTORS.' . $key) < 1) {
                $dead[] = $key;
            }
        }

        $this->assertSame([], $dead, 'these selectors are declared and never used');
    }
}
