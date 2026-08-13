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
     * It takes its turn in the queue and leaves. Warning about it would be noise, and the whole
     * point of the warning is that it stays worth reading.
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
