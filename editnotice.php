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
use local_awareness\local\author_scope;
use local_awareness\output\editor_page;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$courseid = optional_param('courseid', 0, PARAM_INT);
$noticeid = optional_param('noticeid', 0, PARAM_INT);
$action = optional_param('action', 'create', PARAM_TEXT);

/*
 * Two modes, one page, and two scopes to tell apart. The URL's scope is gated FIRST, before the
 * notice is even looked up: in course mode the page knows its course from the URL, so a caller
 * without the course capability is refused before learning whether an id exists. The notice's
 * own scope is gated second, below, once it is resolved — and it wins: a page never overrides
 * where a stored notice belongs, whatever its URL says.
 */
$requested = author_scope::for_request(null, $courseid);
if ($requested->is_site()) {
    admin_externalpage_setup('local_awareness_managenotice');
    helper::require_author($requested, 'manage');
    $PAGE->set_context(context_system::instance());
} else {
    $course = get_course($requested->get_courseid());
    require_login($course);
    helper::require_author($requested, 'manage');
    $PAGE->set_context($requested->context());
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('notice:notice', 'local_awareness'));
    $PAGE->set_heading(format_string($course->fullname, true, ['context' => $requested->context()]));
}
\local_awareness\local\bootstrap::mark_page();
$PAGE->navbar->add(get_string('notice:notice', 'local_awareness'));

// Enforce sesskey on any state-changing action to prevent CSRF.
$actionsrequiressesskey = [
    'disable', 'enable', 'unconfirmedreset', 'confirmedreset',
    'unconfirmeddelete', 'confirmeddelete', 'recalculate',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($action, $actionsrequiressesskey, true)) {
    require_sesskey();
}

$requestedparams = $requested->is_site() ? [] : ['courseid' => $requested->get_courseid()];
$managenoticepage = new moodle_url('/local/awareness/managenotice.php', $requestedparams);
$thispage = new moodle_url('/local/awareness/editnotice.php', ['noticeid' => $noticeid, 'action' => $action] + $requestedparams);
$PAGE->set_url($thispage);
// The notice_editor module boots notice_form, the live preview, the audience estimator and the
// collision warning. Everything they need is on data attributes in the markup they own.
$PAGE->requires->js_call_amd('local_awareness/notice_editor', 'init');

/*
 * Resolved before the form is built, and refused rather than treated as "new": the create-or-update
 * branch below keys on whether a notice was found, and an id that no longer exists must not look
 * like no id at all. A redirect rather than the resolver's own error page, because the likeliest
 * cause is another administrator deleting the notice while this one had it open. A notice outside
 * this caller's authority gets the same redirect and the same message: the resolver folds the two
 * refusals into one, so an id cannot be probed for existence across scopes.
 */
try {
    $awareness = helper::resolve_notice_as_author($noticeid, 'manage');
} catch (moodle_exception $e) {
    if ($e->errorcode !== 'notification:noticedoesnotexist') {
        throw $e;
    }
    redirect(
        $managenoticepage,
        get_string('notification:noticedoesnotexist', 'local_awareness'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
// The notice's scope wins over the URL's; for a new notice they are the same thing. The resolver
// above has already decided the existing notice is this caller's to manage.
$scope = author_scope::for_request($awareness, $courseid);
if ($awareness === null) {
    helper::require_author($scope, 'manage');
}
$scopeparams = $scope->is_site() ? [] : ['courseid' => $scope->get_courseid()];
$managenoticepage = new moodle_url('/local/awareness/managenotice.php', $scopeparams);
$thispage = new moodle_url('/local/awareness/editnotice.php', ['noticeid' => $noticeid, 'action' => $action] + $scopeparams);

$customdata = [
    'persistent' => $awareness,
    'id' => $noticeid,
    'scope' => $scope,
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
    /*
     * Said out loud, not just refused. helper::update_notice() now returns early when the setting
     * is off, which closes the hole but leaves the author staring at a list that did not change;
     * this is the same message the delete path already gives for its own setting.
     */
    if ($awareness && !get_config('local_awareness', 'allow_update')) {
        redirect($managenoticepage, get_string('notification:noupdateallowed', 'local_awareness'));
    }

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
        // Create new notice, in the scope this page is for.
        $audiencestate = helper::create_new_notice($formdata, $scope);
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

// Capture the moodleform's HTML so the editor shell can render it in place. Validation, the file
// API, autocompletes and CSRF all stay with the form; the shell only wraps it.
$rendermoodleform = function (notice_form $mform): string {
    ob_start();
    $mform->display();

    return (string) ob_get_clean();
};

// Display form for new notice.
if ($noticeid == 0 && $action == 'create') {
    $formhtml = $rendermoodleform($mform);
    $output = $PAGE->get_renderer('local_awareness');
    echo $OUTPUT->header();
    echo $output->render_editor_page(new editor_page(null, $formhtml, $scope));
    echo $OUTPUT->footer();
    die;
}

/*
 * No notice and not the create page: a URL shape the manage table never produces (every action
 * link carries a real id), so a silent return to the list is all it needs. An id that named a
 * deleted notice was refused above, with its message.
 */
if (!$awareness) {
    redirect($managenoticepage);
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
    case 'unconfirmedreset':
        /*
         * Confirmed like a delete, because it is closer to one than its old name suggested. Reset
         * saves the notice, which moves timemodified, which is what every recorded interaction is
         * judged against - so it asks the whole audience again and every acceptance on record
         * stops counting as current. The rows survive; their standing does not. The old label,
         * "Reset notice", said none of that and the action fired on a single click.
         */
        echo $OUTPUT->header();
        echo $OUTPUT->box_start();
        $thispage->params(['sesskey' => sesskey(), 'action' => 'confirmedreset', 'noticeid' => $noticeid]);
        $confirmedreset = new single_button($thispage, get_string('notice:reset', 'local_awareness'), 'post');
        $cancel = new single_button($managenoticepage, get_string('cancel'), 'get');
        echo $OUTPUT->confirm(
            get_string('confirmation:resetnotice', 'local_awareness', $awareness->get('title')),
            $confirmedreset,
            $cancel
        );
        echo $OUTPUT->box_end();
        echo $OUTPUT->footer();
        break;
    case 'confirmedreset':
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
            $formhtml = $rendermoodleform($mform);
            $output = $PAGE->get_renderer('local_awareness');
            echo $OUTPUT->header();
            echo $output->render_editor_page(new editor_page($awareness, $formhtml, $scope));
            echo $OUTPUT->footer();
        } else {
            redirect($managenoticepage, get_string('notification:noupdateallowed', 'local_awareness'));
        }
        break;
    default:
        redirect($managenoticepage);
}
