/**
 * AJAX role search handler for Moodle autocomplete elements.
 *
 * @module     local_awareness/role_search
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'jquery'], function(Ajax, $) {

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

    var transport = function(selector, query, callback, failure) {
        var contextSelect = $('#id_filter_role_context');
        var contextLevel = contextSelect.length ? contextSelect.val() : 0;

        var request = {
            methodname: 'local_awareness_search_roles',
            args: {
                query: query,
                contextlevel: parseInt(contextLevel, 10) || 0,
                courseid: courseId()
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

    var processResults = function(selector, results) {
        return results;
    };

    return {
        transport: transport,
        processResults: processResults,
    };
});
