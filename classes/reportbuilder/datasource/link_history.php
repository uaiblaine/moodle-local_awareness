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

namespace local_awareness\reportbuilder\datasource;

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use local_awareness\reportbuilder\local\entities\linkhistory;
use local_awareness\reportbuilder\local\entities\notice;

/**
 * Link click history datasource for Report Builder.
 *
 * Exposes local_awareness_hlinks_his (click events) joined to
 * local_awareness_hlinks (hyperlink definitions) and local_awareness (notices).
 *
 * The linkhistory entity owns the internal hlinks join; the notice entity
 * inherits those joins before adding its own join to local_awareness.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_history extends datasource {
    /**
     * Return user-friendly name of the datasource.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:linkhistory', 'local_awareness');
    }

    /**
     * Initialise report.
     */
    protected function initialise(): void {
        $lhentity = new linkhistory();
        $lhhalias = $lhentity->get_table_alias('local_awareness_hlinks_his');
        $hlalias  = $lhentity->get_table_alias('local_awareness_hlinks');

        $this->set_main_table('local_awareness_hlinks_his', $lhhalias);
        // Add_entity() calls initialise() which registers the hlinks internal join.
        $this->add_entity($lhentity);

        // Notice entity needs the hlinks join to resolve noticeid, plus its own join.
        // We chain $lhentity->get_joins() (which now includes the hlinks join) before
        // adding the join to local_awareness itself.
        $noticeentity = new notice();
        $noticealias  = $noticeentity->get_table_alias('local_awareness');
        $this->add_entity($noticeentity
            ->add_joins($lhentity->get_joins())
            ->add_join(
                "LEFT JOIN {local_awareness} {$noticealias} ON {$noticealias}.id = {$hlalias}.noticeid"
            ));

        // User entity — join via userid.
        $userentity = new user();
        $useralias  = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_join(
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$lhhalias}.userid"
        ));

        $this->add_all_from_entities();
    }

    /**
     * Return the columns shown by default.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullname',
            'notice:title',
            'linkhistory:linktext',
            'linkhistory:linkurl',
            'linkhistory:timecreated',
        ];
    }

    /**
     * Return the default column sort order.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return ['linkhistory:timecreated' => SORT_DESC];
    }

    /**
     * Return the filters shown by default.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'notice:title',
            'user:fullname',
            'linkhistory:timecreated',
        ];
    }

    /**
     * Return the conditions shown by default.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [];
    }
}
