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

        foreach ($contextlist->get_contexts() as $context) {
            $user = $contextlist->get_user();

            $sql1 = "SELECT lv.*
                       FROM {local_awareness_lastview} lv
                      WHERE lv.userid = :userid";

            $sql2 = "SELECT ack.*
                       FROM {local_awareness_ack} ack
                      WHERE ack.userid = :userid";

            $sql3 = "SELECT his.*
                       FROM {local_awareness_hlinks_his} his
                      WHERE his.userid = :userid";

            $sql4 = "SELECT job.*
                       FROM {local_awareness_audience_jobs} job
                      WHERE job.userid = :userid";

            $params = [
                'userid' => $user->id,
            ];

            $lastview = $DB->get_records_sql($sql1, $params);
            $acknowlegement = $DB->get_records_sql($sql2, $params);
            $linktracking = $DB->get_records_sql($sql3, $params);
            $audiencejobs = $DB->get_records_sql($sql4, $params);

            $data = (object)[
                'lastview' => $lastview,
                'acknowledgement' => $acknowlegement,
                'linktracking' => $linktracking,
                'audiencejobs' => $audiencejobs,
            ];

            $subcontext = [
                get_string('pluginname', 'local_awareness'),
            ];

            writer::with_context($context)->export_data($subcontext, $data);
        }
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
        $contexts = $contextlist->get_contexts();
        if (count($contexts) == 0) {
            return;
        }
        $context = reset($contexts);

        if ($context->contextlevel !== CONTEXT_USER) {
            return;
        }
        $userid = $context->instanceid;

        self::delete_all_data_for_userid($userid);
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

        if ($context instanceof \context_user) {
            $userid = $context->instanceid;
            self::delete_all_data_for_userid($userid);
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
        $collection->add_database_table(
            'local_awareness_ack',
            [
                'userid' => 'privacy:metadata:userid',
                'username' => 'privacy:metadata:username',
                'firstname' => 'privacy:metadata:firstname',
                'lastname' => 'privacy:metadata:lastname',
                'idnumber' => 'privacy:metadata:idnumber',
            ],
            'privacy:metadata:local_awareness_ack'
        );

        $collection->add_database_table(
            'local_awareness_hlinks_his',
            [
                'userid' => 'privacy:metadata:userid',
            ],
            'privacy:metadata:local_awareness_hlinks_his'
        );

        $collection->add_database_table(
            'local_awareness_lastview',
            [
                'userid' => 'privacy:metadata:userid',
            ],
            'privacy:metadata:local_awareness_lastview'
        );

        $collection->add_database_table(
            'local_awareness_audience_jobs',
            [
                'userid' => 'privacy:metadata:userid',
                'criteria' => 'privacy:metadata:criteria',
                'timecreated' => 'privacy:metadata:timecreated',
            ],
            'privacy:metadata:local_awareness_audience_jobs'
        );

        return $collection;
    }
}
