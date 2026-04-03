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
 * To create, view notice
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_awareness\form\notice_form;
use local_awareness\helper;
use local_awareness\persistent\awareness;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_awareness_managenotice');
helper::check_manage_capability();

$PAGE->set_context(context_system::instance());
$PAGE->navbar->add(get_string('notice:notice', 'local_awareness'));

$noticeid = optional_param('noticeid', 0, PARAM_INT);
$action = optional_param('action', 'create', PARAM_TEXT);

// Only require sesskey for form submissions or destructive actions, not on plain GET page loads.
if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, ['delete', 'confirmeddelete'])) {
    require_sesskey();
}

$managenoticepage = new moodle_url('/local/awareness/managenotice.php');
$thispage = new moodle_url('/local/awareness/editnotice.php', ['noticeid' => $noticeid]);
$PAGE->set_url($thispage);
$PAGE->requires->js_call_amd('local_awareness/notice_form', 'init', []);

$awareness = awareness::get_record(['id' => $noticeid]);
$customdata = [
    'persistent' => $awareness,
    'id' => $noticeid,
];
$mform = new notice_form($thispage, $customdata);

$options = helper::get_file_editor_options();
$draftitemid = file_get_submitted_draft_itemid('content');
file_prepare_draft_area($draftitemid, context_system::instance()->id, 'local_awareness', 'content', 0, $options);

// Prepare draft area for background image.
$bgdraftitemid = file_get_submitted_draft_itemid('bgimage');
file_prepare_draft_area(
    $bgdraftitemid,
    context_system::instance()->id,
    'local_awareness',
    'bgimage',
    $noticeid ? $noticeid : 0,
    ['maxfiles' => 1, 'accepted_types' => ['image']]
);
// Inject the draft item ID so the form's file picker shows existing files.
$mform->set_data(['bgimage' => $bgdraftitemid]);

// Proccess form data.
if ($formdata = $mform->get_data()) {
    if ($formdata->perpetual == 1) {
        $formdata->timestart = 0;
        $formdata->timeend = 0;
    }

    if (!$awareness) {
        // Create new notice.
        helper::create_new_notice($formdata);
    } else {
        // Update notice.
        helper::update_notice($awareness, $formdata);
    }

    redirect($managenoticepage);
} else if ($mform->is_cancelled()) {
    redirect($managenoticepage);
}

// Display form for new notice.
if ($noticeid == 0 && $action == 'create') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('notice:create', 'local_awareness'));
    $mform->display();
    echo $OUTPUT->footer();
    die;
}

// Check notice existence.
if (!$awareness) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('notice:info', 'local_awareness'));
    echo $OUTPUT->notification(get_string('notification:noticedoesnotexist', 'local_awareness'), 'notifyinfo');
    echo $OUTPUT->footer();
    die;
}

switch ($action) {
    case 'dismissed_report':
        $reportpage = new moodle_url('/local/awareness/report/dismissed_report.php', ["noticeid" => $noticeid]);
        redirect($reportpage);
        break;
    case 'acknowledged_report':
        $reportpage = new moodle_url('/local/awareness/report/acknowledged_report.php', ["noticeid" => $noticeid]);
        redirect($reportpage);
        break;
    case 'reset':
        helper::reset_notice($awareness);
        redirect($managenoticepage);
        break;
    case 'disable':
        helper::disable_notice($awareness);
        redirect($managenoticepage);
        break;
    case 'enable':
        helper::enable_notice($awareness);
        redirect($managenoticepage);
        break;
    case 'unconfirmeddelete':
        if (get_config('local_awareness', 'allow_delete')) {
            echo $OUTPUT->header();
            echo $OUTPUT->box_start();
            $thispage->params(['sesskey' => sesskey(), 'action' => 'confirmeddelete', 'noticeid' => $noticeid]);
            $confirmeddelete = new single_button($thispage, get_string('delete'), 'post');
            $cancel = new single_button($managenoticepage, get_string('cancel'), 'get');
            echo $OUTPUT->confirm(
                get_string('confirmation:deletenotice', 'local_awareness', $awareness->get('title')),
                $confirmeddelete,
                $cancel
            );
            echo $OUTPUT->box_end();
            echo $OUTPUT->footer();
        } else {
            redirect($managenoticepage, get_string('notification:nodeleteallowed', 'local_awareness'));
        }
        break;
    case 'confirmeddelete':
        if (get_config('local_awareness', 'allow_delete')) {
            helper::delete_notice($awareness);
            redirect($managenoticepage);
        } else {
            redirect($managenoticepage, get_string('notification:nodeleteallowed', 'local_awareness'));
        }
        break;
    case 'edit':
        if (get_config('local_awareness', 'allow_update')) {
            echo $OUTPUT->header();
            echo $OUTPUT->heading(get_string('notice:view', 'local_awareness'));
            $mform = new notice_form($thispage, $customdata);
            // Re-prepare draft area for bgimage for the edit form.
            $bgdraft = file_get_submitted_draft_itemid('bgimage');
            file_prepare_draft_area(
                $bgdraft,
                context_system::instance()->id,
                'local_awareness',
                'bgimage',
                $noticeid,
                ['maxfiles' => 1, 'accepted_types' => ['image']]
            );
            $mform->set_data(['bgimage' => $bgdraft]);
            $mform->display();
            echo $OUTPUT->footer();
        } else {
            redirect($managenoticepage, get_string('notification:noupdateallowed', 'local_awareness'));
        }
        break;
    default:
        redirect($managenoticepage);
}
