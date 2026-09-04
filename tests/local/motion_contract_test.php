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
 * The dialogue's motion and layout rules, pinned in the stylesheet and the JavaScript.
 *
 * Nothing in the pipeline reads a stylesheet for meaning: stylelint checks syntax, and the
 * refused-click shake shipped for a year with no reduced-motion guard while the file's only
 * such block guarded a spinner. So the contract is scanned here. Every scan asserts it found
 * something before it asserts anything about what it found.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\persistent\awareness
 */
final class motion_contract_test extends \basic_testcase {
    /**
     * The plugin's stylesheet, comments stripped.
     *
     * @return string
     */
    private function css(): string {
        $css = file_get_contents(dirname(__DIR__, 2) . '/styles.css');
        $this->assertNotFalse($css);

        return preg_replace('~/\*.*?\*/~s', '', $css);
    }

    /**
     * A source file of the plugin.
     *
     * @param string $relative Path from the plugin root.
     * @return string
     */
    private function read(string $relative): string {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $this->assertNotFalse($contents, "Could not read {$relative}");

        return $contents;
    }

    /**
     * The rule blocks of a stylesheet chunk, as selector => declarations.
     *
     * @param string $css The chunk.
     * @return array
     */
    private function rules(string $css): array {
        $rules = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $rules[trim($match[1])] = trim($match[2]);
        }

        return $rules;
    }

    /**
     * Every entrance animation and the shake are silenced under reduced motion and on the test site.
     *
     * The reduced-motion block may collapse the entrances to a fade rather than nothing; the test
     * site gets none, because WebDriver clicks where the box was a frame ago.
     */
    public function test_every_dialogue_animation_has_both_motion_guards(): void {
        $css = $this->css();

        preg_match_all('/\.awareness(?:\.[a-z0-9-]+)*\s*\{[^}]*\banimation\s*:\s*la-enter-[^;]+;/', $css, $matches);
        $animated = $matches[0];
        $this->assertGreaterThanOrEqual(5, count($animated), 'Found too few entrance rules for the scan to mean anything.');

        // Every reduced-motion block in the file, joined: the spinner has one of its own, earlier.
        preg_match_all('/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{(.*?)\}\s*\}/s', $css, $blocks);
        $reduced = implode("\n", $blocks[1]);
        $this->assertNotEmpty($reduced, 'No prefers-reduced-motion block in styles.css.');
        $this->assertMatchesRegularExpression(
            '/\.awareness\[class\*="la-anim-"\]\s*\{[^}]*animation\s*:\s*(none|la-enter-fade)/',
            $reduced,
            'The reduced-motion block does not collapse the entrance animations.'
        );
        $this->assertMatchesRegularExpression(
            '/\.awareness\.jelly-anim\s*\{[^}]*animation\s*:\s*none/',
            $reduced,
            'The refused-click shake still plays under reduced motion.'
        );

        $this->assertMatchesRegularExpression(
            '/body\.behat-site\s+\.awareness\[class\*="la-anim-"\][^{]*\{[^}]*animation\s*:\s*none/',
            $css,
            'The test site still gets the entrance animations.'
        );
        $this->assertMatchesRegularExpression(
            '/body\.behat-site[^{]*\.awareness\.jelly-anim[^{]*\{[^}]*animation\s*:\s*none/',
            $css,
            'The test site still gets the refused-click shake.'
        );

        // Every declared entrance is at least 200 ms: anything shorter reads as a flicker.
        foreach ($animated as $rule) {
            preg_match('/animation\s*:\s*la-enter-[a-z-]+\s+(\d+)ms/', $rule, $duration);
            $this->assertNotEmpty($duration, "Entrance rule without a millisecond duration: {$rule}");
            $this->assertGreaterThanOrEqual(200, (int) $duration[1], $rule);
        }
    }

    /**
     * The stylesheet has a rule for every layout, position and animation the persistent allows.
     *
     * A value the CSS has no rule for renders as the default and the stored choice lies. The
     * classic layout, the centre position and the "none" animation are the defaults and need no
     * rule of their own.
     */
    public function test_the_stylesheet_knows_every_vocabulary_value(): void {
        $css = $this->css();

        foreach (array_diff(awareness::TEMPLATES, ['classic']) as $template) {
            $this->assertStringContainsString(".awareness.la-tpl-{$template}", $css, "No rule for the {$template} layout.");
        }
        foreach (array_diff(awareness::POSITIONS, ['center']) as $position) {
            $this->assertStringContainsString(".awareness.la-pos-{$position}", $css, "No rule for the {$position} position.");
        }
        $this->assertStringContainsString('.awareness.la-pos-center', $css, 'The centre is a real centre, and needs its rule.');
        foreach (array_diff(awareness::ANIMATIONS, ['none']) as $animation) {
            $this->assertStringContainsString(".awareness.la-anim-{$animation}", $css, "No rule for the {$animation} entrance.");
        }
    }

    /**
     * No layout removes the header from the DOM: it holds the close button and the dialogue's name.
     *
     * aria-labelledby points at the title inside the header, so display:none on it would strip the
     * dialogue's accessible name in that layout. A layout may strip the header's chrome, not the
     * element.
     */
    public function test_no_layout_hides_the_header(): void {
        $rules = $this->rules($this->css());
        $checked = 0;
        foreach ($rules as $selector => $declarations) {
            if (!str_contains($selector, '.la-tpl-') || !str_contains($selector, '.modal-header')) {
                continue;
            }
            $checked++;
            $this->assertDoesNotMatchRegularExpression(
                '/\bdisplay\s*:\s*none\b/',
                $declarations,
                "{$selector} removes the header, and the dialogue's name with it."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bvisibility\s*:\s*hidden\b/',
                $declarations,
                "{$selector} hides the header, and the close button with it."
            );
        }
        $this->assertGreaterThan(0, $checked, 'No layout rule touches the header, so the scan checked nothing.');

        // The header is also the only home of the header close button in the template.
        $template = $this->read('templates/modal_notice.mustache');
        $this->assertMatchesRegularExpression(
            '/<div class="modal-header[^>]*data-region="header">.*?id="awareness-closebtn".*?<\/div>\s*'
                . '(?:\{\{!.*?\}\}\s*)?<div class="la-media"/s',
            $template,
            'The header close button has moved out of the header.'
        );
    }

    /**
     * The JavaScript's own copies of the vocabulary agree with the persistent.
     *
     * notice_form.js cannot reach PHP, so it carries the corners and the corner layout by hand;
     * notice.js carries the layouts that honour an author-set size. A value added to the
     * persistent without these being updated would be greyed or sized wrong in silence.
     */
    public function test_the_javascript_mirrors_agree_with_the_persistent(): void {
        $formjs = $this->read('amd/src/notice_form.js');
        preg_match('/var CORNERS = \[([^\]]+)\]/', $formjs, $corners);
        $this->assertNotEmpty($corners, 'notice_form.js no longer declares CORNERS.');
        preg_match_all("/'([a-z-]+)'/", $corners[1], $values);
        $this->assertEqualsCanonicalizing(awareness::POSITIONS_CORNER, $values[1]);
        $this->assertMatchesRegularExpression("/var CORNER_LAYOUT = 'card'/", $formjs);

        $modaljs = $this->read('amd/src/modal_notice.js');
        preg_match('/SIZED: \[([^\]]+)\]/', $modaljs, $sized);
        $this->assertNotEmpty($sized, 'modal_notice.js no longer declares the SIZED layouts.');
        preg_match_all("/'([a-z]+)'/", $sized[1], $values);
        foreach ($values[1] as $template) {
            $this->assertContains(
                $template,
                awareness::TEMPLATES,
                "modal_notice.js sizes a layout the persistent does not know: {$template}"
            );
        }
        $this->assertNotContains('card', $values[1], 'a card sizes itself');
        $this->assertNotContains('fullscreen', $values[1], 'a fullscreen dialogue sizes itself');

        preg_match('/COMPACT: \[([^\]]+)\]/', $modaljs, $compact);
        $this->assertNotEmpty($compact, 'modal_notice.js no longer declares the COMPACT layouts.');
        preg_match_all("/'([a-z]+)'/", $compact[1], $values);
        $this->assertSame(['card'], $values[1], 'the card is the one layout narrower than the large dialogue');
    }

    /**
     * The picker's state rules read the sibling core actually renders.
     *
     * A grouped radio is <label><input> ...</label> (element-radio-inline.mustache, 4.5 and 5.2):
     * the option template is the input's next sibling and the label is its parent. So the only
     * combinator that reaches the option from the radio's state is "+ .la-layout-option", and a
     * rule written "+ label" is dead, which is how the chosen layout shipped with no mark at all.
     * The markup half of this contract is tests/form/picker_render_test.php.
     */
    public function test_the_picker_state_rules_read_the_radios_next_sibling(): void {
        $states = [];
        foreach (array_keys($this->rules($this->css())) as $selector) {
            foreach (explode(',', $selector) as $part) {
                $part = trim($part);
                if (preg_match('/input\[name="(template|position)"\][^\s+~]*\s*[+~]\s*label\b/', $part)) {
                    $this->fail("{$part} reaches for a label after the radio; core puts the radio inside its label");
                }
                if (preg_match('/input\[name="template"\]:(checked|focus-visible)/', $part)) {
                    $states[] = $part;
                    $this->assertMatchesRegularExpression(
                        '/:(checked|focus-visible)\s*\+\s*\.la-layout-option/',
                        $part,
                        'a picker state rule does not read the option as the radio\'s next sibling'
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(2, count($states), 'the picker has no state rules at all; the scan is blind');
    }

    /**
     * No rule sets display on one of Bootstrap's display utilities.
     *
     * .d-flex is display: flex !important on both branches, and the plugin may not write !important
     * (stylelint), so such a declaration can never win: the position grid was display: grid on
     * core's .d-flex and never applied. Any layout change on such an element goes on its children
     * or on its own geometry (width, gap), never on its display.
     */
    public function test_no_rule_sets_display_on_a_bootstrap_display_utility(): void {
        $utilities = 0;
        $offenders = [];
        foreach ($this->rules($this->css()) as $selector => $declarations) {
            foreach (explode(',', $selector) as $part) {
                if (!preg_match('/\.d-(flex|inline-flex|block|inline-block|inline|none|grid)$/', trim($part))) {
                    continue;
                }
                $utilities++;
                if (preg_match('/(^|;)\s*display\s*:/', $declarations)) {
                    $offenders[] = trim($part);
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $utilities, 'no rule targets a display utility; the scan is blind');
        $this->assertSame([], $offenders, 'these rules set display on an element whose display core declares !important');
    }
}
