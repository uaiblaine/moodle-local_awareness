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
 * @copyright  2026 Anderson Blaine
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

        self::resolve($job);

        /*
         * Notified from here and not from resolve(), because this method IS the asynchronous path.
         * A small site resolves the same job inline during the request that asked for it, and
         * messaging someone about work they watched finish in milliseconds is noise.
         */
        if ($job->get('status') === audience_job::STATUS_READY && (int) $job->get('noticeid') > 0) {
            self::notify($job);
        }
    }

    /**
     * Tell the person who asked that their estimate is ready.
     *
     * Modelled on asynchronous course backup: the work outlives the request that started it, so the
     * result has to find its way back to a user who has long since navigated away.
     *
     * A failure to message must not fail the task. An adhoc task that throws is retried forever,
     * and the estimate itself — the thing worth keeping — has already been stored by this point.
     *
     * @param audience_job $job A completed job carrying a notice id.
     * @return void
     */
    private static function notify(audience_job $job): void {
        global $DB;

        $notice = \local_awareness\persistent\awareness::get_record(['id' => (int) $job->get('noticeid')]);
        $user = $DB->get_record('user', ['id' => (int) $job->get('userid'), 'deleted' => 0]);
        if (!$notice || !$user) {
            return;
        }

        $data = (object) [
            'title' => $notice->get('title'),
            'count' => (int) $job->get('resultcount'),
        ];

        $message = new \core\message\message();
        $message->component = 'local_awareness';
        $message->name = 'audience_estimate_ready';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('message:audience_ready:subject', 'local_awareness', $data);
        $message->fullmessage = get_string('message:audience_ready:body', 'local_awareness', $data);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/local/awareness/managenotice.php'))->out(false);
        $message->contexturlname = get_string('setting:managenotice', 'local_awareness');

        try {
            message_send($message);
        } catch (\Throwable $e) {
            mtrace("local_awareness: could not notify user {$user->id} about audience job "
                . $job->get('jobid') . ': ' . $e->getMessage());
        }
    }

    /**
     * Run a pending job's estimate and record the outcome on it.
     *
     * Public and static because the web service resolves small sites inline rather than waiting for
     * cron. Both callers must produce the same stored job, so there is one body rather than two;
     * this one owns the try/catch, so an inline caller cannot turn a failed estimate into a failed
     * request.
     *
     * @param audience_job $job A job in pending status.
     * @return void
     */
    public static function resolve(audience_job $job): void {
        try {
            $criteria = json_decode($job->get('criteria'), true);
            if (!is_array($criteria)) {
                $criteria = [];
            }
            /*
             * The per-rule breakdown is drawn only by the editor's panel, which raises jobs with no
             * notice attached. A job refreshing a saved notice's stored total feeds a list column
             * showing one number, so it asks for the total alone.
             *
             * No longer a saving of one table scan per rule — the counts share a single pass now —
             * but each chip is still a conditional column with its own EXISTS evaluated per row, so
             * dropping seven of them roughly halves the work.
             */
            $withbreakdown = (int) $job->get('noticeid') <= 0;
            $result = (new estimator())->estimate($criteria, $withbreakdown);
            $job->set('resultcount', (int) $result['count']);
            $job->set('breakdown', json_encode($result['breakdown']));
            $job->set('status', audience_job::STATUS_READY);
        } catch (\Throwable $e) {
            $job->set('status', audience_job::STATUS_ERROR);
            $job->set('errormsg', $e->getMessage());
        }

        $job->set('timecompleted', time());
        $job->update();

        // A job raised about a saved notice carries its answer back to that notice; one raised from
        // the editor about an unsaved form has no notice to carry it to, and record() says so.
        \local_awareness\audience\notice_audience::record($job);
    }
}
