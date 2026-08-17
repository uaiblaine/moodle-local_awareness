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
 * Opens the notice as the reader will see it, from the editor.
 *
 * Puts the real content into a core/modal_cancel rather than drawing a mock of the dialogue, so what
 * the author checks is the thing that ships.
 *
 * @module     local_awareness/preview
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/modal_cancel', 'core/str'],
    function($, ModalCancel, str) {
        var preview = {};

        preview.init = function() {
            $('a.notice-preview').on('click', function(e) {
                var clickedLink = $(e.currentTarget);
                var content = clickedLink.attr('data-noticecontent');
                return ModalCancel.create({
                    title: str.get_string('notice:content', 'local_awareness'),
                    body: content,
                    large: true
                })
                    .then(function(modal) {
                        return modal.show();
                    });
            });
        };

        return preview;
    }
);
