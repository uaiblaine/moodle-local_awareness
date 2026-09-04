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

namespace local_awareness\external;

use local_awareness\persistent\awareness;
use local_awareness\persistent\slide;

/**
 * The two previews: the editor's, from the form's fields, and the manage list's, from a saved notice.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\external\preview_notice
 * @covers     \local_awareness\external\render_notice
 * @covers     \local_awareness\local\notice_payload
 */
final class preview_external_test extends \advanced_testcase {
    /**
     * The multimedia filter on, as a fresh site has it.
     */
    private function enable_media_filter(): void {
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');

        filter_set_global_state('mediaplugin', TEXTFILTER_ON);
        set_config('media_plugins_sortorder', 'videojs,youtube,vimeo');
        \core_media_manager::reset_caches();
        \filter_manager::reset_caches();
    }

    /**
     * A draft area of the current user holding one image.
     *
     * @param string $filename The file's name.
     * @return int The draft item id.
     */
    private function draft_with_image(string $filename): int {
        global $USER;

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'not really a png');

        return $draftid;
    }

    /**
     * The editor's preview renders the form's fields as the reader would get them, from drafts.
     */
    public function test_the_editor_preview_renders_the_form_as_the_reader_would_get_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_media_filter();
        $bgdraft = $this->draft_with_image('bg.png');
        $slidedraft = $this->draft_with_image('lab.png');

        $payload = \core_external\external_api::clean_returnvalue(
            preview_notice::execute_returns(),
            preview_notice::execute(
                0,
                'Semester news',
                '<p>News & more.</p>',
                'carousel',
                'top',
                'zoom',
                awareness::INSISTENCE_BLOCKING,
                '',
                $bgdraft,
                '',
                '',
                [
                    ['imagedraftid' => $slidedraft, 'videourl' => '', 'caption' => 'The lab'],
                    ['imagedraftid' => 0, 'videourl' => 'https://vimeo.com/123456', 'caption' => 'Tour'],
                    ['imagedraftid' => 0, 'videourl' => '', 'caption' => ''],
                ]
            )
        );

        $this->assertSame(0, $payload['id']);
        $this->assertSame('Semester news', $payload['title']);
        // Rendered noclean, as the reader's own render is: the editor's markup arrives as written.
        $this->assertStringContainsString('News & more.', $payload['content']);
        $this->assertSame('carousel', $payload['template']);
        $this->assertSame('top', $payload['position']);
        $this->assertSame('zoom', $payload['animation']);
        $this->assertSame(awareness::INSISTENCE_BLOCKING, $payload['insistence']);
        $this->assertSame('', $payload['bgimageurl'], 'a carousel does not paint a background');
        $this->assertSame(['image', 'video'], array_column($payload['slides'], 'type'), 'the empty row is not a slide');
        $this->assertStringContainsString('draftfile.php', $payload['slides'][0]['html']);
        $this->assertStringContainsString('lab.png', $payload['slides'][0]['html']);
        $this->assertStringContainsString('mediaplugin', $payload['slides'][1]['html']);

        // The same draft under a layout that paints a background: the draft URL is shipped.
        $hero = preview_notice::execute(0, 'Hero', '<p>x</p>', 'hero', 'center', 'fade', 0, '', $bgdraft);
        $this->assertStringContainsString('draftfile.php', $hero['bgimageurl']);
        $this->assertStringContainsString('bg.png', $hero['bgimageurl']);

        // A video layout renders the link through the filter, and a card cannot demand an acknowledgement.
        $video = preview_notice::execute(
            0,
            'Tour',
            '<p>x</p>',
            'video',
            'bottom',
            'slide',
            0,
            'https://www.youtube.com/watch?v=3b1aH9K0xQ4'
        );
        $this->assertStringContainsString('mediaplugin', $video['videohtml']);
        $card = preview_notice::execute(0, 'Card', '<p>x</p>', 'card', 'top-end', 'fade', awareness::INSISTENCE_ACKNOWLEDGE);
        $this->assertSame(awareness::INSISTENCE_BLOCKING, $card['insistence']);
        $this->assertSame('top-end', $card['position']);
    }

    /**
     * A value outside a vocabulary previews as the default rather than refusing to open.
     */
    public function test_the_editor_preview_falls_back_rather_than_refusing_a_half_typed_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $payload = preview_notice::execute(0, 'x', '<p>x</p>', 'banner', 'top-end', 'bounce');

        $this->assertSame('classic', $payload['template']);
        $this->assertSame('center', $payload['position'], 'a corner is not offered to the classic layout');
        $this->assertSame('none', $payload['animation']);
    }

    /**
     * The editor's preview is the author's alone.
     */
    public function test_the_editor_preview_needs_the_manage_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        preview_notice::execute(0, 'x', '<p>x</p>');
    }

    /**
     * The manage list's preview renders a saved notice exactly as the reader's queue would.
     */
    public function test_the_list_preview_renders_the_saved_notice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_media_filter();

        $notice = new awareness(0, (object) [
            'title' => 'Tour', 'content' => '<p>Watch.</p>', 'template' => 'video', 'position' => 'top',
            'animation' => 'spring', 'videourl' => 'https://www.youtube.com/watch?v=3b1aH9K0xQ4',
        ]);
        $notice->create();

        $payload = \core_external\external_api::clean_returnvalue(
            render_notice::execute_returns(),
            render_notice::execute((int) $notice->get('id'))
        );

        $this->assertSame((int) $notice->get('id'), $payload['id']);
        $this->assertSame('video', $payload['template']);
        $this->assertSame('spring', $payload['animation']);
        $this->assertStringContainsString('mediaplugin', $payload['videohtml']);

        $carousel = new awareness(0, (object) ['title' => 'News', 'content' => '<p>News.</p>', 'template' => 'carousel']);
        $carousel->create();
        $slide = new slide(0, (object) ['noticeid' => $carousel->get('id'), 'sortorder' => 0, 'caption' => 'Only words']);
        $slide->create();
        $this->assertSame(['text'], array_column(render_notice::execute((int) $carousel->get('id'))['slides'], 'type'));
    }

    /**
     * A stranger cannot render a notice, and an id that names nothing is refused before any gate.
     */
    public function test_the_list_preview_is_refused_to_a_stranger_and_for_an_unknown_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $notice = new awareness(0, (object) ['title' => 'Plain', 'content' => '<p>Plain.</p>']);
        $notice->create();

        $this->setUser($this->getDataGenerator()->create_user());
        try {
            render_notice::execute((int) $notice->get('id'));
            $this->fail('a plain user rendered a notice');
        } catch (\required_capability_exception $e) {
            // The message is localised; the type is the contract.
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        // The resolver fails closed on an id that names nothing, before any gate is consulted.
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        render_notice::execute(987654);
    }
}
