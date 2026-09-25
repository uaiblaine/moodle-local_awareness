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

/**
 * How the notice list spells the admin-set names it shows.
 *
 * Every name in the list reaches a template, and the template decides the spelling: a triple stash
 * renders the escaped one, a double stash escapes the plain one itself. The fixtures carry a bare
 * "&", which the two spellings write differently, so a name escaped twice reads "&amp;amp;" and one
 * never escaped reads a bare "&" in the markup.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\table\all_notices
 */
final class all_notices_rendering_test extends \advanced_testcase {
    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * The site list's rendered cells, keyed by notice title.
     *
     * @return array Title => the row format_row() returns, column => cell HTML.
     */
    private function site_rows(): array {
        $table = new all_notices('rendering', new \moodle_url('/local/awareness/managenotice.php'));
        $filterset = new all_notices_filterset();
        $filterset->set_join_type(filter::JOINTYPE_ALL);
        $table->set_filterset($filterset);
        $table->query_db(all_notices::PER_PAGE, false);

        $rows = [];
        foreach ($table->rawdata ?? [] as $notice) {
            $rows[$notice->get('title')] = $table->format_row($notice);
        }

        return $rows;
    }

    /**
     * Two competing notices: a course notice naming a course, a group and a cohort, and its rival.
     *
     * @param string $title The course notice's title.
     * @param string $rival The rival's title.
     * @param string $coursename The course's full name.
     * @param string $groupname The group's name.
     * @param string $cohortname The cohort's name.
     * @return void
     */
    private function seed(string $title, string $rival, string $coursename, string $groupname, string $cohortname): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => $coursename]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => $groupname]);
        $cohort = $this->getDataGenerator()->create_cohort(['name' => $cohortname]);
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');

        // A computed count, or the audience cell shows "not calculated" and none of its lines.
        $generator->create_notice([
            'title' => $title,
            'courseid' => (int) $course->id,
            'filtervalues' => json_encode(['filter_groups' => [(int) $group->id]]),
            'cohorts' => (string) $cohort->id,
            'audiencecount' => 12,
            'audiencecomputed' => time(),
            'pathmatch' => '/my/%',
            'resetinterval' => WEEKSECS,
        ]);
        $generator->create_notice(['title' => $rival, 'pathmatch' => '/my/%', 'resetinterval' => WEEKSECS]);
    }

    /**
     * Every admin-set name is escaped exactly once, whichever stash it lands in.
     *
     * The title is rendered twice: through a triple stash, which must receive the escaped spelling,
     * and into its tooltip attribute through a double stash, which must receive the plain one. The
     * course, group, cohort and rival names all reach double stashes.
     */
    public function test_every_admin_set_name_is_escaped_exactly_once(): void {
        $this->setAdminUser();
        $this->seed('Q&A session', 'R&D briefing', 'Arts & Crafts', 'Red & Blue', 'Alpha & Omega');

        $rows = $this->site_rows();
        $this->assertArrayHasKey('Q&A session', $rows, 'the notice is listed');
        $row = $rows['Q&A session'];

        $this->assertStringContainsString('title="Q&amp;A session"', $row['title'], 'the tooltip is escaped once');
        $this->assertStringContainsString('>Q&amp;A session</span>', $row['title'], 'the visible title is escaped once');
        $this->assertStringContainsString('Arts &amp; Crafts', $row['title'], 'the course chip is escaped once');
        $this->assertStringContainsString('Red &amp; Blue', $row['audience'], 'the group line is escaped once');
        $this->assertStringContainsString('Alpha &amp; Omega', $row['audience'], 'the cohort line is escaped once');
        $this->assertStringContainsString('R&amp;D briefing', $row['status'], 'the rival is escaped once');

        foreach (['title', 'audience', 'status'] as $column) {
            $this->assertStringNotContainsString('&amp;amp;', $row[$column], "nothing in the {$column} cell is escaped twice");
            $this->assertDoesNotMatchRegularExpression(
                '/&(?![a-zA-Z]+;|#x?[0-9a-fA-F]+;)/',
                $row[$column],
                "no bare & in the {$column} cell"
            );
        }
    }

    /**
     * Cohort names and rival titles go through the text filters, as the title column does.
     *
     * With the multilang filter applied to strings, the title column shows one language: the
     * control that the filter really runs. The cohort line and the conflict badge must show the
     * same language and none of the markup.
     */
    public function test_cohort_names_and_rival_titles_are_formatted(): void {
        filter_set_global_state('multilang', TEXTFILTER_ON);
        filter_set_applies_to_strings('multilang', true);
        \core_filters\filter_manager::reset_caches();

        $multilang = static function (string $english, string $other): string {
            return '<span lang="en" class="multilang">' . $english . '</span>'
                . '<span lang="xx" class="multilang">' . $other . '</span>';
        };

        $this->setAdminUser();
        $own = $multilang('Own title', 'Titre propre');
        $rival = $multilang('Rival title', 'Titre rival');
        $this->seed($own, $rival, 'Course', 'Group', $multilang('Cohort name', 'Nom de cohorte'));

        $rows = $this->site_rows();
        $this->assertArrayHasKey($own, $rows, 'the notice is listed');
        $row = $rows[$own];

        $this->assertStringContainsString('Own title', $row['title'], 'the filter runs: the control');
        $this->assertStringNotContainsString('Titre propre', $row['title']);

        $this->assertStringContainsString('Cohort name', $row['audience']);
        $this->assertStringNotContainsString('Nom de cohorte', $row['audience'], 'the cohort name is filtered');
        $this->assertStringContainsString('Rival title', $row['status']);
        $this->assertStringNotContainsString('Titre rival', $row['status'], 'the rival title is filtered');
        foreach (['audience', 'status'] as $column) {
            $this->assertStringNotContainsString('multilang', $row[$column], "no markup is printed in the {$column} cell");
        }
    }
}
