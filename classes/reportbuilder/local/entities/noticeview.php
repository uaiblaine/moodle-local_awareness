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

namespace local_awareness\reportbuilder\local\entities;

use lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_awareness\persistent\acknowledgement as acknowledgement_persistent;

/**
 * Notice view entity for Report Builder.
 *
 * Maps to local_awareness_lastview which holds one row per (userid, noticeid),
 * recording when a user last viewed a notice and the action taken.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class noticeview extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['local_awareness_lastview'];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_noticeview', 'local_awareness');
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Return all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $alias = $this->get_table_alias('local_awareness_lastview');

        $columns[] = (new column(
            'action',
            new lang_string('report_nv:action', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.action")
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true)
            ->add_callback(static function (?int $value): string {
                if ($value === acknowledgement_persistent::ACTION_ACKNOWLEDGED) {
                    return get_string('report_ack:action_acknowledged', 'local_awareness');
                }
                return get_string('report_ack:action_dismissed', 'local_awareness');
            });

        $columns[] = (new column(
            'timecreated',
            new lang_string('report_nv:timecreated', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timecreated")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'timemodified',
            new lang_string('report_nv:timemodified', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timemodified")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Return all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias('local_awareness_lastview');

        $filters[] = (new filter(
            select::class,
            'action',
            new lang_string('report_nv:action', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.action"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return [
                    acknowledgement_persistent::ACTION_DISMISSED => get_string(
                        'report_ack:action_dismissed',
                        'local_awareness'
                    ),
                    acknowledgement_persistent::ACTION_ACKNOWLEDGED => get_string(
                        'report_ack:action_acknowledged',
                        'local_awareness'
                    ),
                ];
            });

        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('report_nv:timecreated', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.timecreated"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timemodified',
            new lang_string('report_nv:timemodified', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.timemodified"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
