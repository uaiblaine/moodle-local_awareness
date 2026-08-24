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
 * The notice dialogue itself, extending core/modal with the plugin's own controls.
 *
 * Carries the acknowledgement checkbox, the background image and the modal sizing, and routes an
 * outside click or the escape key into the close button so every exit path records the same way.
 *
 * @module     local_awareness/modal_notice
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/modal', 'core/key_codes', 'core/str'],
    function($, Modal, KeyCodes, str) {

        var SELECTORS = {
            CLOSE_BUTTON: '[data-action="close"]',
            ACCEPT_BUTTON: '[data-action="accept"]',
            ACK_CHECKBOX: '#awareness-modal-ackcheckbox',
            ACK_CONTAINER: '#awareness-ack-container'
        };

        var ATTRIBUTE = {
            NOTICE_ID: 'data-noticeid',
            REQUIRED_ACKNOWLEDGE: 'data-noticereqack'
        };

        var ModalNotice = function(root) {
            var self = Reflect.construct(Modal, [root], ModalNotice);
            self.reqack = false;
            self.outsideclick = true;
            return self;
        };

        Object.setPrototypeOf(ModalNotice, Modal);
        ModalNotice.prototype = Object.create(Modal.prototype);
        ModalNotice.prototype.constructor = ModalNotice;

        ModalNotice.TYPE = 'local_awareness';
        ModalNotice.TEMPLATE = 'local_awareness/modal_notice';
        ModalNotice.create = Modal.create;

        /**
         * Selector for the close button.
         *
         * One constant rather than the same selector spelled out at each of the three places that
         * need it. The button carries both an id and the data-action hook
         * (templates/modal_notice.mustache); the data-action form is what the rest of this file
         * uses, so the close and accept hooks read the same way.
         *
         * @returns {String} A selector matching the modal's close button.
         */
        ModalNotice.prototype.getCloseButtonSelector = function() {
            return SELECTORS.CLOSE_BUTTON;
        };

        /**
         * Get ID of accept button.
         * @returns {string}
         */
        ModalNotice.prototype.getAcceptButtonID = function() {
            return '#' + this.getFooter().find(SELECTORS.ACCEPT_BUTTON).attr('id');
        };

        /**
         * Get ID of accept button.
         * @returns {string}
         */
        ModalNotice.prototype.getAckCheckboxID = function() {
            return SELECTORS.ACK_CHECKBOX;
        };

        /**
         * Set outside click dismissed.
         * @param {boolean} allowOutsideClick
         */
        ModalNotice.prototype.setOutsideClick = function(allowOutsideClick) {
            this.outsideclick = allowOutsideClick;
        };

        /**
         * Set Notice ID to the current modal.
         * @param {Integer} noticeid
         */
        ModalNotice.prototype.setNoticeId = function(noticeid) {
            this.getModal().attr(ATTRIBUTE.NOTICE_ID, noticeid);
        };

        /**
         * Get the current notice id.
         * @returns {*}
         */
        ModalNotice.prototype.getNoticeId = function() {
            return this.getModal().attr(ATTRIBUTE.NOTICE_ID);
        };

        /**
         * Add Checkbox if the notice requires acknowledgement.
         * @param {Integer} reqack
         */
        ModalNotice.prototype.setRequiredAcknowledgement = function(reqack) {
            var ackContainer = this.getFooter().find(SELECTORS.ACK_CONTAINER);
            var acceptBtn = this.getFooter().find(SELECTORS.ACCEPT_BUTTON);
            var checkbox = this.getFooter().find(SELECTORS.ACK_CHECKBOX);

            this.reqack = (reqack == 1);

            if (this.reqack) {
                ackContainer.removeClass('d-none');
                acceptBtn.attr('disabled', true);
                checkbox.prop('checked', false);
            } else {
                ackContainer.addClass('d-none');
                acceptBtn.show();
                acceptBtn.removeAttr('disabled');
            }
        };

        /**
         * Update checkbox label text based on forcelogout setting.
         * @param {Integer} forcelogout 1 if force logout is enabled, 0 otherwise.
         */
        ModalNotice.prototype.setForceLogout = function(forcelogout) {
            var stringKey = (parseInt(forcelogout, 10) === 1) ?
                'modal:checkboxtext_logout' : 'modal:checkboxtext_nologout';
            var label = this.getFooter().find('label[for="awareness-modal-ackcheckbox"]');
            return str.get_string(stringKey, 'local_awareness').then(function(text) {
                label.text(text);
                return null;
            });
        };

        /**
         * Set background image on the modal content area.
         * @param {string} url URL of the background image, or empty to clear.
         */
        ModalNotice.prototype.setBackgroundImage = function(url) {
            var modalContent = this.getModal().find('.modal-content');
            if (url) {
                modalContent.css({
                    'background-image': 'url(' + url + ')',
                    'background-size': 'cover',
                    'background-position': 'center center',
                    'background-repeat': 'no-repeat'
                });
                modalContent.addClass('has-bg-image');
            } else {
                modalContent.css('background-image', '');
                modalContent.removeClass('has-bg-image');
            }
        };

        /**
         * Set custom modal dimensions.
         * @param {string} width Custom width (e.g. '600px', '80%', '50vw') or empty for default.
         * @param {string} height Custom height (e.g. '400px', '70%', '50vh') or empty for default.
         */
        ModalNotice.prototype.setModalSize = function(width, height) {
            var modalDialog = this.getModal().find('.modal-dialog');
            var modalContent = this.getModal().find('.modal-content');

            if (width) {
                modalDialog.css({'max-width': 'none', 'width': width});
            } else {
                modalDialog.css({'max-width': '', 'width': ''});
            }

            if (height) {
                modalContent.css('min-height', height);
            } else {
                modalContent.css('min-height', '');
            }
        };


        /**
         * Override registerEventListeners to custom handle backdrop clicks.
         *
         * IMPORTANT: This MUST be a proper prototype method (not a class field
         * like `= function() {}`) so that it exists on the prototype chain BEFORE
         * the parent Modal constructor calls `this.registerEventListeners()`.
         * Class fields are only initialised after super() returns, which means
         * the parent would use its own version instead of this override.
         *
         * Replacing core's version rather than extending it means core's own listeners never
         * register, so what is NOT here is as load-bearing as what is. Deliberately dropped:
         * core's Escape handler (it calls hide() with no server call, losing the dismissal
         * record) and its data-action="hide" handler (this template uses data-action="close").
         * Deliberately NOT reimplemented: the Tab trap. core/modal calls
         * FocusLock.trapFocus() from attachToDOM() (lib/amd/src/modal.js), and focuslock binds
         * keydown in the CAPTURE phase, so core already ran by the time a jQuery handler here
         * would see the key. The copy this file used to carry ran second, fought core for the
         * same keypress, and matched a narrower set of elements - it could not reach a select,
         * a textarea or anything with tabindex inside a notice body, all of which an author can
         * put there through the content editor.
         */
        ModalNotice.prototype.registerEventListeners = function() {
            var modal = this;

            this.getRoot().on('click', function(e) {
                if (!modal.isVisible()) {
                    return;
                }
                if ($(e.target).closest('[data-region="modal"]').length === 0) {
                    if (modal.reqack || !modal.outsideclick) {
                        // The shake goes on the dialogue, which is the element carrying `awareness` and
                        // the element styles.css animates. It used to go on getRoot(), where the rule
                        // `.awareness.jelly-anim .modal-dialog` could never match - `awareness` sits on
                        // the dialog, not the root - so a blocked click produced no feedback at all.
                        var dialog = modal.getModal();
                        dialog.removeClass('jelly-anim');
                        void dialog[0].offsetWidth;
                        dialog.addClass('jelly-anim');
                        return;
                    }
                    modal.getModal().find(SELECTORS.CLOSE_BUTTON).trigger('click');
                }
            });

            $(document).on('keydown', function(e) {
                if (!this.isVisible()) {
                    return;
                }

                if (e.keyCode == KeyCodes.escape) {
                    if (this.reqack || !this.outsideclick) {
                        e.preventDefault();
                        return;
                    }
                    this.getModal().find(SELECTORS.CLOSE_BUTTON).trigger('click');
                }
            }.bind(this));
        };

        return ModalNotice;
    }
);
