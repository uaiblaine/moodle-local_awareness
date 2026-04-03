/**
 * User interaction with notice
 * Originally developed by Jwalit Shah <jwalitshah@catalyst-au.net> (Catalyst IT).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function () {

    /**
     * IDs of the form fields controlled by reqcourse selection.
     * @type {object}
     */
    var SELECTORS = {
        RESET_NUMBER: 'id_resetinterval_number',
        RESET_UNIT: 'id_resetinterval_timeunit',
        REQACK: 'id_reqack',
        REQCOURSE: 'id_reqcourse'
    };

    /**
     * Default values applied when a course-completion course is chosen.
     * @type {object}
     */
    var DEFAULT_VALUES = {
        RESET_NUMBER: 0,
        RESET_UNIT: '60',
        REQACK: '0'
    };

    /**
     * Check whether a course is truly selected in the autocomplete.
     *
     * Moodle autocomplete with noselectionstring uses value "" (empty)
     * when nothing is picked. Also treats "0" as no selection.
     *
     * @param {HTMLSelectElement} select
     * @returns {boolean}
     */
    var hasCourseSelected = function (select) {
        var val = select.value;
        return val !== '' && val !== '0' && parseInt(val, 10) > 0;
    };

    /**
     * Enable or disable the dependent fields.
     *
     * @param {boolean} disable
     */
    var setDependentFields = function (disable) {
        var pairs = [
            { id: SELECTORS.RESET_NUMBER, def: DEFAULT_VALUES.RESET_NUMBER },
            { id: SELECTORS.RESET_UNIT, def: DEFAULT_VALUES.RESET_UNIT },
            { id: SELECTORS.REQACK, def: DEFAULT_VALUES.REQACK }
        ];

        pairs.forEach(function (pair) {
            var el = document.getElementById(pair.id);
            if (!el) {
                return;
            }
            if (disable) {
                el.value = pair.def;
            }
            el.disabled = disable;
        });
    };

    /**
     * Attempt to bind the form logic.
     * Returns true if the select was found and bound, false otherwise.
     *
     * @returns {boolean}
     */
    var bind = function () {
        var select = document.getElementById(SELECTORS.REQCOURSE);
        if (!select) {
            return false;
        }

        // Apply initial state.
        setDependentFields(hasCourseSelected(select));

        // Listen to future changes (Moodle fires 'change' on the hidden select).
        select.addEventListener('change', function () {
            setDependentFields(hasCourseSelected(select));
        });

        return true;
    };

    return {
        /**
         * Entry-point called by Moodle js_call_amd.
         *
         * Because autocomplete elements are initialised asynchronously via
         * their own AMD module, we must wait until the hidden <select> is
         * actually present and has its final initial value.
         *
         * Strategy:
         * 1. Try immediately (works on most pages).
         * 2. Retry after a short delay (async AMD init).
         * 3. Fall back to a MutationObserver that watches for the element
         *    being inserted into the DOM.
         */
        init: function () {

            // Attempt 1: direct (DOM already complete).
            if (bind()) {
                return;
            }

            // Attempt 2: short delay after the current AMD queue finishes.
            setTimeout(function () {
                if (bind()) {
                    return;
                }

                // Attempt 3: MutationObserver as a fallback.
                var observer = new MutationObserver(function () {
                    if (bind()) {
                        observer.disconnect();
                    }
                });
                observer.observe(document.body, { childList: true, subtree: true });

                // Safety: stop observing after 10 s to avoid memory leaks.
                setTimeout(function () {
                    observer.disconnect();
                }, 10000);
            }, 200);
        }
    };
});