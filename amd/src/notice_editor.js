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
 * Orchestrator for the redesigned notice editor.
 *
 * Responsibilities:
 *  - Boots the legacy notice_form/init() (course-completion + competency picker).
 *  - Boots the editor_preview, audience_estimator and save_state AMD modules.
 *
 * @module     local_awareness/notice_editor
 * @copyright  Anderson Blaine <anderson@blaine.com.br>
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
            // Wait for the source form's rendering to complete (Moodle injects
            // some elements late, e.g. autocomplete enhancements). A short
            // setTimeout + MutationObserver fallback covers both cases.
            var ready = false;
            var bootstrap = function() {
                if (ready) {
                    return;
                }
                ready = true;
                // Boot the legacy notice_form bindings (course → reset/reqack
                // dependency, plus the competency picker initialiser).
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
