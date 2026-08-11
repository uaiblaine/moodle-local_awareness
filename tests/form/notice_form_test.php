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

use local_awareness\persistent\awareness;

/**
 * Tests for the notice editing form.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\form\notice_form
 */
final class notice_form_test extends \advanced_testcase {
    /**
     * Create a notice carrying one file in its content file area.
     *
     * @return array{0: awareness, 1: string} The notice and the stored file name.
     */
    private function create_notice_with_embedded_file(): array {
        $notice = new awareness(0, (object) [
            'title' => 'Policy update',
            'content' => '<p>See the diagram below.</p>',
        ]);
        $notice->create();

        get_file_storage()->create_file_from_string(
            [
                'contextid' => \context_system::instance()->id,
                'component' => 'local_awareness',
                'filearea' => 'content',
                'itemid' => $notice->get('id'),
                'filepath' => '/',
                'filename' => 'diagram.png',
            ],
            'not really a png'
        );

        return [$notice, 'diagram.png'];
    }

    /**
     * Read the form's default data, which is protected.
     *
     * @param notice_form $form Form instance.
     * @return \stdClass
     */
    private function default_data(notice_form $form): \stdClass {
        $method = new \ReflectionMethod($form, 'get_default_data');
        $method->setAccessible(true);
        return $method->invoke($form);
    }

    /**
     * Editing a notice must hand the editor a draft area holding the notice's own files.
     *
     * Without an itemid, MoodleQuickForm_editor mints an empty draft area and the save path
     * syncs that empty area over local_awareness/content/<noticeid>, deleting every embedded
     * file. Asserting the draft is populated is what catches a regression: an itemid alone
     * would still be present if the area were prepared from item 0 again.
     */
    public function test_editing_a_notice_loads_its_files_into_the_content_draft_area(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$notice, $filename] = $this->create_notice_with_embedded_file();

        $form = new notice_form(null, ['persistent' => $notice, 'id' => $notice->get('id')]);
        $data = $this->default_data($form);

        $this->assertIsArray($data->content);
        $this->assertArrayHasKey('itemid', $data->content);
        $draftitemid = (int) $data->content['itemid'];
        $this->assertGreaterThan(0, $draftitemid);

        $draftfiles = get_file_storage()->get_area_files(
            \context_user::instance((int) $USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        $names = array_map(static function (\stored_file $file): string {
            return $file->get_filename();
        }, $draftfiles);
        $this->assertContains($filename, array_values($names));
    }

    /**
     * Creating a notice must start from an empty draft area.
     *
     * The control for the test above: it proves the population comes from the notice's own
     * item id rather than from every file the plugin holds.
     */
    public function test_creating_a_notice_starts_from_an_empty_content_draft_area(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_notice_with_embedded_file();

        $form = new notice_form(null, ['persistent' => null, 'id' => 0]);
        $data = $this->default_data($form);

        $draftitemid = (int) $data->content['itemid'];
        $this->assertGreaterThan(0, $draftitemid);

        $draftfiles = get_file_storage()->get_area_files(
            \context_user::instance((int) $USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        $this->assertEmpty($draftfiles);
    }
}
