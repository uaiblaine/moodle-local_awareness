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

namespace local_awareness\event;

/**
 * Notice link clicked event.
 *
 * The object is the LINK, not the click record: a click neither creates nor changes the link, it
 * follows it. That is why crud is 'r' here while local_awareness_tracklink is declared 'write' in
 * db/services.php — the write is the history row in local_awareness_hlinks_his, and this event is
 * a second record of the same act, reachable by an admin who cannot read that table.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class awareness_link_clicked extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_awareness_hlinks';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Description.
     * @return string
     */
    public function get_description() {
        $noticeid = $this->other['noticeid'] ?? 0;
        return "The user with id '$this->userid' clicked the link with id '$this->objectid' " .
            "in the notice with id '$noticeid'";
    }

    /**
     * Gets name.
     * @return \lang_string|string
     */
    public static function get_name() {
        return get_string('event:clicklink', 'local_awareness');
    }

    /**
     * Gets URL.
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/awareness/managenotice.php');
    }
}
