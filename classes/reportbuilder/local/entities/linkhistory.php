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
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Link history entity for Report Builder.
 *
 * Maps to local_awareness_hlinks_his (click events) joined to
 * local_awareness_hlinks (hyperlink definitions). The internal join between
 * these two tables is set up inside initialise() so that all columns and
 * filters automatically include it.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class linkhistory extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'local_awareness_hlinks_his',
            'local_awareness_hlinks',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_linkhistory', 'local_awareness');
    }

    /**
     * Initialise the entity.
     *
     * The internal join from click-history rows to their hyperlink definition is
     * registered here so every column and filter that calls add_joins() will
     * automatically include it.
     *
     * @return base
     */
    public function initialise(): base {
        $lhhalias = $this->get_table_alias('local_awareness_hlinks_his');
        $hlalias  = $this->get_table_alias('local_awareness_hlinks');

        // Join hlinks_his → hlinks (internal to this entity).
        $this->add_join(
            "JOIN {local_awareness_hlinks} {$hlalias} ON {$hlalias}.id = {$lhhalias}.hlinkid"
        );

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
        $lhhalias = $this->get_table_alias('local_awareness_hlinks_his');
        $hlalias  = $this->get_table_alias('local_awareness_hlinks');

        $columns[] = (new column(
            'timecreated',
            new lang_string('report_lh:timecreated', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$lhhalias}.timecreated")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        $columns[] = (new column(
            'linktext',
            new lang_string('report_lh:linktext', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$hlalias}.text")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        $columns[] = (new column(
            'linkurl',
            new lang_string('report_lh:linkurl', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$hlalias}.link")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true);

        return $columns;
    }

    /**
     * Return all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $lhhalias = $this->get_table_alias('local_awareness_hlinks_his');
        $hlalias  = $this->get_table_alias('local_awareness_hlinks');

        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('report_lh:timecreated', 'local_awareness'),
            $this->get_entity_name(),
            "{$lhhalias}.timecreated"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            text::class,
            'linktext',
            new lang_string('report_lh:linktext', 'local_awareness'),
            $this->get_entity_name(),
            "{$hlalias}.text"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
