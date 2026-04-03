/**
 * Notice modal.
 * Originally developed by Nathan Nguyen <nathannguyen@catalyst-au.net> (fork origin: https://github.com/catalyst/moodle-local_sitenotice).
 * Forked and adapted by Anderson Blaine <anderson@blaine.com.br>.
 *
 * @author    Anderson Blaine <anderson@blaine.com.br>
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification', 'core/modal', 'core/key_codes', 'core/str'],
    function ($, Notification, Modal, KeyCodes, str) {

        var SELECTORS = {
            CLOSE_BUTTON: '[data-action="close"]',
            ACCEPT_BUTTON: '[data-action="accept"]',
            ACK_CHECKBOX: '#awareness-modal-ackcheckbox',
            ACK_CONTAINER: '#awareness-ack-container',
            CAN_RECEIVE_FOCUS: 'input:not([type="hidden"]), a[href], button:not([disabled])',
            TOOL_TIP_WRAPPER: '#tooltip-wrapper',
        };

        var ATTRIBUTE = {
            NOTICE_ID: 'data-noticeid',
            REQUIRED_ACKNOWLEDGE: 'data-noticereqack',
        };

        class ModalNotice extends Modal {

            static TYPE = 'local_awareness';

            static TEMPLATE = 'local_awareness/modal_notice';

            constructor(root) {
                super(root);
            }

            /**
             * Get ID of close button.
             * @returns {string}
             */
            getCloseButtonID = function () {
                return '#awareness-closebtn';
            };

            /**
             * Get ID of accept button.
             * @returns {string}
             */
            getAcceptButtonID = function () {
                return '#' + this.getFooter().find(SELECTORS.ACCEPT_BUTTON).attr('id');
            };

            /**
             * Get ID of accept button.
             * @returns {string}
             */
            getAckCheckboxID = function () {
                return SELECTORS.ACK_CHECKBOX;
            };

            /**
             * Set outside click dismissed.
             * @param {boolean} allowOutsideClick
             */
            setOutsideClick = function (allowOutsideClick) {
                this.outsideclick = allowOutsideClick;
            };

            /**
             * Set Notice ID to the current modal.
             * @param {Integer} noticeid
             */
            setNoticeId = function (noticeid) {
                this.getModal().attr(ATTRIBUTE.NOTICE_ID, noticeid);
            };

            /**
             * Get the current notice id.
             * @returns {*}
             */
            getNoticeId = function () {
                return this.getModal().attr(ATTRIBUTE.NOTICE_ID);
            };

            /**
             * Add Checkbox if the notice requires acknowledgement.
             * @param {Integer} reqack
             */
            setRequiredAcknowledgement = function (reqack) {
                var ackContainer = this.getFooter().find(SELECTORS.ACK_CONTAINER);
                var acceptBtn = this.getFooter().find(SELECTORS.ACCEPT_BUTTON);
                var checkbox = this.getFooter().find(SELECTORS.ACK_CHECKBOX);

                // Store state for event listeners
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
            setForceLogout = function (forcelogout) {
                var stringKey = (parseInt(forcelogout, 10) === 1)
                    ? 'modal:checkboxtext_logout'
                    : 'modal:checkboxtext_nologout';
                var label = this.getFooter().find('label[for="awareness-modal-ackcheckbox"]');
                str.get_string(stringKey, 'local_awareness').then(function (text) {
                    label.text(text);
                });
            };

            /**
             * Turn off tool tip
             */
            turnoffToolTip = function () {
                // Deprecated/Not used in new design
            };

            /**
             * Turn on tool tip
             */
            turnonToolTip = function () {
                // Deprecated/Not used in new design
            };

            /**
             * Set background image on the modal content area.
             * @param {string} url URL of the background image, or empty to clear.
             */
            setBackgroundImage = function (url) {
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
            setModalSize = function (width, height) {
                var modalDialog = this.getModal().find('.modal-dialog');
                var modalContent = this.getModal().find('.modal-content');

                if (width) {
                    modalDialog.css({ 'max-width': 'none', 'width': width });
                } else {
                    modalDialog.css({ 'max-width': '', 'width': '' });
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
            registerEventListeners() {
                var modal = this;

                // Backdrop click handling — listen on the root (.modal) container.
                // Clicks on the dark overlay are outside .modal-dialog.
                this.getRoot().on('click', function (e) {
                    if (!modal.isVisible()) {
                        return;
                    }
                    // Check if click was outside the modal content (on the backdrop).
                    if ($(e.target).closest('[data-region="modal"]').length === 0) {
                        // If acknowledgement is required or outside click is disabled, DO NOT close.
                        if (modal.reqack || !modal.outsideclick) {
                            // Jelly bounce to signal that the modal can't be dismissed.
                            var root = modal.getRoot();
                            root.removeClass('jelly-anim');
                            // Force reflow so the animation restarts even if class was just removed.
                            void root[0].offsetWidth;
                            root.addClass('jelly-anim');
                            return;
                        }
                        // Otherwise, dismiss through the close button handler.
                        $('#awareness-closebtn').trigger('click');
                    }
                });

                $(document).on('keydown', function (e) {
                    if (!this.isVisible()) {
                        return;
                    }

                    if (e.keyCode == KeyCodes.tab) {
                        this.handleTabLock(e);
                    }

                    // ESC key handling
                    if (e.keyCode == KeyCodes.escape) {
                        if (this.reqack || !this.outsideclick) {
                            e.preventDefault();
                            return;
                        }
                        $('#awareness-closebtn').trigger('click');
                    }

                }.bind(this));
            }

            /**
             * CAN_RECEIVE_FOCUS in modal.js does not check if the disabled or hidden button
             * @param {Event} e
             */
            handleTabLock = function (e) {
                var target = $(document.activeElement);

                var focusableElements = this.modal.find(SELECTORS.CAN_RECEIVE_FOCUS).filter(":visible");
                var firstFocusable = focusableElements.first();
                var lastFocusable = focusableElements.last();

                var focusable = false;
                var previous = 0;
                focusableElements.each(function (index) {
                    if (target.is(this)) {
                        focusable = true;
                        previous = index;
                    }
                });

                // Focus to first element.
                if (focusable == false) {
                    e.preventDefault();
                    firstFocusable.focus();
                } else {
                    if (target.is(firstFocusable) && e.shiftKey) {
                        lastFocusable.focus();
                        e.preventDefault();
                    } else if (target.is(lastFocusable) && !e.shiftKey) {
                        firstFocusable.focus();
                        e.preventDefault();
                    } else {
                        if (!e.shiftKey) {
                            var next = focusableElements.get(previous + 1);
                        } else {
                            var next = focusableElements.get(previous - 1);
                        }
                        next.focus();
                        e.preventDefault();
                    }
                }
            };
        }

        return ModalNotice;
    }
);
