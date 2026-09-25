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
 * An acknowledgement row always carries the copies of the reader's name and idnumber.
 *
 * The compliance reports print these copies rather than joining {user}, so a row written without
 * them would list an acceptance nobody can be named for.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\acknowledgement
 */
final class acknowledgement_test extends \advanced_testcase {
    /**
     * A complete row, as helper::create_new_acknowledge_record() builds it.
     *
     * @return array Field => value.
     */
    private function complete_row(): array {
        return [
            'userid' => 2,
            'username' => 'admin',
            'firstname' => 'Admin',
            'lastname' => 'User',
            'idnumber' => '',
            'noticeid' => 7,
            'noticetitle' => 'Policy update',
            'action' => acknowledgement::ACTION_ACKNOWLEDGED,
        ];
    }

    /**
     * Each copied field is refused as null.
     *
     * The control is the complete row, which validates and is stored, so each refusal below is
     * that field's and not the fixture's.
     *
     * @return void
     */
    public function test_the_copied_name_fields_are_required(): void {
        global $DB;

        $this->resetAfterTest();

        $complete = new acknowledgement(0, (object) $this->complete_row());
        $this->assertTrue($complete->is_valid(), 'the control row must validate');
        $complete->create();
        $this->assertSame(1, $DB->count_records('local_awareness_ack'));

        foreach (['firstname', 'lastname', 'idnumber'] as $field) {
            $row = $this->complete_row();
            $row[$field] = null;
            $ack = new acknowledgement(0, (object) $row);

            $this->assertFalse($ack->is_valid(), "a null {$field} must be refused");
            $this->assertArrayHasKey($field, $ack->get_errors());
        }
    }
}
