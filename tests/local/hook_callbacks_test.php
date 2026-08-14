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

namespace local_awareness\local;

use local_awareness\helper;

/**
 * Tests for the footer-hook loading decision.
 *
 * Cases are looped rather than fed through a data provider: Moodle 4.5 vendors PHPUnit 9.6, which
 * predates attribute metadata, and a docblock provider would run the method with no arguments.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Log a user in, enable the plugin, and seed one enabled notice reaching every page.
     */
    private function prepare_loadable_site(): void {
        set_config('enabled', 1, 'local_awareness');

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Everywhere';
        $data->content = '<p>body</p>';
        helper::create_new_notice($data);

        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Build a page with the given layout and a settled URL.
     *
     * @param string $layout The page layout.
     * @return \moodle_page
     */
    private function make_page(string $layout): \moodle_page {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagelayout($layout);
        $page->set_url('/my/index.php');
        return $page;
    }

    /**
     * The denylisted layouts never load the module; a standard page with the same state does.
     */
    public function test_denied_layouts_never_load(): void {
        $this->resetAfterTest();
        $this->prepare_loadable_site();

        /*
         * The control first: the same user, notice and URL load on a standard page, so every
         * refusal below is the layout's doing and not a dead fixture.
         */
        $this->assertTrue(hook_callbacks::should_load_on($this->make_page('standard')));

        /*
         * The list is spelled out rather than read from the constant on purpose: iterating the
         * constant would silently drop a layout from the test the moment it was dropped from the
         * code, which is the exact regression this test exists to catch.
         */
        foreach (['maintenance', 'print', 'redirect', 'embedded', 'popup', 'secure'] as $layout) {
            $this->assertFalse(
                hook_callbacks::should_load_on($this->make_page($layout)),
                "Layout '{$layout}' must not load the notice module"
            );
        }
    }

    /**
     * A disabled plugin, or nobody logged in, loads nothing.
     */
    public function test_disabled_plugin_or_anonymous_never_loads(): void {
        $this->resetAfterTest();
        $this->prepare_loadable_site();

        set_config('enabled', 0, 'local_awareness');
        $this->assertFalse(hook_callbacks::should_load_on($this->make_page('standard')));

        set_config('enabled', 1, 'local_awareness');
        $this->setUser(null);
        $this->assertFalse(hook_callbacks::should_load_on($this->make_page('standard')));
    }

    /**
     * The page rules decide: a path-restricted notice loads its module only where it could show.
     */
    public function test_page_rules_decide_loading(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_awareness');

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Dashboard only';
        $data->content = '<p>body</p>';
        $data->pathmatch = '/my/%';
        helper::create_new_notice($data);

        $this->setUser($this->getDataGenerator()->create_user());

        $dashboard = new \moodle_page();
        $dashboard->set_context(\context_system::instance());
        $dashboard->set_pagelayout('mydashboard');
        $dashboard->set_url('/my/index.php');
        $this->assertTrue(hook_callbacks::should_load_on($dashboard));

        $course = new \moodle_page();
        $course->set_context(\context_system::instance());
        $course->set_pagelayout('course');
        $course->set_url('/course/view.php', ['id' => 2]);
        $this->assertFalse(hook_callbacks::should_load_on($course));
    }

    /**
     * Guests keep receiving notices: they pass isloggedin(), and their delivery is a contract.
     *
     * The guest interaction handling (session-only markers, one guest's dismissal not hiding the
     * notice from the next) only ever runs if the module loads for guests in the first place.
     * tool_usertours — the design's model — excludes guests; copying that guard here would
     * silently end guest delivery, which is exactly what this pins against.
     */
    public function test_guests_still_load_the_module(): void {
        $this->resetAfterTest();
        $this->prepare_loadable_site();

        $this->setGuestUser();

        $this->assertTrue(hook_callbacks::should_load_on($this->make_page('standard')));
    }

    /**
     * The hook callback itself queues the module into the page it judged.
     *
     * should_load_on() is covered above; this pins the injection line — the queued AMD call must
     * surface in the page's own footer code, and must not for a denylisted layout.
     */
    public function test_hook_injects_the_module(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->prepare_loadable_site();

        $PAGE = $this->make_page('standard');
        hook_callbacks::before_footer_html_generation(
            new \core\hook\output\before_footer_html_generation($PAGE->get_renderer('core'))
        );
        $this->assertStringContainsString("require(['local_awareness/notice']", $PAGE->requires->get_end_code());

        $PAGE = $this->make_page('secure');
        hook_callbacks::before_footer_html_generation(
            new \core\hook\output\before_footer_html_generation($PAGE->get_renderer('core'))
        );
        $this->assertStringNotContainsString("require(['local_awareness/notice']", $PAGE->requires->get_end_code());
    }

    /**
     * A page whose URL cannot be judged still loads the module: uncertainty fails open.
     */
    public function test_uncertain_page_still_loads(): void {
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_awareness');

        $this->setAdminUser();
        $data = new \stdClass();
        $data->title = 'Dashboard only';
        $data->content = '<p>body</p>';
        $data->pathmatch = '/my/%';
        helper::create_new_notice($data);

        $this->setUser($this->getDataGenerator()->create_user());

        // No set_url(), and $FULLME is null under PHPUnit: the probe knows nothing of the page.
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagelayout('standard');

        $this->assertTrue(hook_callbacks::should_load_on($page));
        $this->assertDebuggingNotCalled();
    }
}
