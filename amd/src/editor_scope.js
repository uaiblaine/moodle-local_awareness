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
 * The scope the notice editor writes under, as the page declares it.
 *
 * One reader for every editor module that calls a web service, so that they cannot disagree about
 * which course a request is for.
 *
 * @module     local_awareness/editor_scope
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const EDITOR = '[data-region="la-editor"]';

/**
 * The course the editor writes for; 0 for the site.
 *
 * Read from the editor root, which classes/output/editor_page.php renders from the scope the page
 * resolved. Never from the URL: editnotice.php lets a notice's own scope win over its courseid
 * parameter, so a course notice opened without one is still edited as a course notice.
 *
 * @returns {number}
 */
export const courseId = () => {
    const root = document.querySelector(EDITOR);

    return root ? (parseInt(root.getAttribute('data-courseid'), 10) || 0) : 0;
};
