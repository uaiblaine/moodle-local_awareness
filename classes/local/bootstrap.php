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
 * Bootstrap major version marker for the plugin's own pages.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_awareness\local;

/**
 * Tells the stylesheet which Bootstrap major version the current site runs.
 *
 * Moodle 4.5 ships Bootstrap 4 and 5.0+ ship Bootstrap 5, and the bridging between
 * them is asymmetric: 4.5's forward bridge (theme/boost/scss/moodle/bs5-bridge.scss)
 * is 116 lines covering only g-0, btn-close, the ms/me/ps/pe spacers and
 * float/text/border/rounded-start/end, while 5.x's backward bridge (bs4-compat.scss)
 * is over a thousand lines. A BS5 utility outside that short list resolves to nothing
 * on 4.5, so styles.css carries a polyfill for the families the plugin uses.
 *
 * That polyfill must not reach 5.x. Plugin CSS loads after core's, so a rule scoped to
 * a plugin surface would outrank core's own definition and freeze 4.5's metrics onto
 * the newer branch. Gating the block on a body class only 4.5 receives is what keeps it
 * inert there.
 */
class bootstrap {
    /** @var string Body class added on sites running Bootstrap 4 (Moodle 4.5). */
    public const BODY_CLASS_BS4 = 'local-awareness-bs4';

    /** @var int First Moodle branch that ships Bootstrap 5. */
    private const FIRST_BS5_BRANCH = 500;

    /**
     * Whether the current site runs Bootstrap 4 rather than Bootstrap 5.
     *
     * @return bool True on Moodle 4.5 and older, false from Moodle 5.0 on.
     */
    public static function is_bs4(): bool {
        global $CFG;

        return (int) $CFG->branch < self::FIRST_BS5_BRANCH;
    }

    /**
     * Adds the Bootstrap 4 marker to the page when the site needs the polyfill.
     *
     * Call this from every page the plugin owns — editnotice.php and managenotice.php, which
     * is where the competency picker and rules markup renders. The notice modal is
     * deliberately NOT covered: notice.js shows it on arbitrary pages, where this marker
     * cannot be on the body, so that template uses the plugin's own .awareness scope for its
     * one weight rule rather than a Bootstrap utility.
     *
     * @return void
     */
    public static function mark_page(): void {
        global $PAGE;

        if (self::is_bs4()) {
            $PAGE->add_body_class(self::BODY_CLASS_BS4);
        }
    }
}
