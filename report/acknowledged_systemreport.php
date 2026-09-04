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
 * Acknowledged notice system report page.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core_reportbuilder\system_report_factory;
use local_awareness\helper;
use local_awareness\local\author_scope;
use local_awareness\reportbuilder\local\systemreports\acknowledged_notice;

$noticeid = required_param('noticeid', PARAM_INT);

require_login();

$context = context_system::instance();

// Resolved before the gate, because the gate depends on whose notice it is. resolve_notice() refuses
// an id that names nothing with the editor's own message and answers null only for an id of zero,
// which is refused the same way here. The report itself stays in the system context in every
// scope: its rows are already one notice's, and it decides its viewer the same way.
$awareness = helper::resolve_notice($noticeid);
if (!$awareness) {
    throw new moodle_exception('notification:noticedoesnotexist', 'local_awareness');
}
helper::require_author(author_scope::of($awareness), 'viewreports');

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/awareness/report/acknowledged_systemreport.php', ['noticeid' => $noticeid]));
$PAGE->set_title(get_string('report:acknowledged', 'local_awareness', $awareness->get('title')));
$PAGE->set_heading(get_string('report:acknowledged', 'local_awareness', $awareness->get('title')));
$PAGE->set_pagelayout('report');
// The Bootstrap 4 polyfill in styles.css is gated on the body class this adds, so a page that
// omits it renders unstyled on 4.5 while every static gate stays green.
\local_awareness\local\bootstrap::mark_page();

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('report:acknowledge_desc', 'local_awareness'));

$report = system_report_factory::create(
    acknowledged_notice::class,
    $context,
    '',
    '',
    0,
    ['noticeid' => $noticeid]
);
echo $report->output();

$backurl = new moodle_url('/local/awareness/managenotice.php');
echo $OUTPUT->render_from_template('local_awareness/manage/backlink', ['url' => $backurl->out(false)]);

echo $OUTPUT->footer();
