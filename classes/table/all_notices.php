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

use local_awareness\persistent\awareness;
use table_sql;
use renderable;
use local_awareness\helper;
use moodle_url;
use local_awareness\local\collision;
use local_awareness\audience\notice_audience;
use local_awareness\persistent\audience_job;

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

    /** @var array Criteria hashes with a job in flight, for this page of rows. */
    protected $inflight = [];

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
         * A name for the table itself. Screen readers list a page's tables and announce each by its
         * accessible name; without one this is "table", which on an admin page that has several is
         * no help at all. Hidden visually because the heading above it already says this in print —
         * set_caption() and render_caption() are on flexible_table on both 4.5 and 5.2.
         */
        $this->set_caption(
            get_string('manage:table:caption', 'local_awareness'),
            ['class' => 'visually-hidden']
        );

        // Set protected properties. setup() refines currpage from the request on a full page load.
        $this->pagesize = $perpage;
        $this->currpage = $page;

        // Define columns in the table.
        $this->define_table_columns();

        // Define configs.
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
     * Derived here rather than accepted as a parameter: notices are a site-level thing, and a
     * context id arriving from the client is a context the client chose.
     *
     * @return \context
     */
    public function get_context(): \context {
        return \context_system::instance();
    }

    /**
     * Whether the current user may see this table.
     *
     * @return bool
     */
    public function has_capability(): bool {
        return has_capability('local/awareness:manage', $this->get_context());
    }

    /**
     * Base URL used when the table is refreshed over AJAX.
     *
     * @return void
     */
    public function guess_base_url(): void {
        $this->baseurl = new moodle_url('/local/awareness/managenotice.php');
    }

    /**
     * How many rows match the current filters, across every page.
     *
     * Reads flexible_table's own public $totalrows, which pagesize() sets from the filtered count
     * in query_db(). Shadowing it with a property of the same name is a fatal error, since the
     * base declares it public.
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
         * Six columns, down from twelve. Four of the removed ones were yes/no columns that are now
         * chips in "behaviour", drawn only when the setting is ON: an on/off pair told apart by
         * colour is invisible to a reader with a colour vision deficiency, and absence carries
         * "off" perfectly well. "Time modified" is gone outright — it guided no decision on this
         * page, and the value still lives on the notice.
         *
         * Status is a column of its own rather than a badge glued to the title, so it can be
         * filtered and sorted like the data it is.
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
        // Set table url.
        $this->define_baseurl($url);

        // Set table configs.
        $this->collapsible(false);
        $this->sortable(false);
        $this->pageable(true);
    }

    /**
     * Get data.
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

        /*
         * Resolved once for the whole page of rows rather than per row, which would re-read every
         * repeating notice for each line of the table.
         *
         * rawdata is null, not [], until the loop above puts something in it, so a site with no
         * notices at all reaches this with nothing. The manage page is exactly where a site with no
         * notices starts.
         */
        $this->clashtitles = collision::clash_titles_for($this->rawdata ?? []);
        // Same shape as the line above: one query for the page, not one per row in col_audience().
        $this->inflight = audience_job::in_flight_hashes();

        // Set initial bars.
        if ($useinitialsbar) {
            $this->initialbars($total > $pagesize);
        }
    }

    /**
     * How many notices matched, printed just above the rows.
     *
     * Inside the table on purpose. start_html() calls this within the dynamic-table wrapper, so
     * the AJAX refresh replaces it along with the rows and the number can never describe a
     * different list than the one on screen. Rendering it in the page around the table would have
     * meant JavaScript reading data-table-total-rows back out and re-fetching a language string
     * on every keystroke — more moving parts, and a window where the two disagree.
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
     * Overridden rather than rendered by the page around the table, because the AJAX refresh
     * replaces the table's own HTML and nothing else: an empty state living outside it would still
     * be showing the previous answer after a filter narrowed the list to nothing.
     *
     * The two cases read differently on purpose. A site with no notices at all needs an invitation
     * to create one; a filter that matched nothing needs a way back out, and offering "create a
     * notice" there would answer a question nobody asked.
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
                'createurl' => (new moodle_url(
                    '/local/awareness/editnotice.php',
                    ['noticeid' => 0, 'sesskey' => sesskey()]
                ))->out(false),
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
     * Turn the filterset into a WHERE clause and its parameters.
     *
     * Every filter is a SQL predicate, never a post-query array_filter. Narrowing the rows after
     * the query would make paging lie: it would fetch a page of 25 and display however many
     * survived, while the pager kept counting the unfiltered total.
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

        if ($filterset->has_filter('name')) {
            $values = $filterset->get_filter('name')->get_filter_values();
            $needle = trim((string) reset($values));
            if ($needle !== '') {
                /*
                 * Accent- and case-insensitive: "manutencao" has to find "Manutenção". On
                 * PostgreSQL that is unaccent() on both operands when the extension is present,
                 * and on MySQL/MariaDB the collation already does it. ILIKE is PostgreSQL-only,
                 * which is why this goes through the helper rather than being written inline.
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
                $ids = collision::clashing_ids();
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
             * Each occurrence gets its own placeholder name. fix_sql_params() counts placeholder
             * OCCURRENCES against the parameter array and throws duplicateparaminsql when a name
             * appears twice, so one :now compared against both ends of the window is two names
             * bound to the same value, not one name reused.
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
        $action = static function (string $name) use ($id): moodle_url {
            return new moodle_url('/local/awareness/editnotice.php', [
                'noticeid' => $id,
                'action' => $name,
                'sesskey' => sesskey(),
            ]);
        };

        /*
         * A core action_menu, not a hand-rolled dropdown. It carries both Bootstrap data-API
         * attribute spellings, the keyboard handling and the ARIA wiring, and — the reason it
         * matters most here — it emits a .dropdown, which is what Boost's
         * `.table-responsive .dropdown { position: static }` rule keys off. That rule is what lets
         * the menu escape the scroll container's overflow clip; a .btn-group wrapper is
         * position:relative and traps it, so the last row's menu is cut off.
         */
        $menu = new \core\output\action_menu();
        $menu->set_kebab_trigger(get_string('actions'));

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
         * Preview posts nothing: it opens the stored content in a modal, and preview.js binds to
         * .notice-preview reading data-noticecontent. It used to be a "View" link in a column of
         * its own, which cost a whole column for one link.
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
                'data-noticecontent' => helper::render_content($awareness),
            ]
        ));

        $secondary = [
            ['recalculate', get_string('notice:audience:recalculate', 'local_awareness'), 'i/calc'],
            ['unconfirmedreset', get_string('notice:reset', 'local_awareness'), 't/reset'],
        ];
        /*
         * Gated on the level, not on reqack. A Blocking notice records acceptances and refusals
         * just as an Acknowledge one does — it simply does not demand a tick first — so gating on
         * reqack hid the reports for exactly the notices whose rows nobody could otherwise reach.
         * Informational notices record no acceptance at all, so they still offer no report.
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
            /*
             * format_time() gives "1 day"; the old column ran the raw interval through a DateInterval
             * format string and printed "1 day(s), 0 hour(s), 0 minute(s) and 0 second(s)" — three
             * wrapped lines per cell for a value that is almost always round.
             */
            $chips[] = get_string('notice:behaviour:repeat', 'local_awareness', format_time($interval));
        }
        /*
         * The level, and only when it is worth saying. Informational is the default and the least
         * insistent, so chipping it would put a badge on almost every row and leave the "nothing
         * to say here" state unreachable. This replaced two chips that reported two of the three
         * booleans the level is derived from, which meant a notice could be hard to escape and
         * say nothing about it.
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
     * Reads the count denormalised onto the notice rather than resolving the latest job per row,
     * which on a page of twenty notices would be twenty extra queries for a column.
     *
     * The number is always shown with what it is a statement about. A count computed before the
     * filters changed is not merely old — it describes a different notice — so it is labelled
     * "filters changed since" rather than being silently presented as current.
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
        ]);
    }

    /**
     * The cohort restriction, as a muted line under the audience count.
     *
     * The cohort names used to own a column. They are a property OF the audience, not a sibling
     * of it, so they read better underneath the number — and a notice targeting everyone, which is
     * the common case, now says nothing instead of spending a column to say "All users".
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
         * Resolved once for the whole page. get_cohort_name() reaches built_cohorts_options(),
         * which is a COUNT plus an unbounded scan of {cohort} joined to {context} plus a
         * capability walk — and it was paid once per cohort id per row, so the page cost scaled
         * with the size of the site rather than with what is on screen. Worse since the list became
         * a dynamic table: the filter bar re-pays it on every keystroke.
         *
         * The memo lives on the table object, which exists for exactly one render, so it cannot go
         * stale across requests, users or test methods. A static inside built_cohorts_options() —
         * which is what the audit recommends — would survive resetAfterTest(), because
         * phpunit_util::reset_all_data() resets a hardcoded list of core caches and has no hook for
         * plugin ones; it would also break the two existing tests that create or delete a cohort
         * between calls.
         *
         * Filled lazily rather than in query_db(): the early return above means a site that never
         * targets cohorts pays nothing at all.
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
     * Custom reset title column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_title(awareness $awareness): string {
        global $OUTPUT;

        /*
         * format_string(), not the raw value: `title` is PARAM_RAW_TRIMMED all the way from the
         * form to the persistent, so the stored string would otherwise reach this page as markup.
         * It is also the same treatment the modal gives the title, which is the point: a multilang
         * title that resolves in the modal and shows its markup here is worse than either
         * behaviour alone.
         */
        $title = format_string(
            $awareness->get('title'),
            true,
            ['context' => \context_system::instance()]
        );

        /*
         * The path is PARAM_RAW as well, and it is a URL pattern rather than prose — so it is
         * passed RAW and escaped by the template. Pre-escaping it here would double-escape it,
         * because Mustache escapes {{ }} on its own.
         */
        $path = trim((string) $awareness->get('pathmatch'));

        return $OUTPUT->render_from_template('local_awareness/manage/cell_title', [
            'title' => $title,
            'titleplain' => $title,
            'where' => $path !== '' ? $path : get_string('notice:pathmatch:anywhere', 'local_awareness'),
        ]);
    }
}
