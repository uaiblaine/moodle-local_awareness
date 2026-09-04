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
 * Opens a saved notice from the manage list as the reader will see it.
 *
 * The row carries only the notice id; the server renders the notice exactly as it renders it for
 * the reader's queue - layout, position, image, video, slides - and the real notice dialogue shows
 * it. The exits close the dialogue and record nothing. Bound once on the document, because the
 * list arrives over AJAX after the page and re-renders on every filter change.
 *
 * @module     local_awareness/preview
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalNotice from 'local_awareness/modal_notice';
import Notification from 'core/notification';
import Pending from 'core/pending';

const SELECTORS = {
    trigger: '.notice-preview',
    closeActions: '[data-action="close"], [data-action="accept"]',
};

const SERVICES = {
    render: 'local_awareness_render_notice',
};

const BOUND = 'laPreviewBound';

/**
 * Open the dialogue on one saved notice.
 *
 * @param {HTMLElement} link The row's preview link, carrying data-noticeid.
 * @returns {Promise} Resolves once the dialogue is showing.
 */
const openPreview = async(link) => {
    const pending = new Pending('local_awareness/preview:open');

    try {
        const noticeid = parseInt(link.dataset.noticeid, 10);
        const payload = await Ajax.call([{methodname: SERVICES.render, args: {noticeid: noticeid}}])[0];

        const modal = await ModalNotice.create({
            type: ModalNotice.TYPE,
            title: payload.title,
            body: payload.content,
            large: true,
        });
        const dialog = modal.getModal();
        dialog.on('click', SELECTORS.closeActions, () => {
            modal.destroy();
            link.focus();
        });
        dialog.on('click', modal.getAckCheckboxID(), () => {
            const ticked = dialog.find(modal.getAckCheckboxID()).is(':checked');
            dialog.find(modal.getAcceptButtonID()).attr('disabled', !ticked);
        });
        modal.setInsistence(payload.insistence);
        // Shown before it is dressed: show() attaches the dialogue to the document, and the
        // video.js loader finds a player by id in the document - a detached band names nothing.
        await modal.show();
        await modal.setAppearance(payload);
        modal.setAnimation(payload.animation);
        dialog.focus();
    } finally {
        pending.resolve();
    }
};

export const init = () => {
    if (document.body.dataset[BOUND] === '1') {
        return;
    }
    document.body.dataset[BOUND] = '1';

    document.addEventListener('click', (event) => {
        const link = event.target.closest(SELECTORS.trigger);
        if (!link) {
            return;
        }
        event.preventDefault();
        openPreview(link).catch(Notification.exception);
    });
};
