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
 * Boots the notice editor's modules once the form has rendered: notice_form, editor_preview,
 * audience_estimator, collision_warning and save_state.
 *
 * @module     local_awareness/notice_editor
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'local_awareness/notice_form',
    'local_awareness/editor_preview',
    'local_awareness/audience_estimator',
    'local_awareness/collision_warning',
    'local_awareness/save_state'
], function(NoticeForm, EditorPreview, AudienceEstimator, CollisionWarning, SaveState) {
    'use strict';

    return {
        init: function() {
            // A short delay after the DOM is ready, because Moodle enhances some
            // form elements late (the autocompletes, for one). notice_form.init()
            // carries its own MutationObserver fallback for what arrives later still.
            var ready = false;
            var bootstrap = function() {
                if (ready) {
                    return;
                }
                ready = true;
                // Guarded so a failure in the form bindings cannot stop the
                // other modules from booting.
                try {
                    NoticeForm.init();
                } catch (e) { /* No-op. */ }
                EditorPreview.init();
                AudienceEstimator.init();
                CollisionWarning.init();
                SaveState.init();
            };

            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                setTimeout(bootstrap, 50);
            } else {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(bootstrap, 50);
                });
            }
        }
    };
});
