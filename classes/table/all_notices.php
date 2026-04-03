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
use html_writer;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table to show list of existing notices.
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (Catalyst IT).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class all_notices extends table_sql implements renderable {
    /** @var int */
    protected $page;

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

        $this->set_attribute('class', 'local_awareness awarenesss');

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
        $cols = [
            'title' => get_string('notice:title', 'local_awareness'),
            'resetinterval' => get_string('notice:resetinterval', 'local_awareness'),
            'reqack' => get_string('notice:reqack', 'local_awareness'),
            'forcelogout' => get_string('notice:forcelogout', 'local_awareness'),
            'reqcourse' => get_string('notice:reqcourse', 'local_awareness'),
            'timestart' => get_string('notice:activefrom', 'local_awareness'),
            'timeend' => get_string('notice:expiry', 'local_awareness'),
            'cohort' => get_string('notice:cohort', 'local_awareness'),
            'content' => get_string('notice:content', 'local_awareness'),
            'actions' => get_string('actions'),
            'timemodified' => get_string('notice:timemodified', 'local_awareness'),
        ];

        $this->define_columns(array_keys($cols));
        $this->define_headers(array_values($cols));
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
        $links = null;
        $editnotice = '/local/awareness/editnotice.php';
        // Edit.
        $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'edit', 'sesskey' => sesskey()];
        $editurl = new moodle_url($editnotice, $editparams);
        $icon = $OUTPUT->pix_icon('t/edit', get_string('edit'));
        $editlink = html_writer::link($editurl, $icon);
        $links .= ' ' . $editlink;

        // Enable/Disable.
        if ($awareness->get('enabled')) {
            $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'disable', 'sesskey' => sesskey()];
            $editurl = new moodle_url($editnotice, $editparams);
            $icon = $OUTPUT->pix_icon('t/hide', get_string('notice:disable', 'local_awareness'));
            $editlink = html_writer::link($editurl, $icon);
        } else {
            $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'enable', 'sesskey' => sesskey()];
            $editurl = new moodle_url($editnotice, $editparams);
            $icon = $OUTPUT->pix_icon('t/show', get_string('notice:enable', 'local_awareness'));
            $editlink = html_writer::link($editurl, $icon);
        }
        $links .= ' ' . $editlink;

        // Reset.
        $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'reset', 'sesskey' => sesskey()];
        $editurl = new moodle_url($editnotice, $editparams);
        $icon = $OUTPUT->pix_icon('t/reset', get_string('notice:reset', 'local_awareness'));
        $editlink = html_writer::link($editurl, $icon);
        $links .= ' ' . $editlink;

        // Delete.
        if (get_config('local_awareness', 'allow_delete')) {
            $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'unconfirmeddelete', 'sesskey' => sesskey()];
            $editurl = new moodle_url($editnotice, $editparams);
            $icon = $OUTPUT->pix_icon('t/delete', get_string('notice:delete', 'local_awareness'));
            $editlink = html_writer::link($editurl, $icon);
            $links .= ' ' . $editlink;
        }

        if ($awareness->get('reqack')) {
            // Acknowledge Report.
            $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'acknowledged_report', 'sesskey' => sesskey()];
            $editurl = new moodle_url($editnotice, $editparams);
            $icon = $OUTPUT->pix_icon('i/report', get_string('report:button:ack', 'local_awareness'));
            $editlink = html_writer::link($editurl, $icon);
            $links .= ' ' . $editlink;

            // Dismiss Report.
            $editparams = ['noticeid' => $awareness->get('id'), 'action' => 'dismissed_report', 'sesskey' => sesskey()];
            $editurl = new moodle_url($editnotice, $editparams);
            $icon = $OUTPUT->pix_icon('i/risk_xss', get_string('report:button:dis', 'local_awareness'));
            $editlink = html_writer::link($editurl, $icon);
            $links .= ' ' . $editlink;
        }

        return $links;
    }

    /**
     * Custom reset title column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_title(awareness $awareness): string {
        return $awareness->get('title');
    }

    /**
     * Custom reset interval column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_resetinterval(awareness $awareness): string {
        return helper::format_interval_time($awareness->get('resetinterval'));
    }

    /**
     * Custom reset cohort column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_cohort(awareness $awareness): string {
        if (empty($awareness->get('cohorts'))) {
            $cohort = get_string('notice:cohort:all', 'local_awareness');
        } else {
            $cohorts = array_map(function ($cohortid) {
                return helper::get_cohort_name($cohortid);
            }, $awareness->get('cohorts'));

            $cohort = implode(', ', $cohorts);
        }

        return $cohort;
    }

    /**
     * Custom reset require acknowledge column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_reqack(awareness $awareness): string {
        return helper::format_boolean($awareness->get('reqack'));
    }

    /**
     * The force logout column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_forcelogout(awareness $awareness): string {
        return helper::format_boolean($awareness->get('forcelogout'));
    }

    /**
     * The timestart column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_timestart(awareness $awareness): string {
        return $awareness->get('timestart') == 0 ? "-" : userdate($awareness->get('timestart'));
    }

    /**
     * The timeend column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_timeend(awareness $awareness): string {
        return $awareness->get('timeend') == 0 ? '-' : userdate($awareness->get('timeend'));
    }

    /**
     * Custom require course completion column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_reqcourse(awareness $awareness): string {
        return helper::get_course_name($awareness->get('reqcourse'));
    }

    /**
     * Custom reset time modified column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_timemodified(awareness $awareness): string {
        if ($awareness->get('timemodified')) {
            return userdate($awareness->get('timemodified'));
        } else {
            return '-';
        }
    }

    /**
     * Custom content column.
     *
     * @param awareness $awareness a notice record.
     * @return string
     */
    protected function col_content(awareness $awareness): string {
        return html_writer::link(
            "#",
            get_string('view'),
            ['class' => 'notice-preview', 'data-noticecontent' => $awareness->get('content')]
        );
    }
}
