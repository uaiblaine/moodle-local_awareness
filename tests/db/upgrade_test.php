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

/**
 * The switch from date-numbered versions to per-Moodle-version numbering, and the rule that keeps
 * savepoints at or below version.php.
 *
 * Coverage stays in the docblock while a branch of this plugin supports Moodle 4.5: moodle-cs on
 * the 4.5 leg cannot see attributes and warns on every method.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::xmldb_local_awareness_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * A site that ran the date-numbered steps and set its version just below this branch's
     * upgrades without complaint.
     *
     * @return void
     */
    public function test_a_site_that_ran_the_dated_steps_upgrades(): void {
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        $below = $this->version_on_disk() - 1;
        // The installed version as such a site has it, or a savepoint would read as a downgrade.
        set_config('version', $below, 'local_awareness');

        $this->assertTrue(xmldb_local_awareness_upgrade($below));
    }

    /**
     * A site whose schema predates the last date-numbered schema step is refused, whatever
     * version it was given by hand: the steps that would bring it up to date are gone.
     *
     * @return void
     */
    public function test_a_site_that_skipped_the_dated_steps_is_refused(): void {
        global $CFG, $DB;
        $this->load_upgrade_lib();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_awareness_slides');
        $dbman->drop_table($table);
        $this->assertFalse($dbman->table_exists($table), 'Control: the table is gone.');
        try {
            $this->expectException(\upgrade_exception::class);
            xmldb_local_awareness_upgrade($this->version_on_disk() - 1);
        } finally {
            $dbman->install_one_table_from_xmldb_file(
                $CFG->dirroot . '/local/awareness/db/install.xml',
                'local_awareness_slides'
            );
        }
    }

    /**
     * No upgrade step records a version above version.php's. The next upgrade of a site running
     * such a tree records it, and the site then reads version.php as a downgrade and stops. A
     * branch may hold no step at all.
     *
     * @return void
     */
    public function test_no_savepoint_is_ahead_of_version_php(): void {
        global $CFG;
        $pattern = '/upgrade_plugin_savepoint\(\s*true,\s*(\d{10}),/';
        // Control: the pattern reads a savepoint written the way the steps write it.
        $this->assertSame(1, preg_match($pattern, "upgrade_plugin_savepoint(true, 2026100501, 'local', 'awareness');"));

        $source = file_get_contents($CFG->dirroot . '/local/awareness/db/upgrade.php');
        preg_match_all($pattern, $source, $matches);
        foreach (array_map('intval', $matches[1]) as $savepoint) {
            $this->assertLessThanOrEqual($this->version_on_disk(), $savepoint);
        }
    }

    /**
     * The version this checkout's version.php declares.
     *
     * @return int The version on disk.
     */
    private function version_on_disk(): int {
        return (int) \core_plugin_manager::instance()->get_plugin_info('local_awareness')->versiondisk;
    }

    /**
     * Load the upgrade library and this plugin's upgrade function.
     *
     * @return void
     */
    private function load_upgrade_lib(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/awareness/db/upgrade.php');
    }
}
