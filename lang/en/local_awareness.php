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
 * English language file
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['all'] = 'All';
$string['awareness:manage'] = 'Manage site notice';
$string['booleanformat:false'] = 'No';
$string['booleanformat:true'] = 'Yes';
$string['button:accept'] = 'Accept';
$string['button:close'] = 'Close';
$string['cachedef_enabled_notices'] = 'A list of enabled notices';
$string['cachedef_notice_view'] = 'A list of viewed notices';
$string['cachedef_user_notices'] = 'Cached user-specific notices for the current session';
$string['confirmation:deletenotice'] = 'Do you really want to delete the notice "{$a}"';
$string['course_search_placeholder'] = 'Type to search courses...';
$string['event:acknowledge'] = 'acknowledge';
$string['event:create'] = 'create';
$string['event:delete'] = 'delete';
$string['event:disable'] = 'disable';
$string['event:dismiss'] = 'dismiss';
$string['event:enable'] = 'enable';
$string['event:reset'] = 'reset';
$string['event:timecreated'] = 'Time';
$string['event:update'] = 'update';
$string['filter_category'] = 'Category';
$string['filter_course'] = 'Courses';
$string['filter_courseformat'] = 'Course format';
$string['filter_role'] = 'Role';
$string['filter_theme'] = 'Theme';
$string['filters'] = 'Filters';
$string['modal:acceptbtntooltip'] = 'Please tick the above check box.';
$string['modal:checkboxtext'] = 'I have read and understand the notice (closing this notice will log you off this site).';
$string['modal:checkboxtext_logout'] = 'I have read and understand the notice (closing this notice will log you off this site).';
$string['modal:checkboxtext_nologout'] = 'I have read and understand the notice.';
$string['notice:activefrom'] = 'Active from';
$string['notice:activefrom_help'] = 'The time and date from which the message will be active .';
$string['notice:bgimage'] = 'Background image';
$string['notice:bgimage_help'] = 'Upload an image to be displayed as the background of the notice modal. The image will cover the entire modal content area.';
$string['notice:cohort'] = 'Cohort';
$string['notice:cohort:all'] = 'All users';
$string['notice:content'] = 'Content';
$string['notice:create'] = 'Create new notice';
$string['notice:delete'] = 'Delete notice';
$string['notice:disable'] = 'Disable notice';
$string['notice:enable'] = 'Enable notice';
$string['notice:expiry'] = 'Expiry';
$string['notice:expiry_help'] = 'The time and date the messages expires and will not be shown to users anymore.';
$string['notice:forcelogout'] = 'Force logout';
$string['notice:forcelogout_help'] = 'If enabled, the user will be logged out after closing the notice. This setting does not affect the site administrator. ';
$string['notice:hlinkcount'] = 'Hyperlink counts';
$string['notice:info'] = 'Notice information';
$string['notice:modal_dimension_invalid'] = 'Invalid value. Use a number followed by px, %, vw, or vh (e.g. 600px, 80%, 50vw).';
$string['notice:modal_height'] = 'Modal height';
$string['notice:modal_height_help'] = 'Custom height for the notice modal. Accepted formats: pixels (e.g. 400px), percentage (e.g. 70%), or viewport height (e.g. 50vh). Leave empty for default size.';
$string['notice:modal_width'] = 'Modal width';
$string['notice:modal_width_help'] = 'Custom width for the notice modal. Accepted formats: pixels (e.g. 600px), percentage (e.g. 80%), or viewport width (e.g. 50vw). Leave empty for default size.';
$string['notice:notice'] = 'Notice';
$string['notice:outsideclick'] = 'Dismiss on outside click';
$string['notice:outsideclick_help'] = 'If enabled, the user can close the notice by clicking outside the modal. If disabled, the user must use the close button or accept button.';
$string['notice:perpetual'] = 'Is perpetual';
$string['notice:perpetual_help'] = 'When set to yes, the notice will always be displayed (unless disabled). If set to no, a date and time range for the notice must be specified';
$string['notice:redirectmsg'] = 'Required Course not completed. Not allowed to submit assignment';
$string['notice:report'] = 'View report';
$string['notice:reqack'] = 'Requires acknowledgement';
$string['notice:reqack_help'] = 'If enabled, the user will need to accept the notice before they can continue to use the LMS site.
If the user does not accept the notice, he/she will be logged out of the site.';
$string['notice:reqcourse'] = 'Requires course completion';
$string['notice:reqcourse_help'] = 'If selected, the user will see the notice till the course is completed.';
$string['notice:reset'] = 'Reset notice';
$string['notice:resetinterval'] = 'Reset every';
$string['notice:resetinterval_help'] = 'The notice will be displayed to user again once the specified period elapses.';
$string['notice:timemodified'] = 'Time modified';
$string['notice:title'] = 'Title';
$string['notice:view'] = 'View notice';
$string['notification:noack'] = 'There is no acknowledgment for this notice';
$string['notification:nodeleteallowed'] = 'Notice deletion is not allowed';
$string['notification:nodis'] = 'There is no dismission for this notice';
$string['notification:noticedoesnotexist'] = 'The notice does not exist';
$string['notification:noupdateallowed'] = 'Notice update is not allowed';
$string['pathmatch'] = 'Apply to URL match';
$string['pathmatch_help'] = 'Notices will be displayed on any page whose URL matches this value.

You can use the % character as a wildcard to mean anything.
Some example values include:

* /my/% - to match the Dashboard
* /course/view.php?id=2 - to match a specific course
* /mod/forum/view.php% - to match the forum discussion list
* /user/profile.php% - to match the user profile page

If you wish to display a notice on the site home, you can use the value: "FRONTPAGE".';
$string['pluginname'] = 'Awareness';
$string['privacy:metadata:firstname'] = 'First name';
$string['privacy:metadata:idnumber'] = 'ID number';
$string['privacy:metadata:lastname'] = 'Last name';
$string['privacy:metadata:local_awareness_ack'] = 'Notice acknowledgement';
$string['privacy:metadata:local_awareness_hlinks_his'] = 'Hyperlink tracking';
$string['privacy:metadata:local_awareness_lastview'] = 'Notice last view';
$string['privacy:metadata:userid'] = 'User ID';
$string['privacy:metadata:username'] = 'Username';
$string['report:acknowledge_desc'] = 'List of users who acknowledged the notice.';
$string['report:acknowledged'] = 'notice_acknowledged_{$a}';
$string['report:button:ack'] = 'Notice acknowledgement report';
$string['report:button:dis'] = 'Notice dismiss report';
$string['report:dismissed'] = 'notice_dismissed_{$a}';
$string['report:dismissed_desc'] = 'List of users who dismissed the notice.';
$string['report:timecreated_server'] = 'Server time';
$string['report:timecreated_spreadsheet'] = 'Spreadsheet timestamp';
$string['report:timeformat:sortable'] = '%Y-%m-%d %H:%M:%S';
$string['setting:allow_delete'] = 'Allow notice deletion';
$string['setting:allow_deletedesc'] = 'Allow notice to be deleted';
$string['setting:allow_update'] = 'Allow notice update';
$string['setting:allow_updatedesc'] = 'Allow notice to be updated';
$string['setting:cleanup_deleted_notice'] = 'Clean up info related to the deleted notice';
$string['setting:cleanup_deleted_noticedesc'] = 'Requires "Allow notice deletion".
If enabled, other details related to the notice being deleted, such as hyperlinks, hyperlinks history, acknowledgement,
user last view will also be deleted';
$string['setting:enabled'] = 'Enabled';
$string['setting:enableddesc'] = 'Enable site notice';
$string['setting:managenotice'] = 'Manage notice';
$string['setting:settings'] = 'Settings';
$string['timeformat:resetinterval'] = '%a day(s), %h hour(s), %i minute(s) and %s second(s)';
