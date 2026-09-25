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
 * Role picker for the notice editor's audience rules.
 *
 * Feeds the autocomplete from local_awareness_search_roles, which applies the capability check for
 * the editor's scope and offers the roles that can be held at the chosen role context.
 *
 * @module     local_awareness/role_search
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'jquery', 'local_awareness/editor_scope'], function(Ajax, $, EditorScope) {

    /**
     * Fetch the roles matching a search.
     *
     * core/form-autocomplete calls this as the author types; existing selections are rendered by the
     * form itself. The role context select narrows the list at the site; under a course scope the
     * server answers for the course whatever level is sent.
     *
     * @param {String} selector The selector of the autocomplete element.
     * @param {String} query The current search query.
     * @param {Function} callback The callback to invoke with results.
     * @param {Function} failure The callback on failure.
     */
    var transport = function(selector, query, callback, failure) {
        var contextSelect = $('#id_filter_role_context');
        var contextLevel = contextSelect.length ? contextSelect.val() : 0;

        var request = {
            methodname: 'local_awareness_search_roles',
            args: {
                query: query,
                contextlevel: parseInt(contextLevel, 10) || 0,
                courseid: EditorScope.courseId()
            }
        };

        Ajax.call([request])[0]
            .then(function(result) {
                var roles = JSON.parse(result.roles);
                var options = roles.map(function(role) {
                    return {
                        value: role.id,
                        label: role.name,
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
