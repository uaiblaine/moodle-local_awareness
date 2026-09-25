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

use context_system;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use core_text;
use lang_string;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\acknowledgement as acknowledgement_persistent;
use local_awareness\persistent\awareness;
use local_awareness\reportbuilder\local\entities\acknowledgement;
use moodle_url;
use pix_icon;

/**
 * Dismissed notice system report.
 *
 * Renders a paged, filterable, downloadable list of users who have
 * dismissed a specific notice. The notice ID is passed as a parameter.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dismissed_notice extends system_report {
    /**
     * Initialise report.
     */
    protected function initialise(): void {
        global $DB;

        $ackentity = new acknowledgement();
        $ackalias  = $ackentity->get_table_alias('local_awareness_ack');

        $this->set_main_table('local_awareness_ack', $ackalias);
        $this->add_entity($ackentity);

        // Base fields needed for the row action (profile link).
        $this->add_base_fields("{$ackalias}.userid");

        // Restrict to this specific notice and dismissed rows only.
        $noticeid = $this->get_parameter('noticeid', 0, PARAM_INT);
        $this->add_base_condition_simple("{$ackalias}.noticeid", $noticeid);
        $actionparam = database::generate_param_name();
        $this->add_base_condition_sql(
            "{$ackalias}.action = :{$actionparam}",
            [$actionparam => acknowledgement_persistent::ACTION_DISMISSED]
        );

        // User entity (live profile data).
        $userentity = new user();
        $useralias  = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_join(
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$ackalias}.userid"
        ));

        /*
         * No notice entity: a base condition already scopes the report to one notice, so its fields
         * would repeat on every row. The title needed for the download name is read directly below.
         */

        $this->add_columns_from_entities([
            'user:fullname',
            'acknowledgement:username',
            'acknowledgement:idnumber',
            'acknowledgement:timecreated',
        ]);

        $this->add_filters_from_entities([
            'user:fullname',
            'acknowledgement:username',
            'acknowledgement:idnumber',
            'acknowledgement:timecreated',
        ]);

        $this->set_initial_sort_column('acknowledgement:timecreated', SORT_DESC);
        // The download name identifies the notice by id and title; see acknowledged_notice::initialise().
        $noticetitle = format_string(
            (string) $DB->get_field('local_awareness', 'title', ['id' => $noticeid]),
            true,
            ['context' => context_system::instance(), 'escape' => false]
        );
        $downloadname = (object) [
            'id' => $noticeid,
            'title' => core_text::substr($noticetitle, 0, 60),
        ];
        $this->set_downloadable(true, get_string('download:dismissed', 'local_awareness', $downloadname));

        // Row action: link to user profile.
        $this->add_action(new action(
            new moodle_url('/user/view.php', ['id' => ':userid']),
            new pix_icon('i/user', ''),
            [],
            false,
            new lang_string('viewprofile')
        ));
    }

    /**
     * Check if the current user can view this report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        // From the report's own noticeid, never its context, and within the group reach; see acknowledged_notice::can_view().
        $notice = awareness::get_record(['id' => $this->get_parameter('noticeid', 0, PARAM_INT)]);
        if (!$notice) {
            return false;
        }

        return helper::require_author(author_scope::of($notice), 'viewreports', false) && helper::may_reach_groups($notice);
    }
}
