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
use local_awareness\persistent\awareness;

/**
 * A service declared 'readonlysession' runs without the session lock, so nothing it writes to
 * $SESSION, or to $USER, survives the request.
 *
 * Core's contract for a read-only session is the one write_close() checks
 * (lib/classes/session/manager.php, the array_session_diff() of $SESSION taken at session start
 * and at close): no $SESSION key added, removed or changed. That is what is asserted here around
 * every call of the six editor and list reads, the way the browser makes it. Core does not compare
 * $USER, so a write to it is lost without an error, which is exactly why local_awareness_getnotices
 * stays undeclared and why the plugin's three $USER markers are compared here too:
 * helper::select_for_display() records each notice it hands over in $USER->awarenessshown, the
 * dismiss and acknowledge services refuse a notice that record does not hold, and a read-only
 * request would drop the record without a word.
 *
 * The metadata stays in docblocks rather than attributes because the plugin still supports
 * Moodle 4.5, whose moodle-cs cannot see attributes.
 *
 * @package    local_awareness
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_awareness\external\check_collision
 * @covers     \local_awareness\external\search_roles
 * @covers     \local_awareness\external\search_courses
 * @covers     \local_awareness\external\get_estimate
 * @covers     \local_awareness\external\preview_notice
 * @covers     \local_awareness\external\render_notice
 */
final class readonly_session_test extends \advanced_testcase {
    /** @var string The read that must keep the session lock, and why is in the class docblock. */
    private const LOCKED_READ = 'local_awareness_getnotices';

    /**
     * @var string[] The $USER fields the plugin keeps between requests. A read-only request drops a
     *     write to any of them without an error, and the write services gate on awarenessshown, so
     *     these are compared beside $SESSION although core compares only $SESSION.
     */
    private const USER_MARKERS = ['awarenessshown', 'awarenessinteracted', 'viewednotices'];

    /**
     * resetAfterTest() does not restore superglobals: drop the sesskey the calls plant in $_POST.
     *
     * @return void
     */
    protected function tearDown(): void {
        unset($_POST['sesskey']);
        parent::tearDown();
    }

    /**
     * The services that must hold no session lock, each with the arguments the editor or the list
     * sends against the fixture below.
     *
     * @param int $noticeid The fixture's notice.
     * @param string $jobid The fixture's audience estimate.
     * @return array Service name => arguments.
     */
    private static function read_only_calls(int $noticeid, string $jobid): array {
        return [
            'local_awareness_check_collision' => ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true],
            'local_awareness_search_roles' => ['query' => 'student'],
            'local_awareness_search_courses' => ['query' => 'Probe'],
            'local_awareness_get_estimate' => ['jobid' => $jobid],
            'local_awareness_preview_notice' => ['courseid' => 0, 'title' => 'Probe preview', 'content' => '<p>Probe</p>'],
            'local_awareness_render_notice' => ['noticeid' => $noticeid],
        ];
    }

    /**
     * Read the plugin's db/services.php as core does.
     *
     * @return array The $functions array it declares.
     */
    private static function declared_functions(): array {
        $functions = null;
        include(\core_component::get_component_directory('local_awareness') . '/db/services.php');

        return $functions;
    }

    /**
     * $SESSION as core compares it: one entry per key, the value serialised so a change inside a
     * nested object is seen too.
     *
     * @return array Key => serialised value.
     */
    private static function session_snapshot(): array {
        global $SESSION;

        return array_map('serialize', (array) $SESSION);
    }

    /**
     * The plugin's $USER markers, serialised, null for one the session does not hold.
     *
     * @return array Field => serialised value or null.
     */
    private static function user_markers(): array {
        global $USER;

        $markers = [];
        foreach (self::USER_MARKERS as $field) {
            $markers[$field] = property_exists($USER, $field) ? serialize($USER->$field) : null;
        }

        return $markers;
    }

    /**
     * The $SESSION keys added, removed or changed between two snapshots.
     *
     * @param array $before Snapshot taken before.
     * @param array $after Snapshot taken after.
     * @return array The keys, sorted.
     */
    private static function changed_keys(array $before, array $after): array {
        $keys = array_keys(array_diff_assoc($before, $after) + array_diff_assoc($after, $before));
        sort($keys);

        return $keys;
    }

    /**
     * Every read except getnotices is declared read-only, no write is, the exercised set is the
     * declared one, and core's own reader of db/services.php sees the flag.
     *
     * Reading through external_function_info() is the point: it is the value
     * external_api::call_external_function() decides the session lock from.
     *
     * @return void
     */
    public function test_every_read_but_getnotices_and_no_write_is_declared_read_only(): void {
        $declared = [];
        foreach (self::declared_functions() as $name => $function) {
            $readonly = !empty($function['readonlysession']);
            $this->assertSame($function['type'] === 'read' && $name !== self::LOCKED_READ, $readonly, $name);
            $this->assertSame($readonly, (bool) external_api::external_function_info($name)->readonlysession, $name);
            if ($readonly) {
                $declared[] = $name;
            }
        }
        sort($declared);
        $exercised = array_keys(self::read_only_calls(1, 'x'));
        sort($exercised);

        $this->assertSame('read', self::declared_functions()[self::LOCKED_READ]['type']);
        $this->assertSame($exercised, $declared);
    }

    /**
     * The comparison sees a write, so an empty result below is not an empty comparison.
     *
     * @return void
     */
    public function test_the_snapshot_sees_a_write(): void {
        global $SESSION;

        $this->resetAfterTest();
        $before = self::session_snapshot();
        $SESSION->local_awareness_probe = (object) ['seen' => 1];
        $added = self::session_snapshot();
        $SESSION->local_awareness_probe->seen = 2;

        $this->assertSame(['local_awareness_probe'], self::changed_keys($before, $added));
        $this->assertSame(['local_awareness_probe'], self::changed_keys($added, self::session_snapshot()));
    }

    /**
     * Every read-only service leaves $SESSION as it found it, on a fixture that gives it work.
     *
     * A repeating Dashboard notice the collision check finds and the list renders, a course the
     * course search finds, and an audience estimate queued for a cohort; the caller is the site
     * admin, who passes every author check. The read-only branch of
     * external_api::call_external_function() is taken, as on a site with
     * $CFG->enable_read_only_sessions on.
     *
     * Each call starts from an empty session, as a fresh request would. The fixture itself goes
     * through the author scope (queueing the estimate does), so a write planted on that path would
     * already sit in a shared session with the same value, and a second write of it would change
     * nothing the comparison can see.
     *
     * One require_login() runs before each snapshot. Core's after_require_login callbacks run
     * inside every service of every component, and under PHPUnit tool_mfa's writes a marker
     * ($SESSION->mfa_login_hook_test, admin/tool/mfa/lib.php); paying it first keeps that core
     * write out of the comparison without exempting any key from it.
     *
     * @return void
     */
    public function test_read_only_services_leave_the_session_untouched(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        set_config('enabled', 1, 'local_awareness');
        $this->setAdminUser();
        $notice = new awareness(0, (object) [
            'title' => 'Probe rival',
            'content' => '<p>Probe rival</p>',
            'pathmatch' => '/my/%',
            'resetinterval' => DAYSECS,
            'enabled' => 1,
        ]);
        $notice->create();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Probe course']);
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->getDataGenerator()->create_user()->id);
        $jobid = (string) estimate_audience::execute(json_encode(['cohorts' => [(int) $cohort->id]]))['jobid'];
        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $CFG->enable_read_only_sessions = true;

        foreach (self::read_only_calls((int) $notice->get('id'), $jobid) as $name => $args) {
            \core\session\manager::init_empty_session();
            $this->setAdminUser();
            require_login();
            $_POST['sesskey'] = sesskey();

            $before = self::session_snapshot();
            $markersbefore = self::user_markers();
            $result = external_api::call_external_function($name, $args, true);
            $after = self::session_snapshot();
            $markersafter = self::user_markers();

            $this->assertFalse($result['error'], "$name: " . json_encode($result['exception'] ?? null));
            // The control: the call found the fixture, so its whole path ran.
            $data = $result['data'];
            match ($name) {
                'local_awareness_check_collision' => $this->assertContains('Probe rival', $data['titles'], $name),
                'local_awareness_search_roles' => $this->assertContains(
                    $studentid,
                    array_map('intval', array_column(json_decode($data['roles'], true), 'id')),
                    $name
                ),
                'local_awareness_search_courses' => $this->assertContains(
                    (int) $course->id,
                    array_map('intval', array_column(json_decode($data['courses'], true), 'id')),
                    $name
                ),
                'local_awareness_get_estimate' => $this->assertTrue(
                    $data['status'] !== 'error' && $data['has_audience_rules'],
                    "$name: the job was not found"
                ),
                'local_awareness_preview_notice' => $this->assertStringContainsString('Probe preview', $data['title'], $name),
                'local_awareness_render_notice' => $this->assertSame((int) $notice->get('id'), (int) $data['id'], $name),
            };
            $this->assertSame([], self::changed_keys($before, $after), "$name changed \$SESSION");
            $this->assertSame($markersbefore, $markersafter, "$name changed a \$USER marker");
        }
    }
}
