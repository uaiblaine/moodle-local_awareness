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

namespace local_awareness\local;

use local_awareness\helper;
use local_awareness\persistent\awareness;

/**
 * What the current page render can tell the probe without a single database query.
 *
 * The probe decides one thing: whether to load the notice module on this page. It must stay a
 * SUPERSET of the display decision the get_notices web service makes later — a notice the service
 * would show must never be filtered out here, while a notice the service would reject may still be
 * admitted (the cost is one wasted XHR, which is the acceptable failure). To keep that guarantee,
 * admits() only ever says "no" from rules that are cheap AND safe to evaluate at render time:
 *
 * - pathmatch, tested against BOTH URL representations (the request path the browser will report,
 *   and the wwwroot-relative path) — the web service will see the browser's one, so matching either
 *   of them covers every outcome the service can reach, including subdirectory installs;
 * - the course, category and format filters, judged against $PAGE->course — the same object
 *   M.cfg.courseId (what the client sends) is derived from;
 * - the theme filter, and only while course and category theme overrides are OFF — with either on,
 *   this request may resolve a different theme than the web service request will, so the rule is
 *   not judged at all.
 *
 * Everything else — role, competency, course access — costs queries or can disagree with the
 * service, so it always counts as a match. Every unknown (no URL, a thrown exception, a missing
 * course property) also counts as a match: uncertainty loads the JS, it never silences the plugin.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class page_probe {
    /** @var string|null Path and query as the browser will report them; null when unknown. */
    private $clienturl;

    /** @var string|null The wwwroot-relative path and query; null when unknown. */
    private $localurl;

    /** @var \stdClass|null The course this page belongs to; null when this is not a course page. */
    private $course;

    /** @var string|null The resolved theme name; null when unknown or when overrides disable the rule. */
    private $theme;

    /**
     * Build a probe from explicit values.
     *
     * @param string|null $clienturl Path and query as the browser will report them, or null.
     * @param string|null $localurl The wwwroot-relative path and query, or null.
     * @param \stdClass|null $course The course this page belongs to, or null when not a course page.
     * @param string|null $theme The resolved theme name, or null to leave the theme rule unjudged.
     */
    public function __construct(?string $clienturl, ?string $localurl, ?\stdClass $course, ?string $theme) {
        $this->clienturl = $clienturl;
        $this->localurl = $localurl;
        $this->course = $course;
        $this->theme = $theme;
    }

    /**
     * Capture what the current render can safely tell us.
     *
     * Every field degrades to "unknown" rather than throwing, because an unknown only ever widens
     * what admits() accepts.
     *
     * @param \moodle_page $page The page being rendered.
     * @return self
     */
    public static function from_page(\moodle_page $page): self {
        global $CFG, $FULLME;

        /*
         * The wwwroot-relative form. has_set_url() is a pure read, so pages that never called
         * set_url() cost neither a debugging() notice nor the $FULLME guess here — and
         * out_as_local_url() throws for a non-local URL, which the catch turns into "unknown".
         */
        $localurl = null;
        try {
            if ($page->has_set_url()) {
                $localurl = $page->url->out_as_local_url(false);
            }
        } catch (\Throwable $t) {
            $localurl = null;
        }

        /*
         * The browser's form. notice.js sends window.location.pathname + window.location.search,
         * which for a normal navigation is the path and query of this very request — so $FULLME is
         * the closest server-side stand-in for what the web service will be asked about.
         */
        $clienturl = null;
        if (!empty($FULLME)) {
            $parts = parse_url($FULLME);
            if (!empty($parts['path'])) {
                $clienturl = $parts['path'];
                if (isset($parts['query']) && $parts['query'] !== '') {
                    $clienturl .= '?' . $parts['query'];
                }
            }
        }

        // The site course means "not a course page", exactly as check_filters() treats courseid 1.
        $course = null;
        try {
            if ($page->course && (int) $page->course->id > 1) {
                $course = $page->course;
            }
        } catch (\Throwable $t) {
            $course = null;
        }

        /*
         * With course or category themes enabled this render may resolve a different theme than the
         * web service request will (the service resolves without a course), and judging the rule
         * against the wrong theme would suppress a notice the service would show. Leave it unjudged.
         */
        $theme = null;
        try {
            if (empty($CFG->allowcoursethemes) && empty($CFG->allowcategorythemes)) {
                $theme = $page->theme->name;
            }
        } catch (\Throwable $t) {
            $theme = null;
        }

        return new self($clienturl, $localurl, $course, $theme);
    }

    /**
     * Whether this notice could still be shown on this page.
     *
     * A "no" is final only for the cheap page rules; see the class docblock for the contract.
     *
     * @param awareness $notice The candidate notice.
     * @return bool
     */
    public function admits(awareness $notice): bool {
        try {
            if (!$this->pathmatch_admits((string) ($notice->get('pathmatch') ?? ''))) {
                return false;
            }
            return $this->filters_admit($notice->get('filtervalues'));
        } catch (\Throwable $t) {
            // Uncertainty loads the JS; it never silences the plugin.
            return true;
        }
    }

    /**
     * The pathmatch rule, judged with the display path's own matcher against both URL forms.
     *
     * check_path_match() is the single implementation of the pattern syntax (tokens, wildcards,
     * anchoring); running it here keeps the probe unable to drift from what the web service will
     * decide. The service is asked about the browser's form, so admitting when EITHER form matches
     * makes this a superset of the service's outcome by construction.
     *
     * @param string $pathmatch The notice's pathmatch pattern; empty means everywhere.
     * @return bool
     */
    private function pathmatch_admits(string $pathmatch): bool {
        if ($pathmatch === '') {
            return true;
        }

        $targets = [];
        if ($this->clienturl !== null && $this->clienturl !== '') {
            $targets[] = $this->clienturl;
        }
        if ($this->localurl !== null && $this->localurl !== '') {
            $targets[] = $this->localurl;
        }
        if (empty($targets)) {
            // No trustworthy URL: load.
            return true;
        }

        foreach ($targets as $target) {
            if (helper::check_path_match($pathmatch, $target)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The zero-query half of the check_filters() rules; everything else counts as a match.
     *
     * Shapes deliberately mirror check_filters(): category and course ids are compared as
     * integers, format and theme as the strings the form stored. A course property this page's
     * course record does not carry is unevaluable and therefore admits.
     *
     * @param string|null $filtervalues JSON encoded filter values.
     * @return bool
     */
    private function filters_admit(?string $filtervalues): bool {
        if (empty($filtervalues)) {
            return true;
        }

        $filters = json_decode($filtervalues, true);
        if (empty($filters) || !is_array($filters)) {
            return true;
        }

        // Category filter: only shown on a course page whose category is listed.
        if (!empty($filters['filter_category'])) {
            if ($this->course === null) {
                // Not on a course page; the display path rejects for the same reason.
                return false;
            }
            if (isset($this->course->category)) {
                $filtercatids = array_map('intval', (array) $filters['filter_category']);
                if (!in_array((int) $this->course->category, $filtercatids)) {
                    return false;
                }
            }
        }

        // Course filter: only shown on a listed course's pages.
        if (!empty($filters['filter_course'])) {
            if ($this->course === null) {
                return false;
            }
            if (isset($this->course->id)) {
                $filtercourseids = array_map('intval', (array) $filters['filter_course']);
                if (!in_array((int) $this->course->id, $filtercourseids)) {
                    return false;
                }
            }
        }

        // Format filter: only shown on a course with a listed format.
        if (!empty($filters['filter_format'])) {
            if ($this->course === null) {
                return false;
            }
            if (isset($this->course->format) && !in_array($this->course->format, (array) $filters['filter_format'])) {
                return false;
            }
        }

        // Theme filter, judged only when from_page() decided the resolved theme is trustworthy.
        if (!empty($filters['filter_theme']) && $this->theme !== null) {
            if (!in_array($this->theme, (array) $filters['filter_theme'])) {
                return false;
            }
        }

        // Role, competency and course-access rules cost queries; they always admit here.
        return true;
    }
}
