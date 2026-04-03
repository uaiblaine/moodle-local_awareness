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
 * Manage Notices
 * @package local_awareness
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
use local_awareness\helper;
use local_awareness\table\all_notices;
admin_externalpage_setup('local_awareness_managenotice');
helper::check_manage_capability();

$page = optional_param('page', 0, PARAM_INT);

$thispage = '/local/awareness/managenotice.php';
$editnotice = '/local/awareness/editnotice.php';

$PAGE->set_url(new moodle_url($thispage));
$PAGE->requires->js_call_amd('local_awareness/preview', 'init');

$table = new all_notices('all_notices_table', new moodle_url($thispage), $page);

$output = $PAGE->get_renderer('local_awareness');
echo $output->header();
echo $output->heading(get_string('setting:managenotice', 'local_awareness'));
$newnoticeparams = ['noticeid' => 0, 'sesskey' => sesskey()];
$newnoticeurl = new moodle_url($editnotice, $newnoticeparams);
echo $OUTPUT->single_button($newnoticeurl, get_string('notice:create', 'local_awareness'));
echo $output->render($table);
echo $output->footer();
