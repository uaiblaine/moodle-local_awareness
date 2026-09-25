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

use local_awareness\local\author_scope;
use local_awareness\local\group_scope;
use local_awareness\local\page_probe;
use local_awareness\local\role_scope;
use local_awareness\local\window;
use local_awareness\persistent\awareness;
use local_awareness\persistent\noticelink;
use local_awareness\persistent\linkhistory;
use local_awareness\persistent\acknowledgement;
use local_awareness\persistent\noticeview;
use local_awareness\persistent\slide;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
/*
 * render_content() calls file_rewrite_pluginfile_urls(), and on the AJAX read path nothing has
 * loaded filelib by then, so without this the call is an undefined-function fatal. A PHPUnit run
 * rarely notices a missing require here: all tests share one process, and filelib stays loaded
 * once anything has rendered a template.
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
    /** @var int Longest criteria list a single estimate statement will carry. */
    public const CRITERIA_LIST_MAX = 500;

    /**
     * The capability that grants each authoring verb, under each scope.
     *
     * null means the scope has no capability of its own for the verb yet, so only the site
     * capability, inherited into the scope's context, can grant it.
     */
    private const VERB_CAPABILITIES = [
        'manage' => ['site' => 'local/awareness:manage', 'course' => 'local/awareness:managecourse'],
        'viewreports' => ['site' => 'local/awareness:viewreports', 'course' => 'local/awareness:viewreportscourse'],
    ];

    /**
     * Save the content editor's draft files and register the content's links for click tracking.
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
     * The scope is who the notice is written as, and it becomes the notice's owner: a course, or the
     * site. The site default fails closed, since a course author does not hold the site capability.
     * courseid is pinned from the scope after the scope has filtered the audience fields, so
     * ownership and reach cannot diverge and nothing in $data can choose the owner.
     *
     * @param \stdClass $data form data
     * @param author_scope|null $scope Who the notice is written as; the site when not given.
     * @return string The audience-estimate state the new notice was left in — see
     *                {@see \local_awareness\audience\notice_audience}. Returned rather than
     *                signalled, because only the caller knows whether there is a user to tell.
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \core\invalid_persistent_exception
     * @throws \required_capability_exception
     * @throws \invalid_parameter_exception When a value names something that does not exist, or is forbidden or
     *                                      outside the notice's scope, or the rows list a stored slide twice.
     */
    public static function create_new_notice(\stdClass $data, ?author_scope $scope = null): string {
        $scope = $scope ?? author_scope::site();
        self::require_author($scope, 'manage');

        self::apply_author_scope($data, $scope);
        $data->courseid = $scope->get_courseid();
        self::apply_layout_rules($data);
        // Read before sanitise_data(), which keeps only the notice's own columns.
        $slides = self::slide_rows($data);

        // Create new notice.
        self::sanitise_data($data);
        $awareness = awareness::create_new_notice($data);

        self::process_content($awareness);
        awareness::update_notice_content($awareness, $awareness->get('content'));

        // Process background image.
        self::process_bgimage($awareness);
        self::process_slides($awareness, $slides);

        // Log created event.
        $params = [
            'context' => self::event_context($awareness),
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
     * @throws \invalid_parameter_exception When a value names something that does not exist, or is forbidden or
     *                                      outside the notice's scope, or the rows list a stored slide twice.
     */
    public static function update_notice(awareness $awareness, \stdClass $data): string {
        $scope = author_scope::of($awareness);
        self::require_author($scope, 'manage');
        self::require_group_reach($awareness);

        /*
         * allow_update is enforced here, where the write happens, so no caller can update a notice
         * with the setting off; delete_notice() does the same for allow_delete.
         */
        if (!get_config('local_awareness', 'allow_update')) {
            return \local_awareness\audience\notice_audience::STATE_NONE;
        }

        self::apply_author_scope($data, $scope);
        /*
         * Ownership is immutable and pinned rather than trusted: sanitise_data() keeps any key that
         * is a property, so a submitted courseid would re-home the notice under a capability check
         * made against the old owner. Moving a notice would be a verb of its own, not an edit.
         */
        $data->courseid = (int) $awareness->get('courseid');
        self::apply_layout_rules($data);
        // Read before sanitise_data(), which keeps only the notice's own columns.
        $slides = self::slide_rows($data);

        self::sanitise_data($data);
        awareness::update_notice_data($awareness, $data);

        self::process_content($awareness);
        awareness::update_notice_content($awareness, $awareness->get('content'));

        // Process background image.
        self::process_bgimage($awareness);
        self::process_slides($awareness, $slides);

        // Log updated event.
        $params = [
            'context' => self::event_context($awareness),
            'objectid' => $awareness->get('id'),
            'relateduserid' => $awareness->get('usermodified'),
        ];
        $event = \local_awareness\event\awareness_updated::create($params);
        $event->trigger();

        return \local_awareness\audience\notice_audience::refresh($awareness);
    }

    /**
     * Run the submitted audience and context fields through the author's scope, then pack the
     * filter fields into the filtervalues JSON.
     *
     * This is the validation boundary for those fields. The form is not one: core does not validate
     * the values of its three ajax autocompletes server-side, and a non-ajax select skips its
     * allowlist when its option list is empty. Nor is sanitise_data(), which runs after the filter
     * fields have been folded into filtervalues and never sees them. Both write paths call this
     * before anything is stored; see {@see author_scope} for what each scope allows.
     *
     * Refused rather than repaired: notice_form::extra_validation() has already shown the author
     * every problem, so a value that still arrives here bypassed the form, and gets an error rather
     * than a notice quietly different from the one requested. Cohorts are the exception, narrowed
     * silently by the scope itself.
     *
     * @param \stdClass $data Form data, modified in place: the filter fields leave it and
     *                        filtervalues, cohorts and reqcourse arrive as the scope left them.
     * @param author_scope $scope Who the notice is being written as.
     * @throws \invalid_parameter_exception When a value names something that does not exist or
     *                                      lies outside the scope.
     */
    private static function apply_author_scope(\stdClass $data, author_scope $scope): void {
        $raw = [];
        foreach (array_keys(author_scope::RULES) as $field) {
            if (isset($data->$field)) {
                $raw[$field] = $data->$field;
            }
        }

        $result = $scope->apply($raw);
        if (!$result->is_clean()) {
            throw new \invalid_parameter_exception(
                'Refused by the author scope: ' . implode(', ', $result->problem_fields())
            );
        }
        $criteria = $result->criteria();

        $filterfields = [
            'filter_role_context',
            'filter_role',
            'filter_category',
            'filter_course',
            'filter_groups',
            'filter_format',
            'filter_theme',
            'filter_competency_rules',
            'filter_competency_requireall',
        ];
        $filters = [];
        foreach ($filterfields as $field) {
            if (!array_key_exists($field, $criteria)) {
                continue;
            }
            $val = $criteria[$field];
            if ($field === 'filter_competency_requireall') {
                $val = empty($val) ? 0 : 1;
            }
            $filters[$field] = $val;
            unset($data->$field);
        }
        $data->filtervalues = json_encode($filters);

        /*
         * The three fields the scope may write that are columns of their own rather than keys of
         * the JSON blob. pathmatch is one because a course scope forces it to the course's main page.
         */
        if (array_key_exists('pathmatch', $criteria)) {
            $data->pathmatch = $criteria['pathmatch'];
        }
        if (array_key_exists('cohorts', $criteria)) {
            $data->cohorts = $criteria['cohorts'];
        }
        if (array_key_exists('reqcourse', $criteria)) {
            $data->reqcourse = $criteria['reqcourse'];
        }
    }

    /**
     * Sanitise submitted data before creating or updating a site notice.
     *
     * @param \stdClass $data
     */
    private static function sanitise_data(\stdClass $data) {
        /*
         * The author chose one level; these are the two columns it has always been stored in. The
         * mapping lives here rather than in the form because both write paths pass through this
         * method, and a mapping that only one of them applied would let a notice be saved at a
         * level the display path could not read. awareness::get_insistence() is the inverse.
         */
        if (isset($data->insistence)) {
            $level = (int) $data->insistence;
            $data->reqack = $level >= awareness::INSISTENCE_ACKNOWLEDGE ? 1 : 0;
            $data->outsideclick = $level >= awareness::INSISTENCE_BLOCKING ? 0 : 1;
            unset($data->insistence);
        }

        foreach ((array) $data as $key => $value) {
            if (!key_exists($key, awareness::properties_definition())) {
                unset($data->$key);
            }
        }

        // Cohorts, and every other audience field, were already narrowed by apply_author_scope().
    }

    /**
     * The choices a layout makes for the author, applied on every write path.
     *
     * hideIf hides a field without stopping its value; these are the values a hidden field would
     * have carried. A fullscreen dialogue has no position, so it is centred whatever the radio
     * said; a link only the video layout reads is dropped rather than stored out of sight, because
     * a value the form will not show is a value the author cannot correct.
     *
     * @param \stdClass $data The submitted data.
     */
    private static function apply_layout_rules(\stdClass $data): void {
        $template = (string) ($data->template ?? awareness::TEMPLATES[0]);
        if ($template === 'fullscreen') {
            $data->position = 'center';
        }
        if (!awareness::uses_video($template)) {
            $data->videourl = null;
        } else if (isset($data->videourl)) {
            $data->videourl = trim((string) $data->videourl);
        }
    }

    /**
     * The slide rows the form submitted, lifted out of the data before it is sanitised.
     *
     * sanitise_data() keeps only the notice's own columns, and the slides are not columns of the
     * notice; read them first, save them after.
     *
     * @param \stdClass $data The submitted data, before sanitise_data() strips what is not a column.
     * @return \stdClass The four slide arrays, each keyed by row index.
     * @throws \invalid_parameter_exception When the rows list a stored slide twice.
     */
    public static function slide_rows(\stdClass $data): \stdClass {
        $rows = new \stdClass();
        foreach (['slide_caption', 'slide_videourl', 'slide_image', 'slide_id'] as $field) {
            $rows->$field = isset($data->$field) ? (array) $data->$field : [];
        }
        // Refused here, before the notice itself is written, so a bad request changes nothing at all.
        self::refuse_repeated_slide_ids($rows->slide_id);

        /*
         * A slide shows one medium, and the form asks which. hideIf hides the other control
         * without stopping its value, so the field the author did not choose is cleared here
         * rather than saved behind their back — a link typed and then switched away from would
         * otherwise reach the row and win, because a slide with a link is a video slide.
         */
        $media = isset($data->slide_media) ? (array) $data->slide_media : [];
        foreach ($media as $i => $shows) {
            if ($shows === slide::MEDIA_VIDEO) {
                $rows->slide_image[$i] = 0;
            } else {
                $rows->slide_videourl[$i] = '';
            }
        }

        return $rows;
    }

    /**
     * Refuse rows that list one stored slide twice.
     *
     * Rows are matched to slides by their hidden id, so two rows carrying the same id would both
     * write the same record: the later would win and the earlier would be lost without a word, and
     * a slide the rows no longer named would be deleted with its file. No path through the editor
     * produces such a request, so it is refused outright rather than reconciled by guesswork; the
     * form refuses it first, on the row, for anyone who reaches it with a browser.
     *
     * @param array $ids The slide ids the rows carry, keyed by row index; 0 for a new slide.
     * @return void
     * @throws \invalid_parameter_exception When a stored slide is listed more than once.
     */
    public static function refuse_repeated_slide_ids(array $ids): void {
        $stored = array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0);
        if (count($stored) !== count(array_unique($stored))) {
            throw new \invalid_parameter_exception('a slide is listed twice');
        }
    }

    /**
     * Reconcile the carousel's slides with the repeated rows the form submitted.
     *
     * Rows are matched to existing slides by their hidden id; a row with an id the notice does not
     * own becomes a new slide rather than an edit of somebody else's, and a row repeating an id
     * already listed — this notice's or another's — is refused outright. A row with no image, no
     * link and no caption is not a slide and is not saved; an existing slide whose row is gone is
     * deleted with its file. The draft ids are read from the submitted array: the core helper
     * cannot address a repeated file picker.
     *
     * @param awareness $awareness The saved notice.
     * @param \stdClass $rows The slide rows, as slide_rows() read them.
     * @throws \invalid_parameter_exception When the rows list a stored slide twice.
     */
    public static function process_slides(awareness $awareness, \stdClass $rows): void {
        // Refused again here, for a caller that built its rows without slide_rows().
        self::refuse_repeated_slide_ids((array) ($rows->slide_id ?? []));

        $noticeid = (int) $awareness->get('id');
        $existing = [];
        foreach (slide::for_notice($noticeid) as $slide) {
            $existing[(int) $slide->get('id')] = $slide;
        }

        $captions = (array) ($rows->slide_caption ?? []);
        $links = (array) ($rows->slide_videourl ?? []);
        $drafts = (array) ($rows->slide_image ?? []);
        $ids = (array) ($rows->slide_id ?? []);

        $indexes = array_keys($captions + $links + $drafts);
        sort($indexes);

        $keep = [];
        $sortorder = 0;
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        foreach ($indexes as $i) {
            $caption = trim((string) ($captions[$i] ?? ''));
            $link = trim((string) ($links[$i] ?? ''));
            $draftid = (int) ($drafts[$i] ?? 0);
            $hasimage = $draftid > 0 && (int) file_get_draft_area_info($draftid)['filecount'] > 0;
            if ($caption === '' && $link === '' && !$hasimage) {
                continue;
            }

            $id = (int) ($ids[$i] ?? 0);
            $slide = isset($existing[$id]) ? $existing[$id] : new slide(0, (object) ['noticeid' => $noticeid]);
            $slide->set('sortorder', $sortorder++);
            $slide->set('videourl', $link === '' ? null : $link);
            $slide->set('caption', $caption === '' ? null : $caption);
            if ($slide->get('id') > 0) {
                $slide->update();
            } else {
                $slide->create();
            }
            $keep[(int) $slide->get('id')] = true;

            if ($draftid > 0) {
                file_save_draft_area_files(
                    $draftid,
                    $contextid,
                    'local_awareness',
                    slide::FILEAREA,
                    (int) $slide->get('id'),
                    ['maxfiles' => 1, 'accepted_types' => ['image']]
                );
            }
        }

        foreach ($existing as $id => $slide) {
            if (!isset($keep[$id])) {
                $fs->delete_area_files($contextid, 'local_awareness', slide::FILEAREA, $id);
                $slide->delete();
            }
        }
    }

    /**
     * Register the links in a notice's content for click tracking.
     *
     * Every anchor gets data-linkid and target="_blank"; links the content no longer carries are
     * deleted with their history.
     *
     * @param awareness $notice
     * @param string $content notice content
     * @return string The content with its anchors tagged.
     */
    private static function update_hyperlinks(awareness $notice, string $content): string {
        if (trim($content) === '') {
            return $content;
        }

        /*
         * The content is stored as authored; resolving file URLs and running the text filters
         * belong to render time (see render_content()). Doing either here would bake in absolute
         * URLs that break when wwwroot changes and freeze a multilang notice into the author's
         * language for every reader.
         */
        $dom = new \DOMDocument();
        $encoded = mb_encode_numericentity($content, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        // Restored below rather than left on: this runs inside a page request, and switching
        // internal error handling on globally silences XML warnings for everything after it.
        $libxmlprevious = libxml_use_internal_errors(true);
        // NOIMPLIED + NODEFDTD keep this a fragment: without them saveHTML() returns a whole
        // document, and the notice body ends up nested inside another <html> when it renders.
        $dom->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlprevious);
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

        /*
         * Clean up links the notice no longer carries, history first: every consumer inner-joins
         * history to the links table, so orphaned history rows would be invisible to every report
         * and impossible to clear. purge_notice() deletes the same pair.
         */
        $unusedlinks = array_diff_key($currentlinks, $newlinks);
        if (!empty($unusedlinks)) {
            linkhistory::delete_link_history(array_keys($unusedlinks));
            noticelink::delete_links(array_keys($unusedlinks));
        }

        // New content of the notice (included link ids).
        $newcontent = $dom->saveHTML();
        return $newcontent;
    }

    /**
     * Ask the whole audience again.
     *
     * A no-op save on purpose: update() stamps timemodified, which supersedes every acceptance on
     * record. That is why the action is labelled "Ask everyone again" and is confirmed before it
     * fires. See acceptance_is_current().
     *
     * @param awareness $notice
     * @return void
     */
    public static function reset_notice(awareness $notice): void {
        self::require_author(author_scope::of($notice), 'manage');
        self::require_group_reach($notice);
        try {
            $notice = new awareness($notice->get('id'));
            $notice->update();

            // Log reset event.
            $params = [
                'context' => self::event_context($notice),
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
     * Enable a notice.
     *
     * The save stamps timemodified, which must_reshow() and acceptance_is_current() both read: the
     * notice is shown again to everyone, deliberately, and every acceptance on it expires. See
     * acceptance_is_current() for the whole coupling.
     *
     * @param awareness $notice
     * @return void
     */
    public static function enable_notice(awareness $notice): void {
        self::require_author(author_scope::of($notice), 'manage');
        self::require_group_reach($notice);
        try {
            $notice->set('enabled', 1);
            $notice->update();

            // Log enabled event.
            $params = [
                'context' => self::event_context($notice),
                'objectid' => $notice->get('id'),
                'relateduserid' => $notice->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_enabled::create($params);
            $event->trigger();
        } catch (\Exception $e) {
            \core\notification::error($e->getMessage());
        }
    }

    /**
     * Disable a notice.
     *
     * Saves the notice, and so expires every acceptance on it; see enable_notice() and
     * acceptance_is_current().
     *
     * @param awareness $notice
     * @return void
     */
    public static function disable_notice(awareness $notice): void {
        self::require_author(author_scope::of($notice), 'manage');
        self::require_group_reach($notice);
        try {
            $notice->set('enabled', 0);
            $notice->update();

            // Log disabled event.
            $params = [
                'context' => self::event_context($notice),
                'objectid' => $notice->get('id'),
                'relateduserid' => $notice->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_disabled::create($params);
            $event->trigger();
        } catch (\Exception $e) {
            \core\notification::error($e->getMessage());
        }
    }

    /**
     * Delete a notice: the verb an author invokes.
     *
     * The gate and the setting live here, and only here; what a deletion does is purge_notice().
     *
     * @param awareness $notice
     * @return void
     */
    public static function delete_notice(awareness $notice): void {
        self::require_author(author_scope::of($notice), 'manage');
        self::require_group_reach($notice);
        if (!get_config('local_awareness', 'allow_delete')) {
            return;
        }

        self::purge_notice($notice);
    }

    /**
     * Purge every notice a course owns, because the course is going.
     *
     * Called from the before_course_deleted hook, where nobody is "the author": the person deleting
     * the course may hold no notice capability at all, and the allow_delete setting governs whether a
     * human may press Delete, not whether a course can stop existing. So this asks no question and
     * honours no setting except cleanup_deleted_notice, exactly as a manual delete does past its gate.
     *
     * @param int $courseid The course being deleted.
     * @return int How many notices went.
     */
    public static function purge_course_notices(int $courseid): int {
        if ($courseid <= 0) {
            return 0;
        }
        $notices = awareness::get_records(['courseid' => $courseid]);
        foreach ($notices as $notice) {
            self::purge_notice($notice);
        }

        return count($notices);
    }

    /**
     * Remove a notice and everything that hangs off it, asking nothing about who is asking.
     *
     * Not a verb: the two callers are delete_notice(), which has already gated and consulted its
     * setting, and purge_course_notices(), which runs where there is no author to gate. The event is
     * logged in the notice's own context however the deletion happened; during a course deletion
     * that context still exists, because the purge runs from before_course_deleted.
     *
     * @param awareness $notice
     * @return void
     */
    private static function purge_notice(awareness $notice): void {
        $oldid = $notice->get('id');
        $notice->delete();
        $params = [
            'context' => self::event_context($notice),
            'objectid' => $oldid,
            'relateduserid' => $notice->get('usermodified'),
        ];
        $event = \local_awareness\event\awareness_deleted::create($params);
        $event->trigger();

        /*
         * The files go with the notice whatever cleanup_deleted_notice says. With the row gone the
         * pluginfile gate, which resolves the notice first, serves them to nobody, and nothing else
         * can claim them: the item id is the notice id.
         */
        $fs = get_file_storage();
        foreach (['content', 'bgimage'] as $filearea) {
            $fs->delete_area_files(\context_system::instance()->id, 'local_awareness', $filearea, $oldid);
        }
        // The slides' images are keyed by slide id, not notice id, so the slides take their own files.
        slide::delete_for_notice((int) $oldid);

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
     * The context every event about a notice is logged in: the notice's course for a course notice,
     * the system context for a site notice.
     *
     * So a course's logs, and core's reports and event monitoring on them, include what happened to
     * that course's notices. A course notice whose course is gone falls back to the system context,
     * which is the only one left to log it in.
     *
     * @param awareness $notice The notice the event is about.
     * @return \context
     */
    private static function event_context(awareness $notice): \context {
        $scope = author_scope::of($notice);
        if (!$scope->is_site()) {
            $context = \context_course::instance($scope->get_courseid(), IGNORE_MISSING);
            if ($context) {
                return $context;
            }
        }

        return \context_system::instance();
    }

    /**
     * Built Audience options based on site cohorts.
     *
     * Every cohort, hidden ones included, except those in contexts where the current user can
     * neither view nor manage cohorts (cohort_get_invisible_contexts()).
     *
     * The names are RAW, exactly as stored: the caller formats them for its own sink. A moodleform
     * menu, which renders option text unescaped, takes cohort_menu_options() instead.
     *
     * @return array Cohort id => raw name.
     * @throws \coding_exception
     */
    public static function built_cohorts_options() {
        $options = [];
        foreach (self::listable_cohorts() as $cohort) {
            $options[$cohort->id] = $cohort->name;
        }
        return $options;
    }

    /**
     * The cohorts built_cohorts_options() lists, with names formatted for a moodleform menu.
     *
     * element-autocomplete.mustache emits every option through a triple stash, so each name goes
     * through format_string() with its default escaping, in the cohort's own context: an ampersand
     * arrives as an entity, markup is stripped and a multilang name resolves. The ids are the same
     * set, so allowed_cohorts() validates exactly what this offers.
     *
     * @return array Cohort id => formatted, escaped name.
     * @throws \coding_exception
     */
    public static function cohort_menu_options(): array {
        $options = [];
        foreach (self::listable_cohorts() as $cohort) {
            $options[$cohort->id] = format_string($cohort->name, true, ['context' => (int) $cohort->contextid]);
        }
        return $options;
    }

    /**
     * The cohort records the two option lists above are built from.
     *
     * @return \stdClass[] Cohort records, each carrying id, name and contextid.
     */
    private static function listable_cohorts(): array {
        return cohort_get_all_cohorts(0, 0)['cohorts'];
    }

    /**
     * The cohort ids from a submitted list that the current user is actually allowed to target.
     *
     * A cohort id arriving by POST is a membership oracle unless it is checked: the estimator counts
     * members with a bare `cohortid IN (…)`, so an id nobody offered still returns a population size.
     *
     * Checked against built_cohorts_options(), which lists the same cohorts as the form's menu
     * (cohort_menu_options()), so validation and menu cannot drift apart. Not cohort_get_cohort($id,
     * $context): it requires the cohort's context to be among $context's parents, and the system
     * context has none, so there it refuses every cohort, even for an admin.
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
     * The ids of every cohort a user belongs to, visible or not.
     *
     * Not cohort_get_user_cohorts(), whose SQL requires `c.visible = 1`: the form offers hidden
     * cohorts as targets (the ordinary way to model a staff-only audience) and the estimator counts
     * `{cohort_members}` with no visibility predicate, so the runtime must agree with both or a
     * hidden cohort's notice reaches nobody.
     *
     * Visibility governs who may *target* a cohort, which allowed_cohorts() enforces when the notice
     * is saved; whether someone is *in* one does not depend on who is looking.
     *
     * @param int $userid The user whose memberships are wanted.
     * @return array Cohort ids as ints.
     */
    public static function user_cohort_ids(int $userid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select('cohort_members', 'cohortid', 'userid = ?', [$userid]));
    }

    /**
     * The groups of a course the user belongs to, hidden ones included.
     *
     * includehidden because delivery is membership and must not depend on who is asking. Without it,
     * unless the current user holds moodle/course:viewhiddengroups, core filters through
     * core_group\visibility::sql_group_visibility_where(), which drops groups whose visibility is
     * "none" and keeps another user's "members" groups only when the current user is a member too.
     * Nothing on the reading side shows a group's name, so no visibility rule is bypassed.
     *
     * One statement covers every course the user is in, and core caches the answer per user when
     * the course has no hidden groups or the current user may see them.
     *
     * @param int $courseid The course.
     * @param int $userid The user.
     * @return int[] Group ids.
     */
    public static function user_group_ids(int $courseid, int $userid): array {
        $groups = groups_get_user_groups($courseid, $userid, true);

        return array_map('intval', $groups[0] ?? []);
    }

    /**
     * Whether a user is in the audience a notice's group rule names.
     *
     * True when the notice names no group. A notice naming groups with no course to find them in
     * reaches nobody, as every rule whose referent cannot be resolved does.
     *
     * @param awareness $notice The notice.
     * @param int $userid The user.
     * @return bool
     */
    public static function user_in_notice_groups(awareness $notice, int $userid): bool {
        $targets = group_scope::targeted($notice);
        if ($targets === []) {
            return true;
        }
        $courseid = (int) $notice->get('courseid');
        if ($courseid <= SITEID) {
            return false;
        }

        return array_intersect($targets, self::user_group_ids($courseid, $userid)) !== [];
    }

    /**
     * Whether a user may act on a notice as far as its groups go.
     *
     * Core's own rule, through group_scope: in separate groups mode without
     * moodle/site:accessallgroups, a notice aimed only at groups the current user is not in is not
     * theirs to see or change. Every other case admits, a notice naming no group first of all.
     *
     * @param awareness $notice The notice.
     * @return bool
     */
    public static function may_reach_groups(awareness $notice): bool {
        $targets = group_scope::targeted($notice);
        if ($targets === []) {
            return true;
        }

        return group_scope::for_author(author_scope::of($notice))->admits($targets);
    }

    /**
     * Refuse an action on a notice whose groups the current user may not reach.
     *
     * The capability named is the one that would open it: the author holds the plugin's own, or
     * require_author() would have refused first.
     *
     * @param awareness $notice The notice.
     * @throws \required_capability_exception
     */
    private static function require_group_reach(awareness $notice): void {
        if (!self::may_reach_groups($notice)) {
            throw new \required_capability_exception(
                author_scope::of($notice)->context(),
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Retrieve the notices to show the current user on the page they are currently on.
     *
     * The page URL is mandatory: everything this returns is about to be rendered, and the pathmatch
     * and check_filters() rules that decide the audience can only be evaluated against a page. Use
     * has_candidate_notices() for the page-independent question.
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
     * read". Nothing rendered may be derived from it; the AJAX call, which carries the browser's
     * page URL, performs the real filtering.
     *
     * Given a page probe, candidates that cannot match this page's cheap rules (pathmatch and the
     * course/category/format/theme filters, see page_probe) stop counting, so pages where nothing
     * could appear skip the module and its request. The probe admits whenever it is unsure, so the
     * narrowing never withholds the JS from a page where a notice is due. Without a probe the answer
     * is page-independent.
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
     * One notice at a time rather than a stack of modals; the next one waits until the user is
     * somewhere it applies again, in practice the next page load.
     *
     * One at a time alone would starve the queue, because a notice that keeps coming back would hold
     * the only slot for ever. So the queue has two tiers, separated by whether the user has met the
     * notice before:
     *
     * - FIRST OCCURRENCE goes to the front, so a repeating notice is seen promptly the first time.
     * - ANYTHING SEEN BEFORE goes to the back: a repeat of a repeating notice, an acknowledgement
     *   the user closed without accepting, or one they ignored. Each would otherwise hold the slot.
     *
     * The one exception to one-at-a-time: repeating notices in their first occurrence are shown
     * together, since deferring one behind another only delays a notice that will interrupt again.
     *
     * Within a tier the order is by notice id, oldest first. Repeats of repeating notices sort
     * behind everything else in the back tier, so they wait until the rest of the queue is clear.
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
         * Remember what was handed over. Two consumers read it.
         *
         * The queue, so an ignored notice stops counting as a first occurrence and yields the slot
         * on the next page. And the write paths, through was_notice_delivered(): the page-dependent
         * rules (check_path_match() and the category, course, format, theme and competency blocks
         * of check_filters()) run only on the read path, so this marker is the only record that
         * they admitted this user. Narrowing this loop weakens the write-path gate, not just the
         * queue order.
         *
         * Session state on purpose: recording a display in the database would put a write on the
         * read path of every page view.
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

        // Loaded once per session.
        if (!isset($USER->viewednotices)) {
            self::load_viewed_notices();
        }
        /*
         * Drop from the viewed set every notice that must_reshow() says has to be shown again, so
         * the filter below lets it through.
         */
        $viewednotices = $USER->viewednotices;
        foreach ($viewednotices as $noticeid => $data) {
            // Disabled, deleted or expired since it was viewed.
            if (!isset($notices[$noticeid])) {
                continue;
            }
            if (self::must_reshow($notices[$noticeid], (int) $data['timeviewed'], (int) $data['action'])) {
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
            $checkgroups = false;
            $checkcompletion = false;

            foreach ($notices as $id => $notice) {
                /*
                 * The page-dependent rules run only for a caller that supplied a page. An explicit
                 * flag rather than an empty $pageurl, so that a get_notices() call leaving the page
                 * out cannot skip them and still receive the rendered notice bodies.
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
                if (group_scope::targeted($notice) !== []) {
                    $checkgroups = true;
                }
                if ($notice->get('reqcourse') > 0) {
                    $checkcompletion = true;
                }
            }

            // Filter out notices by cohorts.
            if ($checkcohorts) {
                $usercohorts = self::user_cohort_ids((int) $USER->id);
                foreach ($notices as $notice) {
                    $cohorts = array_map('intval', $notice->get('cohorts'));
                    if (!empty($cohorts) && !array_intersect($cohorts, $usercohorts)) {
                        unset($usernotices[$notice->get('id')]);
                    }
                }
            }

            /*
             * Filter out notices by group. A course notice may name groups of its own course, and
             * the reader must belong to one of them. Resolved per notice rather than hoisted like
             * the cohorts above; see user_group_ids() for what that costs.
             */
            if ($checkgroups) {
                foreach ($notices as $notice) {
                    if (!self::user_in_notice_groups($notice, (int) $USER->id)) {
                        unset($usernotices[$notice->get('id')]);
                    }
                }
            }

            /*
             * Filter out notices by course completion.
             *
             * Each required course is resolved once for the whole set, as the cohort rule above
             * hoists user_cohort_ids(). This also runs from has_candidate_notices() while the page
             * is generated, so a statement per notice would add to every page's response time.
             */
            if ($checkcompletion) {
                $requiredids = [];
                foreach ($notices as $notice) {
                    if ($notice->get('reqcourse') > 0) {
                        $requiredids[(int) $notice->get('reqcourse')] = true;
                    }
                }

                /*
                 * One answer per course, not per notice. A course that no longer exists has no
                 * entry, and a notice requiring it is withheld: the rule asks "has this user
                 * finished that course?", which has no answer once the course is gone, and every
                 * other rule in this plugin withholds a notice whose referent it cannot resolve.
                 * is_notice_available_to_user() and the estimator's predicate make the same choice;
                 * keep the three in step.
                 */
                $pending = [];
                foreach ($DB->get_records_list('course', 'id', array_keys($requiredids)) as $course) {
                    $completion = new \completion_info($course);
                    $pending[(int) $course->id] = !$completion->is_course_complete($USER->id);
                }

                foreach ($notices as $notice) {
                    $required = (int) $notice->get('reqcourse');
                    if ($required > 0 && empty($pending[$required])) {
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
        return window::has_started((int) $notice->get('timestart'), time());
    }

    /**
     * Whether the notice's scheduling window is open right now.
     *
     * The DISPLAY test, and the exact one: get_enabled_notices() prefilters on the upper bound
     * alone, because that query is cached, so the lower bound is enforced here against a live
     * clock. A zero bound is unbounded on that side — see local\window for the truth table.
     * Writes use has_started() instead, which drops the upper bound; see
     * is_notice_available_to_user() for why.
     *
     * @param awareness $notice Notice.
     * @return bool
     * @throws \coding_exception
     */
    private static function is_within_active_window(awareness $notice): bool {
        return window::is_open(
            (int) $notice->get('timestart'),
            (int) $notice->get('timeend'),
            time()
        );
    }

    /**
     * Whether a notice may currently be acted on by the logged-in user.
     *
     * The web services take a notice id straight from the client, so without this any authenticated
     * user could acknowledge, dismiss or record a click for a notice never shown to them, and the
     * acknowledgement reports could not be trusted.
     *
     * Deliberately looser than the display test in two places:
     *
     * - Only the START of the scheduling window is enforced. Blocking an unpublished notice is the
     *   point; refusing a genuine Accept because the notice expired while the modal was open would
     *   lose the record the reports exist to keep.
     * - The page-dependent rules in check_filters() (category, course, format, theme, competency)
     *   are not applied: they need the page URL, and a write request has no trustworthy source for
     *   it. may_act_on_notice() covers them on the write path by also requiring that
     *   select_for_display() served this notice to this session, where those rules did run.
     *   may_serve_files_of() uses this method on its own, having no delivery to point at, so that
     *   gate stays partial by construction.
     *
     * The role rule is applied through user_matches_role_filter() with the whole filters array, so a
     * course- or category-scoped rule keeps its scope. The group rule is applied too: a course
     * notice's groups are its own course's, so no page is needed to find them.
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

        $cohorts = array_map('intval', $notice->get('cohorts'));
        if (!empty($cohorts)) {
            if (!array_intersect($cohorts, self::user_cohort_ids((int) $USER->id))) {
                return false;
            }
        }

        // Decoded here rather than through check_filters(), which needs a page this request has not
        // got. A malformed or scalar payload leaves the rule unapplied, exactly as it does there.
        $filters = json_decode((string) $notice->get('filtervalues'), true);
        if (is_array($filters) && !self::user_matches_role_filter($filters)) {
            return false;
        }

        if (!self::user_in_notice_groups($notice, (int) $USER->id)) {
            return false;
        }

        if ($notice->get('reqcourse') > 0) {
            // A required course that no longer exists withholds the notice, exactly as the
            // completion block in collect_user_notices() does; the reasoning is written there.
            $course = $DB->get_record('course', ['id' => $notice->get('reqcourse')]);
            if (!$course) {
                return false;
            }
            $completion = new \completion_info($course);
            if ($completion->is_course_complete($USER->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this session was actually served this notice.
     *
     * select_for_display() marks every notice it hands to the client, and it only ever sees
     * notices that survived retrieve_user_notices(), the one place the page-dependent rules run:
     * check_path_match() against the browser's URL, and check_filters() against a course the
     * server re-resolved through can_access_course(). The marker therefore records that those
     * passed for this user, on some page, in this session: the fact is_notice_available_to_user()
     * cannot establish from a write request.
     *
     * The session is the right lifetime and needs no expiry of its own: a shorter one would discard
     * an Accept from a modal left open for a long time, which the window rules deliberately refuse
     * to do, and once the session ends the web service rejects the request anyway.
     *
     * Kept apart from is_notice_available_to_user(), which may_serve_files_of() asks of a file
     * request that has no delivery to point at; may_act_on_notice() joins the two.
     *
     * @param awareness $notice Notice.
     * @return bool True when this session was handed this notice.
     * @throws \coding_exception
     */
    public static function was_notice_delivered(awareness $notice): bool {
        global $USER;

        return isset($USER->awarenessshown[$notice->get('id')]);
    }

    /**
     * Whether the logged-in user may record an interaction with this notice.
     *
     * One predicate behind dismiss, acknowledge and link tracking, so the three cannot drift.
     *
     * Two independent facts, neither implying the other. Delivery says the page-dependent rules
     * passed at some point in this session; the audience test says the other rules still hold now,
     * which catches changes between delivery and write: the notice disabled, the user removed from
     * the cohort, the role unassigned, the required course completed.
     *
     * It stops a user who is in a notice's cohort and holds its role, but is not in the course it
     * targets, from posting an acknowledgement the compliance report would show as consent. It does
     * not stop a reader who lies about their URL, since pathmatch is a client assertion on the read
     * path too: forging a write costs what forging a read already costs, and no less.
     *
     * One consequence: a notice delivered while live, then expired, then acted on after the session
     * was replaced cannot be recorded, because is_within_active_window() will not serve it again to
     * re-mint the marker.
     *
     * @param awareness $notice Notice.
     * @return bool True when an interaction may be recorded.
     * @throws \dml_exception
     * @throws \coding_exception
     */
    public static function may_act_on_notice(awareness $notice): bool {
        return self::is_notice_available_to_user($notice) && self::was_notice_delivered($notice);
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
     * @param int $action acknowledgement::ACTION_DISMISSED or ACTION_ACKNOWLEDGED.
     * @param bool $sessiononly Record in the session only, without writing the shared row.
     */
    private static function add_to_viewed_notices(awareness $notice, int $action, bool $sessiononly = false) {
        global $USER;

        /*
         * The user has now met this notice, so it stops counting as a first occurrence and gives up
         * its place at the front of the queue. Kept in step here rather than re-read, so acting on a
         * notice costs no extra statement.
         */
        $USER->awarenessinteracted[$notice->get('id')] = $notice->get('id');

        /*
         * Guests get the session marker only: every guest session shares the single guest user id,
         * so a stored row would hide the notice from every later guest. The marker is still needed,
         * because collect_user_notices() suppresses a notice only by finding it in
         * $USER->viewednotices; without it the modal reopens on every page load.
         */
        if ($sessiononly) {
            $USER->viewednotices[$notice->get('id')] = ['timeviewed' => time(), 'action' => $action];
            return;
        }

        // Add to viewed notices.
        $noticeview = noticeview::add_notice_view($notice->get('id'), $USER->id, $action);
        $USER->viewednotices[$notice->get('id')] = ['timeviewed' => $noticeview->get('timemodified'), 'action' => $action];
    }

    /**
     * Trim every criteria list to a length a single statement can carry.
     *
     * The criteria arrive as client JSON and reach get_in_or_equal() unbounded, one placeholder per
     * id: PostgreSQL refuses more than 65535 bound parameters, and long before that the estimator's
     * conditional-column query is parsed and planned at a size nobody intended. The editor's own
     * pickers cannot produce a list this long, so a request that does is hand-made.
     *
     * Trimmed rather than rejected, as a disallowed cohort id is. Deliberately not applied inside
     * estimator::normalise(), which also runs over already-stored notices: capping there would let
     * the estimate and its hash describe the first {@see self::CRITERIA_LIST_MAX} ids while
     * check_filters() kept honouring all of them.
     *
     * @param array $raw Raw criteria, as decoded from the request.
     * @return array The same criteria with every list trimmed.
     */
    public static function cap_criteria_lists(array $raw): array {
        foreach ($raw as $key => $value) {
            if (is_array($value) && count($value) > self::CRITERIA_LIST_MAX) {
                $raw[$key] = array_slice($value, 0, self::CRITERIA_LIST_MAX);
            }
        }

        return $raw;
    }

    /**
     * Whether the site-wide notice switch is on.
     *
     * Gates DELIVERY, not authoring. The editor, the manage page and the author-side web services
     * keep working while it is off, which is what makes staging a notice before publishing
     * possible; what stops is showing notices to readers and recording what they did with them.
     *
     * The setting defaults to 0, so a plain truthy read is right here; this is not a default-ON
     * checkbox where only a stored '0' counts as off. The four delivery web services and the footer
     * hook ({@see \local_awareness\local\hook_callbacks::should_load_on()}) all ask here.
     *
     * @return bool True when notices may be delivered.
     * @throws \dml_exception
     */
    public static function is_delivery_enabled(): bool {
        return !empty(get_config('local_awareness', 'enabled'));
    }

    /**
     * Whether this user already has a row of this kind for this notice.
     *
     * The acknowledgement table is the plugin's compliance record: it answers "who dismissed
     * this" and "who accepted this". No unique key backs it, so the check happens in PHP.
     *
     * The only caller is the dismissal path, deliberately. A repeat DISMISSAL of the same notice is
     * the same refusal recorded twice, and the dismissed report would list one person once per page
     * load. A repeat ACCEPTANCE is a new fact: once the author edits the notice or its reset
     * interval elapses, the earlier acceptance no longer covers the current text, so deduplicating
     * the acknowledge path would discard periodic re-acknowledgement.
     *
     * A user may therefore hold several ACKNOWLEDGED rows for one notice; acceptance_is_current()
     * turns them back into a single yes/no by reading the newest one.
     *
     * @param awareness $notice Notice.
     * @param int $userid User id.
     * @param int $action acknowledgement::ACTION_DISMISSED or ACTION_ACKNOWLEDGED.
     * @return bool True when a row already exists.
     */
    private static function has_acknowledgement_record(awareness $notice, int $userid, int $action): bool {
        global $DB;

        return $DB->record_exists('local_awareness_ack', [
            'noticeid' => $notice->get('id'),
            'userid' => $userid,
            'action' => $action,
        ]);
    }

    /**
     * Create new acknowledgement record.
     *
     * @param awareness $notice
     * @param int $action acknowledgement::ACTION_DISMISSED or ACTION_ACKNOWLEDGED.
     *
     * @return \core\persistent
     */
    private static function create_new_acknowledge_record(awareness $notice, int $action) {
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
        /*
         * A refusal is recorded wherever the notice asked for an answer, which is every level from
         * Blocking up, not only Acknowledge: the manage list offers the Dismissed report from
         * Blocking up, and an empty compliance report reads as "nobody refused", not "not recorded".
         *
         * Informational stays out. It asks nothing, so there is nothing to refuse; its dismissal is
         * carried by the event and the lastview row, and the manage list offers it no report.
         *
         * Guests stay out too: every guest session shares one user id, so the row would be nobody's.
         */
        if ($notice->get_insistence() >= awareness::INSISTENCE_BLOCKING && !$isguest) {
            /*
             * One row per reader per notice (see has_acknowledgement_record()): an insistent notice
             * is put back in front of a user who refused it, so an unguarded insert would add a row
             * on every refusal. The event below still fires each time, because a repeated refusal
             * is a real event; only the compliance row must not be duplicated.
             */
            if (!self::has_acknowledgement_record($notice, $userid, acknowledgement::ACTION_DISMISSED)) {
                // Record dismiss action.
                self::create_new_acknowledge_record($notice, acknowledgement::ACTION_DISMISSED);
            }
        }

        /*
         * Every dismissal is logged, not only those that also write a compliance row:
         * local_awareness_ack holds dismissals from Blocking up only, and local_awareness_lastview
         * keeps only each user's latest action.
         *
         * Guests stay out for the same reason their row does: the log would read as one person
         * dismissing the same notice for ever.
         */
        if (!$isguest) {
            $params = [
                'context' => self::event_context($notice),
                'objectid' => $notice->get('id'),
                'relateduserid' => $userid,
            ];
            $event = \local_awareness\event\awareness_dismissed::create($params);
            $event->trigger();
        }

        // Mark notice as viewed — session-only for a guest, so it stops reappearing for them
        // without hiding it from the next guest.
        self::add_to_viewed_notices($notice, acknowledgement::ACTION_DISMISSED, $isguest);

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
            // A guest gets the session marker only, as add_to_viewed_notices explains.
            self::add_to_viewed_notices($notice, acknowledgement::ACTION_ACKNOWLEDGED, true);
        } else if (self::check_if_already_acknowledged_by_user($notice, $USER->id)) {
            // Already acknowledged in another browser.
            return $result;
        } else if ($persistent = self::create_new_acknowledge_record($notice, acknowledgement::ACTION_ACKNOWLEDGED)) {
            // Mark notice as viewed.
            self::add_to_viewed_notices($notice, acknowledgement::ACTION_ACKNOWLEDGED);
            // Log acknowledged event.
            $params = [
                'context' => self::event_context($notice),
                'objectid' => $notice->get('id'),
                'relateduserid' => $persistent->get('usermodified'),
            ];
            $event = \local_awareness\event\awareness_acknowledged::create($params);
            $event->trigger();
        } else {
            $result['status'] = false;
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
         * post arbitrary ids and fabricate click history for a notice never aimed at them.
         *
         * Repeat clicks are deliberately not rate-limited: each click is one row of the link history
         * report source, so a throttle of any window would record a reader who clicked twice as one
         * who clicked once. The table's growth is bounded by age instead, through the
         * purge_link_history scheduled task.
         */
        $link = noticelink::get_record(['id' => $linkid]);
        if (!$link) {
            return ['status' => false];
        }

        $notice = awareness::get_record(['id' => $link->get('noticeid')]);
        if (!$notice || !self::may_act_on_notice($notice)) {
            return ['status' => false];
        }

        $data = new \stdClass();
        $data->hlinkid = $linkid;
        $data->userid = $USER->id;
        $persistent = new linkhistory(0, $data);
        $persistent->create();

        // Log link clicked event; guests returned at the top.
        $params = [
            'context' => self::event_context($notice),
            'objectid' => $linkid,
            'other' => ['noticeid' => (int) $notice->get('id')],
        ];
        $event = \local_awareness\event\awareness_link_clicked::create($params);
        $event->trigger();

        $result = [];
        $result['status'] = true;
        return $result;
    }

    /**
     * Get audience name from the audience options.
     *
     * The name is RAW, as built_cohorts_options() returns it, and so is any name in $options: the
     * caller formats it for its own sink. The two placeholders are plain text.
     *
     * @param int $cohortid Cohort id
     * @param array|null $options A cohort option list already in hand, to save resolving it again.
     *                            Callers rendering many rows pass one; everyone else omits it and
     *                            gets the ordinary lookup.
     * @return string The raw cohort name, the "all" string for 0, or '-' for a cohort not listed.
     */
    public static function get_cohort_name(int $cohortid, ?array $options = null): string {
        if ($cohortid == 0) {
            return get_string('notice:cohort:all', 'local_awareness');
        }

        $cohorts = $options ?? self::built_cohorts_options();

        // A notice outlives the cohort it targets, and cohort_get_all_cohorts() only returns the
        // cohorts visible to the caller. Either way the id can be absent, and an unguarded lookup
        // makes the whole manage-notices page fatal.
        return $cohorts[$cohortid] ?? '-';
    }

    /**
     * Whether the current user may perform an authoring verb under a scope, refusing when not.
     *
     * The one place "which capability, in which context" is decided. Every page, helper verb and
     * web service that acts as an author asks here; nothing else in the plugin checks its own
     * capabilities. Pages and web services pass the scope the request names (the site, or a
     * course); verbs acting on a stored notice pass author_scope::of($notice).
     *
     * The site capability is checked in the scope's own context, so a system-level assignment
     * inherits down and a site manager may act on a course's notice. The course capability is
     * checked only for a course scope: managecourse for the manage verb, viewreportscourse for
     * the reports verb, so a course author reads only the reports of their course's notices.
     *
     * @param author_scope $scope Who the caller is acting as.
     * @param string $verb One of the keys of VERB_CAPABILITIES.
     * @param bool $throw Whether to throw when refused, or only answer.
     * @return bool Whether the verb is allowed.
     * @throws \coding_exception For a verb the map does not know.
     * @throws \required_capability_exception When refused and $throw is set.
     */
    public static function require_author(author_scope $scope, string $verb, bool $throw = true): bool {
        if (!isset(self::VERB_CAPABILITIES[$verb])) {
            throw new \coding_exception("Unknown authoring verb '{$verb}'");
        }
        $sitecapability = self::VERB_CAPABILITIES[$verb]['site'];
        $coursecapability = self::VERB_CAPABILITIES[$verb]['course'];

        /*
         * Asked BEFORE the context is resolved: a course scope whose course is gone has no context
         * to resolve, and context_course::instance() would throw a missing-record error where a
         * refusal is owed. A course author is refused, and the notice is never read as the site's,
         * which would publish an orphaned course notice site-wide. The site capability at the system
         * context still reaches it, so an orphan can be disabled or deleted and its files removed;
         * its forced course filter keeps it from displaying meanwhile.
         */
        if (!$scope->exists()) {
            $allowed = has_capability($sitecapability, \context_system::instance());
            if (!$allowed && $throw) {
                throw new \required_capability_exception(\context_system::instance(), $sitecapability, 'nopermissions', '');
            }

            return $allowed;
        }
        $context = $scope->context();

        $allowed = has_capability($sitecapability, $context);
        if (!$allowed && !$scope->is_site() && $coursecapability !== null) {
            $allowed = has_capability($coursecapability, $context);
        }
        if (!$allowed && $throw) {
            $refused = $scope->is_site() ? $sitecapability : ($coursecapability ?? $sitecapability);
            throw new \required_capability_exception($context, $refused, 'nopermissions', '');
        }

        return $allowed;
    }

    /**
     * The notice a page names, as the current user may act on it, or null for a new one.
     *
     * Two refusals, one answer. A notice that does not exist and a notice outside the caller's
     * authority both come back as "no such notice": under a course scope the page's own gate has
     * already been passed for the URL's course, so an answer that differed between the two — a
     * redirect for one, a permission error for the other — would tell a course author whether an
     * id names a notice in someone else's course, or at the site, by trying it. The page redirects
     * to its list with the missing-notice message in both cases.
     *
     * @param int $noticeid The id from the request; 0 means a new notice.
     * @param string $verb The authoring verb the page is about to perform.
     * @return awareness|null The notice, or null for a new one.
     * @throws \moodle_exception notification:noticedoesnotexist, for a missing notice and for one outside the caller's authority.
     */
    public static function resolve_notice_as_author(int $noticeid, string $verb): ?awareness {
        $notice = self::resolve_notice($noticeid);
        if ($notice !== null && !self::require_author(author_scope::of($notice), $verb, false)) {
            throw new \moodle_exception('notification:noticedoesnotexist', 'local_awareness');
        }
        // The same answer for a notice aimed only at groups the caller may not reach: under separate
        // groups that notice is not theirs to know about, and the list never showed it.
        if ($notice !== null && !self::may_reach_groups($notice)) {
            throw new \moodle_exception('notification:noticedoesnotexist', 'local_awareness');
        }

        return $notice;
    }

    /**
     * Whether the current user may be served a notice's attachments.
     *
     * The gate local_awareness_pluginfile() stands behind, kept here so it can be tested without
     * serving a file. A file URL carries a notice id and nothing about where the reader came from,
     * so the audience is resolved the way the web-service writes resolve it, through
     * is_notice_available_to_user(). The page-dependent rules in check_filters() (category, course,
     * format, theme, competency) need a page URL this request has not got, so this gate is PARTIAL
     * by construction.
     *
     * Authors bypass it so the editor and the manage table can render an unpublished notice, judged
     * in the notice's own scope and within its group reach: a course author reaches the files of
     * their course's notices and nobody else's; a site manager, inheriting down, reaches them all.
     * Everyone else needs access to a course notice's course before the audience is even consulted.
     *
     * @param awareness $notice The notice whose files are asked for.
     * @return bool
     */
    public static function may_serve_files_of(awareness $notice): bool {
        global $DB;

        $scope = author_scope::of($notice);
        if (self::require_author($scope, 'manage', false) && self::may_reach_groups($notice)) {
            return true;
        }

        /*
         * A course notice's files are for people in that course. The display path enforces this
         * through the forced filter_course inside filtervalues, which this request cannot evaluate
         * — but courseid is a column on the row already loaded, so the same question is asked here
         * the same way check_filters() asks it: can_access_course() with active enrolments only,
         * which also admits guest access where the course allows it, exactly as display does. A
         * notice whose course is gone serves nothing to anyone but the site manager above.
         */
        if (!$scope->is_site()) {
            $course = $DB->get_record('course', ['id' => $scope->get_courseid()]);
            if (!$course || !can_access_course($course, null, '', true)) {
                return false;
            }
        }

        return self::is_notice_available_to_user($notice);
    }

    /**
     * The notice a request names, or null when it names none.
     *
     * Fails closed on an id that names nothing, so a save posted against a notice deleted in the
     * meantime, or against a forged id, cannot fall through to the create branch and silently
     * produce a duplicate without its acknowledgements. Pages call it before the form is built, so
     * no later branch can confuse "no id" with "an id that no longer exists".
     *
     * @param int $noticeid The id from the request; 0 means a new notice.
     * @return awareness|null The notice, or null for a new one.
     * @throws \moodle_exception When the id names no notice.
     */
    public static function resolve_notice(int $noticeid): ?awareness {
        if ($noticeid <= 0) {
            return null;
        }

        $notice = awareness::get_record(['id' => $noticeid]);
        if (!$notice) {
            throw new \moodle_exception('notification:noticedoesnotexist', 'local_awareness');
        }

        return $notice;
    }

    /**
     * Check if notice has already been acknowledged by a user.
     *
     * Reads the user's latest interaction ({local_awareness_lastview}) through must_reshow(), so a
     * stale acceptance does not count. When it answers true it also writes the answer into
     * $USER->viewednotices, which is only right when $userid is the current user.
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
        if (self::must_reshow($notice, (int) $latestview->timemodified, (int) $latestview->action)) {
            return false;
        }

        $USER->viewednotices[$notice->get('id')] = [
            'timeviewed' => $latestview->timemodified,
            'action' => $latestview->action,
        ];

        return true;
    }

    /**
     * Whether a notice this user has already seen has to be put in front of them again.
     *
     * Shared by collect_user_notices() and check_if_already_acknowledged_by_user(), so the display
     * path and the acknowledge path judge a recorded interaction the same way.
     *
     * The refusal clause reads the insistence level, deliberately with `>=`, so a level added above
     * Acknowledge later does not silently fall out of it.
     *
     * A refused notice comes back, but it does not jump the queue — select_for_display() leaves it
     * behind notices the reader has not met yet. That is the point of offering an exit at all: the
     * reader gets on with what they were doing, and the notice asks again. It cannot be starved
     * either, because the tier that outranks it holds only notices not yet seen, and one display
     * empties it.
     *
     * @param awareness $notice The notice being judged.
     * @param int $timeviewed When the user last acted on it.
     * @param int $action What they did — see \local_awareness\persistent\acknowledgement.
     * @return bool True when the notice must be shown again.
     */
    private static function must_reshow(awareness $notice, int $timeviewed, int $action): bool {
        $dismissed = $action === acknowledgement::ACTION_DISMISSED;

        // The notice has been edited or its repeat interval has elapsed since they acted.
        return self::interaction_is_stale($notice, $timeviewed)
            // They refused it, and it is insistent enough to ask again.
            || ($dismissed && $notice->get_insistence() >= awareness::INSISTENCE_BLOCKING);
    }

    /**
     * Whether an interaction recorded at this time no longer speaks for the notice as it stands.
     *
     * Two rules that depend only on when the user acted, not on what they did: the notice has been
     * saved since, or its reset interval has elapsed. must_reshow() applies them to a recorded view
     * to decide whether to put the modal back; acceptance_is_current() applies them to an
     * acknowledgement row, so a recorded acceptance expires instead of counting for ever.
     *
     * @param awareness $notice The notice being judged.
     * @param int $when When the user acted, as a unix timestamp.
     * @return bool True when the interaction is stale.
     */
    private static function interaction_is_stale(awareness $notice, int $when): bool {
        $resetinterval = (int) $notice->get('resetinterval');

        // The notice has been updated, reset or re-enabled since they acted.
        return $when < (int) $notice->get('timemodified')
            // Its repeat interval has elapsed.
            || ($resetinterval > 0 && $when + $resetinterval < time());
    }

    /**
     * Whether this user currently stands as having accepted this notice.
     *
     * The plugin's answer to "has user U accepted notice N", and deliberately the only public one.
     * Unlike the private predicates next door:
     *
     *  - It reads {local_awareness_ack}, the compliance record, not {local_awareness_lastview},
     *    which keeps only each user's latest interaction.
     *  - It has no side effects. check_if_already_acknowledged_by_user() writes its answer into
     *    $USER->viewednotices, which is wrong for any user but the current one.
     *  - It expires. A user may hold several ACKNOWLEDGED rows for one notice (see
     *    has_acknowledgement_record()); this reads the newest and asks whether it still speaks for
     *    the notice as it now stands.
     *
     * A dismissal is never an acceptance: both actions share the table, told apart by the action
     * column, and a caller gating access on consent must not be satisfied by a refusal.
     *
     * What expires an acceptance is more than editing. The staleness rule reads
     * {local_awareness}.timemodified, and core\persistent::update() is final and stamps that column
     * whether or not anything changed, so every authoring action that saves the notice expires every
     * acceptance on it: reset_notice() by design, and disable_notice() and enable_notice() as a side
     * effect of the save. An administrator hiding a notice for a week and putting it back has
     * expired every acceptance on record. The rows are kept, and the reports still show them, but
     * they stop counting as current. tests/consent_expiry_test.php pins this beside an untouched
     * control.
     *
     * Anything gating access on this predicate inherits that coupling: a course or activity opened
     * by acceptance closes again the next time an administrator toggles the notice's visibility.
     *
     * @param awareness $notice The notice to test.
     * @param int $userid The user to test.
     * @return bool True when a current, unexpired acceptance is on record.
     * @throws \dml_exception
     */
    public static function acceptance_is_current(awareness $notice, int $userid): bool {
        global $DB;

        $latest = $DB->get_field_sql(
            "SELECT MAX(timecreated)
               FROM {local_awareness_ack}
              WHERE noticeid = :noticeid AND userid = :userid AND action = :action",
            [
                'noticeid' => $notice->get('id'),
                'userid' => $userid,
                'action' => acknowledgement::ACTION_ACKNOWLEDGED,
            ]
        );

        if (empty($latest)) {
            return false;
        }

        return !self::interaction_is_stale($notice, (int) $latest);
    }

    /**
     * The theme the reader is actually looking at, not the one this request happens to run under.
     *
     * Inside the get_notices web service $PAGE has no course set, so moodle_page::resolve_theme()
     * skips its course and category branches and answers the site or user theme; reading
     * $PAGE->theme there would match a course-theme filter nowhere.
     *
     * A throwaway page that does know the course puts those branches back without reimplementing
     * $CFG->themeorder or the two override settings. It is built only when a theme filter exists and
     * an override is switched on, so ordinary sites pay nothing; set_course() has to come before
     * anything reads ->theme, which is why this is a fresh page rather than a mutation of $PAGE.
     *
     * @param \stdClass|null $course The course this request came from, or null when it came from none.
     * @return string The resolved theme name.
     */
    private static function current_theme_name(?\stdClass $course): string {
        global $CFG, $PAGE;

        if ($course !== null && (!empty($CFG->allowcoursethemes) || !empty($CFG->allowcategorythemes))) {
            $coursepage = new \moodle_page();
            $coursepage->set_course($course);

            return (string) $coursepage->theme->name;
        }

        return (string) $PAGE->theme->name;
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
     * Content that already holds absolute pluginfile URLs renders unchanged, because
     * file_rewrite_pluginfile_urls() replaces only the placeholder.
     *
     * @param awareness $notice Notice.
     * @return string HTML ready to place in the modal.
     * @throws \coding_exception
     */
    public static function render_content(awareness $notice): string {
        return self::render_content_parts(
            (string) $notice->get('content'),
            (int) $notice->get('contentformat'),
            (int) $notice->get('id')
        );
    }

    /**
     * Render stored notice content from the three columns it is made of.
     *
     * Same rules as render_content(), for callers holding the row rather than the persistent. The
     * report builder content column is one: a column callback is handed the row's fields, and
     * building a persistent per row would cost one extra query per report row.
     *
     * Both entry points come through here so the rules exist once, and the report and the modal
     * cannot drift apart.
     *
     * @param string $content Stored content, as the author wrote it.
     * @param int $contentformat One of the FORMAT_* constants.
     * @param int $noticeid Notice id, which is the itemid of the content file area.
     * @return string HTML ready to place in the modal.
     * @throws \coding_exception
     */
    public static function render_content_parts(string $content, int $contentformat, int $noticeid): string {
        $content = file_rewrite_pluginfile_urls(
            $content,
            'pluginfile.php',
            \context_system::instance()->id,
            'local_awareness',
            'content',
            $noticeid
        );

        return format_text($content, $contentformat, [
            'noclean' => true,
            'context' => \context_system::instance(),
        ]);
    }

    /**
     * The video layout's player, as the reader gets it.
     *
     * @param awareness $notice The notice.
     * @return string HTML; empty when the notice has no link.
     */
    public static function render_video(awareness $notice): string {
        return self::render_media_link((string) $notice->get('videourl'));
    }

    /**
     * A media link rendered by the site's multimedia filter.
     *
     * The link is wrapped in a real anchor first, because the filter embeds nothing from a bare
     * URL: its early return looks for a closing anchor, video or audio tag. Whatever the filter
     * makes of it - a video.js player for a file, an iframe for YouTube or Vimeo, or the link
     * itself when the site's players do not handle it - is what ships. Nothing here builds a
     * player by hand, so the site's own player configuration governs, on every branch.
     *
     * @param string $url The link, as stored.
     * @return string HTML; empty for an empty link.
     */
    public static function render_media_link(string $url): string {
        global $OUTPUT;

        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $anchor = $OUTPUT->render_from_template('local_awareness/media_link', ['url' => $url]);

        return format_text($anchor, FORMAT_HTML, [
            'noclean' => true,
            'context' => \context_system::instance(),
        ]);
    }

    /**
     * The carousel's slides as the dialogue renders them, in order.
     *
     * A builder over stored rows rather than a parser over the content: each slide already says
     * what it is. Image slides carry a pluginfile URL keyed by the slide id; video slides carry
     * the player the multimedia filter built; the caption is plain text, rendered through
     * format_string() with escaping off because the template's double stash escapes it once.
     *
     * @param awareness $notice The notice.
     * @return array[] Each entry has type (image|video|text), html and caption.
     */
    public static function render_slides(awareness $notice): array {
        $context = \context_system::instance();
        $slides = [];
        foreach (slide::for_notice((int) $notice->get('id')) as $slide) {
            $caption = format_string((string) $slide->get('caption'), true, ['context' => $context, 'escape' => false]);
            $type = $slide->get_mediatype();
            $html = '';
            if ($type === slide::MEDIA_IMAGE) {
                $file = $slide->get_image();
                $url = \moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                );
                $html = self::render_image_slide($url->out(false), $caption);
            } else if ($type === slide::MEDIA_VIDEO) {
                $html = self::render_media_link((string) $slide->get('videourl'));
            }
            $slides[] = ['type' => $type, 'html' => $html, 'caption' => $caption];
        }

        return $slides;
    }

    /**
     * An image slide's media, from the plugin's own template.
     *
     * Shared by the saved-notice path and the editor's preview, which hands over a draft URL.
     *
     * @param string $url The image URL.
     * @param string $alt The caption, which doubles as the alternative text.
     * @return string HTML.
     */
    public static function render_image_slide(string $url, string $alt): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_awareness/slide_image', ['url' => $url, 'alt' => $alt]);
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
     * Get course category options, formatted for the stash the autocomplete renders them through.
     *
     * Same sink and same reasoning as notice_form::course_label(): element-autocomplete.mustache
     * emits every option as a triple stash, so an admin-set category name carrying markup reaches
     * the page as markup and a multilang name reaches it as literal {mlang} text.
     *
     * The system context is deliberate rather than the category's own: it is what
     * rule_describer::category_names() passes for the same names, and the picker's label and the
     * rule chip that quotes it back are read side by side.
     *
     * @return array Category id => formatted name.
     */
    public static function get_category_options(): array {
        global $DB;

        $records = $DB->get_records('course_categories', null, 'name', 'id, name');

        $options = [];
        foreach ($records as $record) {
            $options[$record->id] = format_string($record->name, true, ['context' => \context_system::instance()]);
        }

        return $options;
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
        global $DB;

        /*
         * A request cache, not a static: several notices may name the same competency, and MUC is
         * purged between PHPUnit tests where a static is not, while generated ids repeat from one
         * test to the next. Stored as 0 or 1 because get() answers false for a miss.
         */
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'local_awareness', 'proficiency', [], ['simplekeys' => true]);
        $cachekey = "{$userid}_{$courseid}_{$competencyid}";
        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return (bool) $cached;
        }

        /*
         * Read the row; do not ask core's API for it. get_user_competency_in_course() creates the
         * user_competency_course relation when none exists, and this runs from check_filters() on
         * the local_awareness_getnotices path, which db/services.php declares 'type' => 'read'.
         * Through the API, merely loading a course page covered by a competency-filtered notice
         * would create competency state for a user nobody had assessed.
         *
         * A missing row means not proficient, which is what the absent relation means anyway.
         */
        $proficiency = $DB->get_field('competency_usercompcourse', 'proficiency', [
            'userid' => $userid,
            'courseid' => $courseid,
            'competencyid' => $competencyid,
        ]);

        $cache->set($cachekey, empty($proficiency) ? 0 : 1);

        return !empty($proficiency);
    }

    /**
     * Check if the current page matches the path pattern.
     *
     * The page is its path plus query string, as the browser reports it. Besides the FRONTPAGE, MY
     * and MYCOURSES tokens, a pattern is matched from the first character of the page, and whether
     * it must also reach the last one depends on a '%':
     *
     *  - Without a '%' the whole page must match: '/course/view.php' does not match
     *    '/course/view.php?id=2'.
     *  - With a '%' anywhere, each '%' stands for any text and the end is left open: '/mod/%/view.php'
     *    matches '/mod/forum/view.php?id=5', and also '/mod/forum/view.phpx'.
     *
     * The open end is deliberate: pages carry query strings, and stored patterns would lose reach
     * if it closed. The pathmatch_help string tells authors the same.
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

        /*
         * The pattern is anchored at the start, so a rule for '/mod/quiz/view.php' does not match
         * '/anything/mod/quiz/view.php', and at the end only when it carries no '%'. A Moodle installed
         * in a subdirectory reports '/moodle/mod/quiz/view.php' while the author may write the path
         * with or without that segment, so the pattern is tried against the target and against the
         * target with the wwwroot's own path segment removed, and either may match.
         */
        global $CFG;
        $targets = [$target];
        $wwwrootpath = rtrim((string) parse_url($CFG->wwwroot, PHP_URL_PATH), '/');
        if ($wwwrootpath !== '' && strpos($target, $wwwrootpath) === 0) {
            $targets[] = substr($target, strlen($wwwrootpath));
        }

        $pattern = preg_quote($pathmatch, '@');
        if (strpos($pattern, '%') !== false) {
            $pattern = str_replace('%', '.*', $pattern);
        } else {
            $pattern .= '$';
        }
        $pattern = '^' . $pattern;

        foreach ($targets as $candidate) {
            if (preg_match("@{$pattern}@", $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the filters match the current context.
     *
     * Path matching belongs to check_path_match(); the course here is decided by $courseid alone.
     * An empty or undecodable payload, a scalar one, or one whose lists are all empty, admits, as
     * page_probe::filters_admit() and is_notice_available_to_user() do.
     *
     * @param string|null $filtervalues JSON encoded filter values.
     * @param int $courseid The current course ID (from JS via M.cfg.courseId).
     * @return bool
     */
    public static function check_filters(?string $filtervalues, int $courseid = 0): bool {
        global $USER, $DB;

        if (empty($filtervalues)) {
            return true;
        }

        $filters = json_decode($filtervalues, true);
        if (empty($filters) || !is_array($filters)) {
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
             * accepts any {user_enrolments} row, suspended or expired ones included, so a
             * suspended participant would keep receiving the course's notices. True restricts
             * its enrolment test to active enrolments in enabled plugins, within their dates.
             * The function still admits a user with moodle/course:view and, where the course
             * allows it, guest access.
             *
             * Otherwise a user not yet enrolled fails this on the course's own enrolment page,
             * so a course- or category-targeted notice does not appear at /enrol/index.php. That
             * is intended: the alternative leaks targeted content to anyone who guesses a course
             * id. Use a cohort filter for notices meant to reach people before they enrol.
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
                $currenttheme = self::current_theme_name($course);
            } catch (\Throwable $e) {
                /*
                 * No theme can be resolved for this reader: the course sits in a category that no
                 * longer exists while category themes are on, or the page has no context. A notice
                 * aimed at named themes is withheld, as every rule whose referent cannot be
                 * resolved is. The get_notices service validates its context first, so an ordinary
                 * request never lands here.
                 */
                return false;
            }
            if (!in_array($currenttheme, $filters['filter_theme'])) {
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
     * Unlike the category, course, format, theme and competency rules beside it in check_filters(),
     * it asks about the USER alone ("do they hold role X?"), so it can be answered without a page
     * URL. That is what lets is_notice_available_to_user() apply it to writes, where the client
     * supplies a notice id and nothing trustworthy about where it came from.
     *
     * The whole $filters array is passed, not just filter_role, because filter_category and
     * filter_course also scope which contexts the role assignment is looked for in (see
     * role_scope::sql()). Passing filter_role alone would silently widen a course-scoped rule into a
     * site-wide one on the write path.
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

        /*
         * Core's two implicit roles have no {role_assignments} row. Every logged-in user but the
         * guest holds the default user role in the system context and the front page role in the
         * site course's context (get_user_accessdata()), so each counts only for a rule whose
         * context covers where it is held: the default role for any context or the system, the front
         * page role for any context only, since the site course is neither the system context nor
         * one of the courses a course-level rule means. {@see \local_awareness\audience\estimator}
         * makes the same choice in predicate().
         */
        if (isloggedin() && !isguestuser()) {
            if (!empty($CFG->defaultuserroleid) && ($rolectx == 0 || $rolectx == CONTEXT_SYSTEM)) {
                $userroleids[] = (int) $CFG->defaultuserroleid;
            }
            if (!empty($CFG->defaultfrontpageroleid) && $rolectx == 0) {
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
