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
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_awareness\form\notice_form;
use local_awareness\helper;
use local_awareness\output\editor_page;
use local_awareness\persistent\awareness;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_awareness_managenotice');
helper::check_manage_capability();

$PAGE->set_context(context_system::instance());
\local_awareness\local\bootstrap::mark_page();
$PAGE->navbar->add(get_string('notice:notice', 'local_awareness'));

$noticeid = optional_param('noticeid', 0, PARAM_INT);
$action = optional_param('action', 'create', PARAM_TEXT);

// Enforce sesskey on any state-changing action to prevent CSRF.
$actionsrequiressesskey = ['disable', 'enable', 'reset', 'unconfirmeddelete', 'confirmeddelete', 'recalculate'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, $actionsrequiressesskey, true)) {
    require_sesskey();
}

$managenoticepage = new moodle_url('/local/awareness/managenotice.php');
$thispage = new moodle_url('/local/awareness/editnotice.php', ['noticeid' => $noticeid, 'action' => $action]);
$PAGE->set_url($thispage);
// The notice_editor module boots notice_form, the live preview, the audience estimator and the
// collision warning. Everything they need is on data attributes in the markup they own.
$PAGE->requires->js_call_amd('local_awareness/notice_editor', 'init');

$awareness = awareness::get_record(['id' => $noticeid]);
$customdata = [
    'persistent' => $awareness,
    'id' => $noticeid,
];
$mform = new notice_form($thispage, $customdata);

// The content editor's draft area is prepared inside notice_form::get_default_data(), which is
// where the notice id is known; preparing it here against item 0 handed the editor an empty area.

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

    /*
     * Told, not blocked. Two repeating notices aimed at the same pages take turns interrupting the
     * same people, which is rarely intended and is invisible while editing either one alone — but
     * it is a legitimate thing to want, so this is a warning on the way out and never a validation
     * error that refuses the save.
     *
     * Resolved BEFORE the save. A new notice has no id yet, so once it is in the table it matches
     * the "every enabled repeating notice" query and, having no id to exclude itself by, reports a
     * collision with itself.
     */
    $clashes = \local_awareness\local\collision::clashes_for(
        (int) ($awareness ? $awareness->get('id') : 0),
        $formdata->pathmatch ?? '',
        (int) ($formdata->resetinterval ?? 0)
    );

    if (!$awareness) {
        // Create new notice.
        $audiencestate = helper::create_new_notice($formdata);
    } else {
        // Update notice.
        $audiencestate = helper::update_notice($awareness, $formdata);
    }


    if (!empty($clashes)) {
        $titles = array_map(function ($notice): string {
            return $notice->get('title');
        }, $clashes);

        redirect(
            $managenoticepage,
            get_string('collision:saved', 'local_awareness', implode(', ', $titles)),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    /*
     * Ranked below the collision warning on purpose: a competing notice is something the author may
     * want to go back and change, a queued estimate is only news. It still has to be said, though —
     * on a site too large to estimate during a request the author is about to land on a list whose
     * audience column shows the previous number, or none, and an empty column reads as "reaches
     * nobody" rather than "not counted yet".
     */
    if ($audiencestate === \local_awareness\audience\notice_audience::STATE_PENDING) {
        redirect(
            $managenoticepage,
            get_string('notice:audience:queued', 'local_awareness', $formdata->title),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    redirect($managenoticepage);
} else if ($mform->is_cancelled()) {
    redirect($managenoticepage);
}

// Capture the moodleform's HTML so the new editor shell can render it inside
// a hidden container; the JS module then walks the form-rows and moves them
// into the cards. Validation, file API, autocompletes and CSRF stay intact.
$rendermoodleform = function (notice_form $mform): array {
    ob_start();
    $mform->display();
    $html = (string) ob_get_clean();
    $formid = '';
    if (preg_match('/<form\b[^>]*\bid="([^"]+)"/', $html, $m)) {
        $formid = $m[1];
    }
    return [$html, $formid];
};

// Display form for new notice.
if ($noticeid == 0 && $action == 'create') {
    [$formhtml, $formid] = $rendermoodleform($mform);
    $output = $PAGE->get_renderer('local_awareness');
    echo $OUTPUT->header();
    echo $output->render_editor_page(new editor_page(null, $formhtml, $formid, $managenoticepage));
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
        $reportpage = new moodle_url('/local/awareness/report/dismissed_systemreport.php', ["noticeid" => $noticeid]);
        redirect($reportpage);
        break;
    case 'acknowledged_report':
        $reportpage = new moodle_url('/local/awareness/report/acknowledged_systemreport.php', ["noticeid" => $noticeid]);
        redirect($reportpage);
        break;
    case 'reset':
        helper::reset_notice($awareness);
        redirect($managenoticepage);
        break;
    case 'recalculate':
        // Forced: the author asked for this number specifically, so an up-to-date hash is not a
        // reason to refuse — the site's population moves under a notice whose filters never change.
        $state = \local_awareness\audience\notice_audience::refresh($awareness, true);
        $pending = $state === \local_awareness\audience\notice_audience::STATE_PENDING;
        redirect(
            $managenoticepage,
            get_string(
                $pending ? 'notice:audience:queued' : 'notice:audience:recalculated',
                'local_awareness',
                $awareness->get('title')
            ),
            null,
            $pending ? \core\output\notification::NOTIFY_INFO : \core\output\notification::NOTIFY_SUCCESS
        );
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
            [$formhtml, $formid] = $rendermoodleform($mform);
            $output = $PAGE->get_renderer('local_awareness');
            echo $OUTPUT->header();
            echo $output->render_editor_page(new editor_page($awareness, $formhtml, $formid, $managenoticepage));
            echo $OUTPUT->footer();
        } else {
            redirect($managenoticepage, get_string('notification:noupdateallowed', 'local_awareness'));
        }
        break;
    default:
        redirect($managenoticepage);
}
