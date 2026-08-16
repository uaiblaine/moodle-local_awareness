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

use local_awareness\local\page_probe;
use local_awareness\local\role_scope;
use local_awareness\persistent\awareness;
use local_awareness\persistent\noticelink;
use local_awareness\persistent\linkhistory;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\noticeview;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
/*
 * render_content() needs file_rewrite_pluginfile_urls(). That call used to be reached only from the
 * save path, where adminlib/formslib had already pulled filelib in; the read path runs inside the
 * AJAX web service, where nothing loads it and the call is a fatal "undefined function". PHPUnit
 * cannot catch that — its bootstrap loads filelib for every test — so Behat is the only guard.
 */
require_once($CFG->libdir . '/filelib.php');

/**
 * Helper class to create, retrieve, manage notices
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
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
     *
     * @param \stdClass $data form data
     * @return string The audience-estimate state the new notice was left in — see
     *                {@see \local_awareness\audience\notice_audience}. Returned rather than
     *                signalled, because only the caller knows whether there is a user to tell.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \core\invalid_persistent_exception
     * @throws \required_capability_exception
     */
    public static function create_new_notice(\stdClass $data): string {
        self::check_manage_capability();

        // Pack filter values.
        $filters = [];
        $filterfields = [
            'filter_role_context',
            'filter_role',
            'filter_category',
            'filter_course',
            'filter_format',
            'filter_theme',
            'filter_competency_rules',
            'filter_competency_requireall',
        ];
        foreach ($filterfields as $field) {
            if (isset($data->$field)) {
                $val = $data->$field;
                if ($field === 'filter_competency_rules') {
                    $val = self::normalise_competency_rules($val);
                } else if ($field === 'filter_competency_requireall') {
                    $val = empty($val) ? 0 : 1;
                }
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

        return \local_awareness\audience\notice_audience::refresh($awareness);
    }

    /**
     * Update existing notice.
     *
     * @param awareness $awareness site notice persistent
     * @param \stdClass $data form data
     * @return string The audience-estimate state the notice was left in — see
     *                {@see \local_awareness\audience\notice_audience}. Unchanged filters leave it
     *                current without computing anything.
     * @throws \coding_exception
     * @throws \core\invalid_persistent_exception
     * @throws \dml_exception
     * @throws \required_capability_exception
     */
    public static function update_notice(awareness $awareness, \stdClass $data): string {
        self::check_manage_capability();

        /*
         * The setting, enforced where the write happens. It used to be consulted only in
         * editnotice.php's `case 'edit'`, which decides whether to DISPLAY the form — and the save
         * branch runs before that switch reaches it, so a POST updated the notice with the setting
         * off. delete_notice() has re-checked its own setting here all along; this is the same
         * guard on the other verb, and the asymmetry is what made the gap easy to miss.
         */
        if (!get_config('local_awareness', 'allow_update')) {
            return \local_awareness\audience\notice_audience::STATE_NONE;
        }

        // Pack filter values.
        $filters = [];
        $filterfields = [
            'filter_role_context',
            'filter_role',
            'filter_category',
            'filter_course',
            'filter_format',
            'filter_theme',
            'filter_competency_rules',
            'filter_competency_requireall',
        ];
        foreach ($filterfields as $field) {
            if (isset($data->$field)) {
                $val = $data->$field;
                if ($field === 'filter_competency_rules') {
                    $val = self::normalise_competency_rules($val);
                } else if ($field === 'filter_competency_requireall') {
                    $val = empty($val) ? 0 : 1;
                }
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

        return \local_awareness\audience\notice_audience::refresh($awareness);
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

        /*
         * Cohorts are dropped to the set this user may target. The estimator counts members with a
         * bare `cohortid IN (…)`, so an id that reached the POST without ever being offered still
         * yields a population size — on the save path just as much as through the web service, only
         * slower: save the notice, then read the audience column on the manage list.
         */
        if (isset($data->cohorts)) {
            $submitted = is_string($data->cohorts) ? explode(',', $data->cohorts) : (array) $data->cohorts;
            $data->cohorts = self::allowed_cohorts($submitted);
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
        if (trim($content) === '') {
            return $content;
        }

        /*
         * The content is stored as authored. It used to be run through
         * file_rewrite_pluginfile_urls() and format_text() first, which baked three things into
         * the stored row: absolute /pluginfile.php URLs that break when wwwroot changes, the
         * output of every text filter — freezing a multilang notice into whichever language the
         * author happened to be using, for every reader, forever — and a full
         * <!DOCTYPE html><html><body> wrapper from saveHTML(). All three belong to render time;
         * see render_content().
         */
        $dom = new \DOMDocument();
        $encoded = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        libxml_use_internal_errors(true);
        // NOIMPLIED + NODEFDTD keep this a fragment: without them saveHTML() returns a whole
        // document, and the notice body ends up nested inside another <html> when it renders.
        $dom->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
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
     * The cohort ids from a submitted list that the current user is actually allowed to target.
     *
     * A cohort id arriving by POST is a membership oracle unless it is checked: the estimator counts
     * members with a bare `cohortid IN (…)`, so an id nobody offered still returns a population size.
     *
     * Checked against built_cohorts_options(), NOT against cohort_get_cohort(). The fleet note names
     * that helper, and measured on the running stack it is the wrong one here: it tests
     * `in_array($cohort->contextid, $currentcontext->get_parent_context_ids())`, and for the system
     * context that list is empty — `/1` with its own id popped off — so it returns false for every
     * cohort, a visible system-level one included, even for an admin. A site-wide plugin has no
     * narrower context to pass it. built_cohorts_options() wraps cohort_get_all_cohorts(), which
     * excludes cohort_get_invisible_contexts(), and is the same call that builds the form's menu, so
     * validation and menu cannot drift apart.
     *
     * @param array $cohortids Raw cohort ids as submitted.
     * @return array The subset the user may target, as ints, reindexed.
     */
    public static function allowed_cohorts(array $cohortids): array {
        if (empty($cohortids)) {
            return [];
        }

        $allowed = array_map('intval', array_keys(self::built_cohorts_options()));

        return array_values(array_intersect(array_map('intval', $cohortids), $allowed));
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
     * Retrieve the notices to show the current user on the page they are currently on.
     *
     * The page URL is mandatory. Everything this returns is about to be rendered, and the
     * pathmatch and check_filters() rules that decide the audience can only be evaluated against
     * a page — so a caller with no page to offer has no business reaching this method. Use
     * has_candidate_notices() for the page-independent question instead.
     *
     * @param string $pageurl The current page URL path (from JS). Must not be empty.
     * @param int $courseid The current course ID (from JS). 0 means not on a course page.
     * @return awareness[] Array of awareness instances
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function retrieve_user_notices(string $pageurl, int $courseid = 0): array {
        if (trim($pageurl) === '') {
            throw new \coding_exception(
                'retrieve_user_notices() needs the page URL to apply the path and filter rules; '
                    . 'call has_candidate_notices() for the page-independent check'
            );
        }

        return self::collect_user_notices($pageurl, $courseid, true);
    }

    /**
     * Whether any notice could reach this user once they are on a page.
     *
     * The footer hook only has to decide whether to load the JS, so this stays a SUPERSET of what
     * the user will actually be shown: it answers "is it worth asking?", never "what may this user
     * read". Nothing rendered may be derived from it — the AJAX call, which carries the browser's
     * page URL, performs the real filtering.
     *
     * Given a page probe, the superset narrows to the page: candidates that cannot match this
     * page's cheap, safe rules (pathmatch and the course/category/format/theme filters — see
     * page_probe) stop counting, so pages where nothing could appear stop loading the module and
     * stop paying the XHR. Every uncertainty inside the probe admits, so narrowing never crosses
     * into "the notice was due and the JS did not load". Without a probe the old page-independent
     * answer is preserved.
     *
     * @param \local_awareness\local\page_probe|null $page What the current render can tell us, if anything.
     * @return bool
     * @throws \dml_exception
     */
    public static function has_candidate_notices(?page_probe $page = null): bool {
        $candidates = self::collect_user_notices('', 0, false);

        if ($page === null) {
            return !empty($candidates);
        }

        foreach ($candidates as $notice) {
            if ($page->admits($notice)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Choose which of the applicable notices to actually put in front of the user now.
     *
     * Arriving at a site and finding three modals stacked in front of the thing you came to do is
     * the behaviour this replaces. One notice at a time; the next one waits until the user reaches
     * its situation again, which in practice means the next page load where it still applies.
     *
     * One at a time on its own would starve the queue, because a notice that keeps coming back
     * would hold the only slot for ever. So the queue has two tiers, and what separates them is
     * whether the user has met the notice before:
     *
     * - FIRST OCCURRENCE goes to the front. A repeating notice gets seen promptly the first time,
     *   which is the whole point of setting one up, and then stops being special.
     * - ANYTHING SEEN BEFORE goes to the back — a repeat of a repeating notice, an acknowledgement
     *   the user closed without accepting, or one they simply ignored. All three would otherwise
     *   occupy the slot indefinitely, which is the same starvation by three different routes.
     *
     * The single exception to one-at-a-time: repeating notices in their first occurrence are shown
     * as a group. Deferring one of those behind the other only delays a notice that is going to
     * interrupt again anyway, so nothing is gained by spacing them out.
     *
     * Within a tier the order is by notice id, oldest first, which is the order this plugin has
     * always used. Repeats of repeating notices sort behind everything else in the back tier, so
     * they really do wait until the rest of the queue is clear.
     *
     * @param awareness[] $applicable Notices that pass the audience and page rules, keyed by id.
     * @return awareness[] The notices to display now, keyed by id.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function select_for_display(array $applicable): array {
        global $USER;

        if (empty($applicable)) {
            return [];
        }

        $met = self::notices_already_met($applicable);

        $firstrepeating = [];
        $firstonce = [];
        $againonce = [];
        $againrepeating = [];

        foreach ($applicable as $id => $notice) {
            $repeating = $notice->get('resetinterval') > 0;
            if (!isset($met[$id])) {
                $repeating ? $firstrepeating[$id] = $notice : $firstonce[$id] = $notice;
            } else {
                $repeating ? $againrepeating[$id] = $notice : $againonce[$id] = $notice;
            }
        }

        if (!empty($firstrepeating)) {
            // The one case where more than one notice is handed over at a time.
            $selected = $firstrepeating;
        } else {
            $queue = $firstonce + $againonce + $againrepeating;
            $head = array_key_first($queue);
            $selected = [$head => $queue[$head]];
        }

        /*
         * Remember what was handed over, so an ignored notice stops counting as a first occurrence
         * and yields the slot on the next page. This is session state on purpose: recording a
         * display in the database would put a write on the read path, which is exactly the cost
         * this plugin cannot afford on every page view.
         */
        foreach (array_keys($selected) as $id) {
            $USER->awarenessshown[$id] = true;
        }

        return $selected;
    }

    /**
     * Which of these notices the user has already met, either in this session or in an earlier one.
     *
     * $USER->viewednotices cannot answer this: retrieve_user_notices() drops entries from it as
     * notices fall due again, so by the time the queue is built it means "seen and still settled"
     * rather than "seen at all".
     *
     * @param awareness[] $applicable Notices under consideration, keyed by id.
     * @return array Set of notice ids the user has met, as keys.
     * @throws \dml_exception
     */
    private static function notices_already_met(array $applicable): array {
        global $DB, $USER;

        if (!isset($USER->awarenessinteracted)) {
            // One statement per session, not per page: the set only grows when the user acts, and
            // the write paths add to it themselves.
            $USER->awarenessinteracted = $DB->get_records_menu(
                'local_awareness_lastview',
                ['userid' => $USER->id],
                '',
                'noticeid, noticeid AS seen'
            );
        }

        $met = $USER->awarenessinteracted + ($USER->awarenessshown ?? []);

        return array_intersect_key($met, $applicable);
    }

    /**
     * Shared body of the two retrieval entry points above.
     *
     * @param string $pageurl The current page URL path; empty when the page rules are not applied.
     * @param int $courseid The current course ID. 0 means not on a course page.
     * @param bool $checkpagerules Whether to apply the pathmatch and check_filters() rules.
     * @return awareness[] Array of awareness instances
     * @throws \dml_exception
     */
    private static function collect_user_notices(string $pageurl, int $courseid, bool $checkpagerules): array {
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
                return self::is_within_active_window($notice);
            }
        );

        $usernotices = $notices;
        if (!empty($notices)) {
            $checkcohorts = false;
            $checkcompletion = false;

            foreach ($notices as $id => $notice) {
                /*
                 * The page-dependent rules run only for a caller that supplied a page. This is an
                 * explicit argument rather than "is $pageurl empty" on purpose: while it was
                 * inferred from the string, the get_notices() web service could be called with the
                 * parameter simply left out, and every rule below was skipped for a request that
                 * went on to return the rendered notice bodies.
                 */
                if ($checkpagerules) {
                    // Check Path Match (using the URL passed from JavaScript).
                    if (!self::check_path_match($notice->get('pathmatch') ?? '', $pageurl)) {
                        unset($usernotices[$id]);
                        continue;
                    }

                    // Check Filters (using courseid for course context detection).
                    if (!self::check_filters($notice->get('filtervalues'), $courseid)) {
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

            /*
             * Filter out notices by course completion.
             *
             * Resolved for the whole set before the loop, exactly as the cohort rule above hoists
             * cohort_get_user_cohorts() out of its loop. Fetching the course inside the loop cost a
             * statement per notice, and repeated it outright when two notices required the same
             * course. This block runs during page generation, so that cost sat inside the TTFB of
             * every page load rather than in the asynchronous call like the rest of the per-notice
             * work — it is the only rule in the plugin that delays the paint.
             */
            if ($checkcompletion) {
                $requiredids = [];
                foreach ($notices as $notice) {
                    if ($notice->get('reqcourse') > 0) {
                        $requiredids[(int) $notice->get('reqcourse')] = true;
                    }
                }

                // One answer per course, not per notice. A course that no longer exists simply has
                // no entry, which leaves its notices shown — the behaviour of the guarded fetch
                // this replaces.
                $iscomplete = [];
                foreach ($DB->get_records_list('course', 'id', array_keys($requiredids)) as $course) {
                    $completion = new \completion_info($course);
                    $iscomplete[(int) $course->id] = $completion->is_course_complete($USER->id);
                }

                foreach ($notices as $notice) {
                    $required = (int) $notice->get('reqcourse');
                    if ($required > 0 && !empty($iscomplete[$required])) {
                        unset($usernotices[$notice->get('id')]);
                    }
                }
            }
        }

        return $usernotices;
    }

    /**
     * Whether the notice's scheduling window has opened.
     *
     * A zero timestart means "no start date", so the notice has always been live.
     *
     * @param awareness $notice Notice.
     * @return bool
     */
    private static function has_started(awareness $notice): bool {
        return $notice->get('timestart') == 0 || time() >= $notice->get('timestart');
    }

    /**
     * Whether the notice's scheduling window is open right now.
     *
     * A notice is perpetual when both timestart and timeend are zero; otherwise it must fall
     * inside the window. This is the DISPLAY test; writes use has_started() instead, which drops
     * the upper bound — see is_notice_available_to_user() for why.
     *
     * @param awareness $notice Notice.
     * @return bool
     * @throws \coding_exception
     */
    private static function is_within_active_window(awareness $notice): bool {
        $now = time();
        $isperpetual = $notice->get('timestart') == 0 && $notice->get('timeend') == 0;
        $isinactivewindow = $now >= $notice->get('timestart') && $now < $notice->get('timeend');

        return $isperpetual || $isinactivewindow;
    }

    /**
     * Whether a notice may currently be acted on by the logged-in user.
     *
     * The web services need this because they take a notice id straight from the client: without
     * it any authenticated user can acknowledge, dismiss or record a click for a notice that was
     * never shown to them, and the acknowledgement reports — the reason this plugin exists —
     * cannot be trusted.
     *
     * It is deliberately looser than the display test in two places:
     *
     * - Only the START of the scheduling window is enforced, not the end. Blocking an unpublished
     *   notice is the point; discarding a genuine Accept because the notice expired while the
     *   modal was open would silently lose the very record this plugin exists to keep.
     * - The PAGE-DEPENDENT checks in check_filters() are not repeated, because they need the page
     *   URL and a write request has no trustworthy source for it. Category, course, format, theme
     *   and competency rules are therefore not enforced here, and cannot be: a notice restricted
     *   to one course stays writable from anywhere. The role rule is the exception — it asks what
     *   the user holds, not where they are, so it is applied below through
     *   user_matches_role_filter(), with the whole filters array so a course- or category-scoped
     *   rule keeps its scope.
     *
     * @param awareness $notice Notice.
     * @return bool
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function is_notice_available_to_user(awareness $notice): bool {
        global $DB, $USER;

        if (!$notice->get('enabled') || !self::has_started($notice)) {
            return false;
        }

        $cohorts = $notice->get('cohorts');
        if (!empty($cohorts)) {
            $usercohorts = cohort_get_user_cohorts($USER->id);
            if (!array_intersect($cohorts, array_keys($usercohorts))) {
                return false;
            }
        }

        // Decoded here rather than through check_filters(), which needs a page this request has not
        // got. A malformed or scalar payload leaves the rule unapplied, exactly as it does there.
        $filters = json_decode((string) $notice->get('filtervalues'), true);
        if (is_array($filters) && !self::user_matches_role_filter($filters)) {
            return false;
        }

        if ($notice->get('reqcourse') > 0) {
            if ($course = $DB->get_record('course', ['id' => $notice->get('reqcourse')])) {
                $completion = new \completion_info($course);
                if ($completion->is_course_complete($USER->id)) {
                    return false;
                }
            }
        }

        return true;
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
     * @param bool $sessiononly Record in the session only, without writing the shared row.
     */
    private static function add_to_viewed_notices(awareness $notice, string $action, bool $sessiononly = false) {
        global $USER;

        /*
         * Guests get the session marker only. Every guest session shares the single guest user id,
         * so a persisted row would hide the notice from every guest who came after — but the marker
         * is still required: retrieve_user_notices() suppresses a notice solely by finding it in
         * $USER->viewednotices, so skipping it altogether reopens the modal on every page load with
         * no way for the guest to stop it.
         */
        /*
         * Either way the user has now met this notice, so it stops counting as a first occurrence
         * and gives up its place at the front of the queue. Kept in step here rather than re-read,
         * so acting on a notice costs no extra statement.
         */
        $USER->awarenessinteracted[$notice->get('id')] = $notice->get('id');

        if ($sessiononly) {
            $USER->viewednotices[$notice->get('id')] = ['timeviewed' => time(), 'action' => $action];
            return;
        }

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
        $isguest = isguestuser();

        $result = [];
        // Check if require acknowledgement. Guests share one user id, so the row would be nobody's.
        if ($notice->get('reqack') && !$isguest) {
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

        // Mark notice as viewed — session-only for a guest, so it stops reappearing for them
        // without hiding it from the next guest.
        self::add_to_viewed_notices($notice, acknowledgement::ACTION_DISMISSED, $isguest);

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
        $isguest = isguestuser();

        if ($isguest) {
            /*
             * No shared row for a guest, but the session marker still has to be set or the modal
             * reopens on every page load. Fall through to the forcelogout handling below.
             */
            self::add_to_viewed_notices($notice, acknowledgement::ACTION_ACKNOWLEDGED, true);
        } else if (self::check_if_already_acknowledged_by_user($notice, $USER->id)) {
            // Already acknowledged in another browser.
            return $result;
        } else if ($persistent = self::create_new_acknowledge_record($notice, acknowledgement::ACTION_ACKNOWLEDGED)) {
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

        // Every guest session shares the single guest user id, so the row would be nobody's.
        // Nothing suppresses a future click, so there is no session state to keep here.
        if (isguestuser()) {
            return ['status' => true];
        }

        /*
         * The link id arrives from the client. Without these checks any authenticated user could
         * post arbitrary ids and fabricate click history — or simply create unbounded rows in a
         * table that has no index on either column it is queried by.
         */
        $link = noticelink::get_record(['id' => $linkid]);
        if (!$link) {
            return ['status' => false];
        }

        $notice = awareness::get_record(['id' => $link->get('noticeid')]);
        if (!$notice || !self::is_notice_available_to_user($notice)) {
            return ['status' => false];
        }

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

        // A notice outlives the cohort it targets, and cohort_get_all_cohorts() only returns the
        // cohorts visible to the caller. Either way the id can be absent, and an unguarded lookup
        // makes the whole manage-notices page fatal. Match get_course_name()'s treatment.
        return $cohorts[$cohortid] ?? '-';
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
     * Render a notice's stored content for display.
     *
     * Storage keeps what the author wrote — @@PLUGINFILE@@ placeholders and unfiltered markup.
     * This is where the file URLs are resolved and the text filters run, so a multilang notice
     * resolves per reader and the stored row survives a wwwroot change.
     *
     * file_rewrite_pluginfile_urls() leaves absolute URLs alone, so notices written before the
     * storage format was corrected render unchanged.
     *
     * @param awareness $notice Notice.
     * @return string HTML ready to place in the modal.
     * @throws \coding_exception
     */
    public static function render_content(awareness $notice): string {
        $content = file_rewrite_pluginfile_urls(
            (string) $notice->get('content'),
            'pluginfile.php',
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $notice->get('id')
        );

        return format_text($content, (int) $notice->get('contentformat'), [
            'noclean' => true,
            'context' => \context_system::instance(),
        ]);
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
     * Checks whether competency support is available and enabled.
     *
     * @return bool
     */
    public static function is_competency_filter_enabled(): bool {
        if (!class_exists('\\core_competency\\api')) {
            return false;
        }

        return \core_competency\api::is_enabled();
    }

    /**
     * Normalize competency rules into a safe list of {id, proficient, name} items.
     *
     * @param mixed $rawrules
     * @return array
     */
    public static function normalise_competency_rules($rawrules): array {
        if (is_string($rawrules) && $rawrules !== '') {
            $decoded = json_decode($rawrules, true);
            $rawrules = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($rawrules)) {
            return [];
        }

        $normalised = [];
        $seenids = [];
        foreach ($rawrules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $id = (int) ($rule['id'] ?? ($rule['competencyid'] ?? 0));
            if ($id <= 0) {
                continue;
            }

            if (isset($seenids[$id])) {
                continue;
            }
            $seenids[$id] = true;

            $proficient = !empty($rule['proficient']) ? 1 : 0;
            $name = isset($rule['name']) ? clean_param(trim((string) $rule['name']), PARAM_TEXT) : '';

            $normalised[] = [
                'id' => $id,
                'proficient' => $proficient,
                'name' => $name,
            ];

            // Guardrail: cap amount of competency rules per notice.
            if (count($normalised) >= 25) {
                break;
            }
        }

        return $normalised;
    }

    /**
     * Resolve display names for competencies.
     *
     * @param array $competencyids
     * @return array<int, string>
     */
    public static function get_competency_names(array $competencyids): array {
        global $DB;

        $ids = array_values(array_unique(array_map('intval', $competencyids)));
        $ids = array_filter($ids, function (int $id): bool {
            return $id > 0;
        });

        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select('competency', "id {$insql}", $params, '', 'id, shortname');

        $names = [];
        foreach ($records as $record) {
            $names[(int) $record->id] = format_string($record->shortname, true, ['context' => \context_system::instance()]);
        }

        return $names;
    }

    /**
     * Get proficiency for a user in a competency within a course.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $competencyid
     * @return bool
     */
    private static function get_user_competency_proficiency(int $userid, int $courseid, int $competencyid): bool {
        static $cache = [];

        $cachekey = $userid . ':' . $courseid . ':' . $competencyid;
        if (array_key_exists($cachekey, $cache)) {
            return $cache[$cachekey];
        }

        try {
            $usercompetency = \core_competency\api::get_user_competency_in_course($courseid, $userid, $competencyid);
            $cache[$cachekey] = !empty($usercompetency) && !empty($usercompetency->get('proficiency'));
        } catch (\Exception $e) {
            $cache[$cachekey] = false;
        }

        return $cache[$cachekey];
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

        // Use the passed URL, or try $PAGE->url as a fallback for a caller that has none.
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
     * Takes no page URL, and never did: path matching belongs to check_path_match(), and the
     * course context here is decided by $courseid alone. The parameter used to sit between the two
     * arguments below without a single read, which invited the reader to assume the URL was
     * consulted and made every call pass an empty string past it to reach $courseid.
     *
     * @param string|null $filtervalues JSON encoded filter values.
     * @param int $courseid The current course ID (from JS via M.cfg.courseId).
     * @return bool
     */
    public static function check_filters(?string $filtervalues, int $courseid = 0): bool {
        global $PAGE, $USER, $DB;

        if (empty($filtervalues)) {
            return true;
        }

        $filters = json_decode($filtervalues, true);
        if (empty($filters)) {
            return true;
        }

        // Check if ALL filter arrays are empty — if so, no filtering needed.
        $hasanyfilter = false;
        foreach ($filters as $key => $values) {
            if ($key === 'filter_competency_requireall') {
                continue;
            }
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
            /*
             * The course id is supplied by the browser, and the filters below use it to decide
             * that a course- or category-targeted notice applies. Without an access check any
             * user could name a course they cannot enter and pull that notice's content.
             *
             * $onlyactive = true is deliberate. can_access_course() defaults it to false, which
             * accepts any {user_enrolments} row — including suspended ones and ones whose window
             * has closed — so a suspended participant would keep receiving the course's notices.
             * Passing true restricts it to active enrolments in enabled plugins, within their
             * time restrictions, which is what "is currently in this course" has to mean for a
             * targeted notice.
             *
             * Also deliberate, and easy to mistake for a bug: a user who is not yet enrolled
             * fails this check on the course's own enrolment page, so a course-targeted notice
             * does NOT appear at /enrol/index.php. That is the intended behaviour — the
             * alternative leaks targeted content to anyone who guesses a course id. Use a cohort
             * or category filter for notices meant to reach people before they enrol.
             */
            if ($course && !can_access_course($course, null, '', true)) {
                $course = null;
            }
            if ($course) {
                $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            }
        }

        // 1. Role Filter — check globally or by context.
        if (!self::user_matches_role_filter($filters)) {
            return false;
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

        // 6. Competency filter.
        if (!empty($filters['filter_competency_rules'])) {
            if (!self::is_competency_filter_enabled()) {
                return false;
            }

            if (!$course) {
                return false;
            }

            $rules = self::normalise_competency_rules($filters['filter_competency_rules']);
            if (!empty($rules)) {
                $requireall = !empty($filters['filter_competency_requireall']);

                foreach ($rules as $rule) {
                    $competencyid = (int) $rule['id'];
                    $requiredproficient = !empty($rule['proficient']) ? 1 : 0;
                    $isproficient = self::get_user_competency_proficiency($USER->id, (int) $course->id, $competencyid) ? 1 : 0;

                    if ($requireall) {
                        if ($isproficient !== 1) {
                            return false;
                        }
                        continue;
                    }

                    if ($isproficient !== $requiredproficient) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Whether the role rule inside a notice's filters admits the current user.
     *
     * Lifted out of check_filters() unchanged. It is the one rule in filtervalues that asks a
     * question about the USER rather than about the page — "do they hold role X?" — so unlike the
     * category, course, format, theme and competency rules beside it, it can be answered without a
     * page URL. That is what lets is_notice_available_to_user() apply it to writes, where the
     * client supplies a notice id and nothing trustworthy about where it came from.
     *
     * The whole $filters array is passed, not just filter_role, because filter_category and
     * filter_course carry a SECOND meaning in here: they scope which contexts the role assignment
     * is looked for in. (Their first meaning, as page-context filters in their own right, belongs
     * to the blocks in check_filters() and stays there.) Splitting them out would silently widen a
     * course-scoped rule into a site-wide one on the write path.
     *
     * @param array $filters Decoded filtervalues.
     * @return bool
     * @throws \dml_exception
     */
    private static function user_matches_role_filter(array $filters): bool {
        global $USER, $DB, $CFG;

        // The guard lives here rather than at each call site so that no caller can forget it.
        if (empty($filters['filter_role'])) {
            return true;
        }

        $filterroleids = array_map('intval', $filters['filter_role']);
        $rolectx = (int) ($filters['filter_role_context'] ?? 0);

        [$ctxjoin, $ctxwhere, $ctxparams] = role_scope::sql($filters, $rolectx);
        $params = ['userid' => $USER->id] + $ctxparams;

        // Single query: get ALL distinct role IDs assigned to this user across the matched contexts.
        $sql = "SELECT DISTINCT ra.roleid
                  FROM {role_assignments} ra
                  {$ctxjoin}
                 WHERE ra.userid = :userid {$ctxwhere}";
        $records = $DB->get_records_sql($sql, $params);
        $userroleids = array_map('intval', array_keys($records));

        // Include Moodle's implicit default roles (not stored in role_assignments).
        if ($rolectx == 0 || $rolectx == CONTEXT_SYSTEM) {
            if (!empty($CFG->defaultuserroleid) && isloggedin() && !isguestuser()) {
                $userroleids[] = (int) $CFG->defaultuserroleid;
            }
            if (!empty($CFG->defaultfrontpageroleid) && isloggedin()) {
                $userroleids[] = (int) $CFG->defaultfrontpageroleid;
            }
        }

        $userroleids = array_unique($userroleids);
        if (!array_intersect($filterroleids, $userroleids)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the PostgreSQL `unaccent` extension is installed and usable right now.
     *
     * Read-only — it asks the pg_extension catalogue and nothing else, so it is safe on a
     * request path. On non-PostgreSQL databases it returns false (accent-insensitivity there
     * comes from the collation, not unaccent()). Creating the extension is ensure_unaccent()'s
     * job and happens at install/upgrade time only.
     *
     * @return bool True when unaccent() can be used in SQL (PostgreSQL only).
     */
    public static function has_unaccent(): bool {
        global $DB;

        if ($DB->get_dbfamily() !== 'postgres') {
            return false;
        }

        /*
         * Ask the catalogue on each call rather than caching: PostgreSQL PHPUnit wraps each test
         * in a rolled-back transaction, so a cached "created" flag would go stale once the CREATE
         * EXTENSION is undone, and a later query would reference a now-missing unaccent().
         */
        return $DB->record_exists_sql("SELECT 1 FROM pg_extension WHERE extname = 'unaccent'");
    }

    /**
     * Provision the PostgreSQL `unaccent` extension, creating it when it is missing.
     *
     * This is DDL, so it belongs to install and upgrade — never to a request path. A
     * least-privilege database account cannot create extensions at all, which is why failure
     * is swallowed rather than raised: the site simply keeps accent-sensitive search, and
     * sql_like_ai() learns that from has_unaccent() instead of from a statement that fails on
     * every keystroke of every search box.
     *
     * @return bool True when unaccent() can be used in SQL afterwards (PostgreSQL only).
     */
    public static function ensure_unaccent(): bool {
        global $DB;

        if (self::has_unaccent()) {
            return true;
        }
        if ($DB->get_dbfamily() !== 'postgres') {
            return false;
        }

        try {
            $DB->execute('CREATE EXTENSION IF NOT EXISTS unaccent');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return a case- and accent-insensitive LIKE fragment that works on MySQL/MariaDB and
     * PostgreSQL.
     *
     * On PostgreSQL it wraps both operands in unaccent() when the extension is already installed
     * — it never tries to install it, since this runs inside a search request — and otherwise
     * falls back to an accent-sensitive comparison; on other databases it relies on the collation
     * via core's sql_like(). The bound parameter value must still be built with sql_like_escape()
     * and the surrounding wildcards by the caller.
     *
     * The PostgreSQL unaccent() approach (which core otherwise reports as unsupported) follows
     * the technique of the local_aise plugin, "Accent Insensitive Search Enabler", copyright
     * 2023 Austrian Federal Ministry of Education, released under the GNU GPL v3 or later:
     * https://github.com/Bildungsportal/moodle-local_aise
     *
     * @param string $fieldname The column or SQL expression to match.
     * @param string $param The bound parameter placeholder (e.g. ':q1').
     * @return string The SQL LIKE fragment.
     */
    public static function sql_like_ai(string $fieldname, string $param): string {
        global $DB;

        if (self::has_unaccent()) {
            return "unaccent($fieldname) ILIKE unaccent($param) ESCAPE '\\'";
        }

        return $DB->sql_like($fieldname, $param, false, false);
    }
}
