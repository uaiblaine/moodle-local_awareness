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

/**
 * Retiring Force logout must not quietly make the most insistent notices the least insistent.
 *
 * This runs the REAL upgrade function rather than a copy of its statement. A test that reproduces
 * the SQL beside the code proves the two agree with each other and nothing else — this repository
 * has already shipped a test whose pure-logic twin passed against the exact mutation it existed to
 * catch, and only the real query caught it.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\persistent\awareness::get_insistence
 */
final class upgrade_insistence_test extends \advanced_testcase {
    /**
     * Insert a notice row directly, so a combination the form can no longer produce still exists.
     *
     * @param string $title Title, used to find the row again.
     * @param int $reqack Stored reqack.
     * @param int $outsideclick Stored outsideclick.
     * @param int $forcelogout Stored forcelogout.
     * @return int The new row id.
     */
    private function legacy_notice(string $title, int $reqack, int $outsideclick, int $forcelogout): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_awareness', (object) [
            'title' => $title,
            'content' => '<p>Body</p>',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => $reqack,
            'reqcourse' => 0,
            'enabled' => 1,
            'resetinterval' => 0,
            'usermodified' => 2,
            'timecreated' => $now,
            'timemodified' => $now,
            'timestart' => 0,
            'timeend' => 0,
            'forcelogout' => $forcelogout,
            'pathmatch' => '',
            'filtervalues' => '',
            'bgimage' => 0,
            'modal_width' => '',
            'modal_height' => '',
            'outsideclick' => $outsideclick,
            'audiencecount' => 0,
            'audiencecomputed' => 0,
            'audiencehash' => '',
        ]);
    }

    /**
     * Every stored combination lands on the level its author would recognise.
     *
     * The four rows are the whole space that mattered, and three of them are controls for the
     * fourth: only the Force logout row may move. A migration that set outsideclick unconditionally
     * would satisfy an assertion about that row alone while silently promoting every ordinary
     * notice on the site to Blocking, which is the failure this shape exists to catch.
     *
     * @return void
     */
    public function test_force_logout_notices_keep_their_insistence(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $plain = $this->legacy_notice('Plain', 0, 1, 0);
        $ejecting = $this->legacy_notice('Ejecting', 0, 1, 1);
        $blocking = $this->legacy_notice('Already blocking', 0, 0, 0);
        $acking = $this->legacy_notice('Ejecting and asking', 1, 1, 1);

        // The upgrade file calls upgrade_plugin_savepoint(), which lives in the upgrade library
        // and is not loaded by the test bootstrap.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/awareness/db/upgrade.php');
        /*
         * Put the plugin back one version first. The test site is installed at the current version,
         * so upgrade_plugin_savepoint() would otherwise refuse the savepoint as a downgrade — and
         * a site standing one version back is exactly the state this step exists to handle.
         */
        set_config('version', 2026082401, 'local_awareness');

        // One step below the migration, so this call runs that step and nothing before it.
        xmldb_local_awareness_upgrade(2026082401);

        $level = static function (int $id): int {
            return (new awareness($id))->get_insistence();
        };

        $this->assertSame(
            awareness::INSISTENCE_BLOCKING,
            $level($ejecting),
            'a notice whose only insistence was Force logout must stay hard to escape'
        );

        // The three controls. Each would move if the WHERE clause were wrong in a different way.
        $this->assertSame(
            awareness::INSISTENCE_INFORMATIONAL,
            $level($plain),
            'an ordinary notice must not be promoted; if it is, the WHERE clause is not reading forcelogout'
        );
        $this->assertSame(
            awareness::INSISTENCE_BLOCKING,
            $level($blocking),
            'a notice already blocking must be left exactly as it was'
        );
        $this->assertSame(
            awareness::INSISTENCE_ACKNOWLEDGE,
            $level($acking),
            'a notice that already required acknowledgement is at the top level and must not be demoted'
        );

        // The historical fact is kept, not rewritten: the report column still shows what was asked.
        $this->assertSame(
            '1',
            (string) $DB->get_field('local_awareness', 'forcelogout', ['id' => $ejecting]),
            'the forcelogout column records what the author once asked for and must survive the upgrade'
        );
    }
}
