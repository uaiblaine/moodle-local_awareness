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
    /** @var awareness|null */
    protected $awareness;

    /** @var string */
    protected $formhtml;

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
         * Three states, not two: a published notice whose display window has closed, or can never
         * be satisfied, is enabled and reachable by nobody, so the chip must not call it live.
         */
        $blocked = $enabled && !empty(self::window_problems_of($this->awareness));
        $statusislive = $enabled && !$blocked;

        /*
         * The form is embedded exactly as rendered. It declares its own header sections; moving its
         * rows into cards with JavaScript left fields focusable while painted nowhere.
         */
        $formhtml = $this->formhtml;

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
     * Covers a notice whose expiry has passed and one whose dates cannot both be satisfied. Only
     * ever one sentence: {@see editor_state::window_problems()} returns at most one problem.
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

        // A literal string id per branch, never one built from the constant, so a search finds every use.
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
