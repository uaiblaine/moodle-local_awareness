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
use local_awareness\local\author_scope;
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
            'courseid' => new external_value(PARAM_INT, 'course the editor is scoped to, 0 for the site', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Resolve, enqueue or reuse an audience-estimate job. Returns the job id the client should poll.
     *
     * A recently completed job for the same criteria is reused and a pending one is joined.
     * Otherwise a new job is created and, on a site within {@see live_mode}'s user limit, resolved
     * during this request, so the author does not wait on cron for a quick count and a site without
     * cron still gets one; above the limit it is queued as an adhoc task.
     *
     * @param string $criteria JSON-encoded criteria object
     * @param int $courseid The course the editor is scoped to, 0 for the site.
     * @return array
     * @throws \invalid_parameter_exception When the criteria name something the scope does not admit.
     */
    public static function execute(string $criteria, int $courseid = 0): array {
        global $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['criteria' => $criteria, 'courseid' => $courseid]
        );

        /*
         * The scope the caller is writing under, from the courseid the editor sends: the site when
         * it is 0. Validated as a context, which also requires login to the course, then gated like
         * every author-side entry point, so a caller naming a course they do not author for is
         * refused before anything is read. The other editor services gate the same way.
         */
        $scope = author_scope::for_request(null, (int) $params['courseid']);
        self::validate_context($scope->context());
        helper::require_author($scope, 'manage');

        $raw = json_decode($params['criteria'], true);
        if (!is_array($raw)) {
            $raw = [];
        }

        /*
         * The same scope check the save path applies, so a count and a saved notice agree on what a
         * value means, and the panel cannot be asked how many users are in an arbitrary course.
         * Cohorts are narrowed silently (see author_scope); anything else missing or outside the
         * scope is refused, since the author can fix it now. cap_criteria_lists() bounds the lists
         * the scope does not read, and runs after it so a legitimate cohort is not cut off behind
         * ids the scope drops anyway.
         */
        $scoped = $scope->apply($raw);
        if (!$scoped->is_clean()) {
            throw new \invalid_parameter_exception(
                'criteria name something the scope does not admit: ' . implode(', ', $scoped->problem_fields())
            );
        }
        $raw = helper::cap_criteria_lists($scoped->criteria());
        if (!$scope->is_site()) {
            /*
             * The page reach a course scope forces is not part of the question: pathmatch is a
             * context field and never enters a count, so carrying it would change the criteria hash
             * without changing a number and split the job cache, which relies on the same question
             * being the same job whoever asks. The scope is already in the job, as the forced
             * filter_course that get_estimate::execute() checks a job against.
             */
            unset($raw['pathmatch']);
        }

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
