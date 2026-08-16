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

namespace local_awareness\table;

use core_table\local\filter\filter;
use core_table\local\filter\string_filter;
use local_awareness\persistent\awareness;

/**
 * Filtering and paging the notice list.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\table\all_notices
 * @covers \local_awareness\table\all_notices_filterset
 */
final class all_notices_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create a notice.
     *
     * @param array $fields Field overrides on top of the minimum a notice needs.
     * @return awareness
     */
    private function notice(array $fields): awareness {
        $notice = new awareness(0, (object) ($fields + [
            'title' => 'Notice',
            'content' => '<p>Body</p>',
            'enabled' => 1,
        ]));
        $notice->create();

        return $notice;
    }

    /**
     * Build the table, apply a filterset and return the titles it would render, in order.
     *
     * Goes through query_db() rather than out(), so the assertions are about the rows the SQL
     * selected and not about the HTML wrapped around them.
     *
     * @param array $filters Filter name => value.
     * @param int $page Page number, zero based.
     * @param int $perpage Rows per page.
     * @return array [titles on this page, total rows matching the filters]
     */
    private function query(array $filters, int $page = 0, int $perpage = all_notices::PER_PAGE): array {
        $table = new all_notices('test', new \moodle_url('/local/awareness/managenotice.php'), $page, $perpage);

        $filterset = new all_notices_filterset();
        foreach ($filters as $name => $value) {
            $filterset->add_filter(new string_filter($name, filter::JOINTYPE_ANY, [$value]));
        }
        $table->set_filterset($filterset);

        $table->query_db($perpage, false);

        /*
         * rawdata is null, not [], until a row is put in it — the same detail query_db() guards
         * when it hands the page to the collision resolver, and the state a site with no notices
         * is in.
         */
        $titles = array_map(static function (awareness $notice): string {
            return $notice->get('title');
        }, $table->rawdata ?? []);

        return [array_values($titles), $table->get_total_rows()];
    }

    /**
     * With no filters every notice is listed.
     */
    public function test_no_filters_lists_everything(): void {
        $this->notice(['title' => 'Alpha']);
        $this->notice(['title' => 'Beta', 'enabled' => 0]);

        [$titles, $total] = $this->query([]);

        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $titles);
        $this->assertSame(2, $total);
    }

    /**
     * The name filter ignores case and accents.
     *
     * On PostgreSQL that needs the unaccent extension, which the plugin provisions at install and
     * upgrade time; where it is unavailable the accent-insensitive half cannot work, so only the
     * case-insensitive half is asserted there. The exact-accent search is asserted everywhere,
     * because that must hold on every database.
     */
    public function test_name_filter_is_case_and_accent_insensitive(): void {
        $this->notice(['title' => 'Manutenção programada']);
        $this->notice(['title' => 'Nova política']);

        [$titles] = $this->query(['name' => 'manutenção']);
        $this->assertSame(['Manutenção programada'], $titles, 'Search must ignore case.');

        [$titles] = $this->query(['name' => 'POLÍTICA']);
        $this->assertSame(['Nova política'], $titles, 'Search must ignore case in both directions.');

        if (\local_awareness\helper::has_unaccent() || $this->db_folds_accents()) {
            [$titles] = $this->query(['name' => 'manutencao']);
            $this->assertSame(['Manutenção programada'], $titles, 'Search must ignore accents.');
        }
    }

    /**
     * A search matching nothing returns nothing, rather than falling back to everything.
     */
    public function test_name_filter_can_match_nothing(): void {
        $this->notice(['title' => 'Alpha']);

        [$titles, $total] = $this->query(['name' => 'zzz']);

        $this->assertSame([], $titles);
        $this->assertSame(0, $total);
    }

    /**
     * The status filter separates published notices from drafts.
     */
    public function test_status_filter_splits_live_from_draft(): void {
        $this->notice(['title' => 'Live', 'enabled' => 1]);
        $this->notice(['title' => 'Draft', 'enabled' => 0]);

        [$titles] = $this->query(['status' => all_notices_filterset::STATUS_LIVE]);
        $this->assertSame(['Live'], $titles);

        [$titles] = $this->query(['status' => all_notices_filterset::STATUS_DRAFT]);
        $this->assertSame(['Draft'], $titles);
    }

    /**
     * The competing filter narrows to notices that actually clash, and spares the rest.
     *
     * The two repeating notices reach the same pages; the third repeats but is aimed elsewhere,
     * and the fourth shares the pages but does not repeat, so neither competes. Without those two
     * controls the assertion would pass on a filter that simply returned every repeating notice.
     */
    public function test_status_filter_finds_competing_notices(): void {
        $this->notice(['title' => 'Rival A', 'pathmatch' => '/course/view.php', 'resetinterval' => 86400]);
        $this->notice(['title' => 'Rival B', 'pathmatch' => '/course/view.php', 'resetinterval' => 3600]);
        $this->notice(['title' => 'Elsewhere', 'pathmatch' => '/mod/quiz/view.php', 'resetinterval' => 86400]);
        $this->notice(['title' => 'Once only', 'pathmatch' => '/course/view.php', 'resetinterval' => 0]);

        [$titles, $total] = $this->query(['status' => all_notices_filterset::STATUS_CLASH]);

        $this->assertEqualsCanonicalizing(['Rival A', 'Rival B'], $titles);
        $this->assertSame(2, $total);
    }

    /**
     * With nothing competing, the filter returns nothing rather than everything.
     *
     * The empty case is its own branch — an empty IN () is not portable, so it is written as a
     * false predicate — and getting it wrong would show the whole table under a filter that means
     * the opposite.
     */
    public function test_competing_filter_returns_nothing_when_nothing_competes(): void {
        $this->notice(['title' => 'Alone', 'pathmatch' => '/course/view.php', 'resetinterval' => 86400]);

        [$titles, $total] = $this->query(['status' => all_notices_filterset::STATUS_CLASH]);

        $this->assertSame([], $titles);
        $this->assertSame(0, $total);
    }

    /**
     * Validity is derived from the window, and each value selects only its own notices.
     */
    public function test_validity_filter_reads_the_window(): void {
        $now = time();
        $this->notice(['title' => 'Permanent']);
        $this->notice(['title' => 'Current', 'timestart' => $now - DAYSECS, 'timeend' => $now + DAYSECS]);
        $this->notice(['title' => 'Scheduled', 'timestart' => $now + DAYSECS, 'timeend' => $now + WEEKSECS]);
        $this->notice(['title' => 'Expired', 'timestart' => $now - WEEKSECS, 'timeend' => $now - DAYSECS]);

        $expected = [
            all_notices_filterset::VALIDITY_PERMANENT => 'Permanent',
            all_notices_filterset::VALIDITY_CURRENT => 'Current',
            all_notices_filterset::VALIDITY_SCHEDULED => 'Scheduled',
            all_notices_filterset::VALIDITY_EXPIRED => 'Expired',
        ];
        foreach ($expected as $validity => $title) {
            [$titles, $total] = $this->query(['validity' => $validity]);
            $this->assertSame([$title], $titles, "Filter '$validity' selected the wrong notices.");
            $this->assertSame(1, $total, "Filter '$validity' counted the wrong total.");
        }
    }

    /**
     * Filters combine, rather than the last one winning.
     */
    public function test_filters_combine(): void {
        $now = time();
        $this->notice(['title' => 'Wanted', 'enabled' => 1, 'timestart' => $now + DAYSECS]);
        $this->notice(['title' => 'Wrong status', 'enabled' => 0, 'timestart' => $now + DAYSECS]);
        $this->notice(['title' => 'Wrong window', 'enabled' => 1]);

        [$titles, $total] = $this->query([
            'status' => all_notices_filterset::STATUS_LIVE,
            'validity' => all_notices_filterset::VALIDITY_SCHEDULED,
        ]);

        $this->assertSame(['Wanted'], $titles);
        $this->assertSame(1, $total);
    }

    /**
     * Paging counts the FILTERED set, and a page holds no more than its size.
     *
     * This is the assertion the whole SQL rewrite exists for. Narrowing the rows in PHP after the
     * query would leave the total describing the unfiltered table, so the pager would offer pages
     * that render fewer rows than they promise — or none at all.
     */
    public function test_paging_counts_the_filtered_set(): void {
        for ($i = 1; $i <= 7; $i++) {
            $this->notice(['title' => sprintf('Keep %02d', $i), 'enabled' => 1]);
        }
        for ($i = 1; $i <= 9; $i++) {
            $this->notice(['title' => sprintf('Drop %02d', $i), 'enabled' => 0]);
        }

        [$first, $total] = $this->query(['status' => all_notices_filterset::STATUS_LIVE], 0, 3);
        $this->assertCount(3, $first);
        $this->assertSame(7, $total, 'The total must describe the filtered set, not the whole table.');

        [$last] = $this->query(['status' => all_notices_filterset::STATUS_LIVE], 2, 3);
        $this->assertCount(1, $last, 'The final page holds the remainder of the filtered set.');

        // Every row on every page is one the filter kept.
        [$second] = $this->query(['status' => all_notices_filterset::STATUS_LIVE], 1, 3);
        foreach (array_merge($first, $second, $last) as $title) {
            $this->assertStringStartsWith('Keep', $title);
        }
    }

    /**
     * The table declares the contract the dynamic-table web service relies on.
     *
     * The service constructs the class with the unique id ALONE and then calls these, so a
     * constructor that demanded a URL would fail only over AJAX — never on a page load, and never
     * in a test that built the table the way the page does.
     */
    public function test_dynamic_table_contract(): void {
        $this->setAdminUser();

        $this->assertTrue(is_subclass_of(all_notices::class, \core_table\dynamic::class));
        $this->assertSame(all_notices_filterset::class, all_notices::get_filterset_class());

        $table = new all_notices('test');

        $this->assertInstanceOf(\context_system::class, $table->get_context());
        $this->assertTrue($table->has_capability());

        $table->guess_base_url();
        $this->assertStringContainsString('/local/awareness/managenotice.php', $table->baseurl->out(false));
    }

    /**
     * A user without the manage capability is refused by the table itself.
     */
    public function test_has_capability_refuses_a_plain_user(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $table = new all_notices('test');

        $this->assertFalse($table->has_capability());
    }

    /**
     * Whether this database folds accents in a LIKE of its own accord.
     *
     * MySQL and MariaDB do it through the collation, which is why sql_like_ai() only reaches for
     * unaccent() on PostgreSQL.
     *
     * @return bool
     */
    private function db_folds_accents(): bool {
        global $DB;

        return in_array($DB->get_dbfamily(), ['mysql', 'mariadb'], true);
    }

    /**
     * One page of rows resolves the cohort option list once, not once per cohort reference.
     *
     * built_cohorts_options() wraps cohort_get_all_cohorts(0, 0) — a COUNT plus an unbounded scan
     * of {cohort} joined to {context}, plus a capability walk — and it was paid per cohort id per
     * row, so the page cost scaled with the size of the site rather than with what is on screen.
     * The list is a dynamic table, so the filter bar re-paid it on every keystroke.
     *
     * @covers \local_awareness\table\all_notices::cohort_line
     */
    public function test_cohort_names_are_resolved_once_per_page_of_rows(): void {
        global $DB;

        $this->setAdminUser();

        $one = $this->getDataGenerator()->create_cohort(['name' => 'Alpha cohort']);
        $two = $this->getDataGenerator()->create_cohort(['name' => 'Beta cohort']);

        for ($i = 0; $i < 10; $i++) {
            $this->notice(['title' => 'Notice ' . $i, 'cohorts' => $one->id . ',' . $two->id]);
        }

        $table = new all_notices('probe', new \moodle_url('/local/awareness/managenotice.php'));
        $table->set_filterset(new all_notices_filterset());
        $table->query_db(all_notices::PER_PAGE, false);

        $render = new \ReflectionMethod(all_notices::class, 'cohort_line');
        $render->setAccessible(true);

        $lines = [];
        $before = $DB->perf_get_reads();
        foreach ($table->rawdata as $notice) {
            $lines[] = $render->invoke($table, $notice);
        }
        $reads = $DB->perf_get_reads() - $before;

        /*
         * Control. A flat read count means nothing unless the work that would have inflated it
         * actually ran: every row has to have rendered both cohort NAMES, which only happens if the
         * option list was consulted for all twenty references.
         */
        $this->assertCount(10, $lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('Alpha cohort', $line);
            $this->assertStringContainsString('Beta cohort', $line);
        }

        // Twenty cohort references, one scan of {cohort}: a COUNT and a SELECT, with headroom.
        $this->assertLessThanOrEqual(4, $reads);
    }

    /**
     * The audience column resolves the in-flight jobs once for the page, not once per row.
     *
     * col_audience()'s own comment claimed it avoided a per-row query, and then called
     * notice_audience::state_of(), which runs audience_job::find_in_flight() whenever the stored
     * hash is missing — which is every notice that predates the audience upgrade.
     *
     * @covers \local_awareness\table\all_notices::col_audience
     */
    public function test_the_audience_column_resolves_in_flight_jobs_once_per_page(): void {
        global $DB;

        $this->setAdminUser();

        // No stored hash, which is the state that sends state_of() to the jobs table.
        for ($i = 0; $i < 10; $i++) {
            $this->notice(['title' => 'Notice ' . $i]);
        }

        $table = new all_notices('probe', new \moodle_url('/local/awareness/managenotice.php'));
        $table->set_filterset(new all_notices_filterset());
        $table->query_db(all_notices::PER_PAGE, false);

        $render = new \ReflectionMethod(all_notices::class, 'col_audience');
        $render->setAccessible(true);

        $cells = [];
        $before = $DB->perf_get_reads();
        foreach ($table->rawdata as $notice) {
            $cells[] = $render->invoke($table, $notice);
        }
        $reads = $DB->perf_get_reads() - $before;

        // Control: every row really rendered a cell, so the column was exercised for all ten.
        $this->assertCount(10, $cells);
        foreach ($cells as $cell) {
            $this->assertNotSame('', $cell);
        }

        $this->assertLessThanOrEqual(2, $reads);
    }
}
