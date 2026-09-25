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

use local_awareness\audience\estimator;
use local_awareness\audience\notice_audience;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;

/**
 * A course notice's stored audience count stays current across the change to its criteria hash.
 *
 * Runs the real upgrade function, as upgrade_insistence_test does, against rows stamped the way a
 * save stamped them before the change.
 *
 * Test metadata stays in docblocks while 405 is supported (moodle-cs cannot see attributes there).
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\audience\notice_audience::criteria_for
 */
final class upgrade_audience_hash_test extends \advanced_testcase {
    /**
     * Save a notice through the real path and read it back.
     *
     * @param string $title The title, unique in the test.
     * @param author_scope $scope Where it belongs.
     * @param array $extra More form fields.
     * @return awareness
     */
    private function notice(string $title, author_scope $scope, array $extra = []): awareness {
        $data = (object) ($extra + [
            'title' => $title,
            'content' => '<p>' . $title . '</p>',
            'contentformat' => FORMAT_HTML,
            'cohorts' => '',
            'reqack' => 0,
            'reqcourse' => 0,
            'enabled' => 1,
            'resetinterval' => 0,
            'timestart' => 0,
            'timeend' => 0,
            'pathmatch' => '',
        ]);
        helper::create_new_notice($data, $scope);

        return awareness::get_record(['title' => $title]);
    }

    /**
     * Store a count against a notice as the estimate would have, without touching timemodified.
     *
     * @param awareness $notice The notice.
     * @param string $hash The criteria hash to stamp.
     * @return void
     */
    private function stamp(awareness $notice, string $hash): void {
        global $DB;

        $DB->update_record('local_awareness', (object) [
            'id' => $notice->get('id'),
            'audiencecount' => 7,
            'audiencecomputed' => time() - HOURSECS,
            'audiencehash' => $hash,
        ]);
    }

    /**
     * Only a course notice stamped with its pathmatch in is re-stamped, and nothing else about it moves.
     *
     * The site notice is the control for the course filter: its current hash is the one the step
     * computes with the pathmatch in, so a step reading every row would rewrite it and make it
     * stale. The second course notice, stamped with criteria it does not have, is the control for
     * the match: a stale count must stay stale.
     *
     * @return void
     */
    public function test_a_course_notice_stamped_with_its_pathmatch_reads_current(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $scope = author_scope::course((int) $course->id);
        $saved = $this->notice('Course briefing', $scope);
        $stale = $this->notice('Course reminder', $scope);
        $site = $this->notice('Site briefing', author_scope::site(), ['pathmatch' => '/my/%']);

        $this->assertSame(author_scope::COURSE_PATHMATCH, $saved->get('pathmatch'), 'precondition: the scope forced the reach');
        $old = estimator::hash(estimator::normalise(
            notice_audience::criteria_for($saved) + ['pathmatch' => (string) $saved->get('pathmatch')]
        ));
        $this->assertNotSame(notice_audience::hash_for($saved), $old, 'precondition: the pathmatch changes the hash');

        // Whatever the saves queued goes, so state_of() reads the stored hash rather than a job in flight.
        $DB->delete_records('local_awareness_audience_jobs');
        $this->stamp($saved, $old);
        $this->stamp($stale, estimator::hash(['reqcourse' => 999999]));
        $this->stamp($site, notice_audience::hash_for($site));
        $timemodified = (int) $DB->get_field('local_awareness', 'timemodified', ['id' => $saved->get('id')]);

        $this->assertSame(
            notice_audience::STATE_STALE,
            notice_audience::state_of(new awareness((int) $saved->get('id'))),
            'precondition: before the step the stored count reads as stale'
        );

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/awareness/db/upgrade.php');
        // One version back, so the savepoint is not refused as a downgrade and only this step runs.
        set_config('version', 2026092400, 'local_awareness');
        xmldb_local_awareness_upgrade(2026092400);

        $after = new awareness((int) $saved->get('id'));
        $this->assertSame(notice_audience::STATE_CURRENT, notice_audience::state_of($after));
        $this->assertSame($timemodified, (int) $after->get('timemodified'), 'acceptances are judged against timemodified');

        $this->assertSame(
            notice_audience::STATE_STALE,
            notice_audience::state_of(new awareness((int) $stale->get('id'))),
            'a count stamped with other criteria stays stale'
        );
        $this->assertSame(
            notice_audience::STATE_CURRENT,
            notice_audience::state_of(new awareness((int) $site->get('id'))),
            'a site notice keeps its pathmatch in the hash'
        );
    }
}
