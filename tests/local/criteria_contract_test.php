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
 * The estimator reads a criteria array; audience_criteria.js builds it, and no other gate compares
 * the two. A key the client omits is not an error: it normalises to its default, so, for example,
 * a role rule without filter_role_context is estimated as if it applied in every context while
 * the displayed notice honours the stored context.
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
     * Taken from normalise() itself rather than from AUDIENCE_FIELDS/CONTEXT_FIELDS: modifiers such
     * as filter_role_context, kept beside filter_role, appear in neither constant.
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
     * The reverse direction of the same drift: normalise() drops a key it does not read before the
     * estimate and its hash are computed, so whatever the author set through that field has no
     * effect on the count.
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
