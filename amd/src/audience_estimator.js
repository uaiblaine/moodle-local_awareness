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
 * Audience estimator widget.
 *
 *  - When the form has ≤ threshold audience-shaping rules, runs estimate
 *    automatically (debounced) on form changes.
 *  - When > threshold rules, hides auto-trigger and requires explicit click.
 *  - Triggers a server-side ad-hoc task and polls every pollIntervalMs for
 *    the result, up to pollMax attempts.
 *
 * @module     local_awareness/audience_estimator
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/str',
    'local_awareness/audience_criteria'
], function(Ajax, str, criteriaReader) {
    'use strict';

    // The criteria object keys mirror the moodleform/server field names
    // (filter_role, filter_category, …), which are snake_case by contract.
    /* eslint-disable camelcase */

    var DEBOUNCE_MS = 800;

    var state = {
        threshold: 3,
        pollIntervalMs: 10000,
        pollMax: 30,
        currentJobId: null,
        pollTimer: null,
        pollAttempts: 0,
        debounceTimer: null,
        lastCriteriaJson: '',
        // Monotonic request counter. Same name and shape as collision_warning.js, which already
        // ships this guard — one spelling for one pattern.
        sequence: 0,
        strings: null,
        root: null,
        slots: {}
    };

    /**
     * Scoped querySelector helper.
     *
     * @param {Element} root Root element to search within.
     * @param {string} sel CSS selector to match.
     * @returns {Element|null} The first matching element, or null.
     */
    function $(root, sel) {
        return root.querySelector(sel);
    }

    /**
     * Resolve the panel slot elements once per init.
     *
     * @param {Element} root The audience panel root element.
     * @returns {object} Map of slot name to element (or null when absent).
     */
    function captureSlots(root) {
        return {
            summary:    $(root, '[data-slot="summary"]'),
            reach:      $(root, '[data-slot="reach"]'),
            value:      $(root, '[data-slot="value"]'),
            stateLine:  $(root, '[data-slot="state"]'),
            actions:    $(root, '[data-slot="actions"]'),
            calcBtn:    $(root, '[data-action="calculate"]'),
            retryBtn:   $(root, '[data-action="retry"]'),
            breakdown:  $(root, '[data-slot="breakdown"]'),
            context:    $(root, '[data-slot="context"]'),
            contextChips: $(root, '[data-slot="contextchips"]')
        };
    }

    /**
     * Load and map the audience-estimator language strings into state.
     *
     * @returns {Promise<object>} Promise resolving to the strings map.
     */
    function loadStrings() {
        /*
         * No `param` on the templated strings. Supplying one — even the placeholder's own text —
         * makes get_string() perform the substitution server-side, and an empty param silently
         * erased the value from every context chip ("Course category: " with nothing after it).
         * Omitted, the raw {$a} survives the trip and the replace() calls below can find it.
         */
        /*
         * Requested and mapped BY KEY, never by position. The list used to be a literal array read
         * back as s[0]..s[20], which meant removing one entry silently re-labelled every chip after
         * it — and three entries did need removing, because audience:state:idle,
         * audience:btn:calculate and audience:btn:retry are rendered server-side by
         * templates/editor/audience_panel.mustache and were fetched here and never read.
         */
        var keys = [
            'audience:state:auto_pending',
            'audience:state:manual_ready',
            'audience:state:queued',
            'audience:state:cached',
            'audience:state:timeout',
            'audience:state:error',
            'audience:reach:value',
            'audience:rules_too_many',
            'audience:rule:cohorts',
            'audience:rule:filter_role',
            'audience:rule:reqcourse',
            'audience:rule:pathmatch',
            'audience:rule:filter_category',
            'audience:rule:filter_course',
            'audience:rule:filter_format',
            'audience:rule:filter_theme',
            'audience:rule:filter_competency_rules',
            'audience:state:wholesite'
        ];

        return str.get_strings(keys.map(function(key) {
            return {key: key, component: 'local_awareness'};
        })).then(function(s) {
            var byKey = {};
            keys.forEach(function(key, index) {
                byKey[key] = s[index];
            });

            state.strings = {
                autoPending: byKey['audience:state:auto_pending'],
                manualReady: byKey['audience:state:manual_ready'],
                queued: byKey['audience:state:queued'],
                cachedTpl: byKey['audience:state:cached'],
                timeout: byKey['audience:state:timeout'],
                errorTpl: byKey['audience:state:error'],
                reachValueTpl: byKey['audience:reach:value'],
                rulesTooMany: byKey['audience:rules_too_many'],
                ruleLabels: {
                    cohorts: byKey['audience:rule:cohorts'],
                    filter_role: byKey['audience:rule:filter_role'],
                    reqcourse: byKey['audience:rule:reqcourse'],
                    pathmatch: byKey['audience:rule:pathmatch'],
                    filter_category: byKey['audience:rule:filter_category'],
                    filter_course: byKey['audience:rule:filter_course'],
                    filter_format: byKey['audience:rule:filter_format'],
                    filter_theme: byKey['audience:rule:filter_theme'],
                    filter_competency_rules: byKey['audience:rule:filter_competency_rules']
                },
                wholeSiteTpl: byKey['audience:state:wholesite']
            };
            return state.strings;
        });
    }

    /**
     * Format an integer with locale thousands separators.
     *
     * @param {number} n The number to format.
     * @returns {string} The formatted number string.
     */
    function formatCount(n) {
        try {
            return new Intl.NumberFormat().format(n);
        } catch (e) {
            return String(n);
        }
    }

    /**
     * Write the status line text.
     *
     * @param {string} text The status message to display.
     */
    function setState(text) {
        if (state.slots.stateLine) {
            state.slots.stateLine.textContent = text || '';
        }
    }

    /**
     * Write the reach value text.
     *
     * @param {string} text The value to display.
     */
    function setValue(text) {
        if (state.slots.value) {
            state.slots.value.textContent = text;
        }
    }

    /**
     * Update the small per-rule summary at the top of the panel.
     *
     * @param {object} criteria The current audience criteria object.
     */
    function updateSummary(criteria) {
        if (!state.slots.summary) {
            return;
        }
        var counts = {
            cohorts: criteria.cohorts ? criteria.cohorts.length : 0,
            courses: criteria.filter_course ? criteria.filter_course.length : 0,
            role: criteria.filter_role ? criteria.filter_role.length : 0,
            competencies: criteria.filter_competency_rules ? criteria.filter_competency_rules.length : 0
        };
        Object.keys(counts).forEach(function(key) {
            var dd = state.slots.summary.querySelector('[data-summary-key="' + key + '"]');
            if (dd) {
                dd.textContent = String(counts[key]);
            }
        });
    }

    /**
     * Update the context-restrictions chips list.
     *
     * @param {Array} contextRules List of context rule descriptors to render.
     */
    function updateContextChips(contextRules) {
        if (!state.slots.context || !state.slots.contextChips) {
            return;
        }
        if (!contextRules.length) {
            state.slots.context.hidden = true;
            return;
        }
        state.slots.context.hidden = false;

        var html = '';
        contextRules.forEach(function(rule) {
            html += '<li class="la-chip" data-key="' + rule.key + '">'
                + escapeHtml(ruleLabel(rule.key, rule.display)) + '</li>';
        });
        state.slots.contextChips.innerHTML = html;
    }

    /**
     * Resolve a rule's display label, filling its {$a} placeholder when it has one.
     *
     * Shared by the context chips and the per-rule breakdown chips, which render the same labels:
     * the breakdown gained the category, course and format rules when those started counting
     * towards the audience, and rendering them through a second path is how one of them ends up
     * showing the placeholder verbatim.
     *
     * The substituted text comes from the server, which is the only side that can turn a category
     * id into a category name — and it resolves them per request, so the names arrive in the
     * reader's language rather than in whichever one first computed the job.
     *
     * @param {string} key The criteria key the rule was read from.
     * @param {string} display Server-resolved names for the rule's values; may be empty.
     * @returns {string} The label, ready to escape and insert.
     */
    function ruleLabel(key, display) {
        var label = state.strings.ruleLabels[key] || key;
        if (label.indexOf('{$a}') === -1) {
            return label;
        }
        return label.replace('{$a}', display || '');
    }

    /**
     * Escape a string for safe insertion into HTML.
     *
     * @param {string} s The raw string.
     * @returns {string} The HTML-escaped string.
     */
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[c];
        });
    }

    /** Cancel any pending poll. */
    function stopPolling() {
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
        state.pollAttempts = 0;
        state.currentJobId = null;
    }

    /**
     * Begin polling for a queued job.
     *
     * @param {String} jobid The queued job's RFC-4122 token, not a database id.
     */
    function startPolling(jobid) {
        state.currentJobId = jobid;
        state.pollAttempts = 0;
        scheduleNextPoll();
    }

    /**
     * Schedule the next poll attempt after the configured interval.
     */
    function scheduleNextPoll() {
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
        }
        state.pollTimer = setTimeout(pollOnce, state.pollIntervalMs);
    }

    /**
     * Perform a single poll for the current job's result.
     */
    function pollOnce() {
        if (!state.currentJobId) {
            return;
        }
        state.pollAttempts += 1;

        /*
         * The job id is captured at send time and the answer is only applied if this request is
         * still the current one. Without it, a poll for a superseded job can land after a newer
         * estimate has started and overwrite a fresher count with a stale one — and the author is
         * given no sign that the number on screen answers a question they have already changed.
         */
        var mine = state.sequence;
        var jobid = state.currentJobId;

        Ajax.call([{
            methodname: 'local_awareness_get_estimate',
            args: {jobid: jobid}
        }])[0].then(function(response) {
            if (mine !== state.sequence) {
                return null;
            }
            if (!response) {
                return null;
            }
            if (response.status === 'ready') {
                handleReady(response);
            } else if (response.status === 'error') {
                handleError(response.errormsg || '');
            } else {
                if (state.pollAttempts >= state.pollMax) {
                    handleTimeout();
                } else {
                    scheduleNextPoll();
                }
            }
            return null;
        }).catch(function(err) {
            if (mine !== state.sequence) {
                return;
            }
            handleError((err && err.message) ? err.message : 'AJAX error');
        });
    }

    /**
     * Render a completed estimate result.
     *
     * @param {object} response The web service response payload.
     */
    function handleReady(response) {
        stopPolling();
        var count = (response.count === null || response.count === undefined) ? 0 : parseInt(response.count, 10);
        setValue(state.strings.reachValueTpl.replace('{$a}', formatCount(count)));
        var when = response.timecompleted
            ? new Date(response.timecompleted * 1000).toLocaleTimeString()
            : '';
        // No audience rule means nothing has been narrowed and the count is the whole site — a
        // fact about the notice worth stating, not the same message as a filtered result.
        var template = response.has_audience_rules ? state.strings.cachedTpl : state.strings.wholeSiteTpl;
        setState(template.replace('{$a}', when));
        showAction('calculate', !!state.slots.calcBtn && !state.autoMode);
        showAction('retry', false);
        // Render breakdown chips.
        if (state.slots.breakdown && response.breakdown) {
            try {
                var arr = JSON.parse(response.breakdown);
                if (Array.isArray(arr) && arr.length) {
                    var html = '';
                    arr.forEach(function(it) {
                        var label = ruleLabel(it.key, it.display);
                        html += '<span class="la-chip la-chip--brand">'
                            + escapeHtml(label) + ' · ' + formatCount(parseInt(it.count, 10) || 0)
                            + '</span>';
                    });
                    state.slots.breakdown.innerHTML = html;
                    state.slots.breakdown.hidden = false;
                } else {
                    state.slots.breakdown.hidden = true;
                }
            } catch (e) {
                state.slots.breakdown.hidden = true;
            }
        }
        if (response.context_only_filters) {
            try {
                var ctx = JSON.parse(response.context_only_filters);
                if (Array.isArray(ctx)) {
                    updateContextChips(ctx);
                }
            } catch (e) { /* No-op. */ }
        }
    }

    /**
     * Render an error state.
     *
     * @param {string} msg The error message to surface.
     */
    function handleError(msg) {
        stopPolling();
        setValue('—');
        setState(state.strings.errorTpl.replace('{$a}', msg || ''));
        showAction('calculate', !state.autoMode);
        showAction('retry', true);
    }

    /**
     * Render a timeout state after exhausting poll attempts.
     */
    function handleTimeout() {
        stopPolling();
        setValue('—');
        setState(state.strings.timeout);
        showAction('calculate', !state.autoMode);
        showAction('retry', true);
    }

    /**
     * Toggle visibility of an action button.
     *
     * The calculate button is the exception and always stays on screen: it is the author's manual
     * recalculate control, and hiding it whenever an estimate is queued would take away the one
     * thing they can do about a slow or failed count. Only the retry button follows the requested
     * state. Documented rather than "fixed", because the asymmetry is the intended behaviour and
     * it was the JSDoc that was wrong.
     *
     * @param {string} name Either "calculate" or "retry".
     * @param {boolean} visible Whether the button should be shown. Ignored for "calculate".
     */
    function showAction(name, visible) {
        var el = (name === 'calculate') ? state.slots.calcBtn : state.slots.retryBtn;
        if (!el) {
            return;
        }
        el.hidden = (name === 'calculate') ? false : !visible;
    }

    /**
     * Trigger a fresh estimate based on the current criteria.
     *
     * @param {boolean} [force] Ask again even when the criteria are unchanged. True for a button
     *     press, which is the author explicitly requesting a recount; false or omitted for the
     *     automatic path, where unchanged criteria can only produce the number already on screen.
     */
    function trigger(force) {
        var criteria = criteriaReader.read();
        var json = JSON.stringify(criteria);
        updateSummary(criteria);

        /*
         * The estimate answers a question about the CRITERIA, so re-asking with the same ones can
         * only repaint the same number. Auto mode fires on every change to the form, including the
         * title and the body — so typing a headline used to blank the reach figure, queue an
         * ad-hoc task and spend a round trip restoring the answer that was already on screen, once
         * per pause in typing. Every one of those queued a row nothing deletes.
         *
         * A click always asks: pressing Calculate or Retry is the author's way of saying "count it
         * again", and refusing that would be the dead button this panel already had once. Hence
         * force, rather than comparing at the debounce.
         *
         * The comparison happens BEFORE any side effect. Bumping the sequence or calling
         * stopPolling() first would cancel an in-flight estimate the author is still waiting for.
         */
        if (!force && json === state.lastCriteriaJson) {
            return;
        }
        state.lastCriteriaJson = json;

        /*
         * A new estimate supersedes whatever was in flight. stopPolling() cancels the pending
         * timer; bumping the sequence is what discards an answer already on the wire, which the
         * timer cannot reach.
         */
        stopPolling();
        var mine = ++state.sequence;

        /*
         * No early return on an empty criteria set. It used to stop here and reprint the idle
         * sentence, so pressing "Calculate reach" on an unfiltered notice looked like a dead
         * button — the one thing the author had asked it to answer. An empty set is a valid
         * question with a real answer: everyone on the site.
         */
        setValue('—');
        setState(state.strings.queued);
        showAction('calculate', false);
        showAction('retry', false);

        Ajax.call([{
            methodname: 'local_awareness_estimate_audience',
            args: {criteria: json}
        }])[0].then(function(response) {
            if (mine !== state.sequence) {
                return null;
            }
            if (!response || !response.jobid) {
                handleError('No job id returned.');
                return null;
            }
            if (response.status === 'pending') {
                startPolling(response.jobid);
            } else {
                /*
                 * Already settled — a cached result, or one the server computed during this very
                 * request. Read it now instead of sitting through a poll interval first; testing
                 * for "pending" rather than for "ready" means an error comes back just as fast.
                 */
                state.currentJobId = response.jobid;
                pollOnce();
            }
            return null;
        }).catch(function(err) {
            if (mine !== state.sequence) {
                return;
            }
            handleError((err && err.message) ? err.message : 'AJAX error');
        });
    }

    /** Recompute mode (auto vs manual) based on site size and current rule counts. */
    function recomputeMode() {
        var criteria = criteriaReader.read();
        updateSummary(criteria);
        var totalRules = criteriaReader.countAudienceRules(criteria) + criteriaReader.countContextRules(criteria);
        /*
         * Site size first, and it is not overridable by the rule count: on a large site every
         * estimate scans every user row, so the editor must never start one on its own — the
         * author would trigger a full scan by typing a title.
         */
        var newAutoMode = state.autoEnabled && totalRules <= state.threshold;

        // Keep the manual calculate button available regardless of mode.
        if (state.slots.calcBtn) {
            state.slots.calcBtn.hidden = false;
        }

        if (newAutoMode !== state.autoMode) {
            state.autoMode = newAutoMode;
            if (!newAutoMode) {
                // Two reasons to be manual, and they need different sentences: a large site is a
                // standing fact the author cannot change from this form, too many rules is not.
                setState(state.autoEnabled ? state.strings.rulesTooMany : state.strings.manualReady);
                stopPolling();
            }
        }
        return newAutoMode;
    }

    /** Schedule an auto-trigger after debounce. */
    function debouncedTrigger() {
        if (!state.autoMode) {
            // Update summary chips even without firing the estimate.
            recomputeMode();
            return;
        }
        if (state.debounceTimer) {
            clearTimeout(state.debounceTimer);
        }
        setState(state.strings.autoPending);
        state.debounceTimer = setTimeout(function() {
            state.debounceTimer = null;
            trigger();
        }, DEBOUNCE_MS);
    }

    /** Bind change/input listeners to ALL form fields that affect criteria. */
    function bindFormChanges() {
        /*
         * The moodleform, by its own class. It used to be found as form.la-shell or inside
         * #la-moodleform-source, both of which the editor rebuild removed — and this function
         * returns early when it finds nothing, so the estimator simply stopped reacting to field
         * changes without a word. Scoped to the editor region first so it cannot latch onto some
         * other form on the page.
         */
        var form = document.querySelector('.local-awareness-editor form.mform')
            || document.querySelector('form.mform');
        if (!form) {
            return;
        }
        form.addEventListener('change', function() {
            recomputeMode();
            debouncedTrigger();
        });
        form.addEventListener('input', function() {
            recomputeMode();
            debouncedTrigger();
        });
    }

    return {
        init: function(config) {
            config = config || {};
            var root = document.querySelector('[data-region="la-audience"]');
            if (!root) {
                return;
            }
            state.root = root;
            state.slots = captureSlots(root);
            state.threshold = config.threshold || parseInt(root.getAttribute('data-threshold'), 10) || 3;
            state.pollIntervalMs = config.pollIntervalMs
                || parseInt(root.getAttribute('data-poll-interval-ms'), 10) || 10000;
            state.pollMax = config.pollMax
                || parseInt(root.getAttribute('data-poll-max'), 10) || 30;
            /*
             * Defaults to on when the attribute is absent, so a stale cached template degrades to
             * the old behaviour rather than to a panel that silently never estimates. Only an
             * explicit "0" — which the server writes whenever the site is over the limit — turns
             * the automatic path off.
             */
            state.autoEnabled = root.getAttribute('data-auto') !== '0';

            loadStrings().then(function() {
                state.autoMode = recomputeMode();
                if (state.slots.calcBtn) {
                    state.slots.calcBtn.addEventListener('click', function() {
                        trigger(true);
                    });
                }
                if (state.slots.retryBtn) {
                    state.slots.retryBtn.addEventListener('click', function() {
                        trigger(true);
                    });
                }
                bindFormChanges();
                if (state.autoEnabled) {
                    // Initial sync of context chips & summary.
                    debouncedTrigger();
                } else if (!state.slots.value || state.slots.value.textContent.trim() === '—') {
                    /*
                     * Only when the server rendered no stored count. On a large site that count and
                     * its date are the whole point of the panel, and overwriting the status line
                     * with "click when you are ready" would throw away the answer beside it.
                     */
                    setState(state.strings.manualReady);
                }
                return null;
            }).catch(function() { /* String loading failed — panel stays idle. */ });

            window.addEventListener('beforeunload', stopPolling);
        }
    };
});
