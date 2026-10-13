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
 * The course report of every acknowledgement and dismissal of its notices.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use core_reportbuilder\system_report_factory;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\reportbuilder\local\systemreports\course_notices;

$courseid = required_param('courseid', PARAM_INT);
if ($courseid <= SITEID) {
    // The site's notices are reported per notice from Site administration; author_scope::course() refuses the site.
    throw new moodle_exception('invalidcourseid');
}

$course = get_course($courseid);
require_login($course);
$scope = author_scope::course((int) $course->id);
helper::require_author($scope, 'viewreports');

$context = $scope->context();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/awareness/report/course.php', ['courseid' => $course->id]));
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report:course', 'local_awareness'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->set_secondary_active_tab('coursereports');
// The Bootstrap 4 polyfill in styles.css is gated on the body class this adds.
\local_awareness\local\bootstrap::mark_page();

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('report:course', 'local_awareness'));
echo $OUTPUT->paragraph(get_string('report:course_desc', 'local_awareness'));

$report = system_report_factory::create(
    course_notices::class,
    $context,
    '',
    '',
    0,
    ['courseid' => (int) $course->id]
);
echo $report->output();

echo $OUTPUT->footer();
