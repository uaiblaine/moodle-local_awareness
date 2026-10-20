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

/**
 * Upgrade logic.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * Versions are numbered in the range of the Moodle version a branch requires (5.3: 20261005XX,
 * 5.2: 20260420XX, 5.1: 20251006XX, 4.5: 20241007XX), so a release for one Moodle version never
 * carries the number of another. The steps up to version 2026101303 were numbered by date, above
 * the 4.5, 5.1 and 5.2 ranges, and a site moving between branches would have run them again, so
 * they were removed. 2026101303 is the last date-numbered version and the one every existing site
 * switches from; CHANGELOG.md gives the steps.
 *
 * A step added from now on is numbered inside its own branch's range and goes below the schema
 * check.
 *
 * @param int $oldversion The currently installed plugin version.
 * @return bool True on success.
 * @throws upgrade_exception When the site never ran the date-numbered steps.
 */
function xmldb_local_awareness_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    /*
     * Whether the site ran the removed steps is read from the schema, not from $oldversion:
     * switching numbering sets the version by hand, so it says nothing about which steps ran. The
     * last schema step (2026090402) created the slides table. The data step after it only
     * re-stamped stored audience hashes, which a recalculation also does.
     */
    if (!$dbman->table_exists(new xmldb_table('local_awareness_slides'))) {
        throw new upgrade_exception(
            'local_awareness',
            $oldversion,
            'Upgrade to Awareness version 2026101303 first, which runs every earlier upgrade step. '
                . 'Then set the version to the number CHANGELOG.md gives for this branch with '
                . 'admin/cli/cfg.php --component=local_awareness --name=version --set=<number>, '
                . 'and install this release.'
        );
    }

    return true;
}
