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
 * The reasons a published notice can never actually be displayed.
 *
 * Pure logic, so no database and \basic_testcase — and the instant is injected, so every boundary
 * can be pinned without moving the clock.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\editor_state
 */
final class editor_state_test extends \basic_testcase {
    /** A fixed instant to judge against; nothing here depends on the wall clock. */
    private const NOW = 1000000;

    /**
     * Every window shape, and what it means.
     *
     * @return array Case name => [enabled, timestart, timeend, expected problems].
     */
    public static function window_provider(): array {
        return [
            'perpetual displays' => [1, 0, 0, []],
            'open window displays' => [1, self::NOW - 10, self::NOW + 10, []],
            'starts in the future, still displays later' => [1, self::NOW + 10, self::NOW + 20, []],
            'expired' => [1, self::NOW - 20, self::NOW - 10, [editor_state::WINDOW_EXPIRED]],
            'expires exactly now' => [1, self::NOW - 20, self::NOW, [editor_state::WINDOW_EXPIRED]],
            'start after end' => [1, self::NOW + 20, self::NOW + 10, [editor_state::WINDOW_INVERTED]],
            'start equals end' => [1, self::NOW + 10, self::NOW + 10, [editor_state::WINDOW_INVERTED]],
            'start with no end displays' => [1, self::NOW - 10, 0, []],
            'start in the future with no end' => [1, self::NOW + 10, 0, []],
            'end with no start displays' => [1, 0, self::NOW + 10, []],
            'disabled is off, not broken' => [0, self::NOW - 20, self::NOW - 10, []],
        ];
    }

    /**
     * The predicate must agree with helper::is_within_active_window() about every shape.
     *
     * @dataProvider window_provider
     * @param int $enabled Whether the notice is published.
     * @param int $timestart Start of the window.
     * @param int $timeend End of the window.
     * @param array $expected The problems that shape carries.
     * @return void
     */
    public function test_window_problems(int $enabled, int $timestart, int $timeend, array $expected): void {
        $this->assertSame(
            $expected,
            editor_state::window_problems($enabled, $timestart, $timeend, self::NOW)
        );
    }

    /**
     * The cases that yield nothing must not all yield nothing for the same trivial reason.
     *
     * Without this the provider above would still pass if window_problems() returned [] for
     * everything — most of its rows expect exactly that.
     *
     * @return void
     */
    public function test_the_predicate_actually_discriminates(): void {
        $problems = [];
        foreach (self::window_provider() as [$enabled, $start, $end, $unusedexpected]) {
            $problems = array_merge($problems, editor_state::window_problems($enabled, $start, $end, self::NOW));
        }

        $this->assertSame(
            [
                editor_state::WINDOW_EXPIRED,
                editor_state::WINDOW_INVERTED,
            ],
            array_values(array_unique($problems))
        );
    }
}
