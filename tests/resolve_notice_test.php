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

/**
 * Tests for turning the id a request names into the notice it means.
 *
 * Three directions, so that an implementation that is secretly always-null or always-throw cannot
 * pass: no id means a new notice, a real id means that notice, and an id that names nothing is
 * refused rather than treated as "new". The last case is the one editnotice.php got wrong: its
 * create-or-update branch keyed on whether a record was found, which is false in both of the first
 * and third cases.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\helper::resolve_notice
 */
final class resolve_notice_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * No id means a new notice.
     */
    public function test_no_id_means_a_new_notice(): void {
        $this->assertNull(helper::resolve_notice(0));
    }

    /**
     * A real id means that notice.
     */
    public function test_a_real_id_means_that_notice(): void {
        $notice = $this->getDataGenerator()->get_plugin_generator('local_awareness')->create_notice();

        $found = helper::resolve_notice((int) $notice->get('id'));

        $this->assertNotNull($found);
        $this->assertSame((int) $notice->get('id'), (int) $found->get('id'));
    }

    /**
     * An id that names nothing is refused, with the message the page shows.
     */
    public function test_an_id_that_names_nothing_is_refused(): void {
        global $DB;

        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_awareness}') + 1000;
        $this->assertFalse($DB->record_exists('local_awareness', ['id' => $missing]));

        try {
            helper::resolve_notice($missing);
        } catch (\moodle_exception $e) {
            $this->assertSame('notification:noticedoesnotexist', $e->errorcode);
            return;
        }

        $this->fail('an id that names nothing was resolved');
    }
}
