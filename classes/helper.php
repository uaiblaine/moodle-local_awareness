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

namespace local_awareness;

use local_awareness\persistent\awareness;
use local_awareness\persistent\noticelink;
use local_awareness\persistent\linkhistory;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\noticeview;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');

/**
 * Helper class to create, retrieve, manage notices
 *
 * @package local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Perform all required manipulations with content.
     *
     * @param \local_awareness\persistent\awareness $awareness Notice.
     */
    public static function process_content(awareness $awareness) {
        $draftitemid = file_get_submitted_draft_itemid('content');
        $content = file_save_draft_area_files(
            $draftitemid,
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $awareness->get('id'),
            self::get_file_editor_options(),
            $awareness->get('content')
        );

        $content = self::update_hyperlinks($awareness, $content);
        $awareness->set('content', $content);
    }

    /**
     * Create new notice
     * @param \stdClass $data form data
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \core\invalid_persistent_exception
     * @throws \required_capability_exception
     */
    public static function create_new_notice(\stdClass $data) {
        self::check_manage_capability();

        // Pack filter values.
        $filters = [];
        $filterfields = ['filter_role', 'filter_category', 'filter_course', 'filter_format', 'filter_theme'];
        foreach ($filterfields as $field) {
            if (isset($data->$field)) {
                $val = $data->$field;
                // Autocomplete may return special markers when nothing is selected.
                if (is_array($val)) {
                    $val = array_filter($val, function ($v) {
                        return $v !== '' && $v !== '_qf__force_multiselect_submission';
                    });
                    $val = array_values($val);
                }
                $filters[$field] = $val;
                unset($data->$field);
            }
        }
        $data->filtervalues = json_encode($filters);

        // Create new notice.
        self::sanitise_data($data);
        $awareness = awareness::create_new_notice($data);

        self::process_content($awareness);
        awareness::update_notice_content($awareness, $awareness->get('content'));

        // Process background image.
        self::process_bgimage($awareness);

        // Log created event.
        $params = [
            'context' => \context_system::instance(),
            'objectid' => $awareness->get('id'),
            'relateduserid' => $awareness->get('usermodified'),
        ];
        $event = \local_awareness\event\awareness_created::create($params);
        $event->trigger();
    }

    /**
     * Update existing notice.
     * @param awareness $awareness site notice persistent
     * @param \stdClass $data form data
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     * @throws \required_capability_exception
     */
    public static function update_notice(awareness $awareness, \stdClass $data) {
        self::check_manage_capability();

        // Pack filter values.
        $filters = [];
        $filterfields = ['filter_role', 'filter_category', 'filter_course', 'filter_format', 'filter_theme'];
        foreach ($filterfields as $field) {
            if (isset($data->$field)) {
                $val = $data->$field;
                // Autocomplete may return special markers when nothing is selected.
                if (is_array($val)) {
                    $val = array_filter($val, function ($v) {
                        return $v !== '' && $v !== '_qf__force_multiselect_submission';
                    });
                    $val = array_values($val);
                }
                $filters[$field] = $val;
                unset($data->$field);
            }
        }
        $data->filtervalues = json_encode($filters);

        self::sanitise_data($data);
        awareness::update_notice_data($awareness, $data);

        self::process_content($awareness);
        awareness::update_notice_content($awareness, $awareness->get('content'));

        // Process background image.
        self::process_bgimage($awareness);

        // Log updated event.
        $params = [
            'context' => \context_system::instance(),
            'objectid' => $awareness->get('id'),
            'relateduserid' => $awareness->get('usermodified'),
        ];
        $event = \local_awareness\event\awareness_updated::create($params);
        $event->trigger();
    }

    /**
     * Sanitise submitted data before creating or updating a site notice.
     *
     * @param \stdClass $data
     */
    private static function sanitise_data(\stdClass $data) {
        foreach ((array) $data as $key => $value) {
            if (!key_exists($key, awareness::properties_definition())) {
                unset($data->$key);
            }
        }
    }

    /**
     * Extract hyperlink from notice content.
     *
     * @param awareness $notice
     * @param string $content notice content
     * @return string
     */
    private static function update_hyperlinks(awareness $notice, string $content): string {
        // Replace file URLs before processing.
        $content = file_rewrite_pluginfile_urls(
            $content,
            'pluginfile.php',
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $notice->get('id')
        );

        // Extract hyperlinks from the content of the notice, which is then used for link clicked tracking.
        $dom = new \DOMDocument();
        $content = format_text($content, FORMAT_HTML, ['noclean' => true]);
        $content = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_clear_errors();
        // Current links in the notice.
        $currentlinks = noticelink::get_notice_link_records($notice->get('id'));
        $newlinks = [];

        foreach ($dom->getElementsByTagName('a') as $node) {
            $link = new \stdClass();
            $link->noticeid = $notice->get('id');
            $link->text = trim($node->nodeValue);
            $link->link = trim($node->getAttribute("href"));

            // Create new or reuse link.
            $linkpersistent = noticelink::create_new_link($link);
            $linkid = $linkpersistent->get('id');
            $newlinks[$linkid] = $linkpersistent;

            // ID to use for link tracking in javascript.
            $node->setAttribute('data-linkid', $linkid);
            $node->setAttribute('target', '_blank');
        }

        // Clean up unused links.
        $unusedlinks = array_diff_key($currentlinks, $newlinks);
        noticelink::delete_links(array_keys($unusedlinks));

        // New content of the notice (included link ids).
        $newcontent = $dom->saveHTML();
        return $newcontent;
    }

    /**
     * Reset a notice
     *
     * @param awareness $notice
     * @return void
     */
    public static function reset_notice(awareness $notice): void {
        self::check_manage_capability();
        try {
            $notice = new awareness($notice->get('id'));
            $notice->update();

            // Log reset event.
            $params = [
                'context' => \context_system::instance(),
                'objectid' => $notice->get('id'),
                'relateduserid' => $notice->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_reset::create($params);
            $event->trigger();
        } catch (\Exception $e) {
            \core\notification::error($e->getMessage());
        }
    }

    /**
     * Enable a notice
     *
     * @param awareness $notice
     * @return void
     */
    public static function enable_notice(awareness $notice): void {
        self::check_manage_capability();
        try {
            $notice->set('enabled', 1);
            $notice->update();

            // Log enabled event.
            $params = [
                'context' => \context_system::instance(),
                'objectid' => $notice->get('id'),
                'relateduserid' => $notice->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_updated::create($params);
            $event->trigger();
        } catch (\Exception $e) {
            \core\notification::error($e->getMessage());
        }
    }

    /**
     * Disable a notice
     *
     * @param awareness $notice
     * @return void
     */
    public static function disable_notice(awareness $notice): void {
        self::check_manage_capability();
        try {
            $notice->set('enabled', 0);
            $notice->update();

            // Log disable event.
            $params = [
                'context' => \context_system::instance(),
                'objectid' => $notice->get('id'),
                'relateduserid' => $notice->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_updated::create($params);
            $event->trigger();
        } catch (\Exception $e) {
            \core\notification::error($e->getMessage());
        }
    }

    /**
     * Delete a notice
     *
     * @param awareness $notice
     * @return void
     */
    public static function delete_notice(awareness $notice): void {
        self::check_manage_capability();
        if (!get_config('local_awareness', 'allow_delete')) {
            return;
        }

        $oldid = $notice->get('id');
        $notice->delete();
        $params = [
            'context' => \context_system::instance(),
            'objectid' => $oldid,
            'relateduserid' => $notice->get('usermodified'),
        ];
        $event = \local_awareness\event\awareness_deleted::create($params);
        $event->trigger();

        if (!get_config('local_awareness', 'cleanup_deleted_notice')) {
            return;
        }
        acknowledgement::delete_notice_acknowledgement($oldid);
        noticeview::delete_notice_view($oldid);
        $noticelinks = noticelink::get_notice_link_records($oldid);
        if (!empty($noticelinks)) {
            linkhistory::delete_link_history(array_keys($noticelinks));
            noticelink::delete_notice_links($oldid);
        }
    }

    /**
     * Built Audience options based on site cohorts.
     * @return array
     * @throws \coding_exception
     */
    public static function built_cohorts_options() {
        $options = [];
        $cohorts = cohort_get_all_cohorts(0, 0);
        foreach ($cohorts['cohorts'] as $cohort) {
            $options[$cohort->id] = $cohort->name;
        }
        return $options;
    }

    /**
     * Get a notice
     *
     * @param int $noticeid notice id
     * @return bool|\stdClass
     */
    public static function retrieve_notice(int $noticeid) {
        $awareness = awareness::get_record(['id' => $noticeid]);
        if ($awareness) {
            return $awareness->to_record();
        } else {
            return false;
        }
    }

    /**
     * Retrieve notices applied to user.
     * @param string $pageurl The current page URL path (from JS). Empty means skip path/filter checks.
     * @param int $courseid The current course ID (from JS). 0 means not on a course page.
     * @return awareness[] Array of awareness instances
     * @throws \dml_exception
     */
    public static function retrieve_user_notices(string $pageurl = '', int $courseid = 0): array {
        global $DB, $USER;

        $notices = awareness::get_enabled_notices();

        if (empty($notices)) {
            return [];
        }

        // Only load at login time.
        if (!isset($USER->viewednotices)) {
            self::load_viewed_notices();
        }
        /*
         * Check for updated notice
         * Exclude it from viewed notices if it is updated (based on timemodified)
         */
        $viewednotices = $USER->viewednotices;
        foreach ($viewednotices as $noticeid => $data) {
            // The notice is disabled during the current session.
            if (!isset($notices[$noticeid])) {
                continue;
            }
            $notice = $notices[$noticeid];
            $dissmised = $data['action'] == acknowledgement::ACTION_DISMISSED;
            if (
                // Notice has been updated/reset/enabled.
                $data['timeviewed'] < $notice->get('timemodified')
                // The reset interval has been past.
                || (($notice->get('resetinterval') > 0) && ($data['timeviewed'] + $notice->get('resetinterval') < time()))
                // The previous action is 'dismiss', so still require acknowledgement.
                || ($dissmised && $notice->get('reqack') == true)
                // The action is 'dismiss' and forced to be logged out, still show it (admins are special).
                || ($dissmised && $notice->get('forcelogout') == true) && !is_siteadmin()
            ) {
                unset($USER->viewednotices[$noticeid]);
            }
        }
        $notices = array_filter(
            array_diff_key($notices, $USER->viewednotices),
            function (awareness $notice): bool {
                $now = time();
                $isperpetual = $notice->get('timestart') == 0 && $notice->get('timeend') == 0;
                $isinactivewindow = $now >= $notice->get('timestart') && $now < $notice->get('timeend');
                return $isperpetual || (!$isperpetual && $isinactivewindow);
            }
        );

        $usernotices = $notices;
        if (!empty($notices)) {
            $checkcohorts = false;
            $checkcompletion = false;

            foreach ($notices as $id => $notice) {
                // Only run path/filter checks when called from AJAX with actual page URL.
                // When called from extend_navigation (no pageurl), skip these checks
                // to ensure JS is loaded — AJAX will do the definitive filtering.
                if (!empty($pageurl)) {
                    // Check Path Match (using the URL passed from JavaScript).
                    if (!self::check_path_match($notice->get('pathmatch') ?? '', $pageurl)) {
                        unset($usernotices[$id]);
                        continue;
                    }

                    // Check Filters (using courseid for course context detection).
                    if (!self::check_filters($notice->get('filtervalues'), $pageurl, $courseid)) {
                        unset($usernotices[$id]);
                        continue;
                    }
                }

                if (!empty($notice->get('cohorts'))) {
                    $checkcohorts = true;
                }
                if ($notice->get('reqcourse') > 0) {
                    $checkcompletion = true;
                }
            }

            // Filter out notices by cohorts.
            if ($checkcohorts) {
                $usercohorts = cohort_get_user_cohorts($USER->id);
                foreach ($notices as $notice) {
                    $cohorts = $notice->get('cohorts');
                    if (!empty($cohorts) && !array_intersect($cohorts, array_keys($usercohorts))) {
                        unset($usernotices[$notice->get('id')]);
                    }
                }
            }

            // Filter out notices by course completion.
            if ($checkcompletion) {
                foreach ($notices as $notice) {
                    if ($notice->get('reqcourse') > 0) {
                        if ($course = $DB->get_record('course', ['id' => $notice->get('reqcourse')])) {
                            $completion = new \completion_info($course);
                            if ($completion->is_course_complete($USER->id)) {
                                unset($usernotices[$notice->get('id')]);
                            }
                        }
                    }
                }
            }
        }

        return $usernotices;
    }

    /**
     * Load viewed notices of current user.
     * @throws \dml_exception
     */
    private static function load_viewed_notices() {
        global $USER;
        $records = noticeview::get_user_viewed_notice_records();
        $USER->viewednotices = [];
        foreach ($records as $record) {
            $USER->viewednotices[$record->id] = ["timeviewed" => $record->timemodified, 'action' => $record->action];
        }
    }

    /**
     * Record the latest interaction with the notice of a user.
     *
     * @param \local_awareness\persistent\awareness $notice Notice instance.
     * @param string $action Action.
     */
    private static function add_to_viewed_notices(awareness $notice, string $action) {
        global $USER;
        // Add to viewed notices.
        $noticeview = noticeview::add_notice_view($notice->get('id'), $USER->id, $action);
        $USER->viewednotices[$notice->get('id')] = ['timeviewed' => $noticeview->get('timemodified'), 'action' => $action];
    }

    /**
     * Create new acknowledgement record.
     *
     * @param awareness $notice
     * @param string $action dismissed or acknowledged
     *
     * @return \core\persistent
     */
    private static function create_new_acknowledge_record(awareness $notice, string $action) {
        global $USER;

        // New record.
        $data = new \stdClass();
        $data->userid = $USER->id;
        $data->username = $USER->username;
        $data->firstname = $USER->firstname;
        $data->lastname = $USER->lastname;
        $data->idnumber = $USER->idnumber;
        $data->noticeid = $notice->get('id');
        $data->noticetitle = $notice->get('title');
        $data->action = $action;
        $persistent = new acknowledgement(0, $data);
        return $persistent->create();
    }

    /**
     * Dismiss the notice
     *
     * @param awareness $notice
     * @return array
     */
    public static function dismiss_notice(awareness $notice): array {
        global $USER;

        $userid = $USER->id;

        $result = [];
        // Check if require acknowledgement.
        if ($notice->get('reqack')) {
            // Record dismiss action.
            self::create_new_acknowledge_record($notice, acknowledgement::ACTION_DISMISSED);

            // Log dismissed event.
            $params = [
                'context' => \context_system::instance(),
                'objectid' => $notice->get('id'),
                'relateduserid' => $userid,
            ];
            $event = \local_awareness\event\awareness_dismissed::create($params);
            $event->trigger();
        }

        // Mark notice as viewed.
        self::add_to_viewed_notices($notice, acknowledgement::ACTION_DISMISSED);

        if ((!is_siteadmin() && $notice->get('forcelogout'))) {
            require_logout();
            $loginpage = new \moodle_url("/login/index.php");
            $result['redirecturl'] = $loginpage->out();
        }

        $result['status'] = true;
        return $result;
    }

    /**
     * Acknowledge the notice.
     *
     * @param awareness $notice
     * @return array
     */
    public static function acknowledge_notice(awareness $notice): array {
        global $USER;

        $result = ['status' => true];
        // Check if the notice has been acknowledged by the user in another browser.
        if (self::check_if_already_acknowledged_by_user($notice, $USER->id)) {
            return $result;
        }

        // Record Acknowledge action.
        $persistent = self::create_new_acknowledge_record($notice, acknowledgement::ACTION_ACKNOWLEDGED);
        if ($persistent) {
            // Mark notice as viewed.
            self::add_to_viewed_notices($notice, acknowledgement::ACTION_ACKNOWLEDGED);
            // Log acknowledged event.
            $params = [
                'context' => \context_system::instance(),
                'objectid' => $notice->get('id'),
                'relateduserid' => $persistent->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_acknowledged::create($params);
            $event->trigger();
        } else {
            $result['status'] = false;
        }

        if (!is_siteadmin() && $notice->get('forcelogout')) {
            require_logout();
            $loginpage = new \moodle_url("/login/index.php");
            $result['redirecturl'] = $loginpage->out();
        }

        return $result;
    }

    /**
     * Track user interaction with the hyperlink
     * @param int $linkid link ID
     * @return array
     */
    public static function track_link(int $linkid) {
        global $USER;
        $data = new \stdClass();
        $data->hlinkid = $linkid;
        $data->userid = $USER->id;
        $persistent = new linkhistory(0, $data);
        $persistent->create();

        $result = [];
        $result['status'] = true;
        return $result;
    }

    /**
     * Format date interval.
     *
     * @param string $time Time.
     * @return string
     */
    public static function format_interval_time(string $time): string {
        // Datetime for 01/01/1970.
        $datefrom = new \DateTime("@0");
        // Datetime for 01/01/1970 after the specified time (in seconds).
        $dateto = new \DateTime("@$time");
        // Format the date interval.
        return $datefrom->diff($dateto)->format(get_string('timeformat:resetinterval', 'local_awareness'));
    }

    /**
     * Format boolean value
     *
     * @param bool $value boolean
     * @return string
     */
    public static function format_boolean(bool $value): string {
        if ($value) {
            return get_string('booleanformat:true', 'local_awareness');
        } else {
            return get_string('booleanformat:false', 'local_awareness');
        }
    }

    /**
     * Get audience name from the audience options.
     *
     * @param int $cohortid Cohort id
     * @return string
     */
    public static function get_cohort_name(int $cohortid): string {
        if ($cohortid == 0) {
            return get_string('notice:cohort:all', 'local_awareness');
        }

        $cohorts = self::built_cohorts_options();
        return $cohorts[$cohortid];
    }

    /**
     * Get course name
     * @param int $courseid course id
     * @return mixed
     * @throws \coding_exception
     */
    public static function get_course_name(int $courseid): string {
        global $DB;

        if ($courseid == 0) {
            return get_string('booleanformat:false', 'local_awareness');
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course) {
            return $course->fullname;
        } else {
            return '-';
        }
    }

    /**
     * Return all courses as an options array suitable for autocomplete elements.
     * Excludes the site course (id=1). Sorted alphabetically by fullname.
     *
     * @return array  [id => fullname, ...]
     * @throws \dml_exception
     */
    public static function get_all_courses_options(): array {
        global $DB;
        $courses = $DB->get_records_select(
            'course',
            'id <> :siteid',
            ['siteid' => SITEID],
            'fullname ASC',
            'id, fullname'
        );
        $options = [];
        foreach ($courses as $course) {
            $options[$course->id] = $course->fullname;
        }
        return $options;
    }


    /**
     * Check capability.
     * @throws \required_capability_exception
     * @throws \dml_exception
     */
    public static function check_manage_capability() {
        $syscontext = \context_system::instance();
        require_capability('local/awareness:manage', $syscontext);
    }

    /**
     * Check if notice has already been acknowledged by a user.
     *
     * @param awareness $notice
     * @param int $userid
     *
     * @return bool
     */
    private static function check_if_already_acknowledged_by_user(awareness $notice, int $userid): bool {
        global $USER;
        $latestview = noticeview::get_record(['noticeid' => $notice->get('id'), 'userid' => $userid]);
        if (empty($latestview)) {
            return false;
        }

        $latestview = $latestview->to_record();
        $notice = $notice->to_record();
        if (
            // Notice has been updated/reset/enabled.
            $latestview->timemodified < $notice->timemodified
            // The reset interval has been past.
            || (($notice->resetinterval > 0) && ($latestview->timemodified + $notice->resetinterval < time()))
            // The previous action is 'dismiss', so still require acknowledgement.
            || ($latestview->action == acknowledgement::ACTION_DISMISSED && $notice->reqack == true)
        ) {
            return false;
        }
        $USER->viewednotices[$notice->id] = ['timeviewed' => $latestview->timemodified, 'action' => $latestview->action];
        return true;
    }

    /**
     * Return options for file editor.
     * @return array
     */
    public static function get_file_editor_options(): array {
        global $CFG;

        return [
            'subdirs' => true,
            'maxbytes' => $CFG->maxbytes,
            'maxfiles' => -1, // Unlimited files.
            'context' => \context_system::instance(),
            'trusttext' => true,
            'class' => 'noticecontent',
        ];
    }

    /**
     * Process and save background image from file picker draft area.
     *
     * @param awareness $awareness Notice.
     */
    public static function process_bgimage(awareness $awareness) {
        $draftitemid = file_get_submitted_draft_itemid('bgimage');
        if ($draftitemid) {
            file_save_draft_area_files(
                $draftitemid,
                \context_system::instance()->id,
                'local_awareness',
                'bgimage',
                $awareness->get('id'),
                ['maxfiles' => 1, 'accepted_types' => ['image']]
            );
            // Mark that this notice has a background image.
            $fs = get_file_storage();
            $files = $fs->get_area_files(
                \context_system::instance()->id,
                'local_awareness',
                'bgimage',
                $awareness->get('id'),
                'id',
                false
            );
            $hasbgimage = !empty($files) ? 1 : 0;
            if ($awareness->get('bgimage') != $hasbgimage) {
                $awareness->set('bgimage', $hasbgimage);
                $awareness->update();
            }
        }
    }

    /**
     * Get the URL for a notice's background image.
     *
     * @param int $noticeid Notice ID.
     * @return string URL or empty string.
     */
    public static function get_bgimage_url(int $noticeid): string {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'local_awareness',
            'bgimage',
            $noticeid,
            'id',
            false
        );
        if (!empty($files)) {
            $file = reset($files);
            $url = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            );
            return $url->out();
        }
        return '';
    }

    /**
     * Get role options.
     * @return array
     */
    public static function get_role_options(): array {
        $roles = role_get_names(null, ROLENAME_ORIGINAL);
        $options = [];
        foreach ($roles as $role) {
            $options[$role->id] = $role->localname;
        }
        return $options;
    }

    /**
     * Get course category options.
     * @return array
     */
    public static function get_category_options(): array {
        global $DB;
        return $DB->get_records_menu('course_categories', null, 'name', 'id, name');
    }

    /**
     * Get course format options.
     * @return array
     */
    public static function get_course_format_options(): array {
        $formats = \core_component::get_plugin_list('format');
        $options = [];
        foreach ($formats as $format => $path) {
            $options[$format] = get_string('pluginname', 'format_' . $format);
        }
        return $options;
    }

    /**
     * Get theme options.
     * @return array
     */
    public static function get_theme_options(): array {
        $themes = \core_component::get_plugin_list('theme');
        $options = [];
        foreach ($themes as $theme => $path) {
            $options[$theme] = get_string('pluginname', 'theme_' . $theme);
        }
        return $options;
    }

    /**
     * Check if the current page matches the path pattern.
     *
     * @param string $pathmatch The URL pattern.
     * @param string $pageurl The current page URL path (from JS via AJAX).
     * @return bool
     */
    public static function check_path_match(string $pathmatch, string $pageurl = ''): bool {
        if (empty($pathmatch)) {
            return true;
        }

        // Use passed URL from JS, or try $PAGE->url as fallback (e.g. when called from extend_navigation).
        if (!empty($pageurl)) {
            $target = $pageurl;
        } else {
            global $PAGE;
            try {
                $target = $PAGE->url->out_as_local_url();
            } catch (\coding_exception $e) {
                return true;
            }
        }

        // Special cases for frontpage and dashboard.
        $isfrontpage = ($target === '/' || $target === '/?redirect=0');
        $isdashboard = (strpos($target, '/my/') === 0 && strpos($target, '/my/courses.php') !== 0);
        $ismycourses = (strpos($target, '/my/courses.php') === 0);

        $possiblematches = [];
        if ($isfrontpage) {
            $possiblematches = ['FRONTPAGE', 'FRONTPAGE_MY', 'FRONTPAGE_MYCOURSES', 'FRONTPAGE_MY_MYCOURSES'];
        } else if ($isdashboard) {
            $possiblematches = ['MY', 'FRONTPAGE_MY', 'MY_MYCOURSES', 'FRONTPAGE_MY_MYCOURSES'];
        } else if ($ismycourses) {
            $possiblematches = ['MYCOURSES', 'FRONTPAGE_MYCOURSES', 'MY_MYCOURSES', 'FRONTPAGE_MY_MYCOURSES'];
        }

        if (in_array($pathmatch, $possiblematches)) {
            return true;
        }

        $pattern = preg_quote($pathmatch, '@');
        if (strpos($pattern, '%') !== false) {
            $pattern = str_replace('%', '.*', $pattern);
        } else {
            $pattern .= '$';
        }

        return (bool) preg_match("@{$pattern}@", $target);
    }

    /**
     * Check if the filters match the current context.
     *
     * @param string|null $filtervalues JSON encoded filter values.
     * @param string $pageurl The current page URL path (from JS via AJAX).
     * @param int $courseid The current course ID (from JS via M.cfg.courseId).
     * @return bool
     */
    public static function check_filters(?string $filtervalues, string $pageurl = '', int $courseid = 0): bool {
        global $PAGE, $USER, $DB, $CFG;

        if (empty($filtervalues)) {
            return true;
        }

        $filters = json_decode($filtervalues, true);
        if (empty($filters)) {
            return true;
        }

        // Check if ALL filter arrays are empty — if so, no filtering needed.
        $hasanyfilter = false;
        foreach ($filters as $values) {
            if (!empty($values)) {
                $hasanyfilter = true;
                break;
            }
        }
        if (!$hasanyfilter) {
            return true;
        }

        // Resolve the course from the courseid passed by JS.
        $course = null;
        $coursecontext = null;
        if ($courseid > 1) { // 1 is the site/frontpage course, not a real course.
            $course = $DB->get_record('course', ['id' => $courseid]);
            if ($course) {
                $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            }
        }

        // 1. Role Filter — check globally (user has the role in any context).
        if (!empty($filters['filter_role'])) {
            $filterroleids = array_map('intval', $filters['filter_role']);

            // Single query: get ALL distinct role IDs assigned to this user across all contexts.
            $sql = "SELECT DISTINCT ra.roleid
                      FROM {role_assignments} ra
                     WHERE ra.userid = :userid";
            $records = $DB->get_records_sql($sql, ['userid' => $USER->id]);
            $userroleids = array_map('intval', array_keys($records));

            // Include Moodle's implicit default roles (not stored in role_assignments).
            if (!empty($CFG->defaultuserroleid) && isloggedin() && !isguestuser()) {
                $userroleids[] = (int) $CFG->defaultuserroleid;
            }
            if (!empty($CFG->defaultfrontpageroleid) && isloggedin()) {
                $userroleids[] = (int) $CFG->defaultfrontpageroleid;
            }

            $userroleids = array_unique($userroleids);
            if (!array_intersect($filterroleids, $userroleids)) {
                return false;
            }
        }

        // 2. Course Category Filter — only show when user is on a course in the matching category.
        if (!empty($filters['filter_category'])) {
            $filtercatids = array_map('intval', $filters['filter_category']);
            if (!$course || empty($course->category)) {
                // Not on a course page → reject (notice is category-specific).
                return false;
            }
            if (!in_array((int) $course->category, $filtercatids)) {
                return false;
            }
        }

        // 3. Course Filter — only show when user is on the matching course.
        if (!empty($filters['filter_course'])) {
            $filtercourseids = array_map('intval', $filters['filter_course']);
            if (!$course) {
                // Not on a course page → reject (notice is course-specific).
                return false;
            }
            if (!in_array((int) $course->id, $filtercourseids)) {
                return false;
            }
        }

        // 4. Course Format Filter — only show when user is on a course with the matching format.
        if (!empty($filters['filter_format'])) {
            if (!$course) {
                // Not on a course page → reject (notice is format-specific).
                return false;
            }
            if (!in_array($course->format, $filters['filter_format'])) {
                return false;
            }
        }

        // 5. Theme Filter — check globally.
        if (!empty($filters['filter_theme'])) {
            try {
                $currenttheme = $PAGE->theme->name;
            } catch (\Exception $e) {
                $currenttheme = '';
            }
            if (!empty($currenttheme) && !in_array($currenttheme, $filters['filter_theme'])) {
                return false;
            }
        }

        return true;
    }
}
