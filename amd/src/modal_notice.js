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

define(['jquery', 'core/modal', 'core/key_codes', 'core/templates', 'local_awareness/carousel'],
    function($, Modal, KeyCodes, Templates, Carousel) {

        var SELECTORS = {
            CLOSE_BUTTON: '[data-action="close"]',
            ACCEPT_BUTTON: '[data-action="accept"]',
            ACK_CHECKBOX: '#awareness-modal-ackcheckbox',
            ACK_CONTAINER: '#awareness-ack-container',
            HEADER_CLOSE: '#awareness-closebtn',
            FOOTER_CLOSE: '#awareness-closebtn-footer',
            NOT_NOW: '#awareness-notnowbtn',
            MEDIA: '[data-region="media"]',
            BAND: '[data-region="band"]',
            VIDEO: '[data-region="video"]',
            CAROUSEL: '[data-region="carousel"]',
            CAROUSEL_ROOT: '[data-region="carousel-root"]',
            PLAYER: '.video-js',
            PLAYING: 'video, audio',
            FRAME: 'iframe',
            BOUNDED: '.mediaplugin [style]'
        };

        var TEMPLATES = {
            VIDEO: 'local_awareness/notice/video',
            CAROUSEL: 'local_awareness/notice/carousel'
        };

        /**
         * The class prefixes the stylesheet reads for the layout, the position and the entrance.
         *
         * Mirrors awareness::TEMPLATES and friends only in shape: the values arrive from the
         * server, already validated, and this file writes whatever it is handed.
         */
        var APPEARANCE = {
            TEMPLATE: 'la-tpl-',
            POSITION: 'la-pos-',
            ANIMATION: 'la-anim-',
            FROM: 'la-from-',
            LARGE: 'modal-lg',
            NONE: 'none',
            // The card is the one layout narrower than core's large dialogue.
            COMPACT: ['card'],
            // The layouts whose author-set width and height mean anything.
            SIZED: ['classic', 'hero', 'video', 'carousel']
        };

        /**
         * Replace the class carrying a prefix with another value of it, or with nothing.
         *
         * @param {jQuery} node The element.
         * @param {String} prefix One of the APPEARANCE prefixes.
         * @param {String|null} value The new suffix, or null to carry none.
         */
        var swapClass = function(node, prefix, value) {
            var classes = (node.attr('class') || '').split(/\s+/).filter(function(name) {
                return name !== '' && name.indexOf(prefix) !== 0;
            });
            if (value) {
                classes.push(prefix + value);
            }
            node.attr('class', classes.join(' '));
        };

        /**
         * Stop whatever is playing inside an element.
         *
         * core/modal's hide() toggles classes and nothing else, so a video kept playing, audibly,
         * from a dialogue that was no longer on the screen. A video.js player is paused through its
         * API when the loader made one; a plain element directly; an iframe cannot be paused from
         * outside, so its source is written back, which reloads it stopped.
         *
         * @param {HTMLElement|undefined} container The element to silence.
         */
        var stopMedia = function(container) {
            if (!container) {
                return;
            }
            container.querySelectorAll(SELECTORS.PLAYER).forEach(function(element) {
                var player = window.videojs && window.videojs.getPlayer ? window.videojs.getPlayer(element) : null;
                if (player && !player.paused()) {
                    player.pause();
                }
            });
            container.querySelectorAll(SELECTORS.PLAYING).forEach(function(element) {
                if (!element.paused) {
                    element.pause();
                }
            });
            container.querySelectorAll(SELECTORS.FRAME).forEach(function(frame) {
                var source = frame.getAttribute('src');
                if (source) {
                    frame.setAttribute('src', source);
                }
            });
        };

        /**
         * Let a player fill the band.
         *
         * The multimedia filter wraps its player in an element carrying an inline max-width, the
         * site's default media width, which no stylesheet rule can outbid without !important.
         *
         * @param {HTMLElement} container The band.
         */
        var unboundMediaWidth = function(container) {
            container.querySelectorAll(SELECTORS.BOUNDED).forEach(function(element) {
                element.style.removeProperty('max-width');
            });
        };

        /**
         * How insistent a notice is. Mirrors the INSISTENCE_* constants on the awareness
         * persistent, which is where the levels are defined and where the mapping to storage
         * lives; the server sends the resolved level, so this file never sees the columns.
         */
        var INSISTENCE = {
            INFORMATIONAL: 0,
            BLOCKING: 1,
            ACKNOWLEDGE: 2
        };

        var ATTRIBUTE = {
            NOTICE_ID: 'data-noticeid'
        };

        var ModalNotice = function(root) {
            var self = Reflect.construct(Modal, [root], ModalNotice);
            self.insistence = INSISTENCE.INFORMATIONAL;
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
         * Dress the dialogue for how insistent this notice is.
         *
         * One call rather than three, because it is one decision. Everything it touches is already
         * in the template, so nothing here fetches a string or waits on a promise: the exit button
         * for this level is shown and the other is hidden.
         *
         *  - Informational  the header cross and a single Close; no Accept, so no acceptance can
         *                   be recorded for a notice that never asked for one.
         *  - Blocking       no cross, Not now beside Accept, and the backdrop refuses clicks.
         *  - Acknowledge    as Blocking, and Accept waits for the checkbox.
         *
         * @param {Number} insistence One of the INSISTENCE values.
         */
        ModalNotice.prototype.setInsistence = function(insistence) {
            var level = parseInt(insistence, 10);
            this.insistence = isNaN(level) ? INSISTENCE.INFORMATIONAL : level;

            var footer = this.getFooter();
            var ackContainer = footer.find(SELECTORS.ACK_CONTAINER);
            var acceptBtn = footer.find(SELECTORS.ACCEPT_BUTTON);
            var checkbox = footer.find(SELECTORS.ACK_CHECKBOX);
            var blocking = this.insistence >= INSISTENCE.BLOCKING;

            this.getModal().find(SELECTORS.HEADER_CLOSE).toggleClass('d-none', blocking);
            footer.find(SELECTORS.FOOTER_CLOSE).toggleClass('d-none', blocking);
            footer.find(SELECTORS.NOT_NOW).toggleClass('d-none', !blocking);
            acceptBtn.toggleClass('d-none', !blocking);

            if (this.insistence >= INSISTENCE.ACKNOWLEDGE) {
                ackContainer.removeClass('d-none');
                acceptBtn.attr('disabled', true);
                checkbox.prop('checked', false);
            } else {
                ackContainer.addClass('d-none');
                acceptBtn.removeAttr('disabled');
            }
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
                    if (modal.insistence >= INSISTENCE.BLOCKING) {
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

            /*
             * Namespaced, so destroy() can take this one listener off again: the previews build a
             * fresh dialogue on every press, and a document listener that outlived its dialogue
             * was a closure leaked per preview.
             */
            this.keyns = 'keydown.local_awareness_' + Math.random().toString(36).slice(2);
            $(document).on(this.keyns, function(e) {
                if (!this.isVisible()) {
                    return;
                }

                if (e.keyCode == KeyCodes.escape) {
                    if (this.insistence >= INSISTENCE.BLOCKING) {
                        e.preventDefault();
                        return;
                    }
                    this.getModal().find(SELECTORS.CLOSE_BUTTON).trigger('click');
                }
            }.bind(this));
        };

        /**
         * Dress the dialogue as one of the layouts.
         *
         * Swaps the layout class on the dialog, which IS .awareness, and manages modal-lg itself:
         * the template bakes that class in, so configure({large: true}) is a no-op against it and
         * a compact layout has to take it off by hand, for every notice including the first.
         *
         * @param {String} template One of awareness::TEMPLATES.
         */
        ModalNotice.prototype.setTemplate = function(template) {
            var dialog = this.getModal();
            swapClass(dialog, APPEARANCE.TEMPLATE, template);
            dialog.toggleClass(APPEARANCE.LARGE, APPEARANCE.COMPACT.indexOf(template) === -1);
            this.template = template;
        };

        /**
         * The layout the dialogue currently wears.
         *
         * @returns {String|undefined}
         */
        ModalNotice.prototype.getTemplate = function() {
            return this.template;
        };

        /**
         * Place the dialogue on the screen.
         *
         * The slide entrance takes its edge from here: a dialogue against the bottom slides up
         * from it, everything else slides down from the top.
         *
         * @param {String} position One of awareness::POSITIONS.
         */
        ModalNotice.prototype.setPosition = function(position) {
            var dialog = this.getModal();
            swapClass(dialog, APPEARANCE.POSITION, position);
            swapClass(dialog, APPEARANCE.FROM, position.indexOf('bottom') === 0 ? 'bottom' : 'top');
            this.position = position;
        };

        /**
         * The position the dialogue currently holds.
         *
         * @returns {String|undefined}
         */
        ModalNotice.prototype.getPosition = function() {
            return this.position;
        };

        /**
         * Play the entrance.
         *
         * Called after show(), not hung off it: core's show() returns early for a dialogue that is
         * already visible, which is every notice after the first, so nothing core emits would fire
         * again. The forced reflow is what lets the same class animate twice, the trick the
         * refused-click shake already relies on; the class is dropped when the entrance ends so
         * that shake can play on the same element later.
         *
         * @param {String} animation One of awareness::ANIMATIONS.
         */
        ModalNotice.prototype.setAnimation = function(animation) {
            var dialog = this.getModal();
            swapClass(dialog, APPEARANCE.ANIMATION, null);
            if (!animation || animation === APPEARANCE.NONE) {
                return;
            }
            void dialog[0].offsetWidth;
            dialog.addClass(APPEARANCE.ANIMATION + animation);
            dialog.one('animationend', function() {
                swapClass(dialog, APPEARANCE.ANIMATION, null);
            });
        };

        /**
         * Fill the media band with what the layout draws, or hide it.
         *
         * The hero's image is a band rather than a cover; the video and the slides arrive already
         * rendered by the server, where the multimedia filter runs, and are dropped in through
         * core/templates, whose replaceNodeContents() announces the new nodes to the filters -
         * that announcement is what makes the video.js loader, already on every page, initialise
         * a player it did not render. It is deliberately not announced a second time here.
         *
         * @param {Object} notice The payload of one notice, as get_notices returns it.
         * @returns {Promise} Resolved once the band is filled.
         */
        ModalNotice.prototype.setMedia = function(notice) {
            var media = this.getModal().find(SELECTORS.MEDIA);
            var band = media.find(SELECTORS.BAND);
            var video = media.find(SELECTORS.VIDEO);
            var carousel = media.find(SELECTORS.CAROUSEL);
            var pending = [];
            var shown = false;

            stopMedia(media[0]);
            video.empty();
            carousel.empty();
            band.css('background-image', '');

            if (notice.template === 'hero' && notice.bgimageurl) {
                band.css('background-image', 'url("' + notice.bgimageurl + '")');
                shown = true;
            }

            if (notice.template === 'video' && notice.videohtml) {
                shown = true;
                pending.push(Templates.render(TEMPLATES.VIDEO, {videohtml: notice.videohtml}).then(function(html, js) {
                    Templates.replaceNodeContents(video, html, js);
                    unboundMediaWidth(video[0]);
                    return null;
                }));
            }

            if (notice.template === 'carousel' && notice.slides && notice.slides.length) {
                shown = true;
                var context = {
                    total: notice.slides.length,
                    slides: notice.slides.map(function(slide, index) {
                        return {
                            type: slide.type,
                            html: slide.html,
                            caption: slide.caption,
                            index: index,
                            number: index + 1,
                            first: index === 0
                        };
                    })
                };
                pending.push(Templates.render(TEMPLATES.CAROUSEL, context).then(function(html, js) {
                    Templates.replaceNodeContents(carousel, html, js);
                    unboundMediaWidth(carousel[0]);
                    Carousel.mount(carousel[0].querySelector(SELECTORS.CAROUSEL_ROOT));
                    return null;
                }));
            }

            media.prop('hidden', !shown);

            return $.when.apply($, pending);
        };

        /**
         * Hide the dialogue, and stop whatever it was playing.
         *
         * A prototype method for the reason registerEventListeners() above gives. The media is
         * silenced first, while the elements are still there to be paused.
         */
        ModalNotice.prototype.hide = function() {
            stopMedia(this.getRoot()[0]);
            Modal.prototype.hide.call(this);
        };

        /**
         * Remove the dialogue, and the document listener it registered.
         */
        ModalNotice.prototype.destroy = function() {
            stopMedia(this.getRoot()[0]);
            if (this.keyns) {
                $(document).off(this.keyns);
            }
            Modal.prototype.destroy.call(this);
        };

        /**
         * Dress the dialogue for one notice: layout, position, image, size and media.
         *
         * One call for every path that shows a notice - the reader's queue, both previews - because
         * the reused instance needs each of these re-applied per notice; core keeps nothing per
         * show(). The entrance is not here: it plays after show(), from the caller.
         *
         * @param {Object} notice The payload of one notice, as the web services return it.
         * @returns {Promise} Resolved once the media band is filled.
         */
        ModalNotice.prototype.setAppearance = function(notice) {
            var template = notice.template || 'classic';
            var sized = APPEARANCE.SIZED.indexOf(template) !== -1;
            this.setTemplate(template);
            this.setPosition(notice.position || 'center');
            // The hero paints the image as a band in the media region, not as a cover behind everything.
            this.setBackgroundImage(template === 'hero' ? '' : (notice.bgimageurl || ''));
            this.setModalSize(sized ? (notice.modal_width || '') : '', sized ? (notice.modal_height || '') : '');

            return this.setMedia(notice);
        };

        return ModalNotice;
    }
);
