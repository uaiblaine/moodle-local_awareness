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

use local_awareness\persistent\awareness;
use renderable;
use renderer_base;
use templatable;

/**
 * The audience estimate panel, beside the rules it is an estimate of.
 *
 * It used to be rendered by the editor page, after the whole form — so the number describing the
 * audience sat several sections below the fields that decide it, and an author narrowing a rule
 * had to scroll past the appearance and scheduling sections to see what it did. The panel is the
 * answer to the audience section's question, so it is rendered INTO that section, by the form.
 *
 * That move is why this class exists: the context was built inside editor_page::export_for_template(),
 * which runs after the form has already been rendered and handed to it. Both sides now ask this.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience_panel implements renderable, templatable {
    /** Threshold above which the audience estimator stops auto-running. */
    public const RULE_THRESHOLD = 3;

    /** How often the client asks a queued job whether it has finished, in milliseconds. */
    public const POLL_INTERVAL_MS = 10000;

    /** How many times it asks before giving up. */
    public const POLL_MAX = 30;

    /** @var awareness|null The notice being edited, or null when creating. */
    protected $awareness;

    /**
     * Constructor.
     *
     * @param awareness|null $awareness The notice being edited, or null for a new one.
     */
    public function __construct(?awareness $awareness) {
        $this->awareness = $awareness;
    }

    /**
     * Export the panel's data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context, under the 'audience' key the template reads.
     */
    public function export_for_template(renderer_base $output) {
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
        if ($this->awareness && $this->awareness->get('audiencecount') !== null) {
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

        return ['audience' => $audience];
    }
}
