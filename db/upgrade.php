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
 * @package   local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_awareness_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026020901) {
        // Define field pathmatch to be added to local_awareness.
        $table = new xmldb_table('local_awareness');
        $field = new xmldb_field('pathmatch', XMLDB_TYPE_CHAR, '1333', null, null, null, null, 'forcelogout');

        // Conditionally launch add field pathmatch.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field filtervalues to be added to local_awareness.
        $field = new xmldb_field('filtervalues', XMLDB_TYPE_TEXT, null, null, null, null, null, 'pathmatch');

        // Conditionally launch add field filtervalues.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Awareness savepoint reached.
        upgrade_plugin_savepoint(true, 2026020901, 'local', 'awareness');
    }

    if ($oldversion < 2026021001) {
        $table = new xmldb_table('local_awareness');
        $field = new xmldb_field('bgimage', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'filtervalues');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026021001, 'local', 'awareness');
    }

    if ($oldversion < 2026021002) {
        $table = new xmldb_table('local_awareness');

        $field1 = new xmldb_field('modal_width', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'bgimage');
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        $field2 = new xmldb_field('modal_height', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'modal_width');
        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_plugin_savepoint(true, 2026021002, 'local', 'awareness');
    }

    if ($oldversion < 2026021003) {
        $table = new xmldb_table('local_awareness');
        $field = new xmldb_field('outsideclick', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'modal_height');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026021003, 'local', 'awareness');
    }

    if ($oldversion < 2026030401) {
        $table = new xmldb_table('local_awareness');
        $field = new xmldb_field('contentformat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'content');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026030401, 'local', 'awareness');
    }

    return true;
}
