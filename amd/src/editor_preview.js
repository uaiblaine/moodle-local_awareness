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
 * Preview the notice being edited, as the reader will get it.
 *
 * The form's fields go to the server, which renders them exactly as it renders a saved notice -
 * the multimedia filter runs there, and the slides are rows the form has not saved yet - and the
 * result opens in the real notice dialogue, with its layout, position, entrance and media. The
 * exits close the dialogue and record nothing: the dialogue's own listeners route Escape and a
 * backdrop click into the close button, and the handlers bound here are what the button does.
 *
 * This replaced a plain core/modal_cancel showing the editor's raw HTML, which had been "the
 * thing that ships" only while a notice was text and images.
 *
 * @module     local_awareness/editor_preview
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalNotice from 'local_awareness/modal_notice';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString} from 'core/str';

const SELECTORS = {
    editor: '[data-region="la-editor"]',
    /*
     * By its own data-action, not by the group's id. Inside core's sticky footer the group is
     * rendered from a different template and carries data-groupname rather than #fgroup_id_buttonar,
     * so an id-based selector matches nothing there.
     */
    trigger: '[data-action="preview"]',
    title: '[name="title"]',
    template: 'input[name="template"]:checked',
    position: 'input[name="position"]:checked',
    animation: '[name="animation"]',
    insistence: '[name="insistence"]',
    videourl: '[name="videourl"]',
    bgimage: 'input[name="bgimage"]',
    width: '[name="modal_width"]',
    height: '[name="modal_height"]',
    slideImages: 'input[name^="slide_image["]',
    closeActions: '[data-action="close"], [data-action="accept"]',
};

const SERVICES = {
    preview: 'local_awareness_preview_notice',
};

/**
 * The notice body as HTML, from whichever editor the site runs.
 *
 * Read when the button is pressed rather than at boot: core loads TinyMCE by injecting an async
 * script, so at boot window.tinymce is normally still undefined - and the textarea underneath it
 * holds the last saved value rather than what the author has just typed.
 *
 * @returns {string} The body markup, empty when there is nothing to show yet.
 */
const getContent = () => {
    const editable = document.querySelector('#id_contenteditable');

    if (editable) {
        return editable.innerHTML;
    }

    if (window.tinymce && window.tinymce.get('id_content')) {
        return window.tinymce.get('id_content').getContent();
    }

    const textarea = document.getElementById('id_content');

    return textarea ? textarea.value : '';
};

/**
 * A field's current value, or empty when the form does not carry it.
 *
 * @param {string} selector One of SELECTORS.
 * @returns {string}
 */
const value = (selector) => {
    const field = document.querySelector(selector);

    return field ? String(field.value) : '';
};

/**
 * The slide rows as the form holds them, in order.
 *
 * Each row is found from its file picker's hidden input, whose name carries the row index; the
 * link and the caption of the same row are read by that index.
 *
 * @returns {Array} Rows of {imagedraftid, videourl, caption}.
 */
const readSlides = () => {
    const rows = [];
    document.querySelectorAll(SELECTORS.slideImages).forEach((input) => {
        const match = input.name.match(/\[(\d+)\]/);
        if (!match) {
            return;
        }
        const index = match[1];
        rows.push({
            imagedraftid: parseInt(input.value, 10) || 0,
            videourl: value(`[name="slide_videourl[${index}]"]`),
            caption: value(`[name="slide_caption[${index}]"]`),
        });
    });

    return rows;
};

/**
 * Everything the preview service needs, read off the form now.
 *
 * @returns {Object} The service arguments.
 */
const readForm = () => ({
    courseid: parseInt(new URLSearchParams(window.location.search).get('courseid'), 10) || 0,
    title: value(SELECTORS.title).trim(),
    content: getContent(),
    template: value(SELECTORS.template) || 'classic',
    position: value(SELECTORS.position) || 'center',
    animation: value(SELECTORS.animation) || 'none',
    insistence: parseInt(value(SELECTORS.insistence), 10) || 0,
    videourl: value(SELECTORS.videourl),
    bgimagedraftid: parseInt(value(SELECTORS.bgimage), 10) || 0,
    modalwidth: value(SELECTORS.width),
    modalheight: value(SELECTORS.height),
    slides: readSlides(),
});

/**
 * Give the dialogue's exits something to do that records nothing.
 *
 * ModalNotice binds no handlers to its own buttons - the reader's queue does, with web-service
 * calls - and removeOnClose is inert on it, so the dialogue is destroyed here by hand.
 *
 * @param {Object} modal The ModalNotice.
 * @param {HTMLElement} trigger The button that opened it, so focus comes back to it.
 */
const bindPreviewExits = (modal, trigger) => {
    const dialog = modal.getModal();
    dialog.on('click', SELECTORS.closeActions, () => {
        modal.destroy();
        if (trigger) {
            trigger.focus();
        }
    });
    dialog.on('click', modal.getAckCheckboxID(), () => {
        const ticked = dialog.find(modal.getAckCheckboxID()).is(':checked');
        dialog.find(modal.getAcceptButtonID()).attr('disabled', !ticked);
    });
};

/**
 * Open the preview on whatever the form currently says.
 *
 * @param {HTMLElement} trigger The button that opened it, so focus comes back to it.
 * @returns {Promise} Resolves once the dialogue is showing.
 */
const openPreview = async(trigger) => {
    /*
     * The whole open is registered as pending work, not just the modal's creation: nothing is in
     * flight while the strings resolve, so a page watched for quiescence looks settled during a
     * window in which the dialogue does not exist yet.
     */
    const pending = new Pending('local_awareness/editor_preview:open');

    try {
        const payload = await Ajax.call([{methodname: SERVICES.preview, args: readForm()}])[0];

        const title = payload.title.trim() !== ''
            ? payload.title
            : await getString('editor:preview:title', 'local_awareness');
        const body = payload.content.trim() !== ''
            ? payload.content
            : await getString('editor:preview:empty', 'local_awareness');

        /*
         * Built fresh on every press. The content is whatever the form says at that moment, and a
         * kept modal would show what the form said the first time.
         */
        const modal = await ModalNotice.create({
            type: ModalNotice.TYPE,
            title: title,
            body: body,
            large: true,
        });
        bindPreviewExits(modal, trigger);
        modal.setInsistence(payload.insistence);
        // Shown before it is dressed: show() attaches the dialogue to the document, and the
        // video.js loader finds a player by id in the document - a detached band names nothing.
        await modal.show();
        await modal.setAppearance(payload);
        modal.setAnimation(payload.animation);
        modal.getModal().focus();
    } finally {
        pending.resolve();
    }
};

export const init = () => {
    const editor = document.querySelector(SELECTORS.editor);

    if (!editor) {
        return;
    }

    const trigger = editor.querySelector(SELECTORS.trigger);

    if (!trigger) {
        return;
    }

    trigger.addEventListener('click', event => {
        event.preventDefault();
        openPreview(trigger).catch(Notification.exception);
    });
};
