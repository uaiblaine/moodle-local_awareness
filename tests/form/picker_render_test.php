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

namespace local_awareness\form;

use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;

/**
 * The rendered picker has the shape the stylesheet reads.
 *
 * Core renders a grouped radio as <label><input> ...</label>, the input inside its label, on 4.5
 * and 5.2 alike, and every state rule of the picker is written against that: the option is the
 * input's next sibling. The first version read "input + label", which matches nothing in that
 * markup, and with the radio hidden the chosen layout showed no mark at all. Nothing in the
 * pipeline checks a selector against the markup it was written for, so this test renders the form
 * and asserts the shape; motion_contract_test asserts the stylesheet reads exactly that shape.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\form\notice_form
 */
final class picker_render_test extends \advanced_testcase {
    /**
     * The form for a new site notice, rendered as the administrator, as a queryable document.
     *
     * @return \DOMXPath
     */
    private function render(): \DOMXPath {
        global $PAGE;

        $this->setAdminUser();
        $PAGE->set_url('/local/awareness/editnotice.php');

        $form = new notice_form(null, ['persistent' => null, 'id' => 0, 'scope' => author_scope::site()]);
        $html = $form->render();
        $this->assertStringContainsString('fgroup_id_templategroup', $html, 'the layout group is gone; this test is blind');

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    /**
     * The first element after a node, skipping the whitespace the template leaves between them.
     *
     * @param \DOMNode $node The node.
     * @return \DOMElement|null
     */
    private function next_element(\DOMNode $node): ?\DOMElement {
        $sibling = $node->nextSibling;
        while ($sibling !== null && !($sibling instanceof \DOMElement)) {
            $sibling = $sibling->nextSibling;
        }

        return $sibling;
    }

    /**
     * Every layout radio sits inside its label with the option as its next sibling, and one is chosen.
     */
    public function test_each_layout_option_is_the_next_sibling_of_its_radio_inside_its_label(): void {
        $this->resetAfterTest();
        $xpath = $this->render();

        foreach (awareness::TEMPLATES as $template) {
            $radios = $xpath->query('//input[@type="radio"][@name="template"][@value="' . $template . '"]');
            $this->assertSame(1, $radios->length, "no radio for the {$template} layout");
            $radio = $radios->item(0);
            $this->assertSame('label', $radio->parentNode->nodeName, "{$template}: the radio is not inside its label");

            $option = $this->next_element($radio);
            $this->assertNotNull($option, "{$template}: nothing follows the radio");
            $classes = preg_split('/\s+/', trim($option->getAttribute('class')));
            $this->assertContains('la-layout-option', $classes, "{$template}: the option is not the radio's next sibling");
            $this->assertContains('la-layout-option--' . $template, $classes, "{$template}: the option carries no layout class");
        }

        // Exactly one is chosen from the start, so the mark the stylesheet draws has a radio to draw it on.
        $checked = $xpath->query('//input[@name="template"][@checked]');
        $this->assertSame(1, $checked->length);
        $this->assertSame(awareness::TEMPLATES[0], $checked->item(0)->getAttribute('value'));
    }

    /**
     * Every layout card carries the sentence that says what the layout is for.
     *
     * The sentences live in the help text too, where they are read by whoever opens a help icon
     * while comparing six thumbnails — which is nobody. A card with an empty description would
     * render as a silent gap, so the text itself is asserted, not just the element.
     */
    public function test_every_layout_card_carries_its_description(): void {
        $this->resetAfterTest();
        $xpath = $this->render();

        foreach (awareness::TEMPLATES as $template) {
            $nodes = $xpath->query(
                '//span[contains(@class, "la-layout-option--' . $template . '")]//span[@class="la-layout-desc"]'
            );
            $this->assertSame(1, $nodes->length, "the {$template} card has no description element");
            $this->assertNotSame('', trim($nodes->item(0)->textContent), "the {$template} description is empty");
        }
    }

    /**
     * A position radio is drawn as a cell of the screen, and keeps its name where a reader can hear it.
     *
     * The visible text is gone — seven phrases describe a place where a picture of one shows it —
     * so the accessible name is the whole of what a screen reader has. The offscreen class is the
     * plugin's own: visually-hidden is a Bootstrap 5 name and dead on 4.5 in this surface.
     */
    public function test_every_position_radio_is_a_drawn_cell_that_keeps_its_name(): void {
        $this->resetAfterTest();
        $xpath = $this->render();

        foreach (notice_form::POSITION_GRID as $position) {
            $radios = $xpath->query('//input[@type="radio"][@name="position"][@value="' . $position . '"]');
            $this->assertSame(1, $radios->length, "no radio for the {$position} position");
            $option = $this->next_element($radios->item(0));
            $this->assertNotNull($option, "{$position}: nothing follows the radio");
            $classes = preg_split('/\s+/', trim($option->getAttribute('class')));
            $this->assertContains('la-zone', $classes, "{$position}: the cell is not the radio's next sibling");
            $this->assertContains('la-zone--' . $position, $classes, "{$position}: the cell carries no position class");

            $name = $xpath->query('.//span[@class="la-offscreen"]', $option);
            $this->assertSame(1, $name->length, "{$position}: the name is not carried offscreen");
            $this->assertSame(
                notice_form::position_name($position),
                trim($name->item(0)->textContent),
                "{$position}: the offscreen name is not the position's own"
            );
        }

        // The note that replaces the field vanishing for a fullscreen layout, present and hidden.
        $note = $xpath->query('//span[@data-region="la-position-note"]');
        $this->assertSame(1, $note->length, 'the covered-screen note is missing');
        $this->assertTrue($note->item(0)->hasAttribute('hidden'), 'the note is shown before a layout asks for it');
    }

    /**
     * The position radios come in the reading order of the grid, and the grid is the whole vocabulary.
     */
    public function test_the_position_radios_come_in_the_reading_order_of_the_grid(): void {
        $this->resetAfterTest();
        $xpath = $this->render();

        $order = [];
        foreach ($xpath->query('//input[@type="radio"][@name="position"]') as $radio) {
            $order[] = $radio->getAttribute('value');
            $this->assertSame('label', $radio->parentNode->nodeName, $radio->getAttribute('value') . ': not inside its label');
        }

        $this->assertSame(notice_form::POSITION_GRID, $order);
        $this->assertEqualsCanonicalizing(awareness::POSITIONS, notice_form::POSITION_GRID, 'the grid and the vocabulary drifted');

        $checked = $xpath->query('//input[@name="position"][@checked]');
        $this->assertSame(1, $checked->length);
        $this->assertSame(awareness::POSITIONS[0], $checked->item(0)->getAttribute('value'));
    }
}
