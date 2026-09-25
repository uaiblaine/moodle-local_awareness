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
 * Tests the scheduling-window truth table and, above all, the relation between its projections.
 *
 * The load-bearing test is test_the_prefilter_is_a_superset_of_the_decision. The prefilter is
 * deliberately looser than the decision, for the reason given in {@see window}: made symmetric, it
 * would keep a notice whose start passes while the enabled-notices cache is warm from ever
 * appearing. tests/persistent/enabled_notices_window_test.php runs the same invariant against the
 * real query.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\window
 */
final class window_test extends \basic_testcase {
    /** An arbitrary fixed instant, so nothing here depends on the wall clock. */
    private const NOW = 1800000000;

    /**
     * Every shape of window, against the documented truth table.
     *
     * @return array
     */
    public static function shape_provider(): array {
        return [
            'both unbounded' => [0, 0, true],
            'end only, before it' => [0, self::NOW + 10, true],
            'end only, exactly at it' => [0, self::NOW, false],
            'end only, after it' => [0, self::NOW - 10, false],
            'start only, before it' => [self::NOW + 10, 0, false],
            'start only, exactly at it' => [self::NOW, 0, true],
            'start only, after it' => [self::NOW - 10, 0, true],
            'both, inside' => [self::NOW - 10, self::NOW + 10, true],
            'both, exactly at start' => [self::NOW, self::NOW + 10, true],
            'both, exactly at end' => [self::NOW - 10, self::NOW, false],
            'both, before' => [self::NOW + 10, self::NOW + 20, false],
            'both, after' => [self::NOW - 20, self::NOW - 10, false],
            'inverted' => [self::NOW + 10, self::NOW - 10, false],
        ];
    }

    /**
     * is_open() answers the truth table, half-open at the upper bound.
     *
     * @dataProvider shape_provider
     * @param int $timestart Start of the window.
     * @param int $timeend End of the window.
     * @param bool $expected Whether the window is open at NOW.
     * @return void
     */
    public function test_is_open(int $timestart, int $timeend, bool $expected): void {
        $this->assertSame($expected, window::is_open($timestart, $timeend, self::NOW));
    }

    /**
     * The cached prefilter never hides a notice the display decision would show.
     *
     * The prefilter omits the lower bound on purpose ({@see window}), so this is stated as an
     * implication rather than an equality: open implies prefiltered, never the reverse.
     *
     * @return void
     */
    public function test_the_prefilter_is_a_superset_of_the_decision(): void {
        $strictlylooser = 0;

        foreach (self::shape_provider() as $name => [$timestart, $timeend]) {
            foreach ([self::NOW - 15, self::NOW, self::NOW + 15] as $now) {
                $open = window::is_open($timestart, $timeend, $now);
                $prefiltered = self::prefilter_matches($timeend, $now);

                if ($open) {
                    $this->assertTrue(
                        $prefiltered,
                        "shape '{$name}' is open but the cached prefilter would drop it"
                    );
                }
                if (!$open && $prefiltered) {
                    $strictlylooser++;
                }
            }
        }

        /*
         * Non-vacuity: the implication above also holds for a prefilter identical to is_open().
         * This asserts the prefilter lets through rows is_open() then rejects, the not-yet-started
         * case the live clock has to catch.
         */
        $this->assertGreaterThan(0, $strictlylooser, 'the prefilter is not looser than the decision');
    }

    /**
     * has_started() and has_ended() compose into is_open() for every shape and instant.
     *
     * @return void
     */
    public function test_the_projections_compose(): void {
        foreach (self::shape_provider() as $name => [$timestart, $timeend]) {
            foreach ([self::NOW - 15, self::NOW, self::NOW + 15] as $now) {
                $this->assertSame(
                    window::has_started($timestart, $now) && !window::has_ended($timeend, $now),
                    window::is_open($timestart, $timeend, $now),
                    "shape '{$name}' does not compose at {$now}"
                );
            }
        }
    }

    /**
     * The SQL builder names each placeholder once and binds exactly the names it uses.
     *
     * @return void
     */
    public function test_the_sql_builder_binds_every_placeholder_exactly_once(): void {
        [$sql, $params] = window::open_prefilter_sql('a', self::NOW);
        preg_match_all('/:([a-z0-9_]+)/', $sql, $found);

        $this->assertNotEmpty($found[1], 'the fragment names no placeholders at all');
        $this->assertSame(count($found[1]), count(array_unique($found[1])), 'a name appears twice');
        $this->assertSame(
            array_values(array_unique($found[1])),
            array_values(array_keys($params)),
            'the named placeholders and the bound parameters disagree'
        );
    }

    /**
     * Distinct prefixes keep two fragments combinable in one statement.
     *
     * @return void
     */
    public function test_distinct_prefixes_do_not_collide(): void {
        [, $first] = window::open_prefilter_sql('one', self::NOW);
        [, $second] = window::open_prefilter_sql('two', self::NOW);

        $this->assertSame([], array_intersect_key($first, $second));
    }

    /**
     * Evaluate the prefilter fragment's meaning in PHP: timeend unbounded, or still in the future.
     *
     * @param int $timeend End of the window, 0 for unbounded.
     * @param int $now The instant to judge against.
     * @return bool
     */
    private static function prefilter_matches(int $timeend, int $now): bool {
        return $timeend === 0 || $timeend > $now;
    }
}
