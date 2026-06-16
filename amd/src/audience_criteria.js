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
 * Reads the moodleform's current state and produces a normalised criteria
 * object shared by audience_estimator and live_preview.
 *
 * Field IDs follow Moodle's `id_<fieldname>` convention. Multi-select
 * autocompletes render their selected values into hidden inputs that share
 * the field name; we read those names directly.
 *
 * @module     local_awareness/audience_criteria
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    // The criteria object keys mirror the moodleform/server field names
    // (filter_role, filter_category, …), which are snake_case by contract.
    /* eslint-disable camelcase */

    var AUDIENCE_KEYS = ['cohorts', 'filter_role', 'reqcourse'];
    var CONTEXT_KEYS = [
        'pathmatch',
        'filter_category',
        'filter_course',
        'filter_format',
        'filter_theme',
        'filter_competency_rules'
    ];

    /**
     * Read all values for a multi-value field. Handles:
     *  - Moodle multi-autocomplete (hidden inputs all sharing name="X[]" or "X")
     *  - <select multiple>
     *  - regular inputs
     *
     * @param {string} name moodleform field name
     * @returns {Array<string>}
     */
    function readMultiValue(name) {
        var out = [];
        // Multi-select.
        var sel = document.querySelector('select[name="' + name + '[]"], select[name="' + name + '"]');
        if (sel && sel.multiple) {
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].selected && sel.options[i].value) {
                    out.push(sel.options[i].value);
                }
            }
            return out;
        }

        // Hidden inputs (autocomplete with multi=true).
        var nodes = document.querySelectorAll(
            'input[name="' + name + '[]"], input[name="' + name + '"]'
        );
        nodes.forEach(function(node) {
            if (node.type === 'hidden' || node.type === 'text') {
                if (node.value !== '' && node.value !== '_qf__force_multiselect_submission') {
                    // Some autocomplete widgets store comma-separated values.
                    if (node.value.indexOf(',') !== -1) {
                        node.value.split(',').forEach(function(v) {
                            v = v.trim();
                            if (v !== '') {
                                out.push(v);
                            }
                        });
                    } else {
                        out.push(node.value);
                    }
                }
            }
        });

        return out;
    }

    /**
     * Read a single-value field (input/select).
     *
     * @param {string} name
     * @returns {string}
     */
    function readSingleValue(name) {
        var el = document.querySelector('[name="' + name + '"]');
        return el ? (el.value || '') : '';
    }

    /**
     * Read the competency rules JSON from the hidden input.
     *
     * @returns {Array<{id: number, proficient: number, name: string}>}
     */
    function readCompetencyRules() {
        var el = document.getElementById('id_filter_competency_rules')
            || document.querySelector('input[name="filter_competency_rules"]');
        if (!el || !el.value) {
            return [];
        }
        try {
            var parsed = JSON.parse(el.value);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Read the boolean require-all flag.
     *
     * @returns {number} 0 or 1
     */
    function readRequireAll() {
        var el = document.getElementById('id_filter_competency_requireall')
            || document.querySelector('[name="filter_competency_requireall"]');
        if (!el) {
            return 0;
        }
        return parseInt(el.value, 10) === 1 ? 1 : 0;
    }

    /**
     * Snapshot the current form state into a deterministic criteria object.
     *
     * @returns {Object}
     */
    function read() {
        var criteria = {};

        var cohorts = readMultiValue('cohorts').map(function(v) {
            return parseInt(v, 10);
        })
            .filter(function(v) {
                return v > 0;
            });
        if (cohorts.length) {
            criteria.cohorts = cohorts;
        }

        var roles = readMultiValue('filter_role').map(function(v) {
            return parseInt(v, 10);
        })
            .filter(function(v) {
                return v > 0;
            });
        if (roles.length) {
            criteria.filter_role = roles;
        }

        var reqcourse = parseInt(readSingleValue('reqcourse'), 10);
        if (reqcourse > 0) {
            criteria.reqcourse = reqcourse;
        }

        var categories = readMultiValue('filter_category').map(function(v) {
            return parseInt(v, 10);
        })
            .filter(function(v) {
                return v > 0;
            });
        if (categories.length) {
            criteria.filter_category = categories;
        }

        var courses = readMultiValue('filter_course').map(function(v) {
            return parseInt(v, 10);
        })
            .filter(function(v) {
                return v > 0;
            });
        if (courses.length) {
            criteria.filter_course = courses;
        }

        var formats = readMultiValue('filter_format').filter(function(v) {
            return v !== '';
        });
        if (formats.length) {
            criteria.filter_format = formats;
        }

        var themes = readMultiValue('filter_theme').filter(function(v) {
            return v !== '';
        });
        if (themes.length) {
            criteria.filter_theme = themes;
        }

        var rules = readCompetencyRules();
        if (rules.length) {
            criteria.filter_competency_rules = rules;
            criteria.filter_competency_requireall = readRequireAll();
        }

        var path = (readSingleValue('pathmatch') || '').trim();
        if (path !== '') {
            criteria.pathmatch = path;
        }

        return criteria;
    }

    /**
     * Count audience-shaping rules in the criteria (cohorts, role, reqcourse).
     *
     * @param {Object} criteria
     * @returns {number}
     */
    function countAudienceRules(criteria) {
        var n = 0;
        AUDIENCE_KEYS.forEach(function(k) {
            if (criteria[k] && (typeof criteria[k] === 'number' ? criteria[k] > 0 : criteria[k].length > 0)) {
                n += 1;
            }
        });
        return n;
    }

    /**
     * Count context-only rules in the criteria.
     *
     * @param {Object} criteria
     * @returns {number}
     */
    function countContextRules(criteria) {
        var n = 0;
        CONTEXT_KEYS.forEach(function(k) {
            if (criteria[k] && (typeof criteria[k] === 'string' ? criteria[k] !== '' : criteria[k].length > 0)) {
                n += 1;
            }
        });
        return n;
    }

    return {
        read: read,
        countAudienceRules: countAudienceRules,
        countContextRules: countContextRules,
        AUDIENCE_KEYS: AUDIENCE_KEYS,
        CONTEXT_KEYS: CONTEXT_KEYS
    };
});
