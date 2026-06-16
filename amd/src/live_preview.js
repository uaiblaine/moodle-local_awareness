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
 * Live preview synchroniser. Listens to changes on the moodleform fields and
 * mirrors the visible state into the preview card slots in place.
 *
 * @module     local_awareness/live_preview
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str'], function(str) {
    'use strict';

    var SLOTS = {};
    var debounceTimer = null;

    /**
     * Truncate to N chars without breaking inside a word.
     *
     * @param {string} text Raw text to shorten.
     * @param {number} max Maximum number of characters to keep.
     * @returns {string} Truncated text, with an ellipsis when shortened.
     */
    function truncate(text, max) {
        if (!text) {
            return '';
        }
        text = String(text).replace(/\s+/g, ' ').trim();
        if (text.length <= max) {
            return text;
        }
        return text.substring(0, max).replace(/\s\S*$/, '') + '…';
    }

    /**
     * Read the current value of a named form field.
     *
     * @param {string} name The field name attribute to look up.
     * @returns {string} The field value, or an empty string when absent.
     */
    function getValue(name) {
        var el = document.querySelector('[name="' + name + '"]');
        return el ? (el.value || '') : '';
    }

    /**
     * Resolve whether a named checkbox/select field is in the "on" state.
     *
     * @param {string} name The field name attribute to look up.
     * @returns {boolean} True when checked, or when a select's value equals 1.
     */
    function isChecked(name) {
        var el = document.querySelector('[name="' + name + '"]');
        if (!el) {
            return false;
        }
        if (el.tagName === 'SELECT') {
            return parseInt(el.value, 10) === 1;
        }
        return !!el.checked;
    }

    /**
     * Read the current content from the WYSIWYG editor area; falls back to the
     * underlying textarea value for non-WYSIWYG modes.
     */
    function getContent() {
        // Atto / contenteditable.
        var editable = document.querySelector('#id_contenteditable');
        if (editable) {
            return editable.innerText || editable.textContent || '';
        }
        // TinyMCE iframe.
        if (window.tinymce && window.tinymce.get('id_content')) {
            try {
                return window.tinymce.get('id_content').getContent({format: 'text'}) || '';
            } catch (e) {
                // Editor not initialised yet — fall through.
            }
        }
        // Plain textarea fallback.
        var ta = document.getElementById('id_content');
        if (ta && ta.value) {
            // Strip tags for preview.
            return ta.value.replace(/<[^>]+>/g, ' ');
        }
        return '';
    }

    /**
     * Mirror the current form state into the preview card.
     *
     * @param {object} strings Resolved language strings used in the preview.
     */
    function sync(strings) {
        if (!SLOTS.title) {
            return;
        }

        var title = getValue('title');
        SLOTS.title.textContent = title || strings.placeholderTitle;

        var content = truncate(getContent(), 200);
        SLOTS.content.textContent = content || strings.placeholderContent;

        var width = getValue('modal_width').trim();
        if (SLOTS.modal) {
            if (width !== '') {
                // Treat bare numbers as px.
                var w = /^\d+$/.test(width) ? width + 'px' : width;
                SLOTS.modal.style.maxWidth = w;
            } else {
                SLOTS.modal.style.maxWidth = '';
            }
        }

        var reqack = isChecked('reqack');
        var outsideclick = isChecked('outsideclick');
        var forcelogout = isChecked('forcelogout');

        if (SLOTS.actions) {
            SLOTS.actions.innerHTML = '';
            if (reqack) {
                SLOTS.actions.appendChild(makeBtn(strings.iAmAware, 'la-btn--brand'));
            } else {
                SLOTS.actions.appendChild(makeBtn(strings.later, 'la-btn--ghost'));
                SLOTS.actions.appendChild(makeBtn(strings.gotIt, 'la-btn--brand'));
            }
        }

        if (SLOTS.metaReqack) {
            SLOTS.metaReqack.textContent = reqack ? strings.required : strings.optional;
        }
        if (SLOTS.metaOutsideClick) {
            SLOTS.metaOutsideClick.textContent = outsideclick ? strings.yes : strings.no;
        }
        if (SLOTS.metaForceLogout) {
            SLOTS.metaForceLogout.textContent = forcelogout ? strings.forced : strings.no;
        }

        // Status badge: live when 'enabled' is on (and not in the middle of editing yet-disabled notice).
        if (SLOTS.status) {
            var live = isChecked('enabled');
            SLOTS.status.textContent = live ? strings.live : strings.draft;
        }
    }

    /**
     * Build a preview action button element.
     *
     * @param {string} label Visible button text.
     * @param {string} cls Extra CSS class controlling the button style.
     * @returns {HTMLButtonElement} The constructed button element.
     */
    function makeBtn(label, cls) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'la-btn ' + cls;
        b.textContent = label;
        return b;
    }

    /**
     * Debounce a preview sync call by 250ms to coalesce rapid edits.
     *
     * @param {object} strings Resolved language strings used in the preview.
     */
    function debouncedSync(strings) {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(function() {
            sync(strings);
            debounceTimer = null;
        }, 250);
    }

    /**
     * Wire editor change listeners (Atto contenteditable and TinyMCE) to the
     * debounced preview sync.
     *
     * @param {object} strings Resolved language strings used in the preview.
     */
    function bindEditor(strings) {
        // Atto: contenteditable area.
        var editable = document.querySelector('#id_contenteditable');
        if (editable) {
            editable.addEventListener('input', function() {
                debouncedSync(strings);
            });
        }
        // TinyMCE: editor 'input' event, set up after init.
        if (window.tinymce) {
            try {
                window.tinymce.on('AddEditor', function(e) {
                    if (e.editor && e.editor.id === 'id_content') {
                        e.editor.on('input keyup change', function() {
                            debouncedSync(strings);
                        });
                    }
                });
            } catch (err) {
                // Continue silently.
            }
        }
    }

    /**
     * Cache the preview card slot elements for later updates.
     *
     * @returns {boolean} True when the preview region exists and slots were cached.
     */
    function captureSlots() {
        var preview = document.querySelector('[data-region="la-preview"]');
        if (!preview) {
            return false;
        }
        SLOTS.title = preview.querySelector('[data-slot="title"]');
        SLOTS.content = preview.querySelector('[data-slot="content"]');
        SLOTS.modal = preview.querySelector('[data-slot="mockmodal"]');
        SLOTS.actions = preview.querySelector('[data-slot="actions"]');
        SLOTS.metaReqack = preview.querySelector('[data-slot="meta-reqack"]');
        SLOTS.metaOutsideClick = preview.querySelector('[data-slot="meta-outsideclick"]');
        SLOTS.metaForceLogout = preview.querySelector('[data-slot="meta-forcelogout"]');
        SLOTS.status = preview.querySelector('[data-slot="status"]');
        return true;
    }

    /**
     * Load and map the preview language strings.
     *
     * @returns {Promise<object>} Promise resolving to the named strings object.
     */
    function loadStrings() {
        return str.get_strings([
            {key: 'editor:preview:placeholder:title', component: 'local_awareness'},
            {key: 'editor:preview:placeholder:content', component: 'local_awareness'},
            {key: 'editor:preview:btn:iam_aware', component: 'local_awareness'},
            {key: 'editor:preview:btn:later', component: 'local_awareness'},
            {key: 'editor:preview:btn:gotit', component: 'local_awareness'},
            {key: 'editor:preview:meta:required', component: 'local_awareness'},
            {key: 'editor:preview:meta:optional', component: 'local_awareness'},
            {key: 'editor:preview:meta:forced', component: 'local_awareness'},
            {key: 'editor:preview:meta:yes', component: 'local_awareness'},
            {key: 'editor:preview:meta:no', component: 'local_awareness'},
            {key: 'editor:status:live', component: 'local_awareness'},
            {key: 'editor:status:draft', component: 'local_awareness'}
        ]).then(function(s) {
            return {
                placeholderTitle: s[0],
                placeholderContent: s[1],
                iAmAware: s[2],
                later: s[3],
                gotIt: s[4],
                required: s[5],
                optional: s[6],
                forced: s[7],
                yes: s[8],
                no: s[9],
                live: s[10],
                draft: s[11]
            };
        });
    }

    return {
        init: function() {
            if (!captureSlots()) {
                return;
            }
            loadStrings().then(function(strings) {
                sync(strings);
                bindEditor(strings);

                // Listen to plain inputs/selects in the source form.
                ['title', 'modal_width', 'modal_height', 'reqack', 'outsideclick',
                    'forcelogout', 'enabled'].forEach(function(name) {
                    document.querySelectorAll('[name="' + name + '"]').forEach(function(el) {
                        el.addEventListener('change', function() {
                            debouncedSync(strings);
                        });
                        el.addEventListener('input', function() {
                            debouncedSync(strings);
                        });
                    });
                });
                return null;
            }).catch(function() {
                // String-loading failures are non-fatal — preview just won't update.
            });

            // Tab switching desktop/mobile.
            document.querySelectorAll('.la-preview-tab').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.la-preview-tab').forEach(function(b) {
                        b.classList.remove('is-on');
                        b.setAttribute('aria-selected', 'false');
                    });
                    btn.classList.add('is-on');
                    btn.setAttribute('aria-selected', 'true');
                    var modal = SLOTS.modal;
                    if (modal) {
                        if (btn.getAttribute('data-tab') === 'mobile') {
                            modal.style.maxWidth = '240px';
                        } else {
                            modal.style.maxWidth = '';
                            // Re-apply width from form.
                            var w = getValue('modal_width').trim();
                            if (w !== '') {
                                modal.style.maxWidth = /^\d+$/.test(w) ? w + 'px' : w;
                            }
                        }
                    }
                });
            });
        }
    };
});
