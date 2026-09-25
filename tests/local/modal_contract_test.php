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
 * Guards the notice dialogue's accessibility contract and the claims its strings make.
 *
 * phpcs, the mustache lint and stylelint never resolve a string id, compare a CSS selector with
 * the element a JS file puts the class on, or read what a help string promises. Pinned here:
 *  - every string id a template asks for exists;
 *  - aria-modal and the accessible name sit on the element with role="dialog";
 *  - Tab is left to core's FocusLock, with no second trap in the plugin;
 *  - the close button is actuated through a selector scoped to the dialogue, and pressed once;
 *  - the refused-click animation class is on the element the stylesheet animates;
 *  - neither language pack claims that acknowledgement logs the reader out.
 *
 * Every scan asserts it found something before it asserts anything about what it found.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper
 */
final class modal_contract_test extends \basic_testcase {
    /**
     * Plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Read one file from the plugin root.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string The file contents.
     */
    private function read(string $relative): string {
        $path = $this->plugin_root() . '/' . $relative;
        $this->assertFileExists($path, "Expected {$relative} to exist.");

        return (string) file_get_contents($path);
    }

    /**
     * Every Mustache template the plugin ships.
     *
     * Swept rather than listed, so a template added to a new subdirectory is covered by default.
     *
     * @return array Relative path => contents.
     */
    private function templates(): array {
        $root = $this->plugin_root() . '/templates';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'mustache') {
                $found[substr($file->getPathname(), strlen($this->plugin_root()) + 1)] = file_get_contents($file->getPathname());
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Every string id a template asks core or this plugin to resolve actually exists.
     *
     * A missing id does not throw: get_string() returns the literal "[[identifier]]", warning only
     * at developer debug level, so it renders into the page. In an aria-label only a screen reader
     * user ever hears it. Core has no 'close' string, for example; its modal uses closebuttontitle.
     *
     * @return void
     */
    public function test_every_template_string_id_resolves(): void {
        $sm = get_string_manager();
        $checked = 0;

        foreach ($this->templates() as $relative => $contents) {
            preg_match_all('/\{\{#(str|cleanstr)\}\}(.*?)\{\{\/\1\}\}/s', $contents, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $parts = explode(',', $match[2]);
                $identifier = trim($parts[0]);
                $component = isset($parts[1]) ? trim($parts[1]) : 'core';
                $checked++;
                $this->assertTrue(
                    $sm->string_exists($identifier, $component),
                    "{$relative} asks for the string '{$identifier}' from '{$component}', which does not exist. "
                        . "get_string() will render the literal [[{$identifier}]] rather than failing."
                );
            }
        }

        // The scan is worthless if the pattern stopped matching; prove it found the references.
        $this->assertGreaterThanOrEqual(10, $checked, 'The template string scan matched too little to be meaningful.');
    }

    /**
     * The dialogue's accessible name and aria-modal sit on the element that carries role="dialog".
     *
     * Assistive technology announces the name of the element holding the dialogue role, as core's
     * lib/templates/modal.mustache does; a name on the inner role="document" element is never
     * announced as the dialogue's.
     *
     * @return void
     */
    public function test_the_dialogue_element_carries_its_name_and_aria_modal(): void {
        $template = $this->read('templates/modal_notice.mustache');

        $found = preg_match('/<div\b[^>]*role="dialog"[^>]*>/', $template, $matches);
        $this->assertSame(1, $found, 'No element with role="dialog" found in the notice template.');

        $element = $matches[0];
        $this->assertStringContainsString('aria-modal="true"', $element, 'The role="dialog" element needs aria-modal="true".');
        $this->assertMatchesRegularExpression(
            '/aria-labelledby="[^"]+"/',
            $element,
            'The role="dialog" element needs aria-labelledby, or the dialogue is announced with no name.'
        );
    }

    /**
     * The plugin does not reimplement the Tab focus trap that core already installs.
     *
     * core/modal calls FocusLock.trapFocus() from attachToDOM(), and focuslock binds keydown in the
     * capture phase, so a jQuery handler here always runs second, on a key core has already acted
     * on.
     *
     * @return void
     */
    public function test_the_plugin_does_not_duplicate_cores_focus_trap(): void {
        $js = $this->read('amd/src/modal_notice.js');

        $this->assertStringNotContainsString(
            'handleTabLock',
            $js,
            'core/modal already traps Tab through FocusLock, in the capture phase. A second trap fights it.'
        );
        $this->assertStringNotContainsString(
            'KeyCodes.tab',
            $js,
            'Tab belongs to core/modal FocusLock. Handling it here means two handlers move focus for one keypress.'
        );

        // Control: the file must still handle Escape, or this test passes against a deleted file.
        $this->assertStringContainsString('KeyCodes.escape', $js, 'The dialogue must still decide what Escape does.');
    }

    /**
     * The close button is never actuated through an unscoped, document-wide selector.
     *
     * [data-action="close"] is not private to this plugin: core uses it in
     * admin/tool/lp/templates/scale_configuration_page.mustache and in mod_assign's grading filter
     * dropdown, so an unscoped trigger fired while a notice sits over one of those pages also
     * actuates the other control.
     *
     * @return void
     */
    public function test_the_close_button_is_actuated_within_the_modal_only(): void {
        $js = $this->read('amd/src/modal_notice.js');

        $this->assertStringNotContainsString(
            '$(SELECTORS.CLOSE_BUTTON)',
            $js,
            'Scope the close button to the dialogue (getModal().find(...)); core uses this selector too.'
        );

        // Control: the button must still be actuated somewhere, or the assertion above is free.
        $this->assertStringContainsString(
            'getModal().find(SELECTORS.CLOSE_BUTTON).first().trigger(',
            $js,
            'The refused-exit paths must still route through the close button so the dismissal is recorded.'
        );
    }

    /**
     * A backdrop click or Escape presses one close button, not every one the selector matches.
     *
     * jQuery's trigger() clicks every element in the collection, and the selector matches the header
     * cross, the footer Close and Not now, so an unnarrowed trigger runs the close handler once per
     * button. The reader's queue survives that only because of its in-flight guard; the previews,
     * only because the first destroy() strips the handlers from the rest.
     *
     * @return void
     */
    public function test_each_exit_path_presses_one_close_button(): void {
        $js = $this->read('amd/src/modal_notice.js');
        $template = $this->read('templates/modal_notice.mustache');

        // The force: the template really carries several buttons the selector matches.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($template, 'data-action="close"'),
            'The template no longer carries several close buttons, so this test guards nothing.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/SELECTORS\.CLOSE_BUTTON\)\s*\.trigger\(/',
            $js,
            'A trigger() on every close button runs the close handler once per button.'
        );

        // Both exit paths go through the one helper that narrows the collection.
        $start = strpos($js, 'ModalNotice.prototype.registerEventListeners = function()');
        $this->assertNotFalse($start, 'registerEventListeners() is gone, so the scan below would pass blind.');
        $end = strpos($js, "\n        };", $start);
        $this->assertNotFalse($end, 'registerEventListeners() has no end at its indent.');
        $this->assertSame(
            2,
            substr_count(substr($js, $start, $end - $start), 'pressClose('),
            'The backdrop and the Escape handler must each press the close button through pressClose().'
        );

        // Moodle serves amd/build, so the fix only counts once the bundle carries it.
        $this->assertStringContainsString(
            '.first().trigger("click")',
            $this->read('amd/build/modal_notice.min.js'),
            'amd/build/modal_notice.min.js predates the single press: rebuild it.'
        );
    }

    /**
     * The class the JS toggles for the refused-click animation is the class the stylesheet animates.
     *
     * The JS puts jelly-anim on the element that must move, so a rule styling a descendant of it
     * cannot match: `.awareness.jelly-anim .modal-dialog` finds nothing, because .awareness is
     * itself the .modal-dialog. Without the animation the reader gets no sign that the backdrop
     * click was refused on purpose.
     *
     * @return void
     */
    public function test_the_refused_click_animation_can_actually_match(): void {
        $css = $this->read('styles.css');
        $js = $this->read('amd/src/modal_notice.js');

        $this->assertStringContainsString("addClass('jelly-anim')", $js, 'The refused-click feedback is gone from the JS.');

        // Comments are stripped first: the stylesheet's prose beside the rule mentions jelly-anim,
        // and the scan must not read that prose as a selector.
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/^([^{}\n][^{}]*jelly-anim[^{}]*)\{/m', $rules, $matches);
        $selectors = array_map('trim', $matches[1]);
        $this->assertNotEmpty($selectors, 'No stylesheet rule targets jelly-anim, so the animation never plays.');

        foreach ($selectors as $selector) {
            $rightmost = trim((string) strrchr(' ' . $selector, ' '));
            $this->assertStringContainsString(
                'jelly-anim',
                $rightmost,
                "The rule '{$selector}' puts jelly-anim on an ancestor of the element it styles, but the JS puts the "
                    . 'class on the element itself, so this rule can never match.'
            );
        }
    }

    /**
     * Neither language pack claims that acknowledgement logs the reader out.
     *
     * Acknowledgement records the reader's consent and nothing else: the plugin never ends a
     * session, so a help text or checkbox label promising a logout misleads authors and readers.
     *
     * Each row also carries a sample: a sentence of the kind a drifting pack would plausibly
     * contain, written out literally rather than assembled from the phrase list, so the control at
     * the end of the test can fail.
     *
     * @return array Language directory => list of forbidden substrings, plus an offending sample.
     */
    public static function logout_claim_provider(): array {
        return [
            'en' => [
                'en',
                ['log you off', 'logged out', 'log out', 'logged off'],
                'If you do not accept this notice, you will be logged out of the site.',
            ],
            'pt_br' => [
                'pt_br',
                ['logout', 'desconectado', 'desconectar'],
                'Se você não aceitar o alerta, será desconectado do site.',
            ],
        ];
    }

    /**
     * The acknowledgement strings describe acknowledgement, not session termination.
     *
     * @dataProvider logout_claim_provider
     * @param string $lang The language directory under lang/.
     * @param array $forbidden Lowercased substrings that would claim a logout.
     * @param string $sample A sentence that does claim one, written independently of that list.
     * @return void
     */
    public function test_acknowledgement_strings_do_not_promise_a_logout(
        string $lang,
        array $forbidden,
        string $sample
    ): void {
        $string = [];
        require($this->plugin_root() . "/lang/{$lang}/local_awareness.php");

        $guarded = ['notice:insistence_help', 'modal:checkboxtext'];
        foreach ($guarded as $key) {
            $this->assertArrayHasKey($key, $string, "lang/{$lang} is missing the guarded key {$key}.");
            $value = \core_text::strtolower($string[$key]);
            foreach ($forbidden as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $value,
                    "lang/{$lang} string '{$key}' claims a logout ('{$claim}'), which this setting does not perform."
                );
            }
        }

        /*
         * Control: the phrase list must be able to catch a pack that drifts. $sample is a literal
         * sentence, not built from the list, so emptying or misspelling the list fails here
         * instead of leaving the assertions above unable to fail.
         */
        $lowered = \core_text::strtolower($sample);
        $hits = 0;
        foreach ($forbidden as $claim) {
            $hits += substr_count($lowered, $claim);
        }
        $this->assertGreaterThan(
            0,
            $hits,
            "None of the phrases this test searches for appears in a sentence that plainly claims a logout "
                . "(\"{$sample}\"). The list no longer matches how lang/{$lang} would express it, so the "
                . 'assertions above cannot fail. Update the phrase list.'
        );
    }
}
