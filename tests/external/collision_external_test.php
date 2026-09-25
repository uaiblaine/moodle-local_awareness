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

use local_awareness\external\check_collision;
use local_awareness\persistent\awareness;

/**
 * The web service behind the editor's live collision warning.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external\check_collision
 */
final class collision_external_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create a repeating notice.
     *
     * @param string $title Title.
     * @param string $pathmatch Page reach.
     * @return awareness
     */
    private function repeating(string $title, string $pathmatch): awareness {
        $notice = new awareness(0, (object) [
            'title' => $title,
            'content' => '<p>' . $title . '</p>',
            'pathmatch' => $pathmatch,
            'resetinterval' => DAYSECS,
            'enabled' => 1,
        ]);
        $notice->create();

        return $notice;
    }

    /**
     * An instant as the end date selector holds it, in a given timezone.
     *
     * @param int $time The instant.
     * @param string|int $timezone The timezone to read it in; 99 for the current user's.
     * @return array The year, month, day, hour and minute check_collision reads.
     */
    private function selector_parts(int $time, $timezone = 99): array {
        $date = usergetdate($time, $timezone);

        return [
            'year' => (int) $date['year'],
            'month' => (int) $date['mon'],
            'day' => (int) $date['mday'],
            'hour' => (int) $date['hours'],
            'minute' => (int) $date['minutes'],
        ];
    }

    /**
     * It names the notices a new one would compete with.
     */
    public function test_it_names_the_competing_notices(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');
        $this->repeating('Somewhere else', '/user/profile.php');

        $result = check_collision::execute(0, '/my/%', true);

        $this->assertSame(['Dashboard rival'], $result['titles']);
    }

    /**
     * A notice that is not set to repeat competes with nobody.
     */
    public function test_a_notice_that_does_not_repeat_reports_nothing(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');

        // Control: the same page reach with repeats on does report, so the empty result below
        // comes from the repeat flag and not from an empty site.
        $this->assertSame(['Dashboard rival'], check_collision::execute(0, '/my/%', true)['titles']);

        $this->assertSame([], check_collision::execute(0, '/my/%', false)['titles']);
    }

    /**
     * A notice whose end has passed competes with nobody, so the editor warns about nothing.
     *
     * The controls are the same end on a perpetual notice, whose window the save discards, and an
     * end still ahead: both report the rival, so the empty answer comes from the end alone.
     */
    public function test_a_notice_whose_end_has_passed_reports_nothing(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');
        $ended = $this->selector_parts(time() - DAYSECS);

        $this->assertSame(['Dashboard rival'], check_collision::execute(0, '/my/%', true, 0, true, $ended)['titles']);
        $this->assertSame(
            ['Dashboard rival'],
            check_collision::execute(0, '/my/%', true, 0, false, $this->selector_parts(time() + WEEKSECS))['titles']
        );

        $this->assertSame([], check_collision::execute(0, '/my/%', true, 0, false, $ended)['titles']);
    }

    /**
     * The end date is read in the author's timezone, as the save reads it.
     *
     * Fourteen hours separate the author from the server here, so an end two hours ago in the
     * author's time would still be twelve hours ahead if its parts were read in the server's.
     */
    public function test_the_end_is_read_in_the_authors_timezone(): void {
        global $USER;

        $this->setTimezone('UTC', 'UTC');
        $this->setAdminUser();
        $USER->timezone = 'Pacific/Kiritimati';
        $this->repeating('Dashboard rival', '/my/%');

        // Precondition: the author's wall clock really is fourteen hours ahead of the server's.
        $now = time();
        $this->assertSame(
            (int) usergetdate($now + (14 * HOURSECS), 'UTC')['hours'],
            (int) usergetdate($now, 99)['hours']
        );

        $ahead = $this->selector_parts($now + (2 * HOURSECS), 'Pacific/Kiritimati');
        $this->assertSame(['Dashboard rival'], check_collision::execute(0, '/my/%', true, 0, false, $ahead)['titles']);

        $ended = $this->selector_parts($now - (2 * HOURSECS), 'Pacific/Kiritimati');
        $this->assertSame([], check_collision::execute(0, '/my/%', true, 0, false, $ended)['titles']);
    }

    /**
     * The window arrives in the shape the editor sends it, through the same validation.
     *
     * An older client sends neither key, which test_the_declared_return_shape_carries_the_titles
     * covers; a form with no end date sends null.
     */
    public function test_the_declared_parameters_accept_the_window_the_editor_sends(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');
        $_POST['sesskey'] = sesskey();
        $args = ['noticeid' => 0, 'courseid' => 0, 'pathmatch' => '/my/%', 'repeats' => true, 'perpetual' => false];

        $response = \core_external\external_api::call_external_function(
            'local_awareness_check_collision',
            $args + ['timeend' => null],
            false
        );
        $this->assertFalse($response['error'], 'a null end failed validation');
        $this->assertSame(['Dashboard rival'], $response['data']['titles']);

        $response = \core_external\external_api::call_external_function(
            'local_awareness_check_collision',
            $args + ['timeend' => $this->selector_parts(time() - DAYSECS)],
            false
        );
        $this->assertFalse($response['error'], 'the end date failed validation');
        $this->assertSame([], $response['data']['titles']);
    }

    /**
     * Enumerating notices is limited to users who can manage them.
     *
     * The reply names notices the caller may have no other way of seeing, so the gate is the point
     * rather than a formality.
     */
    public function test_it_requires_the_manage_capability(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        check_collision::execute(0, '/my/%', true);
    }

    /**
     * The declared return shape survives cleaning, so the titles actually reach the client.
     *
     * clean_returnvalue() silently drops anything the returns description does not declare, so a
     * structure that disagreed with the payload would strip it and the editor would stay silent.
     */
    public function test_the_declared_return_shape_carries_the_titles(): void {
        $this->setAdminUser();
        $this->repeating('Dashboard rival', '/my/%');
        $_POST['sesskey'] = sesskey();

        $response = \core_external\external_api::call_external_function(
            'local_awareness_check_collision',
            ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true],
            false
        );

        $this->assertFalse($response['error']);
        $this->assertSame(['Dashboard rival'], $response['data']['titles']);
    }

    /**
     * A title with a bare "<" reaches the client instead of failing the whole response.
     *
     * The return slot is PARAM_TEXT, whose cleaner runs strip_tags(), and clean_returnvalue()
     * throws when the cleaned value differs from the original. The "<3" in the fixture is what
     * trips it; a "<b>x</b>" fixture would prove nothing, because format_string() strips it the
     * same way in both escape modes.
     */
    public function test_a_title_the_cleaner_would_alter_still_reaches_the_client(): void {
        $this->setAdminUser();
        $this->repeating('A & B <3', '/my/%');
        $_POST['sesskey'] = sesskey();

        $response = \core_external\external_api::call_external_function(
            'local_awareness_check_collision',
            ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true],
            false
        );

        $this->assertFalse($response['error'], 'the response failed cleaning');
        $this->assertCount(1, $response['data']['titles']);
        $this->assertStringStartsWith('A & B', $response['data']['titles'][0]);
    }

    /**
     * The same guarantee holds on a site that has switched formatstringstriptags off.
     *
     * With it off, format_string() cleans rather than strips, and a real tag in a title comes back
     * whole, which the PARAM_TEXT cleaner would then strip, failing the response.
     */
    public function test_a_tagged_title_reaches_the_client_when_the_site_does_not_strip_tags(): void {
        $this->setAdminUser();
        set_config('formatstringstriptags', 0);
        $this->repeating('<b>Renewal</b> rival', '/my/%');
        $_POST['sesskey'] = sesskey();

        $response = \core_external\external_api::call_external_function(
            'local_awareness_check_collision',
            ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true],
            false
        );

        $this->assertFalse($response['error'], 'the response failed cleaning');
        $this->assertSame(['Renewal rival'], $response['data']['titles']);
    }
}
