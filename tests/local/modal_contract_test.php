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
 * Nothing else in the pipeline reads any of this. phpcs reads PHP, the mustache lint reads
 * structure, stylelint reads CSS, and none of them resolves a string id, compares a CSS selector
 * against the element a JS file puts the class on, or notices that a help string describes a
 * different setting. Every defect pinned here shipped on all supported branches with CI green.
 *
 * What shipped, and why prose was not enough to stop it:
 *  - The close button announced itself to screen readers as the literal "[[close]]", because
 *    get_string('close', 'core') does not exist; core's own modal uses closebuttontitle.
 *  - The dialogue carried no aria-modal and put its accessible name on the inner element rather
 *    than the one with role="dialog", so the name was never announced with the dialogue.
 *  - The only feedback telling a user that a refused backdrop click was deliberate was a CSS rule
 *    that could not match: the class went on the root, the selector asked for a .modal-dialog
 *    inside .awareness, and .awareness IS the .modal-dialog.
 *  - The plugin carried a second, weaker Tab trap on top of core's FocusLock.
 *  - The close button was actuated through a document-wide [data-action="close"] selector, which
 *    core also uses in tool_lp and mod_assign templates.
 *  - The acknowledgement help string promised a logout that the setting does not perform, and the
 *    checkbox label made the same claim before JavaScript corrected it.
 *
 * Every scan here asserts it found something before it asserts anything about what it found. A
 * pattern that silently stops matching is the failure mode these tests exist to prevent, and it
 * has already happened once in this repository's history to a sweep written with confidence.
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
     * Swept rather than listed: a template added to a new subdirectory is then covered by default,
     * which is the failure an inclusion list produces silently.
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
     * A missing id does not throw and does not warn. get_string() returns the literal
     * "[[identifier]]", so it renders into the page — and when the sink is an aria-label, the only
     * person who ever hears it is the one least able to report it. That is exactly how
     * aria-label="[[close]]" survived on 4.5, 5.1 and 5.2 at once.
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
     * Assistive technology announces the name of the element holding the dialogue role. Core puts
     * both attributes there (lib/templates/modal.mustache); this template used to put the name one
     * level in, on the role="document" element, where it is never announced as the dialogue's name.
     * That matters most for a notice that deliberately cannot be escaped by clicking away.
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
     * CAPTURE phase — so a jQuery handler here always runs second, on a key core has already acted
     * on. The copy this plugin carried also matched a narrower set of elements than core's: it
     * could not reach a select, a textarea, or anything with tabindex inside a notice body, all of
     * which an author can put there through the content editor.
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
     * [data-action="close"] is not private to this plugin. Core matches it in
     * admin/tool/lp/templates/scale_configuration_page.mustache and in mod_assign's grading filter
     * dropdown, on every supported branch — so an unscoped trigger fired while a notice sits over
     * one of those pages also actuates the other control.
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
            'SELECTORS.CLOSE_BUTTON).trigger(',
            $js,
            'The refused-exit paths must still route through the close button so the dismissal is recorded.'
        );
    }

    /**
     * The class the JS toggles for the refused-click animation is the class the stylesheet animates.
     *
     * The JS puts jelly-anim on the element that must move. A selector that asks for a descendant
     * after it therefore cannot match, which is what shipped: the class went on getRoot() and the
     * rule read `.awareness.jelly-anim .modal-dialog`, while `awareness` is itself the
     * .modal-dialog. The user got no signal that their click had been refused on purpose.
     *
     * @return void
     */
    public function test_the_refused_click_animation_can_actually_match(): void {
        $css = $this->read('styles.css');
        $js = $this->read('amd/src/modal_notice.js');

        $this->assertStringContainsString("addClass('jelly-anim')", $js, 'The refused-click feedback is gone from the JS.');

        // Comments are stripped first: this file documents the defect in prose beside the rule that
        // fixes it, and a scan that reads the prose as a selector reports the explanation as the bug.
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
     * Requiring acknowledgement and ending the session are independent settings, and only the
     * second ends a session. Both packs described the first as doing the second, so an author who
     * read the help believed they had already asked for a logout. The checkbox label made the same
     * claim to the reader before JavaScript replaced it — and if that request lost, the claim stood.
     *
     * @return array Language directory => list of forbidden substrings, lowercased.
     */
    public static function logout_claim_provider(): array {
        return [
            'en' => ['en', ['log you off', 'logged out', 'log out', 'logged off']],
            'pt_br' => ['pt_br', ['logout', 'desconectado', 'desconectar']],
        ];
    }

    /**
     * The acknowledgement strings describe acknowledgement, not session termination.
     *
     * @dataProvider logout_claim_provider
     * @param string $lang The language directory under lang/.
     * @param array $forbidden Lowercased substrings that would claim a logout.
     * @return void
     */
    public function test_acknowledgement_strings_do_not_promise_a_logout(string $lang, array $forbidden): void {
        $string = [];
        require($this->plugin_root() . "/lang/{$lang}/local_awareness.php");

        $guarded = ['notice:reqack_help', 'modal:checkboxtext'];
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

        // Control: the forbidden vocabulary must still be able to fire, or this test proves nothing.
        $this->assertArrayHasKey('notice:forcelogout_help', $string, "lang/{$lang} is missing the control key.");
        $control = \core_text::strtolower($string['notice:forcelogout_help']);
        $hits = 0;
        foreach ($forbidden as $claim) {
            $hits += substr_count($control, $claim);
        }
        $this->assertGreaterThan(
            0,
            $hits,
            "None of the forbidden phrases appears in lang/{$lang} notice:forcelogout_help, so the vocabulary this "
                . 'test searches for no longer matches how the pack talks about logging out, and the assertions above '
                . 'cannot fail. Update the phrase list.'
        );
    }
}
