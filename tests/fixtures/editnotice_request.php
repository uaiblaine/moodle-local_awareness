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
 * Runs editnotice.php as one request, inside the test method that includes this file.
 *
 * The page's top-level code runs in the including method's scope and reads core's globals there,
 * so they are bound here rather than in the method, where the ones only the page reads would look
 * unused. Each run is a request of its own: a page already printed cannot be set up again, so the
 * page and its renderer start fresh.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

$PAGE = new moodle_page();
$OUTPUT = new bootstrap_renderer();

require($CFG->dirroot . '/local/awareness/editnotice.php');
