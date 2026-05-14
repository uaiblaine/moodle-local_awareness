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

namespace local_awareness;

use local_awareness\helper;
use local_awareness\persistent\awareness;
use local_awareness\persistent\audience_job;
use local_awareness\audience\estimator;
use local_awareness\task\estimate_audience as estimate_audience_task;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Webservice functions
 * @package local_awareness
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function dismiss_notice_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'noticeid' => new external_value(PARAM_INT, 'notice id', VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Dismisses notice.
     *
     * @param int $noticeid Notice ID.
     * @return array
     */
    public static function dismiss_notice(int $noticeid): array {
        self::validate_context(\context_system::instance());

        $params = self::validate_parameters(
            self::dismiss_notice_parameters(),
            ['noticeid' => $noticeid]
        );

        $result = [
            'status' => 0,
            'redirecturl' => '',
        ];

        if ($notice = awareness::get_record(['id' => $params['noticeid']])) {
            $result = helper::dismiss_notice($notice);
        }

        return $result;
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function dismiss_notice_returns(): external_single_structure {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success', VALUE_DEFAULT, "0"),
                'redirecturl' => new external_value(PARAM_TEXT, 'redirect url', VALUE_DEFAULT, ""),
            ]
        );
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function acknowledge_notice_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'noticeid' => new external_value(PARAM_INT, 'notice id', VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Acknowledge notice.
     *
     * @param int $noticeid Notice ID.
     * @return array
     */
    public static function acknowledge_notice(int $noticeid): array {
        self::validate_context(\context_system::instance());

        $params = self::validate_parameters(
            self::acknowledge_notice_parameters(),
            ['noticeid' => $noticeid]
        );

        $result = [
            'status' => 0,
            'redirecturl' => '',
        ];

        if ($notice = awareness::get_record(['id' => $params['noticeid']])) {
            $result = helper::acknowledge_notice($notice);
        }

        return $result;
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function acknowledge_notice_returns(): external_single_structure {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success', VALUE_DEFAULT, "0"),
                'redirecturl' => new external_value(PARAM_TEXT, 'redirect url', VALUE_DEFAULT, ""),
            ]
        );
    }

    /**
     * Incoming params.
     * @return external_function_parameters
     */
    public static function track_link_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'linkid' => new external_value(PARAM_INT, 'link id', VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Track link.
     *
     * @param int $linkid Link ID.
     * @return array
     */
    public static function track_link(int $linkid): array {
        self::validate_context(\context_system::instance());

        $params = self::validate_parameters(self::track_link_parameters(), ['linkid' => $linkid]);
        return helper::track_link($params['linkid']);
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function track_link_returns(): external_single_structure {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success', VALUE_DEFAULT, "0"),
                'redirecturl' => new external_value(PARAM_TEXT, 'redirect url', VALUE_DEFAULT, ""),
            ]
        );
    }

    /**
     * Incoming params.
     *
     * @return external_function_parameters
     */
    public static function get_notices_parameters(): external_function_parameters {
        return new external_function_parameters([
            'pageurl' => new external_value(PARAM_RAW, 'current page url', VALUE_DEFAULT, ''),
            'courseid' => new external_value(PARAM_INT, 'current course id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Gets a list of notices.
     *
     * @param string $pageurl Current page URL.
     * @param int $courseid Current course ID.
     * @return array
     */
    public static function get_notices(string $pageurl = '', int $courseid = 0): array {
        self::validate_context(\context_system::instance());

        $params = self::validate_parameters(
            self::get_notices_parameters(),
            ['pageurl' => $pageurl, 'courseid' => $courseid]
        );
        $result = [];
        $result['status'] = true;
        $result['notices'] = json_encode(
            array_map(
                function (awareness $notice): \stdClass {
                    $record = $notice->to_record();
                    // Attach background image URL if one exists.
                    if (!empty($record->bgimage)) {
                        $record->bgimageurl = helper::get_bgimage_url($record->id);
                    } else {
                        $record->bgimageurl = '';
                    }
                    return $record;
                },
                helper::retrieve_user_notices($params['pageurl'], (int) $params['courseid'])
            )
        );

        return $result;
    }

    /**
     * Return parameters.
     *
     * @return external_single_structure
     */
    public static function get_notices_returns(): external_single_structure {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success', VALUE_DEFAULT, "0"),
                'notices' => new external_value(PARAM_RAW, 'json of notices', VALUE_DEFAULT, ""),
            ]
        );
    }

    /**
     * Parameters for search_courses.
     *
     * @return external_function_parameters
     */
    public static function search_courses_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW, 'search query', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search courses by name, returning up to 50 matches.
     *
     * @param string $query Search term.
     * @return array
     */
    public static function search_courses(string $query = ''): array {
        global $DB;

        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('local/awareness:manage', $syscontext);

        $params = self::validate_parameters(
            self::search_courses_parameters(),
            ['query' => $query]
        );

        $query = trim($params['query']);
        $results = [];

        if (strlen($query) >= 2) {
            $likesql = $DB->sql_like('fullname', ':search', false);
            $courses = $DB->get_records_select(
                'course',
                "id <> :siteid AND {$likesql}",
                ['siteid' => SITEID, 'search' => '%' . $DB->sql_like_escape($query) . '%'],
                'fullname ASC',
                'id, fullname',
                0,
                50
            );
            foreach ($courses as $course) {
                $results[] = ['id' => (int) $course->id, 'fullname' => $course->fullname];
            }
        }

        return ['courses' => json_encode($results)];
    }

    /**
     * Return parameters for search_courses.
     *
     * @return external_single_structure
     */
    public static function search_courses_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_value(PARAM_RAW, 'JSON array of {id, fullname}', VALUE_DEFAULT, '[]'),
        ]);
    }

    /**
     * Parameters for estimate_audience.
     *
     * @return external_function_parameters
     */
    public static function estimate_audience_parameters(): external_function_parameters {
        return new external_function_parameters([
            'criteria' => new external_value(
                PARAM_RAW,
                'JSON object of audience and context criteria',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Enqueue (or reuse) an audience-estimate job. Returns the job id the
     * client should poll.
     *
     * @param string $criteria JSON-encoded criteria object
     * @return array
     */
    public static function estimate_audience(string $criteria): array {
        global $USER;

        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('local/awareness:manage', $syscontext);

        $params = self::validate_parameters(
            self::estimate_audience_parameters(),
            ['criteria' => $criteria]
        );

        $raw = json_decode($params['criteria'], true);
        if (!is_array($raw)) {
            $raw = [];
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

        $job = new audience_job(0, (object) [
            'jobid' => audience_job::new_jobid(),
            'userid' => (int) $USER->id,
            'criteriahash' => $hash,
            'criteria' => json_encode($normalised),
            'status' => audience_job::STATUS_PENDING,
        ]);
        $job->create();

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
    public static function estimate_audience_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Job identifier'),
            'status' => new external_value(PARAM_ALPHA, 'pending|ready|error'),
            'reused' => new external_value(PARAM_BOOL, 'true if a cached result was returned'),
        ]);
    }

    /**
     * Parameters for get_estimate.
     *
     * @return external_function_parameters
     */
    public static function get_estimate_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_ALPHANUMEXT, 'Job identifier', VALUE_REQUIRED),
        ]);
    }

    /**
     * Poll the result of an audience-estimate job.
     *
     * @param string $jobid
     * @return array
     */
    public static function get_estimate(string $jobid): array {
        $syscontext = \context_system::instance();
        self::validate_context($syscontext);
        require_capability('local/awareness:manage', $syscontext);

        $params = self::validate_parameters(
            self::get_estimate_parameters(),
            ['jobid' => $jobid]
        );

        $job = audience_job::get_record(['jobid' => $params['jobid']]);
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
        $contextrules = estimator::context_rules_in($criteria);

        return [
            'jobid' => $job->get('jobid'),
            'status' => $job->get('status'),
            'count' => $job->get('resultcount'),
            'breakdown' => $job->get('breakdown') ?: '[]',
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
    public static function get_estimate_returns(): external_single_structure {
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
