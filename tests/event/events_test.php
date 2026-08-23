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

use local_awareness\audience\notice_audience;
use local_awareness\helper;
use local_awareness\persistent\awareness;
use local_awareness\persistent\noticelink;

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
 * @covers \local_awareness\event\awareness_dismissed
 * @covers \local_awareness\event\awareness_link_clicked
 * @covers \local_awareness\event\awareness_audience_estimated
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
     *
     * It also fires awareness_audience_estimated, and that is asserted here rather than filtered
     * out: create_new_notice() ends in notice_audience::refresh(), which creates an audience-job
     * row, and a save really does raise an estimate. Asserting the ordered pair keeps the file's
     * rule — assert the CLASS, never a count — while recording the coupling, so a later change
     * that stops estimating on save shows up here instead of passing quietly.
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

        $this->assertSame(
            [awareness_created::class, awareness_audience_estimated::class],
            array_map(static fn($event): string => get_class($event), $events)
        );
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

        /*
         * One event, not two: update_notice() also ends in notice_audience::refresh(), but the
         * criteria are unchanged from make_notice(), so the job raised moments ago is reused and
         * no row is created. That is the dedup working, and it is why the estimate event belongs
         * to job CREATION rather than to the web-service call.
         */
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
     * Dismissing a notice that does NOT require acknowledgement still fires awareness_dismissed.
     *
     * This is the defect: the trigger used to sit inside the reqack branch, so an ordinary
     * dismissal left no trace an admin could reach. local_awareness_ack only ever holds reqack
     * rows, and local_awareness_lastview records that the notice was met without recording who
     * acted, so nothing anywhere logged it.
     *
     * The control is the reqack case below: both must fire, and only the compliance ROW differs
     * between them. Without the pair, an assertion that "a dismissal fires" would be satisfied by
     * the reqack path alone — which was already true before the fix.
     */
    public function test_dismissing_an_ordinary_notice_fires_dismissed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = new awareness(0, (object) [
            'title' => 'Ordinary notice',
            'content' => '<p>No acknowledgement required.</p>',
            'reqack' => 0,
        ]);
        $notice->create();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $fired = $this->events_from(function () use ($notice) {
            helper::dismiss_notice($notice);
        });

        $this->assertSame([awareness_dismissed::class], $fired);

        // Precondition, so the assertion above cannot be satisfied by the reqack path instead.
        $this->assertSame(0, (int) $notice->get('reqack'));
    }

    /**
     * Dismissing a notice that DOES require acknowledgement fires the same event.
     *
     * The control for the test above. It also pins the rule the dedupe comment states: a repeated
     * refusal is a real event even though the compliance row must not be duplicated, so the second
     * dismissal fires again while writing nothing.
     */
    public function test_dismissing_a_reqack_notice_fires_dismissed_every_time(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = new awareness(0, (object) [
            'title' => 'Acknowledge me',
            'content' => '<p>Acknowledgement required.</p>',
            'reqack' => 1,
        ]);
        $notice->create();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $first = $this->events_from(fn() => helper::dismiss_notice($notice));
        $second = $this->events_from(fn() => helper::dismiss_notice($notice));

        $this->assertSame([awareness_dismissed::class], $first);
        $this->assertSame([awareness_dismissed::class], $second);

        // Two events, one compliance row: the dedupe guards the row, not the event.
        $this->assertSame(1, $DB->count_records('local_awareness_ack', [
            'noticeid' => $notice->get('id'),
            'userid' => $user->id,
            'action' => 0,
        ]));
    }

    /**
     * A guest dismissal fires nothing, because every guest session shares one user id.
     */
    public function test_a_guest_dismissal_fires_no_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = new awareness(0, (object) [
            'title' => 'Ordinary notice',
            'content' => '<p>No acknowledgement required.</p>',
            'reqack' => 0,
        ]);
        $notice->create();

        $this->setGuestUser();

        $fired = $this->events_from(function () use ($notice) {
            helper::dismiss_notice($notice);
        });

        $this->assertSame([], $fired);

        /*
         * Control: the same notice, dismissed by a real user, DOES fire. Without it this passes
         * for any reason at all — including the trigger having been deleted outright.
         */
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertSame(
            [awareness_dismissed::class],
            $this->events_from(fn() => helper::dismiss_notice($notice))
        );
    }

    /**
     * Recording a link click fires awareness_link_clicked, naming the notice it came from.
     */
    public function test_tracking_a_link_fires_link_clicked(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = new awareness(0, (object) [
            'title' => 'Policy update',
            'content' => '<p>Read <a href="https://example.com/policy">the policy</a>.</p>',
        ]);
        $notice->create();

        $link = noticelink::create_new_link((object) [
            'noticeid' => $notice->get('id'),
            'text' => 'the policy',
            'link' => 'https://example.com/policy',
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $sink = $this->redirectEvents();
        $result = helper::track_link((int) $link->get('id'));
        $events = $sink->get_events();
        $sink->close();

        // Precondition: the click was actually accepted, so the assertion is not vacuous.
        $this->assertTrue($result['status']);

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(awareness_link_clicked::class, $event);
        $this->assertSame('local_awareness_hlinks', $event->objecttable);
        $this->assertEquals($link->get('id'), $event->objectid);
        $this->assertEquals($notice->get('id'), $event->other['noticeid']);
    }

    /**
     * A refused click fires nothing — the event follows the row, not the request.
     */
    public function test_a_refused_link_click_fires_no_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $sink = $this->redirectEvents();
        $result = helper::track_link(-1);
        $events = $sink->get_events();
        $sink->close();

        // Precondition: the click really was refused, so "no event" means the guard held.
        $this->assertFalse($result['status']);
        $this->assertSame([], array_map(static fn($e): string => get_class($e), $events));
    }

    /**
     * The editor's Recalculate button fires awareness_audience_estimated.
     *
     * notice_audience::refresh() is the second job-creation site, and the one the manual
     * recalculation and every notice save go through. An earlier draft of this fix instrumented
     * only the web service, which would have logged the editor's debounced previews — which mostly
     * reuse a job and create nothing — while missing every deliberate recalculation.
     */
    public function test_recalculating_an_audience_fires_the_estimate_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $notice = $this->make_notice();

        $fired = $this->events_from(function () use ($notice) {
            notice_audience::refresh($notice, true);
        });

        $this->assertSame([awareness_audience_estimated::class], $fired);
    }

    /**
     * Every event class the plugin ships is reachable from a write path.
     *
     * A class nobody fires is a promise to an admin building an event-monitor rule. This walks
     * classes/event/ from disk rather than from a hand-kept list, so a new event class added
     * later without a firing site turns this red instead of shipping dead.
     *
     * The scan reads the WHOLE plugin source, not helper.php alone. It used to read that one file,
     * which is an inclusion list of size one: the day a trigger landed anywhere else — and
     * awareness_audience_estimated is triggered from persistent\audience_job — the test would have
     * reported a live event as dead, and the obvious repair would have been to add a second
     * filename rather than to notice the shape of the mistake. Exclusion list, scanned from the
     * plugin root, so a directory nobody thought of is covered by default.
     */
    public function test_no_event_class_is_unreachable(): void {
        global $CFG;

        $root = $CFG->dirroot . '/local/awareness';
        $skip = ['tests', 'lang', 'docs', 'amd', 'pix', '.git'];

        $sources = '';
        $scanned = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function ($current) use ($skip): bool {
                    return !($current->isDir() && in_array($current->getFilename(), $skip, true));
                }
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources .= file_get_contents($file->getPathname());
                $scanned++;
            }
        }

        $classes = glob($root . '/classes/event/*.php');

        $unfired = [];
        foreach ($classes as $path) {
            $class = basename($path, '.php');
            if (!str_contains($sources, $class . '::create')) {
                $unfired[] = $class;
            }
        }

        $this->assertSame([], $unfired, 'event classes with no firing site in the plugin source');

        /*
         * Non-vacuity on both halves. The class glob proves there was something to check, and the
         * file counter proves the sweep actually read the tree — an excluded-everything filter
         * would otherwise satisfy the assertion above by finding no source at all, which is the
         * failure mode the widening introduces.
         */
        $this->assertGreaterThan(0, count($classes));
        $this->assertGreaterThan(20, $scanned, 'the source sweep read implausibly few files');
    }
}
