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
 * Tells the author, while they are still choosing, that this repeating notice would compete with
 * another one for the same pages.
 *
 * Notices are shown one at a time, so two repeating notices aimed at the same pages take turns
 * interrupting the same people. Saving already warns about it; this says so early enough to change
 * the answer instead of having to come back.
 *
 * @module     local_awareness/collision_warning
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'core/ajax',
    'core/str',
    'core/notification',
    'local_awareness/editor_scope'
], function(Ajax, Str, Notification, EditorScope) {

    var SELECTORS = {
        pathmatch: '#id_pathmatch',
        resetinterval: '#id_resetinterval_number',
        /*
         * Where the warning is written. The course form has no page-reach field, so the first of
         * these is absent there and the Reach line takes it — which is the line that says where the
         * notice fires, and so the right place to say what it will be competing with.
         */
        slotHost: '#fitem_id_pathmatch, #fitem_id_scope_line',
        noticeid: '#id_id',
        perpetual: '#id_perpetual',
        // Followed by one of DATE_PARTS: the selects the end date and time selector renders.
        timeend: '#id_timeend_'
    };

    // The keys check_collision reads the end date by, and the suffixes of its selects.
    var DATE_PARTS = ['year', 'month', 'day', 'hour', 'minute'];

    // Long enough that typing a path does not fire a request per keystroke.
    var DEBOUNCE_MS = 400;

    var state = {
        noticeid: 0,
        slot: null,
        timer: null,
        // Guards against a slow reply overwriting the answer to a later question.
        sequence: 0
    };

    /**
     * Build the element the warning is written into, once.
     *
     * @returns {Element|null} The slot, or null when the page has neither the page-reach field nor the Reach line.
     */
    var ensureSlot = function() {
        if (state.slot) {
            return state.slot;
        }
        var host = document.querySelector(SELECTORS.slotHost);
        if (!host) {
            return null;
        }
        var slot = document.createElement('div');
        slot.className = 'alert alert-warning mt-2';
        slot.setAttribute('role', 'status');
        slot.hidden = true;
        host.appendChild(slot);
        state.slot = slot;
        return slot;
    };

    /**
     * Whether the notice is currently set to repeat.
     *
     * Only the number matters. resetinterval is a duration field, so the unit select multiplies it,
     * but any non-zero number means it repeats whatever the unit says.
     *
     * @returns {Boolean} True when a repeat interval is set.
     */
    var repeats = function() {
        var field = document.querySelector(SELECTORS.resetinterval);
        return !!field && parseInt(field.value, 10) > 0;
    };

    /**
     * Whether the notice is set to run with no window, which makes its end date irrelevant.
     *
     * @returns {Boolean} True when perpetual, and where the form has no such field.
     */
    var perpetual = function() {
        var field = document.querySelector(SELECTORS.perpetual);
        return !field || field.value !== '0';
    };

    /**
     * The end date as its selector holds it, in the author's calendar and timezone.
     *
     * Sent as parts rather than as a timestamp: only the server knows the author's timezone and
     * calendar, and check_collision converts the parts as the save does.
     *
     * @returns {Object|null} The year, month, day, hour and minute, or null where the form has no end date.
     */
    var timeEnd = function() {
        var parts = {};
        var complete = DATE_PARTS.every(function(part) {
            var field = document.querySelector(SELECTORS.timeend + part);
            if (!field) {
                return false;
            }
            parts[part] = parseInt(field.value, 10) || 0;
            return true;
        });
        return complete ? parts : null;
    };

    /**
     * Put the current answer on screen.
     *
     * @param {Array} titles Titles of the notices this one would compete with.
     */
    var render = function(titles) {
        var slot = ensureSlot();
        if (!slot) {
            return;
        }
        if (!titles.length) {
            slot.hidden = true;
            slot.textContent = '';
            return;
        }
        Str.get_string('collision:live', 'local_awareness', titles.join(', ')).then(function(message) {
            slot.textContent = message;
            slot.hidden = false;
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Ask the server who this notice would compete with.
     */
    var check = function() {
        var pathfield = document.querySelector(SELECTORS.pathmatch);

        if (!repeats()) {
            // A notice shown once takes its turn and leaves, so it competes with nobody.
            render([]);
            return;
        }

        var mine = ++state.sequence;
        Ajax.call([{
            methodname: 'local_awareness_check_collision',
            args: {
                noticeid: state.noticeid,
                courseid: EditorScope.courseId(),
                // Empty where the form offers no field; the server's scope writes the real reach.
                pathmatch: pathfield ? pathfield.value : '',
                repeats: true,
                // The window the save would store: a notice whose end has passed competes with nobody.
                perpetual: perpetual(),
                timeend: timeEnd()
            }
        }])[0].then(function(response) {
            // A reply that arrived after a newer question was asked is stale, not an answer.
            if (mine === state.sequence) {
                render(response.titles || []);
            }
            return null;
        }).catch(Notification.exception);
    };

    /**
     * Coalesce bursts of typing into one request.
     */
    var schedule = function() {
        if (state.timer) {
            window.clearTimeout(state.timer);
        }
        state.timer = window.setTimeout(check, DEBOUNCE_MS);
    };

    return {
        /**
         * Start watching the fields the answer depends on.
         */
        init: function() {
            // The form carries the notice id in a hidden field; 0 while it is still being created.
            var idfield = document.querySelector(SELECTORS.noticeid);
            state.noticeid = idfield ? (parseInt(idfield.value, 10) || 0) : 0;

            var pathfield = document.querySelector(SELECTORS.pathmatch);
            var intervalfield = document.querySelector(SELECTORS.resetinterval);
            /*
             * The repeat interval alone is enough to watch. The course form has no page-reach field,
             * because the scope writes the reach, and a course author needs the warning most: every
             * course notice is forced onto the same course pages.
             */
            if (!pathfield && !intervalfield) {
                return;
            }

            if (pathfield) {
                pathfield.addEventListener('input', schedule);
            }
            if (intervalfield) {
                intervalfield.addEventListener('input', schedule);
                intervalfield.addEventListener('change', schedule);
            }
            var perpetualfield = document.querySelector(SELECTORS.perpetual);
            if (perpetualfield) {
                perpetualfield.addEventListener('change', schedule);
            }
            DATE_PARTS.forEach(function(part) {
                var field = document.querySelector(SELECTORS.timeend + part);
                if (field) {
                    field.addEventListener('change', schedule);
                }
            });

            // Answer for the state the form opens in, so an existing clash is visible immediately.
            check();
        }
    };
});
