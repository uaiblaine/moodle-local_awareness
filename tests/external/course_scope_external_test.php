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

namespace local_awareness\external;

use core_external\external_api;
use local_awareness\persistent\audience_job;

/**
 * The five editor web services under a course scope: gated by the course they name, and answering inside it.
 *
 * Every call goes by name through call_external_function(), with courseid added to the arguments the
 * editor sends, so the declared parameters and returns are what is tested. The author holds a role
 * carrying only managecourse, in one course, and is enrolled in both courses as a student: enrolment
 * satisfies validate_context(), so the refusal for the other course has to come from the capability.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\external\estimate_audience
 * @covers \local_awareness\external\get_estimate
 * @covers \local_awareness\external\search_courses
 * @covers \local_awareness\external\search_roles
 * @covers \local_awareness\external\check_collision
 * @covers \local_awareness\external\render_notice
 */
final class course_scope_external_test extends \advanced_testcase {
    /** @var \stdClass The author's course. */
    private \stdClass $mine;

    /** @var \stdClass The other course. */
    private \stdClass $other;

    /** @var \stdClass The course author. */
    private \stdClass $author;

    /**
     * Initial set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->mine = $this->getDataGenerator()->create_course(['fullname' => 'Astronomy 101']);
        $this->other = $this->getDataGenerator()->create_course(['fullname' => 'Astrophysics 201']);
        $this->author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->author->id, $this->mine->id);
        $this->getDataGenerator()->enrol_user($this->author->id, $this->other->id);
        $roleid = $this->getDataGenerator()->create_role();
        $context = \context_course::instance($this->mine->id);
        assign_capability('local/awareness:managecourse', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $this->author->id, $context->id);
    }

    /**
     * Call a plugin web service by name, as the current user.
     *
     * @param string $name The function's short name.
     * @param array $args Its arguments, keyed.
     * @return array The raw response: error, data or exception.
     */
    private function call(string $name, array $args): array {
        $_POST['sesskey'] = sesskey();

        return external_api::call_external_function('local_awareness_' . $name, $args, false);
    }

    /**
     * The five calls the editor makes, with the given course.
     *
     * @param int $courseid The course.
     * @return array name => args.
     */
    private function calls(int $courseid): array {
        return [
            'estimate_audience' => ['criteria' => json_encode([]), 'courseid' => $courseid],
            'get_estimate' => ['jobid' => 'nosuchjob', 'courseid' => $courseid],
            'search_courses' => ['query' => 'Astro', 'courseid' => $courseid],
            'search_roles' => ['query' => '', 'contextlevel' => 0, 'courseid' => $courseid],
            'check_collision' => ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true, 'courseid' => $courseid],
        ];
    }

    /**
     * Every editor service answers a course author for their course and refuses them for another.
     */
    public function test_every_editor_service_is_gated_by_the_course_it_names(): void {
        $this->setUser($this->author);

        foreach ($this->calls((int) $this->mine->id) as $name => $args) {
            $response = $this->call($name, $args);
            $this->assertFalse(
                $response['error'],
                "{$name} answers the author for their course: " . json_encode($response['exception'] ?? null)
            );
        }
        foreach ($this->calls((int) $this->other->id) as $name => $args) {
            $response = $this->call($name, $args);
            $this->assertTrue($response['error'], "{$name} must refuse the other course");
            $this->assertSame('nopermissions', $response['exception']->errorcode, "{$name} refuses for lack of the capability");
        }
        foreach ($this->calls(0) as $name => $args) {
            $this->assertTrue($this->call($name, $args)['error'], "{$name} must refuse the site to a course author");
        }
    }

    /**
     * An estimate under a course scope is confined to the course and comes back without the per-rule chips.
     *
     * The course has two students and the site one outsider, whom an unconfined estimate would
     * count. The per-rule chips each answer over the whole site, so they are withheld at the read:
     * a job a site manager made for the same criteria, chips included, still gives a course author
     * none. The site manager reading that job gets them, the control that the scope withholds them.
     */
    public function test_an_estimate_under_a_course_scope_is_the_course_s_and_carries_no_chips(): void {
        global $DB;

        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $this->mine->id);
        $this->getDataGenerator()->create_user(); // Not in the course: counts for the site, not for the course.
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);

        // A site manager first asks the exact question the course scope will force, chips included.
        $this->setAdminUser();
        $forced = [
            'filter_role' => [$studentroleid],
            'filter_role_context' => CONTEXT_COURSE,
            'filter_course' => [(int) $this->mine->id],
        ];
        $sitejob = $this->call('estimate_audience', ['criteria' => json_encode($forced), 'courseid' => 0]);
        $this->assertFalse($sitejob['error']);
        $siteread = $this->call('get_estimate', ['jobid' => $sitejob['data']['jobid'], 'courseid' => 0]);
        $this->assertSame('ready', $siteread['data']['status']);
        $this->assertNotSame('[]', $siteread['data']['breakdown'], 'the site manager gets the chips');

        // The course author asks with only the role rule; the scope forces the rest, and the job is shared.
        $this->setUser($this->author);
        $mine = $this->call(
            'estimate_audience',
            ['criteria' => json_encode(['filter_role' => [$studentroleid]]), 'courseid' => (int) $this->mine->id]
        );
        $this->assertFalse($mine['error']);
        $this->assertSame($sitejob['data']['jobid'], $mine['data']['jobid'], 'the same question is the same job, whoever asks');
        $job = audience_job::get_record(['jobid' => $mine['data']['jobid']]);
        $criteria = json_decode($job->get('criteria'), true);
        $this->assertSame(
            [(int) $this->mine->id],
            array_map('intval', $criteria['filter_course']),
            'the course is forced onto the job'
        );

        $read = $this->call('get_estimate', ['jobid' => $mine['data']['jobid'], 'courseid' => (int) $this->mine->id]);
        $this->assertFalse($read['error']);
        $this->assertSame('ready', $read['data']['status']);
        $this->assertSame(2, (int) $read['data']['count'], 'the two students of the course, and not the outsider');
        $this->assertSame('[]', $read['data']['breakdown'], 'no chips for a course author, even on a shared job');
    }

    /**
     * A job outside the scope reads as no job at all.
     */
    public function test_a_job_outside_the_scope_reads_as_no_job(): void {
        $this->setAdminUser();
        $sitejob = $this->call('estimate_audience', ['criteria' => json_encode(['pathmatch' => '/my/']), 'courseid' => 0]);
        $theirs = $this->call(
            'estimate_audience',
            ['criteria' => json_encode(['pathmatch' => '/my/']), 'courseid' => (int) $this->other->id]
        );
        $this->assertFalse($sitejob['error']);
        $this->assertFalse($theirs['error']);

        $this->setUser($this->author);
        foreach ([$sitejob, $theirs] as $foreign) {
            $read = $this->call('get_estimate', ['jobid' => $foreign['data']['jobid'], 'courseid' => (int) $this->mine->id]);
            $this->assertFalse($read['error']);
            $this->assertSame(
                'error',
                $read['data']['status'],
                'a site job and another course\'s job are not the author\'s to read'
            );
            $this->assertNull($read['data']['count']);
        }
    }

    /**
     * The search endpoints answer inside the course: its own course only, and the roles a course can hold.
     */
    public function test_the_searches_answer_inside_the_course(): void {
        global $DB;

        $this->setUser($this->author);

        $courses = json_decode(
            $this->call('search_courses', ['query' => 'Astro', 'courseid' => (int) $this->mine->id])['data']['courses'],
            true
        );
        $this->assertSame(
            [(int) $this->mine->id],
            array_map(static fn(array $c): int => (int) $c['id'], $courses),
            'only the author\'s course'
        );

        $this->setAdminUser();
        $both = json_decode($this->call('search_courses', ['query' => 'Astro', 'courseid' => 0])['data']['courses'], true);
        $this->assertCount(2, $both, 'the site search finds both: the control');

        $this->setUser($this->author);
        $rolesresponse = $this->call(
            'search_roles',
            ['query' => '', 'contextlevel' => CONTEXT_SYSTEM, 'courseid' => (int) $this->mine->id]
        );
        $roles = json_decode($rolesresponse['data']['roles'], true);
        // Keyed by the role_context_levels row id, not the role id: the values are the roles.
        $courselevel = array_map('intval', array_values(get_roles_for_contextlevels(CONTEXT_COURSE)));
        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $listed = array_map(static fn(array $r): int => (int) $r['id'], $roles);
        $this->assertNotEmpty($listed);
        $this->assertSame(
            [],
            array_diff($listed, $courselevel),
            'every role listed is one a course can hold, whatever level the client named'
        );
        $this->assertContains(
            $managerid,
            $courselevel,
            'precondition: the check has teeth only if the course-level set is a real subset'
        );
    }

    /**
     * The list's preview answers a course author the same way for every notice outside their authority.
     *
     * The author is enrolled in the other course, so validate_context() admits them there and only
     * the gate can refuse; a course they are not enrolled in is the case where validate_context()
     * would refuse first, and differently, if it ran before the gate. An id naming nothing is the
     * answer every refusal must match, and their own course's notice the control that the preview
     * works for them at all.
     */
    public function test_the_list_preview_refuses_every_notice_outside_the_scope_alike(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $own = $generator->create_notice(['courseid' => $this->mine->id]);
        $theirs = $generator->create_notice(['courseid' => $this->other->id]);
        $elsewhere = $generator->create_notice(['courseid' => $this->getDataGenerator()->create_course()->id]);
        $site = $generator->create_notice();

        $this->setUser($this->author);
        $response = $this->call('render_notice', ['noticeid' => (int) $own->get('id')]);
        $this->assertFalse($response['error'], 'the author previews their own course\'s notice');
        $this->assertSame((int) $own->get('id'), $response['data']['id']);

        $answers = [];
        $ids = [
            'theirs' => $theirs->get('id'),
            'elsewhere' => $elsewhere->get('id'),
            'site' => $site->get('id'),
            'missing' => 987654,
        ];
        foreach ($ids as $key => $id) {
            $response = $this->call('render_notice', ['noticeid' => (int) $id]);
            $this->assertTrue($response['error'], "{$key} must be refused");
            $answers[$key] = $response['exception']->errorcode . ': ' . $response['exception']->message;
        }
        $expected = 'notification:noticedoesnotexist: ' . get_string('notification:noticedoesnotexist', 'local_awareness');
        $this->assertSame(array_fill_keys(array_keys($ids), $expected), $answers);
    }

    /**
     * A competing notice is named only inside the scope; outside it is described, not named.
     */
    public function test_a_rival_outside_the_scope_is_described_and_not_named(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        /*
         * The rivals have no page restriction, so they overlap whatever reach the caller is judged
         * by; under a course scope that is author_scope::COURSE_PATHMATCH, not the '/my/%' sent
         * below. The test is about whose title a course author may read, not about page reach.
         */
        $generator->create_notice(['title' => 'Dashboard rival', 'resetinterval' => WEEKSECS]);
        $generator->create_notice([
            'title' => 'Other course rival',
            'resetinterval' => WEEKSECS,
            'courseid' => $this->other->id,
        ]);
        $generator->create_notice([
            'title' => 'Own course rival',
            'resetinterval' => WEEKSECS,
            'courseid' => $this->mine->id,
        ]);

        $this->setUser($this->author);
        $response = $this->call(
            'check_collision',
            ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true, 'courseid' => (int) $this->mine->id]
        );
        $titles = $response['data']['titles'];
        sort($titles);
        $expected = [
            get_string('collision:redacted:course', 'local_awareness'),
            get_string('collision:redacted:site', 'local_awareness'),
            'Own course rival',
        ];
        sort($expected);
        $this->assertSame($expected, $titles);

        $this->setAdminUser();
        $response = $this->call('check_collision', ['noticeid' => 0, 'pathmatch' => '/my/%', 'repeats' => true, 'courseid' => 0]);
        $site = $response['data']['titles'];
        sort($site);
        $this->assertSame(
            ['Dashboard rival', 'Other course rival', 'Own course rival'],
            $site,
            'the site sees every title: the control'
        );
    }
}
