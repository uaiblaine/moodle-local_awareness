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

namespace local_awareness\task;

use local_awareness\audience\estimator;
use local_awareness\persistent\audience_job;

/**
 * Ad-hoc task that resolves a queued audience-estimate job.
 *
 * Custom data: {jobid: string}
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class estimate_audience extends \core\task\adhoc_task {

    /**
     * Run the task.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $jobid = isset($data->jobid) ? (string) $data->jobid : '';
        if ($jobid === '') {
            mtrace('local_awareness: estimate_audience invoked without jobid');
            return;
        }

        $job = audience_job::get_record(['jobid' => $jobid]);
        if (!$job) {
            mtrace("local_awareness: audience job {$jobid} not found, skipping");
            return;
        }

        // Idempotent: if the job has already been processed, do nothing.
        if ($job->get('status') !== audience_job::STATUS_PENDING) {
            mtrace("local_awareness: audience job {$jobid} already in status {$job->get('status')}, skipping");
            return;
        }

        try {
            $criteria = json_decode($job->get('criteria'), true);
            if (!is_array($criteria)) {
                $criteria = [];
            }
            $result = (new estimator())->estimate($criteria);
            $job->set('resultcount', (int) $result['count']);
            $job->set('breakdown', json_encode($result['breakdown']));
            $job->set('status', audience_job::STATUS_READY);
        } catch (\Throwable $e) {
            $job->set('status', audience_job::STATUS_ERROR);
            $job->set('errormsg', $e->getMessage());
        }

        $job->set('timecompleted', time());
        $job->update();
    }
}
