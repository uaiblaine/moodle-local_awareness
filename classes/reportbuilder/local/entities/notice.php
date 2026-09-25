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
            ->add_fields("{$alias}.title, {$alias}.courseid")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true)
            ->add_callback(static function ($value, \stdClass $row): string {
                // A course notice's title is filtered in its course; a site notice's, or an orphan's, at the site.
                $context = null;
                if ((int) ($row->courseid ?? 0) > 0) {
                    $context = \context_course::instance((int) $row->courseid, IGNORE_MISSING);
                }

                return format_string((string) ($value ?? ''), true, ['context' => $context ?: \context_system::instance()]);
            });

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
             * Normalised in SQL: the stored value is a course id, but Report Builder aggregates a
             * boolean column arithmetically, so percent over the raw id read as a seven-digit
             * percentage. The portable searched CASE keeps the zero-or-one contract of TYPE_BOOLEAN.
             */
            ->add_field("CASE WHEN {$alias}.reqcourse > 0 THEN 1 ELSE 0 END", 'reqcourse')
            ->set_type(column::TYPE_BOOLEAN)
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return !empty($value) ? get_string('yes') : get_string('no');
            });

        /*
         * How insistent the notice is, derived in SQL from reqack and outsideclick. The CASE below
         * is {@see awareness_persistent::get_insistence()} in portable SQL; keep the two in step.
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
                 * A literal string id per level, never a built one. Compared with >=, like every
                 * other reader of the level, so a level added above Acknowledge is not reported as
                 * Informational while the display treats it as insistent.
                 */
                $level = (int) $value;
                if ($level >= awareness_persistent::INSISTENCE_ACKNOWLEDGE) {
                    return get_string('notice:insistence:acknowledge', 'local_awareness');
                }
                if ($level >= awareness_persistent::INSISTENCE_BLOCKING) {
                    return get_string('notice:insistence:blocking', 'local_awareness');
                }
                return get_string('notice:insistence:informational', 'local_awareness');
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
             * the column records what an author once asked for, and core silently drops a column it
             * no longer knows from every saved report using it.
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
             * Seconds, shown as a duration. Zero means the notice never repeats and renders empty,
             * as the manage list shows no repeat chip for it.
             *
             * The type stays TYPE_INTEGER: under TYPE_TEXT the sum/avg/min/max aggregations become
             * incompatible, and a saved report using one would throw on view, because
             * column::set_aggregation() rejects an incompatible stored aggregation. The parameter is
             * ?float because avg hands the callback a float under strict types, and nullable because
             * four datasources LEFT JOIN this table, so a row pointing at a deleted notice gives null.
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
             * Content is stored as the author wrote it (@@PLUGINFILE@@ placeholders, unfiltered
             * markup), so it is rendered as the dialogue renders it, which needs the format and the
             * id. Content stays first: the callback receives the first field as $value, and
             * all_notices_test pins the order.
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
