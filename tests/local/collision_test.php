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

use local_awareness\persistent\awareness;

/**
 * Detecting repeating notices that compete for the same pages.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_awareness\local\collision
 */
final class collision_test extends \advanced_testcase {
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
     * @param string $title Title.
     * @param string $pathmatch Page reach.
     * @param int $resetinterval Repeat interval; zero means it does not repeat.
     * @param int $enabled Whether it is enabled.
     * @return awareness
     */
    private function notice(string $title, string $pathmatch, int $resetinterval, int $enabled = 1): awareness {
        $notice = new awareness(0, (object) [
            'title' => $title,
            'content' => '<p>' . $title . '</p>',
            'pathmatch' => $pathmatch,
            'resetinterval' => $resetinterval,
            'enabled' => $enabled,
        ]);
        $notice->create();

        return $notice;
    }

    /**
     * Page-reach overlap, across the shapes a pathmatch can take.
     *
     * The cases are looped rather than supplied by a data provider on purpose. Moodle 4.5 vendors
     * PHPUnit 9.6, which predates attribute metadata, so #[DataProvider] there supplies nothing and
     * the test is called with no arguments; the docblock form works on 4.5 but raises a runner
     * deprecation on 5.x, which vendors PHPUnit 11.5. A plain loop is the one shape that behaves
     * the same on both, and the assertion message names the pair so a failure still says which.
     */
    public function test_pathmatch_overlap(): void {
        $cases = [
            'both unrestricted' => [true, '', ''],
            'one unrestricted' => [true, '', '/my/'],
            'bare wildcard' => [true, '%', '/course/view.php'],
            'identical' => [true, '/my/', '/my/'],
            'identical ignoring case' => [true, '/My/', '/my/'],
            'token against the path it stands for' => [true, 'MY', '/my/'],
            'tokens sharing a landmark' => [true, 'FRONTPAGE_MY', 'MY'],
            'wildcard covering a literal' => [true, '/mod/%', '/mod/quiz/view.php'],
            'wildcards meeting under a shared prefix' => [true, '/mod/%', '/mod/quiz/%'],
            'tokens with no landmark in common' => [false, 'FRONTPAGE', 'MYCOURSES'],
            'unrelated literals' => [false, '/mod/quiz/view.php', '/mod/forum/view.php'],
            'wildcard missing an unrelated literal' => [false, '/mod/forum/%', '/user/profile.php'],
        ];

        foreach ($cases as $name => [$expected, $a, $b]) {
            $this->assertSame($expected, collision::pathmatch_overlaps($a, $b), "{$name}: {$a} vs {$b}");
            // Overlap is a symmetric question; the implementation must not care about argument order.
            $this->assertSame($expected, collision::pathmatch_overlaps($b, $a), "{$name}, reversed: {$b} vs {$a}");
        }
    }

    /**
     * A notice that does not repeat competes with nobody, whatever its page reach.
     *
     * It takes its turn in the queue and leaves, so a warning about it would be noise.
     */
    public function test_a_notice_that_does_not_repeat_never_clashes(): void {
        $this->setAdminUser();
        $this->notice('Repeating everywhere', '', DAYSECS);

        // Control: the same page reach on a repeating notice does clash, so the query is sound and
        // the empty result below comes from the repeat interval alone.
        $this->assertCount(1, collision::clashes_for(0, '', DAYSECS));

        $this->assertSame([], collision::clashes_for(0, '', 0));
    }

    /**
     * Only enabled notices compete, and a notice never competes with itself.
     */
    public function test_clashes_exclude_the_notice_itself_and_disabled_ones(): void {
        $this->setAdminUser();
        $self = $this->notice('Self', '/my/%', DAYSECS);
        $this->notice('Disabled rival', '/my/%', DAYSECS, 0);
        $rival = $this->notice('Enabled rival', '/my/%', DAYSECS);

        $clashes = collision::clashes_for((int) $self->get('id'), '/my/%', DAYSECS);

        $this->assertSame([(int) $rival->get('id')], array_keys($clashes));
    }

    /**
     * A notice scheduled for later still competes, and is reported before it starts.
     */
    public function test_a_scheduled_notice_still_counts(): void {
        $this->setAdminUser();
        $scheduled = $this->notice('Next week', '/my/%', DAYSECS);
        $scheduled->set('timestart', time() + WEEKSECS);
        $scheduled->set('timeend', time() + (2 * WEEKSECS));
        $scheduled->update();

        $clashes = collision::clashes_for(0, '/my/%', DAYSECS);

        $this->assertSame([(int) $scheduled->get('id')], array_keys($clashes));
    }

    /**
     * A notice whose window has closed for good competes with nobody, in all three answers.
     *
     * It can never display again. The notice scheduled for later is the control that the window
     * cuts only at the end, and the two live notices are the control that the ended one is left
     * out for its window and not because nothing clashes at all.
     */
    public function test_an_ended_notice_no_longer_counts(): void {
        $this->setAdminUser();
        $a = $this->notice('Live A', '/my/%', DAYSECS);
        $b = $this->notice('Live B', '/my/%', DAYSECS);
        $ended = $this->notice('Ended', '/my/%', DAYSECS);
        $ended->set('timestart', time() - (2 * WEEKSECS));
        $ended->set('timeend', time() - WEEKSECS);
        $ended->update();
        $later = $this->notice('Later', '/my/%', DAYSECS);
        $later->set('timestart', time() + WEEKSECS);
        $later->set('timeend', time() + (2 * WEEKSECS));
        $later->update();

        // Precondition: the ended notice is still enabled and repeating, so only its window can exclude it.
        $stored = awareness::get_record(['id' => $ended->get('id')]);
        $this->assertSame(1, (int) $stored->get('enabled'));
        $this->assertGreaterThan(0, (int) $stored->get('resetinterval'));
        $this->assertLessThan(time(), (int) $stored->get('timeend'));

        $clashes = collision::clashes_for(0, '/my/%', DAYSECS);
        $this->assertEqualsCanonicalizing(
            [(int) $a->get('id'), (int) $b->get('id'), (int) $later->get('id')],
            array_keys($clashes)
        );

        $this->assertEqualsCanonicalizing(
            [(int) $a->get('id'), (int) $b->get('id'), (int) $later->get('id')],
            collision::clashing_ids()
        );

        $map = collision::clash_titles_for([$a, $ended]);
        $this->assertEqualsCanonicalizing(['Live B', 'Later'], $map[(int) $a->get('id')]);
        $this->assertArrayNotHasKey((int) $ended->get('id'), $map, 'a notice that has ended is not badged either');
    }

    /**
     * A notice whose own end has passed competes with nobody, as an ended rival does not.
     *
     * It can never show again. The controls are the same question with no end and with an end
     * still ahead, which do report the rival, so the empty answers come from the end alone.
     */
    public function test_a_notice_whose_own_end_has_passed_competes_with_nobody(): void {
        $this->setAdminUser();
        $rival = [(int) $this->notice('Live rival', '/my/%', DAYSECS)->get('id')];

        $this->assertSame($rival, array_keys(collision::clashes_for(0, '/my/%', DAYSECS)));
        $this->assertSame($rival, array_keys(collision::clashes_for(0, '/my/%', DAYSECS, time() + WEEKSECS)));

        $this->assertSame([], collision::clashes_for(0, '/my/%', DAYSECS, time() - MINSECS));
        // The window is half-open, so the end itself has already passed.
        $this->assertSame([], collision::clashes_for(0, '/my/%', DAYSECS, time()));
    }

    /**
     * The warning after a save judges the submitted end and the scope's page reach.
     *
     * The same submission with no end and with an end still ahead reports the rival, so the empty
     * answer comes from the end that has passed. Under a course scope the reach is the forced
     * course page, so the course rival is met and the rival on /my/ is not.
     */
    public function test_the_save_warning_judges_the_submitted_end_and_the_scope_reach(): void {
        $this->setAdminUser();
        $site = author_scope::site();
        $rival = [(int) $this->notice('Live rival', '/my/%', DAYSECS)->get('id')];
        $submission = (object) ['pathmatch' => '/my/%', 'resetinterval' => DAYSECS, 'timeend' => 0];

        $this->assertSame($rival, array_keys(collision::clashes_for_save(null, $submission, $site)));
        $submission->timeend = time() + WEEKSECS;
        $this->assertSame($rival, array_keys(collision::clashes_for_save(null, $submission, $site)));
        $submission->timeend = time() - MINSECS;
        $this->assertSame([], collision::clashes_for_save(null, $submission, $site));

        $courserival = [(int) $this->notice('Course rival', author_scope::COURSE_PATHMATCH, DAYSECS)->get('id')];
        $course = $this->getDataGenerator()->create_course();
        $incourse = (object) ['pathmatch' => '', 'resetinterval' => DAYSECS, 'timeend' => 0];
        $this->assertSame(
            $courserival,
            array_keys(collision::clashes_for_save(null, $incourse, author_scope::course((int) $course->id)))
        );
    }

    /**
     * The warning's titles are redacted outside the author's scope and spelt for the sink.
     *
     * The ampersand is the fixture that tells the two spellings apart: format_string() rewrites it
     * only when escaping, while quotes and tag-shaped input read the same in both modes.
     */
    public function test_formatted_titles_redact_outside_the_scope_and_follow_the_sink(): void {
        $this->setAdminUser();
        $mine = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_awareness');
        $own = $generator->create_notice(['title' => 'Own & rival', 'courseid' => $mine->id]);
        $theirs = $generator->create_notice(['title' => 'Their & rival', 'courseid' => $other->id]);
        $site = $generator->create_notice(['title' => 'Site & rival']);

        $course = author_scope::course((int) $mine->id);
        $this->assertSame(
            [
                'Own &amp; rival',
                get_string('collision:redacted:course', 'local_awareness'),
                get_string('collision:redacted:site', 'local_awareness'),
            ],
            collision::formatted_titles([$own, $theirs, $site], $course)
        );
        $this->assertSame(['Own & rival'], collision::formatted_titles([$own], $course, false));

        // The control: the site sees every title, so the redaction above is the scope's doing.
        $this->assertSame(
            ['Own &amp; rival', 'Their &amp; rival', 'Site &amp; rival'],
            collision::formatted_titles([$own, $theirs, $site], author_scope::site())
        );
    }

    /**
     * The listing map names the rivals of each competing notice, and leaves the rest out.
     */
    public function test_clash_titles_for_a_listing(): void {
        $this->setAdminUser();
        $a = $this->notice('Repeat A', '/my/%', DAYSECS);
        $b = $this->notice('Repeat B', '/my/%', DAYSECS);
        $elsewhere = $this->notice('Repeat elsewhere', '/user/profile.php', DAYSECS);
        $once = $this->notice('Shown once', '/my/%', 0);

        $map = collision::clash_titles_for([$a, $b, $elsewhere, $once]);

        $this->assertSame(['Repeat B'], $map[(int) $a->get('id')]);
        $this->assertSame(['Repeat A'], $map[(int) $b->get('id')]);
        $this->assertArrayNotHasKey((int) $elsewhere->get('id'), $map, 'different pages, no competition');
        $this->assertArrayNotHasKey((int) $once->get('id'), $map, 'does not repeat, no competition');
    }
}
