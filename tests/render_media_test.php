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

namespace local_awareness;

use local_awareness\persistent\awareness;
use local_awareness\persistent\slide;

/**
 * The video and the slides are rendered by the site's own machinery, not built by hand.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\helper
 */
final class render_media_test extends \advanced_testcase {
    /**
     * The multimedia filter on, at the system level, as a fresh site has it.
     */
    private function enable_media_filter(): void {
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');

        filter_set_global_state('mediaplugin', TEXTFILTER_ON);
        // The players a fresh install enables, in the order it enables them.
        set_config('media_plugins_sortorder', 'videojs,youtube,vimeo');
        \core_media_manager::reset_caches();
        // The active filters are memoised per request; a state changed after the first render is
        // invisible until the memo is dropped.
        \filter_manager::reset_caches();
    }

    /**
     * A link becomes the player the site's filter builds, never a hand-made embed.
     *
     * The control proves the filter was what did it: the same link with the filter off stays the
     * anchor the template wrote, so a player that appeared could not have come from anywhere else.
     */
    public function test_a_media_link_is_rendered_by_the_multimedia_filter(): void {
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        filter_set_global_state('mediaplugin', TEXTFILTER_OFF);
        \filter_manager::reset_caches();
        $plain = helper::render_media_link('https://www.youtube.com/watch?v=3b1aH9K0xQ4');
        $this->assertStringContainsString('<a href="https://www.youtube.com/watch?v=3b1aH9K0xQ4"', $plain);
        $this->assertStringNotContainsString('mediaplugin', $plain, 'a player appeared with the filter off');

        $this->enable_media_filter();
        $player = helper::render_media_link('https://www.youtube.com/watch?v=3b1aH9K0xQ4');
        $this->assertStringContainsString('mediaplugin', $player, 'the filter built no player from the link');

        $file = helper::render_media_link('https://example.com/media/tour.mp4');
        $this->assertStringContainsString('mediaplugin', $file, 'the filter built no player from an MP4 link');
        $this->assertStringContainsString('tour.mp4', $file);

        $this->assertSame('', helper::render_media_link('   '), 'an empty link renders nothing');
    }

    /**
     * The slides come out in order, each as what it is, with the caption escaped exactly once.
     */
    public function test_the_slides_render_in_order_as_what_they_are(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_media_filter();

        $notice = new awareness(0, (object) ['title' => 'News', 'content' => '<p>News.</p>', 'template' => 'carousel']);
        $notice->create();

        $video = new slide(0, (object) [
            'noticeid' => $notice->get('id'), 'sortorder' => 1,
            'videourl' => 'https://vimeo.com/123456', 'caption' => 'Tour',
        ]);
        $video->create();
        $image = new slide(0, (object) ['noticeid' => $notice->get('id'), 'sortorder' => 0, 'caption' => 'Lab < 40 seats & more']);
        $image->create();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_awareness',
            'filearea' => slide::FILEAREA,
            'itemid' => $image->get('id'),
            'filepath' => '/',
            'filename' => 'lab.png',
        ], 'not really a png');
        $text = new slide(0, (object) ['noticeid' => $notice->get('id'), 'sortorder' => 2, 'caption' => 'Just words']);
        $text->create();

        $slides = helper::render_slides($notice);

        $this->assertSame(['image', 'video', 'text'], array_column($slides, 'type'));
        $this->assertStringContainsString(
            '/local_awareness/' . slide::FILEAREA . '/' . $image->get('id') . '/lab.png',
            $slides[0]['html']
        );
        // The caption is plain text carried unescaped; the template's double stash escapes it once,
        // and it is also the image's alternative text, escaped by that template.
        $this->assertSame('Lab < 40 seats & more', $slides[0]['caption']);
        $this->assertStringContainsString('alt="Lab &lt; 40 seats &amp; more"', $slides[0]['html']);
        $this->assertStringContainsString('mediaplugin', $slides[1]['html']);
        $this->assertSame('Tour', $slides[1]['caption']);
        $this->assertSame('', $slides[2]['html']);
        $this->assertSame('Just words', $slides[2]['caption']);
    }

    /**
     * A notice without a link renders no video, so the layout has nothing to fall back to by accident.
     */
    public function test_a_notice_without_a_link_renders_no_video(): void {
        $this->resetAfterTest();

        $notice = new awareness(0, (object) ['title' => 'Silent', 'content' => '<p>No video.</p>', 'template' => 'video']);
        $this->assertSame('', helper::render_video($notice));
    }
}
