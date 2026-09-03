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

/**
 * Data generator for local_awareness.
 *
 * Five test files each hand-built notice rows with the same fourteen literal fields, which meant
 * every column added to the table had to be added to fourteen call sites, and a row that omitted
 * one simply relied on the database default. Routing through the persistent gives every test the
 * same defaults the plugin itself writes, and makes a schema change a one-line edit here.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_awareness_generator extends component_generator_base {
    /** @var int Number of notices created, so each gets a distinct default title. */
    protected $noticecount = 0;

    /** @var int Number of bare course rows created, so each gets a distinct shortname. */
    protected $barecount = 0;

    /**
     * Create a notice.
     *
     * Everything has a default, so a test states only what it is actually about — a test asserting
     * on the reset interval should not have to name a content format to get there.
     *
     * Deliberately routed through the awareness persistent rather than insert_record(): the
     * persistent is what production uses, so a test built this way exercises the same validation
     * and the same timestamps, and a field the persistent rejects fails here rather than producing
     * a row the plugin could never have written.
     *
     * @param array|stdClass $record Fields to override.
     * @return \local_awareness\persistent\awareness The stored notice.
     */
    public function create_notice($record = null) {
        $this->noticecount++;
        $record = (array) ($record ?? []);

        $record += [
            'title' => 'Notice ' . $this->noticecount,
            'content' => '<p>Body ' . $this->noticecount . '</p>',
            'contentformat' => FORMAT_HTML,
            'enabled' => 1,
        ];

        $notice = new \local_awareness\persistent\awareness(0, (object) $record);
        $notice->create();

        return $notice;
    }

    /**
     * Rows in {course} and nothing else, as many as asked for.
     *
     * For tests that need a list of ids the author scope will accept as courses, and nothing more:
     * the scope asks only whether each id is a course. The course generator would pay for
     * contexts, sections and enrolment instances such a test never looks at, and pay hundreds of
     * times over when the subject is a bound on list length.
     *
     * @param int $count How many.
     * @return int[] The new course ids, in creation order.
     */
    public function create_bare_courses(int $count): array {
        global $DB;

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $this->barecount++;
            $ids[] = (int) $DB->insert_record('course', (object) [
                'category' => 1,
                'fullname' => 'Bare course ' . $this->barecount,
                'shortname' => 'bare' . $this->barecount,
                'idnumber' => '',
                'lang' => '',
                'calendartype' => '',
                'theme' => '',
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return $ids;
    }
}
