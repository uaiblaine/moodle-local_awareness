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
 * The notice preview dialogue.
 *
 * The preview is a core/modal, opened from the page head. Everything that makes a dialogue usable
 * from the keyboard — the focus trap, Escape, returning focus to the button that opened it — comes
 * from core; a hand-made dialogue gets none of it, which is the only reason this module knows about
 * modals at all.
 *
 * The markup is not built here. The shell renders it once inside an inert template element and this
 * module hands that markup to core/modal as the dialogue body, so the slots keep the values no
 * JavaScript recomputes and the strings are resolved server side.
 *
 * State is read from the form when the dialogue opens, rather than mirrored into it as the author
 * types. The dialogue covers the form, so there is nothing to mirror while it is open — and reading
 * late is what makes the content pane correct: the previous version bound to TinyMCE at boot, when
 * core has not finished injecting it, and silently showed an empty body for the whole session.
 *
 * @module     local_awareness/live_preview
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalCancel from 'core/modal_cancel';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getStrings} from 'core/str';

const SELECTORS = {
    editor: '[data-region="la-editor"]',
    trigger: '[data-action="preview"]',
    source: 'template[data-region="la-preview-source"]',
    preview: '[data-region="la-preview"]',
    toggle: '.la-preview-tab',
};

/** How wide the mock modal is drawn when the Mobile viewport is selected. */
const MOBILE_WIDTH = '240px';

/** How much of the notice body the preview shows. */
const CONTENT_CHARS = 200;

/** The dialogue's data slots, captured once from the modal body. */
const SLOTS = {};

/** Resolved once, reused for the life of the page. */
let stringsPromise = null;

/** The dialogue itself, created on the first open and reused after that. */
let modalPromise = null;

/**
 * Truncate to a length without breaking inside a word.
 *
 * @param {string} text Raw text to shorten.
 * @param {number} max Maximum number of characters to keep.
 * @returns {string} The text, with an ellipsis when it was shortened.
 */
const truncate = (text, max) => {
    if (!text) {
        return '';
    }

    const collapsed = String(text).replace(/\s+/g, ' ').trim();

    if (collapsed.length <= max) {
        return collapsed;
    }

    return collapsed.substring(0, max).replace(/\s\S*$/, '') + '…';
};

/**
 * Read the current value of a named form field.
 *
 * @param {string} name The field's name attribute.
 * @returns {string} The value, or an empty string when the field is absent.
 */
const getValue = name => {
    const element = document.querySelector('[name="' + name + '"]');

    return element ? (element.value || '') : '';
};

/**
 * Resolve whether a named field is in the "on" state.
 *
 * Every one of these is a selectyesno in the form, so the select branch is the live one; the
 * checkbox branch is there because the field type is the form's business, not the preview's.
 *
 * @param {string} name The field's name attribute.
 * @returns {boolean} True when checked, or when a select's value is 1.
 */
const isChecked = name => {
    const element = document.querySelector('[name="' + name + '"]');

    if (!element) {
        return false;
    }

    if (element.tagName === 'SELECT') {
        return parseInt(element.value, 10) === 1;
    }

    return !!element.checked;
};

/**
 * Read the notice body out of whichever editor the site runs.
 *
 * Called when the dialogue opens, never at boot: core loads TinyMCE by injecting an async script,
 * so at boot window.tinymce is normally still undefined and the textarea below it holds the last
 * saved value rather than what the author just typed.
 *
 * @returns {string} The body as plain text.
 */
const getContent = () => {
    const editable = document.querySelector('#id_contenteditable');

    if (editable) {
        return editable.innerText || editable.textContent || '';
    }

    if (window.tinymce && window.tinymce.get('id_content')) {
        return window.tinymce.get('id_content').getContent({format: 'text'}) || '';
    }

    const textarea = document.getElementById('id_content');

    if (textarea && textarea.value) {
        return textarea.value.replace(/<[^>]+>/g, ' ');
    }

    return '';
};

/**
 * Describe the reset interval the way the form's duration selector does.
 *
 * The unit's own option text is the label, so this needs no strings of its own and cannot drift
 * out of step with the field it reports.
 *
 * @returns {string|null} The formatted interval, or null when the field is not on the page.
 */
const getFrequency = () => {
    const number = document.querySelector('[name="resetinterval[number]"]');
    const unit = document.querySelector('[name="resetinterval[timeunit]"]');

    if (!number || !unit) {
        return null;
    }

    const count = parseInt(number.value, 10);

    if (!count) {
        return '0';
    }

    const selected = unit.options[unit.selectedIndex];

    return count + ' ' + (selected ? selected.text : '');
};

/**
 * Build one of the mock modal's action buttons.
 *
 * A span, not a button. This is a picture of the notice, so a real control here would be focusable
 * and announced as actionable inside a dialogue where activating it does nothing.
 *
 * @param {string} label The visible text.
 * @param {string} variant The class controlling the button style.
 * @returns {HTMLElement} The constructed element.
 */
const makeAction = (label, variant) => {
    const action = document.createElement('span');

    action.className = 'la-btn ' + variant;
    action.textContent = label;

    return action;
};

/**
 * Apply a width to the mock modal, treating a bare number as pixels.
 *
 * @param {string} width The raw value from the width field, or an empty string.
 */
const applyWidth = width => {
    if (!SLOTS.modal) {
        return;
    }

    const trimmed = width.trim();

    if (trimmed === '') {
        SLOTS.modal.style.maxWidth = '';

        return;
    }

    SLOTS.modal.style.maxWidth = /^\d+$/.test(trimmed) ? trimmed + 'px' : trimmed;
};

/**
 * Draw the mock at whichever viewport is currently selected.
 *
 * One function decides the width, from one piece of state — which toggle is pressed. Splitting it,
 * so that the click handler set the mobile width and sync() set the form's, is how the dialogue
 * came to reopen at desktop width with the Mobile button still reporting aria-pressed="true": the
 * modal is kept rather than destroyed on close, so the pressed state outlives the width that was
 * meant to go with it.
 */
const applyViewport = () => {
    const pressed = (SLOTS.toggles || []).filter(toggle => toggle.getAttribute('aria-pressed') === 'true');
    const mobile = pressed.length > 0 && pressed[0].dataset.tab === 'mobile';

    applyWidth(mobile ? MOBILE_WIDTH : getValue('modal_width'));
};

/**
 * Mirror the form's current state into the dialogue.
 *
 * @param {Object} strings The resolved preview strings.
 */
const sync = strings => {
    if (!SLOTS.title) {
        return;
    }

    SLOTS.title.textContent = getValue('title') || strings.placeholderTitle;
    SLOTS.content.textContent = truncate(getContent(), CONTENT_CHARS) || strings.placeholderContent;

    applyViewport();

    const reqack = isChecked('reqack');

    if (SLOTS.actions) {
        SLOTS.actions.innerHTML = '';

        if (reqack) {
            SLOTS.actions.appendChild(makeAction(strings.iAmAware, 'la-btn--brand'));
        } else {
            SLOTS.actions.appendChild(makeAction(strings.later, 'la-btn--ghost'));
            SLOTS.actions.appendChild(makeAction(strings.gotIt, 'la-btn--brand'));
        }
    }

    if (SLOTS.metaReqack) {
        SLOTS.metaReqack.textContent = reqack ? strings.required : strings.optional;
    }

    if (SLOTS.metaOutsideClick) {
        SLOTS.metaOutsideClick.textContent = isChecked('outsideclick') ? strings.yes : strings.no;
    }

    if (SLOTS.metaForceLogout) {
        SLOTS.metaForceLogout.textContent = isChecked('forcelogout') ? strings.forced : strings.no;
    }

    const frequency = getFrequency();

    if (SLOTS.metaFrequency && frequency !== null) {
        SLOTS.metaFrequency.textContent = frequency;
    }

    if (SLOTS.status) {
        SLOTS.status.textContent = isChecked('enabled') ? strings.live : strings.draft;
    }
};

/**
 * Cache the dialogue's data slots.
 *
 * @param {HTMLElement} root The dialogue body.
 */
const captureSlots = root => {
    SLOTS.title = root.querySelector('[data-slot="title"]');
    SLOTS.content = root.querySelector('[data-slot="content"]');
    SLOTS.modal = root.querySelector('[data-slot="mockmodal"]');
    SLOTS.actions = root.querySelector('[data-slot="actions"]');
    SLOTS.metaFrequency = root.querySelector('[data-slot="meta-frequency"]');
    SLOTS.metaReqack = root.querySelector('[data-slot="meta-reqack"]');
    SLOTS.metaOutsideClick = root.querySelector('[data-slot="meta-outsideclick"]');
    SLOTS.metaForceLogout = root.querySelector('[data-slot="meta-forcelogout"]');
    SLOTS.status = root.querySelector('[data-slot="status"]');
    SLOTS.toggles = Array.from(root.querySelectorAll(SELECTORS.toggle));
};

/**
 * Wire the Desktop and Mobile viewport toggles.
 *
 * Runs once, on the toggles captured by captureSlots().
 */
const bindToggles = () => {
    SLOTS.toggles.forEach(toggle => {
        toggle.addEventListener('click', () => {
            SLOTS.toggles.forEach(other => {
                other.classList.toggle('is-on', other === toggle);
                other.setAttribute('aria-pressed', other === toggle ? 'true' : 'false');
            });

            applyViewport();
        });
    });
};

/**
 * Load the strings the dialogue fills itself with.
 *
 * @returns {Promise<Object>} Resolves to the named strings.
 */
const loadStrings = () => {
    if (!stringsPromise) {
        stringsPromise = getStrings([
            {key: 'editor:preview:title', component: 'local_awareness'},
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
            {key: 'editor:status:draft', component: 'local_awareness'},
            {key: 'closebuttontitle', component: 'core'},
        ]).then(resolved => ({
            dialogueTitle: resolved[0],
            placeholderTitle: resolved[1],
            placeholderContent: resolved[2],
            iAmAware: resolved[3],
            later: resolved[4],
            gotIt: resolved[5],
            required: resolved[6],
            optional: resolved[7],
            forced: resolved[8],
            yes: resolved[9],
            no: resolved[10],
            live: resolved[11],
            draft: resolved[12],
            close: resolved[13],
        })).catch(error => {
            stringsPromise = null;

            throw error;
        });
    }

    return stringsPromise;
};

/**
 * Build the dialogue, once.
 *
 * @param {HTMLElement} source The template element holding the preview markup.
 * @param {HTMLElement} trigger The button that opens the dialogue.
 * @param {Object} strings The resolved preview strings.
 * @returns {Promise<Object>} Resolves to the core/modal instance.
 */
const buildModal = (source, trigger, strings) => {
    if (!modalPromise) {
        modalPromise = ModalCancel.create({
            title: strings.dialogueTitle,
            body: source.innerHTML,
            large: true,
            removeOnClose: false,
            returnElement: trigger,
            buttons: {cancel: strings.close},
        }).then(modal => {
            const body = modal.getBody()[0].querySelector(SELECTORS.preview);

            captureSlots(body);
            bindToggles();

            return modal;
        }).catch(error => {
            /*
             * A failed build must not stay memoised. Caching the rejection would leave the next
             * click resolving instantly to the same failure, which is precisely the state this
             * whole change exists to end: a Preview button that does nothing.
             */
            modalPromise = null;

            throw error;
        });
    }

    return modalPromise;
};

/**
 * Open the dialogue on the form's current state.
 *
 * @param {HTMLElement} source The template element holding the preview markup.
 * @param {HTMLElement} trigger The button that opens the dialogue.
 * @returns {Promise} Resolves once the dialogue is showing.
 */
const openPreview = async(source, trigger) => {
    /*
     * The whole open is registered as pending work, not just the modal's own creation. Nothing is
     * in flight while the strings resolve, so a page watched for quiescence looks settled during a
     * window in which the dialogue does not exist yet.
     */
    const pending = new Pending('local_awareness/live_preview:open');

    try {
        const strings = await loadStrings();
        const modal = await buildModal(source, trigger, strings);

        sync(strings);

        await modal.show();
    } finally {
        pending.resolve();
    }
};

export const init = () => {
    const editor = document.querySelector(SELECTORS.editor);

    if (!editor) {
        return;
    }

    const source = editor.querySelector(SELECTORS.source);
    const trigger = editor.querySelector(SELECTORS.trigger);

    if (!source || !trigger) {
        return;
    }

    trigger.addEventListener('click', event => {
        event.preventDefault();

        openPreview(source, trigger).catch(Notification.exception);
    });
};
