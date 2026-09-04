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
 * What the dialogue is told about a notice's layout, and what each layout ships with.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\external\get_notices
 */
final class layout_payload_test extends \advanced_testcase {
    /**
     * The plugin switched on, and a reader logged in.
     */
    private function ready(): void {
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');

        $this->resetAfterTest();
        set_config('enabled', 1, 'local_awareness');
        filter_set_global_state('mediaplugin', TEXTFILTER_ON);
        set_config('media_plugins_sortorder', 'videojs,youtube,vimeo');
        \core_media_manager::reset_caches();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * The payload of the one notice showing on /my/, through the declared returns.
     *
     * @return array
     */
    private function payload(): array {
        $result = \core_external\external_api::clean_returnvalue(
            get_notices::execute_returns(),
            get_notices::execute('/my/')
        );
        $this->assertCount(1, $result['notices']);

        return reset($result['notices']);
    }

    /**
     * A notice saved before layouts existed is told it is the classic, centred, still dialogue.
     */
    public function test_a_plain_notice_ships_the_defaults_and_no_media(): void {
        $this->ready();
        $notice = new awareness(0, (object) ['title' => 'Plain', 'content' => '<p>Plain.</p>']);
        $notice->create();

        $payload = $this->payload();

        $this->assertSame('classic', $payload['template']);
        $this->assertSame('center', $payload['position']);
        $this->assertSame('none', $payload['animation']);
        $this->assertSame('', $payload['videohtml']);
        $this->assertSame([], $payload['slides']);
    }

    /**
     * The video layout ships the player the filter built, and no background behind it.
     *
     * The background half is the control for the layout gate: the notice HAS a background file,
     * so an empty URL can only mean the layout withheld it.
     */
    public function test_the_video_layout_ships_the_player_and_withholds_the_background(): void {
        $this->ready();
        $notice = new awareness(0, (object) [
            'title' => 'Tour', 'content' => '<p>Watch.</p>',
            'template' => 'video', 'position' => 'top', 'animation' => 'zoom',
            'videourl' => 'https://www.youtube.com/watch?v=3b1aH9K0xQ4', 'bgimage' => 1,
        ]);
        $notice->create();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_awareness',
            'filearea' => 'bgimage',
            'itemid' => $notice->get('id'),
            'filepath' => '/',
            'filename' => 'bg.png',
        ], 'not really a png');

        $payload = $this->payload();

        $this->assertSame('video', $payload['template']);
        $this->assertSame('top', $payload['position']);
        $this->assertSame('zoom', $payload['animation']);
        $this->assertStringContainsString('mediaplugin', $payload['videohtml'], 'no player in the payload');
        $this->assertSame('', $payload['bgimageurl'], 'the video layout must not paint a background');
        $this->assertSame([], $payload['slides']);

        // Control for the background gate: the same file under the classic layout is shipped.
        $notice->set('template', 'classic');
        $notice->set('videourl', null);
        $notice->update();
        $classic = $this->payload();
        $this->assertStringContainsString('bg.png', $classic['bgimageurl']);
        $this->assertSame('', $classic['videohtml'], 'only the video layout renders the link');
    }

    /**
     * The carousel ships its slides in order, and a caption keeps its bare "<" and "&".
     *
     * The caption is the guard for the escaping contract: it travels unescaped, for the template's
     * double stash to escape exactly once, so an ampersand must not arrive as an entity.
     */
    public function test_the_carousel_ships_its_slides_and_a_sharp_caption_survives(): void {
        $this->ready();
        $notice = new awareness(0, (object) ['title' => 'News', 'content' => '<p>News.</p>', 'template' => 'carousel']);
        $notice->create();
        $second = new slide(0, (object) [
            'noticeid' => $notice->get('id'), 'sortorder' => 1, 'videourl' => 'https://vimeo.com/123456', 'caption' => 'Tour',
        ]);
        $second->create();
        $first = new slide(0, (object) ['noticeid' => $notice->get('id'), 'sortorder' => 0, 'caption' => 'Seats < 40 & more']);
        $first->create();

        $payload = $this->payload();

        $this->assertSame(['text', 'video'], array_column($payload['slides'], 'type'));
        $this->assertSame('Seats < 40 & more', $payload['slides'][0]['caption']);
        $this->assertStringContainsString('mediaplugin', $payload['slides'][1]['html']);
        $this->assertSame('', $payload['videohtml'], 'the carousel draws from its slides, not the video field');
    }
}
