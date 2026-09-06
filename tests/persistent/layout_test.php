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

namespace local_awareness\persistent;

/**
 * The layout, position and animation a notice stores stay inside their vocabularies.
 *
 * Test metadata stays in docblocks while 405 is supported: moodle-cs on that leg cannot see PHP
 * attributes and reports every method of a class carrying only #[CoversClass].
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\persistent\awareness
 */
final class layout_test extends \advanced_testcase {
    /**
     * A persistent carrying the given appearance fields, otherwise valid.
     *
     * @param array $fields Property overrides.
     * @return awareness
     */
    private function notice(array $fields): awareness {
        return new awareness(0, (object) ($fields + [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
        ]));
    }

    /**
     * The three choices default to what every notice rendered as before they existed.
     */
    public function test_a_notice_written_without_choices_is_the_classic_centred_still_dialogue(): void {
        $this->resetAfterTest();

        $notice = $this->notice([]);
        $notice->create();
        $stored = new awareness($notice->get('id'));

        $this->assertSame('classic', $stored->get('template'));
        $this->assertSame('center', $stored->get('position'));
        $this->assertSame('none', $stored->get('animation'));
        $this->assertNull($stored->get('videourl'));
    }

    /**
     * Every listed value is accepted, and the lists are the vocabularies the rest of the plugin reads.
     */
    public function test_every_vocabulary_value_is_accepted(): void {
        foreach (awareness::TEMPLATES as $template) {
            $this->assertTrue($this->notice(['template' => $template])->is_valid(), "template {$template} refused");
        }
        foreach (awareness::POSITIONS as $position) {
            $this->assertTrue($this->notice(['position' => $position])->is_valid(), "position {$position} refused");
        }
        foreach (awareness::ANIMATIONS as $animation) {
            $this->assertTrue($this->notice(['animation' => $animation])->is_valid(), "animation {$animation} refused");
        }

        // The lists themselves: the default of each column heads its vocabulary.
        $this->assertSame('classic', awareness::TEMPLATES[0]);
        $this->assertSame('center', awareness::POSITIONS[0]);
        $this->assertSame('none', awareness::ANIMATIONS[0]);
    }

    /**
     * A value made of legal letters but outside the vocabulary is refused, on the property it was given for.
     *
     * The PARAM types only constrain the character set, so this is the test that proves the
     * `choices` gate exists: delete one `choices` line and exactly one assertion here reddens.
     *
     * @dataProvider out_of_vocabulary_provider
     * @param string $property The property under test.
     * @param string $value A well-formed value the vocabulary does not contain.
     */
    public function test_a_value_outside_the_vocabulary_is_refused(string $property, string $value): void {
        $notice = $this->notice([$property => $value]);

        $this->assertFalse($notice->is_valid());
        $this->assertArrayHasKey($property, $notice->get_errors());
    }

    /**
     * One misspelling per property, each a string the PARAM type would let through.
     *
     * @return array[]
     */
    public static function out_of_vocabulary_provider(): array {
        return [
            'template' => ['template', 'standard'],
            'position' => ['position', 'centre'],
            'animation' => ['animation', 'bounce'],
        ];
    }

    /**
     * A video link that is not a URL is refused; a plain https link is kept as given.
     */
    public function test_the_video_link_must_be_a_url(): void {
        $this->assertFalse($this->notice(['videourl' => 'not a url at all'])->is_valid());

        $notice = $this->notice(['videourl' => 'https://www.youtube.com/watch?v=3b1aH9K0xQ4']);
        $this->assertTrue($notice->is_valid());
        $this->assertSame('https://www.youtube.com/watch?v=3b1aH9K0xQ4', $notice->get('videourl'));
    }

    /**
     * Where each layout may sit: the corners are the card's alone, and fullscreen has no position.
     */
    public function test_the_positions_a_layout_may_take(): void {
        $this->assertSame(['center'], awareness::positions_for('fullscreen'));
        $this->assertSame(awareness::POSITIONS, awareness::positions_for('card'));
        // A banner is a strip: the two edges, never the centre nor a corner.
        $this->assertSame(awareness::POSITIONS_STRIP, awareness::positions_for('banner'));
        $this->assertSame(['top', 'bottom'], awareness::POSITIONS_STRIP);
        $this->assertNotContains('center', awareness::positions_for('banner'));

        foreach (['classic', 'hero', 'split', 'minimal', 'image', 'video', 'carousel'] as $template) {
            $this->assertSame(awareness::POSITIONS_EDGE, awareness::positions_for($template), $template);
            foreach (awareness::POSITIONS_CORNER as $corner) {
                $this->assertNotContains($corner, awareness::positions_for($template), "{$template} must not sit in {$corner}");
            }
        }
        // Every layout's positions are drawn from the vocabulary, and the strip's from the edges.
        foreach (awareness::TEMPLATES as $template) {
            $this->assertSame([], array_diff(awareness::positions_for($template), awareness::POSITIONS), $template);
        }
        $this->assertSame([], array_diff(awareness::POSITIONS_STRIP, awareness::POSITIONS_EDGE));

        // The two position lists partition the vocabulary; a position in neither would be unreachable.
        $this->assertEqualsCanonicalizing(
            awareness::POSITIONS,
            array_merge(awareness::POSITIONS_EDGE, awareness::POSITIONS_CORNER)
        );
    }

    /**
     * The levels each layout can honour: no box for the compact ones, a close and nothing else for two.
     *
     * The order of the refusing list is the picker's order, so a layout added to the vocabulary
     * without a decision here fails the first assertion rather than being offered all three.
     */
    public function test_the_insistence_levels_a_layout_can_honour(): void {
        $refusing = array_values(array_filter(awareness::TEMPLATES, static function (string $template): bool {
            return !awareness::accepts_acknowledgement($template);
        }));
        $this->assertSame(['minimal', 'card', 'banner', 'image'], $refusing);

        $all = [awareness::INSISTENCE_INFORMATIONAL, awareness::INSISTENCE_BLOCKING, awareness::INSISTENCE_ACKNOWLEDGE];
        foreach (['banner', 'image'] as $template) {
            $this->assertSame([awareness::INSISTENCE_INFORMATIONAL], awareness::insistence_levels_for($template), $template);
        }
        foreach (['card', 'minimal'] as $template) {
            $this->assertSame(
                [awareness::INSISTENCE_INFORMATIONAL, awareness::INSISTENCE_BLOCKING],
                awareness::insistence_levels_for($template),
                $template
            );
        }
        foreach (['classic', 'hero', 'split', 'fullscreen', 'video', 'carousel'] as $template) {
            $this->assertSame($all, awareness::insistence_levels_for($template), $template);
        }
        // Informational is the floor everywhere: a layout nobody could dismiss would be a layout nobody could pass.
        foreach (awareness::TEMPLATES as $template) {
            $this->assertSame(awareness::INSISTENCE_INFORMATIONAL, awareness::insistence_levels_for($template)[0], $template);
        }
    }

    /**
     * The lists the JavaScript mirrors are drawn from the vocabulary, and say what they claim.
     */
    public function test_the_compact_and_band_lists_are_drawn_from_the_vocabulary(): void {
        $this->assertSame([], array_diff(awareness::COMPACT, awareness::TEMPLATES));
        $this->assertSame([], array_diff(awareness::BAND, awareness::TEMPLATES));
        $this->assertContains('image', awareness::COMPACT, 'the image sizes itself to the picture');
        $this->assertContains('banner', awareness::COMPACT, 'the banner is a strip, not a large dialogue');
        foreach (awareness::BAND as $template) {
            $this->assertTrue(awareness::uses_bgimage($template), "{$template} paints the image, so it must use it");
        }
    }

    /**
     * Which layouts draw from the video field, and which hide the background image.
     */
    public function test_the_layouts_that_use_the_video_field_and_the_background_image(): void {
        $this->assertTrue(awareness::uses_video('video'));
        $this->assertFalse(awareness::uses_video('carousel'), 'carousel videos come from the slides, not the field');
        $this->assertFalse(awareness::uses_video('classic'));

        $this->assertFalse(awareness::uses_bgimage('video'));
        $this->assertFalse(awareness::uses_bgimage('carousel'));
        $this->assertFalse(awareness::uses_bgimage('banner'), 'a one-line strip has no room for a picture');
        foreach (['classic', 'hero', 'split', 'minimal', 'fullscreen', 'card', 'image'] as $template) {
            $this->assertTrue(awareness::uses_bgimage($template), $template);
        }

        // The image layout is the image: it needs one, and needs no text.
        foreach (awareness::TEMPLATES as $template) {
            $this->assertSame($template === 'image', awareness::requires_bgimage($template), $template);
            $this->assertSame($template !== 'image', awareness::requires_content($template), $template);
        }
    }
}
