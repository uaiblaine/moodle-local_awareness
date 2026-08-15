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
 * Install-time hook.
 *
 * @package    local_awareness
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs once, right after the plugin's tables are created.
 *
 * @return bool Always true; a failure to provision unaccent is not a failure to install.
 */
function xmldb_local_awareness_install() {
    /*
     * Accent-insensitive notice search needs the PostgreSQL unaccent extension, and creating it
     * is DDL that belongs here rather than on a request path. A database account without the
     * privilege simply keeps accent-sensitive search — see helper::ensure_unaccent().
     */
    \local_awareness\helper::ensure_unaccent();

    return true;
}
