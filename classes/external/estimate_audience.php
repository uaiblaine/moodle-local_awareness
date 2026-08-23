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

namespace local_awareness\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_awareness\audience\estimator;
use local_awareness\audience\live_mode;
use local_awareness\helper;
use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;
use local_awareness\task\estimate_audience as estimate_audience_task;

/**
 * Resolve, enqueue or reuse an audience-estimate job.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class estimate_audience extends external_api {
    /**
     * Parameters for estimate_audience.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'criteria' => new external_value(
                PARAM_RAW,
                'JSON object of audience and context criteria',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Resolve, enqueue or reuse an audience-estimate job. Returns the job id the client should poll.
     *
     * The estimate is a handful of COUNT queries, so on most sites it is finished before the
     * response is written and there is nothing to wait for. Handing every one of them to cron cost
     * the author minutes of "Calculating in the background…" for work that took milliseconds, and
     * on a site with no cron it never resolved at all. Above the configured user count the async
     * path remains, because there the cost is real.
     *
     * @param string $criteria JSON-encoded criteria object
     * @return array
     */
    public static function execute(string $criteria): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['criteria' => $criteria]
        );

        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('local/awareness:manage', $syscontext);

        $raw = json_decode($params['criteria'], true);
        if (!is_array($raw)) {
            $raw = [];
        }

        /*
         * Cohort ids come off the wire, so they are dropped to the set this user may target before
         * anything counts members with them. Without this the panel answers "how many people are in
         * cohort N?" for any N a manager cares to type, including cohorts in categories they cannot
         * see — the estimator's predicate is a bare `cohortid IN (…)` with no visibility join.
         *
         * Filtered rather than rejected, matching what the save path does with the same ids: the
         * editor's own menu only ever offers allowed cohorts, so a disallowed id means a hand-made
         * request, and excluding it from the count reveals nothing about it either way.
         */
        if (isset($raw['cohorts'])) {
            $raw['cohorts'] = helper::allowed_cohorts((array) $raw['cohorts']);
        }

        $raw = helper::cap_criteria_lists($raw);

        $normalised = estimator::normalise($raw);
        $hash = estimator::hash($normalised);

        // Reuse a recently-completed job for the same criteria, if any.
        if ($existing = audience_job::find_reusable($hash)) {
            return [
                'jobid' => $existing->get('jobid'),
                'status' => $existing->get('status'),
                'reused' => true,
            ];
        }

        // Otherwise join one already queued for the same criteria rather than queueing a duplicate.
        if ($inflight = audience_job::find_in_flight($hash)) {
            return [
                'jobid' => $inflight->get('jobid'),
                'status' => $inflight->get('status'),
                'reused' => true,
            ];
        }

        $job = new audience_job(0, (object) [
            'jobid' => audience_job::new_jobid(),
            'userid' => (int) $USER->id,
            'criteriahash' => $hash,
            'criteria' => json_encode($normalised),
            'status' => audience_job::STATUS_PENDING,
        ]);
        $job->create();
        audience_job::trigger_created_event($job);

        if (live_mode::is_live()) {
            estimate_audience_task::resolve($job);

            return [
                'jobid' => $job->get('jobid'),
                'status' => $job->get('status'),
                'reused' => false,
            ];
        }

        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->set_userid((int) $USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return [
            'jobid' => $job->get('jobid'),
            'status' => audience_job::STATUS_PENDING,
            'reused' => false,
        ];
    }

    /**
     * Return parameters for estimate_audience.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Job identifier'),
            'status' => new external_value(PARAM_ALPHA, 'pending|ready|error'),
            'reused' => new external_value(PARAM_BOOL, 'true if a cached result was returned'),
        ]);
    }
}
