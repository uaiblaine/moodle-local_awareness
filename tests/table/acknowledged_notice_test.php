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

namespace local_awareness\table;

/**
 * The acknowledged-notices report table.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\table\acknowledged_notice
 */
final class acknowledged_notice_test extends \advanced_testcase {
    /**
     * Build the table the way the report page does.
     *
     * @return acknowledged_notice The table under test.
     */
    private function make_table(): acknowledged_notice {
        return new acknowledged_notice(
            'test-acknowledged',
            new \moodle_url('/local/awareness/report/acknowledged_systemreport.php'),
            0,
            // The constructor reads filters->params, so the shape matters here, not the values.
            ['params' => []]
        );
    }

    /**
     * Columns core escapes must stay escaped when the subclass overrides other_cols().
     *
     * flexible_table::other_cols() exists to return s($row->$column) for email and idnumber, which
     * users can set on their own profile on most sites. This class overrides the method to count
     * link clicks and used to `return null` for everything else, which discards core's escaping
     * rather than deferring to it — and the table declares an idnumber column with no
     * col_idnumber(), so format_row() fell back to the raw value. Audit finding M24.
     *
     * @covers \local_awareness\table\acknowledged_notice::other_cols
     */
    public function test_other_cols_keeps_the_escaping_core_applies(): void {
        $this->resetAfterTest();

        $table = $this->make_table();
        $row = (object) [
            'userid' => 7,
            'noticeid' => 0,
            'idnumber' => '<script>alert(1)</script>',
            'email' => 'a<b>@example.com',
        ];

        $idnumber = $table->other_cols('idnumber', $row);
        $this->assertIsString($idnumber);
        $this->assertStringNotContainsString('<script>', $idnumber);
        $this->assertSame(s($row->idnumber), $idnumber);

        // Core escapes email by the same rule, so it must come back escaped too.
        $this->assertSame(s($row->email), $table->other_cols('email', $row));
    }

    /**
     * The override's own job must still work — otherwise the test above passes on a broken table.
     *
     * A numeric column is a notice hyperlink id and must return a click count, not fall through to
     * core. Without this control, deleting the whole method would satisfy the escaping assertions.
     *
     * @covers \local_awareness\table\acknowledged_notice::other_cols
     */
    public function test_other_cols_still_counts_link_clicks(): void {
        $this->resetAfterTest();

        $table = $this->make_table();
        $row = (object) ['userid' => 7, 'noticeid' => 0];

        $this->assertSame('0', $table->other_cols('3', $row));
    }

    /**
     * A column core does not special-case has no business being invented here.
     *
     * @covers \local_awareness\table\acknowledged_notice::other_cols
     */
    public function test_other_cols_returns_null_for_an_ordinary_column(): void {
        $this->resetAfterTest();

        $table = $this->make_table();

        $this->assertNull($table->other_cols('firstname', (object) [
            'userid' => 7,
            'noticeid' => 0,
            'firstname' => 'Ana',
        ]));
    }
}
