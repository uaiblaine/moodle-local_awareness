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
 * Dismissed notice system report page.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core_reportbuilder\system_report_factory;
use local_awareness\reportbuilder\local\systemreports\dismissed_notice;

$noticeid = required_param('noticeid', PARAM_INT);

require_login();

$context = context_system::instance();
require_capability('local/awareness:viewreports', $context);

// Validate the notice exists.
$notice = $DB->get_record('local_awareness', ['id' => $noticeid], '*', MUST_EXIST);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/awareness/report/dismissed_systemreport.php', ['noticeid' => $noticeid]));
$PAGE->set_title(get_string('report:dismissed', 'local_awareness', $notice->title));
$PAGE->set_heading(get_string('report:dismissed', 'local_awareness', $notice->title));
$PAGE->set_pagelayout('report');

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('report:dismissed_desc', 'local_awareness'));

$report = system_report_factory::create(
    dismissed_notice::class,
    $context,
    '',
    '',
    0,
    ['noticeid' => $noticeid]
);
echo $report->output();

$backurl = new moodle_url('/local/awareness/managenotice.php');
echo html_writer::div(
    html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-secondary mt-3']),
    'mt-3'
);

echo $OUTPUT->footer();
