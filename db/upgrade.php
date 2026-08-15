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

    if ($oldversion < 2026051401) {
        $table = new xmldb_table('local_awareness_audience_jobs');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('jobid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('criteriahash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('criteria', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('resultcount', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('breakdown', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('errormsg', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('jobid_uq', XMLDB_INDEX_UNIQUE, ['jobid']);
            $table->add_index('criteriahash_ix', XMLDB_INDEX_NOTUNIQUE, ['criteriahash']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051401, 'local', 'awareness');
    }

    if ($oldversion < 2026081103) {
        /*
         * local_awareness_hlinks_his grows one row per link click and is queried only by hlinkid
         * (the join in linkhistory::count_clicked_links) and by userid (its WHERE, and the privacy
         * erasure path), yet it had no index on either. Moodle never emits a real FOREIGN KEY
         * constraint — sql_generator::$foreign_keys is false on every driver — so these declare
         * the relationships and create the two indexes, without failing on rows whose hlinkid no
         * longer resolves.
         */
        $table = new xmldb_table('local_awareness_hlinks_his');

        $key = new xmldb_key('hlinkid', XMLDB_KEY_FOREIGN, ['hlinkid'], 'local_awareness_hlinks', ['id']);
        $dbman->add_key($table, $key);

        $key = new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2026081103, 'local', 'awareness');
    }

    if ($oldversion < 2026081200) {
        /*
         * Notice content used to be stored as the output of saveHTML() on a full document, so
         * every row carries a <!DOCTYPE html><html><body> wrapper that then rendered nested
         * inside the page. Unwrap it in place.
         *
         * Only the wrapper is touched. The rows also hold absolute pluginfile URLs and already
         * filter-expanded markup from the same code path; neither can be reversed reliably from
         * the stored text, and both keep rendering correctly — file_rewrite_pluginfile_urls()
         * leaves absolute URLs alone. Content saved from now on is stored as authored.
         *
         * The logic is inlined rather than calling the plugin's classes, which upgrade steps
         * must keep working against however those classes evolve.
         */
        $rs = $DB->get_recordset_select(
            'local_awareness',
            $DB->sql_like('content', ':needle', false),
            ['needle' => '%<html%'],
            '',
            'id, content'
        );

        foreach ($rs as $record) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML(
                mb_encode_numericentity($record->content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8')
            );
            libxml_clear_errors();

            if (!$loaded) {
                continue;
            }

            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body === null) {
                continue;
            }

            $unwrapped = '';
            foreach ($body->childNodes as $child) {
                $unwrapped .= $dom->saveHTML($child);
            }

            // Never blank a notice: if unwrapping produced nothing, keep what was there.
            if (trim($unwrapped) !== '') {
                $DB->set_field('local_awareness', 'content', $unwrapped, ['id' => $record->id]);
            }
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026081200, 'local', 'awareness');
    }

    if ($oldversion < 2026081501) {
        $table = new xmldb_table('local_awareness');

        /*
         * The last computed audience size, kept on the notice so the manage list reads a column
         * instead of resolving "the latest job for this notice" once per row. The jobs table stays
         * the record of computations; this is the pointer at the newest one.
         *
         * audiencehash is what makes the number honest. A stored count describes the criteria it
         * was computed from, so comparing it against the notice's current hash separates "old but
         * still true" from "about filters that no longer exist" — which a timestamp alone cannot.
         */
        $fields = [
            new xmldb_field('audiencecount', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'outsideclick'),
            new xmldb_field('audiencecomputed', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'audiencecount'),
            new xmldb_field('audiencehash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'audiencecomputed'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $jobs = new xmldb_table('local_awareness_audience_jobs');
        $noticeid = new xmldb_field('noticeid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($jobs, $noticeid)) {
            $dbman->add_field($jobs, $noticeid);
        }

        $index = new xmldb_index('noticeid_ix', XMLDB_INDEX_NOTUNIQUE, ['noticeid']);
        if (!$dbman->index_exists($jobs, $index)) {
            $dbman->add_index($jobs, $index);
        }

        upgrade_plugin_savepoint(true, 2026081501, 'local', 'awareness');
    }

    if ($oldversion < 2026081503) {
        /*
         * Accent-insensitive notice search. On PostgreSQL it needs the unaccent extension, which
         * is DDL and therefore belongs to upgrade rather than to the search request. An account
         * without the privilege keeps accent-sensitive search; see helper::ensure_unaccent().
         */
        \local_awareness\helper::ensure_unaccent();

        upgrade_plugin_savepoint(true, 2026081503, 'local', 'awareness');
    }

    return true;
}
