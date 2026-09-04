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
 * The layout, position, animation, video link and slides: what the form offers, refuses and saves.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\form\notice_form
 * @covers     \local_awareness\helper
 */
final class layout_form_test extends \advanced_testcase {
    /**
     * A form for the given notice, or for a new one.
     *
     * @param awareness|null $notice The notice being edited.
     * @return notice_form
     */
    private function form(?awareness $notice = null): notice_form {
        return new notice_form(null, [
            'persistent' => $notice,
            'id' => $notice ? (int) $notice->get('id') : 0,
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
     * The form's extra validation, which is protected, on a submission.
     *
     * @param notice_form $form The form.
     * @param array $data The submitted fields.
     * @return array Element name => message.
     */
    private function validate(notice_form $form, array $data): array {
        $method = new \ReflectionMethod($form, 'extra_validation');
        $method->setAccessible(true);
        $errors = [];

        return $method->invokeArgs($form, [(object) ($data + ['title' => 'Policy update']), [], &$errors]);
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
     * A new notice is offered a fade; a saved one keeps the stillness it stored.
     *
     * The column default stays 'none' so the upgrade changes nothing a reader sees, and only the
     * empty form suggests motion. The second half is the control: without it, a default that
     * leaked into every edit would pass the first.
     */
    public function test_a_new_notice_is_offered_a_fade_and_a_saved_one_keeps_its_stillness(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertSame('fade', $this->default_data($this->form())->animation);

        $notice = new awareness(0, (object) ['title' => 'Policy update', 'content' => '<p>Read it.</p>']);
        $notice->create();
        $this->assertSame('none', $this->default_data($this->form($notice))->animation);
    }

    /**
     * The combinations the picker cannot express are refused on the field the author can act on.
     *
     * @dataProvider refused_combination_provider
     * @param array $data The submitted appearance fields.
     * @param string $element The element the message lands on.
     */
    public function test_a_combination_the_layout_cannot_carry_is_refused(array $data, string $element): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $errors = $this->validate($this->form(), $data);

        $this->assertArrayHasKey($element, $errors, json_encode($errors));
    }

    /**
     * One refused combination per rule.
     *
     * @return array[]
     */
    public static function refused_combination_provider(): array {
        return [
            'card cannot demand an acknowledgement' => [
                ['template' => 'card', 'insistence' => awareness::INSISTENCE_ACKNOWLEDGE, 'position' => 'center'],
                'insistence',
            ],
            'classic cannot sit in a corner' => [
                ['template' => 'classic', 'insistence' => 0, 'position' => 'top-end'],
                'positiongroup',
            ],
            'video needs a link' => [
                ['template' => 'video', 'insistence' => 0, 'position' => 'center', 'videourl' => ''],
                'videourl',
            ],
            'a scheme-less link would resolve against the page' => [
                ['template' => 'video', 'insistence' => 0, 'position' => 'center', 'videourl' => 'youtu.be/3b1aH9K0xQ4'],
                'videourl',
            ],
            'a carousel needs two slides' => [
                [
                    'template' => 'carousel', 'insistence' => 0, 'position' => 'center',
                    'slide_caption' => ['Only one', ''], 'slide_videourl' => ['', ''], 'slide_image' => [0, 0],
                ],
                'templategroup',
            ],
        ];
    }

    /**
     * The combinations the rules allow pass, so the refusals above are not a form that refuses everything.
     */
    public function test_the_allowed_combinations_pass(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = $this->form();

        $this->assertSame([], $this->validate($form, [
            'template' => 'card', 'insistence' => awareness::INSISTENCE_BLOCKING, 'position' => 'top-end',
        ]));
        // Fullscreen has no position, so whatever the hidden radio carried is not a problem.
        $this->assertSame([], $this->validate($form, [
            'template' => 'fullscreen', 'insistence' => awareness::INSISTENCE_ACKNOWLEDGE, 'position' => 'top-end',
        ]));
        $this->assertSame([], $this->validate($form, [
            'template' => 'video', 'insistence' => 0, 'position' => 'top', 'videourl' => 'https://youtu.be/3b1aH9K0xQ4',
        ]));
        $this->assertSame([], $this->validate($form, [
            'template' => 'carousel', 'insistence' => 0, 'position' => 'center',
            'slide_caption' => ['First', ''], 'slide_videourl' => ['', 'https://vimeo.com/123456'], 'slide_image' => [0, 0],
        ]));
    }

    /**
     * A slide asked to show both an image and a video is refused on its link, with the slide named.
     */
    public function test_a_slide_cannot_show_both_an_image_and_a_video(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with_image();

        $errors = $this->validate($this->form(), [
            'template' => 'carousel', 'insistence' => 0, 'position' => 'center',
            'slide_caption' => ['', 'Second'],
            'slide_videourl' => ['https://vimeo.com/123456', ''],
            'slide_image' => [$draftid, 0],
        ]);

        $this->assertArrayHasKey('slide_videourl[0]', $errors);
        $this->assertArrayNotHasKey('templategroup', $errors, 'two slides are present; only the first is malformed');
    }

    /**
     * Saving writes the slides in order with their files, and a later save without a row deletes it.
     */
    public function test_saving_reconciles_the_slides_with_the_rows_submitted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $draftid = $this->draft_with_image();

        helper::create_new_notice((object) [
            'title' => 'Semester news',
            'content' => '<p>News.</p>',
            'perpetual' => 1,
            'template' => 'carousel',
            'slide_caption' => ['Lab', 'Tour', ''],
            'slide_videourl' => ['', 'https://youtu.be/3b1aH9K0xQ4', ''],
            'slide_image' => [$draftid, 0, 0],
            'slide_id' => [0, 0, 0],
        ]);
        $notice = awareness::get_record(['title' => 'Semester news']);
        $slides = slide::for_notice($notice->get('id'));

        // The empty third row is not a slide.
        $this->assertCount(2, $slides);
        $this->assertSame([0, 1], array_map(static fn(slide $s): int => (int) $s->get('sortorder'), $slides));
        $this->assertSame(slide::MEDIA_IMAGE, $slides[0]->get_mediatype());
        $this->assertSame('slide.png', $slides[0]->get_image()->get_filename());
        $this->assertSame(slide::MEDIA_VIDEO, $slides[1]->get_mediatype());
        $this->assertSame('Tour', $slides[1]->get('caption'));

        // Edit: keep the video slide only, and the image slide goes with its file.
        set_config('allow_update', 1, 'local_awareness');
        $imageid = (int) $slides[0]->get('id');
        helper::update_notice($notice, (object) [
            'title' => 'Semester news',
            'content' => '<p>News.</p>',
            'perpetual' => 1,
            'template' => 'carousel',
            'slide_caption' => ['Tour'],
            'slide_videourl' => ['https://youtu.be/3b1aH9K0xQ4'],
            'slide_image' => [0],
            'slide_id' => [(int) $slides[1]->get('id')],
        ]);

        $remaining = slide::for_notice($notice->get('id'));
        $this->assertCount(1, $remaining);
        $this->assertSame(
            (int) $slides[1]->get('id'),
            (int) $remaining[0]->get('id'),
            'the kept row was replaced rather than kept'
        );
        $this->assertSame(0, (int) $remaining[0]->get('sortorder'));
        $this->assertFalse($DB->record_exists('local_awareness_slides', ['id' => $imageid]));
        $this->assertEmpty(get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_awareness',
            slide::FILEAREA,
            $imageid,
            'id',
            false
        ), 'the removed slide\'s image outlived its row');
    }

    /**
     * What a hidden field would have carried is decided by the layout, on every write path.
     */
    public function test_the_layout_decides_the_values_of_the_fields_it_hides(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        helper::create_new_notice((object) [
            'title' => 'Fullscreen policy', 'content' => '<p>Read it.</p>', 'perpetual' => 1,
            'template' => 'fullscreen', 'position' => 'top', 'videourl' => 'https://youtu.be/3b1aH9K0xQ4',
        ]);
        $fullscreen = awareness::get_record(['title' => 'Fullscreen policy']);
        $this->assertSame('center', $fullscreen->get('position'), 'a fullscreen dialogue has no position');
        $this->assertNull($fullscreen->get('videourl'), 'only the video layout keeps a link');

        helper::create_new_notice((object) [
            'title' => 'Library tour', 'content' => '<p>Watch.</p>', 'perpetual' => 1,
            'template' => 'video', 'position' => 'top', 'videourl' => ' https://youtu.be/3b1aH9K0xQ4 ',
        ]);
        $video = awareness::get_record(['title' => 'Library tour']);
        $this->assertSame('top', $video->get('position'));
        $this->assertSame('https://youtu.be/3b1aH9K0xQ4', $video->get('videourl'), 'the link is kept, trimmed');
    }

    /**
     * The appearance section opens for a notice that chose something, and stays closed for the defaults.
     *
     * The stored columns are never empty, so "holds a value" has to mean "differs from the
     * default"; without that, every edit of every notice would open the section.
     */
    public function test_the_appearance_section_opens_only_for_a_choice_away_from_the_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $plain = new awareness(0, (object) ['title' => 'Plain', 'content' => '<p>Plain.</p>']);
        $plain->create();
        $hero = new awareness(0, (object) ['title' => 'Hero', 'content' => '<p>Hero.</p>', 'template' => 'hero']);
        $hero->create();

        $this->assertTrue($this->is_collapsed($this->form($plain), 'header_appearance'), 'the defaults opened the section');
        $this->assertFalse($this->is_collapsed($this->form($hero), 'header_appearance'), 'a chosen layout left the section closed');
    }

    /**
     * Whether a header starts collapsed, from the form's own bookkeeping.
     *
     * @param notice_form $form The form.
     * @param string $header The header element name.
     * @return bool
     */
    private function is_collapsed(notice_form $form, string $header): bool {
        $mform = new \ReflectionProperty($form, '_form');
        $mform->setAccessible(true);
        $quickform = $mform->getValue($form);

        $collapsible = new \ReflectionProperty($quickform, '_collapsibleElements');
        $collapsible->setAccessible(true);
        $state = $collapsible->getValue($quickform);

        $this->assertArrayHasKey($header, $state, "{$header} is not a collapsible section");

        return (bool) $state[$header];
    }
}
