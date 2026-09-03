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
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['all'] = 'All';
$string['audience:btn:calculate'] = 'Calculate reach';
$string['audience:btn:retry'] = 'Try again';
$string['audience:context_restrictions:hint'] = 'These rules narrow when and where the notice appears, but do not change the audience size.';
$string['audience:context_restrictions:title'] = 'Display restrictions';
$string['audience:job_not_found'] = 'Audience job not found.';
$string['audience:reach:label'] = 'Estimated reach';
$string['audience:reach:value'] = '~ {$a} users';
$string['audience:rule:andmore'] = '{$a->names} and {$a->count} more';
$string['audience:rule:cohorts'] = 'Member of selected cohorts';
$string['audience:rule:filter_category'] = 'Course category: {$a}';
$string['audience:rule:filter_competency_rules'] = 'Competency requirement(s)';
$string['audience:rule:filter_course'] = 'Course: {$a}';
$string['audience:rule:filter_format'] = 'Course format: {$a}';
$string['audience:rule:filter_role'] = 'Has selected roles';
$string['audience:rule:filter_theme'] = 'Theme: {$a}';
$string['audience:rule:pathmatch'] = 'On URL path: {$a}';
$string['audience:rule:reqcourse'] = 'Has not completed required course';
$string['audience:rules_too_many'] = 'Too many filter rules to estimate automatically — click "Calculate reach" to run on demand.';
$string['audience:state:auto_pending'] = 'Computing — refreshing as you change the filters…';
$string['audience:state:cached'] = 'Result computed at {$a}.';
$string['audience:state:error'] = 'Estimate failed: {$a}';
$string['audience:state:idle'] = 'Reach has not been calculated yet.';
$string['audience:state:manual_ready'] = 'Click "Calculate reach" when you are ready.';
$string['audience:state:queued'] = 'Calculating in the background…';
$string['audience:state:timeout'] = 'Calculation took longer than expected. Try again.';
$string['audience:state:wholesite'] = 'No audience filter set, so this is every active user on the site. Computed at {$a}.';
$string['audience:summary:cohorts'] = 'Cohorts';
$string['audience:summary:competencies'] = 'Competencies';
$string['audience:summary:courses'] = 'Courses';
$string['audience:summary:role'] = 'Role';
$string['audience:title'] = 'Audience estimate';
$string['awareness:manage'] = 'Manage site notice';
$string['awareness:viewreports'] = 'View awareness reports';
$string['booleanformat:false'] = 'No';
$string['booleanformat:true'] = 'Yes';
$string['button:accept'] = 'Accept';
$string['button:close'] = 'Close';
$string['button:notnow'] = 'Not now';
$string['cachedef_enabled_notices'] = 'A list of enabled notices';
$string['cachedef_notice_view'] = 'A list of viewed notices';
$string['cachedef_site_user_count'] = 'The site user count, which decides whether audience estimates run interactively';
$string['collision:badge'] = 'Competing';
$string['collision:badgetooltip'] = 'This repeating notice reaches the same pages as: {$a}. Only one notice is shown at a time, so they will take turns interrupting the same people.';
$string['collision:live'] = 'Heads up: this repeating notice reaches the same pages as: {$a}. Only one notice is shown at a time, so they will take turns interrupting the same people.';
$string['collision:saved'] = 'Saved. This repeating notice reaches the same pages as: {$a}. Only one notice is shown at a time, so they will take turns interrupting the same people.';
$string['confirmation:deletenotice'] = 'Do you really want to delete the notice "{$a}"';
$string['confirmation:resetnotice'] = 'Ask everyone again about "{$a}"?

Everyone who has already accepted this notice will be shown it again and asked to accept it once more. The acknowledgements already recorded are kept and stay visible in the reports, but they stop counting as current.';
$string['course_search_placeholder'] = 'Type to search courses...';
$string['datasource:acknowledgednotices'] = 'Acknowledged notices';
$string['datasource:allnotices'] = 'All notices';
$string['datasource:dismissednotices'] = 'Dismissed notices';
$string['datasource:linkhistory'] = 'Link click history';
$string['datasource:noticeviews'] = 'Notice views';
$string['download:acknowledged'] = 'Acknowledged - {$a->title} (notice {$a->id})';
$string['download:dismissed'] = 'Dismissed - {$a->title} (notice {$a->id})';
$string['editor:action:preview'] = 'Preview';
$string['editor:nav:howitworks'] = 'How it works';
$string['editor:nav:howitworks:body'] = 'Filters combine by <b>intersection</b> — all must match. Cohorts and individual courses combine by <b>union</b> within their own field.';
$string['editor:preview:empty'] = 'This notice has no content yet.';
$string['editor:preview:title'] = 'Preview';
$string['editor:saved'] = 'Saved {$a}';
$string['editor:section:appearance'] = 'Modal appearance';
$string['editor:section:appearance:desc'] = 'Size and visual fit of the modal window.';
$string['editor:section:audience'] = 'Audience';
$string['editor:section:audience:desc'] = 'Who the notice will be shown to. Filters combine with AND (intersection).';
$string['editor:section:behavior'] = 'Behaviour';
$string['editor:section:behavior:desc'] = 'How the notice appears, repeats and is dismissed.';
$string['editor:section:content'] = 'Notice content';
$string['editor:section:content:desc'] = 'What will be shown in the modal to the user.';
$string['editor:section:filters'] = 'Display restrictions';
$string['editor:section:filters:desc'] = 'Refine where on the platform the notice fires.';
$string['editor:status:blocked'] = 'Published · nobody is seeing it';
$string['editor:status:draft'] = 'Draft · not published';
$string['editor:status:live'] = 'Live · being shown';
$string['editor:subtitle'] = 'Build a contextual modal that will be shown to users when the rules below match.';
$string['editor:title:create'] = 'Create notice';
$string['editor:title:edit'] = 'Edit notice';
$string['editor:unsaved'] = 'Unsaved changes';
$string['editor:warning:window_expired'] = 'This notice stopped displaying on {$a}. Nobody will see it until the expiry date is moved.';
$string['editor:warning:window_inverted'] = 'The start date is on or after the expiry date, so this notice can never display. Fix the dates under Behaviour.';
$string['entity_acknowledgement'] = 'Acknowledgement';
$string['entity_linkhistory'] = 'Link click';
$string['entity_notice'] = 'Notice';
$string['entity_noticeview'] = 'Notice view';
$string['event:acknowledge'] = 'Notice acknowledged';
$string['event:clicklink'] = 'Notice link clicked';
$string['event:create'] = 'Notice created';
$string['event:delete'] = 'Notice deleted';
$string['event:disable'] = 'Notice disabled';
$string['event:dismiss'] = 'Notice dismissed';
$string['event:enable'] = 'Notice enabled';
$string['event:estimateaudience'] = 'Audience estimate requested';
$string['event:reset'] = 'Notice reset';
$string['event:update'] = 'Notice updated';
$string['filter_category'] = 'Category';
$string['filter_competency'] = 'Competencies';
$string['filter_competency_add'] = 'Add competencies';
$string['filter_competency_help'] = 'Filters this notification based on the user’s competency proficiency. This filter only works on course pages (requires course context).

When competencies are selected, each rule checks whether the user is proficient in a given competency within the current course. In the default mode, the user’s proficiency status must exactly match the configuration defined for each rule.

When “Proficient in all” is enabled, the user must be proficient in all selected competencies, regardless of the individual rule settings.';
$string['filter_competency_picker_addselected'] = 'Add selected';
$string['filter_competency_picker_framework'] = 'Competency framework';
$string['filter_competency_picker_loaderror'] = 'The competency list could not be loaded.';
$string['filter_competency_picker_nocompetencies'] = 'No competencies found.';
$string['filter_competency_picker_noframeworks'] = 'No competency frameworks available.';
$string['filter_competency_picker_title'] = 'Select competencies';
$string['filter_competency_proficient'] = 'Proficient';
$string['filter_competency_remove'] = 'Remove';
$string['filter_competency_requireall'] = 'Proficient in all selected competencies';
$string['filter_competency_requireall_help'] = 'When enabled and more than one competency is selected, the notice is shown only if the user is proficient in all selected competencies.';
$string['filter_competency_rules_error'] = 'The selected competencies could not be displayed.';
$string['filter_course'] = 'Courses';
$string['filter_courseformat'] = 'Course format';
$string['filter_role'] = 'Role';
$string['filter_role_context'] = 'Role context';
$string['filter_role_context:category'] = 'Course category';
$string['filter_role_context:course'] = 'Course';
$string['filter_role_context:system'] = 'System';
$string['filter_theme'] = 'Theme';
$string['filters'] = 'Filters';
$string['manage:empty:filtered'] = 'No notices match these filters.';
$string['manage:empty:none'] = 'No notices have been created yet.';
$string['manage:filter:clear'] = 'Clear filters';
$string['manage:filter:name'] = 'Search by name';
$string['manage:filter:nameplaceholder'] = 'Search by name…';
$string['manage:filter:status:all'] = 'All';
$string['manage:filter:status:clash'] = 'Competing';
$string['manage:filter:validity:all'] = 'All';
$string['manage:lede'] = 'Modal notices shown to users when the rules match.';
$string['manage:resultcount'] = 'Notices found: {$a}';
$string['manage:stat:clash'] = 'Competing';
$string['manage:stat:draft'] = 'Drafts';
$string['manage:stat:live'] = 'Active notices';
$string['manage:stat:reach'] = 'Combined reach';
$string['manage:table:caption'] = 'Site notices';
$string['message:audience_ready:body'] = 'The audience estimate for "{$a->title}" has finished. It reaches about {$a->count} users.';
$string['message:audience_ready:subject'] = 'Audience estimate ready: {$a->title}';
$string['messageprovider:audience_estimate_ready'] = 'Audience estimate finished';
$string['modal:checkboxtext'] = 'I have read and understand the notice.';
$string['notice:activefrom'] = 'Active from';
$string['notice:activefrom_help'] = 'The time and date from which the message will be active .';
$string['notice:audience'] = 'Target audience';
$string['notice:audience:cohorts'] = 'Cohorts: {$a}';
$string['notice:audience:computed'] = 'Computed {$a}';
$string['notice:audience:never'] = 'Not calculated';
$string['notice:audience:pending'] = 'Calculating…';
$string['notice:audience:queued'] = 'The audience estimate for "{$a}" is running in the background. You will be notified when it finishes.';
$string['notice:audience:recalculate'] = 'Recalculate audience';
$string['notice:audience:recalculated'] = 'The audience estimate for "{$a}" is up to date.';
$string['notice:audience:stale'] = 'Filters changed since {$a}';
$string['notice:audience:value'] = '~ {$a} users';
$string['notice:behaviour'] = 'Behaviour';
$string['notice:behaviour:none'] = 'No special behaviour';
$string['notice:behaviour:repeat'] = 'Repeats every {$a}';
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
$string['notice:info'] = 'Notice information';
$string['notice:insistence'] = 'Insistence';
$string['notice:insistence:acknowledge'] = 'Must acknowledge';
$string['notice:insistence:blocking'] = 'Blocking';
$string['notice:insistence:informational'] = 'Informational';
$string['notice:insistence_help'] = 'How hard the notice is to get past, and what the reader may do instead of accepting it.

Informational: the reader can dismiss it, including by clicking outside it, and the dismissal is
recorded.

Blocking: it cannot be dismissed by clicking outside it or by pressing Escape. The reader either
accepts it or chooses Not now, and a notice that was not accepted is shown again.

Must acknowledge: as Blocking, and the acknowledgement box must be ticked before Accept becomes
available.

No level logs the reader out.';
$string['notice:modal_dimension_invalid'] = 'Invalid value. Use a number followed by px, %, vw, or vh (e.g. 600px, 80%, 50vw).';
$string['notice:modal_height'] = 'Modal height';
$string['notice:modal_height_help'] = 'Custom height for the notice modal. Accepted formats: pixels (e.g. 400px), percentage (e.g. 70%), or viewport height (e.g. 50vh). Leave empty for default size.';
$string['notice:modal_width'] = 'Modal width';
$string['notice:modal_width_help'] = 'Custom width for the notice modal. Accepted formats: pixels (e.g. 600px), percentage (e.g. 80%), or viewport width (e.g. 50vw). Leave empty for default size.';
$string['notice:notice'] = 'Notice';
$string['notice:pathmatch:anywhere'] = 'Anywhere on the site';
$string['notice:perpetual'] = 'Is perpetual';
$string['notice:perpetual_help'] = 'When set to yes, the notice will always be displayed (unless disabled). If set to no, a date and time range for the notice must be specified';
$string['notice:preview'] = 'Preview the modal';
$string['notice:reqcourse'] = 'Requires course completion';
$string['notice:reqcourse_help'] = 'Show the notice only to users who have not yet completed the selected course. It is an audience rule, not a display frequency: how often the notice reappears is set by the reset interval, and someone who completes the course stops seeing it.';
$string['notice:reset'] = 'Ask everyone again';
$string['notice:resetinterval'] = 'Reset every';
$string['notice:resetinterval_help'] = 'The notice will be displayed to user again once the specified period elapses.';
$string['notice:status'] = 'Status';
$string['notice:status:draft'] = 'Draft';
$string['notice:status:live'] = 'Active';
$string['notice:title'] = 'Title';
$string['notice:validity'] = 'Validity';
$string['notice:validity:current'] = 'Current';
$string['notice:validity:expired'] = 'Expired';
$string['notice:validity:permanent'] = 'Permanent';
$string['notice:validity:scheduled'] = 'Scheduled';
$string['notification:nodeleteallowed'] = 'Notice deletion is not allowed';
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
$string['privacy:metadata:action'] = 'Whether the notice was dismissed (0) or acknowledged (1)';
$string['privacy:metadata:breakdown'] = 'Per-rule breakdown of the audience estimate';
$string['privacy:metadata:criteria'] = 'Audience criteria submitted for the estimate';
$string['privacy:metadata:criteriahash'] = 'Hash of the audience criteria, used to reuse an identical estimate';
$string['privacy:metadata:errormsg'] = 'Error message recorded when the estimate failed';
$string['privacy:metadata:firstname'] = 'First name';
$string['privacy:metadata:hlinkid'] = 'ID of the hyperlink that was clicked';
$string['privacy:metadata:idnumber'] = 'ID number';
$string['privacy:metadata:jobid'] = 'Identifier of the audience estimate job';
$string['privacy:metadata:lastname'] = 'Last name';
$string['privacy:metadata:local_awareness'] = 'Site notices, recording the user who last created or edited each one';
$string['privacy:metadata:local_awareness_ack'] = 'Notice acknowledgement';
$string['privacy:metadata:local_awareness_audience_jobs'] = 'Audience estimate jobs';
$string['privacy:metadata:local_awareness_hlinks_his'] = 'Hyperlink tracking';
$string['privacy:metadata:local_awareness_lastview'] = 'Notice last view';
$string['privacy:metadata:noticeid'] = 'ID of the notice the record refers to';
$string['privacy:metadata:noticetitle'] = 'Title of the notice at the time of the acknowledgement';
$string['privacy:metadata:resultcount'] = 'Number of users the estimate reached';
$string['privacy:metadata:status'] = 'State of the audience estimate job';
$string['privacy:metadata:timecompleted'] = 'Time the estimate finished';
$string['privacy:metadata:timecreated'] = 'Time the record was created';
$string['privacy:metadata:timemodified'] = 'Time the record was last changed';
$string['privacy:metadata:userid'] = 'User ID';
$string['privacy:metadata:usermodified'] = 'ID of the user who last created or edited the notice';
$string['privacy:metadata:username'] = 'Username';
$string['report:acknowledge_desc'] = 'List of users who acknowledged the notice.';
$string['report:acknowledged'] = 'Notices acknowledged for: {$a}';
$string['report:button:ack'] = 'Notice acknowledgement system report';
$string['report:button:dis'] = 'Notice dismiss system report';
$string['report:dismissed'] = 'Notices dismissed for: {$a}';
$string['report:dismissed_desc'] = 'List of users who dismissed the notice.';
$string['report_ack:action'] = 'Action';
$string['report_ack:action_acknowledged'] = 'Acknowledged';
$string['report_ack:action_dismissed'] = 'Dismissed';
$string['report_ack:firstname'] = 'First name';
$string['report_ack:idnumber'] = 'ID number';
$string['report_ack:lastname'] = 'Last name';
$string['report_ack:noticetitle'] = 'Notice title (snapshot)';
$string['report_ack:timecreated'] = 'Date';
$string['report_ack:username'] = 'Username';
$string['report_lh:linktext'] = 'Link text';
$string['report_lh:linkurl'] = 'Link URL';
$string['report_lh:timecreated'] = 'Click date';
$string['report_notice:ack_count'] = 'Acknowledged count';
$string['report_notice:content'] = 'Content';
$string['report_notice:dismiss_count'] = 'Dismissed count';
$string['report_notice:enabled'] = 'Enabled';
$string['report_notice:forcelogout'] = 'Force logout';
$string['report_notice:forcelogout:deprecated'] = 'Force logout no longer has any effect. Use Insistence instead; this column is kept so existing reports still show what the author originally asked for.';
$string['report_notice:insistence'] = 'Insistence';
$string['report_notice:reqack'] = 'Requires acknowledgement';
$string['report_notice:reqcourse'] = 'Requires course completion';
$string['report_notice:resetinterval'] = 'Reset interval';
$string['report_notice:timecreated'] = 'Date created';
$string['report_notice:timeend'] = 'Expiry';
$string['report_notice:timemodified'] = 'Date modified';
$string['report_notice:timestart'] = 'Active from';
$string['report_notice:title'] = 'Notice title';
$string['report_nv:action'] = 'Last action';
$string['report_nv:timecreated'] = 'First seen';
$string['report_nv:timemodified'] = 'Last seen';
$string['setting:allow_delete'] = 'Allow notice deletion';
$string['setting:allow_deletedesc'] = 'Allow notice to be deleted';
$string['setting:allow_update'] = 'Allow notice update';
$string['setting:allow_updatedesc'] = 'Allow notice to be updated';
$string['setting:audience_sync_limit'] = 'Interactive audience estimate limit';
$string['setting:audience_sync_limitdesc'] = 'On sites with at most this many users the audience estimate is interactive: the editor refreshes it as you change the filters, and "Calculate reach" answers immediately. Above it neither happens — the estimate runs only when you ask for it, in the background, and needs cron to be running. Raise it only after timing the estimate on your own site with your heaviest set of filters: it scans one row per user, so the cost grows with the size of the site. Set to 0 to never estimate interactively.';
$string['setting:cleanup_deleted_notice'] = 'Clean up info related to the deleted notice';
$string['setting:cleanup_deleted_noticedesc'] = 'Requires "Allow notice deletion".
If enabled, other details related to the notice being deleted, such as hyperlinks, hyperlinks history, acknowledgement,
user last view will also be deleted';
$string['setting:enabled'] = 'Enabled';
$string['setting:enableddesc'] = 'Enable site notice';
$string['setting:linkhistory_lifetime'] = 'Keep link-click history for';
$string['setting:linkhistory_lifetimedesc'] = 'How long a record of a reader following a link inside a notice is kept. Keep for ever is the default, so upgrading discards nothing; choosing a period starts a nightly purge of anything older.';
$string['setting:managenotice'] = 'Manage notice';
$string['setting:settings'] = 'Settings';
$string['task_estimate_audience'] = 'Estimate a notice audience';
$string['task_purge_audience_jobs'] = 'Purge spent audience estimate jobs';
$string['task_purge_link_history'] = 'Purge old link-click history';
$string['timeformat:resetinterval'] = '%a day(s), %h hour(s), %i minute(s) and %s second(s)';
