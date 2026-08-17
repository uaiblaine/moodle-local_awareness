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
     * @param array|null $inflight Criteria hashes with a job in flight, already resolved. A caller
     *                             rendering many notices passes one; omit it for the single lookup.
     * @return string One of the STATE_* constants.
     */
    public static function state_of(awareness $notice, ?array $inflight = null): string {
        $stored = $notice->get('audiencehash');
        $current = self::hash_for($notice);

        if ($stored !== null && $stored !== '' && $stored === $current) {
            return self::STATE_CURRENT;
        }

        /*
         * A caller rendering many notices resolves the in-flight set once and passes it; everyone
         * else omits it and pays the single lookup. Without that, a page of notices whose stored
         * hash does not match — which is every notice predating the audience upgrade — issued one
         * query per row for this one branch.
         */
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
            /*
             * Joinable only while nobody else is waiting on its answer. The hash names a set of
             * filters, not a notice — two site-wide notices with no filters normalise identically
             * and hash the same — and a job carries its answer back to exactly one notice. Joining
             * regardless meant attach() overwrote the job's owner, so the notice that raised it was
             * left waiting for a result that would never be written, permanently uncounted.
             *
             * A job the editor raised about an unsaved form has no notice yet and is free to claim,
             * which is what attach() exists for; noticeid is nullable, so (int) null === 0 is that
             * case. attach()'s own guard is left alone: it becomes an invariant rather than the only
             * line of defence.
             *
             * Refusing to JOIN rather than refusing to attach is the point. A no-op attach() would
             * leave this returning STATE_PENDING for a job that will never write here either, which
             * moves the defect from one notice to the other instead of removing it.
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
        global $DB;

        /*
         * A job that failed has no count to record. resultcount is 0 on an errored job, and
         * writing that 0 alongside a fresh audiencehash told the editor the answer was CURRENT —
         * so a failed estimate displayed "0 people" as though it were measured, and the stored
         * hash then matched the criteria, which stopped the next unforced refresh from retrying.
         * The failure became sticky and looked like a result.
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
         * Written around the persistent on purpose. core\persistent::update() is final and stamps
         * timemodified unconditionally — and in this plugin timemodified is not metadata, it is the
         * "the author changed this notice" signal: the first thing helper::must_reshow() reads, and
         * the entire content of helper::reset_notice(). Counting an audience is not an authoring
         * act, so putting it through update() made every recalculation a silent Reset: everyone who
         * had already accepted or dismissed the notice got it back on their next page load, and
         * could write a second acknowledgement row, which nothing dedupes.
         *
         * These three columns hold a measurement ABOUT the notice rather than part of it. Writing
         * them directly also stops usermodified being falsified — a cron-resolved job used to stamp
         * the notice as last modified by whoever happened to queue it.
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
