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
 * Capability to manage notice
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    /*
     * RISK_XSS is not decoration. A notice's content is PARAM_RAW from the form to the persistent,
     * helper::render_content() passes it through format_text() with 'noclean' => true, and
     * notice.js hands the result to core's Modal.setBody(), which is innerHTML. So this capability
     * lets its holder put arbitrary markup in front of every logged-in user on the site, which is
     * exactly what Moodle's risk model calls RISK_XSS — and declaring only RISK_CONFIG hid that
     * from the "Check permissions" report and from anyone reviewing who should hold it.
     *
     * The noclean is deliberate: notice bodies legitimately carry embedded media that clean_text()
     * would strip. Trusting the author is a defensible choice; leaving the trust undeclared is not.
     */
    'local/awareness:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG | RISK_XSS,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    /*
     * The reports name users and carry their email and idnumber, so the holder sees personal data
     * about people other than themselves.
     */
    'local/awareness:viewreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
