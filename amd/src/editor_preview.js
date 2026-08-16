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
 * This replaces a hand-drawn mock of the notice modal that was itself rendered inside a core modal
 * — a picture of a dialogue, inside a dialogue, showing truncated plain text with the formatting
 * stripped. It was not a preview; it was an illustration that had to be kept in step with the real
 * thing by hand, and it drifted the moment either changed.
 *
 * The same shape the manage list uses for its own preview: the content goes into a
 * core/modal_cancel, which is what the reader eventually sees it in. The difference is where the
 * content comes from — the list has a saved notice, and this has a form that may never have been
 * saved, so it reads the editor rather than the database.
 *
 * @module     local_awareness/editor_preview
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalCancel from 'core/modal_cancel';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {getString} from 'core/str';

const SELECTORS = {
    editor: '[data-region="la-editor"]',
    /*
     * By its own data-action, not by the group's id. Inside core's sticky footer the group is
     * rendered from a different template and carries data-groupname rather than #fgroup_id_buttonar,
     * so an id-based selector matches nothing there — which is how this shipped as a button that
     * did nothing at all until Behat said so.
     */
    trigger: '[data-action="preview"]',
    title: '[name="title"]',
};

/**
 * The notice body as HTML, from whichever editor the site runs.
 *
 * Read when the button is pressed rather than at boot: core loads TinyMCE by injecting an async
 * script, so at boot window.tinymce is normally still undefined — and the textarea underneath it
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
 * Open the preview on whatever the form currently says.
 *
 * @param {HTMLElement} trigger The button that opened it, so focus comes back to it.
 * @returns {Promise} Resolves once the dialogue is showing.
 */
const openPreview = async(trigger) => {
    /*
     * The whole open is registered as pending work, not just the modal's creation: nothing is in
     * flight while the string resolves, so a page watched for quiescence looks settled during a
     * window in which the dialogue does not exist yet.
     */
    const pending = new Pending('local_awareness/editor_preview:open');

    try {
        const titlefield = document.querySelector(SELECTORS.title);
        const title = titlefield && titlefield.value.trim() !== ''
            ? titlefield.value.trim()
            : await getString('editor:preview:title', 'local_awareness');

        const body = getContent().trim() !== ''
            ? getContent()
            : await getString('editor:preview:empty', 'local_awareness');

        const modal = await ModalCancel.create({
            title: title,
            body: body,
            large: true,
            returnElement: trigger,
            removeOnClose: true,
        });

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

    const trigger = editor.querySelector(SELECTORS.trigger);

    if (!trigger) {
        return;
    }

    trigger.addEventListener('click', event => {
        event.preventDefault();

        /*
         * Built fresh on every press, with removeOnClose. The content is whatever the form
         * says at that moment, and a kept modal would show what the form said the first time.
         */
        openPreview(trigger).catch(Notification.exception);
    });
};
