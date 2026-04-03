/**
 * AJAX course search handler for Moodle autocomplete elements.
 *
 * @module     local_awareness/course_search
 * @package
 * @author     Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax'], function(Ajax) {
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
            args: {query: query}
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
