/**
 * AJAX role search handler for Moodle autocomplete elements.
 *
 * @module     local_awareness/role_search
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'jquery'], function(Ajax, $) {
    var transport = function(selector, query, callback, failure) {
        var contextSelect = $('#id_filter_role_context');
        var contextLevel = contextSelect.length ? contextSelect.val() : 0;

        var request = {
            methodname: 'local_awareness_search_roles',
            args: {
                query: query,
                contextlevel: parseInt(contextLevel, 10) || 0
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
