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

namespace local_awareness\db;

use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\awareness;
use local_awareness\persistent\noticeview;

/**
 * The shipped schema is what install.xml declares, read back from the live database.
 *
 * A plugin's schema is written twice — once in install.xml for a fresh install, once in upgrade.php
 * for an existing site — and nothing in the pipeline compares the two. They drift in one direction:
 * a step is written, install.xml is forgotten, and the defect then appears ONLY on sites that
 * installed before the step, or ONLY on sites that installed after. A suite that always installs
 * fresh sees neither.
 *
 * PHPUnit installs from install.xml, so these read the fresh-install side and pin the declaration.
 * Running the upgrade against a real database is what proves the other side; an upgrade that
 * disagrees leaves the two shapes different, and the next person to add a step then has a stated
 * expectation to check against instead of a guess.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\noticeview
 */
final class schema_test extends \advanced_testcase {
    /**
     * The acknowledgement index leads with noticeid, because every predicate names it.
     *
     * A two-value column indexed alone is not usable: around half the table qualifies either way,
     * so the old ack_action was paid for on every insert and read by nothing.
     *
     * @return void
     */
    public function test_the_acknowledgement_index_is_the_composite(): void {
        global $DB;

        $this->resetAfterTest();

        $indexes = $DB->get_indexes('local_awareness_ack');

        // Non-vacuity: the table was read at all.
        $this->assertNotEmpty($indexes, 'no index found on local_awareness_ack — the read is broken');

        /*
         * Matched on COLUMNS, never on the name. Moodle generates its own index names — the
         * composite comes back as something like t_locaawarack_notact_ix, not as the name
         * install.xml gives it — so an assertion keyed on 'ack_action' can never fail and would be
         * a guard that reads correct and proves nothing. Measured, not assumed: a probe printed the
         * real keys before this was written.
         */
        $columnsets = array_values(array_map(static fn(array $i): array => $i['columns'], $indexes));

        $this->assertNotContains(['action'], $columnsets, 'the two-value index on action alone is still there');
        $this->assertContains(['noticeid', 'action'], $columnsets, 'no (noticeid, action) index on local_awareness_ack');

        foreach ($indexes as $index) {
            if ($index['columns'] === ['noticeid', 'action']) {
                $this->assertEquals(0, (int) $index['unique'], 'the composite must not be unique');
            }
        }
    }

    /**
     * The view table can be reached by noticeid alone.
     *
     * Deleting a notice removes its view rows by noticeid with no userid, and this is the plugin's
     * largest table — one row per user per notice. user_notice_uq leads with userid, so it cannot
     * serve that shape.
     *
     * @return void
     */
    public function test_the_view_table_is_reachable_by_noticeid(): void {
        global $DB;

        $this->resetAfterTest();

        $indexes = $DB->get_indexes('local_awareness_lastview');
        $this->assertNotEmpty($indexes, 'no index found on local_awareness_lastview — the read is broken');

        $leading = [];
        foreach ($indexes as $index) {
            $leading[] = reset($index['columns']);
        }

        $this->assertContains('noticeid', $leading, 'nothing on this table leads with noticeid');

        /*
         * And the composite serving every other read is still here. Without this, the assertion
         * above would be satisfied by a change that swapped one index for the other — trading a
         * rare delete path for every per-user read the plugin makes.
         */
        $this->assertContains('userid', $leading, 'the (userid, noticeid) index has gone');
    }

    /**
     * The view table stores its action as the same integer enum the ack table uses.
     *
     * @return void
     */
    /**
     * The slides table is read by notice, in order, and that is the index it carries.
     */
    public function test_the_slides_table_is_read_by_notice_in_order(): void {
        global $DB;

        $this->resetAfterTest();

        $indexes = $DB->get_indexes('local_awareness_slides');
        $this->assertNotEmpty($indexes, 'no index found on local_awareness_slides — the read is broken');

        $columnsets = array_values(array_map(static fn(array $i): array => $i['columns'], $indexes));
        $this->assertContains(['noticeid', 'sortorder'], $columnsets, 'no (noticeid, sortorder) index on local_awareness_slides');

        $columns = $DB->get_columns('local_awareness_slides');
        foreach (['noticeid', 'sortorder', 'videourl', 'caption', 'usermodified', 'timecreated', 'timemodified'] as $column) {
            $this->assertArrayHasKey($column, $columns, "local_awareness_slides has no {$column} column");
        }
    }

    public function test_the_view_action_is_an_integer(): void {
        global $DB;

        $this->resetAfterTest();

        $view = $DB->get_columns('local_awareness_lastview')['action'];
        $ack = $DB->get_columns('local_awareness_ack')['action'];

        $this->assertSame(
            $ack->meta_type,
            $view->meta_type,
            'the two tables store the same two-value enum in different column types'
        );
        $this->assertSame('I', $view->meta_type, 'the view action is not an integer column');
    }

    /**
     * A view row round-trips its action as an integer.
     *
     * The column type alone is not the guarantee: the persistent declares its own type, and a
     * PARAM_RAW_TRIMMED field over an integer column would still hand back a string that every
     * strict comparison in helper.php would then get wrong.
     *
     * @return void
     */
    public function test_a_view_row_round_trips_its_action(): void {
        $this->resetAfterTest();

        $notice = new awareness(0, (object) [
            'title' => 'Policy update',
            'content' => '<p>Read it.</p>',
        ]);
        $notice->create();

        $user = $this->getDataGenerator()->create_user();
        noticeview::add_notice_view(
            (int) $notice->get('id'),
            (int) $user->id,
            acknowledgement::ACTION_ACKNOWLEDGED
        );

        $stored = noticeview::get_record([
            'noticeid' => $notice->get('id'),
            'userid' => $user->id,
        ]);

        $this->assertNotFalse($stored, 'the view row was not stored at all');
        $this->assertSame(
            acknowledgement::ACTION_ACKNOWLEDGED,
            $stored->get('action'),
            'the action did not come back as the integer it went in as'
        );
    }

    /**
     * A notice's course is a NOT NULL column defaulting to the site, with the index its foreign key builds.
     *
     * The default is what makes the upgrade need no backfill: every row written before the column
     * existed reads as a site notice because 0 is what the column says when nothing was said.
     *
     * @return void
     */
    public function test_a_notice_carries_its_course_and_defaults_to_the_site(): void {
        global $DB;

        $this->resetAfterTest();

        $columns = $DB->get_columns('local_awareness');
        $this->assertNotEmpty($columns, 'no column found on local_awareness — the read is broken');
        $this->assertArrayHasKey('courseid', $columns, 'local_awareness has no courseid column');
        $this->assertTrue((bool) $columns['courseid']->not_null, 'courseid must be NOT NULL');
        $this->assertSame('0', (string) $columns['courseid']->default_value, 'courseid must default to 0, the site');

        // The foreign key exists only as the index Moodle builds for it; matched on columns, never on the name.
        $columnsets = array_values(array_map(static fn(array $i): array => $i['columns'], $DB->get_indexes('local_awareness')));
        $this->assertContains(['courseid'], $columnsets, 'no index on local_awareness.courseid');

        // And the persistent writes it: a row created without saying carries 0, one that says keeps it.
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $site = $generator->create_notice();
        $mine = $generator->create_notice(['courseid' => $course->id]);
        $this->assertSame(0, (int) $DB->get_field('local_awareness', 'courseid', ['id' => $site->get('id')]));
        $this->assertSame((int) $course->id, (int) $DB->get_field('local_awareness', 'courseid', ['id' => $mine->get('id')]));
    }
}
