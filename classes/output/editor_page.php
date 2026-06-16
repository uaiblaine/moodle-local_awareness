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
use templatable;
use renderer_base;
use moodle_url;

/**
 * Renderable for the redesigned notice editor page.
 *
 * @package    local_awareness
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
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

    /** @var string */
    protected $formid;

    /** @var moodle_url */
    protected $cancelurl;

    /**
     * Constructor.
     *
     * @param awareness|null $awareness The notice being edited, or null when creating.
     * @param string $formhtml Rendered moodleform HTML to embed.
     * @param string $formid The DOM id of the embedded form.
     * @param moodle_url $cancelurl URL to return to on cancel.
     */
    public function __construct(?awareness $awareness, string $formhtml, string $formid, moodle_url $cancelurl) {
        $this->awareness = $awareness;
        $this->formhtml = $formhtml;
        $this->formid = $formid;
        $this->cancelurl = $cancelurl;
    }

    /**
     * Export the editor page data for the Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output) {
        $isedit = (bool) $this->awareness;
        $statusislive = $isedit && (int) $this->awareness->get('enabled') === 1;

        $formattributes = '';
        $formhtml = $this->formhtml;
        if (preg_match('/<form\b([^>]*\bid="([^"]+)"[^>]*)>/', $formhtml, $m)) {
            $formattributes = $m[1];
            $formattributes = preg_replace('/\s*class=[\'"][^\'"]*[\'"]/', '', $formattributes);
        }
        $formhtml = preg_replace('/<form\b[^>]*>/', '<div class="la-mform-wrapper">', $formhtml);
        $formhtml = preg_replace('/<\/form>/', '</div>', $formhtml);

        $sections = [
            ['id' => 'sec-content', 'num' => '01', 'icon' => 'fa-file-text-o',
             'title' => get_string('editor:section:content', 'local_awareness'),
             'desc' => get_string('editor:section:content:desc', 'local_awareness')],
            ['id' => 'sec-behavior', 'num' => '02', 'icon' => 'fa-sliders',
             'title' => get_string('editor:section:behavior', 'local_awareness'),
             'desc' => get_string('editor:section:behavior:desc', 'local_awareness')],
            ['id' => 'sec-appearance', 'num' => '03', 'icon' => 'fa-arrows-alt',
             'title' => get_string('editor:section:appearance', 'local_awareness'),
             'desc' => get_string('editor:section:appearance:desc', 'local_awareness')],
            ['id' => 'sec-audience', 'num' => '04', 'icon' => 'fa-users',
             'title' => get_string('editor:section:audience', 'local_awareness'),
             'desc' => get_string('editor:section:audience:desc', 'local_awareness')],
            ['id' => 'sec-filters', 'num' => '05', 'icon' => 'fa-filter',
             'title' => get_string('editor:section:filters', 'local_awareness'),
             'desc' => get_string('editor:section:filters:desc', 'local_awareness')],
        ];

        $sidenavitems = [];
        foreach ($sections as $i => $s) {
            $sidenavitems[] = [
                'id' => $s['id'],
                'num' => $s['num'],
                'icon' => $s['icon'],
                'label' => $s['title'],
                'active' => $i === 0,
            ];
        }

        $preview = [
            'title' => $isedit ? $this->awareness->get('title') : '',
            'contentpreview' => $isedit ? self::truncate(strip_tags($this->awareness->get('content')), 200) : '',
            'bgimageurl' => $isedit && (int) $this->awareness->get('bgimage') === 1
                ? \local_awareness\helper::get_bgimage_url((int) $this->awareness->get('id'))
                : '',
            'modal_width' => $isedit ? (string) $this->awareness->get('modal_width') : '',
            'modal_height' => $isedit ? (string) $this->awareness->get('modal_height') : '',
            'reqack' => $isedit && (int) $this->awareness->get('reqack') === 1,
            'outsideclick' => $isedit ? (int) $this->awareness->get('outsideclick') === 1 : true,
            'forcelogout' => $isedit && (int) $this->awareness->get('forcelogout') === 1,
            'frequency' => $isedit ? self::format_interval((int) $this->awareness->get('resetinterval')) : '0',
            'statusislive' => $statusislive,
        ];

        $audience = [
            'autotrigger' => true,
            'threshold' => self::RULE_THRESHOLD,
            'poll_interval_ms' => self::POLL_INTERVAL_MS,
            'poll_max' => self::POLL_MAX,
            'summary' => [
                ['key' => 'cohorts', 'label' => get_string('audience:summary:cohorts', 'local_awareness'), 'value' => 0],
                ['key' => 'courses', 'label' => get_string('audience:summary:courses', 'local_awareness'), 'value' => 0],
                ['key' => 'role', 'label' => get_string('audience:summary:role', 'local_awareness'), 'value' => 0],
                ['key' => 'competencies', 'label' => get_string('audience:summary:competencies', 'local_awareness'), 'value' => 0],
            ],
            'initialcount' => null,
            'initialcountformatted' => '—',
            'initialstate_idle' => true,
            'initialstate_cached' => false,
            'cachedlabel' => '',
            'contextrules' => [],
        ];

        $actionbar = [
            'formid' => $this->formid,
            'cancelurl' => $this->cancelurl->out(false),
            'cansavedraft' => false, // Save-draft path is identical to publish in this plugin (the form has a single submit).
            'canpublish' => true,
            'disablepublish' => false,
        ];

        return [
            'pagetitle' => $isedit
                ? get_string('editor:title:edit', 'local_awareness')
                : get_string('editor:title:create', 'local_awareness'),
            'subtitle' => get_string('editor:subtitle', 'local_awareness'),
            'statuslabel' => $statusislive
                ? get_string('editor:status:live', 'local_awareness')
                : get_string('editor:status:draft', 'local_awareness'),
            'statusislive' => $statusislive,
            'autosaved' => '',
            'requirements' => '',
            'form_attributes' => $formattributes,
            'formhtml' => $formhtml,
            'sections' => $sections,
            'sidenav' => [
                'items' => $sidenavitems,
                'helptitle' => get_string('editor:nav:howitworks', 'local_awareness'),
                'helpbody' => get_string('editor:nav:howitworks:body', 'local_awareness'),
            ],
            'preview' => $preview,
            'audience' => $audience,
            'actionbar' => $actionbar,
        ];
    }

    /**
     * Truncate to N chars on a word boundary, appending an ellipsis.
     *
     * @param string $text The text to truncate.
     * @param int $max Maximum length in characters.
     * @return string The truncated text.
     */
    private static function truncate(string $text, int $max): string {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (\core_text::strlen($text) <= $max) {
            return $text;
        }
        return rtrim(\core_text::substr($text, 0, $max)) . '…';
    }

    /**
     * Format a reset-interval (seconds) into a human-readable string.
     *
     * @param int $seconds The interval in seconds.
     * @return string Human-readable duration, or '0'.
     */
    private static function format_interval(int $seconds): string {
        if ($seconds <= 0) {
            return '0';
        }
        return format_time($seconds);
    }
}
