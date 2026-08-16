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

/**
 * Guards the audience-criteria contract between the editor's JavaScript and the estimator.
 *
 * The estimator reads a criteria array; audience_criteria.js builds it. Nothing made the two lists
 * agree, and they drifted: the client never sent filter_role_context, so a role rule scoped to one
 * course was estimated as if it applied everywhere, and where the role was the site's default the
 * estimator took its "1 = 1" shortcut and reported the entire user base. The runtime honoured the
 * stored context the whole time, so the panel and the notice disagreed by orders of magnitude.
 *
 * That was audit finding M3, written down in August and still true in the code six releases later.
 * Nothing in the pipeline could see it: the field is absent, not wrong, and no PHP gate reads a
 * JavaScript file. This test is that missing observer — the same reason bootstrap_compat_test
 * exists beside it.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\audience\estimator
 */
final class criteria_contract_test extends \basic_testcase {
    /**
     * Absolute path to the plugin root.
     *
     * @return string Plugin directory without a trailing separator.
     */
    private function plugin_root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Every criteria key estimator::normalise() reads out of the raw array.
     *
     * Taken from normalise() itself rather than from AUDIENCE_FIELDS/CONTEXT_FIELDS, because those
     * constants are not the whole story: filter_role_context is a modifier kept alongside
     * filter_role and appears in neither. Reading the method is what makes this list complete, and
     * completeness is the entire point — the field that broke was the one nobody had listed.
     *
     * @return array Sorted list of key names.
     */
    private function server_keys(): array {
        $source = file_get_contents($this->plugin_root() . '/classes/audience/estimator.php');
        $start = strpos($source, 'public static function normalise');
        $this->assertNotFalse($start, 'estimator::normalise() has been renamed — this scan is broken, not the code.');

        /* The method body ends at the first closing brace in column 4, as Moodle style guarantees. */
        $end = strpos($source, "\n    }", $start);
        $body = substr($source, $start, $end - $start);

        preg_match_all('/\$raw\[\'([a-z_]+)\'\]/', $body, $matches);
        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    /**
     * Every criteria key audience_criteria.js writes.
     *
     * @return array Sorted list of key names.
     */
    private function client_keys(): array {
        $source = file_get_contents($this->plugin_root() . '/amd/src/audience_criteria.js');

        preg_match_all('/criteria\.([a-z_]+)\s*=/', $source, $matches);
        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        return $keys;
    }

    /**
     * The editor must send every criteria field the estimator reads.
     *
     * A field the client omits does not fail: it normalises to a default and the estimate silently
     * answers a different question from the one the author asked.
     *
     * @return void
     */
    public function test_the_editor_sends_every_field_the_estimator_reads(): void {
        $server = $this->server_keys();
        $client = $this->client_keys();

        /* A scan that matched nothing would assert nothing and stay green for ever. */
        $this->assertGreaterThan(5, count($server), 'Found almost no criteria keys in normalise() — the scan is broken.');
        $this->assertGreaterThan(5, count($client), 'Found almost no criteria keys in the JS — the scan is broken.');

        $missing = array_values(array_diff($server, $client));

        $this->assertSame(
            [],
            $missing,
            'estimator::normalise() reads these criteria fields and audience_criteria.js never sends them, so '
                . 'the estimate answers a different question from the one the editor asked: ' . implode(', ', $missing)
        );
    }

    /**
     * And it must not invent fields the estimator will drop on the floor.
     *
     * The reverse direction is the cheaper half of the same drift: a key the client sends and the
     * server never reads is work the author sees no result from, and it changes the criteria hash
     * that keys job reuse, so two identical estimates stop sharing a job.
     *
     * @return void
     */
    public function test_the_editor_sends_nothing_the_estimator_ignores(): void {
        $extra = array_values(array_diff($this->client_keys(), $this->server_keys()));

        $this->assertSame(
            [],
            $extra,
            'audience_criteria.js sends these fields and estimator::normalise() reads none of them: '
                . implode(', ', $extra)
        );
    }
}
