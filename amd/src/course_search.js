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
 * Course picker for the notice editor's audience rules.
 *
 * Feeds the autocomplete from local_awareness_search_courses, which applies the capability check —
 * the field is only ever rendered for a manager.
 *
 * @module     local_awareness/course_search
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax'], function(Ajax) {

    /**
     * The course the editor writes for, read once from the editor root; 0 for the site.
     *
     * Every web service the editor calls takes it, so that a course author's requests are gated and
     * scoped as a course author's rather than refused at the site.
     *
     * @returns {number}
     */
    var courseId = function() {
        var root = document.querySelector('[data-region="la-editor"]');
        return root ? (parseInt(root.getAttribute('data-courseid'), 10) || 0) : 0;
    };

    /**
     * List of options (for pre-existing selections).
     * The autocomplete module calls this when rendering existing values.
     *
     * @param {String} selector The selector of the autocomplete element.
     * @param {String} query The current search query.
     * @param {Function} callback The callback to invoke with results.
     * @param {Function} failure The callback on failure.
     */
    var transport = function(selector, query, callback, failure) {
        var request = {
            methodname: 'local_awareness_search_courses',
            args: {query: query, courseid: courseId()}
        };

        Ajax.call([request])[0]
            .then(function(result) {
                var courses = JSON.parse(result.courses);
                var options = courses.map(function(course) {
                    return {
                        value: course.id,
                        label: course.fullname,
                    };
                });
                // eslint-disable-next-line promise/no-callback-in-promise
                callback(options);
                return;
            })
            .catch(failure);
    };

    /**
     * Process the AJAX results before displaying them.
     *
     * @param {String} selector The selector of the autocomplete element.
     * @param {Array} results The results from the transport function.
     * @return {Array} Processed results.
     */
    var processResults = function(selector, results) {
        return results;
    };

    return {
        transport: transport,
        processResults: processResults,
    };
});
