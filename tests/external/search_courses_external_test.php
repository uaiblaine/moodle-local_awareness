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

namespace local_awareness;

/**
 * Tests for the course-picker web service.
 *
 * search_courses() is registered in db/services.php and had no test of any kind, positive or
 * negative — and it is one of the two functions carrying a capability check, so the check was
 * deletable with the suite green. It also reaches every course on the site by name, which makes
 * an unguarded version a course-catalogue oracle for any authenticated user.
 *
 * The calls go through call_external_function(), not the bare static, so execute_returns() is
 * applied to a real payload. That matters here specifically: the returns declaration is an
 * allowlist, and this function ships its results as a JSON blob inside a single PARAM_RAW key,
 * where an added field would never be stripped and never be noticed.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external::search_courses
 */
final class search_courses_external_test extends \advanced_testcase {
    /**
     * Log in an ordinary user holding local/awareness:manage.
     *
     * assign_capability() rather than setAdminUser(), so the tests show that THIS capability is
     * what the gate reads rather than that an admin passes everything.
     */
    private function login_as_manager(): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/awareness:manage',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $this->setUser($user);
    }

    /**
     * Call the function through the web-service layer and decode its payload.
     *
     * @param string $query The search term.
     * @return array The decoded course list.
     */
    private function search(string $query): array {
        $_POST['sesskey'] = sesskey();
        $response = \core_external\external_api::call_external_function(
            'local_awareness_search_courses',
            ['query' => $query],
            false
        );

        $this->assertFalse($response['error'], 'the web service returned an error');
        return json_decode($response['data']['courses'], true);
    }

    /**
     * A user without local/awareness:manage is refused.
     */
    public function test_a_plain_user_is_refused(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        external::search_courses('Astronomy');
    }

    /**
     * The guest user is refused too — the capability is not granted to guests by default.
     */
    public function test_the_guest_user_is_refused(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $this->setGuestUser();

        $this->expectException(\required_capability_exception::class);
        external::search_courses('Astronomy');
    }

    /**
     * A capability holder gets matching courses back.
     *
     * The control for both refusals above: the same query, the same course, differing only in who
     * is asking. Without it, the two expectException tests would pass against a function that
     * always threw.
     */
    public function test_a_manager_gets_matching_courses(): void {
        $this->resetAfterTest();

        $wanted = $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $this->getDataGenerator()->create_course(['fullname' => 'Botany 101']);
        $this->login_as_manager();

        $courses = $this->search('Astronomy');

        $this->assertCount(1, $courses);
        $this->assertSame((int) $wanted->id, $courses[0]['id']);
        $this->assertSame('Astronomy 101', $courses[0]['fullname']);
    }

    /**
     * The fullname comes back in the ESCAPED spelling, because the sink renders it as HTML.
     *
     * course_search.js hands the label to core's autocomplete, which appends it into the hidden
     * select and re-renders it through the triple stash in form_autocomplete_suggestions.mustache.
     * Nothing between json_encode() and that stash escapes anything, so an unformatted fullname
     * reaches the notice author's DOM as live markup — and a course fullname is settable by anyone
     * holding moodle/course:update, a strictly lower privilege than local/awareness:manage.
     *
     * A bare ampersand is the fixture on purpose. Tag-shaped input like <b>x</b> is stripped
     * identically whether or not the value was formatted, so it would prove nothing; the ampersand
     * rule is the one that actually differs between the two spellings.
     */
    public function test_the_fullname_is_returned_escaped(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course(['fullname' => 'Physics & Chemistry']);
        $this->login_as_manager();

        $courses = $this->search('Physics');

        $this->assertCount(1, $courses);
        $this->assertSame('Physics &amp; Chemistry', $courses[0]['fullname']);
    }

    /**
     * The query still matches the RAW stored fullname, not the escaped one.
     *
     * Deliberate, and the opposite of what WS-03 had to do for roles: the author types the text
     * they actually entered, so "Physics &" must find the course. Making the two "consistent" by
     * matching on the formatted string would break exactly this.
     */
    public function test_the_query_matches_the_unescaped_name(): void {
        $this->resetAfterTest();

        $wanted = $this->getDataGenerator()->create_course(['fullname' => 'Physics & Chemistry']);
        $this->login_as_manager();

        $courses = $this->search('Physics & Chem');

        $this->assertCount(1, $courses);
        $this->assertSame((int) $wanted->id, $courses[0]['id']);

        // Control: the escaped spelling is NOT what the query is compared against.
        $this->assertSame([], $this->search('Physics &amp; Chem'));
    }

    /**
     * The match is case-insensitive and matches anywhere in the name.
     */
    public function test_the_match_is_case_insensitive_and_unanchored(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course(['fullname' => 'Advanced Astronomy']);
        $this->login_as_manager();

        $this->assertCount(1, $this->search('astronomy'));
        $this->assertCount(1, $this->search('ADVANCED'));
    }

    /**
     * A query shorter than two characters returns nothing rather than the whole catalogue.
     *
     * Seeded with a course that the one-character query WOULD match if the guard were removed,
     * so the empty result is the guard's doing and not an empty site.
     */
    public function test_a_query_under_two_characters_returns_nothing(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $this->login_as_manager();

        $this->assertSame([], $this->search('A'));
        $this->assertSame([], $this->search(''));
        // Control: two characters of the same word do match.
        $this->assertCount(1, $this->search('As'));
    }

    /**
     * The site course is never offered.
     */
    public function test_the_site_course_is_excluded(): void {
        $this->resetAfterTest();

        $site = get_site();
        $this->login_as_manager();

        $ids = array_column($this->search(substr($site->fullname, 0, 4)), 'id');
        $this->assertNotContains((int) SITEID, $ids);
    }

    /**
     * A LIKE wildcard typed by the user is escaped rather than executed.
     */
    public function test_wildcards_in_the_query_are_escaped(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $this->getDataGenerator()->create_course(['fullname' => 'A%B special']);
        $this->login_as_manager();

        $courses = $this->search('A%B');

        $this->assertCount(1, $courses, 'a literal %% must not act as a wildcard');
        $this->assertSame('A%B special', $courses[0]['fullname']);
    }
}
