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
use local_awareness\helper;
use local_awareness\persistent\awareness as awareness_persistent;

/**
 * Notice entity for Report Builder.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
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
            ->set_is_sortable(true)
            ->add_callback(static fn($value): string => format_string(
                (string) ($value ?? ''),
                true,
                ['context' => \context_system::instance()]
            ));

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
            /*
             * Normalised in SQL rather than shipped raw. The stored value is a COURSE ID, while
             * the column type promises zero or one and Report Builder aggregates the field
             * arithmetically — so the percent aggregation over a course id reported a seven-digit
             * percentage. A searched CASE keeps the boolean contract the type declares, is plain
             * ANSI so it holds on both PostgreSQL and MariaDB, and leaves every stored
             * aggregation on this column still valid.
             */
            ->add_field("CASE WHEN {$alias}.reqcourse > 0 THEN 1 ELSE 0 END", 'reqcourse')
            ->set_type(column::TYPE_BOOLEAN)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return !empty($value) ? get_string('yes') : get_string('no');
            });

        /*
         * How insistent the notice is, as one ordered value. Derived in SQL from the two columns
         * that store it rather than added to the table, so there is no third copy to drift from
         * awareness::get_insistence() — the CASE below is that method, in ANSI, and the two must
         * be changed together. A searched CASE keeps this portable across PostgreSQL and MariaDB,
         * the same reason reqcourse above is written this way.
         */
        $columns[] = (new column(
            'insistence',
            new lang_string('report_notice:insistence', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_field(
                "CASE WHEN {$alias}.reqack = 1 THEN 2 WHEN {$alias}.outsideclick = 0 THEN 1 ELSE 0 END",
                'insistence'
            )
            ->set_type(column::TYPE_INTEGER)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                /*
                 * A literal per level. get_string() with a built identifier would read more
                 * tidily and is banned for a reason: nothing then proves the strings exist, and a
                 * missing one renders as its own identifier rather than failing.
                 */
                switch ((int) $value) {
                    case awareness_persistent::INSISTENCE_ACKNOWLEDGE:
                        return get_string('notice:insistence:acknowledge', 'local_awareness');
                    case awareness_persistent::INSISTENCE_BLOCKING:
                        return get_string('notice:insistence:blocking', 'local_awareness');
                    default:
                        return get_string('notice:insistence:informational', 'local_awareness');
                }
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
            /*
             * Deprecated rather than removed. Force logout no longer does anything at runtime, but
             * the column records what an author once asked for, and dropping it would silently
             * empty that column in every saved report carrying it — core drops an unknown column
             * from a report without telling anyone. Deprecating keeps the history readable and
             * puts the notice where the person building a report will see it.
             */
            ->set_is_deprecated(get_string('report_notice:forcelogout:deprecated', 'local_awareness'))
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
            ->set_is_sortable(true)
            /*
             * The stored value is a number of seconds, so with no callback the cell printed "86400".
             * Zero means the notice never repeats and renders empty — the manage table shows nothing
             * for it either, emitting no repeat chip at all, so this invents no second vocabulary.
             *
             * Two constraints that are not obvious. The type stays TYPE_INTEGER: under TYPE_TEXT the
             * sum/avg/min/max aggregations become incompatible, and datasource::get_active_columns()
             * applies a STORED aggregation without rechecking, so a saved report using one would
             * throw on view — the same trap RB-02 already worked around in this file. And the
             * parameter is ?float rather than ?int because avg() is compatible with an integer
             * column and hands the callback the averaged float under strict types; it stays nullable
             * because four datasources LEFT JOIN this table, so a row pointing at a deleted notice
             * delivers null.
             */
            ->add_callback(static function (?float $value, \stdClass $row): string {
                return empty($value) ? '' : format::format_time($value, $row);
            });

        $columns[] = (new column(
            'content',
            new lang_string('report_notice:content', 'local_awareness'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            /*
             * Content is stored as the author wrote it — @@PLUGINFILE@@ placeholders, unfiltered
             * markup — so it is rendered here exactly as the modal renders it, which is why the
             * format and the id ride along as extra fields. Content stays FIRST: the callback is
             * handed reset($values), so the field order is load-bearing and is pinned by a test
             * rather than by this comment.
             */
            ->add_fields("{$alias}.content, {$alias}.contentformat, {$alias}.id")
            ->set_type(column::TYPE_LONGTEXT)
            ->set_is_sortable(false)
            ->add_callback(static function (?string $value, \stdClass $row): string {
                if ($value === null) {
                    return '';
                }

                /*
                 * Under an aggregation that runs callbacks without rebuilding the column's fields —
                 * countdistinct on Moodle 4.5, where it extends base rather than count — only the
                 * first field is populated and $value is the aggregate, not the body. Rendering it
                 * would wrap a count in a FORMAT_MOODLE div, so hand it back untouched.
                 */
                if (!isset($row->contentformat) || !isset($row->id)) {
                    return $value;
                }

                return helper::render_content_parts($value, (int) $row->contentformat, (int) $row->id);
            });

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
