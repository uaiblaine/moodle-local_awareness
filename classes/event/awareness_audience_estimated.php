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

namespace local_awareness\event;

/**
 * Audience estimate requested event.
 *
 * Fired when a job ROW is created, not when a web service is called. The editor re-estimates on
 * every form change (amd/src/audience_estimator.js debounces at 800ms), and criteria already
 * answered inside the dedup window reuse an existing job instead of creating one — so this is one
 * line per distinct criteria set a manager actually asked about, which is the auditable fact: who
 * counted the users matching which rules.
 *
 * It is triggered from persistent\audience_job::trigger_created_event() rather than at either call
 * site, because rows are created in two places — the estimate web service and
 * audience\notice_audience::refresh(), which is what a notice save and the editor's Recalculate
 * button both go through. Instrumenting only the web service would have logged the editor's
 * speculative previews while missing every deliberate recalculation.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class awareness_audience_estimated extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_awareness_audience_jobs';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Description.
     * @return string
     */
    public function get_description() {
        $jobid = $this->other['jobid'] ?? '';
        return "The user with id '$this->userid' requested an audience estimate, stored as the " .
            "job with id '$this->objectid' and the token '$jobid'";
    }

    /**
     * Gets name.
     * @return \lang_string|string
     */
    public static function get_name() {
        return get_string('event:estimateaudience', 'local_awareness');
    }

    /**
     * Gets URL.
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/awareness/managenotice.php');
    }
}
