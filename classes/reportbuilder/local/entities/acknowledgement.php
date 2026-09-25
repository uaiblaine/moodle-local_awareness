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
use core_reportbuilder\local\aggregation\avg;
use core_reportbuilder\local\aggregation\sum;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_awareness\persistent\acknowledgement as acknowledgement_persistent;

/**
 * Acknowledgement entity for Report Builder.
 *
 * Maps to local_awareness_ack which stores both dismissed (action=0) and
 * acknowledged (action=1) interactions.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acknowledgement extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['local_awareness_ack'];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_acknowledgement', 'local_awareness');
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
        $alias = $this->get_table_alias('local_awareness_ack');

        $columns[] = (new column(
            'username',
            new lang_string('report_ack:username', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.username")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static fn($value): string => s((string) ($value ?? '')));

        $columns[] = (new column(
            'firstname',
            new lang_string('report_ack:firstname', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.firstname")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static fn($value): string => s((string) ($value ?? '')));

        $columns[] = (new column(
            'lastname',
            new lang_string('report_ack:lastname', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.lastname")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static fn($value): string => s((string) ($value ?? '')));

        $columns[] = (new column(
            'idnumber',
            new lang_string('report_ack:idnumber', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.idnumber")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static fn($value): string => s((string) ($value ?? '')));

        $columns[] = (new column(
            'noticetitle',
            new lang_string('report_ack:noticetitle', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.noticetitle")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static fn($value): string => format_string(
                (string) ($value ?? ''),
                true,
                ['context' => \context_system::instance()]
            ));

        $columns[] = (new column(
            'action',
            new lang_string('report_ack:action', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.action")
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true)
            ->add_callback([self::class, 'format_action']);

        $columns[] = (new column(
            'timecreated',
            new lang_string('report_ack:timecreated', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$alias}.timecreated")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Format the action column: the action's name, or a number under Sum and Average.
     *
     * Sum is the number of acknowledgements among the grouped rows and Average their share, so
     * neither names an action even when it equals 0 or 1. Min, Max and the concatenations keep the
     * action's own values and show their names. The aggregate is read from the row rather than from
     * $value, because 4.5 casts $value to this column's integer type first, which turns an Average of
     * 0.5 into 0.
     *
     * $value is untyped: under an aggregation core passes the aggregate from strict-typed code, and
     * Average's float would raise a TypeError on an int parameter.
     *
     * @param mixed $value The action, or the aggregate cast to the column type.
     * @param \stdClass $row The column's values as the query returned them, keyed by field alias.
     * @param mixed $arguments The callback's additional arguments; none are passed.
     * @param string|null $aggregation Name of the aggregation applied to the column, null for none.
     * @return string
     */
    public static function format_action($value, \stdClass $row, $arguments = null, ?string $aggregation = null): string {
        if ($value === null || $value === '') {
            return '';
        }
        if ($aggregation === sum::get_class_name()) {
            return format_float((float) ($row->action ?? $value), 0);
        }
        if ($aggregation === avg::get_class_name()) {
            return format_float((float) ($row->action ?? $value), 2);
        }
        if ((float) $value === (float) acknowledgement_persistent::ACTION_ACKNOWLEDGED) {
            return get_string('report_ack:action_acknowledged', 'local_awareness');
        }
        if ((float) $value === (float) acknowledgement_persistent::ACTION_DISMISSED) {
            return get_string('report_ack:action_dismissed', 'local_awareness');
        }
        return format_float((float) $value, 2);
    }

    /**
     * Return all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias('local_awareness_ack');

        $filters[] = (new filter(
            select::class,
            'action',
            new lang_string('report_ack:action', 'local_awareness'),
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
            new lang_string('report_ack:timecreated', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.timecreated"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            text::class,
            'username',
            new lang_string('report_ack:username', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.username"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            text::class,
            'idnumber',
            new lang_string('report_ack:idnumber', 'local_awareness'),
            $this->get_entity_name(),
            "{$alias}.idnumber"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
