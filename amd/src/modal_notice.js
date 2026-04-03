/**
 * Notice modal.
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net>
 * (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/modal', 'core/key_codes', 'core/str'],
    function($, Modal, KeyCodes, str) {

        var SELECTORS = {
            CLOSE_BUTTON: '[data-action="close"]',
            ACCEPT_BUTTON: '[data-action="accept"]',
            ACK_CHECKBOX: '#awareness-modal-ackcheckbox',
            ACK_CONTAINER: '#awareness-ack-container',
            CAN_RECEIVE_FOCUS: 'input:not([type="hidden"]), a[href], button:not([disabled])',
            TOOL_TIP_WRAPPER: '#tooltip-wrapper'
        };

        var ATTRIBUTE = {
            NOTICE_ID: 'data-noticeid',
            REQUIRED_ACKNOWLEDGE: 'data-noticereqack'
        };

        var ModalNotice = function(root) {
            Modal.call(this, root);
            this.reqack = false;
            this.outsideclick = true;
        };

        ModalNotice.TYPE = 'local_awareness';
        ModalNotice.TEMPLATE = 'local_awareness/modal_notice';

        ModalNotice.prototype = Object.create(Modal.prototype);
        ModalNotice.prototype.constructor = ModalNotice;

            /**
             * Get ID of close button.
             * @returns {string}
             */
        ModalNotice.prototype.getCloseButtonID = function() {
            return '#awareness-closebtn';
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
            str.get_string(stringKey, 'local_awareness').then(function(text) {
                label.text(text);
            });
        };

            /**
             * Turn off tool tip
             */
        ModalNotice.prototype.turnoffToolTip = function() {
            // Deprecated/Not used in new design.
        };

            /**
             * Turn on tool tip
             */
        ModalNotice.prototype.turnonToolTip = function() {
            // Deprecated/Not used in new design.
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
             */
        ModalNotice.prototype.registerEventListeners = function() {
            var modal = this;

            this.getRoot().on('click', function(e) {
                if (!modal.isVisible()) {
                    return;
                }
                if ($(e.target).closest('[data-region="modal"]').length === 0) {
                    if (modal.reqack || !modal.outsideclick) {
                        var root = modal.getRoot();
                        root.removeClass('jelly-anim');
                        void root[0].offsetWidth;
                        root.addClass('jelly-anim');
                        return;
                    }
                    $('#awareness-closebtn').trigger('click');
                }
            });

            $(document).on('keydown', function(e) {
                if (!this.isVisible()) {
                    return;
                }

                if (e.keyCode == KeyCodes.tab) {
                    this.handleTabLock(e);
                }

                if (e.keyCode == KeyCodes.escape) {
                    if (this.reqack || !this.outsideclick) {
                        e.preventDefault();
                        return;
                    }
                    $('#awareness-closebtn').trigger('click');
                }
            }.bind(this));
        };

            /**
             * CAN_RECEIVE_FOCUS in modal.js does not check if the disabled or hidden button
             * @param {Event} e
             */
        ModalNotice.prototype.handleTabLock = function(e) {
            var target = $(document.activeElement);

            var focusableElements = this.modal.find(SELECTORS.CAN_RECEIVE_FOCUS).filter(':visible');
            var firstFocusable = focusableElements.first();
            var lastFocusable = focusableElements.last();

            var focusable = false;
            var previous = 0;
            focusableElements.each(function(index) {
                if (target.is(this)) {
                    focusable = true;
                    previous = index;
                }
            });

            if (focusable == false) {
                e.preventDefault();
                firstFocusable.focus();
            } else if (target.is(firstFocusable) && e.shiftKey) {
                lastFocusable.focus();
                e.preventDefault();
            } else if (target.is(lastFocusable) && !e.shiftKey) {
                firstFocusable.focus();
                e.preventDefault();
            } else {
                var next = !e.shiftKey ? focusableElements.get(previous + 1) : focusableElements.get(previous - 1);
                next.focus();
                e.preventDefault();
            }
        };

        return ModalNotice;
    }
);
