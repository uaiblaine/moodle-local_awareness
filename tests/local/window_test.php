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
 * BIZ-11 was two predicates that disagreed: the cached query in awareness::get_enabled_notices()
 * dropped any notice with only one bound set, while helper::is_within_active_window() had its own
 * opinion. Latent, because the editor writes both bounds together — but the plugin's own
 * editor_state carried a warning class invented to paper over one half of it.
 *
 * The load-bearing test here is test_the_prefilter_is_a_superset_of_the_decision. The prefilter is
 * deliberately LOOSER than the decision, and that asymmetry is the thing a future edit is most
 * likely to "tidy" into symmetry — which would be a real bug, because the query is cached with no
 * TTL and a notice whose start passes while the cache is warm would never appear at all.
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
     * This is the invariant the whole class exists for. The prefilter omits the LOWER bound on
     * purpose — awareness::get_enabled_notices() caches its result in a MODE_APPLICATION store with
     * no TTL, purged only when a notice is written, so a condition that turns from false to TRUE as
     * the clock moves would leave a scheduled notice permanently outside the cached set.
     *
     * Stated as an implication rather than an equality, because the two are deliberately NOT equal:
     * open implies prefiltered, never the reverse.
     *
     * @return void
     */
    public function test_the_prefilter_is_a_superset_of_the_decision(): void {
        $strictlylooser = 0;

        foreach (self::shape_provider() as $name => [$timestart, $timeend, $unusedexpected]) {
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
         * Non-vacuity, and the point of the whole test: the implication above is satisfied trivially
         * by a prefilter that matches everything AND by one identical to is_open(). This asserts the
         * prefilter is genuinely in between — it lets rows through that is_open() then rejects,
         * which is exactly the not-yet-started case the live clock has to catch.
         */
        $this->assertGreaterThan(0, $strictlylooser, 'the prefilter is not looser than the decision');
    }

    /**
     * has_started() and has_ended() compose into is_open() for every shape and instant.
     *
     * @return void
     */
    public function test_the_projections_compose(): void {
        foreach (self::shape_provider() as $name => [$timestart, $timeend, $unusedexpected]) {
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
     * Both SQL builders name each placeholder once per occurrence.
     *
     * fix_sql_params() counts placeholder OCCURRENCES against the parameter array and throws
     * duplicateparaminsql when a name appears more often than the array explains — which is why
     * open_sql() binds the same instant under two names rather than reusing one.
     *
     * @return void
     */
    public function test_the_sql_builders_bind_every_placeholder_exactly_once(): void {
        foreach ([window::open_prefilter_sql('a', self::NOW), window::open_sql('b', self::NOW)] as [$sql, $params]) {
            preg_match_all('/:([a-z0-9_]+)/', $sql, $found);

            $this->assertNotEmpty($found[1], 'the fragment names no placeholders at all');
            $this->assertSame(count($found[1]), count(array_unique($found[1])), 'a name appears twice');
            $this->assertSame(
                array_values(array_unique($found[1])),
                array_values(array_keys($params)),
                'the named placeholders and the bound parameters disagree'
            );
        }
    }

    /**
     * Distinct prefixes keep two fragments combinable in one statement.
     *
     * @return void
     */
    public function test_distinct_prefixes_do_not_collide(): void {
        [, $first] = window::open_sql('one', self::NOW);
        [, $second] = window::open_sql('two', self::NOW);

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
