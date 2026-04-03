/**
 * User interaction with notice
 * Originally developed by Jwalit Shah <jwalitshah@catalyst-au.net>
 * (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
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
        REQCOURSE: 'id_reqcourse',
        COMPETENCY_FILTER_CONTAINER: 'awareness-competency-filter',
        COMPETENCY_ADD_BUTTON: 'id_awareness_add_competencies',
        COMPETENCY_RULES_CONTAINER: 'id_awareness_competency_rules',
        COMPETENCY_RULES_INPUT: 'id_filter_competency_rules',
        COMPETENCY_REQUIREALL_INPUT: 'id_filter_competency_requireall',
        COMPETENCY_REQUIREALL_WRAPPER: 'fitem_id_filter_competency_requireall'
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

    /**
     * Safe JSON parser.
     *
     * @param {string} raw
     * @returns {Array}
     */
    var parseRules = function (raw) {
        if (!raw) {
            return [];
        }

        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    };

    /**
     * Escape HTML entities.
     *
     * @param {string} value
     * @returns {string}
     */
    var escapeHtml = function (value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    /**
     * Render and keep competency rules in sync with hidden inputs.
     */
    var initCompetencyFilter = function () {
        var container = document.getElementById(SELECTORS.COMPETENCY_FILTER_CONTAINER);
        var addButton = document.getElementById(SELECTORS.COMPETENCY_ADD_BUTTON);
        var rulesContainer = document.getElementById(SELECTORS.COMPETENCY_RULES_CONTAINER);
        var rulesInput = document.getElementById(SELECTORS.COMPETENCY_RULES_INPUT);
        var requireAllInput = document.getElementById(SELECTORS.COMPETENCY_REQUIREALL_INPUT);
        var requireAllWrapper = document.getElementById(SELECTORS.COMPETENCY_REQUIREALL_WRAPPER);

        if (!container || !addButton || !rulesContainer || !rulesInput || !requireAllInput) {
            return;
        }

        var proficientLabel = container.getAttribute('data-proficient-label') || 'Proficient';
        var yesLabel = container.getAttribute('data-yes-label') || 'Yes';
        var noLabel = container.getAttribute('data-no-label') || 'No';
        var removeLabel = container.getAttribute('data-remove-label') || 'Remove';

        var rules = parseRules(rulesInput.value).map(function (rule) {
            var id = parseInt(rule.id || rule.competencyid || 0, 10);
            return {
                id: id,
                name: rule.name || ('#' + id),
                proficient: parseInt(rule.proficient || 0, 10) === 1 ? 1 : 0
            };
        }).filter(function (rule) {
            return rule.id > 0;
        });

        var syncRulesInput = function () {
            var serializable = rules.map(function (rule) {
                return {
                    id: rule.id,
                    name: rule.name,
                    proficient: rule.proficient
                };
            });
            rulesInput.value = JSON.stringify(serializable);
        };

        var toggleRequireAllVisibility = function () {
            if (!requireAllWrapper) {
                return;
            }

            if (rules.length > 1) {
                requireAllWrapper.style.display = '';
            } else {
                requireAllInput.value = '0';
                requireAllWrapper.style.display = 'none';
            }
        };

        var applyRequireAllMode = function () {
            var requireAll = parseInt(requireAllInput.value, 10) === 1;

            var selects = rulesContainer.querySelectorAll('.awareness-competency-proficient');
            selects.forEach(function (select, index) {
                if (requireAll) {
                    rules[index].proficient = 1;
                    select.value = '1';
                    select.disabled = true;
                } else {
                    select.disabled = false;
                }
            });

            syncRulesInput();
        };

        var renderRules = function () {
            if (!rules.length) {
                rulesContainer.innerHTML = '';
                toggleRequireAllVisibility();
                syncRulesInput();
                return;
            }

            var html = '<div class="border rounded p-2">';
            rules.forEach(function (rule, index) {
                html += '<div class="d-flex align-items-center gap-2 mb-2 awareness-competency-row" ' +
                    'data-index="' + index + '">';
                html += '<div class="flex-grow-1"><strong>' + escapeHtml(rule.name) + '</strong></div>';
                html += '<label class="mb-0" for="awareness-competency-proficient-' + index + '">';
                html += escapeHtml(proficientLabel) + '</label>';
                html += '<select id="awareness-competency-proficient-' + index + '" ' +
                    'class="form-select form-select-sm awareness-competency-proficient" ' +
                    'style="max-width: 100px;">';
                html += '<option value="1"' + (rule.proficient === 1 ? ' selected' : '') + '>' + escapeHtml(yesLabel) + '</option>';
                html += '<option value="0"' + (rule.proficient === 0 ? ' selected' : '') + '>' + escapeHtml(noLabel) + '</option>';
                html += '</select>';
                html += '<button type="button" class="btn btn-link text-danger p-0 awareness-competency-remove" ' +
                    'data-index="' + index + '">';
                html += escapeHtml(removeLabel) + '</button>';
                html += '</div>';
            });
            html += '</div>';
            rulesContainer.innerHTML = html;

            rulesContainer.querySelectorAll('.awareness-competency-proficient').forEach(function (select) {
                select.addEventListener('change', function (event) {
                    var row = event.target.closest('.awareness-competency-row');
                    if (!row) {
                        return;
                    }
                    var index = parseInt(row.getAttribute('data-index'), 10);
                    rules[index].proficient = parseInt(event.target.value, 10) === 1 ? 1 : 0;
                    syncRulesInput();
                });
            });

            rulesContainer.querySelectorAll('.awareness-competency-remove').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    var index = parseInt(event.currentTarget.getAttribute('data-index'), 10);
                    rules.splice(index, 1);
                    renderRules();
                });
            });

            toggleRequireAllVisibility();
            applyRequireAllMode();
            syncRulesInput();
        };

        requireAllInput.addEventListener('change', function () {
            applyRequireAllMode();
        });

        var addRulesFromPicker = function (selectedRules) {
            selectedRules.forEach(function (selectedRule) {
                var exists = rules.some(function (rule) {
                    return rule.id === selectedRule.id;
                });
                if (!exists) {
                    rules.push(selectedRule);
                }
            });
            renderRules();
        };

        addButton.addEventListener('click', function () {
            var contextid = parseInt(container.getAttribute('data-contextid'), 10);
            if (!contextid) {
                return;
            }

            require(['tool_lp/competencypicker', 'core/ajax', 'core/notification'], function (Picker, Ajax, Notification) {
                try {
                    var picker = new Picker(contextid, false, 'parents', true);
                    picker.on('save', function (e, data) {
                        var ids = Array.isArray(data.competencyIds) ? data.competencyIds : [];
                        ids = ids.map(function (id) {
                            return parseInt(id, 10);
                        }).filter(function (id) {
                            return id > 0;
                        });

                        if (!ids.length) {
                            return;
                        }

                        var requests = ids.map(function (id) {
                            return Ajax.call([{
                                methodname: 'core_competency_read_competency',
                                args: {id: id}
                            }])[0];
                        });

                        Promise.all(requests).then(function (responses) {
                            var selectedRules = ids.map(function (id, index) {
                                var response = responses[index] || {};
                                return {
                                    id: id,
                                    name: response.shortname || ('#' + id),
                                    proficient: 1
                                };
                            });
                            addRulesFromPicker(selectedRules);
                            return null;
                        }).catch(Notification.exception);
                    });

                    picker.display();
                } catch (error) {
                    Notification.exception(error);
                }
            });
        });

        renderRules();
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
            initCompetencyFilter();

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
