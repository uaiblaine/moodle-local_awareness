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

namespace local_awareness\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use local_awareness\persistent\noticeview;

/**
 * Privacy Subsystem implementation.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Gets contexts for user.
     *
     * @param int $userid user ID.
     * @return \core_privacy\local\request\contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        /*
         * Every user-linked table has to be considered, not just lastview. A link click writes
         * local_awareness_hlinks_his on its own (the modal stays open, so no view record exists
         * yet), and an audience-estimate job writes local_awareness_audience_jobs with no view
         * record at all. Driving the contextlist off lastview alone left those rows outside both
         * the export and the erasure, while the site reported success.
         */
        $sql = "SELECT c.id
                  FROM {context} c
                 WHERE c.contextlevel = :contextuser
                   AND c.instanceid = :userid
                   AND (EXISTS (SELECT 1
                                  FROM {local_awareness_lastview} lv
                                 WHERE lv.userid = c.instanceid)
                     OR EXISTS (SELECT 1
                                  FROM {local_awareness_ack} ack
                                 WHERE ack.userid = c.instanceid)
                     OR EXISTS (SELECT 1
                                  FROM {local_awareness_hlinks_his} his
                                 WHERE his.userid = c.instanceid)
                     OR EXISTS (SELECT 1
                                  FROM {local_awareness_audience_jobs} job
                                 WHERE job.userid = c.instanceid))";

        $params = [
            'contextuser'   => CONTEXT_USER,
            'userid'        => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Exports user data.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Context list.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();

        /*
         * Only the subject's OWN user context. The loop used to export into every context in the
         * list without ever reading $context, so each one received the identical, complete payload.
         * get_contexts_for_userid() only ever adds this user's own context, so today the list
         * cannot hold another — but delete_data_for_user() carries exactly this check a few methods
         * below, and an export that trusts what an erasure verifies is the asymmetry worth removing.
         */
        $usercontext = null;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_USER && (int) $context->instanceid === (int) $user->id) {
                $usercontext = $context;
            }
        }

        if ($usercontext === null) {
            return;
        }

        $params = ['userid' => $user->id];

        $lastview = $DB->get_records_sql(
            "SELECT lv.* FROM {local_awareness_lastview} lv WHERE lv.userid = :userid",
            $params
        );
        $acknowledgement = $DB->get_records_sql(
            "SELECT ack.* FROM {local_awareness_ack} ack WHERE ack.userid = :userid",
            $params
        );
        $linktracking = $DB->get_records_sql(
            "SELECT his.* FROM {local_awareness_hlinks_his} his WHERE his.userid = :userid",
            $params
        );
        $audiencejobs = $DB->get_records_sql(
            "SELECT job.* FROM {local_awareness_audience_jobs} job WHERE job.userid = :userid",
            $params
        );

        $titles = self::notice_titles(array_merge($lastview, $acknowledgement, $audiencejobs));
        $links = self::link_targets($linktracking);

        $data = (object) [
            'lastview' => self::readable($lastview, $titles),
            'acknowledgement' => self::readable($acknowledgement, $titles),
            'linktracking' => self::readable($linktracking, [], $links),
            'audiencejobs' => self::readable($audiencejobs, $titles),
        ];

        $subcontext = [
            get_string('pluginname', 'local_awareness'),
        ];

        writer::with_context($usercontext)->export_data($subcontext, $data);
    }

    /**
     * Notice titles for every noticeid appearing in a set of rows.
     *
     * One query for the whole export rather than one per row: an export runs over everything a user
     * ever met, and a per-row lookup turns that into an N+1 inside a data request.
     *
     * @param array $rows Rows that may carry a noticeid.
     * @return array Notice id => title.
     */
    private static function notice_titles(array $rows): array {
        global $DB;

        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row->noticeid)) {
                $ids[(int) $row->noticeid] = true;
            }
        }

        if (empty($ids)) {
            return [];
        }

        return $DB->get_records_list('local_awareness', 'id', array_keys($ids), '', 'id, title');
    }

    /**
     * The link text and address behind every hlinkid appearing in a set of rows.
     *
     * @param array $rows Click-history rows.
     * @return array Link id => record carrying text and link.
     */
    private static function link_targets(array $rows): array {
        global $DB;

        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row->hlinkid)) {
                $ids[(int) $row->hlinkid] = true;
            }
        }

        if (empty($ids)) {
            return [];
        }

        return $DB->get_records_list('local_awareness_hlinks', 'id', array_keys($ids), '', 'id, text, link');
    }

    /**
     * Turn stored rows into something a person reading their own data export can understand.
     *
     * Timestamps go out as dates rather than as unix integers, and an internal id is accompanied by
     * the thing it names. The id columns stay: a data request is also a record, and dropping them
     * would make the export impossible to reconcile against the site.
     *
     * A referenced notice or link may have been deleted since — the click history deliberately
     * outlives the notice — so a missing target is simply left unnamed rather than treated as an
     * error.
     *
     * @param array $rows Rows straight from the database.
     * @param array $titles Notice id => title, from notice_titles().
     * @param array $links Link id => record, from link_targets().
     * @return array The same rows, with dates formatted and references named.
     */
    private static function readable(array $rows, array $titles, array $links = []): array {
        $out = [];
        foreach ($rows as $key => $row) {
            $row = clone $row;

            foreach (['timecreated', 'timemodified', 'timecompleted'] as $field) {
                if (!empty($row->$field)) {
                    $row->$field = transform::datetime((int) $row->$field);
                }
            }

            if (!empty($row->noticeid) && isset($titles[(int) $row->noticeid])) {
                $row->noticename = format_string(
                    $titles[(int) $row->noticeid]->title,
                    true,
                    ['context' => \context_system::instance()]
                );
            }

            if (!empty($row->hlinkid) && isset($links[(int) $row->hlinkid])) {
                $row->linktext = $links[(int) $row->hlinkid]->text;
                $row->linkurl = $links[(int) $row->hlinkid]->link;
            }

            $out[$key] = $row;
        }

        return $out;
    }

    /**
     * Delete all data for users in provided context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }
        $userid = $context->instanceid;

        self::delete_all_data_for_userid($userid);
    }

    /**
     * Delete data for a user.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Context list.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;

        /*
         * Every approved context, not just the first, and the userid comes from the contextlist
         * rather than from the context. Taking it from the context happens to agree while the
         * list holds one user context, and stops agreeing the moment it does not — at which
         * point the plugin would erase whoever the first context happened to name.
         */
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_USER && (int) $context->instanceid === $userid) {
                self::delete_all_data_for_userid($userid);
                return;
            }
        }
    }

    /**
     * Gets users in a context.
     *
     * @param \core_privacy\local\request\userlist $userlist user list.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_user) {
            return;
        }

        $params = ['contextid' => $context->id, 'contextlevel' => CONTEXT_USER];

        // Same reasoning as get_contexts_for_userid(): a user can hold link-click or
        // audience-job rows without ever having a view record, and driving this off lastview
        // alone meant delete_data_for_users() was never called for them.
        $sql = "SELECT c.instanceid AS userid
                  FROM {context} c
                 WHERE c.id = :contextid
                   AND c.contextlevel = :contextlevel
                   AND (EXISTS (SELECT 1
                                  FROM {local_awareness_lastview} lv
                                 WHERE lv.userid = c.instanceid)
                     OR EXISTS (SELECT 1
                                  FROM {local_awareness_ack} ack
                                 WHERE ack.userid = c.instanceid)
                     OR EXISTS (SELECT 1
                                  FROM {local_awareness_hlinks_his} his
                                 WHERE his.userid = c.instanceid)
                     OR EXISTS (SELECT 1
                                  FROM {local_awareness_audience_jobs} job
                                 WHERE job.userid = c.instanceid))";

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Delete data for users.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist User list.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();

        if (!($context instanceof \context_user)) {
            return;
        }

        /*
         * Driven by the APPROVED ids, not by the context's instanceid. The two agree whenever the
         * approver said yes, which is why the shortcut survives review — but when the approver
         * withheld a user the list arrives empty and the shortcut erases them anyway, turning a
         * refusal into a deletion. The context still bounds what may be touched.
         */
        foreach ($userlist->get_userids() as $userid) {
            if ((int) $userid === (int) $context->instanceid) {
                self::delete_all_data_for_userid((int) $userid);
            }
        }
    }

    /**
     * Remove every row this plugin holds for one user, across all four user-linked tables.
     *
     * Shared by the three deletion entry points so a table added later cannot be wired into one
     * path and forgotten in the others.
     *
     * @param int $userid User id.
     */
    private static function delete_all_data_for_userid(int $userid): void {
        global $DB;

        $DB->delete_records('local_awareness_lastview', ['userid' => $userid]);
        $DB->delete_records('local_awareness_hlinks_his', ['userid' => $userid]);
        $DB->delete_records('local_awareness_ack', ['userid' => $userid]);
        $DB->delete_records('local_awareness_audience_jobs', ['userid' => $userid]);

        // The view records are also held in a MODE_APPLICATION cache keyed by user id, which a
        // bulk delete does not touch.
        noticeview::purge_user_cache($userid);
    }

    /**
     * Returns metadata.
     *
     * @param \core_privacy\local\metadata\collection $collection Collection.
     * @return \core_privacy\local\metadata\collection
     */
    public static function get_metadata(collection $collection): collection {
        /*
         * Every column export_user_data() actually ships. It selects lv.*, ack.*, his.* and job.*,
         * so the whole row reaches the export file — while this declaration named a subset, which
         * is the half of the privacy contract a data subject reads BEFORE deciding whether to ask.
         * A narrower declaration than the export is not a smaller disclosure; it is an inaccurate
         * one.
         */
        $collection->add_database_table(
            'local_awareness_ack',
            [
                'userid' => 'privacy:metadata:userid',
                'username' => 'privacy:metadata:username',
                'firstname' => 'privacy:metadata:firstname',
                'lastname' => 'privacy:metadata:lastname',
                'idnumber' => 'privacy:metadata:idnumber',
                'noticeid' => 'privacy:metadata:noticeid',
                'noticetitle' => 'privacy:metadata:noticetitle',
                'action' => 'privacy:metadata:action',
                'timecreated' => 'privacy:metadata:timecreated',
            ],
            'privacy:metadata:local_awareness_ack'
        );

        $collection->add_database_table(
            'local_awareness_hlinks_his',
            [
                'userid' => 'privacy:metadata:userid',
                'hlinkid' => 'privacy:metadata:hlinkid',
                'timecreated' => 'privacy:metadata:timecreated',
            ],
            'privacy:metadata:local_awareness_hlinks_his'
        );

        $collection->add_database_table(
            'local_awareness_lastview',
            [
                'userid' => 'privacy:metadata:userid',
                'noticeid' => 'privacy:metadata:noticeid',
                'action' => 'privacy:metadata:action',
                'timecreated' => 'privacy:metadata:timecreated',
                'timemodified' => 'privacy:metadata:timemodified',
            ],
            'privacy:metadata:local_awareness_lastview'
        );

        $collection->add_database_table(
            'local_awareness_audience_jobs',
            [
                'userid' => 'privacy:metadata:userid',
                'jobid' => 'privacy:metadata:jobid',
                'noticeid' => 'privacy:metadata:noticeid',
                'criteriahash' => 'privacy:metadata:criteriahash',
                'criteria' => 'privacy:metadata:criteria',
                'status' => 'privacy:metadata:status',
                'resultcount' => 'privacy:metadata:resultcount',
                'breakdown' => 'privacy:metadata:breakdown',
                'errormsg' => 'privacy:metadata:errormsg',
                'timecreated' => 'privacy:metadata:timecreated',
                'timecompleted' => 'privacy:metadata:timecompleted',
            ],
            'privacy:metadata:local_awareness_audience_jobs'
        );

        /*
         * A notice is site configuration rather than one person's data, but core\persistent stamps
         * the author into local_awareness.usermodified on every create and update, so a user id is
         * stored here and has to be declared. Core declares exactly this shape for admin-authored
         * configuration and then declines to act on it — analytics_models, analytics_models_log
         * and the oauth2_* tables each carry a usermodified-only entry with no export and no
         * erasure. Blanking this column would rewrite the record of who published a site-wide
         * notice, so it is declared and deliberately left out of the contextlist, the export and
         * every delete path.
         */
        $collection->add_database_table(
            'local_awareness',
            [
                'usermodified' => 'privacy:metadata:usermodified',
            ],
            'privacy:metadata:local_awareness'
        );

        return $collection;
    }
}
