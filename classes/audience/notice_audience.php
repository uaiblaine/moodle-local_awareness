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

use local_awareness\local\author_scope;
use local_awareness\persistent\audience_job;
use local_awareness\persistent\awareness;
use local_awareness\task\estimate_audience as estimate_audience_task;

/**
 * The audience size of a saved notice: reading it, deciding whether it is still true, refreshing it.
 *
 * The editor's panel counts a form still being edited; this counts a stored notice, which can be
 * computed once, kept, listed and recomputed on request.
 *
 * The stored count carries the hash of the criteria it was computed from. A count describes one set
 * of filters, so once the filters change it describes something else rather than being merely old;
 * comparing hashes detects that, which a timestamp cannot.
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
    /** The estimate run during the request failed and stored nothing. From refresh() and resolve_inline(), never state_of(). */
    public const STATE_ERROR = 'error';

    /**
     * The normalised criteria a saved notice targets.
     *
     * Assembles the criteria the estimate_audience web service hashes for the editor's form, from
     * the columns and the filtervalues JSON the notice actually stores, so a count computed from a
     * saved notice and one computed from the form that saved it hash identically. Any divergence
     * here shows up as a notice that is permanently "stale" the moment it is saved, and as a save
     * that cannot join the editor's job for the same question.
     *
     * A course notice carries no pathmatch: the one it stores is the scope's forced reach, which
     * {@see \local_awareness\external\estimate_audience::execute()} drops under a course scope.
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
        if (author_scope::of($notice)->is_site()) {
            $raw['pathmatch'] = (string) $notice->get('pathmatch');
        }

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
     * @param array|null $inflight Criteria hashes with a job in flight, as audience_job::in_flight_hashes()
     *                             returns them. A caller rendering many notices passes it, so the page
     *                             costs one query rather than one per notice; omit it for a single lookup.
     * @return string One of the STATE_* constants.
     */
    public static function state_of(awareness $notice, ?array $inflight = null): string {
        $stored = $notice->get('audiencehash');
        $current = self::hash_for($notice);

        if ($stored !== null && $stored !== '' && $stored === $current) {
            return self::STATE_CURRENT;
        }

        $pending = ($inflight === null)
            ? (bool) audience_job::find_in_flight($current)
            : isset($inflight[$current]);

        if ($pending) {
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
     * @return string The state the notice is left in — current when it was computed here, error when
     *                that computation failed, pending when the work was queued, or current when there
     *                was nothing to do.
     */
    public static function refresh(awareness $notice, bool $force = false): string {
        global $USER;

        $criteria = self::criteria_for($notice);
        $hash = estimator::hash($criteria);

        if (!$force && $notice->get('audiencehash') === $hash) {
            return self::STATE_CURRENT;
        }

        if (!$force && ($existing = audience_job::find_in_flight($hash))) {
            /*
             * Join only a job no other notice is waiting on. The hash names a set of filters, not a
             * notice (two site-wide notices with no filters hash the same), and a job writes its
             * answer back to exactly one notice, so taking over another notice's job would leave
             * that notice pending for ever. A job the editor raised about an unsaved form has a null
             * noticeid, read here as 0, and is free to claim. Any other job is left alone and this
             * notice gets a job of its own below.
             */
            $owner = (int) $existing->get('noticeid');
            if ($owner === 0 || $owner === (int) $notice->get('id')) {
                // Someone is already computing exactly this; joining costs nothing and queues nothing.
                self::attach($existing, (int) $notice->get('id'));
                return self::STATE_PENDING;
            }
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
        audience_job::trigger_created_event($job);

        if (live_mode::is_live()) {
            /*
             * Small site: finish now, so the list is right the moment the author lands on it and
             * nobody is notified about work that took milliseconds.
             */
            return self::resolve_inline($job);
        }

        $task = new estimate_audience_task();
        $task->set_custom_data(['jobid' => $job->get('jobid')]);
        $task->set_userid((int) $USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return self::STATE_PENDING;
    }

    /**
     * Resolve a job during the request and say what it left its notice in.
     *
     * resolve() catches every failure and records it on the job, so only the job's status tells a
     * stored count from nothing stored.
     *
     * @param audience_job $job A pending job raised for a saved notice.
     * @return string STATE_CURRENT when the count was computed, STATE_ERROR when the estimate failed and nothing was stored.
     */
    public static function resolve_inline(audience_job $job): string {
        estimate_audience_task::resolve($job);

        return $job->get('status') === audience_job::STATUS_READY ? self::STATE_CURRENT : self::STATE_ERROR;
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
        global $DB;

        /*
         * Only a ready job has a count. An errored job has no resultcount, which the cast below
         * reads as 0; recording it with the job's hash would show "0 people" as a current, measured
         * answer, and the matching hash would stop the next unforced refresh() from retrying.
         */
        if ($job->get('status') !== audience_job::STATUS_READY) {
            return null;
        }

        $noticeid = (int) $job->get('noticeid');
        if ($noticeid <= 0) {
            return null;
        }

        $notice = awareness::get_record(['id' => $noticeid]);
        if (!$notice) {
            // The notice was deleted while the estimate was queued; nothing to record it against.
            return null;
        }

        $count = (int) $job->get('resultcount');
        $computed = (int) $job->get('timecompleted');
        $hash = $job->get('criteriahash');

        /*
         * Written around the persistent. core\persistent::update() is final and always stamps
         * timemodified, and here timemodified means "the author changed this notice": it is what
         * helper::must_reshow() and helper::acceptance_is_current() judge a recorded interaction
         * against, and all that helper::reset_notice() changes. Counting an audience is not an edit,
         * so going through update() would reset the notice for everyone who had accepted or
         * dismissed it. Writing the three columns directly also leaves usermodified alone, which
         * update() would set to whoever queued the job.
         */
        $DB->update_record(awareness::TABLE, (object) [
            'id' => $noticeid,
            'audiencecount' => $count,
            'audiencecomputed' => $computed,
            'audiencehash' => $hash,
        ]);

        // Keep the object handed back in step with the row, without going through update().
        $notice->set('audiencecount', $count);
        $notice->set('audiencecomputed', $computed);
        $notice->set('audiencehash', $hash);

        return $notice;
    }
}
