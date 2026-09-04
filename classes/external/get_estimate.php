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
use local_awareness\audience\rule_describer;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;

/**
 * Poll a queued audience-estimate job.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_estimate extends external_api {
    /**
     * Parameters for get_estimate.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Job identifier', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'course the editor is scoped to, 0 for the site', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Poll the result of an audience-estimate job.
     *
     * @param string $jobid
     * @param int $courseid The course the editor is scoped to, 0 for the site.
     * @return array
     */
    public static function execute(string $jobid, int $courseid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['jobid' => $jobid, 'courseid' => $courseid]
        );

        /*
         * The scope the caller is writing under, from the courseid the editor sends: the site when
         * absent. Validated as a context — which also requires login to the course — and then gated
         * the way every author-side entry point is, so a course author's editor works and a caller
         * naming a course they do not hold is refused before anything is read.
         */
        $scope = author_scope::for_request(null, (int) $params['courseid']);
        self::validate_context($scope->context());
        helper::require_author($scope, 'manage');

        $job = audience_job::get_record(['jobid' => $params['jobid']]);
        /*
         * A job is shared by criteria hash between whoever asks the same question, so it is bound
         * to the scope rather than to a user: under a course scope the job's criteria must carry
         * that course as their forced filter, which every job made under the scope does and no
         * site job or other course's job can. A job outside the scope reads as no job at all, so a
         * jobid is not an oracle over what other authors have asked.
         */
        if ($job && !$scope->is_site()) {
            $jobcriteria = json_decode($job->get('criteria'), true) ?: [];
            $jobcourses = array_map('intval', (array) ($jobcriteria['filter_course'] ?? []));
            if ($jobcourses !== [$scope->get_courseid()]) {
                $job = null;
            }
        }
        if (!$job) {
            return [
                'jobid' => $params['jobid'],
                'status' => 'error',
                'count' => null,
                'breakdown' => '[]',
                'context_only_filters' => '[]',
                'has_audience_rules' => false,
                'errormsg' => get_string('audience:job_not_found', 'local_awareness'),
                'timecompleted' => null,
            ];
        }

        $criteria = json_decode($job->get('criteria'), true) ?: [];
        $hasaudience = !empty(estimator::audience_rules_in($criteria));

        /*
         * Names are resolved here and not stored on the job. Jobs are shared between callers by
         * criteria hash, so a label written at compute time would be served to the next reader in
         * the language of the first — the same reason a web service emitting localised strings
         * cannot cache them without the language in the key.
         */
        $contextrules = [];
        foreach (estimator::context_rules_in($criteria) as $rule) {
            $rule['display'] = rule_describer::describe($rule['key'], $rule['values']);
            $contextrules[] = $rule;
        }

        /*
         * No per-rule breakdown under a course scope. isolate_rule() reads each rule alone, on
         * purpose, so every chip answers "how many users hold role X" or "are in cohort Y" over the
         * whole site — the right reading for an administrator and a site-wide oracle for a course
         * author. The total is what the course author asked for and is already confined to the
         * course by the forced filter; the chips are not, so they stay with the site scope.
         */
        $breakdown = $scope->is_site() ? json_decode($job->get('breakdown') ?: '[]', true) : [];
        $breakdown = is_array($breakdown) ? $breakdown : [];
        foreach ($breakdown as $i => $row) {
            $key = (string) ($row['key'] ?? '');
            $breakdown[$i]['display'] = rule_describer::describe($key, $criteria[$key] ?? null);
        }

        return [
            'jobid' => $job->get('jobid'),
            'status' => $job->get('status'),
            'count' => $job->get('resultcount'),
            'breakdown' => json_encode($breakdown),
            'context_only_filters' => json_encode($contextrules),
            'has_audience_rules' => $hasaudience,
            'errormsg' => $job->get('errormsg'),
            'timecompleted' => $job->get('timecompleted'),
        ];
    }

    /**
     * Return parameters for get_estimate.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Job identifier'),
            'status' => new external_value(PARAM_ALPHA, 'pending|ready|error'),
            'count' => new external_value(PARAM_INT, 'Audience size when ready', VALUE_DEFAULT, null, NULL_ALLOWED),
            'breakdown' => new external_value(PARAM_RAW, 'JSON list of {key, count} per audience-shaping rule'),
            'context_only_filters' => new external_value(PARAM_RAW, 'JSON list of {key, values} for context-only restrictions'),
            'has_audience_rules' => new external_value(PARAM_BOOL, 'false when no audience-shaping rule is set'),
            'errormsg' => new external_value(PARAM_RAW, 'Error message when status=error', VALUE_DEFAULT, null, NULL_ALLOWED),
            'timecompleted' => new external_value(PARAM_INT, 'Unix ts of completion', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }
}
