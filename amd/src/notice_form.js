/**
 * User interaction with notice form.
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function () {

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

    var DEFAULT_VALUES = {
        RESET_NUMBER: 0,
        RESET_UNIT: '60',
        REQACK: '0'
    };

    // ───────────────────────────────────────────
    // Course-completion field logic
    // ───────────────────────────────────────────

    var hasCourseSelected = function (select) {
        var val = select.value;
        return val !== '' && val !== '0' && parseInt(val, 10) > 0;
    };

    var setDependentFields = function (disable) {
        [{id: SELECTORS.RESET_NUMBER, def: DEFAULT_VALUES.RESET_NUMBER},
         {id: SELECTORS.RESET_UNIT, def: DEFAULT_VALUES.RESET_UNIT},
         {id: SELECTORS.REQACK, def: DEFAULT_VALUES.REQACK}
        ].forEach(function (pair) {
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

    var bind = function () {
        var select = document.getElementById(SELECTORS.REQCOURSE);
        if (!select) {
            return false;
        }
        if (select.getAttribute('data-awareness-bound') === '1') {
            return true;
        }
        select.setAttribute('data-awareness-bound', '1');
        setDependentFields(hasCourseSelected(select));
        select.addEventListener('change', function () {
            setDependentFields(hasCourseSelected(select));
        });
        return true;
    };

    // ───────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────

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
     * Flatten a competency tree into a list of items with indent levels
     * suitable for the competency_picker_items template.
     *
     * @param {Array} tree  Nodes from buildCompetencyTree
     * @param {Array} existingIds  Already-selected competency IDs
     * @param {number} depth  Current nesting depth
     * @returns {Array}
     */
    var flattenTree = function (tree, existingIds, depth) {
        depth = depth || 0;
        var items = [];
        tree.forEach(function (node) {
            var comp = node.data;
            items.push({
                id: comp.id,
                shortname: comp.shortname,
                idnumber: comp.idnumber || '',
                indent: 8 + (depth * 20),
                existing: existingIds.indexOf(comp.id) !== -1,
                parent: node.children.length > 0
            });
            if (node.children.length) {
                items = items.concat(flattenTree(node.children, existingIds, depth + 1));
            }
        });
        return items;
    };

    var buildCompetencyTree = function (flatList) {
        var tree = [];
        var map = {};
        flatList.forEach(function (item) {
            map[item.id] = {data: item, children: []};
        });
        flatList.forEach(function (item) {
            var node = map[item.id];
            if (item.parentid == 0 || !map[item.parentid]) {
                tree.push(node);
            } else {
                map[item.parentid].children.push(node);
            }
        });
        return tree;
    };

    // ───────────────────────────────────────────
    // Competency filter
    // ───────────────────────────────────────────

    var initCompetencyFilter = function () {
        var container = document.getElementById(SELECTORS.COMPETENCY_FILTER_CONTAINER);
        var addButton = document.getElementById(SELECTORS.COMPETENCY_ADD_BUTTON);
        var rulesContainer = document.getElementById(SELECTORS.COMPETENCY_RULES_CONTAINER);
        var rulesInput = document.getElementById(SELECTORS.COMPETENCY_RULES_INPUT)
            || document.querySelector('input[name="filter_competency_rules"]');
        var requireAllInput = document.getElementById(SELECTORS.COMPETENCY_REQUIREALL_INPUT);
        var requireAllWrapper = document.getElementById(SELECTORS.COMPETENCY_REQUIREALL_WRAPPER);

        if (!container || !addButton || !rulesContainer || !rulesInput || !requireAllInput) {
            return false;
        }
        if (container.getAttribute('data-initialized') === '1') {
            return true;
        }
        container.setAttribute('data-initialized', '1');

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
            rulesInput.value = JSON.stringify(rules.map(function (r) {
                return {id: r.id, name: r.name, proficient: r.proficient};
            }));
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
            rulesContainer.querySelectorAll('.awareness-competency-proficient').forEach(function (sel, i) {
                if (requireAll) {
                    rules[i].proficient = 1;
                    sel.value = '1';
                    sel.disabled = true;
                } else {
                    sel.disabled = false;
                }
            });
            syncRulesInput();
        };

        // ─── Rules rendering (Mustache template) ───
        var renderRules = function () {
            if (!rules.length) {
                rulesContainer.innerHTML = '';
                toggleRequireAllVisibility();
                syncRulesInput();
                return;
            }

            var context = {
                hasRules: true,
                proficientLabel: proficientLabel,
                yesLabel: yesLabel,
                noLabel: noLabel,
                removeLabel: removeLabel,
                rules: rules.map(function (rule, index) {
                    return {
                        index: index,
                        name: rule.name,
                        proficientSelected: rule.proficient === 1
                    };
                })
            };

            require(['core/templates'], function (Templates) {
                Templates.renderForPromise('local_awareness/competency_rules', context)
                    .then(function (result) {
                        rulesContainer.innerHTML = result.html;
                        if (result.js) {
                            Templates.runTemplateJS(result.js);
                        }
                        toggleRequireAllVisibility();
                        applyRequireAllMode();
                        syncRulesInput();
                        return null;
                    })
                    .catch(function () {
                        // Fallback: basic rendering if template fails.
                        rulesContainer.innerHTML = '<div class="alert alert-warning">Error rendering rules.</div>';
                    });
            });
        };

        // ─── Event delegation on rulesContainer (attach once) ───
        rulesContainer.addEventListener('change', function (event) {
            if (!event.target.matches('.awareness-competency-proficient')) {
                return;
            }
            var row = event.target.closest('.awareness-competency-row');
            if (row) {
                var idx = parseInt(row.getAttribute('data-index'), 10);
                rules[idx].proficient = parseInt(event.target.value, 10) === 1 ? 1 : 0;
                syncRulesInput();
            }
        });

        rulesContainer.addEventListener('click', function (event) {
            var btn = event.target.closest('.awareness-competency-remove');
            if (btn) {
                var idx = parseInt(btn.getAttribute('data-index'), 10);
                rules.splice(idx, 1);
                renderRules();
            }
        });

        requireAllInput.addEventListener('change', function () {
            applyRequireAllMode();
        });

        // ─── Add-from-picker helper ───
        var addRulesFromPicker = function (selectedRules) {
            selectedRules.forEach(function (sr) {
                if (!rules.some(function (r) { return r.id === sr.id; })) {
                    rules.push(sr);
                }
            });
            renderRules();
        };

        // ─── Competency picker modal (ModalSaveCancel + Mustache templates) ───
        addButton.addEventListener('click', function () {
            var contextid = parseInt(container.getAttribute('data-contextid'), 10);
            if (!contextid) {
                return;
            }

            var labels = {
                title: container.getAttribute('data-picker-title') || 'Select competencies',
                framework: container.getAttribute('data-picker-framework') || 'Framework',
                search: container.getAttribute('data-picker-search') || 'Search',
                noFrameworks: container.getAttribute('data-picker-noframeworks') || 'No frameworks available.',
                noCompetencies: container.getAttribute('data-picker-nocompetencies') || 'No competencies found.',
                loading: container.getAttribute('data-picker-loading') || 'Loading...',
                addSelected: container.getAttribute('data-picker-addselected') || 'Add selected'
            };

            var existingIds = rules.map(function (r) { return r.id; });

            require(
                ['core/modal_save_cancel', 'core/modal_events', 'core/ajax', 'core/notification', 'core/templates'],
                function (ModalSaveCancel, ModalEvents, Ajax, Notification, Templates) {

                // Fetch frameworks, then open the modal.
                Ajax.call([{
                    methodname: 'core_competency_list_competency_frameworks',
                    args: {
                        sort: 'shortname', order: 'ASC', skip: 0, limit: 0,
                        context: {contextid: contextid},
                        includes: 'children', onlyvisible: true
                    }
                }])[0].then(function (frameworks) {
                    if (!frameworks || !frameworks.length) {
                        Notification.addNotification({message: labels.noFrameworks, type: 'warning'});
                        return null;
                    }

                    var pickerContext = {
                        frameworkLabel: labels.framework,
                        searchLabel: labels.search,
                        loadingLabel: labels.loading,
                        frameworks: frameworks.map(function (fw) {
                            return {id: fw.id, displayname: fw.shortname || fw.idnumber || ('#' + fw.id)};
                        })
                    };

                    var bodyPromise = Templates.renderForPromise('local_awareness/competency_picker_body', pickerContext)
                        .then(function (result) { return result.html; });

                    return ModalSaveCancel.create({
                        title: labels.title,
                        body: bodyPromise,
                        large: true,
                        show: true,
                        removeOnClose: true,
                        buttons: {save: labels.addSelected}
                    });
                }).then(function (modal) {
                    if (!modal) {
                        return null;
                    }

                    // Disable save button initially.
                    modal.setButtonDisabled('save', true);

                    var root = modal.getRoot()[0];

                    // ── Load competencies into the list ──
                    var loadCompetencies = function (frameworkId, searchText) {
                        var listEl = root.querySelector('[data-region="competency-list"]');
                        if (!listEl) {
                            return;
                        }
                        listEl.innerHTML =
                            '<div class="p-3 text-center text-muted">' +
                            '<div class="spinner-border spinner-border-sm" role="status"></div> ' +
                            labels.loading + '</div>';

                        Ajax.call([{
                            methodname: 'core_competency_search_competencies',
                            args: {searchtext: searchText || '', competencyframeworkid: frameworkId}
                        }])[0].then(function (competencies) {
                            if (!competencies || !competencies.length) {
                                var el = root.querySelector('[data-region="competency-list"]');
                                if (el) {
                                    el.innerHTML =
                                        '<div class="p-3 text-center text-muted">' +
                                        labels.noCompetencies + '</div>';
                                }
                                return null;
                            }
                            var tree = buildCompetencyTree(competencies);
                            var itemsContext = {
                                items: flattenTree(tree, existingIds),
                                emptyMessage: labels.noCompetencies
                            };
                            return Templates.renderForPromise('local_awareness/competency_picker_items', itemsContext);
                        }).then(function (result) {
                            if (result) {
                                var el = root.querySelector('[data-region="competency-list"]');
                                if (el) {
                                    el.innerHTML = result.html;
                                    if (result.js) {
                                        Templates.runTemplateJS(result.js);
                                    }
                                }
                            }
                            return null;
                        }).catch(function () {
                            var el = root.querySelector('[data-region="competency-list"]');
                            if (el) {
                                el.innerHTML =
                                    '<div class="p-3 text-center text-danger">Error loading competencies.</div>';
                            }
                        });
                    };

                    // ── Attach body event listeners after body is rendered ──
                    var setupBodyListeners = function () {
                        var fwSelect = root.querySelector('[data-action="choose-framework"]');
                        if (!fwSelect) {
                            return; // Body not yet in DOM.
                        }

                        // Framework selector.
                        fwSelect.addEventListener('change', function () {
                            loadCompetencies(parseInt(fwSelect.value, 10), '');
                            var si = root.querySelector('[data-action="search-input"]');
                            if (si) {
                                si.value = '';
                            }
                        });

                        // Auto-load first framework.
                        if (fwSelect.value) {
                            loadCompetencies(parseInt(fwSelect.value, 10), '');
                        }
                    };

                    // Wait for the body to be rendered before binding to body elements.
                    modal.getRoot().on(ModalEvents.bodyRendered, function () {
                        setupBodyListeners();
                    });
                    // Also try immediately in case body was already rendered synchronously.
                    setupBodyListeners();

                    // ── Search (delegated on root — safe before body renders) ──
                    root.addEventListener('click', function (e) {
                        if (e.target.closest('[data-action="search-btn"]')) {
                            var fwSel = root.querySelector('[data-action="choose-framework"]');
                            var text = root.querySelector('[data-action="search-input"]');
                            if (fwSel && text) {
                                loadCompetencies(parseInt(fwSel.value, 10), text.value || '');
                            }
                        }
                    });
                    root.addEventListener('keydown', function (e) {
                        if (e.target.matches && e.target.matches('[data-action="search-input"]') && e.key === 'Enter') {
                            e.preventDefault();
                            var fwSel = root.querySelector('[data-action="choose-framework"]');
                            if (fwSel) {
                                loadCompetencies(parseInt(fwSel.value, 10), e.target.value || '');
                            }
                        }
                    });

                    // ── Checkbox toggle → enable/disable Save (delegated) ──
                    root.addEventListener('change', function (e) {
                        if (e.target.matches && e.target.matches('[data-competency-id]')) {
                            var any = root.querySelectorAll('[data-competency-id]:checked:not(:disabled)').length > 0;
                            modal.setButtonDisabled('save', !any);
                        }
                    });

                    // ── Save event (ModalSaveCancel fires this) ──
                    modal.getRoot().on(ModalEvents.save, function (evt) {
                        evt.preventDefault();
                        var checked = root.querySelectorAll('[data-competency-id]:checked:not(:disabled)');
                        var selected = [];
                        checked.forEach(function (cb) {
                            selected.push({
                                id: parseInt(cb.getAttribute('data-competency-id'), 10),
                                name: cb.getAttribute('data-competency-name') || ('#' + cb.getAttribute('data-competency-id')),
                                proficient: 1
                            });
                        });
                        if (selected.length) {
                            addRulesFromPicker(selected);
                        }
                        modal.destroy();
                    });

                    return null;
                }).catch(Notification.exception);
            });
        });

        renderRules();
        return true;
    };

    // ───────────────────────────────────────────
    // Entry point
    // ───────────────────────────────────────────

    return {
        init: function () {
            var competencyBound = initCompetencyFilter();

            if (bind() && competencyBound) {
                return;
            }

            setTimeout(function () {
                if (bind()) {
                    competencyBound = initCompetencyFilter() || competencyBound;
                } else {
                    competencyBound = initCompetencyFilter() || competencyBound;
                }
                if (bind() && competencyBound) {
                    return;
                }

                var observer = new MutationObserver(function () {
                    var courseBound = bind();
                    competencyBound = initCompetencyFilter() || competencyBound;
                    if (courseBound && competencyBound) {
                        observer.disconnect();
                    }
                });
                observer.observe(document.body, {childList: true, subtree: true});

                setTimeout(function () {
                    observer.disconnect();
                }, 10000);
            }, 200);
        }
    };
});
