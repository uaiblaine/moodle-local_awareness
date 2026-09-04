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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/awareness/lib.php');

/**
 * A slide's image is served through the notice's gate, though its item id is the slide's.
 *
 * Same technique as lib_test: a successful serve ends in send_stored_file(), which terminates the
 * process, so the positive path is shown by deleting the file first and watching the callback
 * fall out at its own get_file() miss - a line BELOW the gate. The pair carries the meaning:
 * disabled with a file returns false from the gate, enabled without a file returns false from
 * the bottom.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::local_awareness_pluginfile
 */
final class slidemedia_pluginfile_test extends \advanced_testcase {
    /**
     * An enabled or disabled carousel notice with one image slide.
     *
     * @param int $enabled Whether the notice is enabled.
     * @return slide The slide carrying the image.
     */
    private function seed_slide(int $enabled): slide {
        $notice = new awareness(0, (object) [
            'title' => 'News', 'content' => '<p>News.</p>', 'template' => 'carousel', 'enabled' => $enabled,
        ]);
        $notice->create();

        $slide = new slide(0, (object) ['noticeid' => $notice->get('id'), 'sortorder' => 0, 'caption' => 'Lab']);
        $slide->create();

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_awareness',
            'filearea' => slide::FILEAREA,
            'itemid' => $slide->get('id'),
            'filepath' => '/',
            'filename' => 'lab.png',
        ], 'not really a png');

        return $slide;
    }

    /**
     * The callback, for a slide's image.
     *
     * @param int $slideid The slide id in the URL.
     * @return bool|null What the callback returned.
     */
    private function serve(int $slideid) {
        return local_awareness_pluginfile(
            null,
            null,
            \context_system::instance(),
            slide::FILEAREA,
            [$slideid, 'lab.png'],
            false
        );
    }

    /**
     * A plain user is refused the image of a disabled notice's slide: the gate is the notice's.
     */
    public function test_a_plain_user_cannot_fetch_a_slide_image_of_a_disabled_notice(): void {
        $this->resetAfterTest();
        $slide = $this->seed_slide(0);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse($this->serve((int) $slide->get('id')));
    }

    /**
     * A plain user passes the gate on an enabled notice: with the file deleted, the callback
     * reaches its own file miss, which is below the gate.
     */
    public function test_a_plain_user_passes_the_gate_on_an_enabled_notice(): void {
        $this->resetAfterTest();
        $slide = $this->seed_slide(1);
        $this->setUser($this->getDataGenerator()->create_user());

        $slide->get_image()->delete();

        $this->assertFalse($this->serve((int) $slide->get('id')));
    }

    /**
     * A slide id that names nothing is refused before any notice is consulted.
     */
    public function test_an_unknown_slide_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertFalse($this->serve(987654));
    }
}
