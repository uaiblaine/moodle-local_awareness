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

use local_awareness\audience\live_mode;
use local_awareness\output\editor_page;

/**
 * Tests for the interactive-estimate decision and what the editor does with it.
 *
 * Coverage is declared in this docblock rather than with #[CoversClass]; moodle-cs on the 4.05 leg
 * cannot see attributes and reports every method as missing coverage information, which fails
 * phpcs under --max-warnings 0 while this plugin still supports 4.5.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\audience\live_mode
 */
final class audience_live_mode_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        live_mode::reset_cache();
    }

    /**
     * An unstored setting means the default, not zero.
     *
     * Reading a missing setting as 0 would switch interactive estimation off on every site whose
     * upgrade had not yet applied the default — the failure would look like the feature simply
     * being slow, which is the behaviour it was built to remove.
     */
    public function test_an_unstored_limit_reads_as_the_default(): void {
        unset_config('audience_sync_limit', 'local_awareness');
        $this->assertSame(live_mode::LIMIT_DEFAULT, live_mode::limit());

        // Only an explicit zero disables it.
        set_config('audience_sync_limit', 0, 'local_awareness');
        $this->assertSame(0, live_mode::limit());
        $this->assertFalse(live_mode::is_live());
    }

    /**
     * The decision follows the user count across the limit.
     */
    public function test_is_live_follows_the_user_count(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');
        $this->assertTrue(live_mode::is_live());

        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();
        $this->assertFalse(live_mode::is_live(), 'admin and guest already exceed a limit of one');
    }

    /**
     * The user count is cached, so a large site does not scan {user} on every estimate.
     *
     * Asserted through the cache rather than by counting queries: creating a user without clearing
     * the cache must not change the answer, and clearing it must.
     */
    public function test_the_user_count_is_cached(): void {
        set_config('audience_sync_limit', 100000, 'local_awareness');

        $before = \cache::make('local_awareness', 'site_user_count')->get('count');
        $this->assertFalse($before, 'nothing cached before the first call');

        live_mode::is_live();
        $cached = (int) \cache::make('local_awareness', 'site_user_count')->get('count');
        $this->assertGreaterThan(0, $cached);

        $this->getDataGenerator()->create_user();
        $this->assertSame(
            $cached,
            (int) \cache::make('local_awareness', 'site_user_count')->get('count'),
            'a new user does not invalidate the count'
        );

        live_mode::reset_cache();
        live_mode::is_live();
        $this->assertSame(
            $cached + 1,
            (int) \cache::make('local_awareness', 'site_user_count')->get('count')
        );
    }

    /**
     * A disabled limit costs no scan at all.
     *
     * The count is the expensive half on the very sites that fail the limit, so the short-circuit
     * has to come before it. Observed through the cache staying empty.
     */
    public function test_a_disabled_limit_never_counts_users(): void {
        set_config('audience_sync_limit', 0, 'local_awareness');

        $this->assertFalse(live_mode::is_live());
        $this->assertFalse(
            \cache::make('local_awareness', 'site_user_count')->get('count'),
            'the user count was never asked for'
        );
    }

    /**
     * The editor tells the browser not to estimate on its own when the site is over the limit.
     *
     * This is the value that stops an author on a large site from triggering a full user scan by
     * typing a title.
     */
    public function test_the_editor_switches_the_automatic_estimate_off_on_a_large_site(): void {
        global $PAGE;

        $page = new editor_page(null, '<form id="x"></form>', 'x', new \moodle_url('/'));
        // The page renderer, not the DI container, which does not exist on 4.5.
        $renderer = $PAGE->get_renderer('core');

        set_config('audience_sync_limit', 100000, 'local_awareness');
        live_mode::reset_cache();
        $small = $page->export_for_template($renderer);
        $this->assertTrue($small['audience']['autotrigger']);
        $this->assertSame(1, $small['audience']['auto']);

        set_config('audience_sync_limit', 1, 'local_awareness');
        live_mode::reset_cache();
        $large = $page->export_for_template($renderer);
        $this->assertFalse($large['audience']['autotrigger']);
        $this->assertSame(0, $large['audience']['auto']);
    }
}
