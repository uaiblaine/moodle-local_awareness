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
use core_table\local\filter\integer_filter;
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
     * The compliance reports are offered exactly where rows can exist, and nowhere else.
     *
     * These two buttons used to be gated on reqack, which is one of the two columns the insistence
     * level is derived from rather than the level itself. A Blocking notice records acceptances and
     * refusals just as an Acknowledge one does, so gating on reqack hid the reports for precisely
     * the notices whose rows nothing else in the interface could reach — the manage list is the
     * only route to them, and the report pages answer a hand-built URL.
     *
     * Nothing asserted this before, in either direction: a mutation putting the reqack gate back
     * survived the whole suite.
     *
     * @return void
     */
    public function test_the_compliance_reports_are_offered_exactly_where_rows_can_exist(): void {
        $this->setAdminUser();

        $informational = $this->notice(['title' => 'Info', 'reqack' => 0, 'outsideclick' => 1]);
        $blocking = $this->notice(['title' => 'Blocking', 'reqack' => 0, 'outsideclick' => 0]);
        $acknowledge = $this->notice(['title' => 'Acknowledge', 'reqack' => 1]);

        $table = new all_notices('test', new \moodle_url('/local/awareness/managenotice.php'));
        $method = new \ReflectionMethod(all_notices::class, 'col_actions');
        $method->setAccessible(true);

        $acklabel = get_string('report:button:ack', 'local_awareness');
        $dislabel = get_string('report:button:dis', 'local_awareness');

        $informationalmenu = $method->invoke($table, $informational);
        $blockingmenu = $method->invoke($table, $blocking);
        $acknowledgemenu = $method->invoke($table, $acknowledge);

        // The control: the menu really was rendered, so the absences below are absences of the
        // report links and not of the whole column.
        $this->assertStringContainsString(
            get_string('notice:reset', 'local_awareness'),
            $informationalmenu,
            'the action menu did not render, so nothing below this line means anything'
        );

        $this->assertStringNotContainsString($acklabel, $informationalmenu);
        $this->assertStringNotContainsString($dislabel, $informationalmenu);

        $this->assertStringContainsString($acklabel, $blockingmenu, 'a Blocking notice records acceptances');
        $this->assertStringContainsString($dislabel, $blockingmenu, 'a Blocking notice records refusals');

        $this->assertStringContainsString($acklabel, $acknowledgemenu);
        $this->assertStringContainsString($dislabel, $acknowledgemenu);
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
            /*
             * The method returns [sentence, plain list] now: the cell is a Mustache template, so
             * the markup is assembled there and this hands back the two values it needs.
             */
            $this->assertStringContainsString('Alpha cohort', $line[0]);
            $this->assertStringContainsString('Beta cohort', $line[0]);
            $this->assertStringContainsString('Alpha cohort', $line[1]);
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

        /*
         * One render before the counter starts. The cell is built from a Mustache template now,
         * and the FIRST render_from_template() of a request pays a one-off setup cost — measured
         * at nine reads here, then zero for every row after it. Counting from cold would attribute
         * core's theme and template initialisation to this column and make the assertion below
         * about the wrong thing. Measured, not assumed: with this warm-up the ten rows below cost
         * zero reads, so the batching really is per page and not per row.
         */
        $render->invoke($table, reset($table->rawdata));

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

    /**
     * A table for the given course, or for the site, with the filterset the page would build.
     *
     * @param int|null $courseid The course, or null for the site.
     * @return all_notices
     */
    private function scoped_table(?int $courseid): all_notices {
        $table = new all_notices('scoped', new \moodle_url('/local/awareness/managenotice.php'));
        $filterset = new all_notices_filterset();
        $filterset->set_join_type(filter::JOINTYPE_ALL);
        if ($courseid !== null) {
            $filterset->add_filter(new integer_filter('courseid', filter::JOINTYPE_ANY, [$courseid]));
        }
        $table->set_filterset($filterset);

        return $table;
    }

    /**
     * The titles the given table lists.
     *
     * @param all_notices $table The table.
     * @return string[]
     */
    private function titles_of(all_notices $table): array {
        $table->query_db(all_notices::PER_PAGE, false);
        $titles = array_map(static fn(awareness $n): string => $n->get('title'), $table->rawdata ?? []);
        sort($titles);

        return $titles;
    }

    /**
     * A course list holds that course's notices and nothing else; the site list holds everything.
     *
     * Two courses and a site notice, so "only C" has something real to exclude in both directions.
     */
    public function test_a_course_list_holds_only_that_course_s_notices(): void {
        $this->setAdminUser();
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $generator->create_notice(['title' => 'mine', 'courseid' => $mine->id]);
        $generator->create_notice(['title' => 'theirs', 'courseid' => $other->id]);
        $generator->create_notice(['title' => 'site']);

        $this->assertSame(['mine'], $this->titles_of($this->scoped_table((int) $mine->id)));
        $this->assertSame(['theirs'], $this->titles_of($this->scoped_table((int) $other->id)));
        $this->assertSame(['mine', 'site', 'theirs'], $this->titles_of($this->scoped_table(null)));
    }

    /**
     * The context and the capability follow the filterset, which is all the AJAX refresh has.
     *
     * A course author passes for their course's list, is refused another course's, and is refused
     * the site's — whether the filterset says the site or says nothing at all.
     */
    public function test_the_context_and_the_capability_follow_the_filterset(): void {
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, \context_course::instance($mine->id)->id, true);
        role_assign($roleid, $user->id, \context_course::instance($mine->id)->id);
        $this->setUser($user);

        $table = $this->scoped_table((int) $mine->id);
        $this->assertSame(\context_course::instance($mine->id)->id, $table->get_context()->id);
        $this->assertTrue($table->has_capability(), 'the course author sees their course\'s list');

        $table = $this->scoped_table((int) $other->id);
        $this->assertSame(\context_course::instance($other->id)->id, $table->get_context()->id);
        $this->assertFalse($table->has_capability(), 'and not another course\'s');

        $table = $this->scoped_table(null);
        $this->assertInstanceOf(\context_system::class, $table->get_context());
        $this->assertFalse($table->has_capability(), 'nor the site\'s');

        $bare = new all_notices('bare');
        $this->assertInstanceOf(\context_system::class, $bare->get_context(), 'no filterset at all is the site');
        $this->assertFalse($bare->has_capability());
    }

    /**
     * The site list says which course a course notice belongs to; a course list carries its course in every link.
     */
    public function test_the_site_list_names_the_course_and_the_course_list_keeps_it_in_its_links(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $generator->create_notice(['title' => 'mine', 'courseid' => $course->id]);
        $generator->create_notice(['title' => 'site']);

        $site = $this->scoped_table(null);
        $site->query_db(all_notices::PER_PAGE, false);
        $rows = [];
        foreach ($site->rawdata as $notice) {
            $rows[$notice->get('title')] = $site->format_row($notice);
        }
        $this->assertStringContainsString('Astronomy 101', $rows['mine']['title'], 'the site list names the course');
        $this->assertStringNotContainsString('Astronomy 101', $rows['site']['title'], 'a site notice names none');
        $this->assertStringNotContainsString('courseid=', $rows['mine']['actions'], 'site-list links stay site links');

        $mine = $this->scoped_table((int) $course->id);
        $mine->query_db(all_notices::PER_PAGE, false);
        $row = $mine->format_row(reset($mine->rawdata));
        $this->assertStringNotContainsString('Astronomy 101', $row['title'], 'a course list needs no chip');
        $this->assertStringContainsString('courseid=' . $course->id, $row['actions'], 'every action keeps the course');
        $mine->guess_base_url();
        $this->assertSame((int) $course->id, (int) $mine->baseurl->get_param('courseid'));
    }

    /**
     * A reports-only viewer sees the course list, with the reports and the preview and none of the verbs.
     *
     * The manage holder beside them, on the same row, is the control that the verbs exist to be
     * withheld; the report actions appear for both, because the notice is one that records answers.
     */
    public function test_a_reports_only_viewer_gets_a_read_only_list(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $generator->create_notice(['title' => 'mine', 'courseid' => $course->id, 'reqack' => 1]);
        $context = \context_course::instance($course->id);

        $reader = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:viewreportscourse', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $reader->id, $context->id);

        $author = $this->getDataGenerator()->create_user();
        $authorrole = $this->getDataGenerator()->create_role();
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $authorrole, $context->id, true);
        role_assign($authorrole, $author->id, $context->id);

        $this->setUser($reader);
        $table = $this->scoped_table((int) $course->id);
        $this->assertTrue($table->has_capability(), 'the reports capability opens the list');
        $table->query_db(all_notices::PER_PAGE, false);
        $actions = $table->format_row(reset($table->rawdata))['actions'];
        $this->assertStringContainsString('action=acknowledged_report', $actions);
        $this->assertStringContainsString('action=dismissed_report', $actions);
        $verbs = ['action=edit', 'action=disable', 'action=unconfirmeddelete', 'action=unconfirmedreset', 'action=recalculate'];
        foreach ($verbs as $verb) {
            $this->assertStringNotContainsString($verb, $actions, "a reader is offered no {$verb}");
        }

        $this->setUser($author);
        $table = $this->scoped_table((int) $course->id);
        $table->query_db(all_notices::PER_PAGE, false);
        $actions = $table->format_row(reset($table->rawdata))['actions'];
        $this->assertStringContainsString('action=edit', $actions, 'the author is offered the verbs: the control');
        $this->assertStringContainsString('action=acknowledged_report', $actions);
    }

    /**
     * The "competing" filter on a course list keeps to that course's competing notices.
     *
     * A clashing pair in another course and a clashing site notice are seeded beside the course's
     * own pair, so a filter that resolved the site's clashing ids and forgot the course reddens.
     */
    public function test_the_competing_filter_on_a_course_list_keeps_to_the_course(): void {
        $this->setAdminUser();
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $seed = [
            'mine a' => $mine->id,
            'mine b' => $mine->id,
            'theirs a' => $other->id,
            'theirs b' => $other->id,
            'site a' => 0,
            'site b' => 0,
        ];
        foreach ($seed as $title => $courseid) {
            $generator->create_notice([
                'title' => $title,
                'courseid' => $courseid,
                'pathmatch' => '/my/%',
                'resetinterval' => WEEKSECS,
            ]);
        }

        $table = $this->scoped_table((int) $mine->id);
        $filterset = $table->get_filterset();
        $filterset->add_filter(new string_filter('status', filter::JOINTYPE_ANY, [all_notices_filterset::STATUS_CLASH]));
        $table->set_filterset($filterset);

        $this->assertSame(['mine a', 'mine b'], $this->titles_of($table));
    }
}
