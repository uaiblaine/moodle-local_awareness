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

declare(strict_types=1);

namespace local_awareness\reportbuilder\local\systemreports;

use context_course;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use core_text;
use lang_string;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\entities\acknowledgement;
use local_awareness\reportbuilder\local\entities\notice;
use moodle_url;
use pix_icon;

/**
 * Every acknowledgement and dismissal of one course's notices.
 *
 * The course's entry on its Reports page. One row per recorded action, acknowledged or dismissed,
 * across the course's own notices; the notice is a column, where the two per-notice reports scope
 * to one. The course id is passed as a parameter.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_notices extends system_report {
    use identity_fields;

    /**
     * Initialise report.
     */
    protected function initialise(): void {
        global $DB;

        $ackentity = new acknowledgement();
        $ackalias = $ackentity->get_table_alias('local_awareness_ack');

        $this->set_main_table('local_awareness_ack', $ackalias);
        $this->add_entity($ackentity);

        // Base fields needed for the row action (profile link).
        $this->add_base_fields("{$ackalias}.userid");

        /*
         * The rows are those of the notices this viewer may reach in the course, listed by id. The
         * course is read from each notice's own courseid, which never changes after creation, so a
         * row follows the notice it was recorded against. No reachable notice leaves the list empty
         * rather than the condition: an empty IN would be invalid SQL.
         */
        $courseid = $this->get_parameter('courseid', 0, PARAM_INT);
        $noticeids = self::reachable_notice_ids($courseid) ?: [0];
        [$insql, $inparams] = $DB->get_in_or_equal($noticeids, SQL_PARAMS_NAMED, database::generate_param_name() . '_');
        $this->add_base_condition_sql("{$ackalias}.noticeid {$insql}", $inparams);

        $userentity = new user();
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_join(
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$ackalias}.userid"
        ));

        // The live notice, for its title formatted in the course; the row's snapshot is formatted at the site.
        $noticeentity = new notice();
        $noticealias = $noticeentity->get_table_alias('local_awareness');
        $this->add_entity($noticeentity->add_join(
            "LEFT JOIN {" . awareness::TABLE . "} {$noticealias} ON {$noticealias}.id = {$ackalias}.noticeid"
        ));

        // The users' identity fields as core shows them in the course; see acknowledged_notice::initialise().
        $context = context_course::instance($courseid, IGNORE_MISSING);
        [$identitycolumns, $identityfilters] = $this->join_identity(
            "{$ackalias}.userid",
            $context ?: \context_system::instance()
        );
        $rest = ['notice:title', 'acknowledgement:action', 'acknowledgement:timecreated'];

        $this->add_column_from_entity('user:fullname');
        foreach ($identitycolumns as $identitycolumn) {
            $this->add_column($identitycolumn);
        }
        $this->add_columns_from_entities($rest);

        $this->add_filter_from_entity('user:fullname');
        foreach ($identityfilters as $identityfilter) {
            $this->add_filter($identityfilter);
        }
        $this->add_filters_from_entities($rest);

        $this->set_initial_sort_column('acknowledgement:timecreated', SORT_DESC);

        // By id and short name, for the reasons acknowledged_notice::initialise() gives for its title.
        $shortname = (string) $DB->get_field('course', 'shortname', ['id' => $courseid]);
        $downloadname = (object) [
            'id' => $courseid,
            'shortname' => core_text::substr(
                $context ? format_string($shortname, true, ['context' => $context, 'escape' => false]) : $shortname,
                0,
                60
            ),
        ];
        $this->set_downloadable(true, get_string('download:course', 'local_awareness', $downloadname));

        $this->add_action(new action(
            new moodle_url('/user/view.php', ['id' => ':userid', 'course' => $courseid]),
            new pix_icon('i/user', ''),
            [],
            false,
            new lang_string('viewprofile')
        ));
    }

    /**
     * The ids of the course's notices the current user may reach under its group rules.
     *
     * A notice aimed only at groups a confined viewer may not reach is left out, as the course's
     * notice list leaves it out ({@see helper::may_reach_groups()}).
     *
     * @param int $courseid The course.
     * @return int[]
     */
    private static function reachable_notice_ids(int $courseid): array {
        $ids = [];
        foreach (awareness::get_records(['courseid' => $courseid]) as $notice) {
            if (helper::may_reach_groups($notice)) {
                $ids[] = (int) $notice->get('id');
            }
        }

        return $ids;
    }

    /**
     * Check if the current user can view this report.
     *
     * Decided from the report's own courseid parameter, never from the context it was created in,
     * for the reason acknowledged_notice::can_view() gives. The site has no course scope, and
     * author_scope::course() throws on it. A course that is gone is left to require_author(), which
     * refuses it to course holders and admits the site capability, as it does for the list.
     *
     * @return bool
     */
    protected function can_view(): bool {
        $courseid = $this->get_parameter('courseid', 0, PARAM_INT);
        if ($courseid <= SITEID) {
            return false;
        }

        return helper::require_author(author_scope::course($courseid), 'viewreports', false);
    }
}
