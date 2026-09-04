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

namespace local_awareness\output;

use local_awareness\local\editor_state;
use local_awareness\local\author_scope;
use local_awareness\persistent\awareness;
use renderable;
use templatable;
use renderer_base;
use moodle_url;

/**
 * Renderable for the redesigned notice editor page.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editor_page implements renderable, templatable {
    /** Threshold above which the audience estimator stops auto-running. */
    public const RULE_THRESHOLD = 3;
    /** Polling interval (ms). */
    public const POLL_INTERVAL_MS = 10000;
    /** Maximum number of polls before timing out. */
    public const POLL_MAX = 30;

    /** @var awareness|null */
    protected $awareness;

    /** @var string */
    protected $formhtml;

    /**
     * Constructor.
     *
     * Takes neither a form id nor a cancel URL any more. Both were residue of the removed action
     * bar, which posted the page's own form through a form="" attribute; the form declares its own
     * buttons now. The id was extracted from the rendered HTML with a regular expression and then
     * never exported to anything.
     *
     * @param awareness|null $awareness The notice being edited, or null when creating.
     * @param string $formhtml Rendered moodleform HTML to embed.
     */
    /** @var author_scope The scope the editor writes under. */
    protected $scope;

    /**
     * Constructor.
     *
     * @param awareness|null $awareness The notice being edited, or null for a new one.
     * @param string $formhtml The rendered form.
     * @param author_scope|null $scope The scope the editor writes under; the site when not given.
     */
    public function __construct(?awareness $awareness, string $formhtml, ?author_scope $scope = null) {
        $this->awareness = $awareness;
        $this->formhtml = $formhtml;
        $this->scope = $scope ?? author_scope::site();
    }

    /**
     * Export the editor page data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output) {
        $isedit = (bool) $this->awareness;
        $enabled = $isedit && (int) $this->awareness->get('enabled') === 1;

        /*
         * Three states, not two. The badge used to read "Live · being shown" from the enabled flag
         * alone, which is a true statement about the flag and can be a false one about the world:
         * a notice whose display window has closed, or cannot be satisfied, is published and
         * unreachable at the same time. The banner below the head says so in a sentence; a reader
         * who takes in only the chip would still have been told the opposite.
         */
        $blocked = $enabled && !empty(self::window_problems_of($this->awareness));
        $statusislive = $enabled && !$blocked;

        /*
         * The form is rendered as it comes. This used to rewrite its <form> tag into a <div> so the
         * shell could re-emit the tag around a hidden copy and JavaScript could move the fields into
         * cards; the form now declares its own sections, so the surgery, the hidden copy and the
         * whole relocation step are gone — along with the class of bug where a field the map forgot
         * stayed reachable by keyboard while being painted nowhere.
         */
        $formhtml = $this->formhtml;

        /*
         * On a site above the interactive limit the editor must not estimate on its own: each
         * auto-fire is a scan of every user row, and the author triggers one by typing. The panel
         * still offers the button; it just waits to be asked.
         */
        $islive = \local_awareness\audience\live_mode::is_live();

        $audience = [
            'autotrigger' => $islive,
            'auto' => $islive ? 1 : 0,
            'threshold' => self::RULE_THRESHOLD,
            'poll_interval_ms' => self::POLL_INTERVAL_MS,
            'poll_max' => self::POLL_MAX,
            'summary' => [
                ['key' => 'cohorts', 'label' => get_string('audience:summary:cohorts', 'local_awareness'), 'value' => 0],
                ['key' => 'courses', 'label' => get_string('audience:summary:courses', 'local_awareness'), 'value' => 0],
                ['key' => 'role', 'label' => get_string('audience:summary:role', 'local_awareness'), 'value' => 0],
                ['key' => 'competencies', 'label' => get_string('audience:summary:competencies', 'local_awareness'), 'value' => 0],
            ],
            'hascount' => false,
            'initialcountformatted' => '—',
            'initialstate_idle' => true,
            'initialstate_cached' => false,
            'cachedlabel' => '',
            'contextrules' => [],
        ];

        /*
         * A saved notice already carries a count, and on a site that does not estimate
         * interactively it is the only one the author will see without asking. Render it server
         * side so the panel is populated before any JavaScript runs, and say plainly when it
         * describes filters the notice no longer has.
         */
        if ($isedit && $this->awareness->get('audiencecount') !== null) {
            $state = \local_awareness\audience\notice_audience::state_of($this->awareness);
            $when = userdate(
                (int) $this->awareness->get('audiencecomputed'),
                get_string('strftimedatetimeshort')
            );

            $audience['hascount'] = true;
            $audience['initialcountformatted'] = get_string(
                'audience:reach:value',
                'local_awareness',
                number_format((int) $this->awareness->get('audiencecount'))
            );
            $audience['initialstate_idle'] = false;
            $audience['initialstate_cached'] = true;
            $audience['cachedlabel'] = get_string(
                $state === \local_awareness\audience\notice_audience::STATE_STALE
                    ? 'notice:audience:stale'
                    : 'notice:audience:computed',
                'local_awareness',
                $when
            );
        }

        return [
            'pagetitle' => $isedit
                ? get_string('editor:title:edit', 'local_awareness')
                : get_string('editor:title:create', 'local_awareness'),
            'subtitle' => get_string('editor:subtitle', 'local_awareness'),
            'statuslabel' => self::status_label($statusislive, $blocked),
            'statusislive' => $statusislive,
            'statusisblocked' => $blocked,
            'savedlabel' => $isedit
                ? get_string(
                    'editor:saved',
                    'local_awareness',
                    userdate((int) $this->awareness->get('timemodified'), get_string('strftimedatetimeshort'))
                )
                : '',
            'unsavedlabel' => get_string('editor:unsaved', 'local_awareness'),
            'requirements' => self::window_warning($this->awareness),
            'formhtml' => $formhtml,
            // Read by every module that calls a web service: the scope travels with each request.
            'courseid' => $this->scope->get_courseid(),
            'helptitle' => get_string('editor:nav:howitworks', 'local_awareness'),
            'helpbody' => get_string('editor:nav:howitworks:body', 'local_awareness'),
            'audience' => $audience,
        ];
    }

    /**
     * The reasons this notice can never be displayed, or none.
     *
     * One reader for the badge and the banner, so the chip and the sentence under it cannot
     * disagree about the same notice.
     *
     * @param awareness|null $awareness The notice being edited, or null when creating.
     * @return array Zero or more of editor_state's WINDOW_* constants.
     */
    private static function window_problems_of(?awareness $awareness): array {
        if (!$awareness) {
            return [];
        }

        return editor_state::window_problems(
            (int) $awareness->get('enabled'),
            (int) $awareness->get('timestart'),
            (int) $awareness->get('timeend'),
            time()
        );
    }

    /**
     * What the status chip says.
     *
     * @param bool $islive Published and actually reachable.
     * @param bool $isblocked Published, and reachable by nobody.
     * @return string
     */
    private static function status_label(bool $islive, bool $isblocked): string {
        if ($isblocked) {
            return get_string('editor:status:blocked', 'local_awareness');
        }

        return $islive
            ? get_string('editor:status:live', 'local_awareness')
            : get_string('editor:status:draft', 'local_awareness');
    }

    /**
     * The sentence to put above the form when a published notice can never actually appear.
     *
     * The page head paints "Live · being shown" from the enabled flag alone, which is a true
     * statement about the flag and can be a false one about the world: a notice whose expiry has
     * passed, or whose dates cannot both be satisfied, is enabled and unreachable at once. The
     * banner existed in the template, the CSS and two language packs and could never render,
     * because this method returned an empty string.
     *
     * Only ever one sentence: editor_state returns at most one problem, and a wall of warnings is
     * how a warning stops being read.
     *
     * @param awareness|null $awareness The notice being edited, or null when creating.
     * @return string The warning, or an empty string when there is nothing wrong.
     */
    private static function window_warning(?awareness $awareness): string {
        $problems = self::window_problems_of($awareness);

        if (empty($problems)) {
            return '';
        }

        $when = userdate((int) $awareness->get('timeend'), get_string('strftimedatetimeshort'));

        /*
         * A literal per branch rather than a key built from the constant. The fleet rule against
         * dynamic string ids is what keeps `grep editor:warning:` able to find every one of them.
         */
        switch ($problems[0]) {
            case editor_state::WINDOW_EXPIRED:
                return get_string('editor:warning:window_expired', 'local_awareness', $when);
            case editor_state::WINDOW_INVERTED:
                return get_string('editor:warning:window_inverted', 'local_awareness');
            default:
                return '';
        }
    }
}
