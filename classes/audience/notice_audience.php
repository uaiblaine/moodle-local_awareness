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

namespace local_awareness\audience;

use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;
use local_awareness\task\estimate_audience as estimate_audience_task;

/**
 * The audience size of a SAVED notice: reading it, deciding whether it is still true, refreshing it.
 *
 * The editor's panel answers "how many would this reach" about a form still being typed into. This
 * answers it about a stored notice, which is a different object with different economics — it can
 * be computed once, kept, listed, and recomputed on request instead of on every keystroke.
 *
 * The stored answer carries the hash of the criteria it was computed from. That is what makes it
 * honest: a count is a statement about a particular set of filters, so once the filters change the
 * number is not merely old, it is about something else. Comparing hashes separates the two, which
 * a timestamp cannot.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notice_audience {
    /** The stored count is missing entirely. */
    public const STATE_NONE = 'none';
    /** The stored count matches the notice's current criteria. */
    public const STATE_CURRENT = 'current';
    /** The stored count was computed from criteria the notice no longer has. */
    public const STATE_STALE = 'stale';
    /** A computation is queued or running. */
    public const STATE_PENDING = 'pending';

    /**
     * The normalised criteria a saved notice targets.
     *
     * Assembles the same shape the editor's JavaScript sends, from the columns and the filtervalues
     * JSON the notice actually stores, so a count computed from a saved notice and one computed
     * from the form that saved it hash identically. Any divergence here shows up as a notice that
     * is permanently "stale" the moment it is saved.
     *
     * @param awareness $notice
     * @return array Normalised criteria.
     */
    public static function criteria_for(awareness $notice): array {
        $raw = [];

        // The persistent's get_cohorts() splits the stored list itself and always returns an array.
        $cohorts = $notice->get('cohorts');
        if (!empty($cohorts)) {
            $raw['cohorts'] = $cohorts;
        }

        $raw['reqcourse'] = (int) $notice->get('reqcourse');
        $raw['pathmatch'] = (string) $notice->get('pathmatch');

        $filters = json_decode((string) $notice->get('filtervalues'), true);
        if (is_array($filters)) {
            $raw += $filters;
        }

        return estimator::normalise($raw);
    }

    /**
     * The criteria hash a saved notice should currently be counted under.
     *
     * @param awareness $notice
     * @return string
     */
    public static function hash_for(awareness $notice): string {
        return estimator::hash(self::criteria_for($notice));
    }

    /**
     * How the stored count relates to the notice as it stands now.
     *
     * @param awareness $notice
     * @return string One of the STATE_* constants.
     */
    public static function state_of(awareness $notice): string {
        $stored = $notice->get('audiencehash');
        $current = self::hash_for($notice);

        if ($stored !== null && $stored !== '' && $stored === $current) {
            return self::STATE_CURRENT;
        }

        if (audience_job::find_in_flight($current)) {
            return self::STATE_PENDING;
        }

        return ($notice->get('audiencecount') === null) ? self::STATE_NONE : self::STATE_STALE;
    }

    /**
     * Bring a notice's stored audience size up to date, computing now or in the background.
     *
     * Does nothing when the stored count already describes the notice's current criteria, which is
     * what keeps saving a notice whose filters did not change from costing a scan of every user.
     *
     * @param awareness $notice The saved notice.
     * @param bool $force Recompute even when the stored count is current.
     * @return string The state the notice is left in — current when it was computed here, pending
     *                when the work was queued, or unchanged when there was nothing to do.
     */
    public static function refresh(awareness $notice, bool $force = false): string {
        global $USER;

        $criteria = self::criteria_for($notice);
        $hash = estimator::hash($criteria);

        if (!$force && $notice->get('audiencehash') === $hash) {
            return self::STATE_CURRENT;
        }

        if (!$force && ($existing = audience_job::find_in_flight($hash))) {
            // Someone is already computing exactly this; joining costs nothing and queues nothing.
            self::attach($existing, (int) $notice->get('id'));
            return self::STATE_PENDING;
        }

        $job = new audience_job(0, (object) [
            'jobid' => audience_job::new_jobid(),
            'userid' => (int) $USER->id,
            'noticeid' => (int) $notice->get('id'),
            'criteriahash' => $hash,
            'criteria' => json_encode($criteria),
            'status' => audience_job::STATUS_PENDING,
        ]);
        $job->create();

        if (live_mode::is_live()) {
            /*
             * Small site: finish now, so the list is right the moment the author lands on it and
             * nobody is notified about work that took milliseconds.
             */
            estimate_audience_task::resolve($job);
            return self::STATE_CURRENT;
        }

        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->set_userid((int) $USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return self::STATE_PENDING;
    }

    /**
     * Point an existing job at a notice, so its result is written back when it completes.
     *
     * @param audience_job $job
     * @param int $noticeid
     * @return void
     */
    private static function attach(audience_job $job, int $noticeid): void {
        if ((int) $job->get('noticeid') === $noticeid) {
            return;
        }
        $job->set('noticeid', $noticeid);
        $job->update();
    }

    /**
     * Write a completed job's result onto the notice it belongs to.
     *
     * Called from the task rather than from the job, because a job with no notice is a perfectly
     * ordinary thing — the editor runs them about forms that have never been saved.
     *
     * @param audience_job $job A job in ready status.
     * @return awareness|null The notice updated, or null when the job had none or it has since gone.
     */
    public static function record(audience_job $job): ?awareness {
        $noticeid = (int) $job->get('noticeid');
        if ($noticeid <= 0) {
            return null;
        }

        $notice = awareness::get_record(['id' => $noticeid]);
        if (!$notice) {
            // The notice was deleted while the estimate was queued; nothing to record it against.
            return null;
        }

        $notice->set('audiencecount', (int) $job->get('resultcount'));
        $notice->set('audiencecomputed', (int) $job->get('timecompleted'));
        $notice->set('audiencehash', $job->get('criteriahash'));
        $notice->update();

        return $notice;
    }
}
