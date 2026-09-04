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
 * Shows site notices to the reader, one at a time, and records what they do with each.
 *
 * Owns the queue: a notice is displayed, the reader dismisses or accepts it, and the next one takes
 * its place in the same dialogue. The modal is hidden in exactly one place — when the queue empties —
 * so a request that never reached the server leaves the notice on screen rather than closing it and
 * silently losing the record.
 *
 * @module     local_awareness/notice
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(
    ['jquery', 'core/ajax', 'core/notification', 'local_awareness/modal_notice'],
    function($, ajax, Notification, ModalNotice) {

        var notices = {};
        var modal;
        var viewednotices = [];
        // True while a dismiss or acknowledge request is in flight. The modal used to be hidden
        // synchronously on click, which made a second click impossible; now that it stays up until
        // the server answers, this is what stops one notice being dismissed twice.
        var inflight = false;

        var Awareness = {};

        /**
         * Retrieved notice which has not been viewwed.
         * @returns {boolean|*}
         */
        var getNotice = function() {
            for (var i in notices) {
                // Check the notice has been viewed.
                if (!viewednotices.includes(i)) {
                    viewednotices.push(i);
                    return notices[i];
                }
            }
            return false;
        };

        /**
         * Show next notice in the modal.
         */
        var nextNotice = function() {
            var nextnotice = getNotice();
            if (nextnotice == false) {
                if (typeof modal !== 'undefined') {
                    // Nothing left to show. This is the ONLY place the modal is hidden, so a
                    // dismissal that never reached the server leaves the notice on screen instead
                    // of closing it and silently losing the record.
                    modal.hide();
                }
                return;
            }
            if (typeof modal === 'undefined') {
                ModalNotice.create({
                    type: ModalNotice.TYPE,
                    title: nextnotice.title,
                    body: nextnotice.content,
                    large: true,
                })
                    .then(function(newmodal) {
                        modal = newmodal;

                        modal.setNoticeId(nextnotice.id);
                        modal.setInsistence(nextnotice.insistence);

                        // Event listener for close button.
                        modal.getModal().on('click', modal.getCloseButtonSelector(), function() {
                            dismissNotice();
                        });
                        // Event listener for accept button.
                        modal.getModal().on('click', modal.getAcceptButtonID(), function() {
                            acknowledgeNotice();
                        });
                        // Event listener for link tracking.
                        modal.getModal().on('click', 'a', function() {
                            var linkid = $(this).attr("data-linkid");
                            trackLink(linkid);
                        });
                        // Event listener for ack checkbox.
                        modal.getModal().on('click', modal.getAckCheckboxID(), function() {
                            var ischecked = $(modal.getAckCheckboxID()).is(":checked");
                            $(modal.getAcceptButtonID()).attr('disabled', !ischecked);
                        });

                        /*
                         * Shown BEFORE it is dressed. show() is what attaches the dialogue to the
                         * document, and the video.js loader finds the player it is told about by
                         * id, in the document: a band filled while the dialogue was still detached
                         * gave it an id that named nothing, and no player.
                         */
                        modal.show();
                        return modal.setAppearance(nextnotice);
                    })
                    .then(function() {
                        modal.setAnimation(nextnotice.animation);
                        modal.getModal().focus();
                        return null;
                    })
                    .catch(Notification.exception);
            } else {
                /*
                 * The dialogue is dressed in place, whatever its next shape: core's hide() and
                 * show() run in the same frame here, so nothing between them would ever be painted,
                 * and the entrance the caller replays after show() is what carries a change of
                 * shape - it starts from nothing and arrives as the new notice. An author who chose
                 * no entrance chose no motion at all, including here. Hiding stays in exactly one
                 * place, when the queue is empty, which async_contract_test pins.
                 */
                // Update with new details.
                modal.setTitle(nextnotice.title);
                modal.setBody(nextnotice.content);
                modal.setNoticeId(nextnotice.id);
                modal.setInsistence(nextnotice.insistence);
                modal.setAppearance(nextnotice).catch(Notification.exception);
                modal.show();
                modal.setAnimation(nextnotice.animation);
                modal.getModal().focus();
            }
        };

        /**
         * Dismiss Notice.
         */
        var dismissNotice = function() {
            if (inflight) {
                return;
            }
            inflight = true;

            var noticeid = modal.getNoticeId();
            var promises = ajax.call([
                {methodname: 'local_awareness_dismiss', args: {noticeid: noticeid}}
            ]);

            promises[0].done(function() {
                nextNotice();
            }).fail(Notification.exception).always(function() {
                inflight = false;
            });
        };

        /**
         * Acknowledge notice.
         */
        var acknowledgeNotice = function() {
            if (inflight) {
                return;
            }
            inflight = true;

            var noticeid = modal.getNoticeId();
            var promises = ajax.call([
                {methodname: 'local_awareness_acknowledge', args: {noticeid: noticeid}}
            ]);

            promises[0].done(function() {
                nextNotice();
            }).fail(Notification.exception).always(function() {
                inflight = false;
            });
        };

        /**
         * Link tracking.
         * @param {Integer} linkid
         */
        var trackLink = function(linkid) {
            var promises = ajax.call([
                {methodname: 'local_awareness_tracklink', args: {linkid: linkid}}
            ]);

            promises[0].fail(Notification.exception);
        };

        /**
         * Initial Modal with user notices.
         */
        Awareness.init = function() {
            var currenturl = window.location.pathname + window.location.search;
            var courseid = (M.cfg && M.cfg.courseId) ? M.cfg.courseId : 0;
            var promises = ajax.call([
                {methodname: 'local_awareness_getnotices', args: {pageurl: currenturl, courseid: courseid}}
            ]);

            promises[0].done(function(response) {
                // No JSON.parse: the web service declares a real structure now, so core hands over
                // an array that has already been through clean_returnvalue(). The parse used to sit
                // inside done(), where fail() could not see it throw.
                notices = response.notices || [];
                $(document).ready(function() {
                    nextNotice();
                });
            }).fail(Notification.exception);
        };

        return Awareness;
    }
);