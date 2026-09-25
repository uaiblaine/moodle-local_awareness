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
         * local_awareness_hlinks_his grows one row per link click and is queried by hlinkid (the
         * joins to the notice's links) and by userid (privacy export and erasure), with no index on
         * either. Moodle emits no real FOREIGN KEY constraint
         * (sql_generator::$foreign_keys is false on every driver), so these keys declare the
         * relationships and build the two indexes without failing on rows whose hlinkid no longer
         * resolves.
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
        // Accent-insensitive notice search on PostgreSQL; see helper::ensure_unaccent().
        \local_awareness\helper::ensure_unaccent();

        upgrade_plugin_savepoint(true, 2026081503, 'local', 'awareness');
    }

    if ($oldversion < 2026082303) {
        /*
         * Replace ack_action, an index on a two-valued column that no planner will choose, with
         * (noticeid, action). The plugin's own queries on action all name noticeid beside it:
         * helper::has_acknowledgement_record(), both system reports, and the notice datasource's
         * ack_count and dismiss_count subqueries. Report builder filters on action alone lose the
         * old index, which a two-valued column made of little use to them anyway. The composite's
         * leading column duplicates the noticeid foreign key's index on a fresh install; the key
         * stays because it declares the relationship.
         */
        $ack = new xmldb_table('local_awareness_ack');

        $old = new xmldb_index('ack_action', XMLDB_INDEX_NOTUNIQUE, ['action']);
        if ($dbman->index_exists($ack, $old)) {
            $dbman->drop_index($ack, $old);
        }

        $new = new xmldb_index('ack_noticeaction', XMLDB_INDEX_NOTUNIQUE, ['noticeid', 'action']);
        if (!$dbman->index_exists($ack, $new)) {
            $dbman->add_index($ack, $new);
        }

        /*
         * local_awareness_lastview is the plugin's largest table, one row per (user, notice), and
         * deleting a notice removes its rows by noticeid alone (only with cleanup_deleted_notice
         * on), which without an index scans the whole table.
         *
         * Only noticeid. add_key() looks for an existing index by its exact column list, so a
         * userid key would not recognise user_notice_uq and would build a second index on the
         * column that index already leads with.
         */
        $lastview = new xmldb_table('local_awareness_lastview');
        $key = new xmldb_key('noticeid', XMLDB_KEY_FOREIGN, ['noticeid'], 'local_awareness', ['id']);
        $dbman->add_key($lastview, $key);

        upgrade_plugin_savepoint(true, 2026082303, 'local', 'awareness');
    }

    if ($oldversion < 2026082304) {
        /*
         * local_awareness_lastview.action held the 0/1 enum of local_awareness_ack.action in a
         * char(1333). Its only writer, noticeview::add_notice_view(), takes an int and is passed the
         * two acknowledgement constants, so on a site written by this plugin the UPDATE matches
         * nothing.
         *
         * The rows are normalised before the type change rather than trusted to it:
         * change_field_type() hands PostgreSQL USING CAST(CAST(action AS NUMERIC) AS INTEGER),
         * which is a hard error on a non-numeric row, and MySQL's ALTER IGNORE fallback applies
         * only to a text column, not a char one. Anything outside the enum becomes 0 (dismissed)
         * rather than stopping the upgrade half way through DDL.
         *
         * No DEFAULT on the new column: an insert that omits the action should fail rather than
         * silently record a dismissal.
         */
        $DB->execute("UPDATE {local_awareness_lastview} SET action = '0' WHERE action NOT IN ('0', '1')");

        $lastview = new xmldb_table('local_awareness_lastview');
        $field = new xmldb_field('action', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null, 'userid');
        $dbman->change_field_type($lastview, $field);

        upgrade_plugin_savepoint(true, 2026082304, 'local', 'awareness');
    }

    if ($oldversion < 2026082402) {
        /*
         * Force logout is retired. A notice that used it without requiring an acknowledgement is
         * raised to Blocking (outsideclick = 0), so the author's intent that it matters survives;
         * the level is derived in awareness::get_insistence(). A notice with reqack = 1 is already
         * at the top level and is left alone, which the WHERE clause states explicitly.
         *
         * The forcelogout column is kept: dropping it would lose the history and break saved
         * reports using its report builder column, which is marked deprecated instead. Nothing
         * reads it at runtime.
         */
        $DB->execute(
            "UPDATE {local_awareness} SET outsideclick = 0 WHERE forcelogout = 1 AND reqack = 0 AND outsideclick <> 0"
        );

        upgrade_plugin_savepoint(true, 2026082402, 'local', 'awareness');
    }

    if ($oldversion < 2026090400) {
        /*
         * A notice may belong to a course. 0 is the site, which is what every existing row means,
         * so the default is the backfill. The foreign key only builds an index (Moodle enforces no
         * referential integrity); hook_callbacks::before_course_deleted() keeps the column true
         * when a course is deleted.
         */
        $table = new xmldb_table('local_awareness');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $key = new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $dbman->add_key($table, $key);
        }

        upgrade_plugin_savepoint(true, 2026090400, 'local', 'awareness');
    }

    if ($oldversion < 2026090402) {
        /*
         * A notice chooses its layout, its place on the screen and its entrance. The defaults are
         * what every existing notice already renders as - the classic dialogue, centred, arriving
         * with no animation - so this step changes nothing a reader can see. The vocabularies live
         * on the persistent (awareness::TEMPLATES and friends); the columns only hold the choice.
         */
        $table = new xmldb_table('local_awareness');
        $fields = [
            new xmldb_field('template', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'classic', 'outsideclick'),
            new xmldb_field('position', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'center', 'template'),
            new xmldb_field('animation', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none', 'position'),
            new xmldb_field('videourl', XMLDB_TYPE_CHAR, '1333', null, null, null, null, 'animation'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // The carousel's slides: one row per slide, media keyed by the slide id in filearea slidemedia.
        $table = new xmldb_table('local_awareness_slides');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('noticeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('videourl', XMLDB_TYPE_CHAR, '1333', null, null, null, null);
        $table->add_field('caption', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('noticeid', XMLDB_KEY_FOREIGN, ['noticeid'], 'local_awareness', ['id']);
        $table->add_index('notice_order', XMLDB_INDEX_NOTUNIQUE, ['noticeid', 'sortorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026090402, 'local', 'awareness');
    }

    if ($oldversion < 2026092401) {
        /*
         * A course notice's audience criteria no longer carry its pathmatch, which the course scope
         * forces and the editor's estimate leaves out, so a saved course notice now hashes as the
         * editor's form does. A count stored before that is still right, because page reach never
         * enters a count, but its hash would read as "filters changed" until recalculated. This
         * re-stamps each course notice whose stored hash is exactly the one its criteria made with
         * the pathmatch in.
         *
         * The criteria are assembled as notice_audience::criteria_for() assembles them, while the
         * normalising and hashing are the estimator's own, since a copy of those could not be shown
         * to agree with it. Should either change shape later, no stored hash matches and the rows
         * are left as they are, which a recalculation fixes. Written through $DB, because the
         * persistent's update() stamps timemodified and would expire every recorded acceptance.
         */
        $rs = $DB->get_recordset_select(
            'local_awareness',
            'courseid > :siteid',
            ['siteid' => SITEID],
            'id',
            'id, cohorts, reqcourse, pathmatch, filtervalues, audiencehash'
        );
        foreach ($rs as $record) {
            if ((string) $record->audiencehash === '') {
                continue;
            }
            $raw = [];
            if (!empty($record->cohorts)) {
                $raw['cohorts'] = explode(',', $record->cohorts);
            }
            $raw['reqcourse'] = (int) $record->reqcourse;
            $filters = json_decode((string) $record->filtervalues, true);
            $filters = is_array($filters) ? $filters : [];

            $saved = \local_awareness\audience\estimator::hash(
                \local_awareness\audience\estimator::normalise($raw + ['pathmatch' => (string) $record->pathmatch] + $filters)
            );
            $current = \local_awareness\audience\estimator::hash(\local_awareness\audience\estimator::normalise($raw + $filters));
            if ($record->audiencehash === $saved && $saved !== $current) {
                $DB->set_field('local_awareness', 'audiencehash', $current, ['id' => $record->id]);
            }
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026092401, 'local', 'awareness');
    }

    return true;
}
