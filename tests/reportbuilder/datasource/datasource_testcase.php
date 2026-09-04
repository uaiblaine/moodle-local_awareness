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

declare(strict_types=1);

namespace local_awareness\reportbuilder\datasource;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("{$CFG->dirroot}/reportbuilder/tests/helpers.php");

use core_reportbuilder\datasource;
use core_reportbuilder\manager;
use core_reportbuilder\local\helpers\aggregation;
use core_reportbuilder\local\models\column;
use core_reportbuilder\local\models\filter as filter_model;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\tests\core_reportbuilder_testcase;

/**
 * Base class for this plugin's datasource tests: every content fetch is made independent of the clock.
 *
 * Core's datasource_stress_test_columns_aggregation() asserts that ONE fetch emits exactly one
 * debugging() call per deprecated column, and this plugin carries one (notice:forcelogout). The
 * call is emitted whenever core_reportbuilder\datasource::get_active_columns() rebuilds its memo,
 * and the memo is reused only while its build time is later than the moment the report's elements
 * were last modified — two microtime(true) readings, the second taken milliseconds after the first.
 * A fetch reaches that method four times (the table constructor, twice inside get_sql_sort(), and
 * format_row()), so whenever the second reading is not strictly later than the first — the clock
 * stepping backwards, or an equal read under contention — the memo misses on every call and the
 * assertion sees four. That is what failed once on a local matrix run inside the
 * Docker Desktop VM: the leg's log shows the four identical messages, one per site. The VM's wall
 * clock, watched against its monotonic clock while CI legs ran, stepped up to 4.4 ms backwards
 * seventeen times in twenty-five minutes; on the dev stack the first rebuild follows the update by
 * under a millisecond and the last one by a few, so a step of that size covers the window. It was
 * reproduced by forcing the memo to miss, and it cannot be fixed from inside a test any other way,
 * because the readings are core's and the clock is the environment's. Core's column stress helper,
 * which asserts exactly one debugging() for a deprecated column, is covered by the same override.
 *
 * So the fetch is bracketed: the report instance the table builds is fresh, and the "last modified"
 * stamp is set to a time no clock can precede, so the memo built during the fetch is valid for the
 * rest of it whatever microtime(true) returns. Core's own assertion stays exactly as it is. The
 * stamp would also validate a STALE memo, which is why the instance cache is reset first and why
 * the fetch is followed by a control: the columns and conditions the fetch used must match the
 * database rows, so a rebuild that failed to happen fails the test instead of silently running the
 * previous iteration's report.
 *
 * The stamp is left at -1 afterwards, on purpose: every write to the report's elements overwrites it
 * with the current time and a fresh instance has no memo, so the leftover can never validate a stale
 * memo — while putting the previous stamp back would reopen the clock window for the memo reads the
 * helpers make between two fetches. This file is not autoloadable (its namespace maps to classes/,
 * where it does not live), so each test loads it with a require_once of its own; that line is
 * load-bearing.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class datasource_testcase extends core_reportbuilder_testcase {
    /**
     * Fetch report content, with the active-elements memo made independent of the clock.
     *
     * @param int $reportid
     * @param int $pagesize
     * @param array $filtervalues
     * @return array
     */
    protected function get_custom_report_content(int $reportid, int $pagesize = 30, array $filtervalues = []): array {
        self::detach_memo_from_clock($reportid);

        $content = parent::get_custom_report_content($reportid, $pagesize, $filtervalues);

        $this->assert_fetch_used_current_elements($reportid);

        return $content;
    }

    /**
     * Make the next fetch build the report's elements once, and then reuse them whatever the clock does.
     *
     * The instance cache is reset so the table builds a fresh datasource whose memos are empty, and the
     * report's "elements modified" stamp is set below any possible build time. Both are needed: the
     * stamp alone would validate a memo built before the helper's last change to the report.
     *
     * @param int $reportid
     */
    private static function detach_memo_from_clock(int $reportid): void {
        manager::reset_caches();

        if (!property_exists(datasource::class, 'elementsmodified')) {
            self::fail('core_reportbuilder\\datasource no longer keeps its "elements modified" stamp in $elementsmodified; ' .
                'this testcase pins that stamp so the stress helpers do not depend on the clock, and needs updating');
        }

        // The stamp is private to core's datasource; a closure bound to that scope can reach it.
        $stamp = \Closure::bind(static function (int $reportid): void {
            self::$elementsmodified[$reportid] = -1;
        }, null, datasource::class);
        $stamp($reportid);
    }

    /**
     * The columns and conditions the fetch used must be the ones in the database.
     *
     * The stamp set above would also validate a memo that was never rebuilt, so this proves the rebuild
     * happened: the active columns are exactly the stored, available ones, each carrying the aggregation
     * the row stores, and the active conditions are exactly the stored ones. The aggregation compared is
     * the one the column instance APPLIED when the memo was built, not the value on its persistent: the
     * memo clones columns shallowly, so the persistent is shared with the helper that updates it in
     * place, and a stale memo looks current through it. Both reads are memo hits, so they emit no
     * debugging of their own and leave core's count untouched.
     *
     * @param int $reportid
     */
    private function assert_fetch_used_current_elements(int $reportid): void {
        $report = manager::get_report_from_id($reportid);

        $stored = [];
        foreach (column::get_records(['reportid' => $reportid]) as $record) {
            $instance = $report->get_column($record->get('uniqueidentifier'));
            if ($instance === null || !$instance->get_is_available()) {
                continue;
            }
            $name = $record->get('aggregation');
            $stored[(int) $record->get('id')] = $name ? ltrim(aggregation::get_full_classpath($name), '\\') : null;
        }
        $active = [];
        foreach ($report->get_active_columns() as $activecolumn) {
            // A class name on 4.5, an instance from 5.0.
            $applied = $activecolumn->get_aggregation();
            if (is_object($applied)) {
                $applied = get_class($applied);
            }
            $active[(int) $activecolumn->get_persistent()->get('id')] = $applied === null ? null : ltrim($applied, '\\');
        }
        ksort($stored);
        ksort($active);
        $this->assertSame($stored, $active, 'the fetch used columns or aggregations that differ from the stored ones');

        // Conditions are filter rows flagged as such; there is no model of their own. The table builds
        // the memo without the availability check, and the memo is not keyed on that flag, so this
        // reads it the same way and compares against every stored row.
        $storedconditions = array_map(
            static function (filter_model $record): string {
                return $record->get('uniqueidentifier');
            },
            array_values(filter_model::get_records(['reportid' => $reportid, 'iscondition' => 1]))
        );
        $activeconditions = array_map(
            static function (filter $active): string {
                return $active->get_unique_identifier();
            },
            array_values($report->get_active_conditions(false))
        );
        sort($storedconditions);
        sort($activeconditions);
        $this->assertSame($storedconditions, $activeconditions, 'the fetch used conditions that differ from the stored ones');
    }
}
