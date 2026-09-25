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

namespace local_awareness\table;

use local_awareness\audience\notice_audience;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\local\group_scope;
use local_awareness\local\collision;
use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;
use moodle_url;
use renderable;
use table_sql;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table to show list of existing notices.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class all_notices extends table_sql implements \core_table\dynamic, renderable {
    /** @var int Rows per page. */
    public const PER_PAGE = 25;

    /** @var array Notice id to the titles of the repeating notices it competes with, for this page of rows. */
    protected $clashtitles = [];

    /** @var array|null Cohort id to name, resolved once for the whole page of rows. */
    protected $cohortnames = null;

    /** @var array|null Names of every group a row on this page targets, id => name, read once per page. */
    protected $groupnames = null;

    /** @var array Criteria hashes with a job in flight, for this page of rows. */
    protected $inflight = [];

    /** @var array Course names for the course notices on this page, keyed by course id; site mode only. */
    private array $coursenames = [];

    /**
     * all_notices constructor.
     *
     * Everything after $uniqueid is optional because the dynamic-table web service constructs the
     * class with the unique id alone (\core_table\external\dynamic\get) and then feeds it a
     * filterset. guess_base_url() supplies the URL in that path.
     *
     * @param string $uniqueid table unique id
     * @param \moodle_url|null $url base url
     * @param int $page current page
     * @param int $perpage number of records per page
     */
    public function __construct(string $uniqueid, ?\moodle_url $url = null, int $page = 0, int $perpage = self::PER_PAGE) {
        parent::__construct($uniqueid);

        $this->set_attribute('class', 'local-awareness awarenesss');

        /*
         * The table's accessible name, which screen readers announce when listing a page's tables.
         * Visually hidden because the page heading already says it.
         */
        $this->set_caption(
            get_string('manage:table:caption', 'local_awareness'),
            ['class' => 'visually-hidden']
        );

        // When $page is 0, setup() reads the page number from the request itself.
        $this->pagesize = $perpage;
        $this->currpage = $page;

        $this->define_table_columns();

        $this->define_table_configs($url ?? new moodle_url('/local/awareness/managenotice.php'));
    }

    /**
     * The filterset class this table accepts.
     *
     * @return string Fully qualified class name.
     */
    public static function get_filterset_class(): string {
        return all_notices_filterset::class;
    }

    /**
     * The context the dynamic-table web service validates against.
     *
     * Derived from the scope, never from a context id the client sends. The scope is read from the
     * filterset, which is all the AJAX refresh rebuilds the table from, so this, has_capability()
     * and the query always describe the same list.
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->scope()->context();
    }

    /**
     * Whether the current user may see this table.
     *
     * @return bool
     */
    public function has_capability(): bool {
        $scope = $this->scope();

        return helper::require_author($scope, 'manage', false) || helper::require_author($scope, 'viewreports', false);
    }

    /**
     * Base URL used when the table is refreshed over AJAX.
     *
     * @return void
     */
    public function guess_base_url(): void {
        $this->baseurl = $this->page_url('/local/awareness/managenotice.php');
    }

    /**
     * The scope this list is for: the course in the filterset, or the site.
     *
     * An absent filterset, or a courseid filter at or below SITEID, is the site.
     *
     * @return author_scope
     */
    private function scope(): author_scope {
        $filterset = $this->get_filterset();
        if ($filterset !== null && $filterset->has_filter('courseid')) {
            $values = $filterset->get_filter('courseid')->get_filter_values();
            $courseid = (int) reset($values);
            if ($courseid > SITEID) {
                return author_scope::course($courseid);
            }
        }

        return author_scope::site();
    }

    /**
     * A plugin page URL that keeps the list's scope.
     *
     * @param string $path The page.
     * @param array $params Its parameters.
     * @return moodle_url
     */
    private function page_url(string $path, array $params = []): moodle_url {
        $scope = $this->scope();
        if (!$scope->is_site()) {
            $params['courseid'] = $scope->get_courseid();
        }

        return new moodle_url($path, $params);
    }

    /**
     * How many rows match the current filters, across every page.
     *
     * Reads flexible_table's public $totalrows, which pagesize() sets from the filtered count in
     * query_db(). Do not redeclare that property here: a narrower visibility or a type on the
     * redeclaration is a fatal error.
     *
     * @return int
     */
    public function get_total_rows(): int {
        return (int) $this->totalrows;
    }

    /**
     * Table columns and corresponding headers.
     */
    protected function define_table_columns() {
        /*
         * Boolean settings are chips in the behaviour column, drawn only when on, rather than
         * yes/no columns: an on/off pair told apart by colour is invisible to a reader with a
         * colour vision deficiency. Status is a column of its own, not a badge on the title,
         * because the list filters on it.
         */
        $cols = [
            'title' => get_string('notice:title', 'local_awareness'),
            'status' => get_string('notice:status', 'local_awareness'),
            'behaviour' => get_string('notice:behaviour', 'local_awareness'),
            'audience' => get_string('notice:audience', 'local_awareness'),
            'validity' => get_string('notice:validity', 'local_awareness'),
            'actions' => get_string('actions'),
        ];

        $this->define_columns(array_keys($cols));
        $this->define_headers(array_values($cols));
        $this->column_class('actions', 'text-end');
    }

    /**
     * Define table configuration.
     *
     * @param \moodle_url $url
     */
    protected function define_table_configs(\moodle_url $url) {
        $this->define_baseurl($url);

        $this->collapsible(false);
        $this->sortable(false);
        $this->pageable(true);
    }

    /**
     * Fetch one page of the filtered notices, and resolve once per page what the columns look up.
     *
     * @param int $pagesize number of records to fetch
     * @param bool $useinitialsbar initial bar
     */
    public function query_db($pagesize, $useinitialsbar = true): void {
        global $DB;

        [$where, $params] = $this->build_filter_sql();
        $table = '{' . awareness::TABLE . '}';

        // The filtered total lands on $this->totalrows via pagesize(), which is what the pager reads.
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM $table WHERE $where", $params);
        $this->pagesize($pagesize, $total);

        $sql = "SELECT * FROM $table WHERE $where ORDER BY enabled DESC, timemodified DESC, id DESC";
        $records = $DB->get_records_sql($sql, $params, $this->get_page_start(), $this->get_page_size());

        foreach ($records as $record) {
            // Same hydration persistent::get_records() does, so every col_* method still sees a persistent.
            $this->rawdata[] = new awareness(0, $record);
        }

        // The site list shows which course a course notice belongs to; one query for the page.
        $this->coursenames = [];
        if ($this->scope()->is_site()) {
            $courseids = [];
            foreach ($this->rawdata ?? [] as $notice) {
                if ((int) $notice->get('courseid') > 0) {
                    $courseids[(int) $notice->get('courseid')] = true;
                }
            }
            if (!empty($courseids)) {
                $this->coursenames = $DB->get_records_list('course', 'id', array_keys($courseids), '', 'id, fullname');
            }
        }

        /*
         * Resolved once per page rather than per row, which would re-read every repeating notice
         * for each row. rawdata stays null, not [], when the query returns nothing.
         */
        $this->clashtitles = collision::clash_titles_for($this->rawdata ?? [], $this->scope());
        // Same shape as the line above: one query for the page, not one per row in col_audience().
        $this->inflight = audience_job::in_flight_hashes();

        if ($useinitialsbar) {
            $this->initialbars($total > $pagesize);
        }
    }

    /**
     * How many notices matched, printed just above the rows.
     *
     * start_html() calls this inside the dynamic-table wrapper, so the AJAX refresh replaces the
     * count along with the rows and it always describes the list on screen. Printed by the page
     * around the table, it would need JavaScript to re-read and re-format the total on every
     * refresh.
     *
     * @return void
     */
    public function wrap_html_start() {
        global $OUTPUT;

        echo $OUTPUT->render_from_template('local_awareness/manage/resultcount', [
            'count' => number_format($this->get_total_rows()),
        ]);
    }

    /**
     * What an empty result looks like.
     *
     * Rendered by the table, not the page, because the AJAX refresh replaces only the table's own
     * HTML. With filters active it offers to clear them; with none, to create a notice.
     *
     * @return void
     */
    public function print_nothing_to_display(): void {
        global $OUTPUT;

        echo $this->get_dynamic_table_html_start();
        echo $this->render_reset_button();
        $this->print_initials_bar();

        if ($this->has_active_filters()) {
            echo $OUTPUT->render_from_template('local_awareness/manage/empty', [
                'message' => get_string('manage:empty:filtered', 'local_awareness'),
                'showclear' => true,
                'clearlabel' => get_string('manage:filter:clear', 'local_awareness'),
            ]);
        } else {
            echo $OUTPUT->render_from_template('local_awareness/manage/empty', [
                'message' => get_string('manage:empty:none', 'local_awareness'),
                'showcreate' => true,
                'createurl' => $this->page_url(
                    '/local/awareness/editnotice.php',
                    ['noticeid' => 0, 'sesskey' => sesskey()]
                )->out(false),
                'createlabel' => get_string('notice:create', 'local_awareness'),
            ]);
        }

        echo $this->get_dynamic_table_html_end();
    }

    /**
     * Whether the current request carries a filter that actually narrows anything.
     *
     * @return bool
     */
    protected function has_active_filters(): bool {
        $filterset = $this->get_filterset();
        if ($filterset === null) {
            return false;
        }

        foreach (['name', 'status', 'validity'] as $name) {
            if (!$filterset->has_filter($name)) {
                continue;
            }
            $values = $filterset->get_filter($name)->get_filter_values();
            if (trim((string) reset($values)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A predicate excluding the notices whose groups the current user may not reach, or nothing.
     *
     * The candidates are the rows naming a group at all, found by a LIKE on the JSON column for
     * group_scope::FIELD; a row without that key cannot be excluded. Each candidate is decided by
     * group_scope::admits() for its own course, memoised per course. A site notice is never
     * excluded, even one whose stored JSON names a group, so a site administrator can still reach
     * it.
     *
     * @param author_scope $scope The list's scope.
     * @return array [sql, params], the sql empty when nothing is excluded.
     */
    protected function unreachable_notices_sql(author_scope $scope): array {
        global $DB;

        $where = $DB->sql_like('filtervalues', ':grpkey');
        $params = ['grpkey' => '%' . $DB->sql_like_escape('"' . group_scope::FIELD . '"') . '%'];
        if (!$scope->is_site()) {
            $where .= ' AND courseid = :grpcourseid';
            $params['grpcourseid'] = $scope->get_courseid();
        }
        $candidates = $DB->get_records_select(awareness::TABLE, $where, $params, '', 'id, courseid, filtervalues');

        $excluded = [];
        $reach = [];
        foreach ($candidates as $record) {
            $courseid = (int) $record->courseid;
            $targets = group_scope::decode($record->filtervalues);
            if ($targets === [] || $courseid <= SITEID) {
                continue;
            }
            $reach[$courseid] ??= group_scope::for_author(author_scope::course($courseid));
            if (!$reach[$courseid]->admits($targets)) {
                $excluded[] = (int) $record->id;
            }
        }
        if ($excluded === []) {
            return ['', []];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($excluded, SQL_PARAMS_NAMED, 'grpx', false);

        return ["id {$insql}", $inparams];
    }

    /**
     * Turn the filterset into a WHERE clause and its parameters.
     *
     * Every filter is a SQL predicate, never a post-query array_filter: narrowing the rows after
     * the query would show short pages while the pager counted the unfiltered total.
     *
     * @return array [where clause, parameters]
     * @throws \coding_exception
     * @throws \dml_exception
     */
    protected function build_filter_sql(): array {
        global $DB;

        $wheres = ['1 = 1'];
        $params = [];
        $filterset = $this->get_filterset();

        if ($filterset === null) {
            return [implode(' AND ', $wheres), $params];
        }

        // The list's scope: a course page lists that course's notices and nothing else.
        $scope = $this->scope();
        if (!$scope->is_site()) {
            $wheres[] = 'courseid = :courseid';
            $params['courseid'] = $scope->get_courseid();
        }

        /*
         * A notice naming a group this viewer may not reach (separate groups mode without
         * moodle/site:accessallgroups) is not listed; see group_scope::admits(). Excluded by id in
         * SQL, like every other filter here, so the pager's count stays right.
         */
        [$unreachablesql, $unreachableparams] = $this->unreachable_notices_sql($scope);
        if ($unreachablesql !== '') {
            $wheres[] = $unreachablesql;
            $params += $unreachableparams;
        }

        if ($filterset->has_filter('name')) {
            $values = $filterset->get_filter('name')->get_filter_values();
            $needle = trim((string) reset($values));
            if ($needle !== '') {
                /*
                 * Accent- and case-insensitive where the database supports it, so that "manutencao"
                 * finds "Manutenção". See helper::sql_like_ai().
                 */
                $wheres[] = helper::sql_like_ai('title', ':name');
                $params['name'] = '%' . $DB->sql_like_escape($needle) . '%';
            }
        }

        if ($filterset->has_filter('status')) {
            $values = $filterset->get_filter('status')->get_filter_values();
            $status = (string) reset($values);

            if ($status === all_notices_filterset::STATUS_LIVE) {
                $wheres[] = 'enabled = :statuslive';
                $params['statuslive'] = 1;
            } else if ($status === all_notices_filterset::STATUS_DRAFT) {
                $wheres[] = 'enabled = :statusdraft';
                $params['statusdraft'] = 0;
            } else if ($status === all_notices_filterset::STATUS_CLASH) {
                $ids = collision::clashing_ids($this->scope());
                if (empty($ids)) {
                    // No notice competes: an empty IN () is not portable, so say so directly.
                    $wheres[] = '1 = 0';
                } else {
                    [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'clash');
                    $wheres[] = "id $insql";
                    $params += $inparams;
                }
            }
        }

        if ($filterset->has_filter('validity')) {
            $values = $filterset->get_filter('validity')->get_filter_values();
            $validity = (string) reset($values);
            $now = time();

            /*
             * One placeholder name per occurrence: fix_sql_params() throws duplicateparaminsql when
             * a name appears twice in a statement, so "now" compared against both ends of the
             * window is bound under two names.
             */
            if ($validity === all_notices_filterset::VALIDITY_PERMANENT) {
                $wheres[] = 'timestart = 0 AND timeend = 0';
            } else if ($validity === all_notices_filterset::VALIDITY_SCHEDULED) {
                $wheres[] = 'timestart > :nowstart';
                $params['nowstart'] = $now;
            } else if ($validity === all_notices_filterset::VALIDITY_EXPIRED) {
                $wheres[] = 'timeend > 0 AND timeend < :nowend';
                $params['nowend'] = $now;
            } else if ($validity === all_notices_filterset::VALIDITY_CURRENT) {
                $wheres[] = '(timestart <> 0 OR timeend <> 0)'
                    . ' AND (timestart = 0 OR timestart <= :nowcurstart)'
                    . ' AND (timeend = 0 OR timeend >= :nowcurend)';
                $params['nowcurstart'] = $now;
                $params['nowcurend'] = $now;
            }
        }

        return [implode(' AND ', $wheres), $params];
    }

    /**
     * Custom actions column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_actions(awareness $awareness): string {
        global $OUTPUT;

        $id = (int) $awareness->get('id');
        $action = function (string $name) use ($id): moodle_url {
            return $this->page_url('/local/awareness/editnotice.php', [
                'noticeid' => $id,
                'action' => $name,
                'sesskey' => sesskey(),
            ]);
        };

        /*
         * A core action_menu, not a hand-rolled dropdown: it carries both Bootstrap data-API
         * spellings, keyboard handling and ARIA, and it emits a .dropdown. Boost on 5.x has a
         * `.table-responsive .dropdown { position: static }` rule that lets that menu escape the
         * scroll container's overflow clip; a .btn-group wrapper is position: relative and would
         * clip the last row's menu. Moodle 4.5's Boost has no such rule.
         */
        $menu = new \core\output\action_menu();
        $menu->set_kebab_trigger(get_string('actions'));

        /*
         * A viewer who may not manage this notice gets the preview and the reports, none of the
         * verbs: a link to an action require_author() would refuse is a link to an error. Decided
         * per row because each row is judged in its own scope, and on the site list a capability
         * overridden in one course differs from row to row.
         */
        if (!helper::require_author(author_scope::of($awareness), 'manage', false)) {
            return $this->report_only_actions($awareness, $menu);
        }

        $primary = [
            ['edit', get_string('edit'), 't/edit'],
            $awareness->get('enabled')
                ? ['disable', get_string('notice:disable', 'local_awareness'), 't/hide']
                : ['enable', get_string('notice:enable', 'local_awareness'), 't/show'],
        ];
        foreach ($primary as [$name, $label, $icon]) {
            $menu->add_primary_action(new \core\output\action_menu\link_primary(
                $action($name),
                new \pix_icon($icon, ''),
                $label,
                ['title' => $label, 'aria-label' => $label, 'class' => 'local-awareness-action']
            ));
        }

        /*
         * Preview writes nothing: preview.js reads the notice id off .notice-preview, asks the
         * render_notice service for the notice as the reader gets it, and opens it in the real
         * dialogue.
         */
        $previewlabel = get_string('notice:preview', 'local_awareness');
        $menu->add_primary_action(new \core\output\action_menu\link_primary(
            new moodle_url('#'),
            new \pix_icon('i/preview', ''),
            $previewlabel,
            [
                'title' => $previewlabel,
                'aria-label' => $previewlabel,
                'class' => 'local-awareness-action notice-preview',
                'data-noticeid' => (int) $awareness->get('id'),
            ]
        ));

        $secondary = [
            ['recalculate', get_string('notice:audience:recalculate', 'local_awareness'), 'i/calc'],
            ['unconfirmedreset', get_string('notice:reset', 'local_awareness'), 't/reset'],
        ];
        /*
         * Gated on the level, not on reqack: from Blocking up a notice records acceptances and
         * refusals (see helper::dismiss_notice()), even without demanding a tick. An Informational
         * notice records neither, so it offers no report.
         */
        if ($awareness->get_insistence() >= awareness::INSISTENCE_BLOCKING) {
            $secondary[] = ['acknowledged_report', get_string('report:button:ack', 'local_awareness'), 'i/report'];
            $secondary[] = ['dismissed_report', get_string('report:button:dis', 'local_awareness'), 'i/report'];
        }
        // Delete stays last because it is destructive.
        if (get_config('local_awareness', 'allow_delete')) {
            $secondary[] = ['unconfirmeddelete', get_string('notice:delete', 'local_awareness'), 't/delete'];
        }

        foreach ($secondary as [$name, $label, $icon]) {
            $menu->add_secondary_action(new \core\output\action_menu\link_secondary(
                $action($name),
                new \pix_icon($icon, ''),
                $label
            ));
        }

        return $OUTPUT->render($menu);
    }

    /**
     * The actions a reports-only viewer gets: the preview, and the two reports where rows can exist.
     *
     * @param awareness $awareness The notice.
     * @param \core\output\action_menu $menu The menu, with its trigger already set.
     * @return string
     */
    private function report_only_actions(awareness $awareness, \core\output\action_menu $menu): string {
        global $OUTPUT;

        $id = (int) $awareness->get('id');
        $previewlabel = get_string('notice:preview', 'local_awareness');
        $menu->add_primary_action(new \core\output\action_menu\link_primary(
            new moodle_url('#'),
            new \pix_icon('i/preview', ''),
            $previewlabel,
            [
                'title' => $previewlabel,
                'aria-label' => $previewlabel,
                'class' => 'local-awareness-action notice-preview',
                'data-noticeid' => (int) $awareness->get('id'),
            ]
        ));

        // The same gate col_actions() applies: informational notices record nothing to report on.
        if ($awareness->get_insistence() >= awareness::INSISTENCE_BLOCKING) {
            $reports = [
                ['acknowledged_report', get_string('report:button:ack', 'local_awareness')],
                ['dismissed_report', get_string('report:button:dis', 'local_awareness')],
            ];
            foreach ($reports as [$name, $label]) {
                $menu->add_secondary_action(new \core\output\action_menu\link_secondary(
                    $this->page_url(
                        '/local/awareness/editnotice.php',
                        ['noticeid' => $id, 'action' => $name, 'sesskey' => sesskey()]
                    ),
                    new \pix_icon('i/report', ''),
                    $label
                ));
            }
        }

        return $OUTPUT->render($menu);
    }

    /**
     * Status column: whether the notice is published, plus a conflict warning when it competes.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_status(awareness $awareness): string {
        global $OUTPUT;

        $clashes = $this->clashtitles[(int) $awareness->get('id')] ?? [];
        $explanation = empty($clashes)
            ? ''
            : get_string('collision:badgetooltip', 'local_awareness', implode(', ', $clashes));

        return $OUTPUT->render_from_template('local_awareness/manage/cell_status', [
            'enabled' => (bool) $awareness->get('enabled'),
            'hasclash' => !empty($clashes),
            'clashlabel' => get_string('collision:badge', 'local_awareness'),
            'clashexplanation' => $explanation,
        ]);
    }

    /**
     * Behaviour column: one chip per setting that is switched ON, and nothing for the rest.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_behaviour(awareness $awareness): string {
        $chips = [];

        $interval = (int) $awareness->get('resetinterval');
        if ($interval > 0) {
            $chips[] = get_string('notice:behaviour:repeat', 'local_awareness', format_time($interval));
        }
        /*
         * The insistence level, chipped only from Blocking up: Informational is the default, and
         * chipping it would badge almost every row.
         */
        $insistence = $awareness->get_insistence();
        if ($insistence >= awareness::INSISTENCE_BLOCKING) {
            $chips[] = get_string(
                $insistence >= awareness::INSISTENCE_ACKNOWLEDGE
                    ? 'notice:insistence:acknowledge'
                    : 'notice:insistence:blocking',
                'local_awareness'
            );
        }
        if ($awareness->get('reqcourse')) {
            $chips[] = get_string('notice:reqcourse', 'local_awareness');
        }

        global $OUTPUT;

        return $OUTPUT->render_from_template('local_awareness/manage/cell_chips', [
            'haschips' => !empty($chips),
            'emptylabel' => get_string('notice:behaviour:none', 'local_awareness'),
            'chips' => array_map(static fn(string $chip): array => ['label' => $chip], $chips),
        ]);
    }

    /**
     * Validity column: the scheduled window, or "permanent" when there is none.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_validity(awareness $awareness): string {
        $start = (int) $awareness->get('timestart');
        $end = (int) $awareness->get('timeend');

        global $OUTPUT;

        $window = '';
        $statekey = '';
        if ($start || $end) {
            $format = get_string('strftimedatefullshort');
            $window = ($start ? userdate($start, $format) : '…') . ' → ' . ($end ? userdate($end, $format) : '…');

            $now = time();
            if ($end && $end < $now) {
                $statekey = 'notice:validity:expired';
            } else if ($start && $start > $now) {
                $statekey = 'notice:validity:scheduled';
            } else {
                $statekey = 'notice:validity:current';
            }
        }

        return $OUTPUT->render_from_template('local_awareness/manage/cell_validity', [
            'haswindow' => (bool) ($start || $end),
            'window' => $window,
            'state' => $statekey ? get_string($statekey, 'local_awareness') : '',
            'permanentlabel' => get_string('notice:validity:permanent', 'local_awareness'),
        ]);
    }

    /**
     * Custom target-audience column.
     *
     * Reads the count stored on the notice rather than the latest job, which would cost a query per
     * row. A count computed before the notice's filters changed describes a different audience, so
     * it is labelled as such (notice:audience:stale) rather than shown as current.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_audience(awareness $awareness): string {
        global $OUTPUT;

        $count = $awareness->get('audiencecount');
        $computed = (int) $awareness->get('audiencecomputed');
        $state = notice_audience::state_of($awareness, $this->inflight);
        $stale = ($state === notice_audience::STATE_STALE);

        $when = '';
        if ($count !== null) {
            $whenkey = $stale ? 'notice:audience:stale' : 'notice:audience:computed';
            $when = get_string(
                $whenkey,
                'local_awareness',
                userdate($computed, get_string('strftimedatetimeshort'))
            );
        }

        [$cohortline, $cohortlist] = $this->cohort_line($awareness);
        [$groupline, $grouplist] = $this->group_line($awareness);

        return $OUTPUT->render_from_template('local_awareness/manage/cell_audience', [
            'pending' => ($state === notice_audience::STATE_PENDING),
            'pendinglabel' => get_string('notice:audience:pending', 'local_awareness'),
            'never' => ($count === null),
            'neverlabel' => get_string('notice:audience:never', 'local_awareness'),
            'value' => get_string('notice:audience:value', 'local_awareness', number_format((int) $count)),
            'when' => $when,
            'stale' => $stale,
            'hascohorts' => ($cohortlist !== ''),
            'cohortline' => $cohortline,
            'cohortlist' => $cohortlist,
            'hasgroups' => ($grouplist !== ''),
            'groupline' => $groupline,
            'grouplist' => $grouplist,
        ]);
    }

    /**
     * The cohort restriction, as a muted line under the audience count.
     *
     * Nothing is shown for a notice that targets everyone.
     *
     * @param awareness $awareness a notice record.
     * @return array The sentence and the plain list, both empty when the notice targets everyone.
     */
    protected function cohort_line(awareness $awareness): array {
        $cohorts = $awareness->get('cohorts');
        if (empty($cohorts)) {
            return ['', ''];
        }

        /*
         * Resolved once per render, not per cohort per row: built_cohorts_options() reads every
         * cohort the user can see, and the AJAX refresh re-renders on every filter change.
         * Memoised on the table object, which lives for one render; a static would outlive a
         * PHPUnit test's reset. Filled lazily, so a page with no cohort-targeted notice pays
         * nothing.
         */
        $this->cohortnames ??= helper::built_cohorts_options();
        $options = $this->cohortnames;

        $names = array_map(static function ($cohortid) use ($options) {
            return helper::get_cohort_name((int) $cohortid, $options);
        }, $cohorts);
        $list = implode(', ', $names);

        return [get_string('notice:audience:cohorts', 'local_awareness', $list), $list];
    }

    /**
     * The group restriction of a course notice, as a muted line under the audience count.
     *
     * Same shape as cohort_line(), and resolved once for the page for the same reason: the names
     * of every group any row on the page names are read in one statement the first time a row
     * asks, and formatted in their course's context.
     *
     * @param awareness $awareness The notice.
     * @return array [line, list] — both empty when the notice names no group.
     */
    protected function group_line(awareness $awareness): array {
        global $DB;

        $targets = group_scope::targeted($awareness);
        if ($targets === []) {
            return ['', ''];
        }

        if ($this->groupnames === null) {
            $ids = [];
            foreach ($this->rawdata ?? [] as $row) {
                foreach (group_scope::targeted($row) as $id) {
                    $ids[$id] = true;
                }
            }
            $this->groupnames = [];
            foreach ($DB->get_records_list('groups', 'id', array_keys($ids), '', 'id, name, courseid') as $group) {
                $context = \context_course::instance((int) $group->courseid, IGNORE_MISSING) ?: \context_system::instance();
                $this->groupnames[(int) $group->id] = format_string($group->name, true, ['context' => $context]);
            }
        }

        $names = [];
        foreach ($targets as $id) {
            // A group deleted since the notice was saved is shown by its id, so the row still says something is named.
            $names[] = $this->groupnames[$id] ?? '#' . $id;
        }
        $list = implode(', ', $names);

        return [get_string('notice:audience:groups', 'local_awareness', $list), $list];
    }

    /**
     * Title column: the title, the pages it shows on and, on the site list, its course.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_title(awareness $awareness): string {
        global $OUTPUT;

        /*
         * format_string(), not the raw value: the persistent stores the title as
         * PARAM_RAW_TRIMMED, and the modal formats it the same way, so a multilang title reads
         * alike in both (see notice_payload::build()).
         */
        $title = format_string(
            $awareness->get('title'),
            true,
            ['context' => \context_system::instance()]
        );

        /*
         * The path is PARAM_RAW too, but a URL pattern rather than prose: it is passed raw and
         * escaped once by the template's double stash. Escaping it here as well would
         * double-escape it.
         */
        $path = trim((string) $awareness->get('pathmatch'));

        // On the site list a course notice says which course it belongs to; a course list needs no chip.
        $course = null;
        $courseid = (int) $awareness->get('courseid');
        if ($courseid > 0 && $this->scope()->is_site()) {
            if (isset($this->coursenames[$courseid])) {
                $name = format_string(
                    $this->coursenames[$courseid]->fullname,
                    true,
                    ['context' => \context_course::instance($courseid, IGNORE_MISSING) ?: \context_system::instance()]
                );
                $course = [
                    'name' => get_string('manage:scope:course', 'local_awareness', $name),
                    'url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                ];
            } else {
                $course = ['name' => get_string('manage:scope:orphan', 'local_awareness'), 'url' => ''];
            }
        }

        return $OUTPUT->render_from_template('local_awareness/manage/cell_title', [
            'title' => $title,
            'titleplain' => $title,
            'where' => $path !== '' ? $path : get_string('notice:pathmatch:anywhere', 'local_awareness'),
            'course' => $course,
        ]);
    }
}
