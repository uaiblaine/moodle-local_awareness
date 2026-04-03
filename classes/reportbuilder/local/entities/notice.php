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
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Notice entity for Report Builder.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notice extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['local_awareness'];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_notice', 'local_awareness');
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
        $alias = $this->get_table_alias('local_awareness');

        $columns[] = (new column(
            'title',
            new lang_string('report_notice:title', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.title")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'enabled',
            new lang_string('report_notice:enabled', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.enabled")
            ->set_type(column::TYPE_BOOLEAN)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return !empty($value) ? get_string('yes') : get_string('no');
            });

        $columns[] = (new column(
            'reqack',
            new lang_string('report_notice:reqack', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.reqack")
            ->set_type(column::TYPE_BOOLEAN)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return !empty($value) ? get_string('yes') : get_string('no');
            });

        $columns[] = (new column(
            'reqcourse',
            new lang_string('report_notice:reqcourse', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.reqcourse")
            ->set_type(column::TYPE_BOOLEAN)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return !empty($value) ? get_string('yes') : get_string('no');
            });

        $columns[] = (new column(
            'forcelogout',
            new lang_string('report_notice:forcelogout', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.forcelogout")
            ->set_type(column::TYPE_BOOLEAN)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return !empty($value) ? get_string('yes') : get_string('no');
            });

        $columns[] = (new column(
            'timestart',
            new lang_string('report_notice:timestart', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timestart")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'timeend',
            new lang_string('report_notice:timeend', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timeend")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'timecreated',
            new lang_string('report_notice:timecreated', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timecreated")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'timemodified',
            new lang_string('report_notice:timemodified', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timemodified")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'resetinterval',
            new lang_string('report_notice:resetinterval', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.resetinterval")
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'content',
            new lang_string('report_notice:content', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.content")
            ->set_type(column::TYPE_LONGTEXT)
            ->set_is_sortable(false);

        $columns[] = (new column(
            'ack_count',
            new lang_string('report_notice:ack_count', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_field(
                "(SELECT COUNT(1) FROM {local_awareness_ack} ack WHERE ack.noticeid = {$alias}.id AND ack.action = 1)",
                'ack_count'
            )
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'dismiss_count',
            new lang_string('report_notice:dismiss_count', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_field(
                "(SELECT COUNT(1) FROM {local_awareness_ack} dis WHERE dis.noticeid = {$alias}.id AND dis.action = 0)",
                'dismiss_count'
            )
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Return all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias('local_awareness');

        $filters[] = (new filter(
            text::class,
            'title',
            new lang_string('report_notice:title', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.title"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            boolean_select::class,
            'enabled',
            new lang_string('report_notice:enabled', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.enabled"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            boolean_select::class,
            'reqack',
            new lang_string('report_notice:reqack', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.reqack"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timestart',
            new lang_string('report_notice:timestart', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.timestart"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timeend',
            new lang_string('report_notice:timeend', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.timeend"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('report_notice:timecreated', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.timecreated"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
