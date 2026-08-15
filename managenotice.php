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
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
use core_table\local\filter\filter;
use core_table\local\filter\string_filter;
use local_awareness\helper;
use local_awareness\output\manage_page;
use local_awareness\table\all_notices;
use local_awareness\table\all_notices_filterset;
admin_externalpage_setup('local_awareness_managenotice');
helper::check_manage_capability();

$page = optional_param('page', 0, PARAM_INT);

/*
 * The filters also arrive as URL parameters, so a filtered list can be linked to and survives a
 * reload. The AJAX path carries them in the filterset instead; both end up in the same object.
 */
$filtervalues = [
    'name' => trim(optional_param('name', '', PARAM_TEXT)),
    'status' => optional_param('status', '', PARAM_ALPHA),
    'validity' => optional_param('validity', '', PARAM_ALPHA),
];
if (!in_array($filtervalues['status'], all_notices_filterset::status_values(), true)) {
    $filtervalues['status'] = '';
}
if (!in_array($filtervalues['validity'], all_notices_filterset::validity_values(), true)) {
    $filtervalues['validity'] = '';
}

$thispage = '/local/awareness/managenotice.php';
$editnotice = '/local/awareness/editnotice.php';

\local_awareness\local\bootstrap::mark_page();
$PAGE->set_url(new moodle_url($thispage));
$PAGE->requires->js_call_amd('local_awareness/preview', 'init');
// Core owns the paging links and the refresh of the table region; the plugin only feeds it filters.
$PAGE->requires->js_call_amd('core_table/dynamic', 'init');

$table = new all_notices('all_notices_table', new moodle_url($thispage), $page);

$filterset = new all_notices_filterset();
$filterset->set_join_type(filter::JOINTYPE_ALL);
foreach ($filtervalues as $name => $value) {
    if ($value !== '') {
        $filterset->add_filter(new string_filter($name, filter::JOINTYPE_ANY, [$value]));
    }
}
$table->set_filterset($filterset);

$output = $PAGE->get_renderer('local_awareness');
$tablehtml = $output->render($table);

$newnoticeurl = new moodle_url($editnotice, ['noticeid' => 0, 'sesskey' => sesskey()]);

echo $output->header();
echo $output->render_manage_page(new manage_page($tablehtml, $newnoticeurl->out(false), $filtervalues));
echo $output->footer();
