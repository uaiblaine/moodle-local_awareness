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
 * Editor behaviour for the notice form: the competency picker and the rules table.
 *
 * The picker resolves competencies against the framework the author chose and renders the rules the
 * notice will be filtered by.
 *
 * @module     local_awareness/notice_form
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    var SELECTORS = {
        RESET_NUMBER: 'id_resetinterval_number',
        RESET_UNIT: 'id_resetinterval_timeunit',
        INSISTENCE: 'id_insistence',
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
        // Informational. Mirrors awareness::INSISTENCE_INFORMATIONAL; this file cannot reach PHP.
        INSISTENCE: '0'
    };

    // ───────────────────────────────────────────
    // Course-completion field logic
    // ───────────────────────────────────────────

    var hasCourseSelected = function(select) {
        var val = select.value;
        return val !== '' && val !== '0' && parseInt(val, 10) > 0;
    };

    var setDependentFields = function(disable) {
        [{id: SELECTORS.RESET_NUMBER, def: DEFAULT_VALUES.RESET_NUMBER},
            {id: SELECTORS.RESET_UNIT, def: DEFAULT_VALUES.RESET_UNIT},
            {id: SELECTORS.INSISTENCE, def: DEFAULT_VALUES.INSISTENCE}
        ].forEach(function(pair) {
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

    var bind = function() {
        var select = document.getElementById(SELECTORS.REQCOURSE);
        if (!select) {
            return false;
        }
        if (select.getAttribute('data-awareness-bound') === '1') {
            return true;
        }
        select.setAttribute('data-awareness-bound', '1');
        setDependentFields(hasCourseSelected(select));
        select.addEventListener('change', function() {
            setDependentFields(hasCourseSelected(select));
        });
        return true;
    };

    // ───────────────────────────────────────────
    // Layout → position logic
    // ───────────────────────────────────────────

    var LAYOUT_SELECTORS = {
        LAYOUT_RADIOS: 'input[name="template"]',
        POSITION_RADIOS: 'input[name="position"]',
        POSITION_GROUP: '#fgroup_id_positiongroup',
        POSITION_NOTE: '[data-region="la-position-note"]'
    };

    /*
     * The four corners are the card's alone. Mirrors awareness::POSITIONS_CORNER and
     * awareness::positions_for(); this file cannot reach PHP, and the server refuses the
     * combination anyway - this only stops the author picking what the save would bounce.
     */
    var CORNERS = ['top-start', 'top-end', 'bottom-start', 'bottom-end'];
    var CORNER_LAYOUT = 'card';
    var FALLBACK_POSITION = 'center';

    // The one layout that covers the window, and so has no position to pick.
    var COVERED_LAYOUT = 'fullscreen';

    var syncPositions = function() {
        var checked = document.querySelector(LAYOUT_SELECTORS.LAYOUT_RADIOS + ':checked');
        var layout = checked ? checked.value : '';
        var cornersAllowed = layout === CORNER_LAYOUT;
        /*
         * Full screen covers the window, so it has no position at all. The group used to be hidden
         * for it by a server-side hideIf, and a control that vanishes reads as a fault: the screen
         * fills instead and the note says why. The stylesheet draws both states; nothing here
         * writes text.
         */
        var covered = layout === COVERED_LAYOUT;
        var displaced = false;
        document.querySelectorAll(LAYOUT_SELECTORS.POSITION_RADIOS).forEach(function(radio) {
            var corner = CORNERS.indexOf(radio.value) !== -1;
            radio.disabled = covered || (corner && !cornersAllowed);
            if (corner && !cornersAllowed && radio.checked) {
                radio.checked = false;
                displaced = true;
            }
        });
        if (displaced) {
            var fallback = document.querySelector(LAYOUT_SELECTORS.POSITION_RADIOS + '[value="' + FALLBACK_POSITION + '"]');
            if (fallback) {
                fallback.checked = true;
            }
        }

        var group = document.querySelector(LAYOUT_SELECTORS.POSITION_GROUP);
        if (group) {
            group.classList.toggle('la-zones-covered', covered);
        }
        var note = document.querySelector(LAYOUT_SELECTORS.POSITION_NOTE);
        if (note) {
            note.hidden = !covered;
        }
    };

    var bindLayout = function() {
        var radios = document.querySelectorAll(LAYOUT_SELECTORS.LAYOUT_RADIOS);
        if (!radios.length) {
            return false;
        }
        radios.forEach(function(radio) {
            if (radio.getAttribute('data-awareness-bound') === '1') {
                return;
            }
            radio.setAttribute('data-awareness-bound', '1');
            radio.addEventListener('change', syncPositions);
        });
        syncPositions();
        return true;
    };

    // ───────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────

    var parseRules = function(raw) {
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
     * Escape a string for a sink that renders raw HTML.
     *
     * Two of core's own sinks take HTML and cannot be given a text node instead: Modal.setTitle()
     * ends in jQuery .html() (lib/amd/src/modal.js, identical on 4.5 and 5.2), and
     * Notification.addNotification() renders through a triple stash in notification_base.mustache,
     * whose docblock asks for "a cleaned string". Both receive language strings from data-*
     * attributes here, so they are escaped exactly once on the way in.
     *
     * The escaping is the browser's own rather than a hand-written character table — a table is a
     * thing to get wrong, and this cannot be.
     *
     * @param {String} text The raw string.
     * @returns {String} The same string, safe to place in an HTML sink.
     */
    var escapeText = function(text) {
        var holder = document.createElement('div');
        holder.appendChild(document.createTextNode(String(text)));

        return holder.innerHTML;
    };

    /**
     * Replace an element's contents with a single message box, written as TEXT.
     *
     * The message is a language string the server put in a data-* attribute, so it arrives as text
     * and has to be written as text. Concatenated into innerHTML it is re-parsed as markup, and an
     * ampersand or an angle bracket a translator wrote renders wrong or swallows the rest of the
     * fragment. Nothing in the pipeline reads a JS string literal, so the guard is a source-contract
     * test rather than a linter.
     *
     * @param {Element} target Element whose contents are replaced.
     * @param {String} text The message to show.
     * @param {String} classes Class attribute for the message box.
     * @param {Boolean} spinner Whether to prefix a spinner.
     */
    var setMessage = function(target, text, classes, spinner) {
        var box = document.createElement('div');
        box.className = classes;
        if (spinner) {
            var spin = document.createElement('div');
            spin.className = 'spinner-border spinner-border-sm';
            spin.setAttribute('role', 'status');
            box.appendChild(spin);
            box.appendChild(document.createTextNode(' '));
        }
        box.appendChild(document.createTextNode(text));
        target.innerHTML = '';
        target.appendChild(box);
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
    var flattenTree = function(tree, existingIds, depth) {
        depth = depth || 0;
        var items = [];
        tree.forEach(function(node) {
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

    var buildCompetencyTree = function(flatList) {
        var tree = [];
        var map = {};
        flatList.forEach(function(item) {
            map[item.id] = {data: item, children: []};
        });
        flatList.forEach(function(item) {
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

    var initCompetencyFilter = function() {
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
        var rulesErrorLabel = container.getAttribute('data-rules-error')
            || 'The selected competencies could not be displayed.';

        var rules = parseRules(rulesInput.value).map(function(rule) {
            var id = parseInt(rule.id || rule.competencyid || 0, 10);
            return {
                id: id,
                name: rule.name || ('#' + id),
                proficient: parseInt(rule.proficient || 0, 10) === 1 ? 1 : 0
            };
        }).filter(function(rule) {
            return rule.id > 0;
        });

        var syncRulesInput = function() {
            rulesInput.value = JSON.stringify(rules.map(function(r) {
                return {id: r.id, name: r.name, proficient: r.proficient};
            }));
        };

        var toggleRequireAllVisibility = function() {
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

        var applyRequireAllMode = function() {
            var requireAll = parseInt(requireAllInput.value, 10) === 1;
            rulesContainer.querySelectorAll('.awareness-competency-proficient').forEach(function(sel, i) {
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
        var renderRules = function() {
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
                rules: rules.map(function(rule, index) {
                    return {
                        index: index,
                        name: rule.name,
                        proficientSelected: rule.proficient === 1
                    };
                })
            };

            require(['core/templates'], function(Templates) {
                Templates.renderForPromise('local_awareness/competency_rules', context)
                    .then(function(result) {
                        rulesContainer.innerHTML = result.html;
                        if (result.js) {
                            Templates.runTemplateJS(result.js);
                        }
                        toggleRequireAllVisibility();
                        applyRequireAllMode();
                        syncRulesInput();
                        return null;
                    })
                    .catch(function() {
                        // Fallback when the template fails: a translated message, written as text.
                        setMessage(rulesContainer, rulesErrorLabel, 'alert alert-warning', false);
                    });
            });
        };

        // ─── Event delegation on rulesContainer (attach once) ───
        rulesContainer.addEventListener('change', function(event) {
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

        rulesContainer.addEventListener('click', function(event) {
            var btn = event.target.closest('.awareness-competency-remove');
            if (btn) {
                var idx = parseInt(btn.getAttribute('data-index'), 10);
                rules.splice(idx, 1);
                renderRules();
            }
        });

        requireAllInput.addEventListener('change', function() {
            applyRequireAllMode();
        });

        // ─── Add-from-picker helper ───
        var addRulesFromPicker = function(selectedRules) {
            selectedRules.forEach(function(sr) {
                if (!rules.some(function(r) {
                    return r.id === sr.id;
                })) {
                    rules.push(sr);
                }
            });
            renderRules();
        };

        // ─── Competency picker modal (ModalSaveCancel + Mustache templates) ───
        addButton.addEventListener('click', function() {
            var contextid = parseInt(container.getAttribute('data-contextid'), 10);
            if (!contextid) {
                return;
            }
            /*
             * Under a course scope the save accepts only the competencies linked to the course, so
             * the picker offers only those: the frameworks that hold at least one, and within each
             * only the linked ones. Nothing it shows is something the save would refuse.
             */
            var courseid = parseInt(container.getAttribute('data-courseid'), 10) || 0;

            var labels = {
                title: container.getAttribute('data-picker-title') || 'Select competencies',
                framework: container.getAttribute('data-picker-framework') || 'Framework',
                search: container.getAttribute('data-picker-search') || 'Search',
                noFrameworks: container.getAttribute('data-picker-noframeworks') || 'No frameworks available.',
                noCourseLinked: container.getAttribute('data-picker-nocourselinked')
                    || 'This course has no competencies linked to it.',
                noCompetencies: container.getAttribute('data-picker-nocompetencies') || 'No competencies found.',
                loading: container.getAttribute('data-picker-loading') || 'Loading...',
                loadError: container.getAttribute('data-picker-loaderror')
                    || 'The competency list could not be loaded.',
                addSelected: container.getAttribute('data-picker-addselected') || 'Add selected'
            };

            var existingIds = rules.map(function(r) {
                return r.id;
            });

            require(
                ['core/modal_save_cancel', 'core/modal_events', 'core/ajax', 'core/notification', 'core/templates'],
                function(ModalSaveCancel, ModalEvents, Ajax, Notification, Templates) {

                    // The course's competency ids and the frameworks they live in; empty sets mean "no limit".
                    var allowed = null;
                    var allowedFrameworks = null;
                    var courseScope = courseid > 0
                        ? Ajax.call([{
                            methodname: 'core_competency_list_course_competencies',
                            args: {id: courseid}
                        }])[0].then(function(linked) {
                            allowed = {};
                            allowedFrameworks = {};
                            (linked || []).forEach(function(entry) {
                                if (entry && entry.competency) {
                                    allowed[entry.competency.id] = true;
                                    allowedFrameworks[entry.competency.competencyframeworkid] = true;
                                }
                            });
                            return null;
                        })
                        : Promise.resolve(null);

                    // Fetch frameworks, then open the modal.
                    courseScope.then(function() {
                        /*
                         * Frameworks live at the system or a category context, never at a course:
                         * from a course context 'children' finds nothing, on every site, always,
                         * while 'parents' walks up to the category and the system. The site page
                         * keeps 'children', which from the system context is every framework.
                         */
                        return Ajax.call([{
                            methodname: 'core_competency_list_competency_frameworks',
                            args: {
                                sort: 'shortname', order: 'ASC', skip: 0, limit: 0,
                                context: {contextid: contextid},
                                includes: courseid > 0 ? 'parents' : 'children', onlyvisible: true
                            }
                        }])[0];
                    }).then(function(frameworks) {
                        if (allowedFrameworks) {
                            frameworks = (frameworks || []).filter(function(fw) {
                                return allowedFrameworks[fw.id];
                            });
                        }
                        if (!frameworks || !frameworks.length) {
                            /*
                             * Under a course scope the filter above keeps only the frameworks
                             * holding a competency LINKED TO THE COURSE, so an empty list there
                             * almost always means the course has none — not that the site has no
                             * frameworks. Saying the latter sends the author looking for the wrong
                             * thing: reported from the browser, on a site with two frameworks and
                             * no course linked to either.
                             */
                            Notification.addNotification({
                                message: escapeText(courseid > 0 ? labels.noCourseLinked : labels.noFrameworks),
                                type: 'warning'
                            });
                            return null;
                        }

                        var pickerContext = {
                            frameworkLabel: labels.framework,
                            searchLabel: labels.search,
                            loadingLabel: labels.loading,
                            frameworks: frameworks.map(function(fw) {
                                return {id: fw.id, displayname: fw.shortname || fw.idnumber || ('#' + fw.id)};
                            })
                        };

                        // eslint-disable-next-line promise/no-nesting
                        var bodyPromise = Templates.renderForPromise('local_awareness/competency_picker_body', pickerContext)
                            .then(function(result) {
                                return result.html;
                            });

                        return ModalSaveCancel.create({
                            title: escapeText(labels.title),
                            body: bodyPromise,
                            large: true,
                            show: true,
                            removeOnClose: true,
                            buttons: {save: labels.addSelected}
                        });
                    }).then(function(modal) {
                        if (!modal) {
                            return null;
                        }

                        // Disable save button initially.
                        modal.setButtonDisabled('save', true);

                        var root = modal.getRoot()[0];

                        // ── Load competencies into the list ──
                        var loadCompetencies = function(frameworkId, searchText) {
                            var listEl = root.querySelector('[data-region="competency-list"]');
                            if (!listEl) {
                                return;
                            }
                            setMessage(listEl, labels.loading, 'p-3 text-center text-muted', true);

                            // eslint-disable-next-line promise/no-nesting
                            Ajax.call([{
                                methodname: 'core_competency_search_competencies',
                                args: {searchtext: searchText || '', competencyframeworkid: frameworkId}
                            }])[0].then(function(competencies) {
                                if (allowed) {
                                    competencies = (competencies || []).filter(function(competency) {
                                        return allowed[competency.id];
                                    });
                                }
                                if (!competencies || !competencies.length) {
                                    var el = root.querySelector('[data-region="competency-list"]');
                                    if (el) {
                                        setMessage(el, labels.noCompetencies, 'p-3 text-center text-muted', false);
                                    }
                                    return null;
                                }
                                var tree = buildCompetencyTree(competencies);
                                var itemsContext = {
                                    items: flattenTree(tree, existingIds),
                                    emptyMessage: labels.noCompetencies
                                };
                                return Templates.renderForPromise('local_awareness/competency_picker_items', itemsContext);
                            }).then(function(result) {
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
                            }).catch(function() {
                                var el = root.querySelector('[data-region="competency-list"]');
                                if (el) {
                                    setMessage(el, labels.loadError, 'p-3 text-center text-danger', false);
                                }
                            });
                        };

                        // ── Attach body event listeners after body is rendered ──
                        var setupBodyListeners = function() {
                            var fwSelect = root.querySelector('[data-action="choose-framework"]');
                            if (!fwSelect) {
                                return; // Body not yet in DOM.
                            }

                            // Framework selector.
                            fwSelect.addEventListener('change', function() {
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
                        modal.getRoot().on(ModalEvents.bodyRendered, function() {
                            setupBodyListeners();
                        });
                        // Also try immediately in case body was already rendered synchronously.
                        setupBodyListeners();

                        // ── Search (delegated on root — safe before body renders) ──
                        root.addEventListener('click', function(e) {
                            if (e.target.closest('[data-action="search-btn"]')) {
                                var fwSel = root.querySelector('[data-action="choose-framework"]');
                                var text = root.querySelector('[data-action="search-input"]');
                                if (fwSel && text) {
                                    loadCompetencies(parseInt(fwSel.value, 10), text.value || '');
                                }
                            }
                        });
                        root.addEventListener('keydown', function(e) {
                            if (e.target.matches && e.target.matches('[data-action="search-input"]') && e.key === 'Enter') {
                                e.preventDefault();
                                var fwSel = root.querySelector('[data-action="choose-framework"]');
                                if (fwSel) {
                                    loadCompetencies(parseInt(fwSel.value, 10), e.target.value || '');
                                }
                            }
                        });

                        // ── Checkbox toggle → enable/disable Save (delegated) ──
                        root.addEventListener('change', function(e) {
                            if (e.target.matches && e.target.matches('[data-competency-id]')) {
                                var any = root.querySelectorAll('[data-competency-id]:checked:not(:disabled)').length > 0;
                                modal.setButtonDisabled('save', !any);
                            }
                        });

                        // ── Save event (ModalSaveCancel fires this) ──
                        modal.getRoot().on(ModalEvents.save, function(evt) {
                            evt.preventDefault();
                            var checked = root.querySelectorAll('[data-competency-id]:checked:not(:disabled)');
                            var selected = [];
                            checked.forEach(function(cb) {
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
        init: function() {
            // The layout radios are plain form markup, present from the first paint.
            bindLayout();
            var competencyBound = initCompetencyFilter();

            if (bind() && competencyBound) {
                return;
            }

            setTimeout(function() {
                if (bind()) {
                    competencyBound = initCompetencyFilter() || competencyBound;
                } else {
                    competencyBound = initCompetencyFilter() || competencyBound;
                }
                if (bind() && competencyBound) {
                    return;
                }

                var observer = new MutationObserver(function() {
                    bindLayout();
                    var courseBound = bind();
                    competencyBound = initCompetencyFilter() || competencyBound;
                    if (courseBound && competencyBound) {
                        observer.disconnect();
                    }
                });
                observer.observe(document.body, {childList: true, subtree: true});

                setTimeout(function() {
                    observer.disconnect();
                }, 10000);
            }, 200);
        }
    };
});
