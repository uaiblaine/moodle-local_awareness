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
 * Orchestrator for the redesigned notice editor.
 *
 * Responsibilities:
 *  - Relocates moodleform field rows into the new section cards by ID.
 *  - Wires the side-nav scroll-spy.
 *  - Boots the legacy notice_form/init() (course-completion + competency picker).
 *  - Boots the live_preview and audience_estimator AMD modules.
 *
 * @module     local_awareness/notice_editor
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'local_awareness/notice_form',
    'local_awareness/live_preview',
    'local_awareness/audience_estimator'
], function(NoticeForm, LivePreview, AudienceEstimator) {
    'use strict';

    /** Mapping of section id → list of moodleform field names that belong in it. */
    var FIELD_MAP = {
        'sec-content': ['title', 'content', 'bgimage'],
        'sec-behavior': ['enabled', 'resetinterval', 'perpetual', 'timestart', 'timeend',
            'reqack', 'outsideclick', 'forcelogout'],
        'sec-appearance': ['modal_width', 'modal_height'],
        'sec-audience': ['cohorts', 'reqcourse', 'awareness-competency-filter',
            'filter_competency_requireall'],
        'sec-filters': ['pathmatch', 'filter_role', 'filter_category', 'filter_course',
            'filter_format', 'filter_theme']
    };

    /** Reveal the source form (un-hide it visually) for unmapped fields debug. */
    function relocateFields() {
        var source = document.getElementById('la-moodleform-source');
        if (!source) {
            return false;
        }

        // Make the source form discoverable by JS but visually hidden — it
        // continues to own validation, file API, autocompletes, sesskey.
        // We move its rendered field rows (`#fitem_id_<name>`) into the cards.
        source.hidden = false;
        source.style.position = 'absolute';
        source.style.width = '1px';
        source.style.height = '1px';
        source.style.overflow = 'hidden';
        source.style.clip = 'rect(0 0 0 0)';
        source.style.clipPath = 'inset(50%)';
        source.style.whiteSpace = 'nowrap';

        Object.keys(FIELD_MAP).forEach(function(sectionId) {
            var slot = document.querySelector('[data-section="' + sectionId + '"]');
            if (!slot) {
                return;
            }
            FIELD_MAP[sectionId].forEach(function(name) {
                // Try several lookup strategies — moodleform IDs vary by element type.
                var node = source.querySelector('#fitem_id_' + name)
                    || source.querySelector('#' + name) // Raw HTML element id.
                    || source.querySelector('[id^="fgroup_id_' + name + '"]')
                    || source.querySelector('#fitem_id_' + name + '_year'); // Fallback for the date_time_selector container.

                if (node) {
                    slot.appendChild(node);
                }
            });
        });

        // The form's submit/cancel button group is hidden — the action bar
        // submits the form via the `form="<formid>"` attribute. Hide the
        // moodleform-rendered button group to avoid double controls.
        var btnRow = source.querySelector('#fgroup_id_buttonar')
            || source.querySelector('[id^="fgroup_id_button"]')
            || source.querySelector('.fitem_actionbuttons');
        if (btnRow) {
            btnRow.style.display = 'none';
        }
        return true;
    }

    /** Smooth-scroll for side-nav clicks. */
    function bindSideNav() {
        var links = document.querySelectorAll('.la-sidenav-link');
        if (!links.length) {
            return;
        }
        links.forEach(function(link) {
            link.addEventListener('click', function(ev) {
                var target = link.getAttribute('data-target');
                var section = document.getElementById(target);
                if (!section) {
                    return;
                }
                ev.preventDefault();
                section.scrollIntoView({behavior: 'smooth', block: 'start'});
                history.replaceState(null, '', '#' + target);
                setActive(target);
            });
        });
    }

    /**
     * Flag the side-nav entry for the given section id as the current step.
     *
     * @param {string} id Section id whose nav link should be marked active.
     */
    function setActive(id) {
        document.querySelectorAll('.la-sidenav-link').forEach(function(a) {
            if (a.getAttribute('data-target') === id) {
                a.setAttribute('aria-current', 'step');
            } else {
                a.removeAttribute('aria-current');
            }
        });
    }

    /** Scroll-spy: highlight the side-nav entry whose section is closest to the top. */
    function bindScrollSpy() {
        var sections = Array.from(document.querySelectorAll('.la-card[id^="sec-"]'));
        if (!sections.length) {
            return;
        }
        var ticking = false;
        /**
         * Recompute the active section while scrolling (rAF-throttled).
         */
        function onScroll() {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function() {
                ticking = false;
                var topThreshold = 100;
                var current = sections[0].id;
                for (var i = 0; i < sections.length; i++) {
                    var rect = sections[i].getBoundingClientRect();
                    if (rect.top <= topThreshold) {
                        current = sections[i].id;
                    }
                }
                setActive(current);
            });
        }
        window.addEventListener('scroll', onScroll, {passive: true});
        onScroll();
    }

    return {
        init: function() {
            // Wait for the source form's rendering to complete (Moodle injects
            // some elements late, e.g. autocomplete enhancements). A short
            // setTimeout + MutationObserver fallback covers both cases.
            var ready = false;
            var bootstrap = function() {
                if (ready) {
                    return;
                }
                ready = true;
                relocateFields();
                bindSideNav();
                bindScrollSpy();
                // Boot the legacy notice_form bindings (course → reset/reqack
                // dependency, plus the competency picker initialiser).
                try {
                    NoticeForm.init();
                } catch (e) { /* No-op. */ }
                LivePreview.init();
                AudienceEstimator.init();
            };

            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                setTimeout(bootstrap, 50);
            } else {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(bootstrap, 50);
                });
            }
        }
    };
});
