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

use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * The reader's dialogue tracks only the links the server registered.
 *
 * helper::update_hyperlinks() gives data-linkid to the anchors in the stored content when the
 * notice is saved. Anchors that appear later, at render time, carry none: a link the multimedia
 * filter did not turn into a player, or text filter output. notice.js must not send those to the
 * tracking service, which refuses a click without a link id and makes the reader see an error
 * dialogue for following an ordinary link.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper
 */
final class link_tracking_test extends \advanced_testcase {
    /**
     * Read one file from the plugin root.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string The file contents.
     */
    private function read(string $relative): string {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path, "{$relative} has been renamed, so this test is now blind, not passing.");

        return (string) file_get_contents($path);
    }

    /**
     * The click handler that calls trackLink(), with the selector it is delegated on.
     *
     * @param string $js The module source.
     * @return array The selectors every such handler is delegated on.
     */
    private function tracking_selectors(string $js): array {
        preg_match_all("/\\.on\\('click',\\s*'([^']+)',\\s*function\\(\\)\\s*\\{[^}]*trackLink\\(/", $js, $found);

        return $found[1];
    }

    /**
     * Only an anchor carrying data-linkid is sent to the tracking service.
     *
     * The force is shown first: the dialogue really does receive anchors without the attribute, and
     * the service really does refuse a click without one. Then the handler is read, in the source
     * and in the bundle Moodle serves.
     */
    public function test_only_a_registered_link_is_tracked(): void {
        global $CFG;
        require_once($CFG->libdir . '/filterlib.php');

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // A video link no player takes stays the anchor the template wrote, with no data-linkid.
        filter_set_global_state('mediaplugin', TEXTFILTER_OFF);
        \filter_manager::reset_caches();
        $video = helper::render_media_link('https://example.com/not-a-video');
        $this->assertStringContainsString('<a href="https://example.com/not-a-video"', $video);
        $this->assertStringNotContainsString('data-linkid', $video);

        // The service refuses a click that names no link, and the dialogue shows that as an error.
        $_POST['sesskey'] = sesskey();
        $response = \core_external\external_api::call_external_function('local_awareness_tracklink', [], false);
        $this->assertTrue($response['error'], 'the tracking service accepted a click without a link id');

        $js = $this->read('amd/src/notice.js');
        $selectors = $this->tracking_selectors($js);
        $this->assertSame(['a[data-linkid]'], $selectors, 'link tracking must be delegated on a[data-linkid] only');

        $this->assertStringContainsString(
            'a[data-linkid]',
            $this->read('amd/build/notice.min.js'),
            'amd/build/notice.min.js predates the narrowed selector: rebuild it.'
        );
    }

    /**
     * The links the author wrote still carry data-linkid when the reader gets them.
     *
     * The control for the test above: narrowing the selector must not stop tracking the links the
     * server registered. Saving tags them, and rendering keeps the attribute.
     */
    public function test_the_author_s_links_reach_the_reader_tagged(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        helper::create_new_notice((object) [
            'title' => 'Policy update',
            'content' => '<p>Read <a href="https://example.com/policy">the policy</a>.</p>',
            'perpetual' => 1,
        ]);
        $notices = awareness::get_records(['title' => 'Policy update']);
        $this->assertCount(1, $notices);

        $html = helper::render_content(reset($notices));
        $this->assertMatchesRegularExpression('/<a [^>]*data-linkid="\d+"/', $html);
    }
}
