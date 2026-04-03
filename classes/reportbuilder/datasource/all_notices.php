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
use local_awareness\reportbuilder\local\entities\notice;

/**
 * All notices datasource for Report Builder.
 *
 * Exposes every notice (enabled or not) from local_awareness together with
 * the user who last modified each record.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class all_notices extends datasource {
    /**
     * Return user-friendly name of the datasource.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:allnotices', 'local_awareness');
    }

    /**
     * Initialise report.
     */
    protected function initialise(): void {
        $noticeentity = new notice();
        $noticealias  = $noticeentity->get_table_alias('local_awareness');

        $this->set_main_table('local_awareness', $noticealias);
        $this->add_entity($noticeentity);

        // User who last modified the notice.
        $userentity = new user();
        $useralias  = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_join(
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$noticealias}.usermodified"
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
            'notice:title',
            'notice:enabled',
            'notice:reqack',
            'notice:ack_count',
            'notice:dismiss_count',
            'notice:timestart',
            'notice:timeend',
            'notice:timecreated',
        ];
    }

    /**
     * Return the default column sort order.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return ['notice:timecreated' => SORT_DESC];
    }

    /**
     * Return the filters shown by default.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'notice:title',
            'notice:enabled',
            'notice:timestart',
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
