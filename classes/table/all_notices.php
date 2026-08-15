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
use html_writer;

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
class all_notices extends table_sql implements renderable {
    /** @var int */
    protected $page;

    /** @var array Notice id to the titles of the repeating notices it competes with, for this page of rows. */
    protected $clashtitles = [];

    /**
     * all_notices constructor.
     *
     * @param string $uniqueid table unique id
     * @param \moodle_url $url base url
     * @param int $page current page
     * @param int $perpage number of records per page
     */
    public function __construct(string $uniqueid, \moodle_url $url, int $page = 0, int $perpage = 20) {
        parent::__construct($uniqueid);

        $this->set_attribute('class', 'local-awareness awarenesss');

        // Set protected properties.
        $this->pagesize = $perpage;
        $this->page = $page;

        // Define columns in the table.
        $this->define_table_columns();

        // Define configs.
        $this->define_table_configs($url);
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
        $records = awareness::get_records([], 'enabled, timemodified', 'DESC', $this->pagesize * $this->page, $this->pagesize);
        $total = awareness::count_records();
        $this->pagesize($pagesize, $total);

        foreach ($records as $record) {
            $this->rawdata[] = $record;
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

        // Set initial bars.
        if ($useinitialsbar) {
            $this->initialbars($total > $pagesize);
        }
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
            ['reset', get_string('notice:reset', 'local_awareness'), 't/reset'],
        ];
        if ($awareness->get('reqack')) {
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
        /*
         * Every background states its text colour. Bootstrap 4 gives .badge no colour at all and
         * Bootstrap 5 defaults it to white, so the two branches fail on disjoint sets of fills;
         * tests/local/bootstrap_compat_test.php enforces the pairing.
         */
        if ($awareness->get('enabled')) {
            $status = html_writer::tag('span', get_string('notice:status:live', 'local_awareness'), [
                'class' => 'badge bg-success text-white',
            ]);
        } else {
            $status = html_writer::tag('span', get_string('notice:status:draft', 'local_awareness'), [
                'class' => 'badge bg-secondary text-dark',
            ]);
        }

        $clashes = $this->clashtitles[(int) $awareness->get('id')] ?? [];
        if (!empty($clashes)) {
            $explanation = get_string('collision:badgetooltip', 'local_awareness', implode(', ', $clashes));
            /*
             * The badge explains itself twice: title for the pointer, and a visually-hidden sibling
             * for assistive technology. aria-label on a bare span has no role to attach to and is
             * not announced reliably, which is what the previous version relied on.
             */
            $status .= html_writer::tag(
                'span',
                get_string('collision:badge', 'local_awareness')
                    . html_writer::tag('span', ' — ' . $explanation, ['class' => 'visually-hidden']),
                ['class' => 'badge bg-warning text-dark', 'title' => $explanation]
            );
        }

        return html_writer::div($status, 'local-awareness-statuscell');
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
        if ($awareness->get('reqack')) {
            $chips[] = get_string('notice:reqack', 'local_awareness');
        }
        if ($awareness->get('forcelogout')) {
            $chips[] = get_string('notice:forcelogout', 'local_awareness');
        }
        if ($awareness->get('reqcourse')) {
            $chips[] = get_string('notice:reqcourse', 'local_awareness');
        }

        if (empty($chips)) {
            return html_writer::tag(
                'span',
                get_string('notice:behaviour:none', 'local_awareness'),
                ['class' => 'text-muted']
            );
        }

        $rendered = array_map(static function (string $chip): string {
            return html_writer::tag('span', $chip, ['class' => 'local-awareness-chip']);
        }, $chips);

        return html_writer::div(implode('', $rendered), 'local-awareness-chips');
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

        if (!$start && !$end) {
            return html_writer::tag(
                'span',
                get_string('notice:validity:permanent', 'local_awareness'),
                ['class' => 'text-muted']
            );
        }

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

        return html_writer::span($window)
            . html_writer::tag('span', get_string($statekey, 'local_awareness'), ['class' => 'local-awareness-subline']);
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
        $count = $awareness->get('audiencecount');
        $computed = (int) $awareness->get('audiencecomputed');
        $state = notice_audience::state_of($awareness);

        if ($state === notice_audience::STATE_PENDING) {
            return html_writer::tag(
                'span',
                get_string('notice:audience:pending', 'local_awareness'),
                ['class' => 'badge bg-info text-white']
            );
        }

        if ($count === null) {
            return html_writer::tag(
                'span',
                get_string('notice:audience:never', 'local_awareness'),
                ['class' => 'text-muted']
            );
        }

        $value = html_writer::tag(
            'span',
            get_string('notice:audience:value', 'local_awareness', number_format((int) $count)),
            ['class' => 'd-block']
        );

        $whenkey = ($state === notice_audience::STATE_STALE)
            ? 'notice:audience:stale'
            : 'notice:audience:computed';
        /*
         * bg-warning takes text-dark explicitly: Bootstrap 4 gives .badge no colour and Bootstrap 5
         * defaults it to white, so a light fill renders white on near-white on 5.x. Enforced by
         * tests/local/bootstrap_compat_test.php.
         */
        $whenclass = ($state === notice_audience::STATE_STALE)
            ? 'badge bg-warning text-dark'
            : 'text-muted small';

        $when = html_writer::tag(
            'span',
            get_string($whenkey, 'local_awareness', userdate($computed, get_string('strftimedatetimeshort'))),
            ['class' => $whenclass]
        );

        return $value . $when . $this->cohort_line($awareness);
    }

    /**
     * The cohort restriction, as a muted line under the audience count.
     *
     * The cohort names used to own a column. They are a property OF the audience, not a sibling
     * of it, so they read better underneath the number — and a notice targeting everyone, which is
     * the common case, now says nothing instead of spending a column to say "All users".
     *
     * @param awareness $awareness a notice record.
     * @return string Empty string when the notice targets everyone.
     */
    protected function cohort_line(awareness $awareness): string {
        $cohorts = $awareness->get('cohorts');
        if (empty($cohorts)) {
            return '';
        }

        $names = array_map(static function ($cohortid) {
            return helper::get_cohort_name($cohortid);
        }, $cohorts);
        $list = implode(', ', $names);

        return html_writer::tag(
            'span',
            get_string('notice:audience:cohorts', 'local_awareness', $list),
            ['class' => 'local-awareness-subline', 'title' => $list]
        );
    }

    /**
     * Custom reset title column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_title(awareness $awareness): string {
        $title = $awareness->get('title');

        /*
         * A notice title has no length cap in the database, so the cell clamps it to two lines in
         * CSS and carries the whole string in the title attribute. The full text stays in the node
         * — clipped for the eye, intact for a screen reader.
         */
        $cell = html_writer::tag('span', $title, [
            'class' => 'local-awareness-celltitle',
            'title' => $title,
        ]);

        $path = trim((string) $awareness->get('pathmatch'));
        $where = $path !== '' ? $path : get_string('notice:pathmatch:anywhere', 'local_awareness');
        $cell .= html_writer::tag('span', $where, [
            'class' => 'local-awareness-subline',
            'title' => $where,
        ]);

        return $cell;
    }
}
