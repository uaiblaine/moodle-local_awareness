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

use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;
use local_awareness\persistent\slide;

/**
 * Moving a slide: the press exchanges two rows and saves nothing, and the save after it stores the order shown.
 *
 * The buttons are no-submit, like core's repeat deletion, so a press is a re-render of the form
 * with two rows' values exchanged through constants. Everything a row carries has to travel — the
 * hidden id above all, because a slide's image is filed under that id — and the rows at the ends
 * of the strip have to refuse. These tests submit the editor the way a browser does, through
 * mock_submit(), and read the rendered rows back; the last one saves the rearranged rows through
 * the same path the page uses and checks what was stored.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\form\notice_form
 * @covers     \local_awareness\helper
 */
final class slide_reorder_test extends \advanced_testcase {
    /**
     * A stored carousel with three slides: an image slide, a video slide and a caption-only slide.
     *
     * @return array The notice and its slides, in stored order.
     */
    private function carousel(): array {
        $this->setAdminUser();
        helper::create_new_notice((object) [
            'title' => 'Semester news',
            'content' => '<p>News.</p>',
            'perpetual' => 1,
            'template' => 'carousel',
            'slide_caption' => ['Lab', 'Tour', 'Words'],
            'slide_videourl' => ['', 'https://youtu.be/3b1aH9K0xQ4', ''],
            'slide_image' => [$this->draft_with_image(), 0, 0],
            'slide_id' => [0, 0, 0],
        ]);
        $notice = awareness::get_record(['title' => 'Semester news']);
        $slides = slide::for_notice($notice->get('id'));
        $this->assertCount(3, $slides, 'the fixture did not store three slides; nothing below is testing a move');
        $this->assertSame('slide.png', $slides[0]->get_image()->get_filename(), 'the first slide has no image to follow it');

        return [$notice, $slides];
    }

    /**
     * A draft area of the current user holding one image.
     *
     * @return int The draft item id.
     */
    private function draft_with_image(): int {
        global $USER;

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'slide.png',
        ], 'not really a png');

        return $draftid;
    }

    /**
     * The editor, submitted as a browser would submit it for a stored carousel, with one press on top.
     *
     * Each row carries a distinct draft id, 100 plus its index, so a draft id can be seen to travel
     * with its row. A row named in $absent is left out of every array, the way a browser leaves a
     * deleted row out, and $extra is merged last.
     *
     * @param awareness $notice The notice.
     * @param slide[] $slides Its slides, in the order the rows are in.
     * @param array $press The button pressed, e.g. ['slide_moveup' => [2 => 'Move up']].
     * @param array $absent Row indexes to leave out of the submission.
     * @param array $extra Further keys to submit.
     * @return notice_form The form built on that submission.
     */
    private function submit(awareness $notice, array $slides, array $press, array $absent = [], array $extra = []): notice_form {
        global $PAGE;

        $PAGE->set_url('/local/awareness/editnotice.php');
        $data = [
            'title' => 'Semester news',
            'template' => 'carousel',
            'slide_repeats' => count($slides),
            'slide_media' => [],
            'slide_image' => [],
            'slide_videourl' => [],
            'slide_caption' => [],
            'slide_id' => [],
        ];
        foreach ($slides as $i => $slide) {
            if (in_array($i, $absent, true)) {
                continue;
            }
            $data['slide_media'][$i] = $slide->get_mediatype() === slide::MEDIA_VIDEO ? slide::MEDIA_VIDEO : slide::MEDIA_IMAGE;
            $data['slide_image'][$i] = 100 + $i;
            $data['slide_videourl'][$i] = (string) $slide->get('videourl');
            $data['slide_caption'][$i] = (string) $slide->get('caption');
            $data['slide_id'][$i] = (int) $slide->get('id');
        }
        notice_form::mock_submit(array_merge($data, $press, $extra));

        return new notice_form(null, [
            'persistent' => $notice,
            'id' => (int) $notice->get('id'),
            'scope' => author_scope::site(),
        ]);
    }

    /**
     * The form's default data, which is protected.
     *
     * @param notice_form $form The form.
     * @return \stdClass
     */
    private function default_data(notice_form $form): \stdClass {
        $method = new \ReflectionMethod($form, 'get_default_data');
        $method->setAccessible(true);

        return $method->invoke($form);
    }

    /**
     * The rendered form, as a queryable document.
     *
     * @param notice_form $form The form.
     * @return \DOMXPath
     */
    private function render(notice_form $form): \DOMXPath {
        $html = $form->render();
        $this->assertStringContainsString('fitem_id_slide_no_0', $html, 'the slide rows are gone; this test is blind');

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    /**
     * The value of a named input.
     *
     * @param \DOMXPath $xpath The document.
     * @param string $name The input's name.
     * @return string
     */
    private function value(\DOMXPath $xpath, string $name): string {
        $inputs = $xpath->query('//input[@name="' . $name . '"]');
        $this->assertSame(1, $inputs->length, "{$name} is not rendered exactly once");

        return $inputs->item(0)->getAttribute('value');
    }

    /**
     * The selected option of a named select.
     *
     * @param \DOMXPath $xpath The document.
     * @param string $name The select's name.
     * @return string
     */
    private function selected(\DOMXPath $xpath, string $name): string {
        $options = $xpath->query('//select[@name="' . $name . '"]/option[@selected]');
        $this->assertSame(1, $options->length, "{$name} has no selected option");

        return $options->item(0)->getAttribute('value');
    }

    /**
     * The heading of a slide row.
     *
     * @param \DOMXPath $xpath The document.
     * @param int $i The row index.
     * @return string
     */
    private function heading(\DOMXPath $xpath, int $i): string {
        $headings = $xpath->query('//*[@id="fitem_id_slide_no_' . $i . '"]//*[contains(@class, "la-slide-head-number")]');
        $this->assertSame(1, $headings->length, "row {$i} has no heading");

        return trim($headings->item(0)->textContent);
    }

    /**
     * Assert that a button's name is registered as no-submit, so a press re-renders rather than saves.
     *
     * Read from the registration itself rather than through no_submit_button_pressed(): that method
     * memoises its answer in a function-local static, shared by every form instance the process
     * builds, so its answer in a test depends on whichever form asked first.
     *
     * @param notice_form $form The form.
     * @param string $name The button's name.
     * @return void
     */
    private function assert_no_submit(notice_form $form, string $name): void {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        $this->assertContains(
            $name,
            $property->getValue($form)->_noSubmitButtons,
            "{$name} is not a no-submit button: a press would try to save"
        );
    }

    /**
     * The names of the inputs carrying autofocus.
     *
     * @param \DOMXPath $xpath The document.
     * @return string[]
     */
    private function focused(\DOMXPath $xpath): array {
        $names = [];
        foreach ($xpath->query('//input[@autofocus]') as $input) {
            $names[] = $input->getAttribute('name');
        }

        return $names;
    }

    /**
     * A press on Move up exchanges the row with the one above it, whole, and is a no-submit press.
     *
     * The third slide goes to the second row and the second to the third; the first row is the
     * control. Every value travels — the id, the draft id, the medium, the link, the caption — and
     * the store is untouched until the author saves. The moved slide keeps the focus in its new place.
     */
    public function test_a_press_on_move_up_exchanges_the_row_with_the_one_above(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);

        $form = $this->submit($notice, $slides, ['slide_moveup' => [2 => 'Move up']]);
        $this->assert_no_submit($form, 'slide_moveup[2]');
        $xpath = $this->render($form);

        // The second row now holds the third slide.
        $this->assertSame('Words', $this->value($xpath, 'slide_caption[1]'));
        $this->assertSame((string) $ids[2], $this->value($xpath, 'slide_id[1]'));
        $this->assertSame('102', $this->value($xpath, 'slide_image[1]'));
        $this->assertSame('', $this->value($xpath, 'slide_videourl[1]'));
        $this->assertSame(slide::MEDIA_IMAGE, $this->selected($xpath, 'slide_media[1]'));

        // The third row now holds the second slide.
        $this->assertSame('Tour', $this->value($xpath, 'slide_caption[2]'));
        $this->assertSame((string) $ids[1], $this->value($xpath, 'slide_id[2]'));
        $this->assertSame('101', $this->value($xpath, 'slide_image[2]'));
        $this->assertSame('https://youtu.be/3b1aH9K0xQ4', $this->value($xpath, 'slide_videourl[2]'));
        $this->assertSame(slide::MEDIA_VIDEO, $this->selected($xpath, 'slide_media[2]'));

        // The first row is untouched.
        $this->assertSame('Lab', $this->value($xpath, 'slide_caption[0]'));
        $this->assertSame((string) $ids[0], $this->value($xpath, 'slide_id[0]'));
        $this->assertSame('100', $this->value($xpath, 'slide_image[0]'));

        // The headings follow the rows, and the moved slide keeps the focus where it now is.
        $this->assertSame(
            ['Slide 1', 'Slide 2', 'Slide 3'],
            [$this->heading($xpath, 0), $this->heading($xpath, 1), $this->heading($xpath, 2)]
        );
        $this->assertSame(['slide_moveup[1]'], $this->focused($xpath));

        // Nothing stored has changed.
        $stored = slide::for_notice($notice->get('id'));
        $this->assertSame($ids, array_map(static fn(slide $s): int => (int) $s->get('id'), $stored));
        $this->assertSame([0, 1, 2], array_map(static fn(slide $s): int => (int) $s->get('sortorder'), $stored));
    }

    /**
     * Move down exchanges the row with the one below, and a slide reaching the end hands the focus to the other direction.
     */
    public function test_a_press_on_move_down_exchanges_the_row_with_the_one_below(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);

        $form = $this->submit($notice, $slides, ['slide_movedown' => [1 => 'Move down']]);
        $this->assert_no_submit($form, 'slide_movedown[1]');
        $xpath = $this->render($form);

        $this->assertSame('Words', $this->value($xpath, 'slide_caption[1]'));
        $this->assertSame((string) $ids[2], $this->value($xpath, 'slide_id[1]'));
        $this->assertSame('Tour', $this->value($xpath, 'slide_caption[2]'));
        $this->assertSame((string) $ids[1], $this->value($xpath, 'slide_id[2]'));
        $this->assertSame('Lab', $this->value($xpath, 'slide_caption[0]'));
        // The last row cannot move down, so the slide that just arrived there is offered the way back.
        $this->assertSame(['slide_moveup[2]'], $this->focused($xpath));
    }

    /**
     * The first slide cannot move up and the last cannot move down: the press changes nothing.
     *
     * The button is still registered as no-submit — the control that the press reaches the form.
     */
    public function test_a_press_at_either_end_of_the_strip_changes_nothing(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);

        foreach ([['slide_moveup' => [0 => 'Move up']], ['slide_movedown' => [2 => 'Move down']]] as $press) {
            $form = $this->submit($notice, $slides, $press);
            $this->assert_no_submit($form, key($press) . '[' . key(reset($press)) . ']');
            $xpath = $this->render($form);

            $this->assertSame(['Lab', 'Tour', 'Words'], [
                $this->value($xpath, 'slide_caption[0]'),
                $this->value($xpath, 'slide_caption[1]'),
                $this->value($xpath, 'slide_caption[2]'),
            ]);
            $this->assertSame(array_map('strval', $ids), [
                $this->value($xpath, 'slide_id[0]'),
                $this->value($xpath, 'slide_id[1]'),
                $this->value($xpath, 'slide_id[2]'),
            ]);
            $this->assertSame([], $this->focused($xpath), 'nothing moved, yet something took the focus');
        }
    }

    /**
     * A move steps over a deleted row, and the strip is numbered by place rather than by row index.
     *
     * Core keeps a deleted row deleted through a hidden marker and skips its index on every render
     * after, so the indexes have a gap. Moving the third slide up exchanges rows 0 and 2, the
     * headings read 1 and 2, and the buttons' accessible names say the same. The ends are closed:
     * the first row's Move up and the last row's Move down are disabled, the others are not.
     */
    public function test_a_move_steps_over_a_deleted_row_and_the_strip_is_numbered_by_place(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);

        $form = $this->submit($notice, $slides, ['slide_moveup' => [2 => 'Move up']], [1], ['slide_delete-hidden' => [1 => 1]]);
        $this->assert_no_submit($form, 'slide_moveup[2]');
        $xpath = $this->render($form);

        $this->assertSame(0, $xpath->query('//*[@id="fitem_id_slide_no_1"]')->length, 'the deleted row came back');
        $this->assertSame('Words', $this->value($xpath, 'slide_caption[0]'));
        $this->assertSame((string) $ids[2], $this->value($xpath, 'slide_id[0]'));
        $this->assertSame('Lab', $this->value($xpath, 'slide_caption[2]'));
        $this->assertSame((string) $ids[0], $this->value($xpath, 'slide_id[2]'));

        $this->assertSame('Slide 1', $this->heading($xpath, 0));
        $this->assertSame('Slide 2', $this->heading($xpath, 2));
        $button = static fn(string $name): \DOMElement => $xpath->query('//input[@name="' . $name . '"]')->item(0);
        $this->assertSame('Move up: slide 2', $button('slide_moveup[2]')->getAttribute('aria-label'));
        $this->assertSame('Remove slide 1', $button('slide_delete[0]')->getAttribute('aria-label'));

        $this->assertTrue($button('slide_moveup[0]')->hasAttribute('disabled'), 'the first slide is offered a move up');
        $this->assertTrue($button('slide_movedown[2]')->hasAttribute('disabled'), 'the last slide is offered a move down');
        $this->assertFalse($button('slide_movedown[0]')->hasAttribute('disabled'));
        $this->assertFalse($button('slide_moveup[2]')->hasAttribute('disabled'));
        $this->assertFalse($button('slide_delete[0]')->hasAttribute('disabled'));
        $this->assertSame(
            ['slide_movedown[0]'],
            $this->focused($xpath),
            'the slide that reached the top was not offered the way back'
        );
    }

    /**
     * The defaults pair each row with the slide it carries, not with the slide stored at that index.
     *
     * After a move the browser posts the rows where they now are, ids included. The defaults —
     * which a submitted value outranks, so nothing ever showed — used to pair row 1's draft area
     * with the slide stored second. Read straight from get_default_data(), where the pairing is made.
     */
    public function test_the_defaults_pair_each_row_with_the_slide_it_carries(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);

        // The rows as the browser posts them once the third slide has been moved up.
        $defaults = $this->default_data($this->submit($notice, [$slides[0], $slides[2], $slides[1]], []));

        $this->assertSame([$ids[0], $ids[2], $ids[1]], $defaults->slide_id);
        $this->assertSame([100, 101, 102], $defaults->slide_image);
        $this->assertSame(['Lab', 'Words', 'Tour'], $defaults->slide_caption);
    }

    /**
     * A save listing one slide twice is refused before anything is written, notice row included.
     *
     * The rows are read before the notice is saved, so the refusal has to come from there: the
     * title in the same payload is the control that nothing was written first. The second half
     * calls the reconciliation directly, the way a caller that never built its rows through
     * slide_rows() would, and must be refused all the same.
     */
    public function test_a_save_listing_a_slide_twice_is_refused_before_anything_is_written(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);
        set_config('allow_update', 1, 'local_awareness');

        try {
            helper::update_notice($notice, (object) [
                'title' => 'Renamed',
                'content' => '<p>News.</p>',
                'perpetual' => 1,
                'template' => 'carousel',
                'slide_media' => [slide::MEDIA_IMAGE, slide::MEDIA_IMAGE],
                'slide_caption' => ['Overwritten', 'Winner'],
                'slide_videourl' => ['', ''],
                'slide_image' => [0, 0],
                'slide_id' => [$ids[0], $ids[0]],
            ]);
            $this->fail('a save listing a slide twice was accepted');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('listed twice', $e->debuginfo);
        }

        $this->assertSame(
            'Semester news',
            awareness::get_record(['id' => $notice->get('id')])->get('title'),
            'the notice was written first'
        );
        $stored = slide::for_notice($notice->get('id'));
        $this->assertSame($ids, array_map(static fn(slide $s): int => (int) $s->get('id'), $stored), 'a slide was lost');
        $this->assertSame(['Lab', 'Tour', 'Words'], array_map(static fn(slide $s): string => (string) $s->get('caption'), $stored));
        $this->assertSame('slide.png', $stored[0]->get_image()->get_filename(), 'the first slide lost its image');

        // Two rows apart, not next door: the third row repeats the first.
        $rows = (object) [
            'slide_caption' => ['Overwritten', 'Kept', 'Winner'],
            'slide_videourl' => ['', '', ''],
            'slide_image' => [0, 0, 0],
            'slide_id' => [$ids[0], $ids[1], $ids[0]],
        ];
        $this->expectException(\invalid_parameter_exception::class);
        helper::process_slides($notice, $rows);
    }

    /**
     * The save after a move stores the order shown, and a moved slide keeps its id and its image.
     *
     * The rows arrive rearranged with their ids, as the browser posts them after the re-render; the
     * reconciliation numbers them from the rows, so the stored order is the shown one, no slide is
     * recreated, and the image stays filed under the id that travelled with it.
     */
    public function test_the_save_after_a_move_stores_the_order_shown_and_a_slide_keeps_its_id_and_image(): void {
        $this->resetAfterTest();
        [$notice, $slides] = $this->carousel();
        $ids = array_map(static fn(slide $s): int => (int) $s->get('id'), $slides);

        // The first slide's picker, as the editor prepared it: a draft area holding its image.
        $draftid = 0;
        file_prepare_draft_area(
            $draftid,
            \context_system::instance()->id,
            'local_awareness',
            slide::FILEAREA,
            $ids[0],
            ['maxfiles' => 1, 'accepted_types' => ['image']]
        );

        set_config('allow_update', 1, 'local_awareness');
        helper::update_notice($notice, (object) [
            'title' => 'Semester news',
            'content' => '<p>News.</p>',
            'perpetual' => 1,
            'template' => 'carousel',
            'slide_media' => [slide::MEDIA_IMAGE, slide::MEDIA_IMAGE, slide::MEDIA_VIDEO],
            'slide_caption' => ['Words', 'Lab', 'Tour'],
            'slide_videourl' => ['', '', 'https://youtu.be/3b1aH9K0xQ4'],
            'slide_image' => [0, $draftid, 0],
            'slide_id' => [$ids[2], $ids[0], $ids[1]],
        ]);

        $stored = slide::for_notice($notice->get('id'));
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_map(static fn(slide $s): int => (int) $s->get('id'), $stored));
        $this->assertSame([0, 1, 2], array_map(static fn(slide $s): int => (int) $s->get('sortorder'), $stored));
        $this->assertSame(['Words', 'Lab', 'Tour'], array_map(static fn(slide $s): string => (string) $s->get('caption'), $stored));
        $this->assertSame('slide.png', $stored[1]->get_image()->get_filename(), 'the image did not follow its slide');
        $this->assertNull($stored[0]->get_image(), 'the image was copied onto the slide that took the first place');
    }
}
