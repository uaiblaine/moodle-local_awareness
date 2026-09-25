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
 * Each case asserts the event class, not merely that some event fired: a verb firing another
 * verb's event (enable_notice() firing awareness_updated, say) passes a count, and leaves an
 * event-monitor rule on the right event that never matches.
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
     * Serve a notice to the current session through the real read path.
     *
     * helper::track_link() refuses a notice that select_for_display() never handed over, the only
     * record that the page-dependent rules ran. So this goes through the get_notices web service
     * rather than setting the session marker by hand, and asserts the marker appeared.
     *
     * @param awareness $notice The notice expected to be delivered.
     * @return void
     */
    private function deliver(awareness $notice): void {
        /*
         * The plugin's 'enabled' setting defaults to off, and get_notices returns no notice while
         * it is. That gate is tested in tests/external/notice_external_test.php.
         */
        set_config('enabled', 1, 'local_awareness');

        \local_awareness\external\get_notices::execute('/my/');

        $this->assertTrue(
            helper::was_notice_delivered($notice),
            'the read path did not serve this notice, so the write below would prove nothing'
        );
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
     * It also fires awareness_audience_estimated, because create_new_notice() ends in
     * notice_audience::refresh(), which creates an audience job. The ordered pair is asserted, so a
     * change that stops estimating on save fails here.
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
         * One event: update_notice() also ends in notice_audience::refresh(), but the criteria are
         * unchanged from make_notice(), so no audience job is created and no estimate event fires.
         */
        $this->assertSame([awareness_updated::class], $fired);
    }

    /**
     * Enabling fires awareness_enabled, not awareness_updated.
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
     * Disabling fires awareness_disabled, not awareness_updated.
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
     * Enable and disable fire different events from each other and from update.
     *
     * Pins the distinctness directly, independently of which class each per-verb test above expects.
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
     * Dismissing a notice that does not require acknowledgement still fires awareness_dismissed.
     *
     * For an Informational notice the event is the only record of each dismissal:
     * local_awareness_ack gets a dismissal row only from Blocking up, and local_awareness_lastview
     * keeps only the user's latest interaction.
     *
     * The reqack case below is the paired control: both must fire, and only the compliance row
     * differs between them.
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
     * Dismissing a notice that does require acknowledgement fires the same event.
     *
     * The control for the test above. It also pins that a repeated refusal fires the event again
     * while the compliance row is not duplicated ({@see \local_awareness\helper::dismiss_notice()}).
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
         * Control: the same notice dismissed by a real user does fire, so the empty list above is
         * not a missing trigger.
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

        $this->deliver($notice);

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
     * The manage list's Recalculate action fires awareness_audience_estimated.
     *
     * notice_audience::refresh() is the job-creation path behind the manual recalculation and every
     * notice save; the estimate_audience web service is the other. The event is fired on job
     * creation, from both.
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
     * An event class nobody fires is an event-monitor rule that can never match. The classes are
     * listed from classes/event/ on disk, and firing sites are searched for in the whole plugin
     * source minus an exclusion list (awareness_audience_estimated is fired from
     * persistent\audience_job, not helper.php), so a new class or a new directory is covered
     * without editing the test.
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
         * An empty class glob would pass the assertion above with nothing checked, so the class
         * count is asserted; the file count shows the sweep read the tree.
         */
        $this->assertGreaterThan(0, count($classes));
        $this->assertGreaterThan(20, $scanned, 'the source sweep read implausibly few files');
    }
}
