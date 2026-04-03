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
 * Notice Dismissed event
 *
 * @package    local_awareness
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class awareness_dismissed extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_awareness';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Description.
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->relateduserid' dismissed the notice with id '$this->objectid'";
    }

    /**
     * Gets name.
     * @return \lang_string|string
     */
    public static function get_name() {
        return get_string('event:dismiss', 'local_awareness');
    }

    /**
     * Gets URL.
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/awareness/managenotice.php');
    }
}
