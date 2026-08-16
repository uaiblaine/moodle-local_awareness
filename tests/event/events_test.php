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

namespace local_awareness\event;

use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * Tests that each write path fires the event it claims to fire.
 *
 * The fleet rule is that every write fires an event, and until now nothing asserted that any of
 * the eight event classes was ever constructed. That silence hid a real defect for months:
 * enable_notice() and disable_notice() both fired awareness_updated under comments reading "Log
 * enabled event" and "Log disable event", so awareness_enabled and awareness_disabled were
 * unreachable — complete classes, with maintained strings in two languages, listed in the admin
 * event reference, on which an admin could build an event-monitor rule that could never fire.
 *
 * Each case asserts the event CLASS, not merely that some event happened. Asserting a count
 * would have passed throughout the period the wrong class was being fired.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\event\awareness_created
 * @covers \local_awareness\event\awareness_updated
 * @covers \local_awareness\event\awareness_enabled
 * @covers \local_awareness\event\awareness_disabled
 * @covers \local_awareness\event\awareness_reset
 * @covers \local_awareness\event\awareness_deleted
 */
final class events_test extends \advanced_testcase {
    /**
     * Create one notice through the helper, discarding the events that creation itself fires.
     *
     * @return awareness The stored notice.
     */
    private function make_notice(): awareness {
        helper::create_new_notice((object) [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'perpetual' => 1,
        ]);

        $notices = array_values(awareness::get_enabled_notices());
        return reset($notices);
    }

    /**
     * Capture the events fired while running a callable.
     *
     * @param callable $action The write to perform.
     * @return array List of event class names, in firing order.
     */
    private function events_from(callable $action): array {
        $sink = $this->redirectEvents();
        $action();
        $events = $sink->get_events();
        $sink->close();

        return array_map(static fn($event): string => get_class($event), $events);
    }

    /**
     * Creating a notice fires awareness_created, carrying the notice as its object.
     */
    public function test_create_fires_created(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        helper::create_new_notice((object) [
            'title' => 'Policy update',
            'content' => '<p>Read the policy.</p>',
            'perpetual' => 1,
        ]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(awareness_created::class, $event);
        $this->assertSame('local_awareness', $event->objecttable);
        $this->assertEquals(
            \context_system::instance()->id,
            $event->contextid,
            'the notice is a site-wide object, so the event belongs to the system context'
        );
    }

    /**
     * Updating a notice fires awareness_updated.
     */
    public function test_update_fires_updated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_update', 1, 'local_awareness');

        $notice = $this->make_notice();

        $fired = $this->events_from(function () use ($notice) {
            helper::update_notice($notice, (object) [
                'id' => $notice->get('id'),
                'title' => 'Policy update (revised)',
                'content' => '<p>Read the revised policy.</p>',
                'perpetual' => 1,
            ]);
        });

        $this->assertSame([awareness_updated::class], $fired);
    }

    /**
     * Enabling fires awareness_enabled — NOT awareness_updated.
     */
    public function test_enable_fires_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = $this->make_notice();
        helper::disable_notice($notice);

        $fired = $this->events_from(function () use ($notice) {
            helper::enable_notice($notice);
        });

        $this->assertSame([awareness_enabled::class], $fired);
    }

    /**
     * Disabling fires awareness_disabled — NOT awareness_updated.
     */
    public function test_disable_fires_disabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = $this->make_notice();

        $fired = $this->events_from(function () use ($notice) {
            helper::disable_notice($notice);
        });

        $this->assertSame([awareness_disabled::class], $fired);
    }

    /**
     * Enable and disable fire DIFFERENT events from each other and from update.
     *
     * The defect this file was written for is precisely that three verbs shared one event, so a
     * per-verb assertion is not enough on its own: three tests each asserting awareness_updated
     * would also have passed. This pins the distinctness directly.
     */
    public function test_the_three_update_verbs_are_distinguishable(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_update', 1, 'local_awareness');

        $notice = $this->make_notice();

        $disabled = $this->events_from(fn() => helper::disable_notice($notice));
        $enabled = $this->events_from(fn() => helper::enable_notice($notice));
        $updated = $this->events_from(function () use ($notice) {
            helper::update_notice($notice, (object) [
                'id' => $notice->get('id'),
                'title' => 'Policy update (revised)',
                'content' => '<p>Revised.</p>',
                'perpetual' => 1,
            ]);
        });

        $this->assertCount(3, array_unique([...$disabled, ...$enabled, ...$updated]));
    }

    /**
     * Resetting a notice fires awareness_reset.
     */
    public function test_reset_fires_reset(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = $this->make_notice();

        $fired = $this->events_from(function () use ($notice) {
            helper::reset_notice($notice);
        });

        $this->assertSame([awareness_reset::class], $fired);
    }

    /**
     * Deleting a notice fires awareness_deleted.
     */
    public function test_delete_fires_deleted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_delete', 1, 'local_awareness');

        $notice = $this->make_notice();

        $fired = $this->events_from(function () use ($notice) {
            helper::delete_notice($notice);
        });

        $this->assertSame([awareness_deleted::class], $fired);
    }

    /**
     * Every event class the plugin ships is reachable from a write path.
     *
     * A class nobody fires is a promise to an admin building an event-monitor rule. This walks
     * classes/event/ from disk rather than from a hand-kept list, so a new event class added
     * later without a firing site turns this red instead of shipping dead.
     */
    public function test_no_event_class_is_unreachable(): void {
        global $CFG;

        $sources = file_get_contents($CFG->dirroot . '/local/awareness/classes/helper.php');

        $unfired = [];
        foreach (glob($CFG->dirroot . '/local/awareness/classes/event/*.php') as $path) {
            $class = basename($path, '.php');
            if (!str_contains($sources, $class . '::create')) {
                $unfired[] = $class;
            }
        }

        $this->assertSame([], $unfired, 'event classes with no firing site in helper.php');
        // Non-vacuity: the scan found classes at all.
        $this->assertGreaterThan(0, count(glob($CFG->dirroot . '/local/awareness/classes/event/*.php')));
    }
}
