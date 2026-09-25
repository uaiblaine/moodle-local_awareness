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
 * Capability definitions.
 *
 * @package    local_awareness
 * @copyright  Catalyst IT
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    /*
     * RISK_XSS: a notice's content is stored PARAM_RAW, helper::render_content() formats it with
     * 'noclean' => true (notice bodies carry embedded media that clean_text() would strip), and
     * notice.js inserts the result with core's Modal.setBody(). The holder can therefore put
     * arbitrary markup in front of every user a notice reaches.
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
     * The reports name users and show their username and idnumber, so the holder sees personal
     * data about people other than themselves.
     */
    'local/awareness:viewreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    /*
     * The course-level counterpart of manage. RISK_XSS alone: a course notice's content takes the
     * same format_text(noclean) path, but the capability changes no site configuration (core draws
     * the same line between tool/monitor:managetool and tool/monitor:managerules). No archetype:
     * an administrator grants it per role.
     */
    'local/awareness:managecourse' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_XSS,
        'archetypes' => [],
    ],
    /*
     * RISK_PERSONAL like its site-level sibling: the reports of a course's notices name its users
     * and show their username and idnumber. It opens the course's notice list read-only, the
     * preview, and the reports of notices belonging to the course it is held in (the reports
     * resolve the notice first and decide in its scope). It does not manage, and managecourse does
     * not read reports. No archetype, as for managecourse.
     */
    'local/awareness:viewreportscourse' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [],
    ],
];
