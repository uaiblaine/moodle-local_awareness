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

/**
 * Every registered web service resolves to a class that can actually serve it.
 *
 * db/services.php is a table of strings. A classname with a typo, a function whose class was
 * renamed, or a methodname that no longer exists produces a service that installs cleanly, appears
 * in the admin list, and throws only when a client calls it — which on this plugin means in the
 * browser of whoever is editing a notice.
 *
 * The nine functions used to live in one 822-line class, which is what the fleet standard exists to
 * prevent: each is now its own file under classes/external/, and the standard's shape — execute(),
 * execute_parameters(), execute_returns() on a subclass of core_external\external_api — is what
 * these assertions pin.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external\dismiss_notice
 * @covers \local_awareness\external\acknowledge_notice
 * @covers \local_awareness\external\track_link
 * @covers \local_awareness\external\get_notices
 * @covers \local_awareness\external\check_collision
 * @covers \local_awareness\external\search_roles
 * @covers \local_awareness\external\search_courses
 * @covers \local_awareness\external\estimate_audience
 * @covers \local_awareness\external\get_estimate
 */
final class services_contract_test extends \basic_testcase {
    /**
     * The service definitions, read from db/services.php as core reads them.
     *
     * @return array The $functions array the file declares.
     */
    private function functions(): array {
        $functions = [];
        require(dirname(__DIR__, 2) . '/db/services.php');

        return $functions;
    }

    /**
     * Every declared function is a real, callable class with the three methods core will invoke.
     *
     * @return void
     */
    public function test_every_declared_service_resolves(): void {
        $functions = $this->functions();

        $this->assertGreaterThan(5, count($functions), 'implausibly few services declared — the read is broken');

        foreach ($functions as $name => $definition) {
            $class = $definition['classname'];
            $method = $definition['methodname'];

            $this->assertTrue(class_exists($class), "{$name} names a class that does not exist: {$class}");
            $this->assertTrue(
                is_subclass_of($class, \core_external\external_api::class),
                "{$name}: {$class} does not extend core_external\\external_api"
            );

            foreach ([$method, $method . '_parameters', $method . '_returns'] as $required) {
                $this->assertTrue(
                    method_exists($class, $required),
                    "{$name}: {$class} has no {$required}()"
                );
            }
        }
    }

    /**
     * One class per file, one function per class — no service shares a class with another.
     *
     * This is the whole point of the split. Two entries pointing at the same class is how the
     * monolith comes back one function at a time.
     *
     * @return void
     */
    public function test_no_two_services_share_a_class(): void {
        $classes = array_column($this->functions(), 'classname');

        $this->assertNotEmpty($classes, 'no service classnames read — the scan is broken');
        $this->assertSame(
            count($classes),
            count(array_unique($classes)),
            'two web services are declared against the same class'
        );
    }

    /**
     * Every class under classes/external/ is registered, and every registration has a file.
     *
     * Checked in both directions, because each catches a different mistake: a class nobody
     * registers is dead code that reads as live, and a registration with no class is a service that
     * throws in a user's browser.
     *
     * @return void
     */
    public function test_the_directory_and_the_registrations_agree(): void {
        $root = dirname(__DIR__, 2);

        $files = [];
        foreach (glob($root . '/classes/external/*.php') as $path) {
            $files[] = 'local_awareness\\external\\' . basename($path, '.php');
        }
        sort($files);

        $registered = array_values(array_unique(array_column($this->functions(), 'classname')));
        sort($registered);

        $this->assertNotEmpty($files, 'no classes found under classes/external/ — the scan is broken');
        $this->assertSame($files, $registered, 'the directory and db/services.php disagree');

        // And the monolith is gone, not merely unused.
        $this->assertFileDoesNotExist($root . '/classes/external.php', 'the monolithic external class is back');
    }
}
