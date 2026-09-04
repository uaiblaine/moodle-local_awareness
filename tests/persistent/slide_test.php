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
 * Carousel slides: ordering, what each one shows, and the cleanup that takes their files with them.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\persistent\slide
 */
final class slide_test extends \advanced_testcase {
    /**
     * A saved notice to hang slides on.
     *
     * @return awareness
     */
    private function notice(): awareness {
        $notice = new awareness(0, (object) ['title' => 'Semester news', 'content' => '<p>News.</p>']);
        $notice->create();

        return $notice;
    }

    /**
     * A saved slide, with an image file when asked.
     *
     * @param awareness $notice The owning notice.
     * @param int $sortorder Its place.
     * @param string|null $videourl A link, or null.
     * @param bool $withimage Whether to upload an image for it.
     * @return slide
     */
    private function slide(awareness $notice, int $sortorder, ?string $videourl = null, bool $withimage = false): slide {
        $slide = new slide(0, (object) [
            'noticeid' => $notice->get('id'),
            'sortorder' => $sortorder,
            'videourl' => $videourl,
            'caption' => 'Slide ' . $sortorder,
        ]);
        $slide->create();

        if ($withimage) {
            get_file_storage()->create_file_from_string([
                'contextid' => \context_system::instance()->id,
                'component' => 'local_awareness',
                'filearea' => slide::FILEAREA,
                'itemid' => $slide->get('id'),
                'filepath' => '/',
                'filename' => 'slide' . $sortorder . '.png',
            ], 'not really a png');
        }

        return $slide;
    }

    /**
     * Slides come back in sortorder, whatever order they were written in.
     */
    public function test_slides_are_read_in_display_order(): void {
        $this->resetAfterTest();
        $notice = $this->notice();
        $other = $this->notice();

        $this->slide($notice, 2);
        $this->slide($notice, 0);
        $this->slide($other, 0);
        $this->slide($notice, 1);

        $order = array_map(static fn(slide $s): int => (int) $s->get('sortorder'), slide::for_notice($notice->get('id')));

        $this->assertSame([0, 1, 2], $order);
        $this->assertCount(1, slide::for_notice($other->get('id')), 'another notice\'s slides leaked in');
    }

    /**
     * An image makes an image slide, a link a video slide, neither a text slide.
     */
    public function test_what_a_slide_shows_follows_its_media(): void {
        $this->resetAfterTest();
        $notice = $this->notice();

        $image = $this->slide($notice, 0, null, true);
        $video = $this->slide($notice, 1, 'https://www.youtube.com/watch?v=3b1aH9K0xQ4');
        $text = $this->slide($notice, 2);

        $this->assertSame(slide::MEDIA_IMAGE, $image->get_mediatype());
        $this->assertSame('slide0.png', $image->get_image()->get_filename());
        $this->assertSame(slide::MEDIA_VIDEO, $video->get_mediatype());
        $this->assertNull($video->get_image());
        $this->assertSame(slide::MEDIA_TEXT, $text->get_mediatype());
    }

    /**
     * Deleting a notice's slides removes their rows and their files, and nobody else's.
     */
    public function test_deleting_a_notices_slides_takes_their_files_and_leaves_the_neighbours(): void {
        $this->resetAfterTest();
        $notice = $this->notice();
        $other = $this->notice();

        $gone = $this->slide($notice, 0, null, true);
        $kept = $this->slide($other, 0, null, true);

        slide::delete_for_notice($notice->get('id'));

        $this->assertSame([], slide::for_notice($notice->get('id')));
        $this->assertFalse(slide::record_exists($gone->get('id')));

        $fs = get_file_storage();
        $context = \context_system::instance()->id;
        $this->assertEmpty(
            $fs->get_area_files($context, 'local_awareness', slide::FILEAREA, $gone->get('id'), 'id', false),
            'the deleted slide\'s image survived its row'
        );
        // Control: the neighbour's row and file are untouched, so the assertions above cannot pass
        // by the cleanup having deleted everything.
        $this->assertTrue(slide::record_exists($kept->get('id')));
        $this->assertNotEmpty($fs->get_area_files($context, 'local_awareness', slide::FILEAREA, $kept->get('id'), 'id', false));
    }
}
