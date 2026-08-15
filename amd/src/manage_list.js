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
 * Filter bar for the notice list.
 *
 * The list itself is a core dynamic table: this module only translates the controls into a
 * filterset and hands it over, and core fetches and swaps the table's markup. Nothing here knows
 * how a page of notices is loaded.
 *
 * @module     local_awareness/manage_list
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {setFilters} from 'core_table/dynamic';
import Pending from 'core/pending';

const SELECTORS = {
    root: '[data-region="la-manage"]',
    table: '[data-region="core_table/dynamic"]',
    filter: '[data-filter]',
    clear: '[data-action="clear-filters"]',
    barClear: '.local-awareness-filter-clear',
};

/** Combine the filters rather than letting any one of them match on its own. */
const JOINTYPE_ALL = 2;

/** A single filter matches any of its values; each of ours carries exactly one. */
const JOINTYPE_ANY = 1;

/** Keystrokes settle before a request goes out. */
const DEBOUNCE_MS = 250;

/**
 * Read every filter control into a plain name => value map.
 *
 * @param {HTMLElement} root
 * @returns {Object}
 */
const readValues = root => {
    const values = {};

    root.querySelectorAll(SELECTORS.filter).forEach(control => {
        const value = control.value.trim();
        if (value !== '') {
            values[control.dataset.filter] = value;
        }
    });

    return values;
};

/**
 * Shape the values the way core's filterset serialises itself.
 *
 * @param {Object} values
 * @returns {Object}
 */
const buildFilterset = values => {
    const filters = {};

    Object.entries(values).forEach(([name, value]) => {
        filters[name] = {
            name,
            jointype: JOINTYPE_ANY,
            values: [value],
        };
    });

    return {
        jointype: JOINTYPE_ALL,
        filters,
    };
};

/**
 * Show the "clear filters" button only when there is something to clear.
 *
 * @param {HTMLElement} root
 * @param {Object} values
 */
const updateClearButton = (root, values) => {
    const button = root.querySelector(SELECTORS.barClear);

    if (button) {
        button.hidden = Object.keys(values).length === 0;
    }
};

/**
 * Push the current control values at the table.
 *
 * @param {HTMLElement} root
 * @returns {Promise}
 */
const applyFilters = root => {
    const table = root.querySelector(SELECTORS.table);

    if (!table) {
        return Promise.resolve();
    }

    const values = readValues(root);
    updateClearButton(root, values);

    return setFilters(table, buildFilterset(values));
};

export const init = () => {
    const root = document.querySelector(SELECTORS.root);

    if (!root) {
        return;
    }

    let debounce = null;
    let debouncePending = null;

    root.addEventListener('input', event => {
        const control = event.target.closest(SELECTORS.filter);
        if (!control || control.tagName !== 'INPUT') {
            return;
        }

        window.clearTimeout(debounce);

        /*
         * The wait itself is registered as pending work, not just the request that follows it.
         * setFilters() registers its own, but the debounce window is a gap where nothing is in
         * flight and the page looks idle — long enough for anything watching for quiescence to
         * conclude the list had already settled and read the previous rows.
         */
        if (!debouncePending) {
            debouncePending = new Pending('local_awareness/manage_list:filter');
        }

        debounce = window.setTimeout(() => {
            const pending = debouncePending;
            debouncePending = null;

            applyFilters(root)
                .catch(() => null)
                .then(() => pending.resolve())
                .catch(() => null);
        }, DEBOUNCE_MS);
    });

    root.addEventListener('change', event => {
        if (event.target.closest(SELECTORS.filter)) {
            applyFilters(root);
        }
    });

    /*
     * Delegated, because one of these buttons lives inside the table's own empty state and is
     * replaced wholesale every time the table refreshes.
     */
    root.addEventListener('click', event => {
        if (!event.target.closest(SELECTORS.clear)) {
            return;
        }

        event.preventDefault();
        root.querySelectorAll(SELECTORS.filter).forEach(control => {
            control.value = '';
        });
        applyFilters(root);
    });
};
